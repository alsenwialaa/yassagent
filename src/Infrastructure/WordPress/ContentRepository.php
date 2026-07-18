<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WordPress;

use YassinStore\AiAssistant\Application\Port\ContentRepositoryPort;
use YassinStore\AiAssistant\Support\PublicHttpUrl;
use WP_Post;
use WP_Query;

final class ContentRepository implements ContentRepositoryPort
{
    /** @var Settings */
    private $settings;
    /** @var PublicContentVisibilityPolicy */
    private $visibility;

    public function __construct(Settings $settings, ?PublicContentVisibilityPolicy $visibility = null)
    {
        $this->settings = $settings;
        $this->visibility = $visibility !== null ? $visibility : new PublicContentVisibilityPolicy();
    }

    /**
     * @param array<string,mixed> $args
     * @return array<int,array<string,mixed>>
     */
    public function search(array $args): array
    {
        $type = (string) ($args['type'] ?? 'any');
        $postType = $type === 'any' ? array('page', 'post') : $type;
        $query = new WP_Query(array(
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => max(1, min(10, (int) ($args['limit'] ?? 5))),
            's' => sanitize_text_field((string) $args['query']),
            'no_found_rows' => true,
        ));

        $rows = array();
        foreach (is_array($query->posts) ? $query->posts : array() as $post) {
            if (!$post instanceof WP_Post) {
                continue;
            }
            if (!$this->visibility->allows($post)) {
                continue;
            }
            $row = $this->snapshot((int) $post->ID, false);
            if ($row !== array()) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    public function get(int $postId): array
    {
        $post = get_post($postId);
        if (!$this->visibility->allows($post)) {
            return array();
        }

        return $this->snapshot($postId, true);
    }

    /** @return array<string,mixed> */
    public function policy(string $section): array
    {
        $fieldMap = array(
            'shipping' => 'shipping_url',
            'returns' => 'returns_url',
            'terms' => 'terms_url',
            'contact' => 'contact_url',
            'about' => 'about_url',
        );
        $url = PublicHttpUrl::optional($this->settings->get($fieldMap[$section] ?? '', ''));
        if ($url === '') {
            return array('section' => $section, 'available' => false);
        }

        $postId = (int) url_to_postid($url);
        if ($postId > 0) {
            $content = $this->get($postId);
            if ($content !== array()) {
                $content['section'] = $section;
                $content['available'] = true;
                return $content;
            }
        }

        return array(
            'section' => $section,
            'available' => true,
            'url' => $url,
            'title' => ucfirst($section),
            'excerpt' => '',
            'content' => '',
        );
    }

    /** @return array<string,mixed> */
    public function storeInfo(): array
    {
        return array(
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'home_url' => PublicHttpUrl::optional(home_url('/')),
            'currency' => get_woocommerce_currency(),
            'contact_url' => PublicHttpUrl::optional($this->settings->get('contact_url', '')),
            'about_url' => PublicHttpUrl::optional($this->settings->get('about_url', '')),
            'shipping_url' => PublicHttpUrl::optional($this->settings->get('shipping_url', '')),
            'returns_url' => PublicHttpUrl::optional($this->settings->get('returns_url', '')),
            'terms_url' => PublicHttpUrl::optional($this->settings->get('terms_url', '')),
            'account_url' => PublicHttpUrl::optional($this->settings->get('account_url', '')),
        );
    }

    /** @return array<string,mixed> */
    private function snapshot(int $postId, bool $full): array
    {
        $post = get_post($postId);
        if (!$this->visibility->allows($post)) {
            return array();
        }

        $plain = wp_strip_all_tags(strip_shortcodes((string) $post->post_content));
        $plain = preg_replace('/\s+/u', ' ', $plain);
        $plain = is_string($plain) ? trim($plain) : '';

        $title = trim((string) get_the_title($postId));
        $url = (string) get_permalink($postId);
        if ($title === '' || !PublicHttpUrl::isSafe($url)) {
            return array();
        }

        return array(
            'id' => $postId,
            'type' => (string) $post->post_type,
            'title' => $title,
            'url' => $url,
            'excerpt' => wp_trim_words($plain, 55),
            'content' => $full ? wp_trim_words($plain, 500) : '',
            'modified_at' => get_post_modified_time('c', true, $postId),
        );
    }
}
