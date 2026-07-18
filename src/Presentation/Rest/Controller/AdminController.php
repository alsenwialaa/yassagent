<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest\Controller;

use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use YassinStore\AiAssistant\Application\Readiness\RuntimeReadinessFailurePolicy;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiRuntimeProbe;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiException;
use YassinStore\AiAssistant\Presentation\Rest\ApiResponder;
use YassinStore\AiAssistant\Presentation\Rest\AdminTestResponseProjector;

final class AdminController
{
    /** @var GeminiRuntimeProbe */ private $gemini;
    /** @var ApiResponder */ private $responses;
    /** @var AdminTestResponseProjector */ private $projector;

    public function __construct(
        GeminiRuntimeProbe $gemini,
        ApiResponder $responses,
        AdminTestResponseProjector $projector
    ) {
        $this->gemini = $gemini;
        $this->responses = $responses;
        $this->projector = $projector;
    }

    public function testConnection(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);
        try {
            return $this->responses->adminTest(
                $this->projector->project($this->gemini->testConnection())
            );
        } catch (GeminiException $exception) {
            return $this->responses->error(
                $exception->reasonCode(),
                $exception->safeMessage(),
                self::statusFor($exception->reasonCode()),
                $exception->retryAfterSeconds()
            );
        } catch (Throwable $exception) {
            return $this->responses->error(
                'test_failed',
                'تعذر إكمال اختبار الاتصال بسبب خطأ داخلي. أعد المحاولة.',
                500
            );
        }
    }

    private static function statusFor(string $code): int
    {
        if (in_array($code, array('runtime_probe_in_progress', 'runtime_probe_superseded'), true)) {
            return 409;
        }
        if ($code === 'api_key_missing') {
            return 400;
        }
        if (RuntimeReadinessFailurePolicy::probeFailureContradictsProof($code)) {
            return 422;
        }
        if ($code === 'runtime_probe_rate_limited') {
            return 429;
        }
        if ($code === 'runtime_probe_timeout') {
            return 504;
        }
        if (
            in_array($code, array(
            'runtime_probe_network_error',
            'runtime_probe_upstream_unavailable',
            ), true)
        ) {
            return 503;
        }
        return 502;
    }
}
