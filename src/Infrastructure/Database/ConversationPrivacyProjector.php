<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use RuntimeException;
use YassinStore\AiAssistant\Application\Turn\UserMessagePresentation;
use YassinStore\AiAssistant\Domain\Commerce\AppliedCartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartPrimitive;
use YassinStore\AiAssistant\Domain\Commerce\CartSnapshot;
use YassinStore\AiAssistant\Domain\Chat\ConversationState;

/** Projects durable conversation evidence without returning execution authority. */
final class ConversationPrivacyProjector
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public static function messagePayload(string $role, array $payload): array
    {
        if ($role === 'user') {
            $presentation = $payload['presentation'] ?? null;
            if (!is_array($presentation)) {
                throw new RuntimeException('Conversation user-message export is missing presentation data.');
            }
            return array(
                'presentation' => UserMessagePresentation::fromArray($presentation)->forClient(),
            );
        }

        if ($role === 'assistant') {
            $message = $payload['message'] ?? null;
            if (!is_array($message) || $message === array()) {
                throw new RuntimeException('Conversation assistant-message export is missing its client message.');
            }
            return array('message' => $message);
        }

        throw new RuntimeException('Conversation message export role is unsupported.');
    }

    /** @param array<string,mixed> $stored @return array<string,mixed> */
    public static function conversationState(array $stored): array
    {
        return ConversationState::fromArray($stored)->forPrivacy();
    }

    /** @param array<string,mixed> $stored @return array<string,mixed>|null */
    public static function turnResponse(array $stored): ?array
    {
        if ($stored === array()) {
            return null;
        }
        $message = $stored['message'] ?? null;
        if (!is_array($message) || $message === array()) {
            throw new RuntimeException('Conversation turn export is missing its client response.');
        }
        return array('message' => $message);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function turnInput(array $input): array
    {
        $keys = array_keys($input);
        sort($keys, SORT_STRING);
        if (
            $keys !== array(
            'attachments', 'message_fingerprint',
            'message_length', 'message_present',
            'reply_context_fingerprint', 'reply_context_length',
            'reply_context_present', 'schema',
            )
            || ($input['schema'] ?? null) !== 1
            || !is_string($input['message_fingerprint'] ?? null)
            || ((string) $input['message_fingerprint'] !== ''
                && preg_match('/^[a-f0-9]{64}$/', (string) $input['message_fingerprint']) !== 1)
            || !is_string($input['reply_context_fingerprint'] ?? null)
            || ((string) $input['reply_context_fingerprint'] !== ''
                && preg_match('/^[a-f0-9]{64}$/', (string) $input['reply_context_fingerprint']) !== 1)
        ) {
            throw new RuntimeException('Conversation turn input export encountered corrupt evidence.');
        }

        $attachments = array();
        foreach (is_array($input['attachments'] ?? null) ? $input['attachments'] : array() as $attachment) {
            if (
                !is_array($attachment)
                || !is_string($attachment['mime_type'] ?? null)
                || !is_string($attachment['fingerprint'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', (string) $attachment['fingerprint']) !== 1
                || !is_int($attachment['bytes'] ?? null)
                || $attachment['bytes'] < 0
            ) {
                throw new RuntimeException(
                    'Conversation turn input export encountered corrupt attachment evidence.'
                );
            }
            $attachments[] = array(
                'mime_type' => (string) $attachment['mime_type'],
                'bytes' => (int) $attachment['bytes'],
            );
        }

        if (
            !is_bool($input['message_present'] ?? null)
            || !is_int($input['message_length'] ?? null)
            || $input['message_length'] < 0
            || !is_bool($input['reply_context_present'] ?? null)
            || !is_int($input['reply_context_length'] ?? null)
            || $input['reply_context_length'] < 0
            || (bool) $input['message_present'] !== ((string) $input['message_fingerprint'] !== '')
            || (bool) $input['message_present'] !== ((int) $input['message_length'] > 0)
            || (bool) $input['reply_context_present']
                !== ((string) $input['reply_context_fingerprint'] !== '')
            || (bool) $input['reply_context_present']
                !== ((int) $input['reply_context_length'] > 0)
        ) {
            throw new RuntimeException('Conversation turn input export encountered corrupt evidence.');
        }

        return array(
            'message_present' => (bool) $input['message_present'],
            'message_length' => (int) $input['message_length'],
            'reply_context_present' => (bool) $input['reply_context_present'],
            'reply_context_length' => (int) $input['reply_context_length'],
            'attachments' => $attachments,
        );
    }

    /** @param array<string,mixed> $stored @return array<string,mixed> */
    public static function cartPlan(array $stored): array
    {
        $commands = array();
        foreach (CartPlan::fromStorageArray($stored)->commands() as $command) {
            $row = array('type' => $command->type());
            if ($command->displayName() !== '') {
                $row['item'] = $command->displayName();
            }
            if ($command->quantity() > 0) {
                $row['quantity'] = $command->quantity();
            }
            $commands[] = $row;
        }

        return array('commands' => $commands);
    }

    /** @param array<string,mixed> $stored @return array<int,array<string,mixed>> */
    public static function appliedEffects(array $stored): array
    {
        if ($stored === array()) {
            return array();
        }

        $out = array();
        foreach (AppliedCartPlan::fromStorageArray($stored)->effects() as $effect) {
            $row = array('type' => (string) $effect['type']);
            foreach (array('previous_line_count', 'previous_quantity', 'quantity') as $field) {
                if (array_key_exists($field, $effect)) {
                    $row[$field] = $effect[$field];
                }
            }
            if (isset($effect['display_name']) && is_string($effect['display_name'])) {
                $row['item'] = $effect['display_name'];
            }
            $out[] = $row;
        }

        return $out;
    }

    /** @param array<string,mixed> $stored @return array<string,mixed> */
    public static function cartPrimitive(array $stored): array
    {
        $primitive = CartPrimitive::fromStorageArray($stored);
        $row = array(
            'type' => $primitive->type(),
            'action' => $primitive->semanticType(),
            'phase' => $primitive->phase(),
        );
        if ($primitive->displayName() !== '') {
            $row['item'] = $primitive->displayName();
        }
        if ($primitive->quantity() > 0) {
            $row['quantity'] = $primitive->quantity();
        }

        return $row;
    }

    /** @param array<string,mixed> $stored @return array<string,mixed> */
    public static function cartSnapshot(array $stored): array
    {
        return CartSnapshot::fromStorageArray($stored)->forClient(false);
    }

    /** @param array<string,mixed> $stored @return array<string,mixed>|null */
    public static function optionalCartSnapshot(array $stored): ?array
    {
        return $stored === array() ? null : self::cartSnapshot($stored);
    }
}
