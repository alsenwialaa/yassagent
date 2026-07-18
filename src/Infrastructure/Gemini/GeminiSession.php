<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Gemini;

use YassinStore\AiAssistant\Application\Ai\FunctionFeedback;
use YassinStore\AiAssistant\Application\Ai\ModelProtocolException;
use YassinStore\AiAssistant\Application\Ai\ModelRequest;
use YassinStore\AiAssistant\Application\Ai\ModelStep;
use YassinStore\AiAssistant\Application\Ai\OutputLimitRecoverableSessionInterface;
use YassinStore\AiAssistant\Application\Ai\ProviderTimeoutAwareSessionInterface;
use YassinStore\AiAssistant\Application\Ai\RequiredFunctionSessionInterface;
use YassinStore\AiAssistant\Support\Json;

final class GeminiSession implements ProviderTimeoutAwareSessionInterface, OutputLimitRecoverableSessionInterface, RequiredFunctionSessionInterface
{
    /**
     * Complete serialized `contents` authority for one provider session.
     *
     * This includes the initial transcript, current user input, inline images,
     * every closed request-safe model part (including thought state/signatures),
     * plain output corrections, and function feedback. The transport may accept a
     * four-megabyte response, but no sequence of individually valid responses
     * is allowed to grow the next request without bound.
     */
    private const MAX_HISTORY_BYTES = 4194304;

    /** @var GeminiTransportInterface */ private $transport;
    /** @var GeminiResponseDecoder */ private $decoder;
    /** @var array<string,mixed> */ private $basePayload;
    /** @var array<int,array<string,mixed>> */ private $contents;
    /** @var int */ private $historyBytes;
    /** @var ModelStep|null */ private $pendingStep;
    /** @var array<string,mixed>|null */ private $pendingRequestContent;
    /** @var int|null */ private $nextTimeoutSeconds;
    /** @var array<string,mixed>|null */ private $nextGenerationConfig;
    /** @var array<int,string> */ private $availableFunctionNames;
    /** @var string|null */ private $nextAllowedFunctionName;
    /** @var bool */ private $outputLimitRecoveryUsed;

    /** @param array<string,mixed> $basePayload */
    public function __construct(
        GeminiTransportInterface $transport,
        GeminiResponseDecoder $decoder,
        ModelRequest $request,
        array $basePayload
    ) {
        $this->transport = $transport;
        $this->decoder = $decoder;
        $this->basePayload = $basePayload;
        $this->contents = array();
        $this->historyBytes = 2; // Empty JSON list: [].
        $this->appendContents($this->initialContents($request));
        $this->pendingStep = null;
        $this->pendingRequestContent = null;
        $this->nextTimeoutSeconds = null;
        $this->nextGenerationConfig = null;
        $this->availableFunctionNames = array_map(
            static function (array $declaration): string {
                return (string) ($declaration['name'] ?? '');
            },
            $request->toolDeclarations()
        );
        $this->nextAllowedFunctionName = null;
        $this->outputLimitRecoveryUsed = false;
    }

    /**
     * Require exactly the next provider step to call one already-declared function.
     *
     * This is used by the administrative runtime probe and the isolated
     * one-function semantic verifier. Normal agent sessions never call it and
     * retain the complete AI-led tool catalog in VALIDATED mode.
     */
    public function requireOnlyNextFunction(string $name): void
    {
        if (
            $this->pendingStep !== null || $this->nextAllowedFunctionName !== null
            || !in_array($name, $this->availableFunctionNames, true)
        ) {
            throw new ModelProtocolException(
                'model_function_constraint_invalid',
                'The next-function constraint is invalid for the current session state.'
            );
        }
        $this->nextAllowedFunctionName = $name;
    }

    public function setNextTimeoutSeconds(int $seconds): void
    {
        if ($this->pendingStep !== null || $seconds < 1 || $seconds > 90) {
            throw new ModelProtocolException(
                'provider_timeout_invalid',
                'The next provider timeout is invalid for the current session state.'
            );
        }
        $this->nextTimeoutSeconds = $seconds;
    }

    public function next(): ModelStep
    {
        if ($this->pendingStep !== null) {
            throw new ModelProtocolException(
                'model_step_unresolved',
                'The previous model step must be answered before requesting another step.'
            );
        }

        $payload = $this->basePayload;
        if ($this->nextGenerationConfig !== null) {
            $payload['generationConfig'] = $this->nextGenerationConfig;
            $this->nextGenerationConfig = null;
        }
        if ($this->nextAllowedFunctionName !== null) {
            // ANY is the Gemini mode that requires a function call. The normal
            // agent catalog remains VALIDATED; only this explicitly constrained
            // next step is forced through the one allowed declaration.
            $payload['toolConfig']['functionCallingConfig']['mode'] = 'ANY';
            $payload['toolConfig']['functionCallingConfig']['allowedFunctionNames'] = array(
                $this->nextAllowedFunctionName,
            );
            $this->nextAllowedFunctionName = null;
        }
        $payload['contents'] = $this->contents;
        $timeout = $this->nextTimeoutSeconds;
        $this->nextTimeoutSeconds = null;
        if ($timeout !== null && !$this->transport instanceof GeminiTimeoutTransportInterface) {
            throw new ModelProtocolException(
                'provider_timeout_unsupported',
                'The configured provider transport cannot enforce the required per-request timeout.'
            );
        }
        $raw = $timeout !== null
            ? $this->transport->generateWithTimeout($payload, $timeout)
            : $this->transport->generate($payload);
        $decoded = $this->decoder->decode($raw);
        $this->pendingStep = $decoded->step();
        // Preserve only the decoder's closed request-safe provider content,
        // including exact thought signatures and original part order.
        $this->pendingRequestContent = $decoded->requestContent();
        return $this->pendingStep;
    }


    public function recoverOutputLimit(ModelStep $step): bool
    {
        $this->assertPending($step);
        if ($step->finishReason() !== 'MAX_TOKENS' || $step->hasCalls() || $step->plainText() !== '') {
            throw new ModelProtocolException(
                'model_output_recovery_invalid',
                'Only an empty MAX_TOKENS step can enter output-limit recovery.'
            );
        }
        if ($this->outputLimitRecoveryUsed) {
            return false;
        }

        $current = is_array($this->basePayload['generationConfig'] ?? null)
            ? $this->basePayload['generationConfig']
            : array();
        $tokens = isset($current['maxOutputTokens']) && is_int($current['maxOutputTokens'])
            ? $current['maxOutputTokens']
            : 0;
        $recoveryTokens = min(8192, max(2048, $tokens * 2));
        $changed = $recoveryTokens > $tokens;
        $current['maxOutputTokens'] = $recoveryTokens;

        $thinking = is_array($current['thinkingConfig'] ?? null)
            ? $current['thinkingConfig']
            : null;
        if ($thinking !== null && ($thinking['thinkingLevel'] ?? '') !== 'minimal') {
            $thinking['thinkingLevel'] = 'minimal';
            $current['thinkingConfig'] = $thinking;
            $changed = true;
        }
        if (!$changed) {
            return false;
        }

        $this->outputLimitRecoveryUsed = true;
        $this->nextGenerationConfig = $current;
        // The truncated provider content is deliberately discarded. The next
        // request reuses the last valid history and cannot repeat a tool side
        // effect because no executable call from this step was accepted.
        $this->clearPending();
        return true;
    }

    /** @param array<int,FunctionFeedback> $feedback */
    public function submit(ModelStep $step, array $feedback): void
    {
        $this->assertPending($step);
        $calls = $step->calls();
        if (count($calls) !== count($feedback)) {
            throw new ModelProtocolException(
                'function_feedback_count_mismatch',
                'Function feedback count must exactly match the preceding function call count.'
            );
        }

        $responseParts = array();
        foreach ($calls as $index => $call) {
            $item = $feedback[$index] ?? null;
            if (!$item instanceof FunctionFeedback) {
                throw new ModelProtocolException('function_feedback_invalid', 'Function feedback contains an invalid item.');
            }
            if (!hash_equals($call->id(), $item->id()) || !hash_equals($call->name(), $item->name())) {
                throw new ModelProtocolException(
                    'function_feedback_identity_mismatch',
                    'Function feedback id and name must exactly match the preceding call in the same order.'
                );
            }
            if ($call->providerId() === '') {
                throw new ModelProtocolException(
                    'function_call_provider_id_invalid',
                    'Gemini function feedback requires the exact preceding provider call id.'
                );
            }
            $response = array(
                'id' => $call->providerId(),
                'name' => $item->name(),
                // Keep the provider-facing Struct deliberately flat. PHP
                // cannot distinguish every nested empty JSON object from an
                // empty list, and live catalog results contain several nested
                // object/list boundaries that the tiny cart readiness result
                // never exercised. A single JSON-text result is unambiguous,
                // bounded by FunctionFeedback, and follows Gemini's canonical
                // response.result envelope.
                'response' => (object) array(
                    'result' => Json::encodeObject($item->payload()),
                ),
            );
            $responseParts[] = array('functionResponse' => $response);
        }

        $this->appendContents(array(
            $this->pendingContent(),
            array('role' => 'user', 'parts' => $responseParts),
        ));
        $this->clearPending();
    }

    public function correctPlainOutput(ModelStep $step, string $instruction): void
    {
        $this->assertPending($step);
        if ($step->hasCalls()) {
            throw new ModelProtocolException(
                'plain_output_correction_with_calls',
                'A function-calling step cannot be corrected as plain output.'
            );
        }
        $instruction = trim($instruction);
        if ($instruction === '') {
            throw new ModelProtocolException('plain_output_correction_blank', 'The correction instruction must not be blank.');
        }

        $this->appendContents(array(
            $this->pendingContent(),
            array(
                'role' => 'user',
                'parts' => array(array('text' => $instruction)),
            ),
        ));
        $this->clearPending();
    }

    private function assertPending(ModelStep $step): void
    {
        if ($this->pendingStep === null || $this->pendingRequestContent === null) {
            throw new ModelProtocolException('model_step_not_pending', 'There is no pending model step.');
        }
        if (!hash_equals($this->pendingStep->token(), $step->token())) {
            throw new ModelProtocolException('model_step_mismatch', 'The supplied model step is not the pending step.');
        }
    }

    /** @return array<string,mixed> */
    private function pendingContent(): array
    {
        if ($this->pendingRequestContent === null) {
            throw new ModelProtocolException('model_content_not_pending', 'The pending provider content is missing.');
        }
        return $this->pendingRequestContent;
    }

    /**
     * Append complete provider-history items atomically after proving the next
     * serialized `contents` list remains inside the local memory envelope.
     *
     * @param array<int,array<string,mixed>> $items
     */
    private function appendContents(array $items): void
    {
        if ($items === array()) {
            return;
        }

        $projected = $this->historyBytes;
        $hasExisting = $this->contents !== array();
        foreach ($items as $item) {
            try {
                $itemBytes = strlen(Json::encodeObject($item));
            } catch (\RuntimeException $exception) {
                throw new ModelProtocolException(
                    'model_history_invalid',
                    'Gemini provider history is not safely JSON encodable.'
                );
            }
            $projected += $itemBytes + ($hasExisting ? 1 : 0); // JSON list comma.
            if ($projected > self::MAX_HISTORY_BYTES) {
                throw new ModelProtocolException(
                    'model_history_budget_exceeded',
                    'Gemini provider history exceeded the cumulative safe byte limit.'
                );
            }
            $hasExisting = true;
        }

        foreach ($items as $item) {
            $this->contents[] = $item;
        }
        $this->historyBytes = $projected;
    }

    private function clearPending(): void
    {
        $this->pendingStep = null;
        $this->pendingRequestContent = null;
    }

    /** @return array<int,array<string,mixed>> */
    private function initialContents(ModelRequest $request): array
    {
        $contents = array();
        foreach ($request->history() as $row) {
            $contents[] = array(
                'role' => $row['role'] === 'assistant' ? 'model' : 'user',
                'parts' => array(array('text' => $row['text'])),
            );
        }

        $parts = array();
        if ($request->userText() !== '') {
            $parts[] = array('text' => $request->userText());
        }
        foreach ($request->attachments() as $attachment) {
            $parts[] = array('inlineData' => array(
                'mimeType' => $attachment->mimeType(),
                'data' => $attachment->base64Data(),
            ));
        }
        $contents[] = array('role' => 'user', 'parts' => $parts);
        return $contents;
    }
}
