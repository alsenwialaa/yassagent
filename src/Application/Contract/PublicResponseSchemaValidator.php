<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Contract;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Utf8;

/**
 * Bounded validator for the exact JSON-Schema subset used by public responses.
 *
 * This is deliberately not a general JSON-Schema implementation. Any keyword
 * outside the audited subset fails closed so a future schema change cannot be
 * silently ignored by the PHP response boundary.
 */
final class PublicResponseSchemaValidator
{
    private const MAX_DEPTH = 64;

    private const SUPPORTED_KEYWORDS = array(
        '$ref', 'type', 'properties', 'required', 'additionalProperties',
        'items', 'minItems', 'maxItems', 'minLength', 'maxLength',
        'pattern', 'minimum', 'maximum', 'enum', 'const',
        'oneOf', 'anyOf', 'allOf', 'not', 'if', 'then', 'else',
    );

    private const SUPPORTED_TYPES = array(
        'object', 'array', 'string', 'integer', 'number', 'boolean', 'null',
    );

    /** @var PublicApiContract */
    private $contract;

    public function __construct(PublicApiContract $contract)
    {
        $this->contract = $contract;
        foreach (GeneratedPublicApiContract::RESPONSE_DEFINITIONS as $definition) {
            $this->auditSchema(
                $definition,
                $this->contract->responseSchema($definition),
                '$schema.' . $definition,
                0,
                array()
            );
        }
    }

    /** @param array<string,mixed> $payload */
    public function assertResponse(string $definition, array $payload): void
    {
        if (!in_array($definition, GeneratedPublicApiContract::RESPONSE_DEFINITIONS, true)) {
            throw new InvalidArgumentException('Unknown public response definition.');
        }

        $this->validate(
            $definition,
            $this->contract->responseSchema($definition),
            $payload,
            '$',
            0
        );
    }

    /**
     * @param array<string,mixed> $schema
     * @param mixed $value
     */
    private function validate(
        string $definition,
        array $schema,
        $value,
        string $path,
        int $depth
    ): void {
        if ($depth > self::MAX_DEPTH) {
            $this->violation($definition, $path, 'schema recursion exceeded the bounded depth');
        }
        $this->assertSupportedSchema($definition, $schema, $path);

        if (array_key_exists('$ref', $schema)) {
            if (!is_string($schema['$ref'])) {
                $this->violation($definition, $path, 'schema reference is invalid');
            }
            $this->validate(
                $definition,
                $this->contract->resolveLocalReference($schema['$ref']),
                $value,
                $path,
                $depth + 1
            );
        }

        if (array_key_exists('allOf', $schema)) {
            foreach ($this->schemaList($definition, $path, $schema['allOf'], 'allOf') as $branch) {
                $this->validate($definition, $branch, $value, $path, $depth + 1);
            }
        }

        if (array_key_exists('oneOf', $schema)) {
            $matches = 0;
            foreach ($this->schemaList($definition, $path, $schema['oneOf'], 'oneOf') as $branch) {
                if ($this->matches($definition, $branch, $value, $path, $depth + 1)) {
                    ++$matches;
                }
            }
            if ($matches !== 1) {
                $this->violation($definition, $path, 'value must match exactly one schema branch');
            }
        }

        if (array_key_exists('anyOf', $schema)) {
            $matched = false;
            foreach ($this->schemaList($definition, $path, $schema['anyOf'], 'anyOf') as $branch) {
                if ($this->matches($definition, $branch, $value, $path, $depth + 1)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $this->violation($definition, $path, 'value must match at least one schema branch');
            }
        }

        if (array_key_exists('not', $schema)) {
            $branch = $this->schemaObject($definition, $path, $schema['not'], 'not');
            if ($this->matches($definition, $branch, $value, $path, $depth + 1)) {
                $this->violation($definition, $path, 'value matches a forbidden schema branch');
            }
        }

        if (array_key_exists('if', $schema)) {
            $conditionSchema = $this->schemaObject($definition, $path, $schema['if'], 'if');
            $condition = $this->matches(
                $definition,
                $conditionSchema,
                $value,
                $path,
                $depth + 1
            );
            $branchName = $condition ? 'then' : 'else';
            if (array_key_exists($branchName, $schema)) {
                $this->validate(
                    $definition,
                    $this->schemaObject($definition, $path, $schema[$branchName], $branchName),
                    $value,
                    $path,
                    $depth + 1
                );
            }
        }

        if (array_key_exists('type', $schema)) {
            if (
                !is_string($schema['type'])
                || !in_array($schema['type'], self::SUPPORTED_TYPES, true)
                || !$this->hasType($value, $schema['type'])
            ) {
                $this->violation($definition, $path, 'value has the wrong JSON type');
            }
        }

        if (
            array_key_exists('const', $schema)
            && !$this->jsonEquals($value, $schema['const'])
        ) {
            $this->violation($definition, $path, 'value does not match the required constant');
        }

        if (array_key_exists('enum', $schema)) {
            if (!is_array($schema['enum']) || !Arr::isList($schema['enum']) || $schema['enum'] === array()) {
                $this->violation($definition, $path, 'enumeration schema is invalid');
            }
            $matched = false;
            foreach ($schema['enum'] as $allowed) {
                if ($this->jsonEquals($value, $allowed)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $this->violation($definition, $path, 'value is outside the allowed enumeration');
            }
        }

        if (is_string($value)) {
            $this->validateString($definition, $schema, $value, $path);
        }
        if (is_int($value) || is_float($value)) {
            $this->validateNumber($definition, $schema, $value, $path);
        }
        if (is_array($value)) {
            $declaredType = is_string($schema['type'] ?? null) ? $schema['type'] : '';
            if ($declaredType === 'array') {
                /** @var array<int,mixed> $value */
                $this->validateArray($definition, $schema, $value, $path, $depth);
            } elseif ($declaredType === 'object') {
                /** @var array<string,mixed> $value */
                $this->validateObject($definition, $schema, $value, $path, $depth);
            } elseif ($value !== array() && Arr::isList($value)) {
                /** @var array<int,mixed> $value */
                $this->validateArray($definition, $schema, $value, $path, $depth);
            } elseif ($value !== array()) {
                /** @var array<string,mixed> $value */
                $this->validateObject($definition, $schema, $value, $path, $depth);
            }
        }
    }

    /**
     * Audits every response schema branch eagerly so unsupported or malformed
     * constraints cannot remain dormant behind an optional response field.
     *
     * @param array<string,mixed> $schema
     * @param array<string,bool> $references
     */
    private function auditSchema(
        string $definition,
        array $schema,
        string $path,
        int $depth,
        array $references
    ): void {
        if ($depth > self::MAX_DEPTH) {
            $this->violation($definition, $path, 'schema recursion exceeded the bounded depth');
        }
        $this->assertSupportedSchema($definition, $schema, $path);

        if (array_key_exists('$ref', $schema)) {
            if (!is_string($schema['$ref'])) {
                $this->violation($definition, $path, 'schema reference is invalid');
            }
            if (!isset($references[$schema['$ref']])) {
                $references[$schema['$ref']] = true;
                $this->auditSchema(
                    $definition,
                    $this->contract->resolveLocalReference($schema['$ref']),
                    $path . '.$ref',
                    $depth + 1,
                    $references
                );
            }
        }

        if (
            array_key_exists('type', $schema)
            && (!is_string($schema['type'])
                || !in_array($schema['type'], self::SUPPORTED_TYPES, true))
        ) {
            $this->violation($definition, $path, 'schema type is unsupported');
        }

        if (array_key_exists('properties', $schema)) {
            if (
                !is_array($schema['properties'])
                || ($schema['properties'] !== array() && Arr::isList($schema['properties']))
            ) {
                $this->violation($definition, $path, 'object property schema is invalid');
            }
            foreach ($schema['properties'] as $field => $propertySchema) {
                if (!is_string($field) || $field === '') {
                    $this->violation($definition, $path, 'object property name is invalid');
                }
                $this->auditSchema(
                    $definition,
                    $this->schemaObject($definition, $path, $propertySchema, 'property ' . $field),
                    $path . '.properties.' . $field,
                    $depth + 1,
                    $references
                );
            }
        }

        if (array_key_exists('required', $schema)) {
            if (!is_array($schema['required']) || !Arr::isList($schema['required'])) {
                $this->violation($definition, $path, 'required property list is invalid');
            }
            $seen = array();
            foreach ($schema['required'] as $field) {
                if (!is_string($field) || $field === '' || isset($seen[$field])) {
                    $this->violation($definition, $path, 'required property list is invalid');
                }
                $seen[$field] = true;
            }
        }

        if (array_key_exists('items', $schema)) {
            $this->auditSchema(
                $definition,
                $this->schemaObject($definition, $path, $schema['items'], 'items'),
                $path . '.items',
                $depth + 1,
                $references
            );
        }

        foreach (array('oneOf', 'anyOf', 'allOf') as $keyword) {
            if (!array_key_exists($keyword, $schema)) {
                continue;
            }
            foreach ($this->schemaList($definition, $path, $schema[$keyword], $keyword) as $index => $branch) {
                $this->auditSchema(
                    $definition,
                    $branch,
                    $path . '.' . $keyword . '[' . $index . ']',
                    $depth + 1,
                    $references
                );
            }
        }

        foreach (array('not', 'if', 'then', 'else') as $keyword) {
            if (!array_key_exists($keyword, $schema)) {
                continue;
            }
            $this->auditSchema(
                $definition,
                $this->schemaObject($definition, $path, $schema[$keyword], $keyword),
                $path . '.' . $keyword,
                $depth + 1,
                $references
            );
        }

        if (
            array_key_exists('enum', $schema)
            && (!is_array($schema['enum']) || !Arr::isList($schema['enum']) || $schema['enum'] === array())
        ) {
            $this->violation($definition, $path, 'enumeration schema is invalid');
        }
        foreach (array('minLength', 'maxLength', 'minItems', 'maxItems') as $keyword) {
            if (
                array_key_exists($keyword, $schema)
                && !$this->isNonNegativeSchemaInteger($schema[$keyword])
            ) {
                $this->violation($definition, $path, $keyword . ' schema bound is invalid');
            }
        }
        foreach (array('minimum', 'maximum') as $keyword) {
            if (array_key_exists($keyword, $schema) && !$this->isSchemaNumber($schema[$keyword])) {
                $this->violation($definition, $path, $keyword . ' schema bound is invalid');
            }
        }
        if (array_key_exists('pattern', $schema)) {
            if (
                !is_string($schema['pattern'])
                || @preg_match('~' . str_replace('~', '\\~', $schema['pattern']) . '~uD', '') === false
            ) {
                $this->violation($definition, $path, 'string pattern schema is invalid');
            }
        }
        if (
            array_key_exists('minLength', $schema)
            && array_key_exists('maxLength', $schema)
            && $schema['minLength'] > $schema['maxLength']
        ) {
            $this->violation($definition, $path, 'string schema bounds are contradictory');
        }
        if (
            array_key_exists('minItems', $schema)
            && array_key_exists('maxItems', $schema)
            && $schema['minItems'] > $schema['maxItems']
        ) {
            $this->violation($definition, $path, 'array schema bounds are contradictory');
        }
        if (
            array_key_exists('minimum', $schema)
            && array_key_exists('maximum', $schema)
            && (float) $schema['minimum'] > (float) $schema['maximum']
        ) {
            $this->violation($definition, $path, 'number schema bounds are contradictory');
        }
    }

    /** @param array<string,mixed> $schema */
    private function assertSupportedSchema(string $definition, array $schema, string $path): void
    {
        if ($schema !== array() && Arr::isList($schema)) {
            $this->violation($definition, $path, 'schema object is invalid');
        }
        foreach (array_keys($schema) as $keyword) {
            if (!is_string($keyword) || !in_array($keyword, self::SUPPORTED_KEYWORDS, true)) {
                $this->violation(
                    $definition,
                    $path,
                    'schema uses an unsupported keyword' . (is_string($keyword) ? ': ' . $keyword : '')
                );
            }
        }
        if (
            (array_key_exists('then', $schema) || array_key_exists('else', $schema))
            && !array_key_exists('if', $schema)
        ) {
            $this->violation($definition, $path, 'conditional branch is missing its if schema');
        }
        if (
            array_key_exists('additionalProperties', $schema)
            && $schema['additionalProperties'] !== false
        ) {
            $this->violation($definition, $path, 'only closed object schemas are supported');
        }
    }

    /** @param array<string,mixed> $schema */
    private function validateString(
        string $definition,
        array $schema,
        string $value,
        string $path
    ): void {
        try {
            $length = Utf8::codePointLength($value);
        } catch (InvalidArgumentException $exception) {
            $this->violation($definition, $path, 'string is not valid UTF-8');
            return;
        }

        if (
            array_key_exists('minLength', $schema)
            && (!$this->isNonNegativeSchemaInteger($schema['minLength'])
                || $length < (int) $schema['minLength'])
        ) {
            $this->violation($definition, $path, 'string is shorter than the schema minimum');
        }
        if (
            array_key_exists('maxLength', $schema)
            && (!$this->isNonNegativeSchemaInteger($schema['maxLength'])
                || $length > (int) $schema['maxLength'])
        ) {
            $this->violation($definition, $path, 'string exceeds the schema maximum');
        }
        if (
            array_key_exists('minLength', $schema)
            && array_key_exists('maxLength', $schema)
            && $schema['minLength'] > $schema['maxLength']
        ) {
            $this->violation($definition, $path, 'string schema bounds are contradictory');
        }
        if (array_key_exists('pattern', $schema)) {
            if (!is_string($schema['pattern'])) {
                $this->violation($definition, $path, 'string pattern is invalid');
            }
            $regex = '~' . str_replace('~', '\\~', $schema['pattern']) . '~uD';
            $matched = preg_match($regex, $value);
            if ($matched !== 1) {
                $this->violation(
                    $definition,
                    $path,
                    $matched === false
                        ? 'string pattern cannot be evaluated'
                        : 'string does not match the schema pattern'
                );
            }
        }
    }

    /** @param array<string,mixed> $schema @param int|float $value */
    private function validateNumber(
        string $definition,
        array $schema,
        $value,
        string $path
    ): void {
        if (!is_finite((float) $value)) {
            $this->violation($definition, $path, 'number is not finite');
        }
        if (
            ($schema['type'] ?? null) === 'integer'
            && (float) $value !== floor((float) $value)
        ) {
            $this->violation($definition, $path, 'number is not an integer');
        }
        if (array_key_exists('minimum', $schema)) {
            if (
                !$this->isSchemaNumber($schema['minimum'])
                || (float) $value < (float) $schema['minimum']
            ) {
                $this->violation($definition, $path, 'number is below the schema minimum');
            }
        }
        if (array_key_exists('maximum', $schema)) {
            if (
                !$this->isSchemaNumber($schema['maximum'])
                || (float) $value > (float) $schema['maximum']
            ) {
                $this->violation($definition, $path, 'number exceeds the schema maximum');
            }
        }
        if (
            array_key_exists('minimum', $schema)
            && array_key_exists('maximum', $schema)
            && $this->isSchemaNumber($schema['minimum'])
            && $this->isSchemaNumber($schema['maximum'])
            && (float) $schema['minimum'] > (float) $schema['maximum']
        ) {
            $this->violation($definition, $path, 'number schema bounds are contradictory');
        }
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<int,mixed> $value
     */
    private function validateArray(
        string $definition,
        array $schema,
        array $value,
        string $path,
        int $depth
    ): void {
        if (!Arr::isList($value)) {
            $this->violation($definition, $path, 'array value is not a JSON list');
        }
        $count = count($value);
        if (
            array_key_exists('minItems', $schema)
            && (!$this->isNonNegativeSchemaInteger($schema['minItems'])
                || $count < (int) $schema['minItems'])
        ) {
            $this->violation($definition, $path, 'array has too few items');
        }
        if (
            array_key_exists('maxItems', $schema)
            && (!$this->isNonNegativeSchemaInteger($schema['maxItems'])
                || $count > (int) $schema['maxItems'])
        ) {
            $this->violation($definition, $path, 'array has too many items');
        }
        if (
            array_key_exists('minItems', $schema)
            && array_key_exists('maxItems', $schema)
            && $schema['minItems'] > $schema['maxItems']
        ) {
            $this->violation($definition, $path, 'array schema bounds are contradictory');
        }
        if (array_key_exists('items', $schema)) {
            $itemSchema = $this->schemaObject($definition, $path, $schema['items'], 'items');
            foreach ($value as $index => $item) {
                $this->validate(
                    $definition,
                    $itemSchema,
                    $item,
                    $path . '[' . $index . ']',
                    $depth + 1
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $value
     */
    private function validateObject(
        string $definition,
        array $schema,
        array $value,
        string $path,
        int $depth
    ): void {
        if ($value !== array() && Arr::isList($value)) {
            $this->violation($definition, $path, 'object value is a JSON list');
        }
        $properties = $schema['properties'] ?? array();
        if (!is_array($properties) || ($properties !== array() && Arr::isList($properties))) {
            $this->violation($definition, $path, 'object property schema is invalid');
        }

        if (array_key_exists('required', $schema)) {
            if (!is_array($schema['required']) || !Arr::isList($schema['required'])) {
                $this->violation($definition, $path, 'required property list is invalid');
            }
            $seen = array();
            foreach ($schema['required'] as $field) {
                if (!is_string($field) || $field === '' || isset($seen[$field])) {
                    $this->violation($definition, $path, 'required property list is invalid');
                }
                $seen[$field] = true;
                if (!array_key_exists($field, $value)) {
                    $this->violation($definition, $path, 'required property is missing: ' . $field);
                }
            }
        }

        if (($schema['additionalProperties'] ?? null) === false) {
            foreach ($value as $field => $_item) {
                if (!is_string($field) || !array_key_exists($field, $properties)) {
                    $this->violation(
                        $definition,
                        $path,
                        'unknown property is not allowed' . (is_string($field) ? ': ' . $field : '')
                    );
                }
            }
        }

        foreach ($properties as $field => $propertySchema) {
            if (!is_string($field) || $field === '') {
                $this->violation($definition, $path, 'object property name is invalid');
            }
            if (!array_key_exists($field, $value)) {
                continue;
            }
            $this->validate(
                $definition,
                $this->schemaObject($definition, $path, $propertySchema, 'property ' . $field),
                $value[$field],
                $path . '.' . $field,
                $depth + 1
            );
        }
    }

    /** @param array<string,mixed> $schema @param mixed $value */
    private function matches(
        string $definition,
        array $schema,
        $value,
        string $path,
        int $depth
    ): bool {
        try {
            $this->validate($definition, $schema, $value, $path, $depth);
            return true;
        } catch (PublicResponseContractViolation $exception) {
            return false;
        }
    }

    /** @param mixed $value */
    private function hasType($value, string $type): bool
    {
        switch ($type) {
            case 'object':
                return is_array($value) && ($value === array() || !Arr::isList($value));
            case 'array':
                return is_array($value) && ($value === array() || Arr::isList($value));
            case 'string':
                return is_string($value);
            case 'integer':
                return (is_int($value) || is_float($value))
                    && is_finite((float) $value)
                    && (float) $value === floor((float) $value);
            case 'number':
                return (is_int($value) || is_float($value)) && is_finite((float) $value);
            case 'boolean':
                return is_bool($value);
            case 'null':
                return $value === null;
        }
        return false;
    }

    /** @param mixed $value */
    private function isNonNegativeSchemaInteger($value): bool
    {
        return is_int($value) && $value >= 0;
    }

    /** @param mixed $value */
    private function isSchemaNumber($value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    /** @param mixed $value */
    private function jsonEquals($value, $expected): bool
    {
        if (
            (is_int($value) || is_float($value))
            && (is_int($expected) || is_float($expected))
            && is_finite((float) $value)
            && is_finite((float) $expected)
        ) {
            return (float) $value === (float) $expected;
        }
        if (is_array($value) && is_array($expected)) {
            $valueIsList = Arr::isList($value);
            $expectedIsList = Arr::isList($expected);
            if ($value !== array() && $expected !== array() && $valueIsList !== $expectedIsList) {
                return false;
            }
            if (count($value) !== count($expected)) {
                return false;
            }
            if ($valueIsList && $expectedIsList) {
                foreach ($value as $index => $item) {
                    if (!$this->jsonEquals($item, $expected[$index])) {
                        return false;
                    }
                }
                return true;
            }
            $valueKeys = array_keys($value);
            $expectedKeys = array_keys($expected);
            sort($valueKeys, SORT_STRING);
            sort($expectedKeys, SORT_STRING);
            if ($valueKeys !== $expectedKeys) {
                return false;
            }
            foreach ($valueKeys as $key) {
                if (!$this->jsonEquals($value[$key], $expected[$key])) {
                    return false;
                }
            }
            return true;
        }
        return $value === $expected;
    }

    /** @param mixed $value @return array<int,array<string,mixed>> */
    private function schemaList(string $definition, string $path, $value, string $keyword): array
    {
        if (!is_array($value) || !Arr::isList($value) || $value === array()) {
            $this->violation($definition, $path, $keyword . ' schema list is invalid');
        }
        $out = array();
        foreach ($value as $branch) {
            $out[] = $this->schemaObject($definition, $path, $branch, $keyword);
        }
        return $out;
    }

    /** @param mixed $value @return array<string,mixed> */
    private function schemaObject(string $definition, string $path, $value, string $keyword): array
    {
        if (!is_array($value) || ($value !== array() && Arr::isList($value))) {
            $this->violation($definition, $path, $keyword . ' schema is invalid');
        }
        /** @var array<string,mixed> $value */
        return $value;
    }

    private function violation(string $definition, string $path, string $reason): void
    {
        throw new PublicResponseContractViolation($definition, $path, $reason);
    }
}
