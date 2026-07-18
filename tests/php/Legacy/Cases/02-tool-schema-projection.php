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

// Tool declaration schema and Gemini projection.
test('Empty tool schema is valid internally', function (): void { (new ContractSchemaValidator())->validate(ToolSchemas::emptyObject()); ok(true); });
test('Runtime validator accepts an empty argument object', function (): void { (new ArgumentValidator())->validate(array(), ToolSchemas::emptyObject()); ok(true); });
test('Runtime validator rejects unknown zero-arg fields', function (): void { throws(ContractViolation::class, function (): void { (new ArgumentValidator())->validate(array('x'=>1), ToolSchemas::emptyObject()); }); });
test('Every cart clarification requires and preserves one model-authored question', function (): void {
    $handler=new RespondFollowUpHandler();
    (new ArgumentValidator())->validate(array(
        'question'=>'كم وحدة تريد إضافتها؟',
        'purpose'=>'cart_continuation',
        'cart_continuation'=>array(
            'action'=>'update','target_ref'=>'c1','missing'=>'quantity',
            'intent_text'=>'زود الكمية','quantity_mode'=>'increment',
        ),
    ),$handler->contract()->schema());
    (new ArgumentValidator())->validate(array(
        'question'=>'أي مقاس تريده للمنتج البديل؟',
        'purpose'=>'cart_continuation',
        'cart_continuation'=>array(
            'action'=>'replace','target_ref'=>'p1','source_cart_item_ref'=>'c1',
            'missing'=>'variation','intent_text'=>'استبدله بهذا',
            'quantity_mode'=>'preserve',
        ),
    ),$handler->contract()->schema());
    throws(ContractViolation::class,static function()use($handler):void{
        (new ArgumentValidator())->validate(array(
            'purpose'=>'cart_continuation',
            'cart_continuation'=>array(
                'action'=>'update','target_ref'=>'c1','missing'=>'quantity',
                'intent_text'=>'زود الكمية','quantity_mode'=>'increment',
            ),
        ),$handler->contract()->schema());
    },'required_field_missing');

    $outcomes=terminalOutcomesForTest();
    throws(ContractViolation::class,static function()use($outcomes):void{
        terminalResponseForTest($outcomes,'respond_follow_up',array(),agentContextForTest());
    },'follow_up_question_missing');
    throws(ContractViolation::class,static function()use($outcomes):void{
        terminalResponseForTest($outcomes,'respond_follow_up',array(
            'question'=>'ما الذي تفضله؟',
        ),agentContextForTest());
    },'follow_up_purpose_invalid');
    throws(ContractViolation::class,static function()use($outcomes):void{
        terminalResponseForTest($outcomes,'respond_follow_up',array(
            'question'=>'ما الكمية؟','purpose'=>'cart_continuation',
        ),agentContextForTest());
    },'cart_follow_up_continuation_missing');
    throws(ContractViolation::class,static function()use($outcomes):void{
        terminalResponseForTest($outcomes,'respond_follow_up',array(
            'question'=>'أي واحد؟','purpose'=>'cart_continuation','product_refs'=>array('p1'),
            'cart_continuation'=>array(),
        ),agentContextForTest());
    },'cart_follow_up_products_forbidden');
    throws(ContractViolation::class,static function()use($outcomes):void{
        terminalResponseForTest($outcomes,'respond_follow_up',array(
            'question'=>'ما الكمية؟','purpose'=>'cart_ambiguity',
            'cart_continuation'=>array(),
        ),agentContextForTest());
    },'follow_up_continuation_forbidden');

    $authority=new AuthorityRegistry();
    $productRef=$authority->recordProduct(array(
        'id'=>991,'name'=>'بهارات مشكلة','sku'=>'SPICE-991',
        'type'=>'variable','requires_variation'=>true,
        'attributes'=>array(array(
            'name'=>'الوزن','variation'=>true,
            'values'=>array('100 غرام','250 غرام'),
        )),
    ));
    $variation100=array(
        'id'=>9911,'parent_id'=>991,'name'=>'بهارات مشكلة 100 غرام','sku'=>'SPICE-991-100',
        'attributes'=>array(array(
            'label'=>'الوزن','value'=>'100-g','display'=>'100 غرام',
        )),'purchasable'=>true,'in_stock'=>true,
    );
    $variation250=array(
        'id'=>9912,'parent_id'=>991,'name'=>'بهارات مشكلة 250 غرام','sku'=>'SPICE-991-250',
        'attributes'=>array(array(
            'label'=>'الوزن','value'=>'250-g','display'=>'250 غرام',
        )),'purchasable'=>true,'in_stock'=>true,
    );
    $authority->recordVariationCatalog(991,array($variation100,$variation250),str_repeat('c',64));
    $question='متوفر بوزن 100 أو 250 غرام؛ أي وزن يناسبك؟';
    $response=terminalResponseForTest($outcomes,'respond_follow_up',array(
        'question'=>$question,
        'purpose'=>'cart_continuation',
        'cart_continuation'=>array(
            'action'=>'add','target_ref'=>$productRef,'missing'=>'variation',
            'intent_text'=>'أضف البهارات','quantity'=>2,
        ),
    ),cartAgentContextForTest($authority,'أضف البهارات لو سمحت'));
    same($question,$response->text());
    same(Outcome::FOLLOW_UP,$response->outcome());
    ok($response->pendingCartIntent() instanceof PendingCartIntent);
    same($question,$response->pendingCartIntent()->question());
    same(
        $question,
        PendingCartIntent::fromArray($response->pendingCartIntent()->toArray())->question()
    );
});
test('Follow-up construction requires typed model authority and server failures cannot become follow-ups', function (): void {
    throws(TypeError::class,static function():void{
        /** @phpstan-ignore-next-line Deliberately prove that a server string cannot cross this boundary. */
        AssistantResponse::followUp('هل تريد المتابعة؟',array(),null);
    });
    $failure=AssistantResponse::safeFailure(
        'تعذر إكمال الطلب الآن. أعد المحاولة لاحقاً.',
        'assistant_not_ready'
    );
    same(Outcome::SAFE_FAILURE,$failure->outcome());
    same(null,$failure->modelAuthoredQuestion());
    same(AssistantResponse::PENDING_PRESERVE,$failure->pendingCartTransition());
});

test('Model-question authority requires the exact sole call from one sealed current turn', function (): void {
    ok(!method_exists(ModelAuthoredQuestion::class,'acceptFromModel'));
    ok(!method_exists(ModelAuthoredQuestion::class,'fromArray'));
    $questionType=new ReflectionClass(ModelAuthoredQuestion::class);
    ok($questionType->getConstructor() instanceof ReflectionMethod);
    ok($questionType->getConstructor()->isPrivate());
    $storedType=new ReflectionClass(StoredModelQuestionEvidence::class);
    ok($storedType->getConstructor() instanceof ReflectionMethod);
    ok($storedType->getConstructor()->isPrivate());
    $restore=$questionType->getMethod('restore');
    same(StoredModelQuestionEvidence::class,$restore->getParameters()[0]->getType()->getName());
    throws(TypeError::class,static function():void{
        /** @phpstan-ignore-next-line Raw arrays cannot restore question authority. */
        ModelAuthoredQuestion::restore(array());
    });

    $context=cartAgentContextForTest(new AuthorityRegistry(),'أريد معرفة المقاس المناسب');
    $arguments=array(
        'question'=>'ما المقاس الذي يناسبك؟',
        'purpose'=>ModelAuthoredQuestion::PURPOSE_ORDINARY,
    );
    $call=new FunctionCall('call-sealed','provider-sealed','respond_follow_up',$arguments);
    $step=new ModelStep('step-sealed',array($call),'','STOP');
    $sealed=CurrentTurnModelStep::capture($step,$context,3);
    $question=(new ModelAuthoredQuestionFactory(new ArabicCustomerText(),new FixedClock()))
        ->accept($sealed,$call,$arguments,$context);
    same(3,$question->modelRound());
    same('respond_follow_up',$question->toolName());

    $copiedCall=new FunctionCall('call-sealed','provider-sealed','respond_follow_up',$arguments);
    throws(ContractViolation::class,static function()use(
        $sealed,$copiedCall,$arguments,$context
    ):void{
        (new ModelAuthoredQuestionFactory(new ArabicCustomerText(),new FixedClock()))
            ->accept($sealed,$copiedCall,$arguments,$context);
    },'model_question_evidence_invalid');

    $extraCall=new FunctionCall('call-extra','provider-extra','respond_answer',array(
        'text'=>'هذا رد اختباري عربي واضح.','product_refs'=>array(),'variation_refs'=>array(),
    ));
    $multiStep=CurrentTurnModelStep::capture(
        new ModelStep('step-multiple',array($call,$extraCall),'','STOP'),$context,4
    );
    throws(ContractViolation::class,static function()use(
        $multiStep,$call,$arguments,$context
    ):void{
        (new ModelAuthoredQuestionFactory(new ArabicCustomerText(),new FixedClock()))
            ->accept($multiStep,$call,$arguments,$context);
    },'model_question_evidence_invalid');

    $changed=$arguments;
    $changed['question']='ما اللون الذي يناسبك؟';
    throws(ContractViolation::class,static function()use(
        $sealed,$call,$changed,$context
    ):void{
        (new ModelAuthoredQuestionFactory(new ArabicCustomerText(),new FixedClock()))
            ->accept($sealed,$call,$changed,$context);
    },'model_question_evidence_invalid');

    $contextCopy=clone $context;
    throws(ContractViolation::class,static function()use(
        $sealed,$call,$arguments,$contextCopy
    ):void{
        (new ModelAuthoredQuestionFactory(new ArabicCustomerText(),new FixedClock()))
            ->accept($sealed,$call,$arguments,$contextCopy);
    },'model_step_turn_evidence_stale');
});

test('Current-turn model evidence binds recent intent history and pending clarification authority', function (): void {
    $arguments=array(
        'question'=>'ما الخيار الذي تفضله؟',
        'purpose'=>ModelAuthoredQuestion::PURPOSE_ORDINARY,
    );
    $call=new FunctionCall('call-context','provider-context','respond_follow_up',$arguments);
    $step=new ModelStep('step-context',array($call),'','STOP');

    $historyContext=cartAgentContextForTest(new AuthorityRegistry(),'أريد منتجاً مناسباً');
    $sealedHistory=CurrentTurnModelStep::capture($step,$historyContext,1);
    $historyProperty=new ReflectionProperty(AgentContext::class,'cartIntentHistory');
    $historyProperty->setAccessible(true);
    $historyProperty->setValue($historyContext,array(
        array('role'=>'user','text'=>'أريد قهوة'),
        array('role'=>'assistant','text'=>'هذه خيارات مناسبة.'),
    ));
    throws(ContractViolation::class,static function()use(
        $sealedHistory,$call,$arguments,$historyContext
    ):void{
        VerifiedFollowUpCall::verify(
            $sealedHistory,$call,$arguments,$historyContext,new ArabicCustomerText()
        );
    },'model_step_turn_evidence_stale');

    $authority=new AuthorityRegistry();
    $coffee=array(
        'id'=>820,'name'=>'قهوة عربية 250 غرام','sku'=>'COF-820',
        'type'=>'simple','requires_variation'=>false,'attributes'=>array(),
    );
    $dark=$coffee;
    $dark['id']=821;$dark['name']='قهوة عربية داكنة 250 غرام';$dark['sku']='COF-821';
    $coffeeRef=$authority->recordProduct($coffee);
    $darkRef=$authority->recordProduct($dark);
    $pendingContext=cartAgentContextForTest($authority,'أضف القهوة');
    $pending=pendingCartIntentForTest(
        pendingCartIntentFactoryForTest(),
        array(
            'action'=>'add','missing'=>'target','intent_text'=>'أضف القهوة',
            'candidate_commands'=>array(
                array('type'=>'add','product_ref'=>$coffeeRef,'quantity_mode'=>'default'),
                array('type'=>'add','product_ref'=>$darkRef,'quantity_mode'=>'default'),
            ),
        ),
        'أي نوع من القهوة تريد أن أضيفه؟',
        $pendingContext
    );
    $sealedPending=CurrentTurnModelStep::capture($step,$pendingContext,2);
    $pendingProperty=new ReflectionProperty(AgentContext::class,'pendingCartIntent');
    $pendingProperty->setAccessible(true);
    $pendingProperty->setValue($pendingContext,$pending);
    throws(ContractViolation::class,static function()use(
        $sealedPending,$call,$arguments,$pendingContext
    ):void{
        VerifiedFollowUpCall::verify(
            $sealedPending,$call,$arguments,$pendingContext,new ArabicCustomerText()
        );
    },'model_step_turn_evidence_stale');
});

test('Stored model-question evidence is closed self-verifying and fixed to the follow-up tool', function (): void {
    $context=cartAgentContextForTest(new AuthorityRegistry(),'أريد اختيار اللون');
    $question=modelQuestionForTest(
        'أي لون تفضله: أزرق &amp; أبيض أم أسود؟',
        $context,
        ModelAuthoredQuestion::PURPOSE_ORDINARY,
        'step-durable','call-durable','provider-durable'
    );
    $row=$question->toArray();
    same(array(
        'schema','text','model_step_id','tool_name','tool_call_id','provider_call_id',
        'client_turn_id','conversation_id','purpose','model_round',
        'validated_arguments_digest','current_turn_digest','accepted_at','evidence_digest',
    ),array_keys($row));
    same(1,$row['schema']);
    same('respond_follow_up',$row['tool_name']);
    same(1,$row['model_round']);
    same(64,strlen($row['validated_arguments_digest']));
    same(64,strlen($row['current_turn_digest']));
    same(64,strlen($row['evidence_digest']));
    same($row,restoreModelQuestionForTest($row)->toArray());

    $corrupt=$row;
    $corrupt['text']='أي لون تختار؟';
    throws(InvalidArgumentException::class,static function()use($corrupt):void{
        StoredModelQuestionEvidence::fromArray($corrupt);
    },'integrity evidence');

    $wrongTool=$row;
    $wrongTool['tool_name']='respond_answer';
    $unsigned=$wrongTool;
    unset($unsigned['evidence_digest']);
    $wrongTool['evidence_digest']=hash('sha256',Json::canonicalObject($unsigned));
    throws(InvalidArgumentException::class,static function()use($wrongTool):void{
        StoredModelQuestionEvidence::fromArray($wrongTool);
    },'provenance');

    $legacy=$row;
    unset($legacy['schema'],$legacy['tool_name'],$legacy['model_round'],
        $legacy['validated_arguments_digest'],$legacy['current_turn_digest'],$legacy['evidence_digest']);
    throws(InvalidArgumentException::class,static function()use($legacy):void{
        StoredModelQuestionEvidence::fromArray($legacy);
    },'missing or unsupported fields');

    $unknown=$row;
    $unknown['server_repair']='forbidden';
    throws(InvalidArgumentException::class,static function()use($unknown):void{
        StoredModelQuestionEvidence::fromArray($unknown);
    },'missing or unsupported fields');
});

test('Unpublished pre-Stage-D question state is invalidated explicitly', function (): void {
    $current=ConversationState::initial()->toArray();
    same(5,$current['schema']);
    $legacy=$current;
    $legacy['schema']=4;
    throws(InvalidArgumentException::class,static function()use($legacy):void{
        ConversationState::fromArray($legacy);
    },'schema is invalid');
    same('20260718.54',SchemaLifecycle::SCHEMA_VERSION);
});

test('Accepted model questions preserve exact entities punctuation and mixed product names', function (): void {
    $context=agentContextForTest();
    $text='هل تريد قهوة &amp; شاي مع USB-C، أم المنتج «A/B»؟';
    $question=modelQuestionForTest(
        $text,
        $context,
        ModelAuthoredQuestion::PURPOSE_ORDINARY,
        'step-exact-question',
        'call-exact-question',
        'provider-exact-question'
    );
    same($text,$question->text());
    same($text,restoreModelQuestionForTest($question->toArray())->text());
    same('step-exact-question',$question->modelStepId());
    same('respond_follow_up',$question->toolName());
    same(1,$question->modelRound());
    same(64,strlen($question->validatedArgumentsDigest()));
    same(64,strlen($question->currentTurnDigest()));
    same(64,strlen($question->evidenceDigest()));
    same('call-exact-question',$question->toolCallId());
    same('provider-exact-question',$question->providerCallId());
    same($context->turnId(),$question->clientTurnId());
    same($context->conversationPublicId(),$question->conversationId());
    same(ModelAuthoredQuestion::PURPOSE_ORDINARY,$question->purpose());
    $response=AssistantResponse::followUp($question,array(),null);
    same($text,$response->text());
    same($text,$response->forClient()['text']);
    notContains('قهوة & شاي',$response->text());
});

test('Model questions reject ASCII and Unicode outer whitespace instead of trimming it', function (): void {
    foreach(array(
        " ما المقاس الذي تريده؟",
        "ما المقاس الذي تريده؟\n",
        "\u{00A0}ما المقاس الذي تريده؟",
        "ما المقاس الذي تريده؟\u{2003}",
    ) as $text){
        throws(ContractViolation::class,static function()use($text):void{
            modelQuestionForTest(
                $text,
                agentContextForTest(),
                ModelAuthoredQuestion::PURPOSE_ORDINARY
            );
        },'customer_text_outer_whitespace');
    }
});

test('A new customer turn may receive new wording only with new model provenance', function (): void {
    $conversationId=Uuid::v4();
    $resource='conversation|'.$conversationId;
    $makeContext=static function(string $turnId)use($conversationId,$resource):AgentContext{
        return new AgentContext(
            array('id'=>1,'public_id'=>$conversationId,'state'=>ConversationState::initial()->toArray()),
            $turnId,
            str_repeat('a',64),
            new AuthorityRegistry(),
            new TurnEffects(),
            new TurnLease($resource,hash('sha256',$resource),str_repeat('b',32),1,time()+120)
        );
    };
    $first=modelQuestionForTest(
        'أي مقاس تريده؟',
        $makeContext(Uuid::v4()),
        ModelAuthoredQuestion::PURPOSE_ORDINARY,
        'step-first-question','call-first-question','provider-first-question'
    );
    $second=modelQuestionForTest(
        'ما المقاس الأنسب لك؟',
        $makeContext(Uuid::v4()),
        ModelAuthoredQuestion::PURPOSE_ORDINARY,
        'step-second-question','call-second-question','provider-second-question'
    );
    same($first->conversationId(),$second->conversationId());
    ok($first->clientTurnId()!==$second->clientTurnId());
    ok($first->modelStepId()!==$second->modelStepId());
    ok($first->toolCallId()!==$second->toolCallId());
    ok($first->text()!==$second->text());
});

test('Follow-up commit persists exact question bytes and matching server-only provenance', function (): void {
    $context=agentContextForTest();
    $question=modelQuestionForTest(
        'هل تريد قهوة &amp; شاي، أم المنتج «USB-C A/B»؟',
        $context,
        ModelAuthoredQuestion::PURPOSE_ORDINARY,
        'step-commit-question','call-commit-question','provider-commit-question'
    );
    $response=AssistantResponse::followUp($question,array(),null);
    $turn=new TurnRecord(
        31,1,$context->turnId(),str_repeat('d',64),TurnStatus::RUNNING,1,
        array('message_present'=>true),array(),'',1700000000,1700000000,0
    );
    $turns=new AdmissionTurnStore($turn);
    $conversation=new AdmissionConversationStore(array(
        'id'=>1,
        'public_id'=>$context->conversationPublicId(),
        'state'=>ConversationState::initial()->toArray(),
    ));
    $messages=new AdmissionMessageStore();
    $resource='conversation|'.$context->conversationPublicId();
    $lease=new TurnLease($resource,hash('sha256',$resource),str_repeat('b',32),1,1700001000);
    $committer=new TurnCommitter(
        new PassthroughTransaction(),new RecordingTurnLeasePort(),$turns,$conversation,
        $messages,new FixedClock()
    );

    $result=$committer->commit($turn,$lease,$response);
    same(true,$result->isCommitted());
    same($question->text(),$result->message()['text']);
    same($question->text(),$messages->lastAssistantPayload['message']['text']);
    same($question->toArray(),$messages->lastAssistantPayload['model_question']);
    same($question->toArray(),$turns->completed['response']['model_question']);
    same($question->text(),$turns->completed['response']['message']['text']);
    same(TurnStatus::COMPLETED,$turns->completed['status']);
    same('', $turns->completed['failure_code']);
    ok(is_array($conversation->writtenState));
    same(Outcome::FOLLOW_UP,$conversation->writtenState['last_outcome']);
    ok(!array_key_exists('model_question',$result->message()));

    $foreignQuestion=modelQuestionForTest(
        'هل تريد سؤالاً آخر؟',
        agentContextForTest(),
        ModelAuthoredQuestion::PURPOSE_ORDINARY,
        'step-foreign-question','call-foreign-question','provider-foreign-question'
    );
    $foreignTurns=new AdmissionTurnStore($turn);
    $foreignCommitter=new TurnCommitter(
        new PassthroughTransaction(),new RecordingTurnLeasePort(),$foreignTurns,
        new AdmissionConversationStore(array(
            'id'=>1,'public_id'=>$context->conversationPublicId(),
            'state'=>ConversationState::initial()->toArray(),
        )),new AdmissionMessageStore(),new FixedClock()
    );
    throws(RuntimeException::class,static function()use(
        $foreignCommitter,$turn,$lease,$foreignQuestion
    ):void{
        $foreignCommitter->commit(
            $turn,$lease,AssistantResponse::followUp($foreignQuestion,array(),null)
        );
    },'does not belong to the committed turn');
});

test('Committed follow-up replay requires exact durable question provenance', function (): void {
    $context=agentContextForTest();
    $question=modelQuestionForTest(
        'أي لون تفضله: أزرق &amp; أبيض أم أسود؟',
        $context,
        ModelAuthoredQuestion::PURPOSE_ORDINARY,
        'step-replay-question','call-replay-question','provider-replay-question'
    );
    $message=AssistantResponse::followUp($question,array(),null)->forClient();
    $message['turn_id']=$context->turnId();
    $payload=array('message'=>$message,'model_question'=>$question->toArray());
    $turn=new TurnRecord(
        21,1,$context->turnId(),str_repeat('a',64),TurnStatus::COMPLETED,1,
        array('message'=>'اختبار'),$payload,'',1700000000,1700000001,1700000002
    );
    $committer=new TurnCommitter(
        new PassthroughTransaction(),
        new RecordingTurnLeasePort(),
        new AdmissionTurnStore(),
        new AdmissionConversationStore(array(
            'id'=>1,'public_id'=>$context->conversationPublicId(),
            'state'=>ConversationState::initial()->toArray(),
        )),
        new AdmissionMessageStore(),
        new FixedClock()
    );
    $replayed=$committer->replay($turn)->message();
    same($question->text(),$replayed['text']);
    same($message,$replayed);

    $missing=$payload;
    unset($missing['model_question']);
    $missingTurn=new TurnRecord(
        22,1,$context->turnId(),str_repeat('b',64),TurnStatus::COMPLETED,1,
        array('message'=>'اختبار'),$missing,'',1700000000,1700000001,1700000002
    );
    throws(RuntimeException::class,static function()use($committer,$missingTurn):void{
        $committer->replay($missingTurn);
    },'no durable model-question provenance');

    $changed=$payload;
    $changed['message']['text']='أي لون تفضله؟';
    $changedTurn=new TurnRecord(
        23,1,$context->turnId(),str_repeat('c',64),TurnStatus::COMPLETED,1,
        array('message'=>'اختبار'),$changed,'',1700000000,1700000001,1700000002
    );
    throws(RuntimeException::class,static function()use($committer,$changedTurn):void{
        $committer->replay($changedTurn);
    },'contradicts the replay payload');
});

test('An ungrounded cart question is corrected by the model and never replaced by PHP', function (): void {
    $verifier=new class implements CartIntentVerifierPort {
        /** @var int */ public $calls=0;
        public function verify(
            CartIntentVerificationRequest $request,
            ?TurnExecutionSupervisor $supervisor=null
        ): CartIntentVerdict {
            ++$this->calls;
            return $this->calls===1
                ? CartIntentVerdict::deny(CartIntentVerdict::UNSAFE_OR_UNRESOLVED)
                : CartIntentVerdict::allow();
        }
    };
    $authority=new AuthorityRegistry();
    $productRef=$authority->recordProduct(array(
        'id'=>992,'name'=>'بهارات مشكلة','sku'=>'SPICE-992',
        'type'=>'variable','requires_variation'=>true,
        'attributes'=>array(array(
            'name'=>'الوزن','variation'=>true,
            'values'=>array('100 غرام','250 غرام'),
        )),
    ));
    $variation100=array(
        'id'=>9921,'parent_id'=>992,'name'=>'بهارات مشكلة 100 غرام','sku'=>'SPICE-992-100',
        'attributes'=>array(array(
            'label'=>'الوزن','value'=>'100-g','display'=>'100 غرام',
        )),'purchasable'=>true,'in_stock'=>true,
    );
    $variation250=array(
        'id'=>9922,'parent_id'=>992,'name'=>'بهارات مشكلة 250 غرام','sku'=>'SPICE-992-250',
        'attributes'=>array(array(
            'label'=>'الوزن','value'=>'250-g','display'=>'250 غرام',
        )),'purchasable'=>true,'in_stock'=>true,
    );
    $authority->recordVariationCatalog(992,array($variation100,$variation250),str_repeat('d',64));
    $continuation=array(
        'action'=>'add','target_ref'=>$productRef,'missing'=>'variation',
        'intent_text'=>'أضف البهارات',
    );
    $session=new QueuedModelSession(array(
        new ModelStep('step-1',array(new FunctionCall(
            'call-1','provider-call-1','respond_follow_up',array(
                'question'=>'هل تريد عبوة كيلو؟',
                'purpose'=>'cart_continuation',
                'cart_continuation'=>$continuation,
            )
        )),'','STOP'),
        new ModelStep('step-2',array(new FunctionCall(
            'call-2','provider-call-2','respond_follow_up',array(
                'question'=>'متوفر بوزن 100 أو 250 غرام؛ أي وزن يناسبك؟',
                'purpose'=>'cart_continuation',
                'cart_continuation'=>$continuation,
            )
        )),'','STOP'),
    ));
    $response=(new AgentModelLoop(
        new ToolCatalog(new ContractSchemaValidator(),new ArgumentValidator(),array(
            new RespondFollowUpHandler(),new RespondSafeFailureHandler(),
        )),
        terminalOutcomesForTest($verifier),
        new AgentLimits(6,12,131072,1024),
        new RecordingProviderWaitIsolation()
    ))->run($session,cartAgentContextForTest($authority,'أضف البهارات لو سمحت'));
    same('متوفر بوزن 100 أو 250 غرام؛ أي وزن يناسبك؟',$response->text());
    ok($response->modelAuthoredQuestion() instanceof ModelAuthoredQuestion);
    same('step-2',$response->modelAuthoredQuestion()->modelStepId());
    same('call-2',$response->modelAuthoredQuestion()->toolCallId());
    same('provider-call-2',$response->modelAuthoredQuestion()->providerCallId());
    same(2,$verifier->calls);
    same(1,count($session->submissions));
    $feedback=$session->submissions[0]['feedback'][0]->payload();
    same('terminal_contract_invalid',(string)($feedback['code']??''));
    same('cart_intent_needs_clarification',(string)($feedback['data']['reason']??''));
});
test('Continuation mismatch keeps authority but validates one adaptive AI question', function (): void {
    $authority=new AuthorityRegistry();
    $lineRef=$authority->recordCartSnapshot(array(array(
        'cart_item_key'=>'line-preserve-question',
        'line_fingerprint'=>str_repeat('6',64),
        'name'=>'قهوة عربية','quantity'=>3,
    )))[0];
    $pending=pendingCartIntentForTest(pendingCartIntentFactoryForTest(),array(
        'action'=>'update','target_ref'=>$lineRef,'missing'=>'quantity',
        'intent_text'=>'زود كمية القهوة','quantity_mode'=>'increment',
    ),'كم وحدة تريد إضافتها؟',cartAgentContextForTest($authority,'زود كمية القهوة'));
    $context=cartAgentContextForTest($authority,'تمام',$pending);
    $context->effects()->requireModelCartClarification(
        'cart_intent_continuation_mismatch',
        true
    );
    $adaptiveQuestion='لم أفهم العدد المقصود؛ كم وحدة تريد إضافتها؟';
    $response=terminalResponseForTest(terminalOutcomesForTest(),
        'respond_follow_up',
        array('question'=>$adaptiveQuestion,'purpose'=>'cart_continuation_retry'),
        $context
    );
    same($adaptiveQuestion,$response->text());
    same(AssistantResponse::PENDING_REPLACE,$response->pendingCartTransition());
    ok($response->pendingCartIntent() instanceof PendingCartIntent);
    same($pending->id(),$response->pendingCartIntent()->id());
    same(
        $pending->toArray()['expires_at'],
        $response->pendingCartIntent()->toArray()['expires_at']
    );
    same($adaptiveQuestion,$response->pendingCartIntent()->question());
    throws(ContractViolation::class,static function()use($context):void{
        terminalResponseForTest(terminalOutcomesForTest(),
            'respond_answer',array('text'=>'إجابة جديدة غير متحققة.'),$context
        );
    },'cart_clarification_response_required');
    throws(ContractViolation::class,static function()use($context):void{
        terminalResponseForTest(terminalOutcomesForTest(),'respond_follow_up',array(
            'question'=>'ما الذي تقصده؟','purpose'=>'cart_ambiguity',
        ),$context);
    },'cart_continuation_retry_required');
});
test('Model loop feeds continuation mismatch back for one validated adaptive question', function (): void {
    $handler=new class implements ToolHandlerInterface {
        /** @var ToolContract */ private $contract;
        /** @var int */ public $calls=0;
        public function __construct(){
            $this->contract=new ToolContract(
                'test_continuation_mismatch','Test mismatch.',ToolSchemas::emptyObject(),ToolContract::MUTATION
            );
        }
        public function contract(): ToolContract{return $this->contract;}
        public function execute(array $arguments,AgentContext $context): ToolExecutionResult{
            ++$this->calls;
            $context->effects()->requireModelCartClarification(
                'cart_intent_continuation_mismatch',true
            );
            return ToolExecutionResult::failure(
                'cart_intent_continuation_mismatch','',array(
                    'reason'=>CartIntentVerdict::CONTINUATION_MISMATCH,
                    'instruction'=>AgentPromptFeedback::semanticDenial(
                        CartIntentVerdict::CONTINUATION_MISMATCH
                    ),
                )
            );
        }
    };
    $authority=new AuthorityRegistry();
    $lineRef=$authority->recordCartSnapshot(array(array(
        'cart_item_key'=>'line-adaptive-question',
        'line_fingerprint'=>str_repeat('7',64),
        'name'=>'قهوة عربية','quantity'=>2,
    )))[0];
    $pending=pendingCartIntentForTest(pendingCartIntentFactoryForTest(),array(
        'action'=>'update','target_ref'=>$lineRef,'missing'=>'quantity',
        'intent_text'=>'زد القهوة','quantity_mode'=>'increment',
    ),'كم وحدة تريد إضافتها؟',cartAgentContextForTest($authority,'زد القهوة'));
    $context=cartAgentContextForTest($authority,'تمام',$pending);
    $adaptiveQuestion='لم يتضح لي العدد؛ اكتب عدد الوحدات التي تريد إضافتها.';
    $session=new QueuedModelSession(array(
        new ModelStep('step-1',array(new FunctionCall(
            'call-1','provider-call-1','test_continuation_mismatch',array()
        )),'','STOP'),
        new ModelStep('step-2',array(new FunctionCall(
            'call-2','provider-call-2','respond_follow_up',array(
                'question'=>$adaptiveQuestion,'purpose'=>'cart_continuation_retry',
            )
        )),'','STOP'),
    ));
    $response=modelLoopForTest(array(
        $handler,new RespondFollowUpHandler(),new RespondSafeFailureHandler()
    ))->run($session,$context);
    same($adaptiveQuestion,$response->text());
    same(AssistantResponse::PENDING_REPLACE,$response->pendingCartTransition());
    same($pending->id(),$response->pendingCartIntent()->id());
    same(1,$handler->calls);
    same(1,count($session->submissions));
    same(
        CartIntentVerdict::CONTINUATION_MISMATCH,
        $session->submissions[0]['feedback'][0]->payload()['data']['reason']??''
    );
});
test('Closed zero-argument tools retain strict internal parameters and omit only the Gemini wire field', function (): void {
    $contract=new ToolContract('cart_view','View cart',ToolSchemas::emptyObject());
    $declaration=$contract->modelDeclaration();
    same(ToolSchemas::emptyObject(),$declaration['parameters']??array());
    same(ToolSchemas::emptyObject(),$contract->schema());
    throws(ContractViolation::class,static function()use($contract):void{
        (new ArgumentValidator())->validate(array('unexpected'=>true),$contract->schema());
    });

    $parameterized=new ToolContract('catalog_find','Find product',ToolSchemas::closedObject(
        array('query'=>ToolSchemas::boundedText(80)),array('query')
    ));
    same($parameterized->schema(),$parameterized->modelDeclaration()['parameters']??array());

    $request=new ModelRequest('System',array(),'Show cart',array(),array($declaration),1024);
    same(ToolSchemas::emptyObject(),$request->toolDeclarations()[0]['parameters']??array());
    $wire=(new GeminiSchemaProjector())->project($request->toolDeclarations());
    ok(!array_key_exists('parameters',$wire[0]));
    $nearEmpty=$declaration;
    $nearEmpty['parameters']['required']=array();
    ok(array_key_exists('parameters',(new GeminiSchemaProjector())->project(array($nearEmpty))[0]));
});
test('Gemini projector emits properties as JSON object', function (): void {
    $decl = array(array(
        'name'=>'catalog_find','description'=>'Find product',
        'parameters'=>ToolSchemas::closedObject(
            array('query'=>ToolSchemas::boundedText(80)),array('query')
        ),
    ));
    $json = Json::encodeObject(array('functionDeclarations'=>(new GeminiSchemaProjector())->project($decl)));
    contains('"properties":{"query":', $json); notContains('"properties":[]', $json); notContains('"additionalProperties"', $json);
});
test('Model requests accept exactly twenty tool declarations and reject twenty-one', function (): void {
    same(20,ModelRequest::MAX_TOOL_DECLARATIONS);
    $declarations=array();
    for($index=1;$index<=ModelRequest::MAX_TOOL_DECLARATIONS;++$index){
        $declarations[]=array(
            'name'=>'tool_'.str_pad((string)$index,2,'0',STR_PAD_LEFT),
            'description'=>'Bounded declaration '.$index,
            'parameters'=>ToolSchemas::emptyObject(),
        );
    }
    $request=new ModelRequest('System',array(),'Test tools',array(),$declarations,1024);
    same(20,count($request->toolDeclarations()));
    $declarations[]=array(
        'name'=>'tool_21','description'=>'Declaration outside the supported catalog',
        'parameters'=>ToolSchemas::emptyObject(),
    );
    throws(ModelProtocolException::class,static function()use($declarations):void{
        new ModelRequest('System',array(),'Test tools',array(),$declarations,1024);
    },'tool_declarations_invalid');
});
test('Gemini gateway uses production schema projection', function (): void {
    $transport = new QueueTransport(array(modelResponse(array(array('functionCall'=>array('id'=>'call-1','name'=>'cart_view','args'=>array()))))));
    $request = new ModelRequest('System', array(), 'Show my cart', array(), array(
        array('name'=>'cart_view','description'=>'View cart','parameters'=>ToolSchemas::emptyObject()),
    ), 1024);
    $session = geminiGatewayForTest($transport)->start($request);
    $step = $session->next();
    same('cart_view', $step->calls()[0]->name());
    $json = Json::encodeObject($transport->payloads[0]);
    notContains('"parameters"', $json); notContains('"properties":[]', $json);
});
test('Gemini 3 generation policy makes thinking explicit and closed', function (): void {
    $policy=new GeminiGenerationPolicy('low');
    same(array(
        'maxOutputTokens'=>2048,
        'thinkingConfig'=>array('thinkingLevel'=>'low'),
    ),$policy->initialConfig(2048));
    same(
        'low',
        (new GeminiGenerationPolicy('unsupported'))->initialConfig(2048)['thinkingConfig']['thinkingLevel']??''
    );
});

test('Gemini gateway sends low thinking for normal Gemini 3 turns', function (): void {
    $transport=new QueueTransport(array(modelResponse(array(array('functionCall'=>array('name'=>'cart_view','args'=>array()))))));
    $request=new ModelRequest(
        'System',array(),'Show cart',array(),
        array(array('name'=>'cart_view','description'=>'View cart','parameters'=>ToolSchemas::emptyObject())),
        2048
    );
    geminiGatewayForTest($transport)->start($request)->next();
    same('low',$transport->payloads[0]['generationConfig']['thinkingConfig']['thinkingLevel']??'');
    same(2048,$transport->payloads[0]['generationConfig']['maxOutputTokens']??0);
    same('VALIDATED',$transport->payloads[0]['toolConfig']['functionCallingConfig']['mode']??'');
    ok(!array_key_exists(
        'allowedFunctionNames',
        $transport->payloads[0]['toolConfig']['functionCallingConfig']??array()
    ));
    $gateway=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/Gemini/GeminiGateway.php');
    contains("'mode' => 'VALIDATED'",$gateway);
    notContains('allowedFunctionNames',$gateway);
    notContains('verify_cart_mutation_intent',$gateway);
});

test('Gemini session keeps one validated full-catalog contract across rounds', function (): void {
    $transport=new QueueTransport(array(
        modelResponse(array(array('functionCall'=>array(
            'id'=>'round-cart','name'=>'cart_view','args'=>array(),
        )))),
        modelResponse(array(array('functionCall'=>array(
            'id'=>'round-answer','name'=>'respond_answer','args'=>array('text'=>'تم'),
        )))),
    ));
    $request=new ModelRequest(
        'System',array(),'Probe',array(),array(
            array('name'=>'cart_view','description'=>'View cart','parameters'=>ToolSchemas::emptyObject()),
            array(
                'name'=>'respond_answer','description'=>'Answer',
                'parameters'=>ToolSchemas::closedObject(
                    array('text'=>ToolSchemas::boundedText(80)),array('text')
                ),
            ),
        ),1024
    );
    $session=geminiGatewayForTest($transport)->start($request);
    $cart=$session->next();
    same('VALIDATED',$transport->payloads[0]['toolConfig']['functionCallingConfig']['mode']??'');
    ok(!array_key_exists(
        'allowedFunctionNames',
        $transport->payloads[0]['toolConfig']['functionCallingConfig']??array()
    ));
    $session->submit($cart,array(FunctionFeedback::forCall(
        $cart->calls()[0],array('ok'=>true,'data'=>array())
    )));

    $session->next();
    same('VALIDATED',$transport->payloads[1]['toolConfig']['functionCallingConfig']['mode']??'');
    ok(!array_key_exists(
        'allowedFunctionNames',
        $transport->payloads[1]['toolConfig']['functionCallingConfig']??array()
    ));
    same($transport->payloads[0]['tools'],$transport->payloads[1]['tools']);
});

test('Gemini session requires one next function without changing later AI-led defaults', function (): void {
    $transport=new QueueTransport(array(
        modelResponse(array(array('functionCall'=>array(
            'id'=>'restricted-cart','name'=>'cart_view','args'=>array(),
        )))),
        modelResponse(array(array('functionCall'=>array(
            'id'=>'unrestricted-answer','name'=>'respond_answer','args'=>array('text'=>'تم'),
        )))),
    ));
    $request=new ModelRequest(
        'System',array(),'Probe',array(),array(
            array('name'=>'cart_view','description'=>'View cart','parameters'=>ToolSchemas::emptyObject()),
            array(
                'name'=>'respond_answer','description'=>'Answer',
                'parameters'=>ToolSchemas::closedObject(
                    array('text'=>ToolSchemas::boundedText(80)),array('text')
                ),
            ),
        ),1024
    );
    $session=geminiGatewayForTest($transport)->start($request);
    throws(ModelProtocolException::class,static function()use($session):void{
        $session->requireOnlyNextFunction('not_declared');
    },'model_function_constraint_invalid');
    $session->requireOnlyNextFunction('cart_view');
    $cart=$session->next();
    same(
        array('cart_view'),
        $transport->payloads[0]['toolConfig']['functionCallingConfig']['allowedFunctionNames']??array()
    );
    same('ANY',$transport->payloads[0]['toolConfig']['functionCallingConfig']['mode']??'');
    $session->submit($cart,array(FunctionFeedback::forCall(
        $cart->calls()[0],array('ok'=>true,'data'=>array())
    )));
    $session->next();
    same('VALIDATED',$transport->payloads[1]['toolConfig']['functionCallingConfig']['mode']??'');
    ok(!array_key_exists(
        'allowedFunctionNames',
        $transport->payloads[1]['toolConfig']['functionCallingConfig']??array()
    ));
});

test('Runtime probe maps deterministic provider failures without exposing provider text', function (): void {
    $clock=new MutableClock(1700000000);
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array(
        'gemini_api_key'=>'test-key',
    )));
    $settings=new Settings();
    $readiness=runtimeReadinessForTest($settings,$clock);
    $transport=new CallbackTimedTransport(static function(array $payload,int $request): array {
        unset($payload,$request);
        throw new GeminiException(
            'authentication_error',
            'تعذر التحقق من بيانات اعتماد المزود.',
            'secret provider diagnostic',
            'tools[0].functionDeclarations[0].parameters',
            17
        );
    });
    $probe=runtimeProbeForTest($transport,$settings,$readiness);
    try {
        $probe->testConnection();
        throw new TestFailure('Expected provider-access failure.');
    } catch (GeminiException $exception) {
        same('authentication_error',$exception->reasonCode());
        contains('فشل فحص الوصول إلى النموذج',$exception->safeMessage());
        notContains('secret provider diagnostic',$exception->safeMessage());
        same(17,$exception->retryAfterSeconds());
    }
    same('authentication_error',$readiness->status()['code']);
    same('authentication_error',$readiness->status()['last_failure_code']);
    same(array(20),$transport->timeouts);
});

test('Runtime probe uses exactly one access request and one minimal structured-tool request', function (): void {
    $clock=new MutableClock(1700000000);
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array(
        'gemini_api_key'=>'test-key','http_timeout_seconds'=>90,
    )));
    $settings=new Settings();
    $readiness=runtimeReadinessForTest($settings,$clock);
    $transport=new CallbackTimedTransport(static function(array $payload,int $request): array {
        if($request===1){
            ok(!array_key_exists('tools',$payload));
            ok(!array_key_exists('toolConfig',$payload));
            same(RuntimeProbeContract::accessUserMessage(),$payload['contents'][0]['parts'][0]['text']??'');
            return modelResponse(array(array('text'=>'READY')));
        }
        same(2,$request);
        same(RuntimeProbeContract::TOOL,$payload['tools'][0]['functionDeclarations'][0]['name']??'');
        same('ANY',$payload['toolConfig']['functionCallingConfig']['mode']??'');
        same(array(RuntimeProbeContract::TOOL),$payload['toolConfig']['functionCallingConfig']['allowedFunctionNames']??array());
        same(1,count($payload['tools'][0]['functionDeclarations']??array()));
        return runtimeProbeSuccessResponse($payload);
    });
    $result=runtimeProbeForTest($transport,$settings,$readiness)->testConnection();
    (new AdminTestResponseProjector(publicResponseValidatorForTest()))->project($result);
    ok($result['ok']);
    same(2,$result['provider_requests']);
    same(array('provider_access'=>'passed','structured_tool'=>'passed'),$result['checks']);
    same(2,count($transport->payloads));
    same(array(20,20),$transport->timeouts);
    same('ready',$readiness->status()['code']);
    same(1700000000,$readiness->status()['checked_at']);
    same(1702592000,$readiness->status()['expires_at']);
    ok($readiness->isReady());
    $serialized=Json::encode($transport->payloads);
    notContains('catalog_discover',$serialized);
    notContains('cart_apply',$serialized);
    notContains('verify_current_cart_intent',$serialized);
    notContains('respond_follow_up',$serialized);
});

test('Runtime probe rejects malformed access and structured responses without server repair', function (): void {
    $clock=new MutableClock(1700000000);
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array(
        'gemini_api_key'=>'test-key',
    )));
    $settings=new Settings();
    $readiness=runtimeReadinessForTest($settings,$clock);
    $badAccess=new TimedQueueTransport(array(modelResponse(array(array('text'=>'   ')))));
    throws(GeminiException::class,static function()use($badAccess,$settings,$readiness):void{
        runtimeProbeForTest($badAccess,$settings,$readiness)->testConnection();
    },'runtime_probe_access_response_invalid');
    same('runtime_probe_access_response_invalid',$readiness->status()['code']);

    $readiness=runtimeReadinessForTest(new Settings(),$clock);
    $badTool=new CallbackTimedTransport(static function(array $payload,int $request): array {
        if($request===1){return modelResponse(array(array('text'=>'READY')));}
        $token=runtimeProbeTokenFromPayload($payload);
        return modelResponse(array(array('functionCall'=>array(
            'name'=>RuntimeProbeContract::TOOL,
            'args'=>array('token'=>$token.'0'),
        ))));
    });
    throws(GeminiException::class,static function()use($badTool,$settings,$readiness):void{
        runtimeProbeForTest($badTool,$settings,$readiness)->testConnection();
    },'runtime_probe_structured_tool_invalid');
    same('runtime_probe_structured_tool_invalid',$readiness->status()['code']);
});

test('Fresh duplicate runtime probes are rejected and stale attempts are fenced before request two', function (): void {
    $clock=new MutableClock(1700000000);
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array(
        'gemini_api_key'=>'test-key','http_timeout_seconds'=>90,
    )));
    $settings=new Settings();
    $readiness=runtimeReadinessForTest($settings,$clock);
    $active=$readiness->beginCheck();
    try {
        $readiness->beginCheck();
        throw new TestFailure('Expected fresh probe collision.');
    } catch (RuntimeProbeInProgress $exception) {
        same(RuntimeProbeTiming::staleAfterSeconds(90),$exception->retryAfterSeconds());
    }
    $transport=new TimedQueueTransport(array(modelResponse(array(array('text'=>'unused')))));
    try {
        runtimeProbeForTest($transport,$settings,$readiness)->testConnection();
        throw new TestFailure('Expected public in-progress failure.');
    } catch (GeminiException $exception) {
        same('runtime_probe_in_progress',$exception->reasonCode());
        same(RuntimeProbeTiming::staleAfterSeconds(90),$exception->retryAfterSeconds());
    }
    same(0,count($transport->payloads));

    $clock->advance(RuntimeProbeTiming::staleAfterSeconds(90)+1);
    $replacement='';
    $supersedingTransport=new CallbackTimedTransport(static function(array $payload,int $request)use($readiness,$clock,&$replacement): array {
        if($request===1){
            $clock->advance(RuntimeProbeTiming::staleAfterSeconds(90)+1);
            $replacement=$readiness->beginCheck();
            return modelResponse(array(array('text'=>'READY')));
        }
        throw new TestFailure('Superseded probe spent a second provider request.');
    });
    throws(GeminiException::class,static function()use($supersedingTransport,$settings,$readiness):void{
        runtimeProbeForTest($supersedingTransport,$settings,$readiness)->testConnection();
    },'runtime_probe_superseded');
    same(1,count($supersedingTransport->payloads));
    same('runtime_check_in_progress',$readiness->status()['code']);
    ok($replacement!=='');
    $readiness->markReady($replacement);
    same('ready',$readiness->status()['code']);
    unset($active);
});

test('A valid readiness proof stays authoritative during rechecks and transient failures', function (): void {
    $clock=new MutableClock(1700000000);
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array(
        'gemini_api_key'=>'test-key','http_timeout_seconds'=>90,
    )));
    $readiness=runtimeReadinessForTest(new Settings(),$clock);
    $first=$readiness->beginCheck();
    $readiness->markReady($first);
    $proof=$readiness->status();

    $recheck=$readiness->beginCheck();
    $during=$readiness->status();
    ok($during['ready']);
    same('ready_recheck_in_progress',$during['code']);
    same($proof['checked_at'],$during['checked_at']);
    same($proof['expires_at'],$during['expires_at']);

    $readiness->markFailed('runtime_probe_network_error',$recheck);
    $failed=$readiness->status();
    ok($failed['ready']);
    same('ready_with_probe_failure',$failed['code']);
    same('runtime_probe_network_error',$failed['last_failure_code']);
    same($proof['checked_at'],$failed['checked_at']);
    same($proof['expires_at'],$failed['expires_at']);

    $interrupted=$readiness->beginCheck();
    $clock->advance(RuntimeProbeTiming::staleAfterSeconds(90));
    $status=$readiness->status();
    ok($status['ready']);
    same('ready_recheck_interrupted',$status['code']);
    throws(RuntimeException::class,static function()use($readiness,$interrupted):void{
        $readiness->markReady($interrupted);
    },'no longer current');
});

test('Only deterministic provider or minimal-probe contradictions revoke readiness', function (): void {
    $clock=new MutableClock(1700000000);
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array(
        'gemini_api_key'=>'test-key',
    )));
    $readiness=runtimeReadinessForTest(new Settings(),$clock);
    $attempt=$readiness->beginCheck();
    $readiness->markReady($attempt);
    foreach(array('runtime_probe_timeout','runtime_probe_rate_limited','runtime_probe_upstream_unavailable') as $transient){
        $recheck=$readiness->beginCheck();
        $readiness->markFailed($transient,$recheck);
        ok($readiness->isReady());
        same('ready_with_probe_failure',$readiness->status()['code']);
    }
    $recheck=$readiness->beginCheck();
    $readiness->markFailed('runtime_probe_access_contract_rejected',$recheck);
    ok(!$readiness->isReady());
    same('runtime_probe_access_contract_rejected',$readiness->status()['code']);

    $attempt=$readiness->beginCheck();
    $readiness->markReady($attempt);
    $readiness->invalidate('provider_service_disabled');
    ok(!$readiness->isReady());
    same('provider_service_disabled',$readiness->status()['code']);

    throws(InvalidArgumentException::class,static function()use($readiness):void{
        $readiness->invalidate('network_error');
    },'only by a deterministic');
    throws(InvalidArgumentException::class,static function()use($readiness):void{
        $readiness->markFailed('provider_precondition_failed',str_repeat('0',32));
    },'outside the closed policy');
});

test('Runtime readiness status preserves unrelated option-cache authority', function (): void {
    $clock=new MutableClock(1700000000);
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array(
        'gemini_api_key'=>'test-key',
    )));
    $readiness=runtimeReadinessForTest(new Settings(),$clock);
    $attempt=$readiness->beginCheck();
    $readiness->markReady($attempt);
    $proof=$GLOBALS['ysai_test_options'][GeminiRuntimeReadiness::OPTION_KEY]??array();
    $GLOBALS['ysai_test_cache']['options']['alloptions']=array(
        GeminiRuntimeReadiness::OPTION_KEY=>$proof,
        'unrelated_autoloaded_option'=>'must-survive',
    );
    $GLOBALS['ysai_test_cache']['options']['notoptions']=array('unrelated_missing'=>true);
    $GLOBALS['ysai_test_cache_option_reads']=true;
    $GLOBALS['ysai_test_cache_deletes']=array();
    try {
        same('ready',$readiness->status()['code']);
        $next=$readiness->beginCheck();
        ok($next!=='');
        same('must-survive',$GLOBALS['ysai_test_cache']['options']['alloptions']['unrelated_autoloaded_option']??'');
        ok(isset($GLOBALS['ysai_test_cache']['options']['notoptions']['unrelated_missing']));
        ok(!in_array(array('options','alloptions'),$GLOBALS['ysai_test_cache_deletes'],true));
    } finally {
        $GLOBALS['ysai_test_cache_option_reads']=false;
        $GLOBALS['ysai_test_cache']=array();
        $GLOBALS['ysai_test_cache_deletes']=array();
    }
});

test('Runtime readiness fingerprints only provider configuration and the complete minimal wire contract', function (): void {
    $clock=new MutableClock(1700000000);
    $base=array_replace(Settings::defaults(),array(
        'gemini_api_key'=>'test-key','gemini_thinking_level'=>'low',
    ));
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>$base);
    $readiness=runtimeReadinessForTest(new Settings(),$clock);
    $attempt=$readiness->beginCheck();
    $readiness->markReady($attempt);
    same('ready',$readiness->status()['code']);

    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY]=array_replace($base,array(
        'store_guidance'=>'new shopper guidance','max_tool_rounds'=>9,
        'max_output_tokens'=>8192,'http_timeout_seconds'=>90,'allow_images'=>0,
        'widget_title'=>'عنوان مختلف',
    ));
    same('ready',runtimeReadinessForTest(new Settings(),$clock)->status()['code']);

    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY]=array_replace($base,array(
        'gemini_thinking_level'=>'high',
    ));
    same('runtime_configuration_changed',runtimeReadinessForTest(new Settings(),$clock)->status()['code']);
    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY]=array_replace($base,array(
        'gemini_api_key'=>'different-key',
    ));
    same('runtime_configuration_changed',runtimeReadinessForTest(new Settings(),$clock)->status()['code']);

    same(64,strlen(RuntimeProbeContract::fingerprint('low')));
    ok(RuntimeProbeContract::fingerprint('low')!==RuntimeProbeContract::fingerprint('high'));
    $source=(string)file_get_contents(YSAI_PROJECT_ROOT.'/src/Infrastructure/Gemini/GeminiRuntimeReadiness.php');
    notContains('hash_file',$source);
    notContains('AgentPromptBuilder',$source);
    notContains('ToolCatalog',$source);
    notContains('CartIntentVerifier',$source);
    notContains('store_guidance',$source);
    notContains('max_tool_rounds',$source);
});

test('Runtime configuration epoch prevents old A-B-A proofs from reviving', function (): void {
    $clock=new MutableClock(1700000000);
    $a=array_replace(Settings::defaults(),array(
        'gemini_api_key'=>'key-a','gemini_thinking_level'=>'low',
        'runtime_configuration_epoch'=>0,
    ));
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>$a);
    $settings=new Settings();
    $readiness=runtimeReadinessForTest($settings,$clock);
    $attempt=$readiness->beginCheck();
    $readiness->markReady($attempt);

    $input=$a;
    $input['gemini_api_key']='key-b';
    $b=$settings->sanitize($input);
    same(1,$b['runtime_configuration_epoch']);
    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY]=$b;
    same('runtime_configuration_changed',runtimeReadinessForTest(new Settings(),$clock)->status()['code']);

    $settingsB=new Settings();
    $input=$b;
    $input['gemini_api_key']='key-a';
    $aAgain=$settingsB->sanitize($input);
    same(2,$aAgain['runtime_configuration_epoch']);
    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY]=$aAgain;
    same('runtime_configuration_changed',runtimeReadinessForTest(new Settings(),$clock)->status()['code']);

    $settingsA=new Settings();
    $unrelated=$aAgain;
    $unrelated['widget_title']='عنوان جديد';
    $sameProvider=$settingsA->sanitize($unrelated);
    same(2,$sameProvider['runtime_configuration_epoch']);
    $invalid=$settingsA->sanitize(array_replace($unrelated,array('gemini_thinking_level'=>'invalid')));
    same('low',$invalid['gemini_thinking_level']);
    same(2,$invalid['runtime_configuration_epoch']);
});

test('Runtime readiness expires at the exact thirty-day boundary', function (): void {
    $clock=new MutableClock(1700000000);
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array(
        'gemini_api_key'=>'test-key',
    )));
    $readiness=runtimeReadinessForTest(new Settings(),$clock);
    $attempt=$readiness->beginCheck();
    $readiness->markReady($attempt);
    $clock->set(1702591999);
    ok($readiness->isReady());
    same('ready',$readiness->status()['code']);
    $clock->set(1702592000);
    same('runtime_check_expired',$readiness->status()['code']);
    ok(!$readiness->isReady());
});

test('A stale readiness attempt cannot overwrite a newer attempt', function (): void {
    $clock=new MutableClock(1700000000);
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array(
        'gemini_api_key'=>'test-key','http_timeout_seconds'=>90,
    )));
    $readiness=runtimeReadinessForTest(new Settings(),$clock);
    $olderAttempt=$readiness->beginCheck();
    $clock->advance(RuntimeProbeTiming::staleAfterSeconds(90)+1);
    $newerAttempt=$readiness->beginCheck();
    $newerState=$GLOBALS['ysai_test_options'][GeminiRuntimeReadiness::OPTION_KEY]??array();
    throws(RuntimeException::class,static function()use($readiness,$olderAttempt):void{
        $readiness->markReady($olderAttempt);
    },'no longer current');
    same($newerState,$GLOBALS['ysai_test_options'][GeminiRuntimeReadiness::OPTION_KEY]??array());
    $readiness->markReady($newerAttempt);
    same('ready',$readiness->status()['code']);
});

test('Runtime readiness persistence preserves proof on transient write failure and fails closed on revocation failure', function (): void {
    $clock=new MutableClock(1700000000);
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array(
        'gemini_api_key'=>'test-key',
    )));
    $GLOBALS['ysai_test_option_write_failures']=array();
    $GLOBALS['ysai_test_option_delete_failures']=array();
    $readiness=runtimeReadinessForTest(new Settings(),$clock);
    $attempt=$readiness->beginCheck();
    $readiness->markReady($attempt);
    $recheck=$readiness->beginCheck();
    $before=get_option(GeminiRuntimeReadiness::OPTION_KEY,array());
    $GLOBALS['ysai_test_option_write_failures'][GeminiRuntimeReadiness::OPTION_KEY]=true;
    try {
        throws(RuntimeException::class,static function()use($readiness,$recheck):void{
            $readiness->markFailed('runtime_probe_network_error',$recheck);
        },'Unable to persist');
        same($before,get_option(GeminiRuntimeReadiness::OPTION_KEY,array()));
        ok($readiness->isReady());
        same('ready_recheck_in_progress',$readiness->status()['code']);
    } finally {
        $GLOBALS['ysai_test_option_write_failures']=array();
    }

    $readiness->markFailed('runtime_probe_network_error',$recheck);
    $revocation=$readiness->beginCheck();
    $GLOBALS['ysai_test_option_write_failures'][GeminiRuntimeReadiness::OPTION_KEY]=true;
    try {
        throws(RuntimeException::class,static function()use($readiness,$revocation):void{
            $readiness->markFailed('runtime_probe_access_contract_rejected',$revocation);
        },'Unable to persist');
        same(array(),get_option(GeminiRuntimeReadiness::OPTION_KEY,array()));
        ok(!$readiness->isReady());
    } finally {
        $GLOBALS['ysai_test_option_write_failures']=array();
        $GLOBALS['ysai_test_option_delete_failures']=array();
    }
});

test('Malformed and impossible readiness records never authorize shopper traffic', function (): void {
    $clock=new MutableClock(1700000000);
    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array(
        'gemini_api_key'=>'test-key',
    )));
    $readiness=runtimeReadinessForTest(new Settings(),$clock);
    foreach(array(
        array('status'=>'ready'),
        array(),
    ) as $invalid){
        $GLOBALS['ysai_test_options'][GeminiRuntimeReadiness::OPTION_KEY]=$invalid;
        same('runtime_check_required',$readiness->status()['code']);
        ok(!$readiness->isReady());
    }
    $attempt=$readiness->beginCheck();
    $readiness->markReady($attempt);
    $state=get_option(GeminiRuntimeReadiness::OPTION_KEY,array());
    $state['last_failure_code']='runtime_probe_access_contract_rejected';
    $state['last_failure_at']=$clock->now();
    $GLOBALS['ysai_test_options'][GeminiRuntimeReadiness::OPTION_KEY]=$state;
    same('runtime_check_required',$readiness->status()['code']);
    ok(!$readiness->isReady());
});

test('Runtime-readiness failure policy is closed and distinguishes transient from contradictory failures', function (): void {
    ok(RuntimeReadinessFailurePolicy::contradictsProof('authentication_error'));
    ok(RuntimeReadinessFailurePolicy::contradictsProof('provider_service_disabled'));
    ok(!RuntimeReadinessFailurePolicy::contradictsProof('request_precondition_rejected'));
    ok(!RuntimeReadinessFailurePolicy::contradictsProof('provider_precondition_failed'));
    ok(RuntimeReadinessFailurePolicy::probeFailureContradictsProof('runtime_probe_tool_contract_rejected'));
    ok(!RuntimeReadinessFailurePolicy::probeFailureContradictsProof('runtime_probe_timeout'));
    same('runtime_probe_timeout',RuntimeProbeFailureMapper::code(
        RuntimeProbeFailureMapper::PROVIDER_ACCESS,'provider_timeout'
    ));
    same('runtime_probe_tool_precondition_rejected',RuntimeProbeFailureMapper::code(
        RuntimeProbeFailureMapper::STRUCTURED_TOOL,'request_precondition_rejected'
    ));
    same('runtime_probe_structured_tool_failed',RuntimeProbeFailureMapper::code(
        RuntimeProbeFailureMapper::STRUCTURED_TOOL,'unexpected_provider_code'
    ));
    throws(InvalidArgumentException::class,static function():void{
        RuntimeReadinessFailurePolicy::requireProbeFailure('invented_failure');
    },'outside the closed policy');
});

test('A required provider timeout cannot silently fall back to an untimed transport', function (): void {
    $transport=new UntimedQueueTransport(array(modelResponse(array(array('functionCall'=>array(
        'name'=>'cart_view','args'=>array(),
    ))))));
    $request=new ModelRequest('System',array(),'Request',array(),array(
        array('name'=>'cart_view','description'=>'View cart','parameters'=>ToolSchemas::emptyObject()),
    ),256);
    $session=geminiGatewayForTest($transport)->start($request);
    $session->setNextTimeoutSeconds(5);
    throws(ModelProtocolException::class,static function()use($session):void{
        $session->next();
    },'provider_timeout_unsupported');
    same(0,$transport->generateCalls);
});

test('Administrative readiness errors use bounded endpoint-specific HTTP semantics', function (): void {
    $make=static function(string $providerCode): WP_REST_Response {
        $clock=new MutableClock(1700000000);
        $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>array_replace(Settings::defaults(),array(
            'gemini_api_key'=>'test-key',
        )));
        $settings=new Settings();
        $readiness=runtimeReadinessForTest($settings,$clock);
        $transport=new CallbackTimedTransport(static function(array $payload,int $request)use($providerCode): array {
            unset($payload,$request);
            throw new GeminiException($providerCode,'رسالة آمنة.','internal');
        });
        $controller=new \YassinStore\AiAssistant\Presentation\Rest\Controller\AdminController(
            runtimeProbeForTest($transport,$settings,$readiness),
            apiResponderForTest(),
            new AdminTestResponseProjector(publicResponseValidatorForTest())
        );
        return $controller->testConnection(new WP_REST_Request());
    };
    $cases=array(
        'request_contract_rejected'=>array(422,'runtime_probe_access_contract_rejected'),
        'rate_limited'=>array(429,'runtime_probe_rate_limited'),
        'provider_timeout'=>array(504,'runtime_probe_timeout'),
        'network_error'=>array(503,'runtime_probe_network_error'),
        'upstream_rejected'=>array(502,'runtime_probe_upstream_rejected'),
    );
    foreach($cases as $providerCode=>$expected){
        $response=$make($providerCode);
        same($expected[0],$response->status);
        same($expected[1],$response->data['code']??'');
    }

    $GLOBALS['ysai_test_options']=array(Settings::OPTION_KEY=>Settings::defaults());
    $settings=new Settings();
    $clock=new MutableClock(1700000000);
    $controller=new \YassinStore\AiAssistant\Presentation\Rest\Controller\AdminController(
        runtimeProbeForTest(new TimedQueueTransport(array()),$settings,runtimeReadinessForTest($settings,$clock)),
        apiResponderForTest(),
        new AdminTestResponseProjector(publicResponseValidatorForTest())
    );
    same(400,$controller->testConnection(new WP_REST_Request())->status);

    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY]=array_replace(Settings::defaults(),array('gemini_api_key'=>'key'));
    $settings=new Settings();
    $readiness=runtimeReadinessForTest($settings,$clock);
    $readiness->beginCheck();
    $controller=new \YassinStore\AiAssistant\Presentation\Rest\Controller\AdminController(
        runtimeProbeForTest(new TimedQueueTransport(array()),$settings,$readiness),
        apiResponderForTest(),
        new AdminTestResponseProjector(publicResponseValidatorForTest())
    );
    $response=$controller->testConnection(new WP_REST_Request());
    same(409,$response->status);
    same('runtime_probe_in_progress',$response->data['code']??'');
    ok((int)($response->data['retry_after']??0)>0);
});

test('Gemini runtime-probe timing is bounded to two provider requests', function (): void {
    same(10,RuntimeProbeTiming::providerRequestSeconds(10));
    same(20,RuntimeProbeTiming::providerRequestSeconds(90));
    same(30,RuntimeProbeTiming::maximumExecutionSeconds(10));
    same(50,RuntimeProbeTiming::maximumExecutionSeconds(90));
    same(65,RuntimeProbeTiming::staleAfterSeconds(90));
    same(80000,RuntimeProbeTiming::clientTimeoutMilliseconds(90));
    same(2592000,RuntimeReadinessPolicy::READY_TTL_SECONDS);

    $root=YSAI_PROJECT_ROOT;
    $admin=(string)file_get_contents($root.'/assets/js/admin.js');
    contains('new AbortController()',$admin);
    contains('signal: controller.signal',$admin);
    contains('timeoutMs = 80000;',$admin);
    $probe=(string)file_get_contents($root.'/src/Infrastructure/Gemini/GeminiRuntimeProbe.php');
    same(2,RuntimeProbeContract::REQUEST_COUNT);
    contains("'provider_requests' => RuntimeProbeContract::REQUEST_COUNT",$probe);
    contains('GeminiTimeoutTransportInterface $transport',$probe);
    notContains('catalog_discover',$probe);
    notContains('cart_apply',$probe);
    notContains('verify_current_cart_intent',$probe);
});

test('Gemini endpoint is HTTPS-only outside explicit integration mode', function (): void {
    $endpoint=new GeminiEndpoint('https://example.test/v1beta/models');
    same('https://example.test/v1beta/models/test-model:generateContent',$endpoint->generateContent('test-model'));
    throws(InvalidArgumentException::class,static function(): void { new GeminiEndpoint('http://example.test/v1beta/models'); },'must use HTTPS outside integration tests');
    throws(InvalidArgumentException::class,static function(): void { new GeminiEndpoint('https://user:pass@example.test/v1beta/models'); },'base URL is invalid');
    throws(InvalidArgumentException::class,static function() use($endpoint): void { $endpoint->generateContent('../bad'); },'model identifier is invalid');
});
test('Integration harness is source-only and explicitly test-gated', function (): void {
    $root=YSAI_PROJECT_ROOT;
    ok(is_dir($root.'/integration'));
    $endpoint=(string)file_get_contents($root.'/src/Infrastructure/Gemini/GeminiEndpoint.php');
    $controls=(string)file_get_contents($root.'/integration/wordpress/mu-plugins/ysai-integration-harness.php');
    $package=(string)file_get_contents($root.'/scripts/package.py');
    $compose=(string)file_get_contents($root.'/integration/docker-compose.yml');
    $playwright=(string)file_get_contents($root.'/integration/tests/playwright.config.js');
    contains('YSAI_INTEGRATION_TEST_MODE',$endpoint);
    contains('YSAI_INTEGRATION_TEST_MODE',$controls);
    contains('http_request_host_is_external',$controls);
    contains('fake-gemini:8787/v1beta/models/',$controls);
    contains('127.0.0.1:${YSAI_TEST_HOST_PORT:-8080}:80',$compose);
    contains('./artifacts:/artifacts',$compose);
    contains('YSAI_TEST_OUTPUT_DIR',$playwright);
    contains('PRODUCTION_ROOTS',$package);
    contains('SOURCE_ROOTS',$package);
    contains('Unexpected top-level release member',$package);
    contains('"artifacts"',$package);
    notContains('YSAI_GEMINI_API_BASE_URL',(string)file_get_contents($root.'/src/Infrastructure/WordPress/Settings.php'));
});
