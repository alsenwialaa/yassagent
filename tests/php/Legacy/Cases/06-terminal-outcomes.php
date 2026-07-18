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

// Terminal outcomes and effect invariants.
test('Assistant answers cannot be blank', function (): void { throws(InvalidArgumentException::class,function():void{AssistantResponse::answer('   ');}); });
test('Safe failure requires caller-owned nonblank text and preserves its code', function (): void {
    throws(InvalidArgumentException::class,function():void{AssistantResponse::safeFailure('','model_protocol_error');});
    $r=AssistantResponse::safeFailure('Protocol failed.','model_protocol_error');
    same('Protocol failed.',$r->text()); same('model_protocol_error',$r->failureCode());
});
test('Verified action text must be generated from the receipt', function (): void {
    $receipt=receipt(true); $response=AssistantResponse::verifiedAction($receipt->safeMessage(),array($receipt)); same('action_verified',$response->outcome());
    throws(InvalidArgumentException::class,function()use($receipt):void{AssistantResponse::verifiedAction('Different',array($receipt));});
});
test('Maximum multibyte receipt text fits the canonical assistant response contract', function (): void {
    $canonical=receipt(true);
    $message=str_repeat('😀',4096);
    same(4096,Utf8::codePointLength($message));
    same(16384,strlen($message));
    $receipt=new ActionReceipt(
        $canonical->action(),
        $canonical->changed(),
        $canonical->proof(),
        $message
    );
    $response=AssistantResponse::verifiedAction($message,array($receipt));
    same($message,$response->text());
    same($message,$response->forClient()['receipts'][0]['message']??'');
    throws(InvalidArgumentException::class,static function():void{
        AssistantResponse::answer(str_repeat('😀',4097));
    },'too large');
});
test('Durable receipts survive a backward wall-clock adjustment', function (): void {
    $canonical=receipt(true);
    $future=new ActionReceipt(
        $canonical->action(),$canonical->changed(),$canonical->proof(),$canonical->safeMessage(),
        $canonical->publicId(),time()+3600
    );
    $stored=ActionReceipt::fromArray(Json::decodeRequiredObject(
        Json::encodeObject($future->toArray()),'Clock-adjusted receipt'
    ));
    same($future->toArray()['created_at'],$stored->toArray()['created_at']);
    same($future->safeMessage(),$stored->safeMessage());
});
test('Receipt display entities canonicalize once and remain exact after durable hydration', function (): void {
    $before=line('line-entity',1);
    $after=line('line-entity',2);
    $pre=snapshot(array($before),array(),1,10,'entity-receipt-pre');
    $postFacts=facts(2,20,'entity-receipt-post');
    $canonicalTotal="\u{2068}Total &amp; Tax\u{2069}";
    $postFacts['formatted_total']=$canonicalTotal;
    $post=new CartSnapshot(array('line-entity'=>$after),array(),$postFacts);
    $plan=new CartPlan(array(CartCommand::update(
        'line-entity',$before->fingerprint(),2,'Tea &amp;amp; Coffee'
    )));
    $receipt=(new ReceiptPresenter(new CartDeltaVerifier()))->create($plan,$pre,$post,true);
    same('Tea &amp; Coffee',$receipt->proof()['commands'][0]['item']??'');
    same($canonicalTotal,$receipt->proof()['cart_total']??'');
    contains('Tea &amp; Coffee',$receipt->safeMessage());
    notContains('Tea & Coffee',$receipt->safeMessage());

    $stored=ActionReceipt::fromArray(Json::decodeRequiredObject(
        Json::encodeObject($receipt->toArray()),
        'Nested-entity receipt'
    ));
    same(Json::encodeObject($receipt->toArray()),Json::encodeObject($stored->toArray()));
    $response=AssistantResponse::verifiedAction($stored->safeMessage(),array($stored));
    same($receipt->safeMessage(),$response->text());
    same('Tea &amp; Coffee',$response->forClient()['receipts'][0]['proof']['commands'][0]['item']??'');
});
test('Verified multibyte receipt survives the complete durable operation contract', function (): void {
    $before=line('line-a',1);
    $after=line('line-a',2);
    $pre=new CartSnapshot(array('line-a'=>$before),array(),facts(1,10,'durable-multibyte-pre'));
    $postFacts=facts(2,20,'durable-multibyte-post');
    $postFacts['formatted_subtotal']=str_repeat('😀',512);
    $postFacts['formatted_total']=str_repeat('😀',512);
    $post=new CartSnapshot(array('line-a'=>$after),array(),$postFacts);
    $name=str_repeat('😀',500);
    $plan=new CartPlan(array(CartCommand::update('line-a',$before->fingerprint(),2,$name)));
    $applied=new AppliedCartPlan(array(array(
        'type'=>'update','cart_item_key'=>'line-a','previous_quantity'=>1.0,
        'display_name'=>$name,'quantity'=>2.0,
    )));
    $receipt=(new ReceiptPresenter(new CartDeltaVerifier()))->create($plan,$pre,$post,true);
    same($name,$receipt->proof()['commands'][0]['item']??'');
    $visibleLabel=Utf8::truncate($name,47).'…';
    contains($visibleLabel,$receipt->safeMessage());
    notContains($name,$receipt->safeMessage());
    ok((new ArabicCustomerText())->accepts($receipt->safeMessage()));
    ok(Utf8::codePointLength($receipt->safeMessage())<200);

    $storedReceipt=ActionReceipt::fromArray(Json::decodeRequiredObject(
        Json::encodeObject($receipt->toArray()),
        'Durable multibyte receipt'
    ));
    $operation=new OperationRecord(
        1,Uuid::v4(),7,Uuid::v4(),str_repeat('a',64),1,OperationStatus::VERIFIED,
        $plan,$pre,$applied,$post,$storedReceipt,'',$storedReceipt->safeMessage(),str_repeat('b',64),1
    );
    same($receipt->safeMessage(),$storedReceipt->safeMessage());
    same(OperationStatus::VERIFIED,$operation->status());
    same($storedReceipt->safeMessage(),$operation->safeMessage());
});
test('Verified receipt recovery preserves exact authority with a maximum Latin product label', function (): void {
    $before=line('line-a',1);
    $after=line('line-a',2);
    $pre=snapshot(array($before),array(),1,10,'latin-receipt-pre');
    $post=snapshot(array($after),array(),2,20,'latin-receipt-post');
    $name=str_repeat('A',500);
    $plan=new CartPlan(array(CartCommand::update('line-a',$before->fingerprint(),2,$name)));
    $receipt=(new ReceiptPresenter(new CartDeltaVerifier()))->create($plan,$pre,$post,true);
    same($name,$receipt->proof()['commands'][0]['item']??'');
    ok((new ArabicCustomerText())->accepts($receipt->safeMessage()));
    ok(Utf8::codePointLength($receipt->safeMessage())<200);

    $context=agentContextForTest();
    $context->effects()->recordReceipt($receipt);
    $response=terminalOutcomesForTest()->verifiedAction($context);
    same($receipt->safeMessage(),$response->text());
    same($receipt->safeMessage(),$response->forClient()['receipts'][0]['message']??'');
});
test('Durable cart step and attempt messages share the Unicode safe-message boundary', function (): void {
    $message=str_repeat('😀',4096);
    $pre=snapshot(array(line('line-a',1)),array(),1,10,'durable-message-pre');
    $primitive=CartPrimitive::setQuantity(
        CartCommand::UPDATE,0,'single','line-a',str_repeat('c',64),2,'Product 10'
    );
    $step=new CartOperationStep(
        1,Uuid::v4(),1,0,0,str_repeat('d',64),1,str_repeat('e',64),1,
        CartStepStatus::REJECTED,$primitive,$pre,null,null,'','cart_rejected',$message
    );
    $attempt=new CartStepAttempt(
        1,Uuid::v4(),1,1,1,str_repeat('e',64),1,CartStepAttemptStatus::ABANDONED,
        '',null,null,null,'cart_rejected',$message
    );
    same($message,$step->safeMessage());
    same($message,$attempt->safeMessage());
    throws(InvalidArgumentException::class,static function()use($primitive,$pre):void{
        new CartOperationStep(
            1,Uuid::v4(),1,0,0,str_repeat('d',64),1,str_repeat('e',64),1,
            CartStepStatus::REJECTED,$primitive,$pre,null,null,'','cart_rejected',str_repeat('😀',4097)
        );
    },'invalid');
});
test('Action receipts prove exactly one integer-quantity command', function (): void {
    $canonical=receipt(true);
    $proof=$canonical->proof();
    $two=$proof;
    $two['commands'][]=$two['commands'][0];
    throws(InvalidArgumentException::class,static function()use($canonical,$two):void{
        new ActionReceipt('cart_apply',true,$two,$canonical->safeMessage());
    },'proof');
    $fractional=$proof;
    $fractional['commands'][0]['quantity']=1.5;
    throws(InvalidArgumentException::class,static function()use($canonical,$fractional):void{
        new ActionReceipt('cart_apply',true,$fractional,$canonical->safeMessage());
    },'quantity');
});
test('Message canonicalization keeps verified action text and receipt message byte-identical', function (): void {
    $canonical='تم التنفيذ للحساب customer@example.com.';
    $repository=new MessageRepository();
    $method=new ReflectionMethod($repository,'canonicalPayload');
    if (PHP_VERSION_ID < 80100) { $method->setAccessible(true); }
    $payload=$method->invoke($repository,array('message'=>array(
        'outcome'=>Outcome::ACTION_VERIFIED,
        'text'=>'stale assistant text',
        'receipts'=>array(array('action'=>'cart_apply','message'=>'stale receipt text')),
    )),$canonical);
    same($canonical,$payload['message']['text']??'');
    same($canonical,$payload['message']['receipts'][0]['message']??'');
});
test('A receipt and mutation failure cannot coexist', function (): void {
    $effects=new TurnEffects(); $effects->recordReceipt(receipt(true));
    throws(ContractViolation::class,function()use($effects):void{$effects->recordMutationFailure('cart_failed','Failed');},'mutation_failure_after_receipt');
});
test('An uncertain mutation failure cannot be overwritten by a notice', function (): void {
    $effects=new TurnEffects(); $effects->recordMutationFailure('cart_uncertain','Review cart',true); $effects->recordNotice('Read failed');
    same('Review cart',$effects->failureMessage('Fallback')); ok($effects->stateMayBeUncertain());
});
test('Provider failure messages are cause-specific and not the old fixed reply', function (): void {
    $messages=new AgentFailureMessages(new FixedTextLocalizer());
    $protocol=$messages->forCode('model_candidates_ambiguous'); $limit=$messages->forCode('tool_round_limit');
    ok($protocol!==$limit); notContains('تعذر اكمال طلبك بامان',$protocol); notContains('تعذر اكمال طلبك بامان',$limit);
});
