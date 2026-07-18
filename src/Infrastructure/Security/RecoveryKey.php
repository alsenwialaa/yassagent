<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Security;

use RuntimeException;
use Throwable;

/** Stable install-scoped key for proof that must survive WordPress salt rotation. */
final class RecoveryKey
{
    public const OPTION = 'ysai_recovery_key';

    public function ensure(): void
    {
        $stored = get_option(self::OPTION, '');
        if (is_string($stored) && $this->valid($stored)) {
            return;
        }
        if ($stored !== '' && $stored !== false) {
            throw new RuntimeException('The durable recovery key is malformed.');
        }
        try {
            $candidate = bin2hex(random_bytes(32));
        } catch (Throwable $exception) {
            throw new RuntimeException('A durable recovery key could not be generated.', 0, $exception);
        }
        add_option(self::OPTION, $candidate, '', false);
        $persisted = get_option(self::OPTION, '');
        if (!is_string($persisted) || !$this->valid($persisted)) {
            throw new RuntimeException('The durable recovery key could not be persisted exactly.');
        }
    }

    public function key(): string
    {
        $stored = get_option(self::OPTION, '');
        if (!is_string($stored) || !$this->valid($stored)) {
            throw new RuntimeException('The durable recovery key is unavailable.');
        }
        $decoded = hex2bin($stored);
        if (!is_string($decoded) || strlen($decoded) !== 32) {
            throw new RuntimeException('The durable recovery key is invalid.');
        }
        return $decoded;
    }

    private function valid(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }
}
