<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use RuntimeException;
use YassinStore\AiAssistant\Domain\Commerce\OperationStatus;

/** Proves whether an assistant request still owns live database authority. */
final class ActiveWorkInspector
{
    public function hasAny(): bool
    {
        global $wpdb;
        $hash = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT resource_hash FROM ' . SchemaRegistry::leases()
                . ' WHERE lease_until > %s AND (resource LIKE %s OR resource LIKE %s)'
                . ' LIMIT 1 FOR UPDATE',
                gmdate('Y-m-d H:i:s', time()),
                'conversation|%',
                'commerce|%'
            )
        );

        return $this->hasValidHash($hash, 'Unable to inspect live assistant work.');
    }

    public function hasForConversation(int $conversationId, string $publicId): bool
    {
        return $this->hasConversationWork($conversationId, $publicId, true);
    }

    /** Used only while the maintenance barrier already excludes new admission. */
    public function hasForConversationWithoutLock(int $conversationId, string $publicId): bool
    {
        return $this->hasConversationWork($conversationId, $publicId, false);
    }

    private function hasConversationWork(
        int $conversationId,
        string $publicId,
        bool $lockRows
    ): bool {
        if ($conversationId < 1 || trim($publicId) === '') {
            throw new RuntimeException('Conversation work identity is invalid.');
        }

        global $wpdb;
        $locking = $lockRows ? ' FOR UPDATE' : '';
        $resource = 'conversation|' . $publicId;
        $conversationHash = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT resource_hash FROM ' . SchemaRegistry::leases()
                . ' WHERE resource_hash = %s AND resource = %s AND lease_until > %s'
                . ' LIMIT 1' . $locking,
                hash('sha256', $resource),
                $resource,
                gmdate('Y-m-d H:i:s', time())
            )
        );
        if (
            $this->hasValidHash(
                $conversationHash,
                'Unable to inspect the live conversation lease.'
            )
        ) {
            return true;
        }

        $commerceHash = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT l.resource_hash FROM ' . SchemaRegistry::operations() . ' o'
                . ' INNER JOIN ' . SchemaRegistry::leases() . ' l'
                . ' ON l.resource_hash = o.commerce_resource_hash'
                . ' AND l.fence = o.commerce_fence'
                . ' WHERE o.conversation_id = %d AND o.status IN (%s,%s)'
                . ' AND l.resource LIKE %s AND l.lease_until > %s'
                . ' LIMIT 1' . $locking,
                $conversationId,
                OperationStatus::PREPARED,
                OperationStatus::EXECUTING,
                'commerce|%',
                gmdate('Y-m-d H:i:s', time())
            )
        );

        return $this->hasValidHash(
            $commerceHash,
            'Unable to inspect live conversation cart work.'
        );
    }

    /** @param mixed $hash */
    private function hasValidHash($hash, string $databaseFailure): bool
    {
        global $wpdb;
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException($databaseFailure);
        }
        if ($hash === null) {
            return false;
        }
        if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new RuntimeException('Live assistant-work evidence is corrupt.');
        }
        return true;
    }
}
