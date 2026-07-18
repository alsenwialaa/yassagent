<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest\Response;

use InvalidArgumentException;
use YassinStore\AiAssistant\Application\Contract\PublicResponseSchemaValidator;

/** Shared immutable implementation for endpoint-specific public responses. */
abstract class ValidatedPublicResponse implements PublicResponsePayload
{
    /** @var array<string,mixed> */
    private $data;

    /** @var int */
    private $status;

    /** @param array<string,mixed> $data */
    protected function __construct(
        PublicResponseSchemaValidator $validator,
        string $definition,
        array $data,
        int $status
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('Public REST response status is invalid.');
        }
        $validator->assertResponse($definition, $data);
        $this->data = $data;
        $this->status = $status;
    }

    /** @return array<string,mixed> */
    final public function data(): array
    {
        return $this->data;
    }

    final public function status(): int
    {
        return $this->status;
    }
}
