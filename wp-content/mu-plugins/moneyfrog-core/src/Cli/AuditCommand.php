<?php

namespace MoneyfrogCore\Cli;

use MoneyfrogCore\Audit;

/** `wp mf audit`: prints the environment audit as JSON; exits non-zero when a requirement fails. */
final class AuditCommand
{
    /** Print the audit JSON; exit 1 if any requirement fails. */
    public function __invoke(array $args = [], array $assoc = []): void
    {
        $facts    = $this->collect();
        $failures = Audit::failures($facts);
        \WP_CLI::line((string) wp_json_encode($facts + ['failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($failures !== []) {
            \WP_CLI::halt(1);
        }
    }

    /** @return array<string,mixed> */
    private function collect(): array
    {
        global $wpdb;
        $users = [];
        foreach (get_users(['fields' => ['ID', 'user_login']]) as $u) {
            $users[] = [
                'login'     => (string) $u->user_login,
                'can_edit'  => user_can((int) $u->ID, 'edit_posts'),
                'providers' => get_user_meta((int) $u->ID, '_two_factor_enabled_providers', true),
            ];
        }
        $open = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE comment_status = 'open' AND post_type IN ('post','page') AND post_status IN ('publish','draft','pending','future','private')"
        );

        return [
            'php'                 => PHP_VERSION,
            'wp'                  => get_bloginfo('version'),
            'timezone'            => (string) get_option('timezone_string'),
            'two_factor_missing'  => Audit::twoFactorMissing($users),
            'file_edit_disabled'  => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT === true,
            'file_mods_disabled'  => defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS === true,
            'admin_user_exists'   => get_user_by('login', 'admin') !== false,
            'xmlrpc_off'          => !apply_filters('xmlrpc_enabled', true),
            'comments_open_count' => $open,
            'blog_public'         => (int) get_option('blog_public'),
        ];
    }
}
