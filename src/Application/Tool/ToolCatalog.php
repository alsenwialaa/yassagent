<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool;

use YassinStore\AiAssistant\Application\Agent\AgentContext;
use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Support\Arr;

/** Immutable one-contract/one-handler dispatch table. */
final class ToolCatalog
{
    /** @var ArgumentValidator */ private $arguments;
    /** @var array<string,array{contract:ToolContract,handler:ToolHandlerInterface}> */ private $tools = array();

    /** @param array<int,ToolHandlerInterface> $handlers */
    public function __construct(
        ContractSchemaValidator $schemas,
        ArgumentValidator $arguments,
        array $handlers
    ) {
        if (!Arr::isList($handlers) || $handlers === array()) {
            throw new ContractViolation('tool_handlers_missing', 'The tool catalog requires a nonempty handler list.');
        }
        $this->arguments = $arguments;
        foreach ($handlers as $handler) {
            if (!$handler instanceof ToolHandlerInterface) {
                throw new ContractViolation('tool_handler_invalid', 'The tool catalog contains an invalid handler.');
            }
            $contract = $handler->contract();
            $schemas->validate($contract->schema(), '$tools.' . $contract->name());
            if (isset($this->tools[$contract->name()])) {
                throw new ContractViolation('duplicate_tool', 'Duplicate tool contract: ' . $contract->name());
            }
            $this->tools[$contract->name()] = array('contract' => $contract, 'handler' => $handler);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function declarations(): array
    {
        $rows = array();
        foreach ($this->tools as $entry) {
            $rows[] = $entry['contract']->modelDeclaration();
        }
        return $rows;
    }

    /** @return array<int,string> */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    public function isTerminal(string $name): bool
    {
        return isset($this->tools[$name]) && $this->tools[$name]['contract']->kind() === ToolContract::TERMINAL;
    }

    public function isMutation(string $name): bool
    {
        return isset($this->tools[$name]) && $this->tools[$name]['contract']->kind() === ToolContract::MUTATION;
    }

    /** @param mixed $arguments @return array<string,mixed> */
    public function validateTerminal(string $name, $arguments): array
    {
        if (!$this->isTerminal($name)) {
            throw new ContractViolation('terminal_tool_invalid', 'The terminal tool is invalid.');
        }
        if (!is_array($arguments) || ($arguments !== array() && Arr::isList($arguments))) {
            throw new ContractViolation('terminal_arguments_invalid', 'Terminal arguments must be a JSON object.');
        }
        $this->arguments->validate($arguments, $this->tools[$name]['contract']->schema());
        return $arguments;
    }

    /** @param mixed $arguments */
    public function execute(string $name, $arguments, AgentContext $context): ToolExecutionResult
    {
        if (!isset($this->tools[$name])) {
            return ToolExecutionResult::failure('unknown_tool', '', array('tool' => $name));
        }
        $entry = $this->tools[$name];
        if ($entry['contract']->kind() === ToolContract::TERMINAL) {
            return ToolExecutionResult::failure('terminal_tool_misplaced', '', array('tool' => $name));
        }
        if (!is_array($arguments) || ($arguments !== array() && Arr::isList($arguments))) {
            return ToolExecutionResult::failure('tool_contract_invalid', '', array(
                'tool' => $name,
                'reason' => 'arguments_not_object',
            ));
        }
        try {
            $this->arguments->validate($arguments, $entry['contract']->schema());
            $result = $entry['handler']->execute($arguments, $context);
            if ($result->safeMessage() !== '') {
                $context->effects()->recordNotice($result->safeMessage());
            }
            return $result;
        } catch (ContractViolation $exception) {
            return ToolExecutionResult::failure('tool_contract_invalid', '', array(
                'tool' => $name,
                'reason' => $exception->reasonCode(),
                'detail' => $exception->getMessage(),
            ));
        }
    }
}
