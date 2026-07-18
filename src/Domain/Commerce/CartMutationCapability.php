<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

use InvalidArgumentException;

/** Immutable, customer-safe description of the current cart-write boundary. */
final class CartMutationCapability
{
    public const AVAILABLE = 'available';
    public const VERSION_NOT_PROMOTION_TESTED = 'version_not_promotion_tested';
    public const SESSION_HANDLER_UNSUPPORTED = 'session_handler_unsupported';
    public const REQUEST_FENCE_UNAVAILABLE = 'request_fence_unavailable';
    public const STORAGE_TOPOLOGY_UNSUPPORTED = 'storage_topology_unsupported';
    public const SESSION_RUNTIME_UNSUPPORTED = 'session_runtime_unsupported';
    public const SESSION_AUTHORITY_UNAVAILABLE = 'session_authority_unavailable';
    public const RUNTIME_UNAVAILABLE = 'runtime_unavailable';

    /** @var bool */ private $available;
    /** @var string */ private $code;
    /** @var string */ private $notice;
    public function __construct(
        bool $available,
        string $code,
        string $notice
    ) {
        $code = trim($code);
        $notice = trim($notice);
        $allowed = array(
            self::AVAILABLE,
            self::VERSION_NOT_PROMOTION_TESTED,
            self::SESSION_HANDLER_UNSUPPORTED,
            self::REQUEST_FENCE_UNAVAILABLE,
            self::STORAGE_TOPOLOGY_UNSUPPORTED,
            self::SESSION_RUNTIME_UNSUPPORTED,
            self::SESSION_AUTHORITY_UNAVAILABLE,
            self::RUNTIME_UNAVAILABLE,
        );
        if (
            !in_array($code, $allowed, true)
            || ($available && ($code !== self::AVAILABLE || $notice !== ''))
            || (!$available && ($code === self::AVAILABLE || $notice === ''))
            || strlen($notice) > 4096
        ) {
            throw new InvalidArgumentException('Cart mutation capability is invalid.');
        }
        $this->available = $available;
        $this->code = $code;
        $this->notice = $notice;
    }

    public function available(): bool
    {
        return $this->available;
    }
    public function code(): string
    {
        return $this->code;
    }
    public function notice(): string
    {
        return $this->notice;
    }

    /** @return array{available:bool,code:string,notice:string} */
    public function forClient(): array
    {
        return array('available' => $this->available, 'code' => $this->code, 'notice' => $this->notice);
    }

    /** @return array{available:bool,code:string} */
    public function forModel(): array
    {
        return array('available' => $this->available, 'code' => $this->code);
    }
}
