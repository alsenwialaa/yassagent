<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Commerce;

use InvalidArgumentException;
use YassinStore\AiAssistant\Application\Execution\TurnExecutionSupervisor;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Support\Uuid;

final class CommerceExecutionContext
{
    /** @var int */ private $conversationId;
    /** @var string */ private $conversationPublicId;
    /** @var string */ private $turnId;
    /** @var TurnLease */ private $lease;
    /** @var TurnExecutionSupervisor|null */ private $supervisor;

    public function __construct(
        int $conversationId,
        string $conversationPublicId,
        string $turnId,
        TurnLease $lease,
        ?TurnExecutionSupervisor $supervisor = null
    ) {
        $conversationPublicId = strtolower(trim($conversationPublicId));
        $turnId = strtolower(trim($turnId));
        $supervisedLease = $supervisor !== null ? $supervisor->lease() : $lease;
        if (
            $conversationId < 1 || !Uuid::isV4($conversationPublicId) || !Uuid::isV4($turnId)
            || !hash_equals('conversation|' . $conversationPublicId, $lease->resource())
            || !hash_equals($lease->resourceHash(), $supervisedLease->resourceHash())
            || !hash_equals($lease->owner(), $supervisedLease->owner())
            || $lease->fence() !== $supervisedLease->fence()
        ) {
            throw new InvalidArgumentException('Commerce execution authority is invalid.');
        }
        $this->conversationId = $conversationId;
        $this->conversationPublicId = $conversationPublicId;
        $this->turnId = $turnId;
        $this->lease = $lease;
        $this->supervisor = $supervisor;
    }

    public function conversationId(): int
    {
        return $this->conversationId;
    }
    public function conversationPublicId(): string
    {
        return $this->conversationPublicId;
    }
    public function turnId(): string
    {
        return $this->turnId;
    }
    public function lease(): TurnLease
    {
        return $this->supervisor !== null ? $this->supervisor->lease() : $this->lease;
    }
    public function supervisor(): ?TurnExecutionSupervisor
    {
        return $this->supervisor;
    }
}
