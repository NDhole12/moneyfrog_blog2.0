<?php
/** Author photo helpers shared by the post header, the author box and the author archive. */

use MoneyfrogCore\Byline;

if (!defined('ABSPATH')) {
    exit;
}

/** Display widths per avatar size, used for the <img> sizes hint. */
const MF_AVATAR_PX = ['' => 40, 'xl' => 72];

/**
 * The author's photo as a round avatar, or their initials when no photo is set.
 * Decorative: the name always sits next to it, so alt is empty.
 */
function mf_byline_avatar(WP_Term $by, string $size = ''): string
{
    $class = 'avatar' . ($size !== '' ? ' avatar--' . $size : '');
    $photo = Byline::photoId($by);
    if ($photo > 0) {
        $img = (string) wp_get_attachment_image($photo, 'thumbnail', false, [
            'class'    => $class . ' avatar--photo',
            'alt'      => '',
            'sizes'    => (MF_AVATAR_PX[$size] ?? 40) . 'px',
            'decoding' => 'async',
        ]);
        if ($img !== '') {
            return $img;
        }
    }
    return '<span class="' . esc_attr($class) . '" aria-hidden="true">' . esc_html(Byline::initials($by->name)) . '</span>';
}

/** Absolute URL of the author's photo for structured data, or '' when none is set. */
function mf_byline_photo_url(WP_Term $by): string
{
    $photo = Byline::photoId($by);
    $url = $photo > 0 ? wp_get_attachment_image_url($photo, 'medium') : false;
    return is_string($url) ? $url : '';
}
