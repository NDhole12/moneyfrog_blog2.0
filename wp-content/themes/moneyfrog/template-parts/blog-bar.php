<?php
/**
 * Blog bar: wordmark, topic pills, search. Args: ['current' => ?string topic slug].
 */

use MoneyfrogCore\Config;

if (!defined('ABSPATH')) {
    exit;
}

$current = $args['current'] ?? null;
$on_all  = $current === null && (is_home() || is_front_page());
?>
<div class="blog-bar">
    <div class="wrap blog-bar__inner">
        <a class="blog-bar__mark" href="<?php echo esc_url(home_url('/')); ?>">Insights<span>.</span></a>
        <nav class="blog-bar__topics" aria-label="Blog topics">
            <a class="pill<?php echo $on_all ? ' is-current' : ''; ?>" href="<?php echo esc_url(home_url('/')); ?>"<?php echo $on_all ? ' aria-current="page"' : ''; ?>>All</a>
            <?php foreach (Config::TOPICS as $slug => $name) : ?>
                <?php $is_current = $current === $slug; ?>
                <a class="pill<?php echo $is_current ? ' is-current' : ''; ?>" href="<?php echo esc_url(home_url('/category/' . $slug . '/')); ?>"<?php echo $is_current ? ' aria-current="page"' : ''; ?>><?php echo esc_html($name); ?></a>
            <?php endforeach; ?>
        </nav>
        <form class="blog-bar__search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
            <svg class="icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            <label class="sr-only" for="mf-search">Search articles</label>
            <input id="mf-search" type="search" name="s" placeholder="Search articles" value="<?php echo esc_attr(get_search_query()); ?>">
        </form>
    </div>
</div>
