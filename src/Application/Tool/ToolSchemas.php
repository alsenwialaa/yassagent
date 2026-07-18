<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool;

use YassinStore\AiAssistant\Domain\Commerce\CartQuantity;
use YassinStore\AiAssistant\Domain\Commerce\CartCommand;

final class ToolSchemas
{
    /** @param array<string,mixed> $schema @return array<string,mixed> */
    public static function described(array $schema, string $description): array
    {
        $description = trim($description);
        if ($description === '' || strlen($description) > 2048) {
            throw new \InvalidArgumentException('Schema description must be nonblank and bounded.');
        }
        $schema['description'] = $description;
        return $schema;
    }

    /** @return array<string,mixed> */
    public static function emptyObject(): array
    {
        return self::closedObject(array());
    }

    /** @param array<string,array<string,mixed>> $properties @param array<int,string> $required @return array<string,mixed> */
    public static function closedObject(array $properties, array $required = array()): array
    {
        $schema = array(
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => false,
        );
        if ($required !== array()) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    /** @return array<string,mixed> */
    public static function reference(): array
    {
        return array(
            'type' => 'string',
            'minLength' => 2,
            'maxLength' => 7,
            'nonBlank' => true,
            'pattern' => '/^[pvcd][1-9][0-9]{0,5}$/',
        );
    }

    /** @return array<string,mixed> */
    public static function opaqueRef(string $prefix): array
    {
        if (!in_array($prefix, array('p', 'v', 'c', 'd'), true)) {
            throw new \InvalidArgumentException('Unsupported authority-reference prefix.');
        }
        return array(
            'type' => 'string',
            'minLength' => 2,
            'maxLength' => 7,
            'nonBlank' => true,
            'pattern' => '/^' . $prefix . '[1-9][0-9]{0,5}$/',
        );
    }

    /** @return array<string,mixed> */
    public static function productReferences(): array
    {
        return array(
            'type' => 'array',
            'items' => self::opaqueRef('p'),
            'maxItems' => 8,
            'uniqueItems' => true,
        );
    }

    /** @return array<string,mixed> */
    public static function variationReferences(): array
    {
        return array(
            'type' => 'array',
            'items' => self::opaqueRef('v'),
            'maxItems' => 8,
            'uniqueItems' => true,
        );
    }

    /** @return array<string,mixed> */
    public static function boundedText(int $maxLength): array
    {
        return array(
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => $maxLength,
            'nonBlank' => true,
        );
    }

    /** @return array<string,mixed> */
    public static function cartCommand(): array
    {
        return self::closedObject(array(
            'type' => self::described(
                array('type' => 'string', 'enum' => CartCommand::types()),
                'One semantic operation: add, update, remove, replace, or clear.'
            ),
            'product_ref' => self::described(
                self::opaqueRef('p'),
                'Fresh current-turn product authority; used only by add or replace.'
            ),
            'variation_ref' => self::described(
                self::opaqueRef('v'),
                'Fresh inspected variation authority required for a resolved variable product.'
            ),
            'cart_item_ref' => self::described(
                self::opaqueRef('c'),
                'Fresh cart_view line authority; used by update, remove, or replace.'
            ),
            'quantity_mode' => self::described(array(
                'type' => 'string',
                'enum' => array('default', 'exact', 'set', 'increment', 'decrement', 'preserve'),
            ), 'Meaning of quantity, not a generic default: add default/exact, update set/increment/decrement, replace preserve/exact.'),
            'quantity' => self::described(
                array('type' => 'integer', 'minimum' => 0, 'maximum' => CartQuantity::MAX),
                'Customer-stated count or delta. Omit when the selected quantity_mode requires omission.'
            ),
        ), array('type'));
    }
}
