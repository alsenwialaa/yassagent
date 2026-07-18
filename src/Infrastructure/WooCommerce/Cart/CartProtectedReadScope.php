<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use YassinStore\AiAssistant\Application\Port\CartSnapshotProviderPort;
use YassinStore\AiAssistant\Domain\Commerce\CartSnapshot;
use YassinStore\AiAssistant\Domain\Exception\OperationPendingException;
use Throwable;

/** Reads one durable cart snapshot while briefly owning the Woo request fence. */
final class CartProtectedReadScope implements CartSnapshotProviderPort
{
    /** @var WooCartRequestFence */ private $requestFence;
    /** @var WooSessionCartStore */ private $store;
    /** @var CartSnapshotFactory */ private $snapshots;
    /** @var CartMutationCapabilityProof */ private $capability;

    public function __construct(
        WooCartRequestFence $requestFence,
        WooSessionCartStore $store,
        CartSnapshotFactory $snapshots,
        CartMutationCapabilityProof $capability
    ) {
        $this->requestFence = $requestFence;
        $this->store = $store;
        $this->snapshots = $snapshots;
        $this->capability = $capability;
    }

    public function capture(): CartSnapshot
    {
        // A custom/already-initialized handler cannot join the core durable
        // fence. Preserve native read-only assistance in that explicit mode;
        // the independent capability gate still rejects every mutation.
        if ($this->requestFence->isUnfencedReadOnlySession()) {
            return $this->snapshots->capture();
        }
        $this->requestFence->reacquireForMutation();
        try {
            $this->store->refreshWorkingFromDurable();
            return $this->snapshots->capture();
        } finally {
            try {
                // Some terminal/replay paths reach projection without running
                // AgentRunner. Suppress Woo's native writers before unlocking
                // so this refreshed request copy cannot overwrite a newer cart
                // during shutdown.
                $this->capability->releaseForProviderWait();
            } catch (Throwable $exception) {
                throw new OperationPendingException(
                    'cart_read_fence_release_pending',
                    ('تعذر إكمال قراءة السلة بأمان. أعد إرسال الطلب نفسه.'),
                    $exception->getMessage()
                );
            }
        }
    }
}
