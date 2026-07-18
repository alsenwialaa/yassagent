<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Application\Contract\PublicApiContract;
use YassinStore\AiAssistant\Application\Contract\PublicResponseSchemaValidator;
use YassinStore\AiAssistant\Domain\Chat\ConversationState;
use YassinStore\AiAssistant\Presentation\Rest\AdminTestResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\BootResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\ConversationDeleteResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\ConversationExportResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\ErrorResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\HealthResponseProjector;
use YassinStore\AiAssistant\Presentation\Rest\TurnResponseProjector;
use YassinStore\AiAssistant\Support\Json;

require dirname(__DIR__) . '/tests/bootstrap.php';

const FIXTURE_PATH = __DIR__ . '/../tests/fixtures/public-api-contract-examples.json';
const FIXTURE_TIME = 1700000000;
const CONVERSATION_ID = '11111111-1111-4111-8111-111111111111';
const TURN_ID = '22222222-2222-4222-8222-222222222222';
const USER_MESSAGE_ID = '33333333-3333-4333-8333-333333333333';
const ASSISTANT_MESSAGE_ID = '44444444-4444-4444-8444-444444444444';
const CONVERSATION_TOKEN = 'conversation-token-1234567890';

/** @return array<string,mixed> */
function contractFixture(): array
{
    $raw = file_get_contents(dirname(__DIR__) . '/config/public-api-contract.json');
    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('Canonical public contract is unavailable.');
    }
    $contract = new PublicApiContract(Json::decodeRequiredObject($raw, 'Public API contract'));
    $validator = new PublicResponseSchemaValidator($contract);

    $presentation = array(
        'image_scope' => 'none',
        'images' => array(),
        'reply_quote' => '',
    );
    $cart = array(
        'item_count' => 0,
        'formatted_total' => 'SAR 0.00',
        'cart_url' => 'https://example.test/cart',
        'checkout_url' => 'https://example.test/checkout',
    );
    $cartMutations = array(
        'available' => true,
        'code' => 'available',
        'notice' => '',
    );
    $userMessage = array(
        'id' => USER_MESSAGE_ID,
        'turn_id' => TURN_ID,
        'role' => 'user',
        'outcome' => '',
        'text' => 'أرني القميص',
        'products' => array(),
        'receipts' => array(),
        'presentation' => $presentation,
        'created_at' => FIXTURE_TIME,
    );
    $assistantMessage = array(
        'id' => ASSISTANT_MESSAGE_ID,
        'turn_id' => TURN_ID,
        'role' => 'assistant',
        'outcome' => 'answer',
        'text' => 'هذا هو القميص المناسب.',
        'products' => array(array(
            'id' => 17,
            'name' => 'قميص كلاسيكي',
            'formatted_price' => 'SAR 120.00',
            'short_description' => 'قميص قطني.',
            'in_stock' => true,
            'requires_variation' => false,
            'image' => 'https://example.test/product.jpg',
            'permalink' => 'https://example.test/product',
        )),
        'receipts' => array(),
        'presentation' => $presentation,
        'created_at' => FIXTURE_TIME + 1,
    );

    $boot = (new BootResponseProjector($validator))->project(
        'eyJ2IjoxfQ.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        CONVERSATION_ID,
        CONVERSATION_TOKEN,
        array(),
        array(
            'title' => 'مساعد التسوق',
            'subtitle' => '',
            'button_text' => 'محادثة',
            'empty_state_hint' => '',
        ),
        $cart,
        true,
        '',
        array(
            'chat_ready' => true,
            'images' => true,
            'max_images' => 2,
            'max_image_bytes' => 524288,
            'cart_mutations' => $cartMutations,
        ),
        null,
        FIXTURE_TIME
    )->data();
    $turn = (new TurnResponseProjector($validator))->project(
        $assistantMessage,
        true,
        CONVERSATION_ID,
        CONVERSATION_TOKEN,
        array($userMessage, $assistantMessage),
        true,
        '',
        $cart,
        true,
        '',
        $cartMutations
    )->data();
    $health = (new HealthResponseProjector($validator))->project(
        '1.0.0',
        true,
        FIXTURE_TIME
    )->data();
    $export = (new ConversationExportResponseProjector($validator))->project(array(
        'schema' => 1,
        'conversation_id' => CONVERSATION_ID,
        'created_at' => FIXTURE_TIME - 600,
        'updated_at' => FIXTURE_TIME - 30,
        'expires_at' => FIXTURE_TIME + 86400,
        'state' => ConversationState::initial()->forPrivacy(),
        'messages' => array(
            array(
                'role' => 'user',
                'outcome' => '',
                'text' => $userMessage['text'],
                'payload' => array('presentation' => $presentation),
                'created_at' => FIXTURE_TIME,
            ),
            array(
                'role' => 'assistant',
                'outcome' => 'answer',
                'text' => $assistantMessage['text'],
                'payload' => array('message' => $assistantMessage),
                'created_at' => FIXTURE_TIME + 1,
            ),
        ),
        'verified_cart_receipts' => array(),
        'turns' => array(),
        'cart_operations' => array(),
        'cart_operation_steps' => array(),
        'cart_step_attempts' => array(),
        'next_cursor' => null,
        'complete' => true,
    ))->data();
    $deleted = (new ConversationDeleteResponseProjector($validator))->project()->data();
    $admin = (new AdminTestResponseProjector($validator))->project(array(
        'ok' => true,
        'reply' => 'جاهز',
        'model' => 'gemini-3.5-flash',
        'checks' => array(
            'provider_access' => 'passed',
            'structured_tool' => 'passed',
        ),
        'provider_requests' => 2,
        'checked_at' => FIXTURE_TIME,
        'expires_at' => FIXTURE_TIME + 2592000,
    ))->data();
    $error = (new ErrorResponseProjector($validator))->project(
        'request_failed',
        'تعذر إكمال الطلب بأمان.',
        503,
        2
    )->data();

    $valid = array(
        row('new boot request', 'boot_request', array(
            'client_instance_id' => CONVERSATION_ID,
            'browser_continuity_secret' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
        )),
        row('resumed boot request', 'boot_request', array(
            'client_instance_id' => CONVERSATION_ID,
            'browser_continuity_secret' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            'previous_browser_continuity_secret' => 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB',
            'conversation_id' => TURN_ID,
            'conversation_token' => CONVERSATION_TOKEN,
            'pending_turn_id' => USER_MESSAGE_ID,
        )),
        row('text chat request', 'chat_request', array(
            'conversation_id' => CONVERSATION_ID,
            'conversation_token' => CONVERSATION_TOKEN,
            'client_turn_id' => TURN_ID,
            'message' => 'أبحث عن قميص',
            'attachments' => array(),
        )),
        row('image chat request', 'chat_request', array(
            'conversation_id' => CONVERSATION_ID,
            'conversation_token' => CONVERSATION_TOKEN,
            'client_turn_id' => TURN_ID,
            'message' => '',
            'attachments' => array(array(
                'mime_type' => 'image/png',
                'data' => 'AAAAAAAAAAAAAAAAAAAAAA==',
            )),
        )),
        row('typed boot response', 'boot_response', $boot),
        row('typed turn response', 'turn_response', $turn),
        row('typed health response', 'health_response', $health),
        row('typed conversation export response', 'conversation_export_response', $export),
        row('typed conversation delete response', 'conversation_delete_response', $deleted),
        row('typed admin readiness response', 'admin_test_response', $admin),
        row('typed error response', 'error_response', $error),
    );

    $invalidBoot = $valid[0]['value'];
    $invalidBoot['legacy'] = true;
    $invalidAttachment = $valid[3]['value'];
    $invalidAttachment['attachments'][0]['filename'] = 'private.png';
    $invalidChat = $valid[2]['value'];
    $invalidChat['message'] = str_repeat('x', 16385);
    $invalidBootResponse = $boot;
    $invalidBootResponse['capabilities']['legacy'] = true;
    $invalidTurnPresentation = $turn;
    unset($invalidTurnPresentation['message']['presentation']['reply_quote']);
    $invalidTurnProduct = $turn;
    $invalidTurnProduct['message']['products'][0]['legacy_price'] = '120';
    $invalidTurnResponse = $turn;
    unset($invalidTurnResponse['cart_mutations']);
    $invalidHealth = $health;
    $invalidHealth['details'] = array('database' => 'ready');
    $invalidExportState = $export;
    $invalidExportState['export']['state']['pending_cart_intent'] = array('private' => true);
    $invalidExportPayload = $export;
    $invalidExportPayload['export']['messages'][1]['payload']['model_question'] = array('private' => true);
    $invalidDelete = $deleted;
    $invalidDelete['deleted'] = false;
    $invalidAdmin = $admin;
    $invalidAdmin['result']['checks']['catalog'] = 'passed';
    $invalidError = $error;
    $invalidError['retry_after'] = 0;

    return array(
        'valid' => $valid,
        'invalid' => array(
            row('boot request rejects unknown fields', 'boot_request', $invalidBoot),
            row('attachment rejects unknown fields', 'chat_request', $invalidAttachment),
            row('chat message rejects over-limit text', 'chat_request', $invalidChat),
            row('boot response rejects unknown nested capability fields', 'boot_response', $invalidBootResponse),
            row('message requires reply_quote', 'turn_response', $invalidTurnPresentation),
            row('product rejects unknown fields', 'turn_response', $invalidTurnProduct),
            row('turn response requires cart_mutations', 'turn_response', $invalidTurnResponse),
            row('health response rejects diagnostic fields', 'health_response', $invalidHealth),
            row('privacy export rejects pending cart authority', 'conversation_export_response', $invalidExportState),
            row('privacy export rejects model question provenance', 'conversation_export_response', $invalidExportPayload),
            row('conversation delete must confirm deletion', 'conversation_delete_response', $invalidDelete),
            row('admin response rejects unknown readiness checks', 'admin_test_response', $invalidAdmin),
            row('error response rejects invalid retry_after', 'error_response', $invalidError),
        ),
    );
}

/** @param mixed $value @return array{name:string,schema:string,value:mixed} */
function row(string $name, string $schema, $value): array
{
    return array('name' => $name, 'schema' => $schema, 'value' => $value);
}

function encodedFixture(array $fixture): string
{
    $json = json_encode(
        $fixture,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if (!is_string($json)) {
        throw new RuntimeException('Unable to encode public-contract fixtures.');
    }
    return $json . "\n";
}

$check = in_array('--check', $argv, true);
$expected = encodedFixture(contractFixture());
if ($check) {
    $current = is_file(FIXTURE_PATH) ? file_get_contents(FIXTURE_PATH) : false;
    if (!is_string($current) || !hash_equals($expected, $current)) {
        fwrite(STDERR, "Generated public-contract fixtures are stale.\n");
        exit(1);
    }
    fwrite(STDOUT, "Generated public-contract fixtures are current.\n");
    exit(0);
}
if (file_put_contents(FIXTURE_PATH, $expected, LOCK_EX) !== strlen($expected)) {
    fwrite(STDERR, "Unable to write generated public-contract fixtures.\n");
    exit(1);
}
fwrite(STDOUT, "Generated public-contract fixtures updated.\n");
