<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use YassinStore\AiAssistant\Domain\Commerce\CartCommand;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartPrimitive;
use YassinStore\AiAssistant\Domain\Commerce\CartSnapshot;
use YassinStore\AiAssistant\Domain\Exception\SafeCommerceException;

/** Maps the sole semantic command to one durable mutation boundary. */
final class CartStepPlanner
{
    /** @return array<int,CartPrimitive> */
    public function plan(CartPlan $plan, CartSnapshot $preState): array
    {
        $commands = $plan->commands();
        if (count($commands) !== 1) {
            throw new SafeCommerceException(
                'cart_plan_not_atomic',
                ('أرسل تغييراً واحداً للسلة في كل طلب.')
            );
        }

        $command = $commands[0];
        if ($command->type() === CartCommand::ADD) {
            return array(CartPrimitive::add(
                CartCommand::ADD,
                0,
                'single',
                $command->productId(),
                $command->variationId(),
                $command->quantity(),
                $command->expectedPurchaseFingerprint(),
                $command->displayName()
            ));
        }

        if ($command->type() === CartCommand::CLEAR) {
            return array(CartPrimitive::emptyCart(0));
        }

        $line = $preState->line($command->cartItemKey());
        if ($line === null || !hash_equals($line->fingerprint(), $command->expectedLineFingerprint())) {
            throw new SafeCommerceException(
                'cart_item_changed',
                ('تغير عنصر السلة قبل إعداد التنفيذ. اعرض السلة ثم أعد الطلب.')
            );
        }

        if ($command->type() === CartCommand::UPDATE) {
            return array(CartPrimitive::setQuantity(
                CartCommand::UPDATE,
                0,
                'single',
                $command->cartItemKey(),
                $command->expectedLineFingerprint(),
                $command->quantity(),
                $command->displayName()
            ));
        }

        if ($command->type() === CartCommand::REMOVE) {
            return array(CartPrimitive::removeLine(
                CartCommand::REMOVE,
                0,
                'single',
                $command->cartItemKey(),
                $command->expectedLineFingerprint(),
                $command->displayName()
            ));
        }

        if ($command->type() === CartCommand::REPLACE) {
            if (
                $line->productId() === $command->productId()
                && $line->variationId() === $command->variationId()
            ) {
                throw new SafeCommerceException(
                    'cart_replace_same_target',
                    ('العنصر البديل هو نفسه عنصر السلة. استخدم تغيير الكمية إذا أردت تعديل العدد.')
                );
            }
            return array(CartPrimitive::replaceLine(
                0,
                $command->cartItemKey(),
                $command->expectedLineFingerprint(),
                $command->productId(),
                $command->variationId(),
                $command->quantity(),
                $command->expectedPurchaseFingerprint(),
                $command->displayName()
            ));
        }

        throw new SafeCommerceException(
            'cart_command_not_supported',
            ('إجراء السلة المطلوب غير مدعوم.')
        );
    }
}
