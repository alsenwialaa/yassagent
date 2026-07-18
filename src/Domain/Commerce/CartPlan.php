<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Arr;

final class CartPlan
{
    /** @var array<int,CartCommand> */ private $commands;
    /** @var string */ private $expectedCartRevision;

    /** @param array<int,CartCommand> $commands */
    public function __construct(array $commands, string $expectedCartRevision = '')
    {
        // First-release mutation authority is deliberately one semantic
        // command per durable operation. This removes partial-prefix success
        // states and makes every receipt prove one explicitly requested change.
        if (
            !Arr::isList($commands) || count($commands) !== 1
            || !$commands[0] instanceof CartCommand
        ) {
            throw new InvalidArgumentException('A cart plan must contain exactly one command.');
        }
        $expectedCartRevision = strtolower(trim($expectedCartRevision));
        $isClear = $commands[0]->type() === CartCommand::CLEAR;
        if (
            ($isClear && preg_match('/^[a-f0-9]{64}$/', $expectedCartRevision) !== 1)
            || (!$isClear && $expectedCartRevision !== '')
        ) {
            throw new InvalidArgumentException(
                $isClear
                    ? 'Clear cart requires the exact same-turn viewed cart revision.'
                    : 'A non-clear cart plan contains unsupported cart-view authority.'
            );
        }
        $this->commands = array_values($commands);
        $this->expectedCartRevision = $expectedCartRevision;
    }

    /** @return array<int,CartCommand> */ public function commands(): array
    {
        return $this->commands;
    }
    public function isClear(): bool
    {
        return count($this->commands) === 1 && $this->commands[0]->type() === CartCommand::CLEAR;
    }
    public function authorizesPreState(CartSnapshot $snapshot): bool
    {
        return !$this->isClear()
            || hash_equals($this->expectedCartRevision, $snapshot->revision());
    }
    /** @return array<string,mixed> */
    public function canonical(): array
    {
        return array(
            'commands' => array_map(static function (CartCommand $c): array {
                return $c->canonical();
            }, $this->commands),
            'expected_cart_revision' => $this->expectedCartRevision,
        );
    }
    /** @return array<string,mixed> */
    public function toStorageArray(): array
    {
        return array(
            'commands' => array_map(static function (CartCommand $c): array {
                return $c->toStorageArray();
            }, $this->commands),
            'expected_cart_revision' => $this->expectedCartRevision,
        );
    }

    /** @param array<string,mixed> $row */
    public static function fromStorageArray(array $row): self
    {
        $keys = array_keys($row);
        sort($keys, SORT_STRING);
        if (
            $keys !== array('commands', 'expected_cart_revision')
            || !is_array($row['commands']) || !Arr::isList($row['commands'])
            || !is_string($row['expected_cart_revision'])
        ) {
            throw new InvalidArgumentException('Stored cart plan is invalid.');
        }
        $commands = array();
        foreach ($row['commands'] as $command) {
            if (!is_array($command) || ($command !== array() && Arr::isList($command))) {
                throw new InvalidArgumentException('Stored cart plan command is invalid.');
            }
            $commands[] = CartCommand::fromStorageArray($command);
        }
        return new self($commands, $row['expected_cart_revision']);
    }
}
