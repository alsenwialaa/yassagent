<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool;

use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Domain\Shopping\CatalogTextNormalizer;
use YassinStore\AiAssistant\Domain\Shopping\ProductPriceRange;
use YassinStore\AiAssistant\Support\Arr;

/** Explainable deterministic fit ranking over revalidated live product facts. */
final class ProductRecommendationRanker
{
    /** @var CatalogTextNormalizer */ private $text;
    /** @var VariationEligibilityEvaluator */ private $variationEligibility;

    public function __construct(?CatalogTextNormalizer $text = null, ?VariationEligibilityEvaluator $variationEligibility = null)
    {
        $this->text = $text !== null ? $text : new CatalogTextNormalizer();
        $this->variationEligibility = $variationEligibility ?: new VariationEligibilityEvaluator($this->text);
    }

    /** @param array<int,array<string,mixed>> $products @param array<string,mixed> $criteria @return array<string,mixed> */
    public function rank(array $products, array $criteria): array
    {
        if (!Arr::isList($products) || count($products) < 2 || count($products) > 8) {
            throw new ContractViolation('recommendation_product_count_invalid', 'Recommendation ranking requires a list of two to eight products.');
        }
        if ($criteria !== array() && Arr::isList($criteria)) {
            throw new ContractViolation('recommendation_criteria_invalid', 'Recommendation criteria must be an object.');
        }
        $allowed = array(
            'product_refs', 'required_in_stock', 'min_price', 'max_price', 'min_rating',
            'required_attributes', 'excluded_attributes', 'preferred_attributes',
            'required_categories', 'excluded_categories', 'preferred_categories', 'priority',
        );
        foreach (array_keys($criteria) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new ContractViolation('recommendation_criteria_invalid', 'Recommendation criteria contain an unsupported field.');
            }
        }

        $minPrice = $this->criterionPrice($criteria, 'min_price');
        $maxPrice = $this->criterionPrice($criteria, 'max_price');
        if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
            throw new ContractViolation('recommendation_price_range_invalid', 'Recommendation minimum price exceeds maximum price.');
        }
        if (array_key_exists('required_in_stock', $criteria) && !is_bool($criteria['required_in_stock'])) {
            throw new ContractViolation('recommendation_stock_invalid', 'Required stock preference must be boolean.');
        }
        $requiredStock = (bool) ($criteria['required_in_stock'] ?? false);
        $minRating = $this->criterionRating($criteria);
        $requiredAttributes = $this->attributeCriteria($criteria['required_attributes'] ?? array());
        $excludedAttributes = $this->attributeCriteria($criteria['excluded_attributes'] ?? array());
        $preferredAttributes = $this->attributeCriteria($criteria['preferred_attributes'] ?? array());
        $requiredCategories = $this->normalizedStrings($criteria['required_categories'] ?? array(), 8);
        $excludedCategories = $this->normalizedStrings($criteria['excluded_categories'] ?? array(), 8);
        $preferredCategories = $this->normalizedStrings($criteria['preferred_categories'] ?? array(), 8);
        $priority = isset($criteria['priority']) && is_string($criteria['priority'])
            ? $criteria['priority'] : 'balanced';
        if (!in_array($priority, array('balanced', 'lowest_price', 'highest_rating', 'best_selling'), true)) {
            throw new ContractViolation('recommendation_priority_invalid', 'Recommendation priority is invalid.');
        }

        $numericPrices = array();
        $maxSales = 0;
        foreach ($products as $product) {
            if (!is_array($product) || ($product !== array() && Arr::isList($product))) {
                throw new ContractViolation('recommendation_product_invalid', 'Recommendation product facts must be objects.');
            }
            $range = ProductPriceRange::fromSnapshot($product);
            if ($range['min'] !== null) {
                $numericPrices[] = $range['min'];
            }
            $maxSales = max($maxSales, max(0, (int) ($product['total_sales'] ?? 0)));
        }
        $lowestPrice = $numericPrices !== array() ? min($numericPrices) : null;
        $highestPrice = $numericPrices !== array() ? max($numericPrices) : null;

        $rows = array();
        $seenRefs = array();
        foreach ($products as $product) {
            $ref = isset($product['product_ref']) && is_string($product['product_ref']) ? $product['product_ref'] : '';
            $name = isset($product['name']) && is_string($product['name']) ? trim($product['name']) : '';
            if (preg_match('/^p[1-9][0-9]*$/', $ref) !== 1 || $name === '' || isset($seenRefs[$ref])) {
                throw new ContractViolation('recommendation_product_invalid', 'Recommendation product facts are incomplete or duplicated.');
            }
            $seenRefs[$ref] = true;

            $range = ProductPriceRange::fromSnapshot($product);
            $price = $range['min'];
            $rating = max(0.0, min(5.0, (float) ($product['average_rating'] ?? 0)));
            $sales = max(0, (int) ($product['total_sales'] ?? 0));
            $requiresVariation = !empty($product['requires_variation']);
            $eligible = true;
            $unmet = array();
            $confirmations = array();
            $reasons = array();
            $score = 50.0;

            if (empty($product['purchasable'])) {
                $eligible = false;
                $unmet[] = 'not_purchasable';
            } else {
                $score += 3.0;
                $reasons[] = 'purchasable';
            }
            if ($requiredStock && empty($product['in_stock'])) {
                $eligible = false;
                $unmet[] = 'out_of_stock';
            } elseif (!empty($product['in_stock'])) {
                $score += 4.0;
                $reasons[] = 'in_stock';
                if ($requiredStock && $requiresVariation) {
                    $confirmations[] = 'variation_stock';
                }
            }

            if ($minPrice !== null) {
                if (!$range['known']) {
                    $eligible = false;
                    $unmet[] = 'price_unknown';
                } elseif ((float) $range['max'] < $minPrice) {
                    $eligible = false;
                    $unmet[] = 'below_min_price';
                } elseif ((float) $range['min'] < $minPrice) {
                    $confirmations[] = 'variation_price_meets_minimum';
                } else {
                    $score += 5.0;
                    $reasons[] = 'meets_minimum_price';
                }
            }
            if ($maxPrice !== null) {
                if (!$range['known']) {
                    $eligible = false;
                    $unmet[] = 'price_unknown';
                } elseif ((float) $range['min'] > $maxPrice) {
                    $eligible = false;
                    $unmet[] = 'above_max_price';
                } elseif ((float) $range['max'] > $maxPrice) {
                    $confirmations[] = 'variation_price_within_budget';
                } else {
                    $score += 8.0;
                    $reasons[] = 'within_budget';
                }
            }
            if ($minRating !== null && $rating < $minRating) {
                $eligible = false;
                $unmet[] = 'rating_below_minimum';
            } elseif ($minRating !== null) {
                $score += 6.0;
                $reasons[] = 'rating_meets_minimum';
            }

            $attributeEligibility = $this->variationEligibility->evaluate($product, $requiredAttributes, $excludedAttributes);
            if (!$attributeEligibility['eligible']) {
                $eligible = false;
            }
            $score += 10.0 * (int) $attributeEligibility['required_match_count'];
            $reasons = array_merge($reasons, $attributeEligibility['reasons']);
            $unmet = array_merge($unmet, $attributeEligibility['unmet_required']);
            $confirmations = array_merge($confirmations, $attributeEligibility['requires_confirmation']);
            foreach ($preferredAttributes as $criterion) {
                $preference = $this->variationEligibility->preference($product, $criterion);
                if (!$preference['matched']) {
                    continue;
                }
                $score += 8.0;
                $reasons[] = 'preferred_attribute_match';
                if ($preference['requires_confirmation']) {
                    $confirmations[] = 'variation_preferred_attribute:' . $criterion['name'] . '=' . $criterion['value'];
                }
            }
            foreach ($requiredCategories as $category) {
                if (!$this->matchesCategory($product, $category)) {
                    $eligible = false;
                    $unmet[] = 'category_required:' . $category;
                } else {
                    $score += 8.0;
                    $reasons[] = 'required_category_match';
                }
            }
            foreach ($excludedCategories as $category) {
                if ($this->matchesCategory($product, $category)) {
                    $eligible = false;
                    $unmet[] = 'category_excluded:' . $category;
                }
            }
            foreach ($preferredCategories as $category) {
                if ($this->matchesCategory($product, $category)) {
                    $score += 7.0;
                    $reasons[] = 'preferred_category_match';
                }
            }

            if ($priority === 'lowest_price') {
                if ($price === null) {
                    $score -= 20.0;
                    $reasons[] = 'price_unknown_for_priority';
                } elseif ($lowestPrice !== null && $highestPrice !== null && $highestPrice > $lowestPrice) {
                    $score += 25.0 * (($highestPrice - $price) / ($highestPrice - $lowestPrice));
                    if (abs($price - $lowestPrice) < 0.00001) {
                        $reasons[] = $range['is_range'] ? 'lowest_starting_price' : 'lowest_price';
                    }
                } else {
                    $score += 12.5;
                    $reasons[] = 'price_known';
                }
            } elseif ($priority === 'highest_rating') {
                $score += $rating * 5.0;
                if ($rating >= 4.0) {
                    $reasons[] = 'high_rating';
                }
            } elseif ($priority === 'best_selling') {
                if ($maxSales > 0) {
                    $score += 25.0 * ($sales / $maxSales);
                    if ($sales === $maxSales) {
                        $reasons[] = 'highest_sales';
                    }
                } else {
                    $reasons[] = 'sales_data_unavailable';
                }
            } else {
                $score += $rating * 2.0;
                if ($maxSales > 0) {
                    $score += min(8.0, 8.0 * ($sales / $maxSales));
                }
            }

            $confirmations = array_values(array_unique($confirmations));
            if (!$eligible) {
                $score = 0.0;
                $confirmations = array();
            } elseif ($confirmations !== array()) {
                $score -= min(12.0, count($confirmations) * 3.0);
                $reasons[] = 'variation_confirmation_required';
            }
            $fullyVerified = $eligible && $confirmations === array();

            $rows[] = array(
                'product_ref' => $ref,
                'name' => $name,
                'eligible' => $eligible,
                'fully_verified' => $fullyVerified,
                'score' => round(max(0.0, $score), 3),
                'reasons' => array_values(array_unique($reasons)),
                'unmet_required' => array_values(array_unique($unmet)),
                'requires_confirmation' => $confirmations,
                'facts' => array(
                    'formatted_price' => (string) ($product['formatted_price'] ?? ''),
                    'price' => (string) ($product['price'] ?? ''),
                    'price_min' => (string) ($product['price_min'] ?? ($product['price'] ?? '')),
                    'price_max' => (string) ($product['price_max'] ?? ($product['price'] ?? '')),
                    'price_is_range' => $range['is_range'],
                    'price_known' => $range['known'],
                    'price_status' => (string) ($product['price_status'] ?? ($range['known'] ? ($range['is_range'] ? 'range' : 'exact') : 'unknown')),
                    'price_basis' => (string) ($product['price_basis'] ?? 'unknown'),
                    'currency' => (string) ($product['currency'] ?? ''),
                    'purchasable' => (bool) ($product['purchasable'] ?? false),
                    'cart_supported' => (bool) ($product['cart_supported'] ?? false),
                    'cart_support_reason' => (string) ($product['cart_support_reason'] ?? 'unsupported_product_type'),
                    'variation_catalog_supported' => (bool) ($product['variation_catalog_supported'] ?? false),
                    'variation_catalog_reason' => (string) ($product['variation_catalog_reason'] ?? 'variation_catalog_invalid'),
                    'in_stock' => (bool) ($product['in_stock'] ?? false),
                    'requires_variation' => $requiresVariation,
                    'average_rating' => (string) ($product['average_rating'] ?? ''),
                    'review_count' => (int) ($product['review_count'] ?? 0),
                    'categories' => array_values((array) ($product['categories'] ?? array())),
                ),
            );
        }
        usort($rows, static function (array $left, array $right): int {
            if ($left['eligible'] !== $right['eligible']) {
                return $left['eligible'] ? -1 : 1;
            }
            if ($left['fully_verified'] !== $right['fully_verified']) {
                return $left['fully_verified'] ? -1 : 1;
            }
            $score = ((float) $right['score']) <=> ((float) $left['score']);
            return $score !== 0 ? $score : strcmp((string) $left['name'], (string) $right['name']);
        });
        return array(
            'priority' => $priority,
            'ranked' => $rows,
            'eligible_count' => count(array_filter($rows, static function (array $row): bool {
                return $row['eligible'];
            })),
            'fully_verified_count' => count(array_filter($rows, static function (array $row): bool {
                return $row['fully_verified'];
            })),
        );
    }

    /** @param array<string,mixed> $criteria */
    private function criterionPrice(array $criteria, string $key): ?float
    {
        if (!array_key_exists($key, $criteria)) {
            return null;
        }
        $raw = $criteria[$key];
        if (!is_int($raw) && !is_float($raw)) {
            throw new ContractViolation('recommendation_price_invalid', 'Recommendation price criteria must be numeric.');
        }
        $price = (float) $raw;
        if (!is_finite($price) || $price < 0.0) {
            throw new ContractViolation('recommendation_price_invalid', 'Recommendation price criteria are invalid.');
        }
        return $price;
    }

    /** @param array<string,mixed> $criteria */
    private function criterionRating(array $criteria): ?float
    {
        if (!array_key_exists('min_rating', $criteria)) {
            return null;
        }
        $raw = $criteria['min_rating'];
        if (!is_int($raw) && !is_float($raw)) {
            throw new ContractViolation('recommendation_rating_invalid', 'Recommendation minimum rating must be numeric.');
        }
        $rating = (float) $raw;
        if (!is_finite($rating) || $rating < 0.0 || $rating > 5.0) {
            throw new ContractViolation('recommendation_rating_invalid', 'Recommendation minimum rating must be between zero and five.');
        }
        return $rating;
    }

    /** @param mixed $value @return array<int,array{name:string,value:string}> */
    private function attributeCriteria($value): array
    {
        if (!is_array($value) || !Arr::isList($value) || count($value) > 8) {
            throw new ContractViolation('recommendation_attributes_invalid', 'Recommendation attributes must be a bounded list.');
        }
        $rows = array();
        $seen = array();
        foreach ($value as $row) {
            if (!is_array($row) || ($row !== array() && Arr::isList($row))) {
                throw new ContractViolation('recommendation_attribute_invalid', 'Recommendation attribute is invalid.');
            }
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if ($keys !== array('name', 'value')) {
                throw new ContractViolation('recommendation_attribute_invalid', 'Recommendation attribute fields are invalid.');
            }
            $name = $this->text->normalize(is_string($row['name']) ? $row['name'] : '');
            $criterionValue = $this->text->normalize(is_string($row['value']) ? $row['value'] : '');
            $identity = $name . '=' . $criterionValue;
            if ($name === '' || $criterionValue === '' || isset($seen[$identity])) {
                throw new ContractViolation('recommendation_attribute_invalid', 'Recommendation attribute is blank or duplicated.');
            }
            $seen[$identity] = true;
            $rows[] = array('name' => $name, 'value' => $criterionValue);
        }
        return $rows;
    }

    /** @param mixed $value @return array<int,string> */
    private function normalizedStrings($value, int $limit): array
    {
        if (!is_array($value) || !Arr::isList($value) || count($value) > $limit) {
            throw new ContractViolation('recommendation_categories_invalid', 'Recommendation categories must be a bounded list.');
        }
        $rows = array();
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new ContractViolation('recommendation_categories_invalid', 'Recommendation category must be text.');
            }
            $item = $this->text->normalize($item);
            if ($item === '' || isset($rows[$item])) {
                throw new ContractViolation('recommendation_categories_invalid', 'Recommendation category is blank or duplicated.');
            }
            $rows[$item] = true;
        }
        return array_keys($rows);
    }

    /** @param array<string,mixed> $product */
    private function matchesCategory(array $product, string $category): bool
    {
        foreach ((array) ($product['categories'] ?? array()) as $candidate) {
            if ($this->text->contains((string) $candidate, $category)) {
                return true;
            }
        }
        return false;
    }
}
