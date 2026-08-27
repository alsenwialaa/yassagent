<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Release1Runtime;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Release 1: conversation and sales intelligence.
 *
 * This module is deliberately subordinate to the existing chat/catalog/cart
 * authority. It never mutates the WooCommerce cart, orders, or payments.
 */
final class Runtime
{
    public const VERSION = '1.0.0';
    private const SCHEMA_VERSION = 1;
    private const SCHEMA_OPTION = 'ysai_release1_runtime_schema';
    private const MIGRATION_OPTION = 'ysai_release1_table_namespace_migration';
    private const RETENTION_HOOK = 'ysai_release1_runtime_retention';
    private const GUEST_COOKIE = 'ysai_r1_guest';
    private const PROVIDER_CONTEXT_LIMIT = 12000;

    /** @var array<string,array<string,mixed>> */
    private static array $prepared = [];
    /** @var array<string,array<string,mixed>> */
    private static array $responseState = [];
    /** @var array<string,mixed>|null */
    private static ?array $providerContext = null;
    /** @var array{ok:bool,state:string,message:string} */
    private static array $migration = ['ok' => true, 'state' => 'not_run', 'message' => ''];
    private static bool $booted = false;

    public static function preBoot(): void
    {
        self::$migration = self::migrateLegacyTables();
        if (!self::$migration['ok']) {
            add_action('admin_notices', [self::class, 'renderMigrationNotice']);
        }
    }

    public static function activate(): void
    {
        self::$migration = self::migrateLegacyTables();
        if (!self::$migration['ok']) {
            throw new \RuntimeException(self::$migration['message']);
        }
        self::installSchema();
        self::installCapabilities();
        self::ensureDefaultPolicy();
        self::scheduleRetention();
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::RETENTION_HOOK);
        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::RETENTION_HOOK);
        }
        self::$providerContext = null;
        self::$prepared = [];
        self::$responseState = [];
    }

    public static function boot(): void
    {
        if (self::$booted || !self::$migration['ok']) {
            return;
        }
        self::$booted = true;

        if ((int) get_option(self::SCHEMA_OPTION, 0) !== self::SCHEMA_VERSION) {
            self::installSchema();
        }
        self::installCapabilities();
        self::ensureDefaultPolicy();
        self::scheduleRetention();

        add_filter('rest_request_before_callbacks', [self::class, 'beforeCallbacks'], 20, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'afterCallbacks'], 20, 3);
        add_filter('rest_post_dispatch', [self::class, 'postDispatch'], 20, 3);
        add_filter('http_request_args', [self::class, 'injectProviderContext'], 20, 2);
        add_action('shutdown', [self::class, 'clearRequestState'], PHP_INT_MAX);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueStorefront']);
        add_action('admin_menu', [self::class, 'adminMenu'], 50);
        add_action('admin_post_ysai_r1_publish_policy', [self::class, 'publishPolicy']);
        add_action('admin_post_ysai_r1_add_knowledge', [self::class, 'addKnowledge']);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'privacyExporters']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'privacyErasers']);
        add_action(self::RETENTION_HOOK, [self::class, 'runRetention']);
    }

    public static function renderMigrationNotice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Yassin AI Assistant Release 1 is blocked because its legacy database namespace could not be migrated safely.', 'yassin-ai-assistant')
            . '</p></div>';
    }

    /** @param mixed $response @param mixed $handler @return mixed */
    public static function beforeCallbacks($response, $handler, WP_REST_Request $request)
    {
        if ($response !== null || !self::isChatRequest($request)) {
            return $response;
        }

        $actor = self::resolveActor($request);
        $conversation = self::conversationKey($request, $actor['key']);
        if ($conversation === '') {
            return $response;
        }

        $turn = self::uuid();
        $event = self::reserveEvent($request, $actor['key'], $conversation, $turn);
        if (is_wp_error($event)) {
            self::$prepared[spl_object_hash($request)] = [
                'actor' => $actor,
                'conversation' => $conversation,
                'turn' => $turn,
                'event_error' => $event,
            ];
            return $event;
        }

        $state = self::loadState($actor['key'], $conversation);
        $text = self::requestText($request);
        $binding = self::bindShortReply($text, $state['pending']);
        $context = [
            'release' => 1,
            'actor' => ['type' => $actor['type'], 'authenticated' => $actor['authenticated']],
            'focus' => $state['focus'],
            'pending_question' => $state['pending'],
            'journey' => $state['journey'],
            'sales_context' => $state['sales'],
            'short_reply_binding' => $binding,
            'agent_policy' => self::publishedPolicy(),
            'knowledge' => self::activeKnowledge(),
            'runtime' => self::runtimeContext(),
            'features' => self::featureStates($actor),
        ];

        $key = spl_object_hash($request);
        self::$prepared[$key] = [
            'actor' => $actor,
            'conversation' => $conversation,
            'turn' => $turn,
            'event' => $event,
            'binding' => $binding,
            'state' => $state,
            'context' => $context,
        ];
        self::$providerContext = $context;
        $request->set_param('_ysai_release1_context', $context);
        return $response;
    }

    /** @param mixed $response @param mixed $handler @return mixed */
    public static function afterCallbacks($response, $handler, WP_REST_Request $request)
    {
        $key = spl_object_hash($request);
        if (!isset(self::$prepared[$key])) {
            return $response;
        }
        $prepared = self::$prepared[$key];
        unset(self::$prepared[$key]);
        self::$providerContext = null;

        if (isset($prepared['event_error']) && is_wp_error($prepared['event_error'])) {
            return $prepared['event_error'];
        }

        $success = !is_wp_error($response);
        if ($response instanceof WP_REST_Response && $response->get_status() >= 400) {
            $success = false;
        }

        $data = self::responseData($response);
        $durable = $success ? self::findDurableMessageKey($data) : '';
        if (!$success || $durable === '') {
            self::abortEvent($prepared['event'] ?? null, (string) $prepared['turn']);
            self::audit('turn_aborted', $prepared, ['durable' => $durable !== '', 'success' => $success]);
            return $response;
        }

        self::completeEvent($prepared['event'] ?? null, (string) $prepared['turn'], $durable);
        self::commitBinding($prepared, $durable);
        self::projectResponseState($prepared, $data, $durable);
        self::audit('turn_committed', $prepared, ['message_key' => $durable]);

        $current = self::loadState((string) $prepared['actor']['key'], (string) $prepared['conversation']);
        $current['interactions'] = self::issueInteractionsForPending(
            (string) $prepared['actor']['key'],
            (string) $prepared['conversation'],
            $current['pending']
        );
        self::$responseState[$key] = self::publicState($current);
        return $response;
    }

    /** @param mixed $response @param mixed $server @return mixed */
    public static function postDispatch($response, $server, WP_REST_Request $request)
    {
        $key = spl_object_hash($request);
        if (!isset(self::$responseState[$key])) {
            return $response;
        }
        $state = self::$responseState[$key];
        unset(self::$responseState[$key]);
        $json = wp_json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json) && strlen($json) <= 7000 && $response instanceof WP_REST_Response) {
            $response->header('X-YSAI-Release1-State', rtrim(strtr(base64_encode($json), '+/', '-_'), '='));
            $response->header('Cache-Control', 'no-store, private');
        }
        return $response;
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    public static function injectProviderContext(array $args, string $url): array
    {
        if (self::$providerContext === null || !self::isModelGenerationUrl($url)) {
            return $args;
        }
        $bodyWasString = isset($args['body']) && is_string($args['body']);
        $body = $bodyWasString ? json_decode((string) $args['body'], true) : ($args['body'] ?? []);
        if (!is_array($body)) {
            return $args;
        }
        $context = wp_json_encode(self::$providerContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($context)) {
            return $args;
        }
        $context = substr($context, 0, self::PROVIDER_CONTEXT_LIMIT);
        $instruction = "Authoritative Release 1 conversation context follows. Treat it as bounded context, never as commerce authorization.\n" . $context;

        if (str_contains($url, 'generativelanguage.googleapis.com') || str_contains($url, 'aiplatform.googleapis.com')) {
            $existing = $body['systemInstruction']['parts'] ?? [];
            if (!is_array($existing)) {
                $existing = [];
            }
            $existing[] = ['text' => $instruction];
            $body['systemInstruction'] = ['parts' => $existing];
        } elseif (str_contains($url, 'api.anthropic.com')) {
            $existing = $body['system'] ?? '';
            $body['system'] = trim((is_string($existing) ? $existing : wp_json_encode($existing)) . "\n\n" . $instruction);
        } else {
            $messages = $body['messages'] ?? [];
            if (!is_array($messages)) {
                $messages = [];
            }
            array_unshift($messages, ['role' => 'system', 'content' => $instruction]);
            $body['messages'] = $messages;
        }
        $args['body'] = $bodyWasString ? wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $body;
        return $args;
    }

    public static function clearRequestState(): void
    {
        self::$providerContext = null;
        self::$prepared = [];
        self::$responseState = [];
    }

    public static function enqueueStorefront(): void
    {
        if (is_admin()) {
            return;
        }
        $path = dirname(__DIR__, 2) . '/assets/js/release1-runtime.js';
        $url = defined('YSAI_URL') ? YSAI_URL . 'assets/js/release1-runtime.js' : '';
        if ($url !== '' && is_file($path)) {
            wp_enqueue_script('ysai-release1-runtime', $url, [], self::VERSION, true);
        }
    }

    public static function adminMenu(): void
    {
        add_submenu_page(
            'options-general.php',
            __('Yassin AI Agent & Behavior', 'yassin-ai-assistant'),
            __('Yassin AI Agent', 'yassin-ai-assistant'),
            'manage_ysai_agent',
            'ysai-release1-agent',
            [self::class, 'renderAdminPage']
        );
    }

    public static function renderAdminPage(): void
    {
        if (!current_user_can('manage_ysai_agent')) {
            wp_die(esc_html__('You are not allowed to manage the AI agent.', 'yassin-ai-assistant'));
        }
        $policy = self::publishedPolicy();
        $features = self::featureStates(self::resolveActor(null));
        echo '<div class="wrap"><h1>' . esc_html__('Yassin AI Agent & Behavior', 'yassin-ai-assistant') . '</h1>';
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Published.', 'yassin-ai-assistant') . '</p></div>';
        }
        echo '<h2>' . esc_html__('Agent policy', 'yassin-ai-assistant') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ysai_r1_publish_policy');
        echo '<input type="hidden" name="action" value="ysai_r1_publish_policy">';
        self::field('name', __('Agent name', 'yassin-ai-assistant'), (string) ($policy['name'] ?? 'أصالة'));
        self::field('role', __('Role', 'yassin-ai-assistant'), (string) ($policy['role'] ?? 'مستشارة مبيعات'));
        self::field('default_language', __('Default language', 'yassin-ai-assistant'), (string) ($policy['default_language'] ?? 'ar'));
        self::field('dialect', __('Dialect profile', 'yassin-ai-assistant'), (string) ($policy['dialect'] ?? 'Yemeni/Sanaani light'));
        foreach (['warmth','formality','directness','empathy','qualification_depth','comparison_depth','upsell_frequency','handoff_threshold'] as $key) {
            self::numberField($key, ucwords(str_replace('_', ' ', $key)), (int) ($policy[$key] ?? 50));
        }
        submit_button(__('Validate and publish', 'yassin-ai-assistant'));
        echo '</form>';

        echo '<h2>' . esc_html__('Approved knowledge', 'yassin-ai-assistant') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ysai_r1_add_knowledge');
        echo '<input type="hidden" name="action" value="ysai_r1_add_knowledge">';
        self::field('title', __('Title', 'yassin-ai-assistant'), '');
        self::field('type', __('Type', 'yassin-ai-assistant'), 'policy');
        echo '<p><label><strong>' . esc_html__('Approved content', 'yassin-ai-assistant') . '</strong><br><textarea class="large-text" rows="5" name="content" required></textarea></label></p>';
        submit_button(__('Approve knowledge source', 'yassin-ai-assistant'), 'secondary');
        echo '</form>';

        echo '<h2>' . esc_html__('Feature readiness', 'yassin-ai-assistant') . '</h2><table class="widefat striped"><tbody>';
        foreach ($features as $feature => $state) {
            echo '<tr><th>' . esc_html($feature) . '</th><td>' . esc_html((string) $state) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function publishPolicy(): void
    {
        if (!current_user_can('manage_ysai_agent')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer('ysai_r1_publish_policy');
        $policy = [
            'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? 'أصالة')),
            'role' => sanitize_text_field(wp_unslash($_POST['role'] ?? 'مستشارة مبيعات')),
            'default_language' => sanitize_key(wp_unslash($_POST['default_language'] ?? 'ar')),
            'dialect' => sanitize_text_field(wp_unslash($_POST['dialect'] ?? 'Yemeni/Sanaani light')),
        ];
        foreach (['warmth','formality','directness','empathy','qualification_depth','comparison_depth','upsell_frequency','handoff_threshold'] as $key) {
            $policy[$key] = max(0, min(100, (int) ($_POST[$key] ?? 50)));
        }
        self::insertPublishedPolicy($policy);
        wp_safe_redirect(add_query_arg(['page' => 'ysai-release1-agent', 'updated' => '1'], admin_url('options-general.php')));
        exit;
    }

    public static function addKnowledge(): void
    {
        if (!current_user_can('manage_ysai_knowledge')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer('ysai_r1_add_knowledge');
        global $wpdb;
        $wpdb->insert(self::table('knowledge_sources'), [
            'source_id' => self::uuid(),
            'title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
            'source_type' => sanitize_key(wp_unslash($_POST['type'] ?? 'policy')),
            'language' => 'ar',
            'market' => '',
            'branch' => '',
            'authority_level' => 100,
            'content_json' => wp_json_encode(['content' => sanitize_textarea_field(wp_unslash($_POST['content'] ?? ''))], JSON_UNESCAPED_UNICODE),
            'approval_status' => 'approved',
            'effective_from' => gmdate('Y-m-d H:i:s'),
            'effective_until' => null,
            'last_verified_at' => gmdate('Y-m-d H:i:s'),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        wp_safe_redirect(add_query_arg(['page' => 'ysai-release1-agent', 'updated' => '1'], admin_url('options-general.php')));
        exit;
    }

    /** @param array<string,mixed> $exporters @return array<string,mixed> */
    public static function privacyExporters(array $exporters): array
    {
        $exporters['ysai-release1'] = [
            'exporter_friendly_name' => __('Yassin AI Release 1 conversation intelligence', 'yassin-ai-assistant'),
            'callback' => [self::class, 'exportPersonalData'],
        ];
        return $exporters;
    }

    /** @param array<string,mixed> $erasers @return array<string,mixed> */
    public static function privacyErasers(array $erasers): array
    {
        $erasers['ysai-release1'] = [
            'eraser_friendly_name' => __('Yassin AI Release 1 conversation intelligence', 'yassin-ai-assistant'),
            'callback' => [self::class, 'erasePersonalData'],
        ];
        return $erasers;
    }

    /** @return array<string,mixed> */
    public static function exportPersonalData(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['data' => [], 'done' => true];
        }
        $actor = 'u:' . get_current_blog_id() . ':' . (int) $user->ID;
        global $wpdb;
        $data = [];
        foreach (['focus','pending_questions','journeys','sales_context'] as $suffix) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::quotedTable($suffix) . ' WHERE actor_key = %s LIMIT 500', $actor), ARRAY_A);
            if ($rows) {
                $data[] = ['group_id' => 'ysai-release1', 'group_label' => 'Yassin AI Release 1', 'item_id' => $suffix, 'data' => [['name' => $suffix, 'value' => wp_json_encode($rows, JSON_UNESCAPED_UNICODE)]]];
            }
        }
        return ['data' => $data, 'done' => true];
    }

    /** @return array<string,mixed> */
    public static function erasePersonalData(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
        $actor = 'u:' . get_current_blog_id() . ':' . (int) $user->ID;
        global $wpdb;
        $removed = false;
        foreach (['focus','pending_questions','journeys','sales_context','interaction_events'] as $suffix) {
            $deleted = $wpdb->delete(self::table($suffix), ['actor_key' => $actor]);
            $removed = $removed || (is_int($deleted) && $deleted > 0);
        }
        $pseudonym = 'erased:' . hash_hmac('sha256', $actor, wp_salt('auth'));
        $wpdb->update(self::table('audit'), ['actor_key' => $pseudonym], ['actor_key' => $actor]);
        return ['items_removed' => $removed, 'items_retained' => true, 'messages' => [__('Audit records were pseudonymized.', 'yassin-ai-assistant')], 'done' => true];
    }

    public static function runRetention(): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->query($wpdb->prepare('UPDATE ' . self::quotedTable('pending_questions') . " SET status = 'expired', updated_at = %s WHERE status = 'open' AND expires_at < %s", $now, $now));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::quotedTable('interaction_events') . ' WHERE created_at < %s', gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS * 30)));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::quotedTable('audit') . ' WHERE created_at < %s', gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS * 180)));
        foreach (['focus','pending_questions','journeys','sales_context'] as $suffix) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . self::quotedTable($suffix) . " WHERE actor_key LIKE 'g:%%' AND updated_at < %s", gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS * 30)));
        }
    }

    /** @return array{ok:bool,state:string,message:string} */
    private static function migrateLegacyTables(): array
    {
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->prefix)) {
            return ['ok' => false, 'state' => 'database_unavailable', 'message' => 'Database authority unavailable.'];
        }
        $suffixes = ['focus','pending_questions','journeys','sales_context','interaction_events','agent_policy_versions','knowledge_sources','audit'];
        $pairs = [];
        foreach ($suffixes as $suffix) {
            $pairs[$wpdb->prefix . 'ysai_r1_' . $suffix] = $wpdb->prefix . 'ysr1_' . $suffix;
        }
        $hasOld = false;
        foreach (array_keys($pairs) as $old) {
            $hasOld = $hasOld || self::tableExists($old);
        }
        if (!$hasOld) {
            return ['ok' => true, 'state' => 'ready', 'message' => ''];
        }
        $lockName = 'ysai_r1_namespace_' . substr(hash('sha256', (defined('DB_NAME') ? DB_NAME : '') . '|' . $wpdb->prefix), 0, 32);
        $lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 15));
        if ((string) $lock !== '1') {
            return self::migrationFailure('migration_lock_unavailable', 'Could not acquire the Release 1 migration lock.');
        }
        try {
            $rename = [];
            foreach ($pairs as $old => $new) {
                $oldExists = self::tableExists($old);
                $newExists = self::tableExists($new);
                if ($oldExists && $newExists) {
                    return self::migrationFailure('migration_collision', 'Both legacy and canonical Release 1 tables exist.');
                }
                if ($oldExists) {
                    $rename[$old] = $new;
                }
            }
            if ($rename === []) {
                return ['ok' => true, 'state' => 'ready', 'message' => ''];
            }
            $clauses = [];
            foreach ($rename as $old => $new) {
                $clauses[] = self::quoteIdentifier($old) . ' TO ' . self::quoteIdentifier($new);
            }
            $result = $wpdb->query('RENAME TABLE ' . implode(', ', $clauses)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            if ($result === false) {
                return self::migrationFailure('migration_query_failed', (string) $wpdb->last_error);
            }
            foreach ($rename as $old => $new) {
                if (self::tableExists($old) || !self::tableExists($new)) {
                    return self::migrationFailure('migration_verification_failed', 'Atomic migration verification failed.');
                }
            }
            update_option(self::MIGRATION_OPTION, ['state' => 'migrated', 'renamed' => $rename, 'completed_at_gmt' => gmdate('c')], false);
            return ['ok' => true, 'state' => 'migrated', 'message' => ''];
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    /** @return array{ok:bool,state:string,message:string} */
    private static function migrationFailure(string $state, string $message): array
    {
        update_option(self::MIGRATION_OPTION, ['state' => $state, 'message' => $message, 'failed_at_gmt' => gmdate('c')], false);
        return ['ok' => false, 'state' => $state, 'message' => $message];
    }

    private static function installSchema(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = [];
        $sql[] = 'CREATE TABLE ' . self::table('focus') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            site_id bigint unsigned NOT NULL,
            actor_key varchar(191) NOT NULL,
            conversation_key varchar(191) NOT NULL,
            journey_id char(36) NULL,
            question_id char(36) NULL,
            expected_type varchar(64) NULL,
            focused_json longtext NULL,
            source_message_key varchar(191) NULL,
            version bigint unsigned NOT NULL DEFAULT 1,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY actor_conversation (site_id,actor_key,conversation_key)
        ) $charset;";
        $sql[] = 'CREATE TABLE ' . self::table('pending_questions') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            question_id char(36) NOT NULL,
            site_id bigint unsigned NOT NULL,
            actor_key varchar(191) NOT NULL,
            conversation_key varchar(191) NOT NULL,
            journey_id char(36) NULL,
            source_message_key varchar(191) NULL,
            question_type varchar(64) NOT NULL,
            schema_json longtext NOT NULL,
            choices_json longtext NULL,
            resources_json longtext NULL,
            sensitivity varchar(32) NOT NULL DEFAULT 'normal',
            status varchar(24) NOT NULL DEFAULT 'open',
            expires_at datetime NOT NULL,
            answer_json longtext NULL,
            version bigint unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY question_id (question_id), KEY active_question (site_id,actor_key,conversation_key,status)
        ) $charset;";
        $sql[] = 'CREATE TABLE ' . self::table('journeys') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            journey_id char(36) NOT NULL,
            site_id bigint unsigned NOT NULL,
            actor_key varchar(191) NOT NULL,
            conversation_key varchar(191) NOT NULL,
            journey_type varchar(64) NOT NULL,
            status varchar(24) NOT NULL DEFAULT 'active',
            current_step varchar(96) NULL,
            known_json longtext NULL,
            missing_json longtext NULL,
            stale_json longtext NULL,
            resume_point varchar(191) NULL,
            version bigint unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY journey_id (journey_id), KEY active_journey (site_id,actor_key,conversation_key,status)
        ) $charset;";
        $sql[] = 'CREATE TABLE ' . self::table('sales_context') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            site_id bigint unsigned NOT NULL,
            actor_key varchar(191) NOT NULL,
            conversation_key varchar(191) NOT NULL,
            context_json longtext NOT NULL,
            version bigint unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY actor_conversation (site_id,actor_key,conversation_key)
        ) $charset;";
        $sql[] = 'CREATE TABLE ' . self::table('interaction_events') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            event_id char(36) NOT NULL,
            token_hash char(64) NOT NULL,
            site_id bigint unsigned NOT NULL,
            actor_key varchar(191) NOT NULL,
            conversation_key varchar(191) NOT NULL,
            event_type varchar(64) NOT NULL,
            state_hash char(64) NOT NULL,
            payload_json longtext NOT NULL,
            status varchar(24) NOT NULL DEFAULT 'issued',
            reserved_turn char(36) NULL,
            result_message_key varchar(191) NULL,
            expires_at datetime NOT NULL,
            consumed_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY event_id (event_id), UNIQUE KEY token_hash (token_hash), KEY event_scope (site_id,actor_key,conversation_key,status)
        ) $charset;";
        $sql[] = 'CREATE TABLE ' . self::table('agent_policy_versions') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            policy_version bigint unsigned NOT NULL,
            status varchar(24) NOT NULL,
            policy_json longtext NOT NULL,
            checksum char(64) NOT NULL,
            created_by bigint unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            published_at datetime NULL,
            PRIMARY KEY (id), UNIQUE KEY policy_version (policy_version), KEY policy_status (status)
        ) $charset;";
        $sql[] = 'CREATE TABLE ' . self::table('knowledge_sources') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            source_id char(36) NOT NULL,
            title varchar(191) NOT NULL,
            source_type varchar(64) NOT NULL,
            language varchar(16) NOT NULL DEFAULT 'ar',
            market varchar(64) NULL,
            branch varchar(96) NULL,
            authority_level smallint unsigned NOT NULL DEFAULT 100,
            content_json longtext NOT NULL,
            approval_status varchar(24) NOT NULL DEFAULT 'draft',
            effective_from datetime NULL,
            effective_until datetime NULL,
            last_verified_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY source_id (source_id), KEY active_source (approval_status,effective_from,effective_until)
        ) $charset;";
        $sql[] = 'CREATE TABLE ' . self::table('audit') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            audit_id char(36) NOT NULL,
            site_id bigint unsigned NOT NULL,
            actor_key varchar(191) NOT NULL,
            conversation_key varchar(191) NULL,
            event_type varchar(64) NOT NULL,
            details_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY audit_id (audit_id), KEY actor_created (actor_key,created_at)
        ) $charset;";
        foreach ($sql as $statement) {
            dbDelta($statement);
        }
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
    }

    private static function installCapabilities(): void
    {
        $caps = [
            'manage_ysai_agent','manage_ysai_experience','manage_ysai_features','manage_ysai_models','manage_ysai_knowledge',
            'view_ysai_conversations','send_ysai_support_messages','view_ysai_orders','view_ysai_payment_evidence',
            'decide_ysai_payment_reviews','execute_ysai_payment_transition','view_ysai_audit','manage_ysai_privacy',
        ];
        foreach (['administrator','shop_manager'] as $roleName) {
            $role = get_role($roleName);
            if (!$role) {
                continue;
            }
            foreach ($caps as $cap) {
                $role->add_cap($cap);
            }
        }
    }

    private static function ensureDefaultPolicy(): void
    {
        global $wpdb;
        $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::quotedTable('agent_policy_versions'));
        if ($count > 0) {
            return;
        }
        self::insertPublishedPolicy([
            'name' => 'أصالة', 'role' => 'مستشارة مبيعات', 'default_language' => 'ar', 'fallback_language' => 'en',
            'dialect' => 'Yemeni/Sanaani light', 'warmth' => 75, 'formality' => 55, 'directness' => 65,
            'empathy' => 75, 'qualification_depth' => 45, 'comparison_depth' => 60, 'upsell_frequency' => 25,
            'handoff_threshold' => 70, 'refusal_suppression' => true,
        ]);
    }

    /** @param array<string,mixed> $policy */
    private static function insertPublishedPolicy(array $policy): void
    {
        global $wpdb;
        $json = wp_json_encode($policy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Agent policy could not be encoded.');
        }
        $wpdb->query('START TRANSACTION');
        try {
            $version = 1 + (int) $wpdb->get_var('SELECT COALESCE(MAX(policy_version),0) FROM ' . self::quotedTable('agent_policy_versions') . ' FOR UPDATE');
            $wpdb->update(self::table('agent_policy_versions'), ['status' => 'archived'], ['status' => 'published']);
            $ok = $wpdb->insert(self::table('agent_policy_versions'), [
                'policy_version' => $version, 'status' => 'published', 'policy_json' => $json,
                'checksum' => hash('sha256', $json), 'created_by' => get_current_user_id(),
                'created_at' => gmdate('Y-m-d H:i:s'), 'published_at' => gmdate('Y-m-d H:i:s'),
            ]);
            if ($ok === false) {
                throw new \RuntimeException((string) $wpdb->last_error);
            }
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    private static function scheduleRetention(): void
    {
        if (!wp_next_scheduled(self::RETENTION_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::RETENTION_HOOK);
        }
    }

    /** @return array<string,mixed> */
    private static function resolveActor(?WP_REST_Request $request): array
    {
        $site = get_current_blog_id();
        $user = wp_get_current_user();
        if ($user && $user->exists()) {
            return ['key' => 'u:' . $site . ':' . (int) $user->ID, 'type' => user_can($user, 'view_ysai_conversations') ? 'staff' : 'customer', 'authenticated' => true];
        }
        $raw = '';
        if ($request) {
            $raw = trim((string) $request->get_header('X-YSAI-Session'));
        }
        if ($raw === '' && isset($_COOKIE[self::GUEST_COOKIE])) {
            $raw = sanitize_text_field(wp_unslash($_COOKIE[self::GUEST_COOKIE]));
        }
        if ($raw === '') {
            $raw = wp_generate_password(48, false, false);
            if (!headers_sent()) {
                setcookie(self::GUEST_COOKIE, $raw, ['expires' => time() + DAY_IN_SECONDS * 30, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
            }
        }
        return ['key' => 'g:' . $site . ':' . hash_hmac('sha256', $raw, wp_salt('auth')), 'type' => 'guest', 'authenticated' => false];
    }

    private static function conversationKey(WP_REST_Request $request, string $actor): string
    {
        $raw = (string) ($request->get_param('conversation_id') ?: $request->get_param('conversationId') ?: $request->get_header('X-YSAI-Conversation'));
        if ($raw === '') {
            $raw = $actor . '|' . (string) $request->get_header('X-YSAI-Session');
        }
        return hash_hmac('sha256', $raw, wp_salt('secure_auth'));
    }

    private static function isChatRequest(WP_REST_Request $request): bool
    {
        $route = strtolower($request->get_route());
        return (str_contains($route, 'ysai') || str_contains($route, 'yassin')) && preg_match('#/(chat|message|turn)(/|$)#', $route) === 1;
    }

    private static function isModelGenerationUrl(string $url): bool
    {
        $lower = strtolower($url);
        $provider = str_contains($lower, 'generativelanguage.googleapis.com') || str_contains($lower, 'aiplatform.googleapis.com') || str_contains($lower, 'api.openai.com') || str_contains($lower, 'api.anthropic.com');
        $excluded = preg_match('#/(embeddings?|images?|audio|files?|uploads?)(/|\?|$)#', $lower) === 1;
        return $provider && !$excluded;
    }

    /** @return array<string,mixed> */
    private static function loadState(string $actor, string $conversation): array
    {
        global $wpdb;
        $site = get_current_blog_id();
        $focus = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::quotedTable('focus') . ' WHERE site_id=%d AND actor_key=%s AND conversation_key=%s', $site, $actor, $conversation), ARRAY_A) ?: [];
        $pending = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::quotedTable('pending_questions') . " WHERE site_id=%d AND actor_key=%s AND conversation_key=%s AND status='open' AND expires_at >= %s ORDER BY id DESC LIMIT 1", $site, $actor, $conversation, gmdate('Y-m-d H:i:s')), ARRAY_A) ?: [];
        $journey = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::quotedTable('journeys') . " WHERE site_id=%d AND actor_key=%s AND conversation_key=%s AND status IN ('active','paused') ORDER BY id DESC LIMIT 1", $site, $actor, $conversation), ARRAY_A) ?: [];
        $sales = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::quotedTable('sales_context') . ' WHERE site_id=%d AND actor_key=%s AND conversation_key=%s', $site, $actor, $conversation), ARRAY_A) ?: [];
        foreach (['focused_json'] as $field) { if (isset($focus[$field])) { $focus[$field] = self::decodeJson($focus[$field]); } }
        foreach (['schema_json','choices_json','resources_json','answer_json'] as $field) { if (isset($pending[$field])) { $pending[$field] = self::decodeJson($pending[$field]); } }
        foreach (['known_json','missing_json','stale_json'] as $field) { if (isset($journey[$field])) { $journey[$field] = self::decodeJson($journey[$field]); } }
        $sales = isset($sales['context_json']) ? self::decodeJson($sales['context_json']) : [];
        return ['focus' => $focus, 'pending' => $pending, 'journey' => $journey, 'sales' => $sales];
    }

    /** @param array<string,mixed> $pending @return array<string,mixed> */
    private static function bindShortReply(string $text, array $pending): array
    {
        $text = trim(self::normalizeDigits($text));
        if ($text === '' || $pending === []) {
            return ['status' => 'none'];
        }
        if (($pending['sensitivity'] ?? 'normal') !== 'normal') {
            return ['status' => 'blocked', 'code' => 'confirmation_record_required'];
        }
        $schema = is_array($pending['schema_json'] ?? null) ? $pending['schema_json'] : [];
        $type = (string) ($schema['type'] ?? $pending['question_type'] ?? 'text');
        if (in_array($type, ['integer','quantity'], true) && preg_match('/^-?\d+$/D', $text)) {
            $value = (int) $text;
            $min = isset($schema['minimum']) ? (int) $schema['minimum'] : 1;
            $max = isset($schema['maximum']) ? (int) $schema['maximum'] : 999;
            return $value >= $min && $value <= $max ? ['status' => 'bound', 'type' => $type, 'value' => $value] : ['status' => 'invalid', 'code' => 'out_of_range'];
        }
        if ($type === 'number' && is_numeric($text)) {
            return ['status' => 'bound', 'type' => 'number', 'value' => (float) $text];
        }
        if ($type === 'boolean') {
            $yes = ['yes','y','true','1','نعم','ايوه','أيوه','تمام'];
            $no = ['no','n','false','0','لا','كلا'];
            if (in_array(mb_strtolower($text), $yes, true)) { return ['status' => 'bound', 'type' => 'boolean', 'value' => true]; }
            if (in_array(mb_strtolower($text), $no, true)) { return ['status' => 'bound', 'type' => 'boolean', 'value' => false]; }
        }
        $choices = is_array($pending['choices_json'] ?? null) ? $pending['choices_json'] : [];
        foreach ($choices as $index => $choice) {
            if (!is_array($choice)) { continue; }
            $aliases = array_merge([(string) ($choice['id'] ?? ''), (string) ($choice['label'] ?? ''), (string) ($index + 1)], is_array($choice['aliases'] ?? null) ? $choice['aliases'] : []);
            foreach ($aliases as $alias) {
                if ($alias !== '' && mb_strtolower(trim((string) $alias)) === mb_strtolower($text)) {
                    return ['status' => 'bound', 'type' => 'choice', 'value' => (string) ($choice['id'] ?? $index), 'label' => (string) ($choice['label'] ?? '')];
                }
            }
        }
        return ['status' => 'unresolved'];
    }

    /** @param array<string,mixed> $prepared */
    private static function commitBinding(array $prepared, string $message): void
    {
        $binding = $prepared['binding'] ?? [];
        $pending = $prepared['state']['pending'] ?? [];
        if (($binding['status'] ?? '') !== 'bound' || empty($pending['question_id'])) {
            return;
        }
        global $wpdb;
        $wpdb->update(self::table('pending_questions'), [
            'status' => 'answered', 'answer_json' => wp_json_encode($binding, JSON_UNESCAPED_UNICODE),
            'source_message_key' => $message, 'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['question_id' => (string) $pending['question_id'], 'actor_key' => (string) $prepared['actor']['key'], 'conversation_key' => (string) $prepared['conversation'], 'status' => 'open']);
    }

    /** @param array<string,mixed> $prepared @param array<string,mixed> $data */
    private static function projectResponseState(array $prepared, array $data, string $message): void
    {
        $actor = (string) $prepared['actor']['key'];
        $conversation = (string) $prepared['conversation'];
        $question = self::findQuestion($data);
        if ($question !== []) {
            self::createPendingQuestion($actor, $conversation, $message, $question);
        }
        $sales = self::findSalesContext($data);
        if ($sales !== []) {
            self::upsertSalesContext($actor, $conversation, $sales);
        }
        $focus = self::findFocus($data);
        self::upsertFocus($actor, $conversation, $message, $question, $focus);
    }

    /** @return array<string,mixed> */
    private static function findQuestion(array $data): array
    {
        $candidates = self::findArraysByKeys($data, ['expected_answer_type','question_type','choices']);
        foreach ($candidates as $candidate) {
            if (isset($candidate['expected_answer_type']) || isset($candidate['question_type'])) {
                return $candidate;
            }
        }
        return [];
    }

    /** @return array<string,mixed> */
    private static function findSalesContext(array $data): array
    {
        foreach (['sales_context','shopping_memory','memory'] as $key) {
            $found = self::findValueByKey($data, $key);
            if (is_array($found)) { return self::sanitizeContext($found); }
        }
        return [];
    }

    /** @return array<string,mixed> */
    private static function findFocus(array $data): array
    {
        $focus = [];
        foreach (['product_id','variation_id','resource_id','journey_id'] as $key) {
            $value = self::findValueByKey($data, $key);
            if (is_scalar($value) && (string) $value !== '') { $focus[$key] = (string) $value; }
        }
        return $focus;
    }

    /** @param array<string,mixed> $question */
    private static function createPendingQuestion(string $actor, string $conversation, string $message, array $question): void
    {
        global $wpdb;
        $site = get_current_blog_id();
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->query($wpdb->prepare("UPDATE " . self::quotedTable('pending_questions') . " SET status='superseded',updated_at=%s WHERE site_id=%d AND actor_key=%s AND conversation_key=%s AND status='open'", $now, $site, $actor, $conversation));
        $type = sanitize_key((string) ($question['expected_answer_type'] ?? $question['question_type'] ?? 'text'));
        $choices = is_array($question['choices'] ?? null) ? array_slice($question['choices'], 0, 20) : [];
        $schema = ['type' => $type];
        foreach (['minimum','maximum','pattern'] as $key) { if (isset($question[$key])) { $schema[$key] = $question[$key]; } }
        $wpdb->insert(self::table('pending_questions'), [
            'question_id' => self::uuid(), 'site_id' => $site, 'actor_key' => $actor, 'conversation_key' => $conversation,
            'journey_id' => isset($question['journey_id']) ? sanitize_text_field((string) $question['journey_id']) : null,
            'source_message_key' => $message, 'question_type' => $type,
            'schema_json' => wp_json_encode($schema), 'choices_json' => wp_json_encode($choices, JSON_UNESCAPED_UNICODE),
            'resources_json' => wp_json_encode($question['resources'] ?? []),
            'sensitivity' => in_array(($question['sensitivity'] ?? 'normal'), ['normal','sensitive'], true) ? $question['sensitivity'] : 'normal',
            'status' => 'open', 'expires_at' => gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS * 24),
            'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** @param array<string,mixed> $sales */
    private static function upsertSalesContext(string $actor, string $conversation, array $sales): void
    {
        global $wpdb;
        $site = get_current_blog_id(); $now = gmdate('Y-m-d H:i:s');
        $json = wp_json_encode(self::sanitizeContext($sales), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sql = $wpdb->prepare('INSERT INTO ' . self::quotedTable('sales_context') . ' (site_id,actor_key,conversation_key,context_json,version,created_at,updated_at) VALUES (%d,%s,%s,%s,1,%s,%s) ON DUPLICATE KEY UPDATE context_json=VALUES(context_json),version=version+1,updated_at=VALUES(updated_at)', $site, $actor, $conversation, $json, $now, $now);
        $wpdb->query($sql);
    }

    /** @param array<string,mixed> $question @param array<string,mixed> $focus */
    private static function upsertFocus(string $actor, string $conversation, string $message, array $question, array $focus): void
    {
        global $wpdb;
        $site = get_current_blog_id(); $now = gmdate('Y-m-d H:i:s');
        $questionId = $wpdb->get_var($wpdb->prepare("SELECT question_id FROM " . self::quotedTable('pending_questions') . " WHERE site_id=%d AND actor_key=%s AND conversation_key=%s AND status='open' ORDER BY id DESC LIMIT 1", $site, $actor, $conversation));
        $sql = $wpdb->prepare('INSERT INTO ' . self::quotedTable('focus') . ' (site_id,actor_key,conversation_key,journey_id,question_id,expected_type,focused_json,source_message_key,version,updated_at) VALUES (%d,%s,%s,%s,%s,%s,%s,%s,1,%s) ON DUPLICATE KEY UPDATE journey_id=VALUES(journey_id),question_id=VALUES(question_id),expected_type=VALUES(expected_type),focused_json=VALUES(focused_json),source_message_key=VALUES(source_message_key),version=version+1,updated_at=VALUES(updated_at)', $site, $actor, $conversation, $question['journey_id'] ?? null, $questionId ?: null, $question['expected_answer_type'] ?? $question['question_type'] ?? null, wp_json_encode($focus), $message, $now);
        $wpdb->query($sql);
    }

    /** @param array<string,mixed> $actor @return array<string,string> */
    private static function featureStates(array $actor): array
    {
        $on = ['ai_conversation','typed_conversation_state','structured_interactions','agent_policy','knowledge_context','catalog_assistance','cart_assistance','image_input'];
        $states = [];
        foreach ($on as $feature) { $states[$feature] = 'on'; }
        foreach (['chat_checkout','order_service','crm_cases','offline_payment_review','human_handoff'] as $feature) { $states[$feature] = 'off'; }
        if (!$actor['authenticated']) { $states['owned_customer_resources'] = 'blocked'; }
        return $states;
    }

    /** @return array<string,mixed> */
    private static function publishedPolicy(): array
    {
        global $wpdb;
        $json = $wpdb->get_var("SELECT policy_json FROM " . self::quotedTable('agent_policy_versions') . " WHERE status='published' ORDER BY policy_version DESC LIMIT 1");
        return is_string($json) ? self::decodeJson($json) : [];
    }

    /** @return array<int,array<string,mixed>> */
    private static function activeKnowledge(): array
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT source_id,title,source_type,language,market,branch,authority_level,content_json,last_verified_at FROM " . self::quotedTable('knowledge_sources') . " WHERE approval_status='approved' AND (effective_from IS NULL OR effective_from <= %s) AND (effective_until IS NULL OR effective_until >= %s) ORDER BY authority_level DESC,id DESC LIMIT 20", $now, $now), ARRAY_A) ?: [];
        foreach ($rows as &$row) { $row['content'] = self::decodeJson((string) $row['content_json']); unset($row['content_json']); }
        return $rows;
    }

    /** @return array<string,mixed> */
    private static function runtimeContext(): array
    {
        $tz = wp_timezone(); $now = new \DateTimeImmutable('now', $tz);
        return ['utc' => gmdate('c'), 'local' => $now->format(DATE_ATOM), 'timezone' => $tz->getName(), 'site_id' => get_current_blog_id(), 'locale' => get_locale()];
    }

    /** @return array<string,mixed>|WP_Error|null */
    private static function reserveEvent(WP_REST_Request $request, string $actor, string $conversation, string $turn)
    {
        $token = trim((string) ($request->get_header('X-YSAI-Interaction') ?: $request->get_param('interaction_event')));
        if ($token === '') { return null; }
        global $wpdb;
        $hash = hash('sha256', $token);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::quotedTable('interaction_events') . ' WHERE token_hash=%s LIMIT 1', $hash), ARRAY_A);
        if (!$row) { return new WP_Error('ysai_r1_event_invalid', __('The interaction expired or is invalid.', 'yassin-ai-assistant'), ['status' => 409]); }
        if (!hash_equals((string) $row['actor_key'], $actor) || !hash_equals((string) $row['conversation_key'], $conversation)) { return new WP_Error('ysai_r1_event_scope', __('The interaction does not belong to this conversation.', 'yassin-ai-assistant'), ['status' => 403]); }
        if (strtotime((string) $row['expires_at']) < time()) { return new WP_Error('ysai_r1_event_expired', __('The interaction expired.', 'yassin-ai-assistant'), ['status' => 409]); }
        if ($row['status'] === 'completed') { return ['replay' => true, 'row' => $row]; }
        if ($row['status'] !== 'issued') { return new WP_Error('ysai_r1_event_in_use', __('The interaction is already being processed.', 'yassin-ai-assistant'), ['status' => 409]); }
        $updated = $wpdb->query($wpdb->prepare("UPDATE " . self::quotedTable('interaction_events') . " SET status='reserved',reserved_turn=%s WHERE id=%d AND status='issued'", $turn, (int) $row['id']));
        if ($updated !== 1) { return new WP_Error('ysai_r1_event_race', __('The interaction was already used.', 'yassin-ai-assistant'), ['status' => 409]); }
        $row['payload'] = self::decodeJson((string) $row['payload_json']);
        return ['replay' => false, 'row' => $row];
    }

    /** @param mixed $event */
    private static function completeEvent($event, string $turn, string $message): void
    {
        if (!is_array($event) || ($event['replay'] ?? false) || empty($event['row']['id'])) { return; }
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE " . self::quotedTable('interaction_events') . " SET status='completed',result_message_key=%s,consumed_at=%s WHERE id=%d AND status='reserved' AND reserved_turn=%s", $message, gmdate('Y-m-d H:i:s'), (int) $event['row']['id'], $turn));
    }

    /** @param mixed $event */
    private static function abortEvent($event, string $turn): void
    {
        if (!is_array($event) || ($event['replay'] ?? false) || empty($event['row']['id'])) { return; }
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE " . self::quotedTable('interaction_events') . " SET status='issued',reserved_turn=NULL WHERE id=%d AND status='reserved' AND reserved_turn=%s", (int) $event['row']['id'], $turn));
    }

    /** @param array<string,mixed> $pending @return array<int,array<string,string>> */
    private static function issueInteractionsForPending(string $actor, string $conversation, array $pending): array
    {
        if ($pending === [] || ($pending['status'] ?? '') !== 'open' || ($pending['sensitivity'] ?? 'normal') !== 'normal') { return []; }
        $choices = is_array($pending['choices_json'] ?? null) ? $pending['choices_json'] : [];
        $issued = [];
        foreach (array_slice($choices, 0, 12) as $index => $choice) {
            if (!is_array($choice)) { continue; }
            $id = (string) ($choice['id'] ?? $index + 1); $label = (string) ($choice['label'] ?? $id);
            $issued[] = ['choice_id' => $id, 'label' => $label, 'token' => self::issueEvent($actor, $conversation, 'answer_pending_question', ['question_id' => $pending['question_id'], 'choice_id' => $id, 'label' => $label], hash('sha256', (string) $pending['question_id'] . '|' . (string) ($pending['version'] ?? 1)))];
        }
        return $issued;
    }

    /** @param array<string,mixed> $payload */
    private static function issueEvent(string $actor, string $conversation, string $type, array $payload, string $stateHash): string
    {
        global $wpdb;
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $wpdb->insert(self::table('interaction_events'), [
            'event_id' => self::uuid(), 'token_hash' => hash('sha256', $token), 'site_id' => get_current_blog_id(),
            'actor_key' => $actor, 'conversation_key' => $conversation, 'event_type' => $type, 'state_hash' => $stateHash,
            'payload_json' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE), 'status' => 'issued',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS), 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $token;
    }

    /** @param array<string,mixed> $prepared @param array<string,mixed> $details */
    private static function audit(string $type, array $prepared, array $details): void
    {
        global $wpdb;
        $wpdb->insert(self::table('audit'), ['audit_id' => self::uuid(), 'site_id' => get_current_blog_id(), 'actor_key' => (string) ($prepared['actor']['key'] ?? ''), 'conversation_key' => (string) ($prepared['conversation'] ?? ''), 'event_type' => $type, 'details_json' => wp_json_encode($details), 'created_at' => gmdate('Y-m-d H:i:s')]);
    }

    /** @return array<string,mixed> */
    private static function publicState(array $state): array
    {
        return ['version' => 1, 'focus' => $state['focus'], 'pending_question' => $state['pending'], 'journey' => $state['journey'], 'sales_context' => $state['sales'], 'interactions' => $state['interactions'] ?? [], 'features' => self::featureStates(self::resolveActor(null))];
    }

    /** @return array<string,mixed> */
    private static function responseData($response): array
    {
        if ($response instanceof WP_REST_Response) { $data = $response->get_data(); return is_array($data) ? $data : []; }
        return is_array($response) ? $response : [];
    }

    private static function findDurableMessageKey(array $data): string
    {
        foreach (['turn_id','message_id','assistant_message_id','id'] as $key) {
            $value = self::findValueByKey($data, $key);
            if (is_scalar($value) && (string) $value !== '') { return hash('sha256', $key . '|' . (string) $value); }
        }
        return '';
    }

    private static function requestText(WP_REST_Request $request): string
    {
        foreach (['message','text','content','prompt'] as $key) {
            $value = $request->get_param($key);
            if (is_string($value)) { return $value; }
        }
        return '';
    }

    /** @return mixed */
    private static function findValueByKey(array $data, string $wanted)
    {
        if (array_key_exists($wanted, $data)) { return $data[$wanted]; }
        foreach ($data as $value) { if (is_array($value)) { $found = self::findValueByKey($value, $wanted); if ($found !== null) { return $found; } } }
        return null;
    }

    /** @return array<int,array<string,mixed>> */
    private static function findArraysByKeys(array $data, array $keys): array
    {
        $found = [];
        foreach ($keys as $key) { if (array_key_exists($key, $data)) { $found[] = $data; break; } }
        foreach ($data as $value) { if (is_array($value)) { $found = array_merge($found, self::findArraysByKeys($value, $keys)); } }
        return $found;
    }

    /** @return array<string,mixed> */
    private static function sanitizeContext(array $context): array
    {
        $allowed = ['goal','use_case','sales_stage','hard_requirements','soft_preferences','exclusions','budget','budget_scope','quantity','unit','timing','recipient_or_occasion','compared_products','rejected_products','objections','refusals','unresolved_requirements','response_preference'];
        return array_intersect_key($context, array_flip($allowed));
    }

    /** @return array<string,mixed> */
    private static function decodeJson(string $json): array
    {
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private static function normalizeDigits(string $text): string
    {
        return strtr($text, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
    }

    private static function table(string $suffix): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ysr1_' . $suffix;
    }

    private static function quotedTable(string $suffix): string
    {
        return self::quoteIdentifier(self::table($suffix));
    }

    private static function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $identifier)) { throw new \RuntimeException('Unsafe SQL identifier.'); }
        return '`' . $identifier . '`';
    }

    private static function tableExists(string $table): bool
    {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        return is_string($found) && hash_equals($table, $found);
    }

    private static function uuid(): string
    {
        return function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff));
    }

    private static function field(string $name, string $label, string $value): void
    {
        echo '<p><label><strong>' . esc_html($label) . '</strong><br><input class="regular-text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" required></label></p>';
    }

    private static function numberField(string $name, string $label, int $value): void
    {
        echo '<p><label><strong>' . esc_html($label) . '</strong><br><input type="number" min="0" max="100" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '"></label></p>';
    }
}
