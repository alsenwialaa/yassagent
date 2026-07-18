<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Support;

use RuntimeException;

/** Content identity for immutable public asset URLs. */
final class AssetVersion
{
    /** @var array<string,string> */
    private static $versions = array();

    public static function for(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');
        if (
            $relativePath === ''
            || strlen($relativePath) > 200
            || preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $relativePath) !== 1
            || strpos($relativePath, '..') !== false
        ) {
            throw new RuntimeException('Packaged asset path is invalid.');
        }
        if (isset(self::$versions[$relativePath])) {
            return self::$versions[$relativePath];
        }

        $path = YSAI_PLUGIN_DIR . $relativePath;
        $digest = is_file($path) ? hash_file('sha256', $path) : false;
        if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/', $digest) !== 1) {
            throw new RuntimeException('Packaged asset content is unavailable.');
        }

        self::$versions[$relativePath] = YSAI_VERSION . '-' . substr($digest, 0, 16);
        return self::$versions[$relativePath];
    }
}
