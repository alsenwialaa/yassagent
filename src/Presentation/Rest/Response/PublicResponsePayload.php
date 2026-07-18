<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest\Response;

/** Transport-ready payload whose public contract has already been verified. */
interface PublicResponsePayload
{
    /** @return array<string,mixed> */
    public function data(): array;

    public function status(): int;
}
