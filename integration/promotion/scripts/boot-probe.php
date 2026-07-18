<?php

declare(strict_types=1);

if (!defined('WP_CLI') || WP_CLI !== true) {
    throw new RuntimeException('This probe must run through WP-CLI.');
}

$request = new WP_REST_Request('POST', '/yassin-ai/v1/boot');
$request->set_header('Content-Type', 'application/json');
$request->set_body(wp_json_encode(array(
    'client_instance_id' => '11111111-1111-4111-8111-111111111111',
    'browser_continuity_secret' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
    'pending_turn_id' => '',
), JSON_UNESCAPED_SLASHES));
$response = rest_do_request($request);
$status = $response->get_status();
$data = $response->get_data();
if ($status !== 200 || !is_array($data) || ($data['ok'] ?? false) !== true) {
    WP_CLI::error('Boot probe failed: HTTP ' . (string) $status . ' ' . wp_json_encode($data));
}
$messages = $data['conversation']['messages'] ?? null;
if (!is_array($messages) || $messages !== array()) {
    WP_CLI::error('A fresh packaged-plugin boot returned a fabricated transcript message.');
}
if (isset($data['welcome_message'])) {
    WP_CLI::error('The retired welcome_message field leaked into the boot response.');
}
$encoded = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($encoded) || strpos($encoded, 'provider_call_id') !== false
    || strpos($encoded, 'tool_call_id') !== false
    || strpos($encoded, 'model_step_id') !== false
    || strpos($encoded, 'client_turn_id') !== false
    || strpos($encoded, 'accepted_at') !== false
    || strpos($encoded, 'model_question') !== false
) {
    WP_CLI::error('Private model-question provenance leaked into the public boot response.');
}

echo wp_json_encode(array(
    'ok' => true,
    'status' => $status,
    'conversation_id' => (string) ($data['conversation']['id'] ?? ''),
    'message_count' => count($messages),
    'private_provenance_exposed' => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
