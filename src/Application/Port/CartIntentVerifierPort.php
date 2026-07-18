<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

use YassinStore\AiAssistant\Application\Commerce\CartIntentVerdict;
use YassinStore\AiAssistant\Application\Commerce\CartIntentVerificationRequest;
use YassinStore\AiAssistant\Application\Execution\TurnExecutionSupervisor;

/** Isolated natural-language decision for one server-resolved cart proposal. */
interface CartIntentVerifierPort
{
    public function verify(
        CartIntentVerificationRequest $request,
        ?TurnExecutionSupervisor $supervisor = null
    ): CartIntentVerdict;
}
