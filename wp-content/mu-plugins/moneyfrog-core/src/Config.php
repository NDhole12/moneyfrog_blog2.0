<?php

namespace MoneyfrogCore;

final class Config
{
    public const SITE_URL = 'https://moneyfrog.in';
    public const BOOK_CALL_URL = 'https://moneyfrog.in/contact-us';
    public const GTM_ID = 'GTM-M9PS8WZT';
    public const NEWSLETTER_ENABLED = false;

    /** Topic slug => display name. */
    public const TOPICS = [
        'market-outlook'     => 'Market Outlook',
        'mutual-funds'       => 'Mutual Funds',
        'tax'                => 'Tax',
        'insurance'          => 'Insurance',
        'retirement'         => 'Retirement',
        'financial-planning' => 'Financial Planning',
        'guides'             => 'Guides',
    ];

    /** Topic slug => one-line description (home tiles; category intro when the term has none). */
    public const TOPIC_BLURBS = [
        'market-outlook'     => 'Our monthly view on Nifty and macro',
        'mutual-funds'       => 'SIPs, debt funds, choosing schemes',
        'tax'                => 'Saving tax the right way',
        'insurance'          => 'Term, health and critical illness',
        'retirement'         => 'NPS, income, the 4% rule',
        'financial-planning' => 'Goals, budgets, life stages',
        'guides'             => 'The basics, explained simply',
    ];

    /**
     * Home page "Explore articles by financial interest" tiles, in display order.
     * Category slug => tile name and the search phrase the tile falls back to while the
     * category has no articles filed under it yet.
     */
    public const INTERESTS = [
        'mutual-funds' => ['name' => 'Mutual Funds', 'search' => 'mutual fund'],
        'gift-city'    => ['name' => 'GIFT City', 'search' => 'GIFT City'],
        'fixed-income' => ['name' => 'Fixed Income', 'search' => 'fixed income'],
        'aif'          => ['name' => 'AIF', 'search' => 'alternative investment'],
        'pms'          => ['name' => 'PMS', 'search' => 'portfolio management'],
        'equity'       => ['name' => 'Equity', 'search' => 'equity'],
        'insurance'    => ['name' => 'Insurance', 'search' => 'insurance'],
        'tax'          => ['name' => 'Tax Planning', 'search' => 'tax'],
    ];

    public const BLOG_EMAIL = 'blog@moneyfrog.in';
}
