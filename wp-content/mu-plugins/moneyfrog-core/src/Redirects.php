<?php

namespace MoneyfrogCore;

/**
 * Pure old-URL redirect lookup. Makes no WordPress calls.
 * Map keys must already be normalised with normalisePath().
 */
final class Redirects
{
    private const ALLOWED_HOSTS = ['moneyfrog.in', 'blog.moneyfrog.in'];

    public static function normalisePath(string $uri): string
    {
        $path = explode('#', explode('?', $uri, 2)[0], 2)[0];
        $path = rawurldecode($path);
        $path = str_replace("\u{FEFF}", '', $path);
        $path = mb_strtolower($path, 'UTF-8');
        $path = (string) preg_replace('#/{2,}#', '/', '/' . $path);
        return str_ends_with($path, '/') ? $path : $path . '/';
    }

    /** @param array<string,string> $map */
    public static function lookup(string $uri, array $map): ?string
    {
        $target = $map[self::normalisePath($uri)] ?? null;
        if (!is_string($target) || $target === '') {
            return null;
        }
        $beforeFragment = explode('#', $uri, 2)[0];
        $query = str_contains($beforeFragment, '?') ? explode('?', $beforeFragment, 2)[1] : '';
        if ($query === '') {
            return $target;
        }
        return $target . (str_contains($target, '?') ? '&' : '?') . $query;
    }

    /**
     * Old links ending in a byte-order mark (raw or %EF%BB%BF) point at a live post whose
     * clean slug is not in the redirect map (the map never shadows a live path).
     * Returns the clean path, query kept, or null when the request has no BOM.
     */
    public static function bomVariantTarget(string $uri): ?string
    {
        $beforeFragment = explode('#', $uri, 2)[0];
        [$rawPath, $query] = array_pad(explode('?', $beforeFragment, 2), 2, '');
        if (!str_contains(rawurldecode($rawPath), "\u{FEFF}")) {
            return null;
        }
        $clean = self::normalisePath($rawPath);
        return $query === '' ? $clean : $clean . '?' . $query;
    }

    /** Only GET and HEAD requests are redirected. */
    public static function isRedirectableMethod(string $method): bool
    {
        return in_array(strtoupper($method), ['GET', 'HEAD'], true);
    }

    /**
     * Decide whether a lookup target may be used. Returns the URL to redirect
     * to, or null when it must be ignored (loop, foreign host, host tricks).
     */
    public static function decide(string $target, string $ownHost, string $currentPath): ?string
    {
        if ($target === '' || str_starts_with($target, '//') || str_contains($target, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $target) === 1) {
            return null;
        }
        $parts = parse_url($target);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $own = strtolower($ownHost);
        if ($host === '') {
            if (!str_starts_with($target, '/') || isset($parts['scheme'])) {
                return null;
            }
        } elseif (!in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || ($host !== $own && !in_array($host, self::ALLOWED_HOSTS, true))) {
            return null;
        }
        if (($host === '' || $host === $own) && self::normalisePath((string) ($parts['path'] ?? '/')) === $currentPath) {
            return null;
        }
        return $target;
    }
}
