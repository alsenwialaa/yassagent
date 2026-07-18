<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use YassinStore\AiAssistant\Domain\Commerce\CartQuantity;
use RuntimeException;
use YassinStore\AiAssistant\Domain\Commerce\CartPrimitive;
use YassinStore\AiAssistant\Domain\Commerce\CartSnapshot;
use YassinStore\AiAssistant\Support\Json;

/** Verifies one exact authority delta and seals the complete observed post-state. */
final class CartStepVerifier
{
    /** @var CartLineAuthorityPolicy */ private $authority;

    public function __construct(?CartLineAuthorityPolicy $authority = null)
    {
        $this->authority = $authority ?: new CartLineAuthorityPolicy();
    }

    /** @param array<string,mixed> $draft @return array<string,mixed> */
    public function seal(
        CartPrimitive $primitive,
        CartSnapshot $pre,
        CartSnapshot $post,
        array $draft
    ): array {
        $this->assertDraftIdentity($primitive, $draft);
        $expectedLines = $this->lines($pre);
        $postLines = $this->lines($post);
        $expectedCoupons = $pre->coupons();
        $beforeFingerprint = '';
        $postFingerprint = '';

        if ($primitive->type() === CartPrimitive::ADD) {
            $key = (string) ($draft['cart_item_key'] ?? '');
            $actual = $post->line($key);
            $previous = $pre->line($key);
            if (
                $key === '' || $actual === null
                || $actual->productId() !== $primitive->productId()
                || $actual->variationId() !== $primitive->variationId()
            ) {
                throw new RuntimeException('The added line identity does not match its primitive.');
            }
            $previousQuantity = $previous !== null ? $previous->quantity() : 0.0;
            if (
                !CartQuantity::equals((float) ($draft['previous_quantity'] ?? -1), $previousQuantity)
                || !CartQuantity::equals($actual->quantity(), $previousQuantity + $primitive->quantity())
            ) {
                throw new RuntimeException('The added line quantity does not match its primitive.');
            }
            if ($previous !== null) {
                $beforeFingerprint = $previous->fingerprint();
                $before = $previous->authorityArray();
                $after = $actual->authorityArray();
                if (!$this->authority->stableIdentityMatches($before, $after)) {
                    throw new RuntimeException('An existing add target identity changed.');
                }
            }
            $postFingerprint = $actual->fingerprint();
            // The exact new line is sealed here. Recovery must compare this
            // fingerprint and the complete post snapshot; it may not adopt a
            // later live line as expected evidence.
            $expectedLines[$key] = $actual->authorityArray();
        } elseif ($primitive->type() === CartPrimitive::SET_QUANTITY) {
            $key = $primitive->cartItemKey();
            $before = $pre->line($key);
            $after = $post->line($key);
            if (
                $before === null || $after === null
                || !hash_equals($before->fingerprint(), $primitive->expectedLineFingerprint())
                || !CartQuantity::equals($after->quantity(), $primitive->quantity())
            ) {
                throw new RuntimeException('The quantity target does not match its primitive.');
            }
            $beforeAuthority = $before->authorityArray();
            $afterAuthority = $after->authorityArray();
            if (!$this->authority->stableIdentityMatches($beforeAuthority, $afterAuthority)) {
                throw new RuntimeException('The quantity target identity changed.');
            }
            $beforeFingerprint = $before->fingerprint();
            $postFingerprint = $after->fingerprint();
            $expectedLines[$key] = $after->authorityArray();
        } elseif ($primitive->type() === CartPrimitive::REMOVE_LINE) {
            $key = $primitive->cartItemKey();
            $before = $pre->line($key);
            if (
                $before === null || $post->line($key) !== null
                || !hash_equals($before->fingerprint(), $primitive->expectedLineFingerprint())
            ) {
                throw new RuntimeException('The removed line does not match its primitive.');
            }
            $beforeFingerprint = $before->fingerprint();
            unset($expectedLines[$key]);
        } elseif ($primitive->type() === CartPrimitive::REPLACE_LINE) {
            $sourceKey = $primitive->cartItemKey();
            $targetKey = (string) ($draft['target_cart_item_key'] ?? '');
            $source = $pre->line($sourceKey);
            $targetBefore = $pre->line($targetKey);
            $targetAfter = $post->line($targetKey);
            $targetPreviousQuantity = $targetBefore !== null
                ? $targetBefore->quantity() : 0.0;
            if (
                $targetKey === '' || hash_equals($sourceKey, $targetKey)
                || $source === null || $post->line($sourceKey) !== null
                || $targetAfter === null
                || !hash_equals($source->fingerprint(), $primitive->expectedLineFingerprint())
                || (string) ($draft['source_cart_item_key'] ?? '') !== $sourceKey
                || !CartQuantity::equals(
                    (float) ($draft['source_previous_quantity'] ?? -1),
                    $source->quantity()
                )
                || !CartQuantity::equals(
                    (float) ($draft['target_previous_quantity'] ?? -1),
                    $targetPreviousQuantity
                )
                || $targetAfter->productId() !== $primitive->productId()
                || $targetAfter->variationId() !== $primitive->variationId()
                || !CartQuantity::equals(
                    $targetAfter->quantity(),
                    $targetPreviousQuantity + $primitive->quantity()
                )
            ) {
                throw new RuntimeException('The replacement delta does not match its primitive.');
            }
            if (
                $targetBefore !== null && !$this->authority->stableIdentityMatches(
                    $targetBefore->authorityArray(),
                    $targetAfter->authorityArray()
                )
            ) {
                throw new RuntimeException('An existing replacement target identity changed.');
            }
            $beforeFingerprint = $source->fingerprint();
            $postFingerprint = $targetAfter->fingerprint();
            unset($expectedLines[$sourceKey]);
            $expectedLines[$targetKey] = $targetAfter->authorityArray();
        } elseif ($primitive->type() === CartPrimitive::EMPTY_CART) {
            if (
                (int) ($draft['previous_line_count'] ?? -1) !== count($expectedLines)
                || (int) ($draft['previous_coupon_count'] ?? -1) !== count($expectedCoupons)
                || $postLines !== array()
                || $post->coupons() !== array()
            ) {
                throw new RuntimeException('The canonical empty-cart result does not match its primitive.');
            }
            $expectedLines = array();
            $expectedCoupons = array();
        } else {
            throw new RuntimeException('Unsupported cart primitive verification.');
        }

        if (
            !$this->same($expectedLines, $postLines)
            || !$this->same($expectedCoupons, $post->coupons())
        ) {
            throw new RuntimeException('The primitive caused an unexpected cart authority delta.');
        }

        return $draft + array(
            'before_line_fingerprint' => $beforeFingerprint,
            'post_line_fingerprint' => $postFingerprint,
            'pre_revision' => $pre->revision(),
            'post_revision' => $post->revision(),
            'pre_restoration_revision' => $pre->restorationRevision(),
            'post_restoration_revision' => $post->restorationRevision(),
        );
    }

    /** @param array<string,mixed> $sealed */
    public function assertSealed(
        CartPrimitive $primitive,
        CartSnapshot $pre,
        CartSnapshot $post,
        array $sealed
    ): void {
        $draft = $sealed;
        foreach (
            array(
            'before_line_fingerprint', 'post_line_fingerprint', 'pre_revision', 'post_revision',
            'pre_restoration_revision', 'post_restoration_revision',
            ) as $key
        ) {
            unset($draft[$key]);
        }
        $expected = $this->seal($primitive, $pre, $post, $draft);
        if (!$this->same($expected, $sealed)) {
            throw new RuntimeException('Stored cart step effect does not match its exact delta.');
        }
    }

    /** @param array<string,mixed> $draft */
    private function assertDraftIdentity(CartPrimitive $primitive, array $draft): void
    {
        if (
            ($draft['primitive_type'] ?? null) !== $primitive->type()
            || ($draft['semantic_type'] ?? null) !== $primitive->semanticType()
            || ($draft['phase'] ?? null) !== $primitive->phase()
            || ($draft['command_index'] ?? null) !== $primitive->commandIndex()
        ) {
            throw new RuntimeException('Cart primitive effect identity is invalid.');
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function lines(CartSnapshot $snapshot): array
    {
        $authority = $snapshot->authorityArray();
        return is_array($authority['lines'] ?? null) ? $authority['lines'] : array();
    }

    /** @param mixed $left @param mixed $right */
    private function same($left, $right): bool
    {
        return hash_equals(Json::canonical($left), Json::canonical($right));
    }
}
