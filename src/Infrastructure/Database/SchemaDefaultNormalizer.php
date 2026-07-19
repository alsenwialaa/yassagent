<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

/**
 * Converts database metadata defaults into the semantic values declared by the
 * application schema.
 *
 * MariaDB exposes SQL NULL as the string "NULL" and string literals in their
 * quoted SQL representation. Those are metadata encodings, not application
 * values, and must be decoded before exact comparison.
 */
final class SchemaDefaultNormalizer
{
    /** @param mixed $value */
    public static function expected($value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /** @param mixed $value */
    public static function observed($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $observed = (string) $value;
        if (strcasecmp(trim($observed), 'NULL') === 0) {
            return null;
        }

        $length = strlen($observed);
        if ($length >= 2 && $observed[0] === "'" && $observed[$length - 1] === "'") {
            return self::decodeSqlStringLiteral(substr($observed, 1, -1));
        }

        return $observed;
    }

    private static function decodeSqlStringLiteral(string $literal): string
    {
        $decoded = '';
        $length = strlen($literal);
        for ($index = 0; $index < $length; ++$index) {
            $character = $literal[$index];
            if ($character === "'" && $index + 1 < $length && $literal[$index + 1] === "'") {
                $decoded .= "'";
                ++$index;
                continue;
            }
            if ($character !== '\\' || $index + 1 >= $length) {
                $decoded .= $character;
                continue;
            }

            $escaped = $literal[++$index];
            $map = array(
                '0' => "\0",
                "'" => "'",
                '"' => '"',
                'b' => "\x08",
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'Z' => "\x1A",
                '\\' => '\\',
            );
            $decoded .= array_key_exists($escaped, $map)
                ? $map[$escaped]
                : '\\' . $escaped;
        }

        return $decoded;
    }
}
