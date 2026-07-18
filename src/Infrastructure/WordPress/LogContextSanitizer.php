<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WordPress;

/** Produces bounded credential- and contact-safe diagnostic context. */
final class LogContextSanitizer
{
    private const MAX_DEPTH = 6;
    private const MAX_ITEMS = 100;
    private const MAX_STRING_BYTES = 500;

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function sanitize(array $context): array
    {
        $result = array();
        $count = 0;
        foreach ($context as $key => $value) {
            if (!is_string($key) || trim($key) === '') {
                continue;
            }
            if (++$count > self::MAX_ITEMS) {
                $result['context_truncated'] = true;
                break;
            }
            $safeKey = substr((string) preg_replace('/[^A-Za-z0-9_.-]/', '_', $key), 0, 96);
            $result[$safeKey] = $this->sensitiveKey($key)
                ? '[redacted]'
                : $this->value($value, 0, $count);
        }
        return $result;
    }

    /** @param mixed $value @return mixed */
    private function value($value, int $depth, int &$count)
    {
        if ($depth >= self::MAX_DEPTH) {
            return '[truncated-depth]';
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : '[nonfinite-number]';
        }
        if (is_string($value)) {
            return $this->text($value);
        }
        if (is_array($value)) {
            $result = array();
            foreach ($value as $key => $item) {
                if (++$count > self::MAX_ITEMS) {
                    if ($this->isList($value)) {
                        $result[] = '[truncated-items]';
                    } else {
                        $result['context_truncated'] = true;
                    }
                    break;
                }
                if (is_string($key)) {
                    $safeKey = substr((string) preg_replace('/[^A-Za-z0-9_.-]/', '_', $key), 0, 96);
                    $result[$safeKey] = $this->sensitiveKey($key)
                        ? '[redacted]'
                        : $this->value($item, $depth + 1, $count);
                } elseif (is_int($key)) {
                    $result[$key] = $this->value($item, $depth + 1, $count);
                }
            }
            return $result;
        }
        if (is_object($value)) {
            return '[object:' . get_class($value) . ']';
        }
        if (is_resource($value)) {
            return '[resource]';
        }
        return '[unsupported]';
    }

    private function sensitiveKey(string $key): bool
    {
        $key = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $key));
        return preg_match(
            '/(?:^|_)(?:api_?key|authorization|cookie|set_?cookie|token|secret|password|passphrase|credential|attachments?|request_?body|response_?body|session_?value|meta_?value)(?:_|$)/',
            $key
        ) === 1;
    }

    private function text(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $value);
        $value = Redactor::contacts($value);
        $value = (string) preg_replace(
            '/\b(?:AIza[0-9A-Za-z_-]{20,}|sk-[A-Za-z0-9_-]{16,}|Bearer\s+[A-Za-z0-9._~-]{12,}|eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{20,}(?:\.[A-Za-z0-9_-]{20,})?)\b/u',
            '[redacted-secret]',
            $value
        );
        if (strlen($value) > self::MAX_STRING_BYTES) {
            $value = substr($value, 0, self::MAX_STRING_BYTES) . '[truncated]';
        }
        return $value;
    }

    /** @param array<mixed> $value */
    private function isList(array $value): bool
    {
        $index = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $index++) {
                return false;
            }
        }
        return true;
    }
}
