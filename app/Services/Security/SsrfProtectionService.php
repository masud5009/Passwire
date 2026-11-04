<?php

namespace App\Services\Security;

class SsrfProtectionService
{
    /**
     * Forbidden hostname exact matches.
     */
    protected const BLOCKED_HOST_PATTERNS = [
        'localhost',
        'metadata.google.internal',
        'instance-data',
    ];

    /**
     * Forbidden hostname suffixes.
     */
    protected const BLOCKED_SUFFIXES = [
        '.localhost',
        '.local',
        '.internal',
    ];

    /**
     * Check if a URL is safe from SSRF attacks (public HTTP/HTTPS only, no private/metadata IPs).
     *
     * @param string $url
     * @param bool|null $allowPrivateNetworks Optional override (defaults to config/env)
     * @return bool
     */
    public function isSafeUrl(string $url, ?bool $allowPrivateNetworks = null): bool
    {
        $allowPrivate = $allowPrivateNetworks ?? (bool) config('services.gateway.allow_private_network', env('ALLOW_PRIVATE_NETWORK_PROVIDERS', false));

        if (empty($url) || !is_string($url)) {
            return false;
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        // 1. Strict scheme check: Only http and https allowed
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower(trim($parts['host']));

        // Strip IPv6 square brackets if present e.g. [::1] -> ::1
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        // 2. Cloud metadata endpoint is unconditionally blocked ALWAYS (even in dev)
        if ($this->isMetadataEndpoint($host)) {
            return false;
        }

        // 3. Blocked hostnames and suffixes (e.g. localhost, *.local, *.internal)
        if (!$allowPrivate) {
            if (in_array($host, self::BLOCKED_HOST_PATTERNS, true)) {
                return false;
            }
            foreach (self::BLOCKED_SUFFIXES as $suffix) {
                if (str_ends_with($host, $suffix)) {
                    return false;
                }
            }
        }

        // 4. If host is directly an IP address
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if ($this->isMetadataIp($host)) {
                return false;
            }
            if (!$allowPrivate && $this->isPrivateOrReservedIp($host)) {
                return false;
            }
            return true;
        }

        // 5. Hostname DNS resolution check (prevents DNS rebinding to internal addresses)
        if (!$allowPrivate) {
            $resolvedIps = $this->resolveHost($host);
            foreach ($resolvedIps as $ip) {
                if ($this->isMetadataIp($ip) || $this->isPrivateOrReservedIp($ip)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Throw an exception if the URL is not safe.
     *
     * @throws \InvalidArgumentException
     */
    public function assertSafeUrl(string $url, ?bool $allowPrivateNetworks = null): void
    {
        if (!$this->isSafeUrl($url, $allowPrivateNetworks)) {
            throw new \InvalidArgumentException("SSRF Protection: The target URL '{$url}' points to an insecure, private, or metadata endpoint.");
        }
    }

    /**
     * Check if host points to a known cloud metadata host or IP.
     */
    public function isMetadataEndpoint(string $host): bool
    {
        if ($this->isMetadataIp($host)) {
            return true;
        }

        if (in_array($host, ['169.254.169.254', 'metadata.google.internal', 'instance-data', '100.100.100.200'], true)) {
            return true;
        }

        return false;
    }

    /**
     * Check if IP is in the 169.254.0.0/16 link-local/cloud metadata subnet.
     */
    public function isMetadataIp(string $ip): bool
    {
        return str_starts_with($ip, '169.254.');
    }

    /**
     * Check if IP is private, loopback, or reserved.
     */
    public function isPrivateOrReservedIp(string $ip): bool
    {
        if ($this->isMetadataIp($ip)) {
            return true;
        }

        // filter_var returns false for private or reserved IPs when flags are set
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return $isPublic === false;
    }

    /**
     * Resolve a host to IPv4 and IPv6 addresses.
     *
     * @return array<int, string>
     */
    protected function resolveHost(string $host): array
    {
        $ips = [];

        // IPv4 resolution
        $v4List = @gethostbynamel($host);
        if (is_array($v4List)) {
            $ips = array_merge($ips, $v4List);
        }

        // IPv6 resolution via dns_get_record if supported
        if (function_exists('dns_get_record')) {
            $v6Records = @dns_get_record($host, DNS_AAAA);
            if (is_array($v6Records)) {
                foreach ($v6Records as $record) {
                    if (!empty($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        return array_unique($ips);
    }
}

