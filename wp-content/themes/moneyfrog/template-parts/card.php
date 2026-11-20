<?php
/**
 * Article card. Args: ['post' => WP_Post, 'layout' => 'default'|'compact'|'featured', 'cta' => string,
 * 'eyebrow' => string (replaces the layout's own label), 'excerpt' => bool (show it on a compact card)].
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
$heading = 'h3'; // every card sits under a section <h2>
$cta     = (string) ($args['cta'] ?? ''); // visual "Read article" cue; the title link already covers the card
$eyebrow = $c['topic'];
if ($layout === 'featured') {
    $eyebrow = 'Featured · ' . $c['topic'];
} elseif ($layout === 'compact') {
    $eyebrow = $c['month'];
}
if (trim((string) ($args['eyebrow'] ?? '')) !== '') {
    $eyebrow = trim((string) $args['eyebrow']);
}
$showExcerpt = ($layout !== 'compact' || !empty($args['excerpt'])) && $c['excerpt'] !== '';
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
        <?php if ($showExcerpt) : ?>
            <p class="card__excerpt"><?php echo esc_html($c['excerpt']); ?></p>
        <?php endif; ?>
        <?php if ($layout !== 'compact') : ?>
            <p class="card__meta">
                <?php if ($c['byline']) : ?><span class="card__by"><?php echo esc_html($c['byline']); ?></span><?php endif; ?>
                <time datetime="<?php echo esc_attr($c['date_iso']); ?>"><?php echo esc_html($c['date']); ?></time>
                <span><?php echo esc_html($c['read'] . ' min read'); ?></span>
            </p>
        <?php endif; ?>
        <?php if ($cta !== '') : ?>
            <span class="card__cta" aria-hidden="true"><?php echo esc_html($cta); ?> <?php echo mf_icon('i-arrow'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside mf_icon(). ?></span>
        <?php endif; ?>
    </div>
</article>
