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

final class CatalogRelatedHandler implements ToolHandlerInterface
{
    /** @var CatalogToolService */ private $tools;
    /** @var ToolContract */ private $contract;

    public function __construct(CatalogToolService $tools)
    {
        $this->tools = $tools;
        $this->contract = new ToolContract(
            'catalog_related',
            ToolPromptDescriptions::for('catalog_related'),
            ToolSchemas::closedObject(array(
                'product_ref' => ToolSchemas::opaqueRef('p'),
                'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 12),
            ), array('product_ref'))
        );
    }

    public function contract(): ToolContract
    {
        return $this->contract;
    }

    /** @param array<string,mixed> $arguments */
    public function execute(array $arguments, AgentContext $context): ToolExecutionResult
    {
        return $this->tools->related($arguments, $context);
    }
}
