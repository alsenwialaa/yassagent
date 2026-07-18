<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Commerce;

use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Domain\Shopping\CatalogTextNormalizer;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Utf8;
use YassinStore\AiAssistant\Domain\Commerce\VariableProductLimits;

/** Produces the exact durable identity of a variable product and its axes. */
final class VariableProductAuthority
{
    /** @var CatalogTextNormalizer */ private $text;

    public function __construct(CatalogTextNormalizer $text)
    {
        $this->text = $text;
    }

    /**
     * @param array<string,mixed> $product
     * @return array{product_id:int,product_fingerprint:string,axes_fingerprint:string,axes:array<string,array{label:string,values:array<string,string>}>}
     */
    public function inspect(array $product): array
    {
        $productId = $product['id'] ?? null;
        $name = $product['name'] ?? null;
        $sku = $product['sku'] ?? null;
        $type = $product['type'] ?? null;
        $requiresVariation = $product['requires_variation'] ?? null;
        if (
            !is_int($productId) || $productId < 1
            || !is_string($name) || trim($name) === ''
            || !Utf8::isPlainText($name) || !Utf8::isBounded($name, 500, 2000)
            || !is_string($sku) || !Utf8::isPlainText($sku)
            || !Utf8::isBounded($sku, 191, 764)
            || !is_string($type) || preg_match('/^[a-z0-9_-]{1,64}$/', $type) !== 1
            || $type !== 'variable' || $requiresVariation !== true
        ) {
            throw new ContractViolation(
                'pending_cart_product_identity_invalid',
                'The current variable-product identity is malformed.'
            );
        }

        $attributes = $product['attributes'] ?? null;
        if (
            !is_array($attributes) || !Arr::isList($attributes)
            || count($attributes) > VariableProductLimits::MAX_AXES
        ) {
            throw new ContractViolation(
                'pending_cart_variation_axes_invalid',
                'The current product variation axes are malformed.'
            );
        }

        $axes = array();
        foreach ($attributes as $attribute) {
            if (!is_array($attribute) || empty($attribute['variation'])) {
                continue;
            }
            $label = trim((string) ($attribute['name'] ?? ''));
            $values = $attribute['values'] ?? null;
            if (
                $label === '' || !Utf8::isPlainText($label)
                || !Utf8::isBounded($label, 160, 640)
                || !is_array($values) || !Arr::isList($values)
                || $values === array()
                || count($values) > VariableProductLimits::MAX_VALUES_PER_AXIS
            ) {
                throw new ContractViolation(
                    'pending_cart_variation_axes_invalid',
                    'A current product variation axis is malformed.'
                );
            }
            $axisIdentity = $this->identity($label);
            if ($axisIdentity === '' || isset($axes[$axisIdentity])) {
                throw new ContractViolation(
                    'pending_cart_variation_axes_invalid',
                    'Current product variation axes are duplicated.'
                );
            }
            $liveValues = array();
            foreach ($values as $value) {
                $value = is_string($value) ? trim($value) : '';
                $valueIdentity = $this->identity($value);
                if (
                    $value === '' || $valueIdentity === ''
                    || !Utf8::isPlainText($value) || !Utf8::isBounded($value, 160, 640)
                    || isset($liveValues[$valueIdentity])
                ) {
                    throw new ContractViolation(
                        'pending_cart_variation_axes_invalid',
                        'A current product variation value is malformed or duplicated.'
                    );
                }
                $liveValues[$valueIdentity] = $value;
            }
            ksort($liveValues, SORT_STRING);
            $axes[$axisIdentity] = array('label' => $label, 'values' => $liveValues);
        }
        if ($axes === array()) {
            throw new ContractViolation(
                'pending_cart_variation_axes_invalid',
                'The current variable product exposes no selectable variation axis.'
            );
        }
        ksort($axes, SORT_STRING);

        $axisEvidence = array();
        foreach ($axes as $identity => $axis) {
            $values = array();
            foreach ($axis['values'] as $valueIdentity => $value) {
                $values[] = array('identity' => $valueIdentity, 'value' => $value);
            }
            $axisEvidence[] = array(
                'identity' => $identity,
                'label' => $axis['label'],
                'values' => $values,
            );
        }

        return array(
            'product_id' => $productId,
            'product_fingerprint' => hash('sha256', "pending-product-v1\0" . Json::canonical(array(
                'id' => $productId,
                'name' => $name,
                'sku' => $sku,
                'type' => $type,
                'requires_variation' => true,
            ))),
            'axes_fingerprint' => hash(
                'sha256',
                "pending-variation-axes-v1\0" . Json::canonical($axisEvidence)
            ),
            'axes' => $axes,
        );
    }

    /** @param array<string,mixed> $product @param array<string,mixed> $target */
    public function matches(array $product, array $target): bool
    {
        try {
            $current = $this->inspect($product);
        } catch (ContractViolation $exception) {
            return false;
        }
        return (int) ($target['product_id'] ?? 0) === $current['product_id']
            && is_string($target['product_fingerprint'] ?? null)
            && hash_equals($target['product_fingerprint'], $current['product_fingerprint'])
            && is_string($target['variation_axes_fingerprint'] ?? null)
            && hash_equals($target['variation_axes_fingerprint'], $current['axes_fingerprint']);
    }

    private function identity(string $value): string
    {
        return $this->text->normalize($value);
    }
}
