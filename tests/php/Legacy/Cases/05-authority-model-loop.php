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

// Opaque current-turn authority and model-loop contracts.
test('Agent model loop corrects plain prose before accepting one terminal answer', function (): void {
    $session=new QueuedModelSession(array(
        new ModelStep('step-1',array(),'plain answer','STOP'),
        new ModelStep('step-2',array(new FunctionCall('call-1','provider-call-1','respond_answer',array('text'=>'هذه إجابة موثقة.'))),'','STOP'),
    ));
    $response=modelLoopForTest(array(new RespondAnswerHandler(),new RespondSafeFailureHandler()))->run($session,agentContextForTest());
    same(Outcome::ANSWER,$response->outcome());
    same('هذه إجابة موثقة.',$response->text());
    same(1,count($session->corrections));
    same(AgentPromptFeedback::plainOutput(),$session->corrections[0]['instruction']);
    same(0,count($session->submissions));
});
test('Agent model loop feeds back invalid terminal contracts and accepts a corrected terminal', function (): void {
    $session=new QueuedModelSession(array(
        new ModelStep('step-1',array(new FunctionCall('call-1','provider-call-1','respond_answer',array())),'','STOP'),
        new ModelStep('step-2',array(new FunctionCall('call-2','provider-call-2','respond_answer',array('text'=>'هذه إجابة مصححة.'))),'','STOP'),
    ));
    $response=modelLoopForTest(array(new RespondAnswerHandler(),new RespondSafeFailureHandler()))->run($session,agentContextForTest());
    same(Outcome::ANSWER,$response->outcome());
    same('هذه إجابة مصححة.',$response->text());
    same(1,count($session->submissions));
    $feedback=$session->submissions[0]['feedback'];
    same(1,count($feedback));
    same('terminal_contract_invalid',(string)($feedback[0]->payload()['code']??''));
});
test('Agent model loop rejects non-Arabic terminal prose and accepts an Arabic correction', function (): void {
    $session=new QueuedModelSession(array(
        new ModelStep('step-1',array(new FunctionCall('call-1','provider-call-1','respond_answer',array('text'=>'This answer is entirely in English and must not reach the customer.'))),'','STOP'),
        new ModelStep('step-2',array(new FunctionCall('call-2','provider-call-2','respond_answer',array('text'=>'هذه إجابة عربية صالحة للعميل.'))),'','STOP'),
    ));
    $response=modelLoopForTest(array(new RespondAnswerHandler(),new RespondSafeFailureHandler()))->run($session,agentContextForTest());
    same(Outcome::ANSWER,$response->outcome());
    same('هذه إجابة عربية صالحة للعميل.',$response->text());
    same(1,count($session->submissions));
    $feedback=$session->submissions[0]['feedback'];
    same('terminal_contract_invalid',(string)($feedback[0]->payload()['code']??''));
    same('customer_text_not_arabic',(string)($feedback[0]->payload()['data']['reason']??''));
});
test('Agent model loop rejects Markdown and HTML terminal prose as non-plain text', function (): void {
    foreach(array(
        '**هذه إجابة منسقة**',
        '*هذه إجابة مائلة*',
        "1. هذه إجابة في قائمة",
        '<strong>هذه إجابة</strong>',
        '[هذه إجابة](https://example.test)',
    ) as $invalid) {
        $session=new QueuedModelSession(array(
            new ModelStep('step-1',array(new FunctionCall(
                'call-1','provider-call-1','respond_answer',array('text'=>$invalid)
            )),'','STOP'),
            new ModelStep('step-2',array(new FunctionCall(
                'call-2','provider-call-2','respond_answer',array('text'=>'هذه إجابة عربية بنص عادي.')
            )),'','STOP'),
        ));
        $response=modelLoopForTest(array(
            new RespondAnswerHandler(),new RespondSafeFailureHandler()
        ))->run($session,agentContextForTest());
        same('هذه إجابة عربية بنص عادي.',$response->text());
        same('customer_text_not_plain',(string)(
            $session->submissions[0]['feedback'][0]->payload()['data']['reason']??''
        ));
    }
});

test('Agent model loop rejects mutation siblings and publishes the verified receipt immediately', function (): void {
    $verified=receipt();
    $mutation=new RecordingToolHandler(
        'test_mutation',
        ToolContract::MUTATION,
        ToolExecutionResult::verified($verified,array('receipt'=>$verified->forClient()))
    );
    $read=new RecordingToolHandler('test_read',ToolContract::READ,ToolExecutionResult::success(array('value'=>1)));
    $session=new QueuedModelSession(array(
        new ModelStep('step-1',array(
            new FunctionCall('call-1','provider-call-1','test_mutation',array()),
            new FunctionCall('call-2','provider-call-2','test_read',array()),
        ),'','STOP'),
        new ModelStep('step-2',array(new FunctionCall('call-3','provider-call-3','test_mutation',array())),'','STOP'),
    ));
    $response=modelLoopForTest(array($mutation,$read,new RespondAnswerHandler(),new RespondSafeFailureHandler()))->run($session,agentContextForTest());
    same(Outcome::ACTION_VERIFIED,$response->outcome());
    same($verified->safeMessage(),$response->text());
    same(array($verified->forClient()),$response->forClient()['receipts']);
    same(1,$mutation->calls);
    same(0,$read->calls);
    same(1,count($session->submissions));
    same(2,count($session->submissions[0]['feedback']));
    foreach($session->submissions[0]['feedback'] as $feedback){
        same('mutation_must_be_alone',(string)($feedback->payload()['code']??''));
    }
});
test('Mutation failure is terminal locally and never waits on the provider while cart authority is held', function (): void {
    $mutation=new RecordingToolHandler(
        'test_mutation_failure',
        ToolContract::MUTATION,
        ToolExecutionResult::failure('cart_execution_rejected','لم يتم تغيير السلة.'),
        null,
        true
    );
    $isolation=new RecordingProviderWaitIsolation();
    $session=new QueuedModelSession(array(
        new ModelStep('step-1',array(new FunctionCall(
            'call-1','provider-call-1','test_mutation_failure',array()
        )),'','STOP'),
        // This must remain unread. A provider terminal cannot reinterpret an
        // authoritative mutation result.
        new ModelStep('step-2',array(new FunctionCall(
            'call-2','provider-call-2','respond_answer',array('text'=>'تم التنفيذ.')
        )),'','STOP'),
    ));
    $response=modelLoopForTest(array(
        $mutation,new RespondAnswerHandler(),new RespondSafeFailureHandler()
    ),$isolation)->run($session,agentContextForTest());
    same(Outcome::SAFE_FAILURE,$response->outcome());
    same('cart_execution_rejected',$response->failureCode());
    same(1,$mutation->calls);
    same(1,$isolation->releases);
    same(0,count($session->submissions));
});
test('A recoverable semantic cart denial returns to the model for exact follow-up wording', function (): void {
    $mutationState=(object)array('calls'=>0);
    $query=new class implements CartQueryPort {
        public function snapshot(bool $includeAuthority=false): array { throw new RuntimeException('Unexpected cart read.'); }
        public function displaySummary(): array { return array(); }
    };
    $mutations=new class($mutationState) implements CartMutationPort {
        private $state;
        public function __construct($state){$this->state=$state;}
        public function execute(CartPlan $plan,CommerceExecutionContext $context): ActionReceipt {
            ++$this->state->calls;
            return receipt();
        }
        public function recoverForTurn(CommerceExecutionContext $context): ?ActionReceipt { return null; }
    };
    $capability=new class implements CartMutationCapabilityPort {
        public function inspect(): CartMutationCapability {
            return new CartMutationCapability(true,CartMutationCapability::AVAILABLE,'');
        }
    };
    $logger=new class implements LoggerPort {
        public function error(string $message,array $context=array()): void {}
    };
    $verifier=new FixedCartIntentVerifier(false,CartIntentVerdict::AMBIGUOUS_TARGET);
    $normalizer=new CatalogTextNormalizer();
    $variableProducts=new VariableProductAuthority($normalizer);
    $service=new CartToolService(
        $query,new CartPlanFactory(),$mutations,$capability,
        new CurrentTurnCartIntentEvidence($normalizer,$variableProducts),
        new CartIntentVerificationFactory($normalizer),$verifier,new FixedClock(),
        $logger,new FixedTextLocalizer()
    );
    $authority=new AuthorityRegistry();
    $productRef=$authority->recordProduct(array(
        'id'=>801,'name'=>'قهوة عربية','sku'=>'COF-801',
        'type'=>'simple','requires_variation'=>false,'attributes'=>array(),
    ));
    $arguments=array(
        'intent_text'=>'أضفها للسلة',
        'commands'=>array(array(
            'type'=>'add','product_ref'=>$productRef,'quantity_mode'=>'default',
        )),
    );
    $session=new QueuedModelSession(array(
        new ModelStep('step-1',array(new FunctionCall(
            'call-1','provider-call-1','cart_apply',$arguments
        )),'','STOP'),
        new ModelStep('step-2',array(new FunctionCall(
            'call-2','provider-call-2','respond_follow_up',array(
                'question'=>'أي منتج تقصد أن أضيفه إلى السلة؟',
                'purpose'=>'cart_ambiguity',
            )
        )),'','STOP'),
    ));
    $response=modelLoopForTest(array(
        new CartApplyHandler($service),new RespondFollowUpHandler(),
        new RespondAnswerHandler(),new RespondSafeFailureHandler()
    ))->run($session,cartAgentContextForTest($authority,'أضفها للسلة'));
    same(Outcome::FOLLOW_UP,$response->outcome());
    same('',$response->failureCode());
    same('أي منتج تقصد أن أضيفه إلى السلة؟',$response->text());
    same(1,count($verifier->requests));
    same(0,$mutationState->calls);
    same(1,count($session->submissions));
    $feedback=$session->submissions[0]['feedback'][0]->payload();
    same('cart_intent_ambiguous_target',(string)($feedback['code']??''));
    same('model_authored_follow_up',(string)($feedback['data']['customer_response']??''));
    ok(!array_key_exists('safe_message',$feedback));

    $replanVerifier=new FixedCartIntentVerifier(false,CartIntentVerdict::PLAN_MISMATCH);
    $replanService=new CartToolService(
        $query,new CartPlanFactory(),$mutations,$capability,
        new CurrentTurnCartIntentEvidence($normalizer,$variableProducts),
        new CartIntentVerificationFactory($normalizer),$replanVerifier,new FixedClock(),
        $logger,new FixedTextLocalizer()
    );
    $replan=$replanService->apply(
        $arguments,
        cartAgentContextForTest($authority,'أضفها للسلة')
    );
    same('cart_intent_plan_mismatch',$replan->code());
    same('model_replan_cart_action',(string)($replan->data()['customer_response']??''));
    contains('retry cart_apply alone',(string)($replan->data()['instruction']??''));
    same(0,$mutationState->calls);
});
test('A cart continuation is rechecked for expiry immediately before Woo execution', function (): void {
    $now=1700000000;
    $authority=new AuthorityRegistry();
    $lineRef=$authority->recordCartSnapshot(array(array(
        'cart_item_key'=>'line-expiry','line_fingerprint'=>str_repeat('9',64),
        'name'=>'قهوة عربية','quantity'=>3,'attributes'=>array(),
    )))[0];
    $pending=pendingCartIntentForTest(pendingCartIntentFactoryForTest(new FixedClock($now)),array(
        'action'=>'update','target_ref'=>$lineRef,'missing'=>'quantity',
        'intent_text'=>'زود كمية القهوة','quantity_mode'=>'increment',
    ),'كم وحدة تريد إضافتها إلى كمية القهوة العربية؟',cartAgentContextForTest($authority,'زود كمية القهوة'));
    $calls=(object)array('mutations'=>0);
    $query=new class implements CartQueryPort {
        public function snapshot(bool $includeAuthority=false): array { return array(); }
        public function displaySummary(): array { return array(); }
    };
    $mutations=new class($calls) implements CartMutationPort {
        private $calls;
        public function __construct($calls){$this->calls=$calls;}
        public function execute(CartPlan $plan,CommerceExecutionContext $context): ActionReceipt {
            ++$this->calls->mutations; return receipt();
        }
        public function recoverForTurn(CommerceExecutionContext $context): ?ActionReceipt { return null; }
    };
    $capability=new class implements CartMutationCapabilityPort {
        public function inspect(): CartMutationCapability {
            return new CartMutationCapability(true,CartMutationCapability::AVAILABLE,'');
        }
    };
    $logger=new class implements LoggerPort {
        public function error(string $message,array $context=array()): void {}
    };
    $normalizer=new CatalogTextNormalizer();
    $variableProducts=new VariableProductAuthority($normalizer);
    $service=new CartToolService(
        $query,new CartPlanFactory(),$mutations,$capability,
        new CurrentTurnCartIntentEvidence($normalizer,$variableProducts),
        new CartIntentVerificationFactory($normalizer),new FixedCartIntentVerifier(),
        new SequenceClock(array($now+1,$now+1200)),
        $logger,new FixedTextLocalizer()
    );
    $result=$service->apply(array(
        'intent_text'=>'حبتين',
        'commands'=>array(array(
            'type'=>'update','cart_item_ref'=>$lineRef,
            'quantity_mode'=>'increment','quantity'=>2,
        )),
    ),cartAgentContextForTest($authority,'حبتين',$pending));
    same('cart_continuation_expired',$result->code());
    same(0,$calls->mutations);
});
test('Pre-execution mutation contract failure is fed back for one corrected cart call', function (): void {
    $verified=receipt();
    $mutation=new RecordingToolHandler(
        'test_correctable_mutation',
        ToolContract::MUTATION,
        ToolExecutionResult::verified($verified,array('receipt'=>$verified->forClient())),
        ToolSchemas::closedObject(array(
            'quantity'=>array('type'=>'integer','minimum'=>1,'maximum'=>99),
        ),array('quantity'))
    );
    $isolation=new RecordingProviderWaitIsolation();
    $session=new QueuedModelSession(array(
        new ModelStep('step-1',array(new FunctionCall(
            'call-1','provider-call-1','test_correctable_mutation',array()
        )),'','STOP'),
        new ModelStep('step-2',array(new FunctionCall(
            'call-2','provider-call-2','test_correctable_mutation',array('quantity'=>1)
        )),'','STOP'),
    ));
    $response=modelLoopForTest(array(
        $mutation,new RespondAnswerHandler(),new RespondSafeFailureHandler()
    ),$isolation)->run($session,agentContextForTest());
    same(Outcome::ACTION_VERIFIED,$response->outcome());
    same(1,$mutation->calls);
    same(2,$isolation->releases);
    same(1,count($session->submissions));
    $payload=$session->submissions[0]['feedback'][0]->payload();
    same('tool_contract_invalid',(string)($payload['code']??''));
    same('required_field_missing',(string)($payload['data']['reason']??''));
});
test('Every provider round crosses the provider-wait isolation boundary', function (): void {
    $read=new RecordingToolHandler(
        'test_isolated_read',ToolContract::READ,ToolExecutionResult::success(array('value'=>1))
    );
    $isolation=new RecordingProviderWaitIsolation();
    $session=new QueuedModelSession(array(
        new ModelStep('step-1',array(new FunctionCall(
            'call-1','provider-call-1','test_isolated_read',array()
        )),'','STOP'),
        new ModelStep('step-2',array(new FunctionCall(
            'call-2','provider-call-2','respond_answer',array('text'=>'هذه إجابة عربية.')
        )),'','STOP'),
    ));
    $response=modelLoopForTest(array(
        $read,new RespondAnswerHandler(),new RespondSafeFailureHandler()
    ),$isolation)->run($session,agentContextForTest());
    same(Outcome::ANSWER,$response->outcome());
    same(2,$isolation->releases);
});
test('Authority registry issues stable references and refreshes live product facts', function (): void {
    $r=new AuthorityRegistry();
    same('p1',$r->recordProduct(array('id'=>10,'name'=>'A','in_stock'=>true)));
    same('p1',$r->recordProduct(array('id'=>10,'name'=>'Renamed','in_stock'=>false)));
    $refreshed=$r->requireProduct('p1');
    same(10,$refreshed['id']); same('Renamed',$refreshed['name']); same(false,$refreshed['in_stock']);
});
test('Each complete cart read replaces the entire cart-line authority epoch atomically', function (): void {
    $authority=new AuthorityRegistry();
    $first=$authority->recordCartSnapshot(array(
        array(
            'cart_item_key'=>'line-a','line_fingerprint'=>str_repeat('a',64),
            'product_id'=>10,'variation_id'=>0,'name'=>'قهوة','sku'=>'COF',
            'attributes'=>array(),'quantity'=>1,
        ),
        array(
            'cart_item_key'=>'line-b','line_fingerprint'=>str_repeat('b',64),
            'product_id'=>11,'variation_id'=>0,'name'=>'شاي','sku'=>'TEA',
            'attributes'=>array(),'quantity'=>1,
        ),
    ));
    same(array('c1','c2'),$first);

    $second=$authority->recordCartSnapshot(array(array(
        'cart_item_key'=>'line-a','line_fingerprint'=>str_repeat('c',64),
        'product_id'=>10,'variation_id'=>0,'name'=>'قهوة','sku'=>'COF',
        'attributes'=>array(),'quantity'=>2,
    )));
    same(array('c3'),$second);
    throws(ContractViolation::class,static function()use($authority,$first):void{
        $authority->requireCartItem($first[0]);
    },'authority_ref_invalid');
    throws(ContractViolation::class,static function()use($authority,$first):void{
        $authority->requireCartItem($first[1]);
    },'authority_ref_invalid');
    same(2,(int)($authority->requireCartItem('c3')['quantity']??0));

    throws(ContractViolation::class,static function()use($authority):void{
        $authority->recordCartSnapshot(array(
            array(
                'cart_item_key'=>'line-c','line_fingerprint'=>str_repeat('d',64),
                'product_id'=>12,'variation_id'=>0,'name'=>'سكر','sku'=>'SUG',
                'attributes'=>array(),'quantity'=>1,
            ),
            array(),
        ));
    },'cart_item_authority_invalid');
    same(2,(int)($authority->requireCartItem('c3')['quantity']??0),
        'A malformed later snapshot must restore the previous complete authority epoch.');

    same('c4',$authority->recordCartSnapshot(array(array(
        'cart_item_key'=>'line-a','line_fingerprint'=>str_repeat('e',64),
        'product_id'=>10,'variation_id'=>0,'name'=>'قهوة','sku'=>'COF',
        'attributes'=>array(),'quantity'=>3,
    )))[0]);
    throws(ContractViolation::class,static function()use($authority):void{
        $authority->requireCartItem('c3');
    },'authority_ref_invalid');
});
test('Variation clarification authority comes only from one complete live catalog', function (): void {
    $authority=new AuthorityRegistry();
    $direct=array(
        'id'=>301,'parent_id'=>30,'name'=>'خيار مباشر','sku'=>'DIRECT-301',
        'attributes'=>array(array('label'=>'الوزن','value'=>'100','display'=>'100 غرام')),
        'purchasable'=>true,'in_stock'=>true,
    );
    $authority->recordVariation($direct);
    throws(ContractViolation::class,static function()use($authority):void{
        $authority->variationCatalogForProduct(30);
    },'cart_clarification_variation_catalog_missing');

    $second=$direct;
    $second['id']=302;
    $second['name']='خيار ثان';
    $second['sku']='DIRECT-302';
    $second['attributes'][0]['value']='250';
    $second['attributes'][0]['display']='250 غرام';
    $epochA=str_repeat('e',64);
    $epochB=str_repeat('f',64);
    same(array('v1','v2'),$authority->recordVariationCatalog(
        30,array($direct,$second),$epochA
    ));
    $inspection=$authority->variationCatalogForProduct(30);
    same(true,$inspection['complete']);
    same(array(301,302),array_map(static function(array $row):int{
        return (int)$row['id'];
    },$inspection['variations']));

    $foreign=$second;
    $foreign['id']=303;
    $foreign['parent_id']=31;
    throws(ContractViolation::class,static function()use($authority,$foreign,$epochA):void{
        $authority->recordVariationCatalog(30,array($foreign),$epochA);
    },'variation_catalog_authority_invalid');
    same(true,$authority->variationCatalogForProduct(30)['complete']);

    $changed=$direct;
    $changed['attributes'][0]['display']='100 جم';
    same(array('v1'),$authority->recordVariationCatalog(30,array($changed),$epochB));
    $inspection=$authority->variationCatalogForProduct(30);
    same(true,$inspection['complete']);
    same(array(301),array_map(static function(array $row):int{
        return (int)$row['id'];
    },$inspection['variations']));
    throws(ContractViolation::class,static function()use($authority,$changed):void{
        $authority->recordVariationCatalog(30,array($changed),'invalid');
    },'variation_catalog_authority_invalid');
    throws(ContractViolation::class,static function()use($authority,$epochA):void{
        $authority->recordVariationCatalog(30,array(),$epochA);
    },'variation_catalog_authority_invalid');
});
test('Variation resolver matches model-interpreted axes against the complete live catalog', function (): void {
    $resolver=new VariationResolver(new CatalogTextNormalizer());
    $rows=array();
    for($index=1;$index<=VariableProductLimits::MAX_VARIATIONS;++$index){
        $rows[]=array(
            'id'=>5000+$index,
            'parent_id'=>5000,
            'name'=>'بهارات - '.$index.' غرام',
            'sku'=>'SPICE-'.$index,
            'attributes'=>array(array(
                'key'=>'attribute_pa_weight',
                'label'=>'الوزن',
                'value'=>$index.'-g',
                'display'=>$index.' غرام',
            )),
            'in_stock'=>true,
            'purchasable'=>true,
        );
    }

    $exact=$resolver->resolve($rows,array(array(
        'name'=>'الوزن','value'=>VariableProductLimits::MAX_VARIATIONS.' غرام',
    )));
    same('exact',$exact['status']);
    same(1,$exact['match_count']);
    same(5000+VariableProductLimits::MAX_VARIATIONS,$exact['matches'][0]['id']);
    same(true,$exact['matches_complete']);

    $raw=$resolver->resolve($rows,array(array('name'=>'الوزن','value'=>'750-g')));
    same('exact',$raw['status']);
    same(5750,$raw['matches'][0]['id']);

    $ambiguous=$resolver->resolve(array_slice($rows,0,30),array());
    same('ambiguous',$ambiguous['status']);
    same(30,$ambiguous['match_count']);
    same(false,$ambiguous['matches_complete']);
    same(30,$ambiguous['combination_count']);
    same(false,$ambiguous['combinations_complete']);

    $missing=$resolver->resolve(array_slice($rows,0,2),array(array(
        'name'=>'الوزن','value'=>'غير موجود',
    )));
    same('not_found',$missing['status']);
    same(array('1 غرام','2 غرام'),$missing['available_axes'][0]['values']);

    throws(ContractViolation::class,static function()use($resolver,$rows):void{
        $resolver->resolve($rows,array(
            array('name'=>'الوزن','value'=>'1 غرام'),
            array('name'=>'الوزن','value'=>'2 غرام'),
        ));
    },'variation_selection_invalid');
});
test('Add commands and primitives preserve one exact current-turn purchase identity seal', function (): void {
    $product=array(
        'id'=>41,'name'=>'قهوة مختصة','sku'=>'COF-41',
        'type'=>'variable','requires_variation'=>true,
    );
    $variation=array(
        'id'=>411,'parent_id'=>41,'name'=>'قهوة مختصة - 250g','sku'=>'COF-250',
        'attributes'=>array(array(
            'key'=>'attribute_weight','label'=>'الوزن','value'=>'250g','display'=>'250g',
        )),
    );
    $fingerprint=CartPurchaseIdentity::fromAuthority($product,$variation)->fingerprint();
    same($fingerprint,CartPurchaseIdentity::fromAuthority($product,$variation)->fingerprint());
    $reordered=$variation;
    $reordered['attributes']=array_reverse($reordered['attributes']);
    same($fingerprint,CartPurchaseIdentity::fromAuthority($product,$reordered)->fingerprint());
    $renamed=$product;
    $renamed['name']='قهوة مختصة جديدة';
    ok(!hash_equals($fingerprint,CartPurchaseIdentity::fromAuthority($renamed,$variation)->fingerprint()));
    $changedOption=$variation;
    $changedOption['attributes'][0]['value']='500g';
    ok(!hash_equals($fingerprint,CartPurchaseIdentity::fromAuthority($product,$changedOption)->fingerprint()));

    $command=CartCommand::add(41,411,2,$fingerprint,'قهوة مختصة - 250g');
    same($fingerprint,$command->expectedPurchaseFingerprint());
    $storedCommand=$command->toStorageArray();
    same($fingerprint,CartCommand::fromStorageArray($storedCommand)->expectedPurchaseFingerprint());
    $storedCommand['expected_purchase_fingerprint']=str_repeat('0',64);
    same(str_repeat('0',64),CartCommand::fromStorageArray($storedCommand)->expectedPurchaseFingerprint(),
        'A different well-formed seal remains data and is rejected only against live identity.');
    $storedCommand['expected_purchase_fingerprint']='broken';
    throws(InvalidArgumentException::class,static function()use($storedCommand):void{
        CartCommand::fromStorageArray($storedCommand);
    },'purchase identity');

    $primitive=CartPrimitive::add(
        CartCommand::ADD,0,'single',41,411,2,$fingerprint,'قهوة مختصة - 250g'
    );
    same($fingerprint,$primitive->expectedPurchaseFingerprint());
    same($fingerprint,CartPrimitive::fromStorageArray(
        $primitive->toStorageArray()
    )->expectedPurchaseFingerprint());

    $authority=new AuthorityRegistry();
    $productRef=$authority->recordProduct($product);
    $variationRef=$authority->recordVariation($variation);
    $plan=(new CartPlanFactory())->fromToolArguments(array('commands'=>array(array(
        'type'=>'add','product_ref'=>$productRef,'variation_ref'=>$variationRef,
        'quantity_mode'=>'exact','quantity'=>2,
    ))),$authority);
    same($fingerprint,$plan->commands()[0]->expectedPurchaseFingerprint());
    $steps=(new CartStepPlanner())->plan($plan,snapshot(array(),array(),0,0,'identity-seal'));
    same(1,count($steps));
    same($fingerprint,$steps[0]->expectedPurchaseFingerprint());
});
test('Live product identity drift is rejected before cart state or Woo add hooks are touched', function (): void {
    if (!class_exists('WooCommerce')) { eval('class WooCommerce {}'); }
    $hadWoo=array_key_exists('ysai_test_wc',$GLOBALS);
    $previousWoo=$GLOBALS['ysai_test_wc']??null;
    $hadProducts=array_key_exists('ysai_test_products',$GLOBALS);
    $previousProducts=$GLOBALS['ysai_test_products']??null;
    $cart=new class {
        public $reads=0;
        public function get_cart(): array { ++$this->reads; return array(); }
    };
    $product=new WC_Product('simple','',null,null,41);
    $product->name='قهوة مختصة';
    $product->sku='COF-41';
    $GLOBALS['ysai_test_products']=array(41=>$product);
    $GLOBALS['ysai_test_wc']=(object)array(
        'session'=>new YsaiTestWooSession(),
        'customer'=>(object)array(),
        'cart'=>$cart,
    );
    try {
        $fingerprint=CartPurchaseIdentity::fromAuthority(array(
            'id'=>41,'name'=>'قهوة مختصة','sku'=>'COF-41',
            'type'=>'simple','requires_variation'=>false,
        ),null)->fingerprint();
        $policy=new \YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartProductPolicy(
            new WooCartGateway(new WooSession()),
            new \YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\CatalogVisibilityPolicy(),
            new \YassinStore\AiAssistant\Infrastructure\WooCommerce\ProductCapabilityPolicy(),
            new \YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\AttributePresenter()
        );
        same(array('product_id'=>41,'variation_id'=>0,'variation'=>array()),
            $policy->purchase(41,0,1,$fingerprint));
        same(1,$cart->reads);

        $product->name='قهوة مختصة جديدة';
        try {
            $policy->purchase(41,0,1,$fingerprint);
            throw new TestFailure('A renamed live product retained stale purchase authority.');
        } catch (SafeCommerceException $exception) {
            same('product_changed_since_selection',$exception->reasonCode());
            same(false,$exception->stateMayHaveChanged());
        }
        same(1,$cart->reads,
            'Identity drift must fail before aggregate cart inspection and the later add hooks.');
    } finally {
        if($hadWoo){$GLOBALS['ysai_test_wc']=$previousWoo;}else{unset($GLOBALS['ysai_test_wc']);}
        if($hadProducts){$GLOBALS['ysai_test_products']=$previousProducts;}else{unset($GLOBALS['ysai_test_products']);}
    }
});
test('Authority capacity covers complete cart and variation tool bounds while identity refresh remains valid at capacity', function (): void {
    $authority=new AuthorityRegistry();
    for($id=1;$id<=4096;++$id){
        $authority->recordProduct(array('id'=>$id,'name'=>'Product '.$id));
    }
    same('p1',$authority->recordProduct(array('id'=>1,'name'=>'Refreshed')));
    same('Refreshed',(string)($authority->requireProduct('p1')['name']??''));
    throws(ContractViolation::class,static function()use($authority):void{
        $authority->recordProduct(array('id'=>4097,'name'=>'Overflow'));
    },'authority_capacity_invalid');
});
test('Authority registry rejects whitespace and wrong reference kinds', function (): void {
    $r=new AuthorityRegistry(); $r->recordProduct(array('id'=>10)); $r->recordVariation(array('id'=>11,'parent_id'=>10));
    throws(ContractViolation::class,function()use($r):void{$r->requireProduct(' p1');},'authority_ref_invalid');
    throws(ContractViolation::class,function()use($r):void{$r->requireProduct('v1');},'authority_ref_invalid');
});
test('Cart verifier accepts an exact update effect', function (): void {
    $preLine=line('line-a',1); $postLine=line('line-a',2);
    $pre=snapshot(array($preLine),array(),1,10,'h1'); $post=snapshot(array($postLine),array(),2,20,'h2');
    $plan=new CartPlan(array(CartCommand::update('line-a',$preLine->fingerprint(),2,'Product 10')));
    $applied=new AppliedCartPlan(array(array('type'=>'update','cart_item_key'=>'line-a','previous_quantity'=>1.0,'display_name'=>'Product 10','quantity'=>2.0)));
    $result=(new CartDeltaVerifier())->verify($plan,$pre,$post,$applied); ok($result->isVerified()); ok($result->changed());
});
test('Cart verifier rejects an unrelated coupon change', function (): void {
    $preLine=line('line-a',1); $postLine=line('line-a',2);
    $pre=snapshot(array($preLine),array(),1,10,'h1'); $post=snapshot(array($postLine),array('PROMO'),2,20,'h2');
    $plan=new CartPlan(array(CartCommand::update('line-a',$preLine->fingerprint(),2,'Product 10')));
    $applied=new AppliedCartPlan(array(array('type'=>'update','cart_item_key'=>'line-a','previous_quantity'=>1.0,'display_name'=>'Product 10','quantity'=>2.0)));
    $result=(new CartDeltaVerifier())->verify($plan,$pre,$post,$applied); ok(!$result->isVerified()); same('unexpected_cart_delta',$result->reason());
});
test('Cart verifier accepts the canonical quantity-free remove effect', function (): void {
    $preLine=line('line-a',1);
    $pre=snapshot(array($preLine),array(),1,10,'remove-pre');
    $post=snapshot(array(),array(),0,0,'remove-post');
    $plan=new CartPlan(array(CartCommand::remove('line-a',$preLine->fingerprint(),'Product 10')));
    $applied=new AppliedCartPlan(array(array(
        'type'=>'remove','cart_item_key'=>'line-a','previous_quantity'=>1.0,'display_name'=>'Product 10',
    )));
    $result=(new CartDeltaVerifier())->verify($plan,$pre,$post,$applied);
    ok($result->isVerified()); ok($result->changed());
});
test('Cart verifier rejects remove effects with the wrong key or previous quantity', function (): void {
    $preLine=line('line-a',1);
    $pre=snapshot(array($preLine),array(),1,10,'remove-pre');
    $post=snapshot(array(),array(),0,0,'remove-post');
    $plan=new CartPlan(array(CartCommand::remove('line-a',$preLine->fingerprint(),'Product 10')));
    $wrongKey=new AppliedCartPlan(array(array(
        'type'=>'remove','cart_item_key'=>'line-b','previous_quantity'=>1.0,'display_name'=>'Product 10',
    )));
    $wrongPrevious=new AppliedCartPlan(array(array(
        'type'=>'remove','cart_item_key'=>'line-a','previous_quantity'=>2.0,'display_name'=>'Product 10',
    )));
    $result=(new CartDeltaVerifier())->verify($plan,$pre,$post,$wrongKey);
    ok(!$result->isVerified()); same('remove_effect_mismatch',$result->reason());
    $result=(new CartDeltaVerifier())->verify($plan,$pre,$post,$wrongPrevious);
    ok(!$result->isVerified()); same('remove_previous_quantity_mismatch',$result->reason());
});
test('Target cart-line authority permits quantity changes but binds normalized item metadata', function (): void {
    $policy=new CartLineAuthorityPolicy();
    $before=line('line-a',1,10,5,array('attribute_size'=>'small'),array('weight'=>'100g'))->authorityArray();
    $allowed=line('line-a',2,10,5,array('attribute_size'=>'small'),array('weight'=>'100g'))->authorityArray();
    ok($policy->stableIdentityMatches($before,$allowed));
    foreach (array(
        line('line-b',2,10,5,array('attribute_size'=>'small'),array('weight'=>'200g')),
        line('line-a',2,11,5,array('attribute_size'=>'small'),array('weight'=>'200g')),
        line('line-a',2,10,6,array('attribute_size'=>'small'),array('weight'=>'200g')),
        line('line-a',2,10,5,array('attribute_size'=>'large'),array('weight'=>'200g')),
        line('line-a',2,10,5,array('attribute_size'=>'small'),array('weight'=>'200g')),
    ) as $changed) {
        ok(!$policy->stableIdentityMatches($before,$changed->authorityArray()));
    }
});
test('Quantity step verification preserves exact custom metadata and seals the observed target', function (): void {
    $before=line('line-a',1,10,0,array(),array('measured_weight'=>'100g'));
    $after=line('line-a',2,10,0,array(),array('measured_weight'=>'100g'));
    $primitive=CartPrimitive::setQuantity(
        CartCommand::UPDATE,0,'single','line-a',$before->fingerprint(),2,'Product 10'
    );
    $pre=snapshot(array($before),array(),1,10,'update-before');
    $post=snapshot(array($after),array(),2,20,'update-after');
    $verifier=new CartStepVerifier(new CartLineAuthorityPolicy());
    $effect=$verifier->seal(
        $primitive,
        $pre,
        $post,
        array(
            'primitive_type'=>CartPrimitive::SET_QUANTITY,
            'semantic_type'=>CartCommand::UPDATE,
            'phase'=>'single',
            'command_index'=>0,
            'cart_item_key'=>'line-a',
            'previous_quantity'=>1.0,
            'quantity'=>2.0,
            'display_name'=>'Product 10',
        )
    );
    same($before->fingerprint(),$effect['before_line_fingerprint']);
    same($after->fingerprint(),$effect['post_line_fingerprint']);
    $verifier->assertSealed($primitive,$pre,$post,$effect);
});
test('Quantity step verification rejects target identity or unrelated-line changes', function (): void {
    $before=line('line-a',1,10,0,array('attribute_size'=>'small'),array('weight'=>'100g'));
    $identityChanged=line('line-a',2,11,0,array('attribute_size'=>'small'),array('weight'=>'200g'));
    $otherBefore=line('line-b',1,20,0,array(),array('stable'=>'yes'));
    $otherChanged=line('line-b',1,20,0,array(),array('stable'=>'no'));
    $primitive=CartPrimitive::setQuantity(
        CartCommand::UPDATE,0,'single','line-a',$before->fingerprint(),2,'Product 10'
    );
    $draft=array(
        'primitive_type'=>CartPrimitive::SET_QUANTITY,'semantic_type'=>CartCommand::UPDATE,
        'phase'=>'single','command_index'=>0,'cart_item_key'=>'line-a',
        'previous_quantity'=>1.0,'quantity'=>2.0,'display_name'=>'Product 10',
    );
    throws(RuntimeException::class,static function()use($primitive,$before,$identityChanged,$draft):void{
        (new CartStepVerifier())->seal(
            $primitive,snapshot(array($before),array(),1,10,'before'),
            snapshot(array($identityChanged),array(),2,20,'after'),$draft
        );
    },'identity changed');
    $targetAfter=line('line-a',2,10,0,array('attribute_size'=>'small'),array('weight'=>'100g'));
    throws(RuntimeException::class,static function()use($primitive,$before,$targetAfter,$otherBefore,$otherChanged,$draft):void{
        (new CartStepVerifier())->seal(
            $primitive,snapshot(array($before,$otherBefore),array(),2,20,'before'),
            snapshot(array($targetAfter,$otherChanged),array(),3,30,'after'),$draft
        );
    },'unexpected cart authority delta');
});
test('Aggregate update preserves exact target metadata and unrelated authority', function (): void {
    $before=line('line-a',1,10,0,array(),array('weight'=>'100g'));
    $after=line('line-a',2,10,0,array(),array('weight'=>'100g'));
    $other=line('line-b',1,20,0,array(),array('gift'=>'yes'));
    $plan=new CartPlan(array(CartCommand::update('line-a',$before->fingerprint(),2,'Product 10')));
    $applied=new AppliedCartPlan(array(array(
        'type'=>'update','cart_item_key'=>'line-a','previous_quantity'=>1.0,
        'display_name'=>'Product 10','quantity'=>2.0,
    )));
    $result=(new CartDeltaVerifier())->verify(
        $plan,
        snapshot(array($before,$other),array('SAVE10'),2,20,'before'),
        snapshot(array($after,$other),array('SAVE10'),3,30,'after'),
        $applied
    );
    ok($result->isVerified());
});
test('Aggregate update rejects target identity and unrelated-line metadata changes', function (): void {
    $before=line('line-a',1,10,0,array('attribute_size'=>'small'),array('weight'=>'100g'));
    $identityChanged=line('line-a',2,11,0,array('attribute_size'=>'small'),array('weight'=>'200g'));
    $otherBefore=line('line-b',1,20,0,array(),array('gift'=>'yes'));
    $otherChanged=line('line-b',1,20,0,array(),array('gift'=>'no'));
    $plan=new CartPlan(array(CartCommand::update('line-a',$before->fingerprint(),2,'Product 10')));
    $applied=new AppliedCartPlan(array(array(
        'type'=>'update','cart_item_key'=>'line-a','previous_quantity'=>1.0,
        'display_name'=>'Product 10','quantity'=>2.0,
    )));
    $result=(new CartDeltaVerifier())->verify(
        $plan,snapshot(array($before),array(),1,10,'before'),
        snapshot(array($identityChanged),array(),2,20,'after'),$applied
    );
    ok(!$result->isVerified()); same('update_target_identity_changed',$result->reason());
    $targetAfter=line('line-a',2,10,0,array('attribute_size'=>'small'),array('weight'=>'100g'));
    $result=(new CartDeltaVerifier())->verify(
        $plan,snapshot(array($before,$otherBefore),array(),2,20,'before'),
        snapshot(array($targetAfter,$otherChanged),array(),3,30,'after'),$applied
    );
    ok(!$result->isVerified()); same('unexpected_cart_delta',$result->reason());
});
test('Existing add target preserves exact target metadata', function (): void {
    $before=line('line-a',1,10,0,array(),array('bundle_count'=>1));
    $after=line('line-a',3,10,0,array(),array('bundle_count'=>1));
    $primitive=CartPrimitive::add(
        CartCommand::ADD,0,'single',10,0,2,str_repeat('a',64),'Product 10'
    );
    $effect=(new CartStepVerifier())->seal(
        $primitive,snapshot(array($before),array(),1,10,'before'),
        snapshot(array($after),array(),3,30,'after'),array(
            'primitive_type'=>CartPrimitive::ADD,'semantic_type'=>CartCommand::ADD,
            'phase'=>'single','command_index'=>0,'cart_item_key'=>'line-a',
            'previous_quantity'=>1.0,'quantity'=>2.0,'product_id'=>10,'variation_id'=>0,
            'display_name'=>'Product 10',
        )
    );
    same($after->fingerprint(),$effect['post_line_fingerprint']);

    $plan=new CartPlan(array(cartAddCommandForTest(10,0,2,'Product 10')));
    $applied=new AppliedCartPlan(array(array(
        'type'=>'add','cart_item_key'=>'line-a','previous_quantity'=>1.0,'quantity'=>2.0,
        'product_id'=>10,'variation_id'=>0,'display_name'=>'Product 10',
    )));
    $result=(new CartDeltaVerifier())->verify(
        $plan,snapshot(array($before),array(),1,10,'before'),
        snapshot(array($after),array(),3,30,'after'),$applied
    );
    ok($result->isVerified());
});
test('Cart verification failures retain exact internal diagnostic reasons', function (): void {
    $root=YSAI_PROJECT_ROOT.'/src/Infrastructure/WooCommerce/Cart/';
    $engine=(string)file_get_contents($root.'CartStepExecutionEngine.php');
    $coordinator=(string)file_get_contents($root.'CartOperationCoordinator.php');
    contains("cart_step_verification_failed",$engine);
    contains("'reason' => \$exception->getMessage()",$engine);
    contains("cart_semantic_verification_failed",$coordinator);
    contains("'reason' => \$verification->reason()",$coordinator);
});
test('Cart mutation has one final totals calculation before sealing and no post-seal recalculation', function (): void {
    if (!class_exists('WooCommerce')) { eval('class WooCommerce {}'); }
    $hadWoo=array_key_exists('ysai_test_wc',$GLOBALS);
    $previousWoo=$GLOBALS['ysai_test_wc']??null;
    $cart=new class {
        public $calculations=0;
        public $stages=0;
        public function calculate_totals(): void { ++$this->calculations; }
        public function set_session(): void { ++$this->stages; }
    };
    $GLOBALS['ysai_test_wc']=(object)array(
        'session'=>new YsaiTestWooSession(),
        'customer'=>(object)array(),
        'cart'=>$cart,
    );
    try {
        $gateway=new WooCartGateway(new WooSession());
        $gateway->calculate();
        same(1,$cart->calculations);
        $gateway->stageCurrentSession();
        same(1,$cart->calculations,'Session staging must not invoke totals hooks again.');
        same(1,$cart->stages);
    } finally {
        if ($hadWoo) { $GLOBALS['ysai_test_wc']=$previousWoo; }
        else { unset($GLOBALS['ysai_test_wc']); }
    }

    $root=YSAI_PROJECT_ROOT.'/src/Infrastructure/WooCommerce/Cart/';
    $snapshot=(string)file_get_contents($root.'CartSnapshotFactory.php');
    $gateway=(string)file_get_contents($root.'WooCartGateway.php');
    $store=(string)file_get_contents($root.'WooSessionCartStore.php');
    $engine=(string)file_get_contents($root.'CartStepExecutionEngine.php');
    $evidence=(string)file_get_contents($root.'CartStateEvidence.php');

    same(1,substr_count($snapshot,'$this->gateway->calculate();'));
    contains('return $this->captureCurrent();',$snapshot);
    contains('public function captureCurrent(): CartSnapshot',$snapshot);

    contains('public function stageCurrentSession(): void',$gateway);
    notContains('public function stageSession(): void',$gateway);
    notContains('$this->calculate();',$gateway);
    contains('public function stageCurrentWorkingCart(): WooSessionCartEnvelope',$store);
    contains('$this->gateway->stageCurrentSession();',$store);
    notContains('stageWorkingCart()',$store);

    same(1,substr_count($engine,'$this->snapshots->capture();'));
    contains('$this->snapshots->captureCurrent();',$engine);
    contains('$this->store->stageCurrentWorkingCart();',$engine);
    $execute=strpos($engine,'$this->executor->execute');
    $calculate=strpos($engine,'$this->snapshots->capture();',(int)$execute);
    $seal=strpos($engine,'$this->verifier->seal',(int)$calculate);
    $stage=strpos($engine,'$this->store->stageCurrentWorkingCart();',(int)$seal);
    ok($execute!==false&&$calculate!==false&&$seal!==false&&$stage!==false
        &&$execute<$calculate&&$calculate<$seal&&$seal<$stage,
        'The primitive must calculate once before sealing, then stage without recalculating.');

    contains('return $this->snapshots->captureCurrent();',$evidence);
    notContains('$this->snapshots->capture();',$evidence);
});
test('Monotonic execution deadline fails closed at a boundary', function (): void {
    $now=100.0; $deadline=new ExecutionDeadline(10.0,function()use(&$now):float{return $now;}); same(10.0,$deadline->remainingSeconds());
    $now=109.5; ok(!$deadline->hasBudget(1.0)); throws(ExecutionBudgetException::class,function()use($deadline):void{$deadline->assertBudget('terminal_commit',1.0);});
});
test('Provider timeout-aware sessions consume exactly one bounded timeout', function (): void {
    $transport=new RecordingTimeoutTransport();
    $request=new ModelRequest(
        'System',array(),'Show cart',array(),
        array(array('name'=>'cart_view','description'=>'View cart','parameters'=>ToolSchemas::emptyObject())),
        1024
    );
    $session=new GeminiSession($transport,new GeminiResponseDecoder(),$request,array());
    $session->setNextTimeoutSeconds(7);
    $step=$session->next();
    same('cart_view',$step->calls()[0]->name());
    same(array(7),$transport->timeouts);
    same(0,$transport->generateCalls);
});
test('Gemini transport retries share one monotonic provider budget', function (): void {
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array(
        'gemini_api_key'=>'test-key'
    )));
    $settings=new Settings(); $now=100.0; $timeouts=array(); $calls=0;
    $GLOBALS['ysai_test_http_handler']=static function(string $url,array $args)use(&$now,&$timeouts,&$calls){
        ++$calls; $timeouts[]=(float)($args['timeout']??0.0); $now+=0.2;
        return array('status'=>500,'body'=>'{}');
    };
    $transport=new GeminiTransport(
        $settings,
        new \YassinStore\AiAssistant\Infrastructure\WordPress\Logger($settings),
        new GeminiEndpoint('https://generativelanguage.googleapis.com/v1beta/models'),
        static function()use(&$now):float{return $now;},
        static function(float $seconds)use(&$now):void{$now+=$seconds;}
    );
    throws(GeminiException::class,static function()use($transport):void{$transport->generateWithTimeout(array(),3);},'upstream_unavailable');
    same(2,$calls); ok($timeouts[0]<=3.0 && $timeouts[0]>2.9); ok($timeouts[1]<$timeouts[0]);

    $calls=0; $timeouts=array(); $now=200.0;
    $GLOBALS['ysai_test_http_handler']=static function(string $url,array $args)use(&$now,&$timeouts,&$calls){
        ++$calls; $timeouts[]=(float)($args['timeout']??0.0); $now+=(float)($args['timeout']??0.0);
        return new WP_Error('timeout','timeout');
    };
    $transport=new GeminiTransport(
        $settings,
        new \YassinStore\AiAssistant\Infrastructure\WordPress\Logger($settings),
        new GeminiEndpoint('https://generativelanguage.googleapis.com/v1beta/models'),
        static function()use(&$now):float{return $now;},
        static function(float $seconds)use(&$now):void{$now+=$seconds;}
    );
    throws(GeminiException::class,static function()use($transport):void{$transport->generateWithTimeout(array(),2);},'network_error');
    same(1,$calls); ok($timeouts[0]<=2.0 && $timeouts[0]>1.9);

    $calls=0; $timeouts=array(); $now=300.0;
    $GLOBALS['ysai_test_http_handler']=static function(string $url,array $args)use(&$now,&$timeouts,&$calls){
        ++$calls; $timeouts[]=(float)($args['timeout']??0.0); $now+=(float)($args['timeout']??0.0)+0.01;
        return array('status'=>200,'body'=>'{}');
    };
    $transport=new GeminiTransport(
        $settings,
        new \YassinStore\AiAssistant\Infrastructure\WordPress\Logger($settings),
        new GeminiEndpoint('https://generativelanguage.googleapis.com/v1beta/models'),
        static function()use(&$now):float{return $now;},
        static function(float $seconds)use(&$now):void{$now+=$seconds;}
    );
    throws(GeminiException::class,static function()use($transport):void{$transport->generateWithTimeout(array(),2);},'provider_timeout');
    same(1,$calls);
    unset($GLOBALS['ysai_test_http_handler']);
});
test('Gemini transport classifies only bounded structured Google error reasons', function (): void {
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array(
        'gemini_api_key'=>'test-key'
    )));
    $settings=new Settings();
    $fixtures=array(
        array(400,array('error'=>array(
            'status'=>'INVALID_ARGUMENT','message'=>'RAW API KEY MESSAGE',
            'details'=>array(array(
                '@type'=>'type.googleapis.com/google.rpc.ErrorInfo',
                'reason'=>'API_KEY_INVALID','metadata'=>array('secret'=>'RAW METADATA'),
            )),
        )),'authentication_error','بيانات الدخول'),
        array(400,array('error'=>array(
            'status'=>'INVALID_ARGUMENT','message'=>'RAW BLOCK MESSAGE',
            'details'=>array(array('reason'=>'API_KEY_HTTP_REFERRER_BLOCKED')),
        )),'authentication_error','بيانات الدخول'),
        array(403,array('error'=>array(
            'status'=>'PERMISSION_DENIED','message'=>'RAW SERVICE MESSAGE',
            'details'=>array(array('reason'=>'SERVICE_DISABLED')),
        )),'provider_service_disabled','غير مفعلة'),
        array(403,array('error'=>array(
            'status'=>'PERMISSION_DENIED','message'=>'RAW BILLING MESSAGE',
            'details'=>array(array('reason'=>'BILLING_DISABLED')),
        )),'provider_billing_disabled','الفوترة'),
        array(400,array('error'=>array(
            'status'=>'FAILED_PRECONDITION','message'=>'RAW PRECONDITION MESSAGE',
        )),'request_precondition_rejected','متطلبات هذا الطلب'),
        array(400,array('error'=>array(
            'status'=>'INVALID_ARGUMENT','message'=>'RAW CONTRACT MESSAGE',
            'details'=>array(array('reason'=>'provider_message_is_not_a_safe_reason')),
        )),'request_contract_rejected','بروتوكول الطلب'),
        array(400,'{not-json','request_contract_rejected','بروتوكول الطلب'),
    );
    $safeMessages=array();
    foreach($fixtures as $index=>$fixture){
        [$httpStatus,$body,$expectedCode,$safeFragment]=$fixture;
        $raw=is_array($body)?Json::encodeObject($body):(string)$body;
        $GLOBALS['ysai_test_http_handler']=static function(string $url,array $args)use($httpStatus,$raw):array{
            unset($url,$args); return array('status'=>$httpStatus,'body'=>$raw);
        };
        $transport=new GeminiTransport(
            $settings,
            new \YassinStore\AiAssistant\Infrastructure\WordPress\Logger($settings),
            new GeminiEndpoint('https://generativelanguage.googleapis.com/v1beta/models')
        );
        try {
            $transport->generateWithTimeout(array(),3);
            throw new TestFailure('Expected structured Gemini rejection fixture '.$index.'.');
        } catch (GeminiException $exception) {
            same($expectedCode,$exception->reasonCode());
            contains($safeFragment,$exception->safeMessage());
            notContains('RAW',$exception->safeMessage());
            notContains('RAW',$exception->getMessage());
            $safeMessages[$expectedCode]=$exception->safeMessage();
        }
    }
    ok(($safeMessages['authentication_error']??'')!==($safeMessages['provider_service_disabled']??''));
    ok(($safeMessages['provider_service_disabled']??'')!==($safeMessages['provider_billing_disabled']??''));
    ok(($safeMessages['request_precondition_rejected']??'')!==($safeMessages['request_contract_rejected']??''));
    unset($GLOBALS['ysai_test_http_handler']);
});
test('Gemini contract diagnostics retain only a closed structural provider field path', function (): void {
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array(
        'gemini_api_key'=>'test-key'
    )));
    $rawDescription='RAW PROVIDER DESCRIPTION WITH customer@example.com AND SECRET';
    $body=Json::encodeObject(array('error'=>array(
        'status'=>'INVALID_ARGUMENT',
        'message'=>'RAW PROVIDER MESSAGE MUST NEVER SURVIVE',
        'details'=>array(array(
            '@type'=>'type.googleapis.com/google.rpc.BadRequest',
            'fieldViolations'=>array(
                array('field'=>'api_key','description'=>'RAW UNSAFE ROOT'),
                array(
                    'field'=>'tools[0].function_declarations[3].parameters.properties[commands].items',
                    'description'=>$rawDescription,
                ),
            ),
        )),
    )));
    $GLOBALS['ysai_test_http_handler']=static function(string $url,array $args)use($body):array{
        unset($url,$args); return array('status'=>400,'body'=>$body);
    };
    $settings=new Settings();
    $transport=new GeminiTransport(
        $settings,
        new \YassinStore\AiAssistant\Infrastructure\WordPress\Logger($settings),
        new GeminiEndpoint('https://generativelanguage.googleapis.com/v1beta/models')
    );
    try {
        $transport->generateWithTimeout(array(),3);
        throw new TestFailure('Expected the provider contract rejection.');
    } catch (GeminiException $exception) {
        same('request_contract_rejected',$exception->reasonCode());
        same(
            'tools[0].function_declarations[3].parameters.properties[*].items',
            $exception->providerField()
        );
        notContains('RAW PROVIDER',$exception->safeMessage());
        notContains('RAW PROVIDER',$exception->getMessage());
        notContains('customer@example.com',$exception->getMessage());
    } finally {
        unset($GLOBALS['ysai_test_http_handler']);
    }
    same('',GeminiException::normalizeProviderField('api_key'));
    same('',GeminiException::normalizeProviderField('error.details[0].field'));
    same('',GeminiException::normalizeProviderField('contents.customerSecretABC123'));
    same(
        'tools[0].functionDeclarations[3].parameters.properties[*].items',
        GeminiException::normalizeProviderField(
            'tools[0].functionDeclarations[3].parameters.properties.customerSecretABC123.items'
        )
    );
    same('',GeminiException::normalizeProviderField(str_repeat('tools.',100)));
});
test('Execution supervisor renews only before side effects and caps provider timeout', function (): void {
    $resource='conversation|test';
    $lease=new TurnLease($resource,hash('sha256',$resource),str_repeat('a',32),1,time()+30);
    $port=new RecordingTurnLeasePort(); $port->remaining=2.0;
    $now=100.0;
    $supervisor=new TurnExecutionSupervisor(
        $port,$lease,new ExecutionDeadline(60.0,function()use(&$now):float{return $now;}),45
    );
    $supervisor->before(ExecutionBoundary::TOOL_BATCH,5.0,true);
    same(1,$port->renewals);
    same(30,$supervisor->providerTimeout(90,30.0));

    $port->remaining=2.0;
    throws(ExecutionBudgetException::class,function()use($supervisor):void{
        $supervisor->before(ExecutionBoundary::CART_STEP,5.0,false);
    },ExecutionBoundary::CART_STEP);
    same(1,$port->renewals);

    $port->remaining=100.0;
    $supervisor->after(ExecutionBoundary::CART_STEP,true);
    $port->remaining=2.0;
    throws(ExecutionBudgetException::class,function()use($supervisor):void{
        // The default argument must not reopen renewal after any earlier
        // side-effect boundary has sealed the supervisor.
        $supervisor->before(ExecutionBoundary::PROVIDER_REQUEST,5.0,true);
    },ExecutionBoundary::PROVIDER_REQUEST);
    same(1,$port->renewals);
});
test('Execution supervisor counts primary and isolated semantic provider requests once', function (): void {
    $resource='conversation|provider-budget';
    $lease=new TurnLease($resource,hash('sha256',$resource),str_repeat('b',32),1,time()+300);
    $port=new RecordingTurnLeasePort();
    $port->remaining=300.0;
    $supervisor=new TurnExecutionSupervisor(
        $port,$lease,new ExecutionDeadline(300.0),300,2
    );
    $supervisor->before(ExecutionBoundary::PROVIDER_REQUEST);
    $supervisor->after(ExecutionBoundary::PROVIDER_REQUEST);
    $supervisor->before(ExecutionBoundary::PROVIDER_REQUEST);
    $supervisor->after(ExecutionBoundary::PROVIDER_REQUEST);
    throws(ExecutionBudgetException::class,static function()use($supervisor):void{
        $supervisor->before(ExecutionBoundary::PROVIDER_REQUEST);
    },'provider-request budget');
});
test('Agent provider boundary is closed even when the provider throws', function (): void {
    $conversationId=Uuid::v4();
    $resource='conversation|'.$conversationId;
    $lease=new TurnLease(
        $resource,hash('sha256',$resource),str_repeat('f',32),1,time()+120
    );
    $port=new RecordingTurnLeasePort();
    $supervisor=new TurnExecutionSupervisor(
        $port,$lease,new ExecutionDeadline(60.0),60
    );
    $context=new AgentContext(
        array('id'=>1,'public_id'=>$conversationId,'state'=>array()),
        Uuid::v4(),str_repeat('a',64),new AuthorityRegistry(),
        new TurnEffects(),$lease,$supervisor,'مرحبا'
    );
    $session=new class implements ModelSessionInterface {
        public function next(): ModelStep { throw new RuntimeException('Injected provider failure.'); }
        public function submit(ModelStep $step,array $feedback): void {}
        public function correctPlainOutput(ModelStep $step,string $instruction): void {}
    };
    throws(RuntimeException::class,static function()use($session,$context):void{
        modelLoopForTest(array(new RespondAnswerHandler()))->run($session,$context);
    },'Injected provider failure');
    same(2,$port->assertions,'Provider before and after checks must both run.');
});

test('Uncertain cart journal writes become retryable pending outcomes before generic rejection', function (): void {
    $source=(string)file_get_contents(
        YSAI_PROJECT_ROOT.'/src/Infrastructure/WooCommerce/Cart/CartOperationTerminalizer.php'
    );
    foreach(array(
        'cart_uncertain_persistence_pending',
        'cart_step_rejection_persistence_pending',
        'cart_step_uncertain_persistence_pending',
    ) as $code){
        contains("'".$code."'",$source);
    }
    $uncertainStart=strpos($source,'public function uncertain(');
    $resultStart=strpos($source,'public function result(');
    ok($uncertainStart!==false&&$resultStart!==false&&$uncertainStart<$resultStart);
    $terminalTransitions=substr($source,(int)$uncertainStart,(int)$resultStart-(int)$uncertainStart);
    same(3,substr_count($terminalTransitions,'throw $this->messages->pending('));
});
test('Public API contract is closed at every nested object boundary', function (): void {
    $raw=Json::decodeRequiredObject((string)file_get_contents(YSAI_PROJECT_ROOT.'/config/public-api-contract.json'),'contract');
    $contract=new PublicApiContract($raw);
    same(3,$raw['x-contract-version']??null);
    same(3,$contract->contractVersion());
    same(GeneratedPublicApiContract::CONTRACT_VERSION,$contract->contractVersion());
    same('yassin-ai/v1',$contract->namespace());
    same(24,$raw['x-runtime']['transcript_max_rows']??null);
    same(24,$contract->transcriptMaxRows());
    same((new ConversationContextWindow())->terminalTurnLimit()*2,$contract->transcriptMaxRows());
    same(
        array('ok','message','turn_committed','conversation','messages_available','messages_notice','cart','cart_available','cart_notice','cart_mutations'),
        array_keys($contract->responseSchema('turn_response')['properties'])
    );
    foreach($raw['$defs'] as $name=>$definition){
        if(is_array($definition)&&($definition['type']??null)==='object'){
            same(false,$definition['additionalProperties']??null,'Public object must be closed: '.(string)$name);
        }
    }
    $raw['x-runtime']['legacy_limit']=1;
    throws(InvalidArgumentException::class,function()use($raw):void{new PublicApiContract($raw);},'runtime fields are invalid');
});

test('PHP public response validation accepts every generated response fixture and rejects every invalid response fixture', function (): void {
    $fixtures=Json::decodeRequiredObject(
        (string)file_get_contents(YSAI_PROJECT_ROOT.'/tests/fixtures/public-api-contract-examples.json'),
        'public response fixtures'
    );
    $validator=publicResponseValidatorForTest();
    $responses=GeneratedPublicApiContract::RESPONSE_DEFINITIONS;
    $validCoverage=array();
    foreach($fixtures['valid']??array() as $row){
        if(!is_array($row)||!in_array($row['schema']??null,$responses,true)){continue;}
        $validator->assertResponse((string)$row['schema'],(array)$row['value']);
        $validCoverage[(string)$row['schema']]=true;
    }
    $invalidCoverage=array();
    foreach($fixtures['invalid']??array() as $row){
        if(!is_array($row)||!in_array($row['schema']??null,$responses,true)){continue;}
        $definition=(string)$row['schema'];
        $payload=(array)$row['value'];
        throws(PublicResponseContractViolation::class,static function()use($validator,$definition,$payload):void{
            $validator->assertResponse($definition,$payload);
        });
        $invalidCoverage[$definition]=true;
    }
    $expected=$responses;
    sort($expected,SORT_STRING);
    $valid=array_keys($validCoverage);sort($valid,SORT_STRING);
    $invalid=array_keys($invalidCoverage);sort($invalid,SORT_STRING);
    same($expected,$valid,'Every public response requires a generated valid PHP fixture.');
    same($expected,$invalid,'Every public response requires a generated invalid PHP fixture.');
});

test('Exact clean schema contains only current authority and durable cart journal tables', function (): void {
    $tables=(new SchemaDefinition('wp_','utf8mb4','utf8mb4_unicode_ci'))->tables();
    same(array(
        'browser_continuity_authorities','conversations','messages','turns','operations',
        'operation_steps','operation_step_attempts','leases','rate_limits'
    ),array_keys($tables));
    ok(isset($tables['operations']['indexes']['commerce_resource_hash']));
    ok(isset($tables['operation_steps']['indexes']['operation_step']));
    ok(isset($tables['operation_step_attempts']['indexes']['step_attempt']));
});
test('Browser authority nonce uses exact case-sensitive binary storage', function (): void {
    $definition=new SchemaDefinition('wp_','utf8mb4','utf8mb4_unicode_ci');
    $tables=$definition->tables();
    $column=$tables['browser_continuity_authorities']['columns']['session_nonce']??array();
    same('binary(43)',(string)($column['type']??''));
    same('binary(43) NOT NULL',(string)($column['sql']??''));
    same(false,(bool)($column['nullable']??true));
    $statement=$definition->createStatements()['wp_ysai_browser_continuity_authorities']??'';
    contains('session_nonce binary(43) NOT NULL',(string)$statement);
    notContains('session_nonce char(43)',(string)$statement);
});
test('Persistent cart decoder rejects objects recursion malformed input and oversized authority', function (): void {
    $decoder=new SafePersistentCartDecoder();
    $expected=array('cart'=>array('line'=>array('product_id'=>10,'quantity'=>2)));
    same($expected,$decoder->decode(serialize($expected)));
    throws(RuntimeException::class,function()use($decoder):void{$decoder->decode('not-serialized');},'not a serialized array');
    throws(RuntimeException::class,function()use($decoder):void{$decoder->decode(serialize(array('unsafe'=>new stdClass())));},'unsafe value');
    $recursive=array(); $recursive['self']=&$recursive;
    throws(RuntimeException::class,function()use($decoder,$recursive):void{$decoder->decode(serialize($recursive));},'recursive value');
    throws(RuntimeException::class,function()use($decoder):void{$decoder->decode(str_repeat('x',8388609));},'exceeds the allowed size');
});
test('Fenced Woo cart storage requires InnoDB and exact lock-supporting indexes', function (): void {
    $sessionIndexes=array(
        array('INDEX_NAME'=>'PRIMARY','NON_UNIQUE'=>0,'SEQ_IN_INDEX'=>1,'COLUMN_NAME'=>'session_id'),
        array('INDEX_NAME'=>'session_key','NON_UNIQUE'=>0,'SEQ_IN_INDEX'=>1,'COLUMN_NAME'=>'session_key'),
    );
    $userMetaIndexes=array(
        array('INDEX_NAME'=>'PRIMARY','NON_UNIQUE'=>0,'SEQ_IN_INDEX'=>1,'COLUMN_NAME'=>'umeta_id'),
        array('INDEX_NAME'=>'user_id','NON_UNIQUE'=>1,'SEQ_IN_INDEX'=>1,'COLUMN_NAME'=>'user_id'),
        array('INDEX_NAME'=>'meta_key','NON_UNIQUE'=>1,'SEQ_IN_INDEX'=>1,'COLUMN_NAME'=>'meta_key'),
    );
    $database=new CartStorageTopologyDatabase(array('InnoDB','InnoDB'),array($sessionIndexes,$userMetaIndexes));
    $policy=new WooStorageTopologyPolicy($database);
    $policy->assertSupported('wp_woocommerce_sessions','wp_usermeta');
    same(2,$database->engineReads); same(2,$database->indexReads);
    $policy->assertSupported('wp_woocommerce_sessions','wp_usermeta');
    same(2,$database->engineReads); same(2,$database->indexReads);

    $myisam=new WooStorageTopologyPolicy(new CartStorageTopologyDatabase(array('MyISAM'),array()));
    throws(RuntimeException::class,function()use($myisam):void{$myisam->assertSupported('wp_woocommerce_sessions',null);},'must use InnoDB');

    $nonUniqueSession=array(array('INDEX_NAME'=>'session_key','NON_UNIQUE'=>1,'SEQ_IN_INDEX'=>1,'COLUMN_NAME'=>'session_key'));
    $badSession=new WooStorageTopologyPolicy(new CartStorageTopologyDatabase(array('InnoDB'),array($nonUniqueSession)));
    throws(RuntimeException::class,function()use($badSession):void{$badSession->assertSupported('wp_woocommerce_sessions',null);},'unique session_key');

    $badUserMeta=new WooStorageTopologyPolicy(new CartStorageTopologyDatabase(array('InnoDB','InnoDB'),array($sessionIndexes,array())));
    throws(RuntimeException::class,function()use($badUserMeta):void{$badUserMeta->assertSupported('wp_woocommerce_sessions','wp_usermeta');},'indexes required');

    $invalid=new WooStorageTopologyPolicy(new CartStorageTopologyDatabase(array(),array()));
    throws(RuntimeException::class,function()use($invalid):void{$invalid->assertSupported('wp_woocommerce_sessions;DROP',null);},'table name is invalid');
});
test('Cart capability and persistent projection share the verified storage boundary', function (): void {
    $root=YSAI_PROJECT_ROOT.'/src/Infrastructure/WooCommerce/Cart/';
    $store=(string)file_get_contents($root.'WooSessionCartStore.php');
    $persistent=(string)file_get_contents($root.'WooPersistentCartStore.php');
    $proof=(string)file_get_contents($root.'CartMutationCapabilityProof.php');
    $inspector=(string)file_get_contents($root.'CartMutationCapabilityInspector.php');
    $engine=(string)file_get_contents($root.'CartStepExecutionEngine.php');
    $quarantine=(string)file_get_contents($root.'CartMutationCapabilityQuarantine.php');
    $coordinator=(string)file_get_contents($root.'CartOperationCoordinator.php');
    $executor=(string)file_get_contents($root.'CartCommandExecutor.php');
    contains('$this->topology->assertSupported($this->table(), $userMetaTable)',$store);
    contains('$this->assertSupported();',$store);
    $storeMutationStart=strpos($store,'public function beginAuthoritativeMutation(): void');
    $storeVersion=strpos($store,'$this->session->assertVerifiedCartMutationVersion();',(int)$storeMutationStart);
    $storeTopology=strpos($store,'$this->assertSupported();',(int)$storeMutationStart);
    $storeSuppress=strpos($store,'$this->session->suppressAutomaticSave();',(int)$storeMutationStart);
    ok($storeMutationStart!==false&&$storeVersion!==false&&$storeTopology!==false&&$storeSuppress!==false
        &&$storeMutationStart<$storeVersion&&$storeVersion<$storeTopology&&$storeTopology<$storeSuppress,
        'Direct Woo session mutation must retain its own promotion-version and topology defense.');
    contains('$this->decoder->decode((string) $value)',$persistent);
    contains('public function assertVerifiedForUpdate(): void',$persistent);
    contains('$this->persistentCart->assertVerifiedForUpdate();',$store);
    $assertionStart=strpos($persistent,'public function assertVerifiedForUpdate(): void');
    $assertionEnd=strpos($persistent,'public function invalidateCache()', (int)$assertionStart);
    ok($assertionStart!==false&&$assertionEnd!==false&&$assertionStart<$assertionEnd);
    $assertionBody=substr($persistent,(int)$assertionStart,(int)$assertionEnd-(int)$assertionStart);
    contains('FOR UPDATE',$assertionBody);
    notContains('INSERT INTO',$assertionBody);
    notContains('UPDATE ',$assertionBody);
    notContains('DELETE FROM',$assertionBody);
    contains('$this->requestFence->assertProtectsActiveSession()',$proof);
    contains('$this->requestFence->assertCanProtectActiveSession()',$proof);
    $protectedStart=strpos($proof,'public function beginProtectedMutation(): void');
    $availableFirst=strpos($proof,'$this->assertAvailable();',(int)$protectedStart);
    $reacquire=strpos($proof,'$this->requestFence->reacquireForMutation();',(int)$protectedStart);
    $authoritative=strpos($proof,'$this->store->beginAuthoritativeMutation();',(int)$protectedStart);
    $refresh=strpos($proof,'$this->store->refreshWorkingFromDurable();',(int)$protectedStart);
    $activeProof=strpos($proof,'$this->assertSupported();',(int)$refresh);
    ok($protectedStart!==false&&$availableFirst!==false&&$reacquire!==false
        &&$authoritative!==false&&$refresh!==false&&$activeProof!==false
        &&$protectedStart<$availableFirst&&$availableFirst<$reacquire
        &&$reacquire<$authoritative&&$authoritative<$refresh&&$refresh<$activeProof,
        'Mutation capability must be proved before fence reacquire and direct Woo session manipulation.');
    contains('$this->store->assertSupported();',$proof);
    contains('$this->session->assertCartMutationCapability();',$proof);
    contains('$this->proof->assertAvailable();',$inspector);
    contains('$this->capability->assertSupported();',$engine);
    contains('$this->capability->assertSupported();',$executor);
    contains('$this->capability->available()',$quarantine);
    contains("count(\$steps) === 1 && \$steps[0]->status() === CartStepStatus::REJECTED",$quarantine);
    contains('$this->lossPolicy->provesNoEffect($operation, $steps, $latestAttempts)',$quarantine);
    contains('CartStepStatus::REJECTED',$quarantine);
    contains("'changed' => false",$quarantine);
    contains('$this->operations->markRejected(',$quarantine);
    $executeStart=strpos($coordinator,'public function execute(');
    $recoverStart=strpos($coordinator,'public function recoverForTurn(');
    $runStart=strpos($coordinator,'private function runExisting(');
    ok($executeStart!==false&&$recoverStart!==false&&$runStart!==false);
    $executeBody=substr($coordinator,(int)$executeStart,(int)$recoverStart-(int)$executeStart);
    $existingLookup=strpos($executeBody,'$this->operations->findByTurn(');
    $existingReject=strpos($executeBody,'An existing cart operation must be reconciled before agent execution.');
    $newProof=strpos($executeBody,'$this->beginProtectedMutation(null)');
    $executeLease=strpos($executeBody,'$this->scope->withCommerceLease(');
    $executeSession=strpos($executeBody,'$this->stepEngine->beginControlledSession(false)',(int)$executeLease);
    ok($existingLookup!==false&&$existingReject!==false&&$newProof!==false
        &&$executeLease!==false&&$executeSession!==false
        &&$existingLookup<$existingReject&&$existingReject<$newProof
        &&$newProof<$executeLease&&$executeLease<$executeSession,
        'New execution must reject durable collisions, prove capability, acquire the commerce lease, and then begin a fresh controlled session.');
    notContains('$this->quarantine->enforce(',$executeBody);
    notContains('$this->beginProtectedMutation($existing)',$executeBody);
    $recoverBody=substr($coordinator,(int)$recoverStart,(int)$runStart-(int)$recoverStart);
    $recoverLease=strpos($recoverBody,'$this->scope->withCommerceLease(');
    $recoverQuarantine=strpos($recoverBody,'$this->quarantine->enforce(');
    $recoverRefresh=strpos($recoverBody,'$this->beginProtectedMutation($operation)',(int)$recoverQuarantine);
    $recoverSession=strpos($recoverBody,'$this->stepEngine->beginControlledSession(true)',(int)$recoverRefresh);
    ok($recoverLease!==false&&$recoverQuarantine!==false&&$recoverRefresh!==false&&$recoverSession!==false
        &&$recoverLease<$recoverQuarantine&&$recoverQuarantine<$recoverRefresh&&$recoverRefresh<$recoverSession,
        'Recovery must quarantine before strongest refresh and controlled execution.');
    contains('throw new PersistentCartMismatchException(',$persistent);
    contains('catch (PersistentCartMismatchException $exception)',$coordinator);
    contains("'persistent_cart_final_mismatch'",$coordinator);
    notContains('maybe_unserialize',$persistent);
});
test('Capability-loss policy rejects only mechanically proven pre-attempt no-effect journals', function (): void {
    $preLine=line('line-capability-loss',1);
    $pre=snapshot(array($preLine),array(),1,10,'capability-loss-pre');
    $plan=new CartPlan(array(CartCommand::update(
        'line-capability-loss',$preLine->fingerprint(),2,'Product 10'
    )));
    $operation=function(string $status)use($plan,$pre):OperationRecord{
        return new OperationRecord(
            1,Uuid::v4(),7,Uuid::v4(),str_repeat('a',64),1,$status,$plan,$pre,
            null,null,null,'','',str_repeat('b',64),1
        );
    };
    $primitive=(new CartStepPlanner())->plan($plan,$pre)[0];
    $step=function(string $status)use($primitive,$pre):CartOperationStep{
        return new CartOperationStep(
            11,Uuid::v4(),1,0,0,str_repeat('c',64),1,str_repeat('b',64),1,
            $status,$primitive,$pre,null,null,'','',''
        );
    };
    $policy=new CartMutationCapabilityLossPolicy();
    ok($policy->provesNoEffect($operation(OperationStatus::PREPARED),array(),array()));
    $executing=$operation(OperationStatus::EXECUTING);
    ok($policy->provesNoEffect($executing,array(),array()));
    $prepared=$step(CartStepStatus::PREPARED);
    $applying=$step(CartStepStatus::APPLYING);
    $preparedOperation=$operation(OperationStatus::PREPARED);
    ok($policy->provesNoEffect($preparedOperation,array($prepared),array(11=>null)));
    ok($policy->provesNoEffect($preparedOperation,array($applying),array(11=>null)));
    ok($policy->provesNoEffect($executing,array($prepared),array(11=>null)));
    ok($policy->provesNoEffect($executing,array($applying),array(11=>null)));
    ok(!$policy->provesNoEffect($executing,array($applying),array()));

    $started=new CartStepAttempt(
        21,Uuid::v4(),11,1,1,str_repeat('b',64),1,CartStepAttemptStatus::STARTED,
        '',null,null,null,'',''
    );
    ok(!$policy->provesNoEffect($executing,array($applying),array(11=>$started)),
        'Any durable execution attempt must remain uncertain when capability is lost.');
});
test('Woo cart hydration is fenced while provider wait is unlocked and mutation reacquires', function (): void {
    $root=YSAI_PROJECT_ROOT.'/src';
    $fence=(string)file_get_contents($root.'/Infrastructure/WooCommerce/Cart/WooCartRequestFence.php');
    $protected=(string)file_get_contents($root.'/Infrastructure/WooCommerce/Cart/CartProtectedReadScope.php');
    $bootSnapshot=(string)file_get_contents($root.'/Infrastructure/WooCommerce/Cart/BootCartSnapshot.php');
    $query=(string)file_get_contents($root.'/Infrastructure/WooCommerce/Cart/CartQueryService.php');
    $commerce=(string)file_get_contents($root.'/Infrastructure/Composition/CommerceStack.php');
    $tools=(string)file_get_contents($root.'/Infrastructure/Composition/ToolStack.php');
    $cartTools=(string)file_get_contents($root.'/Application/Tool/Service/CartToolService.php');
    $kernel=(string)file_get_contents($root.'/Infrastructure/Composition/PluginKernel.php');
    contains("add_filter('woocommerce_session_handler', array(\$this, 'beforeSessionHandler'), -1000, 1)",$fence);
    contains("add_action('shutdown', array(\$this, 'release'), PHP_INT_MAX)",$fence);
    contains('register_shutdown_function(array($this, \'release\'))',$fence);
    contains('SELECT GET_LOCK(%s,%d)',$fence);
    contains('SELECT RELEASE_LOCK(%s)',$fence);
    contains('IS_USED_LOCK(%s)=CONNECTION_ID()',$fence);
    contains('public function releaseForProviderWait(): void',$fence);
    contains('public function reacquireForMutation(): void',$fence);
    notContains("!function_exists('wp_verify_fast_hash')",$fence);
    notContains("!function_exists('wc_clean')",$fence);
    contains('$requestFence->register();',$commerce);
    contains('$this->requestFence->assertCanProtectActiveSession();',(string)file_get_contents(
        $root.'/Infrastructure/WooCommerce/Cart/CartMutationCapabilityProof.php'
    ));
    contains('$this->store->refreshWorkingFromDurable();',(string)file_get_contents(
        $root.'/Infrastructure/WooCommerce/Cart/CartMutationCapabilityProof.php'
    ));
    $waitProof=(string)file_get_contents(
        $root.'/Infrastructure/WooCommerce/Cart/CartMutationCapabilityProof.php'
    );
    $releaseStart=strpos($waitProof,'public function releaseForProviderWait(): void');
    $mutationStart=strpos($waitProof,'public function beginProtectedMutation(): void');
    ok($releaseStart!==false&&$mutationStart!==false&&$releaseStart<$mutationStart);
    $releaseBody=substr($waitProof,(int)$releaseStart,(int)$mutationStart-(int)$releaseStart);
    notContains('if (!$this->available())',$releaseBody);
    notContains('$this->store->beginAuthoritativeMutation();',$releaseBody);
    contains('$this->requestFence->assertCanProtectActiveSession();',$releaseBody);
    notContains('$this->requestFence->assertProtectsActiveSession();',$releaseBody);
    contains('$this->session->suppressAutomaticSave();',$releaseBody);
    contains('$this->requestFence->releaseForProviderWait();',$releaseBody);
    $waitSuppress=strpos($releaseBody,'$this->session->suppressAutomaticSave();');
    $waitRelease=strpos($releaseBody,'$this->requestFence->releaseForProviderWait();');
    ok($waitSuppress!==false&&$waitRelease!==false&&$waitSuppress<$waitRelease,
        'Even degraded storage capability must suppress stale Woo writers and release before provider I/O.');
    $readFallback=strpos($protected,'if ($this->requestFence->isUnfencedReadOnlySession())');
    $readFallbackCapture=strpos($protected,'return $this->snapshots->capture();',(int)$readFallback);
    $readReacquire=strpos($protected,'$this->requestFence->reacquireForMutation();');
    $readRefresh=strpos($protected,'$this->store->refreshWorkingFromDurable();');
    $readSnapshot=strpos($protected,'$this->snapshots->capture();',(int)$readRefresh);
    $readRelease=strpos($protected,'$this->capability->releaseForProviderWait();');
    ok($readFallback!==false&&$readFallbackCapture!==false
        &&$readReacquire!==false&&$readRefresh!==false
        &&$readSnapshot!==false&&$readRelease!==false
        &&$readFallback<$readFallbackCapture&&$readFallbackCapture<$readReacquire
        &&$readReacquire<$readRefresh&&$readRefresh<$readSnapshot
        &&$readSnapshot<$readRelease,
        'Protected cart reads must reacquire, refresh, snapshot, and release in that order.');
    notContains('$this->store->beginAuthoritativeMutation();',$protected);
    contains('finally {',$protected);
    contains('throw new OperationPendingException(',$protected);
    contains('CartSnapshotProviderPort $snapshots',$query);
    contains('return $this->snapshots->capture()->forClient($includeAuthority);',$query);
    ok(!is_file($root.'/Infrastructure/WooCommerce/Cart/PreProviderCartSnapshot.php'));
    contains('if ($this->requestFence->isUnfencedReadOnlySession())',$bootSnapshot);
    contains('return $this->snapshots->captureCurrent();',$bootSnapshot);
    contains('$this->requestFence->assertProtectsActiveSession();',$bootSnapshot);
    contains('$snapshot = $this->snapshots->capture();',$bootSnapshot);
    contains('return $snapshot;',$bootSnapshot);
    notContains('suppressAutomaticCartStaging',$bootSnapshot);
    notContains('restoreAutomaticCartStaging',$bootSnapshot);
    contains('new CartQueryService(new CartProtectedReadScope(',$commerce);
    contains('new CartQueryService(new BootCartSnapshot(',$commerce);
    contains('$capabilityProof',$commerce);
    contains('$commerce->protectedCart(),',$tools);
    same(2,substr_count($cartTools,'catch (OperationPendingException $exception)'));
    same(2,substr_count($cartTools,'$this->cart->snapshot('));
    $bootStart=strpos($kernel,'new BootController(');
    $chatStart=strpos($kernel,'new ChatController(');
    $privacyStart=strpos($kernel,'new ConversationPrivacyController(');
    ok($bootStart!==false&&$chatStart!==false&&$privacyStart!==false
        &&$bootStart<$chatStart&&$chatStart<$privacyStart);
    $bootComposition=substr($kernel,(int)$bootStart,(int)$chatStart-(int)$bootStart);
    $chatComposition=substr($kernel,(int)$chatStart,(int)$privacyStart-(int)$chatStart);
    contains('$commerce->bootCart()',$bootComposition);
    notContains('$commerce->preProviderCart()',$bootComposition);
    notContains('$commerce->protectedCart()',$bootComposition);
    contains('$commerce->protectedCart()',$chatComposition);
    contains('new GeminiCartIntentVerifier(',(string)file_get_contents(
        $root.'/Infrastructure/Composition/AgentStack.php'
    ));
    notContains('preProviderCart',$commerce);
    notContains('$commerce->cart()',$kernel);
});
test('Degraded durable cart capability still releases the request fence before provider wait', function (): void {
    $events=array();
    $session=new class($events) {
        public $events;
        public function __construct(array &$events){$this->events=&$events;}
        public function suppressAutomaticSave():void{$this->events[]='suppress_writers';}
    };
    $store=new class {
        public function assertSupported():void{throw new RuntimeException('Injected unsupported durable topology.');}
    };
    $fence=new class($events) {
        public $events;
        public function __construct(array &$events){$this->events=&$events;}
        public function isUnfencedReadOnlySession():bool{return false;}
        public function assertCanProtectActiveSession():void{$this->events[]='assert_fence';}
        public function releaseForProviderWait():void{$this->events[]='release_fence';}
    };
    $reflection=new ReflectionClass(
        \YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartMutationCapabilityProof::class
    );
    $proof=$reflection->newInstanceWithoutConstructor();
    foreach(array('session'=>$session,'store'=>$store,'requestFence'=>$fence) as $name=>$value){
        $property=$reflection->getProperty($name);
        if (PHP_VERSION_ID < 80100) { $property->setAccessible(true); }
        $property->setValue($proof,$value);
    }
    $proof->releaseForProviderWait();
    same(array('assert_fence','suppress_writers','release_fence'),$events);
});
test('Woo request fence owns one authenticated guest-session lock before handler construction', function (): void {
    if (!defined('COOKIEHASH')) { define('COOKIEHASH','ysai-test-cookie'); }
    if (!function_exists('wp_hash')) {
        function wp_hash($message,$scheme='auth',$algorithm='md5') {
            return hash_hmac((string)$algorithm,(string)$message,'ysai-test-cookie-secret');
        }
    }
    if (!function_exists('wp_verify_fast_hash')) {
        function wp_verify_fast_hash($message,$hash): bool {
            return is_string($hash) && hash_equals(
                'fast:'.hash_hmac('sha256',(string)$message,'ysai-fast-cookie-secret'),
                $hash
            );
        }
    }
    if (!function_exists('wp_unslash')) {
        function wp_unslash($value) { return $value; }
    }
    if (!function_exists('wc_clean')) {
        function wc_clean($value) { return sanitize_text_field($value); }
    }

    $previousDatabase=$GLOBALS['wpdb']??null;
    $previousCookies=$_COOKIE;
    $hadWoo=array_key_exists('ysai_test_wc',$GLOBALS);
    $previousWoo=$GLOBALS['ysai_test_wc']??null;
    $database=new class {
        public $last_error=''; public $locks=array(); public $acquires=0; public $releases=0;
        public function prepare($sql,...$arguments){return array('sql'=>(string)$sql,'arguments'=>$arguments);}
        public function get_var($query){
            $sql=(string)($query['sql']??''); $arguments=(array)($query['arguments']??array());
            $lock=(string)($arguments[0]??'');
            if(strpos($sql,'GET_LOCK')!==false){$this->locks[$lock]=true;++$this->acquires;return '1';}
            if(strpos($sql,'RELEASE_LOCK')!==false){unset($this->locks[$lock]);++$this->releases;return '1';}
            if(strpos($sql,'IS_USED_LOCK')!==false){return isset($this->locks[$lock])?'1':'0';}
            throw new RuntimeException('Unexpected request-fence query.');
        }
    };
    $GLOBALS['wpdb']=$database;
    $customer='t_'.str_repeat('a',30);
    $testSession=new YsaiTestWooSession();
    $testSession->customerId=$customer;
    $GLOBALS['ysai_test_wc']=(object)array('session'=>$testSession);
    $expiration=(string)(time()+3600);
    $expiring=(string)(time()+1800);
    $message=$customer.'|'.$expiration;
    $signature='fast:'.hash_hmac('sha256',$message,'ysai-fast-cookie-secret');
    $_COOKIE['wp_woocommerce_session_'.COOKIEHASH]=implode('|',array(
        $customer,$expiration,$expiring,$signature
    ));
    try {
        $fence=new \YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\WooCartRequestFence(
            new \YassinStore\AiAssistant\Infrastructure\WordPress\Logger(new Settings())
        );
        $fence->register();
        same('WC_Session_Handler',$fence->beforeSessionHandler('WC_Session_Handler'));
        $fence->assertProtectsActiveSession();
        same(1,$database->acquires);
        same(1,count($database->locks));
        $fence->releaseForProviderWait();
        same(1,$database->releases);
        same(array(),$database->locks);
        $fence->assertCanProtectActiveSession();
        $fence->reacquireForMutation();
        $fence->assertProtectsActiveSession();
        same(2,$database->acquires);
        same(1,count($database->locks));
        $fence->release();
        same(2,$database->releases);
        same(array(),$database->locks);

        // Woo sanitizes the cookie before parsing and signature verification.
        // A fence that validates the raw header instead would treat this as an
        // isolated new session even though Woo hydrates the signed customer.
        $_COOKIE['wp_woocommerce_session_'.COOKIEHASH]=implode('|',array(
            't_<b></b>'.str_repeat('a',30),$expiration,$expiring,$signature
        ));
        $sanitizedFence=new \YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\WooCartRequestFence(
            new \YassinStore\AiAssistant\Infrastructure\WordPress\Logger(new Settings())
        );
        $sanitizedFence->register();
        same('WC_Session_Handler',$sanitizedFence->beforeSessionHandler('WC_Session_Handler'));
        $sanitizedFence->assertProtectsActiveSession();
        same(3,$database->acquires);
        same(1,count($database->locks));
        $sanitizedFence->release();
        same(3,$database->releases);
        same(array(),$database->locks);
    } finally {
        $_COOKIE=$previousCookies;
        $GLOBALS['wpdb']=$previousDatabase;
        if($hadWoo){$GLOBALS['ysai_test_wc']=$previousWoo;}else{unset($GLOBALS['ysai_test_wc']);}
    }
});
test('Woo query-token fencing distinguishes a reload from the exact 10.9.4 clone branches', function (): void {
    if (!defined('WC_VERSION')) { define('WC_VERSION','10.9.4'); }
    if (!defined('COOKIEHASH')) { define('COOKIEHASH','ysai-test-cookie'); }
    if (!defined('WC_SESSION_CACHE_GROUP')) { define('WC_SESSION_CACHE_GROUP','woocommerce_sessions'); }
    if (!class_exists('WC_Cache_Helper')) {
        eval('class WC_Cache_Helper { public static function get_cache_prefix($group){return "ysai_wc_".(string)$group."_";} }');
    }
    if (!class_exists('Automattic\\WooCommerce\\StoreApi\\Utilities\\CartTokenUtils')) {
        eval('namespace Automattic\\WooCommerce\\StoreApi\\Utilities; final class CartTokenUtils { public static function validate_cart_token($token){return isset($GLOBALS["ysai_test_cart_token_payloads"][(string)$token]);} public static function get_cart_token_payload($token){return $GLOBALS["ysai_test_cart_token_payloads"][(string)$token]??array();} }');
    }
    if (!class_exists('WC_Session_Handler')) {
        eval('class WC_Session_Handler { public $cookieWrites=array(); public $customerId="guest-rest-session"; public $data=array(); public $persistent=array(); public $saves=0; public $deletes=array(); public function __construct(string $customerId="guest-rest-session",array $data=array()){$this->customerId=$customerId;$this->data=$data;$this->persistent[$customerId]=$data;} public function set_customer_session_cookie($set):void{$this->cookieWrites[]=(bool)$set;} public function get_customer_id(){return $this->customerId;} public function get($key,$default=null){return array_key_exists($key,$this->data)?$this->data[$key]:$default;} public function set($key,$value):void{$this->data[$key]=$value;} public function save_data():void{++$this->saves;$this->persistent[$this->customerId]=$this->data;} public function get_session($customerId,$default=array()){return $this->persistent[(string)$customerId]??$default;} public function delete_session($customerId):void{$this->deletes[]=(string)$customerId;unset($this->persistent[(string)$customerId]);} }');
    }
    if (!function_exists('maybe_unserialize')) {
        function maybe_unserialize($value) {
            if (!is_string($value)) { return $value; }
            $decoded=@unserialize($value,array('allowed_classes'=>false));
            return $decoded===false && $value!=='b:0;' ? $value : $decoded;
        }
    }

    $previousDatabase=$GLOBALS['wpdb']??null;
    $previousCookies=$_COOKIE;
    $previousQuery=$_GET;
    $previousServer=$_SERVER;
    $previousCache=$GLOBALS['ysai_test_cache'];
    $previousUser=$GLOBALS['ysai_test_current_user_id']??0;
    $previousActions=$GLOBALS['ysai_test_actions'];
    $previousFilters=$GLOBALS['ysai_test_filters'];
    $hadWoo=array_key_exists('ysai_test_wc',$GLOBALS);
    $previousWoo=$GLOBALS['ysai_test_wc']??null;
    $hadTokens=array_key_exists('ysai_test_cart_token_payloads',$GLOBALS);
    $previousTokens=$GLOBALS['ysai_test_cart_token_payloads']??null;

    $source='t_'.str_repeat('s',30);
    $token='signed-query-token';
    $nonce=str_repeat('n',43);
    $marker='sealed-operation-marker';
    $expiration=(string)(time()+3600);
    $expiring=(string)(time()+1800);
    $cookieValue=static function(string $customer)use($expiration,$expiring):string{
        $signature='fast:'.hash_hmac('sha256',$customer.'|'.$expiration,'ysai-fast-cookie-secret');
        return implode('|',array($customer,$expiration,$expiring,$signature));
    };
    $run=static function(
        int $userId,
        string $cookieCustomer,
        array $storedCookieSession,
        string $destination,
        array $nativeData,
        bool $headerOnly=false
    )use($source,$token,$cookieValue):object{
        $_GET=$headerOnly?array():array('session'=>$token);
        if($headerOnly){$_SERVER['HTTP_CART_TOKEN']=$token;}else{unset($_SERVER['HTTP_CART_TOKEN']);}
        $_COOKIE=array();
        if($cookieCustomer!==''){
            $_COOKIE['wp_woocommerce_session_'.COOKIEHASH]=$cookieValue($cookieCustomer);
        }
        $GLOBALS['ysai_test_current_user_id']=$userId;
        $GLOBALS['ysai_test_cart_token_payloads']=array($token=>array('user_id'=>$source));
        $GLOBALS['ysai_test_cache']=array();
        if($cookieCustomer!==''){
            $key=\WC_Cache_Helper::get_cache_prefix(WC_SESSION_CACHE_GROUP).$cookieCustomer;
            $GLOBALS['ysai_test_cache'][WC_SESSION_CACHE_GROUP][$key]=$storedCookieSession;
        }
        $database=new class {
            public $prefix='wp_'; public $last_error=''; public $locks=array();
            public function prepare($sql,...$arguments){return array('sql'=>(string)$sql,'arguments'=>$arguments);}
            public function get_var($query){
                $sql=(string)($query['sql']??''); $arguments=(array)($query['arguments']??array());
                $lock=(string)($arguments[0]??'');
                if(strpos($sql,'GET_LOCK')!==false){$this->locks[$lock]=true;return '1';}
                if(strpos($sql,'RELEASE_LOCK')!==false){unset($this->locks[$lock]);return '1';}
                if(strpos($sql,'IS_USED_LOCK')!==false){return isset($this->locks[$lock])?'1':'0';}
                throw new RuntimeException('Unexpected query-token fence query: '.$sql);
            }
        };
        $GLOBALS['wpdb']=$database;
        $session=new \WC_Session_Handler($destination,$nativeData);
        $GLOBALS['ysai_test_wc']=(object)array('session'=>$session);
        $fence=new \YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\WooCartRequestFence(
            new \YassinStore\AiAssistant\Infrastructure\WordPress\Logger(new Settings())
        );
        $fence->register();
        same('WC_Session_Handler',$fence->beforeSessionHandler('WC_Session_Handler'));
        $fence->adoptActiveSession();
        $fence->assertProtectsActiveSession();
        $fence->release();
        return $session;
    };

    try {
        // WC 10.9.4 returns to the established cookie destination when its
        // durable previous_customer_id already names this query-token source.
        $derived='t_'.str_repeat('d',30);
        $derivedData=array(
            'previous_customer_id'=>$source,
            WooSession::CART_OPERATION_NONCE_KEY=>$nonce,
            WooSession::CART_OPERATION_MARKER_KEY=>$marker,
        );
        $reloaded=$run(0,$derived,$derivedData,$derived,$derivedData);
        same(0,$reloaded->saves,'A token reload must not rewrite the established destination.');
        same(array(),$reloaded->deletes,'A token reload must not delete an active marker session.');
        same($nonce,$reloaded->data[WooSession::CART_OPERATION_NONCE_KEY]??null);
        same($marker,$reloaded->data[WooSession::CART_OPERATION_MARKER_KEY]??null);

        // WC also returns to the cookie directly when it already belongs to
        // the query-token subject, without creating a clone.
        $ownedData=array(
            WooSession::CART_OPERATION_NONCE_KEY=>$nonce,
            WooSession::CART_OPERATION_MARKER_KEY=>$marker,
        );
        $owned=$run(0,$source,$ownedData,$source,$ownedData);
        same(0,$owned->saves);
        same(array(),$owned->deletes);
        same($nonce,$owned->data[WooSession::CART_OPERATION_NONCE_KEY]??null);

        // A different cookie without matching provenance follows the native
        // guest clone branch and must lose the copied cart-only authority.
        $unrelated='t_'.str_repeat('u',30);
        $cloneDestination='t_'.str_repeat('c',30);
        $guestClone=$run(0,$unrelated,array(),$cloneDestination,array(
            'previous_customer_id'=>$source,
            WooSession::CART_OPERATION_NONCE_KEY=>$nonce,
        ));
        same(1,$guestClone->saves,'A new guest query-token clone must persist removal of copied authority.');
        same('',$guestClone->data[WooSession::CART_OPERATION_NONCE_KEY]??null);
        same(array(),$guestClone->deletes);

        // generate_customer_id() returns the logged-in user ID in this exact
        // 10.9.4 branch, so the signed guest clone overwrites that user row.
        $userClone=$run(77,'',array(),'77',array(
            'previous_customer_id'=>$source,
            WooSession::CART_OPERATION_NONCE_KEY=>$nonce,
        ));
        same(1,$userClone->saves,'A logged-in query-token clone must persist removal of copied authority.');
        same('',$userClone->data[WooSession::CART_OPERATION_NONCE_KEY]??null);
        same(array(),$userClone->deletes);

        // Cart-Token is the final Store API handler branch, not the query
        // clone path. It must retain the native destination's live authority.
        $storeApiData=array(
            WooSession::CART_OPERATION_NONCE_KEY=>$nonce,
            WooSession::CART_OPERATION_MARKER_KEY=>$marker,
        );
        $storeApi=$run(0,'',array(),$source,$storeApiData,true);
        same(0,$storeApi->saves);
        same(array(),$storeApi->deletes);
        same($nonce,$storeApi->data[WooSession::CART_OPERATION_NONCE_KEY]??null);
        same($marker,$storeApi->data[WooSession::CART_OPERATION_MARKER_KEY]??null);
    } finally {
        $_COOKIE=$previousCookies;
        $_GET=$previousQuery;
        $_SERVER=$previousServer;
        $GLOBALS['wpdb']=$previousDatabase;
        $GLOBALS['ysai_test_cache']=$previousCache;
        $GLOBALS['ysai_test_current_user_id']=$previousUser;
        $GLOBALS['ysai_test_actions']=$previousActions;
        $GLOBALS['ysai_test_filters']=$previousFilters;
        if($hadWoo){$GLOBALS['ysai_test_wc']=$previousWoo;}else{unset($GLOBALS['ysai_test_wc']);}
        if($hadTokens){$GLOBALS['ysai_test_cart_token_payloads']=$previousTokens;}else{unset($GLOBALS['ysai_test_cart_token_payloads']);}
    }
});
test('Persistent object caches use the transaction-owned core session writer', function (): void {
    $root=YSAI_PROJECT_ROOT.'/src/Infrastructure/WooCommerce/';
    $session=(string)file_get_contents($root.'WooSession.php');
    $store=(string)file_get_contents($root.'Cart/WooSessionCartStore.php');
    $gateway=(string)file_get_contents($root.'Cart/WooCartGateway.php');
    notContains('Persistent object caching is not supported',$store);
    notContains('Persistent object caching is incompatible',$session);
    contains('writeWorkingSessionForUpdate($working->storedEntries())',$store);
    contains('ON DUPLICATE KEY UPDATE session_value=VALUES(session_value),session_expiry=VALUES(session_expiry)',$store);
    contains('$this->invalidateCoreSessionCache();',$store);
    contains('$this->session->markSessionClean();',$store);
    ok(substr_count($store, '$this->session->markSessionClean();') >= 2, 'Both durable save and marker cleanup must finalize the request-local core session.');
    $coreInternals=(string)file_get_contents($root.'Internals/WooCoreStructureProbe.php');
    $storageInternals=(string)file_get_contents($root.'Internals/WooSessionStorageInternals.php');
    contains('if (!is_int($expiration) || $expiration <= time())',$coreInternals);
    contains('$this->core->readSessionExpiration($session)',$storageInternals);
    notContains('2678400',$session);
    notContains('saveSession()',$store);
    notContains('function saveSession',$gateway);
    notContains('function save(): void',$session);
    $before=strpos($store,'// Remove any stale cache entry before the database lock.');
    $transaction=strpos($store,'$stored = $this->transactions->run', (int)$before);
    $write=strpos($store,'writeWorkingSessionForUpdate', (int)$transaction);
    $after=strpos($store,'$this->session->markSessionClean();', (int)$write);
    ok($before!==false&&$transaction!==false&&$write!==false&&$after!==false&&$before<$transaction&&$transaction<$write&&$write<$after);
});
test('Late REST cart construction hydrates native cart exactly once without eager calculation', function (): void {
    if (!class_exists('WooCommerce')) { eval('class WooCommerce {}'); }
    if (!class_exists('WC_Session_Handler')) {
        eval('class WC_Session_Handler { public $cookieWrites=array(); public $data=array(); public $customerId="guest-rest-session"; public function set_customer_session_cookie($set): void { $this->cookieWrites[]=(bool)$set; } public function get_customer_id(){ return $this->customerId; } public function get($key,$default=null){ return array_key_exists($key,$this->data)?$this->data[$key]:$default; } public function set($key,$value): void { $this->data[$key]=$value; } }');
    }
    if (!class_exists('WC_Cart')) {
        eval('class WC_Cart { protected $session; public $contents=array(); public $calculations=0; public $sessionStages=0; public function __construct($session){$this->session=$session;} public function calculate_totals(): void {++$this->calculations;} public function set_session(): void {++$this->sessionStages;} }');
    }
    if (!class_exists('WC_Cart_Session')) {
        eval('class WC_Cart_Session { private $runtime; public $loads=0; public $stages=0; public function __construct($runtime){$this->runtime=$runtime;} public function get_cart_from_session(): void {++$this->loads;$cart=$this->runtime->session->get("cart",array());$this->runtime->cart->contents=is_array($cart)?$cart:array();} public function set_session(): void {++$this->stages;} }');
    }
    $hadWoo=array_key_exists('ysai_test_wc',$GLOBALS);
    $previousWoo=$GLOBALS['ysai_test_wc']??null;
    $hadLoader=array_key_exists('ysai_test_wc_load_cart',$GLOBALS);
    $previousLoader=$GLOBALS['ysai_test_wc_load_cart']??null;
    $hadRest=array_key_exists('ysai_test_rest_request',$GLOBALS);
    $previousRest=$GLOBALS['ysai_test_rest_request']??null;
    $loads=0;
    try {
        $buildRuntime=static function (string $context) use (&$loads): object {
            $runtime=(object)array('session'=>null,'customer'=>null,'cart'=>null,'writer'=>null);
            $GLOBALS['ysai_test_wc']=$runtime;
            $GLOBALS['ysai_test_wc_load_cart']=static function () use ($runtime, &$loads, $context): void {
                ++$loads;
                $session=new WC_Session_Handler();
                $session->data=array(
                    'cart'=>array('line-'.$context=>array('product_id'=>17,'quantity'=>2)),
                    WooSession::CART_OPERATION_NONCE_KEY=>str_repeat('n',43),
                );
                $runtime->session=$session;
                $runtime->customer=(object)array('context'=>$context);
                $runtime->writer=new WC_Cart_Session($runtime);
                $runtime->cart=new WC_Cart($runtime->writer);
            };
            return $runtime;
        };

        $GLOBALS['ysai_test_rest_request']=true;
        $rest=$buildRuntime('rest');
        $restSession=new WooSession();
        same('WC_Session_Handler',$restSession->sessionHandlerClass());
        $restSession->ensure();
        same(1,$rest->writer->loads,'A newly constructed REST cart must be natively hydrated exactly once.');
        same(array('line-rest'=>array('product_id'=>17,'quantity'=>2)),$rest->cart->contents);
        same(str_repeat('n',43),$restSession->cartOperationNonce());
        $restSession->ensure();
        same(str_repeat('n',43),$restSession->cartOperationNonce());
        same(1,$rest->writer->loads);
        same(0,$rest->cart->calculations);
        same(0,$rest->cart->sessionStages);
        same(array(),$rest->session->cookieWrites,'Generic runtime completion must not publish cookies.');

        same(1,$loads,'The all-null REST request must use one canonical Woo cart load.');

        $root=YSAI_PROJECT_ROOT.'/src';
        $source=(string)file_get_contents($root.'/Infrastructure/WooCommerce/WooSession.php');
        $commerce=(string)file_get_contents($root.'/Infrastructure/Composition/CommerceStack.php');
        $bootSnapshot=(string)file_get_contents($root.'/Infrastructure/WooCommerce/Cart/BootCartSnapshot.php');
        contains('wc_load_cart();',$source);
        contains('wp_is_serving_rest_request()',$source);
        contains('hydrateCartFromSession',$source);
        notContains('initialize_customer',$source);
        notContains('initialize_session',$source);
        ok(!is_file($root.'/Infrastructure/WooCommerce/Cart/PreProviderCartSnapshot.php'));
        notContains('preProviderCart',$commerce);
        contains('bootCart',$commerce);
        notContains('function cart()',$commerce);
        contains('$snapshot = $this->snapshots->capture();',$bootSnapshot);
        contains('return $snapshot;',$bootSnapshot);
        contains('$this->session->publishCartOperationAuthority();',$bootSnapshot);
        contains('private function seedCartOperationNonce(): void',$source);
        notContains('public function seedCartOperationNonce(): void',$source);
        $publishStart=strpos($source,'public function publishCartOperationAuthority(): void');
        $publishVersion=strpos($source,'$this->internals->assertVerifiedCartMutationVersion();',(int)$publishStart);
        $publishEnsure=strpos($source,'$this->ensure();',(int)$publishStart);
        $publishSeed=strpos($source,'$this->seedCartOperationNonce();',(int)$publishStart);
        $publishCookie=strpos($source,'$this->internals->publishGuestCookie($handler);',(int)$publishStart);
        $publishSave=strpos($source,'$this->internals->saveSession($handler);',(int)$publishStart);
        $publishRead=strpos($source,'$this->internals->durableSession($handler, $customerId);',(int)$publishStart);
        ok($publishStart!==false&&$publishVersion!==false&&$publishEnsure!==false
            &&$publishSeed!==false&&$publishCookie!==false&&$publishSave!==false&&$publishRead!==false
            &&$publishStart<$publishVersion&&$publishVersion<$publishEnsure
            &&$publishEnsure<$publishSeed&&$publishSeed<$publishCookie
            &&$publishCookie<$publishSave&&$publishSave<$publishRead,
            'Durable cart-operation authority must require a promotion-tested WooCommerce release first.');
        contains('$this->internals->publishGuestCookie($handler);',$source);
        contains('$this->internals->saveSession($handler);',$source);
        contains('$this->internals->durableSession($handler, $customerId);',$source);
        contains('hash_equals($nonce, $stored)',$source);
        notContains('suppressAutomaticCartStaging',$source);
        notContains('restoreAutomaticCartStaging',$source);
    } finally {
        if($hadRest){$GLOBALS['ysai_test_rest_request']=$previousRest;}else{unset($GLOBALS['ysai_test_rest_request']);}
        if($hadLoader){$GLOBALS['ysai_test_wc_load_cart']=$previousLoader;}else{unset($GLOBALS['ysai_test_wc_load_cart']);}
        if($hadWoo){$GLOBALS['ysai_test_wc']=$previousWoo;}else{unset($GLOBALS['ysai_test_wc']);}
    }
});
test('Cart operation nonce is seeded once while strict reads never invent or replace authority', function (): void {
    if (!class_exists('WooCommerce')) { eval('class WooCommerce {}'); }
    $hadWoo=array_key_exists('ysai_test_wc',$GLOBALS);
    $previousWoo=$GLOBALS['ysai_test_wc']??null;
    try {
        $working=new YsaiTestWooSession();
        $GLOBALS['ysai_test_wc']=(object)array(
            'session'=>$working,
            'cart'=>(object)array(),
            'customer'=>(object)array(),
        );
        $session=new WooSession();
        $seeder=new ReflectionMethod(WooSession::class,'seedCartOperationNonce');
        if (PHP_VERSION_ID < 80100) { $seeder->setAccessible(true); }
        $seed=static function () use ($seeder,$session): void {
            $seeder->invoke($session);
        };

        throws(RuntimeException::class,static function () use ($session): void {
            $session->cartOperationNonce();
        },'unavailable');
        same(array(),$working->data,'A strict cart-authority read must not create session state.');

        $seed();
        $nonce=$session->cartOperationNonce();
        ok(preg_match('/^[A-Za-z0-9_-]{43}$/D',$nonce)===1);
        same($nonce,$working->data[WooSession::CART_OPERATION_NONCE_KEY]??'');
        $seed();
        same($nonce,$session->cartOperationNonce(),'Repeated seeding must preserve one cart identity.');

        $working->data[WooSession::CART_OPERATION_NONCE_KEY]='malformed';
        throws(RuntimeException::class,$seed,'malformed');
        throws(RuntimeException::class,static function () use ($session): void {
            $session->cartOperationNonce();
        },'unavailable');
        same('malformed',$working->data[WooSession::CART_OPERATION_NONCE_KEY]);

        $working->data[WooSession::CART_OPERATION_NONCE_KEY]='';
        $working->data[WooSession::CART_OPERATION_MARKER_KEY]=array('untrusted'=>'marker');
        throws(RuntimeException::class,$seed,'marker exists');
        same('',$working->data[WooSession::CART_OPERATION_NONCE_KEY]);
    } finally {
        if($hadWoo){$GLOBALS['ysai_test_wc']=$previousWoo;}else{unset($GLOBALS['ysai_test_wc']);}
    }
});
test('Boot cart refreshes and stages one coherent fenced result while preserving custom-handler reads', function (): void {
    $makeSnapshot=static function ($fence,$session,$snapshots): BootCartSnapshot {
        $reflection=new ReflectionClass(BootCartSnapshot::class);
        $provider=$reflection->newInstanceWithoutConstructor();
        foreach(array('requestFence'=>$fence,'session'=>$session,'snapshots'=>$snapshots) as $name=>$value){
            $property=$reflection->getProperty($name);
            if (PHP_VERSION_ID < 80100) { $property->setAccessible(true); }
            $property->setValue($provider,$value);
        }
        return $provider;
    };

    $current=snapshot(array(line('line-boot',1.0)),array(),1,10.0,'stale');
    $fresh=snapshot(array(line('line-boot',1.0)),array(),1,14.0,'fresh');
    $events=array();
    $fence=new class($events) {
        public $events;
        public function __construct(array &$events){$this->events=&$events;}
        public function isUnfencedReadOnlySession():bool{$this->events[]='fence_mode';return false;}
        public function assertProtectsActiveSession():void{$this->events[]='fence_assert';}
    };
    $session=new class($events) {
        public $events;
        public function __construct(array &$events){$this->events=&$events;}
        public function ensure():void{$this->events[]='session_ensure';}
        public function publishCartOperationAuthority():void{$this->events[]='session_publish_cart_authority';}
    };
    $snapshots=new class($events,$current,$fresh) {
        public $events; private $current; private $fresh;
        public function __construct(array &$events,CartSnapshot $current,CartSnapshot $fresh){
            $this->events=&$events;$this->current=$current;$this->fresh=$fresh;
        }
        public function captureCurrent():CartSnapshot{$this->events[]='capture_current';return $this->current;}
        public function capture():CartSnapshot{$this->events[]='capture_calculated';return $this->fresh;}
    };
    same($fresh,$makeSnapshot($fence,$session,$snapshots)->capture());
    same(array(
        'session_ensure','fence_mode','fence_assert','capture_current',
        'capture_calculated','session_publish_cart_authority',
    ),$events);

    $customEvents=array();
    $customFence=new class($customEvents) {
        public $events;
        public function __construct(array &$events){$this->events=&$events;}
        public function isUnfencedReadOnlySession():bool{$this->events[]='fence_mode';return true;}
        public function assertProtectsActiveSession():void{throw new RuntimeException('Custom handler must not require a core fence.');}
    };
    $customSession=new class($customEvents) {
        public $events;
        public function __construct(array &$events){$this->events=&$events;}
        public function ensure():void{$this->events[]='session_ensure';}
        public function publishCartOperationAuthority():void{throw new RuntimeException('Custom handler must remain read-only.');}
    };
    $customSnapshots=new class($customEvents,$current) {
        public $events; private $current;
        public function __construct(array &$events,CartSnapshot $current){$this->events=&$events;$this->current=$current;}
        public function captureCurrent():CartSnapshot{$this->events[]='capture_current';return $this->current;}
        public function capture():CartSnapshot{throw new RuntimeException('Custom handler must not calculate.');}
    };
    same($current,$makeSnapshot($customFence,$customSession,$customSnapshots)->capture());
    same(array('session_ensure','fence_mode','capture_current'),$customEvents);

    $emptyEvents=array();
    $empty=snapshot(array(),array(),0,0.0,'empty');
    $emptyFence=new class($emptyEvents) {
        public $events;
        public function __construct(array &$events){$this->events=&$events;}
        public function isUnfencedReadOnlySession():bool{$this->events[]='fence_mode';return false;}
        public function assertProtectsActiveSession():void{$this->events[]='fence_assert';}
    };
    $emptySession=new class($emptyEvents) {
        public $events;
        public function __construct(array &$events){$this->events=&$events;}
        public function ensure():void{$this->events[]='session_ensure';}
        public function publishCartOperationAuthority():void{$this->events[]='session_publish_cart_authority';}
    };
    $emptySnapshots=new class($emptyEvents,$empty) {
        public $events; private $empty;
        public function __construct(array &$events,CartSnapshot $empty){$this->events=&$events;$this->empty=$empty;}
        public function captureCurrent():CartSnapshot{$this->events[]='capture_current';return $this->empty;}
        public function capture():CartSnapshot{throw new RuntimeException('Empty boot cart must not calculate.');}
    };
    same($empty,$makeSnapshot($emptyFence,$emptySession,$emptySnapshots)->capture());
    same(array(
        'session_ensure','fence_mode','fence_assert','capture_current','session_publish_cart_authority'
    ),$emptyEvents);
});
test('Cart mutation capability is a closed first-release contract', function (): void {
    $available=new CartMutationCapability(true,CartMutationCapability::AVAILABLE,'');
    same(array('available'=>true,'code'=>'available','notice'=>''),$available->forClient());
    $blocked=new CartMutationCapability(false,CartMutationCapability::SESSION_HANDLER_UNSUPPORTED,'Unavailable');
    same(false,$blocked->available());
    same(array('available'=>false,'code'=>'session_handler_unsupported'),$blocked->forModel());
    $runtime=new CartMutationCapability(false,CartMutationCapability::RUNTIME_UNAVAILABLE,'Unavailable');
    same(array('available'=>false,'code'=>'runtime_unavailable'),$runtime->forModel());
    foreach(array(
        CartMutationCapability::VERSION_NOT_PROMOTION_TESTED,
        CartMutationCapability::REQUEST_FENCE_UNAVAILABLE,
        CartMutationCapability::STORAGE_TOPOLOGY_UNSUPPORTED,
        CartMutationCapability::SESSION_RUNTIME_UNSUPPORTED,
        CartMutationCapability::SESSION_AUTHORITY_UNAVAILABLE,
    ) as $code) {
        same($code,(new CartMutationCapability(false,$code,'Unavailable'))->code());
    }
    throws(InvalidArgumentException::class,static function(): void {
        new CartMutationCapability(false,CartMutationCapability::AVAILABLE,'Unavailable');
    },'invalid');
    throws(InvalidArgumentException::class,static function(): void {
        new CartMutationCapability(false,CartMutationCapability::RUNTIME_UNAVAILABLE,'');
    },'invalid');
});
test('Custom Woo session restrictions are enforced per storefront session without an admin cart probe', function (): void {
    $root=YSAI_PROJECT_ROOT;
    $boot=(string)file_get_contents($root.'/src/Presentation/Rest/Controller/BootController.php');
    $prompt=(string)file_get_contents($root.'/src/Application/Agent/AgentPromptBuilder.php');
    $tool=(string)file_get_contents($root.'/src/Application/Tool/Service/CartToolService.php');
    $admin=(string)file_get_contents($root.'/src/Presentation/Admin/AdminPages.php');
    contains('$cartMutations = $this->cartMutations->inspect()->forClient()',$boot);
    contains("'cart_mutations' => \$cartMutations",$boot);
    contains("'cart_mutation_capability' =>",$prompt);
    contains('لا تستدعه',$prompt);
    contains("'cart_mutation_unavailable'",$tool);
    contains('renderCartMutationSessionPolicy',$admin);
    contains('لا تنشئ صفحة الإدارة جلسة متسوق',$admin);
    notContains('CartMutationCapabilityPort',$admin);
    notContains('->inspect()',$admin);
    $kernel=(string)file_get_contents($root.'/src/Infrastructure/Composition/PluginKernel.php');
    $adminWiring=substr($kernel,(int)strpos($kernel,'(new AdminPages('));
    notContains('$commerce->mutationCapability()',$adminWiring);
    $inspector=(string)file_get_contents($root.'/src/Infrastructure/WooCommerce/Cart/CartMutationCapabilityInspector.php');
    contains("if (\$handler === '')",$inspector);
    contains('CartMutationCapability::RUNTIME_UNAVAILABLE',$inspector);
    contains('CartMutationCapability::SESSION_HANDLER_UNSUPPORTED',$inspector);
});
test('Durable cart journal orders intent effect persistence and verified finalization', function (): void {
    $root=YSAI_PROJECT_ROOT.'/src/Infrastructure/WooCommerce/Cart/';
    $engine=(string)file_get_contents($root.'CartStepExecutionEngine.php');
    $store=(string)file_get_contents($root.'WooSessionCartStore.php');
    $coordinator=(string)file_get_contents($root.'CartOperationCoordinator.php');
    $intent=strpos($engine,'stageIntent');
    $execute=strpos($engine,'$this->executor->execute');
    $seal=strpos($engine,'$this->attempts->seal');
    $persist=strpos($engine,'persistAndReadBack');
    $verified=strpos($engine,'markSessionPersisted');
    ok($intent!==false&&$execute!==false&&$seal!==false&&$persist!==false&&$verified!==false
        &&$intent<$execute&&$execute<$seal&&$seal<$persist&&$persist<$verified,
        'Primitive evidence transitions must remain ordered.');
    contains('$this->persistentCart->persistAndVerifyForUpdate()',$store);
    contains('$this->operations->recordApplied',$coordinator);
    contains('$this->operations->markVerified',$coordinator);
});
test('Cart planning is terminalized before an operation can be left executing', function (): void {
    $source=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/WooCommerce/Cart/CartOperationCoordinator.php');
    $guard=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/WooCommerce/Cart/CartOperationPlanningGuard.php');
    $planning=strpos($source,'$this->planning->resolve(');
    $executing=strpos($source,'return $this->operations->markExecuting(');
    ok($planning!==false&&$executing!==false&&$planning<$executing);
    contains("'cart_step_planning_failed'",$guard);
    contains('OperationStatus::PREPARED',$guard);
    contains('$this->terminalizer->failure(',$guard);
    contains('$this->terminalizer->uncertain(',$guard);
    notContains('terminalization returned unexpectedly',$guard);
});
test('Cart supervisor seals renewal only at a boundary that may change Woo state', function (): void {
    $source=(string)file_get_contents(
        YSAI_PROJECT_ROOT.'/src/Infrastructure/WooCommerce/Cart/CartOperationCoordinator.php'
    );
    contains('$supervisor->after(ExecutionBoundary::CART_OPERATION);',$source);
    notContains('$supervisor->after(ExecutionBoundary::CART_OPERATION, true);',$source);
    contains('$supervisor->after(ExecutionBoundary::CART_STEP, true);',$source);
});
test('Clear is one canonical empty-cart primitive', function (): void {
    $pre=snapshot(array(line('line-a',1)),array('SAVE10'),1,10,'clear-pre');
    $steps=(new CartStepPlanner())->plan(
        new CartPlan(array(CartCommand::clear()),$pre->revision()),$pre
    );
    same(1,count($steps));
    same(CartPrimitive::EMPTY_CART,$steps[0]->type());
    same('clear_atomic',$steps[0]->phase());
    $post=snapshot(array(),array(),0,0,'clear-post');
    $effect=(new CartStepVerifier())->seal($steps[0],$pre,$post,array(
        'primitive_type'=>CartPrimitive::EMPTY_CART,
        'semantic_type'=>CartCommand::CLEAR,
        'phase'=>'clear_atomic',
        'command_index'=>0,
        'previous_line_count'=>1,
        'previous_coupon_count'=>1,
    ));
    same($post->revision(),$effect['post_revision']);
});
test('First-release cart quantities and plans are integer and single-command only', function (): void {
    $fingerprint=str_repeat('a',64);
    foreach (array(0.5,1.5,999.5) as $quantity) {
        throws(InvalidArgumentException::class,static function()use($quantity):void{
            cartAddCommandForTest(10,0,$quantity,'Product');
        },'quantity');
        throws(InvalidArgumentException::class,static function()use($fingerprint,$quantity):void{
            CartCommand::update('line',$fingerprint,$quantity,'Product');
        },'quantity');
    }
    throws(InvalidArgumentException::class,static function()use($fingerprint):void{
        new CartPlan(array(
            CartCommand::update('line',$fingerprint,2,'Product'),
            CartCommand::remove('other',str_repeat('b',64),'Other'),
        ));
    },'exactly one command');
});
test('Public cart counts accept the signed 32-bit maximum and reject overflow', function (): void {
    $maximum=2147483647;

    $boundedSnapshot=snapshot(array(),array(),$maximum,0,'maximum-public-count');
    same($maximum,$boundedSnapshot->forClient()['item_count']);
    throws(InvalidArgumentException::class,static function()use($maximum):void{
        snapshot(array(),array(),$maximum+1,0,'overflow-public-count');
    },'facts');

    $canonicalReceipt=receipt(true);
    $boundedProof=$canonicalReceipt->proof();
    $boundedProof['cart_count']=$maximum;
    $boundedProof['changed_line_count']=$maximum;
    $boundedReceipt=new ActionReceipt(
        $canonicalReceipt->action(),
        $canonicalReceipt->changed(),
        $boundedProof,
        $canonicalReceipt->safeMessage()
    );
    same($maximum,$boundedReceipt->forClient()['proof']['cart_count']);
    same($maximum,$boundedReceipt->forClient()['proof']['changed_line_count']);

    $overflowCartCount=$boundedProof;
    $overflowCartCount['cart_count']=$maximum+1;
    throws(InvalidArgumentException::class,static function()use($canonicalReceipt,$overflowCartCount):void{
        new ActionReceipt(
            $canonicalReceipt->action(),
            $canonicalReceipt->changed(),
            $overflowCartCount,
            $canonicalReceipt->safeMessage()
        );
    },'proof');

    $overflowChangedLineCount=$boundedProof;
    $overflowChangedLineCount['changed_line_count']=$maximum+1;
    throws(InvalidArgumentException::class,static function()use($canonicalReceipt,$overflowChangedLineCount):void{
        new ActionReceipt(
            $canonicalReceipt->action(),
            $canonicalReceipt->changed(),
            $overflowChangedLineCount,
            $canonicalReceipt->safeMessage()
        );
    },'proof');
});
test('Multibyte product names remain valid direct cart plans', function (): void {
    $name=str_repeat('م',300);
    same(300,Utf8::codePointLength($name));
    ok(strlen($name)>500,'Regression input must exceed the old byte-count limit.');
    $authority=new AuthorityRegistry();
    $productRef=$authority->recordProduct(array(
        'id'=>10,
        'name'=>$name,
        'sku'=>'',
        'type'=>'simple',
        'requires_variation'=>false,
    ));
    $plan=(new CartPlanFactory())->fromToolArguments(array(
        'commands'=>array(array(
            'type'=>'add','product_ref'=>$productRef,'quantity_mode'=>'exact','quantity'=>1,
        )),
    ),$authority);
    same($name,$plan->commands()[0]->displayName());
});
test('Verified cart action commits earlier shopping-memory transitions', function (): void {
    $context=agentContextForTest();
    $patch=new ShoppingMemoryPatch(array(
        'mode'=>'replace_topic',
        'goal'=>'Prepare the selected coffee for the cart',
        'stage'=>'cart',
    ));
    $context->effects()->recordShoppingMemoryPatch($patch);
    $verified=receipt();
    $context->effects()->recordReceipt($verified);
    $response=terminalOutcomesForTest()->verifiedAction($context);

    same(Outcome::ACTION_VERIFIED,$response->outcome());
    same($verified->safeMessage(),$response->text());
    same(array($patch),$response->shoppingMemoryPatches());
    $shopping=ConversationState::initial()->after($response,time())->toArray()['shopping'];
    same(1,$shopping['revision']);
    same('Prepare the selected coffee for the cart',$shopping['goal']);
});
test('Woo add and update validation run before any cart side effect', function (): void {
    if (!class_exists('WooCommerce')) { eval('class WooCommerce {}'); }
    $previousWoo=$GLOBALS['ysai_test_wc']??null;
    $previousFilters=$GLOBALS['ysai_test_filters'];
    $cart=new class {
        public $adds=0; public $updates=0;
        public $item=array('product_id'=>10,'variation_id'=>0,'quantity'=>1);
        public function add_to_cart($productId,$quantity,$variationId,$variation,$itemData){++$this->adds;return 'line-new';}
        public function get_cart_item($key){return $this->item;}
        public function set_quantity($key,$quantity,$refresh){++$this->updates;return true;}
    };
    $GLOBALS['ysai_test_wc']=(object)array(
        'session'=>new YsaiTestWooSession(),
        'customer'=>(object)array(),
        'cart'=>$cart,
    );
    try {
        $gateway=new WooCartGateway(new WooSession());
        $simpleArgs=array();
        $GLOBALS['ysai_test_filters']['woocommerce_add_to_cart_validation']=array(array(
            static function(...$args)use(&$simpleArgs){$simpleArgs=$args;return false;},10,5,
        ));
        throws(SafeCommerceException::class,static function()use($gateway):void{
            $gateway->add(10,2,0,array());
        },'cart_add_rejected');
        same(array(true,10,2),$simpleArgs);
        same(0,$cart->adds);

        $addArgs=array();
        $GLOBALS['ysai_test_filters']['woocommerce_add_to_cart_validation']=array(array(
            static function(...$args)use(&$addArgs){$addArgs=$args;return false;},10,5,
        ));
        throws(SafeCommerceException::class,static function()use($gateway):void{
            $gateway->add(10,2,20,array('attribute_size'=>'large'));
        },'cart_add_rejected');
        same(array(true,10,2,20,array('attribute_size'=>'large')),$addArgs);
        same(0,$cart->adds);

        $GLOBALS['ysai_test_filters']['woocommerce_add_to_cart_validation']=array(array(
            static function(){throw new RuntimeException('Injected validation exception.');},10,5,
        ));
        throws(SafeCommerceException::class,static function()use($gateway):void{
            $gateway->add(10,2,20,array('attribute_size'=>'large'));
        },'cart_add_validation_failed');
        same(0,$cart->adds);

        $updateArgs=array();
        $GLOBALS['ysai_test_filters']['woocommerce_update_cart_validation']=array(array(
            static function(...$args)use(&$updateArgs){$updateArgs=$args;return false;},10,4,
        ));
        throws(SafeCommerceException::class,static function()use($gateway):void{
            $gateway->setQuantity('line',3);
        },'cart_update_rejected');
        same(true,$updateArgs[0]); same('line',$updateArgs[1]); same($cart->item,$updateArgs[2]); same(3,$updateArgs[3]);
        same(0,$cart->updates);
    } finally {
        $GLOBALS['ysai_test_filters']=$previousFilters;
        if ($previousWoo!==null) {$GLOBALS['ysai_test_wc']=$previousWoo;} else {unset($GLOBALS['ysai_test_wc']);}
    }
});
test('Non-finite custom cart metadata is explicit non-restorable evidence, never numeric zero', function (): void {
    $normalizer=new CartItemDataNormalizer();
    $finite=$normalizer->normalize(array('custom_amount'=>0.0));
    ok($finite['restorable']);
    same(0.0,$finite['data']['custom_amount']);
    foreach(array(INF,-INF,NAN) as $nonFinite){
        $normalized=$normalizer->normalize(array('custom_amount'=>$nonFinite));
        ok(!$normalized['restorable']);
        same(
            array('__unsupported'=>'non_finite_float'),
            $normalized['data']['custom_amount']
        );
        ok(!hash_equals($finite['hash'],$normalized['hash']));
        contains('non_finite_float',Json::canonical($normalized['data']));
    }
});
test('Every changing cart plan requires whole-cart restoration authority', function (): void {
    $unsafeData=array('opaque'=>array('__unsupported'=>'object'));
    $unsafe=new CartLine(
        'line-unsafe',10,0,array(),2,
        hash('sha256',Json::canonical($unsafeData)),$unsafeData,false,
        array('name'=>'Custom product','quantity'=>2)
    );
    $safe=line('line-safe',3,20);
    $pre=snapshot(array($unsafe,$safe),array(),5,50,'scoped-restore');
    ok(!$pre->restorable());

    $unrelatedAdd=new CartPlan(array(cartAddCommandForTest(30,0,1,'New product')));
    $safeUpdate=new CartPlan(array(CartCommand::update(
        $safe->key(),$safe->fingerprint(),4,'Product 20'
    )));
    $safeRemove=new CartPlan(array(CartCommand::remove(
        $safe->key(),$safe->fingerprint(),'Product 20'
    )));
    $safeReplace=new CartPlan(array(CartCommand::replace(
        $safe->key(),$safe->fingerprint(),30,0,3,
        str_repeat('a',64),'New product'
    )));
    $unsafeUpdate=new CartPlan(array(CartCommand::update(
        $unsafe->key(),$unsafe->fingerprint(),3,'Custom product'
    )));
    $unsafeRemove=new CartPlan(array(CartCommand::remove(
        $unsafe->key(),$unsafe->fingerprint(),'Custom product'
    )));
    $unsafeReplace=new CartPlan(array(CartCommand::replace(
        $unsafe->key(),$unsafe->fingerprint(),30,0,2,
        str_repeat('a',64),'New product'
    )));
    $clear=new CartPlan(array(CartCommand::clear()),$pre->revision());
    $verifier=new CartDeltaVerifier();
    foreach(array(
        $unrelatedAdd,$safeUpdate,$safeRemove,$safeReplace,
        $unsafeUpdate,$unsafeRemove,$unsafeReplace,$clear,
    ) as $plan){
        ok(!$pre->restorable());
        ok($verifier->wouldChange($plan,$pre));
    }

    $noOp=new CartPlan(array(CartCommand::update(
        $unsafe->key(),$unsafe->fingerprint(),2,'Custom product'
    )));
    ok(!(new CartDeltaVerifier())->wouldChange($noOp,$pre));
});
test('Hidden or sold-out stored lines remain structurally recoverable for safe cart plans', function (): void {
    $hadProducts=array_key_exists('ysai_test_products',$GLOBALS);
    $previousProducts=$GLOBALS['ysai_test_products']??null;
    $existing=new WC_Product('simple','',null,null,10);
    $existing->status='private';
    $existing->visible=false;
    $existing->purchasable=false;
    $existing->inStock=false;
    $unrelated=new WC_Product('simple','',null,null,20);
    $GLOBALS['ysai_test_products']=array(10=>$existing,20=>$unrelated);
    try {
        $policy=new \YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartProductPolicy(
            new WooCartGateway(new WooSession()),
            new \YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\CatalogVisibilityPolicy(),
            new \YassinStore\AiAssistant\Infrastructure\WooCommerce\ProductCapabilityPolicy(),
            new \YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\AttributePresenter()
        );
        ok($policy->canReconstructStoredLine(10,0,array()),
            'Rollback authority must not depend on visibility, purchasability, or stock.');

        $stored=new CartLine(
            'line-hidden',10,0,array(),1,
            hash('sha256',Json::canonical(array())),array(),
            $policy->canReconstructStoredLine(10,0,array()),
            array('name'=>'Hidden existing product','quantity'=>1)
        );
        $pre=snapshot(array($stored),array(),1,10,'hidden-pre');
        ok($pre->restorable());

        $plans=array(
            'remove'=>new CartPlan(array(CartCommand::remove(
                $stored->key(),$stored->fingerprint(),'Hidden existing product'
            ))),
            'clear'=>new CartPlan(array(CartCommand::clear()),$pre->revision()),
            'unrelated_add'=>new CartPlan(array(cartAddCommandForTest(20,0,1,'Unrelated product'))),
        );
        $verifier=new CartDeltaVerifier();
        $planner=new CartStepPlanner();
        foreach($plans as $label=>$plan){
            ok($verifier->wouldChange($plan,$pre),$label.' must remain a real mutation.');
            ok($pre->restorable() || !$verifier->wouldChange($plan,$pre),
                $label.' must pass the coordinator rollback gate.');
            same(1,count($planner->plan($plan,$pre)),$label.' must remain one atomic primitive.');
        }
    } finally {
        if($hadProducts){$GLOBALS['ysai_test_products']=$previousProducts;}
        else{unset($GLOBALS['ysai_test_products']);}
    }
});
test('Quantity reductions use exact line authority and Woo validation without new-purchase eligibility', function (): void {
    $line=line('line-reduce',3,10,0,array(),array('configuration'=>'kept'));
    $pre=snapshot(array($line),array(),3,30,'reduce-pre');
    $primitive=CartPrimitive::setQuantity(
        CartCommand::UPDATE,0,'single',$line->key(),$line->fingerprint(),2,'Hidden product'
    );
    $gateway=new class {
        public $setQuantities=array(); public $suppressed=0; public $restored=0;
        public function suppressAutomaticTotals(): void {++$this->suppressed;}
        public function restoreAutomaticTotals(): void {++$this->restored;}
        public function rawItem(string $key): ?array {
            return $key==='line-reduce'?array('product_id'=>10,'variation_id'=>0,'quantity'=>3):null;
        }
        public function setQuantity(string $key,int $quantity): void {$this->setQuantities[]=array($key,$quantity);}
    };
    $products=new class {
        public $eligibilityCalls=0; public $existingQuantityCalls=0;
        public function effectiveCartItem(array $item,int $quantity,string $key): void {
            ++$this->eligibilityCalls;
            throw new RuntimeException('A reduction must not apply new-purchase eligibility.');
        }
        public function assertExistingLineQuantity(array $item,int $quantity): void {
            ++$this->existingQuantityCalls;
            same(2,$quantity);
            same(10,(int)($item['product_id']??0));
        }
    };
    $capability=new class {public $calls=0;public function assertSupported():void{++$this->calls;}};
    $reflection=new ReflectionClass(
        \YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartCommandExecutor::class
    );
    $executor=$reflection->newInstanceWithoutConstructor();
    foreach(array('gateway'=>$gateway,'products'=>$products,'capability'=>$capability) as $name=>$value){
        $property=$reflection->getProperty($name);
        if (PHP_VERSION_ID < 80100) { $property->setAccessible(true); }
        $property->setValue($executor,$value);
    }
    $effect=$executor->execute($primitive,$pre);
    same(0,$products->eligibilityCalls);
    same(1,$products->existingQuantityCalls);
    same(array(array('line-reduce',2)),$gateway->setQuantities);
    same(1,$gateway->suppressed);same(1,$gateway->restored);same(1,$capability->calls);
    same(3.0,$effect['previous_quantity']);same(2.0,$effect['quantity']);
});
test('Cart backend contains aggregate stock rollback and persistent-cart policy boundaries', function (): void {
    $root=YSAI_PROJECT_ROOT.'/src/Infrastructure/WooCommerce/Cart/';
    $sourceRoot=YSAI_PROJECT_ROOT.'/src/';
    $policy=(string)file_get_contents($root.'CartProductPolicy.php');
    $engine=(string)file_get_contents($root.'CartStepExecutionEngine.php');
    $workingStateRestorer=(string)file_get_contents($root.'CartWorkingStateRestorer.php');
    $store=(string)file_get_contents($root.'WooSessionCartStore.php');
    $persistent=(string)file_get_contents($root.'WooPersistentCartStore.php');
    $terminalizer=(string)file_get_contents($root.'CartOperationTerminalizer.php');
    $status=(string)file_get_contents($sourceRoot.'Domain/Commerce/OperationStatus.php');
    $operations=(string)file_get_contents($sourceRoot.'Infrastructure/Database/OperationRepository.php');
    contains('get_stock_managed_by_id',$policy);
    contains('$this->assertAggregateStock',$policy);
    contains('$this->workingStateRestorer->restore($step, $durablePre)',$engine);
    contains('$this->store->restoreWorkingFromDurable($current);',$workingStateRestorer);
    contains('restoreWorkingFromDurable',$store);
    contains("apply_filters('woocommerce_persistent_cart_enabled', true)",$persistent);
    contains("'DELETE FROM ' . \$wpdb->usermeta",$persistent);
    notContains('PARTIAL',$status);
    notContains('markPartial',$operations);
    notContains('verifiedPrefix',$terminalizer);
});
test('Woo session proof authenticates the complete serialized map except its signed marker', function (): void {
    $normalizer=new CartItemDataNormalizer(); $decoder=new SafeSerializedArrayDecoder(); $markerKey=WooSessionOperationMarker::SESSION_KEY;
    $entries=array('cart'=>serialize(array()),'applied_coupons'=>serialize(array()),'chosen_shipping_methods'=>serialize(array('flat_rate:1')),$markerKey=>serialize(array('opaque'=>'one')));
    $first=WooSessionCartEnvelope::fromStoredValue(serialize($entries),$normalizer,$decoder,$markerKey);
    $markerChanged=$entries; $markerChanged[$markerKey]=serialize(array('opaque'=>'two'));
    $markerOnly=WooSessionCartEnvelope::fromStoredValue(serialize($markerChanged),$normalizer,$decoder,$markerKey);
    same($first->payloadHash(),$markerOnly->payloadHash());
    $changed=$entries; $changed['chosen_shipping_methods']=serialize(array('free_shipping:1'));
    $different=WooSessionCartEnvelope::fromStoredValue(serialize($changed),$normalizer,$decoder,$markerKey);
    ok(!hash_equals($first->payloadHash(),$different->payloadHash()));

    $binary=$entries; $binary['opaque_binary']="\xFF\x00\x01";
    $binaryEnvelope=WooSessionCartEnvelope::fromStoredValue(serialize($binary),$normalizer,$decoder,$markerKey);
    ok(!hash_equals($first->payloadHash(),$binaryEnvelope->payloadHash()));

    $recursive=array(); $recursive['self']=&$recursive;
    $badCart=$entries; $badCart['cart']=serialize($recursive);
    throws(RuntimeException::class,function()use($badCart,$normalizer,$decoder,$markerKey):void{
        WooSessionCartEnvelope::fromStoredValue(serialize($badCart),$normalizer,$decoder,$markerKey);
    },'recursive value');
    throws(RuntimeException::class,function()use($normalizer,$decoder,$markerKey):void{
        WooSessionCartEnvelope::fromStoredValue(str_repeat('x',8388609),$normalizer,$decoder,$markerKey);
    },'exceeds the allowed size');
});
test('Clear cart binds the exact same-turn viewed revision and rejects a concurrent cart change', function (): void {
    $factory=new CartPlanFactory();
    $viewed=snapshot(array(line('line-viewed',1)),array(),1,10,'clear-viewed');
    $concurrentlyChanged=snapshot(array(
        line('line-viewed',1),line('line-concurrent',1,20)
    ),array(),2,20,'clear-concurrent');
    $viewedRevision=$viewed->revision();
    $plan=$factory->fromToolArguments(
        array('commands'=>array(array('type'=>'clear'))),
        new AuthorityRegistry(),
        $viewedRevision
    );
    ok($plan->isClear());
    same($viewedRevision,$plan->toStorageArray()['expected_cart_revision']);
    ok($plan->authorizesPreState($viewed));
    ok(!$plan->authorizesPreState($concurrentlyChanged),
        'A clear planned from revision R must reject durable/live revision R+1.');
    $roundTrip=CartPlan::fromStorageArray($plan->toStorageArray());
    same($viewedRevision,$roundTrip->toStorageArray()['expected_cart_revision']);
    throws(InvalidArgumentException::class,static function()use($plan,$concurrentlyChanged):void{
        new OperationRecord(
            1,Uuid::v4(),7,Uuid::v4(),str_repeat('a',64),1,OperationStatus::PREPARED,
            $plan,$concurrentlyChanged,null,null,null,'','',str_repeat('b',64),1
        );
    },'clear-cart authority');
    throws(ContractViolation::class,function()use($factory):void{
        $factory->fromToolArguments(
            array('commands'=>array(array('type'=>'clear'))),
            new AuthorityRegistry(),
            ''
        );
    },'cart_clear_requires_view');
    throws(ContractViolation::class,function()use($factory,$viewedRevision):void{
        $factory->fromToolArguments(
            array('commands'=>array(array('type'=>'clear','confirmed'=>true))),
            new AuthorityRegistry(),
            $viewedRevision
        );
    },'cart_command_field_invalid');
});
test('Cart plan rejects duplicate line targets and clear mixtures', function (): void {
    $f=str_repeat('a',64);
    throws(InvalidArgumentException::class,function()use($f):void{new CartPlan(array(CartCommand::update('x',$f,1,'X'),CartCommand::remove('x',$f,'X')));});
    throws(InvalidArgumentException::class,function():void{new CartPlan(array(CartCommand::clear(),cartAddCommandForTest(1,0,1,'X')));});
});
test('Cart snapshots reject unsafe checkout URLs', function (): void {
    $bad=facts(0,0,'h'); $bad['checkout_url']='javascript:alert(1)';
    throws(InvalidArgumentException::class,function()use($bad):void{new CartSnapshot(array(),array(),$bad);});
});
test('Client cart projection excludes execution authority', function (): void {
    $snap=snapshot(array(line('line-secret',1)),array(),1,10,'h1'); $json=Json::encodeObject($snap->forClient(false));
    notContains('line-secret',$json); notContains('line_fingerprint',$json); notContains('cart_revision',$json);
});
