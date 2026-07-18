<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Infrastructure\Database\SchemaLifecycle;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiRuntimeReadiness;
use YassinStore\AiAssistant\Infrastructure\Security\IngressRateLimiter;
use YassinStore\AiAssistant\Infrastructure\Security\RecoveryKey;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Lifecycle\Cleanup;

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/Autoload.php';
\YassinStore\AiAssistant\Autoload::register();

// Version 1.0.0 never enumerates a network. If an activated single site was
// later converted to multisite, uninstall still cleans the current site's
// stores instead of abandoning them merely because the topology changed.

$runStage = static function (string $stage, callable $callback): void {
    try {
        $callback();
    } catch (Throwable $exception) {
        // Uninstall is best-effort across independent stores. Keep the log
        // fixed and context-free; a failure in one stage must never suppress
        // deletion of unrelated data that the administrator opted to remove.
        error_log('[YSAI][UNINSTALL] Stage failed: ' . $stage);
    }
};

$runStage('cron', static function (): void {
    Cleanup::unschedule();
});
$runStage('runtime-readiness', static function (): void {
    GeminiRuntimeReadiness::deleteState();
});
$stored = get_option(Settings::OPTION_KEY, array());
if (!is_array($stored) || empty($stored['delete_data_on_uninstall'])) {
    exit;
}

$runStage('ingress-options', static function (): void {
    IngressRateLimiter::deleteAll();
});
$runStage('assistant-tables', static function (): void {
    SchemaLifecycle::dropAll();
});
$runStage('settings', static function (): void {
    delete_option(Settings::OPTION_KEY);
});
$runStage('recovery-key', static function (): void {
    delete_option(RecoveryKey::OPTION);
});
