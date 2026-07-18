<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Turn;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Utf8;

/** Immutable canonical customer text plus durable non-authoritative presentation. */
final class CanonicalUserMessage
{
    /** @var string */ private $text;
    /** @var UserMessagePresentation */ private $presentation;

    public function __construct(string $text, UserMessagePresentation $presentation)
    {
        if (Utf8::isWhitespaceOnly($text) || strlen($text) > 16384 || !Utf8::isPlainText($text)) {
            throw new InvalidArgumentException('Canonical customer message text is invalid.');
        }
        $this->text = $text;
        $this->presentation = $presentation;
    }

    public function text(): string
    {
        return $this->text;
    }
    public function presentation(): UserMessagePresentation
    {
        return $this->presentation;
    }
}
