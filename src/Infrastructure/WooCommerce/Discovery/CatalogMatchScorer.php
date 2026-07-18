<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery;

use YassinStore\AiAssistant\Domain\Shopping\CatalogTextNormalizer;

/** Explainable deterministic ranking over live WooCommerce facts. */
final class CatalogMatchScorer
{
    /** @var CatalogTextNormalizer */ private $normalizer;

    public function __construct(CatalogTextNormalizer $normalizer)
    {
        $this->normalizer = $normalizer;
    }

    /**
     * @param array<string,mixed> $product
     * @param array<int,string>   $queries
     * @return array{score:float,semantic_score:float,matched_terms:array<int,string>,reasons:array<int,string>}
     */
    public function score(array $product, array $queries): array
    {
        $name = $this->normalizer->normalize((string) ($product['name'] ?? ''));
        $sku = $this->normalizer->normalize((string) ($product['sku'] ?? ''));
        $categories = $this->normalizer->normalize(implode(' ', (array) ($product['categories'] ?? array())));
        $attributes = array();
        foreach ((array) ($product['attributes'] ?? array()) as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $attributes[] = (string) ($attribute['name'] ?? '');
            foreach ((array) ($attribute['values'] ?? array()) as $value) {
                $attributes[] = (string) $value;
            }
        }
        $attributeText = $this->normalizer->normalize(implode(' ', $attributes));
        $document = $this->normalizer->normalize(implode(' ', array(
            (string) ($product['name'] ?? ''),
            (string) ($product['sku'] ?? ''),
            (string) ($product['short_description'] ?? ''),
            (string) ($product['description'] ?? ''),
            implode(' ', (array) ($product['categories'] ?? array())),
            implode(' ', (array) ($product['tags'] ?? array())),
            implode(' ', $attributes),
        )));

        $score = 0.0;
        $matched = array();
        $reasons = array();
        foreach ($queries as $query) {
            $normalized = $this->normalizer->normalize($query);
            if ($normalized === '') {
                continue;
            }
            $queryScore = 0.0;
            if ($name === $normalized) {
                $queryScore += 120.0;
                $reasons['exact_name'] = 'exact_name';
            } elseif ($name !== '' && strpos($name, $normalized) !== false) {
                $queryScore += 75.0;
                $reasons['name_phrase'] = 'name_phrase';
            }
            if ($sku !== '' && $sku === $normalized) {
                $queryScore += 110.0;
                $reasons['exact_sku'] = 'exact_sku';
            }
            if ($categories !== '' && strpos($categories, $normalized) !== false) {
                $queryScore += 28.0;
                $reasons['category'] = 'category';
            }
            if ($attributeText !== '' && strpos($attributeText, $normalized) !== false) {
                $queryScore += 26.0;
                $reasons['attribute'] = 'attribute';
            }
            if ($document !== '' && strpos($document, $normalized) !== false) {
                $queryScore += 38.0;
                $reasons['phrase'] = 'phrase';
            }

            $tokens = $this->normalizer->tokens($normalized);
            if ($tokens !== array()) {
                $hits = 0;
                foreach ($tokens as $token) {
                    if ($document !== '' && strpos(' ' . $document . ' ', ' ' . $token . ' ') !== false) {
                        ++$hits;
                    }
                }
                if ($hits > 0) {
                    $coverage = $hits / count($tokens);
                    $queryScore += 42.0 * $coverage;
                    if ($coverage >= 0.999) {
                        $reasons['all_terms'] = 'all_terms';
                    } else {
                        $reasons['partial_terms'] = 'partial_terms';
                    }
                }
            }
            if ($queryScore > 0) {
                $score += $queryScore;
                $matched[$query] = true;
            }
        }
        $semanticScore = $score;
        // Availability is only a tie-breaker after the product has a grounded
        // semantic/category/attribute match. It must never create relevance.
        if ($semanticScore > 0.0) {
            if (!empty($product['in_stock'])) {
                $score += 3.0;
            }
            if (!empty($product['purchasable'])) {
                $score += 2.0;
            }
        }

        return array(
            'score' => round($score, 3),
            'semantic_score' => round($semanticScore, 3),
            'matched_terms' => array_keys($matched),
            'reasons' => array_values($reasons),
        );
    }
}
