<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool\Handlers\Terminal;

use YassinStore\AiAssistant\Application\Agent\AgentContext;
use YassinStore\AiAssistant\Application\Tool\ToolExecutionResult;
use YassinStore\AiAssistant\Application\Tool\ToolHandlerInterface;

abstract class AbstractTerminalHandler implements ToolHandlerInterface
{
    /** @param array<string,mixed> $arguments */
    final public function execute(array $arguments, AgentContext $context): ToolExecutionResult
    {
        return ToolExecutionResult::failure('terminal_tool_misplaced');
    }
}
