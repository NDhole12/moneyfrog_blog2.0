<?php
/** Category sidebar. Args: ['topic' => WP_Term]. */

use MoneyfrogCore\Config;

if (!defined('ABSPATH')) {
    exit;
}

$topic = $args['topic'] ?? null;
if (!$topic instanceof WP_Term) {
    return;
}
$popular = mf_popular_posts($topic);
?>
<aside class="sidebar" aria-label="Sidebar">
    <div class="side-card side-card--invest">
        <span class="side-card__eyebrow">Invest with Moneyfrog</span>
        <p class="side-card__lead">Start a SIP with an advisor beside you.</p>
        <p>Regular plans, reviewed with you, tracked in the Moneyfrog app.</p>
        <a class="btn btn--gold btn--sm" href="<?php echo esc_url(Config::BOOK_CALL_URL); ?>">Talk to an advisor</a>
        <p class="side-card__risk">Mutual Fund investments are subject to market risks. Read all scheme related documents carefully.</p>
    </div>

    <?php if ($popular) : ?>
    <div class="side-card side-card--popular">
        <h2 class="side-heading">Popular in <?php echo esc_html($topic->name); ?></h2>
        <ol class="popular">
            <?php foreach ($popular as $i => $p) : ?>
                <li><span class="popular__n"><?php echo esc_html((string) ($i + 1)); ?></span><a href="<?php echo esc_url(get_permalink($p)); ?>"><?php echo esc_html(get_the_title($p)); ?></a></li>
            <?php endforeach; ?>
        </ol>
    </div>
    <?php endif; ?>

    <?php get_template_part('template-parts/newsletter', null, ['variant' => 'card']); ?>

    <div class="side-topics">
        <h2 class="side-heading">Other topics</h2>
        <div class="chips">
            <?php foreach (Config::TOPICS as $slug => $name) : ?>
                <?php if ($slug !== $topic->slug) : ?>
                    <a class="chip" href="<?php echo esc_url(home_url('/category/' . $slug . '/')); ?>"><?php echo esc_html($name); ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</aside>
