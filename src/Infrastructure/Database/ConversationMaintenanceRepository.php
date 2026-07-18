<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use RuntimeException;
use YassinStore\AiAssistant\Application\Port\MaintenanceGatePort;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;

/** Retention, administrator listing, and explicit purge operations. */
final class ConversationMaintenanceRepository
{
    private const MAX_CONVERSATION_BATCH = 100;
    private const MAX_CHILD_BATCH = 500;

    /** @var TransactionManager */ private $transactions;
    /** @var Settings */ private $settings;
    /** @var MaintenanceGatePort */ private $maintenanceGate;
    /** @var ActiveWorkInspector */ private $activeWork;

    public function __construct(
        TransactionManager $transactions,
        Settings $settings,
        MaintenanceGatePort $maintenanceGate,
        ActiveWorkInspector $activeWork
    ) {
        $this->transactions = $transactions;
        $this->settings = $settings;
        $this->maintenanceGate = $maintenanceGate;
        $this->activeWork = $activeWork;
    }

    public function cleanupExpired(
        int $conversationLimit = 50,
        int $childLimit = 250,
        ?float $deadlineAt = null
    ): ConversationCleanupBatch {
        $conversationLimit = max(1, min(self::MAX_CONVERSATION_BATCH, $conversationLimit));
        $childLimit = max(1, min(self::MAX_CHILD_BATCH, $childLimit));

        return $this->maintenanceGate->run(function () use (
            $conversationLimit,
            $childLimit,
            $deadlineAt
        ): ConversationCleanupBatch {
            return $this->transactions->run(function () use (
                $conversationLimit,
                $childLimit,
                $deadlineAt
            ): ConversationCleanupBatch {
                global $wpdb;
                $conversationTable = SchemaRegistry::conversations();
                $now = time();
                $nowSql = gmdate('Y-m-d H:i:s', $now);
                $retentionCutoff = gmdate(
                    'Y-m-d H:i:s',
                    $now - ($this->retentionDays() * DAY_IN_SECONDS)
                );
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, public_id FROM {$conversationTable}
                     WHERE (expires_at < %s OR updated_at < %s)
                     ORDER BY id ASC
                     LIMIT %d",
                        $nowSql,
                        $retentionCutoff,
                        $conversationLimit
                    ),
                    ARRAY_A
                );
                $this->assertNoDatabaseError('Unable to select expired conversations.');
                $rows = is_array($rows) ? $rows : array();
                if (count($rows) > $conversationLimit) {
                    throw new RuntimeException('Expired conversation selection exceeded its hard batch limit.');
                }
                if ($rows === array()) {
                    return ConversationCleanupBatch::empty();
                }

                $selectedCount = count($rows);
                $conversationIds = array();
                $publicIds = array();
                $seenIds = array();
                foreach ($rows as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    $publicId = (string) ($row['public_id'] ?? '');
                    if ($id < 1 || $publicId === '') {
                        throw new RuntimeException('Expired conversation identity is corrupt.');
                    }
                    if (isset($seenIds[$id])) {
                        throw new RuntimeException('Expired conversation batch contains duplicate identities.');
                    }
                    $seenIds[$id] = true;
                    // Admission shares this site-wide barrier, so once a live
                    // conversation or commerce lease is observed here no new
                    // work can appear for the selected identity until cleanup
                    // releases the barrier. Skip it before deleting any child.
                    if ($this->activeWork->hasForConversation($id, $publicId)) {
                        continue;
                    }
                    $conversationIds[] = $id;
                    $publicIds[$id] = $publicId;
                }

            // Active-work inspection locks lease authority first. Only after
            // that ordering proof may cleanup lock and revalidate conversation
            // rows, matching the lease -> conversation order used by turns.
                if ($conversationIds !== array()) {
                    $lockedRows = $this->lockExpiredConversations(
                        $conversationIds,
                        $nowSql,
                        $retentionCutoff
                    );
                    $selectedPublicIds = $publicIds;
                    $conversationIds = array();
                    $publicIds = array();
                    foreach ($lockedRows as $row) {
                        $id = (int) ($row['id'] ?? 0);
                        $publicId = (string) ($row['public_id'] ?? '');
                        if (
                            $id < 1
                            || !isset($selectedPublicIds[$id])
                            || !hash_equals($selectedPublicIds[$id], $publicId)
                            || isset($publicIds[$id])
                        ) {
                            throw new RuntimeException('Locked expired conversation identity is corrupt.');
                        }
                        $conversationIds[] = $id;
                        $publicIds[$id] = $publicId;
                    }
                }

                $counts = array(
                'operation_step_attempts' => 0,
                'operation_steps' => 0,
                'operations' => 0,
                'turns' => 0,
                'messages' => 0,
                'leases' => 0,
                );
                $stoppedForDeadline = $this->deadlineReached($deadlineAt);

                if ($conversationIds === array()) {
                    return new ConversationCleanupBatch(
                        $selectedCount,
                        0,
                        $counts,
                        $stoppedForDeadline,
                        true
                    );
                }

                if (!$stoppedForDeadline) {
                    $counts['operation_step_attempts'] = $this->deleteOperationStepAttempts(
                        $conversationIds,
                        $childLimit
                    );
                    $stoppedForDeadline = $this->deadlineReached($deadlineAt);
                }
                if (!$stoppedForDeadline) {
                    $counts['operation_steps'] = $this->deleteOperationSteps(
                        $conversationIds,
                        $childLimit
                    );
                    $stoppedForDeadline = $this->deadlineReached($deadlineAt);
                }
                if (!$stoppedForDeadline) {
                    $counts['operations'] = $this->deleteOperations($conversationIds, $childLimit);
                    $stoppedForDeadline = $this->deadlineReached($deadlineAt);
                }
                if (!$stoppedForDeadline) {
                    $counts['turns'] = $this->deleteConversationChildren(
                        SchemaRegistry::turns(),
                        $conversationIds,
                        $childLimit,
                        'Unable to select expired turn rows.',
                        'Unable to remove expired turn rows.'
                    );
                    $stoppedForDeadline = $this->deadlineReached($deadlineAt);
                }
                if (!$stoppedForDeadline) {
                    $counts['messages'] = $this->deleteConversationChildren(
                        SchemaRegistry::messages(),
                        $conversationIds,
                        $childLimit,
                        'Unable to select expired message rows.',
                        'Unable to remove expired message rows.'
                    );
                    $stoppedForDeadline = $this->deadlineReached($deadlineAt);
                }

                $conversationsDeleted = 0;
                if (!$stoppedForDeadline) {
                    $readyRows = $this->readyConversations($conversationIds, $conversationLimit);
                    if ($readyRows !== array()) {
                        $readyIds = array();
                        $leaseHashes = array();
                        foreach ($readyRows as $row) {
                            $id = (int) ($row['id'] ?? 0);
                            $publicId = (string) ($row['public_id'] ?? '');
                            if ($id < 1 || !isset($publicIds[$id]) || $publicIds[$id] !== $publicId) {
                                throw new RuntimeException('Cleanup-ready conversation identity is corrupt.');
                            }
                            $readyIds[] = $id;
                            $leaseHashes[] = hash('sha256', 'conversation|' . $publicId);
                        }
                        $counts['leases'] = $this->deleteLeaseHashes($leaseHashes);
                        $conversationsDeleted = $this->deleteConversationIds($readyIds);
                    }
                }

                $hasFullChildBatch = false;
                foreach ($counts as $name => $count) {
                    if ($name !== 'leases' && $count === $childLimit) {
                        $hasFullChildBatch = true;
                        break;
                    }
                }
                $hasMore = $stoppedForDeadline
                || $selectedCount === $conversationLimit
                || $conversationsDeleted < $selectedCount
                || $hasFullChildBatch;

                return new ConversationCleanupBatch(
                    $selectedCount,
                    $conversationsDeleted,
                    $counts,
                    $stoppedForDeadline,
                    $hasMore
                );
            });
        });
    }

    /** @return array<int,array<string,mixed>> */
    public function adminList(int $page, int $perPage): array
    {
        global $wpdb;
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT c.*, (SELECT COUNT(*) FROM ' . SchemaRegistry::messages()
                . ' m WHERE m.conversation_id = c.id) AS message_count FROM '
                . SchemaRegistry::conversations() . ' c ORDER BY c.updated_at DESC LIMIT %d OFFSET %d',
                $perPage,
                ($page - 1) * $perPage
            ),
            ARRAY_A
        );
        if (!is_array($rows) || WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to list assistant conversations.');
        }
        return $rows;
    }

    public function adminCount(): int
    {
        global $wpdb;
        $count = $wpdb->get_var('SELECT COUNT(*) FROM ' . SchemaRegistry::conversations());
        if (
            (!is_int($count) && !is_string($count))
            || preg_match('/^[0-9]+$/', (string) $count) !== 1
            || WpdbError::has($wpdb)
        ) {
            throw new RuntimeException('Unable to count assistant conversations.');
        }
        return (int) $count;
    }

    public function purgeAll(): void
    {
        $this->maintenanceGate->run(function (): void {
            $this->transactions->run(function (): void {
                global $wpdb;
                if ($this->activeWork->hasAny()) {
                    throw new ConversationMaintenanceConflict(
                        'Assistant data cannot be purged while work is active.'
                    );
                }

                foreach (
                    array(
                    SchemaRegistry::browserContinuityAuthorities(),
                    SchemaRegistry::operationStepAttempts(),
                    SchemaRegistry::operationSteps(),
                    SchemaRegistry::operations(),
                    SchemaRegistry::turns(),
                    SchemaRegistry::messages(),
                    SchemaRegistry::conversations(),
                    SchemaRegistry::leases(),
                    SchemaRegistry::rateLimits(),
                    ) as $table
                ) {
                    if (
                        $wpdb->query('DELETE FROM ' . $table) === false
                        || WpdbError::has($wpdb)
                    ) {
                        throw new RuntimeException('Unable to purge assistant data.');
                    }
                }
            });
        });
    }

    /** Shortens pre-existing rows when an administrator lowers the policy. */
    public function shortenRetention(int $days): void
    {
        $days = max(1, min(3650, $days));
        global $wpdb;
        $table = SchemaRegistry::conversations();
        $expression = "DATE_ADD(updated_at, INTERVAL {$days} DAY)";
        $updated = $wpdb->query(
            "UPDATE {$table} SET expires_at = {$expression} WHERE expires_at > {$expression}"
        );
        if ($updated === false || WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to apply the shortened conversation-retention policy.');
        }
    }

    private function retentionDays(): int
    {
        return max(1, min(3650, (int) $this->settings->get('conversation_retention_days', 45)));
    }

    /** @param array<int,int> $conversationIds */
    private function deleteOperationStepAttempts(array $conversationIds, int $limit): int
    {
        $conversationPlaceholders = $this->integerPlaceholders($conversationIds);
        $select = 'SELECT a.id FROM ' . SchemaRegistry::operationStepAttempts() . ' a '
            . 'INNER JOIN ' . SchemaRegistry::operationSteps() . ' s ON s.id = a.step_id '
            . 'INNER JOIN ' . SchemaRegistry::operations() . ' o ON o.id = s.operation_id '
            . "WHERE o.conversation_id IN ({$conversationPlaceholders}) "
            . 'ORDER BY a.id ASC LIMIT %d FOR UPDATE';

        return $this->deleteBoundedIds(
            SchemaRegistry::operationStepAttempts(),
            $select,
            array_merge($conversationIds, array($limit)),
            $limit,
            'Unable to select expired cart step attempts.',
            'Unable to remove expired cart step attempts.'
        );
    }

    /** @param array<int,int> $conversationIds */
    private function deleteOperationSteps(array $conversationIds, int $limit): int
    {
        $conversationPlaceholders = $this->integerPlaceholders($conversationIds);
        $select = 'SELECT s.id FROM ' . SchemaRegistry::operationSteps() . ' s '
            . 'INNER JOIN ' . SchemaRegistry::operations() . ' o ON o.id = s.operation_id '
            . "WHERE o.conversation_id IN ({$conversationPlaceholders}) "
            . 'AND NOT EXISTS (SELECT 1 FROM ' . SchemaRegistry::operationStepAttempts()
            . ' a WHERE a.step_id = s.id) '
            . 'ORDER BY s.id ASC LIMIT %d FOR UPDATE';

        return $this->deleteBoundedIds(
            SchemaRegistry::operationSteps(),
            $select,
            array_merge($conversationIds, array($limit)),
            $limit,
            'Unable to select expired cart operation steps.',
            'Unable to remove expired cart operation steps.'
        );
    }

    /** @param array<int,int> $conversationIds */
    private function deleteOperations(array $conversationIds, int $limit): int
    {
        $conversationPlaceholders = $this->integerPlaceholders($conversationIds);
        $select = 'SELECT o.id FROM ' . SchemaRegistry::operations() . ' o '
            . "WHERE o.conversation_id IN ({$conversationPlaceholders}) "
            . 'AND NOT EXISTS (SELECT 1 FROM ' . SchemaRegistry::operationSteps()
            . ' s WHERE s.operation_id = o.id) '
            . 'ORDER BY o.id ASC LIMIT %d FOR UPDATE';

        return $this->deleteBoundedIds(
            SchemaRegistry::operations(),
            $select,
            array_merge($conversationIds, array($limit)),
            $limit,
            'Unable to select expired cart operations.',
            'Unable to remove expired cart operations.'
        );
    }

    /** @param array<int,int> $conversationIds */
    private function deleteConversationChildren(
        string $table,
        array $conversationIds,
        int $limit,
        string $selectFailure,
        string $deleteFailure
    ): int {
        $conversationPlaceholders = $this->integerPlaceholders($conversationIds);
        $select = "SELECT id FROM {$table} WHERE conversation_id IN ({$conversationPlaceholders}) "
            . 'ORDER BY id ASC LIMIT %d FOR UPDATE';

        return $this->deleteBoundedIds(
            $table,
            $select,
            array_merge($conversationIds, array($limit)),
            $limit,
            $selectFailure,
            $deleteFailure
        );
    }

    /**
     * @param array<int,mixed> $selectArgs
     */
    private function deleteBoundedIds(
        string $table,
        string $selectSql,
        array $selectArgs,
        int $limit,
        string $selectFailure,
        string $deleteFailure
    ): int {
        global $wpdb;
        $rawIds = $wpdb->get_col($wpdb->prepare($selectSql, $selectArgs));
        $this->assertNoDatabaseError($selectFailure);
        $rawIds = is_array($rawIds) ? $rawIds : array();
        if (count($rawIds) > $limit) {
            throw new RuntimeException('Cleanup child selection exceeded its hard batch limit.');
        }

        $ids = array();
        foreach ($rawIds as $rawId) {
            $id = (int) $rawId;
            if ($id < 1 || isset($ids[$id])) {
                throw new RuntimeException('Cleanup child identity batch is corrupt.');
            }
            $ids[$id] = $id;
        }
        $ids = array_values($ids);
        if ($ids === array()) {
            return 0;
        }

        $placeholders = $this->integerPlaceholders($ids);
        $deleted = $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids)
        );
        if ($deleted === false) {
            throw new RuntimeException($deleteFailure);
        }
        $this->assertNoDatabaseError($deleteFailure);
        if ((int) $deleted !== count($ids)) {
            throw new RuntimeException('Cleanup child delete did not remove every locked row.');
        }
        return (int) $deleted;
    }

    /**
     * @param array<int,int> $conversationIds
     * @return array<int,array<string,mixed>>
     */
    private function lockExpiredConversations(
        array $conversationIds,
        string $nowSql,
        string $retentionCutoff
    ): array {
        global $wpdb;
        $placeholders = $this->integerPlaceholders($conversationIds);
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, public_id FROM ' . SchemaRegistry::conversations()
                . " WHERE id IN ({$placeholders})"
                . ' AND (expires_at < %s OR updated_at < %s)'
                . ' ORDER BY id ASC LIMIT %d FOR UPDATE',
                array_merge(
                    $conversationIds,
                    array($nowSql, $retentionCutoff, count($conversationIds))
                )
            ),
            ARRAY_A
        );
        $this->assertNoDatabaseError('Unable to lock expired conversations after authority inspection.');
        $rows = is_array($rows) ? $rows : array();
        if (count($rows) > count($conversationIds)) {
            throw new RuntimeException('Locked expired conversation selection exceeded its identity bound.');
        }
        return $rows;
    }

    /**
     * @param array<int,int> $conversationIds
     * @return array<int,array<string,mixed>>
     */
    private function readyConversations(array $conversationIds, int $limit): array
    {
        global $wpdb;
        $placeholders = $this->integerPlaceholders($conversationIds);
        $conversationTable = SchemaRegistry::conversations();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.public_id FROM {$conversationTable} c
                 WHERE c.id IN ({$placeholders})
                   AND NOT EXISTS (SELECT 1 FROM " . SchemaRegistry::operations() . " o WHERE o.conversation_id = c.id)
                   AND NOT EXISTS (SELECT 1 FROM " . SchemaRegistry::turns() . " t WHERE t.conversation_id = c.id)
                   AND NOT EXISTS (SELECT 1 FROM " . SchemaRegistry::messages() . " m WHERE m.conversation_id = c.id)
                 ORDER BY c.id ASC
                 LIMIT %d
                 FOR UPDATE",
                array_merge($conversationIds, array($limit))
            ),
            ARRAY_A
        );
        $this->assertNoDatabaseError('Unable to select cleanup-ready conversations.');
        $rows = is_array($rows) ? $rows : array();
        if (count($rows) > $limit) {
            throw new RuntimeException('Cleanup-ready conversation batch exceeded its hard limit.');
        }
        return $rows;
    }

    /** @param array<int,string> $hashes */
    private function deleteLeaseHashes(array $hashes): int
    {
        if ($hashes === array()) {
            return 0;
        }
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($hashes), '%s'));
        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . SchemaRegistry::leases() . " WHERE resource_hash IN ({$placeholders})",
                $hashes
            )
        );
        if ($deleted === false) {
            throw new RuntimeException('Unable to remove expired conversation leases.');
        }
        $this->assertNoDatabaseError('Unable to remove expired conversation leases.');
        if ((int) $deleted > count($hashes)) {
            throw new RuntimeException('Conversation lease cleanup exceeded its bounded identity set.');
        }
        return (int) $deleted;
    }

    /** @param array<int,int> $ids */
    private function deleteConversationIds(array $ids): int
    {
        if ($ids === array()) {
            return 0;
        }
        global $wpdb;
        $placeholders = $this->integerPlaceholders($ids);
        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . SchemaRegistry::conversations() . " WHERE id IN ({$placeholders})",
                $ids
            )
        );
        if ($deleted === false) {
            throw new RuntimeException('Unable to remove expired conversations.');
        }
        $this->assertNoDatabaseError('Unable to remove expired conversations.');
        if ((int) $deleted !== count($ids)) {
            throw new RuntimeException('Expired conversation purge did not remove every locked row.');
        }
        return (int) $deleted;
    }

    /** @param array<int,int> $ids */
    private function integerPlaceholders(array $ids): string
    {
        if ($ids === array() || count($ids) > self::MAX_CHILD_BATCH) {
            throw new RuntimeException('Cleanup identity batch is outside the hard bound.');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new RuntimeException('Cleanup identity is invalid.');
            }
        }
        return implode(',', array_fill(0, count($ids), '%d'));
    }

    private function deadlineReached(?float $deadlineAt): bool
    {
        return $deadlineAt !== null && microtime(true) >= $deadlineAt;
    }

    private function assertNoDatabaseError(string $message): void
    {
        global $wpdb;
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException($message);
        }
    }
}
