<?php

namespace MoneyfrogCore;

/** WordPress glue for Redirects: 301 old URLs before WordPress decides a 404. */
final class RedirectHooks
{
    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'maybeRedirect'], 0);
    }

    public static function maybeRedirect(): void
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($uri === '' || is_admin() || !Redirects::isRedirectableMethod((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
            return;
        }
        $target = Redirects::lookup($uri, self::map());
        if ($target === null) {
            return;
        }
        $ownHost = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $url = Redirects::decide($target, $ownHost, Redirects::normalisePath($uri));
        if ($url === null) {
            return;
        }
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if ($host === '' || strcasecmp($host, $ownHost) === 0) {
            wp_safe_redirect($url, 301);
        } else {
            wp_redirect($url, 301); // host already checked against the allow-list
        }
        exit;
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
