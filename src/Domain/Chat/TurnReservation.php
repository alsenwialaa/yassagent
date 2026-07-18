<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Chat;

final class TurnReservation
{
    /** @var TurnRecord */ private $turn;
    /** @var bool */ private $created;

    public function __construct(TurnRecord $turn, bool $created)
    {
        $this->turn = $turn;
        $this->created = $created;
    }

    public function turn(): TurnRecord
    {
        return $this->turn;
    }
    public function created(): bool
    {
        return $this->created;
    }
}
