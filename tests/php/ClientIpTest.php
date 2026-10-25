<?php

use MoneyfrogCore\ClientIp;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase
{
    public function test_spoofed_cf_header_from_non_cloudflare_address_is_ignored(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'];
        $this->assertSame('203.0.113.9', ClientIp::resolve($server));
    }

    public function test_cf_header_honoured_from_ipv4_cloudflare_address(): void
    {
        $server = ['REMOTE_ADDR' => '172.64.10.20', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'];
        $this->assertSame('198.51.100.7', ClientIp::resolve($server));
    }

    public function test_cf_header_honoured_from_ipv6_cloudflare_address(): void
    {
        $server = ['REMOTE_ADDR' => '2606:4700:10::1', 'HTTP_CF_CONNECTING_IP' => '2001:db8::5'];
        $this->assertSame('2001:db8::5', ClientIp::resolve($server));
    }

    public function test_invalid_cf_header_falls_back_to_remote_addr(): void
    {
        $server = ['REMOTE_ADDR' => '172.64.10.20', 'HTTP_CF_CONNECTING_IP' => 'not-an-ip'];
        $this->assertSame('172.64.10.20', ClientIp::resolve($server));
    }

    public function test_range_boundaries(): void
    {
        $this->assertTrue(ClientIp::isCloudflare('104.23.255.255'));  // 104.16.0.0/13 end
        $this->assertTrue(ClientIp::isCloudflare('104.24.0.0')); // 104.24.0.0/14 start
        $this->assertFalse(ClientIp::isCloudflare('104.28.0.0'));
        $this->assertFalse(ClientIp::isCloudflare('2606:4701::1'));
    }

    public function test_https_via_forwarded_proto_only_from_cloudflare(): void
    {
        $fromCf = ['REMOTE_ADDR' => '172.64.10.20', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        $spoofed = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        $this->assertTrue(ClientIp::isHttps($fromCf));
        $this->assertFalse(ClientIp::isHttps($spoofed));
    }

    public function test_https_via_cf_visitor_and_native_https(): void
    {
        $visitor = ['REMOTE_ADDR' => '172.64.10.20', 'HTTP_CF_VISITOR' => '{"scheme":"https"}'];
        $this->assertTrue(ClientIp::isHttps($visitor));
        $this->assertTrue(ClientIp::isHttps(['REMOTE_ADDR' => '203.0.113.9', 'HTTPS' => 'on']));
        $this->assertFalse(ClientIp::isHttps(['REMOTE_ADDR' => '203.0.113.9', 'HTTPS' => 'off']));
    }
}
