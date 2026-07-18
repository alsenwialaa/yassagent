<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

interface TransactionPort
{
    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function run(callable $callback);
}
