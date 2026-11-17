<?php

namespace MoneyfrogCore;

/**
 * The home page interest tiles each point at a category. This makes sure those categories
 * exist (so editors can file articles under them and the tile has somewhere to go). It only
 * ever adds a missing category: names, posts and existing categories are never changed.
 */
final class Interests
{
    private const OPTION = 'mf_interest_terms';

    public static function register(): void
    {
        add_action('init', [self::class, 'ensureCategories'], 20);
    }

    /**
     * Slugs from Config::INTERESTS that are not in the given list of existing category slugs.
     *
     * @param string[] $existing
     * @return string[]
     */
    public static function missing(array $existing): array
    {
        return array_values(array_diff(array_keys(Config::INTERESTS), $existing));
    }

    /** Fingerprint of the configured tiles; the check below runs once per change to that list. */
    public static function fingerprint(): string
    {
        return md5(implode(',', array_keys(Config::INTERESTS)));
    }

    public static function ensureCategories(): void
    {
        if (get_option(self::OPTION) === self::fingerprint()) {
            return;
        }
        $existing = get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'slugs']);
        if (is_wp_error($existing)) {
            error_log('[moneyfrog-core] interest categories: ' . $existing->get_error_message());
            return;
        }
        foreach (self::missing($existing) as $slug) {
            $res = wp_insert_term(Config::INTERESTS[$slug]['name'], 'category', ['slug' => $slug]);
            if (is_wp_error($res)) {
                // Leave the option unset so the next request tries again.
                error_log('[moneyfrog-core] interest category ' . $slug . ': ' . $res->get_error_message());
                return;
            }
        }
        update_option(self::OPTION, self::fingerprint(), true);
    }
}
