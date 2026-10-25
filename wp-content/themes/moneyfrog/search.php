<?php
/** Search results as list rows, with topic shortcuts when nothing matches. */

use MoneyfrogCore\Config;

if (!defined('ABSPATH')) {
    exit;
}

global $wp_query;
$paged = max(1, (int) get_query_var('paged'));
$found = (int) $wp_query->found_posts;
mf_prime_posts($wp_query->posts);
get_header();
?>
<main id="main">
    <section class="cat-head band">
        <div class="wrap">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="<?php echo esc_url(home_url('/')); ?>">Insights</a>
                <span aria-hidden="true">/</span>
                <span aria-current="page">Search</span>
            </nav>
            <div class="cat-head__main">
                <div class="cat-head__copy">
                    <span class="eyebrow"><?php echo esc_html(sprintf('Search · %d %s', $found, $found === 1 ? 'article' : 'articles')); ?></span>
                    <h1 class="display">Results for &ldquo;<?php echo esc_html(get_search_query()); ?>&rdquo;</h1>
                </div>
            </div>
        </div>
    </section>
    <div class="wrap search-layout">
        <?php if (have_posts()) : ?>
            <div class="rows">
                <?php foreach ($wp_query->posts as $p) : ?>
                    <?php get_template_part('template-parts/card-row', null, ['post' => $p]); ?>
                <?php endforeach; ?>
            </div>
            <?php get_template_part('template-parts/pagination', null, ['total' => (int) $wp_query->max_num_pages, 'current' => $paged]); ?>
        <?php else : ?>
            <p class="lead">Nothing matched that search. Try a different word, or start from a topic.</p>
            <div class="chips search-topics">
                <?php foreach (Config::TOPICS as $slug => $name) : ?>
                    <a class="chip" href="<?php echo esc_url(home_url('/category/' . $slug . '/')); ?>"><?php echo esc_html($name); ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php get_template_part('template-parts/advisor-cta'); ?>
</main>
<?php
get_footer();
