<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce;

use RuntimeException;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\SafeSerializedArrayDecoder;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Internals\WooCartHookTopology;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Internals\WooCartIdentityInternals;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Internals\WooCoreStructureProbe;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Internals\WooPersistentCartInternals;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Internals\WooSessionStorageInternals;

/**
 * The single application-facing boundary for WooCommerce core internals.
 *
 * Compatibility-sensitive implementation knowledge is split among focused
 * private collaborators. Production code outside this boundary depends only on
 * this adapter and never on those collaborators directly.
 */
final class WooSessionInternalsAdapter
{
    /** @var WooCommerceCompatibility */ private $compatibility;
    /** @var WooCoreStructureProbe */ private $core;
    /** @var WooSessionStorageInternals */ private $storage;
    /** @var WooCartHookTopology */ private $hooks;
    /** @var WooCartIdentityInternals */ private $identity;
    /** @var WooPersistentCartInternals */ private $persistentCart;

    public function __construct(WooCommerceCompatibility $compatibility)
    {
        $core = new WooCoreStructureProbe();
        $storage = new WooSessionStorageInternals($core);
        $hooks = new WooCartHookTopology($core, $storage);

        $this->compatibility = $compatibility;
        $this->core = $core;
        $this->storage = $storage;
        $this->hooks = $hooks;
        $this->identity = new WooCartIdentityInternals($core, $storage);
        $this->persistentCart = new WooPersistentCartInternals($hooks);
    }

    public static function forInstalledWooCommerce(): self
    {
        return new self(WooCommerceCompatibility::fromPluginContract());
    }

    public function compatibility(): WooCommerceCompatibility
    {
        return $this->compatibility;
    }

    public function allowsVerifiedCartMutation(): bool
    {
        return $this->compatibility->isInstalledVersionPromotionTested();
    }

    public function assertVerifiedCartMutationVersion(): void
    {
        if (!$this->allowsVerifiedCartMutation()) {
            throw new RuntimeException(
                'The installed WooCommerce release has not passed the packaged cart-mutation promotion gate.'
            );
        }
    }

    /** Proves every non-request-specific private collaborator contract. */
    public function assertStaticCoreCapabilities(): void
    {
        $this->compatibility->assertInstalledVersionAdmitted();
        $this->core->assertStaticCapabilities();
        $this->storage->assertStaticCapabilities();
        $this->identity->assertStaticCapabilities();
    }

    /** @param mixed $handlerClass */
    public function isCoreSessionHandlerClass($handlerClass): bool
    {
        return $this->core->isCoreSessionHandlerClass($handlerClass);
    }

    /** @param mixed $session */
    public function isCoreSessionHandler($session): bool
    {
        return $this->core->isCoreSessionHandler($session);
    }

    /** @param mixed $writer */
    public function isCoreCartSessionWriter($writer): bool
    {
        return $this->hooks->isCoreCartSessionWriter($writer);
    }

    /** @param mixed $session */
    public function sessionHandlerClass($session): string
    {
        return $this->core->sessionHandlerClass($session);
    }


    /** @param object $session */
    public function customerId($session): string
    {
        return $this->storage->customerId($session);
    }

    /** @param object $session */
    public function publishGuestCookie($session): void
    {
        $this->assertVerifiedCartMutationVersion();
        $this->storage->publishGuestCookie($session);
    }

    /** @param object $session */
    public function saveSession($session): void
    {
        $this->assertVerifiedCartMutationVersion();
        $this->storage->save($session);
    }

    /** @param object $session @return array<string,mixed> */
    public function durableSession($session, string $customerId): array
    {
        $this->assertVerifiedCartMutationVersion();
        return $this->storage->durableSession($session, $customerId);
    }

    /** @param object $session @return array<string,mixed> */
    public function workingSessionEntries($session): array
    {
        return $this->storage->workingSessionEntries($session);
    }

    /** @param object $session @param array<string,mixed> $entries */
    public function replaceWorkingSessionEntries($session, array $entries): void
    {
        $this->assertVerifiedCartMutationVersion();
        $this->storage->replaceWorkingSessionEntries($session, $entries);
    }

    /** @param object $session */
    public function sessionExpiration($session): int
    {
        return $this->storage->sessionExpiration($session);
    }

    /** @param object $session */
    public function markSessionClean($session): void
    {
        $this->assertVerifiedCartMutationVersion();
        $this->storage->markSessionClean($session);
    }

    /** @param object $cart @return object */
    public function cartSessionWriter($cart)
    {
        return $this->hooks->cartSessionWriter($cart);
    }

    /** @param object $cart */
    public function hydrateCartFromSession($cart): void
    {
        $this->hooks->hydrateCartFromSession($cart);
    }

    /** @param object $cart @return array<string,mixed> */
    public function persistentCartProjection($cart): array
    {
        return $this->persistentCart->persistentCartProjection($cart);
    }

    /**
     * @param object $session
     * @param object $cart
     * @param object|null $containedWriter
     * @return object
     */
    public function assertMutationRuntime($session, $cart, $containedWriter = null)
    {
        $this->assertVerifiedCartMutationVersion();
        return $this->hooks->assertMutationRuntime($session, $cart, $containedWriter);
    }

    /** @param object $session @param object $cart @return object */
    public function suppressAutomaticSave($session, $cart)
    {
        $this->assertVerifiedCartMutationVersion();
        return $this->hooks->suppressAutomaticSave($session, $cart);
    }

    /** @param object $cart */
    public function suppressAutomaticTotals($cart): void
    {
        $this->assertVerifiedCartMutationVersion();
        $this->hooks->suppressAutomaticTotals($cart);
    }

    /** @param object $cart */
    public function restoreAutomaticTotals($cart): void
    {
        $this->assertVerifiedCartMutationVersion();
        $this->hooks->restoreAutomaticTotals($cart);
    }

    /** @param object $writer */
    public function publishVerifiedCartCookies($writer): void
    {
        $this->assertVerifiedCartMutationVersion();
        $this->hooks->publishVerifiedCartCookies($writer);
    }

    public function persistentCartMetaKey(): string
    {
        return $this->persistentCart->persistentCartMetaKey();
    }

    public function sessionTableName(): string
    {
        return $this->storage->sessionTableName();
    }

    public function invalidateSessionCache(string $customerId): void
    {
        $this->assertVerifiedCartMutationVersion();
        $this->storage->invalidateSessionCache($customerId);
    }

    /** @return mixed|null */
    public function storedSessionValue(string $customerId)
    {
        return $this->storage->storedSessionValue($customerId);
    }

    /** @return array<string,mixed> */
    public function storedSessionMap(string $customerId, SafeSerializedArrayDecoder $decoder): array
    {
        return $this->storage->storedSessionMap($customerId, $decoder);
    }

    public function validCookieCustomerId(): string
    {
        return $this->identity->validCookieCustomerId();
    }

    public function validatedCartTokenUserId(string $token, bool $guestOnly): string
    {
        return $this->identity->validatedCartTokenUserId($token, $guestOnly);
    }

    public function queryWillCloneCurrentRequest(
        string $queryTokenSource,
        string $cookieCustomerSource,
        SafeSerializedArrayDecoder $decoder
    ): bool {
        return $this->identity->queryWillCloneCurrentRequest(
            $queryTokenSource,
            $cookieCustomerSource,
            $decoder
        );
    }

    /** @param object $session */
    public function guardClonedOperationAuthority(
        $session,
        string $source,
        string $destination
    ): bool {
        $this->assertVerifiedCartMutationVersion();
        return $this->identity->guardClonedOperationAuthority($session, $source, $destination);
    }
}
