<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Chat;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Uuid;

final class TurnRecord
{
    /** @var int */ private $id;
    /** @var int */ private $conversationId;
    /** @var string */ private $turnId;
    /** @var string */ private $requestHash;
    /** @var string */ private $status;
    /** @var int */ private $leaseFence;
    /** @var array<string,mixed> */ private $response;
    /** @var string */ private $failureCode;

    /** @param array<string,mixed> $input @param array<string,mixed> $response */
    public function __construct(
        int $id,
        int $conversationId,
        string $turnId,
        string $requestHash,
        string $status,
        int $leaseFence,
        array $input,
        array $response,
        string $failureCode,
        int $createdAt,
        int $updatedAt,
        int $completedAt
    ) {
        if (
            $id < 1 || $conversationId < 1 || !Uuid::isV4($turnId)
            || preg_match('/^[a-f0-9]{64}$/', $requestHash) !== 1
            || !in_array($status, TurnStatus::all(), true)
            || $leaseFence < 0
            || ($input !== array() && Arr::isList($input))
            || ($response !== array() && Arr::isList($response))
            || preg_match('/^[a-z0-9_]{0,64}$/', $failureCode) !== 1
            || $createdAt < 1 || $updatedAt < $createdAt || $completedAt < 0
        ) {
            throw new InvalidArgumentException('Durable turn evidence is invalid.');
        }
        if ($status === TurnStatus::RECEIVED && $leaseFence !== 0) {
            throw new InvalidArgumentException('A received turn cannot carry a lease fence.');
        }
        if ($status === TurnStatus::RUNNING && ($leaseFence < 1 || $response !== array() || $completedAt !== 0)) {
            throw new InvalidArgumentException('A running turn has inconsistent authority evidence.');
        }
        if (TurnStatus::isTerminal($status)) {
            if ($response === array() || $completedAt < $createdAt) {
                throw new InvalidArgumentException('A terminal turn requires a response and completion time.');
            }
        } elseif ($response !== array() || $completedAt !== 0 || $failureCode !== '') {
            throw new InvalidArgumentException('A nonterminal turn contains terminal evidence.');
        }

        $this->id = $id;
        $this->conversationId = $conversationId;
        $this->turnId = strtolower($turnId);
        $this->requestHash = $requestHash;
        $this->status = $status;
        $this->leaseFence = $leaseFence;
        $this->response = $response;
        $this->failureCode = $failureCode;
    }

    public function id(): int
    {
        return $this->id;
    }
    public function conversationId(): int
    {
        return $this->conversationId;
    }
    public function turnId(): string
    {
        return $this->turnId;
    }
    public function requestHash(): string
    {
        return $this->requestHash;
    }
    public function status(): string
    {
        return $this->status;
    }
    public function leaseFence(): int
    {
        return $this->leaseFence;
    }
    /** @return array<string,mixed> */ public function response(): array
    {
        return $this->response;
    }
    public function failureCode(): string
    {
        return $this->failureCode;
    }
    public function isTerminal(): bool
    {
        return TurnStatus::isTerminal($this->status);
    }
}
