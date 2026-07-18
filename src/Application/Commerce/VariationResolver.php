<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Commerce;

use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Domain\Shopping\CatalogTextNormalizer;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\TrustedCommerceText;
use YassinStore\AiAssistant\Support\Utf8;

/** Matches model-interpreted option values against one complete live catalog. */
final class VariationResolver
{
    /** @var CatalogTextNormalizer */ private $text;

    public function __construct(CatalogTextNormalizer $text)
    {
        $this->text = $text;
    }

    /**
     * @param array<int,array<string,mixed>> $variations
     * @param array<int,array<string,mixed>> $selected
     * @return array<string,mixed>
     */
    public function resolve(array $variations, array $selected): array
    {
        if (
            !Arr::isList($variations) || !Arr::isList($selected)
            || $variations === array() || count($variations) > 1000
            || count($selected) > 16
        ) {
            throw new ContractViolation(
                'variation_resolution_invalid',
                'Variation resolution requires one bounded complete live catalog.'
            );
        }

        $criteria = array();
        $selectedForModel = array();
        foreach ($selected as $row) {
            if (!is_array($row) || Arr::isList($row)) {
                throw $this->invalidSelection();
            }
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            $name = isset($row['name']) && is_string($row['name'])
                ? TrustedCommerceText::decodeEntities($row['name']) : '';
            $value = isset($row['value']) && is_string($row['value'])
                ? TrustedCommerceText::decodeEntities($row['value']) : '';
            $nameId = $this->identity($name);
            $valueId = $this->identity($value);
            if (
                $keys !== array('name', 'value') || $nameId === '' || $valueId === ''
                || !Utf8::isBounded($name, 160, 640)
                || !Utf8::isBounded($value, 160, 640)
                || isset($criteria[$nameId])
            ) {
                throw $this->invalidSelection();
            }
            $criteria[$nameId] = $valueId;
            $selectedForModel[] = array('name' => $name, 'value' => $value);
        }

        $available = array();
        $matches = array();
        foreach ($variations as $variation) {
            $projected = $this->project($variation);
            if ($projected === null || empty($variation['in_stock']) || empty($variation['purchasable'])) {
                continue;
            }
            $available[] = array('variation' => $variation, 'attributes' => $projected);
            if ($this->matches($projected, $criteria)) {
                $matches[] = array('variation' => $variation, 'attributes' => $projected);
            }
        }

        $evidenceRows = $matches !== array() ? $matches : $available;
        $axes = array();
        foreach ($evidenceRows as $entry) {
            foreach ($entry['attributes'] as $nameId => $attribute) {
                $axes[$nameId]['name'] = $attribute['name'];
                $axes[$nameId]['values'][$attribute['value_id']] = $attribute['value'];
            }
        }
        ksort($axes, SORT_STRING);
        $axisRows = array();
        foreach ($axes as $axis) {
            ksort($axis['values'], SORT_STRING);
            $axisRows[] = array(
                'name' => $axis['name'],
                'values' => array_values($axis['values']),
            );
        }

        $combinationRows = array();
        $combinationCount = 0;
        $seenCombinations = array();
        foreach ($evidenceRows as $entry) {
            $combination = array();
            foreach ($entry['attributes'] as $attribute) {
                $combination[] = array(
                    'name' => $attribute['name'],
                    'value' => $attribute['value'],
                );
            }
            $fingerprint = hash('sha256', \YassinStore\AiAssistant\Support\Json::canonical($combination));
            if (isset($seenCombinations[$fingerprint])) {
                continue;
            }
            $seenCombinations[$fingerprint] = true;
            ++$combinationCount;
            if (count($combinationRows) < 24) {
                $combinationRows[] = $combination;
            }
        }

        $matchRows = array();
        foreach (array_slice($matches, 0, 8) as $entry) {
            $matchRows[] = $entry['variation'];
        }
        return array(
            'status' => count($matches) === 1
                ? 'exact' : (count($matches) > 1 ? 'ambiguous' : 'not_found'),
            'selected_attributes' => $selectedForModel,
            'available_axes' => $axisRows,
            'valid_combinations' => $combinationRows,
            'combination_count' => $combinationCount,
            'combinations_complete' => $combinationCount <= count($combinationRows),
            'match_count' => count($matches),
            'matches' => $matchRows,
            'matches_complete' => count($matches) <= count($matchRows),
        );
    }

    /** @param array<string,mixed> $variation @return array<string,array<string,string>>|null */
    private function project(array $variation): ?array
    {
        $rows = $variation['attributes'] ?? null;
        if (!is_array($rows) || !Arr::isList($rows) || $rows === array()) {
            return null;
        }
        $attributes = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                return null;
            }
            $name = TrustedCommerceText::decodeEntities((string) ($row['label'] ?? ''));
            $display = TrustedCommerceText::decodeEntities((string) ($row['display'] ?? ''));
            $raw = TrustedCommerceText::decodeEntities((string) ($row['value'] ?? ''));
            $value = $display !== '' ? $display : $raw;
            $nameId = $this->identity($name);
            $valueId = $this->identity($value);
            $rawId = $this->identity($raw);
            if ($nameId === '' || $valueId === '' || isset($attributes[$nameId])) {
                return null;
            }
            $attributes[$nameId] = array(
                'name' => $name,
                'value' => $value,
                'value_id' => $valueId,
                'raw_id' => $rawId,
            );
        }
        ksort($attributes, SORT_STRING);
        return $attributes;
    }

    /** @param array<string,array<string,string>> $attributes @param array<string,string> $criteria */
    private function matches(array $attributes, array $criteria): bool
    {
        foreach ($criteria as $nameId => $valueId) {
            if (
                !isset($attributes[$nameId])
                || (!hash_equals($attributes[$nameId]['value_id'], $valueId)
                    && !hash_equals($attributes[$nameId]['raw_id'], $valueId))
            ) {
                return false;
            }
        }
        return true;
    }

    private function identity(string $value): string
    {
        return $this->text->normalize($value);
    }

    private function invalidSelection(): ContractViolation
    {
        return new ContractViolation(
            'variation_selection_invalid',
            'Selected variation attributes must be unique bounded name/value pairs.'
        );
    }
}
