<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Support;

final class Arr
{
    /**
     * PHP 7.4-compatible list detection.
     *
     * @param array<mixed> $value
     */
    public static function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_item) {
            if ($key !== $expected) {
                return false;
            }
            ++$expected;
        }

        return true;
    }
}
