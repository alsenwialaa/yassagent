<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Gemini;

use RuntimeException;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Json;

/** HTTP-only Gemini adapter. It never interprets model protocol semantics. */
final class GeminiTransport implements GeminiTimeoutTransportInterface
{
    private const MAX_RESPONSE_BYTES = 4194304;

    /** @var Settings */ private $settings;
    /** @var Logger */ private $logger;
    /** @var GeminiEndpoint */ private $endpoint;
    /** @var callable */ private $clock;
    /** @var callable */ private $sleeper;

    public function __construct(
        Settings $settings,
        Logger $logger,
        GeminiEndpoint $endpoint,
        ?callable $clock = null,
        ?callable $sleeper = null
    ) {
        $this->settings = $settings;
        $this->logger = $logger;
        $this->endpoint = $endpoint;
        $this->clock = $clock !== null ? $clock : static function (): float {
            return (float) hrtime(true) / 1000000000.0;
        };
        $this->sleeper = $sleeper !== null ? $sleeper : static function (float $seconds): void {
            if ($seconds > 0.0) {
                usleep((int) min(1000000, ceil($seconds * 1000000.0)));
            }
        };
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function generate(array $payload): array
    {
        return $this->generateWithTimeout(
            $payload,
            (int) $this->settings->get('http_timeout_seconds', 30)
        );
    }

    public function generateWithTimeout(array $payload, int $timeoutSeconds): array
    {
        $apiKey = $this->settings->apiKey();
        if ($apiKey === '') {
            throw new GeminiException(
                'api_key_missing',
                ('مفتاح خدمة الذكاء الاصطناعي غير مُعد.'),
                'Gemini API key is missing.'
            );
        }
        $model = Settings::GEMINI_MODEL;

        try {
            $endpoint = $this->endpoint->generateContent($model);
        } catch (\InvalidArgumentException $exception) {
            throw new GeminiException(
                'model_invalid',
                ('اسم نموذج الذكاء الاصطناعي غير صالح.'),
                $exception->getMessage()
            );
        }
        $budget = (float) max(1, min(90, $timeoutSeconds));
        $deadline = $this->now() + $budget;
        $lastFailure = 'Gemini request failed.';

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $remaining = $deadline - $this->now();
            if ($remaining <= 0.0) {
                throw $this->timeoutException();
            }
            $response = wp_remote_post($endpoint, array(
                'timeout' => max(0.1, min(90.0, $remaining)),
                'redirection' => 0,
                'reject_unsafe_urls' => true,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $apiKey,
                ),
                'body' => Json::encodeObject($payload),
            ));
            // Do not accept a late provider response merely because the HTTP
            // adapter failed to enforce its timeout precisely. Every retry and
            // success belongs to one monotonic provider budget.
            if ($this->now() > $deadline) {
                throw $this->timeoutException();
            }

            if (is_wp_error($response)) {
                $lastFailure = 'Gemini network failure.';
                $this->logger->error('Gemini network failure.', array('attempt' => $attempt));
                if ($attempt < 2 && $this->pauseForRetry($deadline, 0.25)) {
                    continue;
                }
                throw new GeminiException(
                    'network_error',
                    ('تعذر الاتصال بخدمة الذكاء الاصطناعي. حاول مرة أخرى.'),
                    $lastFailure
                );
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                throw new GeminiException(
                    'upstream_payload_too_large',
                    ('تلقت الخدمة استجابة أكبر من الحد الآمن.'),
                    'Gemini response exceeded four megabytes.'
                );
            }

            if ($status >= 200 && $status < 300) {
                try {
                    return Json::decodeRequiredObject($body, 'Gemini response');
                } catch (RuntimeException $exception) {
                    $this->logger->error('Gemini returned malformed success JSON.', array('status' => $status));
                    throw new GeminiException(
                        'upstream_payload_invalid',
                        ('أعاد مزود الذكاء الاصطناعي استجابة غير صالحة.'),
                        $exception->getMessage()
                    );
                }
            }

            $error = $this->decodeError($body);
            $upstreamCode = $error['status'];
            $upstreamReason = $error['reason'];
            $upstreamField = $error['field'];
            $this->logger->error('Gemini request rejected.', array(
                'status' => $status,
                'upstream_code' => $upstreamCode,
                'upstream_reason' => $upstreamReason,
                'upstream_field' => $upstreamField,
                'attempt' => $attempt,
            ));
            $lastFailure = 'Gemini HTTP ' . $status
                . ($upstreamCode !== '' ? ' (' . $upstreamCode . ')' : '')
                . ($upstreamReason !== '' ? ' [' . $upstreamReason . ']' : '')
                . ($upstreamField !== '' ? ' {field:' . $upstreamField . '}' : '')
                . '.';

            if (
                $attempt < 2
                && ($status === 429 || $status >= 500)
                && $this->pauseForRetry($deadline, 0.35)
            ) {
                continue;
            }
            throw $this->httpException(
                $status,
                $upstreamCode,
                $upstreamReason,
                $upstreamField,
                $lastFailure
            );
        }

        throw new GeminiException(
            'unknown_upstream_error',
            ('تعذر إكمال الطلب الآن.'),
            $lastFailure
        );
    }


    private function now(): float
    {
        $value = call_user_func($this->clock);
        return is_float($value) || is_int($value) ? (float) $value : microtime(true);
    }

    private function pauseForRetry(float $deadline, float $seconds): bool
    {
        $remaining = $deadline - $this->now();
        if ($remaining <= $seconds + 0.05) {
            return false;
        }
        call_user_func($this->sleeper, min($seconds, max(0.0, $remaining - 0.05)));
        return $deadline - $this->now() > 0.0;
    }

    private function timeoutException(): GeminiException
    {
        return new GeminiException(
            'provider_timeout',
            ('انتهت مهلة الاتصال بخدمة الذكاء الاصطناعي. حاول مرة أخرى.'),
            'Gemini request exhausted its shared provider deadline.'
        );
    }

    /** @return array{status:string,reason:string,field:string} */
    private function decodeError(string $body): array
    {
        try {
            $payload = Json::decodeRequiredObject($body, 'Gemini error response');
            $error = is_array($payload['error'] ?? null) ? $payload['error'] : array();
            $statusCandidate = isset($error['status']) && is_string($error['status'])
                ? trim($error['status'])
                : '';
            $status = $statusCandidate === ''
                || preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $statusCandidate) === 1
                ? $statusCandidate
                : 'malformed_error';
            $reason = '';
            $field = '';
            $details = $error['details'] ?? array();
            if (is_array($details) && Arr::isList($details)) {
                foreach (array_slice($details, 0, 16) as $detail) {
                    if (!is_array($detail)) {
                        continue;
                    }
                    if ($reason === '' && isset($detail['reason']) && is_string($detail['reason'])) {
                        $candidate = trim($detail['reason']);
                        if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $candidate) === 1) {
                            $reason = $candidate;
                        }
                    }
                    $violations = $detail['fieldViolations'] ?? array();
                    if ($field === '' && is_array($violations) && Arr::isList($violations)) {
                        foreach (array_slice($violations, 0, 16) as $violation) {
                            $candidate = is_array($violation) && isset($violation['field'])
                                && is_string($violation['field'])
                                ? trim($violation['field']) : '';
                            $candidate = GeminiException::normalizeProviderField($candidate);
                            if ($candidate !== '') {
                                $field = $candidate;
                                break;
                            }
                        }
                    }
                }
            }
            return array('status' => $status, 'reason' => $reason, 'field' => $field);
        } catch (RuntimeException $exception) {
            return array('status' => 'malformed_error', 'reason' => '', 'field' => '');
        }
    }

    private function httpException(
        int $status,
        string $upstreamCode,
        string $upstreamReason,
        string $upstreamField,
        string $internal
    ): GeminiException {
        if (
            $upstreamReason === 'API_KEY_INVALID'
            || preg_match('/^API_KEY_[A-Z0-9_]+_BLOCKED$/D', $upstreamReason) === 1
        ) {
            return new GeminiException(
                'authentication_error',
                ('رفض مزود الذكاء الاصطناعي بيانات الدخول. راجع مفتاح API وصلاحياته.'),
                $internal
            );
        }
        if ($upstreamReason === 'SERVICE_DISABLED') {
            return new GeminiException(
                'provider_service_disabled',
                ('خدمة Gemini غير مفعلة للمشروع المرتبط بمفتاح API.'),
                $internal
            );
        }
        if ($status === 402 || $upstreamReason === 'BILLING_DISABLED') {
            return new GeminiException(
                'provider_billing_disabled',
                ('الفوترة المطلوبة لخدمة Gemini غير مفعلة للمشروع المرتبط بمفتاح API.'),
                $internal
            );
        }
        if ($status === 412 || $upstreamCode === 'FAILED_PRECONDITION') {
            return new GeminiException(
                'request_precondition_rejected',
                ('رفض مزود الذكاء الاصطناعي متطلبات هذا الطلب. شغّل فحص الجاهزية وراجع عقد الطلب.'),
                $internal,
                $upstreamField
            );
        }
        if (
            $status === 401 || $status === 403
            || in_array($upstreamCode, array('UNAUTHENTICATED', 'PERMISSION_DENIED'), true)
        ) {
            return new GeminiException(
                'authentication_error',
                ('رفض مزود الذكاء الاصطناعي بيانات الدخول. راجع مفتاح API وصلاحياته.'),
                $internal
            );
        }
        if ($status === 404 || $upstreamCode === 'NOT_FOUND') {
            return new GeminiException(
                'model_not_found',
                ('النموذج المحدد غير متاح لهذا المفتاح. راجع اسم النموذج ثم شغّل اختبار الاتصال.'),
                $internal
            );
        }
        if ($status === 400 || $upstreamCode === 'INVALID_ARGUMENT') {
            return new GeminiException(
                'request_contract_rejected',
                ('رفض مزود الذكاء الاصطناعي بروتوكول الطلب. شغّل اختبار الاتصال من إعدادات الإضافة.'),
                $internal,
                $upstreamField
            );
        }
        if ($status === 429 || $upstreamCode === 'RESOURCE_EXHAUSTED') {
            return new GeminiException(
                'rate_limited',
                ('الخدمة مشغولة الآن. حاول بعد قليل.'),
                $internal
            );
        }
        if ($status >= 500 || in_array($upstreamCode, array('INTERNAL', 'UNAVAILABLE', 'DEADLINE_EXCEEDED'), true)) {
            return new GeminiException(
                'upstream_unavailable',
                ('خدمة الذكاء الاصطناعي غير متاحة مؤقتاً.'),
                $internal
            );
        }
        return new GeminiException(
            'upstream_rejected',
            ('رفض مزود الذكاء الاصطناعي الطلب. راجع إعدادات الإضافة واختبار الاتصال.'),
            $internal
        );
    }
}
