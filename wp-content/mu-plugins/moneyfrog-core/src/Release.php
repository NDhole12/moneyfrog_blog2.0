<?php

namespace MoneyfrogCore;

/** Deployed release marker: `wp-content/.mf-release` holds the full 40-hex git SHA. */
final class Release
{
    public static function parse(string $contents): ?string
    {
        $sha = trim($contents);
        return preg_match('/\A[0-9a-f]{40}\z/', $sha) === 1 ? $sha : null;
    }

    public static function short(string $sha): string
    {
        return substr($sha, 0, 12);
    }

    public static function read(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path, false, null, 0, 128);
        return is_string($raw) ? self::parse($raw) : null;
    }

    /** Sends X-MF-Release on front-end and REST responses; read once per request, silent when absent. */
    public static function register(): void
    {
        $cache = [];
        $resolve = static function () use (&$cache): ?string {
            if (!array_key_exists('v', $cache)) {
                $sha = self::read(WP_CONTENT_DIR . '/.mf-release');
                $cache['v'] = $sha === null ? null : self::short($sha);
            }
            return $cache['v'];
        };
        add_action('send_headers', static function () use ($resolve): void {
            $v = $resolve();
            if ($v !== null && !headers_sent()) {
                header('X-MF-Release: ' . $v);
            }
        });
        add_filter('rest_post_dispatch', static function ($response) use ($resolve) {
            $v = $resolve();
            if ($v !== null && $response instanceof \WP_REST_Response) {
                $response->header('X-MF-Release', $v);
            }
            return $response;
        });
    }
}
