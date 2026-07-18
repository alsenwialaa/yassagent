<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

use InvalidArgumentException;
use YassinStore\AiAssistant\Domain\Chat\ModelAuthoredQuestion;
use YassinStore\AiAssistant\Domain\Chat\StoredModelQuestionEvidence;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Utf8;
use YassinStore\AiAssistant\Support\Uuid;

/**
 * Durable, server-owned authority for exactly one unresolved cart-intent field.
 *
 * This is not customer approval and is never exposed as an executable token.
 * A later turn must still resolve a fresh live opaque WooCommerce reference
 * whose stable identity equals the target stored here.
 */
final class PendingCartIntent
{
    public const MISSING_VARIATION = 'variation';
    public const MISSING_QUANTITY = 'quantity';
    public const MISSING_TARGET = 'target';

    /** @var string */ private $id;
    /** @var string */ private $action;
    /** @var array<string,mixed> */ private $target;
    /** @var int */ private $quantity;
    /** @var string */ private $missing;
    /** @var string */ private $label;
    /** @var ModelAuthoredQuestion */ private $question;
    /** @var int */ private $issuedAt;
    /** @var int */ private $expiresAt;

    /** @param array<string,mixed> $target */
    public function __construct(
        string $id,
        string $action,
        array $target,
        int $quantity,
        string $missing,
        string $label,
        ModelAuthoredQuestion $question,
        int $issuedAt,
        int $expiresAt
    ) {
        $id = strtolower(trim($id));
        $label = trim($label);
        if (
            !Uuid::isV4($id)
            || !in_array($missing, array(
                self::MISSING_VARIATION, self::MISSING_QUANTITY, self::MISSING_TARGET,
            ), true)
            || $issuedAt < 1 || $expiresAt <= $issuedAt || $expiresAt > $issuedAt + 1200
            || $label === '' || !Utf8::isPlainText($label) || !Utf8::isBounded($label, 500, 2000)
            || !in_array($question->purpose(), array(
                ModelAuthoredQuestion::PURPOSE_CART_CONTINUATION,
                ModelAuthoredQuestion::PURPOSE_CART_CONTINUATION_RETRY,
            ), true)
        ) {
            throw new InvalidArgumentException('Pending cart intent envelope is invalid.');
        }

        if ($missing === self::MISSING_VARIATION) {
            $isAdd = $action === CartCommand::ADD;
            $isReplace = $action === CartCommand::REPLACE;
            self::assertKeys($target, $isReplace
                ? array(
                    'bound_attributes', 'kind', 'missing_attributes', 'product_id',
                    'product_fingerprint', 'variation_axes_fingerprint',
                    'variation_catalog_epoch',
                    'quantity_mode', 'source_cart_item_key', 'source_label',
                    'source_line_fingerprint',
                )
                : array(
                    'bound_attributes', 'kind', 'missing_attributes', 'product_id',
                    'product_fingerprint', 'variation_axes_fingerprint',
                    'variation_catalog_epoch',
                ));
            $productFingerprint = is_string($target['product_fingerprint'] ?? null)
                ? strtolower(trim($target['product_fingerprint'])) : '';
            $axesFingerprint = is_string($target['variation_axes_fingerprint'] ?? null)
                ? strtolower(trim($target['variation_axes_fingerprint'])) : '';
            $catalogEpoch = is_string($target['variation_catalog_epoch'] ?? null)
                ? strtolower(trim($target['variation_catalog_epoch'])) : '';
            if (
                (!$isAdd && !$isReplace)
                || ($isAdd && $target['kind'] !== 'product')
                || ($isReplace && $target['kind'] !== 'replacement')
                || !is_int($target['product_id']) || $target['product_id'] < 1
                || preg_match('/^[a-f0-9]{64}$/', $productFingerprint) !== 1
                || preg_match('/^[a-f0-9]{64}$/', $axesFingerprint) !== 1
                || preg_match('/^[a-f0-9]{64}$/', $catalogEpoch) !== 1
                || !is_array($target['bound_attributes'])
                || !Arr::isList($target['bound_attributes'])
                || count($target['bound_attributes']) > 16
                || !is_array($target['missing_attributes'])
                || !Arr::isList($target['missing_attributes'])
                || $target['missing_attributes'] === array()
                || count($target['missing_attributes']) > 16
                || ($isAdd && !CartQuantity::isPositiveInteger($quantity))
            ) {
                throw new InvalidArgumentException('Pending variation intent is invalid.');
            }
            $replacement = array();
            if ($isReplace) {
                $key = is_string($target['source_cart_item_key'])
                    ? trim($target['source_cart_item_key']) : '';
                $fingerprint = is_string($target['source_line_fingerprint'])
                    ? strtolower(trim($target['source_line_fingerprint'])) : '';
                $sourceLabel = is_string($target['source_label'])
                    ? trim($target['source_label']) : '';
                $mode = is_string($target['quantity_mode']) ? $target['quantity_mode'] : '';
                if (
                    $key === '' || strlen($key) > 191
                    || preg_match('/[\x00-\x1F\x7F]/', $key) === 1
                    || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1
                    || $sourceLabel === '' || !Utf8::isPlainText($sourceLabel)
                    || !Utf8::isBounded($sourceLabel, 500, 2000)
                    || !in_array($mode, array('preserve', 'exact'), true)
                    || ($mode === 'preserve' && $quantity !== 0)
                    || ($mode === 'exact' && !CartQuantity::isPositiveInteger($quantity))
                ) {
                    throw new InvalidArgumentException('Pending replacement variation intent is invalid.');
                }
                $replacement = array(
                    'source_cart_item_key' => $key,
                    'source_line_fingerprint' => $fingerprint,
                    'source_label' => $sourceLabel,
                    'quantity_mode' => $mode,
                );
            }
            $bound = array();
            foreach ($target['bound_attributes'] as $attribute) {
                if (!is_array($attribute)) {
                    throw new InvalidArgumentException('Pending variation attribute is invalid.');
                }
                self::assertKeys($attribute, array('label', 'value'));
                $attributeLabel = is_string($attribute['label']) ? trim($attribute['label']) : '';
                $attributeValue = is_string($attribute['value']) ? trim($attribute['value']) : '';
                if (
                    $attributeLabel === '' || $attributeValue === ''
                    || !Utf8::isPlainText($attributeLabel) || !Utf8::isPlainText($attributeValue)
                    || !Utf8::isBounded($attributeLabel, 160, 640)
                    || !Utf8::isBounded($attributeValue, 160, 640)
                ) {
                    throw new InvalidArgumentException('Pending variation attribute is invalid.');
                }
                $identity = hash('sha256', self::identityText($attributeLabel));
                if (isset($bound[$identity])) {
                    throw new InvalidArgumentException('Pending variation attribute is duplicated.');
                }
                $bound[$identity] = array('label' => $attributeLabel, 'value' => $attributeValue);
            }
            $missingAttributes = array();
            foreach ($target['missing_attributes'] as $attributeLabel) {
                $attributeLabel = is_string($attributeLabel) ? trim($attributeLabel) : '';
                if (
                    $attributeLabel === '' || !Utf8::isPlainText($attributeLabel)
                    || !Utf8::isBounded($attributeLabel, 160, 640)
                ) {
                    throw new InvalidArgumentException('Pending missing variation attribute is invalid.');
                }
                $identity = hash('sha256', self::identityText($attributeLabel));
                if (isset($missingAttributes[$identity]) || isset($bound[$identity])) {
                    throw new InvalidArgumentException('Pending variation axes overlap or are duplicated.');
                }
                $missingAttributes[$identity] = $attributeLabel;
            }
            if (
                count(array_unique(array_merge(
                    array_keys($bound),
                    array_keys($missingAttributes)
                ))) > 16
            ) {
                throw new InvalidArgumentException('Pending variation axes exceed their bound.');
            }
            $target = array_merge(array(
                'kind' => $isReplace ? 'replacement' : 'product',
                'product_id' => $target['product_id'],
                'product_fingerprint' => $productFingerprint,
                'variation_axes_fingerprint' => $axesFingerprint,
                'variation_catalog_epoch' => $catalogEpoch,
                'bound_attributes' => array_values($bound),
                'missing_attributes' => array_values($missingAttributes),
            ), $replacement);
        } elseif ($missing === self::MISSING_QUANTITY) {
            self::assertKeys($target, array(
                'cart_item_key', 'kind', 'line_fingerprint', 'quantity_mode',
            ));
            $key = is_string($target['cart_item_key']) ? trim($target['cart_item_key']) : '';
            $fingerprint = is_string($target['line_fingerprint'])
                ? strtolower(trim($target['line_fingerprint'])) : '';
            if (
                $action !== CartCommand::UPDATE
                || $target['kind'] !== 'cart_item'
                || !is_string($target['quantity_mode'])
                || !in_array($target['quantity_mode'], array('set', 'increment', 'decrement'), true)
                || $key === '' || strlen($key) > 191 || preg_match('/[\x00-\x1F\x7F]/', $key) === 1
                || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1
                || $quantity !== 0
            ) {
                throw new InvalidArgumentException('Pending quantity intent is invalid.');
            }
            $target = array(
                'kind' => 'cart_item',
                'cart_item_key' => $key,
                'line_fingerprint' => $fingerprint,
                'quantity_mode' => $target['quantity_mode'],
            );
        } else {
            self::assertKeys($target, array('candidates', 'kind'));
            if (
                $target['kind'] !== 'command_candidates'
                || !is_array($target['candidates'])
                || !Arr::isList($target['candidates'])
                || count($target['candidates']) < 2
                || count($target['candidates']) > 8
                || $quantity !== 0
            ) {
                throw new InvalidArgumentException('Pending target intent is invalid.');
            }
            $candidates = array();
            $seen = array();
            $commonSemantics = null;
            foreach ($target['candidates'] as $candidate) {
                if (!is_array($candidate) || Arr::isList($candidate)) {
                    throw new InvalidArgumentException('Pending target candidate is invalid.');
                }
                $candidate = CartContinuationCandidate::validate($candidate);
                if (!hash_equals($action, (string) $candidate['requested_action'])) {
                    throw new InvalidArgumentException('Pending target candidates disagree on action.');
                }
                $semantics = CartContinuationCandidate::semantics($candidate);
                if ($commonSemantics !== null && $commonSemantics !== $semantics) {
                    throw new InvalidArgumentException('Pending target candidates disagree on quantity meaning.');
                }
                $commonSemantics = $semantics;
                $fingerprint = CartContinuationCandidate::fingerprint($candidate);
                if (isset($seen[$fingerprint])) {
                    throw new InvalidArgumentException('Pending target candidates are duplicated.');
                }
                $seen[$fingerprint] = true;
                $candidates[] = $candidate;
            }
            $target = array('kind' => 'command_candidates', 'candidates' => $candidates);
        }

        $this->id = $id;
        $this->action = $action;
        $this->target = $target;
        $this->quantity = $quantity;
        $this->missing = $missing;
        $this->label = $label;
        $this->question = $question;
        $this->issuedAt = $issuedAt;
        $this->expiresAt = $expiresAt;
    }

    /** @param array<string,mixed> $row */
    public static function fromArray(array $row): self
    {
        self::assertKeys($row, array(
            'action', 'expires_at', 'id', 'issued_at', 'label', 'missing',
            'quantity', 'question', 'target',
        ));
        if (
            !is_string($row['id']) || !is_string($row['action'])
            || !is_array($row['target']) || Arr::isList($row['target'])
            || !is_int($row['quantity']) || !is_string($row['missing'])
            || !is_string($row['label']) || !is_array($row['question'])
            || Arr::isList($row['question']) || !is_int($row['issued_at'])
            || !is_int($row['expires_at'])
        ) {
            throw new InvalidArgumentException('Stored pending cart intent is invalid.');
        }
        return new self(
            $row['id'],
            $row['action'],
            $row['target'],
            $row['quantity'],
            $row['missing'],
            $row['label'],
            ModelAuthoredQuestion::restore(StoredModelQuestionEvidence::fromArray($row['question'])),
            $row['issued_at'],
            $row['expires_at']
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array(
            'id' => $this->id,
            'action' => $this->action,
            'target' => $this->target,
            'quantity' => $this->quantity,
            'missing' => $this->missing,
            'label' => $this->label,
            'question' => $this->question->toArray(),
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
        );
    }

    /** No durable WooCommerce identifiers are disclosed to the model. @return array<string,mixed> */
    public function forModel(): array
    {
        $isVariation = $this->missing === self::MISSING_VARIATION;
        $isTarget = $this->missing === self::MISSING_TARGET;
        $isReplacement = $isVariation && $this->action === CartCommand::REPLACE;
        return array(
            'action' => $this->action,
            'target_label' => $this->label,
            'source_label' => $isReplacement ? $this->target['source_label'] : '',
            'missing' => $this->missing,
            'quantity' => $isVariation && (!$isReplacement
                    || $this->target['quantity_mode'] === 'exact')
                ? $this->quantity : null,
            'bound_attributes' => $isVariation
                ? $this->target['bound_attributes'] : array(),
            'missing_attributes' => $isVariation
                ? $this->target['missing_attributes'] : array(),
            'quantity_mode' => $this->missing === self::MISSING_QUANTITY
                ? $this->target['quantity_mode']
                : ($isReplacement ? $this->target['quantity_mode'] : null),
            'candidate_labels' => $isTarget
                ? array_values(array_map(static function (array $candidate): string {
                    return (string) $candidate['label'];
                }, $this->target['candidates']))
                : array(),
            'candidate_options' => $isTarget
                ? array_values(array_map(static function (array $candidate): array {
                    return CartContinuationCandidate::forModel($candidate);
                }, $this->target['candidates']))
                : array(),
        );
    }

    public function id(): string
    {
        return $this->id;
    }
    public function action(): string
    {
        return $this->action;
    }
    /** @return array<string,mixed> */ public function target(): array
    {
        return $this->target;
    }
    public function quantity(): int
    {
        return $this->quantity;
    }
    public function missing(): string
    {
        return $this->missing;
    }
    public function label(): string
    {
        return $this->label;
    }
    public function question(): string
    {
        return $this->question->text();
    }
    public function modelAuthoredQuestion(): ModelAuthoredQuestion
    {
        return $this->question;
    }
    public function isActive(int $now): bool
    {
        return $now >= $this->issuedAt && $now < $this->expiresAt;
    }

    /** Rewords only the customer question; authority identity and expiry remain unchanged. */
    public function withQuestion(ModelAuthoredQuestion $question): self
    {
        return new self(
            $this->id,
            $this->action,
            $this->target,
            $this->quantity,
            $this->missing,
            $this->label,
            $question,
            $this->issuedAt,
            $this->expiresAt
        );
    }

    /** @param array<string,mixed> $row @param array<int,string> $expected */
    private static function assertKeys(array $row, array $expected): void
    {
        $keys = array_keys($row);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('Pending cart intent contains missing or unsupported fields.');
        }
    }

    private static function identityText(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
