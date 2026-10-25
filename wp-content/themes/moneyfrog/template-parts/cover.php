<?php
/**
 * Typographic cover for posts without a featured image.
 * Args: ['title' => string, 'topic' => string, 'variant' => 'cream'|'night'].
 */

use MoneyfrogCore\Content;

if (!defined('ABSPATH')) {
    exit;
}

$title   = (string) ($args['title'] ?? '');
$topic   = (string) ($args['topic'] ?? '');
$variant = ($args['variant'] ?? 'cream') === 'night' ? 'night' : 'cream';
$phrase  = Content::coverPhrase($title, $topic);
$label   = $variant === 'night' ? $topic : 'Moneyfrog Insights';

// Night covers set the last word in gold, as in the design.
$head = $phrase;
$tail = '';
if ($variant === 'night' && str_contains($phrase, ' ')) {
    $cut  = (int) strrpos($phrase, ' ');
    $head = substr($phrase, 0, $cut + 1);
    $tail = substr($phrase, $cut + 1);
}
?>
<div class="mf-cover mf-cover--<?php echo esc_attr($variant); ?>" aria-hidden="true">
    <span class="mf-cover__label"><?php echo esc_html($label); ?></span>
    <span class="mf-cover__phrase"><?php echo esc_html($head); ?><?php echo $tail !== '' ? '<span>' . esc_html($tail) . '</span>' : ''; ?></span>
</div>
