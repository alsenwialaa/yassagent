<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Chat;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Utf8;
use YassinStore\AiAssistant\Support\Uuid;

/**
 * Closed durable representation of verified model-question evidence.
 *
 * The digest detects accidental corruption and representation drift. It is not
 * a secret MAC and does not authenticate storage against an attacker who can
 * rewrite every trusted database field.
 */
final class StoredModelQuestionEvidence
{
    private const SCHEMA = 1;
    private const TOOL_NAME = 'respond_follow_up';

    /** @var array<string,mixed> */ private $payload;
    /** @var string */ private $evidenceDigest;

    /** @param array<string,mixed> $payload */
    private function __construct(array $payload, string $evidenceDigest)
    {
        self::assertPayload($payload);
        if (
            preg_match('/^[a-f0-9]{64}$/', $evidenceDigest) !== 1
            || !hash_equals(self::digest($payload), $evidenceDigest)
        ) {
            throw new InvalidArgumentException('Stored model-question integrity evidence is invalid.');
        }
        $this->payload = $payload;
        $this->evidenceDigest = $evidenceDigest;
    }

    public static function acceptVerified(
        VerifiedModelQuestionEvidence $evidence,
        int $acceptedAt
    ): self {
        $payload = array(
            'schema' => self::SCHEMA,
            'text' => $evidence->question(),
            'model_step_id' => $evidence->modelStepId(),
            'tool_name' => $evidence->toolName(),
            'tool_call_id' => $evidence->toolCallId(),
            'provider_call_id' => $evidence->providerCallId(),
            'client_turn_id' => $evidence->clientTurnId(),
            'conversation_id' => $evidence->conversationId(),
            'purpose' => $evidence->purpose(),
            'model_round' => $evidence->modelRound(),
            'validated_arguments_digest' => $evidence->validatedArgumentsDigest(),
            'current_turn_digest' => $evidence->currentTurnDigest(),
            'accepted_at' => $acceptedAt,
        );
        return new self($payload, self::digest($payload));
    }

    /** @param array<string,mixed> $row */
    public static function fromArray(array $row): self
    {
        self::assertKeys($row, array_merge(self::payloadKeys(), array('evidence_digest')));
        if (!is_string($row['evidence_digest'])) {
            throw new InvalidArgumentException('Stored model-question evidence is invalid.');
        }
        $digest = $row['evidence_digest'];
        unset($row['evidence_digest']);
        return new self($row, $digest);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $row = $this->payload;
        $row['evidence_digest'] = $this->evidenceDigest;
        return $row;
    }

    public function text(): string
    {
        return (string) $this->payload['text'];
    }
    public function modelStepId(): string
    {
        return (string) $this->payload['model_step_id'];
    }
    public function toolName(): string
    {
        return (string) $this->payload['tool_name'];
    }
    public function toolCallId(): string
    {
        return (string) $this->payload['tool_call_id'];
    }
    public function providerCallId(): string
    {
        return (string) $this->payload['provider_call_id'];
    }
    public function clientTurnId(): string
    {
        return (string) $this->payload['client_turn_id'];
    }
    public function conversationId(): string
    {
        return (string) $this->payload['conversation_id'];
    }
    public function purpose(): string
    {
        return (string) $this->payload['purpose'];
    }
    public function modelRound(): int
    {
        return (int) $this->payload['model_round'];
    }
    public function validatedArgumentsDigest(): string
    {
        return (string) $this->payload['validated_arguments_digest'];
    }
    public function currentTurnDigest(): string
    {
        return (string) $this->payload['current_turn_digest'];
    }
    public function acceptedAt(): int
    {
        return (int) $this->payload['accepted_at'];
    }
    public function evidenceDigest(): string
    {
        return $this->evidenceDigest;
    }

    /** @param array<string,mixed> $payload */
    private static function assertPayload(array $payload): void
    {
        self::assertKeys($payload, self::payloadKeys());
        foreach (
            array(
            'text', 'model_step_id', 'tool_name', 'tool_call_id', 'provider_call_id',
            'client_turn_id', 'conversation_id', 'purpose', 'validated_arguments_digest',
            'current_turn_digest',
            ) as $field
        ) {
            if (!is_string($payload[$field])) {
                throw new InvalidArgumentException('Stored model-question evidence is invalid.');
            }
        }
        if (
            !is_int($payload['schema']) || $payload['schema'] !== self::SCHEMA
            || !is_int($payload['model_round']) || !is_int($payload['accepted_at'])
        ) {
            throw new InvalidArgumentException('Stored model-question evidence is invalid.');
        }
        if (
            $payload['text'] === '' || Utf8::hasOuterWhitespace($payload['text'])
            || !Utf8::isPlainText($payload['text'])
            || !Utf8::isBounded($payload['text'], 320, 1280)
        ) {
            throw new InvalidArgumentException('Stored model-question text is invalid.');
        }
        if (
            $payload['model_step_id'] === ''
            || trim($payload['model_step_id']) !== $payload['model_step_id']
            || strlen($payload['model_step_id']) > 128
            || $payload['tool_name'] !== self::TOOL_NAME
            || $payload['tool_call_id'] === ''
            || trim($payload['tool_call_id']) !== $payload['tool_call_id']
            || strlen($payload['tool_call_id']) > 128
            || $payload['provider_call_id'] === ''
            || trim($payload['provider_call_id']) !== $payload['provider_call_id']
            || strlen($payload['provider_call_id']) > 256
            || strtolower($payload['client_turn_id']) !== $payload['client_turn_id']
            || !Uuid::isV4($payload['client_turn_id'])
            || strtolower($payload['conversation_id']) !== $payload['conversation_id']
            || !Uuid::isV4($payload['conversation_id'])
            || !in_array($payload['purpose'], ModelAuthoredQuestion::purposes(), true)
            || $payload['model_round'] < 1 || $payload['model_round'] > 64
            || preg_match('/^[a-f0-9]{64}$/', $payload['validated_arguments_digest']) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $payload['current_turn_digest']) !== 1
            || $payload['accepted_at'] < 1
        ) {
            throw new InvalidArgumentException('Stored model-question provenance is invalid.');
        }
    }

    /** @return array<int,string> */
    private static function payloadKeys(): array
    {
        return array(
            'schema', 'text', 'model_step_id', 'tool_name', 'tool_call_id',
            'provider_call_id', 'client_turn_id', 'conversation_id', 'purpose',
            'model_round', 'validated_arguments_digest', 'current_turn_digest',
            'accepted_at',
        );
    }

    /** @param array<string,mixed> $payload */
    private static function digest(array $payload): string
    {
        return hash('sha256', Json::canonicalObject($payload));
    }

    /** @param array<string,mixed> $row @param array<int,string> $expected */
    private static function assertKeys(array $row, array $expected): void
    {
        if ($row !== array() && Arr::isList($row)) {
            throw new InvalidArgumentException('Stored model-question evidence is invalid.');
        }
        $actual = array_keys($row);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException(
                'Stored model-question evidence contains missing or unsupported fields.'
            );
        }
    }
}
