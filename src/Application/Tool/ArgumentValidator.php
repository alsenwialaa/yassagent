<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool;

use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Utf8;

/** Strict, non-coercing validator for the declared tool schema dialect. */
final class ArgumentValidator
{
    /** @param mixed $value @param array<string,mixed> $schema */
    public function validate($value, array $schema, string $path = '$'): void
    {
        $type = isset($schema['type']) && is_string($schema['type']) ? $schema['type'] : '';
        $this->assertType($value, $type, $path);

        if (isset($schema['enum']) && !in_array($value, (array) $schema['enum'], true)) {
            throw new ContractViolation('enum_mismatch', $path . ' contains an unsupported value.');
        }
        if ($type === 'object') {
            $this->validateObject($value, $schema, $path);
        } elseif ($type === 'array') {
            $this->validateArray($value, $schema, $path);
        } elseif ($type === 'string') {
            $this->validateString($value, $schema, $path);
        } elseif ($type === 'integer' || $type === 'number') {
            $this->validateNumber($value, $schema, $path);
        }
    }

    /** @param mixed $value */
    private function assertType($value, string $type, string $path): void
    {
        $valid = ($type === 'object' && is_array($value) && ($value === array() || !Arr::isList($value)))
            || ($type === 'array' && is_array($value) && Arr::isList($value))
            || ($type === 'string' && is_string($value))
            || ($type === 'integer' && is_int($value))
            || ($type === 'number' && (is_int($value) || is_float($value)))
            || ($type === 'boolean' && is_bool($value))
            || ($type === 'null' && $value === null);
        if (!$valid) {
            throw new ContractViolation('type_mismatch', $path . ' must be a JSON ' . ($type !== '' ? $type : 'value') . '.');
        }
    }

    /** @param array<string,mixed> $value @param array<string,mixed> $schema */
    private function validateObject(array $value, array $schema, string $path): void
    {
        $properties = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : array();
        $required = isset($schema['required']) && is_array($schema['required']) ? $schema['required'] : array();
        foreach ($required as $requiredKey) {
            if (!is_string($requiredKey) || !array_key_exists($requiredKey, $value)) {
                throw new ContractViolation('required_field_missing', $path . '.' . (string) $requiredKey . ' is required.');
            }
        }

        $additional = $schema['additionalProperties'] ?? true;
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new ContractViolation('object_key_invalid', $path . ' has a non-string object key.');
            }
            if (isset($properties[$key]) && is_array($properties[$key])) {
                $this->validate($item, $properties[$key], $path . '.' . $key);
            } elseif (is_array($additional)) {
                $this->validate($item, $additional, $path . '.' . $key);
            } elseif ($additional === false) {
                throw new ContractViolation('unknown_field', $path . '.' . $key . ' is not allowed.');
            }
        }
    }

    /** @param array<int,mixed> $value @param array<string,mixed> $schema */
    private function validateArray(array $value, array $schema, string $path): void
    {
        $count = count($value);
        if (isset($schema['minItems']) && $count < (int) $schema['minItems']) {
            throw new ContractViolation('array_too_short', $path . ' has too few items.');
        }
        if (isset($schema['maxItems']) && $count > (int) $schema['maxItems']) {
            throw new ContractViolation('array_too_long', $path . ' has too many items.');
        }
        if (!empty($schema['uniqueItems'])) {
            $seen = array();
            foreach ($value as $item) {
                $fingerprint = hash('sha256', Json::canonical($item));
                if (isset($seen[$fingerprint])) {
                    throw new ContractViolation('array_item_duplicate', $path . ' contains duplicate items.');
                }
                $seen[$fingerprint] = true;
            }
        }
        foreach ($value as $index => $item) {
            $this->validate($item, $schema['items'], $path . '[' . $index . ']');
        }
    }

    /** @param array<string,mixed> $schema */
    private function validateString(string $value, array $schema, string $path): void
    {
        try {
            $length = Utf8::codePointLength($value);
        } catch (\InvalidArgumentException $exception) {
            throw new ContractViolation('string_utf8_invalid', $path . ' must contain valid UTF-8 text.');
        }
        if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
            throw new ContractViolation('string_too_short', $path . ' is shorter than allowed.');
        }
        if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
            throw new ContractViolation('string_too_long', $path . ' is longer than allowed.');
        }
        if (!empty($schema['nonBlank']) && trim($value) === '') {
            throw new ContractViolation('blank_string', $path . ' must not be blank.');
        }
        if (isset($schema['pattern'])) {
            $matched = @preg_match((string) $schema['pattern'], $value);
            if ($matched !== 1) {
                throw new ContractViolation(
                    $matched === false ? 'schema_pattern_invalid' : 'string_pattern_mismatch',
                    $matched === false ? $path . ' could not be validated.' : $path . ' has an invalid format.'
                );
            }
        }
    }

    /** @param int|float $value @param array<string,mixed> $schema */
    private function validateNumber($value, array $schema, string $path): void
    {
        $numeric = (float) $value;
        if (!is_finite($numeric)) {
            throw new ContractViolation('number_not_finite', $path . ' must be finite.');
        }
        if (isset($schema['minimum']) && $numeric < (float) $schema['minimum']) {
            throw new ContractViolation('number_below_minimum', $path . ' is below the minimum.');
        }
        if (isset($schema['maximum']) && $numeric > (float) $schema['maximum']) {
            throw new ContractViolation('number_above_maximum', $path . ' exceeds the maximum.');
        }
    }
}
