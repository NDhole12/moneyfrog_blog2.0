<?php
/**
 * Plugin Name: Moneyfrog Core
 * Description: Must-use plugin for the Moneyfrog Insights blog. Built by Codespark.
 * Version: 0.1.0
 */

defined('ABSPATH') || exit;

$moneyfrog_core_dir = __DIR__ . '/moneyfrog-core';

foreach (glob($moneyfrog_core_dir . '/src/*.php') ?: [] as $moneyfrog_core_file) {
    require_once $moneyfrog_core_file;
}

if (defined('WP_CLI') && WP_CLI) {
    foreach (glob($moneyfrog_core_dir . '/src/Cli/*.php') ?: [] as $moneyfrog_core_file) {
        require_once $moneyfrog_core_file;
    }
    WP_CLI::add_command('mf import', \MoneyfrogCore\Cli\ImportCommand::class);
}

\MoneyfrogCore\Hardening::register();
\MoneyfrogCore\CommentsOff::register();
\MoneyfrogCore\RedirectHooks::register();
\MoneyfrogCore\Byline::register();
\MoneyfrogCore\ContentHooks::register();
\MoneyfrogCore\ServiceUser::register();

// Marker header on REST responses, used by the smoke tests.
add_filter('rest_post_dispatch', static function ($response) {
    if ($response instanceof WP_REST_Response) {
        $response->header('X-MF-Core', '1');
    }
    return $response;
});
