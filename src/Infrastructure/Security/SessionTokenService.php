<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Security;

use RuntimeException;
use YassinStore\AiAssistant\Application\Port\BrowserContinuityAuthorityPort;
use YassinStore\AiAssistant\Support\Base64Url;
use YassinStore\AiAssistant\Support\BrowserContinuitySecret;
use YassinStore\AiAssistant\Support\Json;

/** Signed continuity token backed only by the browser continuity authority. */
final class SessionTokenService
{
    private const TOKEN_TTL = 7200;

    /** @var BrowserContinuityAuthorityPort */ private $authorities;

    public function __construct(BrowserContinuityAuthorityPort $authorities)
    {
        $this->authorities = $authorities;
    }

    /** @return array<string,string|int> */
    public function issue(
        string $browserContinuitySecret,
        string $previousBrowserContinuitySecret
    ): array {
        if (!BrowserContinuitySecret::isValid($browserContinuitySecret)) {
            throw new RuntimeException('Browser continuity credential is invalid.');
        }
        if (
            $previousBrowserContinuitySecret !== ''
            && (!BrowserContinuitySecret::isValid($previousBrowserContinuitySecret)
                || hash_equals($browserContinuitySecret, $previousBrowserContinuitySecret))
        ) {
            throw new RuntimeException('Browser continuity rotation proof is invalid.');
        }
        if ($previousBrowserContinuitySecret !== '') {
            $nonce = $this->authorities->rotate(
                $this->secretHash($previousBrowserContinuitySecret),
                $this->secretHash($browserContinuitySecret)
            );
        } else {
            $nonce = $this->authorities->activate(
                $this->secretHash($browserContinuitySecret)
            );
        }

        $issued = time();
        $payload = array(
            'v' => 1,
            'iat' => $issued,
            'exp' => $issued + self::TOKEN_TTL,
            'site' => get_current_blog_id(),
            'nonce' => $nonce,
        );
        $encoded = $this->base64UrlEncode(Json::encodeObject($payload));
        $signature = $this->sign($encoded);

        return array(
            'token' => $encoded . '.' . $signature,
            'expires_at' => (int) $payload['exp'],
            'session_hash' => $this->sessionHash($nonce),
        );
    }

    public function validateTransport(string $token): string
    {
        return $this->sessionHash($this->validatedNonce($token));
    }

    public function assertActive(string $token, string $expectedSessionHash): void
    {
        $nonce = $this->validatedNonce($token);
        if (!hash_equals($expectedSessionHash, $this->sessionHash($nonce))) {
            throw new RuntimeException('Assistant session transport identity changed.');
        }
        $this->authorities->assertActiveNonce($nonce);
    }

    private function validatedNonce(string $token): string
    {
        if ($token === '' || strlen($token) > 2048 || substr_count($token, '.') !== 1) {
            throw new RuntimeException('Invalid assistant session token.');
        }

        list($encoded, $signature) = explode('.', $token, 2);
        if (
            $encoded === ''
            || preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1
            || !hash_equals($this->sign($encoded), $signature)
        ) {
            throw new RuntimeException('Invalid assistant session token signature.');
        }

        $decoded = $this->base64UrlDecode($encoded);
        if (!hash_equals($this->base64UrlEncode($decoded), $encoded)) {
            throw new RuntimeException('Assistant session token is not canonically encoded.');
        }
        $payload = Json::decodeRequiredObject($decoded, 'Assistant session token payload');
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== array('exp', 'iat', 'nonce', 'site', 'v')) {
            throw new RuntimeException('Assistant session token has an unsupported payload shape.');
        }

        if (
            !is_int($payload['v'])
            || !is_int($payload['iat'])
            || !is_int($payload['exp'])
            || !is_int($payload['site'])
            || !is_string($payload['nonce'])
        ) {
            throw new RuntimeException('Assistant session token has invalid field types.');
        }

        $now = time();
        $issued = $payload['iat'];
        $expires = $payload['exp'];
        if (
            $payload['v'] !== 1
            || $payload['site'] !== get_current_blog_id()
            || $expires <= $now
            || $issued > $now + 60
            || $issued < 1
            || $expires <= $issued
            || ($expires - $issued) > self::TOKEN_TTL + 60
            || !$this->isNonce($payload['nonce'])
        ) {
            throw new RuntimeException('Assistant session token is expired or invalid.');
        }

        return $payload['nonce'];
    }

    private function sessionHash(string $nonce): string
    {
        return hash_hmac(
            'sha256',
            (string) get_current_blog_id() . '|' . $nonce,
            wp_salt('secure_auth')
        );
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, wp_salt('nonce'));
    }

    private function secretHash(string $browserContinuitySecret): string
    {
        return hash_hmac(
            'sha256',
            'ysai-browser-continuity-authority-v1|' . (string) get_current_blog_id()
                . '|' . $browserContinuitySecret,
            wp_salt('secure_auth')
        );
    }

    private function isNonce(string $value): bool
    {
        return BrowserContinuitySecret::isValid($value);
    }

    private function base64UrlEncode(string $value): string
    {
        return Base64Url::encode($value);
    }

    private function base64UrlDecode(string $value): string
    {
        try {
            return Base64Url::decode($value);
        } catch (\InvalidArgumentException $exception) {
            throw new RuntimeException('Invalid token encoding.', 0, $exception);
        }
    }
}
