<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Gemini;

use YassinStore\AiAssistant\Application\Ai\ModelStep;

final class GeminiDecodedStep
{
    /** @var ModelStep */ private $step;
    /** @var array<string,mixed> */ private $requestContent;

    /** @param array<string,mixed> $requestContent */
    public function __construct(ModelStep $step, array $requestContent)
    {
        $this->step = $step;
        $this->requestContent = $requestContent;
    }

    public function step(): ModelStep
    {
        return $this->step;
    }
    /** @return array<string,mixed> */ public function requestContent(): array
    {
        return $this->requestContent;
    }
}
