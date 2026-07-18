<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\System;

use YassinStore\AiAssistant\Application\Port\ClockPort;

final class SystemClock implements ClockPort
{
    public function now(): int
    {
        return time();
    }
}
