<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WordPress;

use YassinStore\AiAssistant\Application\Port\LoggerPort;
use Throwable;
use YassinStore\AiAssistant\Support\Json;

final class Logger implements LoggerPort
{
    /** @var Settings */
    private $settings;
    /** @var LogContextSanitizer */
    private $sanitizer;

    public function __construct(Settings $settings, ?LogContextSanitizer $sanitizer = null)
    {
        $this->settings = $settings;
        $this->sanitizer = $sanitizer !== null ? $sanitizer : new LogContextSanitizer();
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = array()): void
    {
        // Operational error names remain available even when diagnostics are
        // disabled, but variable context is opt-in. This preserves a minimal
        // failure signal without contradicting the administrator setting or
        // exposing provider/runtime values by default.
        $this->write(
            'error',
            $message,
            (bool) $this->settings->get('diagnostic_logging', 0) ? $context : array()
        );
    }

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = array()): void
    {
        if (!(bool) $this->settings->get('diagnostic_logging', 0)) {
            return;
        }
        $this->write('debug', $message, $context);
    }

    /** @param array<string,mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        try {
            if ($context === array()) {
                error_log('[YSAI][' . strtoupper($level) . '] ' . $message);
                return;
            }
            $safe = $this->sanitizer->sanitize($context);
            error_log('[YSAI][' . strtoupper($level) . '] ' . $message . ' ' . Json::encodeObject($safe));
        } catch (Throwable $exception) {
            error_log('[YSAI][' . strtoupper($level) . '] ' . $message);
        }
    }
}
