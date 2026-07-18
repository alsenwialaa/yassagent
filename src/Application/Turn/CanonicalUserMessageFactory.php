<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Turn;

use YassinStore\AiAssistant\Application\Port\TextLocalizerPort;

/** Builds durable customer text only from decoded input and server-owned authority. */
final class CanonicalUserMessageFactory
{
    /** @var TextLocalizerPort */ private $text;

    public function __construct(TextLocalizerPort $text)
    {
        $this->text = $text;
    }

    public function create(TurnRequest $request): CanonicalUserMessage
    {
        $parts = array();
        if ($request->message() !== '') {
            $parts[] = $request->message();
        }

        $presentation = UserMessagePresentation::fromAttachments(
            $request->attachments(),
            $request->replyContext()
        );
        if ($presentation->imageCount() > 0) {
            $parts[] = $this->imageLine($presentation->imageCount());
        }

        return new CanonicalUserMessage(implode("\n", $parts), $presentation);
    }

    private function imageLine(int $count): string
    {
        if ($count === 1) {
            return $this->text->text('صورة مرفقة (متاحة للمعالجة في هذا الطلب فقط)');
        }
        return $this->text->text('صور مرفقة × ' . (string) $count . ' (متاحة للمعالجة في هذا الطلب فقط)');
    }
}
