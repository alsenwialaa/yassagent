<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Gemini;

use YassinStore\AiAssistant\Application\Ai\ModelGatewayInterface;
use YassinStore\AiAssistant\Application\Ai\ModelRequest;

final class GeminiGateway implements ModelGatewayInterface
{
    /** @var GeminiTransportInterface */ private $transport;
    /** @var GeminiResponseDecoder */ private $decoder;
    /** @var GeminiSchemaProjector */ private $schemas;
    /** @var GeminiGenerationPolicy */ private $generation;

    public function __construct(
        GeminiTransportInterface $transport,
        GeminiResponseDecoder $decoder,
        GeminiSchemaProjector $schemas,
        GeminiGenerationPolicy $generation
    ) {
        $this->transport = $transport;
        $this->decoder = $decoder;
        $this->schemas = $schemas;
        $this->generation = $generation;
    }

    public function start(ModelRequest $request): GeminiSession
    {
        $declarations = $request->toolDeclarations();
        $payload = array(
            'systemInstruction' => array(
                'parts' => array(array('text' => $request->systemInstruction())),
            ),
            'generationConfig' => $this->generation->initialConfig($request->maxOutputTokens()),
            'tools' => array(
                array('functionDeclarations' => $this->schemas->project($declarations)),
            ),
            'toolConfig' => array(
                // VALIDATED keeps the complete production catalog active and
                // enforces declared argument schemas without forcing every
                // provider turn through ANY's stricter large-schema path.
                // AgentModelLoop still accepts only declared function calls as
                // terminal outcomes and corrects any plain model output.
                'functionCallingConfig' => array('mode' => 'VALIDATED'),
            ),
        );

        return new GeminiSession($this->transport, $this->decoder, $request, $payload);
    }
}
