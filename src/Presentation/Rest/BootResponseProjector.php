<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest;

use YassinStore\AiAssistant\Application\Contract\PublicResponseSchemaValidator;
use YassinStore\AiAssistant\Presentation\Rest\Response\BootResponse;

/** Builds the only accepted storefront boot response shape. */
final class BootResponseProjector
{
    /** @var PublicResponseSchemaValidator */
    private $validator;

    public function __construct(PublicResponseSchemaValidator $validator)
    {
        $this->validator = $validator;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @param array<string,mixed> $widget
     * @param array<string,mixed>|null $cart
     * @param array<string,mixed> $capabilities
     * @param array<string,mixed>|null $pendingTurn
     */
    public function project(
        string $sessionToken,
        string $conversationId,
        string $conversationToken,
        array $messages,
        array $widget,
        ?array $cart,
        bool $cartAvailable,
        string $cartNotice,
        array $capabilities,
        ?array $pendingTurn,
        int $serverTime
    ): BootResponse {
        return new BootResponse($this->validator, array(
            'ok' => true,
            'session' => array(
                'token' => $sessionToken,
            ),
            'conversation' => array(
                'id' => $conversationId,
                'token' => $conversationToken,
                'messages' => $messages,
            ),
            'widget' => $widget,
            'cart' => $cart,
            'cart_available' => $cartAvailable,
            'cart_notice' => $cartNotice,
            'capabilities' => $capabilities,
            'pending_turn' => $pendingTurn,
            'server_time' => $serverTime,
        ));
    }
}
