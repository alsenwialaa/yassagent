<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

use YassinStore\AiAssistant\Domain\Chat\TurnRecord;
use YassinStore\AiAssistant\Domain\Chat\TurnReservation;

interface TurnStorePort
{
    public function find(int $conversationId, string $turnId): ?TurnRecord;
    public function findActive(int $conversationId): ?TurnRecord;
    /** @param array<string,mixed> $input */
    public function reserve(int $conversationId, string $turnId, string $requestHash, array $input): TurnReservation;
    public function claim(TurnRecord $turn, int $fence): TurnRecord;
    public function assertClaimedForUpdate(int $turnId, int $fence): TurnRecord;
    /** @param array<string,mixed> $response */
    /** @param array<string,mixed> $response */
    public function complete(int $turnId, int $fence, string $status, array $response, string $failureCode): void;
}
