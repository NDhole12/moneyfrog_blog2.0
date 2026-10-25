<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/wp-content/themes/moneyfrog/inc/tag-label.php';

final class TagLabelTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function cases(): array
    {
        return [
            'slug-like name is humanised'      => ['cluttered-investments', 'cluttered-investments', 'Cluttered investments'],
            'acronym words are upper-cased'    => ['elss-tax-saving-funds', 'elss-tax-saving-funds', 'ELSS tax saving funds'],
            'acronym in the middle'            => ['best-sip-plans', 'best-sip-plans', 'Best SIP plans'],
            'every known acronym'              => ['sip-elss-nfo-nps-ppf-fd-etf-pms-gst-rbi-ipo', 'sip-elss-nfo-nps-ppf-fd-etf-pms-gst-rbi-ipo', 'SIP ELSS NFO NPS PPF FD ETF PMS GST RBI IPO'],
            'real name is kept'                => ['SIP', 'sip', 'SIP'],
            'real multi-word name is kept'     => ['Tax saving', 'tax-saving', 'Tax saving'],
            'lower-case single word capitalised' => ['stocks', 'stocks', 'Stocks'],
            'hyphenated real name is kept'     => ['Long-term investing', 'long-term-investing', 'Long-term investing'],
            'empty name falls back to slug'    => ['', 'fallback', 'fallback'],
        ];
    }

    #[DataProvider('cases')]
    public function test_label(string $name, string $slug, string $expected): void
    {
        $this->assertSame($expected, mf_tag_label($name, $slug));
    }
}
