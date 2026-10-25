<?php
/** Closing call to action: book a call with an advisor. */

use MoneyfrogCore\Config;

if (!defined('ABSPATH')) {
    exit;
}
?>
<section class="advisor-cta">
    <div class="wrap">
        <h2 class="h2">Reading is a start. A plan is <em>better</em>.</h2>
        <p class="lead">Talk to a Moneyfrog advisor about your goals, your portfolio, or anything you read here.</p>
        <div class="advisor-cta__actions">
            <a class="btn btn--gold" href="<?php echo esc_url(Config::BOOK_CALL_URL); ?>">Book a call</a>
            <a class="btn btn--ghost" href="tel:+919004932221">+91 90049 32221</a>
        </div>
    </div>
</section>
