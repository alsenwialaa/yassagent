<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use YassinStore\AiAssistant\Infrastructure\Database\WpdbError;
use RuntimeException;
use YassinStore\AiAssistant\Application\Execution\ExecutionBoundary;
use YassinStore\AiAssistant\Application\Execution\TurnExecutionSupervisor;
use YassinStore\AiAssistant\Domain\Commerce\CartSessionMarker;
use YassinStore\AiAssistant\Domain\Commerce\CartSnapshot;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Infrastructure\Concurrency\TurnLeaseManager;
use YassinStore\AiAssistant\Infrastructure\Database\TransactionManager;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooSession;
use YassinStore\AiAssistant\Support\Json;

/**
 * Fenced adapter for WooCommerce's core database session handler.
 *
 * Custom session stores are rejected because their atomicity, row locking, and
 * independent read-back contracts are unknown. Core session rows are written
 * directly inside the fenced transaction; derived object-cache state is only
 * invalidated outside that transaction.
 */
final class WooSessionCartStore
{
    /** @var WooSession */ private $session;
    /** @var WooCartGateway */ private $gateway;
    /** @var CartItemDataNormalizer */ private $normalizer;
    /** @var SafeSerializedArrayDecoder */ private $decoder;
    /** @var TransactionManager */ private $transactions;
    /** @var TurnLeaseManager */ private $leases;
    /** @var WooSessionOperationMarker */ private $markers;
    /** @var WooPersistentCartStore */ private $persistentCart;
    /** @var WooStorageTopologyPolicy */ private $topology;
    /** @var WooCartRequestFence */ private $requestFence;

    public function __construct(
        WooSession $session,
        WooCartGateway $gateway,
        CartItemDataNormalizer $normalizer,
        SafeSerializedArrayDecoder $decoder,
        TransactionManager $transactions,
        TurnLeaseManager $leases,
        WooSessionOperationMarker $markers,
        WooPersistentCartStore $persistentCart,
        WooStorageTopologyPolicy $topology,
        WooCartRequestFence $requestFence
    ) {
        $this->session = $session;
        $this->gateway = $gateway;
        $this->normalizer = $normalizer;
        $this->decoder = $decoder;
        $this->transactions = $transactions;
        $this->leases = $leases;
        $this->markers = $markers;
        $this->persistentCart = $persistentCart;
        $this->topology = $topology;
        $this->requestFence = $requestFence;
    }

    public function assertSupported(): void
    {
        $this->assertCoreSessionReadable();
        global $wpdb;
        $userMetaTable = $this->persistentCart->enabled()
            ? (isset($wpdb->usermeta) ? (string) $wpdb->usermeta : '')
            : null;
        if ($userMetaTable === '') {
            throw new RuntimeException('WordPress usermeta storage is unavailable for the persistent cart.');
        }
        $this->topology->assertSupported($this->table(), $userMetaTable);
    }

    /** Removes Woo's unfenced shutdown writer before any authoritative mutation. */
    public function beginAuthoritativeMutation(): void
    {
        $this->session->assertVerifiedCartMutationVersion();
        $this->assertSupported();
        $this->session->suppressAutomaticSave();
    }

    public function publishVerifiedCookies(): void
    {
        $this->session->publishVerifiedCartCookies();
    }

    public function workingEnvelope(): WooSessionCartEnvelope
    {
        $this->assertSupported();
        return WooSessionCartEnvelope::fromWorking(
            $this->session,
            $this->normalizer,
            $this->decoder,
            WooSessionOperationMarker::SESSION_KEY
        );
    }

    public function assertWorkingPreState(
        CartSnapshot $expectedState,
        WooSessionCartEnvelope $durableState,
        ?CartSessionMarker $expectedWorkingMarker = null
    ): void {
        $working = $this->workingEnvelope();
        if (
            !hash_equals($working->authorityRevision(), $expectedState->revision())
            || !hash_equals($durableState->authorityRevision(), $expectedState->revision())
            || !hash_equals($working->payloadHash(), $durableState->payloadHash())
        ) {
            throw new RuntimeException('Working and durable Woo session maps differ before cart execution.');
        }
        $workingMarker = $working->marker();
        if ($expectedWorkingMarker === null) {
            if ($workingMarker !== null || $durableState->marker() !== null) {
                throw new RuntimeException('A cart marker exists before operation preparation.');
            }
            return;
        }
        $this->markers->assertAuthentic($expectedWorkingMarker);
        if (
            $workingMarker === null
            || !hash_equals(
                Json::canonical($workingMarker),
                Json::canonical($expectedWorkingMarker->toArray())
            )
        ) {
            throw new RuntimeException('Working Woo session does not contain the exact staged intent marker.');
        }
    }

    /** Stages the final calculated cart without re-running totals hooks. */
    public function stageCurrentWorkingCart(): WooSessionCartEnvelope
    {
        $this->assertSupported();
        $this->gateway->stageCurrentSession();
        return $this->workingEnvelope();
    }

    public function readDurable(): WooSessionCartEnvelope
    {
        $this->assertSupported();
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            'SELECT session_value FROM ' . $this->table() . ' WHERE session_key=%s LIMIT 1',
            $this->sessionKey()
        ));
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to read the durable WooCommerce cart session.');
        }
        return WooSessionCartEnvelope::fromStoredValue(
            is_string($value) ? $value : $this->serializeSessionEntries(array()),
            $this->normalizer,
            $this->decoder,
            WooSessionOperationMarker::SESSION_KEY
        );
    }

    /**
     * Restores the exact request-local cart after a mutation is proved not to
     * have reached durable storage. No business hooks or persistence run here.
     */
    public function restoreWorkingFromDurable(WooSessionCartEnvelope $durable): void
    {
        if ($durable->marker() !== null) {
            throw new RuntimeException('A marked durable cart cannot be used as rollback authority.');
        }
        $this->replaceWorkingFromDurable($durable, 'rollback');
    }

    /** Reloads after provider-wait reacquire; an authentic recovery marker is retained. */
    public function refreshWorkingFromDurable(): WooSessionCartEnvelope
    {
        $this->requestFence->assertProtectsActiveSession();
        $durable = $this->readDurableProjection();
        $this->replaceWorkingFromDurable($durable, 'reacquire');
        return $durable;
    }

    private function replaceWorkingFromDurable(WooSessionCartEnvelope $durable, string $purpose): void
    {
        $entries = $durable->storedEntries();
        $this->session->replaceSessionEntries($entries);
        $this->gateway->restoreWorkingCart(
            $this->decodeEntry($entries, 'cart', 'Durable rollback cart'),
            $this->decodeEntry($entries, 'cart_totals', 'Durable rollback totals'),
            array_values($this->decodeEntry($entries, 'applied_coupons', 'Durable rollback coupons')),
            $this->decodeEntry($entries, 'coupon_discount_totals', 'Durable rollback coupon totals'),
            $this->decodeEntry($entries, 'coupon_discount_tax_totals', 'Durable rollback coupon tax totals'),
            $this->decodeEntry($entries, 'removed_cart_contents', 'Durable rollback removed cart')
        );
        $working = $this->readableWorkingEnvelope();
        if (
            !hash_equals($working->authorityRevision(), $durable->authorityRevision())
            || !hash_equals($working->payloadHash(), $durable->payloadHash())
            || $working->storedEntries() !== $durable->storedEntries()
        ) {
            throw new RuntimeException('Request-local cart ' . $purpose . ' did not reproduce durable authority.');
        }
        $this->session->markSessionClean();
    }

    /** Core-handler read-back used for live read-only projections. */
    private function readDurableProjection(): WooSessionCartEnvelope
    {
        $this->assertCoreSessionReadable();
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            'SELECT session_value FROM ' . $this->table() . ' WHERE session_key=%s LIMIT 1',
            $this->sessionKey()
        ));
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to read the durable WooCommerce cart session.');
        }
        return WooSessionCartEnvelope::fromStoredValue(
            is_string($value) ? $value : $this->serializeSessionEntries(array()),
            $this->normalizer,
            $this->decoder,
            WooSessionOperationMarker::SESSION_KEY
        );
    }

    private function readableWorkingEnvelope(): WooSessionCartEnvelope
    {
        $this->assertCoreSessionReadable();
        return WooSessionCartEnvelope::fromWorking(
            $this->session,
            $this->normalizer,
            $this->decoder,
            WooSessionOperationMarker::SESSION_KEY
        );
    }

    private function assertCoreSessionReadable(): void
    {
        $this->session->ensure();
        if (!$this->session->hasCoreSessionHandler()) {
            throw new RuntimeException('The active WooCommerce session store cannot provide a durable cart snapshot.');
        }
        if ($this->sessionKey() === '') {
            throw new RuntimeException('The active WooCommerce core session has no durable key.');
        }
        $this->session->sessionEntries();
        $this->session->sessionExpiration();
    }

    public function persistAndReadBack(
        CartSnapshot $expectedPre,
        CartSnapshot $expectedPost,
        array $expectedEffect,
        WooSessionCartEnvelope $expectedDurablePre,
        CartSessionMarker $sealedMarker,
        TurnLease $conversationLease,
        TurnLease $commerceLease,
        ?TurnExecutionSupervisor $supervisor = null
    ): WooSessionCartEnvelope {
        if (
            $sealedMarker->phase() !== CartSessionMarker::SEALED
            || !hash_equals($sealedMarker->preRevision(), $expectedPre->revision())
            || !hash_equals($sealedMarker->postRevision(), $expectedPost->revision())
            || !hash_equals($sealedMarker->postRestorationRevision(), $expectedPost->restorationRevision())
            || !hash_equals(
                $sealedMarker->effectHash(),
                hash('sha256', Json::canonical($expectedEffect))
            )
            || $sealedMarker->conversationFence() !== $conversationLease->fence()
            || !hash_equals($sealedMarker->commerceResourceHash(), $commerceLease->resourceHash())
            || $sealedMarker->commerceFence() !== $commerceLease->fence()
            || !hash_equals($expectedDurablePre->authorityRevision(), $expectedPre->revision())
        ) {
            throw new RuntimeException('Sealed cart marker does not match the persistence boundary.');
        }
        $this->markers->assertAuthentic($sealedMarker);
        $this->assertSupported();

        if ($supervisor !== null) {
            // The executor has already mutated the request-local cart. Lease
            // renewal here would extend authority after a side effect began.
            $conversationLease = $supervisor->before(
                ExecutionBoundary::WOO_SESSION_SAVE,
                null,
                false
            );
        }
        // Remove any stale cache entry before the database lock. A concurrent
        // reader may repopulate the old row while this transaction is open,
        // so the cache is invalidated again after commit.
        $this->invalidateCoreSessionCache();
        try {
            $stored = $this->transactions->run(function () use (
                $expectedPre,
                $expectedPost,
                $expectedDurablePre,
                $sealedMarker,
                $conversationLease,
                $commerceLease
            ): WooSessionCartEnvelope {
            // Fixed lock order: conversation lease, commerce lease, session row.
                $this->leases->assertCurrentForUpdate($conversationLease);
                $this->leases->assertCurrentForUpdate($commerceLease);
                $this->requestFence->assertProtectsActiveSession();
                $before = $this->readLocked();
                if (
                    !hash_equals($before->authorityRevision(), $expectedPre->revision())
                    || !hash_equals($before->payloadHash(), $expectedDurablePre->payloadHash())
                    || $before->storedEntries() !== $expectedDurablePre->storedEntries()
                ) {
                    throw new RuntimeException('Durable WooCommerce session changed before the fenced write.');
                }

                $working = $this->workingEnvelope();
                if (
                    !hash_equals($working->authorityRevision(), $expectedPost->revision())
                    || !hash_equals($working->payloadHash(), $sealedMarker->cartPayloadHash())
                ) {
                    throw new RuntimeException('Working Woo session does not match the sealed cart post-state.');
                }
                $workingMarker = $working->marker();
                if (
                    $workingMarker === null
                    || !hash_equals(Json::canonical($workingMarker), Json::canonical($sealedMarker->toArray()))
                ) {
                    throw new RuntimeException('Working Woo session does not contain the sealed marker.');
                }

                $this->requestFence->assertProtectsActiveSession();
                $this->writeWorkingSessionForUpdate($working->storedEntries());
                $this->requestFence->assertProtectsActiveSession();
                $this->persistentCart->persistAndVerifyForUpdate();
                global $wpdb;
                if (WpdbError::has($wpdb)) {
                    throw new RuntimeException('WooCommerce session save reported a database failure.');
                }
                $stored = $this->readLocked();
                if (
                    !hash_equals($stored->authorityRevision(), $expectedPost->revision())
                    || !hash_equals($stored->payloadHash(), $sealedMarker->cartPayloadHash())
                ) {
                    throw new RuntimeException('Durable Woo session read-back does not match the sealed cart state.');
                }
                $storedMarker = $stored->marker();
                if (
                    $storedMarker === null
                    || !hash_equals(Json::canonical($storedMarker), Json::canonical($sealedMarker->toArray()))
                ) {
                    throw new RuntimeException('Durable Woo session read-back does not contain the sealed marker.');
                }
                $this->markers->parseAndVerify($storedMarker);
                return $stored;
            });
            $this->invalidateCoreSessionCache();
            $this->persistentCart->invalidateCache();
            $this->session->markSessionClean();
            return $stored;
        } finally {
            if ($supervisor !== null) {
                $supervisor->after(ExecutionBoundary::WOO_SESSION_SAVE, true);
            }
        }
    }

    public function clearMarker(
        CartSnapshot $expectedState,
        CartSessionMarker $marker,
        TurnLease $conversationLease,
        TurnLease $commerceLease,
        ?TurnExecutionSupervisor $supervisor = null,
        ?callable $afterClear = null
    ): void {
        $this->markers->assertAuthentic($marker);
        if ($supervisor !== null) {
            // The cart effect is already durable. Cleanup may proceed only
            // within the existing grant; it must never renew after that effect.
            $conversationLease = $supervisor->before(
                ExecutionBoundary::WOO_SESSION_SAVE,
                null,
                false
            );
        }
        try {
            $this->transactions->run(function () use (
                $expectedState,
                $marker,
                $conversationLease,
                $commerceLease,
                $afterClear
            ): void {
                $this->leases->assertCurrentForUpdate($conversationLease);
                $this->leases->assertCurrentForUpdate($commerceLease);
                $before = $this->readLocked();
                $storedMarker = $before->marker();
                if (
                    $storedMarker === null
                    || !hash_equals(Json::canonical($storedMarker), Json::canonical($marker->toArray()))
                ) {
                    throw new RuntimeException('Verified cart marker cleanup target no longer matches.');
                }
                if ($marker->phase() === CartSessionMarker::INTENT) {
                    if (
                        !hash_equals($before->authorityRevision(), $expectedState->revision())
                        || !hash_equals($marker->preRevision(), $expectedState->revision())
                        || !hash_equals($marker->preRestorationRevision(), $expectedState->restorationRevision())
                    ) {
                        throw new RuntimeException('Intent marker cleanup is not over its exact pre-state.');
                    }
                } elseif ($marker->phase() === CartSessionMarker::SEALED) {
                    if (
                        !hash_equals($before->authorityRevision(), $expectedState->revision())
                        || !hash_equals($before->payloadHash(), $marker->cartPayloadHash())
                        || !hash_equals($marker->postRevision(), $expectedState->revision())
                        || !hash_equals($marker->postRestorationRevision(), $expectedState->restorationRevision())
                    ) {
                        throw new RuntimeException('Sealed marker cleanup evidence is inconsistent.');
                    }
                } else {
                    throw new RuntimeException('Cart marker cleanup phase is invalid.');
                }
                $entries = $before->storedEntries();
                unset($entries[WooSessionOperationMarker::SESSION_KEY]);
                global $wpdb;
                $this->requestFence->assertProtectsActiveSession();
                $updated = $wpdb->query($wpdb->prepare(
                    'UPDATE ' . $this->table() . ' SET session_value=%s WHERE session_key=%s',
                    $this->serializeSessionEntries($entries),
                    $this->sessionKey()
                ));
                if ($updated !== 1 || WpdbError::has($wpdb)) {
                    throw new RuntimeException('Verified cart marker cleanup could not update the core session row.');
                }
                $after = $this->readLocked();
                if (
                    !hash_equals($after->authorityRevision(), $before->authorityRevision())
                    || !hash_equals($after->payloadHash(), $before->payloadHash())
                    || $after->marker() !== null
                ) {
                    throw new RuntimeException('Verified cart marker cleanup was not durably persisted.');
                }
                if ($afterClear !== null) {
                    $afterClear();
                }
            });
            $this->invalidateCoreSessionCache();
            // Keep this request's working copy aligned. Automatic persistence
            // was removed before the authoritative operation began.
            $this->markers->clear();
            $this->session->markSessionClean();
        } finally {
            if ($supervisor !== null) {
                $supervisor->after(ExecutionBoundary::WOO_SESSION_SAVE, true);
            }
        }
    }

    /**
     * Re-proves the exact marker-free durable envelope while the caller's
     * fenced database transaction is holding both lease rows. The session row
     * lock remains held through the caller's operation-terminal write.
     */
    public function assertDurableFinalStateForUpdate(
        CartSnapshot $expectedState,
        WooSessionCartEnvelope $expectedEnvelope
    ): void {
        if (
            $expectedEnvelope->marker() !== null
            || !hash_equals($expectedEnvelope->authorityRevision(), $expectedState->revision())
        ) {
            throw new RuntimeException('Expected final Woo session evidence is not marker-free and canonical.');
        }
        $this->assertSupported();
        $this->requestFence->assertProtectsActiveSession();
        $locked = $this->readLocked();
        if (
            $locked->marker() !== null
            || !hash_equals($locked->authorityRevision(), $expectedState->revision())
            || !hash_equals($locked->payloadHash(), $expectedEnvelope->payloadHash())
            || $locked->storedEntries() !== $expectedEnvelope->storedEntries()
        ) {
            throw new RuntimeException('Durable Woo session changed before operation finalization.');
        }
        // A verified operation for an authenticated customer has two durable
        // projections. Lock and re-prove usermeta in this same transaction;
        // do not repair or overwrite it while deciding whether success is true.
        $this->requestFence->assertProtectsActiveSession();
        $this->persistentCart->assertVerifiedForUpdate();
    }

    private function readLocked(): WooSessionCartEnvelope
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            'SELECT session_value FROM ' . $this->table() . ' WHERE session_key=%s LIMIT 1 FOR UPDATE',
            $this->sessionKey()
        ));
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to lock the durable WooCommerce session row.');
        }
        return WooSessionCartEnvelope::fromStoredValue(
            is_string($value) ? $value : $this->serializeSessionEntries(array()),
            $this->normalizer,
            $this->decoder,
            WooSessionOperationMarker::SESSION_KEY
        );
    }

    /** @param array<string,mixed> $entries */
    private function writeWorkingSessionForUpdate(array $entries): void
    {
        global $wpdb;
        $written = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $this->table()
            . ' (session_key,session_value,session_expiry) VALUES (%s,%s,%d)'
            . ' ON DUPLICATE KEY UPDATE session_value=VALUES(session_value),session_expiry=VALUES(session_expiry)',
            $this->sessionKey(),
            $this->serializeSessionEntries($entries),
            $this->session->sessionExpiration()
        ));
        if ($written === false || WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to persist the fenced WooCommerce session row.');
        }
    }

    /** @param array<string,mixed> $entries */
    private function serializeSessionEntries(array $entries): string
    {
        return (string) maybe_serialize($entries);
    }

    /** @param array<string,mixed> $entries @return array<string|int,mixed> */
    private function decodeEntry(array $entries, string $key, string $label): array
    {
        $value = $entries[$key] ?? '';
        if (!is_string($value)) {
            throw new RuntimeException($label . ' is malformed.');
        }
        return $this->decoder->decode($value, $label, true);
    }

    private function invalidateCoreSessionCache(): void
    {
        $this->session->invalidateSessionCache($this->sessionKey());
    }

    private function sessionKey(): string
    {
        return is_object(WC()->session) ? (string) WC()->session->get_customer_id() : '';
    }

    private function table(): string
    {
        return $this->session->sessionTableName();
    }
}
