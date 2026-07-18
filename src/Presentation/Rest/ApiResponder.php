<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest;

use WP_REST_Response;
use YassinStore\AiAssistant\Presentation\Rest\Response\AdminTestResponse;
use YassinStore\AiAssistant\Presentation\Rest\Response\BootResponse;
use YassinStore\AiAssistant\Presentation\Rest\Response\ConversationDeleteResponse;
use YassinStore\AiAssistant\Presentation\Rest\Response\ConversationExportResponse;
use YassinStore\AiAssistant\Presentation\Rest\Response\HealthResponse;
use YassinStore\AiAssistant\Presentation\Rest\Response\PublicResponsePayload;
use YassinStore\AiAssistant\Presentation\Rest\Response\TurnResponse;

/** Emits only endpoint-specific payloads already verified against the canonical schema. */
final class ApiResponder
{
    /** @var ErrorResponseProjector */
    private $errors;

    public function __construct(ErrorResponseProjector $errors)
    {
        $this->errors = $errors;
    }

    public function boot(BootResponse $response): WP_REST_Response
    {
        return $this->emit($response);
    }

    public function turn(TurnResponse $response): WP_REST_Response
    {
        return $this->emit($response);
    }

    public function health(HealthResponse $response): WP_REST_Response
    {
        return $this->emit($response);
    }

    public function conversationExport(ConversationExportResponse $response): WP_REST_Response
    {
        return $this->emit($response);
    }

    public function conversationDelete(ConversationDeleteResponse $response): WP_REST_Response
    {
        return $this->emit($response);
    }

    public function adminTest(AdminTestResponse $response): WP_REST_Response
    {
        return $this->emit($response);
    }

    public function error(
        string $code,
        string $safeMessage,
        int $status,
        int $retryAfter = 0
    ): WP_REST_Response {
        return $this->emit(
            $this->errors->project($code, $safeMessage, $status, $retryAfter)
        );
    }

    private function emit(PublicResponsePayload $payload): WP_REST_Response
    {
        return $this->noStore(new WP_REST_Response(
            $payload->data(),
            $payload->status()
        ));
    }

    private function noStore(WP_REST_Response $response): WP_REST_Response
    {
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('Expires', '0');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
