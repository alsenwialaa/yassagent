<?php

/**
 * Plugin Name: Yassin Store AI Sales Agent
 * Plugin URI: https://yassin-store.com/
 * Description: AI-led WooCommerce sales assistant with server-validated tools, verified cart actions, and a secure storefront chat widget.
 * Version: 1.0.0
 * Author: Yassin Store
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 10.9.4
 * WC tested up to: 10.9.4
 * Text Domain: yassin-ai-assistant
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('YSAI_VERSION', '1.0.0');
define('YSAI_PLUGIN_FILE', __FILE__);
define('YSAI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('YSAI_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once YSAI_PLUGIN_DIR . 'src/Autoload.php';

\YassinStore\AiAssistant\Autoload::register();

add_action(
    'before_woocommerce_init',
    static function (): void {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
);

register_activation_hook(
    __FILE__,
    array(\YassinStore\AiAssistant\Lifecycle\Activator::class, 'activate')
);
register_deactivation_hook(
    __FILE__,
    array(\YassinStore\AiAssistant\Lifecycle\Deactivator::class, 'deactivate')
);

add_action(
    'plugins_loaded',
    static function (): void {
        \YassinStore\AiAssistant\Plugin::instance()->boot();
    },
    20
);
