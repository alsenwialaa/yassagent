<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool\Handlers\Cart;

use YassinStore\AiAssistant\Application\Agent\AgentContext;
use YassinStore\AiAssistant\Application\Tool\ToolContract;
use YassinStore\AiAssistant\Application\Tool\ToolExecutionResult;
use YassinStore\AiAssistant\Application\Tool\ToolHandlerInterface;
use YassinStore\AiAssistant\Application\Tool\ToolPromptDescriptions;
use YassinStore\AiAssistant\Application\Tool\ToolSchemas;
use YassinStore\AiAssistant\Application\Tool\Service\CartToolService;

final class CheckoutGetUrlHandler implements ToolHandlerInterface
{
    /** @var CartToolService */ private $tools;
    /** @var ToolContract */ private $contract;

    public function __construct(CartToolService $tools)
    {
        $this->tools = $tools;
        $this->contract = new ToolContract(
            'checkout_get_url',
            ToolPromptDescriptions::for('checkout_get_url'),
            ToolSchemas::emptyObject()
        );
    }

    public function contract(): ToolContract
    {
        return $this->contract;
    }

    /** @param array<string,mixed> $arguments */
    public function execute(array $arguments, AgentContext $context): ToolExecutionResult
    {
        return $this->tools->checkoutUrl();
    }
}
