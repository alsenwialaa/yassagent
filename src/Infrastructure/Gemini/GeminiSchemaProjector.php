<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Gemini;

/**
 * Projects the plugin's strict internal schemas into Gemini's Schema dialect.
 *
 * PHP represents both an empty JSON object and an empty JSON array as [];
 * Gemini does not. Object-valued fields in parameterized schemas therefore
 * have to be shaped explicitly before JSON encoding. Closed zero-argument
 * tools omit the optional provider `parameters` field before this projection.
 */
final class GeminiSchemaProjector
{
    /** @param array<int,array<string,mixed>> $declarations @return array<int,array<string,mixed>> */
    public function project(array $declarations): array
    {
        $rows = array();
        foreach ($declarations as $declaration) {
            if (!is_array($declaration)) {
                continue;
            }
            if ($this->isClosedZeroArgumentSchema($declaration['parameters'] ?? null)) {
                // Gemini treats an omitted parameters field as a no-argument
                // function. Keep the strict closed empty-object schema in the
                // application contract and remove it only from the wire shape.
                unset($declaration['parameters']);
            }
            $projected = $this->projectValue($declaration, '');
            if (is_array($projected)) {
                $rows[] = $projected;
            }
        }
        return $rows;
    }

    /** @param mixed $schema */
    private function isClosedZeroArgumentSchema($schema): bool
    {
        if (!is_array($schema)) {
            return false;
        }
        $keys = array_keys($schema);
        sort($keys, SORT_STRING);
        return $keys === array('additionalProperties', 'properties', 'type')
            && $schema['type'] === 'object'
            && $schema['properties'] === array()
            && $schema['additionalProperties'] === false;
    }

    /** @param mixed $value @return mixed */
    private function projectValue($value, string $field)
    {
        if (!is_array($value)) {
            return $value;
        }

        $out = array();
        foreach ($value as $key => $item) {
            $key = (string) $key;
            if (
                $key === 'additionalProperties'
                || $key === 'nonBlank'
                || $key === 'pattern'
                || $key === 'uniqueItems'
            ) {
                continue;
            }
            $out[$key] = $this->projectValue($item, $key);
        }

        // These fields are JSON maps/objects in Gemini's schema contract. Cast
        // even non-empty maps so their shape cannot change if numeric-looking
        // property names are introduced later.
        if ($field === 'properties' || $field === 'defs' || $field === '$defs') {
            return (object) $out;
        }

        return $out;
    }
}
