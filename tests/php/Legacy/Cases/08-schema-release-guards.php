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

// Exact schema lifecycle and release guards.
test('Exact database definition validates a complete physical schema', function (): void {
    $definition=new SchemaDefinition('wp_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $result=(new SchemaValidator())->validate($definition, schemaInspection($definition));
    ok($result->isValid()); same(array(),$result->issues()); same(9,count($definition->tableNames()));
});
test('Public health response composes schema and cached runtime readiness without traffic proof', function (): void {
    $source=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Presentation/Rest/Controller/HealthController.php');
    $projector=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Presentation/Rest/HealthResponseProjector.php');
    contains('$this->responses->health($this->projector->project(',$source);
    contains('SchemaLifecycle::verifyRuntime() && $this->readiness->isReady()',$source);
    contains("'assistant_ready' => \$assistantReady",$projector);
    notContains('BootRuntimeProof',$source);
    notContains('update_option(',$source);
    notContains('delete_option(',$source);
    notContains('woocommerce_ready',$source);
    notContains('assistant_status',$source);
    notContains('database_status',$source);
    notContains('SchemaDiagnostics',$source);

    $contract=(string)file_get_contents(YSAI_PROJECT_ROOT.'/REST-CONTRACT.md');
    notContains('woocommerce_ready',$contract);
    contains('Detailed model and database diagnostics remain administrator-only.',$contract);
});

test('Ordinary boot and health contain no shopper-traffic readiness proof', function (): void {
    $root=YSAI_PROJECT_ROOT.'/src';
    ok(!is_file($root.'/Infrastructure/Runtime/BootRuntimeProof.php'));
    $boot=(string)file_get_contents($root.'/Presentation/Rest/Controller/BootController.php');
    $health=(string)file_get_contents($root.'/Presentation/Rest/Controller/HealthController.php');
    foreach(array($boot,$health) as $source){
        notContains('BootRuntimeProof',$source);
        notContains('GeminiRuntimeReadiness::OPTION_KEY',$source);
        notContains('update_option(',$source);
        notContains('add_option(',$source);
        notContains('delete_option(',$source);
    }
    contains("'chat_ready' => \$this->readiness->isReady()",$boot);
    contains('$this->readiness->isReady()',$health);
});

test('Provider readiness lifecycle is independent from shopper boot deactivation and schema repair', function (): void {
    $root=YSAI_PROJECT_ROOT.'/src';
    $boot=(string)file_get_contents($root.'/Presentation/Rest/Controller/BootController.php');
    $activator=(string)file_get_contents($root.'/Lifecycle/Activator.php');
    $deactivator=(string)file_get_contents($root.'/Lifecycle/Deactivator.php');
    $schema=(string)file_get_contents($root.'/Infrastructure/Database/SchemaLifecycle.php');
    $uninstall=(string)file_get_contents(YSAI_PROJECT_ROOT.'/uninstall.php');
    contains('GeminiRuntimeReadiness::deleteState()',$activator);
    contains('GeminiRuntimeReadiness::deleteState()',$uninstall);
    notContains('GeminiRuntimeReadiness',$boot);
    notContains('GeminiRuntimeReadiness',$deactivator);
    notContains('GeminiRuntimeReadiness',$schema);
    notContains('BootRuntimeProof',$boot.$activator.$deactivator.$schema.$uninstall);
});

test('Uncommitted turn cart projection failure never claims the request was saved', function (): void {
    $chat=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Presentation/Rest/Controller/ChatController.php');
    contains('$cartNotice = $committed',$chat);
    contains('لم يتم تثبيت هذا الطلب، وتعذر أيضاً تحديث ملخص السلة.',$chat);
});
test('Database definition supports a valid empty WordPress prefix and emits exact table options', function (): void {
    $definition=new SchemaDefinition('', 'utf8mb4', 'utf8mb4_unicode_ci');
    same('ysai_conversations',$definition->tableName('conversations'));
    $sql=implode("\n",$definition->createStatements());
    contains('ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',$sql);
});
test('Database validation ignores MySQL default-generated metadata noise', function (): void {
    $definition=new SchemaDefinition('wp_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $tables=schemaInspection($definition)->tables(); $name=$definition->tableName('conversations');
    $tables[$name]['columns']['state']['extra']='DEFAULT_GENERATED';
    ok((new SchemaValidator())->validate($definition,new SchemaInspection($tables))->isValid());
});
test('Database validation detects missing storage even when version metadata could be current', function (): void {
    $definition=new SchemaDefinition('wp_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $inspection=schemaInspection($definition); $tables=$inspection->tables();
    unset($tables[$definition->tableName('turns')]);
    $result=(new SchemaValidator())->validate($definition,new SchemaInspection($tables));
    ok(!$result->isValid()); contains('missing_table:wp_ysai_turns',implode(',', $result->issues()));
});
test('Database validation rejects obsolete columns indexes and wrong engine', function (): void {
    $definition=new SchemaDefinition('wp_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $tables=schemaInspection($definition)->tables(); $name=$definition->tableName('conversations');
    $tables[$name]['engine']='MyISAM';
    $tables[$name]['columns']['legacy_alias']=array('type'=>'varchar(10)','nullable'=>true,'default'=>null,'extra'=>'');
    $tables[$name]['indexes']['legacy_alias']=array('unique'=>false,'columns'=>array('legacy_alias'));
    $result=(new SchemaValidator())->validate($definition,new SchemaInspection($tables));
    $issues=implode(',', $result->issues());
    ok(!$result->isValid()); contains('engine_mismatch:'.$name,$issues); contains('unexpected_column:'.$name.'.legacy_alias',$issues); contains('unexpected_index:'.$name.'.legacy_alias',$issues);
});
test('Database validation rejects per-column collation and index-prefix drift', function (): void {
    $definition=new SchemaDefinition('wp_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $tables=schemaInspection($definition)->tables(); $name=$definition->tableName('conversations');
    $tables[$name]['columns']['state']['collation']='utf8mb4_general_ci';
    $tables[$name]['indexes']['public_id']['prefixes'][0]=12;
    $issues=implode(',',(new SchemaValidator())->validate($definition,new SchemaInspection($tables))->issues());
    contains('changed_column:'.$name.'.state',$issues); contains('changed_index:'.$name.'.public_id',$issues);
});
test('Schema lifecycle installs a fresh exact schema and commits metadata only after validation', function (): void {
    global $wpdb; $GLOBALS['ysai_test_options']=array();
    $definition=new SchemaDefinition('mi1_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $wpdb=new MutableSchemaDatabase($definition,array(),'mi1_');
    ok(SchemaLifecycle::install());
    same(9,count($wpdb->inspection()->tables())); same(9,count($wpdb->dbDeltaStatements));
    same(SchemaLifecycle::SCHEMA_VERSION,get_option(SchemaLifecycle::SCHEMA_OPTION,''));
    $status=get_option(SchemaLifecycle::SCHEMA_STATUS_OPTION,array()); same('ready',$status['state']??'');
});
test('Schema lifecycle fails closed when verified schema metadata cannot be committed', function (): void {
    global $wpdb;
    $GLOBALS['ysai_test_options']=array();
    $GLOBALS['ysai_test_option_write_failures']=array(SchemaLifecycle::SCHEMA_OPTION=>true);
    $definition=new SchemaDefinition('mi4_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $wpdb=new MutableSchemaDatabase($definition,array(),'mi4_');
    ok(!SchemaLifecycle::install());
    same('',get_option(SchemaLifecycle::SCHEMA_OPTION,''));
    $status=SchemaLifecycle::status();
    same('blocked',$status['state']??'');
    same('schema_metadata_write_failed',$status['reason']??'');
    $GLOBALS['ysai_test_option_write_failures']=array();
});
test('Runtime schema verification converts definition failures into a bounded unverifiable state', function (): void {
    global $wpdb;
    $GLOBALS['ysai_test_options']=array();
    $definition=new SchemaDefinition('valid_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $wpdb=new MutableSchemaDatabase($definition,array(),'invalid-prefix-');
    ok(!SchemaLifecycle::verifyRuntime());
    $status=SchemaLifecycle::status();
    same('unverifiable',$status['state']??'');
    same('database_schema_error',$status['reason']??'');
});
test('Runtime schema verification does not rewrite unchanged ready metadata on every request', function (): void {
    global $wpdb;
    $definition=new SchemaDefinition('mi8_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $ready=(new \YassinStore\AiAssistant\Infrastructure\Database\SchemaRuntimeProof())->readyStatus(
        $definition,
        'wordpress|mi8_',
        SchemaLifecycle::SCHEMA_VERSION,
        time()
    );
    $GLOBALS['ysai_test_options']=array(
        SchemaLifecycle::SCHEMA_OPTION=>SchemaLifecycle::SCHEMA_VERSION,
        SchemaLifecycle::SCHEMA_STATUS_OPTION=>$ready,
    );
    $GLOBALS['ysai_test_option_writes']=array();
    $wpdb=new MutableSchemaDatabase($definition,schemaInspection($definition)->tables(),'mi8_');
    ok(SchemaLifecycle::verifyRuntime());
    same(0,(int)($GLOBALS['ysai_test_option_writes'][SchemaLifecycle::SCHEMA_OPTION]??0));
    same(0,(int)($GLOBALS['ysai_test_option_writes'][SchemaLifecycle::SCHEMA_STATUS_OPTION]??0));
    same('ready',SchemaLifecycle::status()['state']??'');
});

test('Fresh exact schema proof uses one structural canary and skips full metadata scans', function (): void {
    global $wpdb;
    $definition=new SchemaDefinition('cache1_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $GLOBALS['ysai_test_options']=array(
        SchemaLifecycle::SCHEMA_OPTION=>SchemaLifecycle::SCHEMA_VERSION,
        SchemaLifecycle::SCHEMA_STATUS_OPTION=>(new \YassinStore\AiAssistant\Infrastructure\Database\SchemaRuntimeProof())->readyStatus(
            $definition,
            'wordpress|cache1_',
            SchemaLifecycle::SCHEMA_VERSION,
            time()
        ),
    );
    $GLOBALS['ysai_test_option_writes']=array();
    $wpdb=new MutableSchemaDatabase($definition,schemaInspection($definition)->tables(),'cache1_');
    ok(SchemaLifecycle::verifyRuntime());
    $joined=implode("\n",$wpdb->queries);
    same(1,substr_count($joined,'ysai_schema_canary'));
    same(0,substr_count($joined,'information_schema.COLUMNS'));
    same(0,substr_count($joined,'information_schema.STATISTICS'));
    same(0,(int)($GLOBALS['ysai_test_option_writes'][SchemaLifecycle::SCHEMA_STATUS_OPTION]??0));
});

test('Expired or wrong-scope schema proof cannot authorize runtime and is refreshed only after full validation', function (): void {
    global $wpdb;
    $definition=new SchemaDefinition('cache2_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $expired=(new \YassinStore\AiAssistant\Infrastructure\Database\SchemaRuntimeProof())->readyStatus(
        $definition,
        'wordpress|wrong-scope_',
        SchemaLifecycle::SCHEMA_VERSION,
        time()-1000
    );
    $GLOBALS['ysai_test_options']=array(
        SchemaLifecycle::SCHEMA_OPTION=>SchemaLifecycle::SCHEMA_VERSION,
        SchemaLifecycle::SCHEMA_STATUS_OPTION=>$expired,
    );
    $GLOBALS['ysai_test_option_writes']=array();
    $wpdb=new MutableSchemaDatabase($definition,schemaInspection($definition)->tables(),'cache2_');
    ok(SchemaLifecycle::verifyRuntime());
    $joined=implode("\n",$wpdb->queries);
    same(0,substr_count($joined,'ysai_schema_canary'));
    same(1,substr_count($joined,'information_schema.TABLES'));
    same(1,substr_count($joined,'information_schema.COLUMNS'));
    same(1,substr_count($joined,'information_schema.STATISTICS'));
    same(1,(int)($GLOBALS['ysai_test_option_writes'][SchemaLifecycle::SCHEMA_STATUS_OPTION]??0));
    $status=SchemaLifecycle::status();
    ok((new \YassinStore\AiAssistant\Infrastructure\Database\SchemaRuntimeProof())->isFresh(
        $status,$definition,'wordpress|cache2_',SchemaLifecycle::SCHEMA_VERSION,SchemaLifecycle::SCHEMA_VERSION,time()
    ));
});

test('Failed schema canary falls back to exact validation and never authorizes from cache alone', function (): void {
    global $wpdb;
    $definition=new SchemaDefinition('cache3_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $GLOBALS['ysai_test_options']=array(
        SchemaLifecycle::SCHEMA_OPTION=>SchemaLifecycle::SCHEMA_VERSION,
        SchemaLifecycle::SCHEMA_STATUS_OPTION=>(new \YassinStore\AiAssistant\Infrastructure\Database\SchemaRuntimeProof())->readyStatus(
            $definition,'wordpress|cache3_',SchemaLifecycle::SCHEMA_VERSION,time()
        ),
    );
    $GLOBALS['ysai_test_option_writes']=array();
    $wpdb=new MutableSchemaDatabase($definition,schemaInspection($definition)->tables(),'cache3_');
    $wpdb->canaryResult=0;
    ok(SchemaLifecycle::verifyRuntime());
    $joined=implode("\n",$wpdb->queries);
    same(1,substr_count($joined,'ysai_schema_canary'));
    same(1,substr_count($joined,'information_schema.COLUMNS'));
    same(1,substr_count($joined,'information_schema.STATISTICS'));
    same(1,(int)($GLOBALS['ysai_test_option_writes'][SchemaLifecycle::SCHEMA_STATUS_OPTION]??0));
});

test('Schema proof cache is exact short-lived and structurally canaried by release invariant', function (): void {
    $root=YSAI_PROJECT_ROOT;
    $proof=(string)file_get_contents($root.'/src/Infrastructure/Database/SchemaRuntimeProof.php');
    $lifecycle=(string)file_get_contents($root.'/src/Infrastructure/Database/SchemaLifecycle.php');
    $canary=(string)file_get_contents($root.'/src/Infrastructure/Database/SchemaCanary.php');
    contains('public const TTL_SECONDS = 300',$proof);
    contains('scope_hash',$proof);
    contains('expires_at_epoch',$proof);
    contains('new SchemaCanary($wpdb)',$lifecycle);
    contains('ysai_schema_canary',$canary);
    contains('FORCE INDEX',$canary);
    notContains('transient',$proof);
});

test('Transient schema inspection failure preserves runtime provider readiness and recovers automatically', function (): void {
    global $wpdb;
    $definition=new SchemaDefinition('mi10_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $ready=array(
        'state'=>'ready',
        'version'=>SchemaLifecycle::SCHEMA_VERSION,
        'fingerprint'=>$definition->fingerprint(),
        'verified_at'=>'2026-07-12 12:00:00',
        'reason'=>'',
        'issues'=>array(),
    );
    $GLOBALS['ysai_test_options']=array(
        Settings::OPTION_KEY=>array_replace(Settings::defaults(),array('gemini_api_key'=>'verified-key')),
        SchemaLifecycle::SCHEMA_OPTION=>SchemaLifecycle::SCHEMA_VERSION,
        SchemaLifecycle::SCHEMA_STATUS_OPTION=>$ready,
    );
    $wpdb=new YsaiTestAdvisoryLockDatabase();
    $readiness=runtimeReadinessForTest(new Settings());
    $attempt=$readiness->beginCheck();
    $readiness->markReady($attempt);
    ok($readiness->isReady());
    $providerProof=get_option(GeminiRuntimeReadiness::OPTION_KEY,array());

    $wpdb=new MutableSchemaDatabase($definition,schemaInspection($definition)->tables(),'mi10_');
    $wpdb->metadataReadFailuresRemaining=1;
    ok(!SchemaLifecycle::verifyRuntime());
    same($providerProof,get_option(GeminiRuntimeReadiness::OPTION_KEY,array()));
    ok($readiness->isReady());
    $status=SchemaLifecycle::status();
    same('unverifiable',$status['state']??'');
    same('schema_inspection_failed',$status['reason']??'');
    same($definition->fingerprint(),$status['fingerprint']??'');
    same('2026-07-12 12:00:00',$status['verified_at']??'');

    ok(SchemaLifecycle::verifyRuntime());
    same('ready',SchemaLifecycle::status()['state']??'');
    same($providerProof,get_option(GeminiRuntimeReadiness::OPTION_KEY,array()));
    ok($readiness->isReady());
});

test('Transient schema inspection during explicit repair does not revoke runtime provider proof', function (): void {
    global $wpdb;
    $definition=new SchemaDefinition('mi11_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $GLOBALS['ysai_test_options']=array(
        Settings::OPTION_KEY=>array_replace(Settings::defaults(),array('gemini_api_key'=>'verified-key')),
        SchemaLifecycle::SCHEMA_OPTION=>SchemaLifecycle::SCHEMA_VERSION,
        SchemaLifecycle::SCHEMA_STATUS_OPTION=>array(
            'state'=>'ready','version'=>SchemaLifecycle::SCHEMA_VERSION,
            'fingerprint'=>$definition->fingerprint(),'verified_at'=>'2026-07-12 12:00:00',
            'reason'=>'','issues'=>array(),
        ),
    );
    $wpdb=new YsaiTestAdvisoryLockDatabase();
    $readiness=runtimeReadinessForTest(new Settings());
    $attempt=$readiness->beginCheck();
    $readiness->markReady($attempt);
    $providerProof=get_option(GeminiRuntimeReadiness::OPTION_KEY,array());

    $wpdb=new MutableSchemaDatabase($definition,schemaInspection($definition)->tables(),'mi11_');
    $wpdb->metadataReadFailuresRemaining=1;
    ok(!SchemaLifecycle::repair());
    same($providerProof,get_option(GeminiRuntimeReadiness::OPTION_KEY,array()));
    ok($readiness->isReady());
    same('unverifiable',SchemaLifecycle::status()['state']??'');
    same(0,count($wpdb->dbDeltaStatements));
});

test('Schema administration separates temporary verification outage from destructive repair', function (): void {
    $admin=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Presentation/Admin/SchemaAdmin.php');
    $plugin=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Plugin.php');
    contains("\$state === 'unverifiable'",$admin);
    contains('لم تبدأ إعادة بناء المخطط، وتم الاحتفاظ بإثبات جاهزية Gemini التشغيلي الموثق.',$admin);
    contains("\$status['state'] === 'unverifiable'",$plugin);
    contains('التحقق من قاعدة البيانات غير متاح',$plugin);
});

test('Runtime schema readiness never trusts cached schema state and never revokes independent provider proof', function (): void {
    global $wpdb;
    $definition=new SchemaDefinition('mi9_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $ready=array(
        'state'=>'ready','version'=>SchemaLifecycle::SCHEMA_VERSION,
        'fingerprint'=>$definition->fingerprint(),'verified_at'=>gmdate('Y-m-d H:i:s'),
        'reason'=>'','issues'=>array(),
    );
    $GLOBALS['ysai_test_options']=array(
        Settings::OPTION_KEY=>array_replace(Settings::defaults(),array('gemini_api_key'=>'drift-key')),
        SchemaLifecycle::SCHEMA_OPTION=>SchemaLifecycle::SCHEMA_VERSION,
        SchemaLifecycle::SCHEMA_STATUS_OPTION=>$ready,
    );
    $wpdb=new YsaiTestAdvisoryLockDatabase();
    $readiness=runtimeReadinessForTest(new Settings());
    $attempt=$readiness->beginCheck();
    $readiness->markReady($attempt);
    $providerProof=get_option(GeminiRuntimeReadiness::OPTION_KEY,array());

    $tables=schemaInspection($definition)->tables();
    unset($tables[$definition->tableName('messages')]);
    $wpdb=new MutableSchemaDatabase($definition,$tables,'mi9_');
    ok(!SchemaLifecycle::verifyRuntime());
    same(SchemaLifecycle::SCHEMA_VERSION,get_option(SchemaLifecycle::SCHEMA_OPTION,''));
    same($providerProof,get_option(GeminiRuntimeReadiness::OPTION_KEY,array()));
    ok($readiness->isReady());
    $status=SchemaLifecycle::status();
    same('blocked',$status['state']??'');
    same('database_schema_incomplete',$status['reason']??'');

    $wpdb=new MutableSchemaDatabase($definition,schemaInspection($definition)->tables(),'mi9_');
    ok(SchemaLifecycle::verifyRuntime());
    same('ready',SchemaLifecycle::status()['state']??'');
    same($providerProof,get_option(GeminiRuntimeReadiness::OPTION_KEY,array()));
    ok($readiness->isReady());
});

test('Schema lifecycle discards exact-looking unversioned authority before rebuilding', function (): void {
    global $wpdb; $GLOBALS['ysai_test_options']=array();
    $definition=new SchemaDefinition('mi2_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $wpdb=new MutableSchemaDatabase($definition,schemaInspection($definition)->tables(),'mi2_');
    ok(SchemaLifecycle::install());
    $drops=array_values(array_filter($wpdb->queries,static function(string $query): bool{return strpos($query,'DROP TABLE IF EXISTS')===0;}));
    same(9,count($drops)); same(9,count($wpdb->dbDeltaStatements));
});
test('Runtime blocks damaged storage without DDL and explicit repair rebuilds all tables', function (): void {
    global $wpdb; $GLOBALS['ysai_test_options']=array(SchemaLifecycle::SCHEMA_OPTION=>'1.0.0');
    $definition=new SchemaDefinition('mi3_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $tables=schemaInspection($definition)->tables(); unset($tables[$definition->tableName('messages')]);
    $wpdb=new MutableSchemaDatabase($definition,$tables,'mi3_');
    ok(!SchemaLifecycle::verifyRuntime()); same(0,count($wpdb->dbDeltaStatements));
    same(0,count(array_filter($wpdb->queries,static function(string $query): bool{return strpos($query,'DROP TABLE IF EXISTS')===0;})));
    $status=get_option(SchemaLifecycle::SCHEMA_STATUS_OPTION,array()); same('blocked',$status['state']??'');

    ok(SchemaLifecycle::repair());
    $drops=array_values(array_filter($wpdb->queries,static function(string $query): bool{return strpos($query,'DROP TABLE IF EXISTS')===0;}));
    same(8,count($drops)); same(9,count($wpdb->dbDeltaStatements));
    same('ready',(get_option(SchemaLifecycle::SCHEMA_STATUS_OPTION,array()))['state']??'');
});
test('Schema repair shares maintenance admission and preserves independent runtime provider proof', function (): void {
    global $wpdb;
    $definition=new SchemaDefinition('mi_live_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $ready=array(
        'state'=>'ready','version'=>SchemaLifecycle::SCHEMA_VERSION,
        'fingerprint'=>$definition->fingerprint(),'verified_at'=>gmdate('Y-m-d H:i:s'),
        'reason'=>'','issues'=>array(),
    );
    $GLOBALS['ysai_test_options']=array(
        Settings::OPTION_KEY=>array_replace(Settings::defaults(),array('gemini_api_key'=>'live-key')),
        SchemaLifecycle::SCHEMA_OPTION=>SchemaLifecycle::SCHEMA_VERSION,
        SchemaLifecycle::SCHEMA_STATUS_OPTION=>$ready,
    );
    $wpdb=new YsaiTestAdvisoryLockDatabase();
    $readiness=runtimeReadinessForTest(new Settings());
    $attempt=$readiness->beginCheck();
    $readiness->markReady($attempt);
    $providerProof=get_option(GeminiRuntimeReadiness::OPTION_KEY,array());

    $tables=schemaInspection($definition)->tables();
    unset($tables[$definition->tableName('messages')]);
    $wpdb=new MutableSchemaDatabase($definition,$tables,'mi_live_');
    $wpdb->liveAssistantLease=true;
    ok(!SchemaLifecycle::repair());
    same(0,count($wpdb->dbDeltaStatements));
    same(0,count(array_filter($wpdb->queries,static function(string $query):bool{
        return strpos($query,'DROP TABLE IF EXISTS')===0;
    })));
    same(SchemaLifecycle::SCHEMA_VERSION,get_option(SchemaLifecycle::SCHEMA_OPTION,''));
    same($ready,get_option(SchemaLifecycle::SCHEMA_STATUS_OPTION,array()));
    same($providerProof,get_option(GeminiRuntimeReadiness::OPTION_KEY,array()));
    $joined=implode("\n",$wpdb->queries);
    contains('SELECT GET_LOCK',$joined);
    contains('SELECT resource_hash FROM '.$definition->tableName('leases'),$joined);

    $wpdb->liveAssistantLease=false;
    ok(SchemaLifecycle::repair());
    same(9,count($wpdb->dbDeltaStatements));
    same('ready',(get_option(SchemaLifecycle::SCHEMA_STATUS_OPTION,array()))['state']??'');
    same($providerProof,get_option(GeminiRuntimeReadiness::OPTION_KEY,array()));
    ok($readiness->isReady());

    $lifecycle=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/Database/SchemaLifecycle.php');
    contains('(new MaintenanceGate())->run',$lifecycle);
    contains('liveWorkBeforeRepair($definition, $inspection)',$lifecycle);
    notContains('GeminiRuntimeReadiness',$lifecycle);
});

test('Schema repair treats a present malformed lease table as indeterminate and performs no DDL', function (): void {
    global $wpdb;
    $definition=new SchemaDefinition('mi_lease_drift_','utf8mb4','utf8mb4_unicode_ci');
    $tables=schemaInspection($definition)->tables();
    unset($tables[$definition->tableName('leases')]['columns']['resource']);
    $GLOBALS['ysai_test_options']=array(
        SchemaLifecycle::SCHEMA_OPTION=>SchemaLifecycle::SCHEMA_VERSION,
        SchemaLifecycle::SCHEMA_STATUS_OPTION=>array(
            'state'=>'blocked','version'=>SchemaLifecycle::SCHEMA_VERSION,
            'fingerprint'=>'','verified_at'=>'','reason'=>'database_schema_incomplete','issues'=>array(),
        ),
    );
    $wpdb=new MutableSchemaDatabase($definition,$tables,'mi_lease_drift_');
    ok(!SchemaLifecycle::repair());
    same(0,count($wpdb->dbDeltaStatements));
    same(0,count(array_filter($wpdb->queries,static function(string $query):bool{
        return strpos($query,'DROP TABLE IF EXISTS')===0;
    })));
});
test('Schema rebuild invalidates positive readiness before the first DDL statement', function (): void {
    global $wpdb;
    $definition=new SchemaDefinition('mi5_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $GLOBALS['ysai_test_options']=array(
        SchemaLifecycle::SCHEMA_OPTION=>'1.0.0',
        SchemaLifecycle::SCHEMA_STATUS_OPTION=>array('state'=>'ready','version'=>'1.0.0','fingerprint'=>$definition->fingerprint(),'verified_at'=>gmdate('Y-m-d H:i:s'),'reason'=>'','issues'=>array()),
    );
    $tables=schemaInspection($definition)->tables(); unset($tables[$definition->tableName('turns')]);
    $wpdb=new MutableSchemaDatabase($definition,$tables,'mi5_');
    ok(SchemaLifecycle::repair());
    same('',(string)($wpdb->metadataAtFirstDdl['version']??'missing'));
    same('rebuilding',$wpdb->metadataAtFirstDdl['status']['state']??'');
});
test('Schema rebuild performs no DDL when positive readiness cannot be invalidated', function (): void {
    global $wpdb;
    $definition=new SchemaDefinition('mi6_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $ready=array('state'=>'ready','version'=>'1.0.0','fingerprint'=>$definition->fingerprint(),'verified_at'=>gmdate('Y-m-d H:i:s'),'reason'=>'','issues'=>array());
    $GLOBALS['ysai_test_options']=array(SchemaLifecycle::SCHEMA_OPTION=>'1.0.0',SchemaLifecycle::SCHEMA_STATUS_OPTION=>$ready);
    $GLOBALS['ysai_test_option_write_failures']=array(SchemaLifecycle::SCHEMA_STATUS_OPTION=>true);
    $tables=schemaInspection($definition)->tables(); unset($tables[$definition->tableName('messages')]);
    $wpdb=new MutableSchemaDatabase($definition,$tables,'mi6_');
    ok(!SchemaLifecycle::repair()); same(0,count($wpdb->dbDeltaStatements));
    same(0,count(array_filter($wpdb->queries,static function(string $query): bool{return strpos($query,'DROP TABLE IF EXISTS')===0;})));
    same('',get_option(SchemaLifecycle::SCHEMA_OPTION,''));
    $GLOBALS['ysai_test_option_write_failures']=array();
});
test('Schema repair is independent from runtime provider-readiness option failures', function (): void {
    global $wpdb;
    $definition=new SchemaDefinition('mi_runtime_independent_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $staleProviderState=array('stale_authorizing_shape'=>true);
    $GLOBALS['ysai_test_options']=array(
        GeminiRuntimeReadiness::OPTION_KEY=>$staleProviderState,
        SchemaLifecycle::SCHEMA_OPTION=>'1.0.0',
    );
    $GLOBALS['ysai_test_option_delete_failures']=array(GeminiRuntimeReadiness::OPTION_KEY=>true);
    $GLOBALS['ysai_test_option_write_failures']=array(GeminiRuntimeReadiness::OPTION_KEY=>true);
    $tables=schemaInspection($definition)->tables();
    unset($tables[$definition->tableName('messages')]);
    $wpdb=new MutableSchemaDatabase($definition,$tables,'mi_runtime_independent_');
    try {
        ok(SchemaLifecycle::repair());
        same(9,count($wpdb->dbDeltaStatements));
        same($staleProviderState,get_option(GeminiRuntimeReadiness::OPTION_KEY,array()));
        same('ready',SchemaLifecycle::status()['state']??'');
    } finally {
        $GLOBALS['ysai_test_option_delete_failures']=array();
        $GLOBALS['ysai_test_option_write_failures']=array();
    }
});

test('Schema rebuild performs no DDL when schema-version invalidation fails', function (): void {
    global $wpdb;
    $definition=new SchemaDefinition('mi8_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $ready=array('state'=>'ready','version'=>'1.0.0','fingerprint'=>$definition->fingerprint(),'verified_at'=>gmdate('Y-m-d H:i:s'),'reason'=>'','issues'=>array());
    $GLOBALS['ysai_test_options']=array(SchemaLifecycle::SCHEMA_OPTION=>'1.0.0',SchemaLifecycle::SCHEMA_STATUS_OPTION=>$ready);
    $GLOBALS['ysai_test_option_delete_failures']=array(SchemaLifecycle::SCHEMA_OPTION=>true);
    $tables=schemaInspection($definition)->tables(); unset($tables[$definition->tableName('operations')]);
    $wpdb=new MutableSchemaDatabase($definition,$tables,'mi8_');
    ok(!SchemaLifecycle::repair()); same(0,count($wpdb->dbDeltaStatements));
    same(0,count(array_filter($wpdb->queries,static function(string $query): bool{return strpos($query,'DROP TABLE IF EXISTS')===0;})));
    $stored=get_option(SchemaLifecycle::SCHEMA_STATUS_OPTION,array());
    ok(($stored['state']??'')!=='ready');
    same('1.0.0',get_option(SchemaLifecycle::SCHEMA_OPTION,''));
    $GLOBALS['ysai_test_option_delete_failures']=array();
});
test('Interrupted schema rebuild leaves durable non-ready metadata', function (): void {
    global $wpdb;
    $definition=new SchemaDefinition('mi7_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $GLOBALS['ysai_test_options']=array(SchemaLifecycle::SCHEMA_OPTION=>'1.0.0');
    $tables=schemaInspection($definition)->tables(); unset($tables[$definition->tableName('messages')]);
    $wpdb=new MutableSchemaDatabase($definition,$tables,'mi7_'); $wpdb->failDbDeltaAt=2;
    ok(!SchemaLifecycle::repair()); same('',get_option(SchemaLifecycle::SCHEMA_OPTION,''));
    $status=get_option(SchemaLifecycle::SCHEMA_STATUS_OPTION,array()); same('blocked',$status['state']??'');
    ok(($status['state']??'')!=='ready');
});
test('Damaged assistant storage is rebuilt as one clean unit', function (): void {
    $definition=new SchemaDefinition('wp_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $tables=schemaInspection($definition)->tables();
    unset($tables[$definition->tableName('turns')]);
    $name=$definition->tableName('conversations');
    $tables[$name]['columns']['legacy_alias']=array('type'=>'varchar(10)','nullable'=>true,'default'=>null,'extra'=>'','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci');
    $database=new MutableSchemaDatabase($definition,$tables);
    $installer=new SchemaInstaller($database,array($database,'applyDbDelta'));
    $installer->install($definition,true);
    ok((new SchemaValidator())->validate($definition,$database->inspection())->isValid());
    $queries=implode("\n",$database->queries);
    $drops=array_values(array_filter($database->queries,static function(string $query): bool{return strpos($query,'DROP TABLE IF EXISTS')===0;}));
    same(8,count($drops)); notContains('ALTER TABLE',$queries); notContains('legacy_alias',Json::encodeObject($database->inspection()->tables()));
});
test('Schema advisory lock fails closed on prepare and database errors', function (): void {
    $database=new SchemaLockDatabase(); $lock=new AdvisoryLock($database,'schema','wp_|db');
    ok($lock->acquire(0)); $lock->release(); same(2,count($database->queries));

    $prepareFailure=new SchemaLockDatabase(); $prepareFailure->prepared=false;
    ok(!(new AdvisoryLock($prepareFailure,'schema','wp_|db'))->acquire(0)); same(0,count($prepareFailure->queries));

    $queryFailure=new SchemaLockDatabase(); $queryFailure->errorAfterQuery='lock query failed';
    ok(!(new AdvisoryLock($queryFailure,'schema','wp_|db'))->acquire(0));
});
test('Runtime readiness and maintenance advisory locks share the exact database registry scope', function (): void {
    global $wpdb;
    $previous=$wpdb;
    $previousOptions=$GLOBALS['ysai_test_options'];
    $wpdb=new AdvisoryScopeDatabase();
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array(
        'gemini_api_key'=>'test-key',
    )));
    try {
        runtimeReadinessForTest(new Settings())->beginCheck();
        same('locked',(new MaintenanceGate())->run(static function(): string{return 'locked';}));
        $scope=SchemaRegistry::scopeKey();
        $names=array();
        foreach($wpdb->preparedArguments as $prepared){
            if(isset($prepared[1][0])&&is_string($prepared[1][0])){$names[]=$prepared[1][0];}
        }
        ok(in_array('ysai_runtime_ready_'.substr(hash('sha256',$scope),0,40),$names,true));
        ok(in_array('ysai_maintenance_'.substr(hash('sha256',$scope),0,40),$names,true));
        $registry=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/Database/SchemaRegistry.php');
        contains("(string) DB_NAME . '|' . (string) \$wpdb->prefix",$registry);
        notContains("defined('DB_NAME')",$registry);
        $lifecycle=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/Database/SchemaLifecycle.php');
        contains("'schema',\n                SchemaRegistry::scopeKey()",$lifecycle);
    } finally {
        $GLOBALS['ysai_test_options']=$previousOptions;
        $wpdb=$previous;
    }
});

test('Recovery policy never trusts unversioned or partially damaged authority', function (): void {
    $definition=new SchemaDefinition('wp_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $validator=new SchemaValidator(); $policy=new SchemaRecoveryPolicy();
    $exact=schemaInspection($definition); $exactValidation=$validator->validate($definition,$exact);
    ok($policy->runtimeIsReady('1.0.0','1.0.0',$exactValidation));
    ok(!$policy->requiresCleanSlate('1.0.0','1.0.0',$definition,$exact,$exactValidation));
    ok($policy->requiresCleanSlate('','1.0.0',$definition,$exact,$exactValidation));
    same('schema_rebuild_required',$policy->runtimeReason('','1.0.0',$exactValidation));

    $damagedTables=$exact->tables(); unset($damagedTables[$definition->tableName('messages')]);
    $damaged=new SchemaInspection($damagedTables); $damagedValidation=$validator->validate($definition,$damaged);
    ok(!$policy->runtimeIsReady('1.0.0','1.0.0',$damagedValidation));
    ok($policy->requiresCleanSlate('1.0.0','1.0.0',$definition,$damaged,$damagedValidation));
    same('database_schema_incomplete',$policy->runtimeReason('1.0.0','1.0.0',$damagedValidation));

    $empty=new SchemaInspection(array()); $emptyValidation=$validator->validate($definition,$empty);
    ok(!$policy->requiresCleanSlate('','1.0.0',$definition,$empty,$emptyValidation));
});
test('Undefined WordPress collation accepts the database-selected textual collation', function (): void {
    $definition=new SchemaDefinition('wp_', 'utf8mb4', '');
    $tables=schemaInspection($definition)->tables();
    foreach($tables as &$table){
        $table['collation']='utf8mb4_unicode_ci';
        foreach($table['columns'] as &$column){if($column['charset']!==null){$column['collation']='utf8mb4_unicode_ci';}}
        unset($column);
    }
    unset($table);
    ok((new SchemaValidator())->validate($definition,new SchemaInspection($tables))->isValid());
});
test('Clean-slate installation drops every current assistant table before rebuild', function (): void {
    $definition=new SchemaDefinition('wp_', 'utf8mb4', 'utf8mb4_unicode_ci');
    $database=new MutableSchemaDatabase($definition,schemaInspection($definition)->tables());
    $installer=new SchemaInstaller($database,array($database,'applyDbDelta'));
    $installer->install($definition,true);
    ok((new SchemaValidator())->validate($definition,$database->inspection())->isValid());
    $drops=array_values(array_filter($database->queries,static function(string $query): bool{return strpos($query,'DROP TABLE IF EXISTS')===0;}));
    same(9,count($drops)); notContains('ysai_receipts',implode("\n",$drops));
});
test('Runtime schema verification cannot execute installation or destructive repair', function (): void {
    $source=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/Database/SchemaLifecycle.php');
    $start=strpos($source,'public static function verifyRuntime(): bool');
    $end=strpos($source,'public static function repair(): bool',$start===false?0:$start);
    ok($start!==false && $end!==false && $end>$start);
    $body=substr($source,(int)$start,(int)$end-(int)$start);
    foreach(array('installOrRepair(', 'reconcile(', 'AdvisoryLock', 'DROP TABLE', 'dbDelta') as $forbidden){notContains($forbidden,$body);}
});
test('Database repair is POST-only and plugin action links cannot trigger it', function (): void {
    $admin=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Presentation/Admin/SchemaAdmin.php');
    contains("REQUEST_METHOD",$admin); contains("!== 'POST'",$admin); contains('wp_nonce_field',$admin);
    $plugin=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Plugin.php');
    notContains('admin-post.php?action=ysai_repair_schema',$plugin); notContains("wp_nonce_url(
                admin_url('admin-post.php?action=ysai_repair_schema')",$plugin);
});
test('Database definition contains no legacy receipt or migration authority', function (): void {
    $definition=new SchemaDefinition('wp_'); $sql=implode("\n",$definition->createStatements());
    notContains('ysai_receipts',$sql);
    ok(!is_dir(YSAI_PROJECT_ROOT.'/src/Infrastructure/Database/Migration'));
    $lifecycle=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/Database/SchemaLifecycle.php');
    notContains('version_compare',$lifecycle); notContains('ResetUnpublished',$lifecycle); notContains('ResetExperimental',$lifecycle);
    notContains('ALTER TABLE',(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/Database/SchemaInstaller.php'));
});

test('Conversation resume authenticates locks and extends retention in one transaction', function (): void {
    global $wpdb;
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>Settings::defaults());
    $publicId=Uuid::v4();
    $accessToken=str_repeat('t',32);
    $sessionHash=str_repeat('a',64);
    $row=array(
        'id'=>7,
        'public_id'=>$publicId,
        'access_hash'=>hash_hmac('sha256',$accessToken,wp_salt('secure_auth')),
        'session_hash'=>$sessionHash,
        'state'=>Json::encodeObject(ConversationState::initial()->toArray()),
        'created_at'=>gmdate('Y-m-d H:i:s',time()-60),
        'updated_at'=>gmdate('Y-m-d H:i:s',time()-30),
        'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600),
    );
    $wpdb=new ConversationResumeDatabase($row);
    $conversation=(new ConversationRepository(
        new Settings(),
        new TransactionManager(),
        new TurnLeaseManager(),
        new ActiveWorkInspector()
    ))->resume($publicId,$accessToken,$sessionHash);
    ok(is_array($conversation));
    same($publicId,$conversation['public_id']);
    same($accessToken,$conversation['access_token']);
    same('START TRANSACTION',$wpdb->queries[0]??'');
    same('COMMIT',$wpdb->queries[count($wpdb->queries)-1]??'');
    contains('FOR UPDATE',(string)($wpdb->reads[0][0]??''));
    contains('access_hash = %s',(string)($wpdb->reads[0][0]??''));
    same(1,count($wpdb->updates));
    notContains('function touch',(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/Database/ConversationRepository.php'));
});

test('Boot lease maps one session hash to one deterministic active conversation', function (): void {
    global $wpdb;
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>Settings::defaults());
    $wpdb=new ConversationBootDatabase();
    $sessionHash=str_repeat('a',64);
    $resource='boot|'.$sessionHash;
    $lease=new TurnLease($resource,hash('sha256',$resource),str_repeat('b',32),1,time()+60);
    $leases=new RecordingTurnLeasePort();
    $repository=new ConversationRepository(new Settings(),new TransactionManager(),$leases,new ActiveWorkInspector());
    $first=$repository->createOrResume($sessionHash,$lease);
    $second=$repository->createOrResume($sessionHash,$lease);
    same($first['public_id'],$second['public_id']);
    same($first['access_token'],$second['access_token']);
    same(1,count($wpdb->inserts));
    same(1,count($wpdb->updates));
    same(2,$leases->assertions);
    same('START TRANSACTION',$wpdb->queries[0]??'');
    same('COMMIT',$wpdb->queries[count($wpdb->queries)-1]??'');

    $expectedToken=rtrim(strtr(base64_encode(hash_hmac(
        'sha256','ysai-conversation-access-v1|'.get_current_blog_id().'|'.$first['public_id'].'|'.$sessionHash,
        wp_salt('secure_auth'),true
    )),'+/','-_'),'=');
    same($expectedToken,$first['access_token']);
    same(hash_hmac('sha256',$expectedToken,wp_salt('secure_auth')),$wpdb->rows[0]['access_hash']);

    // A mapping whose stored authority no longer matches the one exact
    // derivation is retired and replaced, never adopted as an alias.
    $wpdb->rows[0]['access_hash']=str_repeat('f',64);
    $replacement=$repository->createOrResume($sessionHash,$lease);
    ok($replacement['public_id']!==$first['public_id']);
    same(2,count($wpdb->rows));
    ok($wpdb->rows[0]['session_hash']!==$sessionHash);
    same($sessionHash,$wpdb->rows[1]['session_hash']);
    ok(strtotime((string)$wpdb->rows[0]['expires_at'].' UTC')<time());

    $repositorySource=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/Database/ConversationRepository.php');
    notContains("wp_salt('auth')",$repositorySource);
    contains("wp_salt('secure_auth')",$repositorySource);

    $boot=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Presentation/Rest/Controller/BootController.php');
    $kernel=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/Composition/PluginKernel.php');
    contains('MaintenanceGatePort $maintenanceGate',$boot);
    $gatePosition=strpos($boot,'$this->maintenanceGate->run');
    $createPosition=strpos($boot,'$this->conversations->createOrResume');
    ok($gatePosition!==false&&$createPosition!==false&&$gatePosition<$createPosition);
    contains('$persistence->maintenanceGate()',$kernel);
});

test('Expired conversation cleanup bounds every child table and resumes from durable state', function (): void {
    global $wpdb;
    $wpdb=new BoundedConversationCleanupDatabase(3);
    $gate=new ImmediateMaintenanceGate();
    $batch=(new ConversationMaintenanceRepository(
        new TransactionManager(),new Settings(),$gate,new ActiveWorkInspector()
    ))->cleanupExpired(
        1,
        3,
        microtime(true)+5.0
    );
    ok($batch instanceof ConversationCleanupBatch);
    same(1,$gate->calls);
    same(1,$batch->conversationsDeleted());
    same(17,$batch->totalRowsDeleted());
    $rowsDeleted=ysai_test_private_property($batch,'rowsDeleted');
    same(3,$rowsDeleted['operation_step_attempts']??-1);
    same(3,$rowsDeleted['operation_steps']??-1);
    same(3,$rowsDeleted['operations']??-1);
    same(3,$rowsDeleted['turns']??-1);
    same(3,$rowsDeleted['messages']??-1);
    same(1,$rowsDeleted['leases']??-1);
    ok($batch->madeProgress());
    ok($batch->hasMore());
    ok(!$batch->stoppedForDeadline());
    same(2,count($wpdb->scalarReads));
    notContains('FOR UPDATE',(string)($wpdb->reads[0][0]??''));
    contains('LIMIT %d FOR UPDATE',(string)($wpdb->reads[1][0]??''));

    $childReads=array_values(array_filter($wpdb->reads,static function(array $read): bool {
        return strpos((string)($read[0]??''),'SELECT id, public_id')!==0
            && strpos((string)($read[0]??''),'SELECT c.id, c.public_id')!==0;
    }));
    same(5,count($childReads));
    foreach($childReads as $read){
        contains('LIMIT %d FOR UPDATE',(string)$read[0]);
        same(3,(int)($read[1][count($read[1])-1]??0));
    }
    foreach($wpdb->writes as $write){
        if(strpos((string)$write[0],' WHERE id IN (')!==false){
            ok(count($write[1])<=3,'Cleanup delete exceeded the child identity bound.');
        }
    }
});

test('Expired conversation cleanup shares admission barrier and skips live authority before child deletion', function (): void {
    global $wpdb;
    foreach(array(
        array(true,false,1),
        array(false,true,2),
    ) as $case){
        $wpdb=new BoundedConversationCleanupDatabase(3,0,false,$case[0],$case[1]);
        $gate=new ImmediateMaintenanceGate();
        $batch=(new ConversationMaintenanceRepository(
            new TransactionManager(),new Settings(),$gate,new ActiveWorkInspector()
        ))->cleanupExpired(1,3,microtime(true)+5.0);

        same(1,$gate->calls);
        same(0,$batch->conversationsDeleted());
        same(0,$batch->totalRowsDeleted());
        ok(!$batch->madeProgress());
        ok($batch->hasMore());
        same((int)$case[2],count($wpdb->scalarReads));
        same(1,count($wpdb->reads));
        same(array(),$wpdb->writes);
        same(array('START TRANSACTION','COMMIT'),$wpdb->queries);
    }
});

test('Expired conversation cleanup commits bounded progress when its deadline is reached between stages', function (): void {
    global $wpdb;
    $wpdb=new BoundedConversationCleanupDatabase(3,100000);
    $batch=(new ConversationMaintenanceRepository(
        new TransactionManager(),new Settings(),new ImmediateMaintenanceGate(),new ActiveWorkInspector()
    ))->cleanupExpired(
        1,
        3,
        microtime(true)+0.01
    );
    same(0,$batch->conversationsDeleted());
    same(3,$batch->totalRowsDeleted());
    $rowsDeleted=ysai_test_private_property($batch,'rowsDeleted');
    same(3,$rowsDeleted['operation_step_attempts']??-1);
    same(0,$rowsDeleted['operation_steps']??-1);
    ok($batch->madeProgress());
    ok($batch->hasMore());
    ok($batch->stoppedForDeadline());
    same(3,count($wpdb->reads));
    same(array('START TRANSACTION','COMMIT'),array_values(array_filter($wpdb->queries,static function(string $query): bool {
        return in_array($query,array('START TRANSACTION','COMMIT'),true);
    })));
});

test('Expired conversation cleanup rolls back an oversized child identity batch', function (): void {
    global $wpdb;
    $wpdb=new BoundedConversationCleanupDatabase(3,0,true);
    throws(RuntimeException::class,function(): void {
        (new ConversationMaintenanceRepository(
            new TransactionManager(),new Settings(),new ImmediateMaintenanceGate(),new ActiveWorkInspector()
        ))->cleanupExpired(
            1,
            3,
            microtime(true)+5.0
        );
    },'hard batch limit');
    ok(in_array('ROLLBACK',$wpdb->queries,true));
    ok(!in_array('COMMIT',$wpdb->queries,true));
});

test('Maintenance cleanup has hard parent child and time budgets with resumable continuation', function (): void {
    $cleanup=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Lifecycle/Cleanup.php');
    $maintenance=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/Database/ConversationMaintenanceRepository.php');
    contains('MAX_RUN_SECONDS = 20.0',$cleanup);
    contains('MAX_CONVERSATION_BATCHES = 4',$cleanup);
    contains('CONVERSATION_TARGET_BATCH_SIZE = 50',$cleanup);
    contains('CONVERSATION_CHILD_BATCH_SIZE = 250',$cleanup);
    contains('wp_schedule_single_event',$cleanup);
    contains('CONTINUATION_HOOK',$cleanup);
    notContains('do {',$cleanup);
    contains('cleanupExpired(self::INGRESS_BATCH_SIZE)',$cleanup);
    contains('ingress_limits_deleted',$cleanup);
    contains('self::CONVERSATION_CHILD_BATCH_SIZE',$cleanup);
    contains('$cleanupDeadline',$cleanup);
    contains('MAX_CHILD_BATCH = 500',$maintenance);
    contains('deleteBoundedIds(',$maintenance);
    contains('LIMIT %d FOR UPDATE',$maintenance);
    contains('count($rawIds) > $limit',$maintenance);
    contains('deadlineReached($deadlineAt)',$maintenance);
    notContains('$operationIds = $wpdb->get_col',$maintenance);
    notContains('$stepIds = $wpdb->get_col',$maintenance);
});

test('Cleanup unscheduling is finite and one failed hook cannot suppress the other', function (): void {
    $GLOBALS['ysai_test_scheduled_events']=array(
        Cleanup::HOOK=>array(100,200),
        Cleanup::CONTINUATION_HOOK=>array(300),
    );
    $GLOBALS['ysai_test_clear_scheduled_results']=array(Cleanup::HOOK=>false);
    $GLOBALS['ysai_test_clear_scheduled_calls']=array();
    Cleanup::unschedule();
    same(array(Cleanup::HOOK,Cleanup::CONTINUATION_HOOK),$GLOBALS['ysai_test_clear_scheduled_calls']);
    same(100,wp_next_scheduled(Cleanup::HOOK));
    same(false,wp_next_scheduled(Cleanup::CONTINUATION_HOOK));

    $GLOBALS['ysai_test_clear_scheduled_results']=array();
    $GLOBALS['ysai_test_clear_scheduled_calls']=array();
    Cleanup::unschedule();
    same(array(Cleanup::HOOK,Cleanup::CONTINUATION_HOOK),$GLOBALS['ysai_test_clear_scheduled_calls']);
    same(false,wp_next_scheduled(Cleanup::HOOK));
    same(false,wp_next_scheduled(Cleanup::CONTINUATION_HOOK));

    $source=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Lifecycle/Cleanup.php');
    contains('wp_clear_scheduled_hook($hook, array(), true)',$source);
    notContains('while ($timestamp !== false)',$source);
    $GLOBALS['ysai_test_scheduled_events']=array();
});

test('Expired boot and commerce leases retire after their safety horizon', function (): void {
    global $wpdb;
    $hashes=array(str_repeat('a',64),str_repeat('b',64));
    $wpdb=new LeaseRetirementDatabase($hashes);
    $deleted=(new TurnLeaseManager())->cleanupExpired(2);
    same(2,$deleted);
    $select=(string)($wpdb->reads[0][0]??'');
    $delete=(string)($wpdb->writes[0][0]??'');
    contains('l.resource LIKE %s',$select);
    contains('l.updated_at < %s',$select);
    contains('NOT EXISTS',$select);
    contains('o.status IN (%s,%s)',$select);
    same('boot|%',(string)($wpdb->reads[0][1][2]??''));
    same('commerce|%',(string)($wpdb->reads[0][1][3]??''));
    same('prepared',(string)($wpdb->reads[0][1][4]??''));
    same('executing',(string)($wpdb->reads[0][1][5]??''));
    contains('lease_until < %s',$delete);
    contains('NOT EXISTS',$delete);
});

test('Lease takeover updates audit time before replacing lease expiry', function (): void {
    $source=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/Concurrency/TurnLeaseManager.php');
    $updated=strpos($source,'updated_at = IF(lease_until <= %s, VALUES(updated_at), updated_at)');
    $until=strpos($source,'lease_until = IF(lease_until <= %s, VALUES(lease_until), lease_until)');
    ok($updated!==false && $until!==false && $updated<$until);
});
test('First release rejects multisite before storage work and contains no network lifecycle enumeration', function (): void {
    $root=YSAI_PROJECT_ROOT;
    $entry=(string)file_get_contents($root.'/yassin-ai-assistant.php');
    $activator=(string)file_get_contents($root.'/src/Lifecycle/Activator.php');
    $deactivator=(string)file_get_contents($root.'/src/Lifecycle/Deactivator.php');
    $uninstall=(string)file_get_contents($root.'/uninstall.php');

    $reject=strpos($activator,'self::rejectMultisite();');
    $dependency=strpos($activator,'self::requireWooCommerce();');
    $schema=strpos($activator,'SchemaLifecycle::install()');
    ok($reject!==false && $dependency!==false && $schema!==false && $reject<$dependency && $dependency<$schema);
    contains("if (!is_multisite())",$activator);
    contains('ووردبريس متعدد المواقع غير مدعوم',$activator);
    notContains("if (is_multisite())",$deactivator);
    contains('converted to multisite later',$deactivator);
    notContains("if (is_multisite()) {
    exit;",$uninstall);
    contains('later converted to multisite',$uninstall);
    notContains('Activator::register()',$entry);
    foreach (array($entry,$activator,$deactivator,$uninstall) as $source) {
        notContains('get_sites(',$source);
        notContains('wp_initialize_site',$source);
        notContains('switch_to_blog',$source);
        notContains('restore_current_blog',$source);
        notContains('SiteContext',$source);
    }
    ok(!is_file($root.'/src/Lifecycle/SiteContext.php'));
    notContains('flush_rewrite_rules',$activator);
    notContains('flush_rewrite_rules',$deactivator);

    $readme=(string)file_get_contents($root.'/README.md');
    $wordpressReadme=(string)file_get_contents($root.'/readme.txt');
    $changelog=(string)file_get_contents($root.'/CHANGELOG.md');
    $architecture=(string)file_get_contents($root.'/ARCHITECTURE.md');
    $development=(string)file_get_contents($root.'/DEVELOPMENT.md');
    contains('WordPress Multisite is intentionally unsupported in version 1.0.0',$readme);
    contains('activation rejects WordPress Multisite before creating settings or schema',$wordpressReadme);
    notContains('Network activation requires WooCommerce to be network active',$wordpressReadme);
    contains('Activation rejects WordPress Multisite before WooCommerce checks, schema installation, or settings creation',$changelog);
    contains('Dynamic WooCommerce product names and descriptions remain exact catalog data and may be English or mixed-language',$changelog);
    contains('activation rejects WordPress Multisite before schema or settings changes',$architecture);
    contains('Do not add network enumeration, blog switching, or partial network lifecycle paths',$development);
    notContains('## Fix 15',$architecture);
});
test('Exact schema classes expose no unused reconciliation APIs', function (): void {
    notContains('function columnSql',(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/Database/SchemaDefinition.php'));
    notContains('function changes',(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/Database/SchemaDiff.php'));
    notContains('function diff',(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/Database/SchemaValidationResult.php'));
});
test('Unpublished runtime contains no dead compatibility surfaces confirmed by the audit', function (): void {
    $root=YSAI_PROJECT_ROOT.'/src';
    $workflow=(string)file_get_contents($root.'/Application/Turn/TurnWorkflow.php');
    $decoder=(string)file_get_contents($root.'/Presentation/Rest/RequestDecoder.php');
    notContains('private $leases',$workflow);
    notContains('public function chat(WP_REST_Request',$decoder);
    foreach(array(
        '/Infrastructure/WooCommerce/Projection/DisplayPriceProjection.php',
        '/Infrastructure/WooCommerce/Projection/ProductSnapshotFactory.php',
    ) as $path){
        notContains('method_exists(',(string)file_get_contents($root.$path));
    }
    foreach(array('SchemaInstaller.php','SchemaInspector.php','SchemaCanary.php','AdvisoryLock.php') as $file){
        notContains("property_exists(\$this->database, 'last_error')",(string)file_get_contents($root.'/Infrastructure/Database/'.$file));
    }
    $runtime='';
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,FilesystemIterator::SKIP_DOTS
    )) as $file){if($file->getExtension()==='php'){$runtime.=(string)file_get_contents($file->getPathname());}}
    notContains('core_instructions',$runtime);
    contains("'store_guidance' => ''",$runtime);
});
test('Unpublished merchant guidance has no retired setting alias or migration fallback', function (): void {
    $previous=$GLOBALS['ysai_test_options']??array();
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array(
        'core_instructions'=>'retired value must be ignored',
    ));
    try {
        $settings=new Settings();
        same('',$settings->get('store_guidance'));
        ok(!array_key_exists('core_instructions',$settings->all()));
    } finally {
        $GLOBALS['ysai_test_options']=$previous;
    }
});
test('First-release runtime exposes no test-only value-object getters', function (): void {
    $root=YSAI_PROJECT_ROOT.'/src';
    $contracts=array(
        '/Application/Agent/AgentContext.php'=>array(
            'function conversation(','function pendingCartIntent()',
        ),
        '/Application/Authority/AuthorityRegistry.php'=>array(
            'public function recordCartItem(',
        ),
        '/Application/Tool/ToolExecutionResult.php'=>array('function ok('),
        '/Domain/Chat/AssistantResponse.php'=>array('function receipts('),
        '/Domain/Chat/TurnRecord.php'=>array(
            'function input(','function updatedAt(','function completedAt(',
        ),
        '/Domain/Commerce/CartLine.php'=>array(
            'function itemDataHash(','function itemData(','function identity(',
        ),
        '/Infrastructure/Database/ConversationCleanupBatch.php'=>array(
            'function selectedConversations(','function rowsDeleted(',
        ),
    );
    foreach($contracts as $path=>$signatures){
        $source=(string)file_get_contents($root.$path);
        foreach($signatures as $signature){notContains($signature,$source);}
    }
});
test('Repositories use schema names without depending on lifecycle state', function (): void {
    $root=YSAI_PROJECT_ROOT.'/src/Infrastructure';
    $files=array(
        $root.'/Database/ConversationRepository.php',
        $root.'/Database/ConversationMaintenanceRepository.php',
        $root.'/Database/MessageRepository.php',
        $root.'/Database/TurnRepository.php',
        $root.'/Database/OperationRepository.php',
        $root.'/Concurrency/TurnLeaseManager.php',
        $root.'/Security/RateLimiter.php',
    );
    foreach($files as $file){
        $source=(string)file_get_contents($file);
        contains('SchemaRegistry',$source,$file);
        notContains('SchemaLifecycle',$source,$file);
        notContains('Migrator',$source,$file);
    }
    ok(!is_file(YSAI_PROJECT_ROOT.'/src/Infrastructure/Database/Migrator.php'));
});
