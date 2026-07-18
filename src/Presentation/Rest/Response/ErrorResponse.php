<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest\Response;

use InvalidArgumentException;
use YassinStore\AiAssistant\Application\Contract\PublicResponseSchemaValidator;

final class ErrorResponse extends ValidatedPublicResponse
{
    /** @param array<string,mixed> $data */
    public function __construct(
        PublicResponseSchemaValidator $validator,
        array $data,
        int $status
    ) {
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException('Public REST error status is invalid.');
        }
        parent::__construct($validator, 'error_response', $data, $status);
    }
}
