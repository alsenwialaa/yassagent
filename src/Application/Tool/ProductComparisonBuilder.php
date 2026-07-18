<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool;

use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Domain\Shopping\CatalogTextNormalizer;
use YassinStore\AiAssistant\Support\Json;

/** Builds a bounded, normalized, fact-only comparison matrix for the model. */
final class ProductComparisonBuilder
{
    /** @var CatalogTextNormalizer */ private $text;

    public function __construct(?CatalogTextNormalizer $text = null)
    {
        $this->text = $text !== null ? $text : new CatalogTextNormalizer();
    }

    /** @param array<int,array<string,mixed>> $products @return array<string,mixed> */
    public function build(array $products): array
    {
        if (count($products) < 2 || count($products) > 4) {
            throw new ContractViolation('comparison_product_count_invalid', 'A comparison requires two to four products.');
        }
        $rows = array();
        $attributeMatrix = array();
        $refs = array();
        foreach ($products as $product) {
            $ref = isset($product['product_ref']) && is_string($product['product_ref']) ? $product['product_ref'] : '';
            $name = isset($product['name']) && is_string($product['name']) ? trim($product['name']) : '';
            if ($ref === '' || $name === '' || isset($refs[$ref])) {
                throw new ContractViolation('comparison_product_invalid', 'Comparison product facts are incomplete or duplicated.');
            }
            $refs[$ref] = true;

            /** @var array<string,array{label:string,values:array<string,string>}> $productAttributes */
            $productAttributes = array();
            foreach ((array) ($product['attributes'] ?? array()) as $attribute) {
                if (!is_array($attribute)) {
                    continue;
                }
                $label = isset($attribute['name']) && is_string($attribute['name']) ? trim($attribute['name']) : '';
                $identity = $this->text->normalize($label);
                if ($identity === '') {
                    continue;
                }
                if (!isset($productAttributes[$identity])) {
                    $productAttributes[$identity] = array('label' => $label, 'values' => array());
                }
                foreach ((array) ($attribute['values'] ?? array()) as $rawValue) {
                    $display = trim((string) $rawValue);
                    $normalized = $this->text->normalize($display);
                    if ($display !== '' && $normalized !== '' && !isset($productAttributes[$identity]['values'][$normalized])) {
                        $productAttributes[$identity]['values'][$normalized] = $display;
                    }
                }
                if ($productAttributes[$identity]['values'] === array()) {
                    unset($productAttributes[$identity]);
                }
            }
            ksort($productAttributes, SORT_STRING);

            $displayAttributes = array();
            foreach ($productAttributes as $identity => $attribute) {
                ksort($attribute['values'], SORT_STRING);
                $displayValues = array_values($attribute['values']);
                $displayAttributes[$attribute['label']] = $displayValues;
                if (!isset($attributeMatrix[$identity])) {
                    $attributeMatrix[$identity] = array('label' => $attribute['label'], 'values' => array(), 'normalized' => array());
                }
                $attributeMatrix[$identity]['values'][$ref] = $displayValues;
                $attributeMatrix[$identity]['normalized'][$ref] = array_keys($attribute['values']);
            }

            $rows[] = array(
                'product_ref' => $ref,
                'name' => $name,
                'formatted_price' => (string) ($product['formatted_price'] ?? ''),
                'price' => (string) ($product['price'] ?? ''),
                'price_min' => (string) ($product['price_min'] ?? ($product['price'] ?? '')),
                'price_max' => (string) ($product['price_max'] ?? ($product['price'] ?? '')),
                'price_is_range' => (bool) ($product['price_is_range'] ?? false),
                'price_status' => (string) ($product['price_status'] ?? 'unknown'),
                'price_basis' => (string) ($product['price_basis'] ?? 'unknown'),
                'currency' => (string) ($product['currency'] ?? ''),
                'in_stock' => (bool) ($product['in_stock'] ?? false),
                'purchasable' => (bool) ($product['purchasable'] ?? false),
                'cart_supported' => (bool) ($product['cart_supported'] ?? false),
                'cart_support_reason' => (string) ($product['cart_support_reason'] ?? 'unsupported_product_type'),
                'variation_catalog_supported' => (bool) ($product['variation_catalog_supported'] ?? false),
                'variation_catalog_reason' => (string) ($product['variation_catalog_reason'] ?? 'variation_catalog_invalid'),
                'requires_variation' => (bool) ($product['requires_variation'] ?? false),
                'weight' => (string) ($product['weight'] ?? ''),
                'dimensions' => (string) ($product['dimensions'] ?? ''),
                'average_rating' => (string) ($product['average_rating'] ?? ''),
                'review_count' => (int) ($product['review_count'] ?? 0),
                'categories' => array_values((array) ($product['categories'] ?? array())),
                'attributes' => $displayAttributes,
                'short_description' => (string) ($product['short_description'] ?? ''),
            );
        }

        ksort($attributeMatrix, SORT_STRING);
        $differences = array();
        foreach ($attributeMatrix as $matrix) {
            $complete = array();
            $fingerprints = array();
            foreach (array_keys($refs) as $ref) {
                $complete[$ref] = $matrix['values'][$ref] ?? null;
                $normalized = $matrix['normalized'][$ref] ?? null;
                $fingerprints[Json::canonical($normalized)] = true;
            }
            if (count($fingerprints) > 1) {
                $differences[] = array(
                    'attribute' => (string) $matrix['label'],
                    'values_by_product_ref' => $complete,
                );
            }
            if (count($differences) >= 12) {
                break;
            }
        }
        return array('products' => $rows, 'attribute_differences' => $differences);
    }
}
