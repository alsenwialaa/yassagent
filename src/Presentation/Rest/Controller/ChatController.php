<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest\Controller;

use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use YassinStore\AiAssistant\Application\Turn\TurnProcessor;
use YassinStore\AiAssistant\Application\Turn\TurnResult;
use YassinStore\AiAssistant\Application\Port\CartMutationCapabilityPort;
use YassinStore\AiAssistant\Domain\Exception\InvalidRequest;
use YassinStore\AiAssistant\Domain\Exception\TurnUnavailableException;
use YassinStore\AiAssistant\Infrastructure\Database\ConversationRepository;
use YassinStore\AiAssistant\Infrastructure\Security\IngressRateLimiter;
use YassinStore\AiAssistant\Infrastructure\Security\RequestGuard;
use YassinStore\AiAssistant\Infrastructure\Security\SessionTokenService;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartQueryService;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;
use YassinStore\AiAssistant\Presentation\Rest\ApiResponder;
use YassinStore\AiAssistant\Presentation\Rest\ClientTranscriptProjector;
use YassinStore\AiAssistant\Presentation\Rest\RequestDecoder;
use YassinStore\AiAssistant\Presentation\Rest\SchemaRuntimeGate;
use YassinStore\AiAssistant\Presentation\Rest\TurnResponseProjector;

final class ChatController
{
    /** @var SessionTokenService */ private $sessions;
    /** @var RequestDecoder */ private $decoder;
    /** @var IngressRateLimiter */ private $ingress;
    /** @var SchemaRuntimeGate */ private $schema;
    /** @var ConversationRepository */ private $conversations;
    /** @var TurnProcessor */ private $turns;
    /** @var ClientTranscriptProjector */ private $transcript;
    /** @var CartQueryService */ private $cart;
    /** @var CartMutationCapabilityPort */ private $cartMutations;
    /** @var RequestGuard */ private $guard;
    /** @var ApiResponder */ private $responses;
    /** @var TurnResponseProjector */ private $responseProjector;
    /** @var Logger */ private $logger;

    public function __construct(
        SessionTokenService $sessions,
        RequestDecoder $decoder,
        IngressRateLimiter $ingress,
        SchemaRuntimeGate $schema,
        ConversationRepository $conversations,
        TurnProcessor $turns,
        ClientTranscriptProjector $transcript,
        CartQueryService $cart,
        CartMutationCapabilityPort $cartMutations,
        RequestGuard $guard,
        ApiResponder $responses,
        TurnResponseProjector $responseProjector,
        Logger $logger
    ) {
        $this->sessions = $sessions;
        $this->decoder = $decoder;
        $this->ingress = $ingress;
        $this->schema = $schema;
        $this->conversations = $conversations;
        $this->turns = $turns;
        $this->transcript = $transcript;
        $this->cart = $cart;
        $this->cartMutations = $cartMutations;
        $this->guard = $guard;
        $this->responses = $responses;
        $this->responseProjector = $responseProjector;
        $this->logger = $logger;
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $committedResult = null;
        $committedConversation = null;

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

            $clientIp = $this->guard->clientIp();
            try {
                $ingress = $this->ingress->consumeChat($sessionHash, $clientIp);
            } catch (Throwable $exception) {
                $this->logger->error('Chat ingress admission failed.', array(
                    'type' => get_class($exception),
                    'message' => $exception->getMessage(),
                ));
                return $this->responses->error(
                    'request_admission_unavailable',
                    ('تعذر التحقق من سماح الطلب حالياً. أعد المحاولة بعد قليل.'),
                    503
                );
            }
            if (!$ingress['allowed']) {
                return $this->responses->error(
                    'chat_ingress_rate_limited',
                    ('تم تجاوز حد طلبات المحادثة مؤقتاً. حاول لاحقاً.'),
                    429,
                    (int) $ingress['retry_after']
                );
            }

            // Signed token integrity, the transport budget, and the closed
            // scalar envelope are established before
            // the physical schema canary. Active browser authority is proved
            // immediately after the canary and before any assistant-table read.
            // Attachment bytes are materialized only after that proof.
            $envelope = $this->decoder->chatEnvelope($request);

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

            $input = $this->decoder->chatFromEnvelope($envelope);
            $conversation = $this->conversations->resume(
                $input->conversationPublicId(),
                $input->conversationToken(),
                $sessionHash
            );
            if ($conversation === null) {
                return $this->responses->error(
                    'conversation_invalid',
                    ('انتهت جلسة المحادثة. أعد فتح المساعد.'),
                    401
                );
            }

            $result = $this->turns->process(
                $conversation,
                $input,
                $sessionHash,
                $clientIp
            );
            if ($result->isCommitted()) {
                // From this point onward the durable terminal result is the
                // execution authority. Presentation reads may degrade, but they
                // must never convert the committed result into an HTTP 500.
                $committedResult = $result;
                $committedConversation = $conversation;
            }

            return $this->turnResponse(
                $result->message(),
                $result->isCommitted(),
                $conversation
            );
        } catch (InvalidRequest $exception) {
            $this->logger->debug('Invalid public chat contract.', array(
                'code' => $exception->reasonCode(),
            ));
            return $this->responses->error(
                $exception->reasonCode(),
                $exception->safeMessage(),
                $exception->httpStatus()
            );
        } catch (TurnUnavailableException $exception) {
            return $this->responses->error(
                $exception->reasonCode(),
                $exception->safeMessage(),
                $exception->httpStatus(),
                $exception->retryAfter()
            );
        } catch (Throwable $exception) {
            if ($committedResult instanceof TurnResult && is_array($committedConversation)) {
                $this->logger->error('Committed turn response assembly failed after durable commit.', array(
                    'conversation' => (string) ($committedConversation['public_id'] ?? ''),
                    'turn' => (string) ($committedResult->message()['turn_id'] ?? ''),
                    'type' => get_class($exception),
                    'message' => $exception->getMessage(),
                ));
                return $this->committedFallbackResponse(
                    $committedResult->message(),
                    $committedConversation
                );
            }

            $this->logger->error('REST chat failed.', array(
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
            ));
            return $this->responses->error(
                'chat_internal_error',
                ('حدث خطأ داخلي قبل تثبيت نتيجة الطلب. لم يتم تأكيد أي إجراء غير موثّق. أعد إرسال الطلب نفسه.'),
                500
            );
        }
    }

    /**
     * @param array<string,mixed> $message
     * @param array<string,mixed> $conversation
     */
    private function turnResponse(
        array $message,
        bool $committed,
        array $conversation
    ): WP_REST_Response {
        $messagesAvailable = true;
        $messagesNotice = '';

        try {
            if ($committed) {
                $projection = $this->transcript->committedTurn(
                    (int) $conversation['id'],
                    $message
                );
                $message = $projection['message'];
                $messages = $projection['messages'];
            } else {
                $messages = $this->transcript->messages((int) $conversation['id']);
            }
        } catch (Throwable $exception) {
            if (!$committed) {
                throw $exception;
            }

            // The terminal workflow result is already durable. Projection is a
            // display-only read and must fail independently.
            $messages = array();
            $messagesAvailable = false;
            $messagesNotice = ('تم تثبيت رد المساعد، لكن تعذر تحديث سجل المحادثة حالياً. سيُستعاد السجل عند إعادة فتح المساعد.');
            $this->logger->error('Committed turn transcript projection failed.', array(
                'conversation' => (string) ($conversation['public_id'] ?? ''),
                'turn' => (string) ($message['turn_id'] ?? ''),
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
            ));
        }

        $cart = null;
        $cartAvailable = true;
        $cartNotice = '';
        try {
            $cart = $this->cart->displaySummary();
        } catch (Throwable $exception) {
            $cartAvailable = false;
            $cartNotice = $committed
                ? ('تم حفظ رد المساعد، لكن تعذر تحديث ملخص السلة. افتح صفحة السلة للتحقق منها.')
                : ('لم يتم تثبيت هذا الطلب، وتعذر أيضاً تحديث ملخص السلة. افتح صفحة السلة للتحقق منها.');
            $this->logger->error('Turn response cart snapshot failed.', array(
                'conversation' => (string) $conversation['public_id'],
                'message' => $exception->getMessage(),
            ));
        }

        $cartMutations = $this->cartMutationCapability($cartAvailable);

        return $this->responses->turn($this->responseProjector->project(
            $message,
            $committed,
            (string) $conversation['public_id'],
            (string) $conversation['access_token'],
            $messages,
            $messagesAvailable,
            $messagesNotice,
            $cart,
            $cartAvailable,
            $cartNotice,
            $cartMutations
        ));
    }

    /**
     * Last-resort response used only after a durable terminal result exists and
     * an unexpected presentation-layer exception escapes normal degradation.
     * It performs no database, WooCommerce, or transcript reads.
     *
     * @param array<string,mixed> $message
     * @param array<string,mixed> $conversation
     */
    private function committedFallbackResponse(array $message, array $conversation): WP_REST_Response
    {
        try {
            return $this->responses->turn($this->responseProjector->project(
                $message,
                true,
                (string) ($conversation['public_id'] ?? ''),
                (string) ($conversation['access_token'] ?? ''),
                array(),
                false,
                ('تم تثبيت رد المساعد، لكن تعذر تحديث سجل المحادثة حالياً. سيُستعاد السجل عند إعادة فتح المساعد.'),
                null,
                false,
                ('تم حفظ رد المساعد، لكن تعذر تحديث ملخص السلة. افتح صفحة السلة للتحقق منها.'),
                array(
                    'available' => false,
                    'code' => 'runtime_unavailable',
                    'notice' => ('تعذر التحقق من قدرة تعديل السلة في هذه الجلسة.'),
                )
            ));
        } catch (Throwable $exception) {
            // The durable terminal result remains authoritative, but no
            // contract-invalid payload may escape merely to acknowledge it.
            // A retry of the same client turn is still mutation-safe because
            // server idempotency owns replay.
            $this->logger->error('Committed turn fallback violates the public response contract.', array(
                'conversation' => (string) ($conversation['public_id'] ?? ''),
                'turn' => (string) ($message['turn_id'] ?? ''),
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
            ));
            return $this->responses->error(
                'committed_response_unavailable',
                ('تم تثبيت نتيجة الطلب، لكن تعذر عرضها بأمان. أعد إرسال الطلب نفسه لاسترجاع النتيجة دون تكرار الإجراء.'),
                503
            );
        }
    }

    /** @return array{available:bool,code:string,notice:string} */
    private function cartMutationCapability(bool $cartAvailable): array
    {
        if (!$cartAvailable) {
            return array(
                'available' => false,
                'code' => 'runtime_unavailable',
                'notice' => ('تعذر تحديث السلة أو التحقق من قدرتها على التعديل في هذه الجلسة.'),
            );
        }
        try {
            return $this->cartMutations->inspect()->forClient();
        } catch (Throwable $exception) {
            $this->logger->error('Turn response cart capability inspection failed.', array(
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
            ));
            return array(
                'available' => false,
                'code' => 'runtime_unavailable',
                'notice' => ('تعذر التحقق من قدرة تعديل السلة في هذه الجلسة.'),
            );
        }
    }
}
