<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Utf8;
use YassinStore\AiAssistant\Support\Uuid;

final class OperationRecord
{
    private const MAX_SAFE_MESSAGE_CODE_POINTS = 4096;
    private const MAX_SAFE_MESSAGE_BYTES = 16384;

    /** @var int */ private $id;
    /** @var string */ private $publicId;
    /** @var int */ private $conversationId;
    /** @var string */ private $turnId;
    /** @var string */ private $operationKey;
    /** @var int */ private $leaseFence;
    /** @var string */ private $status;
    /** @var CartPlan */ private $plan;
    /** @var CartSnapshot */ private $preState;
    /** @var AppliedCartPlan|null */ private $applied;
    /** @var CartSnapshot|null */ private $postState;
    /** @var ActionReceipt|null */ private $receipt;
    /** @var string */ private $failureCode;
    /** @var string */ private $safeMessage;
    /** @var string */ private $commerceResourceHash;
    /** @var int */ private $commerceFence;

    public function __construct(
        int $id,
        string $publicId,
        int $conversationId,
        string $turnId,
        string $operationKey,
        int $leaseFence,
        string $status,
        CartPlan $plan,
        CartSnapshot $preState,
        ?AppliedCartPlan $applied,
        ?CartSnapshot $postState,
        ?ActionReceipt $receipt,
        string $failureCode,
        string $safeMessage,
        string $commerceResourceHash,
        int $commerceFence
    ) {
        $publicId = strtolower(trim($publicId));
        $turnId = strtolower(trim($turnId));
        $operationKey = strtolower(trim($operationKey));
        $failureCode = trim($failureCode);
        $safeMessage = trim($safeMessage);
        $commerceResourceHash = strtolower(trim($commerceResourceHash));
        if (
            $id < 1 || $conversationId < 1 || !Uuid::isV4($publicId) || !Uuid::isV4($turnId)
            || preg_match('/^[a-f0-9]{64}$/', $operationKey) !== 1 || $leaseFence < 1
            || preg_match('/^[a-f0-9]{64}$/', $commerceResourceHash) !== 1 || $commerceFence < 1
            || !in_array($status, OperationStatus::all(), true)
        ) {
            throw new InvalidArgumentException('Durable cart operation identity is invalid.');
        }
        if ($failureCode !== '' && preg_match('/^[a-z0-9_]{1,64}$/', $failureCode) !== 1) {
            throw new InvalidArgumentException('Durable cart operation failure code is invalid.');
        }
        if (
            !Utf8::isBounded(
                $safeMessage,
                self::MAX_SAFE_MESSAGE_CODE_POINTS,
                self::MAX_SAFE_MESSAGE_BYTES
            )
        ) {
            throw new InvalidArgumentException('Durable cart operation safe message is invalid.');
        }

        $commandCount = count($plan->commands());
        if (!$plan->authorizesPreState($preState)) {
            throw new InvalidArgumentException(
                'Durable clear-cart authority does not match the recorded pre-state.'
            );
        }
        if ($applied !== null) {
            if ($applied->isEmpty() || $applied->count() !== $commandCount) {
                throw new InvalidArgumentException('Durable cart operation effects do not match the exact plan.');
            }
            foreach ($applied->effects() as $index => $effect) {
                if (($effect['type'] ?? '') !== $plan->commands()[$index]->type()) {
                    throw new InvalidArgumentException('Durable cart operation effect order contradicts its plan.');
                }
            }
        }

        if ($status === OperationStatus::PREPARED) {
            $this->assert($applied === null && $postState === null && $receipt === null
                && $failureCode === '' && $safeMessage === '', 'Prepared operation contains unsupported evidence.');
        } elseif ($status === OperationStatus::EXECUTING) {
            $this->assert(
                $postState === null && $receipt === null && $failureCode === '' && $safeMessage === '',
                'Executing operation contains terminal evidence.'
            );
        } elseif ($status === OperationStatus::VERIFIED) {
            if (
                $applied === null || $applied->count() !== $commandCount
                || $postState === null || $receipt === null || $failureCode !== ''
                || $safeMessage === '' || !hash_equals($safeMessage, $receipt->safeMessage())
            ) {
                throw new InvalidArgumentException(
                    'Verified operation evidence is incomplete or contradictory.'
                );
            }
            $this->assertVerifiedReceipt($plan, $preState, $postState, $receipt);
        } elseif ($status === OperationStatus::REJECTED) {
            $this->assert(
                $applied === null && $receipt === null && $failureCode !== '' && $safeMessage !== '',
                'Rejected operation evidence is incomplete or contradictory.'
            );
        } elseif ($status === OperationStatus::UNCERTAIN) {
            $this->assert(
                $receipt === null && $failureCode !== '' && $safeMessage !== '',
                'Failed operation evidence is incomplete or contradictory.'
            );
        }

        $this->id = $id;
        $this->publicId = $publicId;
        $this->conversationId = $conversationId;
        $this->turnId = $turnId;
        $this->operationKey = $operationKey;
        $this->leaseFence = $leaseFence;
        $this->status = $status;
        $this->plan = $plan;
        $this->preState = $preState;
        $this->applied = $applied;
        $this->postState = $postState;
        $this->receipt = $receipt;
        $this->failureCode = $failureCode;
        $this->safeMessage = $safeMessage;
        $this->commerceResourceHash = $commerceResourceHash;
        $this->commerceFence = $commerceFence;
    }

    public function id(): int
    {
        return $this->id;
    }
    public function publicId(): string
    {
        return $this->publicId;
    }
    public function conversationId(): int
    {
        return $this->conversationId;
    }
    public function turnId(): string
    {
        return $this->turnId;
    }
    public function operationKey(): string
    {
        return $this->operationKey;
    }
    public function leaseFence(): int
    {
        return $this->leaseFence;
    }
    public function status(): string
    {
        return $this->status;
    }
    public function plan(): CartPlan
    {
        return $this->plan;
    }
    public function preState(): CartSnapshot
    {
        return $this->preState;
    }
    public function applied(): ?AppliedCartPlan
    {
        return $this->applied;
    }
    public function postState(): ?CartSnapshot
    {
        return $this->postState;
    }
    public function receipt(): ?ActionReceipt
    {
        return $this->receipt;
    }
    public function failureCode(): string
    {
        return $this->failureCode;
    }
    public function safeMessage(): string
    {
        return $this->safeMessage;
    }
    public function commerceResourceHash(): string
    {
        return $this->commerceResourceHash;
    }
    public function commerceFence(): int
    {
        return $this->commerceFence;
    }
    public function isTerminal(): bool
    {
        return OperationStatus::isTerminal($this->status);
    }

    private function assertVerifiedReceipt(
        CartPlan $plan,
        CartSnapshot $pre,
        CartSnapshot $post,
        ActionReceipt $receipt
    ): void {
        $this->assert($receipt->action() === 'cart_apply', 'Verified operation receipt action is invalid.');
        $proof = $receipt->proof();
        $this->assert(
            hash_equals($pre->revision(), (string) $proof['before_revision'])
            && hash_equals($post->revision(), (string) $proof['after_revision'])
            && hash_equals($pre->restorationRevision(), (string) $proof['before_restoration_revision'])
            && hash_equals($post->restorationRevision(), (string) $proof['after_restoration_revision']),
            'Verified operation receipt revisions contradict its snapshots.'
        );
        $facts = $post->facts();
        $this->assert(
            (int) $proof['cart_count'] === (int) $facts['item_count']
            && (string) $proof['cart_total'] === (string) $facts['formatted_total']
            && (string) $proof['currency'] === (string) $facts['currency'],
            'Verified operation receipt facts contradict its post-state.'
        );

        $expectedCommands = array();
        foreach ($plan->commands() as $command) {
            $row = array('type' => $command->type());
            if ($command->displayName() !== '') {
                $row['item'] = $command->displayName();
            }
            if ($command->quantity() > 0) {
                $row['quantity'] = (int) $command->quantity();
            }
            $expectedCommands[] = $row;
        }
        $this->assert(
            hash_equals(Json::canonical($expectedCommands), Json::canonical($proof['commands'])),
            'Verified operation receipt commands contradict its plan.'
        );
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new InvalidArgumentException($message);
        }
    }
}
