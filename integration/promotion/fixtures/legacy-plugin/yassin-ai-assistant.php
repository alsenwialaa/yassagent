<?php
/**
 * Plugin Name: Yassin AI Sales Assistant
 * Description: Internal pre-publication schema fixture used only by the promotion gate.
 * Version: 0.9.0-internal
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

register_activation_hook(__FILE__, static function (): void {
    global $wpdb;

    $table = $wpdb->prefix . 'ysai_conversations';
    $collate = $wpdb->get_charset_collate();
    $created = $wpdb->query(
        "CREATE TABLE IF NOT EXISTS `{$table}` ("
        . "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,"
        . "public_id char(36) NOT NULL,"
        . "state_json longtext NOT NULL,"
        . "created_at datetime NOT NULL,"
        . "PRIMARY KEY (id), UNIQUE KEY public_id (public_id)"
        . ") {$collate}"
    );
    if ($created === false || trim((string) $wpdb->last_error) !== '') {
        throw new RuntimeException('Unable to create the legacy promotion fixture table.');
    }

    $legacyState = wp_json_encode(array(
        'schema' => 3,
        'continuity' => array(),
        'shopping' => array(),
        'last_outcome' => 'follow_up',
        'pending_cart_intent' => array(
            'id' => 'legacy-pending-continuation',
            'question' => 'سؤال قديم يجب ألا ينجو من الترقية؟',
        ),
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $wpdb->insert($table, array(
        'public_id' => '00000000-0000-4000-8000-000000000001',
        'state_json' => $legacyState,
        'created_at' => gmdate('Y-m-d H:i:s'),
    ), array('%s', '%s', '%s'));

    update_option('ysai_schema_version', '20260701.1', false);
    update_option('ysai_schema_status', array(
        'state' => 'ready',
        'version' => '20260701.1',
        'reason' => 'legacy_fixture',
    ), false);
    update_option('ysai_options', array(
        'enabled' => 1,
        'welcome_message' => 'هذا سؤال خادوم قديم؟',
        'delete_data_on_uninstall' => 0,
    ), false);
    update_option('ysai_promotion_legacy_fixture', array(
        'version' => '0.9.0-internal',
        'pending_question' => 'سؤال قديم يجب ألا ينجو من الترقية؟',
    ), false);
});
