<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery;

use WP_Term;

/** Retrieves bounded product candidates from searchable WooCommerce terms. */
final class CatalogTaxonomyCandidateSource
{
    /**
     * @return array{buckets:array<int,array<int,int>>,category_slugs:array<int,string>}
     */
    public function find(string $query, int $pool): array
    {
        $query = trim(sanitize_text_field($query));
        $pool = max(1, min(72, $pool));
        if ($query === '') {
            return array('buckets' => array(), 'category_slugs' => array());
        }

        $taxonomies = array('product_cat', 'product_tag');
        foreach ((array) wc_get_attribute_taxonomy_names() as $taxonomy) {
            $taxonomy = (string) $taxonomy;
            if ($taxonomy !== '' && taxonomy_exists($taxonomy)) {
                $taxonomies[] = $taxonomy;
            }
        }
        $taxonomies = array_values(array_unique(array_filter(
            $taxonomies,
            static function (string $taxonomy): bool {
                return taxonomy_exists($taxonomy);
            }
        )));
        if ($taxonomies === array()) {
            return array('buckets' => array(), 'category_slugs' => array());
        }

        $terms = get_terms(array(
            'taxonomy' => $taxonomies,
            'hide_empty' => true,
            'number' => 24,
            'search' => $query,
            'orderby' => 'count',
            'order' => 'DESC',
        ));
        if (is_wp_error($terms) || !is_array($terms)) {
            return array('buckets' => array(), 'category_slugs' => array());
        }

        $termIds = array();
        $categorySlugs = array();
        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }
            $taxonomy = (string) $term->taxonomy;
            $termId = (int) $term->term_id;
            if ($taxonomy === '' || $termId < 1 || !in_array($taxonomy, $taxonomies, true)) {
                continue;
            }
            $termIds[$taxonomy][$termId] = true;
            if ($taxonomy === 'product_cat') {
                $slug = sanitize_title((string) $term->slug);
                if ($slug !== '') {
                    $categorySlugs[$slug] = true;
                }
            }
        }

        $buckets = array();
        foreach ($termIds as $taxonomy => $ids) {
            $objects = get_objects_in_term(array_keys($ids), $taxonomy, array('order' => 'ASC'));
            if (is_wp_error($objects) || !is_array($objects)) {
                continue;
            }
            $bucket = array();
            foreach ($objects as $objectId) {
                $objectId = (int) $objectId;
                if ($objectId > 0) {
                    $bucket[$objectId] = true;
                }
                if (count($bucket) >= $pool) {
                    break;
                }
            }
            if ($bucket !== array()) {
                $buckets[] = array_keys($bucket);
            }
        }

        return array(
            'buckets' => $buckets,
            'category_slugs' => array_keys($categorySlugs),
        );
    }
}
