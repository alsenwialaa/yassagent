<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Composition;

use YassinStore\AiAssistant\Application\Commerce\CartPlanFactory;
use YassinStore\AiAssistant\Application\Commerce\CurrentTurnCartIntentEvidence;
use YassinStore\AiAssistant\Application\Commerce\CartIntentVerificationFactory;
use YassinStore\AiAssistant\Application\Port\CartIntentVerifierPort;
use YassinStore\AiAssistant\Application\Port\ClockPort;
use YassinStore\AiAssistant\Application\Port\TextLocalizerPort;
use YassinStore\AiAssistant\Application\Tool\ArgumentValidator;
use YassinStore\AiAssistant\Application\Tool\ContractSchemaValidator;
use YassinStore\AiAssistant\Application\Tool\Handlers\Cart\CartApplyHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Cart\CartViewHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Cart\CheckoutGetUrlHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Catalog\CatalogGetProductBySkuHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Catalog\CatalogResolveVariationHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Catalog\CatalogListCategoriesHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Catalog\CatalogRelatedHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Catalog\CatalogDiscoverHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Catalog\CatalogGetDetailsHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Catalog\CatalogCompareHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Catalog\CatalogFindAlternativesHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Catalog\CatalogRankCandidatesHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Content\ContentGetHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Content\ContentSearchHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Content\StoreInfoHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Content\StorePolicyHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Terminal\RespondAnswerHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Terminal\RespondFollowUpHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Terminal\RespondSafeFailureHandler;
use YassinStore\AiAssistant\Application\Tool\Handlers\Shopping\ShoppingMemoryUpdateHandler;
use YassinStore\AiAssistant\Application\Tool\Service\CartToolService;
use YassinStore\AiAssistant\Application\Tool\Service\CatalogToolService;
use YassinStore\AiAssistant\Application\Tool\Service\ContentToolService;
use YassinStore\AiAssistant\Application\Tool\ToolCatalog;
use YassinStore\AiAssistant\Application\Tool\ToolPromptDescriptions;
use YassinStore\AiAssistant\Infrastructure\WordPress\ContentRepository;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;

/** Builds the immutable one-contract/one-handler tool kernel. */
final class ToolStack
{
    /** @var ToolCatalog */ private $catalog;

    public function __construct(
        CommerceStack $commerce,
        ContentRepository $content,
        Logger $logger,
        TextLocalizerPort $text,
        CurrentTurnCartIntentEvidence $cartIntent,
        CartIntentVerificationFactory $verificationRequests,
        CartIntentVerifierPort $intentVerifier,
        ClockPort $clock
    ) {
        $catalogTools = new CatalogToolService($commerce->catalog(), $text);
        $cartTools = new CartToolService(
            $commerce->protectedCart(),
            new CartPlanFactory(),
            $commerce->mutations(),
            $commerce->mutationCapability(),
            $cartIntent,
            $verificationRequests,
            $intentVerifier,
            $clock,
            $logger,
            $text
        );
        $contentTools = new ContentToolService($content);

        $this->catalog = new ToolCatalog(
            new ContractSchemaValidator(),
            new ArgumentValidator(),
            array(
                new CatalogDiscoverHandler($catalogTools),
                new CatalogGetDetailsHandler($catalogTools),
                new CatalogCompareHandler($catalogTools),
                new CatalogRankCandidatesHandler($catalogTools),
                new CatalogFindAlternativesHandler($catalogTools),
                new ShoppingMemoryUpdateHandler(),
                new CatalogGetProductBySkuHandler($catalogTools),
                new CatalogResolveVariationHandler($catalogTools),
                new CatalogRelatedHandler($catalogTools),
                new CatalogListCategoriesHandler($catalogTools),
                new ContentSearchHandler($contentTools),
                new ContentGetHandler($contentTools),
                new StorePolicyHandler($contentTools),
                new StoreInfoHandler($contentTools),
                new CartViewHandler($cartTools),
                new CartApplyHandler($cartTools),
                new CheckoutGetUrlHandler($cartTools),
                new RespondAnswerHandler(),
                new RespondFollowUpHandler(),
                new RespondSafeFailureHandler(),
            )
        );
        ToolPromptDescriptions::assertExactCatalog($this->catalog->names());
    }

    public function catalog(): ToolCatalog
    {
        return $this->catalog;
    }
}
