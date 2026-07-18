<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Ai;

/**
 * Optional model-session capability for one bounded retry after the provider
 * exhausts its output budget before producing an executable result.
 */
interface OutputLimitRecoverableSessionInterface extends ModelSessionInterface
{
    public function recoverOutputLimit(ModelStep $step): bool;
}
