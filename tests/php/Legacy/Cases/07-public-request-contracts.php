<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Application\Agent\AgentContext;
use YassinStore\AiAssistant\Application\Agent\CurrentTurnModelStep;
use YassinStore\AiAssistant\Application\Agent\AgentFailureMessages;
use YassinStore\AiAssistant\Application\Agent\AgentLimits;
use YassinStore\AiAssistant\Application\Agent\AgentPromptFeedback;
use YassinStore\AiAssistant\Application\Agent\AgentPromptBuilder;
use YassinStore\AiAssistant\Application\Agent\AgentTurnEnvelope;
use YassinStore\AiAssistant\Application\Agent\ArabicCustomerText;
use YassinStore\AiAssistant\Application\Agent\AgentModelLoop;
use YassinStore\AiAssistant\Application\Agent\ModelAuthoredQuestionFactory;
use YassinStore\AiAssistant\Application\Agent\TerminalOutcomeAssembler;
use YassinStore\AiAssistant\Application\Agent\VerifiedFollowUpCall;
use YassinStore\AiAssistant\Application\Agent\ResponseProjection;
use YassinStore\AiAssistant\Application\Agent\TurnEffects;
use YassinStore\AiAssistant\Application\Commerce\CartPlanFactory;
use YassinStore\AiAssistant\Application\Commerce\CartIntentVerificationFactory;
use YassinStore\AiAssistant\Application\Commerce\CartIntentVerificationRequest;
use YassinStore\AiAssistant\Application\Commerce\CartIntentVerdict;
use YassinStore\AiAssistant\Application\Commerce\CommerceExecutionContext;
use YassinStore\AiAssistant\Application\Commerce\CurrentTurnCartIntentEvidence;
use YassinStore\AiAssistant\Application\Commerce\CurrentCustomerMessage;
use YassinStore\AiAssistant\Application\Commerce\PendingCartIntentFactory;
use YassinStore\AiAssistant\Application\Commerce\VariableProductAuthority;
use YassinStore\AiAssistant\Application\Commerce\VariationResolver;
use YassinStore\AiAssistant\Application\Ai\FunctionCall;
use YassinStore\AiAssistant\Application\Ai\FunctionFeedback;
use YassinStore\AiAssistant\Application\Ai\ModelSessionInterface;
use YassinStore\AiAssistant\Application\Ai\ModelGatewayInterface;
use YassinStore\AiAssistant\Application\Ai\ModelStep;
use YassinStore\AiAssistant\Application\Ai\ModelProtocolException;
use YassinStore\AiAssistant\Application\Ai\ModelRequest;
use YassinStore\AiAssistant\Application\Ai\ImageAttachment;
use YassinStore\AiAssistant\Application\Ai\ImageAttachmentPolicy;
use YassinStore\AiAssistant\Application\Authority\AuthorityRegistry;
use YassinStore\AiAssistant\Application\Contract\GeneratedPublicApiContract;
use YassinStore\AiAssistant\Application\Contract\PublicApiContract;
use YassinStore\AiAssistant\Application\Contract\PublicResponseContractViolation;
use YassinStore\AiAssistant\Application\Contract\PublicResponseSchemaValidator;
use YassinStore\AiAssistant\Application\Execution\ExecutionDeadline;
use YassinStore\AiAssistant\Application\Execution\ExecutionBoundary;
use YassinStore\AiAssistant\Application\Execution\TurnExecutionPolicy;
use YassinStore\AiAssistant\Application\Execution\TurnExecutionSupervisor;
use YassinStore\AiAssistant\Domain\Exception\ExecutionBudgetException;
use YassinStore\AiAssistant\Application\Port\BrowserContinuityAuthorityPort;
use YassinStore\AiAssistant\Application\Port\CartIntentVerifierPort;
use YassinStore\AiAssistant\Application\Port\CartMutationPort;
use YassinStore\AiAssistant\Application\Port\TransactionPort;
use YassinStore\AiAssistant\Application\Port\TurnStorePort;
use YassinStore\AiAssistant\Application\Port\ProductCatalogPort;
use YassinStore\AiAssistant\Application\Port\CartQueryPort;
use YassinStore\AiAssistant\Application\Port\CartMutationCapabilityPort;
use YassinStore\AiAssistant\Application\Port\ClockPort;
use YassinStore\AiAssistant\Application\Readiness\RuntimeReadinessFailurePolicy;
use YassinStore\AiAssistant\Application\Port\TextLocalizerPort;
use YassinStore\AiAssistant\Application\Port\TurnLeasePort;
use YassinStore\AiAssistant\Application\Port\MaintenanceGatePort;
use YassinStore\AiAssistant\Application\Port\LoggerPort;
use YassinStore\AiAssistant\Application\Port\ProviderWaitIsolationPort;
use YassinStore\AiAssistant\Application\Tool\ArgumentValidator;
use YassinStore\AiAssistant\Application\Tool\ContractSchemaValidator;
use YassinStore\AiAssistant\Application\Tool\ToolCatalog;
use YassinStore\AiAssistant\Application\Tool\ToolContract;
use YassinStore\AiAssistant\Application\Tool\ToolExecutionResult;
use YassinStore\AiAssistant\Application\Tool\ToolHandlerInterface;
use YassinStore\AiAssistant\Application\Tool\ToolPromptDescriptions;
use YassinStore\AiAssistant\Application\Tool\ToolSchemas;
use YassinStore\AiAssistant\Application\Tool\ProductComparisonBuilder;
use YassinStore\AiAssistant\Application\Tool\ProductRecommendationRanker;
use YassinStore\AiAssistant\Application\Turn\CanonicalUserMessage;
use YassinStore\AiAssistant\Application\Turn\CanonicalUserMessageFactory;
use YassinStore\AiAssistant\Application\Turn\UserMessagePresentation;
use YassinStore\AiAssistant\Application\Turn\TurnAdmission;
use YassinStore\AiAssistant\Application\Turn\TurnCommitter;
use YassinStore\AiAssistant\Application\Turn\TurnRequest;
use YassinStore\AiAssistant\Application\Turn\TurnRequestHasher;
use YassinStore\AiAssistant\Application\Turn\TurnResult;
use YassinStore\AiAssistant\Application\Turn\TurnWorkflow;
use YassinStore\AiAssistant\Application\Chat\ConversationContextWindow;
use YassinStore\AiAssistant\Application\Tool\Handlers\Terminal\RespondAnswerHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Cart\CartApplyHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Terminal\RespondFollowUpHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Terminal\RespondSafeFailureHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Shopping\ShoppingMemoryUpdateHandler;
use YassinStore\AiAssistant\Application\Tool\Service\CartToolService;
use YassinStore\AiAssistant\Domain\Chat\AssistantResponse;
use YassinStore\AiAssistant\Domain\Chat\ConversationState;
use YassinStore\AiAssistant\Domain\Chat\Outcome;
use YassinStore\AiAssistant\Domain\Chat\ModelAuthoredQuestion;
use YassinStore\AiAssistant\Domain\Chat\StoredModelQuestionEvidence;
use YassinStore\AiAssistant\Domain\Chat\TurnRecord;
use YassinStore\AiAssistant\Domain\Chat\TurnStatus;
use YassinStore\AiAssistant\Domain\Commerce\ActionReceipt;
use YassinStore\AiAssistant\Domain\Commerce\AppliedCartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartCommand;
use YassinStore\AiAssistant\Domain\Commerce\CartLine;
use YassinStore\AiAssistant\Domain\Commerce\CartMutationCapability;
use YassinStore\AiAssistant\Domain\Commerce\CartOperationStep;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartPrimitive;
use YassinStore\AiAssistant\Domain\Commerce\CartPurchaseIdentity;
use YassinStore\AiAssistant\Domain\Commerce\CartSnapshot;
use YassinStore\AiAssistant\Domain\Commerce\CartStepAttempt;
use YassinStore\AiAssistant\Domain\Commerce\CartStepAttemptStatus;
use YassinStore\AiAssistant\Domain\Commerce\CartStepStatus;
use YassinStore\AiAssistant\Domain\Commerce\OperationRecord;
use YassinStore\AiAssistant\Domain\Commerce\OperationStatus;
use YassinStore\AiAssistant\Domain\Commerce\PendingCartIntent;
use YassinStore\AiAssistant\Domain\Commerce\VariableProductLimits;
use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Domain\Exception\InvalidRequest;
use YassinStore\AiAssistant\Domain\Exception\LeaseLostException;
use YassinStore\AiAssistant\Domain\Exception\SafeCommerceException;
use YassinStore\AiAssistant\Domain\Shopping\CatalogTextNormalizer;
use YassinStore\AiAssistant\Domain\Shopping\ShoppingMemory;
use YassinStore\AiAssistant\Domain\Shopping\ShoppingMemoryPatch;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Infrastructure\Database\ActiveWorkInspector;
use YassinStore\AiAssistant\Infrastructure\Database\ConversationCleanupBatch;
use YassinStore\AiAssistant\Infrastructure\Database\ConversationMaintenanceRepository;
use YassinStore\AiAssistant\Infrastructure\Database\ConversationPrivacyService;
use YassinStore\AiAssistant\Infrastructure\Database\ConversationPrivacyProjector;
use YassinStore\AiAssistant\Infrastructure\Database\ConversationRepository;
use YassinStore\AiAssistant\Infrastructure\Database\MessageRepository;
use YassinStore\AiAssistant\Infrastructure\Database\MaintenanceGate;
use YassinStore\AiAssistant\Infrastructure\Database\SchemaLifecycle;
use YassinStore\AiAssistant\Infrastructure\Database\SchemaRuntimeProof;
use YassinStore\AiAssistant\Infrastructure\Database\SchemaRegistry;
use YassinStore\AiAssistant\Infrastructure\Database\SchemaDefinition;
use YassinStore\AiAssistant\Infrastructure\Database\SchemaInspection;
use YassinStore\AiAssistant\Infrastructure\Database\AdvisoryLock;
use YassinStore\AiAssistant\Infrastructure\Database\SchemaInspector;
use YassinStore\AiAssistant\Infrastructure\Database\SchemaInstaller;
use YassinStore\AiAssistant\Infrastructure\Database\SchemaRecoveryPolicy;
use YassinStore\AiAssistant\Infrastructure\Database\SchemaValidator;
use YassinStore\AiAssistant\Infrastructure\Database\TransactionManager;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiRuntimeProbe;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiRuntimeReadiness;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiCartIntentVerifier;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiEndpoint;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiException;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiGateway;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiGenerationPolicy;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiResponseDecoder;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiSession;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiTimeoutTransportInterface;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiSchemaProjector;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiTransportInterface;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiTransport;
use YassinStore\AiAssistant\Infrastructure\Gemini\RuntimeProbeContract;
use YassinStore\AiAssistant\Infrastructure\Gemini\RuntimeProbeFailureMapper;
use YassinStore\AiAssistant\Infrastructure\Gemini\RuntimeProbeInProgress;
use YassinStore\AiAssistant\Infrastructure\Gemini\RuntimeReadinessPolicy;
use YassinStore\AiAssistant\Infrastructure\Gemini\RuntimeReadinessStateStore;
use YassinStore\AiAssistant\Infrastructure\Gemini\RuntimeProbeTiming;
use YassinStore\AiAssistant\Infrastructure\System\SystemClock;
use YassinStore\AiAssistant\Infrastructure\Concurrency\TurnLeaseManager;
use YassinStore\AiAssistant\Infrastructure\Runtime\ImageRuntimeCapability;
use YassinStore\AiAssistant\Infrastructure\Security\ConversationExportCursor;
use YassinStore\AiAssistant\Infrastructure\Security\ConversationExportCursorInvalid;
use YassinStore\AiAssistant\Infrastructure\Security\ClientIpResolver;
use YassinStore\AiAssistant\Infrastructure\Security\IpNetwork;
use YassinStore\AiAssistant\Infrastructure\Security\IngressRateLimiter;
use YassinStore\AiAssistant\Infrastructure\Security\RateLimiter;
use YassinStore\AiAssistant\Infrastructure\Security\RecoveryKey;
use YassinStore\AiAssistant\Infrastructure\Security\SecretFingerprint;
use YassinStore\AiAssistant\Infrastructure\Security\SessionTokenService;
use YassinStore\AiAssistant\Infrastructure\Security\TrustedProxyPolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\PlainMoneyFormatter;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooSession;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooCommerceCompatibility;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooSessionInternalsAdapter;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\BootCartSnapshot;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartDeltaVerifier;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartItemDataNormalizer;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartItemDisplayProjector;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartLineAuthorityPolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartMutationCapabilityLossPolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartStepPlanner;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartStepVerifier;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\WooCartGateway;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\WooSessionCartEnvelope;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\WooSessionOperationMarker;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\ReceiptPresenter;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\SafePersistentCartDecoder;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\SafeSerializedArrayDecoder;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\WooStorageTopologyPolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery\CatalogMatchScorer;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\ProductCatalog;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\VariableProductCatalogPolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery\CatalogCandidateMerger;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery\CatalogPricePolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\DisplayPriceProjection;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\AttributePresenter;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\CatalogVisibilityPolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\VariationAuthorityEpoch;
use YassinStore\AiAssistant\Infrastructure\WordPress\Capabilities;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Infrastructure\WordPress\LogContextSanitizer;
use YassinStore\AiAssistant\Presentation\Rest\ClientTranscriptProjector;
use YassinStore\AiAssistant\Presentation\Rest\Controller\ChatController;
use YassinStore\AiAssistant\Presentation\Rest\AdminTestResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\ApiResponder;
use YassinStore\AiAssistant\Presentation\Rest\BootResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\ConversationExportResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\ErrorResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\TurnResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\SchemaRuntimeGate;
use YassinStore\AiAssistant\Lifecycle\Cleanup;
use YassinStore\AiAssistant\Presentation\Rest\RequestDecoder;
use YassinStore\AiAssistant\Presentation\Rest\ImageAttachmentDecoder;
use YassinStore\AiAssistant\Support\AssetVersion;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\CanonicalBase64;
use YassinStore\AiAssistant\Support\Utf8;
use YassinStore\AiAssistant\Support\Uuid;

// Public request contracts.
test('REST decoder rejects list-shaped JSON even when PHP decoded it as an array', function (): void {
    $decoder=requestDecoderForTest();
    throws(InvalidRequest::class,function()use($decoder):void{$decoder->boot(new WP_REST_Request('[]',array()));},'json_object_required');
});
test('REST decoder uses only the real public WordPress request API and classifies malformed JSON', function (): void {
    $decoder=requestDecoderForTest();
    throws(
        InvalidRequest::class,
        function()use($decoder):void{$decoder->boot(new WP_REST_Request('{',null));},
        'json_invalid'
    );
    $root=YSAI_PROJECT_ROOT;
    notContains('get_json_error',(string)file_get_contents($root.'/src/Presentation/Rest/RequestDecoder.php'));
    notContains('get_json_error',(string)file_get_contents($root.'/src/Presentation/Rest/Controller/ConversationPrivacyController.php'));
    notContains('get_json_error',(string)file_get_contents($root.'/tests/bootstrap.php'));
});
test('REST decoder requires one canonical browser boot identity', function (): void {
    $decoder=requestDecoderForTest();
    throws(InvalidRequest::class,function()use($decoder):void{$decoder->boot(new WP_REST_Request('{}',array()));},'boot_client_identity_invalid');
    $client=Uuid::v4();
    $secret=str_repeat('A',43);
    same(
        array('client_instance_id'=>$client,'browser_continuity_secret'=>$secret,'previous_browser_continuity_secret'=>'','conversation_id'=>'','conversation_token'=>'','pending_turn_id'=>''),
        $decoder->boot(new WP_REST_Request(
            '{"client_instance_id":"'.$client.'","browser_continuity_secret":"'.$secret.'"}',
            array('client_instance_id'=>$client,'browser_continuity_secret'=>$secret)
        ))
    );
    throws(InvalidRequest::class,function()use($decoder,$client):void{
        $decoder->boot(new WP_REST_Request(
            '{"client_instance_id":"'.$client.'","browser_continuity_secret":"short"}',
            array('client_instance_id'=>$client,'browser_continuity_secret'=>'short')
        ));
    },'boot_continuity_credential_invalid');
    throws(InvalidRequest::class,function()use($decoder,$client):void{
        $nonCanonical=str_repeat('A',42).'_';
        $decoder->boot(new WP_REST_Request(
            '{"client_instance_id":"'.$client.'","browser_continuity_secret":"'.$nonCanonical.'"}',
            array('client_instance_id'=>$client,'browser_continuity_secret'=>$nonCanonical)
        ));
    },'boot_continuity_credential_invalid');
    throws(InvalidRequest::class,function()use($decoder,$client,$secret):void{
        $decoder->boot(new WP_REST_Request(
            '{}',
            array(
                'client_instance_id'=>$client,
                'browser_continuity_secret'=>$secret,
                'previous_browser_continuity_secret'=>$secret,
            )
        ));
    },'boot_continuity_rotation_invalid');
});

test('Trusted proxy CIDRs are canonical closed settings', function (): void {
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>Settings::defaults());
    $settings=new Settings();
    $sanitized=$settings->sanitize(array(
        'trusted_proxy_cidrs'=>"10.9.8.7/8\n2001:db8:abcd::1/32\ninvalid\n10.0.0.0/8",
    ));
    same("10.0.0.0/8\n2001:db8::/32",$sanitized['trusted_proxy_cidrs']);
    same('10.0.0.0/8',IpNetwork::canonicalCidr('10.9.8.7/8'));
    ok(IpNetwork::contains('2001:db8::/32','2001:db8:1::5'));
    ok(!IpNetwork::contains('2001:db8::/32','2001:db9::1'));
});

test('Client IP resolution ignores spoofed forwarding headers unless the peer is trusted', function (): void {
    $previousServer=$_SERVER;
    $previousOptions=$GLOBALS['ysai_test_options'];
    try {
        $_SERVER['REMOTE_ADDR']='203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_FOR']='198.51.100.77';
        $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array('trusted_proxy_cidrs'=>'')));
        $resolver=new ClientIpResolver(new TrustedProxyPolicy(new Settings()));
        same('203.0.113.9',$resolver->resolve());
        same('ignored_untrusted_peer',$resolver->diagnostics()['header_status']);

        $_SERVER['REMOTE_ADDR']='10.0.0.3';
        $_SERVER['HTTP_X_FORWARDED_FOR']='198.51.100.77, 10.0.0.2';
        $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array('trusted_proxy_cidrs'=>"10.0.0.0/8")));
        $resolver=new ClientIpResolver(new TrustedProxyPolicy(new Settings()));
        same('198.51.100.77',$resolver->resolve());
        same('accepted',$resolver->diagnostics()['header_status']);

        $_SERVER['HTTP_X_FORWARDED_FOR']='203.0.113.200, 198.51.100.77';
        same('198.51.100.77',$resolver->resolve(),'Untrusted client hop must stop the right-to-left chain.');

        $_SERVER['HTTP_X_FORWARDED_FOR']='not-an-ip';
        same('10.0.0.3',$resolver->resolve(),'Malformed forwarding evidence must fail back to the trusted peer.');
    } finally {
        $_SERVER=$previousServer;
        $GLOBALS['ysai_test_options']=$previousOptions;
    }
});

test('Boot admission is browser scoped with a high shared-network safety ceiling', function (): void {
    global $wpdb;
    $previous=$wpdb??null;
    $wpdb=new RateLimitDatabase();
    try {
        $limiter=new RateLimiter(new Settings(),new TransactionManager());
        $client=Uuid::v4();
        for($index=0;$index<30;$index++){
            ok($limiter->consumeBoot($client,'203.0.113.10')['allowed']);
        }
        ok(!$limiter->consumeBoot($client,'203.0.113.10')['allowed'],'One browser cannot rotate through unlimited boot sessions.');

        $wpdb=new RateLimitDatabase();
        $limiter=new RateLimiter(new Settings(),new TransactionManager());
        for($index=0;$index<31;$index++){
            ok($limiter->consumeBoot(Uuid::v4(),'198.51.100.20')['allowed'],'Independent browsers on one shared address must not block at the old 30-request IP ceiling.');
        }
        same(93,array_sum(array_map(static function(array $row): int{return $row['request_count'];},$wpdb->rows)));
    } finally {
        $wpdb=$previous;
    }
});

test('Durable limiter denial commits no new high-cardinality bucket rows', function (): void {
    global $wpdb;
    $previous=$wpdb??null;
    try {
        $wpdb=new RateLimitDatabase();
        $siteHash=hash('sha256','boot-site');
        $wpdb->rows[$siteHash]=array(
            'bucket_hash'=>$siteHash,
            'request_count'=>3000,
            'reset_at'=>gmdate('Y-m-d H:i:s',time()+600),
        );
        $before=$wpdb->rows;
        $limiter=new RateLimiter(new Settings(),new TransactionManager());
        $denied=$limiter->consumeBoot(Uuid::v4(),'198.51.100.77');
        ok(!$denied['allowed']);
        same($before,$wpdb->rows,'Shared denial must occur before client or network rows are initialized.');

        $wpdb=new RateLimitDatabase();
        $client=Uuid::v4();
        $clientHash=hash('sha256','boot-client|'.$client);
        $wpdb->rows[$clientHash]=array(
            'bucket_hash'=>$clientHash,
            'request_count'=>30,
            'reset_at'=>gmdate('Y-m-d H:i:s',time()+600),
        );
        $before=$wpdb->rows;
        $limiter=new RateLimiter(new Settings(),new TransactionManager());
        $denied=$limiter->consumeBoot($client,'203.0.113.9');
        ok(!$denied['allowed']);
        same($before,$wpdb->rows,'Local denial must roll back provisional site and network rows.');
    } finally {
        $wpdb=$previous;
    }
});

test('Pre-schema limiter denial leaves no new option identity rows', function (): void {
    global $wpdb;
    $previous=$wpdb??null;
    $now=1700000010;
    $window=intdiv($now,60);
    try {
        $wpdb=new IngressOptionDatabase();
        $siteName='_ysai_ingress_'.substr(hash('sha256','health|site'),0,48);
        $wpdb->rows[$siteName]=$window.':600:'.$now;
        $before=$wpdb->rows;
        $limiter=new IngressRateLimiter(null,static function() use($now): int{return $now;});
        $denied=$limiter->consumeHealth('198.51.100.77');
        ok(!$denied['allowed']);
        same($before,$wpdb->rows,'Shared denial must not allocate a network option row.');

        $wpdb=new IngressOptionDatabase();
        $networkName='_ysai_ingress_'.substr(hash('sha256','health|network|203.0.113.0/24'),0,48);
        $wpdb->rows[$networkName]=$window.':60:'.$now;
        $before=$wpdb->rows;
        $limiter=new IngressRateLimiter(null,static function() use($now): int{return $now;});
        $denied=$limiter->consumeHealth('203.0.113.44');
        ok(!$denied['allowed']);
        same($before,$wpdb->rows,'Local denial must roll back the provisional site option row.');
    } finally {
        $wpdb=$previous;
    }
});

test('Pre-schema ingress limiter is independent atomic and protects every scope on denial', function (): void {
    $counts=array();
    $now=1700000010;
    $limiter=new IngressRateLimiter(ingressAdmitterForTest($counts),static function() use($now): int{return $now;});
    for($index=0;$index<60;$index++){
        ok($limiter->consumeHealth('203.0.113.10')['allowed']);
    }
    $denied=$limiter->consumeHealth('203.0.113.200');
    ok(!$denied['allowed']);
    same(30,$denied['retry_after']);
    same(60,$counts['health|network|203.0.113.0/24|'.intdiv($now,60)]);
    same(60,$counts['health|site|'.intdiv($now,60)],'A denied request must not increment any ingress scope.');

    ok($limiter->consumeHealth('198.51.100.1')['allowed']);
    same(61,$counts['health|site|'.intdiv($now,60)]);

    $source=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/Security/IngressRateLimiter.php');
    notContains('SchemaRegistry',$source);
    notContains('TransactionManager',$source);
    notContains('ysai_rate_limits',$source);
    contains('$wpdb->options',$source);
    contains('START TRANSACTION',$source);
    contains('FOR UPDATE',$source);
    contains('INSERT IGNORE INTO',$source);
    notContains('ON DUPLICATE KEY UPDATE',$source);
});

test('Signed-session chat transport bounds exact replay without mutating any scope on denial', function (): void {
    $counts=array();
    $now=1700000010;
    $limiter=new IngressRateLimiter(ingressAdmitterForTest($counts),static function() use($now): int{return $now;});
    $sessionA=str_repeat('a',64);
    $sessionB=str_repeat('b',64);
    $window=intdiv($now,60);

    for($index=0;$index<180;$index++){
        ok($limiter->consumeChat($sessionA,'203.0.113.10')['allowed']);
    }
    $denied=$limiter->consumeChat($sessionA,'203.0.113.200');
    ok(!$denied['allowed']);
    same(30,$denied['retry_after']);
    same(180,$counts['chat|session|'.$sessionA.'|'.$window]);
    same(180,$counts['chat|network|203.0.113.0/24|'.$window]);
    same(180,$counts['chat|site|'.$window], 'A denied session must not consume any transport scope.');

    ok($limiter->consumeChat($sessionB,'203.0.113.20')['allowed']);
    same(1,$counts['chat|session|'.$sessionB.'|'.$window]);
    same(181,$counts['chat|network|203.0.113.0/24|'.$window]);
    same(181,$counts['chat|site|'.$window]);

    throws(InvalidArgumentException::class,function()use($limiter):void{
        $limiter->consumeChat('not-a-session-hash','203.0.113.10');
    });
});

test('Pre-schema ingress limiter atomically uses only the WordPress options table', function (): void {
    global $wpdb;
    $previous=$wpdb??null;
    $wpdb=new IngressOptionDatabase();
    try {
        $limiter=new IngressRateLimiter(null,static function(): int{return 1700000010;});
        for($index=0;$index<60;$index++){
            ok($limiter->consumeHealth('203.0.113.42')['allowed']);
        }
        ok(!$limiter->consumeHealth('203.0.113.42')['allowed']);
        same(2,count($wpdb->rows));
        foreach(array_keys($wpdb->rows) as $name){
            ok(strpos($name,'_ysai_ingress_')===0);
            notContains('203.0.113',$name);
        }
        $counts=array();
        foreach($wpdb->rows as $value){
            $parts=explode(':',$value);
            $counts[]=(int)($parts[1]??0);
        }
        sort($counts);
        same(array(60,60),$counts);
    } finally {
        $wpdb=$previous;
    }
});

test('Pre-schema ingress refuses an unproved or nontransactional options table', function (): void {
    global $wpdb;
    $previous=$wpdb??null;
    try {
        $wpdb=new IngressOptionDatabase();
        $wpdb->engine='MyISAM';
        $limiter=new IngressRateLimiter(null,static function(): int{return 1700000010;});
        throws(RuntimeException::class,static function()use($limiter):void{
            $limiter->consumeHealth('203.0.113.42');
        },'cannot prove the transactional ingress contract');
        same(array(),$wpdb->rows);
        same(array(),$wpdb->writes);
    } finally {
        $wpdb=$previous;
    }
});

test('Signed-session chat transport stores only hashed option identities', function (): void {
    global $wpdb;
    $previous=$wpdb??null;
    $wpdb=new IngressOptionDatabase();
    $sessionHash=str_repeat('c',64);
    try {
        $limiter=new IngressRateLimiter(null,static function(): int{return 1700000010;});
        ok($limiter->consumeChat($sessionHash,'203.0.113.42')['allowed']);
        same(3,count($wpdb->rows));
        foreach($wpdb->rows as $name=>$value){
            ok(strpos($name,'_ysai_ingress_')===0);
            notContains($sessionHash,$name);
            notContains($sessionHash,$value);
            notContains('203.0.113',$name);
            notContains('203.0.113',$value);
        }
    } finally {
        $wpdb=$previous;
    }
});

test('Pre-schema ingress rows are retired in bounded batches without assistant-schema authority', function (): void {
    global $wpdb;
    $previous=$wpdb??null;
    $now=1700000010;
    $wpdb=new IngressOptionDatabase();
    try {
        $wpdb->rows=array(
            '_ysai_ingress_old_a'=>'1:7:'.($now-172801),
            '_ysai_ingress_old_b'=>'1:2:'.($now-200000),
            '_ysai_ingress_fresh'=>'1:3:'.($now-30),
            '_unrelated_option'=>'1:1:1',
        );
        $limiter=new IngressRateLimiter(null,static function() use($now): int{return $now;});
        same(1,$limiter->cleanupExpired(1));
        same(1,$limiter->cleanupExpired(10));
        same(0,$limiter->cleanupExpired(10));
        same('1:3:'.($now-30),$wpdb->rows['_ysai_ingress_fresh']??'');
        same('1:1:1',$wpdb->rows['_unrelated_option']??'');
        $last=$wpdb->writes[count($wpdb->writes)-1]??array('sql'=>'','args'=>array());
        contains('DELETE FROM wp_options',(string)$last['sql']);
        contains('LIMIT %d',(string)$last['sql']);
        same('_ysai_ingress_',(string)($last['args'][0]??''));
    } finally {
        $wpdb=$previous;
    }
});

test('Public admission precedes exact schema verification and health combines schema with cached provider readiness', function (): void {
    $root=YSAI_PROJECT_ROOT.'/src';
    $rest=(string)file_get_contents($root.'/Presentation/Rest/RestApi.php');
    $boot=(string)file_get_contents($root.'/Presentation/Rest/Controller/BootController.php');
    $chat=(string)file_get_contents($root.'/Presentation/Rest/Controller/ChatController.php');
    $health=(string)file_get_contents($root.'/Presentation/Rest/Controller/HealthController.php');
    $decoder=(string)file_get_contents($root.'/Presentation/Rest/RequestDecoder.php');

    $publicStart=strpos($rest,'public function health');
    $adminStart=strpos($rest,'public function testConnection');
    ok($publicStart!==false && $adminStart!==false && $publicStart<$adminStart);
    $publicRoutes=substr($rest,$publicStart,$adminStart-$publicStart);
    notContains('$this->schema->blockedResponse()',$publicRoutes);
    contains('$this->schema->blockedResponse()',substr($rest,$adminStart));

    $bootIngress=strpos($boot,'$this->ingress->consumeBoot($clientIp)');
    $bootDecode=strpos($boot,'$this->decoder->boot($request)');
    $bootSchema=strpos($boot,'$this->schema->blockedResponse()');
    $bootDurableRate=strpos($boot,'$this->rateLimiter->consumeBoot(');
    $bootTable=strpos($boot,'$this->conversations->createOrResume(');
    ok(min($bootIngress,$bootDecode,$bootSchema,$bootDurableRate,$bootTable)>=0);
    ok($bootIngress<$bootDecode && $bootDecode<$bootSchema && $bootSchema<$bootDurableRate && $bootDurableRate<$bootTable);

    $chatTransport=strpos($chat,'$this->sessions->validateTransport($sessionToken)');
    $chatIngress=strpos($chat,'$this->ingress->consumeChat($sessionHash, $clientIp)');
    $chatEnvelope=strpos($chat,'$this->decoder->chatEnvelope($request)');
    $chatSchema=strpos($chat,'$this->schema->blockedResponse()');
    $chatAuthority=strpos($chat,'$this->sessions->assertActive($sessionToken, $sessionHash)');
    $chatImages=strpos($chat,'$this->decoder->chatFromEnvelope($envelope)');
    $chatTable=strpos($chat,'$this->conversations->resume(');
    ok(min($chatTransport,$chatIngress,$chatEnvelope,$chatSchema,$chatAuthority,$chatImages,$chatTable)>=0);
    ok($chatTransport<$chatIngress && $chatIngress<$chatEnvelope && $chatEnvelope<$chatSchema
        && $chatSchema<$chatAuthority && $chatAuthority<$chatImages && $chatImages<$chatTable);

    contains('SchemaLifecycle::verifyRuntime()',$health);
    contains('$this->readiness->isReady()',$health);
    notContains('BootRuntimeProof',$health);
    notContains('update_option(',$health);
    notContains('add_option(',$health);
    notContains('delete_option(',$health);
    notContains('BootRuntimeProof',$boot);

    $envelopeStart=strpos($decoder,'public function chatEnvelope');
    $materializeStart=strpos($decoder,'public function chatFromEnvelope');
    ok($envelopeStart!==false && $materializeStart!==false && $envelopeStart<$materializeStart);
    notContains('$this->images->decode',substr($decoder,$envelopeStart,$materializeStart-$envelopeStart));
    contains('$this->images->decode',substr($decoder,$materializeStart));
});

test('REST body limit uses actual bytes even when Content-Length is absent or understated', function (): void {
    $raw=Json::decodeRequiredObject((string)file_get_contents(YSAI_PROJECT_ROOT.'/config/public-api-contract.json'),'contract');
    $raw['x-runtime']['max_body_bytes']=1024;
    $contract=new PublicApiContract($raw);
    $decoder=requestDecoderForTest($contract);
    throws(InvalidRequest::class,function()use($decoder):void{
        $decoder->boot(new WP_REST_Request(str_repeat(' ',1025),array()));
    },'request_too_large');
    $guard=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/Security/RequestGuard.php');
    contains('$actualLength = strlen((string) $request->get_body())',$guard);
});
test('Turn request rejects invalid attachment object order or fields', function (): void {
    $id=Uuid::v4();
    throws(InvalidArgumentException::class,function()use($id):void{new \YassinStore\AiAssistant\Application\Turn\TurnRequest($id,str_repeat('a',24),Uuid::v4(),'x',array(array('data'=>base64_encode('x'),'mime_type'=>'image/png')));});
});

test('Canonical base64 has one accepted representation without decoding whole payloads', function (): void {
    ok(CanonicalBase64::isValid('eA=='));
    same(1,CanonicalBase64::decodedLength('eA=='));
    ok(!CanonicalBase64::isValid('eB=='),'Non-zero unused padding bits must be rejected.');
    ok(!CanonicalBase64::isValid('data:image/png;base64,eA=='));
    ok(!CanonicalBase64::isValid("eA==\n"));
});

test('Image decoder streams canonical data and preserves one encoded representation', function (): void {
    $contract=publicApiContract();
    $decoder=new ImageAttachmentDecoder($contract,imageRuntimeUnlimited());
    $data=tinyPngBase64();
    $attachments=$decoder->decode(array(array('mime_type'=>'image/png','data'=>$data)),true);
    same(1,count($attachments));
    ok($attachments[0] instanceof ImageAttachment);
    same($data,$attachments[0]->base64Data());
    same(CanonicalBase64::decodedLength($data),$attachments[0]->decodedBytes());
    same(hash('sha256',(string)base64_decode($data,true)),$attachments[0]->contentSha256());
    throws(InvalidRequest::class,function()use($decoder,$data):void{
        $decoder->decode(array(array('mime_type'=>'image/png','data'=>'data:image/png;base64,'.$data)),true);
    },'image_encoding_invalid');
});

test('Image policy is bounded per item aggregate and browser source decode', function (): void {
    $contract=publicApiContract();
    same(ImageAttachmentPolicy::MAX_ITEMS,$contract->attachmentMaxItems());
    same(ImageAttachmentPolicy::MAX_DECODED_BYTES,$contract->attachmentMaxDecodedBytes());
    same(ImageAttachmentPolicy::MAX_TOTAL_DECODED_BYTES,$contract->attachmentMaxTotalDecodedBytes());
    same(ImageAttachmentPolicy::MAX_ENCODED_BYTES,$contract->attachmentMaxEncodedBytes());
    same(ImageAttachmentPolicy::MAX_TOTAL_ENCODED_BYTES,$contract->attachmentMaxTotalEncodedBytes());
    same(8388608,ImageAttachmentPolicy::MAX_SOURCE_BYTES);
    same(262144,ImageAttachmentPolicy::MAX_SOURCE_HEADER_BYTES);
    same(4096,ImageAttachmentPolicy::MAX_SOURCE_WIDTH);
    same(4096,ImageAttachmentPolicy::MAX_SOURCE_HEIGHT);
    same(12582912,ImageAttachmentPolicy::MAX_SOURCE_PIXELS);
    same(2097152,$contract->maxBodyBytes());
});

test('Widget source-image limits are localized from the canonical PHP policy', function (): void {
    $root=YSAI_PROJECT_ROOT;
    $widget=(string)file_get_contents($root.'/src/Presentation/Widget/Widget.php');
    $queue=(string)file_get_contents($root.'/assets/js/widget/40-attachment-queue.js');
    contains("'maxSourceImageHeaderBytes' => ImageAttachmentPolicy::MAX_SOURCE_HEADER_BYTES",$widget);
    contains("'maxSourceImageWidth' => ImageAttachmentPolicy::MAX_SOURCE_WIDTH",$widget);
    contains("'maxSourceImageHeight' => ImageAttachmentPolicy::MAX_SOURCE_HEIGHT",$widget);
    contains("'maxSourceImagePixels' => ImageAttachmentPolicy::MAX_SOURCE_PIXELS",$widget);
    contains('readAsArrayBuffer(file.slice(0, size))',$queue);
    contains('parseJpegDimensions',$queue);
    contains('parsePngDimensions',$queue);
    contains('parseWebpDimensions',$queue);
    contains('window.createImageBitmap(file',$queue);
    contains('resizeWidth: target.width',$queue);
    contains('bitmap.close()',$queue);
    contains('revokeObjectURL(objectUrl)',$queue);
    notContains('reader.readAsDataURL(file);
        });
    };',$queue);
});

test('Image capability fails closed when PHP headroom is insufficient', function (): void {
    $available=new ImageRuntimeCapability(static function (): string{return '64M';},static function (): int{return 16*1024*1024;});
    ok($available->canAdvertise());
    ok($available->canProcess());
    $tight=new ImageRuntimeCapability(static function (): string{return '40M';},static function (): int{return 33*1024*1024;});
    ok(!$tight->canAdvertise());
    ok(!$tight->canProcess());
    ok(!$tight->canParseBody(1400000));
    $unlimited=new ImageRuntimeCapability(static function (): string{return '-1';},static function (): int{return PHP_INT_MAX;});
    ok($unlimited->canAdvertise());
});

test('Attachment authority is not repeatedly binary-decoded after REST inspection', function (): void {
    $root=YSAI_PROJECT_ROOT;
    $request=(string)file_get_contents($root.'/src/Application/Turn/TurnRequest.php');
    $hasher=(string)file_get_contents($root.'/src/Application/Turn/TurnRequestHasher.php');
    $model=(string)file_get_contents($root.'/src/Application/Ai/ModelRequest.php');
    $rest=(string)file_get_contents($root.'/src/Presentation/Rest/RequestDecoder.php');
    $decoder=(string)file_get_contents($root.'/src/Presentation/Rest/ImageAttachmentDecoder.php');
    notContains('base64_decode',$request);
    notContains('base64_decode',$hasher);
    notContains('base64_decode',$model);
    notContains('getimagesizefromstring',$rest);
    notContains('base64_encode($decoded)',$rest);
    contains('tmpfile()',$decoder);
    contains('getimagesize($uri)',$decoder);
});

test('Maximum advertised two-image path stays alive under a 40 MiB PHP limit', function (): void {
    if (!function_exists('exec') || PHP_OS_FAMILY === 'Unknown') {
        // Sandboxed WebAssembly PHP may expose exec() even though process
        // spawning is unavailable. The main quality gate executes the same
        // probe as a separate PHP process with the required memory_limit.
        ok(true);
        return;
    }
    $command=escapeshellarg(PHP_BINARY).' -d memory_limit=40M '.escapeshellarg(YSAI_TEST_ROOT.'/image-memory-probe.php').' 2>&1';
    $lines=array(); $status=0; exec($command,$lines,$status);
    same(0,$status,implode("\n",$lines));
    $output=implode("\n",$lines);
    contains('status=ok',$output);
    if(preg_match('/peak_delta=([0-9]+)/',$output,$matches)!==1){throw new TestFailure('Memory probe did not report a peak delta.');}
    ok((int)$matches[1] < 16777216,'The bounded image path used more than 16 MiB above its clean baseline.');
});

test('Settings can explicitly remove a stored API key without treating a blank password field as deletion', function (): void {
    $stored=array_replace(Settings::defaults(),array('gemini_api_key'=>'stored-secret'));
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>$stored);
    $settings=new Settings();
    $input=array(
        'enabled'=>'1','allow_images'=>'1','gemini_api_key'=>'',
        'max_output_tokens'=>$stored['max_output_tokens'],
        'http_timeout_seconds'=>$stored['http_timeout_seconds'],'max_tool_rounds'=>$stored['max_tool_rounds'],
        'store_guidance'=>$stored['store_guidance'],
    );
    $kept=$settings->sanitize($input);
    same('stored-secret',$kept['gemini_api_key']);
    ok(!array_key_exists('_protocol_revision',$kept));

    $settings->refresh();
    $cleared=$settings->sanitize(array_merge($input,array('clear_gemini_api_key'=>'1')));
    same('',$cleared['gemini_api_key']);
    ok(!array_key_exists('_protocol_revision',$cleared));
    ok(!array_key_exists('clear_gemini_api_key',$cleared),'One-shot removal control must not persist.');
});

test('Settings and boot-facing values enforce the browser text bounds before persistence', function (): void {
    $stored=array_replace(Settings::defaults(),array(
        'widget_title'=>'عنوان صالح','store_guidance'=>'إرشاد صالح',
    ));
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>$stored);
    $settings=new Settings();
    $input=array(
        'enabled'=>'1','allow_images'=>'1',
        'max_output_tokens'=>$stored['max_output_tokens'],'http_timeout_seconds'=>$stored['http_timeout_seconds'],
        'max_tool_rounds'=>$stored['max_tool_rounds'],'store_guidance'=>str_repeat('x',Settings::STORE_GUIDANCE_MAX_BYTES+1),
        'widget_title'=>str_repeat('ت',Settings::WIDGET_TEXT_LIMITS['widget_title']+1),
        'widget_subtitle'=>'فرعي','widget_button_text'=>'افتح','empty_state_hint'=>'مرحباً',
    );
    $sanitized=$settings->sanitize($input);
    same('عنوان صالح',$sanitized['widget_title']);
    same('إرشاد صالح',$sanitized['store_guidance']);
    ok(!array_key_exists('_protocol_revision',$sanitized));

    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY]=array_replace($stored,array(
        'widget_title'=>str_repeat('ت',301),'store_guidance'=>str_repeat('x',Settings::STORE_GUIDANCE_MAX_BYTES+1),
    ));
    $settings->refresh();
    same(Settings::defaults()['widget_title'],$settings->get('widget_title'));
    same('',$settings->get('store_guidance'));

    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY]=array_replace($stored,array(
        'widget_title'=>"\xC3\x28",'store_guidance'=>"\xC3\x28",
    ));
    $settings->refresh();
    same(Settings::defaults()['widget_title'],$settings->get('widget_title'));
    same('',$settings->get('store_guidance'));

    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY]=array_replace($stored,array(
        'widget_title'=>"عنوان\x7Fغير صالح",'store_guidance'=>"إرشاد\x01غير صالح",
    ));
    $settings->refresh();
    same(Settings::defaults()['widget_title'],$settings->get('widget_title'));
    same('',$settings->get('store_guidance'));
});

test('Administrator prompt guidance and widget copy preserve exact validated UTF-8', function (): void {
    $stored=array_replace(Settings::defaults(),array(
        'store_guidance'=>'إرشاد حالي',
        'widget_button_text'=>'زر حالي',
        'widget_title'=>'عنوان حالي',
    ));
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>$stored);
    $settings=new Settings();
    $guidance="  استخدم عرض خصم %50 ولا تحذف <b>هذا</b>.\n\tاحتفظ بالنص كما هو.  ";
    $button="  خصم %50 <b>الآن</b>  ";
    $input=array(
        'enabled'=>'1','allow_images'=>'1','gemini_api_key'=>'',
        'gemini_thinking_level'=>$stored['gemini_thinking_level'],
        'max_output_tokens'=>$stored['max_output_tokens'],
        'http_timeout_seconds'=>$stored['http_timeout_seconds'],'max_tool_rounds'=>$stored['max_tool_rounds'],
        'store_guidance'=>$guidance,
        'widget_button_text'=>$button,'widget_title'=>'عنوان','widget_subtitle'=>'فرعي','empty_state_hint'=>'مرحباً',
    );
    $exact=$settings->sanitize($input);
    same($guidance,$exact['store_guidance']);
    same($button,$exact['widget_button_text']);
    ok(!array_key_exists('_protocol_revision',$exact));
    $builtPrompt=promptBuilderForTest($exact['store_guidance'])
        ->build(ConversationState::initial()->toArray());
    contains('## تفضيلات المتجر المكوّنة من المسؤول',$builtPrompt);
    contains(Json::encodeObject(array('store_guidance'=>$guidance)),$builtPrompt);
    notContains("\n".$guidance."\n",$builtPrompt);
    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY]=$exact;
    $settings->refresh();
    same($guidance,$settings->get('store_guidance'));
    same($button,$settings->get('widget_button_text'));

    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY]=$stored;
    $settings->refresh();
    $invalid=$settings->sanitize(array_merge($input,array(
        'store_guidance'=>"تعليمات\x01غير صالحة",
        'widget_button_text'=>"زر\x7Fغير صالح",
        'widget_title'=>"\xC3\x28",
    )));
    same('إرشاد حالي',$invalid['store_guidance']);
    same('زر حالي',$invalid['widget_button_text']);
    same('عنوان حالي',$invalid['widget_title']);
    ok(!array_key_exists('_protocol_revision',$invalid));

    $source=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/WordPress/Settings.php');
    notContains('sanitize_textarea_field',$source);
    same(1,substr_count($source,'sanitize_text_field('),'Only the closed API-key field may use WordPress text sanitization.');
});

test('Thinking level is closed and contributes directly to runtime readiness identity', function (): void {
    $stored=array_replace(Settings::defaults(),array('gemini_api_key'=>'key','gemini_thinking_level'=>'low'));
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>$stored);
    $settings=new Settings();
    $input=array(
        'enabled'=>'1','allow_images'=>'1','gemini_api_key'=>'',
        'gemini_thinking_level'=>'high',
        'max_output_tokens'=>$stored['max_output_tokens'],
        'http_timeout_seconds'=>$stored['http_timeout_seconds'],'max_tool_rounds'=>$stored['max_tool_rounds'],
        'store_guidance'=>$stored['store_guidance'],
    );
    $changed=$settings->sanitize($input);
    same('high',$changed['gemini_thinking_level']);
    same(1,$changed['runtime_configuration_epoch']);
    ok(!array_key_exists('_protocol_revision',$changed));

    $settings->refresh();
    $invalid=$settings->sanitize(array_merge($input,array('gemini_thinking_level'=>'invalid')));
    same('low',$invalid['gemini_thinking_level']);

    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY]=$stored;
    $settings->refresh();
    $readiness=runtimeReadinessForTest($settings);
    $attempt=$readiness->beginCheck();
    $readiness->markReady($attempt);
    same('ready',$readiness->status()['code']);
    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY]=array_replace($stored,array('gemini_thinking_level'=>'high'));
    $settings->refresh();
    same('runtime_configuration_changed',$readiness->status()['code']);
});

test('First-release Gemini runtime readiness binds one model to a minimal provider contract only', function (): void {
    same('gemini-3.5-flash',Settings::GEMINI_MODEL);
    ok(!array_key_exists('gemini_model',Settings::defaults()));
    $root=YSAI_PROJECT_ROOT;
    $admin=(string)file_get_contents($root.'/src/Presentation/Admin/AdminPages.php');
    $transport=(string)file_get_contents($root.'/src/Infrastructure/Gemini/GeminiTransport.php');
    $readiness=(string)file_get_contents($root.'/src/Infrastructure/Gemini/GeminiRuntimeReadiness.php');
    $probe=(string)file_get_contents($root.'/src/Infrastructure/Gemini/GeminiRuntimeProbe.php');
    notContains("'gemini_model' =>",$admin);
    contains('$model = Settings::GEMINI_MODEL',$transport);
    notContains('plugin_version',$readiness);
    notContains('YSAI_VERSION',$readiness);
    contains("'probe_contract' => RuntimeProbeContract::REVISION",$readiness);
    contains('RuntimeProbeContract::fingerprint($thinkingLevel)',$readiness);
    notContains('AgentPromptBuilder',$readiness);
    notContains('PromptProtocol',$readiness);
    notContains('store_name',$readiness);
    notContains('ToolCatalog',$readiness);
    notContains('catalog_discover',$probe);
    notContains('cart_apply',$probe);

    $long=str_repeat('متجر ',80);
    $normalized=AgentPromptBuilder::normalizeStoreName("  ".$long."\n");
    ok(Utf8::codePointLength($normalized)<=AgentPromptBuilder::STORE_NAME_MAX_CODE_POINTS);
    notContains("\n",$normalized);
    same('متجر WooCommerce',AgentPromptBuilder::normalizeStoreName("bad\x01title"));
});

test('Settings and content boundaries retain only canonical public HTTP URLs', function (): void {
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array(
        'contact_url'=>'https://example%40evil.test/path',
        'about_url'=>'https://example.test/about',
    )));
    $settings=new Settings();
    same('',$settings->get('contact_url'));
    same('https://example.test/about',$settings->get('about_url'));
    $saved=$settings->sanitize(array(
        'contact_url'=>' https://example.test/contact ',
        'about_url'=>'https://user:pass@example.test/about',
        'shipping_url'=>'https://example.test/shipping',
    ));
    same('',$saved['contact_url']);
    same('',$saved['about_url']);
    same('https://example.test/shipping',$saved['shipping_url']);
});

test('Widget bubble geometry is shared and storefront cards follow WooCommerce archive image authority', function (): void {
    $widgetCss = file_get_contents(YSAI_PROJECT_ROOT . '/assets/css/widget.css');
    $adminCss = file_get_contents(YSAI_PROJECT_ROOT . '/assets/css/admin.css');
    $imageProjection = file_get_contents(YSAI_PROJECT_ROOT . '/src/Infrastructure/WooCommerce/Projection/StorefrontImage.php');
    ok(is_string($widgetCss) && is_string($adminCss) && is_string($imageProjection));
    contains('--ysai-bubble-tail-radius', $widgetCss);
    contains('border-bottom-left-radius: var(--ysai-bubble-tail-radius)', $widgetCss);
    contains('border-bottom-right-radius: var(--ysai-bubble-tail-radius)', $widgetCss);
    contains('--ysai-bubble-tail-radius', $adminCss);
    contains("apply_filters('single_product_archive_thumbnail_size'", $imageProjection);
    contains('wp_get_attachment_image_url($attachmentId, $size)', $imageProjection);
});

test('Widget appearance settings are closed bounded and create no hidden readiness revision', function (): void {
    $stored=array_replace(Settings::defaults(),array('widget_product_layout'=>'list'));
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>$stored);
    $settings=new Settings();
    $sanitized=$settings->sanitize(array(
        'enabled'=>'1','allow_images'=>'1',
        'max_output_tokens'=>$stored['max_output_tokens'],
        'http_timeout_seconds'=>$stored['http_timeout_seconds'],'max_tool_rounds'=>$stored['max_tool_rounds'],
        'store_guidance'=>$stored['store_guidance'],
        'widget_enabled'=>'1','widget_auto_insert'=>'1','widget_product_show_description'=>'1',
        'widget_brand_color'=>'#380000','widget_header_background_color'=>'#4a1020',
        'widget_header_foreground_color'=>'#fffaf5','widget_chat_background'=>'not-a-color',
        'widget_product_layout'=>'carousel','widget_product_cards_per_view'=>'99',
        'widget_product_image_ratio'=>'4-3','widget_panel_width'=>'999',
        'widget_panel_height'=>'100','widget_panel_radius'=>'30','widget_bubble_radius'=>'22',
        'widget_product_card_radius'=>'99',
        'widget_font_size'=>'16','widget_accent'=>'#abcdef',
    ));
    same('#380000',$sanitized['widget_brand_color']);
    same('#4a1020',$sanitized['widget_header_background_color']);
    same('#fffaf5',$sanitized['widget_header_foreground_color']);
    same(Settings::defaults()['widget_chat_background'],$sanitized['widget_chat_background']);
    same('carousel',$sanitized['widget_product_layout']);
    same(3,$sanitized['widget_product_cards_per_view']);
    same('4-3',$sanitized['widget_product_image_ratio']);
    same(560,$sanitized['widget_panel_width']);
    same(520,$sanitized['widget_panel_height']);
    same(32,$sanitized['widget_product_card_radius']);
    ok(!array_key_exists('_protocol_revision',$sanitized));
    ok(!array_key_exists('widget_accent',$sanitized),'Removed appearance aliases must not persist.');
});

test('Assistant administration is consistently administrator-only', function (): void {
    $capabilities = new Capabilities();
    same('manage_options', $capabilities->manage());

    $previous = $GLOBALS['ysai_test_current_user_capabilities'];
    try {
        $GLOBALS['ysai_test_current_user_capabilities'] = array('manage_woocommerce' => true);
        same(false, $capabilities->currentUserCanManage(), 'Shop Managers must not receive assistant administration authority.');
        $GLOBALS['ysai_test_current_user_capabilities'] = array('manage_options' => true);
        same(true, $capabilities->currentUserCanManage());
    } finally {
        $GLOBALS['ysai_test_current_user_capabilities'] = $previous;
    }

    $root = YSAI_PROJECT_ROOT;
    $admin = (string) file_get_contents($root . '/src/Presentation/Admin/AdminPages.php');
    contains("add_filter('option_page_capability_ysai_settings_group', array(\$this, 'settingsCapability'))", $admin);
    contains('public function settingsCapability(string $defaultCapability): string', $admin);
    contains('return $this->capabilities->manage();', $admin);
    contains('public function menu(): void', $admin);
    contains('public function registerSettings(): void', $admin);
    contains('public function verifySchema(): void', $admin);
    contains('public function enqueue(string $hook): void', $admin);

    $guard = (string) file_get_contents($root . '/src/Infrastructure/Security/RequestGuard.php');
    contains('$this->capabilities->currentUserCanManage()', $guard);

    $privacy = (string) file_get_contents($root . '/src/Presentation/Privacy/Privacy.php');
    contains('public function __construct(Settings $settings, Capabilities $capabilities)', $privacy);
    contains('$this->capabilities->currentUserCanManage()', $privacy);
    contains('تُخزن رسائل المحادثة المقبولة بنصها المطابق', $privacy);
    contains('يقتصر حجب هذه القيم على سياق سجلات التشخيص', $privacy);
    notContains('قبل التخزين عند تفعيل الحجب', $privacy);

    $kernel = (string) file_get_contents($root . '/src/Infrastructure/Composition/PluginKernel.php');
    contains('new Privacy($this->settings, $capabilities)', $kernel);

    $schemaAdmin = (string) file_get_contents($root . '/src/Presentation/Admin/SchemaAdmin.php');
    contains('public function __construct(Capabilities $capabilities)', $schemaAdmin);
    contains('$this->capabilities->currentUserCanManage()', $schemaAdmin);
    notContains("current_user_can('activate_plugins')", $schemaAdmin);

    $plugin = (string) file_get_contents($root . '/src/Plugin.php');
    contains('new SchemaAdmin($this->capabilities)', $plugin);
    contains('if (!$this->capabilities->currentUserCanManage())', $plugin);
});

test('Conversation privacy projection keeps model-question provenance server-side', function (): void {
    $question=modelQuestionForTest(
        'هل تفضّل المقاس المتوسط أم الكبير؟',
        agentContextForTest(),
        ModelAuthoredQuestion::PURPOSE_CART_CONTINUATION,
        'privacy-step-1',
        'privacy-call-1',
        'privacy-provider-call-1'
    );
    $message=publicMessageForTest('assistant','هل تفضّل المقاس المتوسط أم الكبير؟',Outcome::FOLLOW_UP);
    $payload=array(
        'message'=>$message,
        'model_question'=>$question->toArray(),
    );

    $projected=ConversationPrivacyProjector::messagePayload('assistant',$payload);
    same(array('message'=>$message),$projected);
    ok(!array_key_exists('model_question',$projected));
    throws(RuntimeException::class,static function()use($question):void{
        ConversationPrivacyProjector::turnResponse(array('model_question'=>$question->toArray()));
    },'missing its client response');
});

test('Conversation holders have closed authority-scoped export and erasure routes', function (): void {
    $root=YSAI_PROJECT_ROOT;
    $rest=(string)file_get_contents($root.'/src/Presentation/Rest/RestApi.php');
    $controller=(string)file_get_contents($root.'/src/Presentation/Rest/Controller/ConversationPrivacyController.php');
    $service=(string)file_get_contents($root.'/src/Infrastructure/Database/ConversationPrivacyService.php');
    $activeWork=(string)file_get_contents($root.'/src/Infrastructure/Database/ActiveWorkInspector.php');
    $kernel=(string)file_get_contents($root.'/src/Infrastructure/Composition/PluginKernel.php');

    contains("'/conversation/export'",$rest);
    contains("'/conversation/delete'",$rest);
    contains("get_header('X-YSAI-Session')",$controller);
    contains('$this->sessions->validateTransport($sessionToken)',$controller);
    contains('$this->sessions->assertActive($sessionToken, $sessionHash)',$controller);
    contains('$this->conversations->resume(',$controller);
    contains("? array('conversation_id', 'conversation_token')",$controller);
    contains("array('conversation_id', 'conversation_token', 'cursor')",$controller);
    contains("'conversation_export_cursor_invalid'",$controller);
    notContains('message_cursor',$controller);
    notContains('receipt_cursor',$controller);
    contains("preg_match('/^[A-Za-z0-9_-]+$/', \$conversationToken)",$controller);
    contains('consumeConversationPrivacy',$controller);
    contains("'conversation_busy'",$controller);
    contains('$request->get_json_params()',$controller);
    notContains('get_json_error',$controller);

    contains("\$this->conversations->reloadForUpdate(\$identity['id'])",$service);
    contains('LIMIT 1 FOR UPDATE',$activeWork);
    contains('hasForConversation($conversationId, $publicId)',$service);
    contains('lease_until > %s',$activeWork);
    contains('OperationStatus::PREPARED',$activeWork);
    contains('OperationStatus::EXECUTING',$activeWork);
    contains('l.fence = o.commerce_fence',$activeWork);
    contains('SchemaRegistry::operationStepAttempts()',$service);
    contains('SchemaRegistry::operationSteps()',$service);
    contains('verified_cart_receipts',$service);
    contains('private const MESSAGE_PAGE_SIZE = 100',$service);
    contains('private const RECEIPT_PAGE_SIZE = 50',$service);
    contains('private const TURN_PAGE_SIZE = 1',$service);
    contains('AND id <= %d',$service);
    contains("'next_cursor'",$service);
    notContains('next_message_cursor',$service);
    notContains('next_receipt_cursor',$service);
    contains('new ConversationPrivacyService(',$kernel);
    contains('new ConversationExportCursor()',$kernel);
    contains('new ConversationPrivacyController(',$kernel);
});

test('Conversation export cursor is one rolling authority-bound coherent high-water snapshot', function (): void {
    $now=2000000000;
    $clock=static function()use(&$now):int{return $now;};
    $cursors=new ConversationExportCursor($clock);
    $authority=array(
        'public_id'=>'96c92b37-5f44-4a70-a0a7-575826250448',
        'session_hash'=>str_repeat('a',64),
        'state'=>ConversationState::initial()->toArray(),
        'created_at'=>$now-600,
        'updated_at'=>$now-30,
        'expires_at'=>$now+86400,
    );
    $state=$cursors->start($authority,array(
        'messages'=>150,'receipts'=>60,'turns'=>0,
        'operations'=>0,'steps'=>0,'attempts'=>0,
    ));
    same(150,$state['message_high']);
    same(60,$state['receipt_high']);
    same(false,$state['messages_done']);
    same(false,$state['receipts_done']);
    same($authority['created_at'],$state['snapshot_created_at']);
    same($authority['updated_at'],$state['snapshot_updated_at']);
    same($authority['expires_at'],$state['snapshot_expires_at']);

    $state['message_after']=100;
    $state['receipt_after']=50;
    $first=$cursors->seal($state,$authority);
    ok(strlen($first)<=2048,'The complete multi-stream cursor must fit the public REST bound.');
    $opened=$cursors->open($first,$authority);
    same(100,$opened['message_after']);
    same(50,$opened['receipt_after']);
    notContains($authority['public_id'],$first);
    notContains($authority['session_hash'],$first);

    // A continuation close to expiry rolls its short bearer lifetime while
    // retaining the original high-water marks and snapshot metadata.
    $now+=7199;
    $opened=$cursors->open($first,$authority);
    $rolled=$cursors->seal($opened,$authority);
    $now+=2;
    throws(ConversationExportCursorInvalid::class,static function()use($cursors,$first,$authority):void{
        $cursors->open($first,$authority);
    });
    $rolledState=$cursors->open($rolled,$authority);
    same(150,$rolledState['message_high']);
    same($state['snapshot_at'],$rolledState['snapshot_at']);
    same($authority['updated_at'],$rolledState['snapshot_updated_at']);

    $wrong=$authority;
    $wrong['session_hash']=str_repeat('b',64);
    throws(ConversationExportCursorInvalid::class,static function()use($cursors,$rolled,$wrong):void{
        $cursors->open($rolled,$wrong);
    });
    $changed=$authority;
    $changed['state']=ConversationState::initial()->after(
        AssistantResponse::answer('إجابة'),time()
    )->toArray();
    throws(ConversationExportCursorInvalid::class,static function()use($cursors,$rolled,$changed):void{
        $cursors->open($rolled,$changed);
    });
    $tampered='A'.substr($rolled,1);
    throws(ConversationExportCursorInvalid::class,static function()use($cursors,$tampered,$authority):void{
        $cursors->open($tampered,$authority);
    });
    $invalid=$rolledState;
    $invalid['message_after']=151;
    throws(ConversationExportCursorInvalid::class,static function()use($cursors,$invalid,$authority):void{
        $cursors->seal($invalid,$authority);
    });
});

test('Conversation export pages freeze both streams and metadata at one high-water snapshot', function (): void {
    global $wpdb;
    $previous=$wpdb;
    $now=time();
    $publicId='96c92b37-5f44-4a70-a0a7-575826250448';
    $sessionHash=str_repeat('a',64);
    $state=ConversationState::initial()->toArray();
    $row=array(
        'id'=>7,'public_id'=>$publicId,'access_hash'=>str_repeat('c',64),'session_hash'=>$sessionHash,
        'state'=>Json::encodeObject($state),'created_at'=>gmdate('Y-m-d H:i:s',$now-600),
        'updated_at'=>gmdate('Y-m-d H:i:s',$now-30),'expires_at'=>gmdate('Y-m-d H:i:s',$now+86400),
    );
    $messages=array();
    for($id=1;$id<=101;++$id){
        $assistant=($id%2)===0;
        $messages[]=array(
            'id'=>$id,'role'=>$assistant?'assistant':'user','outcome'=>$assistant?Outcome::ANSWER:'',
            'content'=>'message-'.$id,
            'payload'=>Json::encodeObject(privacyMessagePayloadForTest($assistant?'assistant':'user','message-'.$id,$assistant?Outcome::ANSWER:'')),
            'created_at'=>gmdate('Y-m-d H:i:s',$now-500+$id),
        );
    }
    $operation=array(
        'id'=>1,'status'=>OperationStatus::VERIFIED,
        'receipt'=>Json::encodeObject(receipt(true)->toArray()),
        'completed_at'=>gmdate('Y-m-d H:i:s',$now-100),
    );
    $wpdb=new ConversationPrivacyDatabase($row,$messages,array($operation));
    try{
        $transactions=new TransactionManager();
        $service=new ConversationPrivacyService(
            $transactions,
            new ConversationRepository(new Settings(),$transactions,new TurnLeaseManager(),new ActiveWorkInspector()),
            new ConversationExportCursor(static function()use($now):int{return $now;}),
            new ActiveWorkInspector()
        );
        $authority=array(
            'id'=>7,'public_id'=>$publicId,'session_hash'=>$sessionHash,'state'=>$state,
            'created_at'=>$now-600,'updated_at'=>$now-30,'expires_at'=>$now+86400,
        );
        $first=$service->export($authority,null);
        assertConversationExportResponseForTest($first);
        same(100,count($first['messages']));
        same(1,count($first['verified_cart_receipts']));
        $exportedReceipt=$first['verified_cart_receipts'][0]['receipt'];
        $exportedReceiptKeys=array_keys($exportedReceipt);sort($exportedReceiptKeys,SORT_STRING);
        same(array('action','changed','created_at','id','message','proof'),$exportedReceiptKeys);
        $exportedProofKeys=array_keys($exportedReceipt['proof']);sort($exportedProofKeys,SORT_STRING);
        same(
            array('cart_count','cart_total','changed_line_count','commands','currency'),
            $exportedProofKeys
        );
        ok(!array_key_exists('before_revision',$exportedReceipt['proof']));
        ok(!array_key_exists('after_revision',$exportedReceipt['proof']));
        same(false,$first['complete']);
        ok(is_string($first['next_cursor'])&&$first['next_cursor']!=='');
        same('message-100',$first['messages'][99]['text']);

        // Later canonical rows and retention refreshes must not leak into the
        // already-issued snapshot or change its signed metadata.
        $wpdb->messages[]=array(
            'id'=>102,'role'=>'assistant','outcome'=>Outcome::ANSWER,'content'=>'message-102',
            'payload'=>Json::encodeObject(privacyMessagePayloadForTest('assistant','message-102',Outcome::ANSWER)),
            'created_at'=>gmdate('Y-m-d H:i:s',$now+1),
        );
        $later=$operation;$later['id']=2;$later['completed_at']=gmdate('Y-m-d H:i:s',$now+1);
        $wpdb->operations[]=$later;
        $wpdb->row['updated_at']=gmdate('Y-m-d H:i:s',$now);
        $wpdb->row['expires_at']=gmdate('Y-m-d H:i:s',$now+172800);
        $second=$service->export($authority,$first['next_cursor']);
        assertConversationExportResponseForTest($second);
        same(1,count($second['messages']));
        same('message-101',$second['messages'][0]['text']);
        same(0,count($second['verified_cart_receipts']));
        same(true,$second['complete']);
        same(null,$second['next_cursor']);
        same($first['updated_at'],$second['updated_at']);
        same($first['expires_at'],$second['expires_at']);
    }finally{$wpdb=$previous;}
});

test('Conversation receipt snapshots exclude recoverable operations below the former high-water', function (): void {
    global $wpdb;
    $previous=$wpdb;
    $now=time();
    $publicId='96c92b37-5f44-4a70-a0a7-575826250448';
    $sessionHash=str_repeat('a',64);
    $state=ConversationState::initial()->toArray();
    $row=array(
        'id'=>7,'public_id'=>$publicId,'access_hash'=>str_repeat('c',64),'session_hash'=>$sessionHash,
        'state'=>Json::encodeObject($state),'created_at'=>gmdate('Y-m-d H:i:s',$now-600),
        'updated_at'=>gmdate('Y-m-d H:i:s',$now-30),'expires_at'=>gmdate('Y-m-d H:i:s',$now+86400),
    );
    $authority=array(
        'id'=>7,'public_id'=>$publicId,'session_hash'=>$sessionHash,'state'=>$state,
        'created_at'=>$now-600,'updated_at'=>$now-30,'expires_at'=>$now+86400,
    );
    $operations=array();
    for($id=1;$id<=120;++$id){
        $operations[]=array(
            'id'=>$id,
            'status'=>$id===60?OperationStatus::PREPARED:OperationStatus::VERIFIED,
            'receipt'=>$id===60?null:Json::encodeObject(receipt(true)->toArray()),
            'completed_at'=>$id===60?null:gmdate('Y-m-d H:i:s',$now-500+$id),
        );
    }
    $wpdb=new ConversationPrivacyDatabase($row,array(),$operations);
    try{
        $transactions=new TransactionManager();
        $cursors=new ConversationExportCursor(static function()use($now):int{return $now;});
        $service=new ConversationPrivacyService(
            $transactions,
            new ConversationRepository(new Settings(),$transactions,new TurnLeaseManager(),new ActiveWorkInspector()),
            $cursors,
            new ActiveWorkInspector()
        );
        $first=$service->export($authority,null);
        assertConversationExportResponseForTest($first);
        same(50,count($first['verified_cart_receipts']));
        same(false,$first['complete']);
        $snapshot=$cursors->open((string)$first['next_cursor'],$authority);
        same(59,$snapshot['receipt_high'],'The first recoverable operation must end the immutable receipt prefix.');

        foreach($wpdb->operations as &$operation){
            if((int)$operation['id']===60){
                $operation['status']=OperationStatus::VERIFIED;
                $operation['receipt']=Json::encodeObject(receipt(true)->toArray());
                $operation['completed_at']=gmdate('Y-m-d H:i:s',$now+1);
            }
        }
        unset($operation);
        $second=$service->export($authority,(string)$first['next_cursor']);
        assertConversationExportResponseForTest($second);
        same(9,count($second['verified_cart_receipts']));
        same(false,$second['complete']);
        $page=$second;$guard=0;
        while(!$page['complete']&&$guard<20){
            $page=$service->export($authority,(string)$page['next_cursor']);
            assertConversationExportResponseForTest($page);
            same(0,count($page['verified_cart_receipts']));
            ++$guard;
        }
        same(true,$page['complete']);
        same(null,$page['next_cursor']);
    }finally{$wpdb=$previous;}
});

test('Conversation export completes empty and exact-multiple snapshots without phantom cursors', function (): void {
    global $wpdb;
    $previous=$wpdb;
    $now=time();
    $publicId='96c92b37-5f44-4a70-a0a7-575826250448';
    $sessionHash=str_repeat('a',64);
    $state=ConversationState::initial()->toArray();
    $row=array(
        'id'=>7,'public_id'=>$publicId,'access_hash'=>str_repeat('c',64),'session_hash'=>$sessionHash,
        'state'=>Json::encodeObject($state),'created_at'=>gmdate('Y-m-d H:i:s',$now-600),
        'updated_at'=>gmdate('Y-m-d H:i:s',$now-30),'expires_at'=>gmdate('Y-m-d H:i:s',$now+86400),
    );
    $authority=array(
        'id'=>7,'public_id'=>$publicId,'session_hash'=>$sessionHash,'state'=>$state,
        'created_at'=>$now-600,'updated_at'=>$now-30,'expires_at'=>$now+86400,
    );
    $makeService=static function(int $clock):ConversationPrivacyService{
        $transactions=new TransactionManager();
        return new ConversationPrivacyService(
            $transactions,
            new ConversationRepository(new Settings(),$transactions,new TurnLeaseManager(),new ActiveWorkInspector()),
            new ConversationExportCursor(static function()use($clock):int{return $clock;}),
            new ActiveWorkInspector()
        );
    };
    try{
        $wpdb=new ConversationPrivacyDatabase($row,array(),array());
        $empty=$makeService($now)->export($authority,null);
        assertConversationExportResponseForTest($empty);
        same(true,$empty['complete']);same(null,$empty['next_cursor']);
        same(array(),$empty['messages']);same(array(),$empty['verified_cart_receipts']);

        $messages=array();
        for($id=1;$id<=100;++$id){
            $messages[]=array(
                'id'=>$id,'role'=>'user','outcome'=>'','content'=>'m-'.$id,
                'payload'=>Json::encodeObject(privacyMessagePayloadForTest('user','m-'.$id)),
                'created_at'=>gmdate('Y-m-d H:i:s',$now-500+$id)
            );
        }
        $operations=array();
        for($id=1;$id<=50;++$id){
            $operations[]=array(
                'id'=>$id,'status'=>OperationStatus::VERIFIED,
                'receipt'=>Json::encodeObject(receipt(true)->toArray()),
                'completed_at'=>gmdate('Y-m-d H:i:s',$now-400+$id),
            );
        }
        $wpdb=new ConversationPrivacyDatabase($row,$messages,$operations);
        $exact=$makeService($now)->export($authority,null);
        assertConversationExportResponseForTest($exact);
        same(100,count($exact['messages']));same(50,count($exact['verified_cart_receipts']));
        same(false,$exact['complete']);
        $page=$exact;$guard=0;
        while(!$page['complete']&&$guard<10){
            $page=$makeService($now)->export($authority,(string)$page['next_cursor']);
            assertConversationExportResponseForTest($page);
            ++$guard;
        }
        same(true,$page['complete']);same(null,$page['next_cursor']);
    }finally{$wpdb=$previous;}
});

test('Conversation privacy blocks live authority but ignores crashed nonterminal rows after leases expire', function (): void {
    global $wpdb;
    $previous=$wpdb;
    $now=time();
    $publicId='96c92b37-5f44-4a70-a0a7-575826250448';
    $sessionHash=str_repeat('a',64);
    $state=ConversationState::initial()->toArray();
    $row=array(
        'id'=>7,'public_id'=>$publicId,'access_hash'=>str_repeat('c',64),'session_hash'=>$sessionHash,
        'state'=>Json::encodeObject($state),'created_at'=>gmdate('Y-m-d H:i:s',$now-600),
        'updated_at'=>gmdate('Y-m-d H:i:s',$now-30),'expires_at'=>gmdate('Y-m-d H:i:s',$now+86400),
    );
    $authority=array(
        'id'=>7,'public_id'=>$publicId,'session_hash'=>$sessionHash,'state'=>$state,
        'created_at'=>$now-600,'updated_at'=>$now-30,'expires_at'=>$now+86400,
    );
    $staleOperation=array(
        'id'=>1,'status'=>OperationStatus::EXECUTING,'receipt'=>null,'completed_at'=>null,
    );
    $service=static function()use($now):ConversationPrivacyService{
        $transactions=new TransactionManager();
        return new ConversationPrivacyService(
            $transactions,
            new ConversationRepository(new Settings(),$transactions,new TurnLeaseManager(),new ActiveWorkInspector()),
            new ConversationExportCursor(static function()use($now):int{return $now;}),
            new ActiveWorkInspector()
        );
    };
    try{
        $wpdb=new ConversationPrivacyDatabase($row,array(),array($staleOperation));
        $export=$service()->export($authority,null);
        assertConversationExportResponseForTest($export);
        same(true,$export['complete']);
        same(array('conversation_lease','commerce_lease','conversation'),array_slice($wpdb->lockReads,0,3));

        $wpdb=new ConversationPrivacyDatabase($row,array(),array($staleOperation),true,false);
        throws(\YassinStore\AiAssistant\Infrastructure\Database\ConversationPrivacyConflict::class,
            static function()use($service,$authority):void{$service()->export($authority,null);});
        same(array('conversation_lease'),$wpdb->lockReads,'A live turn must be rejected before the conversation row is locked.');

        $wpdb=new ConversationPrivacyDatabase($row,array(),array($staleOperation),false,true);
        throws(\YassinStore\AiAssistant\Infrastructure\Database\ConversationPrivacyConflict::class,
            static function()use($service,$authority):void{$service()->export($authority,null);});
        same(array('conversation_lease','commerce_lease'),$wpdb->lockReads,'Live commerce must be rejected before the conversation row is locked.');

        $privacySource=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/Database/ConversationPrivacyService.php');
        $admissionSource=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Application/Turn/TurnAdmission.php');
        $exportBody=substr($privacySource,(int)strpos($privacySource,'public function export'),(int)strpos($privacySource,'public function delete')-(int)strpos($privacySource,'public function export'));
        $deleteBody=substr($privacySource,(int)strpos($privacySource,'public function delete'),(int)strpos($privacySource,'private function authorityIdentity')-(int)strpos($privacySource,'public function delete'));
        ok(strpos($exportBody,'$this->assertIdle(')<strpos($exportBody,'$this->lockAuthority('));
        ok(strpos($deleteBody,'$this->assertIdle(')<strpos($deleteBody,'$this->lockAuthority('));
        ok(strpos($admissionSource,'$this->leases->assertCurrentForUpdate($lease)')<strpos($admissionSource,'$this->conversations->reloadForUpdate('));
    }finally{$wpdb=$previous;}
});

test('Global purge is lease-authoritative and cannot be wedged by abandoned row statuses', function (): void {
    global $wpdb;
    $previous=$wpdb;
    try{
        $gate=new ImmediateMaintenanceGate();
        $wpdb=new ConversationPurgeDatabase(false);
        (new ConversationMaintenanceRepository(
            new TransactionManager(),new Settings(),$gate,new ActiveWorkInspector()
        ))->purgeAll();
        same(1,$gate->calls);
        ok(in_array('COMMIT',$wpdb->queries,true));
        same(9,count(array_filter($wpdb->queries,static function(string $query):bool{
            return strpos($query,'DELETE FROM ')===0;
        })));

        $wpdb=new ConversationPurgeDatabase(true);
        throws(\YassinStore\AiAssistant\Infrastructure\Database\ConversationMaintenanceConflict::class,
            static function():void{
                (new ConversationMaintenanceRepository(
                    new TransactionManager(),new Settings(),new ImmediateMaintenanceGate(),new ActiveWorkInspector()
                ))->purgeAll();
            });
        ok(in_array('ROLLBACK',$wpdb->queries,true));
        same(0,count(array_filter($wpdb->queries,static function(string $query):bool{
            return strpos($query,'DELETE FROM ')===0;
        })));
    }finally{$wpdb=$previous;}
});

test('Minimal operational logging and best-effort uninstall are explicit', function (): void {
    $root=YSAI_PROJECT_ROOT;
    $logger=(string)file_get_contents($root.'/src/Infrastructure/WordPress/Logger.php');
    $boot=(string)file_get_contents($root.'/src/Presentation/Rest/Controller/BootController.php');
    $uninstall=(string)file_get_contents($root.'/uninstall.php');
    contains("(bool) \$this->settings->get('diagnostic_logging', 0) ? \$context : array()",$logger);
    contains("if (\$context === array())",$logger);
    contains("Boot request failed at phase ' . \$safePhase",$boot);
    foreach(array("'projection'","'capabilities'","'widget_config'","'response'") as $phase){
        contains($phase,$boot);
    }
    contains("\$runStage('ingress-options'",$uninstall);
    contains("\$runStage('assistant-tables'",$uninstall);
    contains("\$runStage('settings'",$uninstall);
    contains("\$runStage('recovery-key'",$uninstall);
});

test('WooCommerce compatibility policy separates accepted capability-gated versions from promotion evidence', function (): void {
    $path=YSAI_PROJECT_ROOT.'/config/woocommerce-compatibility.json';
    $decoded=json_decode((string)file_get_contents($path),true);
    ok(is_array($decoded));
    $policy=WooCommerceCompatibility::fromArray($decoded);
    same('10.9.4',$policy->minimum());
    same('11.0.0',$policy->maximumExclusive());
    same('10.9.4',$policy->testedUpTo());
    same(array('10.9.4'),$policy->promotionTestedVersions());
    same('6.9',$policy->wordpressMinimum());
    same('woocommerce-10.9-core-session-v1',$policy->runtimeContract());
    same(WooCommerceCompatibility::TOO_OLD,$policy->statusForVersion('10.9.3'));
    same(WooCommerceCompatibility::PROMOTION_TESTED,$policy->statusForVersion('10.9.4'));
    same(WooCommerceCompatibility::ADMITTED_UNPROMOTED,$policy->statusForVersion('10.9.5'));
    same(WooCommerceCompatibility::ADMITTED_UNPROMOTED,$policy->statusForVersion('10.10.0'));
    same(WooCommerceCompatibility::TOO_NEW,$policy->statusForVersion('11.0.0'));
    same(WooCommerceCompatibility::MALFORMED,$policy->statusForVersion('10.9.4-beta.1'));
    same(WooCommerceCompatibility::MISSING,$policy->statusForVersion(''));
    ok($policy->admitsVersion('10.99.99'));
    ok(!$policy->admitsVersion('11.0.0'));
    ok(!$policy->isPromotionTested('10.9.5'));
    $publicContract=json_decode((string)file_get_contents(YSAI_PROJECT_ROOT.'/config/public-api-contract.json'),true);
    $capabilityCodes=$publicContract['$defs']['cart_mutation_capability']['properties']['code']['enum']??array();
    ok(in_array('version_not_promotion_tested',$capabilityCodes,true));

    $invalid=$decoded;
    $invalid['tested_up_to']='10.9.5';
    throws(RuntimeException::class,static function () use ($invalid): void {
        WooCommerceCompatibility::fromArray($invalid);
    },'promotion evidence');
    $invalid=$decoded;
    $invalid['promotion_tested'][]='10.9.4';
    throws(RuntimeException::class,static function () use ($invalid): void {
        WooCommerceCompatibility::fromArray($invalid);
    },'duplicates');
});

test('WooCommerce private core layout is split behind one application-facing adapter', function (): void {
    $root=YSAI_PROJECT_ROOT;
    $wooRoot=$root.'/src/Infrastructure/WooCommerce';
    $adapterPath=$wooRoot.'/WooSessionInternalsAdapter.php';
    $internalsRoot=$wooRoot.'/Internals';
    $adapter=(string)file_get_contents($adapterPath);

    $collaborators=array(
        'WooCoreStructureProbe.php'=>array(
            'ReflectionProperty', "'_session_expiration'", "'_table'",
            "'WC_Session_Handler'", "'WC_Cart_Session'",
            'getNumberOfRequiredParameters', 'getNumberOfParameters', 'class_parents',
        ),
        'WooSessionStorageInternals.php'=>array(
            'WC_SESSION_CACHE_GROUP', "'woocommerce_sessions'",
            'workingSessionEntries', 'storedSessionMap', 'sessionTableName',
        ),
        'WooCartHookTopology.php'=>array(
            'sideWriterHooks', 'automaticTotalsHooks', 'assertMutationRuntime',
            'suppressAutomaticSave', 'suppressAutomaticTotals',
        ),
        'WooCartIdentityInternals.php'=>array(
            "'previous_customer_id'", 'CartTokenUtils', 'validCookieCustomerId',
            'queryWillCloneCurrentRequest', 'guardClonedOperationAuthority',
        ),
        'WooPersistentCartInternals.php'=>array(
            'persistentCartMetaKey', 'persistentCartProjection',
        ),
    );

    same(5,count($collaborators));
    foreach($collaborators as $file=>$authorities){
        $path=$internalsRoot.'/'.$file;
        ok(is_file($path),'Missing focused WooCommerce internals collaborator: '.$file);
        $source=(string)file_get_contents($path);
        foreach($authorities as $authority){contains($authority,$source);}
        ok(substr_count($source,"\n")<320,'WooCommerce internals collaborator is oversized: '.$file);
    }

    foreach(array(
        'WooCoreStructureProbe',
        'WooSessionStorageInternals',
        'WooCartHookTopology',
        'WooCartIdentityInternals',
        'WooPersistentCartInternals',
    ) as $collaborator){
        contains('use YassinStore\\AiAssistant\\Infrastructure\\WooCommerce\\Internals\\'.$collaborator.';',$adapter);
        contains('new '.$collaborator.'(',$adapter);
    }
    contains('$this->core->assertStaticCapabilities();',$adapter);
    contains('$this->storage->assertStaticCapabilities();',$adapter);
    contains('$this->identity->assertStaticCapabilities();',$adapter);
    contains('$this->hooks->assertMutationRuntime',$adapter);
    contains('$this->persistentCart->persistentCartProjection',$adapter);
    contains('$this->identity->guardClonedOperationAuthority',$adapter);
    ok(substr_count($adapter,"\n")<280,'Application-facing Woo internals adapter must remain a bounded delegator.');

    foreach(array(
        'ReflectionProperty', '_session_expiration', "'_table'", 'WC_SESSION_CACHE_GROUP',
        'woocommerce_sessions', 'previous_customer_id', 'CartTokenUtils',
        'WC_Session_Handler', 'WC_Cart_Session',
    ) as $privateAuthority){
        notContains($privateAuthority,$adapter,'Application-facing adapter still implements private WooCommerce mechanics: '.$privateAuthority);
    }

    $collaboratorNames=array(
        'WooCoreStructureProbe', 'WooSessionStorageInternals', 'WooCartHookTopology',
        'WooCartIdentityInternals', 'WooPersistentCartInternals',
    );
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $wooRoot,
        FilesystemIterator::SKIP_DOTS
    ));
    foreach($iterator as $file){
        if(!$file->isFile()||$file->getExtension()!=='php'){continue;}
        $path=$file->getPathname();
        $insideInternals=strpos($path,$internalsRoot.DIRECTORY_SEPARATOR)===0;
        if($path===$adapterPath||$insideInternals){continue;}
        $source=(string)file_get_contents($path);
        foreach(array(
            'ReflectionProperty', '_session_expiration', "'_table'", 'WC_SESSION_CACHE_GROUP',
            'woocommerce_sessions', 'previous_customer_id', 'CartTokenUtils',
            'WC_Session_Handler', 'WC_Cart_Session',
        ) as $forbidden){
            notContains($forbidden,$source,'WooCommerce private authority escaped its internals boundary: '.$path.' -> '.$forbidden);
        }
        foreach($collaboratorNames as $collaborator){
            notContains($collaborator,$source,'Application code depends on private WooCommerce collaborator: '.$path.' -> '.$collaborator);
        }
    }

    $session=(string)file_get_contents($wooRoot.'/WooSession.php');
    contains('WooSessionInternalsAdapter',$session);
    contains('$this->internals->workingSessionEntries',$session);
    contains('$this->internals->sessionExpiration',$session);
    contains('$this->internals->persistentCartProjection',$session);
    notContains('new ReflectionProperty',$session);

    $commerce=(string)file_get_contents($root.'/src/Infrastructure/Composition/CommerceStack.php');
    $kernel=(string)file_get_contents($root.'/src/Infrastructure/Composition/PluginKernel.php');
    contains('WooSessionInternalsAdapter $wooInternals',$commerce);
    contains('new WooSession($wooInternals)',$commerce);
    contains('new WooCartRequestFence($logger, $wooInternals)',$commerce);
    contains('if ($wooInternals->allowsVerifiedCartMutation())',$commerce);
    $promotionGate=strpos($commerce,'if ($wooInternals->allowsVerifiedCartMutation())');
    $fenceRegistration=strpos($commerce,'$requestFence->register();',(int)$promotionGate);
    $bootProjection=strpos($commerce,'$this->bootCart =', (int)$fenceRegistration);
    same(1,substr_count($commerce,'$requestFence->register();'));
    ok($promotionGate!==false&&$fenceRegistration!==false&&$bootProjection!==false
        &&$promotionGate<$fenceRegistration&&$fenceRegistration<$bootProjection,
        'An admitted but unpromoted WooCommerce release must not install mutation fencing hooks.');
    contains('WooSessionInternalsAdapter $wooInternals',$kernel);

    $plugin=(string)file_get_contents($root.'/src/Plugin.php');
    $activator=(string)file_get_contents($root.'/src/Lifecycle/Activator.php');
    contains('assertStaticCoreCapabilities()',$plugin);
    contains('assertStaticCoreCapabilities()',$activator);
    contains('assertVerifiedCartMutationVersion',$adapter);
    contains('allowsVerifiedCartMutation',$adapter);
    contains('سيظل تعديل السلة معطلاً',$plugin);
    notContains("version_compare((string) WC_VERSION",$plugin.$activator);
    notContains('YSAI_MIN_WOOCOMMERCE_VERSION',(string)file_get_contents($root.'/yassin-ai-assistant.php').$plugin.$activator);

    $lock=json_decode((string)file_get_contents($root.'/integration/version-lock.json'),true);
    same('6.9.4',$lock['wordpress']??'');
    same('6.9',$lock['wordpress_minimum']??'');
    same('8.3',$lock['php']??'');
    same('7.4',$lock['php_minimum']??'');
    same('10.9.4',$lock['woocommerce']??'');
});

test('WooCommerce capability probe accepts tested and structurally compatible future patches but rejects property and method drift', function (): void {
    if(!function_exists('exec')||PHP_OS_FAMILY==='Unknown'){
        ok(true);
        return;
    }
    foreach(array(
        'success'=>'status=ok mode=success version=10.9.4',
        'future'=>'status=ok mode=future version=10.9.9',
        'future_drift'=>'status=rejected mode=future_drift',
        'drift'=>'status=rejected mode=drift',
        'arity_drift'=>'status=rejected mode=arity_drift',
    ) as $mode=>$expected){
        $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(YSAI_TEST_ROOT.'/woocommerce-compatibility-probe.php').' '.escapeshellarg($mode).' 2>&1';
        $lines=array();$status=0;exec($command,$lines,$status);
        same(0,$status,implode("\n",$lines));
        contains($expected,implode("\n",$lines));
    }
});

test('WooCommerce dependency is enforced before composition while schema verification is deferred to protected entry points', function (): void {
    $root=YSAI_PROJECT_ROOT;
    $entry=(string)file_get_contents($root.'/yassin-ai-assistant.php');
    $plugin=(string)file_get_contents($root.'/src/Plugin.php');
    $dependency=strpos($plugin,"if (!class_exists('WooCommerce'))");
    $composition=strpos($plugin,'new PluginKernel');
    ok($dependency!==false && $composition!==false && $dependency<$composition);
    notContains('SchemaLifecycle::verifyRuntime()',$plugin,'Generic WordPress boot must not inspect the physical assistant schema.');
    contains("add_action('admin_notices', array(\$this, 'woocommerceMissingNotice'));\n            return;",$plugin);
    contains('Requires at least: 6.9',$entry);
    contains('WC requires at least: 10.9.4',$entry);
    notContains('YSAI_MIN_WOOCOMMERCE_VERSION',$entry.$plugin);
    notContains("version_compare((string) WC_VERSION",$plugin);
    contains('WooCommerceCompatibility::fromPluginContract()',$plugin);
    contains('new WooSessionInternalsAdapter($this->compatibility)',$plugin);
    contains("add_action('admin_notices', array(\$this, 'woocommerceVersionNotice'))",$plugin);
    contains("add_action('admin_notices', array(\$this, 'woocommerceCoreContractNotice'))",$plugin);
    contains("add_action('admin_notices', array(\$this, 'woocommerceUntestedVersionNotice'))",$plugin);

    $rest=(string)file_get_contents($root.'/src/Presentation/Rest/RestApi.php');
    contains('SchemaRuntimeGate',$rest);
    contains("'callback' => array(\$this, 'boot')",$rest);
    contains("'callback' => array(\$this, 'chat')",$rest);
    contains("'callback' => array(\$this, 'testConnection')",$rest);
    contains('$this->schema->blockedResponse()',$rest);

    $cleanup=(string)file_get_contents($root.'/src/Lifecycle/Cleanup.php');
    contains('if (!SchemaLifecycle::verifyRuntime())',$cleanup);

    $admin=(string)file_get_contents($root.'/src/Presentation/Admin/AdminPages.php');
    contains("add_action('load-' . \$hook, array(\$this, 'verifySchema'))",$admin);
    contains('SchemaLifecycle::verifyRuntime()',$admin);

    $schemaAdmin=(string)file_get_contents($root.'/src/Presentation/Admin/SchemaAdmin.php');
    contains("add_action('load-plugins.php', array(\$this, 'verifyRuntime'))",$schemaAdmin);
    notContains('snapshot(true)',$schemaAdmin,'Blocked notices must not trigger physical metadata scans on unrelated administrator pages.');

    $activator=(string)file_get_contents($root.'/src/Lifecycle/Activator.php');
    $multisite=strpos($activator,'self::rejectMultisite();');
    $require=strpos($activator,'self::requireWooCommerce();');
    $install=strpos($activator,'SchemaLifecycle::install()');
    $settings=strpos($activator,'get_option(Settings::OPTION_KEY');
    ok($multisite!==false && $require!==false && $settings!==false && $install!==false
        && $multisite<$require && $require<$install && $install<$settings);
    contains('ووردبريس متعدد المواقع غير مدعوم',$activator);
    contains('لا يدعم ووردبريس متعدد المواقع في الإصدار 1.0.0',$activator);
    contains("if (!class_exists('WooCommerce'))",$activator);
    contains('WooCommerceCompatibility::fromPluginContract()',$activator);
    contains('new WooSessionInternalsAdapter($compatibility)',$activator);
    notContains('YSAI_MIN_WOOCOMMERCE_VERSION',$activator);
    contains('إصدار WooCommerce غير مدعوم',$activator);
    contains('فشل تثبيت قاعدة بيانات المساعد',$activator);
    contains('يتطلب وكيل المبيعات الذكي لمتجر ياسين تثبيت WooCommerce وتفعيله قبل تفعيل الإضافة',$activator);
});

test('REST schema gate authorizes valid storage and emits a bounded 503 for proven drift', function (): void {
    global $wpdb;

    $definition=new SchemaDefinition('gate_ok_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $wpdb=new MutableSchemaDatabase($definition,schemaInspection($definition)->tables(),'gate_ok_');
    $GLOBALS['ysai_test_options']=array(
        SchemaLifecycle::SCHEMA_OPTION=>SchemaLifecycle::SCHEMA_VERSION,
        SchemaLifecycle::SCHEMA_STATUS_OPTION=>(new SchemaRuntimeProof())->readyStatus(
            $definition,
            SchemaRegistry::scopeKey(),
            SchemaLifecycle::SCHEMA_VERSION,
            time()
        ),
    );
    $gate=new SchemaRuntimeGate(apiResponderForTest());
    same(null,$gate->blockedResponse());
    same(1,count(array_filter($wpdb->queries,static function(string $sql):bool{
        return strpos($sql,'ysai_schema_canary')!==false;
    })));

    $damagedDefinition=new SchemaDefinition('gate_bad_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $damagedTables=schemaInspection($damagedDefinition)->tables();
    unset($damagedTables[$damagedDefinition->tableName('turns')]);
    $wpdb=new MutableSchemaDatabase($damagedDefinition,$damagedTables,'gate_bad_');
    $wpdb->canaryResult=0;
    $GLOBALS['ysai_test_options']=array(
        SchemaLifecycle::SCHEMA_OPTION=>SchemaLifecycle::SCHEMA_VERSION,
        SchemaLifecycle::SCHEMA_STATUS_OPTION=>(new SchemaRuntimeProof())->readyStatus(
            $damagedDefinition,
            SchemaRegistry::scopeKey(),
            SchemaLifecycle::SCHEMA_VERSION,
            time()
        ),
    );
    $response=$gate->blockedResponse();
    ok($response instanceof WP_REST_Response);
    same(503,$response->status);
    same(false,$response->data['ok']??null);
    same('database_schema_blocked',$response->data['code']??'');
    same('no-store, no-cache, must-revalidate, max-age=0',$response->headers['Cache-Control']??'');
});

test('Conversation administration fails closed per corrupt row instead of crashing the screen', function (): void {
    $source=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Presentation/Admin/AdminPages.php');
    contains('catch (\\Throwable $exception)',$source);
    contains("حالة مخزنة غير صالحة",$source);
    contains("clear_gemini_api_key",$source);
    contains("defined('YSAI_GEMINI_API_KEY')",$source);
    contains("disabled aria-disabled=\"true\"",$source);
    contains('يتطلب حذف المحادثات طلب POST.',$source);
});
