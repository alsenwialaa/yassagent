<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Authority;

use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Domain\Commerce\VariableProductLimits;

/**
 * Current-turn opaque authority only.
 *
 * Product, variation, and cart-item facts enter this registry exclusively from
 * live server tools. Display labels are projections and are never accepted as
 * execution input.
 */
final class AuthorityRegistry
{
    // One turn can legitimately inspect a complete 512-line cart and one or
    // more bounded variable-product catalogs. Keep the in-memory authority envelope aligned
    // with those public tool contracts instead of failing at the old 128-ref
    // ceiling. Provider feedback remains independently byte-bounded.
    private const MAX_CURRENT_TURN_REFS = 4096;

    /** @var array<string,array{kind:string,data:array<string,mixed>}> */
    private $refs = array();
    /** @var array<string,string> */
    private $identityRefs = array();
    /** @var array<string,int> */
    private $counters = array('product' => 0, 'variation' => 0, 'cart_item' => 0, 'content' => 0);
    /** @var array<int,array{epoch:string,ids:array<int,bool>}> */
    private $variationCatalogs = array();

    /** @param array<string,mixed> $product */
    public function recordProduct(array $product): string
    {
        $id = (int) ($product['id'] ?? 0);
        if ($id < 1) {
            throw new ContractViolation('product_authority_invalid', 'A live product authority requires a numeric product id.');
        }
        return $this->record('product', 'product:' . $id, $product);
    }

    /** @param array<string,mixed> $variation */
    public function recordVariation(array $variation): string
    {
        $id = (int) ($variation['id'] ?? 0);
        $parentId = (int) ($variation['parent_id'] ?? 0);
        if ($id < 1 || $parentId < 1) {
            throw new ContractViolation('variation_authority_invalid', 'A live variation authority requires variation and parent ids.');
        }
        return $this->record('variation', 'variation:' . $id, $variation);
    }

    /** @param array<string,mixed> $item */
    private function recordCartItem(array $item): string
    {
        $key = trim((string) ($item['cart_item_key'] ?? ''));
        $fingerprint = trim((string) ($item['line_fingerprint'] ?? ''));
        if ($key === '' || $fingerprint === '') {
            throw new ContractViolation('cart_item_authority_invalid', 'A live cart item authority requires its key and line fingerprint.');
        }
        $identity = 'cart:' . $key . '|' . $fingerprint;
        $prefix = 'cart:' . $key . '|';
        foreach ($this->identityRefs as $knownIdentity => $knownRef) {
            if (strpos($knownIdentity, $prefix) !== 0 || hash_equals($knownIdentity, $identity)) {
                continue;
            }
            unset($this->identityRefs[$knownIdentity], $this->refs[$knownRef]);
        }
        return $this->record('cart_item', $identity, $item);
    }

    /**
     * Replaces cart-line authority as one complete live epoch. A later cart
     * read invalidates every earlier line ref, including lines that vanished
     * and older fingerprints for lines that changed.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,string>
     */
    public function recordCartSnapshot(array $items): array
    {
        if (!Arr::isList($items) || count($items) > 512) {
            throw new ContractViolation(
                'cart_item_authority_invalid',
                'A live cart snapshot exceeds its authority contract.'
            );
        }
        $refsBefore = $this->refs;
        $identitiesBefore = $this->identityRefs;
        $countersBefore = $this->counters;
        try {
            foreach ($this->refs as $ref => $entry) {
                if ($entry['kind'] === 'cart_item') {
                    unset($this->refs[$ref]);
                }
            }
            foreach ($this->identityRefs as $identity => $ref) {
                if (strpos($identity, 'cart:') === 0) {
                    unset($this->identityRefs[$identity]);
                }
            }
            $issued = array();
            foreach ($items as $item) {
                if (!is_array($item) || $item === array()) {
                    throw new ContractViolation(
                        'cart_item_authority_invalid',
                        'A live cart snapshot contains a malformed item.'
                    );
                }
                $issued[] = $this->recordCartItem($item);
            }
            return $issued;
        } catch (\Throwable $exception) {
            $this->refs = $refsBefore;
            $this->identityRefs = $identitiesBefore;
            $this->counters = $countersBefore;
            throw $exception;
        }
    }


    /** @param array<string,mixed> $content */
    public function recordContent(array $content): string
    {
        $id = (int) ($content['id'] ?? 0);
        if ($id < 1) {
            throw new ContractViolation('content_authority_invalid', 'Public content authority requires a numeric internal id.');
        }
        return $this->record('content', 'content:' . $id, $content);
    }

    /** @return array<string,mixed> */
    public function requireContent(string $ref): array
    {
        return $this->requireRef($ref, 'content');
    }

    /** @return array<string,mixed> */
    public function requireProduct(string $ref): array
    {
        return $this->requireRef($ref, 'product');
    }

    /** @return array<string,mixed> */
    public function requireVariation(string $ref): array
    {
        return $this->requireRef($ref, 'variation');
    }

    /** @return array<string,mixed> */
    public function requireCartItem(string $ref): array
    {
        return $this->requireRef($ref, 'cart_item');
    }

    /** Records one complete live variable-product catalog atomically. @param array<int,array<string,mixed>> $variations @return array<int,string> */
    public function recordVariationCatalog(
        int $parentId,
        array $variations,
        string $authorityEpoch
    ): array {
        $authorityEpoch = strtolower(trim($authorityEpoch));
        if (
            $parentId < 1 || !Arr::isList($variations)
            || $variations === array()
            || count($variations) > VariableProductLimits::MAX_VARIATIONS
            || preg_match('/^[a-f0-9]{64}$/', $authorityEpoch) !== 1
        ) {
            throw new ContractViolation(
                'variation_catalog_authority_invalid',
                'A complete live variation catalog has invalid identity or bounds.'
            );
        }
        $refsBefore = $this->refs;
        $identitiesBefore = $this->identityRefs;
        $countersBefore = $this->counters;
        $catalogsBefore = $this->variationCatalogs;
        try {
            $issued = array();
            $ids = array();
            foreach ($variations as $variation) {
                if (
                    !is_array($variation)
                    || (int) ($variation['parent_id'] ?? 0) !== $parentId
                ) {
                    throw new ContractViolation(
                        'variation_catalog_authority_invalid',
                        'A complete live variation catalog contains a foreign or malformed row.'
                    );
                }
                $variationId = (int) ($variation['id'] ?? 0);
                if ($variationId < 1 || isset($ids[$variationId])) {
                    throw new ContractViolation(
                        'variation_catalog_authority_invalid',
                        'A complete live variation catalog contains duplicate identity.'
                    );
                }
                $issued[] = $this->recordVariation($variation);
                $ids[$variationId] = true;
            }
            ksort($ids, SORT_NUMERIC);
            $this->variationCatalogs[$parentId] = array(
                'epoch' => $authorityEpoch,
                'ids' => $ids,
            );
            return $issued;
        } catch (\Throwable $exception) {
            $this->refs = $refsBefore;
            $this->identityRefs = $identitiesBefore;
            $this->counters = $countersBefore;
            $this->variationCatalogs = $catalogsBefore;
            throw $exception;
        }
    }

    /** @return array{variations:array<int,array<string,mixed>>,total:int,complete:bool,epoch:string} */
    public function variationCatalogForProduct(int $parentId): array
    {
        $catalog = $this->variationCatalogs[$parentId] ?? null;
        if ($parentId < 1 || !is_array($catalog)) {
            throw new ContractViolation(
                'cart_clarification_variation_catalog_missing',
                'The complete current live variation catalog must be resolved before asking for an option.'
            );
        }
        $rows = array();
        foreach (array_keys($catalog['ids']) as $variationId) {
            $ref = $this->identityRefs['variation:' . $variationId] ?? '';
            if (
                $ref !== '' && isset($this->refs[$ref])
                && $this->refs[$ref]['kind'] === 'variation'
            ) {
                $rows[] = $this->refs[$ref]['data'];
            }
        }
        return array(
            'variations' => $rows,
            'total' => count($catalog['ids']),
            'complete' => count($rows) === count($catalog['ids']),
            'epoch' => $catalog['epoch'],
        );
    }

    public function variationBelongsToCatalog(
        int $parentId,
        int $variationId,
        string $authorityEpoch
    ): bool {
        $catalog = $this->variationCatalogs[$parentId] ?? null;
        $authorityEpoch = strtolower(trim($authorityEpoch));
        return $parentId > 0 && $variationId > 0
            && is_array($catalog)
            && preg_match('/^[a-f0-9]{64}$/', $authorityEpoch) === 1
            && hash_equals($catalog['epoch'], $authorityEpoch)
            && isset($catalog['ids'][$variationId]);
    }

    /** @param array<int,string> $refs @return array<int,array{id:int,name:string}> */
    public function continuityForRefs(array $refs): array
    {
        $rows = array();
        $seen = array();
        foreach ($refs as $ref) {
            if (!is_string($ref) || !isset($this->refs[$ref])) {
                continue;
            }
            $entry = $this->refs[$ref];
            $product = null;
            if ($entry['kind'] === 'product') {
                $product = $entry['data'];
            }
            if ($entry['kind'] === 'variation') {
                $parentId = (int) ($entry['data']['parent_id'] ?? 0);
                $parentRef = $this->identityRefs['product:' . $parentId] ?? '';
                $product = $parentRef !== '' && isset($this->refs[$parentRef]) ? $this->refs[$parentRef]['data'] : null;
            }
            if ($entry['kind'] === 'cart_item') {
                $productId = (int) ($entry['data']['product_id'] ?? 0);
                $parentRef = $this->identityRefs['product:' . $productId] ?? '';
                $product = $parentRef !== '' && isset($this->refs[$parentRef]) ? $this->refs[$parentRef]['data'] : null;
                if ($product === null && $productId > 0) {
                    $product = array('id' => $productId, 'name' => (string) ($entry['data']['name'] ?? ''));
                }
            }
            if (!is_array($product)) {
                continue;
            }
            $id = (int) ($product['id'] ?? 0);
            $name = trim((string) ($product['name'] ?? ''));
            if ($id > 0 && $name !== '' && !isset($seen[$id])) {
                $seen[$id] = true;
                $rows[] = array('id' => $id, 'name' => $name);
            }
        }
        return array_slice($rows, 0, 8);
    }

    /** @return array<string,mixed> */
    private function requireRef(string $ref, string $expectedKind): array
    {
        if ($ref === '' || trim($ref) !== $ref || !isset($this->refs[$ref]) || $this->refs[$ref]['kind'] !== $expectedKind) {
            throw new ContractViolation(
                'authority_ref_invalid',
                'The supplied ' . $expectedKind . ' reference is not verified in the current turn.'
            );
        }
        return $this->refs[$ref]['data'];
    }

    /** @param array<string,mixed> $data */
    private function record(string $kind, string $identity, array $data): string
    {
        if (
            !isset($this->counters[$kind]) || $identity === ''
            || ($data !== array() && Arr::isList($data))
        ) {
            throw new ContractViolation('authority_capacity_invalid', 'Current-turn authority is invalid.');
        }
        if (isset($this->identityRefs[$identity])) {
            $ref = $this->identityRefs[$identity];
            $this->refs[$ref]['data'] = $data;
            return $ref;
        }
        if (count($this->refs) >= self::MAX_CURRENT_TURN_REFS) {
            throw new ContractViolation(
                'authority_capacity_invalid',
                'Current-turn authority exceeds its safe bound.'
            );
        }
        ++$this->counters[$kind];
        $prefix = $kind === 'product' ? 'p' : ($kind === 'variation' ? 'v' : ($kind === 'cart_item' ? 'c' : 'd'));
        $ref = $prefix . (string) $this->counters[$kind];
        $this->refs[$ref] = array('kind' => $kind, 'data' => $data);
        $this->identityRefs[$identity] = $ref;
        return $ref;
    }
}
