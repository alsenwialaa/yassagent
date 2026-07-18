<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use YassinStore\AiAssistant\Application\Port\CartSnapshotProviderPort;
use YassinStore\AiAssistant\Domain\Commerce\CartSnapshot;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooSession;

/** Fresh short-request storefront summary with one coherent native Woo save. */
final class BootCartSnapshot implements CartSnapshotProviderPort
{
    /** @var WooCartRequestFence */ private $requestFence;
    /** @var WooSession */ private $session;
    /** @var CartSnapshotFactory */ private $snapshots;

    public function __construct(
        WooCartRequestFence $requestFence,
        WooSession $session,
        CartSnapshotFactory $snapshots
    ) {
        $this->requestFence = $requestFence;
        $this->session = $session;
        $this->snapshots = $snapshots;
    }

    public function capture(): CartSnapshot
    {
        $this->session->ensure();

        // Custom handlers have no shared request fence or exact core staging
        // callback contract. Preserve their established read-only assistance
        // from the already hydrated native projection without calculating.
        if ($this->requestFence->isUnfencedReadOnlySession()) {
            return $this->snapshots->captureCurrent();
        }

        $this->requestFence->assertProtectsActiveSession();
        $current = $this->snapshots->captureCurrent();
        if ($current->isEmpty()) {
            // There is no product price to refresh. Avoid Woo's special empty
            // calculation branch, which stages the same empty cart directly.
            $this->session->publishCartOperationAuthority();
            return $current;
        }

        // Keep Woo's canonical set_session callback active. Calculation,
        // request-local cart state, derived cart cookies, and the core session
        // row must all describe one result. Publish and read back that result
        // before boot succeeds while the request fence remains held.
        $snapshot = $this->snapshots->capture();
        $this->session->publishCartOperationAuthority();
        return $snapshot;
    }
}
