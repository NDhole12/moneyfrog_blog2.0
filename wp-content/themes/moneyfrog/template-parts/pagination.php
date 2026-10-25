<?php
/** Numbered pagination. Args: ['total' => int, 'current' => int]; query args in the URL are kept. */

if (!defined('ABSPATH')) {
    exit;
}

$total   = (int) ($args['total'] ?? 1);
$current = max(1, (int) ($args['current'] ?? 1));
if ($total < 2) {
    return;
}
$links = paginate_links([
    'total'     => $total,
    'current'   => $current,
    'type'      => 'array',
    'mid_size'  => 1,
    'end_size'  => 1,
    'prev_text' => 'Previous',
    'next_text' => 'Next ' . mf_icon('i-arrow'),
]);
if (!is_array($links)) {
    return;
}
$allowed = [
    'a'    => ['class' => true, 'href' => true],
    'span' => ['class' => true, 'aria-current' => true],
    'svg'  => ['class' => true, 'aria-hidden' => true, 'focusable' => true],
    'use'  => ['href' => true],
];
?>
<nav class="pagination" aria-label="Pagination">
    <?php foreach ($links as $link) : ?>
        <?php echo wp_kses($link, $allowed); ?>
    <?php endforeach; ?>
</nav>
