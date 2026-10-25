<?php

namespace MoneyfrogCore;

/**
 * The importer's service account. It owns imported posts, can never sign in
 * (password, application password or cookie), and its name is never shown:
 * public author names come from the `mf_byline` taxonomy.
 */
final class ServiceUser
{
    public const LOGIN = 'mf-import';
    public const DISPLAY_NAME = 'Moneyfrog';

    public static function register(): void
    {
        add_filter('authenticate', [self::class, 'rejectLogin'], 100, 2);
        add_filter('wp_is_application_passwords_available_for_user', [self::class, 'noApplicationPasswords'], 10, 2);
    }

    public static function isServiceLogin(string $login): bool
    {
        return strtolower(trim($login)) === self::LOGIN;
    }

    /** Runs last, so it overrides a successful credential check. */
    public static function rejectLogin(mixed $user, mixed $username = ''): mixed
    {
        $resolved = (is_object($user) && isset($user->user_login)) ? (string) $user->user_login : '';
        if (self::isServiceLogin($resolved) || (is_string($username) && self::isServiceLogin($username))) {
            return new \WP_Error('mf_service_account', 'This account cannot sign in.');
        }
        return $user;
    }

    public static function noApplicationPasswords(mixed $available, mixed $user): mixed
    {
        return (is_object($user) && self::isServiceLogin((string) ($user->user_login ?? ''))) ? false : $available;
    }

    /** Create the account if missing (random 48-character password, never shown). */
    public static function ensure(): int
    {
        $existing = get_user_by('login', self::LOGIN);
        if ($existing instanceof \WP_User) {
            return (int) $existing->ID;
        }
        $id = wp_insert_user([
            'user_login' => self::LOGIN,
            'user_pass' => wp_generate_password(48, true, true),
            'user_email' => 'mf-import@moneyfrog.invalid',
            'role' => 'author',
            'display_name' => self::DISPLAY_NAME,
            'nickname' => self::DISPLAY_NAME,
        ]);
        if (is_wp_error($id)) {
            throw new \RuntimeException('Could not create service user: ' . $id->get_error_message());
        }
        return (int) $id;
    }

    /** Replace the password with a fresh random one that is discarded. */
    public static function rotatePassword(): void
    {
        $user = get_user_by('login', self::LOGIN);
        if ($user instanceof \WP_User) {
            wp_set_password(wp_generate_password(48, true, true), (int) $user->ID);
        }
    }
}
