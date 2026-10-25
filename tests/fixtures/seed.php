<?php
/** QA fixtures (run through seed.sh). Titles are prefixed "Codespark QA –" so they are obviously test data. */
$home = (string) get_option('home');
if (!preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', rtrim($home, '/'))) {
    fwrite(STDERR, "seed: refusing non-local site\n");
    exit(2);
}
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

foreach (\MoneyfrogCore\Config::TOPICS as $slug => $name) {
    if (!term_exists($slug, 'category')) {
        wp_insert_term($name, 'category', ['slug' => $slug]);
    }
}
if (!term_exists('codespark-qa', 'mf_byline')) {
    wp_insert_term('Codespark QA', 'mf_byline', ['slug' => 'codespark-qa']);
}

$para = 'Test paragraph for layout checks. It repeats a few plain sentences so that excerpts, reading time and wrapping can be judged by eye. Nothing here is advice. ';
$body = static function (bool $twoHeadings) use ($para): string {
    $html = '<p>' . str_repeat($para, 6) . '</p>';
    if ($twoHeadings) {
        $html .= '<h2>First section</h2><p>' . str_repeat($para, 8) . '</p><h2>Second section</h2><p>' . str_repeat($para, 8) . '</p>';
    }
    return $html;
};

// [slug suffix, title, topic, tags, days ago, mf_order|null]
$fixtures = [
    ['outlook-oct', 'Market Outlook: October 2026', 'market-outlook', [], 1, null],
    ['outlook-sep', 'Market Outlook: September 2026', 'market-outlook', [], 30, null],
    ['outlook-aug', 'Market Outlook: August 2026', 'market-outlook', [], 60, null],
    ['outlook-jul', 'Market Outlook: July 2026', 'market-outlook', [], 90, null],
    ['outlook-jun', 'Market Outlook: June 2026', 'market-outlook', [], 120, null],
    ['sip-vs-lump', 'SIP vs. lump sum, which is better?', 'mutual-funds', ['SIP', 'Popular', 'Editors pick'], 2, null],
    ['direct-regular', 'Direct plan vs regular plan', 'mutual-funds', ['Popular', 'Equity Funds'], 3, null],
    ['sip-lower-return', 'My SIP shows a lower return, what now?', 'mutual-funds', ['Popular', 'SIP'], 4, null],
    ['select-funds', 'How to select mutual funds', 'mutual-funds', ['Start here'], 5, null],
    ['cash-funds', 'What are cash management funds?', 'mutual-funds', ['Debt Funds'], 6, null],
    ['growth-dividend', 'Growth vs dividend options', 'mutual-funds', ['Equity Funds'], 7, null],
    ['debt-vs-fd', 'Debt mutual fund vs fixed deposit', 'mutual-funds', ['Debt Funds'], 8, null],
    ['seven-wonders-sip', 'Seven wonders of SIP', 'mutual-funds', ['SIP'], 9, null],
    ['market-falls', 'What to do when the market falls', 'mutual-funds', [], 10, null],
    ['stp-swp', 'STP and SWP in plain language', 'mutual-funds', [], 11, null],
    ['elss-basics', 'ELSS basics', 'mutual-funds', [], 12, null],
    ['folio-kyc', 'Folios and KYC explained', 'mutual-funds', [], 13, null],
    ['tax-saving', 'How you should save taxes', 'tax', ['Editors pick'], 14, null],
    ['capital-gains', 'Capital gains on mutual funds', 'tax', [], 15, null],
    ['term-plan', 'Benefits of taking a term plan', 'insurance', ['Editors pick'], 16, null],
    ['health-cover', 'Why health cover matters', 'insurance', [], 17, null],
    ['retirement-income', 'How to plan for retirement income', 'retirement', ['Editors pick'], 18, null],
    ['goal-planning', 'Planning for goals by life stage', 'financial-planning', [], 19, null],
    ['investing-101', 'Investing 101', 'guides', ['Start here'], 200, 1],
    ['why-mf', 'Why mutual funds suit beginners', 'guides', ['Start here'], 201, 2],
    ['asset-allocation', 'Asset allocation basics', 'guides', ['Start here'], 202, 3],
];

$byline = get_term_by('slug', 'codespark-qa', 'mf_byline');

foreach ($fixtures as $i => [$suffix, $title, $topic, $tags, $days, $order]) {
    $slug = 'codespark-qa-' . $suffix;
    if (get_page_by_path($slug, OBJECT, 'post')) {
        continue;
    }
    $cat  = get_term_by('slug', $topic, 'category');
    $when = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
    $id   = wp_insert_post([
        'post_type'     => 'post',
        'post_status'   => 'publish',
        'post_name'     => $slug,
        'post_title'    => 'Codespark QA – ' . $title,
        'post_content'  => $body($suffix === 'sip-vs-lump'),
        'post_excerpt'  => 'Codespark QA test article about ' . strtolower($title) . '.',
        'post_date_gmt' => $when,
        'post_date'     => get_date_from_gmt($when),
        'post_category' => [(int) $cat->term_id],
    ], true);
    if (is_wp_error($id)) {
        fwrite(STDERR, 'seed: ' . $id->get_error_message() . "\n");
        continue;
    }
    if ($tags) {
        wp_set_post_tags($id, $tags);
    }
    if ($byline instanceof WP_Term) {
        wp_set_object_terms($id, [(int) $byline->term_id], 'mf_byline');
    }
    if ($order !== null) {
        update_post_meta($id, 'mf_order', $order);
    }
    if ($i % 2 === 0 && function_exists('imagecreatetruecolor')) {
        // Generated gradient image; the other half stays image-less to exercise the typographic cover.
        $img = imagecreatetruecolor(1600, 1000);
        for ($y = 0; $y < 1000; $y++) {
            $c = imagecolorallocate($img, 40 + ($i * 7) % 60, 60 + (int) ($y / 12), 90 + ($i * 11) % 80);
            imageline($img, 0, $y, 1600, $y, $c);
        }
        $tmp = wp_tempnam($slug . '.png');
        imagepng($img, $tmp);
        imagedestroy($img);
        $att = media_handle_sideload(['name' => $slug . '.png', 'tmp_name' => $tmp], $id, null, ['post_title' => 'Codespark QA image ' . $i]);
        if (!is_wp_error($att)) {
            set_post_thumbnail($id, $att);
        }
    }
}
WP_CLI::success('fixtures ready');
