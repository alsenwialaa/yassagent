<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Composition;

use YassinStore\AiAssistant\Application\Agent\AgentFailureMessages;
use YassinStore\AiAssistant\Application\Agent\AgentLimits;
use YassinStore\AiAssistant\Application\Agent\AgentModelLoop;
use YassinStore\AiAssistant\Application\Agent\AgentRequestFactory;
use YassinStore\AiAssistant\Application\Agent\ModelAuthoredQuestionFactory;
use YassinStore\AiAssistant\Application\Agent\AgentPromptBuilder;
use YassinStore\AiAssistant\Application\Agent\ArabicCustomerText;
use YassinStore\AiAssistant\Application\Agent\AgentRunner;
use YassinStore\AiAssistant\Application\Agent\ResponseProjection;
use YassinStore\AiAssistant\Application\Agent\TerminalOutcomeAssembler;
use YassinStore\AiAssistant\Application\Commerce\CurrentTurnCartIntentEvidence;
use YassinStore\AiAssistant\Application\Commerce\CartIntentVerificationFactory;
use YassinStore\AiAssistant\Application\Commerce\PendingCartIntentFactory;
use YassinStore\AiAssistant\Application\Commerce\VariableProductAuthority;
use YassinStore\AiAssistant\Application\Port\TextLocalizerPort;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiCartIntentVerifier;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiEndpoint;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiGateway;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiGenerationPolicy;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiResponseDecoder;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiRuntimeProbe;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiRuntimeReadiness;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiSchemaProjector;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiTransport;
use YassinStore\AiAssistant\Infrastructure\System\SystemClock;
use YassinStore\AiAssistant\Infrastructure\WordPress\ContentRepository;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Domain\Shopping\CatalogTextNormalizer;

/** Immutable provider, readiness, prompt, and tool runtime composition boundary. */
final class AgentStack
{
    /** @var AgentRunner */ private $runner;
    /** @var GeminiRuntimeProbe */ private $probe;

    public function __construct(
        Settings $settings,
        Logger $logger,
        CommerceStack $commerce,
        ContentRepository $content,
        TextLocalizerPort $text,
        GeminiRuntimeReadiness $readiness
    ) {
        $transport = new GeminiTransport($settings, $logger, GeminiEndpoint::configured());
        $decoder = new GeminiResponseDecoder();
        $generation = new GeminiGenerationPolicy(
            (string) $settings->get('gemini_thinking_level', 'low')
        );
        $gateway = new GeminiGateway(
            $transport,
            $decoder,
            new GeminiSchemaProjector(),
            $generation
        );

        $cartText = new CatalogTextNormalizer();
        $clock = new SystemClock();
        $variableProducts = new VariableProductAuthority($cartText);
        $cartEvidence = new CurrentTurnCartIntentEvidence($cartText, $variableProducts);
        $verificationRequests = new CartIntentVerificationFactory($cartText);
        $cartIntentVerifier = new GeminiCartIntentVerifier(
            $gateway,
            (int) $settings->get('http_timeout_seconds', 30)
        );
        $tools = (new ToolStack(
            $commerce,
            $content,
            $logger,
            $text,
            $cartEvidence,
            $verificationRequests,
            $cartIntentVerifier,
            $clock
        ))->catalog();
        $failures = new AgentFailureMessages($text);
        $arabic = new ArabicCustomerText();
        $outcomes = new TerminalOutcomeAssembler(
            new ResponseProjection($commerce->catalog()),
            $failures,
            $text,
            $arabic,
            new PendingCartIntentFactory(
                $cartText,
                $clock,
                $cartEvidence,
                $verificationRequests,
                $cartIntentVerifier,
                $variableProducts
            ),
            new ModelAuthoredQuestionFactory($arabic, $clock)
        );
        $limits = new AgentLimits(
            (int) $settings->get('max_tool_rounds', 6),
            20,
            262144,
            (int) $settings->get('max_output_tokens', 2048),
            (int) $settings->get('http_timeout_seconds', 30)
        );
        $prompt = new AgentPromptBuilder(
            (string) get_bloginfo('name'),
            $commerce->mutationCapability(),
            $clock,
            (string) $settings->get('store_guidance', '')
        );
        $requests = new AgentRequestFactory($prompt, $tools, $limits);
        $this->runner = new AgentRunner(
            $gateway,
            $requests,
            new AgentModelLoop(
                $tools,
                $outcomes,
                $limits,
                $commerce->providerWaitIsolation()
            ),
            $outcomes,
            $logger,
            $clock,
            $readiness
        );
        $this->probe = new GeminiRuntimeProbe(
            $transport,
            $decoder,
            new GeminiSchemaProjector(),
            $generation,
            $settings,
            $readiness
        );
    }

    public function runner(): AgentRunner
    {
        return $this->runner;
    }

    public function probe(): GeminiRuntimeProbe
    {
        return $this->probe;
    }
}
