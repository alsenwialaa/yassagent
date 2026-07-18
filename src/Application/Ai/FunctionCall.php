<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Ai;

use YassinStore\AiAssistant\Support\Arr;

/** Provider-neutral call with mandatory internal and provider identities. */
final class FunctionCall
{
    /** @var string */ private $id;
    /** @var string */ private $providerId;
    /** @var string */ private $name;
    /** @var array<string,mixed> */ private $arguments;

    /** @param array<string,mixed> $arguments */
    public function __construct(string $id, string $providerId, string $name, array $arguments)
    {
        if ($id === '' || strlen($id) > 128 || trim($id) !== $id) {
            throw new ModelProtocolException('function_call_id_invalid', 'A function call has an invalid internal id.');
        }
        if ($providerId === '' || strlen($providerId) > 256 || trim($providerId) !== $providerId) {
            throw new ModelProtocolException(
                'function_call_provider_id_invalid',
                'A provider function-call id must be bounded and exact.'
            );
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{1,127}$/', $name) !== 1) {
            throw new ModelProtocolException('function_call_name_invalid', 'A function call has an invalid name.');
        }
        if ($arguments !== array() && Arr::isList($arguments)) {
            throw new ModelProtocolException('function_call_args_invalid', 'Function-call arguments must be a JSON object.');
        }
        $this->id = $id;
        $this->providerId = $providerId;
        $this->name = $name;
        $this->arguments = $arguments;
    }

    public function id(): string
    {
        return $this->id;
    }
    public function providerId(): string
    {
        return $this->providerId;
    }
    public function name(): string
    {
        return $this->name;
    }
    /** @return array<string,mixed> */ public function arguments(): array
    {
        return $this->arguments;
    }
}
