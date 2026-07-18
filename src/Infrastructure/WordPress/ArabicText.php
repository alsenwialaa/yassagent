<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WordPress;

use YassinStore\AiAssistant\Application\Port\TextLocalizerPort;

/** Arabic-only application text adapter. */
final class ArabicText implements TextLocalizerPort
{
    public function text(string $arabic): string
    {
        return $arabic;
    }
}
