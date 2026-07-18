<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Ai;

/** A model session that can require one declared function on its next step. */
interface RequiredFunctionSessionInterface
{
    public function requireOnlyNextFunction(string $name): void;
}
