<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use YassinStore\AiAssistant\Infrastructure\Database\WpdbError;
use RuntimeException;
use Throwable;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooSessionInternalsAdapter;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;

/**
 * Serializes Woo cart hydration and persistence without holding the lock while
 * the assistant waits on the model provider.
 *
 * WooCommerce reads its session map while constructing the core session handler. An
 * ordinary Woo request holds its pre-hydration lock through shutdown. Chat
 * makes its hydrated working copy read-only, releases before provider I/O, then
 * reacquires the same identity and reloads durable state before mutation. Thus
 * no ordinary stale writer can overlap an assistant write, without blocking
 * cart and checkout requests throughout provider latency.
 */
final class WooCartRequestFence
{
    private const LOCK_WAIT_SECONDS = 5;

    /** @var Logger */ private $logger;
    /** @var WooSessionInternalsAdapter */ private $internals;
    /** @var SafeSerializedArrayDecoder */ private $serializedArrays;
    /** @var bool */ private $registered = false;
    /** @var bool */ private $intercepted = false;
    /** @var bool */ private $registeredTooLate = false;
    /** @var bool */ private $unsupportedHandler = false;
    /** @var string */ private $queryTokenSource = '';
    /** @var string */ private $headerTokenSource = '';
    /** @var string */ private $cookieCustomerSource = '';
    /** @var bool */ private $queryCloneExpected = false;
    /** @var bool */ private $cloneGuarded = false;
    /** @var array<int,string> */ private $locks = array();
    /** @var array<int,string> */ private $resumeLocks = array();
    /** @var bool */ private $releasedForProvider = false;

    public function __construct(
        Logger $logger,
        ?WooSessionInternalsAdapter $internals = null
    ) {
        $this->logger = $logger;
        $this->internals = $internals ?? WooSessionInternalsAdapter::forInstalledWooCommerce();
        $this->serializedArrays = new SafeSerializedArrayDecoder();
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;
        add_filter('woocommerce_session_handler', array($this, 'beforeSessionHandler'), -1000, 1);
        add_action('woocommerce_init', array($this, 'adoptActiveSession'), -1000, 0);
        add_action('ysai_woocommerce_session_initialized', array($this, 'adoptActiveSession'), -1000, 0);
        add_action('shutdown', array($this, 'release'), PHP_INT_MAX);
        register_shutdown_function(array($this, 'release'));
    }

    /** @param mixed $handlerClass @return mixed */
    public function beforeSessionHandler($handlerClass)
    {
        if ($this->intercepted) {
            $this->registeredTooLate = true;
            return $handlerClass;
        }
        $this->intercepted = true;
        if (!$this->internals->isCoreSessionHandlerClass($handlerClass)) {
            $this->unsupportedHandler = true;
            return $handlerClass;
        }
        if ($this->registeredTooLate) {
            return $handlerClass;
        }

        $identities = array();
        try {
            $this->queryTokenSource = $this->readQueryTokenSource();
            $this->headerTokenSource = $this->readHeaderTokenSource();
            $this->cookieCustomerSource = $this->validCookieCustomerId();
            $identities = $this->inboundSessionIdentities();
        } catch (Throwable $exception) {
            $this->logger->error('woo_cart_request_identity_unavailable', array(
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
            ));
            $this->failClosed();
        }
        if ($identities === array()) {
            // A new guest request has no shared durable cart yet. Its random
            // Woo customer identity is created after this boundary and cannot
            // be known or shared by a concurrent inbound request.
            return $handlerClass;
        }

        sort($identities, SORT_STRING);
        try {
            foreach ($identities as $identity) {
                $this->acquireOnce($this->lockName($this->rowIdentity($identity)));
            }
            // Match the accepted core session clone contract while the
            // source and existing cookie destination are both locked. A later
            // request carrying the same token deliberately reuses an already
            // derived cookie session; its persisted clone-source field is
            // provenance, not proof that this request cloned anything.
            $this->queryCloneExpected = $this->queryWillCloneCurrentRequest();
        } catch (Throwable $exception) {
            $this->release();
            $this->logger->error('woo_cart_request_fence_unavailable', array(
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
            ));
            $this->failClosed();
        }
        return $handlerClass;
    }

    /** Adopts the physical destination row immediately after Woo initializes it. */
    public function adoptActiveSession(): void
    {
        if (
            !$this->registered || !$this->intercepted || $this->registeredTooLate
            || $this->unsupportedHandler
        ) {
            return;
        }
        if (!is_object(WC()) || !is_object(WC()->session)) {
            return;
        }
        $customerId = (string) WC()->session->get_customer_id();
        if ($customerId === '') {
            return;
        }
        try {
            $this->acquireOnce($this->lockName($this->rowIdentity($customerId)));
            $this->guardClonedDestination($customerId);
        } catch (Throwable $exception) {
            $this->release();
            $this->logger->error('woo_cart_request_destination_unavailable', array(
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
            ));
            $this->failClosed();
        }
    }

    /**
     * Non-mutating proof that this request crossed the pre-hydration boundary
     * and still owns every lock on which its Woo session initialization relied.
     */
    public function assertProtectsActiveSession(): void
    {
        if (!$this->registered || !$this->intercepted || $this->registeredTooLate) {
            throw new RuntimeException('WooCommerce session initialization was not fenced before hydration.');
        }
        if ($this->unsupportedHandler) {
            throw new RuntimeException('The active WooCommerce session handler cannot share the cart request fence.');
        }
        if ($this->locks === array()) {
            throw new RuntimeException('The active WooCommerce session has no request fence.');
        }
        foreach ($this->locks as $lock) {
            if (!$this->owns($lock)) {
                throw new RuntimeException('The WooCommerce cart request fence is no longer owned.');
            }
        }
    }

    /** Planning/readiness proof; the provider-wait state is intentionally unlocked. */
    public function assertCanProtectActiveSession(): void
    {
        if ($this->releasedForProvider) {
            if (
                !$this->registered || !$this->intercepted || $this->registeredTooLate
                || $this->unsupportedHandler || $this->resumeLocks === array()
                || $this->locks !== array()
            ) {
                throw new RuntimeException('WooCommerce provider-wait cart fencing is invalid.');
            }
            return;
        }
        $this->assertProtectsActiveSession();
    }

    /**
     * True only when this request never acquired (and therefore cannot retain)
     * an assistant cart fence. This is the explicit read-only fallback for a
     * custom/already-initialized session handler; mutation remains unavailable.
     */
    public function isUnfencedReadOnlySession(): bool
    {
        return $this->locks === array()
            && $this->resumeLocks === array()
            && !$this->releasedForProvider
            && (!$this->registered || !$this->intercepted
                || $this->registeredTooLate || $this->unsupportedHandler);
    }

    /**
     * Releases only after the chat request has suppressed every automatic Woo
     * writer. The same session identities are retained for mutation reacquire.
     */
    public function releaseForProviderWait(): void
    {
        if ($this->releasedForProvider) {
            return;
        }
        $this->assertProtectsActiveSession();
        $resume = $this->locks;
        foreach ($this->activeSessionIdentities() as $identity) {
            $resume[] = $this->lockName($this->rowIdentity($identity));
        }
        $resume = array_values(array_unique($resume));
        sort($resume, SORT_STRING);
        if ($resume === array()) {
            throw new RuntimeException('The active WooCommerce session has no resumable cart identity.');
        }
        $this->releaseOwnedLocks(true);
        $this->resumeLocks = $resume;
        $this->releasedForProvider = true;
    }

    /** Reacquires the pre-hydration identity before durable reload and mutation. */
    public function reacquireForMutation(): void
    {
        if (!$this->releasedForProvider) {
            $this->assertProtectsActiveSession();
            return;
        }
        try {
            foreach ($this->resumeLocks as $lock) {
                $this->acquireOnce($lock);
            }
        } catch (Throwable $exception) {
            $this->releaseOwnedLocks();
            throw $exception;
        }
        $this->releasedForProvider = false;
        $this->assertProtectsActiveSession();
    }

    public function release(): void
    {
        $this->releaseOwnedLocks();
        $this->resumeLocks = array();
        $this->releasedForProvider = false;
    }

    private function releaseOwnedLocks(bool $failClosed = false): void
    {
        if ($this->locks === array()) {
            return;
        }
        // Clear first so a shutdown-time database failure cannot cause a second
        // release attempt through the native PHP shutdown callback.
        $locks = array_reverse($this->locks);
        $this->locks = array();
        $failure = null;
        foreach ($locks as $lock) {
            try {
                global $wpdb;
                $released = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
                if ((string) $released !== '1' || WpdbError::has($wpdb)) {
                    throw new RuntimeException('A WooCommerce cart request lock could not be released.');
                }
            } catch (Throwable $exception) {
                // MySQL releases named locks when the connection closes. Keep
                // the failure observable without exposing the session identity.
                $this->logger->error('woo_cart_request_fence_release_failed', array(
                    'type' => get_class($exception),
                    'message' => $exception->getMessage(),
                ));
                $failure = $exception;
            }
        }
        if ($failClosed && $failure instanceof Throwable) {
            throw new RuntimeException('WooCommerce provider-wait cart fence could not be released.', 0, $failure);
        }
    }

    private function acquireOnce(string $lock): void
    {
        if (in_array($lock, $this->locks, true)) {
            return;
        }
        global $wpdb;
        $acquired = $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s,%d)',
            $lock,
            self::LOCK_WAIT_SECONDS
        ));
        if ((string) $acquired !== '1' || WpdbError::has($wpdb)) {
            throw new RuntimeException('The WooCommerce cart is busy in another request.');
        }
        $this->locks[] = $lock;
    }

    private function owns(string $lock): bool
    {
        global $wpdb;
        $owned = $wpdb->get_var($wpdb->prepare(
            'SELECT IF(IS_USED_LOCK(%s)=CONNECTION_ID(),1,0)',
            $lock
        ));
        return (string) $owned === '1' && !WpdbError::has($wpdb);
    }

    /** @return array<int,string> */
    private function inboundSessionIdentities(): array
    {
        $identities = array();
        if (is_user_logged_in()) {
            $userId = (int) get_current_user_id();
            if ($userId > 0) {
                $identities[] = (string) $userId;
            }
        }
        if ($this->cookieCustomerSource !== '') {
            $identities[] = $this->cookieCustomerSource;
        }
        if ($this->queryTokenSource !== '') {
            $identities[] = $this->queryTokenSource;
        }
        if ($this->headerTokenSource !== '') {
            $identities[] = $this->headerTokenSource;
        }
        return array_values(array_unique($identities));
    }

    private function validCookieCustomerId(): string
    {
        return $this->internals->validCookieCustomerId();
    }

    /** @return array<int,string> */
    private function activeSessionIdentities(): array
    {
        $identities = array();
        if (is_user_logged_in()) {
            $userId = (int) get_current_user_id();
            if ($userId > 0) {
                $identities[] = (string) $userId;
            }
        }
        if (is_object(WC()->session)) {
            $customerId = (string) WC()->session->get_customer_id();
            if (
                $customerId !== '' && strlen($customerId) <= 191
                && preg_match('/^[A-Za-z0-9_-]+$/', $customerId) === 1
            ) {
                $identities[] = $customerId;
            }
        }
        return array_values(array_unique($identities));
    }

    private function lockName(string $identity): string
    {
        // MySQL named locks are limited to 64 characters.
        return 'ysai_cart_' . substr(hash(
            'sha256',
            (string) get_current_blog_id() . '|' . $identity
        ), 0, 54);
    }

    private function rowIdentity(string $customerId): string
    {
        return 'session|' . $customerId;
    }

    private function readQueryTokenSource(): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- signed Woo session identity is read-only here, sanitized, and cryptographically validated below.
        $raw = isset($_GET['session']) && is_string($_GET['session'])
            ? wp_unslash($_GET['session'])
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $token = sanitize_text_field($raw);
        if ($token === '') {
            return '';
        }
        if (strlen($token) > 4096) {
            throw new RuntimeException('WooCommerce query cart token is oversized.');
        }
        return $this->validatedTokenUserId($token, true);
    }

    private function readHeaderTokenSource(): string
    {
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the header is sanitized and cryptographically validated as a Woo cart token below.
        $raw = isset($_SERVER['HTTP_CART_TOKEN']) && is_string($_SERVER['HTTP_CART_TOKEN'])
            ? wp_unslash($_SERVER['HTTP_CART_TOKEN'])
            : '';
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $token = sanitize_text_field($raw);
        if ($token === '') {
            return '';
        }
        if (strlen($token) > 4096) {
            throw new RuntimeException('WooCommerce header cart token is oversized.');
        }
        return $this->validatedTokenUserId($token, false);
    }

    private function validatedTokenUserId(string $token, bool $guestOnly): string
    {
        return $this->internals->validatedCartTokenUserId($token, $guestOnly);
    }

    private function guardClonedDestination(string $destination): void
    {
        if ($this->cloneGuarded || !$this->queryCloneExpected) {
            return;
        }
        if (
            $this->internals->guardClonedOperationAuthority(
                WC()->session,
                $this->queryTokenSource,
                $destination
            )
        ) {
            $this->cloneGuarded = true;
        }
    }

    /**
     * True only when the accepted core runtime contract will clone the signed
     * query-token source into the current cookie destination.
     */
    private function queryWillCloneCurrentRequest(): bool
    {
        return $this->internals->queryWillCloneCurrentRequest(
            $this->queryTokenSource,
            $this->cookieCustomerSource,
            $this->serializedArrays
        );
    }

    private function failClosed(): void
    {
        status_header(503);
        if (!headers_sent()) {
            header('Retry-After: ' . (string) self::LOCK_WAIT_SECONDS);
        }
        wp_die(
            'السلة مشغولة بطلب آخر حالياً. أعد المحاولة بعد لحظات.',
            'WooCommerce cart busy',
            array('response' => 503)
        );
    }
}
