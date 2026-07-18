<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Agent;

use YassinStore\AiAssistant\Application\Ai\ModelProtocolException;
use YassinStore\AiAssistant\Application\Ai\ModelRequest;
use YassinStore\AiAssistant\Application\Authority\AuthorityRegistry;
use YassinStore\AiAssistant\Application\Tool\ToolCatalog;
use YassinStore\AiAssistant\Domain\Chat\Outcome;

/** Builds the provider-neutral request from canonical application state. */
final class AgentRequestFactory
{
    /** @var AgentPromptBuilder */ private $prompt;
    /** @var ToolCatalog */ private $tools;
    /** @var AgentLimits */ private $limits;

    public function __construct(AgentPromptBuilder $prompt, ToolCatalog $tools, AgentLimits $limits)
    {
        $this->prompt = $prompt;
        $this->tools = $tools;
        $this->limits = $limits;
    }

    /**
     * @param array<string,mixed>             $conversation
     * @param array<int,array<string,mixed>>  $history
     * @param array<int,\YassinStore\AiAssistant\Application\Ai\ImageAttachment> $attachments
     */
    public function create(
        array $conversation,
        array $history,
        string $message,
        string $replyContext,
        array $attachments,
        AuthorityRegistry $authority,
        string $quotedProductRef = ''
    ): ModelRequest {
        return new ModelRequest(
            $this->prompt->build(
                is_array($conversation['state'] ?? null) ? $conversation['state'] : array()
            ),
            $this->historyForModel($history),
            AgentTurnEnvelope::encode($message, $replyContext, $quotedProductRef),
            $attachments,
            $this->tools->declarations(),
            $this->limits->maxOutputTokens()
        );
    }

    /** @param array<int,array<string,mixed>> $history @return array<int,array{role:string,text:string}> */
    private function historyForModel(array $history): array
    {
        if (count($history) > 48 || count($history) % 2 !== 0) {
            throw new ModelProtocolException(
                'model_history_pairing_invalid',
                'Canonical model history must contain complete user/assistant turn pairs.'
            );
        }

        $rows = array();
        foreach ($history as $index => $row) {
            if (!is_array($row)) {
                throw new ModelProtocolException(
                    'model_history_record_invalid',
                    'Canonical model history contains an invalid record.'
                );
            }
            $keys = array_keys($row);
            sort($keys);
            if (
                $keys !== array('content', 'outcome', 'role')
                || !is_string($row['role'])
                || !is_string($row['content'])
                || !is_string($row['outcome'])
            ) {
                throw new ModelProtocolException(
                    'model_history_record_invalid',
                    'Canonical model history contains an invalid record.'
                );
            }

            $expectedRole = $index % 2 === 0 ? 'user' : 'assistant';
            $text = $row['content'];
            if ($row['role'] !== $expectedRole || trim($text) === '') {
                throw new ModelProtocolException(
                    'model_history_sequence_invalid',
                    'Canonical model history has an invalid role sequence or blank content.'
                );
            }
            if ($expectedRole === 'user' && $row['outcome'] !== '') {
                throw new ModelProtocolException(
                    'model_history_user_outcome_invalid',
                    'A customer history record cannot carry an assistant outcome.'
                );
            }
            if ($expectedRole === 'assistant' && !in_array($row['outcome'], Outcome::all(), true)) {
                throw new ModelProtocolException(
                    'model_history_assistant_outcome_invalid',
                    'An assistant history record requires a valid terminal outcome.'
                );
            }
            $rows[] = array('role' => $expectedRole, 'text' => $text);
        }
        return $rows;
    }
}
