<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Support;

/**
 * Decodes entities only in trusted WooCommerce/WordPress catalog and cart
 * display facts; model-authored output must never pass through this helper.
 */
final class TrustedCommerceText
{
    public static function decodeEntities(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
