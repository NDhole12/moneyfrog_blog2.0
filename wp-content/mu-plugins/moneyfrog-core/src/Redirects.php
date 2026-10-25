<?php

namespace MoneyfrogCore;

/**
 * Pure old-URL redirect lookup. Makes no WordPress calls.
 * Map keys must already be normalised with normalisePath().
 */
final class Redirects
{
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
}
