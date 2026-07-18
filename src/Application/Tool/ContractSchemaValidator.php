<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool;

use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Json;

/**
 * Validates the small closed JSON-schema dialect understood by both the model
 * declaration projector and the PHP runtime validator. Invalid declarations
 * fail at composition time, before a customer turn can reach the model.
 */
final class ContractSchemaValidator
{
    private const TYPES = array('object', 'array', 'string', 'integer', 'number', 'boolean', 'null');
    private const COMMON = array('type', 'enum', 'description');

    /** @param array<string,mixed> $schema */
    public function validate(array $schema, string $path = '$'): void
    {
        if ($schema !== array() && Arr::isList($schema)) {
            throw new ContractViolation('schema_not_object', $path . ' must be a schema object.');
        }
        $type = isset($schema['type']) && is_string($schema['type']) ? $schema['type'] : '';
        if (!in_array($type, self::TYPES, true)) {
            throw new ContractViolation('schema_type_invalid', $path . '.type is missing or unsupported.');
        }
        if (
            array_key_exists('description', $schema)
            && (!is_string($schema['description'])
                || trim($schema['description']) === ''
                || strlen($schema['description']) > 2048)
        ) {
            throw new ContractViolation(
                'schema_description_invalid',
                $path . '.description must be nonblank bounded text.'
            );
        }

        $allowed = self::COMMON;
        if ($type === 'object') {
            $allowed = array_merge($allowed, array('properties', 'required', 'additionalProperties'));
        } elseif ($type === 'array') {
            $allowed = array_merge($allowed, array('items', 'minItems', 'maxItems', 'uniqueItems'));
        } elseif ($type === 'string') {
            $allowed = array_merge($allowed, array('minLength', 'maxLength', 'nonBlank', 'pattern'));
        } elseif ($type === 'integer' || $type === 'number') {
            $allowed = array_merge($allowed, array('minimum', 'maximum'));
        }
        foreach (array_keys($schema) as $keyword) {
            if (!is_string($keyword) || !in_array($keyword, $allowed, true)) {
                throw new ContractViolation('schema_keyword_invalid', $path . ' contains unsupported keyword ' . (string) $keyword . '.');
            }
        }

        if (array_key_exists('enum', $schema)) {
            if (!is_array($schema['enum']) || !Arr::isList($schema['enum']) || $schema['enum'] === array()) {
                throw new ContractViolation('schema_enum_invalid', $path . '.enum must be a nonempty list.');
            }
            $seen = array();
            foreach ($schema['enum'] as $index => $value) {
                $this->assertValueType($value, $type, $path . '.enum[' . $index . ']');
                $key = gettype($value) . ':' . Json::encode($value);
                if (isset($seen[$key])) {
                    throw new ContractViolation('schema_enum_duplicate', $path . '.enum contains a duplicate value.');
                }
                $seen[$key] = true;
            }
        }

        if ($type === 'object') {
            $this->validateObject($schema, $path);
        } elseif ($type === 'array') {
            $this->validateArray($schema, $path);
        } elseif ($type === 'string') {
            $this->validateString($schema, $path);
        } elseif ($type === 'integer' || $type === 'number') {
            $this->validateNumber($schema, $path);
        }
    }

    /** @param array<string,mixed> $schema */
    private function validateObject(array $schema, string $path): void
    {
        $properties = $schema['properties'] ?? array();
        if (!is_array($properties) || ($properties !== array() && Arr::isList($properties))) {
            throw new ContractViolation('schema_properties_invalid', $path . '.properties must be an object.');
        }
        foreach ($properties as $name => $child) {
            if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,127}$/', $name) !== 1 || !is_array($child)) {
                throw new ContractViolation('schema_property_invalid', $path . '.properties contains an invalid entry.');
            }
            $this->validate($child, $path . '.properties.' . $name);
        }

        $required = $schema['required'] ?? array();
        if (!is_array($required) || !Arr::isList($required)) {
            throw new ContractViolation('schema_required_invalid', $path . '.required must be a list.');
        }
        $seen = array();
        foreach ($required as $name) {
            if (!is_string($name) || !array_key_exists($name, $properties)) {
                throw new ContractViolation('schema_required_unknown', $path . '.required references an unknown property.');
            }
            if (isset($seen[$name])) {
                throw new ContractViolation('schema_required_duplicate', $path . '.required contains a duplicate property.');
            }
            $seen[$name] = true;
        }

        if (array_key_exists('additionalProperties', $schema)) {
            $additional = $schema['additionalProperties'];
            if (!is_bool($additional) && !is_array($additional)) {
                throw new ContractViolation('schema_additional_properties_invalid', $path . '.additionalProperties must be boolean or a schema.');
            }
            if (is_array($additional)) {
                $this->validate($additional, $path . '.additionalProperties');
            }
        }
    }

    /** @param array<string,mixed> $schema */
    private function validateArray(array $schema, string $path): void
    {
        if (!isset($schema['items']) || !is_array($schema['items'])) {
            throw new ContractViolation('schema_items_missing', $path . '.items must contain a schema.');
        }
        $this->validate($schema['items'], $path . '.items');
        $min = $this->nonNegativeInt($schema, 'minItems', $path);
        $max = $this->nonNegativeInt($schema, 'maxItems', $path);
        if ($min !== null && $max !== null && $min > $max) {
            throw new ContractViolation('schema_array_bounds_invalid', $path . ' has reversed item bounds.');
        }
        if (isset($schema['uniqueItems']) && !is_bool($schema['uniqueItems'])) {
            throw new ContractViolation('schema_unique_items_invalid', $path . '.uniqueItems must be boolean.');
        }
    }

    /** @param array<string,mixed> $schema */
    private function validateString(array $schema, string $path): void
    {
        $min = $this->nonNegativeInt($schema, 'minLength', $path);
        $max = $this->nonNegativeInt($schema, 'maxLength', $path);
        if ($min !== null && $max !== null && $min > $max) {
            throw new ContractViolation('schema_string_bounds_invalid', $path . ' has reversed string bounds.');
        }
        if (isset($schema['nonBlank']) && !is_bool($schema['nonBlank'])) {
            throw new ContractViolation('schema_nonblank_invalid', $path . '.nonBlank must be boolean.');
        }
        if (isset($schema['pattern'])) {
            if (!is_string($schema['pattern']) || $schema['pattern'] === '' || @preg_match($schema['pattern'], '') === false) {
                throw new ContractViolation('schema_pattern_invalid', $path . '.pattern is not a valid PHP regular expression.');
            }
        }
    }

    /** @param array<string,mixed> $schema */
    private function validateNumber(array $schema, string $path): void
    {
        foreach (array('minimum', 'maximum') as $key) {
            if (isset($schema[$key]) && (!is_int($schema[$key]) && !is_float($schema[$key]))) {
                throw new ContractViolation('schema_numeric_bound_invalid', $path . '.' . $key . ' must be numeric.');
            }
            if (isset($schema[$key]) && !is_finite((float) $schema[$key])) {
                throw new ContractViolation('schema_numeric_bound_invalid', $path . '.' . $key . ' must be finite.');
            }
        }
        if (
            isset($schema['minimum'], $schema['maximum'])
            && (float) $schema['minimum'] > (float) $schema['maximum']
        ) {
            throw new ContractViolation('schema_numeric_bounds_invalid', $path . ' has reversed numeric bounds.');
        }
    }

    /** @param array<string,mixed> $schema */
    private function nonNegativeInt(array $schema, string $key, string $path): ?int
    {
        if (!array_key_exists($key, $schema)) {
            return null;
        }
        if (!is_int($schema[$key]) || $schema[$key] < 0) {
            throw new ContractViolation('schema_integer_bound_invalid', $path . '.' . $key . ' must be a nonnegative integer.');
        }
        return $schema[$key];
    }

    /** @param mixed $value */
    private function assertValueType($value, string $type, string $path): void
    {
        $valid = ($type === 'string' && is_string($value))
            || ($type === 'integer' && is_int($value))
            || ($type === 'number' && (is_int($value) || is_float($value)))
            || ($type === 'boolean' && is_bool($value))
            || ($type === 'null' && $value === null)
            || ($type === 'array' && is_array($value) && Arr::isList($value))
            || ($type === 'object' && is_array($value) && ($value === array() || !Arr::isList($value)));
        if (!$valid) {
            throw new ContractViolation('schema_enum_type_mismatch', $path . ' does not match schema type ' . $type . '.');
        }
    }
}
