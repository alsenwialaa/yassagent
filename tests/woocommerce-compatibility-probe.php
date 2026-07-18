<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\StoreApi\Utilities {
    final class CartTokenUtils
    {
        public static function validate_cart_token($token): bool { return is_string($token); }
        public static function get_cart_token_payload($token): array { return array('user_id' => (string) $token); }
    }
}

namespace {
    $mode = isset($argv[1]) ? (string) $argv[1] : 'success';
    $version = in_array($mode, array('future', 'future_drift'), true) ? '10.9.9' : '10.9.4';
    define('WC_VERSION', $version);
    define('WC_SESSION_CACHE_GROUP', 'woocommerce_sessions');

    function wp_verify_fast_hash($message, $hash): bool { return is_string($message) && is_string($hash); }
    function wc_clean($value) { return $value; }
    function wp_unslash($value) { return $value; }
    function wp_cache_get($key, $group = '') { return false; }
    function wp_cache_delete($key, $group = '') { return true; }
    function maybe_unserialize($value) { return $value; }

    class WooCommerce {}

    abstract class WC_Session
    {
        protected $_data = array();
        protected $_dirty = false;
    }

    class WC_Session_Handler extends WC_Session
    {
        protected $_session_expiration = 4102444800;
        protected $_table = 'wp_woocommerce_sessions';
        public function get($key, $default = null) { return $default; }
        public function set($key, $value): void {}
        public function get_customer_id() { return 'probe'; }
        public function save_data($oldSessionKey = 0): void {}
        public function get_session($customerId, $default = array()) { return $default; }
        public function delete_session($customerId): void {}
        public function set_customer_session_cookie($set): void {}
    }

    if ($mode === 'drift' || $mode === 'future_drift') {
        class WC_Cart
        {
            private $session;
            public function calculate_totals(): void {}
        }
    } else {
        class WC_Cart
        {
            protected $session;
            public function calculate_totals(): void {}
        }
    }

    class WC_Cart_Session
    {
        public function get_cart_from_session(): void {}
        public function get_cart_for_session(): array { return array(); }
        public function maybe_set_cart_cookies(): void {}
        public function persistent_cart_update(): void {}
    }

    if ($mode === 'arity_drift') {
        class WC_Cache_Helper
        {
            public static function get_cache_prefix($group, $unexpected): string
            {
                return 'probe_' . (string) $group . '_' . (string) $unexpected . '_';
            }
        }
    } else {
        class WC_Cache_Helper
        {
            public static function get_cache_prefix($group): string
            {
                return 'probe_' . (string) $group . '_';
            }
        }
    }

    require_once dirname(__DIR__) . '/src/Autoload.php';
    \YassinStore\AiAssistant\Autoload::register();

    $contract = array(
        'schema_version' => 1,
        'minimum' => '10.9.4',
        'maximum_exclusive' => '11.0.0',
        'tested_up_to' => '10.9.4',
        'promotion_tested' => array('10.9.4'),
        'wordpress_minimum' => '6.9',
        'runtime_contract' => 'woocommerce-10.9-core-session-v1',
    );
    $compatibility = \YassinStore\AiAssistant\Infrastructure\WooCommerce\WooCommerceCompatibility::fromArray($contract);
    $adapter = new \YassinStore\AiAssistant\Infrastructure\WooCommerce\WooSessionInternalsAdapter($compatibility);

    try {
        $adapter->assertStaticCoreCapabilities();
        if (in_array($mode, array('drift', 'arity_drift', 'future_drift'), true)) {
            fwrite(STDERR, $mode . "_not_rejected\n");
            exit(1);
        }
        if ($mode === 'future') {
            if ($compatibility->statusForInstalledVersion()
                !== \YassinStore\AiAssistant\Infrastructure\WooCommerce\WooCommerceCompatibility::ADMITTED_UNPROMOTED
                || $adapter->allowsVerifiedCartMutation()
            ) {
                fwrite(STDERR, "future_status_invalid\n");
                exit(1);
            }
        } elseif (!$compatibility->isInstalledVersionPromotionTested()
            || !$adapter->allowsVerifiedCartMutation()
        ) {
            fwrite(STDERR, "tested_status_invalid\n");
            exit(1);
        }
        echo 'status=ok mode=' . $mode . ' version=' . WC_VERSION . "\n";
        exit(0);
    } catch (\Throwable $exception) {
        if (in_array($mode, array('drift', 'arity_drift', 'future_drift'), true)) {
            echo 'status=rejected mode=' . $mode . ' reason=' . get_class($exception) . "\n";
            exit(0);
        }
        fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
        exit(1);
    }
}
