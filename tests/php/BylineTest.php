<?php

use MoneyfrogCore\Byline;
use PHPUnit\Framework\TestCase;

final class BylineTest extends TestCase
{
    public function test_initials(): void
    {
        $this->assertSame('MK', Byline::initials('Mushtaq Kazi'));
        $this->assertSame('SA', Byline::initials('Sanket'));
        $this->assertSame('MK', Byline::initials("  Mushtaq   Kazi \n"));
        $this->assertSame('', Byline::initials(''));
        $this->assertSame('', Byline::initials("   "));
    }

    public function test_initials_use_first_and_last_word_and_are_multibyte_safe(): void
    {
        $this->assertSame('AS', Byline::initials('Anil Kumar Sharma'));
        $this->assertSame('ÉD', Byline::initials('élodie Dupont'));
        $this->assertSame('ÉL', Byline::initials('Élodie'));
        $this->assertSame('अश', Byline::initials('अ श'));
    }
}
