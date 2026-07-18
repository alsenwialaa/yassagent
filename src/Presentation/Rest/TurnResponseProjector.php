<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest;

use YassinStore\AiAssistant\Application\Contract\PublicResponseSchemaValidator;
use YassinStore\AiAssistant\Presentation\Rest\Response\TurnResponse;

/** Builds the only accepted storefront turn response shape. */
final class TurnResponseProjector
{
    /** @var PublicResponseSchemaValidator */
    private $validator;

    public function __construct(PublicResponseSchemaValidator $validator)
    {
        $this->validator = $validator;
    }

    /**
     * @param array<string,mixed> $message
     * @param array<int,array<string,mixed>> $messages
     * @param array<string,mixed>|null $cart
     * @param array<string,mixed> $cartMutations
     */
    public function project(
        array $message,
        bool $committed,
        string $conversationId,
        string $conversationToken,
        array $messages,
        bool $messagesAvailable,
        string $messagesNotice,
        ?array $cart,
        bool $cartAvailable,
        string $cartNotice,
        array $cartMutations
    ): TurnResponse {
        return new TurnResponse($this->validator, array(
            'ok' => true,
            'message' => $message,
            'turn_committed' => $committed,
            'conversation' => array(
                'id' => $conversationId,
                'token' => $conversationToken,
                'messages' => $messages,
            ),
            'messages_available' => $messagesAvailable,
            'messages_notice' => $messagesNotice,
            'cart' => $cart,
            'cart_available' => $cartAvailable,
            'cart_notice' => $cartNotice,
            'cart_mutations' => $cartMutations,
        ));
    }
}
