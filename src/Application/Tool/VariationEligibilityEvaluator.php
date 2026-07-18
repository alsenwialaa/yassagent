<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool;

use YassinStore\AiAssistant\Domain\Shopping\CatalogTextNormalizer;

/** Parent option lists are conditional evidence, not proof of one variation combination. */
final class VariationEligibilityEvaluator
{
    /** @var CatalogTextNormalizer */ private $text;
    public function __construct(?CatalogTextNormalizer $text = null)
    {
        $this->text = $text ?: new CatalogTextNormalizer();
    }

    /** @param array<string,mixed> $product @param array<int,array{name:string,value:string}> $required @param array<int,array{name:string,value:string}> $excluded @return array<string,mixed> */
    public function evaluate(array $product, array $required, array $excluded): array
    {
        $attributes = $this->attributes($product);
        $eligible = true;
        $matched = 0;
        $reasons = [];
        $unmet = [];
        $confirm = [];
        foreach ($required as $criterion) {
            $found = false;
            $variation = false;
            foreach ($this->matching($attributes, $criterion['name']) as $attribute) {
                if ($this->containsAny($attribute['values'], array($criterion['value']))) {
                    $found = true;
                    $variation = $variation || $attribute['variation'];
                }
            }
            if (!$found) {
                $eligible = false;
                $unmet[] = 'attribute:' . $criterion['name'] . '=' . $criterion['value'];
                continue;
            }
            foreach ($excluded as $ex) {
                if ($this->sameName($criterion['name'], $ex['name']) && $this->sameValue($criterion['value'], $ex['value'])) {
                    $eligible = false;
                    $unmet[] = 'attribute_conflict:' . $criterion['name'] . '=' . $criterion['value'];
                }
            }
            ++$matched;
            $reasons[] = 'required_attribute_match';
            if ($variation) {
                $confirm[] = 'variation_attribute_combination:' . $criterion['name'] . '=' . $criterion['value'];
            }
        }
        foreach ($excluded as $criterion) {
            foreach ($this->matching($attributes, $criterion['name']) as $attribute) {
                if (!$this->containsAny($attribute['values'], array($criterion['value']))) {
                    continue;
                }
                if (!$attribute['variation']) {
                    $eligible = false;
                    $unmet[] = 'excluded_attribute:' . $criterion['name'] . '=' . $criterion['value'];
                    continue;
                }
                $allowed = false;
                foreach ($attribute['values'] as $value) {
                    if (!$this->sameValue($value, $criterion['value'])) {
                        $allowed = true;
                        break;
                    }
                }
                if ($allowed) {
                    $confirm[] = 'variation_attribute_exclusion:' . $criterion['name'];
                } else {
                    $eligible = false;
                    $unmet[] = 'all_variations_excluded:' . $criterion['name'];
                }
            }
        }
        if (!$eligible) {
            $confirm = [];
        }
        return array('eligible' => $eligible,'required_match_count' => $matched,'reasons' => array_values(array_unique($reasons)),
            'unmet_required' => array_values(array_unique($unmet)),'requires_confirmation' => array_values(array_unique($confirm)));
    }
    /** @param array<string,mixed> $product @param array{name:string,value:string} $criterion @return array{matched:bool,requires_confirmation:bool} */
    public function preference(array $product, array $criterion): array
    {
        $matched = false;
        $requiresConfirmation = false;
        foreach ($this->matching($this->attributes($product), $criterion['name']) as $attribute) {
            if (!$this->containsAny($attribute['values'], array($criterion['value']))) {
                continue;
            }
            $matched = true;
            $requiresConfirmation = $requiresConfirmation || $attribute['variation'];
        }
        return array('matched' => $matched, 'requires_confirmation' => $requiresConfirmation);
    }

    /** @param array<string,mixed> $product @return array<int,array{name:string,values:array<int,string>,variation:bool}> */
    private function attributes(array $product): array
    {
        $out = [];
        foreach ((array)($product['attributes'] ?? []) as $a) {
            if (!is_array($a)) {
                continue;
            } $n = $this->text->normalize((string)($a['name'] ?? ''));
            if ($n === '') {
                continue;
            } $v = [];
            foreach ((array)($a['values'] ?? []) as $x) {
                $x = $this->text->normalize((string)$x);
                if ($x !== '') {
                    $v[$x] = true;
                }
            } $out[] = ['name' => $n,'values' => array_keys($v),'variation' => !empty($a['variation'])];
        } return $out;
    }
    /** @param array<int,array{name:string,values:array<int,string>,variation:bool}> $attributes @return array<int,array{name:string,values:array<int,string>,variation:bool}> */
    private function matching(array $attributes, string $name): array
    {
        return array_values(array_filter($attributes, function (array $a) use ($name) {
               return $this->sameName($a['name'], $name);
        }));
    }
    private function sameName(string $a, string $b): bool
    {
        return $this->text->contains($a, $b) || $this->text->contains($b, $a);
    }
    private function sameValue(string $a, string $b): bool
    {
        return $this->text->contains($a, $b) || $this->text->contains($b, $a);
    }
    /** @param array<int,string> $values @param array<int,string> $criteria */
    private function containsAny(array $values, array $criteria): bool
    {
        foreach ($values as $v) {
            foreach ($criteria as $c) {
                if ($this->sameValue($v, $c)) {
                         return true;
                }
            }
        } return false;
    }
}
