<?php

use MoneyfrogCore\Content;
use PHPUnit\Framework\TestCase;

final class ContentTest extends TestCase
{
    public function test_reading_time_rounds_up_and_min_one(): void
    {
        $this->assertSame(3, Content::readingTime('<p>' . str_repeat('word ', 600) . '</p>'));
        $this->assertSame(1, Content::readingTime('<p>' . str_repeat('word ', 10) . '</p>'));
        $this->assertSame(1, Content::readingTime(''));
    }

    public function test_heading_ids_are_stable_and_unique(): void
    {
        $out = Content::addHeadingIds('<h2>Positives</h2><p>a</p><h2>Positives</h2>');
        $this->assertStringContainsString('<h2 id="positives">Positives</h2>', $out);
        $this->assertStringContainsString('<h2 id="positives-2">Positives</h2>', $out);
        $this->assertSame($out, Content::addHeadingIds($out));
    }

    public function test_existing_ids_are_kept(): void
    {
        $out = Content::addHeadingIds('<h2 id="custom">Risks</h2>');
        $this->assertStringContainsString('id="custom"', $out);
        $this->assertStringNotContainsString('id="risks"', $out);
    }

    public function test_heading_ids_leave_unicode_content_unchanged(): void
    {
        $html = '<h2>Price ₹500 और बचत</h2><p>नमस्ते ₹ Kitna sahi hai</p>';
        $out = Content::addHeadingIds($html);
        $this->assertStringContainsString('₹500 और बचत', $out);
        $this->assertStringContainsString('<p>नमस्ते ₹ Kitna sahi hai</p>', $out);
        $this->assertStringContainsString('id="price-500"', $out);
    }

    public function test_toc_empty_with_fewer_than_two_h2(): void
    {
        $this->assertSame([], Content::toc('<h2>Only one</h2><p>x</p>'));
        $this->assertSame([], Content::toc('<p>none</p>'));
    }

    public function test_toc_lists_h2_in_order(): void
    {
        $toc = Content::toc('<h2>First part</h2><h3>sub</h3><h2>Second part</h2>');
        $this->assertSame([
            ['id' => 'first-part', 'text' => 'First part'],
            ['id' => 'second-part', 'text' => 'Second part'],
        ], $toc);
    }

    public function test_cover_phrase(): void
    {
        $this->assertSame('SIP vs. Lump sum', Content::coverPhrase('SIP vs. Lump sum – Which is better?', 'Mutual Funds'));
        $this->assertSame('Market Outlook', Content::coverPhrase('Market Outlook – December 2025', 'Market Outlook'));
        $title = 'Retire early with smart habits and steady investing every month';
        $this->assertSame('Retire early with', Content::coverPhrase($title, 'Retirement'));
        $this->assertSame('Retirement', Content::coverPhrase('Supercalifragilisticexpialidocious extraordinarily unbelievable', 'Retirement'));
        $this->assertLessThanOrEqual(22, mb_strlen(Content::coverPhrase($title, 'Retirement')));
    }
}
