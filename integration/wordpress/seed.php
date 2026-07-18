<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    fwrite(STDERR, "WordPress must be loaded.\n");
    exit(1);
}
if (!class_exists('WooCommerce') || !class_exists('WC_Product_Simple')) {
    WP_CLI::error('WooCommerce is not active.');
}

$existing = get_posts(array(
    'post_type' => array('product', 'product_variation'),
    'post_status' => 'any',
    'numberposts' => -1,
    'fields' => 'ids',
    'meta_key' => '_ysai_integration_fixture',
    'meta_value' => '1',
));
foreach ($existing as $postId) {
    wp_delete_post((int) $postId, true);
}

$category = get_term_by('slug', 'integration-fixtures', 'product_cat');
if (!$category) {
    $created = wp_insert_term('Integration Fixtures', 'product_cat', array('slug' => 'integration-fixtures'));
    if (is_wp_error($created)) {
        WP_CLI::error($created->get_error_message());
    }
    $categoryId = (int) $created['term_id'];
} else {
    $categoryId = (int) $category->term_id;
}

$simple = new WC_Product_Simple();
$simple->set_name('Integration Coffee');
$simple->set_slug('integration-coffee');
$simple->set_status('publish');
$simple->set_catalog_visibility('visible');
$simple->set_regular_price('10.00');
$simple->set_price('10.00');
$simple->set_sku('INT-COFFEE');
$simple->set_manage_stock(true);
$simple->set_stock_quantity(50);
$simple->set_stock_status('instock');
$simple->set_total_sales(25);
$simple->set_description('A deterministic product used only by the integration harness.');
$simple->set_short_description('Integration coffee fixture.');
$simple->set_category_ids(array($categoryId));
$simple->set_date_created(time() - DAY_IN_SECONDS);
$simpleId = $simple->save();
update_post_meta($simpleId, '_ysai_integration_fixture', '1');

$variable = new WC_Product_Variable();
$variable->set_name('Integration Shirt');
$variable->set_slug('integration-shirt');
$variable->set_status('publish');
$variable->set_catalog_visibility('visible');
$variable->set_sku('INT-SHIRT');
$variable->set_total_sales(50);
$variable->set_category_ids(array($categoryId));
$variable->set_date_created(time());
$attribute = new WC_Product_Attribute();
$attribute->set_id(0);
$attribute->set_name('Size');
$attribute->set_options(array('Small', 'Large'));
$attribute->set_position(0);
$attribute->set_visible(true);
$attribute->set_variation(true);
$variable->set_attributes(array($attribute));
$variableId = $variable->save();
update_post_meta($variableId, '_ysai_integration_fixture', '1');

$variationIds = array();
foreach (array('Small' => '15.00', 'Large' => '17.00') as $size => $price) {
    $variation = new WC_Product_Variation();
    $variation->set_parent_id($variableId);
    $variation->set_status('publish');
    $variation->set_attributes(array('size' => sanitize_title($size)));
    $variation->set_regular_price($price);
    $variation->set_price($price);
    $variation->set_manage_stock(true);
    $variation->set_stock_quantity(20);
    $variation->set_stock_status('instock');
    $variationId = $variation->save();
    update_post_meta($variationId, '_ysai_integration_fixture', '1');
    $variationIds[strtolower($size)] = $variationId;
}
WC_Product_Variable::sync($variableId);
wc_delete_product_transients($variableId);
wc_delete_product_transients($simpleId);

$fixtures = array(
    'simple' => $simpleId,
    'variable' => $variableId,
    'variations' => $variationIds,
);
update_option('ysai_integration_products', $fixtures, false);
WP_CLI::success('Seeded YSAI integration products: ' . wp_json_encode($fixtures));
