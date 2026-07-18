<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use InvalidArgumentException;
use RuntimeException;
use YassinStore\AiAssistant\Domain\Commerce\CartSessionMarker;
use YassinStore\AiAssistant\Domain\Commerce\CartSnapshot;
use YassinStore\AiAssistant\Domain\Commerce\CartStepAttempt;
use YassinStore\AiAssistant\Domain\Commerce\CartStepAttemptStatus;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Uuid;

/** Each execution retry gets a new immutable attempt identity and origin fences. */
final class CartStepAttemptRepository
{
    public function latestForStep(int $stepId, bool $forUpdate = false): ?CartStepAttempt
    {
        if ($stepId < 1) {
            throw new InvalidArgumentException('Cart step ID is invalid.');
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE step_id=%d ORDER BY attempt_number DESC LIMIT 1'
                . ($forUpdate ? ' FOR UPDATE' : ''),
                $stepId
            ),
            ARRAY_A
        );
        $this->assertRead('Unable to read durable cart step attempt.');
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function start(
        int $stepId,
        int $conversationFence,
        string $commerceResourceHash,
        int $commerceFence
    ): CartStepAttempt {
        $commerceResourceHash = strtolower(trim($commerceResourceHash));
        if (
            $stepId < 1 || $conversationFence < 1
            || preg_match('/^[a-f0-9]{64}$/', $commerceResourceHash) !== 1 || $commerceFence < 1
        ) {
            throw new InvalidArgumentException('Cart step attempt identity is invalid.');
        }
        $latest = $this->latestForStep($stepId, true);
        if ($latest !== null && !$latest->isTerminal()) {
            throw new RuntimeException('A cart step already has a nonterminal execution attempt.');
        }
        $number = $latest !== null ? $latest->attemptNumber() + 1 : 1;
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . self::table()
            . ' (public_id,step_id,attempt_number,conversation_fence,commerce_resource_hash,commerce_fence,'
            . ' status,marker_digest,marker,candidate_effect,candidate_post_state,failure_code,safe_message,'
            . ' created_at,updated_at,completed_at)'
            . ' VALUES (%s,%d,%d,%d,%s,%d,%s,%s,NULL,NULL,NULL,%s,%s,%s,%s,NULL)',
            Uuid::v4(),
            $stepId,
            $number,
            $conversationFence,
            $commerceResourceHash,
            $commerceFence,
            CartStepAttemptStatus::STARTED,
            '',
            '',
            '',
            $now,
            $now
        ));
        $this->assertWrite($inserted, 'Unable to start durable cart step attempt.');
        $attempt = $this->latestForStep($stepId);
        if ($attempt === null || $attempt->attemptNumber() !== $number) {
            throw new RuntimeException('Started cart step attempt was not persisted.');
        }
        return $attempt;
    }

    public function stageIntent(int $attemptId, CartSessionMarker $marker): CartStepAttempt
    {
        return $this->transition(
            $attemptId,
            array(CartStepAttemptStatus::STARTED),
            CartStepAttemptStatus::INTENT_STAGED,
            $marker,
            null,
            null,
            '',
            ''
        );
    }

    /** @param array<string,mixed> $effect */
    public function seal(
        int $attemptId,
        CartSessionMarker $marker,
        array $effect,
        CartSnapshot $postState
    ): CartStepAttempt {
        return $this->transition(
            $attemptId,
            array(CartStepAttemptStatus::INTENT_STAGED),
            CartStepAttemptStatus::SEALED,
            $marker,
            $effect,
            $postState,
            '',
            ''
        );
    }

    public function markSessionPersisted(int $attemptId): CartStepAttempt
    {
        $current = $this->requireById($attemptId, true);
        return $this->transition(
            $attemptId,
            array(CartStepAttemptStatus::SEALED),
            CartStepAttemptStatus::SESSION_PERSISTED,
            $this->requireMarker($current),
            $current->candidateEffect(),
            $current->candidatePostState(),
            '',
            ''
        );
    }

    public function markVerified(int $attemptId): CartStepAttempt
    {
        $current = $this->requireById($attemptId, true);
        return $this->transition(
            $attemptId,
            array(CartStepAttemptStatus::SEALED, CartStepAttemptStatus::SESSION_PERSISTED),
            CartStepAttemptStatus::VERIFIED,
            $this->requireMarker($current),
            $current->candidateEffect(),
            $current->candidatePostState(),
            '',
            ''
        );
    }

    public function markFailed(
        int $attemptId,
        string $status,
        string $failureCode,
        string $safeMessage
    ): CartStepAttempt {
        if (
            !in_array($status, array(
            CartStepAttemptStatus::ABANDONED,
            CartStepAttemptStatus::UNCERTAIN,
            ), true)
        ) {
            throw new InvalidArgumentException('Cart attempt failure status is invalid.');
        }
        $current = $this->requireById($attemptId, true);
        $marker = $current->marker() !== null ? CartSessionMarker::fromArray($current->marker()) : null;
        return $this->transition(
            $attemptId,
            array(
                CartStepAttemptStatus::STARTED,
                CartStepAttemptStatus::INTENT_STAGED,
                CartStepAttemptStatus::SEALED,
                CartStepAttemptStatus::SESSION_PERSISTED,
            ),
            $status,
            $marker,
            $current->candidateEffect(),
            $current->candidatePostState(),
            $failureCode,
            $safeMessage
        );
    }

    /** @param array<int,string> $from @param array<string,mixed>|null $effect */
    private function transition(
        int $attemptId,
        array $from,
        string $to,
        ?CartSessionMarker $marker,
        ?array $effect,
        ?CartSnapshot $postState,
        string $failureCode,
        string $safeMessage
    ): CartStepAttempt {
        $current = $this->requireById($attemptId, true);
        $aggregate = new CartStepAttempt(
            $current->id(),
            $current->publicId(),
            $current->stepId(),
            $current->attemptNumber(),
            $current->conversationFence(),
            $current->commerceResourceHash(),
            $current->commerceFence(),
            $to,
            $marker !== null ? $marker->digest() : '',
            $marker !== null ? $marker->toArray() : null,
            $effect,
            $postState,
            $failureCode,
            $safeMessage
        );
        if ($current->status() === $to) {
            if ($this->sameEvidence($current, $aggregate)) {
                return $current;
            }
            throw new RuntimeException('Cart step attempt already has different durable evidence.');
        }
        if (!in_array($current->status(), $from, true)) {
            throw new RuntimeException('Cart step attempt transition is not allowed.');
        }
        global $wpdb;
        $terminal = CartStepAttemptStatus::isTerminal($to);
        $base = array(
            $aggregate->status(),
            $aggregate->markerDigest(),
            $aggregate->marker() !== null ? Json::encodeObject($aggregate->marker()) : '',
            $aggregate->candidateEffect() !== null ? Json::encodeObject($aggregate->candidateEffect()) : '',
            $aggregate->candidatePostState() !== null ? Json::encodeObject($aggregate->candidatePostState()->toStorageArray()) : '',
            $aggregate->failureCode(),
            $aggregate->safeMessage(),
            gmdate('Y-m-d H:i:s'),
        );
        $sql = 'UPDATE ' . self::table()
            . ' SET status=%s,marker_digest=%s,marker=%s,candidate_effect=%s,candidate_post_state=%s,'
            . ' failure_code=%s,safe_message=%s,updated_at=%s';
        if ($terminal) {
            $sql .= ',completed_at=%s';
            $base[] = gmdate('Y-m-d H:i:s');
        }
        $sql .= ' WHERE id=%d AND status=%s';
        $base[] = $attemptId;
        $base[] = $current->status();
        $updated = $wpdb->query($wpdb->prepare($sql, $base));
        $this->assertWrite($updated, 'Unable to persist cart step attempt transition.');
        return $this->requireById($attemptId);
    }

    private function requireMarker(CartStepAttempt $attempt): CartSessionMarker
    {
        $row = $attempt->marker();
        if ($row === null) {
            throw new RuntimeException('Cart step attempt marker is missing.');
        }
        return CartSessionMarker::fromArray($row);
    }

    private function requireById(int $attemptId, bool $forUpdate = false): CartStepAttempt
    {
        if ($attemptId < 1) {
            throw new InvalidArgumentException('Cart step attempt ID is invalid.');
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id=%d LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''), $attemptId),
            ARRAY_A
        );
        $this->assertRead('Unable to read cart step attempt.');
        if (!is_array($row)) {
            throw new RuntimeException('Cart step attempt no longer exists.');
        }
        return $this->hydrate($row);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): CartStepAttempt
    {
        $marker = Json::decodeOptionalObject((string) ($row['marker'] ?? ''), 'Cart attempt marker');
        $effect = Json::decodeOptionalObject((string) ($row['candidate_effect'] ?? ''), 'Cart attempt candidate effect');
        $post = Json::decodeOptionalObject((string) ($row['candidate_post_state'] ?? ''), 'Cart attempt candidate post-state');
        return new CartStepAttempt(
            (int) ($row['id'] ?? 0),
            (string) ($row['public_id'] ?? ''),
            (int) ($row['step_id'] ?? 0),
            (int) ($row['attempt_number'] ?? 0),
            (int) ($row['conversation_fence'] ?? 0),
            (string) ($row['commerce_resource_hash'] ?? ''),
            (int) ($row['commerce_fence'] ?? 0),
            (string) ($row['status'] ?? ''),
            (string) ($row['marker_digest'] ?? ''),
            $marker !== array() ? $marker : null,
            $effect !== array() ? $effect : null,
            $post !== array() ? CartSnapshot::fromStorageArray($post) : null,
            (string) ($row['failure_code'] ?? ''),
            (string) ($row['safe_message'] ?? '')
        );
    }

    private static function table(): string
    {
        return SchemaRegistry::operationStepAttempts();
    }

    private function sameEvidence(CartStepAttempt $left, CartStepAttempt $right): bool
    {
        return $left->status() === $right->status()
            && hash_equals($left->markerDigest(), $right->markerDigest())
            && $this->sameOptionalArray($left->marker(), $right->marker())
            && $this->sameOptionalArray($left->candidateEffect(), $right->candidateEffect())
            && $this->sameOptionalSnapshot($left->candidatePostState(), $right->candidatePostState())
            && hash_equals($left->failureCode(), $right->failureCode())
            && hash_equals($left->safeMessage(), $right->safeMessage());
    }

    /** @param array<string,mixed>|null $left @param array<string,mixed>|null $right */
    private function sameOptionalArray(?array $left, ?array $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        return hash_equals(Json::canonical($left), Json::canonical($right));
    }

    private function sameOptionalSnapshot(?CartSnapshot $left, ?CartSnapshot $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        return hash_equals(
            Json::canonical($left->toStorageArray()),
            Json::canonical($right->toStorageArray())
        );
    }

    /** @param int|false $result */
    private function assertWrite($result, string $message): void
    {
        global $wpdb;
        if ($result === false || WpdbError::has($wpdb)) {
            throw new RuntimeException($message);
        }
    }

    private function assertRead(string $message): void
    {
        global $wpdb;
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException($message);
        }
    }
}
