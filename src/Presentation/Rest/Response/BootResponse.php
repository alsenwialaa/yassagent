<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest\Response;

use YassinStore\AiAssistant\Application\Contract\PublicResponseSchemaValidator;

final class BootResponse extends ValidatedPublicResponse
{
    /** @param array<string,mixed> $data */
    public function __construct(PublicResponseSchemaValidator $validator, array $data)
    {
        parent::__construct($validator, 'boot_response', $data, 200);
    }
}
