<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest;

use YassinStore\AiAssistant\Application\Contract\PublicResponseSchemaValidator;
use YassinStore\AiAssistant\Presentation\Rest\Response\ConversationDeleteResponse;

final class ConversationDeleteResponseProjector
{
    /** @var PublicResponseSchemaValidator */
    private $validator;

    public function __construct(PublicResponseSchemaValidator $validator)
    {
        $this->validator = $validator;
    }

    public function project(): ConversationDeleteResponse
    {
        return new ConversationDeleteResponse($this->validator, array(
            'ok' => true,
            'deleted' => true,
        ));
    }
}
