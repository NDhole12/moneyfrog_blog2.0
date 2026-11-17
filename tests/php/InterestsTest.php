<?php

namespace MoneyfrogCore\Tests;

use MoneyfrogCore\Config;
use MoneyfrogCore\Interests;
use PHPUnit\Framework\TestCase;

final class InterestsTest extends TestCase
{
    public function test_the_eight_approved_tiles_in_order(): void
    {
        $this->assertSame(
            ['Mutual Funds', 'GIFT City', 'Fixed Income', 'AIF', 'PMS', 'Equity', 'Insurance', 'Tax Planning'],
            array_column(Config::INTERESTS, 'name')
        );
    }

    public function test_every_tile_has_a_search_fallback_and_an_icon_file(): void
    {
        $dir = dirname(__DIR__, 2) . '/wp-content/themes/moneyfrog/assets/img/home';
        foreach (Config::INTERESTS as $slug => $interest) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
            $this->assertNotSame('', trim($interest['search']), $slug);
            $this->assertFileExists($dir . '/interest-' . $slug . '.webp');
        }
        $this->assertFileExists($dir . '/hero.webp');
        $this->assertFileExists($dir . '/app.webp');
    }

    public function test_only_categories_that_do_not_exist_yet_are_created(): void
    {
        $this->assertSame(
            ['gift-city', 'fixed-income', 'aif', 'pms', 'equity'],
            Interests::missing(['market-outlook', 'mutual-funds', 'tax', 'insurance', 'retirement', 'financial-planning', 'guides'])
        );
        $this->assertSame([], Interests::missing(array_keys(Config::INTERESTS)));
    }

    public function test_fingerprint_follows_the_configured_slugs(): void
    {
        $this->assertSame(md5(implode(',', array_keys(Config::INTERESTS))), Interests::fingerprint());
    }
}
