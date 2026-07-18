<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

/** Releases supported cart serialization before a slow provider wait. */
interface ProviderWaitIsolationPort
{
    public function releaseForProviderWait(): void;
}
