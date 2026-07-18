<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Contract;

use InvalidArgumentException;
use YassinStore\AiAssistant\Application\Ai\ImageAttachmentPolicy;
use YassinStore\AiAssistant\Support\Arr;

/**
 * Immutable runtime projection of the canonical Draft 2020-12 public schema.
 *
 * PHP does not attempt to become a general JSON-Schema engine. It validates
 * the closed schema structure and derives every request guard/decoder limit
 * from the same document consumed by the generated browser projection.
 */
final class PublicApiContract
{
    private const DRAFT = 'https://json-schema.org/draft/2020-12/schema';
    /** @var array<string,mixed> */
    private $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $this->validate($data);
    }

    public function contractVersion(): int
    {
        return (int) $this->data['x-contract-version'];
    }

    public function namespace(): string
    {
        return (string) $this->data['x-namespace'];
    }

    public function maxBodyBytes(): int
    {
        return (int) $this->runtime()['max_body_bytes'];
    }

    /** @return array<int,string> */
    public function bootFields(): array
    {
        return GeneratedPublicApiContract::OBJECT_FIELDS['boot_request'];
    }

    /** @return array<int,string> */
    public function chatFields(): array
    {
        return GeneratedPublicApiContract::OBJECT_FIELDS['chat_request'];
    }

    public function replyContextMaxChars(): int
    {
        $variants = $this->definition('reply_context')['oneOf'];
        return (int) $variants[0]['properties']['text']['maxLength'];
    }

    public function conversationTokenMinLength(): int
    {
        return (int) $this->definition('conversation_token')['minLength'];
    }

    public function conversationTokenMaxLength(): int
    {
        return (int) $this->definition('conversation_token')['maxLength'];
    }

    public function messageMaxChars(): int
    {
        return (int) $this->property('chat_request', 'message')['maxLength'];
    }

    public function attachmentMaxItems(): int
    {
        return (int) $this->property('chat_request', 'attachments')['maxItems'];
    }

    /** @return array<int,string> */
    public function attachmentFields(): array
    {
        return $this->definitionFields('attachment');
    }

    /** @return array<int,string> */
    public function attachmentMimeTypes(): array
    {
        return $this->property('attachment', 'mime_type')['enum'];
    }

    public function attachmentMinDecodedBytes(): int
    {
        return (int) $this->imagePolicy()['min_decoded_bytes'];
    }

    public function attachmentMaxDecodedBytes(): int
    {
        return (int) $this->imagePolicy()['max_decoded_bytes'];
    }

    public function attachmentMaxEncodedBytes(): int
    {
        return (int) $this->imagePolicy()['max_encoded_bytes'];
    }

    public function attachmentMaxTotalDecodedBytes(): int
    {
        return (int) $this->imagePolicy()['max_total_decoded_bytes'];
    }

    public function attachmentMaxTotalEncodedBytes(): int
    {
        return (int) $this->imagePolicy()['max_total_encoded_bytes'];
    }

    public function attachmentMaxWidth(): int
    {
        return (int) $this->imagePolicy()['max_width'];
    }

    public function attachmentMaxHeight(): int
    {
        return (int) $this->imagePolicy()['max_height'];
    }

    public function attachmentMaxPixels(): int
    {
        return (int) $this->imagePolicy()['max_pixels'];
    }

    public function transcriptMaxRows(): int
    {
        return (int) $this->runtime()['transcript_max_rows'];
    }

    /** @return array<int,string> */
    public function messageOptionalFailureFields(): array
    {
        return $this->runtime()['message_optional_failure_fields'];
    }

    /** @return array<string,mixed> */
    public function responseSchema(string $definition): array
    {
        if (!in_array($definition, GeneratedPublicApiContract::RESPONSE_DEFINITIONS, true)) {
            throw new InvalidArgumentException('Unknown public response definition.');
        }
        return $this->definition($definition);
    }

    /** @return array<string,mixed> */
    public function resolveLocalReference(string $reference): array
    {
        $prefix = '#/$defs/';
        if (strpos($reference, $prefix) !== 0) {
            throw new InvalidArgumentException('Only local public-contract definition references are supported.');
        }
        $name = substr($reference, strlen($prefix));
        if (
            !is_string($name)
            || preg_match('/^[a-z][a-z0-9_]{0,127}$/', $name) !== 1
        ) {
            throw new InvalidArgumentException('Public-contract definition reference is invalid.');
        }
        return $this->definition($name);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function validate(array $data): array
    {
        $this->assertObjectKeys(
            $data,
            array(
                '$schema', '$id', 'title', 'description', 'x-contract-version',
                'x-namespace', 'x-runtime', 'oneOf', '$defs',
            ),
            'root'
        );
        if (
            ($data['$schema'] ?? null) !== self::DRAFT
            || ($data['x-contract-version'] ?? null) !== GeneratedPublicApiContract::CONTRACT_VERSION
            || ($data['$id'] ?? null) !== GeneratedPublicApiContract::SCHEMA_ID
            || !is_string($data['$id'] ?? null)
            || !is_string($data['title'] ?? null)
            || !is_string($data['description'] ?? null)
            || !is_string($data['x-namespace'] ?? null)
            || !hash_equals(GeneratedPublicApiContract::NAMESPACE, (string) $data['x-namespace'])
            || preg_match('#^[a-z0-9-]+/v[0-9]+$#', (string) $data['x-namespace']) !== 1
        ) {
            throw new InvalidArgumentException('Public API contract root is invalid.');
        }

        $runtime = $data['x-runtime'] ?? null;
        $this->assertObjectKeys(
            $runtime,
            array(
                'max_body_bytes', 'transcript_max_rows', 'message_optional_failure_fields',
                'image_policy', 'endpoint_schemas',
            ),
            'runtime'
        );
        $this->assertInt($runtime['max_body_bytes'] ?? null, 1024, 33554432, 'body limit');
        $this->assertInt($runtime['transcript_max_rows'] ?? null, 2, 100, 'transcript row limit');
        if ($runtime['transcript_max_rows'] !== 24) {
            throw new InvalidArgumentException('Public API transcript limits diverge from runtime authority.');
        }
        $this->assertStringList(
            $runtime['message_optional_failure_fields'] ?? null,
            1,
            8,
            'optional message failure fields'
        );
        if ($runtime['message_optional_failure_fields'] !== array('failure_code', 'state_uncertain')) {
            throw new InvalidArgumentException('Public API optional message failure fields are invalid.');
        }

        $this->assertObjectKeys(
            $runtime['image_policy'] ?? null,
            array(
                'min_decoded_bytes', 'max_decoded_bytes', 'max_encoded_bytes',
                'max_total_decoded_bytes', 'max_total_encoded_bytes', 'max_width',
                'max_height', 'max_pixels',
            ),
            'image policy'
        );
        $this->assertObjectKeys(
            $runtime['endpoint_schemas'] ?? null,
            GeneratedPublicApiContract::ENDPOINT_DEFINITIONS,
            'endpoint schemas'
        );
        $endpointNames = GeneratedPublicApiContract::ENDPOINT_DEFINITIONS;
        foreach ($endpointNames as $name) {
            if (($runtime['endpoint_schemas'][$name] ?? null) !== '#/$defs/' . $name) {
                throw new InvalidArgumentException('Public API endpoint schema map is invalid.');
            }
        }
        $this->assertExactRefs($data['oneOf'] ?? null, $endpointNames, 'root endpoint union');

        $expectedDefinitions = GeneratedPublicApiContract::DEFINITIONS;
        $this->assertObjectKeys($data['$defs'] ?? null, $expectedDefinitions, 'definitions');

        $this->assertScalarDefinition($data, 'uuid_v4', 36, 36, '^[a-f0-9]');
        $this->assertScalarDefinition($data, 'code', 1, 64, '^[a-z0-9_]');
        $this->assertScalarDefinition($data, 'conversation_token', 24, 180, '^[A-Za-z0-9_-]');

        $closedObjects = array_keys(GeneratedPublicApiContract::OBJECT_FIELDS);
        foreach ($closedObjects as $name) {
            $this->assertClosedObjectDefinition($data, $name);
        }
        $this->assertReplyContextDefinition($data);
        $this->assertCartCommandDefinition($data);

        foreach (GeneratedPublicApiContract::OBJECT_FIELDS as $name => $fields) {
            $this->assertDefinitionFields($data, $name, $fields);
            if (
                ($data['$defs'][$name]['required'] ?? null)
                !== GeneratedPublicApiContract::REQUIRED_FIELDS[$name]
            ) {
                throw new InvalidArgumentException(
                    'Public API required fields diverge from the generated PHP projection: ' . $name . '.'
                );
            }
        }
        if (!in_array('reply_quote', $data['$defs']['presentation']['required'], true)) {
            throw new InvalidArgumentException('Public API presentation must require reply_quote.');
        }

        $this->assertInt($data['$defs']['chat_request']['properties']['message']['maxLength'] ?? null, 1, 10000, 'message limit');
        $this->assertInt($data['$defs']['chat_request']['properties']['attachments']['maxItems'] ?? null, 0, 8, 'attachment count');
        $this->assertInt($data['$defs']['reply_context']['oneOf'][0]['properties']['text']['maxLength'] ?? null, 1, 1000, 'reply context limit');
        $this->assertInt($data['$defs']['conversation_token']['minLength'] ?? null, 16, 512, 'token minimum');
        $this->assertInt($data['$defs']['conversation_token']['maxLength'] ?? null, 24, 4096, 'token maximum');
        if ($data['$defs']['conversation_token']['minLength'] > $data['$defs']['conversation_token']['maxLength']) {
            throw new InvalidArgumentException('Public API token limits are contradictory.');
        }

        $mimeTypes = $data['$defs']['attachment']['properties']['mime_type']['enum'] ?? null;
        $this->assertStringList($mimeTypes, 1, 16, 'attachment MIME types');
        $expectedAttachmentPolicy = array(
            'max_items' => ImageAttachmentPolicy::MAX_ITEMS,
            'allowed_mime_types' => ImageAttachmentPolicy::mimeTypes(),
            'min_decoded_bytes' => ImageAttachmentPolicy::MIN_DECODED_BYTES,
            'max_decoded_bytes' => ImageAttachmentPolicy::MAX_DECODED_BYTES,
            'max_encoded_bytes' => ImageAttachmentPolicy::MAX_ENCODED_BYTES,
            'max_total_decoded_bytes' => ImageAttachmentPolicy::MAX_TOTAL_DECODED_BYTES,
            'max_total_encoded_bytes' => ImageAttachmentPolicy::MAX_TOTAL_ENCODED_BYTES,
            'max_width' => ImageAttachmentPolicy::MAX_WIDTH,
            'max_height' => ImageAttachmentPolicy::MAX_HEIGHT,
            'max_pixels' => ImageAttachmentPolicy::MAX_PIXELS,
        );
        $actualAttachmentPolicy = array(
            'max_items' => $data['$defs']['chat_request']['properties']['attachments']['maxItems'] ?? null,
            'allowed_mime_types' => $mimeTypes,
            'min_decoded_bytes' => $runtime['image_policy']['min_decoded_bytes'] ?? null,
            'max_decoded_bytes' => $runtime['image_policy']['max_decoded_bytes'] ?? null,
            'max_encoded_bytes' => $runtime['image_policy']['max_encoded_bytes'] ?? null,
            'max_total_decoded_bytes' => $runtime['image_policy']['max_total_decoded_bytes'] ?? null,
            'max_total_encoded_bytes' => $runtime['image_policy']['max_total_encoded_bytes'] ?? null,
            'max_width' => $runtime['image_policy']['max_width'] ?? null,
            'max_height' => $runtime['image_policy']['max_height'] ?? null,
            'max_pixels' => $runtime['image_policy']['max_pixels'] ?? null,
        );
        if ($actualAttachmentPolicy !== $expectedAttachmentPolicy) {
            throw new InvalidArgumentException('Public API image policy does not match the runtime authority.');
        }
        if (
            ($data['$defs']['attachment']['properties']['data']['maxLength'] ?? null) !== ImageAttachmentPolicy::MAX_ENCODED_BYTES
            || ($data['$defs']['image_metadata']['properties']['byte_length']['maximum'] ?? null) !== ImageAttachmentPolicy::MAX_DECODED_BYTES
            || ($data['$defs']['capabilities']['properties']['max_images']['maximum'] ?? null) !== ImageAttachmentPolicy::MAX_ITEMS
            || ($data['$defs']['capabilities']['properties']['max_image_bytes']['maximum'] ?? null) !== ImageAttachmentPolicy::MAX_DECODED_BYTES
        ) {
            throw new InvalidArgumentException('Public API nested image limits diverge from runtime authority.');
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function runtime(): array
    {
        return $this->data['x-runtime'];
    }

    /** @return array<string,mixed> */
    private function imagePolicy(): array
    {
        return $this->runtime()['image_policy'];
    }

    /** @return array<string,mixed> */
    private function definition(string $name): array
    {
        $definition = $this->data['$defs'][$name] ?? null;
        if (!is_array($definition) || ($definition !== array() && Arr::isList($definition))) {
            throw new InvalidArgumentException('Public API definition is invalid: ' . $name . '.');
        }
        return $definition;
    }

    /** @return array<string,mixed> */
    private function property(string $definition, string $property): array
    {
        $value = $this->definition($definition)['properties'][$property] ?? null;
        if (!is_array($value) || ($value !== array() && Arr::isList($value))) {
            throw new InvalidArgumentException('Public API property is invalid: ' . $definition . '.' . $property . '.');
        }
        return $value;
    }

    /** @return array<int,string> */
    private function definitionFields(string $name): array
    {
        $fields = array_keys($this->definition($name)['properties']);
        foreach ($fields as $field) {
            if (!is_string($field)) {
                throw new InvalidArgumentException('Public API property name is invalid: ' . $name . '.');
            }
        }
        return $fields;
    }

    /** @param array<string,mixed> $data */
    private function assertClosedObjectDefinition(array $data, string $name): void
    {
        $definition = $data['$defs'][$name] ?? null;
        if (
            !is_array($definition)
            || ($definition !== array() && Arr::isList($definition))
            || ($definition['type'] ?? null) !== 'object'
            || ($definition['additionalProperties'] ?? null) !== false
            || !is_array($definition['properties'] ?? null)
            || (($definition['properties'] ?? array()) !== array() && Arr::isList($definition['properties']))
            || !is_array($definition['required'] ?? null)
            || !Arr::isList($definition['required'])
        ) {
            throw new InvalidArgumentException('Public API object definition is invalid: ' . $name . '.');
        }
        $this->assertStringList($definition['required'], 1, 32, $name . ' required fields');
        foreach ($definition['required'] as $field) {
            if (!array_key_exists($field, $definition['properties'])) {
                throw new InvalidArgumentException('Public API required field is not defined: ' . $name . '.' . $field . '.');
            }
        }
    }

    /** @param array<string,mixed> $data */
    private function assertReplyContextDefinition(array $data): void
    {
        $definition = $data['$defs']['reply_context'] ?? null;
        if (!is_array($definition) || array_keys($definition) !== array('oneOf')) {
            throw new InvalidArgumentException('Public API reply-context definition is invalid.');
        }
        $variants = $definition['oneOf'];
        if (!is_array($variants) || !Arr::isList($variants) || count($variants) !== 2) {
            throw new InvalidArgumentException('Public API reply-context variants are invalid.');
        }
        $this->assertObjectKeys($variants[0], array('type', 'properties', 'required', 'additionalProperties'), 'plain reply context');
        $this->assertObjectKeys($variants[1], array('type', 'properties', 'required', 'additionalProperties'), 'product reply context');
        foreach ($variants as $variant) {
            if (($variant['type'] ?? null) !== 'object' || ($variant['additionalProperties'] ?? null) !== false) {
                throw new InvalidArgumentException('Public API reply-context variant is not closed.');
            }
        }
        $this->assertObjectKeys($variants[0]['properties'], array('text'), 'plain reply-context fields');
        $this->assertObjectKeys($variants[1]['properties'], array('text', 'message_id', 'product_index'), 'product reply-context fields');
        if (
            $variants[0]['required'] !== array('text')
            || $variants[1]['required'] !== array('text', 'message_id', 'product_index')
        ) {
            throw new InvalidArgumentException('Public API reply-context required fields are invalid.');
        }
    }

    /** @param array<string,mixed> $data */
    private function assertCartCommandDefinition(array $data): void
    {
        $definition = $data['$defs']['cart_command'] ?? null;
        if (!is_array($definition) || array_keys($definition) !== array('oneOf')) {
            throw new InvalidArgumentException('Public API cart-command definition is invalid.');
        }
        $variants = $definition['oneOf'];
        if (!is_array($variants) || !Arr::isList($variants) || count($variants) !== 5) {
            throw new InvalidArgumentException('Public API cart-command variants are invalid.');
        }
        $seen = array();
        foreach ($variants as $variant) {
            if (
                !is_array($variant)
                || ($variant['type'] ?? null) !== 'object'
                || ($variant['additionalProperties'] ?? null) !== false
                || !is_array($variant['properties'] ?? null)
                || !is_array($variant['required'] ?? null)
            ) {
                throw new InvalidArgumentException('Public API cart-command variant is invalid.');
            }
            $type = $variant['properties']['type']['const'] ?? null;
            if (!is_string($type) || isset($seen[$type])) {
                throw new InvalidArgumentException('Public API cart-command type is invalid.');
            }
            $seen[$type] = true;
        }
        $types = array_keys($seen);
        sort($types, SORT_STRING);
        if ($types !== array('add', 'clear', 'remove', 'replace', 'update')) {
            throw new InvalidArgumentException('Public API cart-command types are invalid.');
        }
    }

    /** @param array<string,mixed> $data */
    private function assertScalarDefinition(
        array $data,
        string $name,
        int $minimumLength,
        int $maximumLength,
        string $patternPrefix
    ): void {
        $definition = $data['$defs'][$name] ?? null;
        $this->assertObjectKeys($definition, array('type', 'maxLength', 'minLength', 'pattern'), $name);
        if (
            ($definition['type'] ?? null) !== 'string'
            || ($definition['minLength'] ?? null) !== $minimumLength
            || ($definition['maxLength'] ?? null) !== $maximumLength
            || !is_string($definition['pattern'] ?? null)
            || strpos($definition['pattern'], $patternPrefix) !== 0
        ) {
            throw new InvalidArgumentException('Public API scalar definition is invalid: ' . $name . '.');
        }
    }

    /** @param array<string,mixed> $data @param array<int,string> $expected */
    private function assertDefinitionFields(array $data, string $name, array $expected): void
    {
        $properties = $data['$defs'][$name]['properties'] ?? null;
        $this->assertObjectKeys($properties, $expected, $name . ' properties');
    }

    /** @param mixed $value @param array<int,string> $expected */
    private function assertObjectKeys($value, array $expected, string $name): void
    {
        if (!is_array($value) || ($value !== array() && Arr::isList($value))) {
            throw new InvalidArgumentException('Public API ' . $name . ' contract is invalid.');
        }
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('Public API ' . $name . ' fields are invalid.');
        }
    }

    /** @param mixed $value @param array<int,string> $names */
    private function assertExactRefs($value, array $names, string $name): void
    {
        if (!is_array($value) || !Arr::isList($value) || count($value) !== count($names)) {
            throw new InvalidArgumentException('Public API ' . $name . ' is invalid.');
        }
        foreach ($names as $index => $definition) {
            if (
                !is_array($value[$index] ?? null)
                || array_keys($value[$index]) !== array('$ref')
                || ($value[$index]['$ref'] ?? null) !== '#/$defs/' . $definition
            ) {
                throw new InvalidArgumentException('Public API ' . $name . ' is invalid.');
            }
        }
    }

    /** @param mixed $value */
    private function assertInt($value, int $minimum, int $maximum, string $name): void
    {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('Public API ' . $name . ' is invalid.');
        }
    }

    /** @param mixed $value */
    private function assertStringList($value, int $minimum, int $maximum, string $name): void
    {
        if (!is_array($value) || !Arr::isList($value) || count($value) < $minimum || count($value) > $maximum) {
            throw new InvalidArgumentException('Public API ' . $name . ' is invalid.');
        }
        $seen = array();
        foreach ($value as $item) {
            if (
                !is_string($item)
                || preg_match('/^[a-z][a-z0-9_\/-]{0,127}$/', $item) !== 1
                || isset($seen[$item])
            ) {
                throw new InvalidArgumentException('Public API ' . $name . ' contains an invalid entry.');
            }
            $seen[$item] = true;
        }
    }
}
