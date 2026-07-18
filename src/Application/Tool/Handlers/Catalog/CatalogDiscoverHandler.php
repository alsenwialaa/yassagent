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

final class CatalogDiscoverHandler implements ToolHandlerInterface
{
    /** @var CatalogToolService */ private $tools;
    /** @var ToolContract */ private $contract;

    public function __construct(CatalogToolService $tools)
    {
        $this->tools = $tools;
        $this->contract = new ToolContract(
            'catalog_discover',
            ToolPromptDescriptions::for('catalog_discover'),
            ToolSchemas::closedObject(array(
                'queries' => array(
                    'type' => 'array',
                    'items' => ToolSchemas::boundedText(160),
                    'minItems' => 1,
                    'maxItems' => 5,
                    'uniqueItems' => true,
                ),
                'category_slugs' => array(
                    'type' => 'array',
                    'items' => ToolSchemas::boundedText(120),
                    'maxItems' => 4,
                    'uniqueItems' => true,
                ),
                'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 12),
                'min_price' => array('type' => 'number', 'minimum' => 0),
                'max_price' => array('type' => 'number', 'minimum' => 0),
                'in_stock_only' => array('type' => 'boolean'),
                'sort' => array(
                    'type' => 'string',
                    'enum' => array('relevance', 'price_asc', 'price_desc', 'newest', 'best_selling'),
                ),
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
        return $this->tools->discover($arguments, $context);
    }
}
