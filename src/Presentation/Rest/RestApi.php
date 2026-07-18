<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest;

use WP_REST_Request;
use WP_REST_Response;
use YassinStore\AiAssistant\Infrastructure\Security\RequestGuard;
use YassinStore\AiAssistant\Presentation\Rest\Controller\AdminController;
use YassinStore\AiAssistant\Presentation\Rest\Controller\BootController;
use YassinStore\AiAssistant\Presentation\Rest\Controller\ChatController;
use YassinStore\AiAssistant\Presentation\Rest\Controller\ConversationPrivacyController;
use YassinStore\AiAssistant\Presentation\Rest\Controller\HealthController;
use YassinStore\AiAssistant\Application\Contract\PublicApiContract;

/**
 * REST route composition only. Transport controllers delegate all turn and
 * commerce behavior to application services; no authority transitions live in
 * this class.
 */
final class RestApi
{
    public const NAMESPACE = 'yassin-ai/v1';

    /** @var RequestGuard */ private $guard;
    /** @var HealthController */ private $health;
    /** @var BootController */ private $boot;
    /** @var ChatController */ private $chat;
    /** @var ConversationPrivacyController */ private $privacy;
    /** @var AdminController */ private $admin;
    /** @var SchemaRuntimeGate */ private $schema;
    /** @var ApiResponder */ private $responses;

    public function __construct(
        RequestGuard $guard,
        HealthController $health,
        BootController $boot,
        ChatController $chat,
        ConversationPrivacyController $privacy,
        AdminController $admin,
        PublicApiContract $contract,
        SchemaRuntimeGate $schema,
        ApiResponder $responses
    ) {
        if (!hash_equals(self::NAMESPACE, $contract->namespace())) {
            throw new \RuntimeException('Public API namespace does not match the registered REST namespace.');
        }
        $this->guard = $guard;
        $this->health = $health;
        $this->boot = $boot;
        $this->chat = $chat;
        $this->privacy = $privacy;
        $this->admin = $admin;
        $this->schema = $schema;
        $this->responses = $responses;
    }

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/health', array(
            'methods' => 'GET',
            'callback' => array($this, 'health'),
            'permission_callback' => array($this, 'publicPermission'),
        ));
        register_rest_route(self::NAMESPACE, '/boot', array(
            'methods' => 'POST',
            'callback' => array($this, 'boot'),
            'permission_callback' => array($this, 'publicPermission'),
        ));
        register_rest_route(self::NAMESPACE, '/chat', array(
            'methods' => 'POST',
            'callback' => array($this, 'chat'),
            'permission_callback' => array($this, 'publicPermission'),
        ));
        register_rest_route(self::NAMESPACE, '/conversation/export', array(
            'methods' => 'POST',
            'callback' => array($this, 'exportConversation'),
            'permission_callback' => array($this, 'publicPermission'),
        ));
        register_rest_route(self::NAMESPACE, '/conversation/delete', array(
            'methods' => 'POST',
            'callback' => array($this, 'deleteConversation'),
            'permission_callback' => array($this, 'publicPermission'),
        ));
        register_rest_route(self::NAMESPACE, '/admin/test', array(
            'methods' => 'POST',
            'callback' => array($this, 'testConnection'),
            'permission_callback' => array($this, 'adminPermission'),
        ));
    }

    public function publicPermission(WP_REST_Request $request)
    {
        return true;
    }

    public function adminPermission(WP_REST_Request $request)
    {
        return true;
    }

    public function health(WP_REST_Request $request): WP_REST_Response
    {
        $blocked = $this->publicBlocked($request);
        return $blocked !== null ? $blocked : $this->health->handle($request);
    }

    public function boot(WP_REST_Request $request): WP_REST_Response
    {
        $blocked = $this->publicBlocked($request);
        return $blocked !== null ? $blocked : $this->boot->handle($request);
    }

    public function chat(WP_REST_Request $request): WP_REST_Response
    {
        $blocked = $this->publicBlocked($request);
        return $blocked !== null ? $blocked : $this->chat->handle($request);
    }

    public function exportConversation(WP_REST_Request $request): WP_REST_Response
    {
        $blocked = $this->publicBlocked($request);
        return $blocked !== null ? $blocked : $this->privacy->export($request);
    }

    public function deleteConversation(WP_REST_Request $request): WP_REST_Response
    {
        $blocked = $this->publicBlocked($request);
        return $blocked !== null ? $blocked : $this->privacy->delete($request);
    }

    public function testConnection(WP_REST_Request $request): WP_REST_Response
    {
        $permission = $this->adminBlocked($request);
        if ($permission !== null) {
            return $permission;
        }
        $blocked = $this->schema->blockedResponse();
        return $blocked !== null ? $blocked : $this->admin->testConnection($request);
    }

    private function publicBlocked(WP_REST_Request $request): ?WP_REST_Response
    {
        $rejection = $this->guard->publicRejection($request);
        return $rejection === null ? null : $this->rejectionResponse($rejection);
    }

    private function adminBlocked(WP_REST_Request $request): ?WP_REST_Response
    {
        $rejection = $this->guard->adminRejection($request);
        return $rejection === null ? null : $this->rejectionResponse($rejection);
    }

    /** @param array{code:string,message:string,status:int} $rejection */
    private function rejectionResponse(array $rejection): WP_REST_Response
    {
        return $this->responses->error(
            $rejection['code'],
            $rejection['message'],
            $rejection['status']
        );
    }
}
