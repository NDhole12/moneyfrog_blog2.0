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

    public function test_bom_variant_of_a_live_path_has_a_canonical_target(): void
    {
        $this->assertSame('/market-outlook-april-2024/', Redirects::bomVariantTarget('/market-outlook-april-2024%ef%bb%bf/'));
        $this->assertSame('/market-outlook-april-2024/?utm_source=x', Redirects::bomVariantTarget("/Market-Outlook-April-2024\u{FEFF}?utm_source=x"));
        $this->assertNull(Redirects::bomVariantTarget('/market-outlook-april-2024/'));
        $this->assertNull(Redirects::bomVariantTarget('/Market-Outlook-April-2024'));
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

    /** @return array<string,array{0:string,1:?string}> */
    public static function decideCases(): array
    {
        return [
            'same-host path' => ['/gilt-fund/?a=1', '/gilt-fund/?a=1'],
            'equal path is a loop' => ['/here/', null],
            'equal path other case/slash' => ['/Here', null],
            'allowed apex host' => ['https://moneyfrog.in/x/', 'https://moneyfrog.in/x/'],
            'allowed blog host' => ['https://blog.moneyfrog.in/x/', 'https://blog.moneyfrog.in/x/'],
            'disallowed host' => ['https://example.com/x/', null],
            'protocol-relative' => ['//evil.com/x', null],
            'suffix trick' => ['https://moneyfrog.in.evil.com/x', null],
            'userinfo trick' => ['https://moneyfrog.in@evil.com/', null],
            'userinfo reversed' => ['https://evil.com@moneyfrog.in/', null],
            'backslash trick' => ['/\evil.com', null],
            'javascript scheme' => ['javascript:alert(1)', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('decideCases')]
    public function test_decide(string $target, ?string $expected): void
    {
        $this->assertSame($expected, Redirects::decide($target, 'blog.moneyfrog.in', '/here/'));
    }

    public function test_decide_own_host_absolute_url(): void
    {
        $this->assertSame('https://blog.moneyfrog.in/x/', Redirects::decide('https://blog.moneyfrog.in/x/', 'blog.moneyfrog.in', '/here/'));
        $this->assertNull(Redirects::decide('https://blog.moneyfrog.in/here/', 'blog.moneyfrog.in', '/here/'));
        $this->assertSame('http://localhost:8080/x/', Redirects::decide('http://localhost:8080/x/', 'localhost', '/here/'));
    }

    public function test_only_get_and_head_are_redirectable(): void
    {
        $this->assertTrue(Redirects::isRedirectableMethod('GET'));
        $this->assertTrue(Redirects::isRedirectableMethod('head'));
        $this->assertFalse(Redirects::isRedirectableMethod('POST'));
        $this->assertFalse(Redirects::isRedirectableMethod('PUT'));
    }
}
