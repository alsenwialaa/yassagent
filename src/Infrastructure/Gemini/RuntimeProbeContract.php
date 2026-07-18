<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Gemini;

use InvalidArgumentException;
use YassinStore\AiAssistant\Application\Tool\ToolSchemas;
use YassinStore\AiAssistant\Support\Json;

/** Closed, deliberately tiny contract for administrative runtime readiness. */
final class RuntimeProbeContract
{
    public const REVISION = 'gemini-runtime-access-and-echo-v2';
    public const TOOL = 'readiness_echo';
    public const REQUEST_COUNT = 2;
    public const CHECKS = array('provider_access', 'structured_tool');

    public static function accessSystemInstruction(): string
    {
        return 'This is an administrative model-access check. Return one non-empty plain-text acknowledgement. Do not call functions.';
    }

    public static function accessUserMessage(): string
    {
        return 'Confirm that this configured model can answer a plain-text request.';
    }

    public static function structuredSystemInstruction(): string
    {
        return 'Call readiness_echo exactly once with the exact opaque token supplied by the user. Do not answer with plain text.';
    }

    public static function structuredUserMessage(string $token): string
    {
        self::assertToken($token);
        return 'Call readiness_echo with token ' . $token . '.';
    }

    /** @return array<string,mixed> */
    public static function declaration(string $token): array
    {
        self::assertToken($token);
        return array(
            'name' => self::TOOL,
            'description' => 'Administrative compatibility check. Echo the one allowed opaque token.',
            'parameters' => ToolSchemas::closedObject(array(
                'token' => ToolSchemas::described(array(
                    'type' => 'string',
                    'enum' => array($token),
                ), 'The exact opaque token supplied by the current readiness request.'),
            ), array('token')),
        );
    }

    /**
     * Fingerprint the complete two-request wire contract that the cached proof
     * actually certifies. Shopper prompts and the production tool catalog are
     * deliberately excluded.
     */
    public static function fingerprint(string $thinkingLevel): string
    {
        $placeholder = str_repeat('0', 32);
        $generation = new GeminiGenerationPolicy($thinkingLevel);
        $declaration = self::declaration($placeholder);
        $projected = (new GeminiSchemaProjector())->project(array($declaration));

        return hash('sha256', Json::canonical(array(
            'revision' => self::REVISION,
            'access' => array(
                'system' => self::accessSystemInstruction(),
                'user' => self::accessUserMessage(),
                'generation_config' => $generation->initialConfig(256),
                'tools' => 'absent',
            ),
            'structured' => array(
                'system' => self::structuredSystemInstruction(),
                'user' => self::structuredUserMessage($placeholder),
                'generation_config' => $generation->initialConfig(256),
                'declaration' => $declaration,
                'projected_declaration' => $projected,
                'function_calling_mode' => 'ANY',
                'allowed_function_names' => array(self::TOOL),
            ),
        )));
    }

    private static function assertToken(string $token): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Runtime readiness token is invalid.');
        }
    }
}
