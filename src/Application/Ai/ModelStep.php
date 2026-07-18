<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Ai;

use YassinStore\AiAssistant\Support\Arr;

final class ModelStep
{
    /** @var string */ private $token;
    /** @var array<int,FunctionCall> */ private $calls;
    /** @var string */ private $plainText;
    /** @var string */ private $finishReason;

    /** @param array<int,FunctionCall> $calls */
    public function __construct(string $token, array $calls, string $plainText = '', string $finishReason = '')
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 128 || !Arr::isList($calls) || count($calls) > 16) {
            throw new ModelProtocolException('model_step_invalid', 'The model step identity or call list is invalid.');
        }
        foreach ($calls as $call) {
            if (!$call instanceof FunctionCall) {
                throw new ModelProtocolException('model_step_call_invalid', 'The model step contains an invalid function call.');
            }
        }
        if (strlen($plainText) > 131072 || strlen($finishReason) > 128) {
            throw new ModelProtocolException('model_step_payload_invalid', 'The model step payload is too large.');
        }
        $this->token = $token;
        $this->calls = array_values($calls);
        $this->plainText = trim($plainText);
        $this->finishReason = trim($finishReason);
    }

    public function token(): string
    {
        return $this->token;
    }
    /** @return array<int,FunctionCall> */ public function calls(): array
    {
        return $this->calls;
    }
    public function hasCalls(): bool
    {
        return $this->calls !== array();
    }
    public function plainText(): string
    {
        return $this->plainText;
    }
    public function finishReason(): string
    {
        return $this->finishReason;
    }
}
