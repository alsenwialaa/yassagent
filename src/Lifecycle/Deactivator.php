<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Lifecycle;

final class Deactivator
{
    /** WordPress deactivation-hook signature; network activation is unsupported. */
    public static function deactivate(bool $networkWide = false): void
    {
        unset($networkWide);
        // Network activation is rejected. If an ordinary installation was
        // converted to multisite later, still unschedule the current site's
        // hook instead of leaving an orphaned task behind.
        Cleanup::unschedule();
    }
}
