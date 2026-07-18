<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce;

use WC_Product;
use WC_Product_Variation;
use WP_Query;
use YassinStore\AiAssistant\Application\Port\ProductCatalogPort;
use YassinStore\AiAssistant\Domain\Exception\SafeCommerceException;
use YassinStore\AiAssistant\Domain\Shopping\CatalogTextNormalizer;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery\CatalogMatchScorer;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery\CatalogCandidateMerger;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery\CatalogAlternativeRanker;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery\CatalogCategoryEligibility;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery\CatalogPricePolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery\CatalogQueryFilter;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery\CatalogTaxonomyCandidateSource;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\CatalogVisibilityPolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\ProductSnapshotFactory;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\ProductProjectionException;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\VariationSnapshotFactory;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\VariationAuthorityEpoch;

/** Live, read-only WooCommerce catalog adapter. */
final class ProductCatalog implements ProductCatalogPort
{
    /** @var ProductSnapshotFactory */ private $products;
    /** @var VariationSnapshotFactory */ private $variations;
    /** @var VariationAuthorityEpoch */ private $variationEpoch;
    /** @var VariableProductCatalogPolicy */ private $variationCatalog;
    /** @var ProductCapabilityPolicy */ private $capabilities;
    /** @var CatalogVisibilityPolicy */ private $visibility;
    /** @var CatalogMatchScorer */ private $scorer;
    /** @var CatalogTextNormalizer */ private $normalizer;
    /** @var CatalogCandidateMerger */ private $candidates;
    /** @var CatalogPricePolicy */ private $prices;
    /** @var CatalogCategoryEligibility */ private $categories;
    /** @var CatalogQueryFilter */ private $filters;
    /** @var CatalogAlternativeRanker */ private $alternativeRanker;
    /** @var CatalogTaxonomyCandidateSource */ private $taxonomyCandidates;

    public function __construct(
        ProductSnapshotFactory $products,
        VariationSnapshotFactory $variations,
        VariationAuthorityEpoch $variationEpoch,
        VariableProductCatalogPolicy $variationCatalog,
        ProductCapabilityPolicy $capabilities,
        CatalogVisibilityPolicy $visibility,
        CatalogMatchScorer $scorer,
        CatalogTextNormalizer $normalizer,
        CatalogCandidateMerger $candidates,
        CatalogPricePolicy $prices,
        CatalogCategoryEligibility $categories,
        CatalogQueryFilter $filters,
        CatalogAlternativeRanker $alternativeRanker,
        CatalogTaxonomyCandidateSource $taxonomyCandidates
    ) {
        $this->products = $products;
        $this->variations = $variations;
        $this->variationEpoch = $variationEpoch;
        $this->variationCatalog = $variationCatalog;
        $this->capabilities = $capabilities;
        $this->visibility = $visibility;
        $this->scorer = $scorer;
        $this->normalizer = $normalizer;
        $this->candidates = $candidates;
        $this->prices = $prices;
        $this->categories = $categories;
        $this->filters = $filters;
        $this->alternativeRanker = $alternativeRanker;
        $this->taxonomyCandidates = $taxonomyCandidates;
    }

    /** @param array<string,mixed> $args @return array<int,array<string,mixed>> */
    public function discover(array $args): array
    {
        $queries = array();
        foreach ((array) ($args['queries'] ?? array()) as $query) {
            $query = trim(sanitize_text_field((string) $query));
            if ($query !== '' && !isset($queries[$query])) {
                $queries[$query] = true;
            }
        }
        $queries = array_keys($queries);
        $sort = (string) ($args['sort'] ?? 'relevance');
        if ($queries === array()) {
            if ($sort === 'newest') {
                return $this->queryByOrder($args, 'date', 'DESC');
            }
            if ($sort === 'best_selling') {
                return $this->queryByOrder($args, 'meta_value_num', 'DESC', 'total_sales');
            }
            throw new SafeCommerceException(
                'catalog_discover_queries_required',
                ('حدد ما الذي تريد البحث عنه في المتجر.')
            );
        }
        $limit = $this->limit($args, 8, 12);
        $pool = min(72, max(24, $limit * 6));
        $priorityIds = array();
        $queryBuckets = array();
        $derivedCategorySlugs = array();

        $retrievalQueries = array();
        // Preserve every model-supplied semantic query before spending the
        // remaining bounded retrieval budget on orthographic variants.
        foreach ($queries as $queryText) {
            $queryText = trim(sanitize_text_field($queryText));
            if ($queryText !== '') {
                $retrievalQueries[$queryText] = true;
            }
        }
        foreach ($queries as $queryText) {
            foreach ($this->normalizer->searchVariants($queryText) as $variant) {
                $variant = trim(sanitize_text_field($variant));
                if ($variant !== '' && !isset($retrievalQueries[$variant])) {
                    $retrievalQueries[$variant] = true;
                    if (count($retrievalQueries) >= 8) {
                        break 2;
                    }
                }
            }
        }
        foreach (array_keys($retrievalQueries) as $queryText) {
            $skuId = (int) wc_get_product_id_by_sku($queryText);
            if ($skuId > 0) {
                $priorityIds[$skuId] = true;
            }
            $queryArgs = array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => $pool,
                'fields' => 'ids',
                'no_found_rows' => true,
                's' => $queryText,
                'orderby' => 'relevance',
                'order' => 'DESC',
            );
            $this->filters->apply($queryArgs, $args, 'search');
            $query = new WP_Query($queryArgs);
            $bucket = array();
            foreach (is_array($query->posts) ? $query->posts : array() as $id) {
                $id = (int) $id;
                if ($id > 0 && !isset($bucket[$id])) {
                    $bucket[$id] = true;
                }
            }
            if ($bucket !== array()) {
                $queryBuckets[] = array_keys($bucket);
            }

            $taxonomy = $this->taxonomyCandidates->find($queryText, $pool);
            foreach ($taxonomy['buckets'] as $taxonomyBucket) {
                $queryBuckets[] = $taxonomyBucket;
            }
            foreach ($taxonomy['category_slugs'] as $slug) {
                $derivedCategorySlugs[$slug] = true;
            }
        }

        $explicitSlugs = $this->filters->categorySlugs($args);

        $candidateLimit = min(120, max($pool, $pool * max(1, count($queries))));
        $candidateIds = $this->candidates->merge(array_keys($priorityIds), $queryBuckets, $candidateLimit);
        $ranked = array();
        $derivedSlugs = array_keys($derivedCategorySlugs);
        foreach ($this->snapshots($candidateIds, $candidateLimit, CatalogVisibilityPolicy::SEARCH) as $product) {
            if (!$this->categories->allows($product, $explicitSlugs)) {
                continue;
            }
            if (!empty($args['in_stock_only']) && empty($product['in_stock'])) {
                continue;
            }
            $matchedExplicit = $this->categories->matched($product, $explicitSlugs);
            $matchedDerived = $this->categories->matched($product, $derivedSlugs);
            $priceFilter = $this->prices->filterStatus($product, $args);
            if (!$priceFilter['matches']) {
                continue;
            }
            $match = $this->scorer->score($product, $queries);
            if ($matchedExplicit !== array()) {
                // Explicit categories are a hard boundary and a small tie-breaker,
                // never a substitute for semantic relevance to the query.
                $match['score'] = round((float) $match['score'] + 4.0, 3);
                $match['reasons'][] = 'category_filter';
            }
            if ($matchedDerived !== array()) {
                $match['score'] = round((float) $match['score'] + 24.0, 3);
                $match['semantic_score'] = round((float) $match['semantic_score'] + 24.0, 3);
                $match['reasons'][] = 'category_term_match';
            }
            $match['matched_category_slugs'] = array_values(array_unique(array_merge(
                $matchedExplicit,
                $matchedDerived
            )));
            if ($priceFilter['requires_variation']) {
                $match['reasons'][] = 'variation_price_confirmation_required';
                $match['price_filter_requires_variation'] = true;
            } else {
                $match['price_filter_requires_variation'] = false;
            }
            if ((float) $match['semantic_score'] <= 0.0) {
                continue;
            }
            $product['match'] = $match;
            $ranked[] = $product;
        }
        $this->sortDiscovery($ranked, (string) ($args['sort'] ?? 'relevance'));
        return array_slice($ranked, 0, $limit);
    }

    /** @return array{product:array<string,mixed>,variation:array<string,mixed>|null} */
    public function getBySku(string $sku): array
    {
        $sku = trim(sanitize_text_field($sku));
        if ($sku === '') {
            throw $this->skuNotFound();
        }
        $productId = (int) wc_get_product_id_by_sku($sku);
        if ($productId <= 0) {
            throw $this->skuNotFound();
        }

        $resolved = wc_get_product($productId);
        if ($resolved instanceof WC_Product_Variation) {
            $parentId = (int) $resolved->get_parent_id();
            return array(
                'product' => $this->get($parentId),
                'variation' => $this->getVariation($productId, $parentId),
            );
        }
        return array('product' => $this->get($productId), 'variation' => null);
    }

    /** @return array<string,mixed> */
    public function get(int $productId): array
    {
        $product = wc_get_product($productId);
        if (!$this->visibility->productIsVisible($product)) {
            throw new SafeCommerceException(
                'product_not_found',
                ('هذا المنتج لم يعد متاحاً.')
            );
        }
        /** @var WC_Product $product */
        try {
            return $this->products->create($product);
        } catch (ProductProjectionException $exception) {
            throw new SafeCommerceException(
                'product_display_invalid',
                ('تعذر عرض هذا المنتج بأمان حالياً.')
            );
        }
    }

    /** @return array<string,mixed> */
    public function getVariation(int $variationId, int $expectedParentId = 0): array
    {
        $variation = wc_get_product($variationId);
        if (!$variation instanceof WC_Product_Variation) {
            throw new SafeCommerceException(
                'variation_not_found',
                ('هذا الخيار لم يعد متاحاً.')
            );
        }
        $parentId = (int) $variation->get_parent_id();
        $parent = $parentId > 0 ? wc_get_product($parentId) : null;
        if (!$this->visibility->variationIsVisible($variation, $parent)) {
            throw new SafeCommerceException(
                'variation_not_found',
                ('هذا الخيار لم يعد متاحاً.')
            );
        }
        if (!$this->capabilities->concreteVariation($variation)) {
            throw new SafeCommerceException(
                'variation_option_incomplete',
                ('هذا الخيار يحتاج تحديد قيمة إضافية من صفحة المنتج.')
            );
        }
        if ($expectedParentId > 0 && $parentId !== $expectedParentId) {
            throw new SafeCommerceException(
                'variation_parent_mismatch',
                ('الخيار المحدد لا يخص هذا المنتج.')
            );
        }
        return $this->variations->create($variation);
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int,authority_epoch:string} */
    public function variationCatalog(int $productId): array
    {
        $product = wc_get_product($productId);
        if (
            !$this->visibility->productIsVisible($product)
            || !($product instanceof WC_Product) || !$product->is_type('variable')
        ) {
            return array(
                'items' => array(),'total' => 0,
                'authority_epoch' => hash('sha256', 'unavailable-variable-product'),
            );
        }
        $catalogReason = $this->variationCatalog->reason($product);
        if ($catalogReason !== VariableProductCatalogPolicy::SUPPORTED) {
            throw new SafeCommerceException(
                $catalogReason,
                $catalogReason === VariableProductCatalogPolicy::CATALOG_TOO_LARGE
                    ? 'خيارات هذا المنتج تتجاوز الحد الآمن للمحادثة. استخدم صفحة المنتج لاختيار الخيار.'
                    : 'تعذر تحميل خيارات هذا المنتج بأمان حالياً. استخدم صفحة المنتج لاختيار الخيار.'
            );
        }
        $projectedVisible = array();
        foreach ((array) $product->get_children() as $variationId) {
            $variation = wc_get_product((int) $variationId);
            if (
                $variation instanceof WC_Product_Variation
                && $this->visibility->variationIsVisible($variation, $product)
                && $this->capabilities->concreteVariation($variation)
            ) {
                $projectedVisible[] = $this->variations->create($variation);
            }
        }
        $total = count($projectedVisible);
        return array(
            'items' => $projectedVisible,'total' => $total,
            'authority_epoch' => $this->variationEpoch->create($product, $projectedVisible),
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function related(int $productId, int $limit = 6): array
    {
        $limit = max(1, min(12, $limit));
        $pool = min(48, max(12, $limit * 4));
        $ids = wc_get_related_products($productId, $pool);
        return $this->snapshots(
            is_array($ids) ? $ids : array(),
            $limit,
            CatalogVisibilityPolicy::CATALOG
        );
    }

    /** @param array<string,mixed> $args @return array<int,array<string,mixed>> */
    public function alternatives(int $productId, array $args): array
    {
        $source = $this->get($productId);
        $limit = $this->limit($args, 6, 12);
        $objective = (string) ($args['objective'] ?? 'similar');
        $pool = min(72, max(30, $limit * 6));
        $relatedIds = wc_get_related_products($productId, $pool);
        $related = array();
        foreach (is_array($relatedIds) ? $relatedIds : array() as $id) {
            $id = (int) $id;
            if ($id > 0 && $id !== $productId) {
                $related[$id] = true;
            }
        }
        $categorySlugs = array_values((array) ($source['category_slugs'] ?? array()));
        $sourceContext = $this->alternativeRanker->sourceContext($source);
        $relatedLookup = $related;
        $candidateLimit = min(120, $pool);

        // Eligibility is a live projected fact. Raw WooCommerce relationship
        // count cannot suppress category fallback because related rows may be
        // hidden, malformed, unpurchasable, out of stock, or outside the
        // requested price objective.
        $ranked = $this->alternativeRanker->rank(
            $this->snapshots(array_keys($related), $candidateLimit, CatalogVisibilityPolicy::CATALOG),
            $args,
            $objective,
            $sourceContext,
            $relatedLookup
        );

        if (count($ranked) < $limit && $categorySlugs !== array()) {
            $queryArgs = array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => $pool,
                'fields' => 'ids',
                'no_found_rows' => true,
                'orderby' => 'date',
                'order' => 'DESC',
            );
            $filterArgs = array('category_slugs' => $categorySlugs, 'in_stock_only' => $objective === 'in_stock');
            if (isset($args['max_price'])) {
                $filterArgs['max_price'] = $args['max_price'];
            }
            $this->filters->apply($queryArgs, $filterArgs, 'catalog');
            $query = new WP_Query($queryArgs);
            $fallback = array();
            foreach (is_array($query->posts) ? $query->posts : array() as $id) {
                $id = (int) $id;
                if ($id > 0 && $id !== $productId && !isset($related[$id])) {
                    $fallback[$id] = true;
                }
            }
            $ranked = array_merge($ranked, $this->alternativeRanker->rank(
                $this->snapshots(array_keys($fallback), $candidateLimit, CatalogVisibilityPolicy::CATALOG),
                $args,
                $objective,
                $sourceContext,
                $relatedLookup
            ));
        }

        usort($ranked, static function (array $left, array $right): int {
            return ((float) ($right['alternative_match']['score'] ?? 0)) <=> ((float) ($left['alternative_match']['score'] ?? 0));
        });
        return array_slice($ranked, 0, $limit);
    }

    /** @param array<string,mixed> $args @return array<int,array<string,mixed>> */
    public function categories(array $args): array
    {
        $termArgs = array(
            'taxonomy' => 'product_cat',
            'hide_empty' => true,
            'number' => $this->limit($args, 20, 50),
            'orderby' => 'count',
            'order' => 'DESC',
        );
        if (isset($args['parent_id'])) {
            $termArgs['parent'] = max(0, (int) $args['parent_id']);
        }
        if (!empty($args['query'])) {
            $termArgs['search'] = sanitize_text_field((string) $args['query']);
        }
        $terms = get_terms($termArgs);
        if (is_wp_error($terms) || !is_array($terms)) {
            return array();
        }
        $rows = array();
        foreach ($terms as $term) {
            $rows[] = array(
                'id' => (int) $term->term_id,
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
                'parent_id' => (int) $term->parent,
                'product_count' => (int) $term->count,
            );
        }
        return $rows;
    }

    /** @param array<string,mixed> $args @return array<int,array<string,mixed>> */
    private function queryByOrder(array $args, string $orderby, string $order, string $metaKey = ''): array
    {
        $limit = $this->limit($args, 6, 12);
        $batchSize = min(48, max(12, $limit * 4));
        $queryArgs = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $batchSize,
            'fields' => 'ids',
            'no_found_rows' => true,
            'orderby' => $orderby,
            'order' => $order,
        );
        if ($metaKey !== '') {
            $queryArgs['meta_key'] = $metaKey;
        }
        $this->filters->apply($queryArgs, $args, 'catalog');

        $rows = array();
        $seen = array();
        // Product-level visibility and projection can reject rows that SQL
        // cannot safely express. Scan a fixed number of bounded pages so a
        // hidden/malformed first page does not underfill an otherwise eligible
        // latest or best-seller result.
        for ($page = 1; $page <= 4 && count($rows) < $limit; ++$page) {
            $queryArgs['paged'] = $page;
            $query = new WP_Query($queryArgs);
            $ids = is_array($query->posts) ? $query->posts : array();
            // Project the whole bounded page before post-query price checks;
            // otherwise one rejected leading product can underfill the result
            // even when later ordered products satisfy the same live filters.
            foreach ($this->snapshots($ids, $batchSize, CatalogVisibilityPolicy::CATALOG) as $product) {
                $id = (int) ($product['id'] ?? 0);
                if ($id < 1 || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $priceFilter = $this->prices->filterStatus($product, $args);
                if (!$priceFilter['matches']) {
                    continue;
                }
                if (isset($args['min_price']) || isset($args['max_price'])) {
                    $requiresVariation = (bool) $priceFilter['requires_variation'];
                    $product['match'] = array(
                        'score' => 0.0,
                        'semantic_score' => 0.0,
                        'matched_terms' => array(),
                        'matched_category_slugs' => array(),
                        'reasons' => $requiresVariation
                            ? array('variation_price_confirmation_required')
                            : array(),
                        'price_filter_requires_variation' => $requiresVariation,
                    );
                }
                $rows[] = $product;
                if (count($rows) >= $limit) {
                    break;
                }
            }
            if (count($ids) < $batchSize) {
                break;
            }
        }
        return $rows;
    }

    /** @param array<int,mixed> $ids @return array<int,array<string,mixed>> */
    private function snapshots(
        array $ids,
        int $limit,
        string $visibilityContext = CatalogVisibilityPolicy::PUBLIC
    ): array {
        $rows = array();
        $seen = array();
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id < 1 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $product = wc_get_product($id);
            if (!$this->visibility->productIsVisible($product, $visibilityContext)) {
                continue;
            }
            /** @var WC_Product $product */
            try {
                $rows[] = $this->products->create($product);
            } catch (ProductProjectionException $exception) {
                // Malformed public display facts receive no model authority.
                continue;
            }
            if (count($rows) >= $limit) {
                break;
            }
        }
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function sortDiscovery(array &$rows, string $sort): void
    {
        usort($rows, function (array $left, array $right) use ($sort): int {
            if ($sort === 'price_asc' || $sort === 'price_desc') {
                $leftKnown = $this->prices->range($left)['known'];
                $rightKnown = $this->prices->range($right)['known'];
                if ($leftKnown !== $rightKnown) {
                    return $leftKnown ? -1 : 1;
                }
                $order = ((float) $this->prices->range($left)['min']) <=> ((float) $this->prices->range($right)['min']);
                return $sort === 'price_desc' ? -$order : $order;
            }
            if ($sort === 'newest') {
                return ((int) ($right['date_created'] ?? 0)) <=> ((int) ($left['date_created'] ?? 0));
            }
            if ($sort === 'best_selling') {
                return ((int) ($right['total_sales'] ?? 0)) <=> ((int) ($left['total_sales'] ?? 0));
            }
            $score = ((float) ($right['match']['score'] ?? 0)) <=> ((float) ($left['match']['score'] ?? 0));
            if ($score !== 0) {
                return $score;
            }
            $sales = ((int) ($right['total_sales'] ?? 0)) <=> ((int) ($left['total_sales'] ?? 0));
            return $sales !== 0
                ? $sales
                : (((int) ($right['date_created'] ?? 0)) <=> ((int) ($left['date_created'] ?? 0)));
        });
    }

    /** @param array<string,mixed> $args */
    private function limit(array $args, int $default, int $maximum): int
    {
        return max(1, min($maximum, (int) ($args['limit'] ?? $default)));
    }

    private function skuNotFound(): SafeCommerceException
    {
        return new SafeCommerceException(
            'sku_not_found',
            ('لم أجد منتجاً بهذا الرمز.')
        );
    }
}
