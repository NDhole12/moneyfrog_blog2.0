<?php

namespace MoneyfrogCore;

/** Card/OG image resolver (single implementation; the theme and `wp mf warm` both call it). */
final class CardImage
{
    public const META = '_mf_first_img';

    /** Featured image, else the first uploads <img> in content (cached in post meta; 0 = none), else 0. */
    public static function idFor(int $postId): int
    {
        $featured = (int) get_post_thumbnail_id($postId);
        if ($featured > 0) {
            return $featured;
        }
        $cached = get_post_meta($postId, self::META, true);
        if ($cached !== '') {
            return (int) $cached;
        }
        $found = self::firstContentImageId((string) get_post_field('post_content', $postId));
        update_post_meta($postId, self::META, $found);
        return $found;
    }

    /** Attachment ID of the first <img> in the HTML whose src is in this site's uploads folder; 0 when none. */
    public static function firstContentImageId(string $html): int
    {
        $base = (string) (wp_get_upload_dir()['baseurl'] ?? '');
        if ($base === '' || !preg_match_all('/<img\b[^>]*?\bsrc\s*=\s*(["\'])(.*?)\1/is', $html, $m)) {
            return 0;
        }
        foreach ($m[2] as $src) {
            $src = html_entity_decode((string) strtok($src, '?#'), ENT_QUOTES);
            if (!str_starts_with($src, $base . '/')) {
                continue;
            }
            $full = (string) preg_replace('/-\d+x\d+(?=\.[A-Za-z0-9]+$)/', '', $src);
            $id   = (int) attachment_url_to_postid($full);
            if ($id === 0 && $full !== $src) {
                $id = (int) attachment_url_to_postid($src);
            }
            if ($id > 0 && wp_attachment_is_image($id)) {
                return $id;
            }
            return 0; // The first uploads image is the banner; a later one would not represent the post.
        }
        return 0;
    }
}
