<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Agent;

final class AgentLimits
{
    /** @var int */ private $maxRounds;
    /** @var int */ private $maxToolCalls;
    /** @var int */ private $maxFeedbackBytes;
    /** @var int */ private $maxOutputTokens;
    /** @var int */ private $providerTimeoutSeconds;

    public function __construct(
        int $maxRounds,
        int $maxToolCalls,
        int $maxFeedbackBytes,
        int $maxOutputTokens,
        int $providerTimeoutSeconds = 30
    ) {
        // A variable-product mutation needs three sequential model decisions:
        // discover the product, inspect live variations, then issue cart_apply.
        $this->maxRounds = max(3, min(10, $maxRounds));
        $this->maxToolCalls = max(4, min(40, $maxToolCalls));
        $this->maxFeedbackBytes = max(32768, min(1048576, $maxFeedbackBytes));
        $this->maxOutputTokens = max(256, min(8192, $maxOutputTokens));
        $this->providerTimeoutSeconds = max(1, min(90, $providerTimeoutSeconds));
    }

    public function maxRounds(): int
    {
        return $this->maxRounds;
    }
    public function maxToolCalls(): int
    {
        return $this->maxToolCalls;
    }
    public function maxFeedbackBytes(): int
    {
        return $this->maxFeedbackBytes;
    }
    public function maxOutputTokens(): int
    {
        return $this->maxOutputTokens;
    }
    public function providerTimeoutSeconds(): int
    {
        return $this->providerTimeoutSeconds;
    }
}
