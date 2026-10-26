<?php

namespace Acms\Plugins\DF_Like\Services;

class LikeClientIp
{
    /**
     * Trust only explicitly configured proxy IPs/CIDRs. The server must supply
     * the connection peer in REMOTE_ADDR and proxies must append/replace XFF.
     * Invalid configuration or any malformed header falls back to the peer.
     */
    public static function resolve(array $server, array $trustedProxies = []): string
    {
        $remote = self::normalize($server['REMOTE_ADDR'] ?? null);
        if ($remote === '' || !$trustedProxies) {
            return $remote;
        }

        $networks = [];
        foreach ($trustedProxies as $proxy) {
            if (!is_string($proxy)) {
                return $remote;
            }
            $parts = explode('/', trim($proxy, " \t"));
            $address = self::normalize($parts[0]);
            if ($address === '' || count($parts) > 2) {
                return $remote;
            }
            $packed = inet_pton($address);
            $bits = strlen($packed) * 8;
            $prefix = $parts[1] ?? (string)$bits;
            if (!preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/', $prefix) || (int)$prefix > $bits) {
                return $remote;
            }
            $networks[] = [$packed, (int)$prefix];
        }
        if (!self::isTrusted($remote, $networks)) {
            return $remote;
        }

        $forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? null;
        if (!is_string($forwarded) || strlen($forwarded) > 8192) {
            return $remote;
        }
        $parts = explode(',', $forwarded);
        if (count($parts) > 64) {
            return $remote;
        }
        $chain = [];
        foreach ($parts as $part) {
            $address = self::normalize(trim($part, " \t"));
            if ($address === '') {
                return $remote;
            }
            $chain[] = $address;
        }

        // Walk from the connection peer toward the client. Values to the left
        // of the first untrusted hop cannot be vouched for by a trusted proxy.
        $address = $remote;
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (!self::isTrusted($address, $networks)) {
                break;
            }
            $address = $chain[$i];
        }
        return $address;
    }

    private static function normalize($address): string
    {
        if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        // Canonicalize IPv6 spelling before hashing. IPv4-mapped IPv6 remains
        // IPv6 and needs an explicit IPv6 trust entry; families never mix.
        return inet_ntop(inet_pton($address));
    }

    private static function isTrusted(string $address, array $networks): bool
    {
        $packed = inet_pton($address);
        foreach ($networks as $network) {
            [$subnet, $prefix] = $network;
            if (strlen($packed) !== strlen($subnet)) {
                continue;
            }
            $bytes = intdiv($prefix, 8);
            if (substr($packed, 0, $bytes) !== substr($subnet, 0, $bytes)) {
                continue;
            }
            $remaining = $prefix % 8;
            if ($remaining === 0) {
                return true;
            }
            $mask = (0xff << (8 - $remaining)) & 0xff;
            if ((ord($packed[$bytes]) & $mask) === (ord($subnet[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }
}
