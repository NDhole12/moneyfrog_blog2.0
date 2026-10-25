<?php

namespace MoneyfrogCore;

/**
 * Resolves the real visitor address and scheme when the site sits behind
 * Cloudflare. Pure: takes a $_SERVER-style array, touches no globals.
 *
 * Forwarded headers are only trusted when REMOTE_ADDR is a Cloudflare edge.
 */
final class ClientIp
{
    /**
     * Cloudflare published ranges, fetched 2026-10-25 from
     * https://www.cloudflare.com/ips-v4 and https://www.cloudflare.com/ips-v6
     */
    public const CLOUDFLARE_RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /** @param array<string,mixed> $server */
    public static function resolve(array $server): string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        $forwarded = (string) ($server['HTTP_CF_CONNECTING_IP'] ?? '');
        if (self::isCloudflare($remote) && filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
            return $forwarded;
        }
        return $remote;
    }

    /** @param array<string,mixed> $server */
    public static function isHttps(array $server): bool
    {
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if (!self::isCloudflare((string) ($server['REMOTE_ADDR'] ?? ''))) {
            return false;
        }
        return strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || str_contains(str_replace(' ', '', (string) ($server['HTTP_CF_VISITOR'] ?? '')), '"scheme":"https"');
    }

    public static function isCloudflare(string $ip): bool
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;
        if ($packed === false) {
            return false;
        }
        foreach (self::CLOUDFLARE_RANGES as $range) {
            if (self::inRange($packed, $range)) {
                return true;
            }
        }
        return false;
    }

    private static function inRange(string $packedIp, string $cidr): bool
    {
        [$base, $bits] = explode('/', $cidr);
        $packedBase = inet_pton($base);
        if ($packedBase === false || strlen($packedBase) !== strlen($packedIp)) {
            return false; // different address family
        }
        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        if (substr($packedIp, 0, $fullBytes) !== substr($packedBase, 0, $fullBytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($packedIp[$fullBytes]) & $mask) === (ord($packedBase[$fullBytes]) & $mask);
    }
}
