<?php
/** Removes everything seed.php creates (run through seed.sh --remove). Idempotent, localhost only. */
$home = (string) get_option('home');
if (!preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', rtrim($home, '/'))) {
    fwrite(STDERR, "seed --remove: refusing non-local site\n");
    exit(2);
}
global $wpdb;
$ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND (post_name LIKE 'codespark-qa-%' OR post_title LIKE 'Codespark QA%')");
foreach ($ids as $id) {
    foreach (get_children(['post_parent' => (int) $id, 'post_type' => 'attachment', 'numberposts' => -1, 'fields' => 'ids']) as $att) {
        wp_delete_attachment((int) $att, true);
    }
    wp_delete_post((int) $id, true);
}
foreach ($wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title LIKE 'Codespark QA%'") as $att) {
    wp_delete_attachment((int) $att, true);
}
$byline = get_term_by('slug', 'codespark-qa', 'mf_byline');
if ($byline) {
    wp_delete_term($byline->term_id, 'mf_byline');
}
// Fixture tags are removed only when no real post uses them; topic categories are shared with real content and stay.
foreach (['SIP', 'Popular', 'Editors pick', 'Start here', 'Equity Funds', 'Debt Funds'] as $name) {
    $tag = get_term_by('name', $name, 'post_tag');
    if ($tag) {
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $tag->term_taxonomy_id));
        if ($count === 0) {
            wp_delete_term($tag->term_id, 'post_tag');
        }
    }
}
WP_CLI::success('fixtures removed');
