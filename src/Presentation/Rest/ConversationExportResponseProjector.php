<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest;

use YassinStore\AiAssistant\Application\Contract\PublicResponseSchemaValidator;
use YassinStore\AiAssistant\Presentation\Rest\Response\ConversationExportResponse;

final class ConversationExportResponseProjector
{
    /** @var PublicResponseSchemaValidator */
    private $validator;

    public function __construct(PublicResponseSchemaValidator $validator)
    {
        $this->validator = $validator;
    }

    /** @param array<string,mixed> $export */
    public function project(array $export): ConversationExportResponse
    {
        return new ConversationExportResponse($this->validator, array(
            'ok' => true,
            'export' => $export,
        ));
    }
}
