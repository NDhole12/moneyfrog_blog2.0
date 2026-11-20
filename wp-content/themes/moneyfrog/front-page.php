<?php
/**
 * Blog home, in the approved order: hero, featured articles, explore by financial interest,
 * latest insights, Market Outlook, Moneyfrog School, suggest a topic, app, final call to action.
 * Section backgrounds alternate ivory and beige, starting with an ivory hero.
 * Pages 2+ (/page/N/) show only the latest list; "Load More Articles" reads them in place.
 */

use MoneyfrogCore\Config;

if (!defined('ABSPATH')) {
    exit;
}

$paged    = max(1, (int) get_query_var('paged'));
$s        = mf_home_sections();
$featured = $s['featured'];
$pages    = (int) $s['latest']->max_num_pages;
$img      = get_template_directory_uri() . '/assets/img/home/';
get_header();
?>
<main id="main">
<?php if ($paged === 1) : ?>
    <section class="home-hero">
        <div class="wrap home-hero__inner">
            <div class="home-hero__copy">
                <span class="eyebrow">Moneyfrog Insights</span>
                <h1 class="home-hero__title">Financial Blogs, Market Outlooks<br class="home-hero__break"> &amp; Investment Guides</h1>
                <p class="home-hero__lead">Discover investment articles, monthly market outlooks and educational guides covering mutual funds, equity, fixed income, insurance and more. Explore financial topics and build your knowledge with Moneyfrog.</p>
            </div>
            <img class="home-hero__art" src="<?php echo esc_url($img . 'hero.webp'); ?>" alt="" width="880" height="880" decoding="async" fetchpriority="high">
        </div>
    </section>

    <?php if ($featured !== []) : ?>
    <section class="home-featured">
        <div class="wrap">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Handpicked for you</span>
                    <h2 class="h2">Featured Articles</h2>
                    <p class="lead">A selected read offering perspectives worth exploring.</p>
                </div>
            </div>
            <div class="featured-cards">
                <?php foreach ($featured as $f) : ?>
                    <?php get_template_part('template-parts/card', null, ['post' => $f['post'], 'layout' => 'compact', 'eyebrow' => $f['label'], 'excerpt' => true, 'cta' => $f['cta']]); ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="home-interests">
        <div class="wrap">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Discover by category</span>
                    <h2 class="h2">Explore articles by financial interest</h2>
                    <p class="lead">Find insights and guidance on key financial areas that matter to you.</p>
                </div>
            </div>
            <div class="interest-tiles">
                <?php foreach (mf_home_interests() as $tile) : ?>
                    <a class="interest-tile" href="<?php echo esc_url($tile['url']); ?>" aria-label="<?php echo esc_attr($tile['name'] . ': explore articles'); ?>">
                        <img class="interest-tile__icon" src="<?php echo esc_url($tile['icon']); ?>" alt="" width="192" height="192" loading="lazy" decoding="async">
                        <span class="interest-tile__text">
                            <span class="interest-tile__name"><?php echo esc_html($tile['name']); ?></span>
                            <span class="interest-tile__cta">Explore Articles <?php echo mf_icon('i-arrow'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside mf_icon(). ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

    <section class="home-latest" id="latest">
        <div class="wrap">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Fresh perspectives</span>
                    <h2 class="h2">Latest insights</h2>
                    <p class="lead">The most recently published articles, separate from the featured story.</p>
                </div>
            </div>
            <?php if ($s['latest']->have_posts()) : ?>
                <div class="card-grid card-grid--latest">
                    <?php foreach ($s['latest']->posts as $p) : ?>
                        <?php get_template_part('template-parts/card', null, ['post' => $p, 'cta' => 'Read article']); ?>
                    <?php endforeach; ?>
                </div>
                <?php if ($paged === 1 && $pages > 1) : ?>
                    <div class="home-latest__more">
                        <a class="btn btn--outline" href="<?php echo esc_url(home_url('/page/2/')); ?>#latest" data-load-more data-base="<?php echo esc_url(home_url('/page/')); ?>" data-next="2" data-total="<?php echo esc_attr((string) $pages); ?>">Load More Articles</a>
                    </div>
                <?php else : ?>
                    <?php get_template_part('template-parts/pagination', null, ['total' => $pages, 'current' => $paged]); ?>
                <?php endif; ?>
            <?php else : ?>
                <p class="lead">No articles yet.</p>
            <?php endif; ?>
        </div>
    </section>

<?php if ($paged === 1) : ?>
    <?php if ($s['outlook']) : ?>
    <section class="outlook">
        <div class="wrap">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Every month</span>
                    <h2 class="h2">Market Outlook</h2>
                    <p class="lead">Market perspectives, recent editions and access to the archive.</p>
                </div>
                <a class="btn btn--outline" href="<?php echo esc_url(home_url('/category/market-outlook/')); ?>">View All Outlooks <?php echo mf_icon('i-arrow'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside mf_icon(). ?></a>
            </div>
            <div class="card-grid card-grid--4">
                <?php foreach ($s['outlook'] as $p) : ?>
                    <?php get_template_part('template-parts/card', null, ['post' => $p, 'layout' => 'compact', 'cta' => 'Read outlook']); ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($s['guides']) : ?>
    <section class="home-school">
        <div class="wrap">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Learn with Moneyfrog</span>
                    <h2 class="h2">Moneyfrog School</h2>
                    <p class="lead">New to investing? Start here.</p>
                </div>
            </div>
            <div class="school">
                <div class="school__intro">
                    <span class="eyebrow">Moneyfrog School</span>
                    <h3 class="school__title">New to investing? <em>Start here.</em></h3>
                    <p>Short reads, in order, that cover the basics before you put money anywhere.</p>
                    <a class="school__more" href="<?php echo esc_url(home_url('/category/guides/')); ?>">Explore School &amp; Guides <?php echo mf_icon('i-arrow'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside mf_icon(). ?></a>
                </div>
                <ol class="school__list">
                    <?php foreach ($s['guides'] as $i => $p) : ?>
                        <li>
                            <a href="<?php echo esc_url(get_permalink($p)); ?>">
                                <span class="school__n"><?php echo esc_html(sprintf('%02d', $i + 1)); ?></span>
                                <span class="school__name"><?php echo esc_html(get_the_title($p)); ?></span>
                                <?php echo mf_icon('i-arrow'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside mf_icon(). ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="home-suggest">
        <div class="wrap">
            <div class="suggest">
                <div class="suggest__copy">
                    <span class="eyebrow">Your voice matters</span>
                    <h2 class="h2">Have a financial topic you&rsquo;d like us to cover?</h2>
                    <p class="lead">Write to us with your questions or suggest a topic you&rsquo;d like to read about. We&rsquo;d love to hear from you.</p>
                </div>
                <a class="btn btn--gold" href="<?php echo esc_url('mailto:' . Config::BLOG_EMAIL); ?>" aria-label="<?php echo esc_attr('Email ' . Config::BLOG_EMAIL); ?>"><?php echo mf_icon('i-mail'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside mf_icon(). ?> <?php echo esc_html(Config::BLOG_EMAIL); ?></a>
            </div>
        </div>
    </section>

    <section class="home-app">
        <div class="wrap">
            <div class="app-promo">
                <img class="app-promo__art" src="<?php echo esc_url($img . 'app.webp'); ?>" alt="The Insights section in the Moneyfrog app" width="1080" height="720" loading="lazy" decoding="async">
                <div class="app-promo__copy">
                    <span class="eyebrow">Insights on the Moneyfrog app</span>
                    <h2 class="h2">Insights, now on the go.</h2>
                    <p class="lead">Read market outlooks, guides and the latest articles, anytime on the Moneyfrog app.</p>
                </div>
            </div>
        </div>
    </section>

    <?php get_template_part('template-parts/newsletter', null, ['variant' => 'band']); ?>
    <?php
    get_template_part('template-parts/advisor-cta', null, [
        'title'  => 'Ready to Put Your Money to Work?',
        'lead'   => 'Discover investment options across mutual funds, equities and more.',
        'button' => 'Connect with Moneyfrog',
        'url'    => Config::CONSULT_URL,
        'phone'  => false,
    ]);
    ?>
<?php endif; ?>
</main>
<?php
get_footer();
