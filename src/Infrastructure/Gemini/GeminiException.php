<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Gemini;

use YassinStore\AiAssistant\Application\Ai\ModelGatewayException;

/** Gemini adapter specialization of the provider-neutral gateway failure. */
final class GeminiException extends ModelGatewayException
{
    private const PROVIDER_FIELD_ROOTS = array(
        'tools', 'toolConfig', 'tool_config', 'generationConfig',
        'generation_config', 'contents', 'systemInstruction', 'system_instruction',
    );
    private const PROVIDER_FIELD_SEGMENTS = array(
        'functionDeclarations', 'function_declarations', 'parameters', 'properties',
        'items', 'required', 'type', 'enum', 'description', 'minimum', 'maximum',
        'minItems', 'min_items', 'maxItems', 'max_items', 'minLength', 'min_length',
        'maxLength', 'max_length', 'functionCallingConfig', 'function_calling_config',
        'mode', 'thinkingConfig',
        'thinking_config', 'thinkingLevel', 'thinking_level', 'maxOutputTokens',
        'max_output_tokens', 'role', 'parts', 'text', 'inlineData', 'inline_data',
        'mimeType', 'mime_type', 'data', 'functionCall', 'function_call',
        'functionResponse', 'function_response', 'id', 'name', 'args', 'response',
        'thought', 'thoughtSignature', 'thought_signature',
    );
    private const PROVIDER_FIELD_MAP_SEGMENTS = array('properties', 'args', 'response');

    /** @var string */ private $providerField;
    /** @var int */ private $retryAfterSeconds;

    public function __construct(
        string $reasonCode,
        string $safeMessage,
        string $internalMessage = '',
        string $providerField = '',
        int $retryAfterSeconds = 0
    ) {
        $providerField = self::normalizeProviderField($providerField);
        parent::__construct($reasonCode, $safeMessage, $internalMessage);
        $this->providerField = $providerField;
        $this->retryAfterSeconds = max(0, $retryAfterSeconds);
    }

    /** Only a closed structural path is retained; descriptions and values are discarded. */
    public function providerField(): string
    {
        return $this->providerField;
    }

    /** Bounded client retry guidance for administrative readiness checks. */
    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }

    public static function normalizeProviderField(string $candidate): string
    {
        $candidate = trim($candidate);
        if ($candidate === '' || strlen($candidate) > 512) {
            return '';
        }
        $candidate = (string) preg_replace_callback(
            '/\[([^\]\r\n]{1,64})\]/',
            static function (array $matches): string {
                $index = (string) ($matches[1] ?? '');
                return preg_match('/^[0-9]{1,4}$/D', $index) === 1
                    ? '[' . $index . ']'
                    : '[*]';
            },
            $candidate
        );
        if (
            strlen($candidate) > 240
            || preg_match('/^([A-Za-z][A-Za-z0-9_]*)/', $candidate, $root) !== 1
            || !in_array($root[1], self::PROVIDER_FIELD_ROOTS, true)
        ) {
            return '';
        }

        $normalized = $root[1];
        $offset = strlen($root[1]);
        $components = 0;
        $lastSegment = '';
        $length = strlen($candidate);
        while ($offset < $length && ++$components <= 24) {
            $tail = substr($candidate, $offset);
            if (preg_match('/^\[(?:[0-9]{1,4}|\*)\]/', $tail, $bracket) === 1) {
                $normalized .= $bracket[0];
                $offset += strlen($bracket[0]);
                $lastSegment = '';
                continue;
            }
            if (preg_match('/^\.([0-9]{1,4})(?=\.|\[|$)/', $tail, $numeric) === 1) {
                $normalized .= '[' . $numeric[1] . ']';
                $offset += strlen($numeric[0]);
                $lastSegment = '';
                continue;
            }
            if (preg_match('/^\.([A-Za-z][A-Za-z0-9_]{0,63})/', $tail, $segment) !== 1) {
                return '';
            }
            $name = $segment[1];
            if (in_array($name, self::PROVIDER_FIELD_SEGMENTS, true)) {
                $normalized .= '.' . $name;
                $lastSegment = $name;
            } elseif (in_array($lastSegment, self::PROVIDER_FIELD_MAP_SEGMENTS, true)) {
                // Schema properties and function maps can contain model-owned
                // keys. Preserve only their structural position, never text.
                $normalized .= '[*]';
                $lastSegment = '';
            } else {
                return '';
            }
            $offset += strlen($segment[0]);
        }

        return $offset === $length && strlen($normalized) <= 240
            ? $normalized
            : '';
    }
}
