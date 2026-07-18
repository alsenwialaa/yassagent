<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use YassinStore\AiAssistant\Infrastructure\Database\WpdbError;
use RuntimeException;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooSession;
use YassinStore\AiAssistant\Support\Json;

/**
 * Fenced projection of the verified working cart into WooCommerce's logged-in
 * persistent-cart usermeta. Guest carts have no persistent projection.
 */
final class WooPersistentCartStore
{
    /** @var WooSession */ private $session;
    /** @var SafePersistentCartDecoder */ private $decoder;
    /** @var bool|null */ private $enabled;
    /** @var WooCartRequestFence */ private $requestFence;

    public function __construct(
        WooSession $session,
        SafePersistentCartDecoder $decoder,
        WooCartRequestFence $requestFence
    ) {
        $this->session = $session;
        $this->decoder = $decoder;
        $this->requestFence = $requestFence;
    }

    public function enabled(): bool
    {
        if ($this->enabled !== null) {
            return $this->enabled;
        }
        if ($this->session->authenticatedCustomerId() < 1) {
            $this->enabled = false;
            return false;
        }
        try {
            // Exact persistent-cart policy exposed by the accepted core runtime contract.
            $this->enabled = (bool) apply_filters('woocommerce_persistent_cart_enabled', true);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Unable to determine WooCommerce persistent-cart policy.', 0, $exception);
        }
        return $this->enabled;
    }

    /** Must be called inside the same database transaction as the core session write. */
    public function persistAndVerifyForUpdate(): void
    {
        if (!$this->enabled()) {
            return;
        }
        $userId = $this->session->authenticatedCustomerId();

        global $wpdb;
        if (!isset($wpdb->usermeta) || trim((string) $wpdb->usermeta) === '') {
            throw new RuntimeException('WordPress usermeta storage is unavailable for the persistent cart.');
        }
        $key = $this->session->persistentCartMetaKey();
        $projection = $this->session->persistentCartProjection();
        $serialized = (string) maybe_serialize($projection);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT umeta_id,meta_value FROM ' . $wpdb->usermeta
                . ' WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id ASC FOR UPDATE',
                $userId,
                $key
            ),
            ARRAY_A
        );
        if (WpdbError::has($wpdb) || !is_array($rows)) {
            throw new RuntimeException('Unable to lock the logged-in persistent cart.');
        }

        $cart = $projection['cart'] ?? null;
        if (!is_array($cart)) {
            throw new RuntimeException('WooCommerce persistent-cart projection is malformed.');
        }
        if ($cart === array()) {
            if ($rows !== array()) {
                $this->requestFence->assertProtectsActiveSession();
                $deleted = $wpdb->query($wpdb->prepare(
                    'DELETE FROM ' . $wpdb->usermeta . ' WHERE user_id=%d AND meta_key=%s',
                    $userId,
                    $key
                ));
                if ($deleted === false || WpdbError::has($wpdb)) {
                    throw new RuntimeException('Unable to clear the logged-in persistent cart.');
                }
            }
            $stored = $wpdb->get_col($wpdb->prepare(
                'SELECT meta_value FROM ' . $wpdb->usermeta
                . ' WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id ASC FOR UPDATE',
                $userId,
                $key
            ));
            if (WpdbError::has($wpdb) || !is_array($stored) || $stored !== array()) {
                throw new RuntimeException('Logged-in persistent-cart deletion was not verified.');
            }
            return;
        }

        if ($rows === array()) {
            $this->requestFence->assertProtectsActiveSession();
            $inserted = $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->usermeta . ' (user_id,meta_key,meta_value) VALUES (%d,%s,%s)',
                $userId,
                $key,
                $serialized
            ));
            if ($inserted !== 1 || WpdbError::has($wpdb)) {
                throw new RuntimeException('Unable to create the logged-in persistent cart.');
            }
        } else {
            $this->requestFence->assertProtectsActiveSession();
            $updated = $wpdb->query($wpdb->prepare(
                'UPDATE ' . $wpdb->usermeta . ' SET meta_value=%s WHERE user_id=%d AND meta_key=%s',
                $serialized,
                $userId,
                $key
            ));
            if ($updated === false || WpdbError::has($wpdb)) {
                throw new RuntimeException('Unable to update the logged-in persistent cart.');
            }
        }

        $stored = $wpdb->get_col($wpdb->prepare(
            'SELECT meta_value FROM ' . $wpdb->usermeta
            . ' WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id ASC FOR UPDATE',
            $userId,
            $key
        ));
        if (WpdbError::has($wpdb) || !is_array($stored) || $stored === array()) {
            throw new RuntimeException('Unable to read back the logged-in persistent cart.');
        }
        $expected = Json::canonical($projection);
        foreach ($stored as $value) {
            $decoded = $this->decoder->decode((string) $value);
            if (!hash_equals($expected, Json::canonical($decoded))) {
                throw new RuntimeException('Logged-in persistent-cart read-back does not match the verified cart.');
            }
        }
    }

    /**
     * Locks and re-proves the logged-in persistent projection without writing.
     * Must run in the aggregate operation-finalization transaction after the
     * marker-free core session row has also been locked and verified.
     */
    public function assertVerifiedForUpdate(): void
    {
        if (!$this->enabled()) {
            return;
        }
        $userId = $this->session->authenticatedCustomerId();
        global $wpdb;
        if (!isset($wpdb->usermeta) || trim((string) $wpdb->usermeta) === '') {
            throw new RuntimeException('WordPress usermeta storage is unavailable for persistent-cart verification.');
        }
        $projection = $this->session->persistentCartProjection();
        $cart = $projection['cart'] ?? null;
        if (!is_array($cart)) {
            throw new RuntimeException('WooCommerce persistent-cart projection is malformed.');
        }
        $stored = $wpdb->get_col($wpdb->prepare(
            'SELECT meta_value FROM ' . $wpdb->usermeta
            . ' WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id ASC FOR UPDATE',
            $userId,
            $this->session->persistentCartMetaKey()
        ));
        if (WpdbError::has($wpdb) || !is_array($stored)) {
            throw new RuntimeException('Unable to lock the final logged-in persistent cart.');
        }
        if ($cart === array()) {
            if ($stored !== array()) {
                throw new PersistentCartMismatchException(
                    'Final logged-in persistent cart was recreated after verified deletion.'
                );
            }
            return;
        }
        if ($stored === array()) {
            throw new PersistentCartMismatchException('Final logged-in persistent cart is missing.');
        }
        $expected = Json::canonical($projection);
        foreach ($stored as $value) {
            try {
                $decoded = $this->decoder->decode((string) $value);
            } catch (\Throwable $exception) {
                throw new PersistentCartMismatchException(
                    'Final logged-in persistent cart is malformed.',
                    0,
                    $exception
                );
            }
            if (!hash_equals($expected, Json::canonical($decoded))) {
                throw new PersistentCartMismatchException(
                    'Final logged-in persistent cart differs from the verified cart.'
                );
            }
        }
    }

    public function invalidateCache(): void
    {
        if (!$this->enabled()) {
            return;
        }
        $userId = $this->session->authenticatedCustomerId();
        wp_cache_delete($userId, 'user_meta');
        clean_user_cache($userId);
    }
}
