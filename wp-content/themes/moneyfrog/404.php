<?php
/** Not-found page, themed with the blog bar. */
get_header();
?>
<main id="main" class="wrap section not-found">
    <span class="eyebrow">Error 404</span>
    <h1 class="display">That page <em>moved</em> or never existed.</h1>
    <p class="lead">Try the topics above, search for what you were after, or head back to the latest articles.</p>
    <p class="not-found__cta"><a class="btn btn--gold" href="<?php echo esc_url(home_url('/')); ?>">Back to Insights <?php echo mf_icon('i-arrow'); ?></a></p>
</main>
<?php
get_footer();
