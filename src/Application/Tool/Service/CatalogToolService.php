<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool\Service;

use YassinStore\AiAssistant\Application\Agent\AgentContext;
use YassinStore\AiAssistant\Application\Port\ProductCatalogPort;
use YassinStore\AiAssistant\Application\Port\TextLocalizerPort;
use YassinStore\AiAssistant\Application\Tool\ProductComparisonBuilder;
use YassinStore\AiAssistant\Application\Tool\ProductRecommendationRanker;
use YassinStore\AiAssistant\Application\Tool\ToolExecutionResult;
use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Domain\Exception\SafeCommerceException;
use YassinStore\AiAssistant\Domain\Commerce\VariableProductLimits;
use YassinStore\AiAssistant\Application\Commerce\VariationResolver;
use YassinStore\AiAssistant\Domain\Shopping\CatalogTextNormalizer;

/** Grounded read-only catalog intelligence shared by single-purpose handlers. */
final class CatalogToolService
{
    /** @var ProductCatalogPort */ private $catalog;
    /** @var TextLocalizerPort */ private $text;
    /** @var ProductComparisonBuilder */ private $comparisons;
    /** @var ProductRecommendationRanker */ private $recommendations;
    /** @var VariationResolver */ private $variationResolver;

    public function __construct(
        ProductCatalogPort $catalog,
        TextLocalizerPort $text,
        ?ProductComparisonBuilder $comparisons = null,
        ?ProductRecommendationRanker $recommendations = null,
        ?VariationResolver $variationResolver = null
    ) {
        $this->catalog = $catalog;
        $this->text = $text;
        $this->comparisons = $comparisons !== null ? $comparisons : new ProductComparisonBuilder();
        $this->recommendations = $recommendations !== null ? $recommendations : new ProductRecommendationRanker();
        $this->variationResolver = $variationResolver !== null
            ? $variationResolver : new VariationResolver(new CatalogTextNormalizer());
    }

    /** @param array<string,mixed> $arguments */
    public function discover(array $arguments, AgentContext $context): ToolExecutionResult
    {
        $this->assertPriceRange($arguments);
        $queries = isset($arguments['queries']) && is_array($arguments['queries'])
            ? $arguments['queries'] : array();
        $sort = (string) ($arguments['sort'] ?? 'relevance');
        if ($queries === array() && !in_array($sort, array('newest', 'best_selling'), true)) {
            throw new ContractViolation(
                'catalog_discover_queries_required',
                'catalog_discover requires queries unless sort is newest or best_selling.'
            );
        }
        return $this->productsSafely(function () use ($arguments): array {
            return $this->catalog->discover($arguments);
        }, $context);
    }

    /** @param array<string,mixed> $arguments */
    public function getBySku(array $arguments, AgentContext $context): ToolExecutionResult
    {
        try {
            $resolved = $this->catalog->getBySku((string) $arguments['sku']);
            $product = $resolved['product'];
            $product['product_ref'] = $context->authority()->recordProduct($product);
            $data = array('product' => $this->compactProduct($product));
            if (is_array($resolved['variation'])) {
                $variation = $resolved['variation'];
                $variation['variation_ref'] = $context->authority()->recordVariation($variation);
                $data['variation'] = $this->compactVariation($variation);
            }
            return ToolExecutionResult::success($data);
        } catch (SafeCommerceException $exception) {
            return $this->safeFailure($exception);
        }
    }

    /** @param array<string,mixed> $arguments */
    public function details(array $arguments, AgentContext $context): ToolExecutionResult
    {
        try {
            $productRef = (string) $arguments['product_ref'];
            $product = $this->revalidateProduct($productRef, $context);
            return ToolExecutionResult::success(array('product' => $this->detailedProduct($product)));
        } catch (SafeCommerceException $exception) {
            return $this->safeFailure($exception);
        }
    }

    /** @param array<string,mixed> $arguments */
    public function compare(array $arguments, AgentContext $context): ToolExecutionResult
    {
        try {
            $products = array();
            foreach ((array) $arguments['product_refs'] as $productRef) {
                $products[] = $this->revalidateProduct((string) $productRef, $context);
            }
            return ToolExecutionResult::success(array(
                'comparison' => $this->comparisons->build($products),
            ));
        } catch (SafeCommerceException $exception) {
            return $this->safeFailure($exception);
        }
    }

    /** @param array<string,mixed> $arguments */
    public function rankCandidates(array $arguments, AgentContext $context): ToolExecutionResult
    {
        try {
            $this->assertPriceRange($arguments);
            $products = array();
            foreach ((array) $arguments['product_refs'] as $productRef) {
                $products[] = $this->revalidateProduct((string) $productRef, $context);
            }
            return ToolExecutionResult::success(array(
                'recommendation' => $this->recommendations->rank($products, $arguments),
            ));
        } catch (SafeCommerceException $exception) {
            return $this->safeFailure($exception);
        }
    }

    /** @param array<string,mixed> $arguments */
    public function alternatives(array $arguments, AgentContext $context): ToolExecutionResult
    {
        try {
            $this->assertPriceRange($arguments);
            $sourceRef = (string) $arguments['product_ref'];
            $source = $this->revalidateProduct($sourceRef, $context);
            $alternatives = $this->productsResult(
                $this->catalog->alternatives((int) $source['id'], $arguments),
                $context
            );
            return ToolExecutionResult::success(array_merge(
                array('source_product_ref' => $sourceRef, 'objective' => (string) ($arguments['objective'] ?? 'similar')),
                $alternatives->data()
            ));
        } catch (SafeCommerceException $exception) {
            return $this->safeFailure($exception);
        }
    }

    /** @param array<string,mixed> $arguments */
    public function resolveVariation(array $arguments, AgentContext $context): ToolExecutionResult
    {
        try {
            $productRef = (string) $arguments['product_ref'];
            $product = $this->revalidateProduct($productRef, $context);
            if (empty($product['requires_variation'])) {
                return ToolExecutionResult::failure(
                    'product_not_variable',
                    $this->text->text('هذا المنتج لا يحتوي على خيارات.')
                );
            }

            $catalog = $this->catalog->variationCatalog((int) $product['id']);
            if ($catalog['items'] === array()) {
                return ToolExecutionResult::failure(
                    'variation_options_unavailable',
                    $this->text->text('لا توجد خيارات متاحة لهذا المنتج حالياً.')
                );
            }
            $context->authority()->recordVariationCatalog(
                (int) $product['id'],
                $catalog['items'],
                (string) $catalog['authority_epoch']
            );
            $resolution = $this->variationResolver->resolve(
                $catalog['items'],
                (array) ($arguments['attributes'] ?? array())
            );
            $rows = array();
            foreach ($resolution['matches'] as $variation) {
                $variation['variation_ref'] = $context->authority()->recordVariation($variation);
                $rows[] = $this->compactVariation($variation);
            }
            unset($resolution['matches']);
            return ToolExecutionResult::success(array(
                'product_ref' => $productRef,
                'catalog_total' => (int) $catalog['total'],
                'catalog_complete' => true,
                'resolution' => array_merge($resolution, array('matches' => $rows)),
            ));
        } catch (SafeCommerceException $exception) {
            return $this->safeFailure($exception);
        }
    }

    /** @param array<string,mixed> $arguments */
    public function related(array $arguments, AgentContext $context): ToolExecutionResult
    {
        try {
            $productRef = (string) $arguments['product_ref'];
            $product = $this->revalidateProduct($productRef, $context);
            $related = $this->productsResult(
                $this->catalog->related((int) $product['id'], (int) ($arguments['limit'] ?? 6)),
                $context
            );
            return ToolExecutionResult::success(array_merge(
                array('source_product_ref' => $productRef),
                $related->data()
            ));
        } catch (SafeCommerceException $exception) {
            return $this->safeFailure($exception);
        }
    }

    /** @param array<string,mixed> $arguments */
    public function categories(array $arguments): ToolExecutionResult
    {
        try {
            return ToolExecutionResult::success(array('categories' => $this->catalog->categories($arguments)));
        } catch (SafeCommerceException $exception) {
            return $this->safeFailure($exception);
        }
    }

    /** @param callable():array<int,array<string,mixed>> $query */
    private function productsSafely(callable $query, AgentContext $context): ToolExecutionResult
    {
        try {
            return $this->productsResult($query(), $context);
        } catch (SafeCommerceException $exception) {
            return $this->safeFailure($exception);
        }
    }

    /** @return array<string,mixed> */
    private function revalidateProduct(string $productRef, AgentContext $context): array
    {
        $authoritative = $context->authority()->requireProduct($productRef);
        $product = $this->catalog->get((int) $authoritative['id']);
        $stableRef = $context->authority()->recordProduct($product);
        if (!hash_equals($productRef, $stableRef)) {
            throw new ContractViolation('product_authority_refresh_mismatch', 'Live product revalidation changed its opaque reference.');
        }
        $product['product_ref'] = $productRef;
        return $product;
    }

    /** @param array<int,array<string,mixed>> $products */
    private function productsResult(array $products, AgentContext $context): ToolExecutionResult
    {
        $rows = array();
        foreach ($products as $product) {
            $product['product_ref'] = $context->authority()->recordProduct($product);
            $rows[] = $this->compactProduct($product);
        }
        return ToolExecutionResult::success(array('products' => $rows, 'count' => count($rows)));
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    private function compactProduct(array $product): array
    {
        $row = array(
            'product_ref' => (string) $product['product_ref'],
            'name' => (string) $product['name'],
            'type' => (string) $product['type'],
            'sku' => (string) $product['sku'],
            'price' => (string) $product['price'],
            'price_min' => (string) ($product['price_min'] ?? $product['price']),
            'price_max' => (string) ($product['price_max'] ?? $product['price']),
            'price_is_range' => (bool) ($product['price_is_range'] ?? false),
            'price_status' => (string) ($product['price_status'] ?? 'unknown'),
            'price_basis' => (string) ($product['price_basis'] ?? 'unknown'),
            'formatted_price' => (string) $product['formatted_price'],
            'currency' => (string) $product['currency'],
            'on_sale' => (bool) ($product['on_sale'] ?? false),
            'in_stock' => (bool) $product['in_stock'],
            'purchasable' => (bool) $product['purchasable'],
            'cart_supported' => (bool) ($product['cart_supported'] ?? false),
            'cart_support_reason' => (string) ($product['cart_support_reason'] ?? 'unsupported_product_type'),
            'variation_catalog_supported' => (bool) ($product['variation_catalog_supported'] ?? false),
            'variation_catalog_reason' => (string) ($product['variation_catalog_reason'] ?? 'variation_catalog_invalid'),
            'requires_variation' => (bool) $product['requires_variation'],
            'short_description' => (string) $product['short_description'],
            'categories' => array_values((array) $product['categories']),
            'attributes' => array_slice(
                array_values((array) ($product['attributes'] ?? array())),
                0,
                VariableProductLimits::MAX_AXES
            ),
            'average_rating' => (string) ($product['average_rating'] ?? ''),
            'review_count' => (int) ($product['review_count'] ?? 0),
        );
        if (isset($product['match']) && is_array($product['match'])) {
            $row['match'] = $product['match'];
        }
        if (isset($product['alternative_match']) && is_array($product['alternative_match'])) {
            $row['alternative_match'] = $product['alternative_match'];
        }
        return $row;
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    private function detailedProduct(array $product): array
    {
        return array_merge($this->compactProduct($product), array(
            'stock_status' => (string) ($product['stock_status'] ?? ''),
            'sold_individually' => (bool) ($product['sold_individually'] ?? false),
            'weight' => (string) ($product['weight'] ?? ''),
            'dimensions' => (string) ($product['dimensions'] ?? ''),
            'description' => (string) ($product['description'] ?? ''),
            'tags' => array_values((array) ($product['tags'] ?? array())),
            'rating_count' => (int) ($product['rating_count'] ?? 0),
            'total_sales' => (int) ($product['total_sales'] ?? 0),
        ));
    }

    /** @param array<string,mixed> $variation @return array<string,mixed> */
    private function compactVariation(array $variation): array
    {
        return array(
            'variation_ref' => (string) $variation['variation_ref'],
            'name' => (string) $variation['name'],
            'sku' => (string) $variation['sku'],
            'price' => (string) ($variation['price'] ?? ''),
            'price_min' => (string) ($variation['price_min'] ?? ''),
            'price_max' => (string) ($variation['price_max'] ?? ''),
            'price_is_range' => (bool) ($variation['price_is_range'] ?? false),
            'price_status' => (string) ($variation['price_status'] ?? 'unknown'),
            'price_basis' => (string) ($variation['price_basis'] ?? 'unknown'),
            'formatted_price' => (string) $variation['formatted_price'],
            'currency' => (string) ($variation['currency'] ?? ''),
            'attributes' => (array) $variation['attributes'],
            'in_stock' => (bool) $variation['in_stock'],
            'purchasable' => (bool) $variation['purchasable'],
            'image' => (string) $variation['image'],
        );
    }

    /** @param array<string,mixed> $arguments */
    private function assertPriceRange(array $arguments): void
    {
        if (
            isset($arguments['min_price'], $arguments['max_price'])
            && (float) $arguments['min_price'] > (float) $arguments['max_price']
        ) {
            throw new ContractViolation('catalog_price_range_invalid', 'min_price must not exceed max_price.');
        }
    }

    private function safeFailure(SafeCommerceException $exception): ToolExecutionResult
    {
        return ToolExecutionResult::failure($exception->reasonCode(), $exception->safeMessage());
    }
}
