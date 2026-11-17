<?php
/**
 * Plugin Name: Moneyfrog Core
 * Description: Must-use plugin for the Moneyfrog Insights blog. Built by Codespark.
 * Version: 0.1.0
 */

defined('ABSPATH') || exit;

// Do not advertise the PHP version (front end, REST, wp-admin and wp-login all load mu-plugins).
// Belt and braces with `expose_php = Off` in the docroot .user.ini (see infra/provision.md).
if (!headers_sent()) {
    header_remove('X-Powered-By');
}
add_action('send_headers', static function (): void {
    if (!headers_sent()) {
        header_remove('X-Powered-By');
    }
}, PHP_INT_MAX);
add_filter('wp_headers', static function (array $headers): array {
    unset($headers['X-Powered-By']);
    return $headers;
}, PHP_INT_MAX);

$moneyfrog_core_dir = __DIR__ . '/moneyfrog-core';

foreach (glob($moneyfrog_core_dir . '/src/*.php') ?: [] as $moneyfrog_core_file) {
    require_once $moneyfrog_core_file;
}

if (defined('WP_CLI') && WP_CLI) {
    foreach (glob($moneyfrog_core_dir . '/src/Cli/*.php') ?: [] as $moneyfrog_core_file) {
        require_once $moneyfrog_core_file;
    }
    WP_CLI::add_command('mf import', \MoneyfrogCore\Cli\ImportCommand::class);
    WP_CLI::add_command('mf audit', \MoneyfrogCore\Cli\AuditCommand::class);
    WP_CLI::add_command('mf warm', \MoneyfrogCore\Cli\WarmCommand::class);
}

\MoneyfrogCore\Hardening::register();
\MoneyfrogCore\CommentsOff::register();
\MoneyfrogCore\RedirectHooks::register();
\MoneyfrogCore\Byline::register();
\MoneyfrogCore\BylineAdmin::register();
\MoneyfrogCore\ContentHooks::register();
\MoneyfrogCore\Interests::register();
\MoneyfrogCore\ServiceUser::register();
\MoneyfrogCore\Release::register();
\MoneyfrogCore\AdminBrand::register();

// Marker header on REST responses, used by the smoke tests.
add_filter('rest_post_dispatch', static function ($response) {
    if ($response instanceof WP_REST_Response) {
        $response->header('X-MF-Core', '1');
    }
    return $response;
});
