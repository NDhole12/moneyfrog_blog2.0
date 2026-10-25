<?php
/**
 * Article card. Args: ['post' => WP_Post, 'layout' => 'default'|'compact'|'featured'].
 * One link (the title) covers the whole card, so screen readers hear a single target.
 */

if (!defined('ABSPATH')) {
    exit;
}

$post = $args['post'] ?? null;
if (!$post instanceof WP_Post) {
    return;
}
$layout  = in_array($args['layout'] ?? '', ['compact', 'featured'], true) ? $args['layout'] : 'default';
$c       = mf_card_data($post, $layout === 'featured' ? 'mf-hero' : 'mf-card');
$heading = $layout === 'featured' ? 'h2' : 'h3';
$eyebrow = $c['topic'];
if ($layout === 'featured') {
    $eyebrow = 'Featured · ' . $c['topic'];
} elseif ($layout === 'compact') {
    $eyebrow = $c['month'];
}
?>
<article class="card card--<?php echo esc_attr($layout); ?>">
    <div class="card__media">
        <?php
        if ($c['thumb'] !== '') {
            echo $c['thumb']; // phpcs:ignore WordPress.Security.EscapeOutput -- core-generated <img>.
        } else {
            get_template_part('template-parts/cover', null, ['title' => $c['title'], 'topic' => $c['topic'], 'variant' => $c['variant']]);
        }
        ?>
    </div>
    <div class="card__body">
        <p class="card__eyebrow"><?php echo esc_html($eyebrow); ?></p>
        <<?php echo $heading; ?> class="card__title"><a class="card__link" href="<?php echo esc_url($c['url']); ?>"><?php echo esc_html($c['title']); ?></a></<?php echo $heading; ?>>
        <?php if ($layout !== 'compact' && $c['excerpt'] !== '') : ?>
            <p class="card__excerpt"><?php echo esc_html($c['excerpt']); ?></p>
        <?php endif; ?>
        <?php if ($layout !== 'compact') : ?>
            <p class="card__meta">
                <?php if ($c['byline']) : ?><span class="card__by"><?php echo esc_html($c['byline']); ?></span><?php endif; ?>
                <time datetime="<?php echo esc_attr($c['date_iso']); ?>"><?php echo esc_html($c['date']); ?></time>
                <span><?php echo esc_html($c['read'] . ' min read'); ?></span>
            </p>
        <?php endif; ?>
    </div>
</article>
