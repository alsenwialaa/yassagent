<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool;

use InvalidArgumentException;
use RuntimeException;
use YassinStore\AiAssistant\Domain\Commerce\ActionReceipt;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Json;

/** Closed result envelope shared by every tool handler. */
final class ToolExecutionResult
{
    private const MAX_SAFE_MESSAGE_BYTES = 4096;
    private const MAX_DATA_BYTES = 131072;

    /** @var bool */ private $ok;
    /** @var string */ private $code;
    /** @var array<string,mixed> */ private $data;
    /** @var string */ private $safeMessage;
    /** @var ActionReceipt|null */ private $receipt;

    /** @param array<string,mixed> $data */
    private function __construct(bool $ok, string $code, array $data, string $safeMessage, ?ActionReceipt $receipt)
    {
        $code = trim($code);
        $safeMessage = trim($safeMessage);
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) !== 1) {
            throw new InvalidArgumentException('Tool result code is invalid.');
        }
        if ($safeMessage !== '' && strlen($safeMessage) > self::MAX_SAFE_MESSAGE_BYTES) {
            throw new InvalidArgumentException('Tool safe message exceeds the size limit.');
        }
        if ($data !== array() && Arr::isList($data)) {
            throw new InvalidArgumentException('Tool result data must be a JSON object.');
        }
        try {
            if (strlen(Json::encodeObject($data)) > self::MAX_DATA_BYTES) {
                throw new InvalidArgumentException('Tool result data exceeds the size limit.');
            }
        } catch (RuntimeException $exception) {
            throw new InvalidArgumentException('Tool result data is not JSON encodable.', 0, $exception);
        }

        if ($receipt !== null) {
            if (!$ok || $code !== 'verified_action' || !hash_equals($receipt->safeMessage(), $safeMessage)) {
                throw new InvalidArgumentException('A receipt is valid only for an exact verified-action result.');
            }
        } elseif ($code === 'verified_action') {
            throw new InvalidArgumentException('A verified-action result requires a receipt.');
        }
        if ($ok && !in_array($code, array('ok', 'verified_action'), true)) {
            throw new InvalidArgumentException('A successful tool result has an invalid code.');
        }
        if (!$ok && in_array($code, array('ok', 'verified_action'), true)) {
            throw new InvalidArgumentException('A failed tool result has a success code.');
        }

        $this->ok = $ok;
        $this->code = $code;
        $this->data = $data;
        $this->safeMessage = $safeMessage;
        $this->receipt = $receipt;
    }

    /** @param array<string,mixed> $data */
    public static function success(array $data = array(), string $safeMessage = ''): self
    {
        return new self(true, 'ok', $data, $safeMessage, null);
    }

    /** @param array<string,mixed> $data */
    public static function verified(ActionReceipt $receipt, array $data = array()): self
    {
        return new self(true, 'verified_action', $data, $receipt->safeMessage(), $receipt);
    }

    /** @param array<string,mixed> $data */
    public static function failure(string $code, string $safeMessage = '', array $data = array()): self
    {
        return new self(false, $code, $data, $safeMessage, null);
    }

    public function code(): string
    {
        return $this->code;
    }
    /** @return array<string,mixed> */ public function data(): array
    {
        return $this->data;
    }
    public function safeMessage(): string
    {
        return $this->safeMessage;
    }
    public function receipt(): ?ActionReceipt
    {
        return $this->receipt;
    }

    /** @return array<string,mixed> */
    public function forModel(): array
    {
        $payload = array('ok' => $this->ok, 'code' => $this->code, 'data' => $this->data);
        if ($this->safeMessage !== '') {
            $payload['safe_message'] = $this->safeMessage;
        }
        if ($this->receipt !== null) {
            $payload['mutation_verified'] = true;
            $payload['receipt_id'] = $this->receipt->publicId();
        }
        return $payload;
    }
}
