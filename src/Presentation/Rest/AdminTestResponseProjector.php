<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest;

use YassinStore\AiAssistant\Application\Contract\PublicResponseSchemaValidator;
use YassinStore\AiAssistant\Presentation\Rest\Response\AdminTestResponse;

final class AdminTestResponseProjector
{
    /** @var PublicResponseSchemaValidator */
    private $validator;

    public function __construct(PublicResponseSchemaValidator $validator)
    {
        $this->validator = $validator;
    }

    /** @param array<string,mixed> $result */
    public function project(array $result): AdminTestResponse
    {
        return new AdminTestResponse($this->validator, array(
            'ok' => true,
            'result' => $result,
        ));
    }
}
