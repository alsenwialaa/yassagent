<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Ai;

interface ModelGatewayInterface
{
    public function start(ModelRequest $request): ModelSessionInterface;
}
