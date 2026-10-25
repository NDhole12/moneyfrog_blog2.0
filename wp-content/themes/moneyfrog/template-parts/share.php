<?php
/**
 * Share row. Args: ['url' => string, 'title' => string].
 * Without JavaScript the copy control is a plain link to the post; copy-link.js upgrades it.
 */

if (!defined('ABSPATH')) {
    exit;
}

$url   = (string) ($args['url'] ?? '');
$title = (string) ($args['title'] ?? '');
if ($url === '') {
    return;
}
$icons = ['LinkedIn' => 'i-linkedin', 'WhatsApp' => 'i-whatsapp', 'X' => 'i-x'];
?>
<div class="share" role="group" aria-label="Share this article">
    <?php foreach (mf_share_links($url, $title) as $label => $href) : ?>
        <a class="share__btn" href="<?php echo esc_url($href); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr('Share on ' . $label . ' (opens in a new tab)'); ?>">
            <?php echo mf_icon($icons[$label], 'icon icon--fill'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside mf_icon(). ?>
        </a>
    <?php endforeach; ?>
    <a class="share__btn share__copy" href="<?php echo esc_url($url); ?>" data-copy-link aria-label="Copy link">
        <?php echo mf_icon('i-link'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside mf_icon(). ?>
    </a>
    <span class="share__status" aria-live="polite"></span>
</div>
