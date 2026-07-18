<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection;

/** Uses the same archive image-size authority as the active WooCommerce storefront. */
final class StorefrontImage
{
    public static function url(int $attachmentId): string
    {
        if ($attachmentId < 1) {
            return '';
        }

        $size = 'woocommerce_thumbnail';
        $filtered = apply_filters('single_product_archive_thumbnail_size', $size);
        if (is_string($filtered) && trim($filtered) !== '') {
            $size = $filtered;
        } elseif (
            is_array($filtered)
            && count($filtered) === 2
            && isset($filtered[0], $filtered[1])
            && is_numeric($filtered[0])
            && is_numeric($filtered[1])
            && (int) $filtered[0] > 0
            && (int) $filtered[1] > 0
        ) {
            $size = array((int) $filtered[0], (int) $filtered[1]);
        }

        $url = wp_get_attachment_image_url($attachmentId, $size);
        if ((!is_string($url) || $url === '') && $size !== 'woocommerce_thumbnail') {
            $url = wp_get_attachment_image_url($attachmentId, 'woocommerce_thumbnail');
        }

        return is_string($url) ? $url : '';
    }
}
