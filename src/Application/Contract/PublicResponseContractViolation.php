<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Contract;

use RuntimeException;

/**
 * Signals a server-side attempt to emit a public payload that does not match
 * the canonical response schema. No customer data is included in the message.
 */
final class PublicResponseContractViolation extends RuntimeException
{
    /** @var string */
    private $definition;

    /** @var string */
    private $contractPath;

    public function __construct(string $definition, string $contractPath, string $reason)
    {
        $this->definition = $definition;
        $this->contractPath = $contractPath;
        parent::__construct(
            'Public response contract violation in ' . $definition
            . ' at ' . $contractPath . ': ' . $reason
        );
    }

    public function definition(): string
    {
        return $this->definition;
    }

    public function contractPath(): string
    {
        return $this->contractPath;
    }
}
