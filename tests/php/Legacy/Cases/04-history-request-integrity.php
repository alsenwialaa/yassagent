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

// History and request integrity.
test('Model and visible transcript share one terminal-turn context window', function (): void {
    $window=new ConversationContextWindow();
    same(12,$window->terminalTurnLimit());

    $previous=$GLOBALS['wpdb']??null;
    $database=new MessageWindowDatabase();
    $GLOBALS['wpdb']=$database;
    try {
        $repository=new MessageRepository();
        same(array(),$repository->modelHistory(7,$window->terminalTurnLimit()));
        same(array(),$repository->clientMessages(7,$window->terminalTurnLimit()));
    } finally {
        $GLOBALS['wpdb']=$previous;
    }

    same(2,count($database->prepares));
    foreach($database->prepares as $prepared){
        $arguments=$prepared[1][0]??array();
        ok(is_array($arguments));
        same(12,(int)end($arguments));
    }
});

test('Context-window wiring has no independent model or client numeric limit', function (): void {
    $root=YSAI_PROJECT_ROOT.'/src';
    $workflow=(string)file_get_contents($root.'/Application/Turn/TurnWorkflow.php');
    $projector=(string)file_get_contents($root.'/Presentation/Rest/ClientTranscriptProjector.php');
    $kernel=(string)file_get_contents($root.'/Infrastructure/Composition/PluginKernel.php');
    $policy=(string)file_get_contents($root.'/Application/Chat/ConversationContextWindow.php');
    contains('private const TERMINAL_TURN_LIMIT = 12',$policy);
    contains('$this->contextWindow->terminalTurnLimit()',$workflow);
    contains('$this->contextWindow->terminalTurnLimit()',$projector);
    contains('$contextWindow = new ConversationContextWindow()',$kernel);
    notContains('modelHistory($turn->conversationId(), 24',$workflow);
    notContains('clientMessages($conversationId, 40)',$projector);
});

test('Committed response projection survives aging outside the bounded transcript window', function (): void {
    $previous=$GLOBALS['wpdb']??null;
    $database=new CommittedTurnProjectionDatabase();
    $GLOBALS['wpdb']=$database;
    try {
        $repository=new MessageRepository();
        $projector=new ClientTranscriptProjector(
            $repository,
            new ConversationContextWindow()
        );
        $targetTurnId=$database->targetTurnId();
        $bounded=$projector->messages(7);
        same(24,count($bounded));
        foreach($bounded as $message){
            ok(($message['turn_id']??'')!==$targetTurnId,'The oldest turn must be outside the normal window.');
        }

        $committed=$database->targetAssistant();
        $projection=$projector->committedTurn(7,$committed);
        same($committed,$projection['message']);
        same(24,count($projection['messages']));
        same(1,$database->exactTurnReads);
        $roles=array();
        foreach($projection['messages'] as $message){
            if(($message['turn_id']??'')===$targetTurnId){$roles[]=$message['role']??'';}
        }
        same(array('user','assistant'),$roles);
        same($targetTurnId,$projection['messages'][0]['turn_id']);
        same('user',$projection['messages'][0]['role']);
        same($committed,$projection['messages'][1]);
    } finally {
        $GLOBALS['wpdb']=$previous;
    }
});

test('Exact replay keeps immutable terminal identity', function (): void {
    $previous=$GLOBALS['wpdb']??null;
    $database=new CommittedTurnProjectionDatabase();
    $GLOBALS['wpdb']=$database;
    try {
        $projector=new ClientTranscriptProjector(
            new MessageRepository(),
            new ConversationContextWindow()
        );
        $committed=$database->targetAssistant();
        $projection=$projector->committedTurn(7,$committed);
        same($committed,$projection['message']);
        $contradictory=$committed;
        $contradictory['text']='رد مختلف';
        throws(RuntimeException::class,static function()use($projector,$contradictory):void{
            $projector->committedTurn(7,$contradictory);
        },'contradicts');
    } finally {
        $GLOBALS['wpdb']=$previous;
    }
});

test('Committed chat response degrades transcript projection without returning HTTP 500', function (): void {
    $conversationId='77777777-7777-4777-8777-777777777777';
    $turnId='78787878-7878-4787-8787-787878787878';
    $message=array(
        'id'=>'79797979-7979-4797-8797-797979797979',
        'turn_id'=>$turnId,
        'role'=>'assistant',
        'outcome'=>'follow_up',
        'text'=>'تم تثبيت هذا الرد.',
        'products'=>array(),
        'receipts'=>array(),
        'presentation'=>array('image_scope'=>'none','images'=>array(),'reply_quote'=>''),
        'created_at'=>time(),
    );
    $result=new TurnResult($message,true);
    $requestInput=new TurnRequest(
        $conversationId,
        'conversation-token-1234567890',
        $turnId,
        'طلب العميل',
        array()
    );
    $conversation=array(
        'id'=>7,
        'public_id'=>$conversationId,
        'access_token'=>'conversation-token-1234567890',
    );

    $reflection=new ReflectionClass(ChatController::class);
    /** @var ChatController $controller */
    $controller=$reflection->newInstanceWithoutConstructor();
    $inject=function (string $property,$value) use ($reflection,$controller): void {
        $field=$reflection->getProperty($property);
        if (PHP_VERSION_ID < 80100) { $field->setAccessible(true); }
        $field->setValue($controller,$value);
    };
    $inject('sessions',new class {
        public function validateTransport(string $token): string { return str_repeat('b',64); }
        public function assertActive(string $token,string $expectedSessionHash): void {}
    });
    $inject('decoder',new class($requestInput) {
        private $input;
        public function __construct(TurnRequest $input){$this->input=$input;}
        public function chatEnvelope($request): array { return array(); }
        public function chatFromEnvelope(array $envelope): TurnRequest { return $this->input; }
    });
    $inject('ingress',new class {
        public function consumeChat(string $sessionHash,string $ip): array { return array('allowed'=>true,'retry_after'=>0); }
    });
    $inject('schema',new class {
        public function blockedResponse(){ return null; }
    });
    $inject('conversations',new class($conversation) {
        private $conversation;
        public function __construct(array $conversation){$this->conversation=$conversation;}
        public function resume(string $id,string $token,string $sessionHash): array { return $this->conversation; }
    });
    $inject('turns',new class($result) {
        private $result;
        public function __construct(TurnResult $result){$this->result=$result;}
        public function process(array $conversation,TurnRequest $request,string $sessionHash,string $ip): TurnResult { return $this->result; }
    });
    $inject('transcript',new class {
        public function committedTurn(int $conversationId,array $message): array { throw new RuntimeException('projection unavailable'); }
        public function messages(int $conversationId): array { throw new RuntimeException('projection unavailable'); }
    });
    $inject('cart',new class {
        public function displaySummary(): array {
            return array('item_count'=>1,'formatted_total'=>'10','cart_url'=>'https://example.test/cart','checkout_url'=>'https://example.test/checkout');
        }
    });
    $inject('cartMutations',new class {
        public function inspect(): CartMutationCapability {
            return new CartMutationCapability(true,CartMutationCapability::AVAILABLE,'');
        }
    });
    $inject('guard',new class {
        public function clientIp(): string { return '203.0.113.10'; }
    });
    $inject('responses',apiResponderForTest());
    $inject('responseProjector',new TurnResponseProjector(publicResponseValidatorForTest()));
    $logger=new class {
        public $errors=array();
        public function error(string $message,array $context=array()): void {$this->errors[]=array($message,$context);}
        public function debug(string $message,array $context=array()): void {}
    };
    $inject('logger',$logger);

    $response=$controller->handle(new WP_REST_Request('',array(),array('X-YSAI-Session'=>'signed')));
    same(200,$response->status);
    same(true,$response->data['ok']);
    same(true,$response->data['turn_committed']);
    same(false,$response->data['messages_available']);
    same(array(),$response->data['conversation']['messages']);
    same('تم تثبيت هذا الرد.',$response->data['message']['text']);
    same(true,$response->data['cart_available']);
    same(1,$response->data['cart']['item_count']);
    same(1,count($logger->errors));
    same('Committed turn transcript projection failed.',$logger->errors[0][0]);
});

test('Committed fallback emits only a canonical error when its durable message cannot be projected', function (): void {
    $reflection=new ReflectionClass(ChatController::class);
    /** @var ChatController $controller */
    $controller=$reflection->newInstanceWithoutConstructor();
    $inject=static function(string $property,$value)use($reflection,$controller):void{
        $field=$reflection->getProperty($property);
        if(PHP_VERSION_ID<80100){$field->setAccessible(true);}
        $field->setValue($controller,$value);
    };
    $inject('responses',apiResponderForTest());
    $inject('responseProjector',new TurnResponseProjector(publicResponseValidatorForTest()));
    $logger=new class {
        public $errors=array();
        public function error(string $message,array $context=array()):void{$this->errors[]=array($message,$context);}
    };
    $inject('logger',$logger);

    $method=$reflection->getMethod('committedFallbackResponse');
    if(PHP_VERSION_ID<80100){$method->setAccessible(true);}
    /** @var WP_REST_Response $response */
    $response=$method->invoke($controller,array(
        'turn_id'=>'78787878-7878-4787-8787-787878787878',
        'text'=>'تم تثبيت هذا الرد، لكنه يفتقد حقول العرض العامة.',
    ),array(
        'public_id'=>'77777777-7777-4777-8777-777777777777',
        'access_token'=>'conversation-token-1234567890',
    ));

    same(503,$response->status);
    same(array('ok','code','message'),array_keys($response->data));
    same(false,$response->data['ok']);
    same('committed_response_unavailable',$response->data['code']);
    ok(!array_key_exists('safe_message',$response->data));
    same('no-store, no-cache, must-revalidate, max-age=0',$response->headers['Cache-Control']??'');
    same(1,count($logger->errors));
    same('Committed turn fallback violates the public response contract.',$logger->errors[0][0]);
});

test('Model history accepts complete terminal pairs', function (): void {
    $request=new ModelRequest('System',array(array('role'=>'user','text'=>'Hi'),array('role'=>'assistant','text'=>'Hello')),'Next',array(),array(array('name'=>'cart_view','description'=>'View cart','parameters'=>ToolSchemas::emptyObject())),1024);
    same(2,count($request->history()));
});
test('Model history rejects orphan user messages', function (): void {
    throws(ModelProtocolException::class,function():void{new ModelRequest('System',array(array('role'=>'user','text'=>'Hi')),'Next',array(),array(array('name'=>'cart_view','description'=>'View cart','parameters'=>ToolSchemas::emptyObject())),1024);},'model_history_pairing_invalid');
});
test('Model history rejects unsupported fields', function (): void {
    throws(ModelProtocolException::class,function():void{new ModelRequest('System',array(array('role'=>'user','text'=>'Hi','outcome'=>'x'),array('role'=>'assistant','text'=>'Yo')),'Next',array(),array(array('name'=>'cart_view','description'=>'View cart','parameters'=>ToolSchemas::emptyObject())),1024);},'model_history_field_invalid');
});
