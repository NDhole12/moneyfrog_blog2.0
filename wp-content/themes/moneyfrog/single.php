<?php
/** Single article: header, hero, optional table of contents, body, author, advisor box, prev/next, keep reading. */

use MoneyfrogCore\Byline;
use MoneyfrogCore\Config;
use MoneyfrogCore\Content;

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<main id="main">
<?php
while (have_posts()) :
    the_post();
    $post     = get_post();
    $c        = mf_card_data($post, 'mf-hero');
    $topic    = mf_post_topic($post);
    $by       = Byline::forPost($post->ID);
    $role     = $by ? trim((string) get_term_meta($by->term_id, 'mf_role', true)) : '';
    $byUrl    = $by ? get_term_link($by) : '';
    $content  = apply_filters('the_content', get_the_content()); // heading ids come from ContentHooks, so the TOC below matches.
    $content  = str_replace(']]>', ']]&gt;', $content);
    $toc      = Content::toc($content);
    $adj      = mf_adjacent_posts($post, $topic);
    $related  = mf_related_posts($post, $topic);
    $tags     = mf_post_tag_links($post, $topic);
    $dek      = mf_post_standfirst($post);
    $topicUrl = $topic ? get_term_link($topic) : '';
    $alt      = has_post_thumbnail($post) ? trim((string) get_post_meta(get_post_thumbnail_id($post), '_wp_attachment_image_alt', true)) : '';
    ?>
    <div class="read-progress" role="progressbar" aria-label="Reading progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" hidden><span class="read-progress__bar"></span></div>
    <article <?php post_class('post'); ?>>
        <header class="post-head">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="<?php echo esc_url(home_url('/')); ?>">Insights</a>
                <?php if ($topic && !is_wp_error($topicUrl)) : ?>
                    <span aria-hidden="true">/</span>
                    <a href="<?php echo esc_url($topicUrl); ?>" aria-current="page"><?php echo esc_html($topic->name); ?></a>
                <?php endif; ?>
            </nav>
            <p class="eyebrow post-head__eyebrow"><?php echo esc_html($c['topic'] . ' · ' . get_the_date('F Y', $post)); ?></p>
            <h1 class="display post-head__title"><?php echo esc_html($c['title']); ?></h1>
            <?php if ($dek !== '') : ?><p class="post-head__dek"><?php echo esc_html($dek); ?></p><?php endif; ?>
            <div class="post-head__meta">
                <div class="byline">
                    <?php if ($by) : ?>
                        <span class="avatar" aria-hidden="true"><?php echo esc_html(Byline::initials($by->name)); ?></span>
                    <?php endif; ?>
                    <p class="byline__text">
                        <?php if ($by) : ?>
                            <span class="byline__name"><?php if (!is_wp_error($byUrl)) : ?><a href="<?php echo esc_url($byUrl); ?>"><?php echo esc_html($by->name); ?></a><?php else : echo esc_html($by->name); endif; ?></span>
                        <?php endif; ?>
                        <span class="byline__when"><?php if ($role !== '') : echo esc_html($role) . ' · '; endif; ?><time datetime="<?php echo esc_attr($c['date_iso']); ?>"><?php echo esc_html($c['date']); ?></time> · <?php echo esc_html($c['read'] . ' min read'); ?></span>
                    </p>
                </div>
                <?php get_template_part('template-parts/share', null, ['url' => $c['url'], 'title' => $c['title']]); ?>
            </div>
        </header>

        <?php if (has_post_thumbnail($post)) : // No featured image, no hero: the post's own first image already opens the article. ?>
        <div class="post-hero">
            <?php echo get_the_post_thumbnail($post, 'mf-hero', ['alt' => $alt !== '' ? $alt : $c['title'], 'loading' => 'eager', 'fetchpriority' => 'high', 'decoding' => 'async']); // phpcs:ignore WordPress.Security.EscapeOutput -- core-generated <img>. ?>
        </div>
        <?php endif; ?>

        <div class="post-body<?php echo $toc === [] ? ' post-body--solo' : ''; ?>">
            <?php if ($toc !== []) : ?>
                <nav class="toc" aria-label="On this page">
                    <p class="toc__title">On this page</p>
                    <ol class="toc__list">
                        <?php foreach ($toc as $item) : ?>
                            <li><a href="#<?php echo esc_attr($item['id']); ?>"><?php echo esc_html($item['text']); ?></a></li>
                        <?php endforeach; ?>
                    </ol>
                </nav>
            <?php endif; ?>

            <div class="post-main">
                <div class="entry-content"><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content filters (sanitised on import, ids added). ?></div>

                <?php get_template_part('template-parts/disclaimer'); ?>

                <?php if ($tags !== []) : ?>
                    <ul class="chips post-tags" aria-label="Tags">
                        <?php foreach ($tags as $tag) : ?>
                            <li><a class="chip" href="<?php echo esc_url($tag['url']); ?>"><?php echo esc_html($tag['name']); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php
                if ($by) {
                    get_template_part('template-parts/author-box', null, ['byline' => $by]);
                }
                ?>

                <aside class="post-advisor" aria-label="Talk to an advisor">
                    <p class="post-advisor__text">Questions about what this means for your portfolio?</p>
                    <a class="btn btn--gold" href="<?php echo esc_url(Config::BOOK_CALL_URL); ?>">Talk to an advisor</a>
                </aside>

                <?php if ($adj['prev'] || $adj['next']) : ?>
                    <nav class="post-nav" aria-label="Previous and next articles">
                        <?php foreach (['prev' => 'Previous', 'next' => 'Next'] as $dir => $label) : ?>
                            <?php if ($adj[$dir]) : ?>
                                <a class="post-nav__item" href="<?php echo esc_url(get_permalink($adj[$dir])); ?>" rel="<?php echo esc_attr($dir); ?>">
                                    <span class="post-nav__label"><?php echo $dir === 'prev' ? '← ' : ''; ?><?php echo esc_html($label); ?><?php echo $dir === 'next' ? ' →' : ''; ?></span>
                                    <span class="post-nav__title"><?php echo esc_html(get_the_title($adj[$dir])); ?></span>
                                </a>
                            <?php else : ?>
                                <span class="post-nav__item post-nav__item--empty">
                                    <span class="post-nav__label"><?php echo esc_html($label); ?></span>
                                    <span class="post-nav__title"><?php echo esc_html($dir === 'next' ? 'This is the newest article in ' . $c['topic'] . '.' : 'This is the first article in ' . $c['topic'] . '.'); ?></span>
                                </span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </article>

    <?php if ($related !== []) : ?>
        <section class="keep-reading">
            <div class="wrap">
                <h2 class="keep-reading__title">Keep reading</h2>
                <div class="card-grid card-grid--3">
                    <?php
                    foreach ($related as $rel) {
                        get_template_part('template-parts/card', null, ['post' => $rel]);
                    }
                    ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
<?php endwhile; ?>
    <?php get_template_part('template-parts/newsletter'); ?>
</main>
<?php get_footer(); ?>
