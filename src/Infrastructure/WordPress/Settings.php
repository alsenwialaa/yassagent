<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WordPress;

use YassinStore\AiAssistant\Application\Port\RuntimeSettingsPort;
use YassinStore\AiAssistant\Infrastructure\Security\IpNetwork;
use YassinStore\AiAssistant\Support\Utf8;
use YassinStore\AiAssistant\Support\PublicHttpUrl;

final class Settings implements RuntimeSettingsPort
{
    public const OPTION_KEY = 'ysai_options';
    public const GEMINI_MODEL = 'gemini-3.5-flash';

    /**
     * The production system prompt, bounded state, capability evidence, and
     * bounded live capability evidence retain the rest of ModelRequest's
     * 128 KiB system-instruction envelope.
     */
    public const STORE_GUIDANCE_MAX_BYTES = 32768;

    /** @var array<string,int> Unicode code-point limits shared with the widget response contract. */
    public const WIDGET_TEXT_LIMITS = array(
        'widget_button_text' => 300,
        'widget_title' => 300,
        'widget_subtitle' => 500,
        'empty_state_hint' => 500,
    );

    /** @var array<string,mixed>|null */
    private $cache;

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return array(
            'enabled' => 1,
            'gemini_api_key' => '',
            'gemini_thinking_level' => 'low',
            // Hidden monotonic epoch prevents an old A→B→A provider proof from reviving.
            'runtime_configuration_epoch' => 0,
            'max_output_tokens' => 2048,
            'http_timeout_seconds' => 30,
            'max_tool_rounds' => 6,
            'allow_images' => 1,
            'store_guidance' => '',
            'widget_enabled' => 1,
            'widget_auto_insert' => 1,
            'widget_position' => 'right',
            'widget_button_text' => 'مساعدة ياسين',
            'widget_title' => 'مساعدة متجر ياسين',
            'widget_subtitle' => 'اسأل عن المنتجات والأسعار والسلة وسياسات المتجر',
            'empty_state_hint' => 'اكتب ما تبحث عنه أو اطلب مساعدة في اختيار منتج.',

            'widget_brand_color' => '#380000',
            'widget_header_background_color' => '#380000',
            'widget_header_foreground_color' => '#fffaf5',
            'widget_chat_background' => '#f8f5f5',
            'widget_surface_color' => '#ffffff',
            'widget_assistant_bubble_color' => '#ffffff',
            'widget_user_bubble_color' => '#380000',
            'widget_user_text_color' => '#ffffff',
            'widget_text_color' => '#24191b',
            'widget_muted_color' => '#75696c',
            'widget_border_color' => '#e8dfe1',
            'widget_panel_width' => 420,
            'widget_panel_height' => 700,
            'widget_panel_radius' => 26,
            'widget_bubble_radius' => 20,
            'widget_product_card_radius' => 17,
            'widget_font_size' => 14,
            'widget_product_layout' => 'carousel',
            'widget_product_cards_per_view' => 1,
            'widget_product_image_ratio' => '1-1',
            'widget_product_show_description' => 1,

            'rate_limit_turns' => 40,
            'trusted_proxy_cidrs' => '',
            'rate_window_seconds' => 600,
            'daily_ai_turn_limit' => 1200,
            'conversation_retention_days' => 45,
            'diagnostic_logging' => 0,
            'delete_data_on_uninstall' => 0,

            'contact_url' => 'https://yassin-store.com/contact/',
            'about_url' => '',
            'shipping_url' => '',
            'returns_url' => '',
            'terms_url' => '',
            'account_url' => 'https://yassin-store.com/my-account/',
        );
    }

    /** @return mixed */
    public function get(string $key, $default = null)
    {
        $all = $this->all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $stored = get_option(self::OPTION_KEY, array());
        $defaults = self::defaults();
        $known = is_array($stored) ? array_intersect_key($stored, $defaults) : array();
        $merged = array_replace($defaults, $known);
        $epoch = $merged['runtime_configuration_epoch'] ?? 0;
        $merged['runtime_configuration_epoch'] = is_int($epoch) && $epoch >= 0
            ? $epoch
            : 0;
        $thinkingLevel = is_string($merged['gemini_thinking_level'] ?? null)
            ? (string) $merged['gemini_thinking_level']
            : '';
        $merged['gemini_thinking_level'] = self::validThinkingLevel($thinkingLevel)
            ? $thinkingLevel
            : (string) $defaults['gemini_thinking_level'];
        foreach (self::WIDGET_TEXT_LIMITS as $field => $limit) {
            $merged[$field] = self::widgetText(
                $field,
                $merged[$field] ?? '',
                (string) $defaults[$field]
            );
        }
        $guidance = is_string($merged['store_guidance'] ?? null)
            ? (string) $merged['store_guidance']
            : '';
        $merged['store_guidance'] = self::validStoreGuidance($guidance)
            ? $guidance
            : '';
        foreach (self::urlFields() as $field) {
            $merged[$field] = PublicHttpUrl::optional($merged[$field] ?? '');
        }
        $this->cache = $merged;

        return $this->cache;
    }

    public function refresh(): void
    {
        $this->cache = null;
    }

    public function apiKey(): string
    {
        if (defined('YSAI_GEMINI_API_KEY') && is_string(YSAI_GEMINI_API_KEY)) {
            return trim(YSAI_GEMINI_API_KEY);
        }

        return trim((string) $this->get('gemini_api_key', ''));
    }

    public function runtimeConfigurationEpoch(): int
    {
        $epoch = $this->get('runtime_configuration_epoch', 0);
        return is_int($epoch) && $epoch >= 0 ? $epoch : 0;
    }

    /**
     * Settings API sanitizer. It never clears a stored API key when the masked
     * admin field is submitted blank.
     *
     * @param mixed $input
     * @return array<string,mixed>
     */
    public function sanitize($input): array
    {
        $input = is_array($input) ? $input : array();
        $current = $this->all();
        $defaults = self::defaults();

        $out = $defaults;
        $out['enabled'] = isset($input['enabled']) ? 1 : 0;
        $out['widget_enabled'] = isset($input['widget_enabled']) ? 1 : 0;
        $out['widget_auto_insert'] = isset($input['widget_auto_insert']) ? 1 : 0;
        $out['widget_product_show_description'] = isset($input['widget_product_show_description']) ? 1 : 0;
        $out['allow_images'] = isset($input['allow_images']) ? 1 : 0;
        $out['diagnostic_logging'] = isset($input['diagnostic_logging']) ? 1 : 0;
        $out['delete_data_on_uninstall'] = isset($input['delete_data_on_uninstall']) ? 1 : 0;

        $submittedKey = isset($input['gemini_api_key']) ? trim((string) $input['gemini_api_key']) : '';
        $clearStoredKey = isset($input['clear_gemini_api_key']);
        $out['gemini_api_key'] = $clearStoredKey
            ? ''
            : ($submittedKey !== ''
                ? sanitize_text_field($submittedKey)
                : (string) ($current['gemini_api_key'] ?? ''));

        $currentThinkingLevel = is_string($current['gemini_thinking_level'] ?? null)
            && self::validThinkingLevel((string) $current['gemini_thinking_level'])
            ? (string) $current['gemini_thinking_level']
            : (string) $defaults['gemini_thinking_level'];
        $thinkingLevel = isset($input['gemini_thinking_level'])
            ? sanitize_key((string) $input['gemini_thinking_level'])
            : $currentThinkingLevel;
        $out['gemini_thinking_level'] = self::validThinkingLevel($thinkingLevel)
            ? $thinkingLevel
            : $currentThinkingLevel;

        $out['max_output_tokens'] = $this->boundedInt($input, 'max_output_tokens', 256, 8192, (int) $defaults['max_output_tokens']);
        $out['http_timeout_seconds'] = $this->boundedInt($input, 'http_timeout_seconds', 10, 90, (int) $defaults['http_timeout_seconds']);
        $out['max_tool_rounds'] = $this->boundedInt($input, 'max_tool_rounds', 3, 10, (int) $defaults['max_tool_rounds']);
        $out['rate_limit_turns'] = $this->boundedInt($input, 'rate_limit_turns', 5, 500, (int) $defaults['rate_limit_turns']);
        $out['rate_window_seconds'] = $this->boundedInt($input, 'rate_window_seconds', 60, 86400, (int) $defaults['rate_window_seconds']);
        $out['daily_ai_turn_limit'] = $this->boundedInt($input, 'daily_ai_turn_limit', 10, 100000, (int) $defaults['daily_ai_turn_limit']);
        $out['conversation_retention_days'] = $this->boundedInt($input, 'conversation_retention_days', 1, 3650, (int) $defaults['conversation_retention_days']);
        $out['trusted_proxy_cidrs'] = $this->trustedProxyCidrs($input);
        $out['widget_panel_width'] = $this->boundedInt($input, 'widget_panel_width', 340, 560, (int) $defaults['widget_panel_width']);
        $out['widget_panel_height'] = $this->boundedInt($input, 'widget_panel_height', 520, 860, (int) $defaults['widget_panel_height']);
        $out['widget_panel_radius'] = $this->boundedInt($input, 'widget_panel_radius', 12, 36, (int) $defaults['widget_panel_radius']);
        $out['widget_bubble_radius'] = $this->boundedInt($input, 'widget_bubble_radius', 10, 28, (int) $defaults['widget_bubble_radius']);
        $out['widget_product_card_radius'] = $this->boundedInt($input, 'widget_product_card_radius', 8, 32, (int) $defaults['widget_product_card_radius']);
        $out['widget_font_size'] = $this->boundedInt($input, 'widget_font_size', 13, 18, (int) $defaults['widget_font_size']);
        $out['widget_product_cards_per_view'] = $this->boundedInt($input, 'widget_product_cards_per_view', 1, 3, (int) $defaults['widget_product_cards_per_view']);

        $guidance = isset($input['store_guidance'])
            ? (string) $input['store_guidance']
            : '';
        if (!self::validStoreGuidance($guidance)) {
            $this->settingsError(
                'ysai_store_guidance_invalid',
                __('لم يتم حفظ إرشادات المتجر لأنها ليست نص UTF-8 صالحاً أو تحتوي محارف تحكم أو تتجاوز حد الحجم الآمن.', 'yassin-ai-assistant')
            );
            $currentGuidance = is_string($current['store_guidance'] ?? null)
                ? (string) $current['store_guidance']
                : '';
            $guidance = self::validStoreGuidance($currentGuidance) ? $currentGuidance : '';
        }
        $out['store_guidance'] = $guidance;

        foreach (self::WIDGET_TEXT_LIMITS as $field => $limit) {
            $value = isset($input[$field]) ? (string) $input[$field] : (string) $defaults[$field];
            if (!self::validWidgetText($field, $value)) {
                $this->settingsError(
                    'ysai_' . $field . '_invalid',
                    __('لم يتم حفظ أحد نصوص الواجهة لأنه ليس نص UTF-8 صالحاً أو يحتوي محارف تحكم أو يتجاوز الحد المسموح.', 'yassin-ai-assistant')
                );
                $value = self::widgetText($field, $current[$field] ?? '', (string) $defaults[$field]);
            }
            $out[$field] = self::widgetText($field, $value, (string) $defaults[$field]);
        }

        $position = isset($input['widget_position']) ? sanitize_key((string) $input['widget_position']) : 'right';
        $out['widget_position'] = in_array($position, array('left', 'right'), true) ? $position : 'right';

        $layout = isset($input['widget_product_layout']) ? sanitize_key((string) $input['widget_product_layout']) : '';
        $out['widget_product_layout'] = in_array($layout, array('list', 'grid', 'carousel'), true)
            ? $layout
            : (string) $defaults['widget_product_layout'];

        $ratio = isset($input['widget_product_image_ratio']) ? sanitize_key((string) $input['widget_product_image_ratio']) : '';
        $out['widget_product_image_ratio'] = in_array($ratio, array('1-1', '4-3', '3-4', '16-9'), true)
            ? $ratio
            : (string) $defaults['widget_product_image_ratio'];

        foreach (
            array(
            'widget_brand_color',
            'widget_header_background_color',
            'widget_header_foreground_color',
            'widget_chat_background',
            'widget_surface_color',
            'widget_assistant_bubble_color',
            'widget_user_bubble_color',
            'widget_user_text_color',
            'widget_text_color',
            'widget_muted_color',
            'widget_border_color',
            ) as $field
        ) {
            $color = isset($input[$field]) ? sanitize_hex_color((string) $input[$field]) : false;
            $out[$field] = $color !== false ? $color : (string) $defaults[$field];
        }

        foreach (self::urlFields() as $field) {
            $candidate = isset($input[$field]) ? (string) $input[$field] : (string) $defaults[$field];
            $out[$field] = PublicHttpUrl::optional($candidate);
        }

        $currentEpoch = is_int($current['runtime_configuration_epoch'] ?? null)
            && (int) $current['runtime_configuration_epoch'] >= 0
            ? (int) $current['runtime_configuration_epoch']
            : 0;
        $providerConfigurationChanged = !hash_equals(
            (string) ($current['gemini_api_key'] ?? ''),
            (string) $out['gemini_api_key']
        ) || !hash_equals($currentThinkingLevel, (string) $out['gemini_thinking_level']);
        $out['runtime_configuration_epoch'] = $providerConfigurationChanged
            ? self::nextRuntimeConfigurationEpoch($currentEpoch)
            : $currentEpoch;

        $this->cache = $out;
        return $out;
    }

    /** @param array<string,mixed> $input */
    private function trustedProxyCidrs(array $input): string
    {
        $raw = isset($input['trusted_proxy_cidrs']) ? (string) $input['trusted_proxy_cidrs'] : '';
        if (strlen($raw) > 4096) {
            $raw = substr($raw, 0, 4096);
        }
        $parts = preg_split('/[\s,]+/', trim($raw));
        $valid = array();
        $invalid = 0;
        foreach (is_array($parts) ? $parts : array() as $part) {
            if ($part === '') {
                continue;
            }
            $cidr = IpNetwork::canonicalCidr((string) $part);
            if ($cidr === '') {
                $invalid++;
                continue;
            }
            $valid[$cidr] = true;
            if (count($valid) >= 64) {
                break;
            }
        }
        if ($invalid > 0) {
            add_settings_error(
                self::OPTION_KEY,
                'ysai_trusted_proxy_cidrs_invalid',
                sprintf(
                    __('تم تجاهل %d من عناوين الوكيل الموثوق أو نطاقات CIDR غير الصالحة.', 'yassin-ai-assistant'),
                    $invalid
                ),
                'warning'
            );
        }
        return implode("\n", array_keys($valid));
    }

    /** @param array<string,mixed> $input */
    private function boundedInt(array $input, string $key, int $min, int $max, int $default): int
    {
        if (!isset($input[$key]) || !is_numeric($input[$key])) {
            return $default;
        }

        return max($min, min($max, (int) $input[$key]));
    }

    /** @param mixed $value */
    public static function widgetText(string $field, $value, string $fallback = ''): string
    {
        if (is_string($value) && self::validWidgetText($field, $value)) {
            return $value;
        }
        if (self::validWidgetText($field, $fallback)) {
            return $fallback;
        }
        return '';
    }

    private static function validStoreGuidance(string $value): bool
    {
        return strlen($value) <= self::STORE_GUIDANCE_MAX_BYTES
            && Utf8::isPlainText($value);
    }


    private static function validThinkingLevel(string $level): bool
    {
        return in_array($level, array('minimal', 'low', 'medium', 'high'), true);
    }

    private static function nextRuntimeConfigurationEpoch(int $current): int
    {
        return $current >= PHP_INT_MAX ? PHP_INT_MAX : $current + 1;
    }

    /** @return array<int,string> */
    private static function urlFields(): array
    {
        return array('contact_url', 'about_url', 'shipping_url', 'returns_url', 'terms_url', 'account_url');
    }

    private static function validWidgetText(string $field, string $value): bool
    {
        $limit = self::WIDGET_TEXT_LIMITS[$field] ?? 0;
        return $limit > 0
            && Utf8::isPlainText($value)
            && !Utf8::isWhitespaceOnly($value)
            && Utf8::isBounded($value, $limit, $limit * 4);
    }

    private function settingsError(string $code, string $message): void
    {
        add_settings_error(self::OPTION_KEY, $code, $message, 'error');
    }
}
