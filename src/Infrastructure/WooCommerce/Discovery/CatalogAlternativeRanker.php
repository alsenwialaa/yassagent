<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery;

use YassinStore\AiAssistant\Domain\Shopping\CatalogTextNormalizer;

/** Pure eligibility and ranking policy for already projected live alternatives. */
final class CatalogAlternativeRanker
{
    /** @var CatalogPricePolicy */ private $prices;
    /** @var CatalogTextNormalizer */ private $normalizer;

    public function __construct(CatalogPricePolicy $prices, CatalogTextNormalizer $normalizer)
    {
        $this->prices = $prices;
        $this->normalizer = $normalizer;
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    public function sourceContext(array $source): array
    {
        $slugs = array_values((array) ($source['category_slugs'] ?? array()));
        return array(
            'range' => $this->prices->range($source),
            'attributes' => $this->attributeValues($source),
            'categories' => array_fill_keys($slugs, true),
        );
    }

    /**
     * @param array<int,array<string,mixed>> $products
     * @param array<string,mixed> $args
     * @param array<string,mixed> $source
     * @param array<int,bool> $relatedLookup
     * @return array<int,array<string,mixed>>
     */
    public function rank(
        array $products,
        array $args,
        string $objective,
        array $source,
        array $relatedLookup
    ): array {
        $ranked = array();
        $sourceRange = (array) ($source['range'] ?? array());
        $sourceAttributes = (array) ($source['attributes'] ?? array());
        $sourceCategories = (array) ($source['categories'] ?? array());
        foreach ($products as $product) {
            if (empty($product['purchasable'])) {
                continue;
            }
            $candidateRange = $this->prices->range($product);
            if (
                isset($args['max_price'])
                && (!$candidateRange['known'] || (float) $candidateRange['max'] > (float) $args['max_price'])
            ) {
                continue;
            }
            if ($objective === 'in_stock' && empty($product['in_stock'])) {
                continue;
            }
            if (
                $objective === 'cheaper'
                && (!$sourceRange['known'] || !$candidateRange['known']
                    || (float) $candidateRange['max'] >= (float) $sourceRange['min'])
            ) {
                continue;
            }
            if (
                $objective === 'premium'
                && (!$sourceRange['known'] || !$candidateRange['known']
                    || (float) $candidateRange['min'] <= (float) $sourceRange['max'])
            ) {
                continue;
            }

            $score = 0.0;
            $reasons = array();
            foreach ((array) ($product['category_slugs'] ?? array()) as $slug) {
                if (isset($sourceCategories[$slug])) {
                    $score += 24.0;
                    $reasons['same_category'] = 'same_category';
                }
            }
            $overlap = count(array_intersect($sourceAttributes, $this->attributeValues($product)));
            if ($overlap > 0) {
                $score += min(30.0, $overlap * 5.0);
                $reasons['shared_attributes'] = 'shared_attributes';
            }
            if (!empty($product['in_stock'])) {
                $score += 6.0;
                $reasons['in_stock'] = 'in_stock';
            }
            if (isset($relatedLookup[(int) $product['id']])) {
                $score += 35.0;
                $reasons['woocommerce_related'] = 'woocommerce_related';
            }
            if (
                $objective === 'cheaper' && $sourceRange['known'] && $candidateRange['known']
                && (float) $sourceRange['min'] > 0.0
            ) {
                $score += min(25.0, (((float) $sourceRange['min'] - (float) $candidateRange['max']) / (float) $sourceRange['min']) * 25.0);
                $reasons['lower_price_range'] = 'lower_price_range';
            } elseif ($objective === 'premium' && $sourceRange['known'] && $candidateRange['known']) {
                $score += 12.0;
                $reasons['higher_price_range'] = 'higher_price_range';
            }
            $product['alternative_match'] = array(
                'objective' => $objective,
                'score' => round($score, 3),
                'reasons' => array_values($reasons),
            );
            $ranked[] = $product;
        }
        return $ranked;
    }

    /** @param array<string,mixed> $product @return array<int,string> */
    private function attributeValues(array $product): array
    {
        $values = array();
        foreach ((array) ($product['attributes'] ?? array()) as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $name = $this->normalizer->normalize((string) ($attribute['name'] ?? ''));
            foreach ((array) ($attribute['values'] ?? array()) as $value) {
                $value = $this->normalizer->normalize((string) $value);
                if ($value !== '') {
                    $values[$name !== '' ? $name . '=' . $value : $value] = true;
                }
            }
        }
        return array_keys($values);
    }
}
