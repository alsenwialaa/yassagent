<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WordPress;

/** Public customer-facing content must be viewable without credentials or a post password. */
final class PublicContentVisibilityPolicy
{
    public function allows($post): bool
    {
        if (!is_object($post) || !isset($post->ID, $post->post_status, $post->post_type)) {
            return false;
        }
        if (
            (string) $post->post_status !== 'publish'
            || !in_array((string) $post->post_type, array('page', 'post'), true)
            || trim((string) ($post->post_password ?? '')) !== ''
        ) {
            return false;
        }
        return is_post_publicly_viewable($post);
    }
}
