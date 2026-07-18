<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Commerce;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Utf8;

/** Keeps server-validated reply context separate from newly authored text. */
final class CurrentCustomerMessage
{
    /** @var string */ private $text;
    /** @var string */ private $quotedContext;

    public function __construct(string $message, string $quotedContext = '')
    {
        if (
            $message === '' || Utf8::isWhitespaceOnly($message)
            || !Utf8::isPlainText($message)
            || !Utf8::isBounded($message, 1200, 4800)
        ) {
            throw new InvalidArgumentException('Current customer message evidence is invalid.');
        }

        if (
            $quotedContext !== '' && (
            Utf8::isWhitespaceOnly($quotedContext)
            || !Utf8::isPlainText($quotedContext)
            || !Utf8::isBounded($quotedContext, 280, 1120)
            )
        ) {
            throw new InvalidArgumentException('Current customer reply context is invalid.');
        }

        $this->text = $message;
        $this->quotedContext = $quotedContext;
    }

    public function text(): string
    {
        return $this->text;
    }
    public function quotedContext(): string
    {
        return $this->quotedContext;
    }
}
