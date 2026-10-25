<?php
/** Site shell top: moneyfrog.in header + drawer, blog bar. */

if (!defined('ABSPATH')) {
    exit;
}

$logo = get_template_directory_uri() . '/assets/img/moneyfrog-logo.svg';
$site = static fn (string $path = ''): string => mf_site_url($path);
$nav  = [
    ['id' => 'offerings', 'label' => 'Offerings', 'links' => [
        ['Mutual Funds', $site('/mutual-funds')],
        ['Stocks/ Equity', $site('/stocks-equity')],
        ['PMS', $site('/pms')],
        ['AIF/ GIFT CITY', $site('/aif-gift-city')],
        ['Tax', $site('/tax')],
        ['Fixed Income/ FD/ NCD/ MLD', $site('/fixed-income')],
        ['Insurance', $site('/insurance')],
    ]],
    ['id' => 'who-we-serve', 'label' => 'Who We Serve', 'url' => $site('/#accounts')],
    ['id' => 'about', 'label' => 'About Us', 'url' => $site('/about-us')],
    ['id' => 'calculators', 'label' => 'Financial Calculators', 'url' => $site('/#calculator')],
    ['id' => 'resources', 'label' => 'Resources', 'links' => [
        ['Blogs', home_url('/')],
        ['Market Outlook', home_url('/category/market-outlook/')],
        ['FAQ', $site('/faq')],
    ]],
];
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php mf_icons_sprite(); ?>
<a class="skip-link" href="#main">Skip to content</a>

<header class="site-header" id="top">
    <div class="wrap">
        <a href="<?php echo esc_url($site('/')); ?>" class="brand"><img src="<?php echo esc_url($logo); ?>" alt="Moneyfrog" width="201" height="35"></a>

        <nav class="nav" aria-label="Primary">
            <?php foreach ($nav as $menu) : ?>
                <?php if (!empty($menu['links'])) : ?>
                    <div class="nav__item" data-dropdown>
                        <button type="button" class="nav__link" aria-expanded="false" aria-controls="menu-<?php echo esc_attr($menu['id']); ?>">
                            <?php echo esc_html($menu['label']); ?> <?php echo mf_icon('i-chevron'); ?>
                        </button>
                        <div class="nav__menu" id="menu-<?php echo esc_attr($menu['id']); ?>">
                            <?php foreach ($menu['links'] as [$label, $url]) : ?>
                                <a href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else : ?>
                    <div class="nav__item"><a href="<?php echo esc_url($menu['url']); ?>" class="nav__link"><?php echo esc_html($menu['label']); ?></a></div>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <div class="header-actions">
            <a href="<?php echo esc_url($site('/login')); ?>" class="btn btn--outline btn--sm">Log In</a>
            <a href="<?php echo esc_url($site('/signup')); ?>" class="btn btn--gold btn--sm">Sign Up <?php echo mf_icon('i-arrow'); ?></a>
            <button type="button" class="menu-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="drawer" data-drawer-open>
                <?php echo mf_icon('i-menu'); ?>
            </button>
        </div>
    </div>
</header>

<div class="drawer" id="drawer" aria-hidden="true">
    <div class="drawer__scrim" data-drawer-close></div>
    <div class="drawer__panel" role="dialog" aria-modal="true" aria-label="Menu">
        <div class="drawer__head">
            <a href="<?php echo esc_url($site('/')); ?>" class="brand"><img src="<?php echo esc_url($logo); ?>" alt="Moneyfrog" width="201" height="35"></a>
            <button type="button" class="drawer__close" aria-label="Close menu" data-drawer-close>
                <?php echo mf_icon('i-close'); ?>
            </button>
        </div>
        <div class="drawer__actions">
            <a href="<?php echo esc_url($site('/signup')); ?>" class="btn btn--gold">Sign Up</a>
            <a href="<?php echo esc_url($site('/login')); ?>" class="btn btn--ghost">Log In</a>
        </div>
        <?php foreach ($nav as $menu) : ?>
            <div class="drawer__group">
                <?php if (!empty($menu['links'])) : ?>
                    <p class="drawer__title"><?php echo esc_html($menu['label']); ?></p>
                    <?php foreach ($menu['links'] as [$label, $url]) : ?>
                        <a href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                <?php else : ?>
                    <a href="<?php echo esc_url($menu['url']); ?>"><?php echo esc_html($menu['label']); ?></a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php get_template_part('template-parts/blog-bar', null, ['current' => mf_current_topic()]); ?>
