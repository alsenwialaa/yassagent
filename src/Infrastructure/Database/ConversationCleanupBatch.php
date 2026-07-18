<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use InvalidArgumentException;

/** Immutable outcome of one bounded expired-conversation cleanup transaction. */
final class ConversationCleanupBatch
{
    /** @var int */ private $conversationsDeleted;
    /** @var array<string,int> */ private $rowsDeleted;
    /** @var bool */ private $stoppedForDeadline;
    /** @var bool */ private $hasMore;

    /**
     * @param array<string,int> $rowsDeleted
     */
    public function __construct(
        int $selectedConversations,
        int $conversationsDeleted,
        array $rowsDeleted,
        bool $stoppedForDeadline,
        bool $hasMore
    ) {
        if (
            $selectedConversations < 0 || $conversationsDeleted < 0
            || $conversationsDeleted > $selectedConversations
        ) {
            throw new InvalidArgumentException('Conversation cleanup counts are invalid.');
        }
        foreach ($rowsDeleted as $name => $count) {
            if (!is_string($name) || $name === '' || !is_int($count) || $count < 0) {
                throw new InvalidArgumentException('Conversation cleanup row counts are invalid.');
            }
        }

        $this->conversationsDeleted = $conversationsDeleted;
        $this->rowsDeleted = $rowsDeleted;
        $this->stoppedForDeadline = $stoppedForDeadline;
        $this->hasMore = $hasMore;
    }

    public static function empty(): self
    {
        return new self(0, 0, array(), false, false);
    }

    public function conversationsDeleted(): int
    {
        return $this->conversationsDeleted;
    }

    public function totalRowsDeleted(): int
    {
        return array_sum($this->rowsDeleted) + $this->conversationsDeleted;
    }

    public function madeProgress(): bool
    {
        return $this->totalRowsDeleted() > 0;
    }

    public function stoppedForDeadline(): bool
    {
        return $this->stoppedForDeadline;
    }

    public function hasMore(): bool
    {
        return $this->hasMore;
    }
}
