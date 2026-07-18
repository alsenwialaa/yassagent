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

final class CatalogRankCandidatesHandler implements ToolHandlerInterface
{
    /** @var CatalogToolService */ private $tools;
    /** @var ToolContract */ private $contract;

    public function __construct(CatalogToolService $tools)
    {
        $this->tools = $tools;
        $attribute = ToolSchemas::closedObject(array(
            'name' => ToolSchemas::boundedText(120),
            'value' => ToolSchemas::boundedText(160),
        ), array('name', 'value'));
        $this->contract = new ToolContract(
            'catalog_rank_candidates',
            ToolPromptDescriptions::for('catalog_rank_candidates'),
            ToolSchemas::closedObject(array(
                'product_refs' => array(
                    'type' => 'array',
                    'items' => ToolSchemas::opaqueRef('p'),
                    'minItems' => 2,
                    'maxItems' => 8,
                    'uniqueItems' => true,
                ),
                'required_in_stock' => array('type' => 'boolean'),
                'min_price' => array('type' => 'number', 'minimum' => 0),
                'max_price' => array('type' => 'number', 'minimum' => 0),
                'min_rating' => array('type' => 'number', 'minimum' => 0, 'maximum' => 5),
                'required_attributes' => array(
                    'type' => 'array', 'items' => $attribute, 'maxItems' => 8, 'uniqueItems' => true,
                ),
                'excluded_attributes' => array(
                    'type' => 'array', 'items' => $attribute, 'maxItems' => 8, 'uniqueItems' => true,
                ),
                'preferred_attributes' => array(
                    'type' => 'array', 'items' => $attribute, 'maxItems' => 8, 'uniqueItems' => true,
                ),
                'required_categories' => array(
                    'type' => 'array', 'items' => ToolSchemas::boundedText(120), 'maxItems' => 8, 'uniqueItems' => true,
                ),
                'excluded_categories' => array(
                    'type' => 'array', 'items' => ToolSchemas::boundedText(120), 'maxItems' => 8, 'uniqueItems' => true,
                ),
                'preferred_categories' => array(
                    'type' => 'array', 'items' => ToolSchemas::boundedText(120), 'maxItems' => 8, 'uniqueItems' => true,
                ),
                'priority' => array(
                    'type' => 'string',
                    'enum' => array('balanced', 'lowest_price', 'highest_rating', 'best_selling'),
                ),
            ), array('product_refs'))
        );
    }

    public function contract(): ToolContract
    {
        return $this->contract;
    }

    /** @param array<string,mixed> $arguments */
    public function execute(array $arguments, AgentContext $context): ToolExecutionResult
    {
        return $this->tools->rankCandidates($arguments, $context);
    }
}
