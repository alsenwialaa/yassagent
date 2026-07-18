<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Gemini;

use YassinStore\AiAssistant\Application\Ai\FunctionCall;
use YassinStore\AiAssistant\Application\Ai\ModelProtocolException;
use YassinStore\AiAssistant\Application\Ai\ModelStep;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Uuid;

/** Strict Gemini response decoder with a provider-neutral result. */
final class GeminiResponseDecoder
{
    private const MAX_PARTS = 64;
    private const MAX_CALLS = 16;
    private const MAX_TEXT_BYTES = 131072;
    private const MAX_SIGNATURE_BYTES = 131072;

    /**
     * Documented Gemini Part.data union members. This integration accepts
     * model text and custom function calls only; every other data member is
     * rejected instead of being echoed into a later GenerateContent request.
     */
    private const PART_DATA_KEYS = array(
        'text',
        'inlineData',
        'functionCall',
        'functionResponse',
        'fileData',
        'executableCode',
        'codeExecutionResult',
        'toolCall',
        'toolResponse',
    );

    /** @param array<string,mixed> $response */
    public function decode(array $response): GeminiDecodedStep
    {
        $this->assertObject($response, 'model_response_invalid', 'Gemini response');
        $promptFeedback = $response['promptFeedback'] ?? array();
        if (!is_array($promptFeedback) || ($promptFeedback !== array() && Arr::isList($promptFeedback))) {
            throw new ModelProtocolException('model_prompt_feedback_invalid', 'Gemini prompt feedback is invalid.');
        }
        $blockReason = $this->optionalString($promptFeedback, 'blockReason', 'model_block_reason_invalid');
        if ($blockReason !== '') {
            throw new ModelProtocolException('model_prompt_blocked', 'Gemini blocked the prompt: ' . $blockReason);
        }

        $candidates = $response['candidates'] ?? null;
        if (!is_array($candidates) || !Arr::isList($candidates) || count($candidates) !== 1) {
            throw new ModelProtocolException(
                'model_candidates_ambiguous',
                'Gemini must return exactly one candidate for an executable turn.'
            );
        }
        $candidate = $candidates[0];
        if (!is_array($candidate) || ($candidate !== array() && Arr::isList($candidate))) {
            throw new ModelProtocolException('model_candidate_invalid', 'Gemini returned an invalid candidate.');
        }

        $finishReason = strtoupper($this->optionalString($candidate, 'finishReason', 'model_finish_reason_invalid'));
        // MAX_TOKENS is recoverable only as a fresh provider request. Never
        // interpret or append a possibly truncated content/tool payload.
        if ($finishReason === 'MAX_TOKENS') {
            return new GeminiDecodedStep(
                new ModelStep(Uuid::v4(), array(), '', $finishReason),
                array()
            );
        }
        $this->assertFinishReason($finishReason);
        $content = $candidate['content'] ?? null;
        if (!is_array($content) || ($content !== array() && Arr::isList($content))) {
            throw new ModelProtocolException('model_content_missing', 'Gemini returned no valid candidate content.');
        }
        if (!isset($content['role']) || !is_string($content['role']) || $content['role'] !== 'model') {
            throw new ModelProtocolException('model_role_invalid', 'Gemini candidate content is missing the model role.');
        }
        $parts = $content['parts'] ?? null;
        if (
            !is_array($parts) || !Arr::isList($parts)
            || $parts === array() || count($parts) > self::MAX_PARTS
        ) {
            throw new ModelProtocolException('model_parts_invalid', 'Gemini returned an invalid or oversized parts list.');
        }

        $calls = array();
        $providerIds = array();
        $plainText = '';
        $normalizedParts = array();
        $firstFunctionCallSeen = false;
        foreach ($parts as $index => $part) {
            if (!is_array($part) || ($part !== array() && Arr::isList($part))) {
                throw new ModelProtocolException('model_part_invalid', 'Gemini returned a non-object content part.');
            }

            $dataKeys = $this->presentDataKeys($part);
            if (count($dataKeys) !== 1) {
                throw new ModelProtocolException(
                    $dataKeys === array() ? 'model_part_unsupported' : 'model_part_ambiguous',
                    'Gemini returned an unsupported content part at index ' . $index . '.'
                );
            }
            $dataKey = $dataKeys[0];
            if ($dataKey !== 'text' && $dataKey !== 'functionCall') {
                throw new ModelProtocolException(
                    'model_part_unsupported',
                    'Gemini returned an unsupported content part at index ' . $index . '.'
                );
            }
            $this->validateThoughtMetadata($part);

            if ($dataKey === 'text') {
                if (!is_string($part['text'])) {
                    throw new ModelProtocolException('model_text_invalid', 'Gemini returned a non-string text part.');
                }
                // Thought text is provider state, not customer-visible prose.
                if (empty($part['thought'])) {
                    $plainText .= $part['text'];
                    if (strlen($plainText) > self::MAX_TEXT_BYTES) {
                        throw new ModelProtocolException('model_text_too_large', 'Gemini text exceeded the safe size limit.');
                    }
                }
                $normalizedParts[] = $this->withThoughtMetadata(
                    array('text' => $part['text']),
                    $part
                );
                continue;
            }

            if (count($calls) >= self::MAX_CALLS) {
                throw new ModelProtocolException('model_call_limit_exceeded', 'Gemini returned too many calls.');
            }
            $rawCall = $part['functionCall'];
            if (!is_array($rawCall) || ($rawCall !== array() && Arr::isList($rawCall))) {
                throw new ModelProtocolException('function_call_invalid', 'Gemini returned an invalid function call.');
            }
            $providerId = $this->requiredCallId($rawCall);
            if (isset($providerIds[$providerId])) {
                throw new ModelProtocolException('function_call_id_duplicate', 'Gemini returned duplicate call ids.');
            }
            $providerIds[$providerId] = true;
            if (!$firstFunctionCallSeen) {
                $firstFunctionCallSeen = true;
                if (
                    !isset($part['thoughtSignature'])
                    || !is_string($part['thoughtSignature'])
                    || $part['thoughtSignature'] === ''
                ) {
                    throw new ModelProtocolException(
                        'model_signature_missing',
                        'Gemini returned an executable step without its required thought signature.'
                    );
                }
            }
            if (!isset($rawCall['name']) || !is_string($rawCall['name'])) {
                throw new ModelProtocolException('function_call_name_invalid', 'Gemini returned an invalid function name.');
            }
            $name = $rawCall['name'];
            if (trim($name) !== $name || preg_match('/^[A-Za-z][A-Za-z0-9_]{1,127}$/', $name) !== 1) {
                throw new ModelProtocolException('function_call_name_invalid', 'Gemini returned an invalid function name.');
            }
            $args = $rawCall['args'] ?? array();
            if (!is_array($args) || ($args !== array() && Arr::isList($args))) {
                throw new ModelProtocolException('function_call_args_invalid', 'Function-call arguments must be a JSON object.');
            }
            $calls[] = new FunctionCall(Uuid::v4(), $providerId, $name, $args);

            // Build a closed request-safe functionCall. Preserve args as an
            // object even when empty; otherwise PHP serializes it as [] and
            // Gemini rejects the next GenerateContent request.
            $normalizedCall = array(
                'id' => $providerId,
                'name' => $name,
                'args' => (object) $args,
            );
            $normalizedParts[] = $this->withThoughtMetadata(
                array('functionCall' => $normalizedCall),
                $part
            );
        }

        if ($calls !== array() && trim($plainText) !== '') {
            throw new ModelProtocolException(
                'model_output_mixed',
                'Gemini returned visible prose and function calls in the same executable step.'
            );
        }
        if ($calls === array() && trim($plainText) === '') {
            throw new ModelProtocolException('model_output_empty', 'Gemini returned no function calls or visible text.');
        }

        // Never echo the provider response object. Rebuild the documented
        // Content request shape so output-only or future metadata cannot poison
        // a subsequent GenerateContent round.
        $normalizedContent = array(
            'role' => 'model',
            'parts' => $normalizedParts,
        );
        return new GeminiDecodedStep(
            new ModelStep(Uuid::v4(), $calls, $plainText, $finishReason),
            $normalizedContent
        );
    }

    private function assertFinishReason(string $reason): void
    {
        if ($reason === 'STOP') {
            return;
        }
        $codes = array(
            '' => 'model_finish_missing',
            'FINISH_REASON_UNSPECIFIED' => 'model_finish_unspecified',
            'SAFETY' => 'model_finish_safety',
            'RECITATION' => 'model_finish_recitation',
            'LANGUAGE' => 'model_finish_language',
            'BLOCKLIST' => 'model_finish_blocklist',
            'PROHIBITED_CONTENT' => 'model_finish_prohibited_content',
            'SPII' => 'model_finish_sensitive_data',
            'MALFORMED_FUNCTION_CALL' => 'model_finish_function_call_invalid',
            'IMAGE_SAFETY' => 'model_finish_image_safety',
            'IMAGE_PROHIBITED_CONTENT' => 'model_finish_image_prohibited_content',
            'IMAGE_OTHER' => 'model_finish_image_rejected',
            'NO_IMAGE' => 'model_finish_image_missing',
            'MISSING_THOUGHT_SIGNATURE' => 'model_finish_signature_missing',
            'MALFORMED_RESPONSE' => 'model_finish_malformed_response',
            'TOO_MANY_TOOL_CALLS' => 'model_finish_tool_limit',
            'UNEXPECTED_TOOL_CALL' => 'model_finish_tool_unexpected',
        );
        throw new ModelProtocolException(
            isset($codes[$reason]) ? $codes[$reason] : 'model_finish_rejected',
            'Gemini returned an incomplete or rejected candidate with finish reason ' . $reason . '.'
        );
    }

    /** @param array<string,mixed> $part @return array<int,string> */
    private function presentDataKeys(array $part): array
    {
        $present = array();
        foreach (self::PART_DATA_KEYS as $key) {
            if (array_key_exists($key, $part)) {
                $present[] = $key;
            }
        }
        return $present;
    }

    /** @param array<string,mixed> $part */
    private function validateThoughtMetadata(array $part): void
    {
        if (array_key_exists('thought', $part) && !is_bool($part['thought'])) {
            throw new ModelProtocolException('model_thought_flag_invalid', 'Gemini thought flag is invalid.');
        }
        if (
            array_key_exists('thoughtSignature', $part)
            && (!is_string($part['thoughtSignature'])
                || strlen($part['thoughtSignature']) > self::MAX_SIGNATURE_BYTES)
        ) {
            throw new ModelProtocolException('model_signature_invalid', 'Gemini thought signature is invalid.');
        }
    }

    /**
     * @param array<string,mixed> $normalized
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function withThoughtMetadata(array $normalized, array $source): array
    {
        if (array_key_exists('thought', $source)) {
            $normalized['thought'] = $source['thought'];
        }
        if (array_key_exists('thoughtSignature', $source)) {
            $normalized['thoughtSignature'] = $source['thoughtSignature'];
        }
        return $normalized;
    }

    /** @param array<string,mixed> $value */
    private function assertObject(array $value, string $code, string $label): void
    {
        if ($value !== array() && Arr::isList($value)) {
            throw new ModelProtocolException($code, $label . ' must be an object.');
        }
    }

    /** @param array<string,mixed> $call */
    private function requiredCallId(array $call): string
    {
        if (!array_key_exists('id', $call)) {
            throw new ModelProtocolException(
                'function_call_provider_id_invalid',
                'Gemini returned a function call without its required id.'
            );
        }
        if (!is_string($call['id'])) {
            throw new ModelProtocolException(
                'function_call_provider_id_invalid',
                'Gemini returned a non-string function-call id.'
            );
        }
        $id = $call['id'];
        if ($id === '' || strlen($id) > 256 || trim($id) !== $id) {
            throw new ModelProtocolException(
                'function_call_provider_id_invalid',
                'A Gemini function-call id must be nonblank, bounded, and exact.'
            );
        }
        return $id;
    }

    /** @param array<string,mixed> $object */
    private function optionalString(array $object, string $key, string $code): string
    {
        if (!array_key_exists($key, $object)) {
            return '';
        }
        if (!is_string($object[$key])) {
            throw new ModelProtocolException($code, 'Gemini returned a non-string ' . $key . '.');
        }
        $value = trim($object[$key]);
        if (strlen($value) > 128) {
            throw new ModelProtocolException($code, 'Gemini returned an oversized ' . $key . '.');
        }
        return $value;
    }
}
