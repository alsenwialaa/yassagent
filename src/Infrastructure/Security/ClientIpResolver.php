<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Security;

final class ClientIpResolver
{
    private const MAX_FORWARDED_HEADER_BYTES = 1024;
    private const MAX_FORWARDED_HOPS = 16;

    /** @var TrustedProxyPolicy */
    private $trustedProxies;

    public function __construct(TrustedProxyPolicy $trustedProxies)
    {
        $this->trustedProxies = $trustedProxies;
    }

    public function resolve(): string
    {
        $diagnostics = $this->diagnostics();
        return (string) $diagnostics['resolved_ip'];
    }

    /** @return array{mode:string,remote_ip:string,resolved_ip:string,header_status:string,trusted_proxy_count:int} */
    public function diagnostics(): array
    {
        $remoteSource = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';
        $remote = IpNetwork::normalize($remoteSource);
        if ($remote === '') {
            return array(
                'mode' => 'unavailable',
                'remote_ip' => 'unknown',
                'resolved_ip' => 'unknown',
                'header_status' => 'remote_invalid',
                'trusted_proxy_count' => $this->trustedProxies->configuredCount(),
            );
        }

        $count = $this->trustedProxies->configuredCount();
        $rawForwarded = isset($_SERVER['HTTP_X_FORWARDED_FOR']) && is_string($_SERVER['HTTP_X_FORWARDED_FOR'])
            ? trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])))
            : '';
        if (!$this->trustedProxies->isTrusted($remote)) {
            return array(
                'mode' => 'direct_peer',
                'remote_ip' => $remote,
                'resolved_ip' => $remote,
                'header_status' => $rawForwarded === '' ? 'absent' : 'ignored_untrusted_peer',
                'trusted_proxy_count' => $count,
            );
        }

        if ($rawForwarded === '') {
            return array(
                'mode' => 'trusted_proxy',
                'remote_ip' => $remote,
                'resolved_ip' => $remote,
                'header_status' => 'missing',
                'trusted_proxy_count' => $count,
            );
        }
        if (strlen($rawForwarded) > self::MAX_FORWARDED_HEADER_BYTES) {
            return array(
                'mode' => 'trusted_proxy',
                'remote_ip' => $remote,
                'resolved_ip' => $remote,
                'header_status' => 'invalid',
                'trusted_proxy_count' => $count,
            );
        }

        $parts = array_map('trim', explode(',', $rawForwarded));
        if ($parts === array() || count($parts) > self::MAX_FORWARDED_HOPS) {
            return array(
                'mode' => 'trusted_proxy',
                'remote_ip' => $remote,
                'resolved_ip' => $remote,
                'header_status' => 'invalid',
                'trusted_proxy_count' => $count,
            );
        }

        $hops = array();
        foreach ($parts as $part) {
            $hop = IpNetwork::normalize($part);
            if ($hop === '') {
                return array(
                    'mode' => 'trusted_proxy',
                    'remote_ip' => $remote,
                    'resolved_ip' => $remote,
                    'header_status' => 'invalid',
                    'trusted_proxy_count' => $count,
                );
            }
            $hops[] = $hop;
        }

        $resolved = $remote;
        for ($index = count($hops) - 1; $index >= 0; $index--) {
            if (!$this->trustedProxies->isTrusted($resolved)) {
                break;
            }
            $resolved = $hops[$index];
        }

        return array(
            'mode' => 'trusted_proxy',
            'remote_ip' => $remote,
            'resolved_ip' => $resolved,
            'header_status' => 'accepted',
            'trusted_proxy_count' => $count,
        );
    }
}
