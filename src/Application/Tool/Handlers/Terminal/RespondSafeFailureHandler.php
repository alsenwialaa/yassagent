<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool\Handlers\Terminal;

use YassinStore\AiAssistant\Application\Tool\ToolContract;
use YassinStore\AiAssistant\Application\Tool\ToolPromptDescriptions;
use YassinStore\AiAssistant\Application\Tool\ToolSchemas;

final class RespondSafeFailureHandler extends AbstractTerminalHandler
{
    /** @var ToolContract */ private $contract;

    public function __construct()
    {
        $this->contract = new ToolContract(
            'respond_safe_failure',
            ToolPromptDescriptions::for('respond_safe_failure'),
            ToolSchemas::closedObject(array(
                'text' => ToolSchemas::boundedText(1200),
            ), array('text')),
            ToolContract::TERMINAL
        );
    }

    public function contract(): ToolContract
    {
        return $this->contract;
    }
}
