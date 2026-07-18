<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Security;

use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;

final class TrustedProxyPolicy
{
    /** @var Settings */
    private $settings;

    /** @var array<int,string>|null */
    private $cidrs;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
        $this->cidrs = null;
    }

    public function isTrusted(string $ip): bool
    {
        $normalized = IpNetwork::normalize($ip);
        if ($normalized === '') {
            return false;
        }
        foreach ($this->cidrs() as $cidr) {
            if (IpNetwork::contains($cidr, $normalized)) {
                return true;
            }
        }
        return false;
    }

    public function configuredCount(): int
    {
        return count($this->cidrs());
    }

    /** @return array<int,string> */
    public function cidrs(): array
    {
        if ($this->cidrs !== null) {
            return $this->cidrs;
        }

        $raw = (string) $this->settings->get('trusted_proxy_cidrs', '');
        $parts = preg_split('/[\s,]+/', trim($raw));
        $valid = array();
        foreach (is_array($parts) ? $parts : array() as $part) {
            $cidr = IpNetwork::canonicalCidr((string) $part);
            if ($cidr !== '') {
                $valid[$cidr] = true;
            }
        }
        $this->cidrs = array_keys($valid);
        return $this->cidrs;
    }
}
