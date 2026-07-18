<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection;

use WC_Product;
use WP_Term;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\ProductCapabilityPolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\VariableProductCatalogPolicy;
use YassinStore\AiAssistant\Support\PublicHttpUrl;

final class ProductSnapshotFactory
{
    /** @var AttributePresenter */ private $attributes;
    /** @var DisplayPriceProjection */ private $prices;
    /** @var ProductCapabilityPolicy */ private $capabilities;
    /** @var VariableProductCatalogPolicy */ private $variationCatalog;

    public function __construct(
        AttributePresenter $attributes,
        DisplayPriceProjection $prices,
        ProductCapabilityPolicy $capabilities,
        VariableProductCatalogPolicy $variationCatalog
    ) {
        $this->attributes = $attributes;
        $this->prices = $prices;
        $this->capabilities = $capabilities;
        $this->variationCatalog = $variationCatalog;
    }

    /** @return array<string,mixed> */
    public function create(WC_Product $product): array
    {
        $imageId = (int) $product->get_image_id();
        $image = StorefrontImage::url($imageId);

        $categories = array();
        $categorySlugs = array();
        $categoryFilterSlugs = array();
        $terms = wp_get_post_terms($product->get_id(), 'product_cat');
        foreach (is_array($terms) ? $terms : array() as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }
            $categories[] = (string) $term->name;
            $categorySlugs[] = (string) $term->slug;
            $categoryFilterSlugs[(string) $term->slug] = true;
            foreach ((array) get_ancestors((int) $term->term_id, 'product_cat', 'taxonomy') as $ancestorId) {
                $ancestor = get_term((int) $ancestorId, 'product_cat');
                if ($ancestor instanceof WP_Term && trim((string) $ancestor->slug) !== '') {
                    $categoryFilterSlugs[(string) $ancestor->slug] = true;
                }
            }
        }
        $tags = array();
        $tagTerms = wp_get_post_terms($product->get_id(), 'product_tag');
        foreach (is_array($tagTerms) ? $tagTerms : array() as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }
            $tags[] = (string) $term->name;
        }
        $created = $product->get_date_created();
        $price = $this->prices->create($product);
        $variationCatalogReason = $this->variationCatalog->reason($product);
        $permalink = (string) $product->get_permalink();
        if (!PublicHttpUrl::isSafe($permalink)) {
            throw new ProductProjectionException('WooCommerce product permalink is not a safe public HTTP URL.');
        }

        return array(
            'id' => (int) $product->get_id(),
            'name' => (string) $product->get_name(),
            'type' => (string) $product->get_type(),
            'sku' => (string) $product->get_sku(),
            'price' => $price['price'],
            'price_min' => $price['price_min'],
            'price_max' => $price['price_max'],
            'price_is_range' => $price['price_is_range'],
            'price_status' => $price['price_status'],
            'price_basis' => $price['price_basis'],
            'formatted_price' => $price['formatted_price'],
            'currency' => $price['currency'],
            'on_sale' => (bool) $product->is_on_sale(),
            'in_stock' => (bool) $product->is_in_stock(),
            'stock_status' => (string) $product->get_stock_status(),
            'purchasable' => (bool) $product->is_purchasable(),
            'cart_supported' => $this->capabilities->cartSupported($product),
            'cart_support_reason' => $this->capabilities->reason($product),
            'variation_catalog_supported' => VariableProductCatalogPolicy::reasonIsSupported(
                $variationCatalogReason
            ),
            'variation_catalog_reason' => $variationCatalogReason,
            'sold_individually' => (bool) $product->is_sold_individually(),
            'requires_variation' => (bool) $product->is_type('variable'),
            'weight' => (string) $product->get_weight(),
            'dimensions' => wp_strip_all_tags((string) wc_format_dimensions($product->get_dimensions(false))),
            'short_description' => wp_trim_words(
                wp_strip_all_tags((string) $product->get_short_description()),
                45
            ),
            'description' => wp_trim_words(
                wp_strip_all_tags((string) $product->get_description()),
                120
            ),
            'categories' => array_slice($categories, 0, 8),
            'category_slugs' => array_slice($categorySlugs, 0, 8),
            'category_filter_slugs' => array_slice(array_keys($categoryFilterSlugs), 0, 24),
            'tags' => array_slice($tags, 0, 12),
            'attributes' => array_slice($this->attributes->productAttributes($product), 0, 16),
            'average_rating' => (string) $product->get_average_rating(),
            'rating_count' => (int) $product->get_rating_count(),
            'review_count' => (int) $product->get_review_count(),
            'total_sales' => (int) $product->get_total_sales(),
            'date_created' => $created !== null ? (int) $created->getTimestamp() : 0,
            'image' => PublicHttpUrl::optional($image),
            'permalink' => $permalink,
        );
    }
}
