<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Chat;

use InvalidArgumentException;
use YassinStore\AiAssistant\Domain\Commerce\PendingCartIntent;
use YassinStore\AiAssistant\Domain\Shopping\ShoppingMemory;
use YassinStore\AiAssistant\Domain\Shopping\ShoppingMemoryPatch;
use YassinStore\AiAssistant\Support\Arr;

final class ConversationState
{
    private const SCHEMA = 5;

    /** @var array<int,array{id:int,name:string}> */ private $products;
    /** @var int */ private $updatedAt;
    /** @var string */ private $lastOutcome;
    /** @var ShoppingMemory */ private $shopping;
    /** @var PendingCartIntent|null */ private $pendingCartIntent;

    /** @param array<int,array{id:int,name:string}> $products */
    private function __construct(
        array $products,
        int $updatedAt,
        string $lastOutcome,
        ShoppingMemory $shopping,
        ?PendingCartIntent $pendingCartIntent
    ) {
        $this->products = $products;
        $this->updatedAt = $updatedAt;
        $this->lastOutcome = $lastOutcome;
        $this->shopping = $shopping;
        $this->pendingCartIntent = $pendingCartIntent;
    }

    public static function initial(): self
    {
        return new self(array(), 0, '', ShoppingMemory::initial(), null);
    }

    /** @param array<string,mixed> $state */
    public static function fromArray(array $state): self
    {
        self::assertKeys(
            $state,
            array('schema', 'continuity', 'shopping', 'last_outcome', 'pending_cart_intent'),
            'Conversation state'
        );
        if (
            !isset($state['schema']) || !is_int($state['schema']) || $state['schema'] !== self::SCHEMA
            || !is_array($state['continuity']) || Arr::isList($state['continuity'])
            || !is_array($state['shopping']) || Arr::isList($state['shopping'])
            || !is_string($state['last_outcome'])
            || ($state['pending_cart_intent'] !== null
                && (!is_array($state['pending_cart_intent']) || Arr::isList($state['pending_cart_intent'])))
        ) {
            throw new InvalidArgumentException('Conversation state schema is invalid.');
        }
        $continuity = $state['continuity'];
        self::assertKeys($continuity, array('products', 'updated_at'), 'Conversation continuity');
        if (
            !is_array($continuity['products']) || !Arr::isList($continuity['products'])
            || !is_int($continuity['updated_at']) || $continuity['updated_at'] < 0
            || count($continuity['products']) > 8
        ) {
            throw new InvalidArgumentException('Conversation continuity is invalid.');
        }

        $products = self::products($continuity['products'], 'Conversation product continuity');

        $lastOutcome = $state['last_outcome'];
        if ($lastOutcome !== '' && !in_array($lastOutcome, Outcome::all(), true)) {
            throw new InvalidArgumentException('Conversation last outcome is invalid.');
        }

        $pendingCartIntent = is_array($state['pending_cart_intent'])
            ? PendingCartIntent::fromArray($state['pending_cart_intent'])
            : null;

        return new self(
            $products,
            $continuity['updated_at'],
            $lastOutcome,
            ShoppingMemory::fromArray($state['shopping']),
            $pendingCartIntent
        );
    }

    public function after(AssistantResponse $response, int $now): self
    {
        $transition = $response->pendingCartTransition();
        $next = $transition === AssistantResponse::PENDING_PRESERVE
            ? $this->pendingCartIntent($now)
            : ($transition === AssistantResponse::PENDING_REPLACE
                ? $response->pendingCartIntent()
                : null);
        return $this->transition($response, $next, $now);
    }

    private function transition(
        AssistantResponse $response,
        ?PendingCartIntent $nextPendingCartIntent,
        int $now
    ): self {
        if ($now < 1) {
            throw new InvalidArgumentException('Conversation transition time is invalid.');
        }
        $shopping = $this->shopping;
        $topicReset = false;
        foreach ($response->shoppingMemoryPatches() as $patch) {
            $shopping = $shopping->apply($patch, $now);
            $topicReset = $topicReset || in_array(
                $patch->mode(),
                array(ShoppingMemoryPatch::CLEAR, ShoppingMemoryPatch::REPLACE_TOPIC),
                true
            );
        }

        $products = $response->continuityProducts();
        $continuityUpdatedAt = $this->updatedAt;
        if ($products !== array()) {
            $continuityUpdatedAt = $now;
        } elseif ($topicReset) {
            $products = array();
            $continuityUpdatedAt = $now;
        } else {
            $products = $this->products;
        }
        return new self(
            array_slice($products, 0, 8),
            $continuityUpdatedAt,
            $response->outcome(),
            $shopping,
            $nextPendingCartIntent
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $pending = $this->pendingCartIntent;
        return array(
            'schema' => self::SCHEMA,
            'continuity' => array(
                'products' => $this->products,
                'updated_at' => $this->updatedAt,
            ),
            'shopping' => $this->shopping->toArray(),
            'last_outcome' => $this->lastOutcome,
            'pending_cart_intent' => $pending !== null
                ? $pending->toArray()
                : null,
        );
    }

    /**
     * Privacy-safe durable context. Pending cart authority is represented only
     * as presence; its candidates, fingerprints, and model-call provenance are
     * never part of a customer export.
     *
     * @return array<string,mixed>
     */
    public function forPrivacy(): array
    {
        return array(
            'schema' => 1,
            'continuity' => array(
                'products' => $this->products,
                'updated_at' => $this->updatedAt,
            ),
            'shopping' => $this->shopping->toArray(),
            'last_outcome' => $this->lastOutcome,
            'has_pending_cart_intent' => $this->pendingCartIntent !== null,
        );
    }

    /** @return array<string,mixed> */
    public function forModel(int $now): array
    {
        if ($now < 1) {
            throw new InvalidArgumentException('Conversation projection time is invalid.');
        }
        $products = array();
        // Product continuity is deliberately short-lived. Old names may help
        // conversation flow, but must not silently anchor a new shopping topic.
        if ($this->updatedAt > 0 && $this->updatedAt >= $now - 1800) {
            foreach ($this->products as $row) {
                $products[] = array('name' => $row['name']);
            }
        }
        $pending = $this->pendingCartIntent($now);
        return array(
            'recent_products' => $products,
            'shopping' => $this->shopping->forModel($now),
            'last_outcome' => $this->lastOutcome,
            'pending_cart_intent' => $pending !== null ? $pending->forModel() : null,
        );
    }

    public function pendingCartIntent(int $now): ?PendingCartIntent
    {
        if ($now < 1) {
            throw new InvalidArgumentException('Conversation pending-cart time is invalid.');
        }
        return $this->pendingCartIntent !== null && $this->pendingCartIntent->isActive($now)
            ? $this->pendingCartIntent
            : null;
    }

    /** @param array<int,mixed> $rows @return array<int,array{id:int,name:string}> */
    private static function products(array $rows, string $context): array
    {
        $products = array();
        $seen = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException($context . ' is invalid.');
            }
            self::assertKeys($row, array('id', 'name'), $context);
            if (
                !is_int($row['id']) || $row['id'] < 1 || !is_string($row['name'])
                || trim($row['name']) === '' || trim($row['name']) !== $row['name']
                || isset($seen[$row['id']])
            ) {
                throw new InvalidArgumentException($context . ' is invalid.');
            }
            $seen[$row['id']] = true;
            $products[] = array('id' => $row['id'], 'name' => $row['name']);
        }
        return $products;
    }

    /** @param array<string,mixed> $row @param array<int,string> $expected */
    private static function assertKeys(array $row, array $expected, string $context): void
    {
        $actual = array_keys($row);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($context . ' contains missing or unsupported fields.');
        }
    }
}
