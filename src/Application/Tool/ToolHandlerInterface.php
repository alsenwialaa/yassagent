<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool;

use YassinStore\AiAssistant\Application\Agent\AgentContext;

/** One immutable contract bound to one execution path. */
interface ToolHandlerInterface
{
    public function contract(): ToolContract;

    /** @param array<string,mixed> $arguments */
    public function execute(array $arguments, AgentContext $context): ToolExecutionResult;
}
