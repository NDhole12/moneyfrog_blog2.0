<?php
/**
 * Closing call to action: book a call with an advisor.
 * Args (all optional): ['title' => string, 'lead' => string, 'button' => string, 'phone' => bool, 'band' => bool].
 * Without args it renders the default wording used on topic, search and author pages.
 */

use MoneyfrogCore\Config;

if (!defined('ABSPATH')) {
    exit;
}

$title  = (string) ($args['title'] ?? '');
$lead   = (string) ($args['lead'] ?? 'Talk to a Moneyfrog advisor about your goals, your portfolio, or anything you read here.');
$button = (string) ($args['button'] ?? 'Book a call');
$phone  = (bool) ($args['phone'] ?? true);
$band   = (bool) ($args['band'] ?? false);
?>
<section class="advisor-cta<?php echo $band ? ' advisor-cta--band' : ''; ?>">
    <div class="wrap">
        <?php if ($title !== '') : ?>
            <h2 class="h2"><?php echo esc_html($title); ?></h2>
        <?php else : ?>
            <h2 class="h2">Reading is a start. A plan is <em>better</em>.</h2>
        <?php endif; ?>
        <p class="lead"><?php echo esc_html($lead); ?></p>
        <div class="advisor-cta__actions">
            <a class="btn btn--gold" href="<?php echo esc_url(Config::BOOK_CALL_URL); ?>"><?php echo esc_html($button); ?><?php echo $title !== '' ? ' ' . mf_icon('i-arrow') : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside mf_icon(). ?></a>
            <?php if ($phone) : ?>
                <a class="btn btn--ghost" href="tel:+919004932221">+91 90049 32221</a>
            <?php endif; ?>
        </div>
    </div>
</section>
