<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce;

use RuntimeException;
use Throwable;
use YassinStore\AiAssistant\Support\Json;

/**
 * Closed WooCommerce release policy for the compatibility-sensitive cart core.
 *
 * Versions inside the accepted range are still required to pass the structural
 * runtime contract. Promotion-tested versions are recorded separately so a
 * future compatible patch can boot without being presented as release-tested.
 */
final class WooCommerceCompatibility
{
    public const PROMOTION_TESTED = 'promotion_tested';
    public const ADMITTED_UNPROMOTED = 'admitted_unpromoted';
    public const MISSING = 'missing';
    public const MALFORMED = 'malformed';
    public const TOO_OLD = 'too_old';
    public const TOO_NEW = 'too_new';

    /** @var string */ private $minimum;
    /** @var string */ private $maximumExclusive;
    /** @var string */ private $testedUpTo;
    /** @var array<int,string> */ private $promotionTested;
    /** @var string */ private $wordpressMinimum;
    /** @var string */ private $runtimeContract;

    /** @param array<string,mixed> $contract */
    private function __construct(array $contract)
    {
        $expected = array(
            'schema_version',
            'minimum',
            'maximum_exclusive',
            'tested_up_to',
            'promotion_tested',
            'wordpress_minimum',
            'runtime_contract',
        );
        $keys = array_keys($contract);
        sort($expected, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($keys !== $expected || ($contract['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('WooCommerce compatibility contract is malformed.');
        }

        foreach (array('minimum', 'maximum_exclusive', 'tested_up_to') as $field) {
            if (!is_string($contract[$field]) || !self::isStableVersion($contract[$field])) {
                throw new RuntimeException('WooCommerce compatibility version is malformed: ' . $field . '.');
            }
        }
        if (
            !is_string($contract['wordpress_minimum'])
            || !self::isWordPressVersion($contract['wordpress_minimum'])
        ) {
            throw new RuntimeException('WordPress compatibility version is malformed.');
        }
        if (
            !is_string($contract['runtime_contract'])
            || preg_match('/^[a-z0-9][a-z0-9._-]{2,127}$/D', $contract['runtime_contract']) !== 1
        ) {
            throw new RuntimeException('WooCommerce runtime-contract identifier is malformed.');
        }
        if (!is_array($contract['promotion_tested']) || $contract['promotion_tested'] === array()) {
            throw new RuntimeException('WooCommerce promotion-tested versions are missing.');
        }

        $minimum = $contract['minimum'];
        $maximumExclusive = $contract['maximum_exclusive'];
        $testedUpTo = $contract['tested_up_to'];
        if (version_compare($minimum, $maximumExclusive, '>=')) {
            throw new RuntimeException('WooCommerce compatibility range is empty.');
        }
        if (
            version_compare($testedUpTo, $minimum, '<')
            || version_compare($testedUpTo, $maximumExclusive, '>=')
        ) {
            throw new RuntimeException('WooCommerce tested-up-to version is outside the accepted range.');
        }

        $promotionTested = array();
        foreach ($contract['promotion_tested'] as $version) {
            if (!is_string($version) || !self::isStableVersion($version)) {
                throw new RuntimeException('WooCommerce promotion-tested version is malformed.');
            }
            if (
                version_compare($version, $minimum, '<')
                || version_compare($version, $maximumExclusive, '>=')
            ) {
                throw new RuntimeException('WooCommerce promotion-tested version is outside the accepted range.');
            }
            $promotionTested[] = $version;
        }
        if (count(array_unique($promotionTested)) !== count($promotionTested)) {
            throw new RuntimeException('WooCommerce promotion-tested versions contain duplicates.');
        }
        usort($promotionTested, 'version_compare');
        if ($promotionTested[count($promotionTested) - 1] !== $testedUpTo) {
            throw new RuntimeException('WooCommerce tested-up-to version does not match promotion evidence.');
        }

        $this->minimum = $minimum;
        $this->maximumExclusive = $maximumExclusive;
        $this->testedUpTo = $testedUpTo;
        $this->promotionTested = $promotionTested;
        $this->wordpressMinimum = $contract['wordpress_minimum'];
        $this->runtimeContract = $contract['runtime_contract'];
    }

    public static function fromPluginContract(): self
    {
        $root = defined('YSAI_PLUGIN_DIR')
            ? rtrim((string) YSAI_PLUGIN_DIR, '/\\')
            : dirname(__DIR__, 3);
        $path = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR
            . 'woocommerce-compatibility.json';
        if (!is_readable($path)) {
            throw new RuntimeException('WooCommerce compatibility contract is unavailable.');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('WooCommerce compatibility contract is empty.');
        }
        try {
            $decoded = Json::decodeRequiredObject($raw, 'WooCommerce compatibility contract');
        } catch (Throwable $exception) {
            throw new RuntimeException('WooCommerce compatibility contract is invalid JSON.', 0, $exception);
        }
        return new self($decoded);
    }

    /** @param array<string,mixed> $contract */
    public static function fromArray(array $contract): self
    {
        return new self($contract);
    }

    public function installedVersion(): string
    {
        return defined('WC_VERSION') ? (string) WC_VERSION : '';
    }

    public function statusForInstalledVersion(): string
    {
        return $this->statusForVersion($this->installedVersion());
    }

    public function statusForVersion(string $version): string
    {
        if ($version === '') {
            return self::MISSING;
        }
        if (!self::isStableVersion($version)) {
            return self::MALFORMED;
        }
        if (version_compare($version, $this->minimum, '<')) {
            return self::TOO_OLD;
        }
        if (version_compare($version, $this->maximumExclusive, '>=')) {
            return self::TOO_NEW;
        }
        return $this->isPromotionTested($version)
            ? self::PROMOTION_TESTED
            : self::ADMITTED_UNPROMOTED;
    }

    public function admitsInstalledVersion(): bool
    {
        return $this->admitsVersion($this->installedVersion());
    }

    public function admitsVersion(string $version): bool
    {
        $status = $this->statusForVersion($version);
        return $status === self::PROMOTION_TESTED
            || $status === self::ADMITTED_UNPROMOTED;
    }

    public function isInstalledVersionPromotionTested(): bool
    {
        return $this->isPromotionTested($this->installedVersion());
    }

    public function isPromotionTested(string $version): bool
    {
        return self::isStableVersion($version)
            && in_array($version, $this->promotionTested, true);
    }

    public function assertInstalledVersionAdmitted(): void
    {
        $version = $this->installedVersion();
        if (!$this->admitsVersion($version)) {
            throw new RuntimeException(
                'WooCommerce ' . ($version !== '' ? $version : 'unknown')
                . ' is outside the accepted range ' . $this->rangeLabel() . '.'
            );
        }
    }

    public function minimum(): string
    {
        return $this->minimum;
    }

    public function maximumExclusive(): string
    {
        return $this->maximumExclusive;
    }

    public function testedUpTo(): string
    {
        return $this->testedUpTo;
    }

    public function wordpressMinimum(): string
    {
        return $this->wordpressMinimum;
    }

    public function runtimeContract(): string
    {
        return $this->runtimeContract;
    }

    /** @return array<int,string> */
    public function promotionTestedVersions(): array
    {
        return $this->promotionTested;
    }

    public function rangeLabel(): string
    {
        return '>=' . $this->minimum . ' <' . $this->maximumExclusive;
    }

    private static function isStableVersion(string $version): bool
    {
        return preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $version) === 1;
    }

    private static function isWordPressVersion(string $version): bool
    {
        return preg_match(
            '/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*))?$/D',
            $version
        ) === 1;
    }
}
