<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Utf8;
use YassinStore\AiAssistant\Support\Uuid;

final class ActionReceipt
{
    private const MAX_SAFE_MESSAGE_CODE_POINTS = 4096;
    private const MAX_SAFE_MESSAGE_BYTES = 16384;

    private const MAX_PUBLIC_COUNT = 2147483647;
    private const MAX_PUBLIC_TIMESTAMP = 4294967295;

    /** @var string */ private $publicId;
    /** @var string */ private $action;
    /** @var bool */ private $changed;
    /** @var array<string,mixed> */ private $proof;
    /** @var string */ private $safeMessage;
    /** @var int */ private $createdAt;

    /** @param array<string,mixed> $proof */
    public function __construct(string $action, bool $changed, array $proof, string $safeMessage, ?string $publicId = null, ?int $createdAt = null)
    {
        $action = trim($action);
        $id = $publicId !== null ? strtolower(trim($publicId)) : Uuid::v4();
        $timestamp = $createdAt !== null ? $createdAt : time();
        $safeMessageIsValid = $safeMessage !== '' && Utf8::isBounded(
            $safeMessage,
            self::MAX_SAFE_MESSAGE_CODE_POINTS,
            self::MAX_SAFE_MESSAGE_BYTES
        );
        if (
            preg_match('/^[a-z0-9_]{1,64}$/', $action) !== 1
            || trim($safeMessage) !== $safeMessage
            || !$safeMessageIsValid
            || !Uuid::isV4($id) || $timestamp < 1 || $timestamp > self::MAX_PUBLIC_TIMESTAMP
            || ($proof !== array() && Arr::isList($proof))
        ) {
            throw new InvalidArgumentException('Verified action receipt is invalid.');
        }
        $proof = $this->normalizeProof($proof);
        $sameRevision = hash_equals($proof['before_revision'], $proof['after_revision']);
        if ($changed === $sameRevision) {
            throw new InvalidArgumentException('Verified receipt change flag contradicts its cart revisions.');
        }
        $this->publicId = $id;
        $this->action = $action;
        $this->changed = $changed;
        $this->proof = $proof;
        $this->safeMessage = $safeMessage;
        $this->createdAt = $timestamp;
    }

    public function publicId(): string
    {
        return $this->publicId;
    }
    public function action(): string
    {
        return $this->action;
    }
    public function changed(): bool
    {
        return $this->changed;
    }
    /** @return array<string,mixed> */ public function proof(): array
    {
        return $this->proof;
    }
    public function safeMessage(): string
    {
        return $this->safeMessage;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array(
            'public_id' => $this->publicId,
            'action' => $this->action,
            'changed' => $this->changed,
            'proof' => $this->proof,
            'safe_message' => $this->safeMessage,
            'created_at' => $this->createdAt,
        );
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        if (
            $keys !== array('action', 'changed', 'created_at', 'proof', 'public_id', 'safe_message')
            || !is_string($data['action']) || !is_bool($data['changed'])
            || !is_array($data['proof']) || ($data['proof'] !== array() && Arr::isList($data['proof']))
            || !is_string($data['safe_message']) || !is_string($data['public_id']) || !is_int($data['created_at'])
        ) {
            throw new InvalidArgumentException('Stored action receipt is invalid.');
        }
        return new self($data['action'], $data['changed'], $data['proof'], $data['safe_message'], $data['public_id'], $data['created_at']);
    }

    /** @return array<string,mixed> */
    public function forClient(): array
    {
        $publicProof = array();
        foreach (array('commands', 'cart_count', 'cart_total', 'changed_line_count', 'currency') as $key) {
            $publicProof[$key] = $this->proof[$key];
        }
        return array(
            'id' => $this->publicId,
            'action' => $this->action,
            'changed' => $this->changed,
            'message' => $this->safeMessage,
            'proof' => $publicProof,
            'created_at' => $this->createdAt,
        );
    }

    /** @param array<string,mixed> $proof @return array<string,mixed> */
    private function normalizeProof(array $proof): array
    {
        $keys = array_keys($proof);
        sort($keys, SORT_STRING);
        $expected = array(
            'after_restoration_revision', 'after_revision', 'before_restoration_revision',
            'before_revision', 'cart_count', 'cart_total', 'changed_line_count', 'commands', 'currency',
        );
        if (
            $keys !== $expected
            || !is_array($proof['commands']) || !Arr::isList($proof['commands'])
            || count($proof['commands']) !== 1
            || !is_int($proof['cart_count']) || $proof['cart_count'] < 0
            || $proof['cart_count'] > self::MAX_PUBLIC_COUNT
            || !is_string($proof['cart_total']) || strlen($proof['cart_total']) > 2048
            || !is_string($proof['currency']) || preg_match('/^[A-Z]{3}$/', $proof['currency']) !== 1
            || !is_string($proof['before_revision']) || preg_match('/^[a-f0-9]{64}$/', $proof['before_revision']) !== 1
            || !is_string($proof['after_revision']) || preg_match('/^[a-f0-9]{64}$/', $proof['after_revision']) !== 1
            || !is_string($proof['before_restoration_revision']) || preg_match('/^[a-f0-9]{64}$/', $proof['before_restoration_revision']) !== 1
            || !is_string($proof['after_restoration_revision']) || preg_match('/^[a-f0-9]{64}$/', $proof['after_restoration_revision']) !== 1
            || !is_int($proof['changed_line_count']) || $proof['changed_line_count'] < 0
            || $proof['changed_line_count'] > self::MAX_PUBLIC_COUNT
        ) {
            throw new InvalidArgumentException('Verified receipt proof is invalid.');
        }
        $proof['commands'] = array($this->normalizeCommand($proof['commands'][0]));
        return $proof;
    }

    /** @param mixed $command @return array<string,mixed> */
    private function normalizeCommand($command): array
    {
        if (
            !is_array($command) || ($command !== array() && Arr::isList($command))
            || !isset($command['type']) || !is_string($command['type'])
            || !in_array($command['type'], CartCommand::types(), true)
        ) {
            throw new InvalidArgumentException('Verified receipt command projection is invalid.');
        }
        $type = $command['type'];
        $expected = $type === CartCommand::CLEAR ? array('type') : array('item', 'type');
        if ($type !== CartCommand::CLEAR) {
            $this->assertDisplayName($command['item'] ?? null, 'item');
        }
        if (
            in_array($type, array(
            CartCommand::ADD, CartCommand::UPDATE, CartCommand::REPLACE,
            ), true)
        ) {
            $quantity = $command['quantity'] ?? null;
            if (!CartQuantity::isPositiveInteger($quantity)) {
                throw new InvalidArgumentException('Verified receipt command quantity is invalid.');
            }
            $expected[] = 'quantity';
        }
        $keys = array_keys($command);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('Verified receipt command projection is invalid.');
        }
        return $command;
    }

    /** @param mixed $value */
    private function assertDisplayName($value, string $field): void
    {
        if (!is_string($value) || trim($value) === '' || trim($value) !== $value) {
            throw new InvalidArgumentException('Verified receipt command ' . $field . ' is invalid.');
        }
        try {
            if (Utf8::codePointLength($value) > 500) {
                throw new InvalidArgumentException('Verified receipt command ' . $field . ' is invalid.');
            }
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(
                'Verified receipt command ' . $field . ' is invalid.',
                0,
                $exception
            );
        }
    }
}
