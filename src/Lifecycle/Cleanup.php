<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Lifecycle;

use Throwable;
use YassinStore\AiAssistant\Infrastructure\Concurrency\TurnLeaseManager;
use YassinStore\AiAssistant\Infrastructure\Database\ConversationMaintenanceRepository;
use YassinStore\AiAssistant\Application\Port\BrowserContinuityAuthorityPort;
use YassinStore\AiAssistant\Infrastructure\Database\SchemaLifecycle;
use YassinStore\AiAssistant\Infrastructure\Security\IngressRateLimiter;
use YassinStore\AiAssistant\Infrastructure\Security\RateLimiter;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;

final class Cleanup
{
    public const HOOK = 'ysai_daily_cleanup';
    public const CONTINUATION_HOOK = 'ysai_cleanup_continue';

    private const CONVERSATION_TARGET_BATCH_SIZE = 50;
    private const CONVERSATION_CHILD_BATCH_SIZE = 250;
    private const MAX_CONVERSATION_BATCHES = 4;
    private const LEASE_BATCH_SIZE = 250;
    private const RATE_LIMIT_BATCH_SIZE = 1000;
    private const CONTINUITY_BATCH_SIZE = 500;
    private const INGRESS_BATCH_SIZE = 500;
    private const MAX_RUN_SECONDS = 20.0;
    private const CONTINUATION_DELAY_SECONDS = 300;

    /** @var ConversationMaintenanceRepository */ private $conversations;
    /** @var BrowserContinuityAuthorityPort */ private $browserContinuity;
    /** @var TurnLeaseManager */ private $leases;
    /** @var IngressRateLimiter */ private $ingress;
    /** @var RateLimiter */ private $rateLimits;
    /** @var Logger */ private $logger;

    public function __construct(
        ConversationMaintenanceRepository $conversations,
        BrowserContinuityAuthorityPort $browserContinuity,
        TurnLeaseManager $leases,
        IngressRateLimiter $ingress,
        RateLimiter $rateLimits,
        Logger $logger
    ) {
        $this->conversations = $conversations;
        $this->browserContinuity = $browserContinuity;
        $this->leases = $leases;
        $this->ingress = $ingress;
        $this->rateLimits = $rateLimits;
        $this->logger = $logger;
    }

    public function register(): void
    {
        add_action(self::HOOK, array($this, 'run'));
        add_action(self::CONTINUATION_HOOK, array($this, 'run'));
        add_action('init', array(self::class, 'schedule'));
    }

    public function run(): void
    {
        $startedAt = microtime(true);
        $ingressDeleted = 0;
        try {
            // These rows deliberately live outside the assistant schema, so
            // retire them even when the assistant tables are unavailable.
            $ingressDeleted = $this->ingress->cleanupExpired(self::INGRESS_BATCH_SIZE);
            if ($ingressDeleted === self::INGRESS_BATCH_SIZE) {
                self::scheduleContinuation();
            }
        } catch (Throwable $exception) {
            // An options-table cleanup failure must not suppress recovery of
            // durable assistant data when its schema remains healthy.
            $this->logger->error('Public ingress cleanup failed.', array(
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
                'elapsed_ms' => (int) round($this->elapsed($startedAt) * 1000),
            ));
        }

        if (!SchemaLifecycle::verifyRuntime()) {
            $this->logger->debug(
                'Scheduled assistant-table cleanup skipped because the schema is unavailable.',
                array('ingress_limits_deleted' => $ingressDeleted)
            );
            return;
        }

        try {
            // Run every cleanup family at least once before spending the
            // remaining budget on conversation batches. Each repository call
            // is independently bounded, so one cron invocation cannot create
            // an unbounded query loop.
            $leasesDeleted = $this->leases->cleanupExpired(self::LEASE_BATCH_SIZE);
            $rateLimitsDeleted = $this->rateLimits->cleanupExpired(self::RATE_LIMIT_BATCH_SIZE);
            $continuityDeleted = $this->browserContinuity->cleanupExpired(self::CONTINUITY_BATCH_SIZE);

            $conversationsDeleted = 0;
            $conversationRowsDeleted = 0;
            $conversationBatches = 0;
            $lastConversationBatch = null;
            $cleanupDeadline = $startedAt + self::MAX_RUN_SECONDS;
            while (
                $conversationBatches < self::MAX_CONVERSATION_BATCHES
                && $this->elapsed($startedAt) < self::MAX_RUN_SECONDS
            ) {
                $lastConversationBatch = $this->conversations->cleanupExpired(
                    self::CONVERSATION_TARGET_BATCH_SIZE,
                    self::CONVERSATION_CHILD_BATCH_SIZE,
                    $cleanupDeadline
                );
                $conversationsDeleted += $lastConversationBatch->conversationsDeleted();
                $conversationRowsDeleted += $lastConversationBatch->totalRowsDeleted();
                ++$conversationBatches;
                if (!$lastConversationBatch->hasMore()) {
                    break;
                }
                if (!$lastConversationBatch->madeProgress()) {
                    break;
                }
            }

            $conversationWorkRemaining = $lastConversationBatch !== null
                && $lastConversationBatch->hasMore();
            $budgetExhausted = $this->elapsed($startedAt) >= self::MAX_RUN_SECONDS
                || ($lastConversationBatch !== null && $lastConversationBatch->stoppedForDeadline())
                || ($conversationBatches >= self::MAX_CONVERSATION_BATCHES
                    && $conversationWorkRemaining);
            $moreWork = $ingressDeleted === self::INGRESS_BATCH_SIZE
                || $conversationWorkRemaining
                || $leasesDeleted === self::LEASE_BATCH_SIZE
                || $continuityDeleted === self::CONTINUITY_BATCH_SIZE
                || $rateLimitsDeleted === self::RATE_LIMIT_BATCH_SIZE
                || $budgetExhausted;
            if ($moreWork) {
                self::scheduleContinuation();
            }

            $this->logger->debug('Scheduled cleanup completed.', array(
                'conversations_deleted' => $conversationsDeleted,
                'conversation_rows_deleted' => $conversationRowsDeleted,
                'conversation_batches' => $conversationBatches,
                'leases_deleted' => $leasesDeleted,
                'browser_continuity_deleted' => $continuityDeleted,
                'ingress_limits_deleted' => $ingressDeleted,
                'rate_limits_deleted' => $rateLimitsDeleted,
                'budget_exhausted' => $budgetExhausted,
                'continuation_scheduled' => $moreWork,
                'elapsed_ms' => (int) round($this->elapsed($startedAt) * 1000),
            ));
        } catch (Throwable $exception) {
            $this->logger->error('Scheduled cleanup failed.', array(
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
                'elapsed_ms' => (int) round($this->elapsed($startedAt) * 1000),
            ));
        }
    }

    public static function schedule(): void
    {
        if (wp_next_scheduled(self::HOOK) === false) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    public static function scheduleContinuation(): void
    {
        if (wp_next_scheduled(self::CONTINUATION_HOOK) === false) {
            wp_schedule_single_event(
                time() + self::CONTINUATION_DELAY_SECONDS,
                self::CONTINUATION_HOOK
            );
        }
    }

    public static function unschedule(): void
    {
        foreach (array(self::HOOK, self::CONTINUATION_HOOK) as $hook) {
            try {
                // WordPress 6.9 is the first-release floor. Its bulk API takes
                // one finite snapshot of cron entries, so a failed individual
                // unschedule cannot trap deactivation/uninstall in an
                // unbounded wp_next_scheduled() loop. Treat each hook as an
                // independent best-effort stage so one failure never suppresses
                // cleanup of the other hook.
                $cleared = wp_clear_scheduled_hook($hook, array(), true);
                if (
                    is_wp_error($cleared) || $cleared === false
                    || wp_next_scheduled($hook) !== false
                ) {
                    error_log('[YSAI][CLEANUP] Unable to clear scheduled hook: ' . $hook);
                }
            } catch (Throwable $exception) {
                error_log('[YSAI][CLEANUP] Unable to clear scheduled hook: ' . $hook);
            }
        }
    }

    private function elapsed(float $startedAt): float
    {
        return max(0.0, microtime(true) - $startedAt);
    }
}
