<?php
/**
 * Branded wp-login.php (log in, lost password, Two-Factor code screen): Moneyfrog logo, cream page,
 * white card, gold button. Self-hosted assets only; no scripts.
 */

use MoneyfrogCore\Config;

if (!defined('ABSPATH')) {
    exit;
}

add_action('login_enqueue_scripts', static function (): void {
    $uri = get_template_directory_uri();
    $dir = get_template_directory();
    $ver = static fn (string $path): string => (string) (@filemtime($dir . $path) ?: wp_get_theme()->get('Version'));

    wp_enqueue_style('mf-tokens', $uri . '/assets/css/tokens.css', [], $ver('/assets/css/tokens.css'));
    wp_enqueue_style('mf-login', $uri . '/assets/css/login.css', ['mf-tokens'], $ver('/assets/css/login.css'));
    wp_add_inline_style('mf-login', ':root{--mf-login-logo:url("' . esc_url($uri . '/assets/img/moneyfrog-logo.svg') . '")}');
});

add_filter('login_headerurl', static fn (): string => Config::SITE_URL);
add_filter('login_headertext', static fn (): string => 'Moneyfrog');
add_filter('login_title', static function (string $title, string $page): string {
    $page = $page === 'Log In' ? 'Log in' : $page;
    return $page . ' ‹ Moneyfrog Insights';
}, 10, 2);

add_action('login_footer', static function (): void {
    echo '<p class="mf-login-note">Moneyfrog Insights &middot; editor access</p>';
});
