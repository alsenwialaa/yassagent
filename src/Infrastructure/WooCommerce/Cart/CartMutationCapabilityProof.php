<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use Throwable;
use YassinStore\AiAssistant\Application\Port\ProviderWaitIsolationPort;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooSession;
use YassinStore\AiAssistant\Domain\Commerce\CartMutationCapability;

/** Single strongest non-mutating proof used by every cart-mutation boundary. */
final class CartMutationCapabilityProof implements ProviderWaitIsolationPort
{
    /** @var WooSession */ private $session;
    /** @var WooSessionCartStore */ private $store;
    /** @var WooCartRequestFence */ private $requestFence;

    public function __construct(
        WooSession $session,
        WooSessionCartStore $store,
        WooCartRequestFence $requestFence
    ) {
        $this->session = $session;
        $this->store = $store;
        $this->requestFence = $requestFence;
    }

    public function assertSupported(): void
    {
        $this->assertProof(true);
    }

    /** Readiness/planning proof that is also valid during unlocked provider I/O. */
    public function assertAvailable(): void
    {
        $this->assertProof(false);
    }

    private function assertProof(bool $active): void
    {
        try {
            $this->session->assertVerifiedCartMutationVersion();
        } catch (Throwable $exception) {
            throw new CartMutationCapabilityException(
                CartMutationCapability::VERSION_NOT_PROMOTION_TESTED,
                $exception
            );
        }
        try {
            $active
                ? $this->requestFence->assertProtectsActiveSession()
                : $this->requestFence->assertCanProtectActiveSession();
        } catch (Throwable $exception) {
            throw new CartMutationCapabilityException(
                CartMutationCapability::REQUEST_FENCE_UNAVAILABLE,
                $exception
            );
        }
        try {
            $this->store->assertSupported();
        } catch (Throwable $exception) {
            throw new CartMutationCapabilityException(
                CartMutationCapability::STORAGE_TOPOLOGY_UNSUPPORTED,
                $exception
            );
        }
        try {
            $this->session->assertCartMutationCapability();
        } catch (Throwable $exception) {
            throw new CartMutationCapabilityException(
                CartMutationCapability::SESSION_RUNTIME_UNSUPPORTED,
                $exception
            );
        }
        try {
            $this->session->cartOperationNonce();
        } catch (Throwable $exception) {
            throw new CartMutationCapabilityException(
                CartMutationCapability::SESSION_AUTHORITY_UNAVAILABLE,
                $exception
            );
        }
    }

    public function releaseForProviderWait(): void
    {
        // Storage topology may disable assistant mutation while ordinary chat
        // remains available. It must not cause this request to retain its
        // pre-hydration cart lock throughout provider I/O. Suppress the exact
        // Woo writers independently of durable-mutation storage support, then
        // release. If even writer containment is unsupported, fail before any
        // provider wait rather than blocking cart/checkout requests for it.
        if ($this->requestFence->isUnfencedReadOnlySession()) {
            return;
        }
        // The model loop calls this before every provider round. Accept both
        // the initially fenced state and the already released provider-wait
        // state; WooCartRequestFence::releaseForProviderWait() is idempotent.
        $this->requestFence->assertCanProtectActiveSession();
        $this->session->suppressAutomaticSave();
        $this->requestFence->releaseForProviderWait();
    }

    /** Reacquires, then replaces every request-local cart/session field from durable authority. */
    public function beginProtectedMutation(): void
    {
        // Prove the promotion version, resumable fence, storage topology,
        // active core session, and durable operation authority before taking
        // the mutation lock or changing the request-local Woo session.
        $this->assertAvailable();
        $this->requestFence->reacquireForMutation();
        $this->store->beginAuthoritativeMutation();
        $this->store->refreshWorkingFromDurable();
        $this->assertSupported();
    }

    public function available(): bool
    {
        try {
            $this->assertAvailable();
            return true;
        } catch (Throwable $exception) {
            return false;
        }
    }
}
