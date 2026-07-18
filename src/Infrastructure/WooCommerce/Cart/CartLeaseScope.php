<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use Throwable;
use YassinStore\AiAssistant\Application\Commerce\CommerceExecutionContext;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Infrastructure\Concurrency\TurnLeaseManager;
use YassinStore\AiAssistant\Infrastructure\Database\TransactionManager;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;

/** Owns dual conversation/cart lease transactions and cart-session lease lifetime. */
final class CartLeaseScope
{
    /** @var TurnLeaseManager */ private $leases;
    /** @var TransactionManager */ private $transactions;
    /** @var WooSessionOperationMarker */ private $markers;
    /** @var Logger */ private $logger;
    /** @var CartOperationMessages */ private $messages;

    public function __construct(
        TurnLeaseManager $leases,
        TransactionManager $transactions,
        WooSessionOperationMarker $markers,
        Logger $logger,
        CartOperationMessages $messages
    ) {
        $this->leases = $leases;
        $this->transactions = $transactions;
        $this->markers = $markers;
        $this->logger = $logger;
        $this->messages = $messages;
    }

    /**
     * @template T
     * @param callable(TurnLease):T $callback
     * @return T
     */
    public function withCommerceLease(CommerceExecutionContext $context, callable $callback)
    {
        $this->leases->assertCurrent($context->lease());
        $resource = $this->markers->commerceResource();
        $remaining = max(30, min(3600, (int) floor($this->leases->remainingSeconds($context->lease()))));
        $commerceLease = $this->leases->acquire($resource, $remaining);
        if ($commerceLease === null) {
            throw $this->messages->pending('commerce_cart_busy', 'Another conversation is changing this Woo cart session.');
        }
        try {
            return $callback($commerceLease);
        } finally {
            try {
                $this->leases->release($commerceLease);
            } catch (Throwable $exception) {
                $this->logger->error('commerce_lease_release_failed', array(
                    'resource' => $commerceLease->resourceHash(),
                    'fence' => $commerceLease->fence(),
                    'message' => $exception->getMessage(),
                ));
            }
        }
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function guarded(TurnLease $conversationLease, TurnLease $commerceLease, callable $callback)
    {
        return $this->transactions->run(function () use ($conversationLease, $commerceLease, $callback) {
            $this->leases->assertCurrentForUpdate($conversationLease);
            $this->leases->assertCurrentForUpdate($commerceLease);
            return $callback();
        });
    }
}
