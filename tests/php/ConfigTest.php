<?php

namespace MoneyfrogCore\Tests;

use MoneyfrogCore\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function test_constants(): void
    {
        $this->assertSame('GTM-M9PS8WZT', Config::GTM_ID);
        $this->assertFalse(Config::NEWSLETTER_ENABLED);
        $this->assertSame('Market Outlook', Config::TOPICS['market-outlook']);
        $this->assertCount(7, Config::TOPICS);
    }

    public function test_every_topic_has_a_blurb(): void
    {
        $this->assertSame(array_keys(Config::TOPICS), array_keys(Config::TOPIC_BLURBS));
        $this->assertSame('SIPs, debt funds, choosing schemes', Config::TOPIC_BLURBS['mutual-funds']);
    }
}
