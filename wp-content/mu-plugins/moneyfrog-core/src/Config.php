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
}
