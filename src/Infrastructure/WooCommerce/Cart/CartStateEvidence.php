<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use Throwable;
use YassinStore\AiAssistant\Domain\Commerce\CartOperationStep;
use YassinStore\AiAssistant\Domain\Commerce\CartSnapshot;

/** Purely reads and compares live/durable cart evidence; it never mutates. */
final class CartStateEvidence
{
    /** @var CartSnapshotFactory */ private $snapshots;
    /** @var WooSessionCartStore */ private $store;

    public function __construct(CartSnapshotFactory $snapshots, WooSessionCartStore $store)
    {
        $this->snapshots = $snapshots;
        $this->store = $store;
    }

    public function sameComplete(CartSnapshot $left, ?CartSnapshot $right): bool
    {
        return $right !== null
            && hash_equals($left->revision(), $right->revision())
            && hash_equals($left->restorationRevision(), $right->restorationRevision());
    }

    public function capture(): CartSnapshot
    {
        return $this->snapshots->captureCurrent();
    }

    public function captureOptional(): ?CartSnapshot
    {
        try {
            return $this->capture();
        } catch (Throwable $ignored) {
            return null;
        }
    }

    public function stepStillAtPre(CartOperationStep $step): bool
    {
        try {
            $durable = $this->store->readDurable();
            return $this->sameComplete($step->preState(), $this->capture())
                && $durable->marker() === null
                && hash_equals($durable->authorityRevision(), $step->preState()->revision());
        } catch (Throwable $ignored) {
            return false;
        }
    }
}
