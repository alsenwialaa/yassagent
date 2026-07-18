<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Shopping;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Utf8;

/**
 * Closed, model-proposed shopping-context transition.
 *
 * This object is context only. It never grants product, variation, cart-line,
 * or mutation authority.
 */
final class ShoppingMemoryPatch
{
    public const MERGE = 'merge';
    public const REPLACE_TOPIC = 'replace_topic';
    public const CLEAR = 'clear';

    /** @var array<string,mixed> */ private $payload;

    /** @param array<string,mixed> $payload */
    public function __construct(array $payload)
    {
        if ($payload !== array() && Arr::isList($payload)) {
            throw new InvalidArgumentException('Shopping-memory patch must be an object.');
        }
        $allowed = array('mode', 'goal', 'stage', 'constraints', 'remove_constraint_keys', 'compared_products', 'unresolved_question');
        $keys = array_keys($payload);
        foreach ($keys as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('Shopping-memory patch contains an unsupported field.');
            }
        }

        $mode = isset($payload['mode']) && is_string($payload['mode']) ? $payload['mode'] : '';
        if (!in_array($mode, array(self::MERGE, self::REPLACE_TOPIC, self::CLEAR), true)) {
            throw new InvalidArgumentException('Shopping-memory patch mode is invalid.');
        }
        if ($mode === self::CLEAR) {
            if ($keys !== array('mode')) {
                sort($keys, SORT_STRING);
                if ($keys !== array('mode')) {
                    throw new InvalidArgumentException('A clear shopping-memory patch cannot contain other fields.');
                }
            }
            $this->payload = array('mode' => self::CLEAR);
            return;
        }

        $normalized = array('mode' => $mode);
        if (array_key_exists('goal', $payload)) {
            $normalized['goal'] = self::boundedNonblank($payload['goal'], 320, 'Shopping goal');
            ShoppingMemoryPrivacyPolicy::assertNarrativeAllowed($normalized['goal']);
        }
        if (array_key_exists('stage', $payload)) {
            $stage = is_string($payload['stage']) ? $payload['stage'] : '';
            if (!in_array($stage, array('discovering', 'comparing', 'configuring', 'deciding', 'cart'), true)) {
                throw new InvalidArgumentException('Shopping stage is invalid.');
            }
            $normalized['stage'] = $stage;
        }
        if (array_key_exists('constraints', $payload)) {
            $constraints = self::constraints($payload['constraints']);
            if ($constraints !== array()) {
                $normalized['constraints'] = $constraints;
            }
        }
        if (array_key_exists('remove_constraint_keys', $payload)) {
            $removeKeys = self::constraintKeys($payload['remove_constraint_keys']);
            if ($removeKeys !== array()) {
                $normalized['remove_constraint_keys'] = $removeKeys;
            }
        }
        if (array_key_exists('compared_products', $payload)) {
            $normalized['compared_products'] = self::products($payload['compared_products']);
        }
        if (array_key_exists('unresolved_question', $payload)) {
            if (!is_string($payload['unresolved_question'])) {
                throw new InvalidArgumentException('Unresolved shopping question must be text.');
            }
            $question = trim($payload['unresolved_question']);
            if (self::length($question) > 320) {
                throw new InvalidArgumentException('Unresolved shopping question is too large.');
            }
            ShoppingMemoryPrivacyPolicy::assertNarrativeAllowed($question);
            $normalized['unresolved_question'] = $question;
        }
        if (count($normalized) === 1) {
            throw new InvalidArgumentException('Shopping-memory patch has no transition.');
        }
        $this->payload = $normalized;
    }

    public function mode(): string
    {
        return (string) $this->payload['mode'];
    }
    public function has(string $field): bool
    {
        return array_key_exists($field, $this->payload);
    }
    /** @return mixed */ public function value(string $field)
    {
        return $this->payload[$field] ?? null;
    }
    /** @return array<string,mixed> */ public function toArray(): array
    {
        return $this->payload;
    }

    /** @param mixed $value */
    private static function boundedNonblank($value, int $max, string $label): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ' must be text.');
        }
        $value = trim($value);
        if ($value === '' || self::length($value) > $max) {
            throw new InvalidArgumentException($label . ' is blank or too large.');
        }
        return $value;
    }

    /** @param mixed $value @return array<int,array{key:string,value:string,priority:string,polarity:string}> */
    private static function constraints($value): array
    {
        if (!is_array($value) || !Arr::isList($value) || count($value) > 16) {
            throw new InvalidArgumentException('Shopping constraints must be a bounded list.');
        }
        $rows = array();
        $seen = array();
        $seenValues = array();
        foreach ($value as $row) {
            if (!is_array($row) || ($row !== array() && Arr::isList($row))) {
                throw new InvalidArgumentException('Shopping constraint is invalid.');
            }
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if ($keys !== array('key', 'polarity', 'priority', 'value')) {
                throw new InvalidArgumentException('Shopping constraint fields are invalid.');
            }
            $key = isset($row['key']) && is_string($row['key']) ? strtolower(trim($row['key'])) : '';
            $constraintValue = self::boundedNonblank($row['value'] ?? null, 160, 'Shopping constraint value');
            $priority = isset($row['priority']) && is_string($row['priority']) ? $row['priority'] : '';
            $polarity = isset($row['polarity']) && is_string($row['polarity']) ? $row['polarity'] : '';
            ShoppingMemoryPrivacyPolicy::assertConstraintKeyAllowed($key);
            ShoppingMemoryPrivacyPolicy::assertConstraintValueAllowed($key, $constraintValue);
            if (
                preg_match('/^[a-z][a-z0-9_]{0,47}$/', $key) !== 1
                || !in_array($priority, array('required', 'preferred'), true)
                || !in_array($polarity, array('include', 'exclude'), true)
            ) {
                throw new InvalidArgumentException('Shopping constraint semantics are invalid.');
            }
            $identity = $key . '|' . $polarity;
            $valueIdentity = function_exists('mb_strtolower')
                ? mb_strtolower($constraintValue, 'UTF-8') : strtolower($constraintValue);
            $valueIdentity = trim((string) preg_replace('/\s+/u', ' ', $valueIdentity));
            $opposite = $key . '|' . ($polarity === 'include' ? 'exclude' : 'include') . '|' . $valueIdentity;
            if (isset($seen[$identity]) || isset($seenValues[$opposite])) {
                throw new InvalidArgumentException('Shopping-memory patch contains duplicate or contradictory constraints.');
            }
            $seen[$identity] = true;
            $seenValues[$key . '|' . $polarity . '|' . $valueIdentity] = true;
            $rows[] = array(
                'key' => $key,
                'value' => $constraintValue,
                'priority' => $priority,
                'polarity' => $polarity,
            );
        }
        return $rows;
    }


    /** @param mixed $value @return array<int,string> */
    private static function constraintKeys($value): array
    {
        if (!is_array($value) || !Arr::isList($value) || count($value) > 16) {
            throw new InvalidArgumentException('Removed constraint keys must be a bounded list.');
        }
        $rows = array();
        foreach ($value as $key) {
            $key = is_string($key) ? strtolower(trim($key)) : '';
            ShoppingMemoryPrivacyPolicy::assertConstraintKeyAllowed($key);
            if (preg_match('/^[a-z][a-z0-9_]{0,47}$/', $key) !== 1 || isset($rows[$key])) {
                throw new InvalidArgumentException('Removed constraint key is invalid or duplicated.');
            }
            $rows[$key] = true;
        }
        return array_keys($rows);
    }

    /** @param mixed $value @return array<int,array{id:int,name:string}> */
    private static function products($value): array
    {
        if (!is_array($value) || !Arr::isList($value) || count($value) > 8) {
            throw new InvalidArgumentException('Compared products must be a bounded list.');
        }
        $rows = array();
        $seen = array();
        foreach ($value as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Compared product is invalid.');
            }
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            $id = isset($row['id']) && is_int($row['id']) ? $row['id'] : 0;
            $name = isset($row['name']) && is_string($row['name']) ? trim($row['name']) : '';
            if ($keys !== array('id', 'name') || $id < 1 || $name === '' || self::length($name) > 240 || isset($seen[$id])) {
                throw new InvalidArgumentException('Compared product fields are invalid.');
            }
            $seen[$id] = true;
            $rows[] = array('id' => $id, 'name' => $name);
        }
        return $rows;
    }

    private static function length(string $value): int
    {
        return Utf8::codePointLength($value);
    }
}
