<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Security;

use WP_REST_Request;
use YassinStore\AiAssistant\Infrastructure\WordPress\Capabilities;
use YassinStore\AiAssistant\Application\Contract\PublicApiContract;
use YassinStore\AiAssistant\Infrastructure\Runtime\ImageRuntimeCapability;

final class RequestGuard
{
    /** @var Capabilities */
    private $capabilities;

    /** @var OriginPolicy */
    private $origins;

    /** @var PublicApiContract */
    private $contract;

    /** @var ImageRuntimeCapability */
    private $imageRuntime;

    /** @var ClientIpResolver */
    private $clientIps;

    public function __construct(
        Capabilities $capabilities,
        OriginPolicy $origins,
        PublicApiContract $contract,
        ImageRuntimeCapability $imageRuntime,
        ClientIpResolver $clientIps
    ) {
        $this->capabilities = $capabilities;
        $this->origins = $origins;
        $this->contract = $contract;
        $this->imageRuntime = $imageRuntime;
        $this->clientIps = $clientIps;
    }
    /** @return array{code:string,message:string,status:int}|null */
    public function publicRejection(WP_REST_Request $request): ?array
    {
        if (!$this->isSameOrigin()) {
            return array(
                'code' => 'origin_rejected',
                'message' => ('تم رفض مصدر الطلب.'),
                'status' => 403,
            );
        }

        $declaredLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        $actualLength = strlen((string) $request->get_body());
        if (
            $declaredLength > $this->contract->maxBodyBytes()
            || $actualLength > $this->contract->maxBodyBytes()
        ) {
            return array(
                'code' => 'request_too_large',
                'message' => ('حجم الطلب أكبر من المسموح.'),
                'status' => 413,
            );
        }
        if (!$this->imageRuntime->canParseBody($actualLength)) {
            return array(
                'code' => 'request_memory_unavailable',
                'message' => ('لا تتوفر ذاكرة كافية لمعالجة هذا الطلب بأمان.'),
                'status' => 503,
            );
        }

        return null;
    }

    /** @return array{code:string,message:string,status:int}|null */
    public function adminRejection(WP_REST_Request $request): ?array
    {
        if (!$this->capabilities->currentUserCanManage()) {
            return array('code' => 'forbidden', 'message' => 'غير مسموح.', 'status' => 403);
        }

        return null;
    }

    public function clientIp(): string
    {
        return $this->clientIps->resolve();
    }

    private function isSameOrigin(): bool
    {
        $raw = '';
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- exact origin bytes are unslashed, sanitized below, and rejected if sanitization changes them.
        if (isset($_SERVER['HTTP_ORIGIN']) && is_string($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== '') {
            $raw = wp_unslash($_SERVER['HTTP_ORIGIN']);
        } elseif (isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER'] !== '') {
            $raw = wp_unslash($_SERVER['HTTP_REFERER']);
        }
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ($raw === '') {
            return true;
        }
        $sanitized = sanitize_text_field($raw);
        if (!hash_equals($raw, $sanitized)) {
            return false;
        }

        return $this->origins->allows($sanitized, home_url('/'));
    }
}
