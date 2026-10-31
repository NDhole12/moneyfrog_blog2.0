<?php
/** Byline (author) archive: photo, role and bio in the compact header band, then list rows as on topic pages. */

if (!defined('ABSPATH')) {
    exit;
}

$by = get_queried_object();
if (!$by instanceof WP_Term) {
    get_template_part('404');
    return;
}
global $wp_query;
$paged = max(1, (int) get_query_var('paged'));
$found = (int) $wp_query->found_posts;
$bio   = trim((string) get_term_meta($by->term_id, 'mf_bio', true));
$role  = trim((string) get_term_meta($by->term_id, 'mf_role', true));
mf_prime_posts($wp_query->posts);
get_header();
?>
<main id="main">
    <section class="cat-head band">
        <div class="wrap">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="<?php echo esc_url(home_url('/')); ?>">Insights</a>
                <span aria-hidden="true">/</span>
                <span aria-current="page"><?php echo esc_html($by->name); ?></span>
            </nav>
            <div class="cat-head__main cat-head__main--author">
                <?php echo mf_byline_avatar($by, 'xl'); // phpcs:ignore WordPress.Security.EscapeOutput -- core <img> or escaped initials. ?>
                <div class="cat-head__copy">
                    <span class="eyebrow"><?php echo esc_html(sprintf('Author · %d %s', $found, $found === 1 ? 'article' : 'articles')); ?></span>
                    <h1 class="display"><?php echo esc_html($by->name); ?></h1>
                    <?php if ($role !== '') : ?><p class="cat-head__role"><?php echo esc_html($role); ?></p><?php endif; ?>
                    <?php if ($bio !== '') : ?><p class="lead"><?php echo esc_html($bio); ?></p><?php endif; ?>
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
            <p class="lead">No articles by this author yet.</p>
        <?php endif; ?>
    </div>
    <?php get_template_part('template-parts/advisor-cta'); ?>
</main>
<?php
get_footer();
