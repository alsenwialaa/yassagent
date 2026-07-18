<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Shopping;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Utf8;

/** Durable, typed shopping context. It is never commerce execution authority. */
final class ShoppingMemory
{
    private const ACTIVE_TTL_SECONDS = 604800; // Seven days.
    /** @var int */ private $revision;
    /** @var string */ private $goal;
    /** @var string */ private $stage;
    /** @var array<int,array{key:string,value:string,priority:string,polarity:string}> */ private $constraints;
    /** @var array<int,array{id:int,name:string}> */ private $comparedProducts;
    /** @var string */ private $unresolvedQuestion;
    /** @var int */ private $updatedAt;

    /**
     * @param array<int,array{key:string,value:string,priority:string,polarity:string}> $constraints
     * @param array<int,array{id:int,name:string}> $comparedProducts
     */
    private function __construct(
        int $revision,
        string $goal,
        string $stage,
        array $constraints,
        array $comparedProducts,
        string $unresolvedQuestion,
        int $updatedAt
    ) {
        $this->revision = $revision;
        $this->goal = $goal;
        $this->stage = $stage;
        $this->constraints = $constraints;
        $this->comparedProducts = $comparedProducts;
        $this->unresolvedQuestion = $unresolvedQuestion;
        $this->updatedAt = $updatedAt;
    }

    public static function initial(): self
    {
        return new self(0, '', '', array(), array(), '', 0);
    }

    /** @param array<string,mixed> $state */
    public static function fromArray(array $state): self
    {
        if ($state !== array() && Arr::isList($state)) {
            throw new InvalidArgumentException('Shopping memory must be an object.');
        }
        $keys = array_keys($state);
        sort($keys, SORT_STRING);
        if ($keys !== array('compared_products', 'constraints', 'goal', 'revision', 'stage', 'unresolved_question', 'updated_at')) {
            throw new InvalidArgumentException('Shopping memory fields are invalid.');
        }
        $revision = isset($state['revision']) && is_int($state['revision']) ? $state['revision'] : -1;
        $updatedAt = isset($state['updated_at']) && is_int($state['updated_at']) ? $state['updated_at'] : -1;
        $goal = isset($state['goal']) && is_string($state['goal']) ? trim($state['goal']) : '';
        $stage = isset($state['stage']) && is_string($state['stage']) ? $state['stage'] : '';
        $question = isset($state['unresolved_question']) && is_string($state['unresolved_question'])
            ? trim($state['unresolved_question']) : '';
        if (
            $revision < 0 || $updatedAt < 0 || self::length($goal) > 320 || self::length($question) > 320
            || !in_array($stage, array('', 'discovering', 'comparing', 'configuring', 'deciding', 'cart'), true)
        ) {
            throw new InvalidArgumentException('Shopping memory scalar fields are invalid.');
        }
        $patchPayload = array(
            'mode' => ShoppingMemoryPatch::REPLACE_TOPIC,
            'constraints' => $state['constraints'] ?? null,
            'compared_products' => $state['compared_products'] ?? null,
            'unresolved_question' => $question,
        );
        if ($goal !== '') {
            $patchPayload['goal'] = $goal;
        }
        if ($stage !== '') {
            $patchPayload['stage'] = $stage;
        }
        $validated = new ShoppingMemoryPatch($patchPayload);
        $constraints = (array) $validated->value('constraints');
        $products = (array) $validated->value('compared_products');
        return new self($revision, $goal, $stage, $constraints, $products, $question, $updatedAt);
    }

    public function apply(ShoppingMemoryPatch $patch, int $now): self
    {
        if ($now < 1) {
            throw new InvalidArgumentException('Shopping-memory transition time is invalid.');
        }
        if ($patch->mode() === ShoppingMemoryPatch::CLEAR) {
            return new self($this->revision + 1, '', '', array(), array(), '', $now);
        }

        $stale = $this->revision > 0 && $this->updatedAt > 0
            && $this->updatedAt < $now - self::ACTIVE_TTL_SECONDS;
        $replace = $patch->mode() === ShoppingMemoryPatch::REPLACE_TOPIC || $stale;
        $goal = $replace ? '' : $this->goal;
        $stage = $replace ? '' : $this->stage;
        $constraints = $replace ? array() : $this->constraints;
        $products = $replace ? array() : $this->comparedProducts;
        $question = $replace ? '' : $this->unresolvedQuestion;

        if ($patch->has('goal')) {
            $goal = (string) $patch->value('goal');
        }
        if ($patch->has('stage')) {
            $stage = (string) $patch->value('stage');
        }
        if ($patch->has('remove_constraint_keys')) {
            $remove = array_fill_keys((array) $patch->value('remove_constraint_keys'), true);
            $constraints = array_values(array_filter($constraints, static function (array $row) use ($remove): bool {
                return !isset($remove[$row['key']]);
            }));
        }
        if ($patch->has('constraints')) {
            $incoming = (array) $patch->value('constraints');
            if ($replace) {
                $constraints = $incoming;
            } else {
                $indexed = array();
                foreach ($constraints as $row) {
                    $indexed[$row['key'] . '|' . $row['polarity']] = $row;
                }
                foreach ($incoming as $row) {
                    $oppositePolarity = $row['polarity'] === 'include' ? 'exclude' : 'include';
                    $oppositeIdentity = $row['key'] . '|' . $oppositePolarity;
                    if (
                        isset($indexed[$oppositeIdentity])
                        && self::constraintValueIdentity($indexed[$oppositeIdentity]['value'])
                            === self::constraintValueIdentity($row['value'])
                    ) {
                        // The newest customer-grounded statement supersedes an
                        // exact opposite remembered statement. This prevents
                        // durable memory from becoming self-contradictory when
                        // a correction arrives in a later model round/turn.
                        unset($indexed[$oppositeIdentity]);
                    }
                    $indexed[$row['key'] . '|' . $row['polarity']] = $row;
                }
                $constraints = array_slice(array_values($indexed), -16);
            }
        }
        if ($patch->has('compared_products')) {
            $products = (array) $patch->value('compared_products');
        }
        if ($patch->has('unresolved_question')) {
            $question = (string) $patch->value('unresolved_question');
        }

        return new self($this->revision + 1, $goal, $stage, $constraints, $products, $question, $now);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array(
            'revision' => $this->revision,
            'goal' => $this->goal,
            'stage' => $this->stage,
            'constraints' => $this->constraints,
            'compared_products' => $this->comparedProducts,
            'unresolved_question' => $this->unresolvedQuestion,
            'updated_at' => $this->updatedAt,
        );
    }

    /** @return array<string,mixed> */
    public function forModel(int $now): array
    {
        if ($now < 1) {
            throw new InvalidArgumentException('Shopping-memory projection time is invalid.');
        }
        if (
            $this->revision > 0 && $this->updatedAt > 0
            && $this->updatedAt < $now - self::ACTIVE_TTL_SECONDS
        ) {
            return self::initial()->forModel($now);
        }
        $products = array();
        foreach ($this->comparedProducts as $row) {
            $products[] = array('name' => $row['name']);
        }
        return array(
            'revision' => $this->revision,
            'goal' => $this->goal,
            'stage' => $this->stage,
            'constraints' => $this->constraints,
            'compared_products' => $products,
            'unresolved_question' => $this->unresolvedQuestion,
            'updated_at' => $this->updatedAt,
        );
    }

    private static function length(string $value): int
    {
        return Utf8::codePointLength($value);
    }

    private static function constraintValueIdentity(string $value): string
    {
        $value = function_exists('mb_strtolower')
            ? mb_strtolower(trim($value), 'UTF-8')
            : strtolower(trim($value));
        $collapsed = preg_replace('/\s+/u', ' ', $value);
        return is_string($collapsed) ? trim($collapsed) : $value;
    }
}
