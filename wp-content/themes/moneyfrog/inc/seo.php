<?php
/**
 * Meta description and Open Graph tags, written by hand (no SEO plugin).
 * Posts: description + og:* + article times + twitter:card. Home and topic pages: description only.
 */

use MoneyfrogCore\Config;

if (!defined('ABSPATH')) {
    exit;
}

const MF_SITE_NAME = 'Moneyfrog Insights';
const MF_DEFAULT_DESCRIPTION = 'Plain-language articles on markets, mutual funds, tax, insurance and financial planning from Moneyfrog.';
const MF_META_DESCRIPTION_MAX = 160;

/** Plain text cut on a word boundary to at most $max characters (an ellipsis counts toward the limit). */
function mf_meta_excerpt(string $text, int $max = MF_META_DESCRIPTION_MAX): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut  = mb_substr($text, 0, $max - 1);
    $last = mb_strrpos($cut, ' ');
    if ($last !== false && $last > 0) {
        $cut = mb_substr($cut, 0, $last);
    }
    return rtrim($cut, " ,;:-–—.") . '…';
}

/** Description of a post: its excerpt as plain text, else the first ~30 words of the content. */
function mf_post_description(WP_Post $post): string
{
    $text = trim(wp_strip_all_tags($post->post_excerpt));
    if ($text === '') {
        $text = wp_trim_words(wp_strip_all_tags(strip_shortcodes($post->post_content)), 30, '');
    }
    return mf_meta_excerpt($text);
}

/** Description for the home page and topic pages; '' elsewhere. */
function mf_archive_description(): string
{
    if (is_front_page() || is_home()) {
        $tagline = trim((string) get_bloginfo('description'));
        return $tagline !== '' ? $tagline : MF_DEFAULT_DESCRIPTION;
    }
    $term = is_category() ? get_queried_object() : null;
    if ($term instanceof WP_Term) {
        return mf_meta_excerpt(mf_topic_blurb($term));
    }
    return '';
}

/** Open Graph / Twitter tags of a post as property => content (images only when present). */
function mf_post_og(WP_Post $post): array
{
    $imageId = mf_card_image_id($post->ID);
    $image   = $imageId > 0 ? wp_get_attachment_url($imageId) : false;
    $tags    = [
        'og:type'                => 'article',
        'og:title'               => get_the_title($post),
        'og:description'         => mf_post_description($post),
        'og:url'                 => get_permalink($post),
        'og:site_name'           => MF_SITE_NAME,
        'article:published_time' => get_the_date('c', $post),
        'article:modified_time'  => get_the_modified_date('c', $post),
        'twitter:card'           => $image ? 'summary_large_image' : 'summary',
    ];
    if ($image) {
        $tags['og:image'] = $image;
    }
    return $tags;
}

add_action('wp_head', static function (): void {
    $object = get_queried_object();
    if (is_singular('post') && $object instanceof WP_Post) {
        printf('<meta name="description" content="%s">' . "\n", esc_attr(mf_post_description($object)));
        foreach (mf_post_og($object) as $key => $value) {
            $attr = str_starts_with($key, 'twitter:') ? 'name' : 'property';
            printf('<meta %s="%s" content="%s">' . "\n", $attr, esc_attr($key), esc_attr($value));
        }
        return;
    }
    $description = mf_archive_description();
    if ($description !== '') {
        printf('<meta name="description" content="%s">' . "\n", esc_attr($description));
    }
}, 4);
