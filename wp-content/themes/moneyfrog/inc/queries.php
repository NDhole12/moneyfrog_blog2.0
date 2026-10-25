<?php
/**
 * Data assembly for the home, category and search templates. Templates stay thin:
 * everything that touches WP_Query or term lookups lives here, and posts are
 * primed in bulk (terms, meta, thumbnails) so cards never trigger per-post queries.
 */

use MoneyfrogCore\Byline;
use MoneyfrogCore\Config;
use MoneyfrogCore\Content;

if (!defined('ABSPATH')) {
    exit;
}

const MF_CATEGORY_PER_PAGE = 10;
const MF_HOME_LATEST_PER_PAGE = 6;
/** Tags that drive layout (picks, popular, pinned) and so are not shown as sub-topics. */
const MF_UTILITY_TAGS = ['popular', 'editors-pick', 'start-here'];

/** Run a non-paginated post query and return the posts, primed for card rendering. */
function mf_query_posts(array $args): array
{
    $query = new WP_Query($args + [
        'post_type'           => 'post',
        'post_status'         => 'publish',
        'ignore_sticky_posts' => true,
        'no_found_rows'       => true,
    ]);
    mf_prime_posts($query->posts);
    return $query->posts;
}

/** Warm the term, meta and thumbnail caches for a set of posts in a handful of queries. */
function mf_prime_posts(array $posts): void
{
    $ids = array_values(array_unique(array_map(static fn (WP_Post $p): int => $p->ID, $posts)));
    if ($ids === []) {
        return;
    }
    update_object_term_cache($ids, 'post');
    update_postmeta_cache($ids);
    $thumbs = [];
    foreach ($ids as $id) {
        $thumb = (int) get_post_meta($id, '_thumbnail_id', true);
        if ($thumb > 0) {
            $thumbs[] = $thumb;
        }
    }
    if ($thumbs !== []) {
        _prime_post_caches($thumbs, false, true);
    }
}

/** The topic category of a post: the first assigned category that is one of the seven topics. */
function mf_post_topic(WP_Post $post): ?WP_Term
{
    $cats = get_the_category($post->ID);
    foreach ($cats as $cat) {
        if (isset(Config::TOPICS[$cat->slug])) {
            return $cat;
        }
    }
    return $cats[0] ?? null;
}

/** Everything a card needs, as plain values (escape at output). */
function mf_card_data(WP_Post $post, string $size = 'mf-card'): array
{
    $topic = mf_post_topic($post);
    $by    = Byline::forPost($post->ID);
    $text  = $post->post_excerpt !== '' ? $post->post_excerpt : wp_strip_all_tags(strip_shortcodes($post->post_content));
    $thumb = has_post_thumbnail($post) ? get_the_post_thumbnail($post, $size, ['loading' => 'lazy', 'decoding' => 'async', 'alt' => '']) : '';

    return [
        'id'         => $post->ID,
        'title'      => get_the_title($post),
        'url'        => get_permalink($post),
        'topic'      => $topic ? $topic->name : 'Insights',
        'topic_slug' => $topic ? $topic->slug : '',
        'byline'     => $by ? $by->name : null,
        'date_iso'   => get_the_date('c', $post),
        'date'       => get_the_date('j M Y', $post),
        'month'      => get_the_date('M Y', $post),
        'read'       => Content::readingTime($post->post_content),
        'excerpt'    => wp_trim_words($text, 24, '…'),
        'thumb'      => $thumb,
        'variant'    => $post->ID % 2 === 0 ? 'cream' : 'night',
    ];
}

/** First non-utility tag name on a post (list-row eyebrow), else the topic name. */
function mf_row_label(WP_Post $post, string $topicName): string
{
    $tags = get_the_tags($post->ID);
    if (is_array($tags)) {
        foreach ($tags as $tag) {
            if (!in_array($tag->slug, MF_UTILITY_TAGS, true)) {
                return $tag->name;
            }
        }
    }
    return $topicName;
}

/**
 * Home page data. featured = newest Market Outlook; picks = editors-pick tag (fallback
 * newest non-outlook); outlook = next four outlooks; latest = paged query excluding those;
 * guides = start-here posts by mf_order (fallback oldest five Guides).
 *
 * @return array{featured:?WP_Post, picks:WP_Post[], outlook:WP_Post[], latest:WP_Query, guides:WP_Post[]}
 */
function mf_home_sections(): array
{
    $outlooks = mf_query_posts(['category_name' => 'market-outlook', 'posts_per_page' => 5]);
    $featured = $outlooks[0] ?? null;
    $outlook  = array_slice($outlooks, 1, 4);

    $picks = mf_query_posts(['tag' => 'editors-pick', 'posts_per_page' => 4]);
    if ($picks === []) {
        $skip  = get_category_by_slug('market-outlook');
        $picks = mf_query_posts(['posts_per_page' => 4, 'category__not_in' => $skip ? [$skip->term_id] : []]);
    }

    $shown = array_map(static fn (WP_Post $p): int => $p->ID, array_merge($featured ? [$featured] : [], $picks, $outlook));
    $latest = new WP_Query([
        'post_type'           => 'post',
        'post_status'         => 'publish',
        'ignore_sticky_posts' => true,
        'posts_per_page'      => MF_HOME_LATEST_PER_PAGE,
        'paged'               => max(1, (int) get_query_var('paged')),
        'post__not_in'        => $shown,
    ]);
    mf_prime_posts($latest->posts);

    return [
        'featured' => $featured,
        'picks'    => $picks,
        'outlook'  => $outlook,
        'latest'   => $latest,
        'guides'   => mf_home_guides(),
    ];
}

/** @return WP_Post[] */
function mf_home_guides(): array
{
    $tagged = mf_query_posts(['tag' => 'start-here', 'posts_per_page' => 20, 'orderby' => 'date', 'order' => 'ASC']);
    if ($tagged === []) {
        return mf_query_posts(['category_name' => 'guides', 'posts_per_page' => 5, 'orderby' => 'date', 'order' => 'ASC']);
    }
    $rank = static function (WP_Post $p): int {
        $order = get_post_meta($p->ID, 'mf_order', true);
        return $order === '' ? PHP_INT_MAX : (int) $order;
    };
    usort($tagged, static fn (WP_Post $a, WP_Post $b): int => $rank($a) <=> $rank($b));
    return array_slice($tagged, 0, 5);
}

/** One-line description for a topic: its own term description, else the configured blurb. */
function mf_topic_blurb(WP_Term|string $topic): string
{
    $slug = $topic instanceof WP_Term ? $topic->slug : $topic;
    if ($topic instanceof WP_Term && trim($topic->description) !== '') {
        return trim(wp_strip_all_tags($topic->description));
    }
    return Config::TOPIC_BLURBS[$slug] ?? '';
}

/** Page on moneyfrog.in that matches a topic, for the category header CTA (null when none). */
function mf_topic_site_page(string $slug): ?string
{
    $pages = ['mutual-funds' => '/mutual-funds', 'tax' => '/tax', 'insurance' => '/insurance'];
    return isset($pages[$slug]) ? mf_site_url($pages[$slug]) : null;
}

/** Popular in a topic: newest three tagged `popular`, else the newest three in the topic. */
function mf_popular_posts(WP_Term $topic): array
{
    $popular = mf_query_posts(['cat' => $topic->term_id, 'tag' => 'popular', 'posts_per_page' => 3]);
    return $popular !== [] ? $popular : mf_query_posts(['cat' => $topic->term_id, 'posts_per_page' => 3]);
}

/** Pinned "start here" post of a topic: newest post tagged start-here in it. */
function mf_pinned_post(WP_Term $topic): ?WP_Post
{
    static $cache = [];
    if (!array_key_exists($topic->term_id, $cache)) {
        $found = mf_query_posts(['cat' => $topic->term_id, 'tag' => 'start-here', 'posts_per_page' => 1]);
        $cache[$topic->term_id] = $found[0] ?? null;
    }
    return $cache[$topic->term_id];
}

/** Sub-topic pills: the non-utility tags used by posts in a topic, most used first. */
function mf_subtopics(WP_Term $topic, int $limit = 8): array
{
    $ids = get_posts([
        'cat' => $topic->term_id, 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true,
        'update_post_meta_cache' => false, 'update_post_term_cache' => false,
    ]);
    if ($ids === []) {
        return [];
    }
    $tags = wp_get_object_terms($ids, 'post_tag', ['orderby' => 'count', 'order' => 'DESC']);
    if (is_wp_error($tags)) {
        return [];
    }
    $tags = array_filter($tags, static fn (WP_Term $t): bool => !in_array($t->slug, MF_UTILITY_TAGS, true));
    return array_slice(array_values($tags), 0, $limit);
}

/** Sort direction requested on a category page: only `asc` is honoured, anything else is newest first. */
function mf_sort_order(): string
{
    $raw = isset($_GET['order']) ? sanitize_key(wp_unslash((string) $_GET['order'])) : '';
    return $raw === 'asc' ? 'asc' : 'desc';
}

/**
 * Per-request filters read from the URL. `?tag=` is lifted out of WordPress's own query
 * vars (in the `request` filter) so it cannot change the queried object of a category page.
 */
function mf_request_state(?array $set = null): array
{
    static $state = ['tag' => ''];
    if ($set !== null) {
        $state = $set + $state;
    }
    return $state;
}

add_filter('request', static function (array $vars): array {
    if (!empty($vars['category_name']) && isset($vars['tag'])) {
        mf_request_state(['tag' => sanitize_title((string) $vars['tag'])]);
        unset($vars['tag']);
    }
    return $vars;
});

add_action('pre_get_posts', static function (WP_Query $query): void {
    if (is_admin() || !$query->is_main_query()) {
        return;
    }
    if ($query->is_home()) {
        // The home template runs its own queries; keep the main one tiny but never the
        // limiting factor for how many /page/N/ URLs exist.
        $query->set('posts_per_page', 1);
        return;
    }
    if ($query->is_search()) {
        $query->set('post_type', 'post');
        $query->set('posts_per_page', MF_CATEGORY_PER_PAGE);
        return;
    }
    if (!$query->is_category()) {
        return;
    }
    $query->set('posts_per_page', MF_CATEGORY_PER_PAGE);
    $query->set('orderby', 'date');
    $query->set('order', mf_sort_order() === 'asc' ? 'ASC' : 'DESC');

    $tag = mf_request_state()['tag'];
    if ($tag !== '') {
        $query->set('tax_query', [['taxonomy' => 'post_tag', 'field' => 'slug', 'terms' => [$tag]]]);
        return;
    }
    $topic = get_category_by_slug(basename((string) $query->get('category_name')));
    $pinned = $topic instanceof WP_Term ? mf_pinned_post($topic) : null;
    if ($pinned) {
        $query->set('post__not_in', [$pinned->ID]);
    }
});
