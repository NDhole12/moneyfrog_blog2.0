<?php

namespace MoneyfrogCore;

/**
 * Author names without login accounts: the `mf_byline` taxonomy owns /author/<slug>/.
 * Core user-author archives are moved to /wp-user/ and answer 404.
 */
final class Byline
{
    public const TAXONOMY = 'mf_byline';
    public const CORE_AUTHOR_BASE = 'wp-user';
    /** Term meta: attachment id of the author's photo. */
    public const PHOTO_META = 'mf_photo';
    /** Every published article shows an author; one saved without a byline is published as Mushtaq Kazi. */
    public const DEFAULT_SLUG = 'mushtaq-kazi';
    private const REWRITE_VERSION = '1';
    private const REWRITE_OPTION = 'mf_byline_rewrite_version';

    public static function register(): void
    {
        add_action('init', [self::class, 'setAuthorBase'], 0);
        add_action('init', [self::class, 'registerTaxonomy'], 5);
        add_action('init', [self::class, 'maybeFlushRewrite'], 99);
        add_action('template_redirect', [self::class, 'blockCoreAuthorArchives'], 0);
        // Runs after the editor has saved the post's terms, so a byline picked in the editor is already set.
        add_action('wp_after_insert_post', [self::class, 'ensureByline'], 10, 2);
    }

    /** Frees /author/ for bylines; core user archives live under /wp-user/. */
    public static function setAuthorBase(): void
    {
        global $wp_rewrite;
        if ($wp_rewrite instanceof \WP_Rewrite) {
            $wp_rewrite->author_base = self::CORE_AUTHOR_BASE;
        }
    }

    public static function registerTaxonomy(): void
    {
        register_taxonomy(self::TAXONOMY, ['post'], [
            'labels' => [
                'name' => 'Authors', 'singular_name' => 'Author', 'menu_name' => 'Authors',
                'all_items' => 'All authors', 'edit_item' => 'Edit author', 'view_item' => 'View author',
                'update_item' => 'Update author', 'add_new_item' => 'Add new author', 'new_item_name' => 'New author name',
                'search_items' => 'Search authors', 'not_found' => 'No authors found.',
                'separate_items_with_commas' => 'One author per article.', 'choose_from_most_used' => 'Choose an author',
                'back_to_items' => '← Back to authors',
            ],
            'public' => true,
            'hierarchical' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'show_in_rest' => true,
            'rest_base' => 'mf_byline',
            'rewrite' => ['slug' => 'author', 'with_front' => false],
        ]);

        foreach (['mf_bio', 'mf_role'] as $key) {
            register_term_meta(self::TAXONOMY, $key, [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback' => static fn () => current_user_can('manage_categories'),
            ]);
        }
        register_term_meta(self::TAXONOMY, self::PHOTO_META, [
            'type' => 'integer',
            'single' => true,
            'show_in_rest' => true,
            'sanitize_callback' => [self::class, 'sanitizePhotoId'],
            'auth_callback' => static fn () => current_user_can('manage_categories'),
        ]);
    }

    /** Positive whole number or 0. */
    public static function sanitizePhotoId(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }
        return is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/', $value) === 1 ? (int) $value : 0;
    }

    /** The author's photo (an image attachment), or 0 when none is set or it was deleted. */
    public static function photoId(\WP_Term $by): int
    {
        $id = self::sanitizePhotoId(get_term_meta($by->term_id, self::PHOTO_META, true));
        return $id > 0 && wp_attachment_is_image($id) ? $id : 0;
    }

    public static function needsDefault(string $postType, string $status, bool $hasByline): bool
    {
        return $postType === 'post' && in_array($status, ['publish', 'future'], true) && !$hasByline;
    }

    public static function ensureByline(int $postId, \WP_Post $post): void
    {
        if (!self::needsDefault($post->post_type, $post->post_status, self::forPost($postId) !== null)) {
            return;
        }
        $default = get_term_by('slug', self::DEFAULT_SLUG, self::TAXONOMY);
        if ($default instanceof \WP_Term) {
            wp_set_object_terms($postId, [(int) $default->term_id], self::TAXONOMY);
        }
    }

    /** Flush once per rewrite version, never on every request. */
    public static function maybeFlushRewrite(): void
    {
        if (get_option(self::REWRITE_OPTION) !== self::REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option(self::REWRITE_OPTION, self::REWRITE_VERSION, true);
        }
    }

    /** /wp-user/<login>/ and ?author=<id> must not reveal that a login exists. */
    public static function blockCoreAuthorArchives(): void
    {
        global $wp_query;
        if ($wp_query instanceof \WP_Query && is_author()) {
            remove_action('template_redirect', 'redirect_canonical');
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
        }
    }

    public static function forPost(int $postId): ?\WP_Term
    {
        $terms = get_the_terms($postId, self::TAXONOMY);
        if (!is_array($terms) || $terms === []) {
            return null;
        }
        $first = reset($terms);
        return $first instanceof \WP_Term ? $first : null;
    }

    /** "Mushtaq Kazi" -> "MK"; one word -> its first two letters; empty -> "". */
    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return '';
        }
        if (count($words) === 1) {
            return mb_strtoupper(mb_substr($words[0], 0, 2));
        }
        return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[count($words) - 1], 0, 1));
    }
}
