<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery;

/** Builds bounded public-catalog query filters from validated tool arguments. */
final class CatalogQueryFilter
{
    /** @param array<string,mixed> $queryArgs @param array<string,mixed> $args */
    public function apply(array &$queryArgs, array $args, string $context): void
    {
        $metaQuery = array();
        if (!empty($args['in_stock_only'])) {
            $metaQuery[] = array('key' => '_stock_status', 'value' => 'instock', 'compare' => '=');
        }
        if ($metaQuery !== array()) {
            $queryArgs['meta_query'] = $metaQuery;
        }

        $taxQuery = array();
        $visibility = wc_get_product_visibility_term_ids();
        $visibilityKey = $context === 'search'
            ? 'exclude-from-search'
            : 'exclude-from-catalog';
        $excluded = array_values(array_filter(array(
            (int) ($visibility[$visibilityKey] ?? 0),
        )));
        if ($excluded !== array()) {
            $taxQuery[] = array(
                'taxonomy' => 'product_visibility',
                'field' => 'term_taxonomy_id',
                'terms' => $excluded,
                'operator' => 'NOT IN',
            );
        }
        $slugs = $this->categorySlugs($args);
        if ($slugs !== array()) {
            $taxQuery[] = array(
                'taxonomy' => 'product_cat',
                'field' => 'slug',
                'terms' => $slugs,
                'operator' => 'IN',
            );
        }
        if ($taxQuery !== array()) {
            $queryArgs['tax_query'] = $taxQuery;
        }
    }

    /** @param array<string,mixed> $args @return array<int,string> */
    public function categorySlugs(array $args): array
    {
        $raw = array();
        foreach ((array) ($args['category_slugs'] ?? array()) as $slug) {
            $raw[] = $slug;
        }
        $rows = array();
        foreach ($raw as $slug) {
            $slug = sanitize_title((string) $slug);
            if ($slug !== '') {
                $rows[$slug] = true;
            }
        }
        return array_keys($rows);
    }
}
