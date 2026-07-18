<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;

interface TurnLeasePort
{
    public function acquire(string $resource, int $ttl): ?TurnLease;
    public function renew(TurnLease $lease, int $ttl): TurnLease;
    public function remainingSeconds(TurnLease $lease): float;
    public function assertCurrent(TurnLease $lease): void;
    public function isCurrent(TurnLease $lease): bool;
    public function assertCurrentForUpdate(TurnLease $lease): void;
    public function release(TurnLease $lease): void;
}
