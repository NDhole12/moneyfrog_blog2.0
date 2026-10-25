<?php
/** Google Tag Manager: the only third-party script on the blog. */

use MoneyfrogCore\Config;

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_head', static function (): void {
    $id = esc_js(Config::GTM_ID);
    echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});"
        . "var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;"
        . "j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);"
        . "})(window,document,'script','dataLayer','{$id}');</script>\n";
}, 1);

add_action('wp_body_open', static function (): void {
    printf(
        '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=%s" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n",
        esc_attr(Config::GTM_ID)
    );
}, 1);
