<?php

namespace MoneyfrogCore;

/** Pure evaluation of the `wp mf audit` facts (no WordPress calls, unit-testable). */
final class Audit
{
    public const REQUIRED_TIMEZONE = 'Asia/Kolkata';

    /**
     * Logins of editors (users with edit_posts) lacking two-factor. The importer's
     * service account is excluded: it can never sign in.
     *
     * @param list<array{login:string,can_edit:bool,providers:mixed}> $users
     * @return list<string>
     */
    public static function twoFactorMissing(array $users): array
    {
        $missing = [];
        foreach ($users as $u) {
            if (empty($u['can_edit']) || ServiceUser::isServiceLogin((string) $u['login'])) {
                continue;
            }
            $providers = $u['providers'] ?? [];
            if (!is_array($providers) || $providers === []) {
                $missing[] = (string) $u['login'];
            }
        }
        return $missing;
    }

    /**
     * Names of the failed requirements; a missing fact fails closed.
     *
     * @param array<string,mixed> $facts
     * @return list<string>
     */
    public static function failures(array $facts): array
    {
        $failed = [];
        if (!isset($facts['two_factor_missing']) || $facts['two_factor_missing'] !== []) {
            $failed[] = 'two_factor_missing';
        }
        foreach (['file_edit_disabled', 'file_mods_disabled'] as $k) {
            if (($facts[$k] ?? false) !== true) {
                $failed[] = $k;
            }
        }
        if (($facts['admin_user_exists'] ?? true) !== false) {
            $failed[] = 'admin_user_exists';
        }
        if (($facts['comments_open_count'] ?? 1) !== 0) {
            $failed[] = 'comments_open_count';
        }
        if (($facts['timezone'] ?? '') !== self::REQUIRED_TIMEZONE) {
            $failed[] = 'timezone';
        }
        return $failed;
    }
}
