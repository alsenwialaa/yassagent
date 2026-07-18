<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Commerce;

use InvalidArgumentException;

/** Closed result of the independent semantic cart-intent pass. */
final class CartIntentVerdict
{
    public const AUTHORIZED = 'authorized_current_request';
    public const NOT_A_REQUEST = 'not_a_request';
    public const NEGATED_OR_CONDITIONAL = 'negated_or_conditional';
    public const AMBIGUOUS_ACTION = 'ambiguous_action';
    public const AMBIGUOUS_TARGET = 'ambiguous_target';
    public const AMBIGUOUS_QUANTITY = 'ambiguous_quantity';
    public const PLAN_MISMATCH = 'plan_mismatch';
    public const CONTINUATION_MISMATCH = 'continuation_mismatch';
    public const MULTIPLE_ACTIONS_UNSUPPORTED = 'multiple_actions_unsupported';
    public const UNSAFE_OR_UNRESOLVED = 'unsafe_or_unresolved';

    /** @var bool */ private $authorized;
    /** @var string */ private $reason;

    private function __construct(bool $authorized, string $reason)
    {
        if (
            !in_array($reason, self::reasons(), true)
            || ($authorized && $reason !== self::AUTHORIZED)
            || (!$authorized && $reason === self::AUTHORIZED)
        ) {
            throw new InvalidArgumentException('Cart-intent verdict is contradictory.');
        }
        $this->authorized = $authorized;
        $this->reason = $reason;
    }

    public static function allow(): self
    {
        return new self(true, self::AUTHORIZED);
    }
    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
    public function authorized(): bool
    {
        return $this->authorized;
    }
    public function reason(): string
    {
        return $this->reason;
    }

    /** @return array<int,string> */
    public static function reasons(): array
    {
        return array(
            self::AUTHORIZED,
            self::NOT_A_REQUEST,
            self::NEGATED_OR_CONDITIONAL,
            self::AMBIGUOUS_ACTION,
            self::AMBIGUOUS_TARGET,
            self::AMBIGUOUS_QUANTITY,
            self::PLAN_MISMATCH,
            self::CONTINUATION_MISMATCH,
            self::MULTIPLE_ACTIONS_UNSUPPORTED,
            self::UNSAFE_OR_UNRESOLVED,
        );
    }
}
