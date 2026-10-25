<?php
/** Moneyfrog Insights theme setup. */

if (!defined('ABSPATH')) {
    exit;
}

require_once get_template_directory() . '/inc/icons.php';
require_once get_template_directory() . '/inc/gtm.php';
require_once get_template_directory() . '/inc/tag-label.php';
require_once get_template_directory() . '/inc/queries.php';
require_once get_template_directory() . '/inc/single.php';
require_once get_template_directory() . '/inc/seo.php';
require_once get_template_directory() . '/inc/login.php';

add_action('after_setup_theme', static function (): void {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'gallery', 'caption', 'style', 'script']);
    add_image_size('mf-card', 640, 400, true);
    add_image_size('mf-hero', 1600, 686, true);
});

add_action('wp_enqueue_scripts', static function (): void {
    $dir = get_template_directory();
    $uri = get_template_directory_uri();
    $ver = static fn (string $file): string => (string) (filemtime($dir . $file) ?: '1');

    wp_enqueue_style('mf-tokens', $uri . '/assets/css/tokens.css', [], $ver('/assets/css/tokens.css'));
    wp_enqueue_style('mf-base', $uri . '/assets/css/base.css', ['mf-tokens'], $ver('/assets/css/base.css'));
    wp_enqueue_style('mf-components', $uri . '/assets/css/components.css', ['mf-base'], $ver('/assets/css/components.css'));
    wp_enqueue_style('mf-pages', $uri . '/assets/css/pages.css', ['mf-components'], $ver('/assets/css/pages.css'));
    wp_enqueue_script('mf-nav', $uri . '/assets/js/nav.js', [], $ver('/assets/js/nav.js'), ['in_footer' => true, 'strategy' => 'defer']);
});

// Drop emoji detection (inline script + extra stylesheet) and the unused block-library CSS.
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');
add_action('wp_enqueue_scripts', static function (): void {
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('global-styles');
    wp_dequeue_style('classic-theme-styles');
}, 100);

/** Absolute URL on the main site, e.g. mf_site_url('/login'). */
function mf_site_url(string $path = ''): string
{
    return \MoneyfrogCore\Config::SITE_URL . $path;
}

/** Slug of the topic being viewed, or null when not on a topic archive. */
function mf_current_topic(): ?string
{
    if (!is_category()) {
        return null;
    }
    $term = get_queried_object();
    return $term instanceof WP_Term ? $term->slug : null;
}
