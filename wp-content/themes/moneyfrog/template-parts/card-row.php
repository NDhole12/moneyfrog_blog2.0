<?php
/** Horizontal list row (category, search, author). Args: ['post' => WP_Post, 'topic' => ?string label override]. The eyebrow is always a topic name, never a tag. */

if (!defined('ABSPATH')) {
    exit;
}

$post = $args['post'] ?? null;
if (!$post instanceof WP_Post) {
    return;
}
$c = mf_card_data($post);
?>
<article class="card-row">
    <div class="card-row__media">
        <?php
        if ($c['thumb'] !== '') {
            echo $c['thumb']; // phpcs:ignore WordPress.Security.EscapeOutput -- core-generated <img>.
        } else {
            get_template_part('template-parts/cover', null, ['title' => $c['title'], 'topic' => $c['topic'], 'variant' => $c['variant']]);
        }
        ?>
    </div>
    <div class="card-row__body">
        <p class="card__eyebrow"><?php echo esc_html((string) ($args['topic'] ?? $c['topic'])); ?></p>
        <h3 class="card__title"><a class="card__link" href="<?php echo esc_url($c['url']); ?>"><?php echo esc_html($c['title']); ?></a></h3>
        <?php if ($c['excerpt'] !== '') : ?>
            <p class="card__excerpt"><?php echo esc_html($c['excerpt']); ?></p>
        <?php endif; ?>
        <p class="card__meta">
            <?php if ($c['byline']) : ?><span class="card__by"><?php echo esc_html($c['byline']); ?></span><?php endif; ?>
            <time datetime="<?php echo esc_attr($c['date_iso']); ?>"><?php echo esc_html($c['date']); ?></time>
            <span><?php echo esc_html($c['read'] . ' min read'); ?></span>
        </p>
    </div>
</article>
