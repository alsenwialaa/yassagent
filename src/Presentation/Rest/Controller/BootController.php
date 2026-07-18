<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest\Controller;

use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use YassinStore\AiAssistant\Application\Port\RuntimeReadinessPort;
use YassinStore\AiAssistant\Application\Port\CartMutationCapabilityPort;
use YassinStore\AiAssistant\Application\Port\TurnLeasePort;
use YassinStore\AiAssistant\Application\Port\MaintenanceGatePort;
use YassinStore\AiAssistant\Domain\Exception\InvalidRequest;
use YassinStore\AiAssistant\Infrastructure\Database\ConversationRepository;
use YassinStore\AiAssistant\Infrastructure\Database\TurnRepository;
use YassinStore\AiAssistant\Infrastructure\Security\IngressRateLimiter;
use YassinStore\AiAssistant\Infrastructure\Security\RateLimiter;
use YassinStore\AiAssistant\Infrastructure\Security\RequestGuard;
use YassinStore\AiAssistant\Infrastructure\Security\SessionTokenService;
use YassinStore\AiAssistant\Infrastructure\Runtime\ImageRuntimeCapability;
use YassinStore\AiAssistant\Application\Ai\ImageAttachmentPolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartQueryService;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Presentation\Rest\ApiResponder;
use YassinStore\AiAssistant\Presentation\Rest\BootResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\ClientTranscriptProjector;
use YassinStore\AiAssistant\Presentation\Rest\RequestDecoder;
use YassinStore\AiAssistant\Presentation\Rest\SchemaRuntimeGate;

final class BootController
{
    /** @var Settings */ private $settings;
    /** @var RuntimeReadinessPort */ private $readiness;
    /** @var RequestDecoder */ private $decoder;
    /** @var SessionTokenService */ private $sessions;
    /** @var IngressRateLimiter */ private $ingress;
    /** @var RateLimiter */ private $rateLimiter;
    /** @var SchemaRuntimeGate */ private $schema;
    /** @var RequestGuard */ private $guard;
    /** @var ConversationRepository */ private $conversations;
    /** @var TurnRepository */ private $turns;
    /** @var TurnLeasePort */ private $leases;
    /** @var ClientTranscriptProjector */ private $transcript;
    /** @var CartQueryService */ private $cart;
    /** @var ApiResponder */ private $responses;
    /** @var BootResponseProjector */ private $responseProjector;
    /** @var Logger */ private $logger;
    /** @var ImageRuntimeCapability */ private $imageRuntime;
    /** @var CartMutationCapabilityPort */ private $cartMutations;
    /** @var MaintenanceGatePort */ private $maintenanceGate;

    public function __construct(
        Settings $settings,
        RuntimeReadinessPort $readiness,
        RequestDecoder $decoder,
        SessionTokenService $sessions,
        IngressRateLimiter $ingress,
        RateLimiter $rateLimiter,
        SchemaRuntimeGate $schema,
        RequestGuard $guard,
        ConversationRepository $conversations,
        TurnRepository $turns,
        TurnLeasePort $leases,
        ClientTranscriptProjector $transcript,
        CartQueryService $cart,
        ApiResponder $responses,
        BootResponseProjector $responseProjector,
        Logger $logger,
        ImageRuntimeCapability $imageRuntime,
        CartMutationCapabilityPort $cartMutations,
        MaintenanceGatePort $maintenanceGate
    ) {
        $this->settings = $settings;
        $this->readiness = $readiness;
        $this->decoder = $decoder;
        $this->sessions = $sessions;
        $this->ingress = $ingress;
        $this->rateLimiter = $rateLimiter;
        $this->schema = $schema;
        $this->guard = $guard;
        $this->conversations = $conversations;
        $this->turns = $turns;
        $this->leases = $leases;
        $this->transcript = $transcript;
        $this->cart = $cart;
        $this->responses = $responses;
        $this->responseProjector = $responseProjector;
        $this->logger = $logger;
        $this->imageRuntime = $imageRuntime;
        $this->cartMutations = $cartMutations;
        $this->maintenanceGate = $maintenanceGate;
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $clientIp = $this->guard->clientIp();
        $phase = 'decode';
        try {
            $ingress = $this->ingress->consumeBoot($clientIp);
        } catch (Throwable $exception) {
            $this->logger->error('Boot ingress admission failed.', array(
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
                'boot_ingress_rate_limited',
                ('تم تجاوز حد طلبات بدء المساعد مؤقتاً. حاول لاحقاً.'),
                429,
                (int) $ingress['retry_after']
            );
        }

        try {
            // The closed boot envelope and the high-ceiling ingress limiter are
            // deliberately evaluated before the physical assistant-schema
            // canary. The existing durable boot limiter remains authoritative
            // after exact schema verification.
            $input = $this->decoder->boot($request);

            $phase = 'schema';
            $blocked = $this->schema->blockedResponse();
            if ($blocked !== null) {
                return $blocked;
            }

            $phase = 'rate_limit';
            $rate = $this->rateLimiter->consumeBoot(
                $input['client_instance_id'],
                $clientIp
            );
            if (!$rate['allowed']) {
                return $this->responses->error(
                    'boot_rate_limited',
                    ('تم تجاوز حد بدء الجلسات مؤقتاً. حاول لاحقاً.'),
                    429,
                    (int) $rate['retry_after']
                );
            }

            $phase = 'session';
            $session = $this->sessions->issue(
                (string) $input['browser_continuity_secret'],
                (string) $input['previous_browser_continuity_secret']
            );
            $sessionHash = (string) $session['session_hash'];
            $phase = 'conversation';
            $conversation = $this->maintenanceGate->run(function () use (
                $sessionHash,
                $input
            ): ?array {
                if ($input['conversation_id'] !== '') {
                    $conversation = $this->conversations->resume(
                        (string) $input['conversation_id'],
                        (string) $input['conversation_token'],
                        $sessionHash
                    );
                    if ($conversation === null) {
                        throw new InvalidRequest(
                            'conversation_invalid',
                            ('انتهت صلاحية المحادثة أو تغيرت جلستها.'),
                            'Boot continuity does not match the supplied browser conversation.',
                            401
                        );
                    }
                    return $conversation;
                }

                $bootLease = $this->leases->acquire('boot|' . $sessionHash, 60);
                if ($bootLease === null) {
                    return null;
                }
                try {
                    return $this->conversations->createOrResume(
                        $sessionHash,
                        $bootLease
                    );
                } finally {
                    try {
                        $this->leases->release($bootLease);
                    } catch (Throwable $releaseFailure) {
                        // The short lease expires independently. Do not discard a
                        // canonical session/conversation result solely because the
                        // advisory release write failed after the mapping committed.
                        $this->logger->error('Boot lease release failed.', array(
                            'type' => get_class($releaseFailure),
                            'message' => $releaseFailure->getMessage(),
                        ));
                    }
                }
            });
            if ($conversation === null) {
                return $this->responses->error(
                    'boot_in_progress',
                    ('يجري بدء المساعد في نافذة أخرى. ستتم إعادة المحاولة تلقائياً.'),
                    409,
                    1
                );
            }
            $phase = 'projection';
            $pendingTurn = null;
            $pendingTurnId = (string) $input['pending_turn_id'];
            if ($pendingTurnId !== '') {
                $turn = $this->turns->find((int) $conversation['id'], $pendingTurnId);
                $pendingStatus = $turn === null
                    ? 'absent'
                    : ($turn->isTerminal() ? 'terminal' : 'pending');
                $pendingTurn = array('id' => $pendingTurnId, 'status' => $pendingStatus);
            }

            $messages = $pendingTurn !== null && $pendingTurn['status'] === 'terminal'
                ? $this->transcript->messagesIncludingTurn((int) $conversation['id'], $pendingTurnId)
                : $this->transcript->messages((int) $conversation['id']);

            $cart = null;
            $cartAvailable = true;
            $cartNotice = '';
            try {
                $cart = $this->cart->displaySummary();
            } catch (Throwable $exception) {
                // Cart projection is display-only during boot. A transient cart
                // read failure must not prevent the customer from starting a
                // conversation or asking non-commerce questions.
                $cartAvailable = false;
                $cartNotice = ('تم بدء المساعد، لكن تعذر تحميل ملخص السلة حالياً. افتح صفحة السلة للتحقق منها.');
                $this->logger->error('Boot cart snapshot failed.', array(
                    'conversation' => (string) $conversation['public_id'],
                    'type' => get_class($exception),
                    'message' => $exception->getMessage(),
                ));
            }

            $phase = 'capabilities';
            $capabilities = $this->capabilities($cartAvailable);
            $phase = 'widget_config';
            $widget = $this->widgetConfig();
            $phase = 'response';
            return $this->responses->boot($this->responseProjector->project(
                (string) $session['token'],
                (string) $conversation['public_id'],
                (string) $conversation['access_token'],
                $messages,
                $widget,
                $cart,
                $cartAvailable,
                $cartNotice,
                $capabilities,
                $pendingTurn,
                time()
            ));
        } catch (InvalidRequest $exception) {
            return $this->responses->error(
                $exception->reasonCode(),
                $exception->safeMessage(),
                $exception->httpStatus()
            );
        } catch (Throwable $exception) {
            $safePhase = $phase;
            $this->logger->error('Boot request failed at phase ' . $safePhase . '.', array(
                'phase' => $safePhase,
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
            ));
            return $this->responses->error(
                'boot_failed_' . $safePhase,
                ('تعذر بدء جلسة المساعد. أعد تحميل الصفحة. مرجع الدعم: ' . $safePhase . '.'),
                503
            );
        }
    }


    /** @return array<string,mixed> */
    private function capabilities(bool $cartAvailable): array
    {
        $images = (bool) $this->settings->get('allow_images', 1)
            && $this->imageRuntime->canAdvertise();
        if (!$cartAvailable) {
            // Mutation requires a coherent live cart projection. Never
            // advertise write capability after that same boot request failed
            // to establish even the read-side cart state.
            $cartMutations = array(
                'available' => false,
                'code' => 'runtime_unavailable',
                'notice' => ('تعذر تحميل حالة السلة الحالية، لذلك لا يمكن تعديلها بأمان داخل الدردشة.'),
            );
        } else {
            try {
                $cartMutations = $this->cartMutations->inspect()->forClient();
            } catch (Throwable $exception) {
                // Cart mutation is an optional storefront capability. Failure to
                // inspect it must never suppress ordinary conversation boot.
                $cartMutations = array(
                    'available' => false,
                    'code' => 'runtime_unavailable',
                    'notice' => ('يمكن للمساعد متابعة الدردشة، لكن تعذر التحقق من تعديل السلة حالياً.'),
                );
                $this->logger->error('Boot cart mutation capability inspection failed.', array(
                    'type' => get_class($exception),
                    'message' => $exception->getMessage(),
                ));
            }
        }
        return array(
            'chat_ready' => $this->readiness->isReady(),
            'images' => $images,
            'max_images' => $images ? ImageAttachmentPolicy::MAX_ITEMS : 0,
            'max_image_bytes' => $images ? ImageAttachmentPolicy::MAX_DECODED_BYTES : 0,
            'cart_mutations' => $cartMutations,
        );
    }

    /** @return array<string,mixed> */
    private function widgetConfig(): array
    {
        return array(
            'title' => Settings::widgetText(
                'widget_title',
                $this->settings->get('widget_title', ''),
                (string) Settings::defaults()['widget_title']
            ),
            'subtitle' => Settings::widgetText(
                'widget_subtitle',
                $this->settings->get('widget_subtitle', ''),
                (string) Settings::defaults()['widget_subtitle']
            ),
            'button_text' => Settings::widgetText(
                'widget_button_text',
                $this->settings->get('widget_button_text', ''),
                (string) Settings::defaults()['widget_button_text']
            ),
            'empty_state_hint' => Settings::widgetText(
                'empty_state_hint',
                $this->settings->get('empty_state_hint', ''),
                (string) Settings::defaults()['empty_state_hint']
            ),
        );
    }
}
