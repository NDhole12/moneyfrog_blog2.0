<?php
/** Fallback template; dedicated home/archive/single templates arrive in later tasks. */
get_header();
?>
<main id="main" class="wrap section">
<?php if (have_posts()) : ?>
    <ul class="plain-list">
    <?php while (have_posts()) : the_post(); ?>
        <li><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></li>
    <?php endwhile; ?>
    </ul>
<?php else : ?>
    <p class="lead">No articles yet.</p>
<?php endif; ?>
</main>
<?php
get_footer();
