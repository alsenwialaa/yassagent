<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Composition;

use YassinStore\AiAssistant\Application\Port\CartMutationPort;
use YassinStore\AiAssistant\Application\Port\CartMutationCapabilityPort;
use YassinStore\AiAssistant\Application\Port\TextLocalizerPort;
use YassinStore\AiAssistant\Application\Port\ProviderWaitIsolationPort;
use YassinStore\AiAssistant\Domain\Shopping\CatalogTextNormalizer;
use YassinStore\AiAssistant\Infrastructure\Security\RecoveryKey;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartCommandExecutor;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartDeltaVerifier;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartItemDataNormalizer;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartItemDisplayProjector;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartLineAuthorityPolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartStateEvidence;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartOperationMessages;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartOperationPlanningGuard;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartOperationTerminalizer;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartLeaseScope;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartMutationCapabilityQuarantine;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartMutationCapabilityLossPolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartMutationCapabilityInspector;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartMutationCapabilityProof;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartOperationCoordinator;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartProductPolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartProtectedReadScope;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartQueryService;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\BootCartSnapshot;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartRecoveryCoordinator;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartSemanticEffectBuilder;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartSnapshotFactory;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartStepPlanner;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartStepExecutionEngine;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartStepVerifier;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\CartWorkingStateRestorer;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\ReceiptPresenter;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\WooCartGateway;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\WooCartRequestFence;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\SafePersistentCartDecoder;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\SafeSerializedArrayDecoder;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\WooPersistentCartStore;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\WooSessionCartStore;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\WooSessionOperationMarker;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\WooStorageTopologyPolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery\CatalogCandidateMerger;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery\CatalogAlternativeRanker;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery\CatalogCategoryEligibility;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery\CatalogMatchScorer;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery\CatalogPricePolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery\CatalogQueryFilter;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery\CatalogTaxonomyCandidateSource;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\PlainMoneyFormatter;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\ProductCapabilityPolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\ProductCatalog;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\AttributePresenter;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\CatalogVisibilityPolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\DisplayPriceProjection;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\ProductSnapshotFactory;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\VariationSnapshotFactory;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\VariationAuthorityEpoch;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\VariableProductCatalogPolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooSession;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooSessionInternalsAdapter;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;

/** Focused construction boundary for read projections and durable verified cart effects. */
final class CommerceStack
{
    /** @var WooSession */ private $session;
    /** @var CartQueryService */ private $bootCart;
    /** @var CartQueryService */ private $protectedCart;
    /** @var CartMutationPort */ private $mutations;
    /** @var CartMutationCapabilityPort */ private $mutationCapability;
    /** @var ProductCatalog */ private $catalog;
    /** @var ProviderWaitIsolationPort */ private $providerWaitIsolation;

    public function __construct(
        PersistenceStack $persistence,
        Logger $logger,
        TextLocalizerPort $text,
        WooSessionInternalsAdapter $wooInternals
    ) {
        $this->session = new WooSession($wooInternals);
        $money = new PlainMoneyFormatter();
        $gateway = new WooCartGateway($this->session, $money);
        $normalizer = new CartItemDataNormalizer();
        $visibility = new CatalogVisibilityPolicy();
        $capabilities = new ProductCapabilityPolicy();
        $variationCatalog = new VariableProductCatalogPolicy();
        $attributes = new AttributePresenter();
        $cartProducts = new CartProductPolicy($gateway, $visibility, $capabilities, $attributes);
        $snapshots = new CartSnapshotFactory(
            $gateway,
            $normalizer,
            $cartProducts,
            $money,
            new CartItemDisplayProjector()
        );
        $lineAuthority = new CartLineAuthorityPolicy();
        $planVerifier = new CartDeltaVerifier($lineAuthority);
        $stepVerifier = new CartStepVerifier($lineAuthority);
        $markers = new WooSessionOperationMarker($this->session, new RecoveryKey());
        $requestFence = new WooCartRequestFence($logger, $wooInternals);
        // Only promotion-tested releases may enter the direct session-storage
        // and mutation path. Capability-gated future 10.x releases retain
        // native read-only cart/catalog assistance until their exact package
        // passes the real WordPress/WooCommerce promotion lane.
        if ($wooInternals->allowsVerifiedCartMutation()) {
            // This must be registered while plugins_loaded is still running,
            // before Woo constructs and hydrates its session handler on init.
            $requestFence->register();
        }
        $this->bootCart = new CartQueryService(new BootCartSnapshot(
            $requestFence,
            $this->session,
            $snapshots
        ));
        $serializedArrays = new SafeSerializedArrayDecoder();
        $store = new WooSessionCartStore(
            $this->session,
            $gateway,
            $normalizer,
            $serializedArrays,
            $persistence->transactions(),
            $persistence->leases(),
            $markers,
            new WooPersistentCartStore(
                $this->session,
                new SafePersistentCartDecoder($serializedArrays),
                $requestFence
            ),
            new WooStorageTopologyPolicy(),
            $requestFence
        );
        $capabilityProof = new CartMutationCapabilityProof($this->session, $store, $requestFence);
        $this->protectedCart = new CartQueryService(new CartProtectedReadScope(
            $requestFence,
            $store,
            $snapshots,
            $capabilityProof
        ));
        $this->providerWaitIsolation = $capabilityProof;
        $this->mutationCapability = new CartMutationCapabilityInspector(
            $this->session,
            $capabilityProof,
            $text
        );
        $messages = new CartOperationMessages();
        $scope = new CartLeaseScope(
            $persistence->leases(),
            $persistence->transactions(),
            $markers,
            $logger,
            $messages
        );
        $evidence = new CartStateEvidence($snapshots, $store);
        $recovery = new CartRecoveryCoordinator(
            $persistence->cartSteps(),
            $persistence->cartStepAttempts(),
            $store,
            $markers,
            $stepVerifier,
            $scope,
            $evidence,
            $messages
        );
        $terminalizer = new CartOperationTerminalizer(
            $persistence->operations(),
            $persistence->cartSteps(),
            $persistence->cartStepAttempts(),
            $scope,
            $evidence,
            $messages,
            $markers,
            $persistence->leases()
        );
        $workingStateRestorer = new CartWorkingStateRestorer(
            $store,
            $snapshots,
            $evidence,
            $logger
        );
        $stepEngine = new CartStepExecutionEngine(
            $persistence->cartSteps(),
            $persistence->cartStepAttempts(),
            $snapshots,
            new CartCommandExecutor($gateway, $cartProducts, $capabilityProof),
            $stepVerifier,
            $markers,
            $store,
            $recovery,
            $persistence->leases(),
            $logger,
            $scope,
            $evidence,
            $messages,
            $terminalizer,
            $workingStateRestorer,
            $capabilityProof
        );
        $quarantine = new CartMutationCapabilityQuarantine(
            $persistence->operations(),
            $persistence->cartSteps(),
            $persistence->cartStepAttempts(),
            $capabilityProof,
            $scope,
            $evidence,
            $messages,
            new CartMutationCapabilityLossPolicy()
        );
        $this->mutations = new CartOperationCoordinator(
            $persistence->operations(),
            $persistence->cartSteps(),
            $snapshots,
            new CartOperationPlanningGuard(
                new CartStepPlanner(),
                $persistence->cartSteps(),
                $terminalizer
            ),
            $planVerifier,
            $store,
            $recovery,
            new ReceiptPresenter($planVerifier),
            $logger,
            $scope,
            $messages,
            new CartSemanticEffectBuilder(),
            $terminalizer,
            $stepEngine,
            $quarantine,
            $capabilityProof
        );

        $catalogText = new CatalogTextNormalizer();
        $displayPrices = new DisplayPriceProjection($money);
        $catalogPrices = new CatalogPricePolicy();
        $this->catalog = new ProductCatalog(
            new ProductSnapshotFactory(
                $attributes,
                $displayPrices,
                $capabilities,
                $variationCatalog
            ),
            new VariationSnapshotFactory($attributes, $displayPrices),
            new VariationAuthorityEpoch($attributes),
            $variationCatalog,
            $capabilities,
            $visibility,
            new CatalogMatchScorer($catalogText),
            $catalogText,
            new CatalogCandidateMerger(),
            $catalogPrices,
            new CatalogCategoryEligibility(),
            new CatalogQueryFilter(),
            new CatalogAlternativeRanker($catalogPrices, $catalogText),
            new CatalogTaxonomyCandidateSource()
        );
    }

    public function bootCart(): CartQueryService
    {
        return $this->bootCart;
    }
    public function protectedCart(): CartQueryService
    {
        return $this->protectedCart;
    }
    public function mutations(): CartMutationPort
    {
        return $this->mutations;
    }
    public function mutationCapability(): CartMutationCapabilityPort
    {
        return $this->mutationCapability;
    }
    public function providerWaitIsolation(): ProviderWaitIsolationPort
    {
        return $this->providerWaitIsolation;
    }
    public function catalog(): ProductCatalog
    {
        return $this->catalog;
    }
}
