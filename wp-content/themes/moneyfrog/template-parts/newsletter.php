<?php
/**
 * Newsletter signup. Built but switched off: renders nothing while
 * Config::NEWSLETTER_ENABLED is false, so no email field reaches the page.
 * Args: ['variant' => 'band'|'card'].
 */

use MoneyfrogCore\Config;

if (!defined('ABSPATH') || !Config::NEWSLETTER_ENABLED) {
    return;
}
$variant = ($args['variant'] ?? 'band') === 'card' ? 'card' : 'band';
$id      = 'nl-email-' . $variant;
?>
<?php if ($variant === 'band') : ?>
<section class="newsletter">
    <div class="wrap newsletter__inner">
        <div class="newsletter__copy">
            <span class="eyebrow">Newsletter</span>
            <h2 class="newsletter__title">Get the Market Outlook in your inbox.</h2>
            <p>One email a month. Unsubscribe any time.</p>
        </div>
        <form class="newsletter__form" method="post" action="#">
            <label class="sr-only" for="<?php echo esc_attr($id); ?>">Email address</label>
            <input id="<?php echo esc_attr($id); ?>" type="email" name="email" placeholder="you@example.com" autocomplete="email" required>
            <button type="submit" class="btn btn--gold">Subscribe</button>
        </form>
    </div>
</section>
<?php else : ?>
<form class="side-card side-card--newsletter" method="post" action="#">
    <p class="side-card__lead">The monthly Market Outlook, by email.</p>
    <label for="<?php echo esc_attr($id); ?>">Email address</label>
    <input id="<?php echo esc_attr($id); ?>" type="email" name="email" placeholder="you@example.com" autocomplete="email" required>
    <button type="submit" class="btn btn--ink">Subscribe</button>
</form>
<?php endif; ?>
