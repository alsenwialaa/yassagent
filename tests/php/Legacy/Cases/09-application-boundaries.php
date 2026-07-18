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

// Application boundary and orchestration ownership.
test('Application and domain dependency direction is enforced', function (): void {
    $root=YSAI_PROJECT_ROOT;
    $applicationFiles=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src/Application'));
    foreach($applicationFiles as $file){
        if(!$file->isFile() || substr($file->getFilename(),-4)!=='.php'){continue;}
        $source=(string)file_get_contents($file->getPathname());
        notContains('YassinStore\\AiAssistant\\Infrastructure',$source,$file->getPathname());
        notContains('YassinStore\\AiAssistant\\Support\\I18n',$source,$file->getPathname());
        ok(preg_match('/\b(?:wp_[A-Za-z0-9_]*|wc_[A-Za-z0-9_]*|WC|determine_locale|is_rtl|get_bloginfo|get_option|update_option|delete_option|current_time|home_url|admin_url|rest_url)\s*\(/',$source)!==1,
            'Application code calls an infrastructure runtime global: '.$file->getPathname());
    }
    $domainFiles=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src/Domain'));
    foreach($domainFiles as $file){
        if(!$file->isFile() || substr($file->getFilename(),-4)!=='.php'){continue;}
        $source=(string)file_get_contents($file->getPathname());
        notContains('YassinStore\\AiAssistant\\Application',$source,$file->getPathname());
        notContains('YassinStore\\AiAssistant\\Infrastructure',$source,$file->getPathname());
        notContains('YassinStore\\AiAssistant\\Support\\I18n',$source,$file->getPathname());
        ok(preg_match('/\b(?:wp_[A-Za-z0-9_]*|wc_[A-Za-z0-9_]*|WC|determine_locale|is_rtl|get_bloginfo|get_option|update_option|delete_option|current_time|home_url|admin_url|rest_url)\s*\(/',$source)!==1,
            'Domain code calls an infrastructure runtime global: '.$file->getPathname());
    }
});
test('Shared utilities imported by application or domain are framework independent', function (): void {
    $root=YSAI_PROJECT_ROOT;
    $imported=array();
    foreach(array($root.'/src/Application',$root.'/src/Domain') as $layer){
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($layer));
        foreach($files as $file){
            if(!$file->isFile() || substr($file->getFilename(),-4)!=='.php'){continue;}
            $source=(string)file_get_contents($file->getPathname());
            if(preg_match_all('/^use YassinStore\\\\AiAssistant\\\\Support\\\\([A-Za-z0-9_]+);$/m',$source,$matches)!==false){
                foreach($matches[1] as $name){$imported[(string)$name]=true;}
            }
        }
    }
    ok($imported!==array(),'Expected application/domain shared utility imports.');
    foreach(array_keys($imported) as $name){
        $path=$root.'/src/Support/'.$name.'.php';
        ok(is_file($path),'Missing imported shared utility '.$name);
        $source=(string)file_get_contents($path);
        ok(preg_match('/\\b(?:wp_[A-Za-z0-9_]*|wc_[A-Za-z0-9_]*|WC|determine_locale|is_rtl|get_bloginfo|get_option|update_option|delete_option|current_time|home_url|admin_url|rest_url)\\s*\\(/',$source)!==1,
            'Inner-layer shared utility calls a WordPress/WooCommerce runtime global: '.$path);
        notContains('YassinStore\\AiAssistant\\Application',$source,$path);
        notContains('YassinStore\\AiAssistant\\Infrastructure',$source,$path);
        notContains('YassinStore\\AiAssistant\\Presentation',$source,$path);
    }
    notContains('wp_json_encode',(string)file_get_contents($root.'/src/Support/Json.php'));
    notContains('wp_generate_uuid4',(string)file_get_contents($root.'/src/Support/Uuid.php'));
});
test('Native JSON and UUID utilities preserve strict inner-layer contracts', function (): void {
    same('{"value":1.0,"text":"مرحبا"}',Json::encodeObject(array('value'=>1.0,'text'=>'مرحبا')));
    $uuid=Uuid::v4();
    ok(Uuid::isV4($uuid));
    same(strtolower($uuid),$uuid);
});
test('All runtime classes and application ports load without signature conflicts', function (): void {
    $root=YSAI_PROJECT_ROOT;
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src'));
    foreach($files as $file){
        if(!$file->isFile() || substr($file->getFilename(),-4)!=='.php'){continue;}
        $relative=substr($file->getPathname(),strlen($root.'/src/'),-4);
        $symbol='YassinStore\\AiAssistant\\'.str_replace(DIRECTORY_SEPARATOR,'\\',$relative);
        ok(class_exists($symbol) || interface_exists($symbol),'Unable to load '.$symbol);
    }
});
test('Diagnostic log context recursively removes credentials contacts and oversized values', function (): void {
    $safe=(new LogContextSanitizer())->sanitize(array(
        'api_key'=>'AIza'.str_repeat('A',30),
        'nested'=>array(
            'authorization'=>'Bearer abcdefghijklmnopqrstuvwxyz',
            'customer'=>'customer@example.com / +967 771 234 567',
            'session_token'=>'eyJ'.str_repeat('a',20).'.'.str_repeat('b',40),
        ),
        'upstream_message'=>'Rejected customer@example.com using sk-'.str_repeat('x',24),
        'long'=>str_repeat('z',700),
    ));
    same('[redacted]',$safe['api_key']);
    same('[redacted]',$safe['nested']['authorization']);
    same('[redacted]',$safe['nested']['session_token']);
    contains('[redacted-email]',$safe['nested']['customer']);
    contains('[redacted-phone]',$safe['nested']['customer']);
    contains('[redacted-email]',$safe['upstream_message']);
    contains('[redacted-secret]',$safe['upstream_message']);
    contains('[truncated]',$safe['long']);
});

test('Provider errors REST guards retention uninstall catalog and packaging keep their corrected boundaries', function (): void {
    $root=YSAI_PROJECT_ROOT;
    $transport=(string)file_get_contents($root.'/src/Infrastructure/Gemini/GeminiTransport.php');
    notContains('upstream_message',$transport);
    notContains("\$error['message']",$transport);

    $guard=(string)file_get_contents($root.'/src/Infrastructure/Security/RequestGuard.php');
    $rest=(string)file_get_contents($root.'/src/Presentation/Rest/RestApi.php');
    notContains('new WP_Error',$guard);
    contains('publicRejection',$guard); contains('adminRejection',$guard);
    contains('$this->responses->error(',$rest);
    contains('$blocked = $this->publicBlocked($request);',$rest);

    $conversations=(string)file_get_contents($root.'/src/Infrastructure/Database/ConversationRepository.php');
    $maintenance=(string)file_get_contents($root.'/src/Infrastructure/Database/ConversationMaintenanceRepository.php');
    $admin=(string)file_get_contents($root.'/src/Presentation/Admin/AdminPages.php');
    contains('AND expires_at > %s AND updated_at > %s',$conversations);
    contains('WHERE (expires_at < %s OR updated_at < %s)',$maintenance);
    contains('public function shortenRetention(int $days): void',$maintenance);
    contains("'update_option_' . Settings::OPTION_KEY",$admin);

    $uninstall=(string)file_get_contents($root.'/uninstall.php');
    contains('IngressRateLimiter::deleteAll();',$uninstall);
    $ingress=(string)file_get_contents($root.'/src/Infrastructure/Security/IngressRateLimiter.php');
    contains('public static function deleteAll(): void',$ingress);

    $catalog=(string)file_get_contents($root.'/src/Infrastructure/WooCommerce/ProductCatalog.php');
    $catalogFilter=(string)file_get_contents($root.'/src/Infrastructure/WooCommerce/Discovery/CatalogQueryFilter.php');
    contains("\$this->filters->apply(\$queryArgs, \$args, 'search')",$catalog);
    contains("\$this->filters->apply(\$queryArgs, \$args, 'catalog')",$catalog);
    contains("\$visibilityKey = \$context === 'search'",$catalogFilter);
    contains('for ($page = 1; $page <= 4 && count($rows) < $limit; ++$page)',$catalog);
    contains("\$pool = min(48, max(12, \$limit * 4));\n        \$ids = wc_get_related_products",$catalog);

    $lock=Json::decodeRequiredObject((string)file_get_contents($root.'/package-lock.json'),'package lock');
    same('2.3.2',$lock['packages']['node_modules/playwright/node_modules/fsevents']['version']??'');
    $gate=(string)file_get_contents($root.'/scripts/quality-gate.sh');
    $package=(string)file_get_contents($root.'/scripts/package.py');
    $archiveVerifier=(string)file_get_contents($root.'/scripts/quality/verify-release-archives.py');
    contains('cmp -s "$archive" "$release_b/$name"',$gate);
    contains('verify-release-archives.py',$gate);
    contains('archive.testzip()',$archiveVerifier);
    contains('Production/source byte mismatch',$archiveVerifier);
    contains('is_sensitive_environment_path',$package);
    contains('path.is_symlink()',$package);
    contains('Release source contains a symbolic link',$gate);
    contains('Sensitive environment file leaked into',$archiveVerifier);
    contains('Unexpected top-level member in',$archiveVerifier);
});

test('Declared WordPress and WooCommerce floors have no dead core-function fallback branches', function (): void {
    $root=YSAI_PROJECT_ROOT.'/src/';
    $files=array(
        'Infrastructure/WordPress/ContentRepository.php',
        'Infrastructure/WooCommerce/Cart/CartSnapshotFactory.php',
        'Infrastructure/WooCommerce/Cart/CartProductPolicy.php',
        'Infrastructure/WooCommerce/Discovery/CatalogQueryFilter.php',
        'Infrastructure/WooCommerce/PlainMoneyFormatter.php',
        'Infrastructure/WooCommerce/ProductCatalog.php',
        'Infrastructure/WooCommerce/Projection/DisplayPriceProjection.php',
        'Infrastructure/WooCommerce/Projection/CatalogVisibilityPolicy.php',
        'Infrastructure/WooCommerce/Projection/ProductSnapshotFactory.php',
        'Infrastructure/WooCommerce/Projection/StorefrontImage.php',
        'Infrastructure/WordPress/Settings.php',
    );
    $source='';
    foreach($files as $file){$source.=(string)file_get_contents($root.$file)."\n";}
    foreach(array(
        'get_woocommerce_currency','wc_get_product_visibility_term_ids','wc_price',
        'wp_strip_all_tags','wc_get_product_id_by_sku','wc_get_related_products',
        'wc_get_price_to_display','wc_tax_enabled','get_option','get_ancestors',
        'get_term','apply_filters','add_settings_error',
    ) as $function){
        notContains("function_exists('".$function."')",$source);
    }
    foreach(array('has_enough_stock','get_stock_managed_by_id','is_visible') as $method){
        notContains("method_exists(\$product, '".$method."')",$source);
        notContains("method_exists(\$target, '".$method."')",$source);
        notContains("method_exists(\$parent, '".$method."')",$source);
    }
    contains('return $this->isolate($this->plain((string) wc_price(',$source);
    contains('$visibility = wc_get_product_visibility_term_ids();',$source);
    contains('$relatedIds = wc_get_related_products($productId, $pool);',$source);
});

test('Runtime readiness invalidates stale proof first and proves every state write', function (): void {
    global $wpdb;
    $wpdb=new YsaiTestAdvisoryLockDatabase();
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array('gemini_api_key'=>'key')));
    $GLOBALS['ysai_test_option_write_failures']=array();
    $GLOBALS['ysai_test_option_delete_failures']=array();
    $readiness=runtimeReadinessForTest(new Settings());
    $attempt=$readiness->beginCheck();
    same(false,$readiness->isReady());
    same('runtime_check_in_progress',$readiness->status()['code']);
    $readiness->markReady($attempt);
    same(true,$readiness->isReady());
    same('ready',$readiness->status()['code']);
    $readiness->invalidate('authentication_error');
    same(false,$readiness->isReady());
    same('authentication_error',$readiness->status()['code']);

    $attempt=$readiness->beginCheck();
    $GLOBALS['ysai_test_option_write_failures'][GeminiRuntimeReadiness::OPTION_KEY]=true;
    try {
        throws(RuntimeException::class,function () use ($readiness,$attempt): void {
            $readiness->markReady($attempt);
        },'persist');
        same(false,$readiness->isReady());
        $retained=get_option(GeminiRuntimeReadiness::OPTION_KEY,array());
        same($attempt,$retained['check_attempt_id']??'');
        same(0,$retained['proof_checked_at']??-1);
    } finally {
        $GLOBALS['ysai_test_option_write_failures']=array();
        $GLOBALS['ysai_test_option_delete_failures']=array();
    }
});

test('Composition root wires the refactored graph without constructor mismatch', function (): void {
    $GLOBALS['ysai_test_actions']=array();
    $GLOBALS['ysai_test_filters']=array();
    $GLOBALS['ysai_test_options'][RecoveryKey::OPTION]=str_repeat('a',64);
    $settings=new Settings();
    $kernel=new \YassinStore\AiAssistant\Infrastructure\Composition\PluginKernel(
        $settings,
        new \YassinStore\AiAssistant\Infrastructure\WordPress\Logger($settings),
        WooSessionInternalsAdapter::forInstalledWooCommerce()
    );
    $kernel->register();
    ok(isset($GLOBALS['ysai_test_actions']['rest_api_init']));
    ok(isset($GLOBALS['ysai_test_actions']['ysai_daily_cleanup']));
    ok(isset($GLOBALS['ysai_test_actions']['ysai_cleanup_continue']));
    ok(isset($GLOBALS['ysai_test_actions']['admin_init']));
    ok(isset($GLOBALS['ysai_test_actions']['admin_menu']));
    unset($GLOBALS['ysai_test_options'][RecoveryKey::OPTION]);
});
test('Assistant session issue renewal and validation require no WooCommerce runtime', function (): void {
    $hadWoo=array_key_exists('ysai_test_wc',$GLOBALS);
    $previousWoo=$GLOBALS['ysai_test_wc']??null;
    $hadLoader=array_key_exists('ysai_test_wc_load_cart',$GLOBALS);
    $previousLoader=$GLOBALS['ysai_test_wc_load_cart']??null;
    $loads=0;
    try {
        $GLOBALS['ysai_test_wc']=null;
        $GLOBALS['ysai_test_wc_load_cart']=static function () use (&$loads): void {
            ++$loads;
            throw new RuntimeException('Assistant continuity must not initialize WooCommerce.');
        };

        $tokens=new SessionTokenService(new MemoryBrowserContinuityAuthority());
        $continuitySecret=str_repeat('A',43);
        $issued=$tokens->issue($continuitySecret,'');
        $sessionHash=(string)$issued['session_hash'];
        same($sessionHash,$tokens->validateTransport((string)$issued['token']));
        $tokens->assertActive((string)$issued['token'],$sessionHash);
        same($sessionHash,(string)$tokens->issue($continuitySecret,'')['session_hash']);
        same(0,$loads);

        throws(RuntimeException::class,static function () use ($tokens,$issued): void {
            $tokens->assertActive((string)$issued['token'],str_repeat('f',64));
        },'transport identity changed');

        $source=(string)file_get_contents(
            YSAI_PROJECT_ROOT.'/src/Infrastructure/Security/SessionTokenService.php'
        );
        notContains('WooSession',$source);
        notContains('WC()',$source);
        notContains('wc_load_cart',$source);
        notContains('customerSubject',$source);
    } finally {
        if($hadLoader){$GLOBALS['ysai_test_wc_load_cart']=$previousLoader;}else{unset($GLOBALS['ysai_test_wc_load_cart']);}
        if($hadWoo){$GLOBALS['ysai_test_wc']=$previousWoo;}else{unset($GLOBALS['ysai_test_wc']);}
    }
});

test('Browser bearer rotation revokes old authority and exact lost-response replay converges', function (): void {
    $secretA=str_repeat('A',43);
    $secretB=rtrim(strtr(base64_encode(str_repeat("\x01",32)),'+/','-_'),'=');
    $secretC=rtrim(strtr(base64_encode(str_repeat("\x02",32)),'+/','-_'),'=');

    $authorities=new MemoryBrowserContinuityAuthority();
    $tokens=new SessionTokenService($authorities);
    $first=$tokens->issue($secretA,'');
    $same=$tokens->issue($secretA,'');
    same($first['session_hash'],$same['session_hash']);

    $rotated=$tokens->issue($secretB,$secretA);
    ok($rotated['session_hash']!==$first['session_hash']);
    same($first['session_hash'],$tokens->validateTransport((string)$first['token']));
    throws(RuntimeException::class,static function()use($tokens,$first):void{
        $tokens->assertActive((string)$first['token'],(string)$first['session_hash']);
    },'not active');
    same($rotated['session_hash'],$tokens->validateTransport((string)$rotated['token']));
    $tokens->assertActive((string)$rotated['token'],(string)$rotated['session_hash']);

    // The exact A -> B transition is idempotent after a lost response. No
    // alternative successor or reverse resurrection is permitted.
    same($rotated['session_hash'],$tokens->issue($secretB,$secretA)['session_hash']);
    throws(RuntimeException::class,static function()use($tokens,$secretA):void{
        $tokens->issue($secretA,'');
    },'revoked');
    throws(RuntimeException::class,static function()use($tokens,$secretA,$secretC):void{
        $tokens->issue($secretC,$secretA);
    },'revoked');
    throws(RuntimeException::class,static function()use($tokens,$secretA,$secretB):void{
        $tokens->issue($secretA,$secretB);
    },'reused');
});

test('Lost browser rotation response converges after revoked predecessor cleanup', function (): void {
    $secretA=rtrim(strtr(base64_encode(str_repeat("\x11",32)),'+/','-_'),'=');
    $secretB=rtrim(strtr(base64_encode(str_repeat("\x12",32)),'+/','-_'),'=');
    $authorities=new MemoryBrowserContinuityAuthority();
    $tokens=new SessionTokenService($authorities);

    $tokens->issue($secretA,'');
    $rotated=$tokens->issue($secretB,$secretA);
    same(true,$authorities->deleteOneRevokedPredecessor());

    // The first rotation committed but its response was lost. Even when the
    // bounded cleanup pass has removed A, retrying the exact A -> B request
    // must recover B's existing authority rather than fork the browser.
    $replayed=$tokens->issue($secretB,$secretA);
    same($rotated['session_hash'],$replayed['session_hash']);
    $tokens->assertActive((string)$replayed['token'],(string)$replayed['session_hash']);
});

test('Missing prior browser authority cannot bootstrap an unproved rotation', function (): void {
    $secretA=rtrim(strtr(base64_encode(str_repeat("\x03",32)),'+/','-_'),'=');
    $secretB=rtrim(strtr(base64_encode(str_repeat("\x04",32)),'+/','-_'),'=');
    $authorities=new MemoryBrowserContinuityAuthority();
    $tokens=new SessionTokenService($authorities);

    throws(RuntimeException::class,static function()use($tokens,$secretA,$secretB):void{
        $tokens->issue($secretB,$secretA);
    },'missing');

    $repository=(string)file_get_contents(
        YSAI_PROJECT_ROOT.'/src/Infrastructure/Database/BrowserContinuityAuthorityRepository.php'
    );
    contains('if ($previous === null)',$repository);
    contains('Previous browser continuity authority is missing.',$repository);
    $previousMissing=strpos($repository,'if ($previous === null)');
    $existingSuccessor=strpos($repository,'if ($next !== null)',$previousMissing);
    $missingFailure=strpos(
        $repository,
        'Previous browser continuity authority is missing.',
        $previousMissing
    );
    ok(is_int($existingSuccessor) && is_int($missingFailure)
        && $existingSuccessor < $missingFailure);
    notContains('authority reset',$repository);
});

test('Lost browser storage without rotation proof receives a distinct independent authority', function (): void {
    $secretA=rtrim(strtr(base64_encode(str_repeat("\x0a",32)),'+/','-_'),'=');
    $secretB=rtrim(strtr(base64_encode(str_repeat("\x0b",32)),'+/','-_'),'=');
    $authorities=new MemoryBrowserContinuityAuthority();
    $tokens=new SessionTokenService($authorities);
    $first=$tokens->issue($secretA,'');
    $replacement=$tokens->issue($secretB,'');
    ok($first['session_hash']!==$replacement['session_hash']);
    $tokens->assertActive((string)$first['token'],(string)$first['session_hash']);
    $tokens->assertActive((string)$replacement['token'],(string)$replacement['session_hash']);

    $repository=(string)file_get_contents(
        YSAI_PROJECT_ROOT.'/src/Infrastructure/Database/BrowserContinuityAuthorityRepository.php'
    );
    notContains('recoverBoundSession',$repository);
    notContains('lockOptionalByNonce',$repository);
    notContains('boundSessionNonce',$repository);
});

test('Admission UUID never becomes session or conversation bearer authority', function (): void {
    $root=YSAI_PROJECT_ROOT.'/src';
    $sessions=(string)file_get_contents($root.'/Infrastructure/Security/SessionTokenService.php');
    $conversations=(string)file_get_contents($root.'/Infrastructure/Database/ConversationRepository.php');
    $schema=(string)file_get_contents($root.'/Infrastructure/Database/SchemaDefinition.php');
    $boot=(string)file_get_contents($root.'/Presentation/Rest/Controller/BootController.php');
    notContains('clientInstanceId',$sessions);
    notContains('client_instance_id',$conversations);
    notContains("'client_instance_id' => \$this->column",$schema);
    notContains('WooSession',$sessions);
    notContains('WC()',$sessions);
    notContains('boundSessionNonce',$sessions);
    contains("(string) \$input['previous_browser_continuity_secret']",$boot);
    contains("\$this->leases->acquire('boot|' . \$sessionHash",$boot);
    contains('BrowserContinuitySecret::isValid',$sessions);
});

test('Boot authenticates supplied conversation before any create-or-resume path', function (): void {
    $boot=(string)file_get_contents(
        YSAI_PROJECT_ROOT.'/src/Presentation/Rest/Controller/BootController.php'
    );
    $credentialBranch=strpos($boot,"if (\$input['conversation_id'] !== '')");
    $resume=strpos($boot,'$this->conversations->resume(', (int)$credentialBranch);
    $invalid=strpos($boot,"'conversation_invalid'", (int)$resume);
    $lease=strpos($boot,"\$this->leases->acquire('boot|' . \$sessionHash", (int)$invalid);
    $create=strpos($boot,'$this->conversations->createOrResume(', (int)$lease);
    ok(
        $credentialBranch!==false&&$resume!==false&&$invalid!==false&&$lease!==false&&$create!==false
        &&$credentialBranch<$resume&&$resume<$invalid&&$invalid<$lease&&$lease<$create,
        'A supplied conversation must be authenticated without first creating an orphan mapping.'
    );
    $resumeBranch=substr($boot,$credentialBranch,$lease-$credentialBranch);
    notContains('createOrResume(',$resumeBranch);
});

test('Infrastructure adapters satisfy application ports', function (): void {
    $pairs=array(
        'YassinStore\\AiAssistant\\Infrastructure\\Database\\TransactionManager'=>'YassinStore\\AiAssistant\\Application\\Port\\TransactionPort',
        'YassinStore\\AiAssistant\\Infrastructure\\Database\\BrowserContinuityAuthorityRepository'=>'YassinStore\\AiAssistant\\Application\\Port\\BrowserContinuityAuthorityPort',
        'YassinStore\\AiAssistant\\Infrastructure\\Concurrency\\TurnLeaseManager'=>'YassinStore\\AiAssistant\\Application\\Port\\TurnLeasePort',
        'YassinStore\\AiAssistant\\Infrastructure\\Database\\ConversationRepository'=>'YassinStore\\AiAssistant\\Application\\Port\\ConversationStorePort',
        'YassinStore\\AiAssistant\\Infrastructure\\Database\\MessageRepository'=>'YassinStore\\AiAssistant\\Application\\Port\\MessageStorePort',
        'YassinStore\\AiAssistant\\Infrastructure\\Database\\TurnRepository'=>'YassinStore\\AiAssistant\\Application\\Port\\TurnStorePort',
        'YassinStore\\AiAssistant\\Infrastructure\\Security\\RateLimiter'=>'YassinStore\\AiAssistant\\Application\\Port\\RateLimiterPort',
        'YassinStore\\AiAssistant\\Infrastructure\\Gemini\\GeminiCartIntentVerifier'=>'YassinStore\\AiAssistant\\Application\\Port\\CartIntentVerifierPort',
        'YassinStore\\AiAssistant\\Infrastructure\\Security\\SecretFingerprint'=>'YassinStore\\AiAssistant\\Application\\Port\\FingerprintPort',
        'YassinStore\\AiAssistant\\Infrastructure\\WooCommerce\\ProductCatalog'=>'YassinStore\\AiAssistant\\Application\\Port\\ProductCatalogPort',
        'YassinStore\\AiAssistant\\Infrastructure\\WooCommerce\\Cart\\CartQueryService'=>'YassinStore\\AiAssistant\\Application\\Port\\CartQueryPort',
        'YassinStore\\AiAssistant\\Infrastructure\\WooCommerce\\Cart\\CartOperationCoordinator'=>'YassinStore\\AiAssistant\\Application\\Port\\CartMutationPort',
        'YassinStore\\AiAssistant\\Infrastructure\\WordPress\\ContentRepository'=>'YassinStore\\AiAssistant\\Application\\Port\\ContentRepositoryPort',
        'YassinStore\\AiAssistant\\Infrastructure\\WordPress\\Logger'=>'YassinStore\\AiAssistant\\Application\\Port\\LoggerPort',
        'YassinStore\\AiAssistant\\Infrastructure\\WordPress\\Settings'=>'YassinStore\\AiAssistant\\Application\\Port\\RuntimeSettingsPort',
        'YassinStore\\AiAssistant\\Infrastructure\\System\\SystemClock'=>'YassinStore\\AiAssistant\\Application\\Port\\ClockPort',
        'YassinStore\\AiAssistant\\Infrastructure\\WordPress\\ArabicText'=>'YassinStore\\AiAssistant\\Application\\Port\\TextLocalizerPort',
        'YassinStore\\AiAssistant\\Infrastructure\\WooCommerce\\Cart\\CartSnapshotFactory'=>'YassinStore\\AiAssistant\\Application\\Port\\CartSnapshotProviderPort',
        'YassinStore\\AiAssistant\\Infrastructure\\WooCommerce\\Cart\\BootCartSnapshot'=>'YassinStore\\AiAssistant\\Application\\Port\\CartSnapshotProviderPort',
        'YassinStore\\AiAssistant\\Infrastructure\\WooCommerce\\Cart\\CartProtectedReadScope'=>'YassinStore\\AiAssistant\\Application\\Port\\CartSnapshotProviderPort',
    );
    foreach($pairs as $adapter=>$port){ok(is_subclass_of($adapter,$port),$adapter.' does not implement '.$port);}
    ok(is_subclass_of('YassinStore\\AiAssistant\\Infrastructure\\Gemini\\GeminiException','YassinStore\\AiAssistant\\Application\\Ai\\ModelGatewayException'));
});
test('Durable cart implementation replaces the obsolete coarse mutation path', function (): void {
    $root=YSAI_PROJECT_ROOT;
    foreach(array(
        '/src/Application/Commerce/CartMutationStateMachine.php',
        '/src/Application/Commerce/CartOperationJournal.php',
        '/src/Application/Commerce/CartOperationExecutor.php',
        '/src/Application/Commerce/CartOperationRecovery.php',
        '/src/Infrastructure/WooCommerce/Cart/CartPlanApplier.php',
        '/src/Infrastructure/WooCommerce/Cart/CartRestorer.php',
    ) as $file){ok(!is_file($root.$file),$file.' must be removed');}
    foreach(array(
        '/src/Infrastructure/WooCommerce/Cart/CartOperationCoordinator.php',
        '/src/Infrastructure/WooCommerce/Cart/CartRecoveryCoordinator.php',
        '/src/Infrastructure/WooCommerce/Cart/CartStepPlanner.php',
        '/src/Infrastructure/WooCommerce/Cart/CartStepVerifier.php',
        '/src/Infrastructure/WooCommerce/Cart/WooSessionCartStore.php',
        '/src/Infrastructure/WooCommerce/Cart/WooPersistentCartStore.php',
    ) as $file){ok(is_file($root.$file),$file);}
});
