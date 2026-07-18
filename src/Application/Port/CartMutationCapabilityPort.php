<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

use YassinStore\AiAssistant\Domain\Commerce\CartMutationCapability;

interface CartMutationCapabilityPort
{
    public function inspect(): CartMutationCapability;
}
