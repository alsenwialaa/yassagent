<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

interface RuntimeSettingsPort
{
    /** @return mixed */
    public function get(string $key, $default = null);
}
