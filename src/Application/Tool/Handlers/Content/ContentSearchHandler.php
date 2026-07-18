<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool\Handlers\Content;

use YassinStore\AiAssistant\Application\Agent\AgentContext;
use YassinStore\AiAssistant\Application\Tool\ToolContract;
use YassinStore\AiAssistant\Application\Tool\ToolExecutionResult;
use YassinStore\AiAssistant\Application\Tool\ToolHandlerInterface;
use YassinStore\AiAssistant\Application\Tool\ToolPromptDescriptions;
use YassinStore\AiAssistant\Application\Tool\ToolSchemas;
use YassinStore\AiAssistant\Application\Tool\Service\ContentToolService;

final class ContentSearchHandler implements ToolHandlerInterface
{
    /** @var ContentToolService */ private $tools;
    /** @var ToolContract */ private $contract;

    public function __construct(ContentToolService $tools)
    {
        $this->tools = $tools;
        $this->contract = new ToolContract(
            'content_search',
            ToolPromptDescriptions::for('content_search'),
            ToolSchemas::closedObject(array(
                'query' => ToolSchemas::boundedText(160),
                'type' => array('type' => 'string', 'enum' => array('any', 'page', 'post')),
                'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 10),
            ), array('query'))
        );
    }

    public function contract(): ToolContract
    {
        return $this->contract;
    }

    /** @param array<string,mixed> $arguments */
    public function execute(array $arguments, AgentContext $context): ToolExecutionResult
    {
        return $this->tools->search($arguments, $context);
    }
}
