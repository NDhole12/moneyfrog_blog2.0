<?php

namespace MoneyfrogCore;

/**
 * Posts → Authors (the `mf_byline` screens): role, short bio and photo for each author,
 * shown in the author box under every article. Saves only when its own nonce is posted,
 * so programmatic wp_insert_term()/wp_update_term() calls (the importer) never touch these fields.
 */
final class BylineAdmin
{
    private const NONCE = 'mf_byline_profile_nonce';
    private const NONCE_ACTION = 'mf_byline_profile';
    private const BIO_MAX = 600;
    private const ROLE_MAX = 80;

    public static function register(): void
    {
        $tax = Byline::TAXONOMY;
        add_action("{$tax}_add_form_fields", [self::class, 'renderAddFields']);
        add_action("{$tax}_edit_form_fields", [self::class, 'renderEditFields']);
        add_action("created_{$tax}", [self::class, 'save']);
        add_action("edited_{$tax}", [self::class, 'save']);
        add_filter("manage_edit-{$tax}_columns", [self::class, 'columns']);
        add_filter("manage_{$tax}_custom_column", [self::class, 'columnContent'], 10, 3);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function renderAddFields(): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE, false);
        foreach (self::fields('', '', 0) as $f) {
            printf('<div class="form-field %s">%s%s</div>', esc_attr($f['class']), $f['label'], $f['input']); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts in fields().
        }
    }

    public static function renderEditFields(\WP_Term $term): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE, false);
        $fields = self::fields(
            (string) get_term_meta($term->term_id, 'mf_role', true),
            (string) get_term_meta($term->term_id, 'mf_bio', true),
            Byline::photoId($term)
        );
        foreach ($fields as $f) {
            printf('<tr class="form-field %s"><th scope="row">%s</th><td>%s</td></tr>', esc_attr($f['class']), $f['label'], $f['input']); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts in fields().
        }
    }

    /** @return list<array{class:string,label:string,input:string}> */
    private static function fields(string $role, string $bio, int $photoId): array
    {
        $preview = $photoId > 0 ? (string) wp_get_attachment_image($photoId, 'thumbnail', false, ['alt' => '']) : '';
        return [
            [
                'class' => 'term-mf-photo-wrap',
                'label' => '<label for="mf-photo-choose">Photo</label>',
                'input' => sprintf(
                    '<div class="mf-photo-field%s"><div class="mf-photo-field__preview">%s</div>'
                    . '<input type="hidden" name="mf_photo" value="%s">'
                    . '<button type="button" class="button" id="mf-photo-choose" data-mf-photo-choose>Choose photo</button> '
                    . '<button type="button" class="button-link mf-photo-field__remove" data-mf-photo-remove>Remove photo</button></div>'
                    . '<p class="description">A square head-and-shoulders photo, at least 300 × 300 px. Shown round under every article by this author.</p>',
                    $preview !== '' ? ' has-photo' : '',
                    $preview,
                    esc_attr($photoId > 0 ? (string) $photoId : '')
                ),
            ],
            [
                'class' => 'term-mf-role-wrap',
                'label' => '<label for="mf-role">Role</label>',
                'input' => sprintf(
                    '<input name="mf_role" id="mf-role" type="text" value="%s" maxlength="%d"><p class="description">Shown next to the name, e.g. "Founder &amp; CEO".</p>',
                    esc_attr($role),
                    self::ROLE_MAX
                ),
            ],
            [
                'class' => 'term-mf-bio-wrap',
                'label' => '<label for="mf-bio">Short bio</label>',
                'input' => sprintf(
                    '<textarea name="mf_bio" id="mf-bio" rows="4" maxlength="%d">%s</textarea><p class="description">Two or three sentences, shown under every article by this author.</p>',
                    self::BIO_MAX,
                    esc_textarea($bio)
                ),
            ],
        ];
    }

    public static function save(int $termId): void
    {
        $nonce = self::posted(self::NONCE);
        if ($nonce === '' || !wp_verify_nonce($nonce, self::NONCE_ACTION) || !current_user_can('manage_categories')) {
            return;
        }
        update_term_meta($termId, 'mf_role', mb_substr(sanitize_text_field(self::posted('mf_role')), 0, self::ROLE_MAX));
        update_term_meta($termId, 'mf_bio', mb_substr(sanitize_text_field(self::posted('mf_bio')), 0, self::BIO_MAX));
        $photo = Byline::sanitizePhotoId(self::posted('mf_photo'));
        if ($photo > 0 && wp_attachment_is_image($photo)) {
            update_term_meta($termId, Byline::PHOTO_META, $photo);
        } else {
            delete_term_meta($termId, Byline::PHOTO_META);
        }
    }

    /** A posted text field, unslashed; '' when missing or not a string. */
    private static function posted(string $key): string
    {
        $value = $_POST[$key] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification -- verified in save() before any write.
        return is_string($value) ? wp_unslash($value) : '';
    }

    /** The list table shows each author's photo instead of the unused core description. */
    public static function columns(array $columns): array
    {
        unset($columns['description']);
        $out = [];
        foreach ($columns as $key => $label) {
            if ($key === 'name') {
                $out['mf_photo'] = 'Photo';
            }
            $out[$key] = $label;
        }
        return $out;
    }

    public static function columnContent(string $content, string $column, int $termId): string
    {
        if ($column !== 'mf_photo') {
            return $content;
        }
        $term = get_term($termId, Byline::TAXONOMY);
        $photo = $term instanceof \WP_Term ? Byline::photoId($term) : 0;
        return $photo > 0
            ? (string) wp_get_attachment_image($photo, [40, 40], false, ['alt' => '', 'class' => 'mf-photo-thumb'])
            : '<span aria-hidden="true">—</span><span class="screen-reader-text">No photo</span>';
    }

    public static function enqueue(string $hook): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!in_array($hook, ['edit-tags.php', 'term.php'], true) || $screen === null || $screen->taxonomy !== Byline::TAXONOMY) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_style('mf-byline-admin', AdminBrand::assetUrl('byline-admin.css'), [], AdminBrand::assetVersion('byline-admin.css'));
        wp_enqueue_script('mf-byline-admin', AdminBrand::assetUrl('byline-admin.js'), ['jquery', 'media-editor'], AdminBrand::assetVersion('byline-admin.js'), true);
    }
}
