<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Execution;

final class ExecutionBoundary
{
    public const PROVIDER_REQUEST = 'provider_request';
    public const TOOL_BATCH = 'tool_batch';
    public const CART_OPERATION = 'cart_operation';
    public const CART_STEP = 'cart_step';
    public const WOO_SESSION_SAVE = 'woo_session_save';
    public const RECONCILIATION = 'reconciliation';
    public const TERMINAL_COMMIT = 'terminal_commit';

    /** @return array<int,string> */
    public static function all(): array
    {
        return array(
            self::PROVIDER_REQUEST,
            self::TOOL_BATCH,
            self::CART_OPERATION,
            self::CART_STEP,
            self::WOO_SESSION_SAVE,
            self::RECONCILIATION,
            self::TERMINAL_COMMIT,
        );
    }

    public static function minimumBudget(string $boundary): float
    {
        switch ($boundary) {
            case self::PROVIDER_REQUEST:
                return 12.0;
            case self::CART_OPERATION:
                return 20.0;
            case self::CART_STEP:
                return 15.0;
            case self::WOO_SESSION_SAVE:
                return 10.0;
            case self::RECONCILIATION:
                return 15.0;
            case self::TERMINAL_COMMIT:
                return 8.0;
            case self::TOOL_BATCH:
                return 5.0;
            default:
                return 0.0;
        }
    }
}
