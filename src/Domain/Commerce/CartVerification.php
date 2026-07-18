<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

final class CartVerification
{
    /** @var bool */ private $verified;
    /** @var bool */ private $changed;
    /** @var string */ private $reason;

    private function __construct(bool $verified, bool $changed, string $reason)
    {
        $this->verified = $verified;
        $this->changed = $changed;
        $this->reason = $reason;
    }

    public static function verified(bool $changed): self
    {
        return new self(true, $changed, '');
    }

    public static function rejected(string $reason): self
    {
        return new self(false, false, $reason);
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }
    public function changed(): bool
    {
        return $this->changed;
    }
    public function reason(): string
    {
        return $this->reason;
    }
}
