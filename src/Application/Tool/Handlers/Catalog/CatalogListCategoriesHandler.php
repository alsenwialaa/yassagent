<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool\Handlers\Catalog;

use YassinStore\AiAssistant\Application\Agent\AgentContext;
use YassinStore\AiAssistant\Application\Tool\ToolContract;
use YassinStore\AiAssistant\Application\Tool\ToolExecutionResult;
use YassinStore\AiAssistant\Application\Tool\ToolHandlerInterface;
use YassinStore\AiAssistant\Application\Tool\ToolPromptDescriptions;
use YassinStore\AiAssistant\Application\Tool\ToolSchemas;
use YassinStore\AiAssistant\Application\Tool\Service\CatalogToolService;

final class CatalogListCategoriesHandler implements ToolHandlerInterface
{
    /** @var CatalogToolService */ private $tools;
    /** @var ToolContract */ private $contract;

    public function __construct(CatalogToolService $tools)
    {
        $this->tools = $tools;
        $this->contract = new ToolContract(
            'catalog_list_categories',
            ToolPromptDescriptions::for('catalog_list_categories'),
            ToolSchemas::closedObject(array(
                'query' => ToolSchemas::boundedText(120),
                'parent_id' => array('type' => 'integer', 'minimum' => 0),
                'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 50),
            ))
        );
    }

    public function contract(): ToolContract
    {
        return $this->contract;
    }

    /** @param array<string,mixed> $arguments */
    public function execute(array $arguments, AgentContext $context): ToolExecutionResult
    {
        return $this->tools->categories($arguments);
    }
}
