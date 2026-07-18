<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Ai;

interface ModelSessionInterface
{
    public function next(): ModelStep;

    /** @param array<int,FunctionFeedback> $feedback */
    public function submit(ModelStep $step, array $feedback): void;

    public function correctPlainOutput(ModelStep $step, string $instruction): void;
}
