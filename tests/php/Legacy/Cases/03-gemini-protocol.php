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

// Gemini response protocol.
test('Decoder preserves empty function args as an object for echo', function (): void {
    $decoded = (new GeminiResponseDecoder())->decode(modelResponse(array(array('functionCall'=>array('id'=>'x1','name'=>'cart_view','args'=>array())))));
    same(array(), $decoded->step()->calls()[0]->arguments());
    contains('"args":{}', Json::encodeObject($decoded->requestContent()));
});
test('Decoder requires the exact provider call id and first-call thought signature', function (): void {
    throws(ModelProtocolException::class, function (): void {
        (new GeminiResponseDecoder())->decode(rawModelResponse(array(array(
            'functionCall'=>array('name'=>'cart_view','args'=>array()),
            'thoughtSignature'=>'signature-without-id',
        ))));
    }, 'function_call_provider_id_invalid');
    throws(ModelProtocolException::class, function (): void {
        (new GeminiResponseDecoder())->decode(rawModelResponse(array(
            array('text'=>'reasoning','thought'=>true,'thoughtSignature'=>'thought-part-signature'),
            array('functionCall'=>array('id'=>'provider-call','name'=>'cart_view','args'=>array())),
        )));
    }, 'model_signature_missing');
    throws(ModelProtocolException::class, function (): void {
        (new GeminiResponseDecoder())->decode(rawModelResponse(array(array(
            'functionCall'=>array('id'=>'provider-call','name'=>'cart_view','args'=>array()),
            'thoughtSignature'=>'',
        ))));
    }, 'model_signature_missing');
});
test('Decoder rejects multiple executable candidates', function (): void {
    $one = array('finishReason'=>'STOP','content'=>array('role'=>'model','parts'=>array(array('text'=>'x'))));
    throws(ModelProtocolException::class, function () use ($one): void { (new GeminiResponseDecoder())->decode(array('candidates'=>array($one,$one))); }, 'model_candidates_ambiguous');
});
test('Decoder maps provider finish reasons to fixed bounded failure codes', function (): void {
    throws(ModelProtocolException::class,function():void{
        (new GeminiResponseDecoder())->decode(array('candidates'=>array(array(
            'content'=>array('role'=>'model','parts'=>array(array('text'=>'unfinished'))),
        ))));
    },'model_finish_missing');
    throws(ModelProtocolException::class,function():void{
        (new GeminiResponseDecoder())->decode(rawModelResponse(
            array(array('text'=>'unfinished')),
            'FINISH_REASON_UNSPECIFIED'
        ));
    },'model_finish_unspecified');
    throws(ModelProtocolException::class,function():void{
        (new GeminiResponseDecoder())->decode(modelResponse(array(array('text'=>'blocked')),'SAFETY'));
    },'model_finish_safety');
    throws(ModelProtocolException::class,function():void{
        (new GeminiResponseDecoder())->decode(modelResponse(array(array('text'=>'blocked')),'FUTURE_REASON'));
    },'model_finish_rejected');
    throws(ModelProtocolException::class,function():void{
        (new GeminiResponseDecoder())->decode(modelResponse(array(array('text'=>'blocked')),str_repeat('X',129)));
    },'model_finish_reason_invalid');
});
test('Decoder rejects visible text mixed with calls', function (): void {
    throws(ModelProtocolException::class, function (): void {
        (new GeminiResponseDecoder())->decode(modelResponse(array(array('text'=>'done'),array('functionCall'=>array('name'=>'cart_view','args'=>array())))));
    }, 'model_output_mixed');
});
test('Decoder permits thought text alongside a call', function (): void {
    $decoded=(new GeminiResponseDecoder())->decode(modelResponse(array(array('text'=>'reasoning','thought'=>true),array('functionCall'=>array('name'=>'cart_view','args'=>array())))));
    same('', $decoded->step()->plainText()); same(1, count($decoded->step()->calls()));
});
test('Decoder rebuilds closed request-safe content and preserves only required thought and call fields', function (): void {
    $response=modelResponse(array(array(
        'functionCall'=>array(
            'id'=>'provider-1',
            'name'=>'cart_view',
            'args'=>array(),
            'providerOnly'=>'drop-call',
        ),
        'thought'=>true,
        'thoughtSignature'=>'signature-1',
        'partMetadata'=>array('source'=>'drop-documented-but-unused-metadata'),
        'providerOnly'=>'drop-part',
    )));
    $response['candidates'][0]['content']['providerMetadata']=array('outputOnly'=>'drop-content');

    $decoded=(new GeminiResponseDecoder())->decode($response);
    same(
        '{"role":"model","parts":[{"functionCall":{"id":"provider-1","name":"cart_view","args":{}},"thought":true,"thoughtSignature":"signature-1"}]}',
        Json::encodeObject($decoded->requestContent())
    );
});
test('Decoder rejects conflicting Gemini Part data union members', function (): void {
    throws(ModelProtocolException::class, function (): void {
        (new GeminiResponseDecoder())->decode(modelResponse(array(array(
            'text'=>'visible',
            'inlineData'=>array('mimeType'=>'image/png','data'=>'AA=='),
        ))));
    }, 'model_part_ambiguous');
    throws(ModelProtocolException::class, function (): void {
        (new GeminiResponseDecoder())->decode(modelResponse(array(array(
            'functionCall'=>array('name'=>'cart_view','args'=>array()),
            'functionResponse'=>array('name'=>'cart_view','response'=>array()),
        ))));
    }, 'model_part_ambiguous');
});
test('Decoder rejects unsupported Gemini model-output part types', function (): void {
    throws(ModelProtocolException::class, function (): void {
        (new GeminiResponseDecoder())->decode(modelResponse(array(array(
            'inlineData'=>array('mimeType'=>'image/png','data'=>'AA=='),
        ))));
    }, 'model_part_unsupported');
});
test('Decoder rejects list-shaped function args', function (): void {
    throws(ModelProtocolException::class, function (): void {
        (new GeminiResponseDecoder())->decode(modelResponse(array(array('functionCall'=>array('name'=>'cart_view','args'=>array('x'))))));
    }, 'function_call_args_invalid');
});
test('Decoder exposes MAX_TOKENS only as a non-executable recovery sentinel', function (): void {
    $decoded=(new GeminiResponseDecoder())->decode(array('candidates'=>array(array('finishReason'=>'MAX_TOKENS'))));
    same('MAX_TOKENS',$decoded->step()->finishReason());
    same(array(),$decoded->step()->calls());
    same('',$decoded->step()->plainText());
    same(array(),$decoded->requestContent());
});

test('Gemini session performs one bounded MAX_TOKENS recovery without appending truncated history', function (): void {
    $max=array('candidates'=>array(array('finishReason'=>'MAX_TOKENS')));
    $transport=new QueueTransport(array(
        $max,
        modelResponse(array(array('functionCall'=>array('id'=>'recovered','name'=>'cart_view','args'=>array())))),
        $max,
    ));
    $request=new ModelRequest(
        'System',array(),'Show cart',array(),
        array(array('name'=>'cart_view','description'=>'View cart','parameters'=>ToolSchemas::emptyObject())),
        2048
    );
    $session=geminiGatewayForTest($transport)->start($request);
    $first=$session->next();
    same('MAX_TOKENS',$first->finishReason());
    same(1,count(ysai_test_private_property($session,'contents')));
    ok($session->recoverOutputLimit($first));

    $second=$session->next();
    same('cart_view',$second->calls()[0]->name());
    same(4096,$transport->payloads[1]['generationConfig']['maxOutputTokens']??0);
    same('minimal',$transport->payloads[1]['generationConfig']['thinkingConfig']['thinkingLevel']??'');
    same($transport->payloads[0]['contents'],$transport->payloads[1]['contents'],'Recovery must reuse only the last valid history.');
    $session->submit($second,array(FunctionFeedback::forCall($second->calls()[0],array('ok'=>true,'data'=>array()))));

    $third=$session->next();
    same('MAX_TOKENS',$third->finishReason());
    ok(!$session->recoverOutputLimit($third),'Only one output-limit recovery is permitted per provider session.');
    same(3,count($transport->payloads));
});
test('Session echoes exact call identity and object feedback', function (): void {
    $transport = new QueueTransport(array(
        modelResponse(array(array('functionCall'=>array('id'=>'provider/Call:7_Exact','name'=>'cart_view','args'=>array())))),
        modelResponse(array(array('functionCall'=>array('id'=>'provider-8','name'=>'respond_answer','args'=>array('text'=>'Done'))))),
    ));
    $request = new ModelRequest('System', array(), 'Show cart', array(), array(
        array('name'=>'cart_view','description'=>'View cart','parameters'=>ToolSchemas::emptyObject()),
    ), 1024);
    $session=geminiGatewayForTest($transport)->start($request);
    $step=$session->next();
    $session->submit($step,array(FunctionFeedback::forCall($step->calls()[0],array('ok'=>true,'data'=>array()))));
    $session->next();
    $echo=$transport->payloads[1]['contents'][2]['parts'][0]['functionResponse']??array();
    same('provider/Call:7_Exact',$step->calls()[0]->providerId());
    same('provider/Call:7_Exact',$echo['id']??'');
    same('cart_view',$echo['name']??'');
    ok(($echo['response']??null) instanceof stdClass);
    $result=(string)($echo['response']->result??'');
    same(array('ok'=>true,'data'=>array()),Json::decodeRequiredObject($result,'Function result'));
    same(array('result'),array_keys(get_object_vars($echo['response'])));
});
test('Session rejects feedback for a different call', function (): void {
    $transport = new QueueTransport(array(modelResponse(array(array('functionCall'=>array('id'=>'p','name'=>'cart_view','args'=>array()))))));
    $request = new ModelRequest('System',array(),'Show cart',array(),array(array('name'=>'cart_view','description'=>'View cart','parameters'=>ToolSchemas::emptyObject())),1024);
    $session=geminiGatewayForTest($transport)->start($request);
    $step=$session->next();
    throws(ModelProtocolException::class,function() use($session,$step):void{
        $session->submit($step,array(new FunctionFeedback(Uuid::v4(),'cart_view',array('ok'=>true))));
    },'function_feedback_identity_mismatch');
});
test('Session rejects cumulative thought history before another provider request can be built', function (): void {
    $thought=str_repeat('t',2300*1024);
    $transport=new QueueTransport(array(
        modelResponse(array(
            array('text'=>$thought,'thought'=>true,'thoughtSignature'=>'sig-1'),
            array('functionCall'=>array('id'=>'call-1','name'=>'cart_view','args'=>array())),
        )),
        modelResponse(array(
            array('text'=>$thought,'thought'=>true,'thoughtSignature'=>'sig-2'),
            array('functionCall'=>array('id'=>'call-2','name'=>'cart_view','args'=>array())),
        )),
    ));
    $request=new ModelRequest(
        'System',array(),'Show cart',array(),
        array(array('name'=>'cart_view','description'=>'View cart','parameters'=>ToolSchemas::emptyObject())),
        1024
    );
    $session=geminiGatewayForTest($transport)->start($request);

    $first=$session->next();
    $session->submit($first,array(FunctionFeedback::forCall($first->calls()[0],array('ok'=>true,'data'=>array()))));
    same(3,count(ysai_test_private_property($session,'contents')));

    $second=$session->next();
    same('sig-1',$transport->payloads[1]['contents'][1]['parts'][0]['thoughtSignature']);
    same($thought,$transport->payloads[1]['contents'][1]['parts'][0]['text']);
    throws(ModelProtocolException::class,function() use($session,$second):void{
        $session->submit($second,array(FunctionFeedback::forCall($second->calls()[0],array('ok'=>true,'data'=>array()))));
    },'model_history_budget_exceeded');
    same(3,count(ysai_test_private_property($session,'contents')),'A rejected history append must be atomic.');
    same(2,count($transport->payloads));
});
test('Model loop fails closed after an unrecoverable output limit', function (): void {
    $session=new QueuedModelSession(array(new ModelStep(Uuid::v4(),array(),'','MAX_TOKENS')));
    $response=modelLoopForTest(array(new RespondAnswerHandler()))->run($session,agentContextForTest());
    same('model_output_limit',$response->failureCode());
    contains('استنفد',$response->text());
});

test('Session drops arbitrary provider metadata before cumulative history accounting', function (): void {
    $response=modelResponse(array(array(
        'functionCall'=>array(
            'id'=>'call-1',
            'name'=>'cart_view',
            'args'=>array(),
            'providerOnly'=>str_repeat('c',2048),
        ),
        'providerOnly'=>str_repeat('p',2048),
    )));
    $response['candidates'][0]['content']['providerMetadata']=str_repeat('m',4080000);
    $transport=new QueueTransport(array($response));
    $request=new ModelRequest(
        'System',array(),str_repeat('u',130000),array(),
        array(array('name'=>'cart_view','description'=>'View cart','parameters'=>ToolSchemas::emptyObject())),
        1024
    );
    $session=geminiGatewayForTest($transport)->start($request);
    $step=$session->next();
    $session->submit($step,array(FunctionFeedback::forCall($step->calls()[0],array('ok'=>true,'data'=>array()))));
    $contents=ysai_test_private_property($session,'contents');
    same(3,count($contents));
    $history=Json::encodeObject(array('contents'=>$contents));
    notContains('providerMetadata',$history);
    notContains('providerOnly',$history);
});
