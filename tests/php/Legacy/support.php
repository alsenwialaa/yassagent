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

if (!class_exists('YsaiTestWooSession')) {
    final class YsaiTestWooSession {
        public $customerId='guest-session';
        public $data=array();
        public function get_customer_id(){return $this->customerId;}
        public function set_customer_session_cookie($set): void {}
        public function get($key,$default=null){return array_key_exists($key,$this->data)?$this->data[$key]:$default;}
        public function set($key,$value): void {$this->data[$key]=$value;}
    }
}
if (!function_exists('WC')) {
    function WC(){return $GLOBALS['ysai_test_wc'];}
}
if (!function_exists('wc_load_cart')) {
    function wc_load_cart(): void {
        $loader=$GLOBALS['ysai_test_wc_load_cart']??null;
        if(!is_callable($loader)){throw new RuntimeException('Unexpected test WooCommerce cart load.');}
        $loader();
    }
}
if (!function_exists('wc_get_product')) {
    function wc_get_product($id){return $GLOBALS['ysai_test_products'][(int)$id]??false;}
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int { return (int)($GLOBALS['ysai_test_current_user_id']??0); }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool { return get_current_user_id()>0; }
}
if (!function_exists('wp_is_serving_rest_request')) {
    function wp_is_serving_rest_request(): bool { return !empty($GLOBALS['ysai_test_rest_request']); }
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code; private $message;
        public function __construct(string $code='',string $message=''){ $this->code=$code; $this->message=$message; }
        public function get_error_message(): string { return $this->message; }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($value): bool { return $value instanceof WP_Error; }
}
if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url,$args){
        $handler=$GLOBALS['ysai_test_http_handler']??null;
        if(!is_callable($handler)){throw new RuntimeException('Unexpected test HTTP request.');}
        return $handler((string)$url,(array)$args);
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response): int { return is_array($response)?(int)($response['status']??0):0; }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string { return is_array($response)?(string)($response['body']??''):''; }
}

if (!class_exists('WC_Product')) {
    class WC_Product {
        private $type; private $price; private $minimum; private $maximum; private $id;
        public $status='publish'; public $visible=true; public $catalogVisibility='visible'; public $purchasable=true; public $inStock=true; public $attributes=array();
        public $name='Product'; public $sku=''; public $soldIndividually=false; public $stockManagedById=0; public $children=array();
        public function __construct(string $type='simple', $price='', $minimum=null, $maximum=null, int $id=0) {
            $this->type=$type; $this->price=$price; $this->minimum=$minimum; $this->maximum=$maximum; $this->id=$id;
        }
        public function is_type($type): bool { return $this->type===$type; }
        public function get_price() { return $this->price; }
        public function get_variation_price($bound, $display=false) { return $bound==='min' ? $this->minimum : $this->maximum; }
        public function get_status(): string { return (string)$this->status; }
        public function is_visible(): bool { return (bool)$this->visible; }
        public function get_catalog_visibility(): string { return (string)$this->catalogVisibility; }
        public function is_purchasable(): bool { return (bool)$this->purchasable; }
        public function is_in_stock(): bool { return (bool)$this->inStock; }
        public function get_id(): int { return $this->id; }
        public function get_name(): string { return (string)$this->name; }
        public function get_sku(): string { return (string)$this->sku; }
        public function get_type(): string { return (string)$this->type; }
        public function is_sold_individually(): bool { return (bool)$this->soldIndividually; }
        public function get_min_purchase_quantity(): int { return 1; }
        public function get_max_purchase_quantity(): int { return 999; }
        public function has_enough_stock($quantity): bool { return $this->inStock && (float)$quantity <= 999.0; }
        public function get_stock_managed_by_id(): int { return $this->stockManagedById>0?$this->stockManagedById:$this->id; }
        public function get_attributes(): array { return $this->attributes; }
        public function get_children(): array { return $this->children; }
    }
}
if (!class_exists('WC_Product_Variable')) {
    class WC_Product_Variable extends WC_Product {
        public function __construct($price='', $minimum=null, $maximum=null, int $id=0) {
            parent::__construct('variable',$price,$minimum,$maximum,$id);
        }
    }
}
if (!class_exists('WP_Term')) {
    class WP_Term {
        public $term_id; public $taxonomy; public $slug;
        public function __construct(int $termId=0, string $taxonomy='', string $slug='') {
            $this->term_id=$termId; $this->taxonomy=$taxonomy; $this->slug=$slug;
        }
    }
}
if (!class_exists('WC_Product_Attribute')) {
    class WC_Product_Attribute {
        private $name; private $options; private $taxonomy; private $variation;
        public function __construct(string $name,array $options,bool $taxonomy=false,bool $variation=false){
            $this->name=$name;$this->options=$options;$this->taxonomy=$taxonomy;$this->variation=$variation;
        }
        public function get_name(): string{return $this->name;}
        public function get_options(): array{return $this->options;}
        public function is_taxonomy(): bool{return $this->taxonomy;}
        public function get_variation(): bool{return $this->variation;}
    }
}
if (!class_exists('WC_Product_Variation')) {
    class WC_Product_Variation extends WC_Product {
        public $parentId=0; public $variationAttributes=array(); public $variationVisible=true;
        public function __construct(int $id=0, int $parentId=0, array $attributes=array()) { parent::__construct('variation','',null,null,$id); $this->parentId=$parentId; $this->variationAttributes=$attributes; }
        public function get_parent_id(): int { return (int)$this->parentId; }
        public function get_variation_attributes(): array { return $this->variationAttributes; }
        public function variation_is_visible(): bool { return (bool)$this->variationVisible; }
    }
}
if (!function_exists('wc_get_product_terms')) {
    function wc_get_product_terms($productId,$taxonomy,$args=array()){
        unset($args);
        return $GLOBALS['ysai_test_product_terms'][(int)$productId][(string)$taxonomy]??array();
    }
}
if (!function_exists('wc_attribute_label')) {
    function wc_attribute_label($name,$product=null): string{
        unset($product);
        return (string)($GLOBALS['ysai_test_attribute_labels'][(string)$name]??$name);
    }
}
if (!function_exists('taxonomy_exists')) {
    function taxonomy_exists($taxonomy): bool{return isset($GLOBALS['ysai_test_taxonomies'][(string)$taxonomy]);}
}
if (!function_exists('get_term_by')) {
    function get_term_by($field,$value,$taxonomy){
        unset($field);
        return $GLOBALS['ysai_test_terms_by_slug'][(string)$taxonomy][(string)$value]??false;
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($title): string {
        return trim(strtolower((string)$title));
    }
}
if (!function_exists('wc_get_attribute_taxonomy_names')) {
    function wc_get_attribute_taxonomy_names(): array {
        return array_values((array)($GLOBALS['ysai_test_attribute_taxonomies']??array()));
    }
}
if (!function_exists('get_terms')) {
    function get_terms($args=array()) {
        $handler=$GLOBALS['ysai_test_get_terms']??null;
        return is_callable($handler)?$handler((array)$args):array();
    }
}
if (!function_exists('get_objects_in_term')) {
    function get_objects_in_term($termIds,$taxonomy,$args=array()) {
        unset($args);
        $handler=$GLOBALS['ysai_test_get_objects_in_term']??null;
        return is_callable($handler)?$handler((array)$termIds,(string)$taxonomy):array();
    }
}

function publicApiContract(): PublicApiContract {
    $raw=file_get_contents(YSAI_PROJECT_ROOT.'/config/public-api-contract.json');
    if(!is_string($raw)||$raw===''){throw new RuntimeException('Test public API contract is missing.');}
    return new PublicApiContract(Json::decodeRequiredObject($raw,'Test public API contract'));
}
function publicResponseValidatorForTest(?PublicApiContract $contract=null): PublicResponseSchemaValidator {
    return new PublicResponseSchemaValidator($contract??publicApiContract());
}
function apiResponderForTest(?PublicApiContract $contract=null): ApiResponder {
    $validator=publicResponseValidatorForTest($contract);
    return new ApiResponder(new ErrorResponseProjector($validator));
}
/** @param array<string,mixed> $export */
function assertConversationExportResponseForTest(array $export): void {
    (new ConversationExportResponseProjector(publicResponseValidatorForTest()))->project($export);
}
function publicMessageForTest(string $role,string $text,string $outcome=''): array {
    if($role!=='user'&&$role!=='assistant'){throw new InvalidArgumentException('Test message role is invalid.');}
    if($role==='user'){$outcome='';}
    elseif($outcome===''){$outcome=Outcome::ANSWER;}
    return array(
        'id'=>Uuid::v4(),
        'turn_id'=>Uuid::v4(),
        'role'=>$role,
        'outcome'=>$outcome,
        'text'=>$text,
        'products'=>array(),
        'receipts'=>array(),
        'presentation'=>array('image_scope'=>'none','images'=>array(),'reply_quote'=>''),
        'created_at'=>time(),
    );
}
function privacyMessagePayloadForTest(string $role,string $text,string $outcome=''): array {
    return $role==='user'
        ? array('presentation'=>array('image_scope'=>'none','images'=>array(),'reply_quote'=>''))
        : array('message'=>publicMessageForTest('assistant',$text,$outcome));
}
function imageRuntimeUnlimited(): ImageRuntimeCapability {
    return new ImageRuntimeCapability(static function (): string { return '-1'; }, static function (): int { return 0; });
}
function requestDecoderForTest(?PublicApiContract $contract=null): RequestDecoder {
    $contract=$contract??publicApiContract();
    return new RequestDecoder(
        new Settings(),
        $contract,
        new ImageAttachmentDecoder($contract,imageRuntimeUnlimited())
    );
}
function geminiGatewayForTest(
    GeminiTransportInterface $transport,
    string $thinkingLevel='low'
): GeminiGateway {
    return new GeminiGateway(
        $transport,
        new GeminiResponseDecoder(),
        new GeminiSchemaProjector(),
        new GeminiGenerationPolicy($thinkingLevel)
    );
}
function tinyPngBase64(int $decodedBytes=0): string {
    $base=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZJXcAAAAASUVORK5CYII=',true);
    if(!is_string($base)){throw new RuntimeException('Unable to decode test PNG.');}
    if($decodedBytes>strlen($base)){$base.=str_repeat("\0",$decodedBytes-strlen($base));}
    return base64_encode($base);
}


final class RecordingTimeoutTransport implements GeminiTimeoutTransportInterface {
    public $timeouts=array(); public $generateCalls=0;
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function generate(array $payload): array { ++$this->generateCalls; return $this->response(); }
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function generateWithTimeout(array $payload,int $timeoutSeconds): array {
        $this->timeouts[]=$timeoutSeconds; return $this->response();
    }
    /** @return array<string,mixed> */
    private function response(): array {
        return array('candidates'=>array(array(
            'finishReason'=>'STOP',
            'content'=>array('role'=>'model','parts'=>array(array(
                'functionCall'=>array('id'=>'timeout-provider-call','name'=>'cart_view','args'=>array()),
                'thoughtSignature'=>'timeout-thought-signature',
            )))
        )));
    }
}
final class RecordingTurnLeasePort implements TurnLeasePort {
    public $remaining=100.0; public $renewals=0; public $assertions=0;
    public function acquire(string $resource,int $ttl): ?TurnLease { return null; }
    public function renew(TurnLease $lease,int $ttl): TurnLease { ++$this->renewals; $this->remaining=(float)$ttl; return $lease->renewedUntil(time()+$ttl); }
    public function remainingSeconds(TurnLease $lease): float { return $this->remaining; }
    public function assertCurrent(TurnLease $lease): void { ++$this->assertions; }
    public function isCurrent(TurnLease $lease): bool { return true; }
    public function assertCurrentForUpdate(TurnLease $lease): void { ++$this->assertions; }
    public function release(TurnLease $lease): void {}
}
final class RecordingProviderWaitIsolation implements ProviderWaitIsolationPort {
    /** @var int */ public $releases=0;
    public function releaseForProviderWait(): void { ++$this->releases; }
}
final class TestFailure extends RuntimeException {}
final class MemoryBrowserContinuityAuthority implements BrowserContinuityAuthorityPort {
    /** @var array<string,array{nonce:string,status:string,rotated_to:string}> */
    private $rows=array();

    public function activate(string $secretHash): string {
        if(isset($this->rows[$secretHash])){
            if($this->rows[$secretHash]['status']!=='active'){
                throw new RuntimeException('Browser continuity credential has been revoked.');
            }
            return $this->rows[$secretHash]['nonce'];
        }
        $nonce=rtrim(strtr(base64_encode(hash('sha256','nonce|'.$secretHash,true)),'+/','-_'),'=');
        $this->rows[$secretHash]=array('nonce'=>$nonce,'status'=>'active','rotated_to'=>'');
        return $nonce;
    }

    public function rotate(string $previousSecretHash,string $nextSecretHash): string {
        if(!isset($this->rows[$previousSecretHash])){
            if(isset($this->rows[$nextSecretHash])){
                if($this->rows[$nextSecretHash]['status']!=='active'){
                    throw new RuntimeException('Browser continuity credential has been revoked.');
                }
                return $this->rows[$nextSecretHash]['nonce'];
            }
            throw new RuntimeException('Previous browser continuity authority is missing.');
        }
        $previous=$this->rows[$previousSecretHash];
        if($previous['status']==='revoked'){
            if($previous['rotated_to']===$nextSecretHash
                && isset($this->rows[$nextSecretHash])
                && $this->rows[$nextSecretHash]['status']==='active'
            ){
                return $this->rows[$nextSecretHash]['nonce'];
            }
            throw new RuntimeException('Browser continuity credential has been revoked.');
        }
        if(isset($this->rows[$nextSecretHash])){
            throw new RuntimeException('A browser continuity credential cannot be reused.');
        }
        $next=$this->activate($nextSecretHash);
        $this->rows[$previousSecretHash]['status']='revoked';
        $this->rows[$previousSecretHash]['rotated_to']=$nextSecretHash;
        return $next;
    }

    public function cleanupExpired(int $limit): int { return 0; }

    public function deleteOneRevokedPredecessor(): bool {
        foreach($this->rows as $secretHash=>$row){
            if($row['status']==='revoked'){
                unset($this->rows[$secretHash]);
                return true;
            }
        }
        return false;
    }

    public function assertActiveNonce(string $sessionNonce): void {
        $active=0;
        foreach($this->rows as $row){
            if($row['nonce']===$sessionNonce && $row['status']==='active'){++$active;}
        }
        if($active!==1){throw new RuntimeException('Browser continuity session authority is not active.');}
    }
}
final class SchemaLockDatabase {
    public $last_error=''; public $prepared=true; public $result='1'; public $errorAfterQuery=''; public $queries=array();
    public function prepare(string $sql,...$args){return $this->prepared ? $sql : false;}
    public function get_var($query){$this->queries[]=$query;if($this->errorAfterQuery!==''){$this->last_error=$this->errorAfterQuery;}return $this->result;}
}
final class AdvisoryScopeDatabase {
    public $prefix='scope_'; public $last_error=''; public $preparedArguments=array();
    public function prepare(string $sql,...$args): string{$this->preparedArguments[]=array($sql,$args);return $sql;}
    public function get_var(string $query){return '1';}
}
final class CartStorageTopologyDatabase {
    public $dbname='store'; public $last_error=''; public $engineReads=0; public $indexReads=0;
    private $engines; private $indexSets;
    public function __construct(array $engines,array $indexSets){$this->engines=$engines;$this->indexSets=$indexSets;}
    public function prepare(string $sql,...$args): string{return $sql;}
    public function get_var(string $sql){++$this->engineReads;return array_shift($this->engines);}
    public function get_results(string $sql,$format): array{++$this->indexReads;$rows=array_shift($this->indexSets);return is_array($rows)?$rows:array();}
}

final class MessageWindowDatabase {
    public $prefix='message_window_'; public $charset='utf8mb4'; public $collate=''; public $last_error=''; public $prepares=array();
    public function prepare(string $sql,...$args): string{$this->prepares[]=array($sql,$args);return $sql;}
    public function get_results(string $sql,$format): array{return array();}
}

final class CommittedTurnProjectionDatabase {
    public $prefix='projection_'; public $charset='utf8mb4'; public $collate=''; public $last_error='';
    public $exactTurnReads=0;
    private $lastArgs=array();
    private $turns=array();
    private $messages=array();
    private $targetTurnId='';

    public function __construct(){
        for($index=1;$index<=13;++$index){
            $turnId=$this->uuid($index);
            if($index===1){$this->targetTurnId=$turnId;}
            $this->turns[$turnId]=array('id'=>$index,'turn_id'=>$turnId,'status'=>TurnStatus::COMPLETED);
            $createdAt=1700000000+$index;
            $assistant=array(
                'id'=>$this->uuid(1000+$index),
                'turn_id'=>$turnId,
                'role'=>'assistant',
                'outcome'=>Outcome::ANSWER,
                'text'=>'رد موثق '.$index,
                'products'=>array(),
                'receipts'=>array(),
                'presentation'=>array('image_scope'=>'none','images'=>array(),'reply_quote'=>''),
                'created_at'=>$createdAt,
            );
            $this->messages[$turnId]=array(
                array(
                    'id'=>(2*$index)-1,
                    'public_id'=>$this->uuid(2000+$index),
                    'conversation_id'=>7,
                    'turn_id'=>$turnId,
                    'role'=>'user',
                    'outcome'=>'',
                    'content'=>'طلب '.$index,
                    'payload'=>Json::encodeObject(array(
                        'presentation'=>array('image_scope'=>'none','images'=>array(),'reply_quote'=>'')
                    )),
                    'created_at'=>gmdate('Y-m-d H:i:s',$createdAt),
                ),
                array(
                    'id'=>2*$index,
                    'public_id'=>$assistant['id'],
                    'conversation_id'=>7,
                    'turn_id'=>$turnId,
                    'role'=>'assistant',
                    'outcome'=>Outcome::ANSWER,
                    'content'=>$assistant['text'],
                    'payload'=>Json::encodeObject(array('message'=>$assistant)),
                    'created_at'=>gmdate('Y-m-d H:i:s',$createdAt),
                ),
            );
        }
    }

    public function prepare(string $sql,...$args): string{
        if(count($args)===1 && is_array($args[0])){$args=array_values($args[0]);}
        $this->lastArgs=$args;
        return $sql;
    }

    public function get_results(string $sql,$format): array{
        if(strpos($sql,'_ysai_turns')!==false){
            $rows=array_values($this->turns);
            usort($rows,static function(array $left,array $right):int{return $right['id']<=>$left['id'];});
            return array_map(static function(array $row):array{
                return array('turn_id'=>$row['turn_id'],'status'=>$row['status']);
            },array_slice($rows,0,12));
        }
        if(strpos($sql,'_ysai_messages')!==false){
            $turnIds=array_slice($this->lastArgs,1);
            $rows=array();
            foreach($turnIds as $turnId){
                foreach($this->messages[(string)$turnId]??array() as $row){$rows[]=$row;}
            }
            usort($rows,static function(array $left,array $right):int{return $left['id']<=>$right['id'];});
            return $rows;
        }
        return array();
    }

    public function get_row(string $sql,$format){
        if(strpos($sql,'_ysai_turns')!==false){
            ++$this->exactTurnReads;
            $turnId=strtolower((string)($this->lastArgs[1]??''));
            $turn=$this->turns[$turnId]??null;
            return is_array($turn)
                ? array('turn_id'=>$turn['turn_id'],'status'=>$turn['status'])
                : null;
        }
        return null;
    }

    public function targetTurnId(): string{return $this->targetTurnId;}

    /** @return array<string,mixed> */
    public function targetAssistant(): array{
        $payload=Json::decodeRequiredObject((string)$this->messages[$this->targetTurnId][1]['payload'],'Test payload');
        return $payload['message'];
    }

    private function uuid(int $value): string{
        return sprintf('00000000-0000-4000-8000-%012x',$value);
    }
}

final class MutableSchemaDatabase {
    public $last_error=''; public $queries=array(); public $dbDeltaStatements=array();
    public $prefix; public $charset; public $collate;
    public $metadataAtFirstDdl=null; public $failDbDeltaAt=0;
    public $metadataReadFailuresRemaining=0; public $metadataReadError='Transient metadata read failure.';
    public $canaryResult=1; public $canaryError=''; public $canaryFailuresRemaining=0;
    public $liveAssistantLease=false;
    private $definition; private $tables;
    public function __construct(SchemaDefinition $definition,array $tables,string $prefix='wp_'){
        $this->definition=$definition;$this->tables=$tables;$this->prefix=$prefix;
        $this->charset='utf8mb4';$this->collate=$definition->collation();
    }
    public function prepare(string $sql,...$args): string{return $sql;}
    public function get_col(string $sql): array {
        $this->queries[]=$sql;
        if(strpos($sql,'information_schema.TABLES')!==false){return array_keys($this->tables);}
        return array();
    }
    public function get_var($sql){
        $this->queries[]=(string)$sql;
        if(strpos((string)$sql,'SELECT resource_hash FROM ')===0
            && strpos((string)$sql,'_ysai_leases')!==false
            && strpos((string)$sql,'lease_until > %s')!==false
        ){
            return $this->liveAssistantLease?str_repeat('a',64):null;
        }
        if(strpos((string)$sql,'ysai_schema_canary')!==false){
            if($this->canaryFailuresRemaining>0){--$this->canaryFailuresRemaining;$this->last_error=$this->canaryError!==''?$this->canaryError:'Transient schema canary failure.';return null;}
            $this->last_error=$this->canaryError;
            return $this->canaryResult;
        }
        return 1;
    }
    public function get_results(string $sql,$format): array {
        $this->queries[]=$sql;
        if(strpos($sql,'information_schema.')!==false && $this->metadataReadFailuresRemaining>0){--$this->metadataReadFailuresRemaining;$this->last_error=$this->metadataReadError;return array();}
        if(strpos($sql,'information_schema.TABLES')!==false){$rows=array();foreach($this->tables as $name=>$table){$rows[]=array('TABLE_NAME'=>$name,'ENGINE'=>$table['engine'],'TABLE_COLLATION'=>$table['collation']);}return $rows;}
        if(strpos($sql,'information_schema.COLUMNS')!==false){$rows=array();foreach($this->tables as $name=>$table){$position=0;foreach($table['columns'] as $columnName=>$column){$rows[]=array('TABLE_NAME'=>$name,'COLUMN_NAME'=>$columnName,'COLUMN_TYPE'=>$column['type'],'IS_NULLABLE'=>$column['nullable']?'YES':'NO','COLUMN_DEFAULT'=>$column['default'],'EXTRA'=>$column['extra'],'CHARACTER_SET_NAME'=>$column['charset'],'COLLATION_NAME'=>$column['collation'],'ORDINAL_POSITION'=>++$position);}}return $rows;}
        if(strpos($sql,'information_schema.STATISTICS')!==false){$rows=array();foreach($this->tables as $name=>$table){foreach($table['indexes'] as $indexName=>$index){foreach($index['columns'] as $position=>$columnName){$rows[]=array('TABLE_NAME'=>$name,'INDEX_NAME'=>$indexName,'NON_UNIQUE'=>$index['unique']?0:1,'COLUMN_NAME'=>$columnName,'SUB_PART'=>$index['prefixes'][$position]??null,'INDEX_TYPE'=>$index['type'],'SEQ_IN_INDEX'=>$position+1);}}}return $rows;}
        return array();
    }
    public function query(string $sql){
        $this->queries[]=$sql;
        if(preg_match('/^DROP TABLE IF EXISTS `([^`]+)`$/',$sql,$m)){
            if($this->metadataAtFirstDdl===null){$this->metadataAtFirstDdl=array('version'=>get_option(SchemaLifecycle::SCHEMA_OPTION,''),'status'=>get_option(SchemaLifecycle::SCHEMA_STATUS_OPTION,array()));}
            unset($this->tables[$m[1]]);return 1;
        }
        if(!preg_match('/^ALTER TABLE `([^`]+)` (.+)$/',$sql,$m)){return 1;}
        $table=$m[1];$operation=$m[2];
        if(!isset($this->tables[$table])){$this->last_error='missing table';return false;}
        if(preg_match('/^DROP INDEX `([^`]+)`$/',$operation,$x)){unset($this->tables[$table]['indexes'][$x[1]]);return 1;}
        if(preg_match('/^DROP COLUMN `([^`]+)`$/',$operation,$x)){unset($this->tables[$table]['columns'][$x[1]]);return 1;}
        if(preg_match('/^DROP PRIMARY KEY, ADD PRIMARY KEY \(([^)]+)\)$/',$operation,$x)){$columns=explode(',',$x[1]);$this->tables[$table]['indexes']['PRIMARY']=array('unique'=>true,'type'=>'BTREE','columns'=>$columns,'prefixes'=>array_fill(0,count($columns),null));return 1;}
        if(preg_match('/^MODIFY COLUMN ([A-Za-z0-9_]+) /',$operation,$x)){$expected=schemaTableByNameForTest($this->definition,$table);$this->tables[$table]['columns'][$x[1]]=$expected['columns'][$x[1]];return 1;}
        if(preg_match('/^ENGINE=([A-Za-z0-9_]+)$/',$operation,$x)){$this->tables[$table]['engine']=$x[1];return 1;}
        if(preg_match('/^CONVERT TO CHARACTER SET ([A-Za-z0-9_]+)(?: COLLATE ([A-Za-z0-9_]+))?$/',$operation,$x)){$this->tables[$table]['collation']=$x[2]??'';foreach($this->tables[$table]['columns'] as &$column){if($column['charset']!==null){$column['charset']=$x[1];$column['collation']=$x[2]??null;}}unset($column);return 1;}
        return 1;
    }
    public function applyDbDelta(string $statement): void {
        if($this->metadataAtFirstDdl===null){$this->metadataAtFirstDdl=array('version'=>get_option(SchemaLifecycle::SCHEMA_OPTION,''),'status'=>get_option(SchemaLifecycle::SCHEMA_STATUS_OPTION,array()));}
        $this->dbDeltaStatements[]=$statement;
        if($this->failDbDeltaAt>0 && count($this->dbDeltaStatements)===$this->failDbDeltaAt){throw new RuntimeException('Injected dbDelta failure.');}
        if(!preg_match('/^CREATE TABLE ([A-Za-z0-9_]+)/',$statement,$m)){throw new RuntimeException('Bad test CREATE statement.');}
        $name=$m[1];$expected=schemaTableByNameForTest($this->definition,$name);
        if(!isset($this->tables[$name])){$this->tables[$name]=array('engine'=>$this->definition->engine(),'collation'=>$this->definition->collation(),'columns'=>$expected['columns'],'indexes'=>$expected['indexes']);return;}
        foreach($expected['columns'] as $columnName=>$column){if(!isset($this->tables[$name]['columns'][$columnName])){$this->tables[$name]['columns'][$columnName]=$column;}}
        foreach($expected['indexes'] as $indexName=>$index){if(!isset($this->tables[$name]['indexes'][$indexName])){$this->tables[$name]['indexes'][$indexName]=$index;}}
    }
    public function inspection(): SchemaInspection{return new SchemaInspection($this->tables);}
}
final class ThrowingGatewayTransport implements GeminiTransportInterface {
    private $exception;
    public function __construct(GeminiException $exception){$this->exception=$exception;}
    public function generate(array $payload): array {throw $this->exception;}
}

final class QueueTransport implements GeminiTimeoutTransportInterface {
    public $payloads = array(); public $timeouts=array(); private $responses;
    public function __construct(array $responses) { $this->responses = $responses; }
    public function generate(array $payload): array {
        $this->payloads[] = $payload;
        if ($this->responses === array()) { throw new RuntimeException('No queued response.'); }
        return array_shift($this->responses);
    }
    public function generateWithTimeout(array $payload,int $timeoutSeconds): array {
        $this->timeouts[]=$timeoutSeconds;
        return $this->generate($payload);
    }
}

final class UntimedQueueTransport implements GeminiTransportInterface {
    public $generateCalls=0;
    /** @var array<int,array<string,mixed>> */ private $responses;
    public function __construct(array $responses){$this->responses=$responses;}
    public function generate(array $payload): array {
        unset($payload); ++$this->generateCalls;
        if($this->responses===array()){throw new RuntimeException('No queued response.');}
        return array_shift($this->responses);
    }
}

final class TimedQueueTransport implements GeminiTimeoutTransportInterface {
    public $payloads=array(); public $timeouts=array(); private $responses;
    public function __construct(array $responses){$this->responses=$responses;}
    public function generate(array $payload): array {
        unset($payload);
        throw new RuntimeException('The readiness probe bypassed its bounded timeout.');
    }
    public function generateWithTimeout(array $payload,int $timeoutSeconds): array {
        $this->payloads[]=$payload;
        $this->timeouts[]=$timeoutSeconds;
        if($this->responses===array()){throw new RuntimeException('No queued response.');}
        return array_shift($this->responses);
    }
}

final class CallbackTimedTransport implements GeminiTimeoutTransportInterface {
    public $payloads=array(); public $timeouts=array(); private $callback;
    public function __construct(callable $callback){$this->callback=$callback;}
    public function generate(array $payload): array {
        unset($payload);
        throw new RuntimeException('The runtime probe bypassed its bounded timeout.');
    }
    public function generateWithTimeout(array $payload,int $timeoutSeconds): array {
        $this->payloads[]=$payload;
        $this->timeouts[]=$timeoutSeconds;
        $callback=$this->callback;
        $response=$callback($payload,count($this->payloads));
        if(!is_array($response)){throw new RuntimeException('Runtime probe callback returned an invalid response.');}
        return $response;
    }
}

final class FixedTextLocalizer implements TextLocalizerPort {
    public function __construct(bool $arabic=true){}
    public function text(string $arabic): string{return $arabic;}
}
final class MemoryProductCatalog implements ProductCatalogPort {
    /** @var array<int,array<string,mixed>> */ private $products;
    /** @var array<int,array<string,mixed>> */ private $variations;
    /** @param array<int,array<string,mixed>> $products @param array<int,array<string,mixed>> $variations */
    public function __construct(array $products=array(),array $variations=array()){
        $this->products=array();
        foreach($products as $row){$this->products[(int)($row['id']??0)]=$row;}
        $this->variations=array();
        foreach($variations as $row){$this->variations[(int)($row['id']??0)]=$row;}
    }
    public function discover(array $args): array{unset($args);return array_values($this->products);}
    public function getBySku(string $sku): array{unset($sku);throw new SafeCommerceException('product_not_found','غير متاح.');}
    public function get(int $productId): array{
        if(!isset($this->products[$productId])){throw new SafeCommerceException('product_not_found','غير متاح.');}
        return $this->products[$productId];
    }
    public function getVariation(int $variationId,int $expectedParentId=0): array{
        $row=$this->variations[$variationId]??null;
        if(!is_array($row)||($expectedParentId>0&&(int)($row['parent_id']??0)!==$expectedParentId)){
            throw new SafeCommerceException('variation_not_found','غير متاح.');
        }
        return $row;
    }
    public function variationCatalog(int $productId): array{
        $items=array_values(array_filter($this->variations,static function(array $row)use($productId):bool{
            return (int)($row['parent_id']??0)===$productId;
        }));
        return array('items'=>$items,'total'=>count($items),'authority_epoch'=>hash('sha256',Json::encode($items)));
    }
    public function related(int $productId,int $limit=6): array{unset($productId,$limit);return array();}
    public function alternatives(int $productId,array $args): array{unset($productId,$args);return array();}
    public function categories(array $args): array{unset($args);return array();}
}
final class FixedClock implements ClockPort {
    /** @var int */ private $now;
    public function __construct(int $now=1700000000){$this->now=$now;}
    public function now(): int{return $this->now;}
}
final class MutableClock implements ClockPort {
    /** @var int */ private $now;
    public function __construct(int $now=1700000000){$this->now=$now;}
    public function now(): int{return $this->now;}
    public function set(int $now): void{$this->now=$now;}
    public function advance(int $seconds): void{$this->now+=$seconds;}
}
final class FixedCartIntentVerifier implements CartIntentVerifierPort {
    /** @var bool */ private $authorized;
    /** @var string */ private $reason;
    /** @var array<int,CartIntentVerificationRequest> */ public $requests=array();
    public function __construct(bool $authorized=true,string $reason=CartIntentVerdict::AUTHORIZED){
        $this->authorized=$authorized;
        $this->reason=$authorized?CartIntentVerdict::AUTHORIZED:$reason;
    }
    public function verify(
        CartIntentVerificationRequest $request,
        ?TurnExecutionSupervisor $supervisor=null
    ): CartIntentVerdict {
        $this->requests[]=$request;
        return $this->authorized
            ? CartIntentVerdict::allow()
            : CartIntentVerdict::deny($this->reason);
    }
}
final class SequenceClock implements ClockPort {
    /** @var array<int,int> */ private $times;
    /** @var int */ public $calls=0;
    /** @param array<int,int> $times */
    public function __construct(array $times){
        if($times===array()){throw new InvalidArgumentException('Clock sequence cannot be empty.');}
        $this->times=array_values($times);
    }
    public function now(): int{
        ++$this->calls;
        if(count($this->times)>1){return (int)array_shift($this->times);}
        return (int)$this->times[0];
    }
}
final class PassthroughTransaction implements TransactionPort {
    public $runs=0;
    public function run(callable $callback){++$this->runs;return $callback();}
}
final class ImmediateMaintenanceGate implements MaintenanceGatePort {
    public $calls=0;
    public function run(callable $critical){++$this->calls;return $critical();}
}
final class AdmissionConversationStore implements \YassinStore\AiAssistant\Application\Port\ConversationStorePort {
    public $conversation; public $writtenState=null;
    public function __construct(array $conversation){$this->conversation=$conversation;}
    public function reload(int $conversationId): ?array{return $this->conversation;}
    public function reloadForUpdate(int $conversationId): ?array{return $this->conversation;}
    public function writeState(int $conversationId,array $state): void{$this->writtenState=$state;}
}
final class AdmissionMessageStore implements \YassinStore\AiAssistant\Application\Port\MessageStorePort {
    public $userWrites=0; public $assistantWrites=0; public $lastUserContent=''; public $lastUserPayload=array(); public $lastAssistantPayload=array();
    public function appendUserMessage(array $conversation,string $turnId,string $content,array $payload=array()): array{++$this->userWrites;$this->lastUserContent=$content;$this->lastUserPayload=$payload;return array();}
    public function appendAssistantMessage(array $conversation,string $turnId,string $outcome,string $content,array $payload): array{++$this->assistantWrites;$this->lastAssistantPayload=$payload;return array('payload'=>$payload);}
    public function modelHistory(int $conversationId,int $turnLimit,string $excludeTurnId=''): array{return array();}
    public function quotedProduct(int $conversationId,string $messageId,int $productIndex,string $quote): ?array{return null;}
}
final class AdmissionTurnStore implements TurnStorePort {
    public $existing; public $reserveCalls=0; public $completed=null;
    public function __construct(?TurnRecord $existing=null){$this->existing=$existing;}
    public function find(int $conversationId,string $turnId): ?TurnRecord{return $this->existing;}
    public function findActive(int $conversationId): ?TurnRecord{return null;}
    public function reserve(int $conversationId,string $turnId,string $requestHash,array $input): \YassinStore\AiAssistant\Domain\Chat\TurnReservation{++$this->reserveCalls;throw new RuntimeException('Reserve must not be called in this admission test.');}
    public function claim(TurnRecord $turn,int $fence): TurnRecord{throw new RuntimeException('Not used.');}
    public function assertClaimedForUpdate(int $turnId,int $fence): TurnRecord{
        if(!$this->existing instanceof TurnRecord||$this->existing->id()!==$turnId||$this->existing->leaseFence()!==$fence){throw new RuntimeException('Unexpected claimed turn.');}
        return $this->existing;
    }
    public function complete(int $turnId,int $fence,string $status,array $response,string $failureCode): void{$this->completed=array('turn_id'=>$turnId,'fence'=>$fence,'status'=>$status,'response'=>$response,'failure_code'=>$failureCode);}
}
final class AdmissionRateLimiter implements \YassinStore\AiAssistant\Application\Port\RateLimiterPort {
    public $calls=0; private $allowed;
    public function __construct(bool $allowed){$this->allowed=$allowed;}
    public function consume(string $sessionHash,string $ip): array{++$this->calls;return array('allowed'=>$this->allowed,'retry_after'=>$this->allowed?0:60);}
}
final class FixedFingerprint implements \YassinStore\AiAssistant\Application\Port\FingerprintPort {
    public function digest(string $purpose,string $value): string{return hash('sha256',$purpose."\0".$value);}
}
/** @param array<string,int> $counts */
function ingressAdmitterForTest(array &$counts): callable {
    return static function(array $buckets,int $window,int $timestamp) use (&$counts): array {
        $windowId=intdiv($timestamp,$window);
        $retry=max(1,(($windowId+1)*$window)-$timestamp);
        foreach($buckets as $bucket){
            $identity=(string)($bucket['identity']??'');
            $limit=(int)($bucket['limit']??0);
            $key=$identity.'|'.$windowId;
            if((int)($counts[$key]??0)>=$limit){
                return array('allowed'=>false,'retry_after'=>$retry);
            }
        }
        foreach($buckets as $bucket){
            $key=(string)($bucket['identity']??'').'|'.$windowId;
            $counts[$key]=(int)($counts[$key]??0)+1;
        }
        return array('allowed'=>true,'retry_after'=>0);
    };
}

final class QueuedModelSession implements ModelSessionInterface {
    /** @var array<int,ModelStep> */ private $steps;
    /** @var array<int,array{step:ModelStep,feedback:array<int,FunctionFeedback>}> */ public $submissions=array();
    /** @var array<int,array{step:ModelStep,instruction:string}> */ public $corrections=array();
    public function __construct(array $steps){$this->steps=array_values($steps);}
    public function next(): ModelStep {
        if($this->steps===array()){throw new RuntimeException('No queued model step.');}
        return array_shift($this->steps);
    }
    public function submit(ModelStep $step,array $feedback): void {$this->submissions[]=array('step'=>$step,'feedback'=>$feedback);}
    public function correctPlainOutput(ModelStep $step,string $instruction): void {$this->corrections[]=array('step'=>$step,'instruction'=>$instruction);}
}
final class RecordingToolHandler implements ToolHandlerInterface {
    /** @var ToolContract */ private $contract;
    /** @var ToolExecutionResult */ private $result;
    /** @var bool */ private $recordMutationFailure;
    /** @var int */ public $calls=0;
    /** @param array<string,mixed>|null $schema */
    public function __construct(
        string $name,
        string $kind,
        ToolExecutionResult $result,
        ?array $schema=null,
        bool $recordMutationFailure=false
    ){
        $this->contract=new ToolContract(
            $name,'Test handler.',$schema!==null?$schema:ToolSchemas::emptyObject(),$kind
        );
        $this->result=$result;
        $this->recordMutationFailure=$recordMutationFailure;
    }
    public function contract(): ToolContract{return $this->contract;}
    public function execute(array $arguments,AgentContext $context): ToolExecutionResult{
        ++$this->calls;
        if($this->result->receipt()!==null){
            $context->effects()->recordReceipt($this->result->receipt());
        }elseif($this->recordMutationFailure){
            $context->effects()->recordMutationFailure(
                $this->result->code(),$this->result->safeMessage()
            );
        }
        return $this->result;
    }
}


final class RateLimitDatabase
{
    public $prefix = 'rl_';
    public $charset = 'utf8mb4';
    public $collate = 'utf8mb4_unicode_ci';
    public $last_error = '';
    /** @var array<string,array{bucket_hash:string,request_count:int,reset_at:string}> */
    public $rows = array();
    /** @var array<string,array{bucket_hash:string,request_count:int,reset_at:string}>|null */
    private $snapshot = null;

    public function prepare(string $query, ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        return 'YSAI_PREPARED:' . base64_encode(serialize(array($query, $args)));
    }

    public function query(string $query)
    {
        if ($query === 'START TRANSACTION') {
            $this->snapshot = $this->rows;
            return 1;
        }
        if ($query === 'COMMIT') {
            $this->snapshot = null;
            return 1;
        }
        if ($query === 'ROLLBACK') {
            if ($this->snapshot !== null) {
                $this->rows = $this->snapshot;
            }
            $this->snapshot = null;
            return 1;
        }
        if (strpos($query, 'SAVEPOINT ') === 0
            || strpos($query, 'RELEASE SAVEPOINT ') === 0
            || strpos($query, 'ROLLBACK TO SAVEPOINT ') === 0
        ) {
            return 1;
        }
        list($sql, $args) = $this->decode($query);
        if (strpos($sql, 'INSERT IGNORE INTO ') === 0) {
            $hash = (string) ($args[0] ?? '');
            if (!isset($this->rows[$hash])) {
                $this->rows[$hash] = array(
                    'bucket_hash' => $hash,
                    'request_count' => 0,
                    'reset_at' => (string) ($args[2] ?? ''),
                );
                return 1;
            }
            return 0;
        }
        if (strpos($sql, 'UPDATE ') === 0) {
            $hash = (string) ($args[4] ?? '');
            if (!isset($this->rows[$hash])) {
                return 0;
            }
            $this->rows[$hash]['request_count'] = (int) ($args[1] ?? 0);
            $this->rows[$hash]['reset_at'] = (string) ($args[2] ?? '');
            return 1;
        }
        throw new RuntimeException('Unexpected rate-limit query: ' . $sql);
    }

    public function get_row(string $query, $output)
    {
        list($sql, $args) = $this->decode($query);
        if (strpos($sql, 'SELECT bucket_hash,request_count,reset_at FROM ') !== 0) {
            throw new RuntimeException('Unexpected rate-limit read: ' . $sql);
        }
        $hash = (string) ($args[0] ?? '');
        return $this->rows[$hash] ?? null;
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function decode(string $query): array
    {
        if (strpos($query, 'YSAI_PREPARED:') !== 0) {
            throw new RuntimeException('Expected a prepared rate-limit query.');
        }
        $decoded = unserialize((string) base64_decode(substr($query, 14), true));
        if (!is_array($decoded) || count($decoded) !== 2 || !is_string($decoded[0]) || !is_array($decoded[1])) {
            throw new RuntimeException('Prepared rate-limit query is invalid.');
        }
        return array($decoded[0], array_values($decoded[1]));
    }
}

final class IngressOptionDatabase
{
    public $options='wp_options';
    public $last_error='';
    public $engine='InnoDB';
    /** @var array<string,string> */ public $rows=array();
    /** @var array<int,array{sql:string,args:array<int,mixed>}> */ public $writes=array();
    /** @var array<string,string>|null */ private $snapshot=null;

    public function prepare(string $query,...$args): string
    {
        if(count($args)===1 && is_array($args[0])){$args=$args[0];}
        return 'YSAI_PREPARED:'.base64_encode(serialize(array($query,array_values($args))));
    }

    public function query(string $query)
    {
        if($query==='START TRANSACTION'){$this->snapshot=$this->rows;return 1;}
        if($query==='COMMIT'){$this->snapshot=null;return 1;}
        if($query==='ROLLBACK'){
            if($this->snapshot!==null){$this->rows=$this->snapshot;}
            $this->snapshot=null;
            return 1;
        }

        [$sql,$args]=$this->decode($query);
        $this->writes[]=array('sql'=>$sql,'args'=>$args);
        if(strpos($sql,'DELETE FROM wp_options')===0){
            $prefix=(string)($args[0]??'');
            $cutoff=(int)($args[1]??0);
            $limit=(int)($args[2]??0);
            $deleted=0;
            foreach(array_keys($this->rows) as $name){
                if($deleted>=$limit || substr($name,0,strlen($prefix))!==$prefix){continue;}
                $parts=explode(':',(string)$this->rows[$name]);
                if((int)($parts[2]??0)>=$cutoff){continue;}
                unset($this->rows[$name]);
                ++$deleted;
            }
            return $deleted;
        }
        if(strpos($sql,'INSERT IGNORE INTO wp_options ')===0){
            $name=(string)($args[0]??'');
            if(!isset($this->rows[$name])){
                $this->rows[$name]=(string)($args[1]??'');
                return 1;
            }
            return 0;
        }
        if(strpos($sql,'UPDATE wp_options SET option_value = ')===0){
            $name=(string)($args[1]??'');
            if(!isset($this->rows[$name])){return 0;}
            $this->rows[$name]=(string)($args[0]??'');
            return 1;
        }
        throw new RuntimeException('Unexpected ingress write: '.$sql);
    }

    public function get_var(string $query)
    {
        [$sql,$args]=$this->decode($query);
        if(strpos($sql,'SELECT ENGINE FROM information_schema.TABLES')===0){
            return (string)$this->engine;
        }
        if(strpos($sql,'SELECT option_value FROM wp_options ')!==0 || strpos($sql,'FOR UPDATE')===false){
            throw new RuntimeException('Unexpected ingress read.');
        }
        return $this->rows[(string)($args[0]??'')]??null;
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function decode(string $query): array
    {
        if(strpos($query,'YSAI_PREPARED:')!==0){throw new RuntimeException('Expected a prepared ingress query.');}
        $decoded=unserialize((string)base64_decode(substr($query,14),true));
        if(!is_array($decoded)||count($decoded)!==2||!is_string($decoded[0])||!is_array($decoded[1])){
            throw new RuntimeException('Prepared ingress query is invalid.');
        }
        return array($decoded[0],array_values($decoded[1]));
    }
}

final class BoundedConversationCleanupDatabase
{
    public $prefix='cleanup_'; public $charset='utf8mb4'; public $collate='utf8mb4_unicode_ci'; public $last_error='';
    public $reads=array(); public $scalarReads=array(); public $writes=array(); public $queries=array();
    public $liveConversation=false; public $liveCommerce=false;
    /** @var int */ private $stageSize;
    /** @var int */ private $stageNumber=0;
    /** @var int */ private $delayMicros;
    /** @var bool */ private $overflow;

    public function __construct(
        int $stageSize=3,
        int $delayMicros=0,
        bool $overflow=false,
        bool $liveConversation=false,
        bool $liveCommerce=false
    ){
        $this->stageSize=max(0,$stageSize);
        $this->delayMicros=max(0,$delayMicros);
        $this->overflow=$overflow;
        $this->liveConversation=$liveConversation;
        $this->liveCommerce=$liveCommerce;
    }

    public function prepare(string $query,...$args): string {
        if(count($args)===1 && is_array($args[0])){$args=$args[0];}
        return 'YSAI_PREPARED:'.base64_encode(serialize(array($query,array_values($args))));
    }

    public function query(string $query){
        $this->queries[]=$query;
        if(in_array($query,array('START TRANSACTION','COMMIT','ROLLBACK'),true)){return 1;}
        [$sql,$args]=$this->decode($query);
        $this->writes[]=array($sql,$args);
        if(strpos($sql,'DELETE FROM ')!==0){throw new RuntimeException('Unexpected bounded cleanup write: '.$sql);}
        return count($args);
    }

    public function get_results(string $query,$format): array {
        [$sql,$args]=$this->decode($query);
        $this->reads[]=array($sql,$args);
        if(strpos($sql,'SELECT id, public_id FROM cleanup_ysai_conversations')===0){
            return array(array('id'=>7,'public_id'=>'11111111-1111-4111-8111-111111111111'));
        }
        if(strpos($sql,'SELECT c.id, c.public_id FROM cleanup_ysai_conversations c')===0){
            return array(array('id'=>7,'public_id'=>'11111111-1111-4111-8111-111111111111'));
        }
        throw new RuntimeException('Unexpected bounded cleanup row read: '.$sql);
    }

    public function get_var(string $query){
        [$sql,$args]=$this->decode($query);
        $this->scalarReads[]=array($sql,$args);
        if(strpos($sql,'SELECT resource_hash FROM ')===0&&strpos($sql,'_ysai_leases')!==false){
            return $this->liveConversation?hash('sha256',(string)($args[1]??'')):null;
        }
        if(strpos($sql,'SELECT l.resource_hash FROM ')===0&&strpos($sql,'status IN')!==false){
            return $this->liveCommerce?str_repeat('b',64):null;
        }
        throw new RuntimeException('Unexpected bounded cleanup scalar read: '.$sql);
    }

    public function get_col(string $query): array {
        [$sql,$args]=$this->decode($query);
        $this->reads[]=array($sql,$args);
        if(strpos($sql,'LIMIT %d FOR UPDATE')===false){
            throw new RuntimeException('Bounded cleanup child read has no locking limit.');
        }
        $limit=(int)($args[count($args)-1]??0);
        if($limit<1){throw new RuntimeException('Bounded cleanup child limit is invalid.');}
        ++$this->stageNumber;
        if($this->delayMicros>0){usleep($this->delayMicros);}
        $count=$this->overflow?$limit+1:min($limit,$this->stageSize);
        $base=$this->stageNumber*1000;
        $ids=array();
        for($i=1;$i<=$count;++$i){$ids[]=$base+$i;}
        return $ids;
    }

    private function decode(string $query): array {
        if(strpos($query,'YSAI_PREPARED:')!==0){throw new RuntimeException('Expected a prepared cleanup query.');}
        $decoded=unserialize((string)base64_decode(substr($query,14),true));
        if(!is_array($decoded)||count($decoded)!==2){throw new RuntimeException('Prepared cleanup query is invalid.');}
        return array((string)$decoded[0],array_values((array)$decoded[1]));
    }
}

final class ConversationResumeDatabase
{
    public $prefix='resume_'; public $charset='utf8mb4'; public $collate='utf8mb4_unicode_ci'; public $last_error='';
    public $row; public $queries=array(); public $reads=array(); public $updates=array();
    public function __construct(array $row){$this->row=$row;}
    public function prepare(string $query,...$args): string {
        if(count($args)===1 && is_array($args[0])){$args=$args[0];}
        return 'YSAI_PREPARED:'.base64_encode(serialize(array($query,array_values($args))));
    }
    public function query(string $query){$this->queries[]=$query;return 1;}
    public function get_row(string $query,$format){
        [$sql,$args]=$this->decode($query);$this->reads[]=array($sql,$args);
        if(strpos($sql,'SELECT * FROM ')!==0 || strpos($sql,'FOR UPDATE')===false){throw new RuntimeException('Unexpected resume read.');}
        if((string)($args[0]??'')!==(string)$this->row['public_id']
            || (string)($args[1]??'')!==(string)$this->row['access_hash']
            || (string)($args[2]??'')!==(string)$this->row['session_hash']){return null;}
        return $this->row;
    }
    public function update($table,array $data,array $where,array $formats,array $whereFormats){
        $this->updates[]=array($table,$data,$where,$formats,$whereFormats);
        if((int)($where['id']??0)!==(int)$this->row['id']){return 0;}
        foreach($data as $key=>$value){$this->row[$key]=$value;}
        return 1;
    }
    private function decode(string $query): array {
        if(strpos($query,'YSAI_PREPARED:')!==0){throw new RuntimeException('Expected a prepared resume query.');}
        $decoded=unserialize((string)base64_decode(substr($query,14),true));
        if(!is_array($decoded)||count($decoded)!==2){throw new RuntimeException('Prepared resume query is invalid.');}
        return array((string)$decoded[0],array_values((array)$decoded[1]));
    }
}

final class ConversationBootDatabase
{
    public $prefix='boot_conversation_'; public $charset='utf8mb4'; public $collate='utf8mb4_unicode_ci';
    public $last_error=''; public $insert_id=0; public $rows=array(); public $queries=array();
    public $reads=array(); public $inserts=array(); public $updates=array();
    public $liveConversation=false; public $liveCommerce=false;
    public function prepare(string $query,...$args): string {
        if(count($args)===1 && is_array($args[0])){$args=$args[0];}
        return 'YSAI_PREPARED:'.base64_encode(serialize(array($query,array_values($args))));
    }
    public function query(string $query){$this->queries[]=$query;return 1;}
    public function get_results(string $query,$format): array {
        [$sql,$args]=$this->decode($query);$this->reads[]=array($sql,$args);
        if(strpos($sql,'WHERE session_hash = %s')===false || strpos($sql,'LIMIT 2 FOR UPDATE')===false){
            throw new RuntimeException('Unexpected browser conversation read.');
        }
        $session=(string)($args[0]??'');
        return array_values(array_filter($this->rows,static function(array $row)use($session):bool{
            return hash_equals((string)$row['session_hash'],$session);
        }));
    }
    public function get_var(string $query){
        [$sql,$args]=$this->decode($query);
        if(strpos($sql,'SELECT resource_hash FROM ')===0&&strpos($sql,'_leases')!==false){
            return $this->liveConversation?hash('sha256',(string)($args[1]??'')):null;
        }
        if(strpos($sql,'SELECT l.resource_hash FROM ')===0&&strpos($sql,'status IN')!==false){
            return $this->liveCommerce?str_repeat('b',64):null;
        }
        throw new RuntimeException('Unexpected browser conversation scalar read.');
    }
    public function insert($table,array $data,array $formats){
        ++$this->insert_id;$data['id']=$this->insert_id;$this->rows[]=$data;
        $this->inserts[]=array($table,$data,$formats);return 1;
    }
    public function update($table,array $data,array $where,array $formats,array $whereFormats){
        $this->updates[]=array($table,$data,$where,$formats,$whereFormats);
        foreach($this->rows as &$row){
            if((int)$row['id']===(int)($where['id']??0)){foreach($data as $key=>$value){$row[$key]=$value;}unset($row);return 1;}
        }
        unset($row);return 0;
    }
    private function decode(string $query): array {
        if(strpos($query,'YSAI_PREPARED:')!==0){throw new RuntimeException('Expected a prepared browser conversation query.');}
        $decoded=unserialize((string)base64_decode(substr($query,14),true));
        if(!is_array($decoded)||count($decoded)!==2){throw new RuntimeException('Prepared browser conversation query is invalid.');}
        return array((string)$decoded[0],array_values((array)$decoded[1]));
    }
}

final class ConversationPrivacyDatabase
{
    public $prefix='privacy_'; public $charset='utf8mb4'; public $collate='utf8mb4_unicode_ci';
    public $last_error=''; public $row; public $messages; public $operations; public $turns; public $steps; public $attempts; public $queries=array();
    public $lockReads=array();
    public $liveConversation; public $liveCommerce;
    public function __construct(array $row,array $messages,array $operations,bool $liveConversation=false,bool $liveCommerce=false,array $turns=array(),array $steps=array(),array $attempts=array()){
        $this->row=$row;$this->messages=$messages;$this->operations=$operations;
        $this->turns=$turns;$this->steps=$steps;$this->attempts=$attempts;
        $this->liveConversation=$liveConversation;$this->liveCommerce=$liveCommerce;
    }
    public function prepare(string $query,...$args): string {
        if(count($args)===1 && is_array($args[0])){$args=$args[0];}
        return 'YSAI_PREPARED:'.base64_encode(serialize(array($query,array_values($args))));
    }
    public function query(string $query){$this->queries[]=$query;return 1;}
    public function get_row(string $query,$format){
        [$sql,$args]=$this->decode($query);
        if(strpos($sql,'SELECT * FROM ')!==0 || strpos($sql,'LIMIT 1 FOR UPDATE')===false
            || (int)($args[0]??0)!==(int)$this->row['id']){
            throw new RuntimeException('Unexpected privacy conversation read.');
        }
        $this->lockReads[]='conversation';
        return $this->row;
    }
    public function get_var(string $query){
        [$sql,$args]=$this->decode($query);
        if(strpos($sql,'SELECT resource_hash FROM ')===0 && strpos($sql,'_leases')!==false){
            $this->lockReads[]='conversation_lease';
            return $this->liveConversation?hash('sha256',(string)($args[1]??'')):null;
        }
        if(strpos($sql,'SELECT l.resource_hash FROM ')===0 && strpos($sql,'status IN')!==false){
            $this->lockReads[]='commerce_lease';
            return $this->liveCommerce?str_repeat('b',64):null;
        }
        if(strpos($sql,'COALESCE(MAX(')===false){throw new RuntimeException('Unexpected privacy scalar read.');}
        if(strpos($sql,'MAX(a.id)')!==false){$rows=$this->attempts;}
        elseif(strpos($sql,'MAX(s.id)')!==false){$rows=$this->steps;}
        elseif(strpos($sql,'_messages')!==false){$rows=$this->messages;}
        elseif(strpos($sql,'_turns')!==false){$rows=$this->turns;}
        elseif(strpos($sql,'o.status =')!==false){$rows=$this->verifiedOperationPrefix();}
        else{$rows=$this->operations;}
        $ids=array_map(static function(array $row):int{return (int)$row['id'];},$rows);
        return (string)($ids===array()?0:max($ids));
    }
    public function get_results(string $query,$format): array {
        [$sql,$args]=$this->decode($query);
        if(strpos($sql,'SELECT id,role,outcome,content,payload,created_at')===0){
            $after=(int)($args[1]??-1);$high=(int)($args[2]??-1);$limit=(int)($args[3]??0);
            $canonicalMessages=array_map(array($this,'completeMessage'),$this->messages);
            $rows=array_values(array_filter($canonicalMessages,static function(array $row)use($after,$high):bool{
                return (int)$row['id']>$after && (int)$row['id']<=$high;
            }));
        }elseif(strpos($sql,'SELECT id,receipt,completed_at')===0){
            $after=(int)($args[1]??-1);$high=(int)($args[2]??-1);$limit=(int)($args[4]??0);
            $rows=array_values(array_filter($this->verifiedOperations(),static function(array $row)use($after,$high):bool{
                return (int)$row['id']>$after && (int)$row['id']<=$high;
            }));
        }elseif(strpos($sql,'SELECT id,turn_id,status,input_payload')===0){
            $after=(int)($args[1]??-1);$high=(int)($args[2]??-1);$limit=(int)($args[3]??0);
            $rows=$this->boundedRows($this->turns,$after,$high);
        }elseif(strpos($sql,'SELECT id,public_id,turn_id,status,plan')===0){
            $after=(int)($args[1]??-1);$high=(int)($args[2]??-1);$limit=(int)($args[3]??0);
            $rows=$this->boundedRows(array_map(array($this,'completeOperation'),$this->operations),$after,$high);
        }elseif(strpos($sql,'SELECT s.id,s.public_id,o.public_id AS operation_public_id')===0){
            $after=(int)($args[1]??-1);$high=(int)($args[2]??-1);$limit=(int)($args[3]??0);
            $rows=$this->boundedRows($this->steps,$after,$high);
        }elseif(strpos($sql,'SELECT a.id,a.public_id,s.public_id AS step_public_id')===0){
            $after=(int)($args[1]??-1);$high=(int)($args[2]??-1);$limit=(int)($args[3]??0);
            $rows=$this->boundedRows($this->attempts,$after,$high);
        }else{throw new RuntimeException('Unexpected privacy page read: '.$sql);}
        usort($rows,static function(array $left,array $right):int{return (int)$left['id']<=>(int)$right['id'];});
        return array_slice($rows,0,$limit);
    }
    private function completeMessage(array $row): array{
        if(is_string($row['payload']??null)&&$row['payload']!==''){return $row;}
        $role=(string)($row['role']??'');
        $text=(string)($row['content']??'');
        $outcome=(string)($row['outcome']??'');
        $row['payload']=Json::encodeObject(privacyMessagePayloadForTest($role,$text,$outcome));
        return $row;
    }
    private function boundedRows(array $rows,int $after,int $high): array{
        return array_values(array_filter($rows,static function(array $row)use($after,$high):bool{
            return (int)$row['id']>$after&&(int)$row['id']<=$high;
        }));
    }
    public function completeOperation(array $row): array{
        $id=(int)($row['id']??0);$now=time();
        $pre=snapshot(array(),array(),0,0.0,'privacy-operation-'.$id);
        $plan=new CartPlan(array(cartAddCommandForTest(9,0,1,'منتج')));
        return array_replace(array(
            'id'=>$id,'public_id'=>sprintf('00000000-0000-4000-8000-%012d',$id),
            'turn_id'=>sprintf('10000000-0000-4000-8000-%012d',$id),'status'=>OperationStatus::VERIFIED,
            'plan'=>Json::encodeObject($plan->toStorageArray()),
            'pre_state'=>Json::encodeObject($pre->toStorageArray()),
            'applied_effects'=>null,'post_state'=>null,'receipt'=>null,
            'failure_code'=>'','safe_message'=>'','created_at'=>gmdate('Y-m-d H:i:s',$now-10),
            'updated_at'=>gmdate('Y-m-d H:i:s',$now-5),'completed_at'=>gmdate('Y-m-d H:i:s',$now-1),
        ),$row);
    }
    private function verifiedOperations(): array {
        return array_values(array_filter($this->operations,static function(array $row):bool{
            return ($row['status']??'')===OperationStatus::VERIFIED && is_string($row['receipt']??null);
        }));
    }
    private function verifiedOperationPrefix(): array {
        $firstNonterminal=null;
        foreach($this->operations as $row){
            if(in_array(($row['status']??''),array(OperationStatus::PREPARED,OperationStatus::EXECUTING),true)){
                $id=(int)($row['id']??0);
                if($id>0&&($firstNonterminal===null||$id<$firstNonterminal)){$firstNonterminal=$id;}
            }
        }
        return array_values(array_filter($this->verifiedOperations(),static function(array $row)use($firstNonterminal):bool{
            return $firstNonterminal===null||(int)$row['id']<$firstNonterminal;
        }));
    }
    private function decode(string $query): array {
        if(strpos($query,'YSAI_PREPARED:')!==0){throw new RuntimeException('Expected a prepared privacy query.');}
        $decoded=unserialize((string)base64_decode(substr($query,14),true));
        if(!is_array($decoded)||count($decoded)!==2){throw new RuntimeException('Prepared privacy query is invalid.');}
        return array((string)$decoded[0],array_values((array)$decoded[1]));
    }
}

final class ConversationPurgeDatabase
{
    public $prefix='purge_'; public $charset='utf8mb4'; public $collate='utf8mb4_unicode_ci';
    public $last_error=''; public $queries=array(); public $live;
    public function __construct(bool $live){$this->live=$live;}
    public function prepare(string $query,...$args): string {
        if(count($args)===1&&is_array($args[0])){$args=$args[0];}
        return 'YSAI_PREPARED:'.base64_encode(serialize(array($query,array_values($args))));
    }
    public function get_var(string $query){
        [$sql]=$this->decode($query);
        if(strpos($sql,'SELECT resource_hash FROM ')!==0||strpos($sql,'lease_until > %s')===false){
            throw new RuntimeException('Unexpected purge active-work read.');
        }
        return $this->live?str_repeat('a',64):null;
    }
    public function query(string $query){$this->queries[]=$query;return 1;}
    private function decode(string $query): array {
        if(strpos($query,'YSAI_PREPARED:')!==0){throw new RuntimeException('Expected a prepared purge query.');}
        $decoded=unserialize((string)base64_decode(substr($query,14),true));
        if(!is_array($decoded)||count($decoded)!==2){throw new RuntimeException('Prepared purge query is invalid.');}
        return array((string)$decoded[0],array_values((array)$decoded[1]));
    }
}

final class LeaseRetirementDatabase
{
    public $prefix='lease_retire_'; public $charset='utf8mb4'; public $collate='utf8mb4_unicode_ci'; public $last_error='';
    public $hashes; public $reads=array(); public $writes=array();
    public function __construct(array $hashes){$this->hashes=$hashes;}
    public function prepare(string $query,...$args): string {
        if(count($args)===1 && is_array($args[0])){$args=$args[0];}
        return 'YSAI_PREPARED:'.base64_encode(serialize(array($query,array_values($args))));
    }
    public function get_col(string $query): array {[$sql,$args]=$this->decode($query);$this->reads[]=array($sql,$args);return $this->hashes;}
    public function query(string $query){[$sql,$args]=$this->decode($query);$this->writes[]=array($sql,$args);return count($this->hashes);}
    private function decode(string $query): array {
        if(strpos($query,'YSAI_PREPARED:')!==0){throw new RuntimeException('Expected a prepared lease query.');}
        $decoded=unserialize((string)base64_decode(substr($query,14),true));
        if(!is_array($decoded)||count($decoded)!==2){throw new RuntimeException('Prepared lease query is invalid.');}
        return array((string)$decoded[0],array_values((array)$decoded[1]));
    }
}

$tests = array();
$assertions = 0;
function test(string $name, callable $fn): void { global $tests; $tests[] = array($name, $fn); }
function ok($condition, string $message = 'Assertion failed'): void { global $assertions; ++$assertions; if (!$condition) { throw new TestFailure($message); } }
function same($expected, $actual, string $message = ''): void { global $assertions; ++$assertions; if ($expected !== $actual) { throw new TestFailure($message !== '' ? $message : 'Expected ' . var_export($expected,true) . ', got ' . var_export($actual,true)); } }
function contains(string $needle, string $haystack, string $message = ''): void { global $assertions; ++$assertions; if (strpos($haystack, $needle) === false) { throw new TestFailure($message !== '' ? $message : 'Missing substring: ' . $needle); } }
function notContains(string $needle, string $haystack, string $message = ''): void { global $assertions; ++$assertions; if (strpos($haystack, $needle) !== false) { throw new TestFailure($message !== '' ? $message : 'Unexpected substring: ' . $needle); } }
function throws(string $class, callable $fn, ?string $reason = null): void {
    global $assertions; ++$assertions;
    try { $fn(); } catch (Throwable $e) {
        if (!$e instanceof $class) { throw new TestFailure('Expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage()); }
        if ($reason !== null) {
            $actual = method_exists($e, 'reasonCode') ? $e->reasonCode() : $e->getMessage();
            if ($actual !== $reason && strpos((string) $actual, $reason) === false) { throw new TestFailure('Expected reason ' . $reason . ', got ' . $actual); }
        }
        return;
    }
    throw new TestFailure('Expected exception ' . $class . '.');
}
function rawModelResponse(array $parts, string $finish = 'STOP'): array {
    return array('candidates' => array(array('finishReason' => $finish, 'content' => array('role' => 'model', 'parts' => $parts))));
}
function modelResponse(array $parts, string $finish = 'STOP'): array {
    $callIndex=0;
    $firstCall=true;
    foreach($parts as &$part){
        if(!is_array($part)||!isset($part['functionCall'])||!is_array($part['functionCall'])){continue;}
        ++$callIndex;
        if(!array_key_exists('id',$part['functionCall'])){
            $part['functionCall']['id']='test-provider-call-'.$callIndex;
        }
        if($firstCall&&!array_key_exists('thoughtSignature',$part)){
            $part['thoughtSignature']='test-thought-signature-'.$callIndex;
        }
        $firstCall=false;
    }
    unset($part);
    return rawModelResponse($parts,$finish);
}
function runtimeReadinessForTest(
    Settings $settings,
    ?ClockPort $clock=null,
    ?RuntimeReadinessStateStore $store=null
): GeminiRuntimeReadiness {
    return new GeminiRuntimeReadiness(
        $settings,
        $clock!==null?$clock:new SystemClock(),
        $store
    );
}
function runtimeProbeForTest(
    GeminiTimeoutTransportInterface $transport,
    Settings $settings,
    GeminiRuntimeReadiness $readiness
): GeminiRuntimeProbe {
    $decoder=new GeminiResponseDecoder();
    $generation=new GeminiGenerationPolicy((string)$settings->get('gemini_thinking_level','low'));
    return new GeminiRuntimeProbe(
        $transport,
        $decoder,
        new GeminiSchemaProjector(),
        $generation,
        $settings,
        $readiness
    );
}
function runtimeProbeTokenFromPayload(array $payload): string {
    $properties=$payload['tools'][0]['functionDeclarations'][0]['parameters']['properties']??array();
    if($properties instanceof stdClass){$properties=(array)$properties;}
    $tokenSchema=is_array($properties)&&isset($properties['token'])?$properties['token']:array();
    if($tokenSchema instanceof stdClass){$tokenSchema=(array)$tokenSchema;}
    $token=(string)(is_array($tokenSchema)?($tokenSchema['enum'][0]??''):'');
    if(preg_match('/^[a-f0-9]{32}$/D',$token)!==1){throw new RuntimeException('Runtime probe token was not declared.');}
    return $token;
}
function runtimeProbeSuccessResponse(array $payload): array {
    return modelResponse(array(array('functionCall'=>array(
        'name'=>RuntimeProbeContract::TOOL,
        'args'=>array('token'=>runtimeProbeTokenFromPayload($payload)),
    ))));
}
function facts(int $count, float $total, string $hash = 'hash'): array {
    return array(
        'item_count' => $count, 'subtotal' => $total, 'total' => $total,
        'formatted_subtotal' => '$' . number_format($total,2), 'formatted_total' => '$' . number_format($total,2),
        'currency' => 'USD', 'woocommerce_cart_hash' => $hash,
        'cart_url' => 'https://example.test/cart', 'checkout_url' => 'https://example.test/checkout',
    );
}
function schemaInspection(SchemaDefinition $definition): SchemaInspection {
    $tables=array();
    foreach($definition->tables() as $table){
        $columns=array();
        foreach($table['columns'] as $name=>$column){
            $columns[$name]=array(
                'type'=>$column['type'],
                'nullable'=>$column['nullable'],
                'default'=>$column['default'],
                'extra'=>$column['extra'],
                'charset'=>$column['charset'],
                'collation'=>$column['collation'],
            );
        }
        $tables[$table['name']]=array(
            'engine'=>$definition->engine(),
            'collation'=>$definition->collation(),
            'columns'=>$columns,
            'indexes'=>$table['indexes'],
        );
    }
    return new SchemaInspection($tables);
}
/** @return array<string,mixed> */
function schemaTableByNameForTest(SchemaDefinition $definition,string $tableName): array {
    foreach($definition->tables() as $table){
        if(($table['name']??null)===$tableName){return $table;}
    }
    throw new RuntimeException('Unknown test schema table.');
}
function line(
    string $key,
    float $quantity,
    int $productId = 10,
    int $variationId = 0,
    array $variation = array(),
    array $data = array()
): CartLine {
    return new CartLine(
        $key, $productId, $variationId, $variation, $quantity,
        hash('sha256', Json::canonical($data)), $data, true,
        array('name' => 'Product ' . $productId, 'quantity' => $quantity)
    );
}
function snapshot(array $lines, array $coupons, int $count, float $total, string $hash): CartSnapshot {
    $map = array(); foreach ($lines as $item) { $map[$item->key()] = $item; }
    return new CartSnapshot($map, $coupons, facts($count, $total, $hash));
}
function receipt(bool $changed = true): ActionReceipt {
    $before = str_repeat('a', 64); $after = $changed ? str_repeat('b', 64) : $before;
    $beforeRest = str_repeat('c', 64); $afterRest = $changed ? str_repeat('d', 64) : $beforeRest;
    return new ActionReceipt('cart_apply', $changed, array(
        'commands' => array(array('type' => 'update', 'item' => 'Product 10', 'quantity' => 1.0)),
        'cart_count' => 1, 'cart_total' => '$20.00', 'changed_line_count' => $changed ? 1 : 0,
        'currency' => 'USD', 'before_revision' => $before, 'after_revision' => $after,
        'before_restoration_revision' => $beforeRest, 'after_restoration_revision' => $afterRest,
    ), $changed ? 'تم تحديث السلة.' : 'السلة مطابقة بالفعل.');
}

function agentContextForTest(): AgentContext {
    $conversationId=Uuid::v4();
    $turnId=Uuid::v4();
    $resource='conversation|'.$conversationId;
    return new AgentContext(
        array('id'=>1,'public_id'=>$conversationId,'state'=>array()),
        $turnId,
        str_repeat('a',64),
        new AuthorityRegistry(),
        new TurnEffects(),
        new TurnLease($resource,hash('sha256',$resource),str_repeat('b',32),1,1700001000)
    );
}
function cartAddCommandForTest(
    int $productId,
    int $variationId,
    float $quantity,
    string $displayName
): CartCommand {
    return CartCommand::add(
        $productId,
        $variationId,
        $quantity,
        str_repeat('a', 64),
        $displayName
    );
}

function cartAgentContextForTest(
    AuthorityRegistry $authority,
    string $message,
    ?PendingCartIntent $pending = null,
    array $history = array()
): AgentContext {
    $conversationId=$pending instanceof PendingCartIntent
        ? $pending->modelAuthoredQuestion()->conversationId()
        : Uuid::v4();
    $turnId=Uuid::v4();
    $resource='conversation|'.$conversationId;
    return new AgentContext(
        array('id'=>1,'public_id'=>$conversationId,'state'=>ConversationState::initial()->toArray()),
        $turnId,
        str_repeat('a',64),
        $authority,
        new TurnEffects(),
        new TurnLease($resource,hash('sha256',$resource),str_repeat('b',32),1,time()+120),
        null,
        $message,
        $pending,
        $history
    );
}
function pendingCartIntentFactoryForTest(
    ?ClockPort $clock=null,
    ?CartIntentVerifierPort $verifier=null
): PendingCartIntentFactory {
    $text=new CatalogTextNormalizer();
    $variableProducts=new VariableProductAuthority($text);
    return new PendingCartIntentFactory(
        $text,
        $clock!==null?$clock:new FixedClock(),
        new CurrentTurnCartIntentEvidence($text,$variableProducts),
        new CartIntentVerificationFactory($text),
        $verifier!==null?$verifier:new FixedCartIntentVerifier(),
        $variableProducts
    );
}
function terminalOutcomesForTest(?CartIntentVerifierPort $verifier=null): TerminalOutcomeAssembler {
    $text=new FixedTextLocalizer(false);
    $arabic=new ArabicCustomerText();
    $clock=new FixedClock();
    return new TerminalOutcomeAssembler(
        new ResponseProjection(new MemoryProductCatalog()),
        new AgentFailureMessages($text),
        $text,
        $arabic,
        pendingCartIntentFactoryForTest($clock,$verifier),
        new ModelAuthoredQuestionFactory($arabic,$clock)
    );
}
function modelQuestionForTest(
    string $text,
    AgentContext $context,
    string $purpose=ModelAuthoredQuestion::PURPOSE_CART_CONTINUATION,
    string $stepId='step-question',
    string $callId='call-question',
    string $providerCallId='provider-call-question'
): ModelAuthoredQuestion {
    $arguments=array('question'=>$text,'purpose'=>$purpose);
    $call=new FunctionCall($callId,$providerCallId,'respond_follow_up',$arguments);
    $step=new ModelStep($stepId,array($call),'','STOP');
    return (new ModelAuthoredQuestionFactory(new ArabicCustomerText(),new FixedClock()))
        ->accept(CurrentTurnModelStep::capture($step,$context,1),$call,$arguments,$context);
}
function restoreModelQuestionForTest(array $row): ModelAuthoredQuestion {
    return ModelAuthoredQuestion::restore(StoredModelQuestionEvidence::fromArray($row));
}
function terminalResponseForTest(
    TerminalOutcomeAssembler $outcomes,
    string $name,
    array $arguments,
    AgentContext $context,
    string $stepId='step-terminal',
    string $callId='call-terminal',
    string $providerCallId='provider-call-terminal'
): AssistantResponse {
    $call=new FunctionCall($callId,$providerCallId,$name,$arguments);
    $step=new ModelStep($stepId,array($call),'','STOP');
    return $outcomes->fromTerminal(
        CurrentTurnModelStep::capture($step,$context,1),
        $call,
        $arguments,
        $context
    );
}
function pendingCartIntentForTest(
    PendingCartIntentFactory $factory,
    array $spec,
    string $question,
    AgentContext $context
): PendingCartIntent {
    return $factory->create($spec,modelQuestionForTest($question,$context),$context);
}
function modelLoopForTest(array $handlers, ?ProviderWaitIsolationPort $isolation = null): AgentModelLoop {
    $outcomes=terminalOutcomesForTest();
    return new AgentModelLoop(
        new ToolCatalog(new ContractSchemaValidator(),new ArgumentValidator(),$handlers),
        $outcomes,
        new AgentLimits(6,12,131072,1024),
        $isolation !== null ? $isolation : new RecordingProviderWaitIsolation()
    );
}

function promptBuilderForTest(string $guidance = ''): AgentPromptBuilder {
    $capability=new class implements CartMutationCapabilityPort {
        public function inspect(): CartMutationCapability {
            return new CartMutationCapability(
                true,
                CartMutationCapability::AVAILABLE,
                ''
            );
        }
    };
    return new AgentPromptBuilder(
        'Yassin Test Store',$capability,new FixedClock(),$guidance
    );
}
