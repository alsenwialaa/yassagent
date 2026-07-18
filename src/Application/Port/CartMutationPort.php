<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

use YassinStore\AiAssistant\Application\Commerce\CommerceExecutionContext;
use YassinStore\AiAssistant\Domain\Commerce\ActionReceipt;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;

interface CartMutationPort
{
    public function execute(CartPlan $plan, CommerceExecutionContext $context): ActionReceipt;
    public function recoverForTurn(CommerceExecutionContext $context): ?ActionReceipt;
}
