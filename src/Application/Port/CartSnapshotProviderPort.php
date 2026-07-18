<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

use YassinStore\AiAssistant\Domain\Commerce\CartSnapshot;

interface CartSnapshotProviderPort
{
    public function capture(): CartSnapshot;
}
