<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool\Handlers\Catalog;

use YassinStore\AiAssistant\Application\Agent\AgentContext;
use YassinStore\AiAssistant\Application\Tool\Service\CatalogToolService;
use YassinStore\AiAssistant\Application\Tool\ToolContract;
use YassinStore\AiAssistant\Application\Tool\ToolExecutionResult;
use YassinStore\AiAssistant\Application\Tool\ToolHandlerInterface;
use YassinStore\AiAssistant\Application\Tool\ToolPromptDescriptions;
use YassinStore\AiAssistant\Application\Tool\ToolSchemas;

final class CatalogFindAlternativesHandler implements ToolHandlerInterface
{
    /** @var CatalogToolService */ private $tools;
    /** @var ToolContract */ private $contract;

    public function __construct(CatalogToolService $tools)
    {
        $this->tools = $tools;
        $this->contract = new ToolContract(
            'catalog_find_alternatives',
            ToolPromptDescriptions::for('catalog_find_alternatives'),
            ToolSchemas::closedObject(array(
                'product_ref' => ToolSchemas::opaqueRef('p'),
                'objective' => array('type' => 'string', 'enum' => array('similar', 'cheaper', 'in_stock', 'premium')),
                'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 12),
                'max_price' => array('type' => 'number', 'minimum' => 0),
            ), array('product_ref', 'objective'))
        );
    }

    public function contract(): ToolContract
    {
        return $this->contract;
    }

    /** @param array<string,mixed> $arguments */
    public function execute(array $arguments, AgentContext $context): ToolExecutionResult
    {
        return $this->tools->alternatives($arguments, $context);
    }
}
