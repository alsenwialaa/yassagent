<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Gemini;

use Throwable;
use YassinStore\AiAssistant\Application\Ai\ModelGatewayException;
use YassinStore\AiAssistant\Application\Ai\ModelProtocolException;
use YassinStore\AiAssistant\Application\Ai\ModelRequest;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;

/**
 * Two-request administrative runtime check.
 *
 * Request one proves configured-model access. Request two proves one tiny closed
 * function declaration and one decoded function call. Shopping prompts, the
 * production tool catalog, cart semantics, and WooCommerce are deliberately
 * excluded; release and integration tests own those deeper contracts.
 */
final class GeminiRuntimeProbe
{
    /** @var GeminiTimeoutTransportInterface */ private $transport;
    /** @var GeminiResponseDecoder */ private $decoder;
    /** @var GeminiSchemaProjector */ private $schemas;
    /** @var GeminiGenerationPolicy */ private $generation;
    /** @var Settings */ private $settings;
    /** @var GeminiRuntimeReadiness */ private $readiness;

    public function __construct(
        GeminiTimeoutTransportInterface $transport,
        GeminiResponseDecoder $decoder,
        GeminiSchemaProjector $schemas,
        GeminiGenerationPolicy $generation,
        Settings $settings,
        GeminiRuntimeReadiness $readiness
    ) {
        $this->transport = $transport;
        $this->decoder = $decoder;
        $this->schemas = $schemas;
        $this->generation = $generation;
        $this->settings = $settings;
        $this->readiness = $readiness;
    }

    /** @return array<string,mixed> */
    public function testConnection(): array
    {
        if ($this->settings->apiKey() === '') {
            throw new GeminiException(
                'api_key_missing',
                'مفتاح خدمة الذكاء الاصطناعي غير مُعد.',
                'Gemini runtime probe cannot start without an API key.'
            );
        }

        try {
            $attemptId = $this->readiness->beginCheck();
        } catch (RuntimeProbeInProgress $exception) {
            throw new GeminiException(
                'runtime_probe_in_progress',
                'يوجد فحص جاهزية Gemini قيد التنفيذ بالفعل. استخدم نتيجته أو أعد المحاولة بعد انتهائه.',
                $exception->getMessage(),
                '',
                $exception->retryAfterSeconds()
            );
        }

        try {
            return $this->runAttempt($attemptId);
        } catch (RuntimeProbeSuperseded $exception) {
            throw new GeminiException(
                'runtime_probe_superseded',
                'بدأ مسؤول فحص جاهزية أحدث. استخدم نتيجة الفحص الأحدث.',
                $exception->getMessage()
            );
        }
    }

    /** @return array<string,mixed> */
    private function runAttempt(string $attemptId): array
    {
        $stage = RuntimeProbeFailureMapper::PROVIDER_ACCESS;
        try {
            $this->assertProviderAccess();
            // A stale/superseded attempt is stopped before it spends the second
            // provider request or publishes an obsolete result.
            $this->readiness->assertCurrentAttempt($attemptId);

            $stage = RuntimeProbeFailureMapper::STRUCTURED_TOOL;
            $this->assertStructuredTool();
        } catch (RuntimeProbeSuperseded $exception) {
            throw $exception;
        } catch (ModelGatewayException $exception) {
            $code = RuntimeProbeFailureMapper::code($stage, $exception->reasonCode());
            $this->readiness->markFailed($code, $attemptId);
            throw $this->providerFailure($stage, $code, $exception);
        } catch (ModelProtocolException $exception) {
            $code = $stage === RuntimeProbeFailureMapper::PROVIDER_ACCESS
                ? 'runtime_probe_access_response_invalid'
                : 'runtime_probe_structured_tool_invalid';
            $this->readiness->markFailed($code, $attemptId);
            throw new GeminiException(
                $code,
                $stage === RuntimeProbeFailureMapper::PROVIDER_ACCESS
                    ? 'اتصل المزود، لكنه لم يُعد استجابة نموذج صالحة لفحص الوصول.'
                    : 'اتصل المزود، لكنه لم يجتز فحص الاستدعاء المنظم المصغر.',
                $exception->getMessage()
            );
        } catch (Throwable $exception) {
            $this->readiness->markFailed('runtime_probe_interrupted', $attemptId);
            throw new GeminiException(
                'runtime_probe_interrupted',
                'توقف فحص جاهزية Gemini قبل اكتماله. أعد المحاولة.',
                'Gemini runtime probe interrupted: ' . get_class($exception)
            );
        }

        $this->readiness->markReady($attemptId);
        $status = $this->readiness->status();
        if (empty($status['ready'])) {
            throw new GeminiException(
                'runtime_probe_publication_failed',
                'اكتمل الفحص، لكن تعذر نشر إثبات الجاهزية. أعد المحاولة.',
                'Runtime readiness was not ready after markReady.'
            );
        }

        return array(
            'ok' => true,
            'reply' => 'جاهز',
            'model' => Settings::GEMINI_MODEL,
            'checks' => array(
                'provider_access' => 'passed',
                'structured_tool' => 'passed',
            ),
            'provider_requests' => RuntimeProbeContract::REQUEST_COUNT,
            'checked_at' => (int) ($status['checked_at'] ?? 0),
            'expires_at' => (int) ($status['expires_at'] ?? 0),
        );
    }

    private function assertProviderAccess(): void
    {
        $raw = $this->transport->generateWithTimeout(array(
            'systemInstruction' => array(
                'parts' => array(array(
                    'text' => RuntimeProbeContract::accessSystemInstruction(),
                )),
            ),
            'generationConfig' => $this->generation->initialConfig(256),
            'contents' => array(array(
                'role' => 'user',
                'parts' => array(array(
                    'text' => RuntimeProbeContract::accessUserMessage(),
                )),
            )),
        ), $this->providerTimeoutSeconds());
        $step = $this->decoder->decode($raw)->step();
        if ($step->hasCalls() || trim($step->plainText()) === '') {
            throw new ModelProtocolException(
                'runtime_probe_access_response_invalid',
                'The provider-access request did not return one non-empty text response.'
            );
        }
    }

    private function assertStructuredTool(): void
    {
        $token = bin2hex(random_bytes(16));
        $declaration = RuntimeProbeContract::declaration($token);
        $gateway = new GeminiGateway(
            $this->transport,
            $this->decoder,
            $this->schemas,
            $this->generation
        );
        $session = $gateway->start(new ModelRequest(
            RuntimeProbeContract::structuredSystemInstruction(),
            array(),
            RuntimeProbeContract::structuredUserMessage($token),
            array(),
            array($declaration),
            256
        ));
        $session->requireOnlyNextFunction(RuntimeProbeContract::TOOL);
        $session->setNextTimeoutSeconds($this->providerTimeoutSeconds());
        $step = $session->next();
        $calls = $step->calls();
        if (
            count($calls) !== 1
            || $calls[0]->name() !== RuntimeProbeContract::TOOL
            || $calls[0]->arguments() !== array('token' => $token)
        ) {
            throw new ModelProtocolException(
                'runtime_probe_structured_tool_invalid',
                'The provider did not return the exact readiness_echo function call.'
            );
        }
    }

    private function providerTimeoutSeconds(): int
    {
        return RuntimeProbeTiming::providerRequestSeconds(
            (int) $this->settings->get('http_timeout_seconds', 30)
        );
    }

    private function providerFailure(
        string $stage,
        string $code,
        ModelGatewayException $exception
    ): GeminiException {
        $label = $stage === RuntimeProbeFailureMapper::PROVIDER_ACCESS
            ? 'الوصول إلى النموذج'
            : 'الاستدعاء المنظم المصغر';
        $field = $exception instanceof GeminiException
            ? $exception->providerField()
            : '';
        $retryAfter = $exception instanceof GeminiException
            ? $exception->retryAfterSeconds()
            : 0;
        $safe = 'فشل فحص ' . $label . '. ' . $exception->safeMessage();
        if ($field !== '') {
            $safe .= ' مسار العقد المرفوض: ' . $field . '.';
        }

        return new GeminiException(
            $code,
            $safe,
            'Gemini runtime probe failed during ' . $stage . ': ' . $exception->reasonCode(),
            $field,
            $retryAfter
        );
    }
}
