<?php

use MoneyfrogCore\Release;
use PHPUnit\Framework\TestCase;

final class ReleaseTest extends TestCase
{
    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    public function test_accepts_40_hex_with_whitespace(): void
    {
        $this->assertSame(self::SHA, Release::parse(self::SHA . "\n"));
        $this->assertSame(substr(self::SHA, 0, 12), Release::short(self::SHA));
    }

    public function test_rejects_bad_values(): void
    {
        foreach (['', 'abc', strtoupper(self::SHA), self::SHA . 'x', substr(self::SHA, 0, 39), "x\r\nSet-Cookie: a=b"] as $bad) {
            $this->assertNull(Release::parse($bad), $bad);
        }
    }

    public function test_missing_file_is_null(): void
    {
        $this->assertNull(Release::read('/nonexistent/path/.mf-release'));
    }
}
