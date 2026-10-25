<?php

use MoneyfrogCore\Redirects;
use PHPUnit\Framework\TestCase;

final class RedirectsTest extends TestCase
{
    public function test_normalise_path_strips_bom_raw_and_encoded(): void
    {
        $this->assertSame('/market-outlook-december-2025/', Redirects::normalisePath('/market-outlook-december-2025%ef%bb%bf/'));
        $this->assertSame('/market-outlook-december-2025/', Redirects::normalisePath("/market-outlook-december-2025\u{FEFF}"));
    }

    public function test_normalise_path_basics(): void
    {
        $this->assertSame('/a/b/', Redirects::normalisePath('//A//B?x=1'));
        $this->assertSame('/', Redirects::normalisePath(''));
        $this->assertSame('/', Redirects::normalisePath('/?utm_source=x'));
    }

    public function test_variants_resolve_to_same_target(): void
    {
        $map = ['/moneyfrog-school/gilt-fund/' => '/gilt-fund/'];
        $this->assertSame('/gilt-fund/', Redirects::lookup('/Moneyfrog-School/Gilt-Fund', $map));
        $this->assertSame('/gilt-fund/?utm_source=x', Redirects::lookup('/moneyfrog-school/gilt-fund/?utm_source=x', $map));
    }

    public function test_absolute_url_target_with_query(): void
    {
        $map = ['/old/' => 'https://moneyfrog.in/new/?a=1'];
        $this->assertSame('https://moneyfrog.in/new/?a=1&b=2', Redirects::lookup('/old/?b=2', $map));
    }

    public function test_lookup_returns_null_for_unknown(): void
    {
        $this->assertNull(Redirects::lookup('/nope/', ['/yes/' => '/x/']));
    }
}
