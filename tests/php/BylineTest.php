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

    public function test_photo_id_accepts_only_positive_whole_numbers(): void
    {
        $this->assertSame(42, Byline::sanitizePhotoId('42'));
        $this->assertSame(42, Byline::sanitizePhotoId(42));
        $this->assertSame(0, Byline::sanitizePhotoId(''));
        $this->assertSame(0, Byline::sanitizePhotoId('0'));
        $this->assertSame(0, Byline::sanitizePhotoId('-3'));
        $this->assertSame(0, Byline::sanitizePhotoId('4.5'));
        $this->assertSame(0, Byline::sanitizePhotoId('12abc'));
        $this->assertSame(0, Byline::sanitizePhotoId(null));
        $this->assertSame(0, Byline::sanitizePhotoId(['1']));
    }

    public function test_a_published_post_without_byline_gets_the_default(): void
    {
        $this->assertTrue(Byline::needsDefault('post', 'publish', false));
        $this->assertTrue(Byline::needsDefault('post', 'future', false));
        $this->assertFalse(Byline::needsDefault('post', 'publish', true));
        $this->assertFalse(Byline::needsDefault('post', 'draft', false));
        $this->assertFalse(Byline::needsDefault('page', 'publish', false));
        $this->assertSame('mushtaq-kazi', Byline::DEFAULT_SLUG);
    }

    public function test_initials_use_first_and_last_word_and_are_multibyte_safe(): void
    {
        $this->assertSame('AS', Byline::initials('Anil Kumar Sharma'));
        $this->assertSame('ÉD', Byline::initials('élodie Dupont'));
        $this->assertSame('ÉL', Byline::initials('Élodie'));
        $this->assertSame('अश', Byline::initials('अ श'));
    }
}
