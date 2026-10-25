<?php

use MoneyfrogCore\LoginLimiter;
use PHPUnit\Framework\TestCase;

final class LoginLimiterTest extends TestCase
{
    private int $now = 1000;
    /** @var array<string,array> */
    private array $store = [];

    private function limiter(): LoginLimiter
    {
        return new LoginLimiter(
            fn (string $k) => $this->store[$k] ?? false,
            function (string $k, array $v, int $ttl): void {
                $this->store[$k] = $v;
            },
            function (string $k): void {
                unset($this->store[$k]);
            },
            fn (): int => $this->now,
            5,
            900
        );
    }

    private function failTimes(LoginLimiter $l, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $l->recordFailure('ip');
        }
    }

    public function test_five_failures_allowed_sixth_blocked(): void
    {
        $l = $this->limiter();
        $this->failTimes($l, 4);
        $this->assertFalse($l->isBlocked('ip'));
        $this->failTimes($l, 1);
        $this->assertTrue($l->isBlocked('ip'));
    }

    public function test_still_blocked_inside_window(): void
    {
        $l = $this->limiter();
        $this->failTimes($l, 5);
        $this->now += 899;
        $this->assertTrue($l->isBlocked('ip'));
    }

    public function test_allowed_again_after_window_expires(): void
    {
        $l = $this->limiter();
        $this->failTimes($l, 5);
        $this->now += 900;
        $this->assertFalse($l->isBlocked('ip'));
    }

    public function test_window_not_extended_while_blocked(): void
    {
        $l = $this->limiter();
        $this->failTimes($l, 5);
        $this->now += 600;
        $this->failTimes($l, 3); // attempts while blocked
        $this->now += 300;  // 900s after the first block began
        $this->assertFalse($l->isBlocked('ip'));
    }

    public function test_success_clears_counter(): void
    {
        $l = $this->limiter();
        $this->failTimes($l, 4);
        $l->clear('ip');
        $this->failTimes($l, 4);
        $this->assertFalse($l->isBlocked('ip'));
    }

    public function test_keys_are_independent(): void
    {
        $l = $this->limiter();
        $this->failTimes($l, 5);
        $this->assertFalse($l->isBlocked('other'));
    }
}
