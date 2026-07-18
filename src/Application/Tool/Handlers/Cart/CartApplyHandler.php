<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool\Handlers\Cart;

use YassinStore\AiAssistant\Application\Agent\AgentContext;
use YassinStore\AiAssistant\Application\Tool\Service\CartToolService;
use YassinStore\AiAssistant\Application\Tool\ToolContract;
use YassinStore\AiAssistant\Application\Tool\ToolExecutionResult;
use YassinStore\AiAssistant\Application\Tool\ToolHandlerInterface;
use YassinStore\AiAssistant\Application\Tool\ToolPromptDescriptions;
use YassinStore\AiAssistant\Application\Tool\ToolSchemas;

final class CartApplyHandler implements ToolHandlerInterface
{
    /** @var CartToolService */ private $tools;
    /** @var ToolContract */ private $contract;

    public function __construct(CartToolService $tools)
    {
        $this->tools = $tools;
        $command = ToolSchemas::cartCommand();
        $this->contract = new ToolContract(
            'cart_apply',
            ToolPromptDescriptions::for('cart_apply'),
            ToolSchemas::closedObject(array(
                'intent_text' => ToolSchemas::described(
                    ToolSchemas::boundedText(320),
                    'Shortest byte-exact substring of customer_message that supplies the current action or missing-value answer.'
                ),
                'commands' => ToolSchemas::described(array(
                    'type' => 'array',
                    'items' => $command,
                    'minItems' => 1,
                    'maxItems' => 1,
                ), 'Exactly one complete semantic cart command.'),
            ), array('intent_text', 'commands')),
            ToolContract::MUTATION
        );
    }

    public function contract(): ToolContract
    {
        return $this->contract;
    }

    /** @param array<string,mixed> $arguments */
    public function execute(array $arguments, AgentContext $context): ToolExecutionResult
    {
        return $this->tools->apply($arguments, $context);
    }
}
