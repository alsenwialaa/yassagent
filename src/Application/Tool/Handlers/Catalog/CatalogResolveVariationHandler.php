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

final class CatalogResolveVariationHandler implements ToolHandlerInterface
{
    /** @var CatalogToolService */ private $tools;
    /** @var ToolContract */ private $contract;

    public function __construct(CatalogToolService $tools)
    {
        $this->tools = $tools;
        $attribute = ToolSchemas::closedObject(array(
            'name' => ToolSchemas::described(
                ToolSchemas::boundedText(160),
                'Model-interpreted live option-axis label, such as weight or color.'
            ),
            'value' => ToolSchemas::described(
                ToolSchemas::boundedText(160),
                'Model-interpreted customer-requested value for this axis.'
            ),
        ), array('name', 'value'));
        $this->contract = new ToolContract(
            'catalog_resolve_variation',
            ToolPromptDescriptions::for('catalog_resolve_variation'),
            ToolSchemas::closedObject(array(
                'product_ref' => ToolSchemas::opaqueRef('p'),
                'attributes' => array(
                    'type' => 'array',
                    'items' => $attribute,
                    'maxItems' => 16,
                    'uniqueItems' => true,
                ),
            ), array('product_ref', 'attributes'))
        );
    }

    public function contract(): ToolContract
    {
        return $this->contract;
    }

    /** @param array<string,mixed> $arguments */
    public function execute(array $arguments, AgentContext $context): ToolExecutionResult
    {
        return $this->tools->resolveVariation($arguments, $context);
    }
}
