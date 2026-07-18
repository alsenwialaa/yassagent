<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use YassinStore\AiAssistant\Domain\Commerce\CartQuantity;
use RuntimeException;
use YassinStore\AiAssistant\Domain\Commerce\ActionReceipt;
use YassinStore\AiAssistant\Domain\Commerce\CartCommand;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartSnapshot;
use YassinStore\AiAssistant\Support\TrustedCommerceText;
use YassinStore\AiAssistant\Support\Utf8;

final class ReceiptPresenter
{
    private const MAX_MESSAGE_LABEL_CODE_POINTS = 48;
    private const MAX_MESSAGE_TOTAL_CODE_POINTS = 24;

    /** @var CartDeltaVerifier */ private $verifier;

    public function __construct(CartDeltaVerifier $verifier)
    {
        $this->verifier = $verifier;
    }

    public function create(CartPlan $plan, CartSnapshot $pre, CartSnapshot $post, bool $changed): ActionReceipt
    {
        $planCommands = $plan->commands();
        if (count($planCommands) !== 1 || !$planCommands[0] instanceof CartCommand) {
            throw new RuntimeException('A verified receipt requires exactly one semantic cart command.');
        }
        $command = $planCommands[0];
        $displayName = trim(TrustedCommerceText::decodeEntities($command->displayName()));
        // Cart totals already passed through PlainMoneyFormatter. They are
        // canonical display text and must survive receipt persistence exactly.
        $formattedTotal = trim((string) ($post->facts()['formatted_total'] ?? ''));
        $row = array('type' => $command->type());
        if ($displayName !== '') {
            $row['item'] = $displayName;
        }
        if ($command->quantity() > 0) {
            $row['quantity'] = (int) $command->quantity();
        }
        $commands = array($row);

        $facts = $post->facts();
        $proof = array(
            'commands' => $commands,
            'cart_count' => (int) ($facts['item_count'] ?? 0),
            'cart_total' => $formattedTotal,
            'currency' => (string) ($facts['currency'] ?? ''),
            'before_revision' => $pre->revision(),
            'after_revision' => $post->revision(),
            'before_restoration_revision' => $pre->restorationRevision(),
            'after_restoration_revision' => $post->restorationRevision(),
            'changed_line_count' => $this->verifier->changedLineCount($pre, $post),
        );

        return new ActionReceipt(
            'cart_apply',
            $changed,
            $proof,
            $this->message($command, $displayName, $formattedTotal, $changed)
        );
    }

    private function message(
        CartCommand $command,
        string $displayName,
        string $formattedTotal,
        bool $changed
    ): string {
        $total = $this->messageTotal($formattedTotal);
        if (!$changed) {
            return ('السلة مطابقة للحالة المطلوبة بالفعل.');
        }
        $name = $this->messageLabel($displayName, 'العنصر');
        if ($command->type() === CartCommand::ADD) {
            return (sprintf('تمت إضافة %s بكمية %s. إجمالي السلة الآن %s.', $name, $this->quantity($command->quantity()), $total));
        }
        if ($command->type() === CartCommand::UPDATE) {
            return (sprintf('تم تحديث كمية %s إلى %s. إجمالي السلة الآن %s.', $name, $this->quantity($command->quantity()), $total));
        }
        if ($command->type() === CartCommand::REMOVE) {
            return (sprintf('تمت إزالة %s من السلة. إجمالي السلة الآن %s.', $name, $total));
        }
        if ($command->type() === CartCommand::REPLACE) {
            return (sprintf(
                'تم استبدال عنصر السلة بـ %s بكمية %s. إجمالي السلة الآن %s.',
                $name,
                $this->quantity($command->quantity()),
                $total
            ));
        }
        return ('تم إفراغ السلة والتحقق منها.');
    }

    private function messageLabel(string $label, string $fallback): string
    {
        $label = trim($label);
        if ($label === '') {
            return $fallback;
        }
        $collapsed = preg_replace('/\s+/u', ' ', $label);
        if (!is_string($collapsed)) {
            throw new RuntimeException('Receipt product label is not valid UTF-8.');
        }
        if (Utf8::codePointLength($collapsed) <= self::MAX_MESSAGE_LABEL_CODE_POINTS) {
            return $collapsed;
        }
        // Product names remain complete in the structured receipt proof. Only
        // the immutable human sentence is bounded so one adversarially long
        // Latin-heavy catalog label cannot invalidate Arabic terminal text.
        return Utf8::truncate($collapsed, self::MAX_MESSAGE_LABEL_CODE_POINTS - 1) . '…';
    }

    private function messageTotal(string $total): string
    {
        $total = trim($total);
        if ($total === '') {
            return '—';
        }
        $collapsed = preg_replace('/\s+/u', ' ', $total);
        if (!is_string($collapsed)) {
            throw new RuntimeException('Receipt cart total is not valid UTF-8.');
        }
        if (Utf8::codePointLength($collapsed) <= self::MAX_MESSAGE_TOTAL_CODE_POINTS) {
            return $collapsed;
        }
        return Utf8::truncate($collapsed, self::MAX_MESSAGE_TOTAL_CODE_POINTS - 1) . '…';
    }

    private function quantity(float $quantity): string
    {
        if (!CartQuantity::isPositiveInteger($quantity)) {
            throw new RuntimeException('Verified receipt quantity is not a supported whole number.');
        }
        return (string) (int) $quantity;
    }
}
