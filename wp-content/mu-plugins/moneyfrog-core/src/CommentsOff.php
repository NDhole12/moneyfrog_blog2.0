<?php

namespace MoneyfrogCore;

/**
 * Comments, trackbacks, pingbacks and XML-RPC are off everywhere.
 */
final class CommentsOff
{
    private const BLOCKED_SCRIPTS = ['wp-comments-post.php', 'xmlrpc.php'];

    public static function register(): void
    {
        add_action('init', [self::class, 'blockEntryPoints'], 0);
        add_action('init', [self::class, 'removePostTypeSupport'], 100);

        add_filter('pre_option_default_comment_status', static fn () => 'closed');
        add_filter('pre_option_default_ping_status', static fn () => 'closed');
        add_filter('comments_open', '__return_false', 20);
        add_filter('pings_open', '__return_false', 20);
        add_filter('comments_array', '__return_empty_array', 20);
        add_filter('wp_headers', [self::class, 'dropPingbackHeader']);
        add_filter('bloginfo_url', [self::class, 'dropPingbackUrl'], 10, 2);
        add_filter('bloginfo', [self::class, 'dropPingbackUrl'], 10, 2);

        add_action('template_redirect', [self::class, 'blockCommentFeeds'], 0);
        add_action('admin_menu', [self::class, 'removeAdminMenu'], 999);
        add_action('wp_before_admin_bar_render', [self::class, 'removeAdminBarNode']);
        add_action('wp_dashboard_setup', [self::class, 'removeDashboardWidget']);

        remove_action('wp_head', 'feed_links_extra', 3);
    }

    /** Answer 403 for the scripts that accept comments or XML-RPC calls. */
    public static function blockEntryPoints(): void
    {
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (in_array($script, self::BLOCKED_SCRIPTS, true)) {
            // Not wp_die(): the XML-RPC die handler would answer 200.
            status_header(403);
            nocache_headers();
            header('Content-Type: text/plain; charset=UTF-8');
            exit('Forbidden');
        }
    }

    public static function removePostTypeSupport(): void
    {
        foreach (get_post_types() as $type) {
            remove_post_type_support($type, 'comments');
            remove_post_type_support($type, 'trackbacks');
        }
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    public static function dropPingbackHeader(array $headers): array
    {
        unset($headers['X-Pingback']);
        return $headers;
    }

    public static function dropPingbackUrl(mixed $output, mixed $show = ''): mixed
    {
        return $show === 'pingback_url' ? '' : $output;
    }

    public static function blockCommentFeeds(): void
    {
        if (is_feed() && is_comment_feed()) {
            wp_die('Not found', 'Not found', ['response' => 404]);
        }
    }

    public static function removeAdminMenu(): void
    {
        remove_menu_page('edit-comments.php');
        remove_submenu_page('options-general.php', 'options-discussion.php');
    }

    public static function removeAdminBarNode(): void
    {
        global $wp_admin_bar;
        if ($wp_admin_bar instanceof \WP_Admin_Bar) {
            $wp_admin_bar->remove_node('comments');
        }
    }

    public static function removeDashboardWidget(): void
    {
        remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
    }
}
