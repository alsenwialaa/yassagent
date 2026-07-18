<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Agent;

use YassinStore\AiAssistant\Application\Authority\AuthorityRegistry;
use YassinStore\AiAssistant\Application\Port\ProductCatalogPort;
use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Domain\Exception\SafeCommerceException;
use YassinStore\AiAssistant\Support\PublicHttpUrl;
use YassinStore\AiAssistant\Support\Utf8;
use YassinStore\AiAssistant\Support\TrustedCommerceText;

final class ResponseProjection
{
    /** @var ProductCatalogPort */ private $catalog;

    public function __construct(ProductCatalogPort $catalog)
    {
        $this->catalog = $catalog;
    }

    /**
     * Terminal display evidence is re-read at the last possible boundary.
     * Earlier tool snapshots establish opaque current-turn authority, but they
     * are not allowed to freeze price, stock, visibility, or variation facts
     * for the remainder of a potentially long model turn.
     *
     * @param array<int,mixed> $productRefs
     * @param array<int,mixed> $variationRefs
     * @return array<int,array<string,mixed>>
     */
    public function cards(
        array $productRefs,
        array $variationRefs,
        AuthorityRegistry $authority
    ): array {
        if (count($productRefs) + count($variationRefs) > 8) {
            throw new ContractViolation('response_product_limit_exceeded', 'A terminal response may project at most eight products.');
        }

        $rows = array();
        $seen = array();
        foreach ($productRefs as $ref) {
            $this->assertRef($ref, 'p', $seen);
            $rows[] = $this->liveProductCard($ref, $authority);
        }
        foreach ($variationRefs as $ref) {
            $this->assertRef($ref, 'v', $seen);
            $rows[] = $this->liveVariationCard($ref, $authority);
        }
        return $rows;
    }

    /** @param array<int,string> $visibleRefs @return array<int,array{id:int,name:string}> */
    public function continuity(array $visibleRefs, AuthorityRegistry $authority): array
    {
        $rows = array();
        foreach ($authority->continuityForRefs($visibleRefs) as $row) {
            $id = isset($row['id']) && is_int($row['id']) ? $row['id'] : 0;
            $name = isset($row['name']) && is_string($row['name'])
                ? trim(TrustedCommerceText::decodeEntities($row['name']))
                : '';
            try {
                $validName = $name !== '' && Utf8::codePointLength($name) <= 500;
            } catch (\InvalidArgumentException $exception) {
                $validName = false;
            }
            if ($id < 1 || $id > 9007199254740991 || !$validName) {
                throw new ContractViolation(
                    'response_product_continuity_invalid',
                    'Verified product continuity cannot be projected safely.'
                );
            }
            $rows[] = array('id' => $id, 'name' => $name);
        }
        return $rows;
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    private function productForClient(array $product): array
    {
        $keys = array(
            'id', 'name', 'formatted_price', 'short_description',
            'in_stock', 'requires_variation', 'image', 'permalink',
        );
        foreach ($keys as $key) {
            if (!array_key_exists($key, $product)) {
                throw new ContractViolation('response_product_projection_invalid', 'Verified product facts cannot be projected safely.');
            }
        }
        $id = is_int($product['id']) ? $product['id'] : 0;
        $name = is_string($product['name']) ? trim(TrustedCommerceText::decodeEntities($product['name'])) : '';
        // WooCommerce money is already canonical plain text at its formatter
        // boundary. Preserve it exactly here so a literal nested entity is not
        // decoded a second time during response projection.
        $price = is_string($product['formatted_price']) ? $product['formatted_price'] : null;
        $description = is_string($product['short_description'])
            ? TrustedCommerceText::decodeEntities($product['short_description'])
            : null;
        try {
            $textIsBounded = $name !== ''
                && Utf8::codePointLength($name) <= 500
                && $price !== null && Utf8::codePointLength($price) <= 1000
                && $description !== null && Utf8::codePointLength($description) <= 4000;
        } catch (\InvalidArgumentException $exception) {
            $textIsBounded = false;
        }
        if (
            $id < 1 || $name === ''
            || $id > 9007199254740991
            || !$textIsBounded
            || !is_bool($product['in_stock'])
            || !is_bool($product['requires_variation'])
            || !PublicHttpUrl::isSafe($product['image'], true)
            || !PublicHttpUrl::isSafe($product['permalink'])
        ) {
            throw new ContractViolation('response_product_projection_invalid', 'Verified product facts cannot be projected safely.');
        }
        return array(
            'id' => $id,
            'name' => $name,
            'formatted_price' => $price,
            'short_description' => $description,
            'in_stock' => $product['in_stock'],
            'requires_variation' => $product['requires_variation'],
            'image' => $product['image'],
            'permalink' => $product['permalink'],
        );
    }

    /** @param mixed $ref @param array<string,bool> $seen */
    private function assertRef($ref, string $prefix, array &$seen): void
    {
        if (
            !is_string($ref)
            || preg_match('/^' . $prefix . '[1-9][0-9]{0,5}$/', $ref) !== 1
        ) {
            throw new ContractViolation('response_product_ref_invalid', 'A terminal display reference is malformed.');
        }
        if (isset($seen[$ref])) {
            throw new ContractViolation('response_product_ref_duplicate', 'A terminal response contains a duplicate display reference.');
        }
        $seen[$ref] = true;
    }

    /** @return array<string,mixed> */
    private function liveProductCard(string $ref, AuthorityRegistry $authority): array
    {
        $issued = $authority->requireProduct($ref);
        $productId = (int) ($issued['id'] ?? 0);
        try {
            $live = $this->catalog->get($productId);
        } catch (SafeCommerceException $exception) {
            throw new ContractViolation(
                'response_product_ref_stale',
                'The selected product changed or disappeared. Re-read the live catalog before finishing.'
            );
        }
        if (
            $productId < 1 || (int) ($live['id'] ?? 0) !== $productId
            || $authority->recordProduct($live) !== $ref
        ) {
            throw new ContractViolation(
                'response_product_ref_stale',
                'The selected product no longer matches its current-turn authority.'
            );
        }
        return $this->productForClient($live);
    }

    /** @return array<string,mixed> */
    private function liveVariationCard(string $ref, AuthorityRegistry $authority): array
    {
        $issued = $authority->requireVariation($ref);
        $variationId = (int) ($issued['id'] ?? 0);
        $parentId = (int) ($issued['parent_id'] ?? 0);
        try {
            $parent = $this->catalog->get($parentId);
            $variation = $this->catalog->getVariation($variationId, $parentId);
        } catch (SafeCommerceException $exception) {
            throw new ContractViolation(
                'response_variation_ref_stale',
                'The selected variation changed or disappeared. Re-read its live options before finishing.'
            );
        }
        if (
            $variationId < 1 || $parentId < 1
            || (int) ($parent['id'] ?? 0) !== $parentId
            || (int) ($variation['id'] ?? 0) !== $variationId
            || (int) ($variation['parent_id'] ?? 0) !== $parentId
            || $authority->recordVariation($variation) !== $ref
        ) {
            throw new ContractViolation(
                'response_variation_ref_stale',
                'The selected variation no longer matches its current-turn authority.'
            );
        }
        $authority->recordProduct($parent);

        // Product cards deliberately retain the parent product id and URL so a
        // later quoted-card turn re-enters through a live parent lookup. The
        // visible identity, price, stock, and image are the exact variation.
        $card = $parent;
        $card['id'] = $parentId;
        $card['name'] = $variation['name'] ?? '';
        $card['formatted_price'] = $variation['formatted_price'] ?? '';
        $card['in_stock'] = $variation['in_stock'] ?? null;
        $card['requires_variation'] = false;
        if (
            isset($variation['image']) && is_string($variation['image'])
            && $variation['image'] !== ''
        ) {
            $card['image'] = $variation['image'];
        }
        return $this->productForClient($card);
    }
}
