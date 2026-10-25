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
        add_action('user_register', [self::class, 'setSchemeForNewUser']);
        add_action('init', [self::class, 'migrateExistingUsers'], 20);
        add_filter('admin_footer_text', [self::class, 'footerText']);
        add_filter('update_footer', [self::class, 'versionText'], 11);
        add_filter('update_right_now_text', '__return_empty_string');
        add_action('admin_init', [self::class, 'removeWelcomePanel']);
        add_action('wp_dashboard_setup', [self::class, 'tidyDashboard'], 20);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueBarStyle']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueSchemeStyle']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueBarStyle']);
        add_action('admin_head', [self::class, 'printFavicon']);
        add_action('login_head', [self::class, 'printFavicon']);
        add_action('wp_head', [self::class, 'printFavicon'], 2);
        // Core must never print or serve its own icon: drop wp_site_icon() and point the Site Icon fallback at ours.
        remove_action('wp_head', 'wp_site_icon', 99);
        foreach (['admin_head', 'login_head'] as $hook) {
            remove_action($hook, 'wp_site_icon');
            remove_action($hook, 'wp_site_icon', 99);
        }
        add_filter('get_site_icon_url', [self::class, 'siteIconUrl']);
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
            admin_url('css/colors/modern/colors' . (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min') . '.css'),
            ['#16191d', '#8a6a1f', '#c29a2c', '#f9f8f4'],
            ['base' => '#e3c66a', 'focus' => '#ffffff', 'current' => '#ffffff']
        );
    }

    public const MIGRATION_OPTION = 'mf_admin_color_migrated';

    /** New users start on the Moneyfrog scheme; they can pick another one in their profile. */
    public static function setSchemeForNewUser(int $userId): void
    {
        update_user_meta($userId, 'admin_color', self::SCHEME);
    }

    /**
     * One-time move of existing users who never chose a scheme (stored value empty, or core's own
     * default: "fresh", and "modern" on current WordPress). Anyone with another stored value keeps it.
     */
    public static function migrateExistingUsers(): void
    {
        if (get_option(self::MIGRATION_OPTION) === '1') {
            return;
        }
        foreach (get_users(['fields' => 'ID', 'number' => -1]) as $id) {
            $current = (string) get_user_meta((int) $id, 'admin_color', true);
            if (in_array($current, ['', 'fresh', 'modern'], true)) {
                update_user_meta((int) $id, 'admin_color', self::SCHEME);
            }
        }
        update_option(self::MIGRATION_OPTION, '1', false);
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

    /** Overrides for the Moneyfrog scheme, loaded after core's `colors` stylesheet. */
    public static function enqueueSchemeStyle(): void
    {
        if (get_user_option('admin_color') === self::SCHEME) {
            wp_enqueue_style('mf-admin-colors', self::assetUrl('admin-colors.css'), ['colors'], self::assetVersion('admin-colors.css'));
        }
    }

    public static function enqueueBarStyle(): void
    {
        if (!is_admin() && !is_admin_bar_showing()) {
            return;
        }
        wp_enqueue_style('mf-admin-bar', self::assetUrl('admin-bar.css'), [], self::assetVersion('admin-bar.css'));
    }

    public static function siteIconUrl(): string
    {
        return self::assetUrl('favicon-32.png');
    }

    public static function printFavicon(): void
    {
        $eol = chr(10);
        printf('<link rel="icon" type="image/svg+xml" href="%s">%s', esc_url(self::assetUrl('favicon-frog.svg')), $eol);
        printf('<link rel="icon" type="image/png" sizes="32x32" href="%s">%s', esc_url(self::assetUrl('favicon-32.png')), $eol);
        printf('<link rel="apple-touch-icon" sizes="180x180" href="%s">%s', esc_url(self::assetUrl('favicon.png')), $eol);
    }
}
