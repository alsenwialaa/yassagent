<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool\Service;

use YassinStore\AiAssistant\Application\Tool\ToolExecutionResult;
use YassinStore\AiAssistant\Application\Agent\AgentContext;
use YassinStore\AiAssistant\Application\Port\ContentRepositoryPort;

final class ContentToolService
{
    /** @var ContentRepositoryPort */ private $content;

    public function __construct(ContentRepositoryPort $content)
    {
        $this->content = $content;
    }

    /** @param array<string,mixed> $arguments */
    public function search(array $arguments, AgentContext $context): ToolExecutionResult
    {
        $rows = array();
        foreach ($this->content->search($arguments) as $row) {
            $row['content_ref'] = $context->authority()->recordContent($row);
            unset($row['id']);
            $rows[] = $row;
        }
        return ToolExecutionResult::success(array('results' => $rows));
    }

    public function get(string $contentRef, AgentContext $context): ToolExecutionResult
    {
        $authority = $context->authority()->requireContent($contentRef);
        $row = $this->content->get((int) ($authority['id'] ?? 0));
        return $row === array()
            ? ToolExecutionResult::failure('content_not_found')
            : ToolExecutionResult::success(array('content' => array_merge(array('content_ref' => $contentRef), array_diff_key($row, array('id' => true)))));
    }

    public function policy(string $section): ToolExecutionResult
    {
        return ToolExecutionResult::success(array('policy' => $this->content->policy($section)));
    }

    public function storeInfo(): ToolExecutionResult
    {
        return ToolExecutionResult::success(array('store' => $this->content->storeInfo()));
    }
}
