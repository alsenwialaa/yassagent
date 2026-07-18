<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest\Controller;

use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use YassinStore\AiAssistant\Application\Port\RuntimeReadinessPort;
use YassinStore\AiAssistant\Infrastructure\Database\SchemaLifecycle;
use YassinStore\AiAssistant\Infrastructure\Security\IngressRateLimiter;
use YassinStore\AiAssistant\Infrastructure\Security\RequestGuard;
use YassinStore\AiAssistant\Presentation\Rest\ApiResponder;
use YassinStore\AiAssistant\Presentation\Rest\HealthResponseProjector;

final class HealthController
{
    /** @var RuntimeReadinessPort */ private $readiness;
    /** @var ApiResponder */ private $responses;
    /** @var IngressRateLimiter */ private $ingress;
    /** @var RequestGuard */ private $guard;
    /** @var HealthResponseProjector */ private $projector;

    public function __construct(
        RuntimeReadinessPort $readiness,
        ApiResponder $responses,
        IngressRateLimiter $ingress,
        RequestGuard $guard,
        HealthResponseProjector $projector
    ) {
        $this->readiness = $readiness;
        $this->responses = $responses;
        $this->ingress = $ingress;
        $this->guard = $guard;
        $this->projector = $projector;
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $rate = $this->ingress->consumeHealth($this->guard->clientIp());
        } catch (Throwable $exception) {
            return $this->responses->error(
                'request_admission_unavailable',
                ('تعذر التحقق من سماح الطلب حالياً. أعد المحاولة بعد قليل.'),
                503
            );
        }

        if (!$rate['allowed']) {
            return $this->responses->error(
                'health_rate_limited',
                ('تم تجاوز حد فحص حالة المساعد مؤقتاً. حاول لاحقاً.'),
                429,
                (int) $rate['retry_after']
            );
        }

        // Health is a read-only composition of the physical schema canary and
        // the cached two-request provider proof. It never depends on recent
        // shopper traffic and never writes an option during ordinary requests.
        try {
            return $this->responses->health($this->projector->project(
                YSAI_VERSION,
                SchemaLifecycle::verifyRuntime() && $this->readiness->isReady(),
                time()
            ));
        } catch (Throwable $exception) {
            // Contract or readiness drift must still leave this plugin-owned
            // route inside the canonical public error envelope.
            return $this->responses->error(
                'health_failed',
                ('تعذر التحقق من حالة المساعد حالياً. أعد المحاولة بعد قليل.'),
                503
            );
        }
    }
}
