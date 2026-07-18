<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest;

use WP_REST_Request;
use YassinStore\AiAssistant\Application\Turn\TurnRequest;
use YassinStore\AiAssistant\Application\Contract\PublicApiContract;
use YassinStore\AiAssistant\Domain\Exception\InvalidRequest;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\BrowserContinuitySecret;
use YassinStore\AiAssistant\Support\Utf8;
use YassinStore\AiAssistant\Support\Uuid;

final class RequestDecoder
{
    /** @var Settings */ private $settings;
    /** @var PublicApiContract */ private $contract;
    /** @var ImageAttachmentDecoder */ private $images;

    public function __construct(
        Settings $settings,
        PublicApiContract $contract,
        ImageAttachmentDecoder $images
    ) {
        $this->settings = $settings;
        $this->contract = $contract;
        $this->images = $images;
    }

    /** @return array{client_instance_id:string,browser_continuity_secret:string,previous_browser_continuity_secret:string,conversation_id:string,conversation_token:string,pending_turn_id:string} */
    public function boot(WP_REST_Request $request): array
    {
        $body = $this->jsonObject($request);
        $this->assertOnlyFields($body, $this->contract->bootFields());

        if (
            (isset($body['client_instance_id']) && !is_string($body['client_instance_id']))
            || (isset($body['browser_continuity_secret']) && !is_string($body['browser_continuity_secret']))
            || (isset($body['previous_browser_continuity_secret']) && !is_string($body['previous_browser_continuity_secret']))
            || (isset($body['conversation_id']) && !is_string($body['conversation_id']))
            || (isset($body['conversation_token']) && !is_string($body['conversation_token']))
            || (isset($body['pending_turn_id']) && !is_string($body['pending_turn_id']))
        ) {
            throw new InvalidRequest(
                'boot_contract_invalid',
                ('بيانات استئناف المحادثة غير صالحة.')
            );
        }

        $clientInstanceId = (string) ($body['client_instance_id'] ?? '');
        $browserContinuitySecret = (string) ($body['browser_continuity_secret'] ?? '');
        $previousBrowserContinuitySecret = (string) ($body['previous_browser_continuity_secret'] ?? '');
        $conversationId = (string) ($body['conversation_id'] ?? '');
        $conversationToken = (string) ($body['conversation_token'] ?? '');
        $pendingTurnId = (string) ($body['pending_turn_id'] ?? '');
        if (
            trim($clientInstanceId) !== $clientInstanceId
            || trim($browserContinuitySecret) !== $browserContinuitySecret
            || trim($previousBrowserContinuitySecret) !== $previousBrowserContinuitySecret
            || trim($conversationId) !== $conversationId
            || trim($conversationToken) !== $conversationToken
            || trim($pendingTurnId) !== $pendingTurnId
        ) {
            throw new InvalidRequest(
                'boot_contract_invalid',
                ('بيانات استئناف المحادثة غير صالحة.')
            );
        }
        if (!Uuid::isV4($clientInstanceId)) {
            throw new InvalidRequest(
                'boot_client_identity_invalid',
                ('تعذر التحقق من هوية تشغيل المتصفح.')
            );
        }
        if (!BrowserContinuitySecret::isValid($browserContinuitySecret)) {
            throw new InvalidRequest(
                'boot_continuity_credential_invalid',
                ('تعذر التحقق من استمرارية جلسة المتصفح.')
            );
        }
        if (
            $previousBrowserContinuitySecret !== ''
            && (!BrowserContinuitySecret::isValid($previousBrowserContinuitySecret)
                || hash_equals($browserContinuitySecret, $previousBrowserContinuitySecret))
        ) {
            throw new InvalidRequest(
                'boot_continuity_rotation_invalid',
                ('تعذر التحقق من تدوير استمرارية جلسة المتصفح.')
            );
        }
        if (($conversationId === '') !== ($conversationToken === '')) {
            throw new InvalidRequest(
                'boot_credentials_incomplete',
                ('بيانات استئناف المحادثة غير مكتملة.')
            );
        }

        if (
            ($pendingTurnId !== '' && $conversationId === '')
            || ($pendingTurnId !== '' && !Uuid::isV4($pendingTurnId))
        ) {
            throw new InvalidRequest(
                'boot_contract_invalid',
                ('بيانات الطلب المعلّق غير صالحة.')
            );
        }

        if (
            $conversationId !== ''
            && (!Uuid::isV4($conversationId)
                || strlen($conversationToken) < $this->contract->conversationTokenMinLength()
                || strlen($conversationToken) > $this->contract->conversationTokenMaxLength()
                || preg_match('/^[A-Za-z0-9_-]+$/', $conversationToken) !== 1)
        ) {
            throw new InvalidRequest(
                'boot_contract_invalid',
                ('بيانات استئناف المحادثة غير صالحة.')
            );
        }

        return array(
            'client_instance_id' => strtolower($clientInstanceId),
            'browser_continuity_secret' => $browserContinuitySecret,
            'previous_browser_continuity_secret' => $previousBrowserContinuitySecret,
            'conversation_id' => $conversationId,
            'conversation_token' => $conversationToken,
            'pending_turn_id' => strtolower($pendingTurnId),
        );
    }

    /**
     * Performs the closed, scalar-only chat contract before any assistant
     * schema inspection. Raw attachment rows remain encoded at this stage.
     *
     * @return array{
     *   conversation_id:string,
     *   conversation_token:string,
     *   client_turn_id:string,
     *   message:string,
     *   reply_context:string,
     *   reply_message_id:string,
     *   reply_product_index:int|null,
     *   attachments:array<int,mixed>
     * }
     */
    public function chatEnvelope(WP_REST_Request $request): array
    {
        $body = $this->jsonObject($request);
        $this->assertOnlyFields($body, $this->contract->chatFields());

        $conversationId = isset($body['conversation_id']) && is_string($body['conversation_id'])
            ? $body['conversation_id']
            : '';
        $conversationToken = isset($body['conversation_token']) && is_string($body['conversation_token'])
            ? $body['conversation_token']
            : '';
        $turnId = isset($body['client_turn_id']) && is_string($body['client_turn_id'])
            ? $body['client_turn_id']
            : '';
        if (
            trim($conversationId) !== $conversationId
            || trim($conversationToken) !== $conversationToken
            || trim($turnId) !== $turnId
        ) {
            throw new InvalidRequest(
                'conversation_contract_invalid',
                ('بيانات المحادثة غير صالحة. أعد فتح المساعد.')
            );
        }
        if (
            !Uuid::isV4($conversationId)
            || strlen($conversationToken) < $this->contract->conversationTokenMinLength()
            || strlen($conversationToken) > $this->contract->conversationTokenMaxLength()
            || preg_match('/^[A-Za-z0-9_-]+$/', $conversationToken) !== 1
            || !Uuid::isV4($turnId)
        ) {
            throw new InvalidRequest(
                'conversation_contract_invalid',
                ('بيانات المحادثة غير صالحة. أعد فتح المساعد.')
            );
        }

        if (isset($body['message']) && !is_string($body['message'])) {
            throw new InvalidRequest(
                'message_type_invalid',
                ('نص الرسالة غير صالح.')
            );
        }
        $message = isset($body['message']) ? (string) $body['message'] : '';
        if (!Utf8::isPlainText($message)) {
            throw new InvalidRequest(
                'message_text_invalid',
                ('نص الرسالة غير صالح.')
            );
        }
        $length = Utf8::codePointLength($message);
        if ($length > $this->contract->messageMaxChars()) {
            throw new InvalidRequest(
                'message_too_long',
                ('الرسالة أطول من الحد المسموح.')
            );
        }

        if (isset($body['attachments']) && !is_array($body['attachments'])) {
            throw new InvalidRequest(
                'attachments_type_invalid',
                ('بيانات الصور غير صالحة.')
            );
        }

        $replyContext = '';
        $replyMessageId = '';
        $replyProductIndex = null;
        if (array_key_exists('reply_context', $body)) {
            $reply = $body['reply_context'];
            if (!is_array($reply) || Arr::isList($reply)) {
                throw new InvalidRequest(
                    'reply_context_invalid',
                    ('بيانات الرد على الرسالة غير صالحة.')
                );
            }
            $keys = array_keys($reply);
            sort($keys, SORT_STRING);
            $plain = $keys === array('text');
            $product = $keys === array('message_id', 'product_index', 'text');
            if ((!$plain && !$product) || !is_string($reply['text'])) {
                throw new InvalidRequest(
                    'reply_context_invalid',
                    ('بيانات الرد على الرسالة غير صالحة.')
                );
            }
            if ($product) {
                if (
                    !is_string($reply['message_id'])
                    || !Uuid::isV4(strtolower($reply['message_id']))
                    || !is_int($reply['product_index'])
                    || $reply['product_index'] < 0
                    || $reply['product_index'] > 7
                ) {
                    throw new InvalidRequest(
                        'reply_context_invalid',
                        ('بيانات الرد على الرسالة غير صالحة.')
                    );
                }
                $replyMessageId = strtolower($reply['message_id']);
                $replyProductIndex = $reply['product_index'];
            }
            $replyContext = $reply['text'];
            if (
                Utf8::isWhitespaceOnly($replyContext)
                || !Utf8::isPlainText($replyContext)
                || Utf8::codePointLength($replyContext) > $this->contract->replyContextMaxChars()
            ) {
                throw new InvalidRequest(
                    'reply_context_invalid',
                    ('بيانات الرد على الرسالة غير صالحة.')
                );
            }
        }

        return array(
            'conversation_id' => $conversationId,
            'conversation_token' => $conversationToken,
            'client_turn_id' => $turnId,
            'message' => $message,
            'reply_context' => $replyContext,
            'reply_message_id' => $replyMessageId,
            'reply_product_index' => $replyProductIndex,
            'attachments' => is_array($body['attachments'] ?? null)
                ? $body['attachments']
                : array(),
        );
    }

    /**
     * Materializes bounded image authority only after the exact schema gate has
     * authorized the assistant request.
     *
     * @param array{
     *   conversation_id:string,
     *   conversation_token:string,
     *   client_turn_id:string,
     *   message:string,
     *   reply_context:string,
     *   reply_message_id:string,
     *   reply_product_index:int|null,
     *   attachments:array<int,mixed>
     * } $envelope
     */
    public function chatFromEnvelope(array $envelope): TurnRequest
    {
        $attachments = $this->images->decode(
            $envelope['attachments'],
            (bool) $this->settings->get('allow_images', 1)
        );

        if (Utf8::isWhitespaceOnly($envelope['message']) && $attachments === array()) {
            throw new InvalidRequest(
                'turn_empty',
                ('اكتب رسالة أو أرفق صورة.')
            );
        }

        return new TurnRequest(
            $envelope['conversation_id'],
            $envelope['conversation_token'],
            $envelope['client_turn_id'],
            $envelope['message'],
            $attachments,
            $envelope['reply_context'],
            $envelope['reply_message_id'],
            $envelope['reply_product_index']
        );
    }

    /** @return array<string,mixed> */
    private function jsonObject(WP_REST_Request $request): array
    {
        $rawBody = (string) $request->get_body();
        if (strlen($rawBody) > $this->contract->maxBodyBytes()) {
            throw new InvalidRequest(
                'request_too_large',
                ('حجم الطلب أكبر من المسموح.'),
                'The actual REST request body exceeded the public contract limit.',
                413
            );
        }
        $raw = trim($rawBody);
        $body = $request->get_json_params();
        if (!is_array($body)) {
            if ($raw !== '') {
                // WP_REST_Request exposes get_json_params(), but its JSON
                // parser is protected and it has no public JSON-error accessor.
                // WordPress normally rejects malformed JSON before the route
                // callback; this bounded fallback preserves the plugin's exact
                // error envelope when the decoder is invoked directly.
                json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new InvalidRequest(
                        'json_invalid',
                        ('بيانات الطلب ليست JSON صالحاً.')
                    );
                }
                throw new InvalidRequest(
                    'json_object_required',
                    ('يجب أن يكون الطلب كائن JSON.')
                );
            }
            return array();
        }
        // PHP represents decoded {} and [] as the same empty array. The raw
        // leading token is therefore part of the formal transport contract
        // whenever a body was supplied.
        if ($raw !== '' && substr($raw, 0, 1) !== '{') {
            throw new InvalidRequest(
                'json_object_required',
                ('يجب أن يكون الطلب كائن JSON.')
            );
        }
        if ($body !== array() && Arr::isList($body)) {
            throw new InvalidRequest(
                'json_object_required',
                ('يجب أن يكون الطلب كائن JSON.')
            );
        }
        // WordPress has already materialized the parsed JSON. Release the raw
        // request copy before image inspection; WordPress 6.9 is the
        // first-release floor and provides this request API.
        $request->set_body('');
        return $body;
    }

    /** @param array<string,mixed> $body @param array<int,string> $allowed */
    private function assertOnlyFields(array $body, array $allowed): void
    {
        foreach (array_keys($body) as $field) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                throw new InvalidRequest(
                    'request_field_unknown',
                    ('يحتوي الطلب على حقل غير مدعوم.'),
                    'Unsupported request field: ' . (string) $field
                );
            }
        }
    }
}
