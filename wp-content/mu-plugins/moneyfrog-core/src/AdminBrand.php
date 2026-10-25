<?php

namespace MoneyfrogCore;

/**
 * Moneyfrog-branded WordPress admin: admin bar, colour scheme, footer, dashboard and favicon.
 * Lives in the must-use plugin so it survives a theme switch. No scripts, everything escaped.
 */
final class AdminBrand
{
    public const SCHEME = 'moneyfrog';
    public const FOOTER_TEXT = 'Moneyfrog Insights · editor area';

    /** Admin bar nodes that belong to the WordPress logo menu. */
    private const WP_LOGO_NODES = [
        'wp-logo', 'about', 'contribute', 'wp-logo-external', 'wporg', 'documentation', 'learn', 'support-forums', 'feedback',
    ];

    public static function register(): void
    {
        add_action('admin_bar_menu', [self::class, 'addHomeNode'], 1);
        add_action('admin_bar_menu', [self::class, 'removeWpLogo'], 999);
        // Registered immediately: core resolves the scheme's stylesheet the first time it builds the style list.
        self::registerColorScheme();
        add_filter('get_user_option_admin_color', [self::class, 'defaultScheme']);
        add_filter('admin_footer_text', [self::class, 'footerText']);
        add_filter('update_footer', [self::class, 'versionText'], 11);
        add_filter('update_right_now_text', '__return_empty_string');
        add_action('admin_init', [self::class, 'removeWelcomePanel']);
        add_action('wp_dashboard_setup', [self::class, 'tidyDashboard'], 20);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueBarStyle']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueBarStyle']);
        add_action('admin_head', [self::class, 'printFavicon']);
        add_action('login_head', [self::class, 'printFavicon']);
        add_action('wp_head', [self::class, 'printFrontFavicon'], 2);
    }

    private static function assetUrl(string $file): string
    {
        return plugins_url('moneyfrog-core/assets/' . $file, dirname(__DIR__, 2) . '/moneyfrog-core.php');
    }

    private static function assetVersion(string $file): string
    {
        $path = dirname(__DIR__) . '/assets/' . $file;
        return is_file($path) ? (string) filemtime($path) : '1';
    }

    public static function addHomeNode(\WP_Admin_Bar $bar): void
    {
        $bar->add_node([
            'id'    => 'mf-home',
            'title' => sprintf(
                '<img class="mf-frog" src="%s" width="18" height="18" alt=""><span class="ab-label">Moneyfrog Insights</span>',
                esc_url(self::assetUrl('favicon-frog.svg'))
            ),
            'href'  => home_url('/'),
            'meta'  => ['title' => 'Moneyfrog Insights'],
        ]);
    }

    public static function removeWpLogo(\WP_Admin_Bar $bar): void
    {
        $nodes = is_admin() ? array_merge(self::WP_LOGO_NODES, ['site-name']) : self::WP_LOGO_NODES;
        foreach ($nodes as $id) {
            $bar->remove_node($id);
        }
    }

    public static function registerColorScheme(): void
    {
        wp_admin_css_color(
            self::SCHEME,
            'Moneyfrog',
            self::assetUrl('admin-colors.css') . '?ver=' . self::assetVersion('admin-colors.css'),
            ['#16191d', '#8a6a1f', '#c29a2c', '#f9f8f4'],
            ['base' => '#e3c66a', 'focus' => '#ffffff', 'current' => '#ffffff']
        );
    }

    /**
     * Everyone gets the Moneyfrog scheme unless they explicitly saved another one. Core stores its own
     * default on every new user ("fresh", "modern" on current versions), so those count as "never chose".
     */
    public static function defaultScheme(mixed $value): mixed
    {
        return ($value === false || $value === null || $value === '' || $value === 'fresh' || $value === 'modern') ? self::SCHEME : $value;
    }

    public static function footerText(mixed $text = ''): string
    {
        return esc_html(self::FOOTER_TEXT);
    }

    public static function versionText(mixed $text = ''): string
    {
        $sha = Release::read(WP_CONTENT_DIR . '/.mf-release');
        return $sha === null ? '' : esc_html('Release ' . Release::short($sha));
    }

    public static function removeWelcomePanel(): void
    {
        remove_action('welcome_panel', 'wp_welcome_panel');
    }

    public static function tidyDashboard(): void
    {
        foreach (['dashboard_primary', 'dashboard_secondary', 'dashboard_quick_press', 'dashboard_site_health'] as $id) {
            remove_meta_box($id, 'dashboard', 'normal');
            remove_meta_box($id, 'dashboard', 'side');
        }
        wp_add_dashboard_widget('mf_insights', 'Moneyfrog Insights', [self::class, 'renderWidget']);
    }

    public static function renderWidget(): void
    {
        $links = [
            'Write a new post' => admin_url('post-new.php'),
            'All posts'        => admin_url('edit.php'),
            'View the blog'    => home_url('/'),
            'Topics'           => admin_url('edit-tags.php?taxonomy=category'),
        ];
        echo '<ul class="mf-dash-links">';
        foreach ($links as $label => $url) {
            printf('<li><a href="%s">%s</a></li>', esc_url($url), esc_html($label));
        }
        echo '</ul>';
        echo '<p class="description">Every post shows the compliance disclaimer automatically.</p>';
    }

    public static function enqueueBarStyle(): void
    {
        if (!is_admin() && !is_admin_bar_showing()) {
            return;
        }
        wp_enqueue_style('mf-admin-bar', self::assetUrl('admin-bar.css'), [], self::assetVersion('admin-bar.css'));
    }

    public static function printFavicon(): void
    {
        printf('<link rel="icon" type="image/svg+xml" href="%s">' . "\n", esc_url(self::assetUrl('favicon-frog.svg')));
    }

    /** Front end: only when no site icon is set in the Customizer. */
    public static function printFrontFavicon(): void
    {
        if (!has_site_icon()) {
            self::printFavicon();
        }
    }
}
