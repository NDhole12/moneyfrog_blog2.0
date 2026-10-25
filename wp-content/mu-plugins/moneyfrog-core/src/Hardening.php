<?php

namespace MoneyfrogCore;

/**
 * Front-end and login hardening.
 *
 * wp-config constants expected on every environment (set via the
 * WORDPRESS_CONFIG_EXTRA env var locally, wp-config.php on the server):
 *   define('DISALLOW_FILE_EDIT', true);
 *   define('DISALLOW_FILE_MODS', true);       // LIVE only; deploys ship plugins
 *   define('WP_AUTO_UPDATE_CORE', 'minor');
 */
final class Hardening
{
    public const CSP = "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://www.googletagmanager.com https://www.google-analytics.com; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: https:; "
        . "font-src 'self'; "
        . "connect-src 'self' https://www.googletagmanager.com https://www.google-analytics.com https://*.google-analytics.com https://analytics.google.com https://stats.g.doubleclick.net; "
        . 'frame-src https://www.googletagmanager.com https://www.youtube.com https://www.youtube-nocookie.com; '
        . "frame-ancestors 'self'; base-uri 'self'; form-action 'self'";

    public const LOGIN_MAX_FAILURES = 5;
    public const LOGIN_WINDOW_SECONDS = 900;

    public static function register(): void
    {
        add_filter('xmlrpc_enabled', '__return_false');
        add_filter('rest_endpoints', [self::class, 'filterRestEndpoints']);
        add_filter('wp_sitemaps_add_provider', [self::class, 'dropUsersSitemap'], 10, 2);
        add_action('rest_api_init', [self::class, 'hideAuthorLinks']);
        add_action('template_redirect', [self::class, 'blockAuthorEnumeration'], 0);
        add_action('send_headers', [self::class, 'sendSecurityHeaders']);

        add_filter('authenticate', [self::class, 'rejectIfRateLimited'], 99);
        add_action('wp_login_failed', [self::class, 'recordFailure']);
        add_action('wp_login', [self::class, 'clearFailures'], 10, 0);
        add_filter('login_errors', [self::class, 'genericLoginError']);
    }

    public const GENERIC_LOGIN_ERROR = "Those details didn't work. Please try again.";

    /** Login errors never say whether the username exists; only the rate-limit notice is passed through. */
    public static function genericLoginError(mixed $error): string
    {
        $text = is_string($error) ? $error : '';
        if ($text === '') {
            return '';
        }
        return str_contains($text, 'Too many failed login attempts') ? $text : self::GENERIC_LOGIN_ERROR;
    }

    /**
     * @param array<string,mixed> $endpoints
     * @return array<string,mixed>
     */
    public static function filterRestEndpoints(array $endpoints): array
    {
        if (is_user_logged_in()) {
            return $endpoints;
        }
        foreach (array_keys($endpoints) as $route) {
            if (str_starts_with((string) $route, '/wp/v2/users')) {
                unset($endpoints[$route]);
            }
        }
        return $endpoints;
    }

    public static function dropUsersSitemap(mixed $provider, string $name): mixed
    {
        return $name === 'users' ? false : $provider;
    }

    /** Logged-out REST responses carry no author link, so _embed adds no user data. */
    public static function hideAuthorLinks(): void
    {
        foreach (get_post_types(['show_in_rest' => true]) as $type) {
            add_filter("rest_prepare_{$type}", static function ($response) {
                if (!is_user_logged_in() && $response instanceof \WP_REST_Response) {
                    $response->remove_link('author');
                }
                return $response;
            });
        }
    }

    public static function blockAuthorEnumeration(): void
    {
        global $wp_query;
        if (isset($_GET['author']) && is_numeric($_GET['author'])) { // phpcs:ignore WordPress.Security.NonceVerification
            remove_action('template_redirect', 'redirect_canonical');
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
        }
    }

    public static function sendSecurityHeaders(): void
    {
        if (is_admin() || ($GLOBALS['pagenow'] ?? '') === 'wp-login.php') {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Content-Security-Policy: ' . self::CSP);
        if (ClientIp::isHttps($_SERVER)) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /** Runs after core's credential checks so a block cannot be overridden by them. */
    public static function rejectIfRateLimited(mixed $user): mixed
    {
        if (self::limiter()->isBlocked(self::limiterKey())) {
            return new \WP_Error(
                'mf_too_many_attempts',
                'Too many failed login attempts. Please try again in a few minutes.'
            );
        }
        return $user;
    }

    public static function recordFailure(): void
    {
        self::limiter()->recordFailure(self::limiterKey());
    }

    public static function clearFailures(): void
    {
        self::limiter()->clear(self::limiterKey());
    }

    private static function limiter(): LoginLimiter
    {
        return new LoginLimiter(
            static fn (string $key) => get_transient($key),
            static function (string $key, array $record, int $ttl): void {
                set_transient($key, $record, $ttl);
            },
            static function (string $key): void {
                delete_transient($key);
            },
            static fn (): int => time(),
            self::LOGIN_MAX_FAILURES,
            self::LOGIN_WINDOW_SECONDS
        );
    }

    private static function limiterKey(): string
    {
        return 'mf_login_fail_' . md5(ClientIp::resolve($_SERVER));
    }
}
