<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use InvalidArgumentException;
use RuntimeException;
use YassinStore\AiAssistant\Domain\Commerce\ActionReceipt;
use YassinStore\AiAssistant\Domain\Commerce\AppliedCartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartSnapshot;
use YassinStore\AiAssistant\Domain\Commerce\OperationRecord;
use YassinStore\AiAssistant\Domain\Commerce\OperationStatus;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Utf8;
use YassinStore\AiAssistant\Support\Uuid;

final class OperationRepository
{
    private const MAX_SAFE_MESSAGE_CODE_POINTS = 4096;
    private const MAX_SAFE_MESSAGE_BYTES = 16384;

    public function findByTurn(int $conversationId, string $turnId): ?OperationRecord
    {
        $this->assertTurnIdentity($conversationId, $turnId);
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . SchemaRegistry::operations()
                . ' WHERE conversation_id = %d AND turn_id = %s LIMIT 1',
                $conversationId,
                strtolower($turnId)
            ),
            ARRAY_A
        );
        $this->assertDatabaseRead('Unable to read durable cart operation.');
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function prepare(
        int $conversationId,
        string $turnId,
        string $operationKey,
        int $leaseFence,
        string $commerceResourceHash,
        int $commerceFence,
        CartPlan $plan,
        CartSnapshot $preState
    ): OperationRecord {
        $this->assertTurnIdentity($conversationId, $turnId);
        $operationKey = strtolower(trim($operationKey));
        $commerceResourceHash = strtolower(trim($commerceResourceHash));
        if (
            preg_match('/^[a-f0-9]{64}$/', $operationKey) !== 1 || $leaseFence < 1
            || preg_match('/^[a-f0-9]{64}$/', $commerceResourceHash) !== 1 || $commerceFence < 1
        ) {
            throw new InvalidArgumentException('Prepared operation identity is invalid.');
        }

        global $wpdb;
        $existing = $this->findByTurn($conversationId, $turnId);
        if ($existing !== null) {
            if (!hash_equals($existing->operationKey(), $operationKey)) {
                throw new RuntimeException('A turn cannot execute more than one distinct cart operation.');
            }
            return $existing;
        }

        $now = gmdate('Y-m-d H:i:s');
        $sql = $wpdb->prepare(
            'INSERT IGNORE INTO ' . SchemaRegistry::operations()
            . ' (public_id,conversation_id,turn_id,operation_key,lease_fence,commerce_resource_hash,commerce_fence,status,plan,pre_state,'
            . ' applied_effects,post_state,receipt,failure_code,safe_message,created_at,updated_at,completed_at)'
            . ' VALUES (%s,%d,%s,%s,%d,%s,%d,%s,%s,%s,NULL,NULL,NULL,%s,%s,%s,%s,NULL)',
            Uuid::v4(),
            $conversationId,
            strtolower($turnId),
            $operationKey,
            $leaseFence,
            $commerceResourceHash,
            $commerceFence,
            OperationStatus::PREPARED,
            Json::encodeObject($plan->toStorageArray()),
            Json::encodeObject($preState->toStorageArray()),
            '',
            '',
            $now,
            $now
        );
        $inserted = $wpdb->query($sql);
        if ($inserted === false || WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to persist prepared cart operation.');
        }

        $stored = $this->findByTurn($conversationId, $turnId);
        if ($stored === null || !hash_equals($stored->operationKey(), $operationKey)) {
            throw new RuntimeException('A turn cannot execute more than one distinct cart operation.');
        }
        return $stored;
    }

    public function adopt(
        OperationRecord $operation,
        int $leaseFence,
        string $commerceResourceHash,
        int $commerceFence
    ): OperationRecord {
        if ($operation->isTerminal()) {
            return $operation;
        }
        $commerceResourceHash = strtolower(trim($commerceResourceHash));
        if (
            $leaseFence < 1 || $leaseFence < $operation->leaseFence()
            || !hash_equals($operation->commerceResourceHash(), $commerceResourceHash)
            || $commerceFence < $operation->commerceFence()
        ) {
            throw new InvalidArgumentException('An operation cannot be adopted by an older fence.');
        }
        if ($leaseFence === $operation->leaseFence() && $commerceFence === $operation->commerceFence()) {
            return $operation;
        }

        global $wpdb;
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . SchemaRegistry::operations()
                . ' SET lease_fence = %d, commerce_fence = %d, updated_at = %s'
                . ' WHERE id = %d AND lease_fence <= %d AND commerce_resource_hash = %s'
                . ' AND commerce_fence <= %d AND status IN (%s,%s)',
                $leaseFence,
                $commerceFence,
                gmdate('Y-m-d H:i:s'),
                $operation->id(),
                $leaseFence,
                $commerceResourceHash,
                $commerceFence,
                OperationStatus::PREPARED,
                OperationStatus::EXECUTING
            )
        );
        $this->assertDatabaseWrite($updated, 'Unable to adopt durable cart operation.');
        $current = $this->requireById($operation->id());
        if ($current->isTerminal()) {
            return $current;
        }
        if ($current->leaseFence() !== $leaseFence || $current->commerceFence() !== $commerceFence) {
            throw new RuntimeException('Unable to adopt durable cart operation under the current fence.');
        }
        return $current;
    }

    public function markExecuting(int $operationId, int $leaseFence, int $commerceFence): OperationRecord
    {
        $this->assertMutationIdentity($operationId, $leaseFence, $commerceFence);
        global $wpdb;
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . SchemaRegistry::operations()
                . ' SET status = %s, updated_at = %s'
                . ' WHERE id = %d AND lease_fence = %d AND commerce_fence = %d AND status = %s',
                OperationStatus::EXECUTING,
                gmdate('Y-m-d H:i:s'),
                $operationId,
                $leaseFence,
                $commerceFence,
                OperationStatus::PREPARED
            )
        );
        $this->assertDatabaseWrite($updated, 'Unable to start the prepared cart operation.');
        $current = $this->requireById($operationId);
        if (
            $current->status() !== OperationStatus::EXECUTING || $current->leaseFence() !== $leaseFence
            || $current->commerceFence() !== $commerceFence
        ) {
            throw new RuntimeException('Unable to start the prepared cart operation.');
        }
        return $current;
    }

    public function recordApplied(int $operationId, int $leaseFence, int $commerceFence, AppliedCartPlan $applied): void
    {
        $this->assertMutationIdentity($operationId, $leaseFence, $commerceFence);
        if ($applied->isEmpty()) {
            throw new InvalidArgumentException('An empty effect is not durable mutation evidence.');
        }

        global $wpdb;
        $current = $this->requireById($operationId, true);
        if (
            $current->leaseFence() !== $leaseFence || $current->commerceFence() !== $commerceFence
            || $current->status() !== OperationStatus::EXECUTING
        ) {
            throw new RuntimeException('Unable to persist effects for a non-current operation.');
        }
        $this->assertAppliedPlan($current->plan(), $applied);
        if ($current->applied() !== null) {
            if ($this->sameApplied($current->applied(), $applied)) {
                return;
            }
            throw new RuntimeException('Durable cart operation effects are write-once and do not match.');
        }

        $encoded = Json::encodeObject($applied->toStorageArray());
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . SchemaRegistry::operations()
                . ' SET applied_effects = %s, updated_at = %s'
                . ' WHERE id = %d AND lease_fence = %d AND commerce_fence = %d AND status = %s'
                . " AND (applied_effects IS NULL OR applied_effects = '')",
                $encoded,
                gmdate('Y-m-d H:i:s'),
                $operationId,
                $leaseFence,
                $commerceFence,
                OperationStatus::EXECUTING
            )
        );
        $this->assertDatabaseWrite($updated, 'Unable to persist cart operation effects.');
        $stored = $this->requireById($operationId);
        if ($stored->applied() === null || !$this->sameApplied($stored->applied(), $applied)) {
            throw new RuntimeException('Unable to persist exact cart operation effects.');
        }
    }

    public function markVerified(
        int $operationId,
        int $leaseFence,
        int $commerceFence,
        CartSnapshot $postState,
        ActionReceipt $receipt
    ): OperationRecord {
        return $this->markTerminal(
            $operationId,
            $leaseFence,
            $commerceFence,
            OperationStatus::VERIFIED,
            $postState,
            $receipt,
            '',
            $receipt->safeMessage()
        );
    }

    public function markRejected(
        int $operationId,
        int $leaseFence,
        int $commerceFence,
        string $failureCode,
        string $safeMessage,
        ?CartSnapshot $observedState = null
    ): OperationRecord {
        return $this->markTerminal(
            $operationId,
            $leaseFence,
            $commerceFence,
            OperationStatus::REJECTED,
            $observedState,
            null,
            $failureCode,
            $safeMessage
        );
    }

    public function markUncertain(
        int $operationId,
        int $leaseFence,
        int $commerceFence,
        string $failureCode,
        string $safeMessage,
        ?CartSnapshot $postState = null
    ): OperationRecord {
        return $this->markTerminal(
            $operationId,
            $leaseFence,
            $commerceFence,
            OperationStatus::UNCERTAIN,
            $postState,
            null,
            $failureCode,
            $safeMessage
        );
    }

    private function markTerminal(
        int $operationId,
        int $leaseFence,
        int $commerceFence,
        string $status,
        ?CartSnapshot $postState,
        ?ActionReceipt $receipt,
        string $failureCode,
        string $safeMessage
    ): OperationRecord {
        $this->assertMutationIdentity($operationId, $leaseFence, $commerceFence);
        if (!OperationStatus::isTerminal($status)) {
            throw new InvalidArgumentException('Operation terminal status is invalid.');
        }
        $failureCode = trim($failureCode);
        $safeMessage = trim($safeMessage);
        if ($failureCode !== '' && preg_match('/^[a-z0-9_]{1,64}$/', $failureCode) !== 1) {
            throw new InvalidArgumentException('Operation failure code is invalid.');
        }
        if (
            !Utf8::isBounded(
                $safeMessage,
                self::MAX_SAFE_MESSAGE_CODE_POINTS,
                self::MAX_SAFE_MESSAGE_BYTES
            )
        ) {
            throw new InvalidArgumentException('Operation safe message is invalid.');
        }

        global $wpdb;
        $current = $this->requireById($operationId, true);
        if ($current->leaseFence() !== $leaseFence || $current->commerceFence() !== $commerceFence) {
            throw new RuntimeException('Unable to persist terminal state under a stale operation fence.');
        }
        if ($current->isTerminal()) {
            if ($this->terminalMatches($current, $status, $postState, $receipt, $failureCode, $safeMessage)) {
                return $current;
            }
            throw new RuntimeException('Durable cart operation already has different terminal evidence.');
        }

        // Validate the requested terminal aggregate before writing it. This
        // prevents a failed hydration from leaving contradictory durable state
        // when callers accidentally omit required evidence.
        new OperationRecord(
            $current->id(),
            $current->publicId(),
            $current->conversationId(),
            $current->turnId(),
            $current->operationKey(),
            $current->leaseFence(),
            $status,
            $current->plan(),
            $current->preState(),
            $current->applied(),
            $postState,
            $receipt,
            $failureCode,
            $safeMessage,
            $current->commerceResourceHash(),
            $current->commerceFence()
        );

        $now = gmdate('Y-m-d H:i:s');
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . SchemaRegistry::operations()
                . ' SET status = %s, post_state = %s, receipt = %s, failure_code = %s,'
                . ' safe_message = %s, updated_at = %s, completed_at = %s'
                . ' WHERE id = %d AND lease_fence = %d AND commerce_fence = %d AND status IN (%s,%s)',
                $status,
                $postState !== null ? Json::encodeObject($postState->toStorageArray()) : '',
                $receipt !== null ? Json::encodeObject($receipt->toArray()) : '',
                $failureCode,
                $safeMessage,
                $now,
                $now,
                $operationId,
                $leaseFence,
                $commerceFence,
                OperationStatus::PREPARED,
                OperationStatus::EXECUTING
            )
        );
        $this->assertDatabaseWrite($updated, 'Unable to persist terminal cart operation state.');
        $stored = $this->requireById($operationId);
        if (!$this->terminalMatches($stored, $status, $postState, $receipt, $failureCode, $safeMessage)) {
            throw new RuntimeException('Durable terminal cart operation evidence does not match the requested result.');
        }
        return $stored;
    }

    private function requireById(int $id, bool $forUpdate = false): OperationRecord
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Operation ID is invalid.');
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . SchemaRegistry::operations() . ' WHERE id = %d LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''),
                $id
            ),
            ARRAY_A
        );
        $this->assertDatabaseRead('Unable to read durable cart operation.');
        if (!is_array($row)) {
            throw new RuntimeException('Durable cart operation no longer exists.');
        }
        return $this->hydrate($row);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): OperationRecord
    {
        $appliedRaw = Json::decodeOptionalObject((string) ($row['applied_effects'] ?? ''), 'Operation applied effects');
        $postRaw = Json::decodeOptionalObject((string) ($row['post_state'] ?? ''), 'Operation post-state');
        $receiptRaw = Json::decodeOptionalObject((string) ($row['receipt'] ?? ''), 'Operation receipt');
        return new OperationRecord(
            (int) ($row['id'] ?? 0),
            (string) ($row['public_id'] ?? ''),
            (int) ($row['conversation_id'] ?? 0),
            (string) ($row['turn_id'] ?? ''),
            (string) ($row['operation_key'] ?? ''),
            (int) ($row['lease_fence'] ?? 0),
            (string) ($row['status'] ?? ''),
            CartPlan::fromStorageArray(Json::decodeRequiredObject((string) ($row['plan'] ?? ''), 'Operation plan')),
            CartSnapshot::fromStorageArray(Json::decodeRequiredObject((string) ($row['pre_state'] ?? ''), 'Operation pre-state')),
            $appliedRaw !== array() ? AppliedCartPlan::fromStorageArray($appliedRaw) : null,
            $postRaw !== array() ? CartSnapshot::fromStorageArray($postRaw) : null,
            $receiptRaw !== array() ? ActionReceipt::fromArray($receiptRaw) : null,
            (string) ($row['failure_code'] ?? ''),
            (string) ($row['safe_message'] ?? ''),
            (string) ($row['commerce_resource_hash'] ?? ''),
            (int) ($row['commerce_fence'] ?? 0)
        );
    }

    private function assertTurnIdentity(int $conversationId, string $turnId): void
    {
        if ($conversationId < 1 || !Uuid::isV4(strtolower(trim($turnId)))) {
            throw new InvalidArgumentException('Operation turn identity is invalid.');
        }
    }

    private function assertMutationIdentity(int $operationId, int $leaseFence, int $commerceFence): void
    {
        if ($operationId < 1 || $leaseFence < 1 || $commerceFence < 1) {
            throw new InvalidArgumentException('Operation mutation identity is invalid.');
        }
    }

    private function assertAppliedPlan(CartPlan $plan, AppliedCartPlan $applied): void
    {
        if ($applied->count() !== count($plan->commands())) {
            throw new InvalidArgumentException('Applied effects do not match the durable plan.');
        }
        foreach ($applied->effects() as $index => $effect) {
            if (($effect['type'] ?? '') !== $plan->commands()[$index]->type()) {
                throw new InvalidArgumentException('Applied effects do not match the durable plan order.');
            }
        }
    }

    private function sameApplied(AppliedCartPlan $left, AppliedCartPlan $right): bool
    {
        return hash_equals(Json::canonical($left->toStorageArray()), Json::canonical($right->toStorageArray()));
    }

    private function terminalMatches(
        OperationRecord $current,
        string $status,
        ?CartSnapshot $postState,
        ?ActionReceipt $receipt,
        string $failureCode,
        string $safeMessage
    ): bool {
        return $current->status() === $status
            && $this->sameOptionalSnapshot($current->postState(), $postState)
            && $this->sameOptionalReceipt($current->receipt(), $receipt)
            && hash_equals($current->failureCode(), $failureCode)
            && hash_equals($current->safeMessage(), $safeMessage);
    }

    private function sameOptionalSnapshot(?CartSnapshot $left, ?CartSnapshot $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        return hash_equals(Json::canonical($left->toStorageArray()), Json::canonical($right->toStorageArray()));
    }

    private function sameOptionalReceipt(?ActionReceipt $left, ?ActionReceipt $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        return hash_equals(Json::canonical($left->toArray()), Json::canonical($right->toArray()));
    }

    /** @param int|false $result */
    private function assertDatabaseWrite($result, string $message): void
    {
        global $wpdb;
        if ($result === false || WpdbError::has($wpdb)) {
            throw new RuntimeException($message);
        }
    }

    private function assertDatabaseRead(string $message): void
    {
        global $wpdb;
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException($message);
        }
    }
}
