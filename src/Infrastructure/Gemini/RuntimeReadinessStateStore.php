<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Gemini;

use RuntimeException;

/** Exact, non-autoloaded WordPress option storage for runtime-readiness state. */
final class RuntimeReadinessStateStore
{
    /** @var string */ private $optionKey;

    public function __construct(string $optionKey)
    {
        $this->optionKey = $optionKey;
    }

    /** @return mixed */
    public function read()
    {
        return get_option($this->optionKey, array());
    }

    /** @return mixed */
    public function readFresh()
    {
        $this->evictOptionCacheEntry();
        return get_option($this->optionKey, array());
    }

    /**
     * @param array<string,mixed> $state
     *
     * A failed replacement normally leaves the previously verified state in
     * place. Callers set $failClosed only when the intended transition revokes
     * authority; in that case an unproved old state is removed before the
     * persistence failure is surfaced.
     */
    public function writeExact(array $state, bool $failClosed = false): void
    {
        $this->evictOptionCacheEntry();
        update_option($this->optionKey, $state, false);
        $stored = $this->readFresh();
        if (is_array($stored) && $stored === $state) {
            return;
        }

        if ($failClosed) {
            $this->deleteExact();
        }
        throw new RuntimeException('Unable to persist the Gemini runtime-readiness state.');
    }

    public function deleteExact(): void
    {
        $this->evictOptionCacheEntry();
        delete_option($this->optionKey);
        $this->evictOptionCacheEntry();
        $sentinel = '__ysai_runtime_readiness_missing__';
        if (get_option($this->optionKey, $sentinel) !== $sentinel) {
            throw new RuntimeException('Unable to remove Gemini runtime-readiness state.');
        }
    }

    /**
     * The option is always written with autoload=false. Remove only its own
     * direct/notoptions entries. If an obsolete cache placed it in alloptions,
     * remove that one key while preserving unrelated autoloaded options.
     */
    private function evictOptionCacheEntry(): void
    {
        wp_cache_delete($this->optionKey, 'options');

        $notOptions = wp_cache_get('notoptions', 'options');
        if (is_array($notOptions) && array_key_exists($this->optionKey, $notOptions)) {
            unset($notOptions[$this->optionKey]);
            wp_cache_set('notoptions', $notOptions, 'options');
        }

        $allOptions = wp_cache_get('alloptions', 'options');
        if (is_array($allOptions) && array_key_exists($this->optionKey, $allOptions)) {
            unset($allOptions[$this->optionKey]);
            wp_cache_set('alloptions', $allOptions, 'options');
        }
    }
}
