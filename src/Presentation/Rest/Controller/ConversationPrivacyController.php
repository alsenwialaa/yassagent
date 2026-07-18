<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest\Controller;

use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use YassinStore\AiAssistant\Domain\Exception\InvalidRequest;
use YassinStore\AiAssistant\Infrastructure\Database\ConversationPrivacyConflict;
use YassinStore\AiAssistant\Infrastructure\Database\ConversationPrivacyService;
use YassinStore\AiAssistant\Infrastructure\Database\ConversationRepository;
use YassinStore\AiAssistant\Infrastructure\Security\IngressRateLimiter;
use YassinStore\AiAssistant\Infrastructure\Security\ConversationExportCursorInvalid;
use YassinStore\AiAssistant\Infrastructure\Security\RequestGuard;
use YassinStore\AiAssistant\Infrastructure\Security\SessionTokenService;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;
use YassinStore\AiAssistant\Presentation\Rest\ApiResponder;
use YassinStore\AiAssistant\Presentation\Rest\ConversationDeleteResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\ConversationExportResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\SchemaRuntimeGate;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Uuid;

/** Conversation-holder privacy lifecycle; administrator authority is not substituted. */
final class ConversationPrivacyController
{
    /** @var SessionTokenService */ private $sessions;
    /** @var IngressRateLimiter */ private $ingress;
    /** @var SchemaRuntimeGate */ private $schema;
    /** @var ConversationRepository */ private $conversations;
    /** @var ConversationPrivacyService */ private $privacy;
    /** @var RequestGuard */ private $guard;
    /** @var ApiResponder */ private $responses;
    /** @var Logger */ private $logger;
    /** @var ConversationExportResponseProjector */ private $exportProjector;
    /** @var ConversationDeleteResponseProjector */ private $deleteProjector;

    public function __construct(
        SessionTokenService $sessions,
        IngressRateLimiter $ingress,
        SchemaRuntimeGate $schema,
        ConversationRepository $conversations,
        ConversationPrivacyService $privacy,
        RequestGuard $guard,
        ApiResponder $responses,
        ConversationExportResponseProjector $exportProjector,
        ConversationDeleteResponseProjector $deleteProjector,
        Logger $logger
    ) {
        $this->sessions = $sessions;
        $this->ingress = $ingress;
        $this->schema = $schema;
        $this->conversations = $conversations;
        $this->privacy = $privacy;
        $this->guard = $guard;
        $this->responses = $responses;
        $this->exportProjector = $exportProjector;
        $this->deleteProjector = $deleteProjector;
        $this->logger = $logger;
    }

    public function export(WP_REST_Request $request): WP_REST_Response
    {
        return $this->handle($request, false);
    }

    public function delete(WP_REST_Request $request): WP_REST_Response
    {
        return $this->handle($request, true);
    }

    private function handle(WP_REST_Request $request, bool $delete): WP_REST_Response
    {
        try {
            $sessionToken = (string) $request->get_header('X-YSAI-Session');
            try {
                $sessionHash = $this->sessions->validateTransport($sessionToken);
            } catch (RuntimeException $exception) {
                return $this->responses->error(
                    'session_invalid',
                    ('انتهت جلسة المساعد. أعد فتحه من الصفحة.'),
                    401
                );
            }

            try {
                $admission = $this->ingress->consumeConversationPrivacy(
                    $sessionHash,
                    $this->guard->clientIp()
                );
            } catch (Throwable $exception) {
                $this->logger->error('Conversation privacy ingress admission failed.', array(
                    'type' => get_class($exception),
                    'message' => $exception->getMessage(),
                ));
                return $this->responses->error(
                    'request_admission_unavailable',
                    ('تعذر التحقق من سماح الطلب حالياً. أعد المحاولة بعد قليل.'),
                    503
                );
            }
            if (!$admission['allowed']) {
                return $this->responses->error(
                    'conversation_privacy_rate_limited',
                    ('تم تجاوز حد طلبات بيانات المحادثة مؤقتاً. حاول لاحقاً.'),
                    429,
                    (int) $admission['retry_after']
                );
            }

            $credentials = $this->credentials($request, $delete);
            $blocked = $this->schema->blockedResponse();
            if ($blocked !== null) {
                return $blocked;
            }
            try {
                $this->sessions->assertActive($sessionToken, $sessionHash);
            } catch (RuntimeException $exception) {
                return $this->responses->error(
                    'session_invalid',
                    ('انتهت جلسة المساعد. أعد فتحه من الصفحة.'),
                    401
                );
            }
            $conversation = $this->conversations->resume(
                $credentials['conversation_id'],
                $credentials['conversation_token'],
                $sessionHash
            );
            if ($conversation === null) {
                return $this->responses->error(
                    'conversation_invalid',
                    ('انتهت جلسة المحادثة. أعد فتح المساعد.'),
                    401
                );
            }

            if ($delete) {
                $this->privacy->delete($conversation);
                return $this->responses->conversationDelete(
                    $this->deleteProjector->project()
                );
            }
            return $this->responses->conversationExport(
                $this->exportProjector->project(
                    $this->privacy->export($conversation, $credentials['cursor'])
                )
            );
        } catch (InvalidRequest $exception) {
            return $this->responses->error(
                $exception->reasonCode(),
                $exception->safeMessage(),
                $exception->httpStatus()
            );
        } catch (ConversationPrivacyConflict $exception) {
            return $this->responses->error(
                'conversation_busy',
                ('توجد عملية نشطة في هذه المحادثة. انتظر اكتمالها ثم أعد المحاولة.'),
                409
            );
        } catch (ConversationExportCursorInvalid $exception) {
            return $this->responses->error(
                'conversation_export_cursor_invalid',
                ('انتهت صلاحية متابعة التصدير أو لم تعد مطابقة للمحادثة. ابدأ التصدير من جديد.'),
                400
            );
        } catch (Throwable $exception) {
            $this->logger->error('Conversation privacy lifecycle failed.', array(
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
            ));
            return $this->responses->error(
                'conversation_privacy_failed',
                ('تعذر إكمال طلب بيانات المحادثة حالياً.'),
                500
            );
        }
    }

    /** @return array{conversation_id:string,conversation_token:string,cursor:?string} */
    private function credentials(WP_REST_Request $request, bool $delete): array
    {
        $body = $request->get_json_params();
        if (
            !is_array($body)
            || ($body !== array() && Arr::isList($body))
        ) {
            throw $this->invalidCredentials();
        }
        $keys = array_keys($body);
        sort($keys, SORT_STRING);
        $expected = $delete
            ? array('conversation_id', 'conversation_token')
            : array('conversation_id', 'conversation_token', 'cursor');
        $exportWithoutCursors = !$delete && $keys === array('conversation_id', 'conversation_token');
        if (
            (!$exportWithoutCursors && $keys !== $expected)
            || !is_string($body['conversation_id'])
            || !is_string($body['conversation_token'])
        ) {
            throw $this->invalidCredentials();
        }
        $conversationId = $body['conversation_id'];
        $conversationToken = $body['conversation_token'];
        if (
            trim($conversationId) !== $conversationId
            || trim($conversationToken) !== $conversationToken
            || !Uuid::isV4($conversationId)
            || strlen($conversationToken) < 24
            || strlen($conversationToken) > 180
            || preg_match('/^[A-Za-z0-9_-]+$/', $conversationToken) !== 1
        ) {
            throw $this->invalidCredentials();
        }
        $cursor = array_key_exists('cursor', $body) ? $body['cursor'] : null;
        if (
            $cursor !== null
            && (!is_string($cursor)
                || $cursor === ''
                || strlen($cursor) > 2048
                || preg_match('/^[A-Za-z0-9_-]+\.[a-f0-9]{64}$/', $cursor) !== 1)
        ) {
            throw $this->invalidCredentials();
        }
        return array(
            'conversation_id' => strtolower($conversationId),
            'conversation_token' => $conversationToken,
            'cursor' => $cursor,
        );
    }

    private function invalidCredentials(): InvalidRequest
    {
        return new InvalidRequest(
            'conversation_contract_invalid',
            ('بيانات المحادثة غير صالحة. أعد فتح المساعد.')
        );
    }
}
