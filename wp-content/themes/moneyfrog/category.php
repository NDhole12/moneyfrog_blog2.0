<?php
/** Topic page: header band with sub-topic pills and sort, pinned guide, list rows, sidebar. */

if (!defined('ABSPATH')) {
    exit;
}

$topic = get_queried_object();
if (!$topic instanceof WP_Term) {
    get_template_part('404');
    return;
}
global $wp_query;

$paged  = max(1, (int) get_query_var('paged'));
$order  = mf_sort_order();
$tag    = mf_request_state()['tag'];
$pinned = ($paged === 1 && $tag === '') ? mf_pinned_post($topic) : null;
$subs   = mf_subtopics($topic);
$base   = get_term_link($topic);
$site   = mf_topic_site_page($topic->slug);
$count  = (int) $topic->count;
mf_prime_posts($wp_query->posts);

/** Category URL with the current sort (only when not the default) and an optional tag filter. */
$link = static function (string $tagSlug) use ($base, $order): string {
    $args = array_filter(['tag' => $tagSlug, 'order' => $order === 'asc' ? 'asc' : '']);
    return esc_url(add_query_arg($args, $base));
};

get_header();
?>
<main id="main">
    <section class="cat-head band">
        <div class="wrap">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="<?php echo esc_url(home_url('/')); ?>">Insights</a>
                <span aria-hidden="true">/</span>
                <span aria-current="page"><?php echo esc_html($topic->name); ?></span>
            </nav>
            <div class="cat-meta">
                <span class="eyebrow"><?php echo esc_html(sprintf('Topic · %d %s', $count, $count === 1 ? 'article' : 'articles')); ?></span>
                <form class="sort" method="get" action="<?php echo esc_url($base); ?>" data-autosubmit>
                    <?php if ($tag !== '') : ?><input type="hidden" name="tag" value="<?php echo esc_attr($tag); ?>"><?php endif; ?>
                    <label for="sort">Sort</label>
                    <select id="sort" name="order">
                        <option value="desc"<?php selected($order, 'desc'); ?>>Newest first</option>
                        <option value="asc"<?php selected($order, 'asc'); ?>>Oldest first</option>
                    </select>
                    <noscript><button type="submit" class="btn btn--outline btn--sm">Apply</button></noscript>
                </form>
            </div>
            <div class="cat-head__main">
                <div class="cat-head__copy">
                    <h1 class="display"><?php echo esc_html($topic->name); ?></h1>
                    <p class="lead"><?php echo esc_html(mf_topic_blurb($topic)); ?></p>
                </div>
                <?php if ($site) : ?>
                    <a class="btn btn--gold" href="<?php echo esc_url($site); ?>">Explore <?php echo esc_html($topic->name); ?> <?php echo mf_icon('i-arrow'); ?></a>
                <?php endif; ?>
            </div>
            <div class="cat-tools">
                <?php if ($subs) : ?>
                <nav class="chips" aria-label="Sub-topics">
                    <a class="chip<?php echo $tag === '' ? ' is-current' : ''; ?>" href="<?php echo $link(''); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $link. ?>"<?php echo $tag === '' ? ' aria-current="page"' : ''; ?>>All</a>
                    <?php foreach ($subs as $sub) : ?>
                        <a class="chip<?php echo $tag === $sub->slug ? ' is-current' : ''; ?>" href="<?php echo $link($sub->slug); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $link. ?>"<?php echo $tag === $sub->slug ? ' aria-current="page"' : ''; ?>><?php echo esc_html(mf_tag_label($sub)); ?></a>
                    <?php endforeach; ?>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="wrap cat-layout">
        <div class="cat-list">
            <?php if ($pinned) : ?>
                <?php $pc = mf_card_data($pinned); ?>
                <article class="pinned">
                    <div class="pinned__media">
                        <?php if ($pc['thumb'] !== '') : ?>
                            <?php echo $pc['thumb']; // phpcs:ignore WordPress.Security.EscapeOutput -- core-generated <img>. ?>
                        <?php else : ?>
                            <div class="mf-cover mf-cover--night" aria-hidden="true">
                                <span class="mf-cover__label">Start here</span>
                                <span class="mf-cover__phrase"><?php echo esc_html(\MoneyfrogCore\Content::coverPhrase($pc['title'], $pc['topic'])); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="pinned__body">
                        <p class="card__eyebrow">Pinned guide</p>
                        <h2 class="pinned__title"><a class="card__link" href="<?php echo esc_url($pc['url']); ?>"><?php echo esc_html($pc['title']); ?></a></h2>
                        <?php if ($pc['excerpt'] !== '') : ?><p class="card__excerpt"><?php echo esc_html($pc['excerpt']); ?></p><?php endif; ?>
                        <p class="card__meta">
                            <?php if ($pc['byline']) : ?><span class="card__by"><?php echo esc_html($pc['byline']); ?></span><?php endif; ?>
                            <time datetime="<?php echo esc_attr($pc['date_iso']); ?>"><?php echo esc_html($pc['date']); ?></time>
                            <span><?php echo esc_html($pc['read'] . ' min read'); ?></span>
                        </p>
                    </div>
                </article>
            <?php endif; ?>

            <?php if (have_posts()) : ?>
                <div class="rows">
                    <?php foreach ($wp_query->posts as $p) : ?>
                        <?php get_template_part('template-parts/card-row', null, ['post' => $p]); ?>
                    <?php endforeach; ?>
                </div>
                <?php get_template_part('template-parts/pagination', null, ['total' => (int) $wp_query->max_num_pages, 'current' => $paged]); ?>
            <?php elseif (!$pinned) : ?>
                <p class="lead">No articles match this filter yet. <a class="text-link" href="<?php echo esc_url($base); ?>">See all <?php echo esc_html($topic->name); ?> articles</a>.</p>
            <?php endif; ?>
        </div>
        <?php get_template_part('template-parts/sidebar', null, ['topic' => $topic]); ?>
    </div>
    <?php get_template_part('template-parts/advisor-cta'); ?>
</main>
<?php
get_footer();
