<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

/** Serializes turn reservation against destructive global maintenance. */
interface MaintenanceGatePort
{
    /**
     * @template T
     * @param callable():T $critical
     * @return T
     */
    public function run(callable $critical);
}
