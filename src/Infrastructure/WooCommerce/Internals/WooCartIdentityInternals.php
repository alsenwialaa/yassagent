<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Internals;

use Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils;
use RuntimeException;
use Throwable;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\SafeSerializedArrayDecoder;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooSession;

/** Cookie, Store API token, and query-token clone authority. */
final class WooCartIdentityInternals
{
    private const PREVIOUS_CUSTOMER_ID_KEY = 'previous_customer_id';

    /** @var WooCoreStructureProbe */ private $core;
    /** @var WooSessionStorageInternals */ private $storage;

    public function __construct(
        WooCoreStructureProbe $core,
        WooSessionStorageInternals $storage
    ) {
        $this->core = $core;
        $this->storage = $storage;
    }

    public function assertStaticCapabilities(): void
    {
        if (!class_exists(CartTokenUtils::class)) {
            throw new RuntimeException('WooCommerce cart-token authority is unavailable.');
        }
        $this->core->assertPublicMethodContract(
            CartTokenUtils::class,
            'validate_cart_token',
            true,
            1,
            1
        );
        $this->core->assertPublicMethodContract(
            CartTokenUtils::class,
            'get_cart_token_payload',
            true,
            1,
            1
        );

        foreach (array('wp_verify_fast_hash', 'wc_clean', 'wp_unslash') as $function) {
            if (!function_exists($function)) {
                throw new RuntimeException('Required cart-identity function is unavailable: ' . $function . '.');
            }
        }
    }

    public function validCookieCustomerId(): string
    {
        if (!defined('COOKIEHASH')) {
            throw new RuntimeException('WordPress cookie identity is unavailable.');
        }
        $cookieName = apply_filters('woocommerce_cookie', 'wp_woocommerce_session_' . COOKIEHASH);
        if (
            !is_string($cookieName) || $cookieName === '' || !isset($_COOKIE[$cookieName])
            || !is_string($_COOKIE[$cookieName])
        ) {
            return '';
        }

        // Match WooCommerce's own cookie canonicalization before parsing and
        // signature verification. Rejecting a value merely because wc_clean()
        // removes markup would let Woo hydrate a session that this request did
        // not fence, creating a mutation race against the same durable cart.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_clean mirrors Woo core canonicalization; the signed cookie is validated below.
        $raw = wc_clean(wp_unslash($_COOKIE[$cookieName]));
        if (!is_string($raw) || strlen($raw) > 1024) {
            return '';
        }
        // WooCommerce 10.9 writes a single-pipe cookie and accepts the legacy
        // double-pipe form. Major 11 is excluded by the compatibility policy.
        $parts = strpos($raw, '||') !== false ? explode('||', $raw) : explode('|', $raw);
        if (count($parts) !== 4) {
            return '';
        }
        list($customerId, $expiration, $expiring, $signature) = $parts;
        if (
            $customerId === '' || strlen($customerId) > 191
            || preg_match('/^[A-Za-z0-9_-]+$/D', $customerId) !== 1
            || preg_match('/^[0-9]{1,12}$/D', $expiration) !== 1
            || preg_match('/^[0-9]{1,12}$/D', $expiring) !== 1
            || $signature === '' || strlen($signature) > 255
        ) {
            return '';
        }
        return wp_verify_fast_hash($customerId . '|' . $expiration, $signature)
            ? $customerId
            : '';
    }

    public function validatedCartTokenUserId(string $token, bool $guestOnly): string
    {
        if (!class_exists(CartTokenUtils::class)) {
            throw new RuntimeException('WooCommerce cart-token authority is unavailable.');
        }
        try {
            if (!CartTokenUtils::validate_cart_token($token)) {
                return '';
            }
            $payload = CartTokenUtils::get_cart_token_payload($token);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'WooCommerce cart-token authority could not be inspected.',
                0,
                $exception
            );
        }
        if (
            !is_array($payload) || !array_key_exists('user_id', $payload)
            || !is_scalar($payload['user_id'])
        ) {
            throw new RuntimeException('WooCommerce cart-token subject is malformed.');
        }
        $customerId = (string) $payload['user_id'];
        if ($customerId === '') {
            return '';
        }
        if (
            strlen($customerId) > 191
            || preg_match('/^[A-Za-z0-9_-]+$/D', $customerId) !== 1
        ) {
            throw new RuntimeException('WooCommerce cart-token subject is malformed.');
        }
        if ($guestOnly && strncmp($customerId, 't_', 2) !== 0) {
            return '';
        }
        return $customerId;
    }

    public function queryWillCloneCurrentRequest(
        string $queryTokenSource,
        string $cookieCustomerSource,
        SafeSerializedArrayDecoder $decoder
    ): bool {
        if ($queryTokenSource === '') {
            return false;
        }
        if ($cookieCustomerSource === '') {
            return true;
        }
        if (hash_equals($queryTokenSource, $cookieCustomerSource)) {
            return false;
        }
        $cookieSession = $this->storage->storedSessionMap($cookieCustomerSource, $decoder);
        $previous = $cookieSession[self::PREVIOUS_CUSTOMER_ID_KEY] ?? null;
        return !is_string($previous) || !hash_equals($queryTokenSource, $previous);
    }

    /**
     * Invalidates cart-operation authority copied by Woo's query-token clone.
     *
     * @param object $session
     */
    public function guardClonedOperationAuthority(
        $session,
        string $source,
        string $destination
    ): bool {
        if (
            $source === '' || $destination === '' || hash_equals($source, $destination)
            || !$this->core->isCoreSessionHandler($session)
        ) {
            return false;
        }
        $previous = $session->get(self::PREVIOUS_CUSTOMER_ID_KEY, '');
        if (!is_string($previous) || !hash_equals($source, $previous)) {
            return false;
        }

        $marker = $session->get(WooSession::CART_OPERATION_MARKER_KEY, '');
        if ($marker !== '' && $marker !== null) {
            $session->delete_session($destination);
            throw new RuntimeException('A cloned WooCommerce session contains active cart operation authority.');
        }

        $session->set(WooSession::CART_OPERATION_NONCE_KEY, '');
        $session->save_data();
        $durable = $session->get_session($destination, array());
        $storedNonce = is_array($durable)
            ? ($durable[WooSession::CART_OPERATION_NONCE_KEY] ?? '')
            : null;
        if ($storedNonce !== '' && $storedNonce !== null) {
            $session->delete_session($destination);
            throw new RuntimeException('Cloned cart operation authority could not be invalidated.');
        }
        return true;
    }
}
