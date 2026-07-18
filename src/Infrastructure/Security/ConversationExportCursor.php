<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Security;

use RuntimeException;
use YassinStore\AiAssistant\Support\Base64Url;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Uuid;

/** Signed, authority-bound high-water state for one coherent conversation export. */
final class ConversationExportCursor
{
    private const VERSION = 1;
    // A continuation can be rolled while the same bounded snapshot remains
    // valid. This matches the two-hour signed session lifetime without placing
    // a page-count ceiling on a large, legally retained conversation.
    private const TTL = 7200;
    private const CLOCK_SKEW = 60;
    private const MAX_SNAPSHOT_AGE = 315446400; // Maximum retention (3650 days) plus one day.
    private const MAX_TOKEN_BYTES = 2048;
    private const STREAMS = array(
        'message' => 'messages',
        'receipt' => 'receipts',
        'turn' => 'turns',
        'operation' => 'operations',
        'step' => 'steps',
        'attempt' => 'attempts',
    );

    /** @var callable */ private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static function (): int {
            return time();
        };
    }

    /**
     * @param array<string,mixed> $authority
     * @return array<string,mixed>
     */
    public function start(array $authority, array $highWater): array
    {
        $keys = array_keys($highWater);
        sort($keys, SORT_STRING);
        $expected = array_values(self::STREAMS);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new RuntimeException('Conversation export high-water marks are invalid.');
        }
        foreach ($highWater as $value) {
            if (!is_int($value) || $value < 0) {
                throw new RuntimeException('Conversation export high-water marks are invalid.');
            }
        }
        $now = $this->now();
        $createdAt = $this->authorityTimestamp($authority, 'created_at');
        $updatedAt = $this->authorityTimestamp($authority, 'updated_at');
        $expiresAt = $this->authorityTimestamp($authority, 'expires_at');
        $state = array(
            'v' => self::VERSION,
            'authority_binding' => $this->authorityBinding($authority),
            'state_binding' => $this->stateBinding($authority),
            'snapshot_at' => $now,
            'snapshot_created_at' => $createdAt,
            'snapshot_updated_at' => $updatedAt,
            'snapshot_expires_at' => $expiresAt,
            'iat' => $now,
            'exp' => $now + self::TTL,
        );
        foreach (self::STREAMS as $singular => $plural) {
            $high = $highWater[$plural];
            $state[$singular . '_after'] = 0;
            $state[$singular . '_high'] = $high;
            $state[$plural . '_done'] = $high === 0;
        }
        $this->assertState($state, $authority);
        return $state;
    }

    /** @param array<string,mixed> $authority @return array<string,mixed> */
    public function open(string $token, array $authority): array
    {
        if (
            $token === ''
            || strlen($token) > self::MAX_TOKEN_BYTES
            || substr_count($token, '.') !== 1
        ) {
            throw new ConversationExportCursorInvalid('Conversation export cursor is malformed.');
        }
        list($encoded, $signature) = explode('.', $token, 2);
        if (
            $encoded === ''
            || preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1
            || !hash_equals($this->sign($encoded), $signature)
        ) {
            throw new ConversationExportCursorInvalid('Conversation export cursor signature is invalid.');
        }

        try {
            $decoded = $this->base64UrlDecode($encoded);
            if (!hash_equals($encoded, $this->base64UrlEncode($decoded))) {
                throw new ConversationExportCursorInvalid('Conversation export cursor encoding is not canonical.');
            }
            $state = Json::decodeRequiredObject($decoded, 'Conversation export cursor payload');
            $this->assertState($state, $authority);
        } catch (ConversationExportCursorInvalid $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw new ConversationExportCursorInvalid(
                'Conversation export cursor payload is invalid.',
                0,
                $exception
            );
        }
        return $state;
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $authority */
    public function seal(array $state, array $authority): string
    {
        $now = $this->now();
        $state['iat'] = $now;
        $state['exp'] = $now + self::TTL;
        $this->assertState($state, $authority);
        $encoded = $this->base64UrlEncode(Json::encodeObject($state));
        return $encoded . '.' . $this->sign($encoded);
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $authority */
    private function assertState(array $state, array $authority): void
    {
        $keys = array_keys($state);
        sort($keys, SORT_STRING);
        $expectedKeys = array(
            'authority_binding',
            'exp',
            'iat',
            'snapshot_at',
            'snapshot_created_at',
            'snapshot_expires_at',
            'snapshot_updated_at',
            'state_binding',
            'v',
        );
        foreach (self::STREAMS as $singular => $plural) {
            $expectedKeys[] = $singular . '_after';
            $expectedKeys[] = $singular . '_high';
            $expectedKeys[] = $plural . '_done';
        }
        sort($expectedKeys, SORT_STRING);
        if ($keys !== $expectedKeys) {
            throw new ConversationExportCursorInvalid('Conversation export cursor shape is invalid.');
        }

        foreach (
            array(
            'v',
            'iat',
            'exp',
            'snapshot_at',
            'snapshot_created_at',
            'snapshot_updated_at',
            'snapshot_expires_at',
            ) as $key
        ) {
            if (!is_int($state[$key])) {
                throw new ConversationExportCursorInvalid('Conversation export cursor field type is invalid.');
            }
        }
        foreach (self::STREAMS as $singular => $plural) {
            foreach (array($singular . '_after', $singular . '_high') as $key) {
                if (!is_int($state[$key])) {
                    throw new ConversationExportCursorInvalid('Conversation export cursor field type is invalid.');
                }
            }
            if (!is_bool($state[$plural . '_done'])) {
                throw new ConversationExportCursorInvalid('Conversation export cursor claim type is invalid.');
            }
        }
        if (
            !is_string($state['authority_binding'])
            || preg_match('/^[a-f0-9]{64}$/', $state['authority_binding']) !== 1
            || !is_string($state['state_binding'])
            || preg_match('/^[a-f0-9]{64}$/', $state['state_binding']) !== 1
        ) {
            throw new ConversationExportCursorInvalid('Conversation export cursor claim type is invalid.');
        }

        $now = $this->now();
        if (
            $state['v'] !== self::VERSION
            || $state['iat'] < 1
            || $state['iat'] > $now + self::CLOCK_SKEW
            || $state['exp'] - $state['iat'] !== self::TTL
            || $state['exp'] <= $now
            || $state['snapshot_at'] < 1
            || $state['snapshot_at'] > $now + self::CLOCK_SKEW
            || $state['snapshot_at'] <= $now - self::MAX_SNAPSHOT_AGE
            || $state['snapshot_created_at'] < 1
            || $state['snapshot_created_at'] > $state['snapshot_updated_at']
            || $state['snapshot_updated_at'] > $state['snapshot_expires_at']
            || $state['snapshot_updated_at'] > $state['snapshot_at'] + self::CLOCK_SKEW
            || !hash_equals($this->authorityBinding($authority), $state['authority_binding'])
            || !hash_equals($this->stateBinding($authority), $state['state_binding'])
        ) {
            throw new ConversationExportCursorInvalid('Conversation export cursor claims are invalid.');
        }
        foreach (self::STREAMS as $singular => $plural) {
            if (
                $state[$singular . '_after'] < 0
                || $state[$singular . '_high'] < $state[$singular . '_after']
                || $state[$plural . '_done'] !== (
                    $state[$singular . '_after'] === $state[$singular . '_high']
                )
            ) {
                throw new ConversationExportCursorInvalid('Conversation export cursor claims are invalid.');
            }
        }
    }

    /** @param array<string,mixed> $authority */
    private function stateBinding(array $authority): string
    {
        $state = $authority['state'] ?? null;
        if (!is_array($state)) {
            throw new ConversationExportCursorInvalid('Conversation export snapshot state is invalid.');
        }
        return hash_hmac(
            'sha256',
            'ysai-conversation-export-state-v1|' . Json::canonicalObject($state),
            wp_salt('auth')
        );
    }

    /** @param array<string,mixed> $authority */
    private function authorityTimestamp(array $authority, string $key): int
    {
        $value = $authority[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new ConversationExportCursorInvalid('Conversation export snapshot timestamp is invalid.');
        }
        return $value;
    }

    /** @param array<string,mixed> $authority */
    private function authorityBinding(array $authority): string
    {
        $publicId = strtolower(trim((string) ($authority['public_id'] ?? '')));
        $sessionHash = strtolower(trim((string) ($authority['session_hash'] ?? '')));
        if (!Uuid::isV4($publicId) || preg_match('/^[a-f0-9]{64}$/', $sessionHash) !== 1) {
            throw new ConversationExportCursorInvalid('Conversation export cursor authority is invalid.');
        }
        return hash_hmac(
            'sha256',
            'ysai-conversation-export-v1|' . (string) get_current_blog_id()
                . '|' . $publicId . '|' . $sessionHash,
            wp_salt('auth')
        );
    }

    private function sign(string $encoded): string
    {
        return hash_hmac('sha256', 'ysai-conversation-export-v1|' . $encoded, wp_salt('nonce'));
    }

    private function base64UrlEncode(string $value): string
    {
        return Base64Url::encode($value);
    }

    private function base64UrlDecode(string $value): string
    {
        try {
            return Base64Url::decode($value);
        } catch (\InvalidArgumentException $exception) {
            throw new ConversationExportCursorInvalid(
                'Conversation export cursor encoding is invalid.',
                0,
                $exception
            );
        }
    }

    private function now(): int
    {
        $now = call_user_func($this->clock);
        if (!is_int($now) || $now < 1) {
            throw new RuntimeException('Conversation export cursor clock is invalid.');
        }
        return $now;
    }
}
