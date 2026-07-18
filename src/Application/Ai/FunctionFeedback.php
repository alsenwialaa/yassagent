<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Ai;

use RuntimeException;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Json;

/** Immutable, bounded feedback for exactly one preceding function call. */
final class FunctionFeedback
{
    private const MAX_PAYLOAD_BYTES = 131072;

    /** @var string */ private $id;
    /** @var string */ private $name;
    /** @var array<string,mixed> */ private $payload;

    /** @param array<string,mixed> $payload */
    public function __construct(string $id, string $name, array $payload)
    {
        if (
            $id === '' || strlen($id) > 128 || trim($id) !== $id
            || preg_match('/^[A-Za-z][A-Za-z0-9_]{1,127}$/', $name) !== 1
        ) {
            throw new ModelProtocolException(
                'function_feedback_identity_invalid',
                'Function feedback requires an exact bounded call id and name.'
            );
        }
        if ($payload !== array() && Arr::isList($payload)) {
            throw new ModelProtocolException(
                'function_feedback_payload_invalid',
                'Function feedback payload must be a JSON object.'
            );
        }
        try {
            $bytes = strlen(Json::encodeObject($payload));
        } catch (RuntimeException $exception) {
            throw new ModelProtocolException(
                'function_feedback_payload_invalid',
                'Function feedback payload must be JSON encodable.'
            );
        }
        if ($bytes > self::MAX_PAYLOAD_BYTES) {
            throw new ModelProtocolException(
                'function_feedback_payload_too_large',
                'Function feedback payload exceeded the safe size limit.'
            );
        }

        $this->id = $id;
        $this->name = $name;
        $this->payload = $payload;
    }

    /** @param array<string,mixed> $payload */
    public static function forCall(FunctionCall $call, array $payload): self
    {
        return new self($call->id(), $call->name(), $payload);
    }

    public function id(): string
    {
        return $this->id;
    }
    public function name(): string
    {
        return $this->name;
    }
    /** @return array<string,mixed> */ public function payload(): array
    {
        return $this->payload;
    }
}
