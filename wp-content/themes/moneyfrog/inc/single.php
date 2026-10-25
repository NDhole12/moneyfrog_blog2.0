<?php
/**
 * Data assembly for the single post template: share links, JSON-LD, previous/next and
 * related posts. The template stays thin; everything that queries lives here.
 */

use MoneyfrogCore\Byline;

if (!defined('ABSPATH')) {
    exit;
}

/** Share targets for a post. Returns label => URL (all external, already URL-encoded). */
function mf_share_links(string $url, string $title): array
{
    return [
        'LinkedIn' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode($url),
        'WhatsApp' => 'https://wa.me/?text=' . rawurlencode($title . ' ' . $url),
        'X'        => 'https://x.com/intent/post?url=' . rawurlencode($url) . '&text=' . rawurlencode($title),
    ];
}

/** Social/search structured data for a post, as a plain array. */
function mf_post_jsonld(WP_Post $post): array
{
    $by      = Byline::forPost($post->ID);
    $logo    = get_template_directory_uri() . '/assets/img/moneyfrog-logo.svg';
    $author  = $by
        ? ['@type' => 'Person', 'name' => $by->name]
        : ['@type' => 'Organization', 'name' => 'Moneyfrog'];
    $data = [
        '@context'         => 'https://schema.org',
        '@type'            => 'BlogPosting',
        'headline'         => get_the_title($post),
        'datePublished'    => get_the_date('c', $post),
        'dateModified'     => get_the_modified_date('c', $post),
        'author'           => $author,
        'publisher'        => ['@type' => 'Organization', 'name' => 'Moneyfrog', 'logo' => ['@type' => 'ImageObject', 'url' => $logo]],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => get_permalink($post)],
    ];
    $imageId = mf_card_image_id($post->ID);
    $image   = $imageId > 0 ? wp_get_attachment_url($imageId) : false;
    if (is_string($image) && $image !== '') {
        $data['image'] = $image;
    }
    return $data;
}

/** JSON-LD as a safe <script> body: `<`, `>` and `&` are hex-escaped so `</script>` cannot break out. */
function mf_jsonld_encode(array $data): string
{
    return (string) wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
}

add_action('wp_head', static function (): void {
    $post = get_queried_object();
    if (is_singular('post') && $post instanceof WP_Post) {
        echo '<script type="application/ld+json">' . mf_jsonld_encode(mf_post_jsonld($post)) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON, tag characters hex-escaped.
    }
}, 5);

/**
 * Older and newer posts in the same topic. Ordered by (date, ID) so posts sharing a timestamp
 * still have one stable neighbour each way and a post is never both previous and next.
 */
function mf_adjacent_posts(WP_Post $post, ?WP_Term $topic): array
{
    $none = ['prev' => null, 'next' => null];
    if (!$topic) {
        return $none;
    }
    $find = static function (string $cmp, string $order) use ($post, $topic): ?WP_Post {
        $where = static function (string $sql) use ($post, $cmp): string {
            global $wpdb;
            return $sql . $wpdb->prepare(
                " AND ({$wpdb->posts}.post_date {$cmp} %s OR ({$wpdb->posts}.post_date = %s AND {$wpdb->posts}.ID {$cmp} %d))",
                $post->post_date,
                $post->post_date,
                $post->ID
            );
        };
        add_filter('posts_where', $where);
        $found = mf_query_posts([
            'cat'            => $topic->term_id,
            'posts_per_page' => 1,
            'orderby'        => ['date' => $order, 'ID' => $order],
            'suppress_filters' => false,
        ]);
        remove_filter('posts_where', $where);
        return $found[0] ?? null;
    };
    return ['prev' => $find('<', 'DESC'), 'next' => $find('>', 'ASC')];
}

/** Keep reading: the three newest posts in the same topic, excluding this one. */
function mf_related_posts(WP_Post $post, ?WP_Term $topic): array
{
    if (!$topic) {
        return [];
    }
    return mf_query_posts(['cat' => $topic->term_id, 'post__not_in' => [$post->ID], 'posts_per_page' => 3]);
}

/** Non-utility tags of a post, each with a URL (the topic page filtered by that tag when possible). */
function mf_post_tag_links(WP_Post $post, ?WP_Term $topic): array
{
    $tags = get_the_tags($post->ID);
    if (!is_array($tags)) {
        return [];
    }
    $base  = $topic ? get_term_link($topic) : '';
    $links = [];
    foreach ($tags as $tag) {
        if (in_array($tag->slug, MF_UTILITY_TAGS, true)) {
            continue;
        }
        $url = is_string($base) && $base !== '' ? add_query_arg('tag', $tag->slug, $base) : get_tag_link($tag);
        $links[] = ['name' => $tag->name, 'url' => $url];
    }
    return $links;
}

/** The imported excerpt is a machine-cut teaser ending in an ellipsis; only a hand-written one is a standfirst. */
function mf_post_standfirst(WP_Post $post): string
{
    $text = trim(wp_strip_all_tags($post->post_excerpt));
    if ($text === '' || preg_match('/(…|\.\.\.|\[…\])$/u', $text)) {
        return '';
    }
    return $text;
}

add_action('wp_enqueue_scripts', static function (): void {
    if (!is_singular('post')) {
        return;
    }
    $dir = get_template_directory();
    $uri = get_template_directory_uri();
    foreach (['progress', 'copy-link'] as $name) {
        $file = '/assets/js/' . $name . '.js';
        wp_enqueue_script('mf-' . $name, $uri . $file, [], (string) (filemtime($dir . $file) ?: '1'), ['in_footer' => true, 'strategy' => 'defer']);
    }
});
