<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\PublicHttpUrl;

final class CartSnapshot
{
    private const MAX_PUBLIC_ITEM_COUNT = 2147483647;

    /** @var array<string,CartLine> */ private $lines;
    /** @var array<int,string> */ private $coupons;
    /** @var array<string,mixed> */ private $facts;
    /** @var string */ private $revision;
    /** @var string */ private $restorationRevision;

    /** @param array<string,CartLine> $lines @param array<int,string> $coupons @param array<string,mixed> $facts */
    public function __construct(array $lines, array $coupons, array $facts)
    {
        if (
            ($lines !== array() && Arr::isList($lines)) || !Arr::isList($coupons)
            || ($facts !== array() && Arr::isList($facts))
            || count($lines) > 512 || count($coupons) > 128
        ) {
            throw new InvalidArgumentException('Cart snapshot structure is invalid.');
        }
        $normalizedLines = array();
        foreach ($lines as $key => $line) {
            if (!is_string($key) || !$line instanceof CartLine || !hash_equals($key, $line->key())) {
                throw new InvalidArgumentException('Cart snapshot line authority is invalid.');
            }
            $normalizedLines[$key] = $line;
        }
        ksort($normalizedLines, SORT_STRING);

        $normalizedCoupons = array();
        foreach ($coupons as $coupon) {
            if (!is_string($coupon)) {
                throw new InvalidArgumentException('Cart coupon authority is invalid.');
            }
            $coupon = trim($coupon);
            if ($coupon === '' || strlen($coupon) > 191 || in_array($coupon, $normalizedCoupons, true)) {
                throw new InvalidArgumentException('Cart coupon authority is invalid.');
            }
            $normalizedCoupons[] = $coupon;
        }
        sort($normalizedCoupons, SORT_STRING);

        $this->lines = $normalizedLines;
        $this->coupons = $normalizedCoupons;
        $this->facts = $this->normalizeFacts($facts);
        $this->revision = hash('sha256', Json::canonical($this->authorityArray()));
        $this->restorationRevision = hash('sha256', Json::canonical(array(
            'authority' => $this->authorityArray(),
            'facts' => $this->restorationFacts(),
        )));
    }

    /** @return array<string,CartLine> */ public function lines(): array
    {
        return $this->lines;
    }
    /** @return array<int,string> */ public function coupons(): array
    {
        return $this->coupons;
    }
    /** @return array<string,mixed> */ public function facts(): array
    {
        return $this->facts;
    }
    public function revision(): string
    {
        return $this->revision;
    }
    public function restorationRevision(): string
    {
        return $this->restorationRevision;
    }
    public function isEmpty(): bool
    {
        return $this->lines === array();
    }
    public function line(string $key): ?CartLine
    {
        return $this->lines[$key] ?? null;
    }

    public function restorable(): bool
    {
        foreach ($this->lines as $line) {
            if (!$line->restorable()) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,mixed> */
    public function authorityArray(): array
    {
        $lines = array();
        foreach ($this->lines as $key => $line) {
            $lines[$key] = $line->authorityArray();
        }
        return array('lines' => $lines, 'coupons' => $this->coupons);
    }

    /** @return array<string,mixed> */
    public function toStorageArray(): array
    {
        $lines = array();
        foreach ($this->lines as $key => $line) {
            $lines[$key] = $line->toStorageArray();
        }
        return array(
            'lines' => $lines,
            'coupons' => $this->coupons,
            'facts' => $this->facts,
            'revision' => $this->revision,
            'restoration_revision' => $this->restorationRevision,
        );
    }

    /** @param array<string,mixed> $row */
    public static function fromStorageArray(array $row): self
    {
        self::assertKeys($row, array('lines', 'coupons', 'facts', 'revision', 'restoration_revision'));
        if (
            !is_array($row['lines']) || ($row['lines'] !== array() && Arr::isList($row['lines']))
            || !is_array($row['coupons']) || !Arr::isList($row['coupons'])
            || !is_array($row['facts']) || ($row['facts'] !== array() && Arr::isList($row['facts']))
            || !is_string($row['revision']) || preg_match('/^[a-f0-9]{64}$/', $row['revision']) !== 1
            || !is_string($row['restoration_revision']) || preg_match('/^[a-f0-9]{64}$/', $row['restoration_revision']) !== 1
        ) {
            throw new InvalidArgumentException('Stored cart snapshot is invalid.');
        }
        $lines = array();
        foreach ($row['lines'] as $key => $line) {
            if (!is_string($key) || !is_array($line) || ($line !== array() && Arr::isList($line))) {
                throw new InvalidArgumentException('Stored cart snapshot line is invalid.');
            }
            $lines[$key] = CartLine::fromStorageArray($line);
        }
        $snapshot = new self($lines, $row['coupons'], $row['facts']);
        if (
            !hash_equals($snapshot->revision(), $row['revision'])
            || !hash_equals($snapshot->restorationRevision(), $row['restoration_revision'])
        ) {
            throw new InvalidArgumentException('Stored cart snapshot fingerprint does not match its evidence.');
        }
        return $snapshot;
    }

    /** @return array<string,mixed> */
    public function forClient(bool $includeInternal = false): array
    {
        $items = array();
        foreach ($this->lines as $line) {
            $item = $line->publicFacts();
            if ($includeInternal) {
                $item['cart_item_key'] = $line->key();
                $item['line_fingerprint'] = $line->fingerprint();
            }
            $items[] = $item;
        }
        $facts = $this->facts;
        unset($facts['woocommerce_cart_hash']);
        $facts['items'] = $items;
        $facts['is_empty'] = $this->isEmpty();
        if ($includeInternal) {
            $facts['cart_revision'] = $this->revision;
        }
        return $facts;
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function normalizeFacts(array $facts): array
    {
        self::assertKeys($facts, array(
            'item_count', 'subtotal', 'total', 'formatted_subtotal', 'formatted_total',
            'currency', 'woocommerce_cart_hash', 'cart_url', 'checkout_url',
        ));
        if (
            !is_int($facts['item_count']) || $facts['item_count'] < 0
            || $facts['item_count'] > self::MAX_PUBLIC_ITEM_COUNT
            || !$this->number($facts['subtotal']) || !$this->number($facts['total'])
            || !is_string($facts['formatted_subtotal']) || strlen($facts['formatted_subtotal']) > 2048
            || !is_string($facts['formatted_total']) || strlen($facts['formatted_total']) > 2048
            || !is_string($facts['currency']) || preg_match('/^[A-Z]{3}$/', $facts['currency']) !== 1
            || !is_string($facts['woocommerce_cart_hash']) || strlen($facts['woocommerce_cart_hash']) > 128
            || !PublicHttpUrl::isSafe($facts['cart_url']) || !PublicHttpUrl::isSafe($facts['checkout_url'])
        ) {
            throw new InvalidArgumentException('Cart snapshot facts are invalid.');
        }
        return array(
            'item_count' => $facts['item_count'],
            'subtotal' => (float) $facts['subtotal'],
            'total' => (float) $facts['total'],
            'formatted_subtotal' => $facts['formatted_subtotal'],
            'formatted_total' => $facts['formatted_total'],
            'currency' => $facts['currency'],
            'woocommerce_cart_hash' => $facts['woocommerce_cart_hash'],
            'cart_url' => $facts['cart_url'],
            'checkout_url' => $facts['checkout_url'],
        );
    }

    /** @return array<string,mixed> */
    private function restorationFacts(): array
    {
        return array(
            'item_count' => $this->facts['item_count'],
            'subtotal' => $this->facts['subtotal'],
            'total' => $this->facts['total'],
            'currency' => $this->facts['currency'],
            'woocommerce_cart_hash' => $this->facts['woocommerce_cart_hash'],
        );
    }

    /** @param mixed $value */
    private function number($value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }
    /** @param array<string,mixed> $row @param array<int,string> $expected */
    private static function assertKeys(array $row, array $expected): void
    {
        $keys = array_keys($row);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('Cart snapshot fields are incomplete or unsupported.');
        }
    }
}
