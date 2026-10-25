<?php
/** Blog home: featured story + picks, topics, Market Outlook band, latest, start-here guides, advisor CTA. */

use MoneyfrogCore\Config;

if (!defined('ABSPATH')) {
    exit;
}

$paged    = max(1, (int) get_query_var('paged'));
$s        = mf_home_sections();
$featured = $s['featured'];
$tiles    = array_diff_key(Config::TOPICS, ['guides' => true]);
get_header();
?>
<main id="main">
<?php if ($paged === 1) : ?>
    <h1 class="sr-only">Moneyfrog Insights</h1>
    <section class="home-top">
        <div class="wrap">
            <?php if ($featured) : ?>
                <?php get_template_part('template-parts/card', null, ['post' => $featured, 'layout' => 'featured']); ?>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($s['picks']) : ?>
    <section class="editors-picks" aria-labelledby="editors-picks-title">
        <div class="wrap">
            <div class="picks-head">
                <div class="picks-head__title">
                    <h2 class="picks-head__h" id="editors-picks-title">Editor&rsquo;s Picks</h2>
                    <span class="picks-head__bar" aria-hidden="true"></span>
                </div>
                <span class="picks-head__rule" aria-hidden="true"></span>
                <a class="picks-head__all" href="<?php echo esc_url(home_url('/#latest')); ?>" aria-label="All articles"><?php echo mf_icon('i-arrow'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside mf_icon(). ?></a>
            </div>
            <ol class="picks__list">
                <?php foreach ($s['picks'] as $i => $p) : ?>
                    <?php
                    $topic   = mf_post_topic($p);
                    $tname   = $topic ? $topic->name : 'Insights';
                    $imageId = mf_card_image_id($p->ID);
                    ?>
                    <li class="picks__item">
                        <span class="picks__n" aria-hidden="true"><?php echo esc_html(sprintf('%02d', $i + 1)); ?></span>
                        <div class="picks__thumb" aria-hidden="true">
                            <?php if ($imageId > 0) : ?>
                                <?php echo wp_get_attachment_image($imageId, 'mf-card', false, ['loading' => 'lazy', 'decoding' => 'async', 'alt' => '']); // phpcs:ignore WordPress.Security.EscapeOutput -- core-generated <img>. ?>
                            <?php else : ?>
                                <?php get_template_part('template-parts/cover', null, ['title' => get_the_title($p), 'topic' => $tname, 'variant' => $p->ID % 2 === 0 ? 'cream' : 'night']); ?>
                            <?php endif; ?>
                        </div>
                        <div class="picks__text">
                            <p class="card__eyebrow picks__topic"><?php echo esc_html($tname); ?></p>
                            <h3 class="picks__title"><a class="picks__link" href="<?php echo esc_url(get_permalink($p)); ?>"><?php echo esc_html(get_the_title($p)); ?></a></h3>
                        </div>
                        <span class="picks__arrow" aria-hidden="true"><?php echo mf_icon('i-arrow'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside mf_icon(). ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </section>
    <?php endif; ?>

    <section class="home-topics">
        <div class="wrap">
            <h2 class="h2 home-topics__title">Browse by topic</h2>
            <div class="topic-tiles">
                <?php foreach ($tiles as $slug => $name) : ?>
                    <?php
                    $term  = get_category_by_slug($slug);
                    $total = $term instanceof WP_Term ? (int) $term->count : 0;
                    ?>
                    <a class="topic-tile" href="<?php echo esc_url(home_url('/category/' . $slug . '/')); ?>">
                        <span class="topic-tile__icon" aria-hidden="true"><?php echo mf_icon(mf_topic_icon($slug)); ?></span>
                        <span class="topic-tile__name"><?php echo esc_html($name); ?></span>
                        <span class="topic-tile__blurb"><?php echo esc_html(Config::TOPIC_BLURBS[$slug] ?? ''); ?></span>
                        <span class="topic-tile__count"><?php echo esc_html(sprintf('%d %s', $total, $total === 1 ? 'article' : 'articles')); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <?php if ($s['outlook']) : ?>
    <section class="band outlook">
        <div class="wrap">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Every month</span>
                    <h2 class="h2">Market Outlook</h2>
                    <p class="lead">What moved the market last month, the events ahead, and how we are thinking about asset allocation.</p>
                </div>
                <a class="btn btn--outline" href="<?php echo esc_url(home_url('/category/market-outlook/')); ?>">All outlooks <?php echo mf_icon('i-arrow'); ?></a>
            </div>
            <div class="card-grid card-grid--4">
                <?php foreach ($s['outlook'] as $p) : ?>
                    <?php get_template_part('template-parts/card', null, ['post' => $p, 'layout' => 'compact']); ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>
<?php endif; ?>

    <section class="home-latest" id="latest">
        <div class="wrap">
            <h2 class="h2 home-latest__title">Latest articles</h2>
            <?php if ($s['latest']->have_posts()) : ?>
                <div class="card-grid card-grid--3">
                    <?php foreach ($s['latest']->posts as $p) : ?>
                        <?php get_template_part('template-parts/card', null, ['post' => $p]); ?>
                    <?php endforeach; ?>
                </div>
                <?php get_template_part('template-parts/pagination', null, ['total' => (int) $s['latest']->max_num_pages, 'current' => $paged]); ?>
            <?php else : ?>
                <p class="lead">No articles yet.</p>
            <?php endif; ?>
        </div>
    </section>

<?php if ($paged === 1 && $s['guides']) : ?>
    <section class="start-here">
        <div class="wrap start-here__inner">
            <div class="start-here__intro">
                <span class="eyebrow">Moneyfrog School</span>
                <h2 class="h2">New to investing? <em>Start here.</em></h2>
                <p class="lead">Five short reads, in order, that cover the basics before you put money anywhere.</p>
            </div>
            <ol class="start-here__list">
                <?php foreach ($s['guides'] as $i => $p) : ?>
                    <li>
                        <a href="<?php echo esc_url(get_permalink($p)); ?>">
                            <span class="start-here__n"><?php echo esc_html(sprintf('%02d', $i + 1)); ?></span>
                            <span class="start-here__title"><?php echo esc_html(get_the_title($p)); ?></span>
                            <?php echo mf_icon('i-arrow'); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </section>
<?php endif; ?>

    <?php get_template_part('template-parts/newsletter', null, ['variant' => 'band']); ?>
    <?php get_template_part('template-parts/advisor-cta'); ?>
</main>
<?php
get_footer();
