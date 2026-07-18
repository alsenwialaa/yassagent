<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery;

/** Applies explicit category slugs as a hard boundary to every candidate source. */
final class CatalogCategoryEligibility
{
    /** @param array<string,mixed> $product @param array<int,string> $requiredSlugs */
    public function allows(array $product, array $requiredSlugs): bool
    {
        return $requiredSlugs === array() || $this->matched($product, $requiredSlugs) !== array();
    }

    /** @param array<string,mixed> $product @param array<int,string> $slugs @return array<int,string> */
    public function matched(array $product, array $slugs): array
    {
        $required = array_fill_keys($slugs, true);
        $matched = array();
        foreach ((array) ($product['category_filter_slugs'] ?? $product['category_slugs'] ?? array()) as $slug) {
            $slug = trim((string) $slug);
            if ($slug !== '' && isset($required[$slug])) {
                $matched[$slug] = true;
            }
        }
        return array_keys($matched);
    }
}
