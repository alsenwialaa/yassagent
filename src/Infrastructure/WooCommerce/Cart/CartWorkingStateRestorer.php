<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use Throwable;
use YassinStore\AiAssistant\Domain\Commerce\CartOperationStep;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;

/** Restores request-local WooCommerce state only from proven durable authority. */
final class CartWorkingStateRestorer
{
    /** @var WooSessionCartStore */ private $store;
    /** @var CartSnapshotFactory */ private $snapshots;
    /** @var CartStateEvidence */ private $evidence;
    /** @var Logger */ private $logger;

    public function __construct(
        WooSessionCartStore $store,
        CartSnapshotFactory $snapshots,
        CartStateEvidence $evidence,
        Logger $logger
    ) {
        $this->store = $store;
        $this->snapshots = $snapshots;
        $this->evidence = $evidence;
        $this->logger = $logger;
    }

    public function restore(
        CartOperationStep $step,
        WooSessionCartEnvelope $durablePre
    ): bool {
        try {
            $current = $this->store->readDurable();
            if (
                $current->marker() !== null
                || !hash_equals($current->authorityRevision(), $step->preState()->revision())
                || !hash_equals($current->authorityRevision(), $durablePre->authorityRevision())
                || !hash_equals($current->payloadHash(), $durablePre->payloadHash())
                || $current->storedEntries() !== $durablePre->storedEntries()
            ) {
                return false;
            }
            $this->store->restoreWorkingFromDurable($current);
            return $this->evidence->sameComplete(
                $step->preState(),
                $this->snapshots->captureCurrent()
            );
        } catch (Throwable $exception) {
            $this->logger->error('cart_working_prestate_restore_failed', array(
                'step' => $step->publicId(),
                'reason' => $exception->getMessage(),
            ));
            return false;
        }
    }
}
