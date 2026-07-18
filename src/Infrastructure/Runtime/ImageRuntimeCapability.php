<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Runtime;

use YassinStore\AiAssistant\Application\Ai\ImageAttachmentPolicy;

/** Conservative memory-headroom gate for JSON/base64 image requests. */
final class ImageRuntimeCapability
{
    /** @var callable */ private $limitProvider;
    /** @var callable */ private $usageProvider;

    public function __construct(?callable $limitProvider = null, ?callable $usageProvider = null)
    {
        $this->limitProvider = $limitProvider ?? static function (): string {
            $value = ini_get('memory_limit');
            return is_string($value) ? $value : '';
        };
        $this->usageProvider = $usageProvider ?? static function (): int {
            return memory_get_usage(true);
        };
    }

    public function canAdvertise(): bool
    {
        return $this->hasHeadroom(ImageAttachmentPolicy::ADVERTISE_HEADROOM_BYTES);
    }

    public function canProcess(): bool
    {
        return $this->hasHeadroom(ImageAttachmentPolicy::PROCESS_HEADROOM_BYTES);
    }

    public function canParseBody(int $bodyBytes): bool
    {
        if ($bodyBytes < 262144) {
            return true;
        }
        $required = max(
            4194304,
            ($bodyBytes * 3) + 4194304
        );
        return $this->hasHeadroom($required);
    }

    private function hasHeadroom(int $requiredBytes): bool
    {
        $limit = $this->memoryLimitBytes((string) call_user_func($this->limitProvider));
        if ($limit === null) {
            return true;
        }
        $usage = (int) call_user_func($this->usageProvider);
        return $usage >= 0 && ($limit - $usage) >= $requiredBytes;
    }

    private function memoryLimitBytes(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-1') {
            return null;
        }
        if (!preg_match('/^([0-9]+)([KMG])?$/i', $raw, $matches)) {
            return null;
        }
        $bytes = (int) $matches[1];
        $unit = strtoupper((string) ($matches[2] ?? ''));
        $multipliers = array('' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824);
        if (!isset($multipliers[$unit])) {
            return null;
        }
        $multiplier = $multipliers[$unit];
        if ($bytes > intdiv(PHP_INT_MAX, $multiplier)) {
            return PHP_INT_MAX;
        }
        return $bytes * $multiplier;
    }
}
