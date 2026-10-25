<?php
/** Author box under the article. Args: ['byline' => WP_Term]. Hidden when the byline has no bio. */

use MoneyfrogCore\Byline;

if (!defined('ABSPATH')) {
    exit;
}

$by = $args['byline'] ?? null;
if (!$by instanceof WP_Term) {
    return;
}
$bio = trim((string) get_term_meta($by->term_id, 'mf_bio', true));
if ($bio === '') {
    return;
}
$role  = trim((string) get_term_meta($by->term_id, 'mf_role', true));
$link  = get_term_link($by);
$first = explode(' ', trim($by->name))[0];
?>
<section class="author-box" aria-label="About the author">
    <span class="avatar avatar--lg" aria-hidden="true"><?php echo esc_html(Byline::initials($by->name)); ?></span>
    <div class="author-box__body">
        <p class="author-box__eyebrow">Written by</p>
        <p class="author-box__name"><?php echo esc_html($by->name); ?></p>
        <?php if ($role !== '') : ?><p class="author-box__role"><?php echo esc_html($role); ?></p><?php endif; ?>
        <p class="author-box__bio"><?php echo nl2br(esc_html($bio)); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped, then newlines to <br>. ?></p>
        <?php if (!is_wp_error($link)) : ?>
            <a class="text-link" href="<?php echo esc_url($link); ?>">All articles by <?php echo esc_html($first); ?> <?php echo mf_icon('i-arrow'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside mf_icon(). ?></a>
        <?php endif; ?>
    </div>
</section>
