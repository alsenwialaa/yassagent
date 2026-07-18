<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest;

use WP_REST_Response;
use YassinStore\AiAssistant\Infrastructure\Database\SchemaLifecycle;

/**
 * Exact storage authority for assistant REST entry points.
 *
 * WordPress boot and storefront rendering never inspect the physical assistant
 * schema. The exact canary/full validation runs only after route permission has
 * succeeded and immediately before an assistant handler can touch its tables.
 */
final class SchemaRuntimeGate
{
    /** @var ApiResponder */ private $responses;

    public function __construct(ApiResponder $responses)
    {
        $this->responses = $responses;
    }

    public function blockedResponse(): ?WP_REST_Response
    {
        if (SchemaLifecycle::verifyRuntime()) {
            return null;
        }

        $status = SchemaLifecycle::status();
        $state = isset($status['state']) && is_string($status['state'])
            ? $status['state']
            : 'unverifiable';

        if ($state === 'blocked') {
            return $this->responses->error(
                'database_schema_blocked',
                ('قاعدة بيانات المساعد غير جاهزة. يجب على مسؤول الموقع إصلاحها قبل استخدام المساعد.'),
                503
            );
        }

        return $this->responses->error(
            'database_schema_unavailable',
            ('تعذر التحقق من قاعدة بيانات المساعد حالياً. أعد المحاولة بعد قليل.'),
            503
        );
    }
}
