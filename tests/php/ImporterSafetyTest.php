<?php

use MoneyfrogCore\Cli\Importer;
use PHPUnit\Framework\TestCase;

final class ImporterSafetyTest extends TestCase
{
    public function test_clean_html_removes_active_content_and_keeps_formatting(): void
    {
        $in = '<p onclick="x()">Hi <strong>there</strong> <a href="javascript:alert(1)" onmouseover=\'y()\'>bad</a> '
            . '<a href="https://moneyfrog.in/">ok</a></p><script>alert(1)</script><h2>Head</h2>';
        $out = Importer::cleanHtml($in);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('onmouseover', $out);
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringContainsString('<strong>there</strong>', $out);
        $this->assertStringContainsString('<h2>Head</h2>', $out);
        $this->assertStringContainsString('href="https://moneyfrog.in/"', $out);
    }

    public function test_clean_html_keeps_only_youtube_iframes_with_allowed_attributes(): void
    {
        $yt = '<iframe src="https://www.youtube.com/embed/abc" width="560" height="315" title="t" allowfullscreen onload="x()" style="x"></iframe>';
        $out = Importer::cleanHtml(
            $yt . '<iframe src="https://evil.example/x"></iframe><iframe src="//www.youtube.com.evil.example/e"></iframe>'
            . '<iframe src="javascript:alert(1)"></iframe>'
        );
        $this->assertSame(1, substr_count($out, '<iframe'));
        $this->assertStringContainsString('https://www.youtube.com/embed/abc', $out);
        $this->assertStringContainsString('allowfullscreen', $out);
        $this->assertStringNotContainsString('onload', $out);
        $this->assertStringNotContainsString('style=', $out);
        $this->assertStringContainsString(
            'youtube-nocookie.com',
            Importer::cleanHtml('<iframe src="https://www.youtube-nocookie.com/embed/z"></iframe>')
        );
    }

    public function test_normalize_date_is_strict(): void
    {
        $this->assertSame('2025-04-11 14:59:37', Importer::normalizeDate('2025-04-11T14:59:37'));
        $this->assertSame('2025-04-11 14:59:37', Importer::normalizeDate('2025-04-11 14:59:37'));
        $this->assertNull(Importer::normalizeDate(''));
        $this->assertNull(Importer::normalizeDate('not-a-date'));
        $this->assertNull(Importer::normalizeDate('2025-13-40T00:00:00'));
        $this->assertNull(Importer::normalizeDate('2025-02-30T00:00:00'));
    }

    public function test_timezone_guard_accepts_only_india_offset(): void
    {
        $this->assertTrue(Importer::isIndiaOffset('Asia/Kolkata', 0));
        $this->assertTrue(Importer::isIndiaOffset('', '5.5'));
        $this->assertFalse(Importer::isIndiaOffset('', 0));
        $this->assertFalse(Importer::isIndiaOffset('UTC', 5.5));
        $this->assertFalse(Importer::isIndiaOffset('Europe/London', 0));
    }

    public function test_allowed_extensions(): void
    {
        foreach (['a.JPG', 'a.jpeg', 'a.png', 'a.gif', 'a.webp', 'a.svg', 'a.pdf', 'a.ico'] as $ok) {
            $this->assertTrue(Importer::isAllowedMediaExtension($ok), $ok);
        }
        foreach (['a.php', 'a.phtml', 'a.html', 'a.js', 'a.jpg.php', 'a', 'a.svgz'] as $bad) {
            $this->assertFalse(Importer::isAllowedMediaExtension($bad), $bad);
        }
    }

    public function test_svg_safety(): void
    {
        $this->assertTrue(Importer::isSafeSvg('<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>'));
        $this->assertFalse(Importer::isSafeSvg('<svg><script>alert(1)</script></svg>'));
        $this->assertFalse(Importer::isSafeSvg('<svg onload="x()"></svg>'));
        $this->assertFalse(Importer::isSafeSvg('<svg><a xlink:href="javascript:x()"/></svg>'));
        $this->assertFalse(Importer::isSafeSvg('<html></html>'));
    }
}
