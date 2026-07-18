<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use YassinStore\AiAssistant\Application\Ai\ImageAttachmentPolicy;
use YassinStore\AiAssistant\Application\Ai\ModelRequest;
use YassinStore\AiAssistant\Application\Contract\PublicApiContract;
use YassinStore\AiAssistant\Infrastructure\Runtime\ImageRuntimeCapability;
use YassinStore\AiAssistant\Presentation\Rest\ImageAttachmentDecoder;
use YassinStore\AiAssistant\Support\Json;

$contractRaw = file_get_contents(dirname(__DIR__) . '/config/public-api-contract.json');
if (!is_string($contractRaw)) { exit(2); }
$contract = new PublicApiContract(Json::decodeRequiredObject($contractRaw, 'Probe contract'));
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZJXcAAAAASUVORK5CYII=', true);
if (!is_string($png)) { exit(3); }
$first = base64_encode($png . str_repeat("\0", ImageAttachmentPolicy::MAX_DECODED_BYTES - strlen($png)));
$second = $first;
unset($png);
$baseline = memory_get_usage(true);
$raw = Json::encodeObject(array('attachments' => array(
    array('mime_type' => 'image/png', 'data' => $first),
    array('mime_type' => 'image/png', 'data' => $second),
)));
$body = Json::decodeRequiredObject($raw, 'Probe body');
$runtime = new ImageRuntimeCapability();
$decoder = new ImageAttachmentDecoder($contract, $runtime);
$attachments = $decoder->decode((array) $body['attachments'], true);
$request = new ModelRequest(
    'System instruction',
    array(),
    'Inspect the images.',
    $attachments,
    array(array('name' => 'cart_view', 'description' => 'View cart.', 'parameters' => array('type' => 'object'))),
    256
);
$parts = array(array('text' => $request->userText()));
foreach ($request->attachments() as $attachment) {
    $parts[] = array('inlineData' => array('mimeType' => $attachment->mimeType(), 'data' => $attachment->base64Data()));
}
$providerJson = Json::encodeObject(array('contents' => array(array('role' => 'user', 'parts' => $parts))));
if ($providerJson === '' || count($attachments) !== 2) { exit(4); }
echo 'status=ok' . PHP_EOL;
echo 'peak_delta=' . max(0, memory_get_peak_usage(true) - $baseline) . PHP_EOL;
