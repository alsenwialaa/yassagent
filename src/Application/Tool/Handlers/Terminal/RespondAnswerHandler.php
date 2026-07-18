<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool\Handlers\Terminal;

use YassinStore\AiAssistant\Application\Tool\ToolContract;
use YassinStore\AiAssistant\Application\Tool\ToolPromptDescriptions;
use YassinStore\AiAssistant\Application\Tool\ToolSchemas;

final class RespondAnswerHandler extends AbstractTerminalHandler
{
    /** @var ToolContract */ private $contract;

    public function __construct()
    {
        $this->contract = new ToolContract(
            'respond_answer',
            ToolPromptDescriptions::for('respond_answer'),
            ToolSchemas::closedObject(array(
                'text' => ToolSchemas::boundedText(1200),
                'product_refs' => ToolSchemas::productReferences(),
                'variation_refs' => ToolSchemas::variationReferences(),
            ), array('text')),
            ToolContract::TERMINAL
        );
    }

    public function contract(): ToolContract
    {
        return $this->contract;
    }
}
