<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

interface ClockPort
{
    public function now(): int;
}
