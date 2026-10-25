<?php

namespace MoneyfrogCore;

/** WordPress glue for Redirects: 301 old URLs before WordPress decides a 404. */
final class RedirectHooks
{
    private const ALLOWED_HOSTS = ['moneyfrog.in', 'blog.moneyfrog.in'];

    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'maybeRedirect'], 0);
    }

    public static function maybeRedirect(): void
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($uri === '' || is_admin()) {
            return;
        }
        $target = Redirects::lookup($uri, self::map());
        if ($target === null) {
            return;
        }
        $current = Redirects::normalisePath($uri);
        $targetPath = (string) wp_parse_url($target, PHP_URL_PATH);
        $targetHost = (string) wp_parse_url($target, PHP_URL_HOST);
        $ownHost = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        if ($targetHost === '' || strcasecmp($targetHost, $ownHost) === 0) {
            if (Redirects::normalisePath($targetPath) === $current) {
                return;
            }
            wp_safe_redirect($target, 301);
            exit;
        }
        if (in_array(strtolower($targetHost), self::ALLOWED_HOSTS, true)) {
            wp_redirect($target, 301);
            exit;
        }
    }

    /** @return array<string,string> */
    private static function map(): array
    {
        static $map = null;
        if ($map === null) {
            $base = require dirname(__DIR__) . '/data/redirects.php';
            $extra = apply_filters('mf_redirects_extra', []);
            $map = array_merge(is_array($base) ? $base : [], is_array($extra) ? $extra : []);
        }
        return $map;
    }
}
