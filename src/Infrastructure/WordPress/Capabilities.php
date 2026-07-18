<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WordPress;

final class Capabilities
{
    public function manage(): string
    {
        return 'manage_options';
    }

    public function currentUserCanManage(): bool
    {
        return current_user_can($this->manage());
    }
}
