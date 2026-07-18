<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Chat;

use InvalidArgumentException;
use YassinStore\AiAssistant\Domain\Commerce\ActionReceipt;
use YassinStore\AiAssistant\Domain\Commerce\PendingCartIntent;
use YassinStore\AiAssistant\Domain\Shopping\ShoppingMemoryPatch;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Utf8;
use YassinStore\AiAssistant\Support\Uuid;

final class AssistantResponse
{
    public const PENDING_CLEAR = 'clear';
    public const PENDING_PRESERVE = 'preserve';
    public const PENDING_REPLACE = 'replace';
    private const MAX_TEXT_CODE_POINTS = 4096;
    private const MAX_TEXT_BYTES = 16384;

    /** @var string */ private $id;
    /** @var string */ private $outcome;
    /** @var string */ private $text;
    /** @var ModelAuthoredQuestion|null */ private $modelQuestion;
    /** @var array<int,array<string,mixed>> */ private $products;
    /** @var array<int,ActionReceipt> */ private $receipts;
    /** @var PendingCartIntent|null */ private $pendingCartIntent;
    /** @var string */ private $pendingCartTransition;
    /** @var array<int,array{id:int,name:string}> */ private $continuityProducts;
    /** @var bool */ private $uncertain;
    /** @var string */ private $failureCode;
    /** @var array<int,ShoppingMemoryPatch> */ private $shoppingMemoryPatches;
    /** @var int */ private $createdAt;

    /**
     * @param array<int,array<string,mixed>>       $products
     * @param array<int,ActionReceipt>             $receipts
     * @param array<int,array{id:int,name:string}> $continuityProducts
     * @param array<int,ShoppingMemoryPatch>        $shoppingMemoryPatches
     */
    private function __construct(
        string $outcome,
        string $text,
        ?ModelAuthoredQuestion $modelQuestion,
        array $products,
        array $receipts,
        ?PendingCartIntent $pendingCartIntent,
        string $pendingCartTransition,
        array $continuityProducts,
        bool $uncertain,
        string $failureCode,
        array $shoppingMemoryPatches = array(),
        ?string $id = null,
        ?int $createdAt = null
    ) {
        $textIsValid = $text !== '' && trim($text) === $text && Utf8::isBounded(
            $text,
            self::MAX_TEXT_CODE_POINTS,
            self::MAX_TEXT_BYTES
        );
        if (!$textIsValid) {
            throw new InvalidArgumentException('Assistant response text is blank or too large.');
        }
        if (!in_array($outcome, Outcome::all(), true)) {
            throw new InvalidArgumentException('Assistant response outcome is invalid.');
        }
        if (!Arr::isList($products) || count($products) > 8) {
            throw new InvalidArgumentException('Assistant product projection must be a bounded list.');
        }
        foreach ($products as $product) {
            if (!is_array($product) || ($product !== array() && Arr::isList($product))) {
                throw new InvalidArgumentException('Assistant product projection is invalid.');
            }
        }
        foreach ($receipts as $receipt) {
            if (!$receipt instanceof ActionReceipt) {
                throw new InvalidArgumentException('Assistant receipt projection is invalid.');
            }
        }

        if (!Arr::isList($shoppingMemoryPatches) || count($shoppingMemoryPatches) > 4) {
            throw new InvalidArgumentException('Assistant shopping-memory transitions are invalid.');
        }
        foreach ($shoppingMemoryPatches as $patch) {
            if (!$patch instanceof ShoppingMemoryPatch) {
                throw new InvalidArgumentException('Assistant shopping-memory transition is invalid.');
            }
        }

        if ($outcome === Outcome::ACTION_VERIFIED) {
            if (count($receipts) !== 1 || !hash_equals($receipts[0]->safeMessage(), $text)) {
                throw new InvalidArgumentException('A verified action requires exactly one matching receipt.');
            }
        } elseif ($receipts !== array()) {
            throw new InvalidArgumentException('Only a verified action may expose receipts.');
        }

        if (
            !in_array($pendingCartTransition, array(
            self::PENDING_CLEAR, self::PENDING_PRESERVE, self::PENDING_REPLACE,
            ), true)
            || ($pendingCartTransition === self::PENDING_REPLACE && $pendingCartIntent === null)
            || ($pendingCartTransition !== self::PENDING_REPLACE && $pendingCartIntent !== null)
        ) {
            throw new InvalidArgumentException('Assistant pending-cart transition is invalid.');
        }

        if ($outcome === Outcome::FOLLOW_UP) {
            if (
                !$modelQuestion instanceof ModelAuthoredQuestion
                || !hash_equals($modelQuestion->text(), $text)
            ) {
                throw new InvalidArgumentException(
                    'A follow-up requires one matching model-authored question authority.'
                );
            }
            // Product cards may illustrate the question, but the shopper
            // always answers with typed natural language.
        } elseif ($modelQuestion !== null || $pendingCartIntent !== null) {
            throw new InvalidArgumentException(
                'Only a follow-up may carry model-question or pending-cart authority.'
            );
        }

        $failureCode = trim($failureCode);
        if ($outcome === Outcome::SAFE_FAILURE) {
            if ($failureCode === '') {
                $failureCode = 'safe_failure';
            }
            if (preg_match('/^[a-z0-9_]{1,64}$/', $failureCode) !== 1) {
                throw new InvalidArgumentException('Safe-failure code is invalid.');
            }
        } elseif ($failureCode !== '' || $uncertain) {
            throw new InvalidArgumentException('Only a safe failure may carry failure or uncertainty evidence.');
        }

        $responseId = $id !== null ? strtolower(trim($id)) : Uuid::v4();
        $timestamp = $createdAt !== null ? $createdAt : time();
        if (!Uuid::isV4($responseId) || $timestamp < 1 || $timestamp > time() + 300) {
            throw new InvalidArgumentException('Assistant response identity or timestamp is invalid.');
        }

        $this->id = $responseId;
        $this->outcome = $outcome;
        $this->text = $text;
        $this->modelQuestion = $modelQuestion;
        $this->products = array_values($products);
        $this->receipts = array_values($receipts);
        $this->pendingCartIntent = $pendingCartIntent;
        $this->pendingCartTransition = $pendingCartTransition;
        $this->continuityProducts = $this->normalizeContinuity($continuityProducts);
        $this->uncertain = $uncertain;
        $this->failureCode = $failureCode;
        $this->shoppingMemoryPatches = array_values($shoppingMemoryPatches);
        $this->createdAt = $timestamp;
    }

    /** @param array<int,array<string,mixed>> $products @param array<int,array{id:int,name:string}> $continuityProducts */
    public static function answer(
        string $text,
        array $products = array(),
        array $continuityProducts = array(),
        array $shoppingMemoryPatches = array()
    ): self {
        return new self(
            Outcome::ANSWER,
            $text,
            null,
            $products,
            array(),
            null,
            self::PENDING_CLEAR,
            $continuityProducts,
            false,
            '',
            $shoppingMemoryPatches
        );
    }

    /** @param array<int,array<string,mixed>> $products @param array<int,array{id:int,name:string}> $continuityProducts */
    public static function followUp(
        ModelAuthoredQuestion $question,
        array $products,
        ?PendingCartIntent $pendingCartIntent,
        array $continuityProducts = array(),
        array $shoppingMemoryPatches = array()
    ): self {
        return new self(
            Outcome::FOLLOW_UP,
            $question->text(),
            $question,
            $products,
            array(),
            $pendingCartIntent,
            $pendingCartIntent !== null ? self::PENDING_REPLACE : self::PENDING_CLEAR,
            $continuityProducts,
            false,
            '',
            $shoppingMemoryPatches
        );
    }

    /** @param array<int,ActionReceipt> $receipts */
    public static function verifiedAction(string $text, array $receipts, array $shoppingMemoryPatches = array()): self
    {
        return new self(
            Outcome::ACTION_VERIFIED,
            $text,
            null,
            array(),
            $receipts,
            null,
            self::PENDING_CLEAR,
            array(),
            false,
            '',
            $shoppingMemoryPatches
        );
    }

    public static function safeFailure(
        string $text,
        string $failureCode = '',
        bool $uncertain = false,
        array $shoppingMemoryPatches = array()
    ): self {
        if (trim($text) === '') {
            throw new InvalidArgumentException('Safe failure text cannot be blank.');
        }
        return new self(
            Outcome::SAFE_FAILURE,
            $text,
            null,
            array(),
            array(),
            null,
            self::PENDING_PRESERVE,
            array(),
            $uncertain,
            $failureCode,
            $shoppingMemoryPatches
        );
    }

    public function id(): string
    {
        return $this->id;
    }
    public function outcome(): string
    {
        return $this->outcome;
    }
    public function text(): string
    {
        return $this->text;
    }
    public function modelAuthoredQuestion(): ?ModelAuthoredQuestion
    {
        return $this->modelQuestion;
    }
    public function pendingCartIntent(): ?PendingCartIntent
    {
        return $this->pendingCartIntent;
    }
    public function pendingCartTransition(): string
    {
        return $this->pendingCartTransition;
    }
    /** @return array<int,array{id:int,name:string}> */ public function continuityProducts(): array
    {
        return $this->continuityProducts;
    }
    public function uncertain(): bool
    {
        return $this->uncertain;
    }
    public function failureCode(): string
    {
        return $this->failureCode;
    }
    /** @return array<int,ShoppingMemoryPatch> */ public function shoppingMemoryPatches(): array
    {
        return $this->shoppingMemoryPatches;
    }

    public function turnStatus(): string
    {
        if ($this->uncertain) {
            return TurnStatus::UNCERTAIN;
        }
        return $this->outcome === Outcome::SAFE_FAILURE ? TurnStatus::SAFE_FAILED : TurnStatus::COMPLETED;
    }

    /** @return array<string,mixed> */
    public function forClient(): array
    {
        $receipts = array();
        foreach ($this->receipts as $receipt) {
            $receipts[] = $receipt->forClient();
        }

        $message = array(
            'id' => $this->id,
            'role' => 'assistant',
            'outcome' => $this->outcome,
            'text' => $this->text,
            'products' => $this->products,
            'receipts' => $receipts,
            'presentation' => array(
                'image_scope' => 'none',
                'images' => array(),
                'reply_quote' => '',
            ),
            'created_at' => $this->createdAt,
        );
        if ($this->outcome === Outcome::SAFE_FAILURE) {
            $message['failure_code'] = $this->failureCode;
            $message['state_uncertain'] = $this->uncertain;
        }
        return $message;
    }

    /** @param array<int,array{id:int,name:string}> $rows @return array<int,array{id:int,name:string}> */
    private function normalizeContinuity(array $rows): array
    {
        if (!Arr::isList($rows) || count($rows) > 8) {
            throw new InvalidArgumentException('Assistant product continuity is invalid.');
        }
        $normalized = array();
        $seen = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Assistant product continuity row is invalid.');
            }
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            $id = isset($row['id']) && is_int($row['id']) ? $row['id'] : 0;
            $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : '';
            try {
                $validName = $name !== '' && Utf8::codePointLength($name) <= 500;
            } catch (InvalidArgumentException $exception) {
                $validName = false;
            }
            if (
                $keys !== array('id', 'name') || $id < 1 || $id > 9007199254740991
                || trim($name) !== $name || !$validName || isset($seen[$id])
            ) {
                throw new InvalidArgumentException('Assistant product continuity row is invalid.');
            }
            $seen[$id] = true;
            $normalized[] = array('id' => $id, 'name' => $name);
        }
        return $normalized;
    }
}
