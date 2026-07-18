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

// Strict JSON boundaries.
test('JSON encodes empty authority as an object', function (): void { same('{}', Json::encodeObject(array())); });
test('JSON accepts a required empty object', function (): void { same(array(), Json::decodeRequiredObject('{}')); });
test('JSON rejects a list at an object boundary', function (): void { throws(RuntimeException::class, function (): void { Json::decodeRequiredObject('[]'); }); });
test('JSON rejects malformed durable authority', function (): void { throws(RuntimeException::class, function (): void { Json::decodeRequiredObject('{'); }); });
test('Canonical object fingerprints ignore key insertion order', function (): void { same(Json::canonicalObject(array('b'=>2,'a'=>1)), Json::canonicalObject(array('a'=>1,'b'=>2))); });
test('Durable turn fingerprints use one explicit install-scoped key independent of WordPress salts', function (): void {
    $installKey=str_repeat("\x5a",32);
    $derived=hash_hmac('sha256','ysai-durable-fingerprint-v1',$installKey,true);
    $fingerprints=new SecretFingerprint($installKey);
    same(
        hash_hmac('sha256',"turn-request-v1\0canonical request",$derived),
        $fingerprints->digest('turn-request-v1','canonical request')
    );
    throws(RuntimeException::class,static function (): void { new SecretFingerprint(''); },'install-scoped');
    throws(RuntimeException::class,static function (): void { new SecretFingerprint(str_repeat('x',31)); },'install-scoped');
    throws(RuntimeException::class,static function (): void { new SecretFingerprint(str_repeat('x',33)); },'install-scoped');

    $root=YSAI_PROJECT_ROOT;
    $implementation=(string)file_get_contents($root.'/src/Infrastructure/Security/SecretFingerprint.php');
    $kernel=(string)file_get_contents($root.'/src/Infrastructure/Composition/PluginKernel.php');
    notContains('wp_salt',$implementation);
    contains('new SecretFingerprint($recoveryKey->key())',$kernel);
    $constructions=0;
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src',FilesystemIterator::SKIP_DOTS));
    foreach($iterator as $file){
        if($file->getExtension()==='php'){
            $constructions+=substr_count((string)file_get_contents($file->getPathname()),'new SecretFingerprint(');
        }
    }
    same(1,$constructions,'Every runtime SecretFingerprint construction must use the one explicit install key.');
});
test('AI-led cart evidence accepts natural language without a PHP sentence grammar', function (): void {
    $text=new CatalogTextNormalizer();
    $evidence=new CurrentTurnCartIntentEvidence($text,new VariableProductAuthority($text));
    $authority=new AuthorityRegistry();
    $productRef=$authority->recordProduct(array(
        'id'=>501,'name'=>'قهوة عربية','sku'=>'COF-501',
        'type'=>'simple','requires_variation'=>false,
    ));
    $arguments=array(
        'intent_text'=>'أضفها لو سمحت',
        'commands'=>array(array(
            'type'=>'add','product_ref'=>$productRef,'quantity_mode'=>'default',
        )),
    );
    $plan=(new CartPlanFactory())->fromToolArguments($arguments,$authority);
    foreach(array(
        'أضفها لو سمحت',
        'حطها لي في السلة',
        'دخّلها السلة يا غالي',
        'أبغى ذي بالسلة',
        'ممكن تطرحها داخل السلة؟',
        'لو تكرمت ضيف هذا المنتج',
        'خلّها مع طلباتي',
        'اشتيها في السلة',
        'هات لي منها واحدة',
        'add this one to my cart please',
    ) as $message){
        $call=$arguments;
        $call['intent_text']=$message;
        $evidence->assertForPlan(
            $message,$message,$plan,$authority,$call,null
        );
        ok(true,'Natural customer wording was rejected: '.$message);
    }

    $quoted='أضفها لو سمحت';
    $evidence->assertForPlan(
        $quoted,'أضفها لو سمحت',$plan,$authority,$arguments,null,'قهوة عربية'
    );
    throws(ContractViolation::class,static function()use(
        $evidence,$quoted,$plan,$authority,$arguments
    ):void{
        $bad=$arguments;
        $bad['intent_text']='قهوة عربية';
        $evidence->assertForPlan(
            $quoted,'قهوة عربية',$plan,$authority,$bad,null,'قهوة عربية'
        );
    },'cart_intent_evidence_not_current');
});
test('Structured reply context stays separate from exact customer-authored text', function (): void {
    $framed=new CurrentCustomerMessage('أضفها لو سمحت','قهوة عربية');
    same('قهوة عربية',$framed->quotedContext());
    same('أضفها لو سمحت',$framed->text());
    $manual=new CurrentCustomerMessage("> قهوة عربية\n\nأضفها لو سمحت");
    same('',$manual->quotedContext());
    same("> قهوة عربية\n\nأضفها لو سمحت",$manual->text());
});

test('Semantic cart evidence is exact bounded and fingerprinted across message history and proposal', function (): void {
    $history=array(
        array('role'=>'user','text'=>'أريد قهوة عربية'),
        array('role'=>'assistant','text'=>'هذه القهوة العربية المناسبة لك.'),
    );
    $proposal=array(
        'kind'=>'execute_now','requested_action'=>'add','effective_action'=>'add',
        'target'=>array('name'=>'قهوة عربية'),
        'quantity'=>array('mode'=>'default','stated_value'=>null,'resulting_value'=>1),
    );
    $request=new CartIntentVerificationRequest(
        'ممكن تضيفها للسلة؟','','ممكن تضيفها للسلة؟',$history,$proposal
    );
    same('ممكن تضيفها للسلة؟',$request->forModel()['exact_current_customer_text']);
    same('',$request->forModel()['quoted_context']);
    same($history,$request->forModel()['recent_conversation']);
    same($proposal,$request->forModel()['server_resolved_cart_proposal']);
    same(64,strlen($request->fingerprint()));
    same($request->fingerprint(),$request->forModel()['evidence_fingerprint']);

    $different=new CartIntentVerificationRequest(
        'ممكن تضيف اثنتين للسلة؟','','ممكن تضيف اثنتين للسلة؟',$history,$proposal
    );
    ok(!hash_equals($request->fingerprint(),$different->fingerprint()));
    throws(InvalidArgumentException::class,static function()use($proposal):void{
        new CartIntentVerificationRequest(
            'أضفها','','أضفها',array(array('role'=>'user','text'=>'سياق يتيم')),$proposal
        );
    },'complete turns');
});

test('Semantic cart proposal binds the exact live target action and quantity meaning', function (): void {
    $authority=new AuthorityRegistry();
    $productRef=$authority->recordProduct(array(
        'id'=>503,'name'=>'قهوة هرري','sku'=>'COF-503',
        'type'=>'simple','requires_variation'=>false,'attributes'=>array(),
    ));
    $arguments=array(
        'intent_text'=>'هات لي منها واحدة',
        'commands'=>array(array(
            'type'=>'add','product_ref'=>$productRef,'quantity_mode'=>'default',
        )),
    );
    $plan=(new CartPlanFactory())->fromToolArguments($arguments,$authority);
    $context=cartAgentContextForTest(
        $authority,
        'هات لي منها واحدة',
        null,
        array(
            array('role'=>'user','text'=>'رشح لي قهوة هرري'),
            array('role'=>'assistant','text'=>'هذه قهوة هرري المتاحة.'),
        )
    );
    $request=(new CartIntentVerificationFactory(new CatalogTextNormalizer()))->forPlan(
        $context,$arguments['intent_text'],$plan,$arguments,null
    );
    $proposal=$request->forModel()['server_resolved_cart_proposal'];
    same('execute_now',$proposal['kind']);
    same('add',$proposal['requested_action']);
    same('add',$proposal['effective_action']);
    same('قهوة هرري',$proposal['target']['name']);
    same($productRef,$proposal['target']['product_authority_ref']);
    same('default',$proposal['quantity']['mode']);
    same(null,$proposal['quantity']['stated_value']);
    same(1,$proposal['quantity']['resulting_value']);
});

test('Current cart images are fingerprint-bound and sent to the isolated semantic verifier', function (): void {
    $data=tinyPngBase64();
    $binary=base64_decode($data,true);
    if(!is_string($binary)){throw new RuntimeException('Unable to decode verifier image fixture.');}
    $image=new ImageAttachment('image/png',$data,strlen($binary),hash('sha256',$binary));
    $proposal=array(
        'kind'=>'execute_now','requested_action'=>'add','effective_action'=>'add',
        'target'=>array('name'=>'قهوة مصورة'),
        'quantity'=>array('mode'=>'default','stated_value'=>null,'resulting_value'=>1),
    );
    $withImage=new CartIntentVerificationRequest(
        'أضف هذا إلى السلة','','أضف هذا إلى السلة',array(),$proposal,array($image)
    );
    $withoutImage=new CartIntentVerificationRequest(
        'أضف هذا إلى السلة','','أضف هذا إلى السلة',array(),$proposal
    );
    ok(!hash_equals($withImage->fingerprint(),$withoutImage->fingerprint()));
    same(1,$withImage->forModel()['current_image_count']);
    same(array($image),$withImage->attachments());

    $transport=new QueueTransport(array(modelResponse(array(array(
        'functionCall'=>array(
            'name'=>'verify_current_cart_intent',
            'args'=>array(
                'authorized'=>true,
                'reason'=>CartIntentVerdict::AUTHORIZED,
                'evidence_fingerprint'=>$withImage->fingerprint(),
            ),
        ),
    )))));
    (new GeminiCartIntentVerifier(geminiGatewayForTest($transport),30))->verify($withImage);
    $inline=$transport->payloads[0]['contents'][0]['parts'][1]['inlineData']??array();
    same('image/png',$inline['mimeType']??'');
    same($data,$inline['data']??'');
});

test('Natural cart language reaches every resolved command without a PHP phrase grammar', function (): void {
    $authority=new AuthorityRegistry();
    $coffeeRef=$authority->recordProduct(array(
        'id'=>701,'name'=>'قهوة عربية','sku'=>'COF-701',
        'type'=>'simple','requires_variation'=>false,'attributes'=>array(),
    ));
    $teaRef=$authority->recordProduct(array(
        'id'=>702,'name'=>'شاي عدني','sku'=>'TEA-702',
        'type'=>'simple','requires_variation'=>false,'attributes'=>array(),
    ));
    $lineRef=$authority->recordCartSnapshot(array(array(
        'cart_item_key'=>'line-language','line_fingerprint'=>str_repeat('7',64),
        'name'=>'قهوة عربية','quantity'=>4,'attributes'=>array(),
    )))[0];
    $cases=array(
        array('ممكن تضيف لي هذه؟',array(
            'type'=>'add','product_ref'=>$teaRef,'quantity_mode'=>'default',
        ),'add','add','default',1),
        array('خلي كمية القهوة ثلاث حبات',array(
            'type'=>'update','cart_item_ref'=>$lineRef,'quantity_mode'=>'set','quantity'=>3,
        ),'update','update','set',3),
        array('زيد عليها حبتين لو سمحت',array(
            'type'=>'update','cart_item_ref'=>$lineRef,'quantity_mode'=>'increment','quantity'=>2,
        ),'update','update','increment',6),
        array('نقص منها واحدة',array(
            'type'=>'update','cart_item_ref'=>$lineRef,'quantity_mode'=>'decrement','quantity'=>1,
        ),'update','update','decrement',3),
        array('شيل القهوة من السلة لو سمحت',array(
            'type'=>'remove','cart_item_ref'=>$lineRef,
        ),'remove','remove','none',null),
        array('بدل القهوة بهذا الشاي وخلي نفس العدد',array(
            'type'=>'replace','cart_item_ref'=>$lineRef,'product_ref'=>$teaRef,
            'quantity_mode'=>'preserve',
        ),'replace','replace','preserve',4),
        array('فضي السلة كلها',array(
            'type'=>'clear',
        ),'clear','clear','none',null),
    );
    $plans=new CartPlanFactory();
    $normalizer=new CatalogTextNormalizer();
    $evidence=new CurrentTurnCartIntentEvidence($normalizer,new VariableProductAuthority($normalizer));
    foreach($cases as $case){
        $message=$case[0];
        $arguments=array('intent_text'=>$message,'commands'=>array($case[1]));
        $context=cartAgentContextForTest($authority,$message);
        $revision='';
        if($case[2]===CartCommand::CLEAR){
            $revision=str_repeat('8',64);
            $context->effects()->recordViewedCartRevision($revision);
        }
        $plan=$plans->fromToolArguments($arguments,$authority,$revision);
        $evidence->assertForPlan($message,$message,$plan,$authority,$arguments,null);
        $proposal=(new CartIntentVerificationFactory($normalizer))->forPlan(
            $context,$message,$plan,$arguments,null
        )->forModel()['server_resolved_cart_proposal'];
        same($case[2],$proposal['requested_action']);
        same($case[3],$proposal['effective_action']);
        same($case[4],$proposal['quantity']['mode']);
        same($case[5],$proposal['quantity']['resulting_value']);
        same('',$proposal['declared_continuation_id']);
        same(array(),$proposal['server_owned_continuation']);
    }
    ok($coffeeRef!==$teaRef);
});

test('Isolated Gemini cart verifier accepts denies and rejects unbound semantic verdicts', function (): void {
    $request=new CartIntentVerificationRequest(
        'لو سمحت حطها في السلة','','لو سمحت حطها في السلة',array(),array(
            'kind'=>'execute_now','requested_action'=>'add','effective_action'=>'add',
            'target'=>array('name'=>'قهوة عربية'),
            'quantity'=>array('mode'=>'default','stated_value'=>null,'resulting_value'=>1),
        )
    );
    $allowTransport=new QueueTransport(array(modelResponse(array(array(
        'functionCall'=>array(
            'name'=>'verify_current_cart_intent',
            'args'=>array(
                'authorized'=>true,
                'reason'=>CartIntentVerdict::AUTHORIZED,
                'evidence_fingerprint'=>$request->fingerprint(),
            ),
        ),
    )))));
    $allowVerifier=new GeminiCartIntentVerifier(geminiGatewayForTest($allowTransport),30);
    $allowed=$allowVerifier->verify($request);
    ok($allowed->authorized());
    same(CartIntentVerdict::AUTHORIZED,$allowed->reason());
    same('ANY',$allowTransport->payloads[0]['toolConfig']['functionCallingConfig']['mode']??'');
    same(
        array('verify_current_cart_intent'),
        $allowTransport->payloads[0]['toolConfig']['functionCallingConfig']['allowedFunctionNames']??array()
    );
    same(
        'verify_current_cart_intent',
        $allowTransport->payloads[0]['tools'][0]['functionDeclarations'][0]['name']??''
    );
    $instruction=(string)(
        $allowTransport->payloads[0]['systemInstruction']['parts'][0]['text']??''
    );
    contains('# Evidence contract',$instruction);
    contains('Understand Arabic dialects, English, politeness, pronouns, and ellipsis by meaning',$instruction);
    contains('server_bound_continuation=true means the server',$instruction);
    contains('server_bound_continuation=false and declared_continuation_id empty means a new request',$instruction);
    contains('A generic acknowledgement such as',$instruction);
    contains('quoted_context, recent_conversation, and current image attachments may identify one unique target',$instruction);
    contains('# Denial reason selection',$instruction);
    contains('# Decision examples',$instruction);
    contains('multiple_actions_unsupported',$instruction);

    $denyTransport=new QueueTransport(array(modelResponse(array(array(
        'functionCall'=>array(
            'name'=>'verify_current_cart_intent',
            'args'=>array(
                'authorized'=>false,
                'reason'=>CartIntentVerdict::NOT_A_REQUEST,
                'evidence_fingerprint'=>$request->fingerprint(),
            ),
        ),
    )))));
    $denied=(new GeminiCartIntentVerifier(geminiGatewayForTest($denyTransport),30))->verify($request);
    ok(!$denied->authorized());
    same(CartIntentVerdict::NOT_A_REQUEST,$denied->reason());

    $unboundResponse=modelResponse(array(array(
        'functionCall'=>array(
            'name'=>'verify_current_cart_intent',
            'args'=>array(
                'authorized'=>true,
                'reason'=>CartIntentVerdict::AUTHORIZED,
                'evidence_fingerprint'=>str_repeat('0',64),
            ),
        ),
    )));
    $unboundTransport=new QueueTransport(array($unboundResponse,$unboundResponse));
    throws(ModelProtocolException::class,static function()use($request,$unboundTransport):void{
        (new GeminiCartIntentVerifier(geminiGatewayForTest($unboundTransport),30))->verify($request);
    },'cart_intent_verifier_result_invalid');
    same(2,count($unboundTransport->payloads));

    $retryTransport=new QueueTransport(array(
        modelResponse(array(array('text'=>'سأتحقق من الطلب.'))),
        modelResponse(array(array('functionCall'=>array(
            'name'=>'verify_current_cart_intent',
            'args'=>array(
                'authorized'=>true,
                'reason'=>CartIntentVerdict::AUTHORIZED,
                'evidence_fingerprint'=>$request->fingerprint(),
            ),
        )))),
    ));
    $retried=(new GeminiCartIntentVerifier(geminiGatewayForTest($retryTransport),30))->verify($request);
    ok($retried->authorized());
    same(2,count($retryTransport->payloads));
    same('ANY',$retryTransport->payloads[0]['toolConfig']['functionCallingConfig']['mode']??'');
    same('ANY',$retryTransport->payloads[1]['toolConfig']['functionCallingConfig']['mode']??'');

    $service=(string)file_get_contents(
        YSAI_PROJECT_ROOT.'/src/Application/Tool/Service/CartToolService.php'
    );
    $verifyAt=strpos($service,'$this->intentVerifier->verify(');
    $startAt=strpos($service,'recordMutationExecutionStarted()');
    $executeAt=strpos($service,'$this->mutations->execute(');
    ok($verifyAt!==false&&$startAt!==false&&$executeAt!==false
        &&$verifyAt<$startAt&&$startAt<$executeAt,
        'Semantic authorization must finish before the first WooCommerce execution boundary.'
    );
});

test('Structured quantity modes resolve exact live cart arithmetic', function (): void {
    $authority=new AuthorityRegistry();
    $item=array(
        'cart_item_key'=>'line-q','line_fingerprint'=>str_repeat('a',64),
        'name'=>'قهوة عربية','quantity'=>5,
    );
    $itemRef=$authority->recordCartSnapshot(array($item))[0];
    $product=array(
        'id'=>502,'name'=>'شاي أخضر','sku'=>'TEA-502',
        'type'=>'simple','requires_variation'=>false,
    );
    $productRef=$authority->recordProduct($product);
    $factory=new CartPlanFactory();
    $cases=array(
        array('set',3,CartCommand::UPDATE,3),
        array('increment',2,CartCommand::UPDATE,7),
        array('decrement',2,CartCommand::UPDATE,3),
        array('decrement',5,CartCommand::REMOVE,0),
        array('set',0,CartCommand::REMOVE,0),
    );
    foreach($cases as $case){
        $plan=$factory->fromToolArguments(array('commands'=>array(array(
            'type'=>'update','cart_item_ref'=>$itemRef,
            'quantity_mode'=>$case[0],'quantity'=>$case[1],
        ))),$authority);
        $command=$plan->commands()[0];
        same($case[2],$command->type());
        same((float)$case[3],$command->quantity());
    }

    $default=$factory->fromToolArguments(array('commands'=>array(array(
        'type'=>'add','product_ref'=>$productRef,'quantity_mode'=>'default',
    ))),$authority)->commands()[0];
    same(1.0,$default->quantity());
    $preserved=$factory->fromToolArguments(array('commands'=>array(array(
        'type'=>'replace','cart_item_ref'=>$itemRef,'product_ref'=>$productRef,
        'quantity_mode'=>'preserve',
    ))),$authority)->commands()[0];
    same(CartCommand::REPLACE,$preserved->type());
    same(5.0,$preserved->quantity());
});

test('Structured cart continuations bind the exact missing field and fresh live target', function (): void {
    $now=time();
    $normalizer=new CatalogTextNormalizer();
    $evidence=new CurrentTurnCartIntentEvidence($normalizer,new VariableProductAuthority($normalizer));
    $clarificationVerifier=new FixedCartIntentVerifier();
    $factory=pendingCartIntentFactoryForTest(new FixedClock($now),$clarificationVerifier);
    $product=array(
        'id'=>610,'name'=>'حذاء رياضي','sku'=>'SHOE-610',
        'type'=>'variable','requires_variation'=>true,
        'attributes'=>array(
            array('name'=>'اللون','variation'=>true,'values'=>array('أحمر','أزرق')),
            array('name'=>'المقاس','variation'=>true,'values'=>array('42','43')),
        ),
    );
    $red42=array(
        'id'=>6101,'parent_id'=>610,'name'=>'حذاء رياضي أحمر 42','sku'=>'SHOE-R-42',
        'attributes'=>array(
            array('key'=>'attribute_color','label'=>'اللون','value'=>'red','display'=>'أحمر'),
            array('key'=>'attribute_size','label'=>'المقاس','value'=>'42','display'=>'42'),
        ),
    );
    $red43=$red42;
    $red43['id']=6102;
    $red43['name']='حذاء رياضي أحمر 43';
    $red43['sku']='SHOE-R-43';
    $red43['attributes'][1]['value']='43';
    $red43['attributes'][1]['display']='43';
    $originAuthority=new AuthorityRegistry();
    $productRef=$originAuthority->recordProduct($product);
    $originAuthority->recordVariationCatalog(610,array($red42,$red43),str_repeat('a',64));
    $origin=cartAgentContextForTest(
        $originAuthority,'أضف الحذاء الأحمر لو سمحت'
    );
    $pending=pendingCartIntentForTest($factory,array(
        'action'=>'add','target_ref'=>$productRef,'missing'=>'variation',
        'intent_text'=>'أضف الحذاء الأحمر','quantity'=>2,
        'selected_attributes'=>array(array('label'=>'اللون','value'=>'أحمر')),
    ),'أي مقاس تريده للحذاء الرياضي الأحمر؟',$origin);
    same(array('المقاس'),$pending->target()['missing_attributes']);
    same(2,$pending->quantity());
    same(64,strlen($pending->target()['product_fingerprint']));
    same(64,strlen($pending->target()['variation_axes_fingerprint']));
    $questionProposal=$clarificationVerifier->requests[0]->forModel()['server_resolved_cart_proposal'];
    same('أي مقاس تريده للحذاء الرياضي الأحمر؟',$questionProposal['proposed_customer_question']);
    same(array(
        'label'=>'المقاس','listed_values'=>array('42','43'),
        'value_count'=>2,'values_complete'=>true,
    ),$questionProposal['question_authority']['missing_axes'][0]);

    $fresh=new AuthorityRegistry();
    $freshProduct=$fresh->recordProduct($product);
    $freshVariation=$fresh->recordVariationCatalog(
        610,array($red42),str_repeat('a',64)
    )[0];
    $arguments=array(
        'intent_text'=>'مقاس 42',
        'commands'=>array(array(
            'type'=>'add','product_ref'=>$freshProduct,'variation_ref'=>$freshVariation,
            'quantity_mode'=>'exact','quantity'=>2,
        )),
    );
    $plan=(new CartPlanFactory())->fromToolArguments($arguments,$fresh);
    $boundId=$evidence->assertForPlan(
        'مقاس 42','مقاس 42',$plan,$fresh,$arguments,$pending
    );
    same($pending->id(),$boundId);
    $boundProposal=(new CartIntentVerificationFactory($normalizer))->forPlan(
        cartAgentContextForTest($fresh,'مقاس 42',$pending),
        'مقاس 42',$plan,$arguments,$pending,$boundId
    )->forModel()['server_resolved_cart_proposal'];
    same($pending->id(),$boundProposal['declared_continuation_id']);
    same(true,$boundProposal['server_bound_continuation']);
    same(array(
        'missing'=>'variation',
        'attributes'=>array(array('label'=>'المقاس','value'=>'42')),
    ),$boundProposal['resolved_missing_values']);
    ok(!array_key_exists('continuation_id',$boundProposal['server_owned_continuation']));

    $driftedProduct=$product;
    $driftedProduct['attributes'][1]['values'][]='44';
    $driftedAuthority=new AuthorityRegistry();
    $driftedProductRef=$driftedAuthority->recordProduct($driftedProduct);
    $driftedVariationRef=$driftedAuthority->recordVariation($red42);
    $driftedArguments=$arguments;
    $driftedArguments['commands'][0]['product_ref']=$driftedProductRef;
    $driftedArguments['commands'][0]['variation_ref']=$driftedVariationRef;
    $driftedPlan=(new CartPlanFactory())->fromToolArguments(
        $driftedArguments,$driftedAuthority
    );
    same('',$evidence->assertForPlan(
        'مقاس 42','مقاس 42',$driftedPlan,$driftedAuthority,
        $driftedArguments,$pending
    ));

    $independentProduct=$fresh->recordProduct(array(
        'id'=>611,'name'=>'قهوة عربية','sku'=>'COF-611',
        'type'=>'simple','requires_variation'=>false,'attributes'=>array(),
    ));
    $independent=array(
        'intent_text'=>'أضف القهوة الآن',
        'commands'=>array(array(
            'type'=>'add','product_ref'=>$independentProduct,'quantity_mode'=>'default',
        )),
    );
    $independentPlan=(new CartPlanFactory())->fromToolArguments($independent,$fresh);
    $evidence->assertForPlan(
        'أضف القهوة الآن','أضف القهوة الآن',$independentPlan,$fresh,$independent,$pending
    );
    $independentProposal=(new CartIntentVerificationFactory($normalizer))->forPlan(
        cartAgentContextForTest($fresh,'أضف القهوة الآن',$pending),
        'أضف القهوة الآن',$independentPlan,$independent,$pending
    )->forModel()['server_resolved_cart_proposal'];
    same('',$independentProposal['declared_continuation_id']);
    ok(!array_key_exists('continuation_id',$independentProposal['server_owned_continuation']));

    $lineAuthority=new AuthorityRegistry();
    $lineRef=$lineAuthority->recordCartSnapshot(array(array(
        'cart_item_key'=>'line-cont','line_fingerprint'=>str_repeat('c',64),
        'name'=>'قهوة عربية','quantity'=>5,
    )))[0];
    $quantityPending=pendingCartIntentForTest($factory,array(
        'action'=>'update','target_ref'=>$lineRef,'missing'=>'quantity',
        'intent_text'=>'زود كمية القهوة','quantity_mode'=>'increment',
    ),'كم وحدة تريد إضافتها إلى كمية القهوة العربية؟',cartAgentContextForTest($lineAuthority,'زود كمية القهوة'));
    $quantityArguments=array(
        'intent_text'=>'حبتين',
        'commands'=>array(array(
            'type'=>'update','cart_item_ref'=>$lineRef,
            'quantity_mode'=>'increment','quantity'=>2,
        )),
    );
    $quantityPlan=(new CartPlanFactory())->fromToolArguments(
        $quantityArguments,$lineAuthority
    );
    same(7.0,$quantityPlan->commands()[0]->quantity());
    $quantityBoundId=$evidence->assertForPlan(
        'حبتين','حبتين',$quantityPlan,$lineAuthority,
        $quantityArguments,$quantityPending
    );
    same($quantityPending->id(),$quantityBoundId);
    $quantityProposal=(new CartIntentVerificationFactory($normalizer))->forPlan(
        cartAgentContextForTest($lineAuthority,'حبتين',$quantityPending),
        'حبتين',$quantityPlan,$quantityArguments,$quantityPending,$quantityBoundId
    )->forModel()['server_resolved_cart_proposal'];
    same(array(
        'missing'=>'quantity','quantity_mode'=>'increment',
        'stated_value'=>2,'resulting_value'=>7,
    ),$quantityProposal['resolved_missing_values']);
    $wrongMode=$quantityArguments;
    $wrongMode['commands'][0]['quantity_mode']='set';
    $wrongPlan=(new CartPlanFactory())->fromToolArguments($wrongMode,$lineAuthority);
    same('',$evidence->assertForPlan(
        'حبتين','حبتين',$wrongPlan,$lineAuthority,
        $wrongMode,$quantityPending
    ));

    $replaceOrigin=new AuthorityRegistry();
    $replaceSourceRef=$replaceOrigin->recordCartSnapshot(array(array(
        'cart_item_key'=>'line-replace-cont',
        'line_fingerprint'=>str_repeat('d',64),
        'name'=>'الحذاء القديم','quantity'=>3,
    )))[0];
    $replaceTargetRef=$replaceOrigin->recordProduct($product);
    $replaceOrigin->recordVariationCatalog(610,array($red42,$red43),str_repeat('b',64));
    $replacePending=pendingCartIntentForTest($factory,array(
        'action'=>'replace','target_ref'=>$replaceTargetRef,
        'source_cart_item_ref'=>$replaceSourceRef,
        'missing'=>'variation','intent_text'=>'بدل الحذاء القديم بالأحمر',
        'quantity_mode'=>'preserve',
        'selected_attributes'=>array(array('label'=>'اللون','value'=>'أحمر')),
    ),'أي مقاس تريده للحذاء الرياضي الأحمر البديل؟',cartAgentContextForTest($replaceOrigin,'بدل الحذاء القديم بالأحمر'));
    same(CartCommand::REPLACE,$replacePending->action());
    same('replacement',$replacePending->target()['kind']);
    same('preserve',$replacePending->target()['quantity_mode']);
    same('الحذاء القديم',$replacePending->forModel()['source_label']);

    $replaceFresh=new AuthorityRegistry();
    $replaceLineRefs=$replaceFresh->recordCartSnapshot(array(
        array(
            'cart_item_key'=>'line-replace-cont',
            'line_fingerprint'=>str_repeat('d',64),
            'name'=>'الحذاء القديم','quantity'=>3,
        ),
        array(
            'cart_item_key'=>'line-other',
            'line_fingerprint'=>str_repeat('e',64),
            'name'=>'سطر آخر','quantity'=>3,
        ),
    ));
    $freshSourceRef=$replaceLineRefs[0];
    $otherSourceRef=$replaceLineRefs[1];
    $freshReplaceProduct=$replaceFresh->recordProduct($product);
    $freshReplaceVariation=$replaceFresh->recordVariationCatalog(
        610,array($red42),str_repeat('b',64)
    )[0];
    $replaceArguments=array(
        'intent_text'=>'مقاس 42',
        'commands'=>array(array(
            'type'=>'replace','cart_item_ref'=>$freshSourceRef,
            'product_ref'=>$freshReplaceProduct,'variation_ref'=>$freshReplaceVariation,
            'quantity_mode'=>'preserve',
        )),
    );
    $replacePlan=(new CartPlanFactory())->fromToolArguments(
        $replaceArguments,$replaceFresh
    );
    same(3.0,$replacePlan->commands()[0]->quantity());
    same($replacePending->id(),$evidence->assertForPlan(
        'مقاس 42','مقاس 42',$replacePlan,$replaceFresh,
        $replaceArguments,$replacePending
    ));
    $clarificationProposal=(new CartIntentVerificationFactory($normalizer))->forClarification(
        cartAgentContextForTest($replaceOrigin,'بدل الحذاء القديم بالأحمر'),
        'بدل الحذاء القديم بالأحمر',
        'أي مقاس تريده للحذاء الرياضي الأحمر البديل؟',
        $replacePending,
        array(
            'missing_kind'=>'variation',
            'missing_axes'=>array(array(
                'label'=>'المقاس','listed_values'=>array('42','43'),
                'value_count'=>2,'values_complete'=>true,
            )),
        )
    )->forModel()['server_resolved_cart_proposal'];
    same(CartCommand::REPLACE,$clarificationProposal['requested_action']);
    same('أي مقاس تريده للحذاء الرياضي الأحمر البديل؟',$clarificationProposal['proposed_customer_question']);
    same('variation',$clarificationProposal['question_authority']['missing_kind']);
    same('الحذاء القديم',$clarificationProposal['target']['source_name']);
    same('preserve',$clarificationProposal['quantity']['mode']);

    $exactPending=pendingCartIntentForTest($factory,array(
        'action'=>'replace','target_ref'=>$replaceTargetRef,
        'source_cart_item_ref'=>$replaceSourceRef,
        'missing'=>'variation','intent_text'=>'بدله بالأحمر وخليه أربعة',
        'quantity_mode'=>'exact','quantity'=>4,
        'selected_attributes'=>array(array('label'=>'اللون','value'=>'أحمر')),
    ),'أي مقاس تريده للحذاء الرياضي الأحمر البديل؟',cartAgentContextForTest($replaceOrigin,'بدله بالأحمر وخليه أربعة'));
    same('exact',$exactPending->forModel()['quantity_mode']);
    same(4,$exactPending->forModel()['quantity']);
    $exactArguments=$replaceArguments;
    $exactArguments['commands'][0]['quantity_mode']='exact';
    $exactArguments['commands'][0]['quantity']=4;
    $exactPlan=(new CartPlanFactory())->fromToolArguments(
        $exactArguments,$replaceFresh
    );
    same($exactPending->id(),$evidence->assertForPlan(
        'مقاس 42','مقاس 42',$exactPlan,$replaceFresh,
        $exactArguments,$exactPending
    ));

    $wrongSource=$replaceArguments;
    $wrongSource['commands'][0]['cart_item_ref']=$otherSourceRef;
    $wrongSourcePlan=(new CartPlanFactory())->fromToolArguments(
        $wrongSource,$replaceFresh
    );
    same('',$evidence->assertForPlan(
        'مقاس 42','مقاس 42',$wrongSourcePlan,$replaceFresh,
        $wrongSource,$replacePending
    ));
});

test('AI-authored target clarification binds a terse answer to one fresh candidate plan', function (): void {
    $now=time();
    $normalizer=new CatalogTextNormalizer();
    $verifier=new FixedCartIntentVerifier();
    $factory=pendingCartIntentFactoryForTest(new FixedClock($now),$verifier);
    $coffee=array(
        'id'=>620,'name'=>'قهوة عربية 250 غرام','sku'=>'COF-250',
        'type'=>'simple','requires_variation'=>false,'attributes'=>array(),
    );
    $dark=$coffee;
    $dark['id']=621;$dark['name']='قهوة عربية داكنة 250 غرام';$dark['sku']='COF-DARK-250';
    $origin=new AuthorityRegistry();
    $coffeeRef=$origin->recordProduct($coffee);
    $darkRef=$origin->recordProduct($dark);
    $pending=pendingCartIntentForTest($factory,array(
        'action'=>'add','missing'=>'target','intent_text'=>'أضف القهوة',
        'candidate_commands'=>array(
            array('type'=>'add','product_ref'=>$coffeeRef,'quantity_mode'=>'default'),
            array('type'=>'add','product_ref'=>$darkRef,'quantity_mode'=>'default'),
        ),
    ),'أي نوع من القهوة تريد أن أضيفه؟',cartAgentContextForTest($origin,'أضف القهوة'));
    same(PendingCartIntent::MISSING_TARGET,$pending->missing());
    same(array('قهوة عربية 250 غرام','قهوة عربية داكنة 250 غرام'),$pending->forModel()['candidate_labels']);
    same($pending->toArray(),PendingCartIntent::fromArray($pending->toArray())->toArray());
    $question=$verifier->requests[0]->forModel()['server_resolved_cart_proposal'];
    same('target',$question['question_authority']['missing_kind']);
    same(2,$question['question_authority']['candidate_count']);
    same('default',$question['question_authority']['candidate_options'][0]['quantity_mode']);

    $fresh=new AuthorityRegistry();
    $freshCoffee=$fresh->recordProduct($coffee);
    $freshDark=$fresh->recordProduct($dark);
    $arguments=array(
        'intent_text'=>'الداكنة',
        'commands'=>array(array(
            'type'=>'add','product_ref'=>$freshDark,'quantity_mode'=>'default',
        )),
    );
    $plan=(new CartPlanFactory())->fromToolArguments($arguments,$fresh);
    $evidence=new CurrentTurnCartIntentEvidence(
        $normalizer,new VariableProductAuthority($normalizer)
    );
    $bound=$evidence->assertForPlan(
        'الداكنة','الداكنة',$plan,$fresh,$arguments,$pending
    );
    same($pending->id(),$bound);
    $proposal=(new CartIntentVerificationFactory($normalizer))->forPlan(
        cartAgentContextForTest($fresh,'الداكنة',$pending),
        'الداكنة',$plan,$arguments,$pending,$bound
    )->forModel()['server_resolved_cart_proposal'];
    same(true,$proposal['server_bound_continuation']);
    same('target',$proposal['resolved_missing_values']['missing']);
    same('قهوة عربية داكنة 250 غرام',$proposal['resolved_missing_values']['selected_candidate']['target']['name']);

    $wrongQuantity=$arguments;
    $wrongQuantity['commands'][0]['quantity_mode']='exact';
    $wrongQuantity['commands'][0]['quantity']=2;
    $wrongPlan=(new CartPlanFactory())->fromToolArguments($wrongQuantity,$fresh);
    same('',$evidence->assertForPlan(
        'الداكنة','الداكنة',$wrongPlan,$fresh,$wrongQuantity,$pending
    ));
    ok($freshCoffee!==$freshDark);

    throws(ContractViolation::class,static function()use($factory,$origin,$coffeeRef,$darkRef):void{
        pendingCartIntentForTest($factory,array(
            'action'=>'add','missing'=>'target','intent_text'=>'أضف القهوة',
            'candidate_commands'=>array(
                array('type'=>'add','product_ref'=>$coffeeRef,'quantity_mode'=>'default'),
                array('type'=>'add','product_ref'=>$darkRef,'quantity_mode'=>'exact','quantity'=>2),
            ),
        ),'أي قهوة تريد؟',cartAgentContextForTest($origin,'أضف القهوة'));
    },'pending_cart_candidates_invalid');
});

test('Variation clarification preserves live tuples and progressively binds terse axis answers', function (): void {
    $now=time();
    $normalizer=new CatalogTextNormalizer();
    $verifier=new FixedCartIntentVerifier();
    $factory=pendingCartIntentFactoryForTest(new FixedClock($now),$verifier);
    $product=array(
        'id'=>630,'name'=>'حذاء محدود الخيارات','sku'=>'SHOE-630',
        'type'=>'variable','requires_variation'=>true,
        'attributes'=>array(
            array('name'=>'اللون','variation'=>true,'values'=>array('أحمر','أزرق')),
            array('name'=>'المقاس','variation'=>true,'values'=>array('42','43')),
        ),
    );
    $red42=array(
        'id'=>6301,'parent_id'=>630,'name'=>'حذاء أحمر 42','sku'=>'S-R-42',
        'attributes'=>array(
            array('key'=>'attribute_color','label'=>'اللون','value'=>'red','display'=>'أحمر'),
            array('key'=>'attribute_size','label'=>'المقاس','value'=>'42','display'=>'42'),
        ),
    );
    $blue43=array(
        'id'=>6302,'parent_id'=>630,'name'=>'حذاء أزرق 43','sku'=>'S-B-43',
        'attributes'=>array(
            array('key'=>'attribute_color','label'=>'اللون','value'=>'blue','display'=>'أزرق'),
            array('key'=>'attribute_size','label'=>'المقاس','value'=>'43','display'=>'43'),
        ),
    );
    $epoch=str_repeat('9',64);
    $firstAuthority=new AuthorityRegistry();
    $firstProductRef=$firstAuthority->recordProduct($product);
    $firstAuthority->recordVariationCatalog(630,array($red42,$blue43),$epoch);
    $first=pendingCartIntentForTest($factory,array(
        'action'=>'add','target_ref'=>$firstProductRef,'missing'=>'variation',
        'intent_text'=>'أضف الحذاء','selected_attributes'=>array(),
    ),'أي لون ومقاس تريد؟',cartAgentContextForTest($firstAuthority,'أضف الحذاء'));
    $firstProposal=$verifier->requests[0]->forModel()['server_resolved_cart_proposal'];
    same(2,$firstProposal['question_authority']['combination_count']);
    same(true,$firstProposal['question_authority']['combinations_complete']);
    same(array(
        array(array('label'=>'اللون','value'=>'أحمر'),array('label'=>'المقاس','value'=>'42')),
        array(array('label'=>'اللون','value'=>'أزرق'),array('label'=>'المقاس','value'=>'43')),
    ),$firstProposal['question_authority']['listed_valid_combinations']);

    $secondAuthority=new AuthorityRegistry();
    $secondProductRef=$secondAuthority->recordProduct($product);
    $variationRefs=$secondAuthority->recordVariationCatalog(
        630,array($red42,$blue43),$epoch
    );
    $second=pendingCartIntentForTest($factory,array(
        'action'=>'add','target_ref'=>$secondProductRef,'missing'=>'variation',
        'intent_text'=>'الأحمر',
        'selected_attributes'=>array(array('label'=>'اللون','value'=>'أحمر')),
    ),'ما المقاس الذي تريده؟',cartAgentContextForTest(
        $secondAuthority,'الأحمر',$first
    ));
    same(array(array('label'=>'اللون','value'=>'أحمر')),$second->target()['bound_attributes']);
    same(array('المقاس'),$second->target()['missing_attributes']);
    $secondProposal=$verifier->requests[1]->forModel()['server_resolved_cart_proposal'];
    same(true,$secondProposal['server_bound_continuation']);
    same(array(
        'missing'=>'variation',
        'attributes'=>array(array('label'=>'اللون','value'=>'أحمر')),
    ),$secondProposal['resolved_missing_values']);
    same(array('42'),$secondProposal['question_authority']['missing_axes'][0]['listed_values']);

    $arguments=array(
        'intent_text'=>'42',
        'commands'=>array(array(
            'type'=>'add','product_ref'=>$secondProductRef,
            'variation_ref'=>$variationRefs[0],'quantity_mode'=>'default',
        )),
    );
    $plan=(new CartPlanFactory())->fromToolArguments($arguments,$secondAuthority);
    $evidence=new CurrentTurnCartIntentEvidence(
        $normalizer,new VariableProductAuthority($normalizer)
    );
    same($second->id(),$evidence->assertForPlan(
        '42','42',$plan,$secondAuthority,$arguments,$second
    ));

    $impossible=$red42;
    $impossible['id']=6303;
    $impossible['name']='حذاء أحمر 43';
    $impossible['attributes'][1]['value']='43';
    $impossible['attributes'][1]['display']='43';
    $impossibleRef=$secondAuthority->recordVariation($impossible);
    $impossibleArguments=$arguments;
    $impossibleArguments['commands'][0]['variation_ref']=$impossibleRef;
    $impossiblePlan=(new CartPlanFactory())->fromToolArguments(
        $impossibleArguments,$secondAuthority
    );
    same('',$evidence->assertForPlan(
        '43','43',$impossiblePlan,$secondAuthority,
        $impossibleArguments,$second
    ));
});

test('Customer-visible custom cart-line data distinguishes AI targets and verifier authority', function (): void {
    $previousFilters=$GLOBALS['ysai_test_filters'];
    try {
        $GLOBALS['ysai_test_filters']['woocommerce_get_item_data']=array(array(
            static function(array $data,array $item):array{
                return array(
                    array('key'=>'النقش','value'=>'Yassin &amp; Co','display'=>'<b>Yassin &amp; Co</b>'),
                    array('key'=>'داخلي','value'=>'سر','hidden'=>true),
                    array('name'=>'التغليف','value'=>$item['gift_wrap']??''),
                );
            },10,2,
        ));
        same(array(
            array('label'=>'النقش','value'=>'Yassin & Co'),
            array('label'=>'التغليف','value'=>'هدية'),
        ),(new CartItemDisplayProjector())->project(array('gift_wrap'=>'هدية')));
    } finally {
        $GLOBALS['ysai_test_filters']=$previousFilters;
    }

    $now=time();
    $normalizer=new CatalogTextNormalizer();
    $verifier=new FixedCartIntentVerifier();
    $factory=pendingCartIntentFactoryForTest(new FixedClock($now),$verifier);
    $authority=new AuthorityRegistry();
    $light=array(
        'cart_item_key'=>'coffee-light','line_fingerprint'=>str_repeat('1',64),
        'product_id'=>640,'name'=>'قهوة عربية','quantity'=>1,
        'attributes'=>array(array('label'=>'الوزن','value'=>'250 غرام')),
        'item_data'=>array(array('label'=>'التحميص','value'=>'فاتح')),
    );
    $dark=$light;
    $dark['cart_item_key']='coffee-dark';
    $dark['line_fingerprint']=str_repeat('2',64);
    $dark['item_data']=array(array('label'=>'التحميص','value'=>'داكن'));
    $refs=$authority->recordCartSnapshot(array($light,$dark));
    $pending=pendingCartIntentForTest($factory,array(
        'action'=>'remove','missing'=>'target','intent_text'=>'احذف القهوة',
        'candidate_commands'=>array(
            array('type'=>'remove','cart_item_ref'=>$refs[0]),
            array('type'=>'remove','cart_item_ref'=>$refs[1]),
        ),
    ),'أي قهوة تريد حذفها: الفاتحة أم الداكنة؟',cartAgentContextForTest(
        $authority,'احذف القهوة'
    ));
    same(array(
        'قهوة عربية (الوزن: 250 غرام، التحميص: فاتح)',
        'قهوة عربية (الوزن: 250 غرام، التحميص: داكن)',
    ),$pending->forModel()['candidate_labels']);

    $arguments=array(
        'intent_text'=>'الداكنة',
        'commands'=>array(array('type'=>'remove','cart_item_ref'=>$refs[1])),
    );
    $plan=(new CartPlanFactory())->fromToolArguments($arguments,$authority);
    $evidence=new CurrentTurnCartIntentEvidence(
        $normalizer,new VariableProductAuthority($normalizer)
    );
    $bound=$evidence->assertForPlan(
        'الداكنة','الداكنة',$plan,$authority,$arguments,$pending
    );
    same($pending->id(),$bound);
    $proposal=(new CartIntentVerificationFactory($normalizer))->forPlan(
        cartAgentContextForTest($authority,'الداكنة',$pending),
        'الداكنة',$plan,$arguments,$pending,$bound
    )->forModel()['server_resolved_cart_proposal'];
    same(array(array('label'=>'التحميص','value'=>'داكن')),$proposal['source']['item_data']);
});

test('Target clarification rejects distinct cart lines with indistinguishable visible labels', function (): void {
    $factory=pendingCartIntentFactoryForTest(new FixedClock(time()),new FixedCartIntentVerifier());
    $authority=new AuthorityRegistry();
    $base=array(
        'cart_item_key'=>'line-a','line_fingerprint'=>str_repeat('a',64),
        'product_id'=>650,'name'=>'قهوة عربية','quantity'=>1,
        'attributes'=>array(array('label'=>'الوزن','value'=>'250 غرام')),
        'item_data'=>array(),
    );
    $other=$base;
    $other['cart_item_key']='line-b';
    $other['line_fingerprint']=str_repeat('b',64);
    $refs=$authority->recordCartSnapshot(array($base,$other));
    throws(ContractViolation::class,static function()use($factory,$authority,$refs):void{
        pendingCartIntentForTest($factory,array(
            'action'=>'remove','missing'=>'target','intent_text'=>'احذف القهوة',
            'candidate_commands'=>array(
                array('type'=>'remove','cart_item_ref'=>$refs[0]),
                array('type'=>'remove','cart_item_ref'=>$refs[1]),
            ),
        ),'أي سطر تريد حذفه؟',cartAgentContextForTest($authority,'احذف القهوة'));
    },'pending_cart_candidate_labels_ambiguous');
});

test('Replacement is one exact rollback-safe primitive and verified delta', function (): void {
    $source=line('source-line',2,10);
    $target=line('target-line',1,20);
    $pre=snapshot(array($source,$target),array(),3,30,'replace-pre');
    $command=CartCommand::replace(
        $source->key(),$source->fingerprint(),20,0,2,
        str_repeat('d',64),'Product 20'
    );
    $plan=new CartPlan(array($command));
    $primitive=(new CartStepPlanner())->plan($plan,$pre)[0];
    same(CartPrimitive::REPLACE_LINE,$primitive->type());
    same(CartCommand::REPLACE,$primitive->semanticType());
    $contradictory=$primitive->toStorageArray();
    $contradictory['phase']='single';
    throws(InvalidArgumentException::class,static function()use($contradictory):void{
        CartPrimitive::fromStorageArray($contradictory);
    },'phase is contradictory');

    $post=snapshot(array(line('target-line',3,20)),array(),3,30,'replace-post');
    $sealed=(new CartStepVerifier())->seal($primitive,$pre,$post,array(
        'primitive_type'=>CartPrimitive::REPLACE_LINE,
        'semantic_type'=>CartCommand::REPLACE,
        'phase'=>'replace_atomic','command_index'=>0,
        'source_cart_item_key'=>'source-line','source_previous_quantity'=>2.0,
        'target_cart_item_key'=>'target-line','target_previous_quantity'=>1.0,
        'quantity'=>2.0,'product_id'=>20,'variation_id'=>0,
        'display_name'=>'Product 20',
    ));
    same($source->fingerprint(),$sealed['before_line_fingerprint']);
    same($post->line('target-line')->fingerprint(),$sealed['post_line_fingerprint']);

    $applied=new AppliedCartPlan(array(array(
        'type'=>'replace','source_cart_item_key'=>'source-line',
        'source_previous_quantity'=>2.0,'target_cart_item_key'=>'target-line',
        'target_previous_quantity'=>1.0,'quantity'=>2.0,
        'product_id'=>20,'variation_id'=>0,'display_name'=>'Product 20',
    )));
    ok((new CartDeltaVerifier())->verify($plan,$pre,$post,$applied)->isVerified());
    $receipt=(new ReceiptPresenter(new CartDeltaVerifier()))->create(
        $plan,$pre,$post,true
    );
    contains('تم استبدال عنصر السلة',$receipt->safeMessage());

    $sourceStillPresent=snapshot(array($source,line('target-line',3,20)),array(),5,50,'bad');
    ok(!(new CartDeltaVerifier())->verify(
        $plan,$pre,$sourceStillPresent,$applied
    )->isVerified());
});

test('Replacement excludes and removes the source before Woo validates the target add', function (): void {
    $source=line('source-line',1,10);
    $pre=snapshot(array($source),array(),1,10,'replace-stock-pre');
    $primitive=CartPrimitive::replaceLine(
        0,$source->key(),$source->fingerprint(),20,0,1,
        str_repeat('d',64),'Product 20'
    );
    $events=(object)array('rows'=>array());
    $gateway=new class($events) {
        private $events;
        public function __construct($events){$this->events=$events;}
        public function suppressAutomaticTotals():void{$this->events->rows[]='suppress';}
        public function restoreAutomaticTotals():void{$this->events->rows[]='restore';}
        public function rawItem(string $key):?array{
            $this->events->rows[]='raw:'.$key;
            return array('product_id'=>10,'variation_id'=>0,'quantity'=>1);
        }
        public function remove(string $key):void{$this->events->rows[]='remove:'.$key;}
        public function add(int $productId,int $quantity,int $variationId,array $variation):string{
            $this->events->rows[]='add:'.$productId.':'.$quantity;
            return 'target-line';
        }
    };
    $products=new class($events) {
        private $events;
        public function __construct($events){$this->events=$events;}
        public function purchase(
            int $productId,int $variationId,int $quantity,
            string $fingerprint,string $excludedCartItemKey=''
        ):array{
            $this->events->rows[]='purchase-excluding:'.$excludedCartItemKey;
            return array('product_id'=>$productId,'variation_id'=>$variationId,'variation'=>array());
        }
    };
    $capability=new class($events) {
        private $events;
        public function __construct($events){$this->events=$events;}
        public function assertSupported():void{$this->events->rows[]='capability';}
    };
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
    same(array(
        'capability','suppress','raw:source-line',
        'purchase-excluding:source-line','remove:source-line','add:20:1','restore',
    ),$events->rows);
    same('source-line',$effect['source_cart_item_key']);
    same('target-line',$effect['target_cart_item_key']);
});

test('Public message length is one extension-independent Unicode code-point contract', function (): void {
    same(3,Utf8::codePointLength('أ😀ب'));
    $conversationId=Uuid::v4(); $turnId=Uuid::v4(); $token=str_repeat('a',24);
    $accepted=str_repeat('😀',1200);
    $request=new TurnRequest($conversationId,$token,$turnId,$accepted,array());
    same($accepted,$request->message());
    throws(InvalidArgumentException::class,static function() use($conversationId,$token): void {
        new TurnRequest($conversationId,$token,Uuid::v4(),str_repeat('😀',1201),array());
    },'too long');
});
test('Customer text remains byte-identical from REST decoding through replay identity and model input', function (): void {
    $decoder=requestDecoderForTest();
    $exact="  <b>خصم 100%</b>\n\tالسطر الثاني  ";
    $body=array(
        'conversation_id'=>Uuid::v4(),
        'conversation_token'=>str_repeat('a',24),
        'client_turn_id'=>Uuid::v4(),
        'message'=>$exact,
        'attachments'=>array(),
    );
    $envelope=$decoder->chatEnvelope(new WP_REST_Request(Json::encodeObject($body),$body));
    same($exact,$envelope['message']);
    $request=$decoder->chatFromEnvelope($envelope);
    same($exact,$request->message());

    $storage=(new TurnRequestHasher(new FixedFingerprint()))->storageInput($request);
    same(hash('sha256',"turn-message-v1\0".$exact),$storage['message_fingerprint']??'');
    same(Utf8::codePointLength($exact),$storage['message_length']??0);

    $canonical=new CanonicalUserMessage($request->message(),new UserMessagePresentation(array()));
    same($exact,$canonical->text());
    $model=new ModelRequest(
        'System',
        array(
            array('role'=>'user','text'=>$exact),
            array('role'=>'assistant','text'=>"  جواب  "),
        ),
        $exact,
        array(),
        array(array('name'=>'cart_view','description'=>'View cart','parameters'=>ToolSchemas::emptyObject())),
        1024
    );
    same($exact,$model->history()[0]['text']);
    same($exact,$model->userText());
});
test('Reply context is a closed separately hashed field and never rewrites customer text', function (): void {
    $decoder=requestDecoderForTest();
    $message="> كتابة عادية\n\nأضفها";
    $quote='قهوة عربية';
    $body=array(
        'conversation_id'=>Uuid::v4(),
        'conversation_token'=>str_repeat('a',24),
        'client_turn_id'=>Uuid::v4(),
        'message'=>$message,
        'reply_context'=>array('text'=>$quote),
        'attachments'=>array(),
    );
    $envelope=$decoder->chatEnvelope(new WP_REST_Request(Json::encodeObject($body),$body));
    same($message,$envelope['message']);
    same($quote,$envelope['reply_context']);
    $request=$decoder->chatFromEnvelope($envelope);
    same($message,$request->message());
    same($quote,$request->replyContext());
    $storage=(new TurnRequestHasher(new FixedFingerprint()))->storageInput($request);
    same(true,$storage['reply_context_present']??false);
    same(Utf8::codePointLength($quote),$storage['reply_context_length']??0);
    same(hash('sha256',"turn-reply-context-v1\0".$quote),$storage['reply_context_fingerprint']??'');
    $canonical=(new CanonicalUserMessageFactory(new FixedTextLocalizer()))->create($request);
    same($message,$canonical->text());
    same($quote,$canonical->presentation()->replyQuote());

    $invalid=$body;
    $invalid['reply_context']=array('text'=>$quote,'unexpected'=>true);
    try {
        $decoder->chatEnvelope(new WP_REST_Request(Json::encodeObject($invalid),$invalid));
        throw new TestFailure('Expected closed reply-context rejection.');
    } catch(InvalidRequest $exception) {
        same('reply_context_invalid',$exception->reasonCode());
    }
});
test('Product-card replies bind a canonical message position into replay identity', function (): void {
    $decoder=requestDecoderForTest();
    $messageId=Uuid::v4();
    $body=array(
        'conversation_id'=>Uuid::v4(),
        'conversation_token'=>str_repeat('a',24),
        'client_turn_id'=>Uuid::v4(),
        'message'=>'أضفه',
        'reply_context'=>array(
            'text'=>'قهوة عربية — 500 ر.س',
            'message_id'=>$messageId,
            'product_index'=>1,
        ),
        'attachments'=>array(),
    );
    $request=$decoder->chatFromEnvelope(
        $decoder->chatEnvelope(new WP_REST_Request(Json::encodeObject($body),$body))
    );
    same(true,$request->hasProductReply());
    same($messageId,$request->replyMessageId());
    same(1,$request->replyProductIndex());
    $hasher=new TurnRequestHasher(new FixedFingerprint());
    $first=$hasher->hash($request);
    $body['reply_context']['product_index']=2;
    $secondRequest=$decoder->chatFromEnvelope(
        $decoder->chatEnvelope(new WP_REST_Request(Json::encodeObject($body),$body))
    );
    ok(!hash_equals($first,$hasher->hash($secondRequest)));
});
test('REST classifies whitespace-only exact text as a 400 empty turn without rewriting it', function (): void {
    $decoder=requestDecoderForTest();
    $whitespace=" \t\r\n ";
    $body=array(
        'conversation_id'=>Uuid::v4(),
        'conversation_token'=>str_repeat('a',24),
        'client_turn_id'=>Uuid::v4(),
        'message'=>$whitespace,
        'attachments'=>array(),
    );
    $envelope=$decoder->chatEnvelope(new WP_REST_Request(Json::encodeObject($body),$body));
    same($whitespace,$envelope['message']);
    try {
        $decoder->chatFromEnvelope($envelope);
        throw new TestFailure('Expected whitespace-only turn rejection.');
    } catch (InvalidRequest $exception) {
        same('turn_empty',$exception->reasonCode());
        same(400,$exception->httpStatus());
    }
});
test('REST rejects a malformed chat conversation token as a client contract error', function (): void {
    $decoder=requestDecoderForTest();
    $body=array(
        'conversation_id'=>Uuid::v4(),
        'conversation_token'=>str_repeat('a',23).'.',
        'client_turn_id'=>Uuid::v4(),
        'message'=>'مرحبا',
        'attachments'=>array(),
    );
    try {
        $decoder->chatEnvelope(new WP_REST_Request(Json::encodeObject($body),$body));
        throw new TestFailure('Expected malformed conversation token rejection.');
    } catch (InvalidRequest $exception) {
        same('conversation_contract_invalid',$exception->reasonCode());
        same(400,$exception->httpStatus());
    }
});
test('REST classifies Unicode-separator-only exact text as an empty turn', function (): void {
    $decoder=requestDecoderForTest();
    $whitespace="\xC2\xA0\xE2\x80\x83";
    $body=array(
        'conversation_id'=>'11111111-1111-4111-8111-111111111111',
        'conversation_token'=>str_repeat('t',24),
        'client_turn_id'=>'22222222-2222-4222-8222-222222222222',
        'message'=>$whitespace,
        'attachments'=>array(),
    );
    $envelope=$decoder->chatEnvelope(new WP_REST_Request(Json::encodeObject($body),$body));
    same($whitespace,$envelope['message']);
    try {
        $decoder->chatFromEnvelope($envelope);
        throw new TestFailure('Expected Unicode-whitespace-only turn rejection.');
    } catch (InvalidRequest $exception) {
        same('turn_empty',$exception->reasonCode());
    }
});
test('Customer text rejects malformed UTF-8 and non-text controls instead of normalizing them', function (): void {
    ok(!Utf8::isPlainText("bad\x00text"));
    ok(!Utf8::isPlainText("\xC3\x28"));
    ok(!Utf8::isPlainText("bad\xC2\x85text"));
    throws(InvalidArgumentException::class,static function():void{
        new TurnRequest(Uuid::v4(),str_repeat('a',24),Uuid::v4(),"\xC3\x28",array());
    },'plain text');

    $decoder=requestDecoderForTest();
    $body=array(
        'conversation_id'=>Uuid::v4(),
        'conversation_token'=>str_repeat('a',24),
        'client_turn_id'=>Uuid::v4(),
        'message'=>"bad\x00text",
        'attachments'=>array(),
    );
    throws(InvalidRequest::class,static function()use($decoder,$body):void{
        $decoder->chatEnvelope(new WP_REST_Request(Json::encodeObject($body),$body));
    },'message_text_invalid');
});
test('Server execution and browser replay windows come from one closed timing policy', function (): void {
    same(10,TurnExecutionPolicy::maxProviderRequests(6));
    same(420,TurnExecutionPolicy::executionSeconds(30,6));
    same(480000,TurnExecutionPolicy::clientDeadlineMilliseconds(30,6));
    same(1020000,TurnExecutionPolicy::retryRetentionMilliseconds(30,6));
    same(14,TurnExecutionPolicy::maxProviderRequests(10));
    same(1380,TurnExecutionPolicy::executionSeconds(90,10));
    same(1440000,TurnExecutionPolicy::clientDeadlineMilliseconds(90,10));
    same(1980000,TurnExecutionPolicy::retryRetentionMilliseconds(90,10));
});
test('UTF-8 truncation preserves exact astral code-point boundaries without mbstring', function (): void {
    $value=str_repeat('😀',500).'م';
    $truncated=Utf8::truncate($value,500);
    same(str_repeat('😀',500),$truncated);
    same(500,Utf8::codePointLength($truncated));
    same('',Utf8::truncate($value,0));
    throws(InvalidArgumentException::class,static function()use($value):void{
        Utf8::truncate($value,-1);
    },'must not be negative');
});
