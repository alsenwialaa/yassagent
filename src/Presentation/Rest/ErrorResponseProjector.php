<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest;

use InvalidArgumentException;
use YassinStore\AiAssistant\Application\Contract\PublicResponseSchemaValidator;
use YassinStore\AiAssistant\Presentation\Rest\Response\ErrorResponse;

/** Builds and validates the shared public error envelope. */
final class ErrorResponseProjector
{
    /** @var PublicResponseSchemaValidator */
    private $validator;

    public function __construct(PublicResponseSchemaValidator $validator)
    {
        $this->validator = $validator;
    }

    public function project(
        string $code,
        string $safeMessage,
        int $status,
        int $retryAfter = 0
    ): ErrorResponse {
        if (
            trim($code) !== $code
            || trim($safeMessage) !== $safeMessage
            || $retryAfter < 0
            || $retryAfter > 4294967295
        ) {
            throw new InvalidArgumentException('Public REST error projection is invalid.');
        }
        $payload = array(
            'ok' => false,
            'code' => $code,
            'message' => $safeMessage,
        );
        if ($retryAfter > 0) {
            $payload['retry_after'] = $retryAfter;
        }
        return new ErrorResponse($this->validator, $payload, $status);
    }
}
