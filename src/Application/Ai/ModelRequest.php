<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Ai;

use RuntimeException;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Json;

/** Immutable, bounded initial request for one provider-neutral model session. */
final class ModelRequest
{
    public const MAX_TEXT_BYTES = 131072;
    public const MAX_TOOL_DECLARATIONS = 20;
    private const MAX_HISTORY_BYTES = 262144;

    /** @var string */ private $systemInstruction;
    /** @var array<int,array{role:string,text:string}> */ private $history;
    /** @var string */ private $userText;
    /** @var array<int,ImageAttachment> */ private $attachments;
    /** @var array<int,array<string,mixed>> */ private $toolDeclarations;
    /** @var int */ private $maxOutputTokens;

    /**
     * @param array<int,array{role:string,text:string}> $history
     * @param array<int,ImageAttachment> $attachments
     * @param array<int,array<string,mixed>> $toolDeclarations
     */
    public function __construct(
        string $systemInstruction,
        array $history,
        string $userText,
        array $attachments,
        array $toolDeclarations,
        int $maxOutputTokens
    ) {
        if (trim($systemInstruction) === '' || strlen($systemInstruction) > self::MAX_TEXT_BYTES) {
            throw new ModelProtocolException(
                'system_instruction_invalid',
                'The system instruction is blank or exceeds the safe size limit.'
            );
        }
        if (strlen($userText) > self::MAX_TEXT_BYTES) {
            throw new ModelProtocolException('model_user_input_too_large', 'The model user input is too large.');
        }
        if ($maxOutputTokens < 256 || $maxOutputTokens > 8192) {
            throw new ModelProtocolException('model_output_limit_invalid', 'The model output token limit is invalid.');
        }

        $normalizedAttachments = $this->normalizeAttachments($attachments);
        if (trim($userText) === '' && $normalizedAttachments === array()) {
            throw new ModelProtocolException('model_user_input_missing', 'The model request has no user input.');
        }
        $normalizedDeclarations = $this->normalizeDeclarations($toolDeclarations);
        if ($normalizedDeclarations === array()) {
            throw new ModelProtocolException('tool_declarations_missing', 'The model request has no tool declarations.');
        }

        $this->systemInstruction = $systemInstruction;
        $this->history = $this->normalizeHistory($history);
        $this->userText = $userText;
        $this->attachments = $normalizedAttachments;
        $this->toolDeclarations = $normalizedDeclarations;
        $this->maxOutputTokens = $maxOutputTokens;
    }

    public function systemInstruction(): string
    {
        return $this->systemInstruction;
    }
    /** @return array<int,array{role:string,text:string}> */ public function history(): array
    {
        return $this->history;
    }
    public function userText(): string
    {
        return $this->userText;
    }
    /** @return array<int,ImageAttachment> */ public function attachments(): array
    {
        return $this->attachments;
    }
    /** @return array<int,array<string,mixed>> */ public function toolDeclarations(): array
    {
        return $this->toolDeclarations;
    }
    public function maxOutputTokens(): int
    {
        return $this->maxOutputTokens;
    }

    /** @param array<int,array{role:string,text:string}> $history @return array<int,array{role:string,text:string}> */
    private function normalizeHistory(array $history): array
    {
        if (!Arr::isList($history) || count($history) > 48) {
            throw new ModelProtocolException('model_history_invalid', 'Model history must be a bounded list.');
        }
        $rows = array();
        $bytes = 0;
        foreach ($history as $index => $row) {
            if (!is_array($row) || ($row !== array() && Arr::isList($row))) {
                throw new ModelProtocolException('model_history_row_invalid', 'Model history contains an invalid row.');
            }
            foreach (array_keys($row) as $key) {
                if (!in_array($key, array('role', 'text'), true)) {
                    throw new ModelProtocolException('model_history_field_invalid', 'Model history contains an unsupported field.');
                }
            }
            if (!isset($row['role'], $row['text']) || !is_string($row['role']) || !is_string($row['text'])) {
                throw new ModelProtocolException('model_history_row_invalid', 'Model history contains incomplete fields.');
            }
            $role = $row['role'];
            $text = $row['text'];
            $expectedRole = $index % 2 === 0 ? 'user' : 'assistant';
            if (trim($text) === '' || $role !== $expectedRole) {
                throw new ModelProtocolException(
                    'model_history_sequence_invalid',
                    'Model history must contain complete alternating user and assistant pairs.'
                );
            }
            $bytes += strlen($text);
            if ($bytes > self::MAX_HISTORY_BYTES) {
                throw new ModelProtocolException('model_history_too_large', 'Model history exceeds the safe size limit.');
            }
            $rows[] = array('role' => $role, 'text' => $text);
        }
        if (count($rows) % 2 !== 0) {
            throw new ModelProtocolException(
                'model_history_pairing_invalid',
                'Model history must end with a complete assistant terminal response.'
            );
        }
        return $rows;
    }

    /** @param array<int,ImageAttachment> $attachments @return array<int,ImageAttachment> */
    private function normalizeAttachments(array $attachments): array
    {
        if (!Arr::isList($attachments) || count($attachments) > ImageAttachmentPolicy::MAX_ITEMS) {
            throw new ModelProtocolException('model_attachments_invalid', 'Model attachments must be a bounded list.');
        }
        $rows = array();
        $totalBytes = 0;
        foreach ($attachments as $attachment) {
            if (!$attachment instanceof ImageAttachment) {
                throw new ModelProtocolException('model_attachment_invalid', 'Model attachment authority is invalid.');
            }
            $totalBytes += $attachment->decodedBytes();
            if ($totalBytes > ImageAttachmentPolicy::MAX_TOTAL_DECODED_BYTES) {
                throw new ModelProtocolException('model_attachments_too_large', 'Model attachments exceed the safe size limit.');
            }
            $rows[] = $attachment;
        }
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $declarations @return array<int,array<string,mixed>> */
    private function normalizeDeclarations(array $declarations): array
    {
        if (
            !Arr::isList($declarations)
            || count($declarations) > self::MAX_TOOL_DECLARATIONS
        ) {
            throw new ModelProtocolException('tool_declarations_invalid', 'Tool declarations must be a bounded list.');
        }

        $rows = array();
        $seen = array();
        $bytes = 0;
        foreach ($declarations as $declaration) {
            if (!is_array($declaration) || ($declaration !== array() && Arr::isList($declaration))) {
                throw new ModelProtocolException('tool_declaration_invalid', 'A tool declaration is not a JSON object.');
            }
            foreach (array_keys($declaration) as $key) {
                if (!in_array($key, array('name', 'description', 'parameters'), true)) {
                    throw new ModelProtocolException(
                        'tool_declaration_field_invalid',
                        'A tool declaration contains an unsupported field.'
                    );
                }
            }

            $name = isset($declaration['name']) && is_string($declaration['name'])
                ? $declaration['name']
                : '';
            $description = isset($declaration['description']) && is_string($declaration['description'])
                ? trim($declaration['description'])
                : '';
            $parameters = $declaration['parameters'] ?? null;
            if (
                preg_match('/^[a-z][a-z0-9_]{1,63}$/', $name) !== 1
                || isset($seen[$name])
                || $description === ''
                || strlen($description) > 2048
                || !is_array($parameters)
                || ($parameters !== array() && Arr::isList($parameters))
                || ($parameters['type'] ?? '') !== 'object'
            ) {
                throw new ModelProtocolException(
                    'tool_declaration_invalid',
                    'A tool declaration is incomplete, duplicated, or outside the closed envelope.'
                );
            }

            $row = array(
                'name' => $name,
                'description' => $description,
                'parameters' => $parameters,
            );
            try {
                $bytes += strlen(Json::encodeObject($row));
            } catch (RuntimeException $exception) {
                throw new ModelProtocolException('tool_declaration_invalid', 'A tool declaration is not JSON encodable.');
            }
            if ($bytes > self::MAX_TEXT_BYTES) {
                throw new ModelProtocolException('tool_declarations_too_large', 'Tool declarations exceed the safe size limit.');
            }
            $seen[$name] = true;
            $rows[] = $row;
        }
        return $rows;
    }
}
