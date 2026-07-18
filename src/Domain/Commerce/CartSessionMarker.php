<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Uuid;

/** Strict signed marker stored beside the cart in the authoritative Woo session row. */
final class CartSessionMarker
{
    public const VERSION = 1;
    public const INTENT = 'intent';
    public const SEALED = 'sealed';

    /** @var array<string,mixed> */ private $payload;
    /** @var string */ private $signature;

    /** @param array<string,mixed> $payload */
    public function __construct(array $payload, string $signature)
    {
        $expected = array(
            'attempt_id', 'cart_payload_hash', 'command_hash', 'commerce_fence',
            'commerce_resource_hash', 'conversation_fence', 'effect_hash', 'issued_at',
            'operation_id', 'phase', 'post_restoration_revision', 'post_revision',
            'pre_restoration_revision', 'pre_revision', 'session_binding', 'step_id',
            'step_index', 'v',
        );
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        $signature = strtolower(trim($signature));
        if (
            $keys !== $expected
            || !is_int($payload['v']) || $payload['v'] !== self::VERSION
            || !is_string($payload['session_binding']) || !$this->hash($payload['session_binding'])
            || !is_string($payload['operation_id']) || !Uuid::isV4($payload['operation_id'])
            || !is_string($payload['step_id']) || !Uuid::isV4($payload['step_id'])
            || !is_string($payload['attempt_id']) || !Uuid::isV4($payload['attempt_id'])
            || !is_int($payload['step_index']) || $payload['step_index'] < 0 || $payload['step_index'] > 4095
            || !is_string($payload['command_hash']) || !$this->hash($payload['command_hash'])
            || !is_int($payload['conversation_fence']) || $payload['conversation_fence'] < 1
            || !is_string($payload['commerce_resource_hash']) || !$this->hash($payload['commerce_resource_hash'])
            || !is_int($payload['commerce_fence']) || $payload['commerce_fence'] < 1
            || !is_string($payload['pre_revision']) || !$this->hash($payload['pre_revision'])
            || !is_string($payload['pre_restoration_revision']) || !$this->hash($payload['pre_restoration_revision'])
            || !is_string($payload['phase']) || !in_array($payload['phase'], array(self::INTENT, self::SEALED), true)
            || !is_string($payload['effect_hash']) || !$this->optionalHash($payload['effect_hash'])
            || !is_string($payload['post_revision']) || !$this->optionalHash($payload['post_revision'])
            || !is_string($payload['post_restoration_revision']) || !$this->optionalHash($payload['post_restoration_revision'])
            || !is_string($payload['cart_payload_hash']) || !$this->optionalHash($payload['cart_payload_hash'])
            || !is_int($payload['issued_at']) || $payload['issued_at'] < 1
            || !$this->hash($signature)
        ) {
            throw new InvalidArgumentException('Cart session operation marker is invalid.');
        }
        if ($payload['phase'] === self::INTENT) {
            if (
                $payload['effect_hash'] !== '' || $payload['post_revision'] !== ''
                || $payload['post_restoration_revision'] !== '' || $payload['cart_payload_hash'] !== ''
            ) {
                throw new InvalidArgumentException('Intent cart marker contains post-state evidence.');
            }
        } elseif (
            $payload['effect_hash'] === '' || $payload['post_revision'] === ''
            || $payload['post_restoration_revision'] === '' || $payload['cart_payload_hash'] === ''
        ) {
            throw new InvalidArgumentException('Sealed cart marker evidence is incomplete.');
        }
        $this->payload = $payload;
        $this->signature = $signature;
    }

    /** @return array<string,mixed> */ public function payload(): array
    {
        return $this->payload;
    }
    public function signature(): string
    {
        return $this->signature;
    }
    public function phase(): string
    {
        return (string) $this->payload['phase'];
    }
    public function attemptId(): string
    {
        return (string) $this->payload['attempt_id'];
    }
    public function stepId(): string
    {
        return (string) $this->payload['step_id'];
    }
    public function operationId(): string
    {
        return (string) $this->payload['operation_id'];
    }
    public function stepIndex(): int
    {
        return (int) $this->payload['step_index'];
    }
    public function commandHash(): string
    {
        return (string) $this->payload['command_hash'];
    }
    public function sessionBinding(): string
    {
        return (string) $this->payload['session_binding'];
    }
    public function conversationFence(): int
    {
        return (int) $this->payload['conversation_fence'];
    }
    public function commerceResourceHash(): string
    {
        return (string) $this->payload['commerce_resource_hash'];
    }
    public function commerceFence(): int
    {
        return (int) $this->payload['commerce_fence'];
    }
    public function preRevision(): string
    {
        return (string) $this->payload['pre_revision'];
    }
    public function preRestorationRevision(): string
    {
        return (string) $this->payload['pre_restoration_revision'];
    }
    public function postRevision(): string
    {
        return (string) $this->payload['post_revision'];
    }
    public function postRestorationRevision(): string
    {
        return (string) $this->payload['post_restoration_revision'];
    }
    public function effectHash(): string
    {
        return (string) $this->payload['effect_hash'];
    }
    public function cartPayloadHash(): string
    {
        return (string) $this->payload['cart_payload_hash'];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array('payload' => $this->payload, 'signature' => $this->signature);
    }

    /** @param array<string,mixed> $row */
    public static function fromArray(array $row): self
    {
        $keys = array_keys($row);
        sort($keys, SORT_STRING);
        if ($keys !== array('payload', 'signature') || !is_array($row['payload']) || !is_string($row['signature'])) {
            throw new InvalidArgumentException('Stored cart marker envelope is invalid.');
        }
        return new self($row['payload'], $row['signature']);
    }

    public function digest(): string
    {
        return hash('sha256', Json::canonical($this->toArray()));
    }

    private function hash(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    private function optionalHash(string $value): bool
    {
        return $value === '' || $this->hash($value);
    }
}
