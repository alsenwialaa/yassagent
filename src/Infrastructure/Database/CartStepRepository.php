<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use InvalidArgumentException;
use RuntimeException;
use YassinStore\AiAssistant\Domain\Commerce\CartOperationStep;
use YassinStore\AiAssistant\Domain\Commerce\CartPrimitive;
use YassinStore\AiAssistant\Domain\Commerce\CartSnapshot;
use YassinStore\AiAssistant\Domain\Commerce\CartStepStatus;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Uuid;

/** Durable logical-step store. Intent columns are immutable after preparation. */
final class CartStepRepository
{
    /** @return array<int,CartOperationStep> */
    public function findByOperation(int $operationId): array
    {
        if ($operationId < 1) {
            throw new InvalidArgumentException('Operation ID is invalid.');
        }
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE operation_id = %d ORDER BY step_index ASC', $operationId),
            ARRAY_A
        );
        $this->assertRead('Unable to read durable cart steps.');
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Durable cart step row is invalid.');
            }
            $out[] = $this->hydrate($row);
        }
        foreach ($out as $index => $step) {
            if ($step->stepIndex() !== $index) {
                throw new RuntimeException('Durable cart steps are not a contiguous prefix.');
            }
        }
        return $out;
    }

    public function findByIndex(int $operationId, int $stepIndex, bool $forUpdate = false): ?CartOperationStep
    {
        if ($operationId < 1 || $stepIndex < 0 || $stepIndex > 4095) {
            throw new InvalidArgumentException('Cart step identity is invalid.');
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE operation_id = %d AND step_index = %d LIMIT 1'
                . ($forUpdate ? ' FOR UPDATE' : ''),
                $operationId,
                $stepIndex
            ),
            ARRAY_A
        );
        $this->assertRead('Unable to read durable cart step.');
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function prepare(
        int $operationId,
        int $stepIndex,
        CartPrimitive $primitive,
        CartSnapshot $preState,
        int $conversationFence,
        string $commerceResourceHash,
        int $commerceFence
    ): CartOperationStep {
        $commerceResourceHash = strtolower(trim($commerceResourceHash));
        if (
            $operationId < 1 || $stepIndex < 0 || $stepIndex > 4095 || $conversationFence < 1
            || preg_match('/^[a-f0-9]{64}$/', $commerceResourceHash) !== 1 || $commerceFence < 1
        ) {
            throw new InvalidArgumentException('Prepared cart step identity is invalid.');
        }
        $commandHash = hash('sha256', Json::canonical(array(
            'operation_id' => $operationId,
            'step_index' => $stepIndex,
            'primitive' => $primitive->toStorageArray(),
            'pre_revision' => $preState->revision(),
            'pre_restoration_revision' => $preState->restorationRevision(),
            'commerce_resource_hash' => $commerceResourceHash,
        )));
        $existing = $this->findByIndex($operationId, $stepIndex, true);
        if ($existing !== null) {
            $this->assertSameIntent($existing, $commandHash, $primitive, $preState, $commerceResourceHash);
            return $existing;
        }

        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . self::table()
            . ' (public_id,operation_id,step_index,command_index,command_hash,conversation_fence,'
            . ' commerce_resource_hash,commerce_fence,status,primitive,pre_state,effect,post_state,'
            . ' marker_digest,failure_code,safe_message,created_at,updated_at,completed_at)'
            . ' VALUES (%s,%d,%d,%d,%s,%d,%s,%d,%s,%s,%s,NULL,NULL,%s,%s,%s,%s,%s,NULL)',
            Uuid::v4(),
            $operationId,
            $stepIndex,
            $primitive->commandIndex(),
            $commandHash,
            $conversationFence,
            $commerceResourceHash,
            $commerceFence,
            CartStepStatus::PREPARED,
            Json::encodeObject($primitive->toStorageArray()),
            Json::encodeObject($preState->toStorageArray()),
            '',
            '',
            '',
            $now,
            $now
        ));
        $this->assertWrite($inserted, 'Unable to prepare durable cart step.');
        $stored = $this->findByIndex($operationId, $stepIndex);
        if ($stored === null) {
            throw new RuntimeException('Prepared cart step was not persisted.');
        }
        $this->assertSameIntent($stored, $commandHash, $primitive, $preState, $commerceResourceHash);
        return $stored;
    }

    public function adopt(
        CartOperationStep $step,
        int $conversationFence,
        string $commerceResourceHash,
        int $commerceFence
    ): CartOperationStep {
        if ($step->isTerminal()) {
            return $step;
        }
        $commerceResourceHash = strtolower(trim($commerceResourceHash));
        if (
            !hash_equals($step->commerceResourceHash(), $commerceResourceHash)
            || $conversationFence < $step->conversationFence() || $commerceFence < $step->commerceFence()
        ) {
            throw new RuntimeException('A cart step cannot be adopted under older authority.');
        }
        if ($conversationFence === $step->conversationFence() && $commerceFence === $step->commerceFence()) {
            return $step;
        }
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table()
            . ' SET conversation_fence=%d,commerce_fence=%d,updated_at=%s'
            . ' WHERE id=%d AND conversation_fence<=%d AND commerce_resource_hash=%s AND commerce_fence<=%d'
            . ' AND status IN (%s,%s)',
            $conversationFence,
            $commerceFence,
            gmdate('Y-m-d H:i:s'),
            $step->id(),
            $conversationFence,
            $commerceResourceHash,
            $commerceFence,
            CartStepStatus::PREPARED,
            CartStepStatus::APPLYING
        ));
        $this->assertWrite($updated, 'Unable to adopt durable cart step.');
        return $this->requireById($step->id());
    }

    public function markApplying(int $stepId, int $conversationFence, int $commerceFence): CartOperationStep
    {
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET status=%s,updated_at=%s'
            . ' WHERE id=%d AND conversation_fence=%d AND commerce_fence=%d AND status=%s',
            CartStepStatus::APPLYING,
            gmdate('Y-m-d H:i:s'),
            $stepId,
            $conversationFence,
            $commerceFence,
            CartStepStatus::PREPARED
        ));
        $this->assertWrite($updated, 'Unable to start durable cart step.');
        $step = $this->requireById($stepId);
        if (
            $step->status() !== CartStepStatus::APPLYING
            || $step->conversationFence() !== $conversationFence || $step->commerceFence() !== $commerceFence
        ) {
            throw new RuntimeException('Durable cart step did not enter applying state.');
        }
        return $step;
    }

    /** @param array<string,mixed> $effect */
    public function markVerified(
        int $stepId,
        int $conversationFence,
        int $commerceFence,
        array $effect,
        CartSnapshot $postState,
        string $markerDigest
    ): CartOperationStep {
        return $this->markTerminal(
            $stepId,
            $conversationFence,
            $commerceFence,
            CartStepStatus::VERIFIED,
            $effect,
            $postState,
            $markerDigest,
            '',
            ''
        );
    }

    /** @param array<string,mixed>|null $effect */
    public function markFailure(
        int $stepId,
        int $conversationFence,
        int $commerceFence,
        string $status,
        string $failureCode,
        string $safeMessage,
        ?array $effect = null,
        ?CartSnapshot $postState = null,
        string $markerDigest = ''
    ): CartOperationStep {
        if (!in_array($status, array(CartStepStatus::REJECTED, CartStepStatus::UNCERTAIN), true)) {
            throw new InvalidArgumentException('Cart step failure status is invalid.');
        }
        return $this->markTerminal(
            $stepId,
            $conversationFence,
            $commerceFence,
            $status,
            $effect,
            $postState,
            $markerDigest,
            $failureCode,
            $safeMessage
        );
    }

    /** @param array<string,mixed>|null $effect */
    private function markTerminal(
        int $stepId,
        int $conversationFence,
        int $commerceFence,
        string $status,
        ?array $effect,
        ?CartSnapshot $postState,
        string $markerDigest,
        string $failureCode,
        string $safeMessage
    ): CartOperationStep {
        $current = $this->requireById($stepId, true);
        if ($current->conversationFence() !== $conversationFence || $current->commerceFence() !== $commerceFence) {
            throw new RuntimeException('Unable to finalize cart step under stale authority.');
        }
        $aggregate = new CartOperationStep(
            $current->id(),
            $current->publicId(),
            $current->operationId(),
            $current->stepIndex(),
            $current->commandIndex(),
            $current->commandHash(),
            $current->conversationFence(),
            $current->commerceResourceHash(),
            $current->commerceFence(),
            $status,
            $current->primitive(),
            $current->preState(),
            $effect,
            $postState,
            $markerDigest,
            $failureCode,
            $safeMessage
        );
        if ($current->isTerminal()) {
            if ($current->status() === $status && $this->sameTerminalEvidence($current, $aggregate)) {
                return $current;
            }
            throw new RuntimeException('Cart step already has different terminal evidence.');
        }

        // Validate the complete aggregate before its single terminal write.
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table()
            . ' SET status=%s,effect=%s,post_state=%s,marker_digest=%s,failure_code=%s,safe_message=%s,'
            . ' updated_at=%s,completed_at=%s WHERE id=%d AND conversation_fence=%d AND commerce_fence=%d'
            . ' AND status IN (%s,%s)',
            $status,
            $effect !== null ? Json::encodeObject($effect) : '',
            $postState !== null ? Json::encodeObject($postState->toStorageArray()) : '',
            $markerDigest,
            $failureCode,
            $safeMessage,
            $now,
            $now,
            $stepId,
            $conversationFence,
            $commerceFence,
            CartStepStatus::PREPARED,
            CartStepStatus::APPLYING
        ));
        $this->assertWrite($updated, 'Unable to finalize durable cart step.');
        return $this->requireById($stepId);
    }

    private function requireById(int $stepId, bool $forUpdate = false): CartOperationStep
    {
        if ($stepId < 1) {
            throw new InvalidArgumentException('Cart step ID is invalid.');
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id=%d LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''), $stepId),
            ARRAY_A
        );
        $this->assertRead('Unable to read durable cart step.');
        if (!is_array($row)) {
            throw new RuntimeException('Durable cart step no longer exists.');
        }
        return $this->hydrate($row);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): CartOperationStep
    {
        $effect = Json::decodeOptionalObject((string) ($row['effect'] ?? ''), 'Cart step effect');
        $post = Json::decodeOptionalObject((string) ($row['post_state'] ?? ''), 'Cart step post-state');
        return new CartOperationStep(
            (int) ($row['id'] ?? 0),
            (string) ($row['public_id'] ?? ''),
            (int) ($row['operation_id'] ?? 0),
            (int) ($row['step_index'] ?? -1),
            (int) ($row['command_index'] ?? -1),
            (string) ($row['command_hash'] ?? ''),
            (int) ($row['conversation_fence'] ?? 0),
            (string) ($row['commerce_resource_hash'] ?? ''),
            (int) ($row['commerce_fence'] ?? 0),
            (string) ($row['status'] ?? ''),
            CartPrimitive::fromStorageArray(Json::decodeRequiredObject((string) ($row['primitive'] ?? ''), 'Cart step primitive')),
            CartSnapshot::fromStorageArray(Json::decodeRequiredObject((string) ($row['pre_state'] ?? ''), 'Cart step pre-state')),
            $effect !== array() ? $effect : null,
            $post !== array() ? CartSnapshot::fromStorageArray($post) : null,
            (string) ($row['marker_digest'] ?? ''),
            (string) ($row['failure_code'] ?? ''),
            (string) ($row['safe_message'] ?? '')
        );
    }

    private function assertSameIntent(
        CartOperationStep $step,
        string $commandHash,
        CartPrimitive $primitive,
        CartSnapshot $preState,
        string $commerceResourceHash
    ): void {
        if (
            !hash_equals($step->commandHash(), $commandHash)
            || !hash_equals($step->commerceResourceHash(), $commerceResourceHash)
            || !hash_equals(Json::canonical($step->primitive()->toStorageArray()), Json::canonical($primitive->toStorageArray()))
            || !hash_equals($step->preState()->revision(), $preState->revision())
            || !hash_equals($step->preState()->restorationRevision(), $preState->restorationRevision())
        ) {
            throw new RuntimeException('A cart step index already has different durable intent.');
        }
    }

    private static function table(): string
    {
        return SchemaRegistry::operationSteps();
    }

    private function sameTerminalEvidence(CartOperationStep $left, CartOperationStep $right): bool
    {
        return $this->sameOptionalEffect($left->effect(), $right->effect())
            && $this->sameOptionalSnapshot($left->postState(), $right->postState())
            && hash_equals($left->markerDigest(), $right->markerDigest())
            && hash_equals($left->failureCode(), $right->failureCode())
            && hash_equals($left->safeMessage(), $right->safeMessage());
    }

    /** @param array<string,mixed>|null $left @param array<string,mixed>|null $right */
    private function sameOptionalEffect(?array $left, ?array $right): bool
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
