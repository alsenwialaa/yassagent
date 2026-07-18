<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use YassinStore\AiAssistant\Application\Port\ConversationStorePort;
use YassinStore\AiAssistant\Application\Port\TurnLeasePort;
use RuntimeException;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Domain\Chat\ConversationState;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Support\Base64Url;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Uuid;

/** Canonical conversation identity, session binding, and typed state store. */
final class ConversationRepository implements ConversationStorePort
{
    /** @var Settings */ private $settings;
    /** @var TransactionManager */ private $transactions;
    /** @var TurnLeasePort */ private $leases;
    /** @var ActiveWorkInspector */ private $activeWork;

    public function __construct(
        Settings $settings,
        TransactionManager $transactions,
        TurnLeasePort $leases,
        ActiveWorkInspector $activeWork
    ) {
        $this->settings = $settings;
        $this->transactions = $transactions;
        $this->leases = $leases;
        $this->activeWork = $activeWork;
    }

    /** @return array<string,mixed> */
    public function createOrResume(
        string $sessionHash,
        TurnLease $lease
    ): array {
        $sessionHash = strtolower(trim($sessionHash));
        if (
            preg_match('/^[a-f0-9]{64}$/', $sessionHash) !== 1
            || !hash_equals('boot|' . $sessionHash, $lease->resource())
        ) {
            throw new RuntimeException('Conversation creation authority is invalid.');
        }

        return $this->transactions->run(function () use ($sessionHash, $lease): array {
            global $wpdb;
            // Locking the durable boot lease in this same transaction prevents
            // a lease takeover from racing the client lookup/insert even if the
            // original wall-clock lease expires while this transaction runs.
            $this->leases->assertCurrentForUpdate($lease);

            $now = time();
            $nowSql = gmdate('Y-m-d H:i:s', $now);
            $retentionCutoff = gmdate(
                'Y-m-d H:i:s',
                $now - ($this->retentionDays() * DAY_IN_SECONDS)
            );
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM ' . SchemaRegistry::conversations()
                    . ' WHERE session_hash = %s AND expires_at > %s AND updated_at > %s'
                    . ' ORDER BY id DESC LIMIT 2 FOR UPDATE',
                    $sessionHash,
                    $nowSql,
                    $retentionCutoff
                ),
                ARRAY_A
            );
            if (WpdbError::has($wpdb)) {
                throw new RuntimeException('Unable to lock browser conversation mapping.');
            }
            if (!is_array($rows) || count($rows) > 1) {
                throw new RuntimeException('Browser conversation mapping is ambiguous.');
            }

            if (count($rows) === 1) {
                $row = $rows[0];
                if (!is_array($row)) {
                    throw new RuntimeException('Browser conversation mapping is invalid.');
                }
                $conversation = $this->hydrate($row);
                $accessToken = $this->accessToken(
                    (string) $conversation['public_id'],
                    $sessionHash
                );
                if (
                    !hash_equals(
                        (string) ($row['access_hash'] ?? ''),
                        $this->hashAccessToken($accessToken)
                    )
                ) {
                    // Access authority has one exact derivation. A mismatch is
                    // never adopted or treated as a prior-version credential:
                    // retire the mapping and create a clean conversation after
                    // proving no live request can still own the old identity.
                    if (
                        $this->activeWork->hasForConversationWithoutLock(
                            (int) $conversation['id'],
                            (string) $conversation['public_id']
                        )
                    ) {
                        throw new RuntimeException(
                            'Browser conversation access authority cannot be replaced while work is active.'
                        );
                    }
                    $this->retireMapping($conversation, (string) $row['access_hash'], $now);
                } else {
                    $expiresAt = $this->expirySql($now);
                    $updated = $wpdb->update(
                        SchemaRegistry::conversations(),
                        array('updated_at' => $nowSql, 'expires_at' => $expiresAt),
                        array('id' => (int) $conversation['id']),
                        array('%s', '%s'),
                        array('%d')
                    );
                    if ($updated === false || WpdbError::has($wpdb)) { // @phpstan-ignore booleanOr.rightAlwaysFalse (wpdb last_error is mutable at runtime)
                        throw new RuntimeException('Unable to refresh browser conversation mapping.');
                    }
                    if (
                        $updated === 0
                        && ((string) ($row['updated_at'] ?? '') !== $nowSql
                            || (string) ($row['expires_at'] ?? '') !== $expiresAt)
                    ) {
                        throw new RuntimeException('Browser conversation mapping refresh did not affect the locked row.');
                    }
                    $conversation['access_token'] = $accessToken;
                    $conversation['updated_at'] = $now;
                    $conversation['expires_at'] = $this->utcTimestamp($expiresAt);
                    return $conversation;
                }
            }

            $publicId = Uuid::v4();
            $accessToken = $this->accessToken($publicId, $sessionHash);
            $state = ConversationState::initial()->toArray();
            $expiresAt = $this->expirySql($now);
            $inserted = $wpdb->insert(
                SchemaRegistry::conversations(),
                array(
                    'public_id' => $publicId,
                    'access_hash' => $this->hashAccessToken($accessToken),
                    'session_hash' => $sessionHash,
                    'state' => Json::encodeObject($state),
                    'created_at' => $nowSql,
                    'updated_at' => $nowSql,
                    'expires_at' => $expiresAt,
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
            if (
                $inserted !== 1
                || WpdbError::has($wpdb) // @phpstan-ignore booleanOr.rightAlwaysFalse (wpdb last_error is mutable at runtime)
                || (int) $wpdb->insert_id < 1
            ) {
                throw new RuntimeException('Unable to create browser conversation mapping.');
            }

            return array(
                'id' => (int) $wpdb->insert_id,
                'public_id' => $publicId,
                'access_token' => $accessToken,
                'session_hash' => $sessionHash,
                'state' => $state,
                'created_at' => $now,
                'updated_at' => $now,
                'expires_at' => $this->utcTimestamp($expiresAt),
            );
        });
    }

    /** @return array<string,mixed>|null */
    public function resume(string $publicId, string $accessToken, string $sessionHash): ?array
    {
        if (
            !Uuid::isV4($publicId) || strlen($accessToken) < 24 || strlen($accessToken) > 180
            || preg_match('/^[a-f0-9]{64}$/', $sessionHash) !== 1
        ) {
            return null;
        }

        $accessHash = $this->hashAccessToken($accessToken);
        return $this->transactions->run(function () use ($publicId, $accessToken, $accessHash, $sessionHash): ?array {
            global $wpdb;
            $now = time();
            $nowSql = gmdate('Y-m-d H:i:s', $now);
            $retentionCutoff = gmdate(
                'Y-m-d H:i:s',
                $now - ($this->retentionDays() * DAY_IN_SECONDS)
            );
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM ' . SchemaRegistry::conversations()
                    . ' WHERE public_id = %s AND access_hash = %s AND session_hash = %s'
                    . ' AND expires_at > %s AND updated_at > %s LIMIT 1 FOR UPDATE',
                    $publicId,
                    $accessHash,
                    $sessionHash,
                    $nowSql,
                    $retentionCutoff
                ),
                ARRAY_A
            );
            if (WpdbError::has($wpdb)) {
                throw new RuntimeException('Unable to lock conversation state for resume.');
            }
            if (!is_array($row)) {
                return null;
            }

            // The locked row cannot be removed by scheduled cleanup between
            // authentication and retention extension. This closes the former
            // unlocked read -> touch race that could return a deleted identity.
            $updatedAt = $nowSql;
            $expiresAt = $this->expirySql($now);
            $updated = $wpdb->update(
                SchemaRegistry::conversations(),
                array(
                    'updated_at' => $updatedAt,
                    'expires_at' => $expiresAt,
                ),
                array('id' => (int) ($row['id'] ?? 0)),
                array('%s', '%s'),
                array('%d')
            );
            if ($updated === false || WpdbError::has($wpdb)) { // @phpstan-ignore booleanOr.rightAlwaysFalse (wpdb last_error is mutable at runtime)
                throw new RuntimeException('Unable to refresh conversation retention.');
            }
            if (
                $updated === 0
                && ((string) ($row['updated_at'] ?? '') !== $updatedAt
                    || (string) ($row['expires_at'] ?? '') !== $expiresAt)
            ) {
                throw new RuntimeException('Conversation retention refresh did not affect the locked row.');
            }

            $row['updated_at'] = $updatedAt;
            $row['expires_at'] = $expiresAt;
            $conversation = $this->hydrate($row);
            $conversation['access_token'] = $accessToken;
            return $conversation;
        });
    }

    /** @return array<string,mixed>|null */
    public function reload(int $conversationId): ?array
    {
        global $wpdb;
        if ($conversationId < 1) {
            throw new RuntimeException('Conversation identity is invalid.');
        }
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . SchemaRegistry::conversations() . ' WHERE id = %d LIMIT 1',
                $conversationId
            ),
            ARRAY_A
        );
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to reload conversation state.');
        }
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Must be called inside a transaction before replacing canonical state.
     *
     * @return array<string,mixed>|null
     */
    public function reloadForUpdate(int $conversationId): ?array
    {
        global $wpdb;
        if ($conversationId < 1) {
            throw new RuntimeException('Conversation identity is invalid.');
        }
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . SchemaRegistry::conversations() . ' WHERE id = %d LIMIT 1 FOR UPDATE',
                $conversationId
            ),
            ARRAY_A
        );
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to lock conversation state.');
        }
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Replaces the complete typed state projection; never recursively merges
     * arbitrary arrays from an earlier turn.
     *
     * @param array<string,mixed> $state
     */
    public function writeState(int $conversationId, array $state): void
    {
        if ($conversationId < 1) {
            throw new RuntimeException('Conversation identity is invalid.');
        }
        $state = ConversationState::fromArray($state)->toArray();
        global $wpdb;
        $now = time();
        $updated = $wpdb->update(
            SchemaRegistry::conversations(),
            array(
                'state' => Json::encodeObject($state),
                'updated_at' => gmdate('Y-m-d H:i:s', $now),
                'expires_at' => $this->expirySql($now),
            ),
            array('id' => $conversationId),
            array('%s', '%s', '%s'),
            array('%d')
        );
        if ($updated === false) {
            throw new RuntimeException('Unable to persist conversation state.');
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrate(array $row): array
    {
        $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
        $publicId = strtolower(trim((string) ($row['public_id'] ?? '')));
        $sessionHash = strtolower(trim((string) ($row['session_hash'] ?? '')));
        $accessHash = strtolower(trim((string) ($row['access_hash'] ?? '')));
        $createdAt = $this->utcTimestamp((string) ($row['created_at'] ?? ''));
        $updatedAt = $this->utcTimestamp((string) ($row['updated_at'] ?? ''));
        $expiresAt = $this->utcTimestamp((string) ($row['expires_at'] ?? ''));
        if (
            $id < 1 || !Uuid::isV4($publicId)
            || preg_match('/^[a-f0-9]{64}$/', $accessHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $sessionHash) !== 1
            || $updatedAt < $createdAt || $expiresAt < $updatedAt
        ) {
            throw new RuntimeException('Canonical conversation evidence is invalid.');
        }
        $state = ConversationState::fromArray(
            Json::decodeRequiredObject((string) ($row['state'] ?? ''), 'Conversation state')
        )->toArray();
        return array(
            'id' => $id,
            'public_id' => $publicId,
            'session_hash' => $sessionHash,
            'state' => $state,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'expires_at' => $expiresAt,
        );
    }

    private function expirySql(int $now): string
    {
        return gmdate('Y-m-d H:i:s', $now + ($this->retentionDays() * DAY_IN_SECONDS));
    }

    private function retentionDays(): int
    {
        return max(1, min(3650, (int) $this->settings->get('conversation_retention_days', 45)));
    }

    private function utcTimestamp(string $value): int
    {
        if ($value === '') {
            throw new RuntimeException('Conversation timestamp is missing.');
        }
        $timestamp = strtotime($value . ' UTC');
        if ($timestamp === false || $timestamp < 1) {
            throw new RuntimeException('Conversation timestamp is invalid.');
        }
        return $timestamp;
    }

    private function hashAccessToken(string $token): string
    {
        return hash_hmac('sha256', $token, wp_salt('secure_auth'));
    }

    private function accessToken(string $publicId, string $sessionHash): string
    {
        return Base64Url::encode(hash_hmac(
            'sha256',
            'ysai-conversation-access-v1|' . (string) get_current_blog_id()
                . '|' . $publicId . '|' . $sessionHash,
            wp_salt('secure_auth'),
            true
        ));
    }

    /** @param array<string,mixed> $conversation */
    private function retireMapping(array $conversation, string $accessHash, int $now): void
    {
        global $wpdb;
        $publicId = (string) $conversation['public_id'];
        $sessionHash = (string) $conversation['session_hash'];
        $retiredAt = gmdate('Y-m-d H:i:s', max(1, $now - 1));
        $retiredSessionHash = hash_hmac(
            'sha256',
            'ysai-retired-conversation-session-v1|' . (string) $conversation['id']
                . '|' . $publicId . '|' . $sessionHash,
            wp_salt('secure_auth')
        );
        $retiredAccessHash = hash_hmac(
            'sha256',
            'ysai-retired-conversation-access-v1|' . $publicId . '|' . $accessHash,
            wp_salt('secure_auth')
        );
        $updated = $wpdb->update(
            SchemaRegistry::conversations(),
            array(
                'access_hash' => $retiredAccessHash,
                'session_hash' => $retiredSessionHash,
                'updated_at' => $retiredAt,
                'expires_at' => $retiredAt,
            ),
            array(
                'id' => (int) $conversation['id'],
                'public_id' => $publicId,
                'session_hash' => $sessionHash,
                'access_hash' => $accessHash,
            ),
            array('%s', '%s', '%s', '%s'),
            array('%d', '%s', '%s', '%s')
        );
        if ($updated !== 1 || WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to retire invalid browser conversation authority.');
        }
    }
}
