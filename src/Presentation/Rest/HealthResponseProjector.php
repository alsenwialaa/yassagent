<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest;

use YassinStore\AiAssistant\Application\Contract\PublicResponseSchemaValidator;
use YassinStore\AiAssistant\Presentation\Rest\Response\HealthResponse;

final class HealthResponseProjector
{
    /** @var PublicResponseSchemaValidator */
    private $validator;

    public function __construct(PublicResponseSchemaValidator $validator)
    {
        $this->validator = $validator;
    }

    public function project(
        string $version,
        bool $assistantReady,
        int $serverTime
    ): HealthResponse {
        return new HealthResponse($this->validator, array(
            'ok' => true,
            'version' => $version,
            'architecture' => 'ai-led-fenced-turns',
            'assistant_ready' => $assistantReady,
            'server_time' => $serverTime,
        ));
    }
}
