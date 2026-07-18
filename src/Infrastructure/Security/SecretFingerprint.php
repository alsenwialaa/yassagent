<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Security;

use YassinStore\AiAssistant\Application\Port\FingerprintPort;
use RuntimeException;

/** Purpose-separated HMAC fingerprints for durable replay and observability. */
final class SecretFingerprint implements FingerprintPort
{
    private const DERIVATION_PURPOSE = 'ysai-durable-fingerprint-v1';

    /** @var string */ private $key;

    public function __construct(string $installKey)
    {
        if (strlen($installKey) !== 32) {
            throw new RuntimeException('The install-scoped fingerprint key is invalid.');
        }
        $this->key = hash_hmac('sha256', self::DERIVATION_PURPOSE, $installKey, true);
    }

    public function digest(string $purpose, string $value): string
    {
        $purpose = trim($purpose);
        if ($purpose === '' || strlen($purpose) > 64) {
            throw new RuntimeException('Fingerprint purpose is invalid.');
        }
        return hash_hmac('sha256', $purpose . "\0" . $value, $this->key);
    }
}
