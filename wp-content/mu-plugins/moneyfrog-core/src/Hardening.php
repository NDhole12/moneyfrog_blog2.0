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
        add_action('template_redirect', [self::class, 'blockAuthorEnumeration'], 0);
        add_action('send_headers', [self::class, 'sendSecurityHeaders']);

        add_filter('authenticate', [self::class, 'rejectIfRateLimited'], 99);
        add_action('wp_login_failed', [self::class, 'recordFailure']);
        add_action('wp_login', [self::class, 'clearFailures'], 10, 0);
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
        if (is_ssl()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /** Runs after core's credential checks so a block cannot be overridden by them. */
    public static function rejectIfRateLimited(mixed $user): mixed
    {
        if (self::failureRecord()['count'] >= self::LOGIN_MAX_FAILURES) {
            return new \WP_Error(
                'mf_too_many_attempts',
                'Too many failed login attempts. Please try again in a few minutes.'
            );
        }
        return $user;
    }

    public static function recordFailure(): void
    {
        $record = self::failureRecord();
        if ($record['count'] >= self::LOGIN_MAX_FAILURES) {
            return; // already blocked; do not extend the window
        }
        $expires = $record['expires'] ?: time() + self::LOGIN_WINDOW_SECONDS;
        set_transient(
            self::transientKey(),
            ['count' => $record['count'] + 1, 'expires' => $expires],
            max(1, $expires - time())
        );
    }

    public static function clearFailures(): void
    {
        delete_transient(self::transientKey());
    }

    /** @return array{count:int,expires:int} */
    private static function failureRecord(): array
    {
        $record = get_transient(self::transientKey());
        if (!is_array($record) || ($record['expires'] ?? 0) <= time()) {
            return ['count' => 0, 'expires' => 0];
        }
        return ['count' => (int) $record['count'], 'expires' => (int) $record['expires']];
    }

    private static function transientKey(): string
    {
        return 'mf_login_fail_' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }
}
