<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Composition;

use YassinStore\AiAssistant\Application\Chat\ConversationContextWindow;
use YassinStore\AiAssistant\Application\Contract\PublicApiContract;
use YassinStore\AiAssistant\Application\Contract\PublicResponseSchemaValidator;
use YassinStore\AiAssistant\Application\Turn\AbandonedTurnReconciler;
use YassinStore\AiAssistant\Application\Turn\CommerceTurnRecovery;
use YassinStore\AiAssistant\Application\Turn\CanonicalUserMessageFactory;
use YassinStore\AiAssistant\Application\Turn\TurnAdmission;
use YassinStore\AiAssistant\Application\Turn\TurnCommitter;
use YassinStore\AiAssistant\Application\Turn\TurnProcessor;
use YassinStore\AiAssistant\Application\Turn\TurnRequestHasher;
use YassinStore\AiAssistant\Application\Turn\TurnWorkflow;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiRuntimeReadiness;
use YassinStore\AiAssistant\Infrastructure\Database\ConversationPrivacyService;
use YassinStore\AiAssistant\Infrastructure\Runtime\ImageRuntimeCapability;
use YassinStore\AiAssistant\Infrastructure\Security\IngressRateLimiter;
use YassinStore\AiAssistant\Infrastructure\Security\ClientIpResolver;
use YassinStore\AiAssistant\Infrastructure\Security\OriginPolicy;
use YassinStore\AiAssistant\Infrastructure\Security\RateLimiter;
use YassinStore\AiAssistant\Infrastructure\Security\RequestGuard;
use YassinStore\AiAssistant\Infrastructure\Security\SessionTokenService;
use YassinStore\AiAssistant\Infrastructure\Security\ConversationExportCursor;
use YassinStore\AiAssistant\Infrastructure\Security\RecoveryKey;
use YassinStore\AiAssistant\Infrastructure\Security\TrustedProxyPolicy;
use YassinStore\AiAssistant\Infrastructure\Security\SecretFingerprint;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooSessionInternalsAdapter;
use YassinStore\AiAssistant\Infrastructure\WordPress\Capabilities;
use YassinStore\AiAssistant\Infrastructure\WordPress\ContentRepository;
use YassinStore\AiAssistant\Infrastructure\WordPress\ArabicText;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Infrastructure\System\SystemClock;
use YassinStore\AiAssistant\Lifecycle\Cleanup;
use YassinStore\AiAssistant\Presentation\Admin\AdminPages;
use YassinStore\AiAssistant\Presentation\Privacy\Privacy;
use YassinStore\AiAssistant\Presentation\Rest\ApiResponder;
use YassinStore\AiAssistant\Presentation\Rest\AdminTestResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\ConversationDeleteResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\ConversationExportResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\HealthResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\BootResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\ClientTranscriptProjector;
use YassinStore\AiAssistant\Presentation\Rest\ErrorResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\Controller\AdminController;
use YassinStore\AiAssistant\Presentation\Rest\Controller\BootController;
use YassinStore\AiAssistant\Presentation\Rest\Controller\ChatController;
use YassinStore\AiAssistant\Presentation\Rest\Controller\ConversationPrivacyController;
use YassinStore\AiAssistant\Presentation\Rest\Controller\HealthController;
use YassinStore\AiAssistant\Presentation\Rest\RequestDecoder;
use YassinStore\AiAssistant\Presentation\Rest\ImageAttachmentDecoder;
use YassinStore\AiAssistant\Presentation\Rest\RestApi;
use YassinStore\AiAssistant\Presentation\Rest\SchemaRuntimeGate;
use YassinStore\AiAssistant\Presentation\Rest\TurnResponseProjector;
use YassinStore\AiAssistant\Presentation\Widget\Widget;
use YassinStore\AiAssistant\Support\Json;

/** Registers the plugin from focused construction stacks, not a service locator. */
final class PluginKernel
{
    /** @var Settings */ private $settings;
    /** @var Logger */ private $logger;
    /** @var WooSessionInternalsAdapter */ private $wooInternals;
    public function __construct(
        Settings $settings,
        Logger $logger,
        WooSessionInternalsAdapter $wooInternals
    ) {
        $this->settings = $settings;
        $this->logger = $logger;
        $this->wooInternals = $wooInternals;
    }

    public function register(): void
    {
        $contractPath = YSAI_PLUGIN_DIR . 'config/public-api-contract.json';
        $contractRaw = is_file($contractPath) ? file_get_contents($contractPath) : false;
        if (!is_string($contractRaw) || $contractRaw === '') {
            throw new \RuntimeException('The public API contract is missing.');
        }
        $publicContract = new PublicApiContract(
            Json::decodeRequiredObject($contractRaw, 'Public API contract')
        );

        $persistence = new PersistenceStack($this->settings);
        $clock = new SystemClock();
        $text = new ArabicText();
        $commerce = new CommerceStack(
            $persistence,
            $this->logger,
            $text,
            $this->wooInternals
        );
        $content = new ContentRepository($this->settings);

        $sessionTokens = new SessionTokenService($persistence->browserContinuity());
        $recoveryKey = new RecoveryKey();
        $rateLimiter = new RateLimiter($this->settings, $persistence->transactions());
        $ingressLimiter = new IngressRateLimiter();
        $capabilities = new Capabilities();
        $imageRuntime = new ImageRuntimeCapability();
        $clientIps = new ClientIpResolver(new TrustedProxyPolicy($this->settings));
        $requestGuard = new RequestGuard(
            $capabilities,
            new OriginPolicy(),
            $publicContract,
            $imageRuntime,
            $clientIps
        );
        $readiness = new GeminiRuntimeReadiness($this->settings, $clock);
        $agent = new AgentStack(
            $this->settings,
            $this->logger,
            $commerce,
            $content,
            $text,
            $readiness
        );

        $committer = new TurnCommitter(
            $persistence->transactions(),
            $persistence->leases(),
            $persistence->turns(),
            $persistence->conversations(),
            $persistence->messages(),
            $clock
        );
        $admission = new TurnAdmission(
            $persistence->transactions(),
            $persistence->leases(),
            $persistence->conversations(),
            $persistence->turns(),
            $persistence->messages(),
            $rateLimiter,
            new TurnRequestHasher(new SecretFingerprint($recoveryKey->key())),
            $committer,
            new CanonicalUserMessageFactory($text),
            $persistence->maintenanceGate(),
            $text
        );
        $commerceRecovery = new CommerceTurnRecovery($commerce->mutations(), $agent->runner());
        $contextWindow = new ConversationContextWindow();
        $turnWorkflow = new TurnWorkflow(
            $admission,
            $persistence->turns(),
            $persistence->conversations(),
            $persistence->messages(),
            $commerceRecovery,
            $agent->runner(),
            $committer,
            $readiness,
            $this->logger,
            $text,
            $contextWindow,
            $commerce->catalog()
        );
        $turnProcessor = new TurnProcessor(
            $persistence->leases(),
            new AbandonedTurnReconciler(
                $persistence->turns(),
                $persistence->conversations(),
                $commerceRecovery,
                $committer,
                $text
            ),
            $turnWorkflow,
            $this->settings,
            $this->logger,
            $text
        );

        $responseValidator = new PublicResponseSchemaValidator($publicContract);
        $bootResponses = new BootResponseProjector($responseValidator);
        $turnResponses = new TurnResponseProjector($responseValidator);
        $healthResponses = new HealthResponseProjector($responseValidator);
        $privacyExportResponses = new ConversationExportResponseProjector($responseValidator);
        $privacyDeleteResponses = new ConversationDeleteResponseProjector($responseValidator);
        $adminResponses = new AdminTestResponseProjector($responseValidator);
        $responses = new ApiResponder(new ErrorResponseProjector($responseValidator));
        $schemaGate = new SchemaRuntimeGate($responses);
        $decoder = new RequestDecoder(
            $this->settings,
            $publicContract,
            new ImageAttachmentDecoder($publicContract, $imageRuntime)
        );
        $transcript = new ClientTranscriptProjector(
            $persistence->messages(),
            $contextWindow
        );
        $rest = new RestApi(
            $requestGuard,
            new HealthController($readiness, $responses, $ingressLimiter, $requestGuard, $healthResponses),
            new BootController(
                $this->settings,
                $readiness,
                $decoder,
                $sessionTokens,
                $ingressLimiter,
                $rateLimiter,
                $schemaGate,
                $requestGuard,
                $persistence->conversations(),
                $persistence->turns(),
                $persistence->leases(),
                $transcript,
                $commerce->bootCart(),
                $responses,
                $bootResponses,
                $this->logger,
                $imageRuntime,
                $commerce->mutationCapability(),
                $persistence->maintenanceGate()
            ),
            new ChatController(
                $sessionTokens,
                $decoder,
                $ingressLimiter,
                $schemaGate,
                $persistence->conversations(),
                $turnProcessor,
                $transcript,
                $commerce->protectedCart(),
                $commerce->mutationCapability(),
                $requestGuard,
                $responses,
                $turnResponses,
                $this->logger
            ),
            new ConversationPrivacyController(
                $sessionTokens,
                $ingressLimiter,
                $schemaGate,
                $persistence->conversations(),
                new ConversationPrivacyService(
                    $persistence->transactions(),
                    $persistence->conversations(),
                    new ConversationExportCursor(),
                    $persistence->activeWork()
                ),
                $requestGuard,
                $responses,
                $privacyExportResponses,
                $privacyDeleteResponses,
                $this->logger
            ),
            new AdminController($agent->probe(), $responses, $adminResponses),
            $publicContract,
            $schemaGate,
            $responses
        );
        add_action('rest_api_init', array($rest, 'register'));

        (new Cleanup(
            $persistence->maintenance(),
            $persistence->browserContinuity(),
            $persistence->leases(),
            $ingressLimiter,
            $rateLimiter,
            $this->logger
        ))->register();
        (new Privacy($this->settings, $capabilities))->register();
        (new AdminPages(
            $this->settings,
            $readiness,
            $persistence->maintenance(),
            $capabilities,
            $clientIps
        ))->register();

        (new Widget($this->settings))->register();
    }
}
