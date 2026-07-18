<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Ai;

/** Optional capability for provider sessions that can bound the next blocking request. */
interface ProviderTimeoutAwareSessionInterface extends ModelSessionInterface
{
    public function setNextTimeoutSeconds(int $seconds): void;
}
