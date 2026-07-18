<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use YassinStore\AiAssistant\Application\Ai\FunctionFeedback;
use YassinStore\AiAssistant\Application\Ai\ModelProtocolException;
use YassinStore\AiAssistant\Application\Ai\ModelRequest;
use YassinStore\AiAssistant\Application\Tool\ToolSchemas;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiGateway;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiGenerationPolicy;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiResponseDecoder;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiSchemaProjector;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiTransportInterface;

$transport = new class implements GeminiTransportInterface {
    /** @var int */ private $round = 0;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function generate(array $payload): array
    {
        ++$this->round;
        return array('candidates' => array(array(
            'finishReason' => 'STOP',
            'content' => array(
                'role' => 'model',
                'parts' => array(
                    array(
                        'text' => str_repeat('t', 2300 * 1024),
                        'thought' => true,
                        'thoughtSignature' => 'probe-' . $this->round,
                    ),
                    array('functionCall' => array(
                        'id' => 'probe-call-' . $this->round,
                        'name' => 'cart_view',
                        'args' => array(),
                    ), 'thoughtSignature' => 'probe-call-signature-' . $this->round),
                ),
            ),
        )));
    }
};

$request = new ModelRequest(
    'System',
    array(),
    'Show cart',
    array(),
    array(array(
        'name' => 'cart_view',
        'description' => 'View cart',
        'parameters' => ToolSchemas::emptyObject(),
    )),
    1024
);
$session = (new GeminiGateway(
    $transport,
    new GeminiResponseDecoder(),
    new GeminiSchemaProjector(),
    new GeminiGenerationPolicy('low')
))->start($request);

$first = $session->next();
$session->submit($first, array(FunctionFeedback::forCall(
    $first->calls()[0],
    array('ok' => true, 'data' => array())
)));
$second = $session->next();

$reason = '';
try {
    $session->submit($second, array(FunctionFeedback::forCall(
        $second->calls()[0],
        array('ok' => true, 'data' => array())
    )));
} catch (ModelProtocolException $exception) {
    $reason = $exception->reasonCode();
}

if ($reason !== 'model_history_budget_exceeded') {
    fwrite(STDERR, 'Cumulative Gemini history did not fail closed at the configured budget.' . PHP_EOL);
    exit(1);
}
if (count(ysai_test_private_property($session, 'contents')) !== 3) {
    fwrite(STDERR, 'Rejected Gemini history changed the previously committed session contents.' . PHP_EOL);
    exit(1);
}

$peak = memory_get_peak_usage(true);
if ($peak >= 64 * 1024 * 1024) {
    fwrite(STDERR, 'Gemini history probe exceeded the 64 MiB process envelope.' . PHP_EOL);
    exit(1);
}

printf("GEMINI HISTORY MEMORY PROBE PASSED: peak=%d bytes\n", $peak);
