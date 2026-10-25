<?php

use MoneyfrogCore\Hardening;
use PHPUnit\Framework\TestCase;

final class LoginErrorTest extends TestCase
{
    public function test_unknown_user_and_wrong_password_look_identical(): void
    {
        $unknown = Hardening::genericLoginError('<strong>Error:</strong> The username <strong>nobody</strong> is not registered on this site.');
        $wrong   = Hardening::genericLoginError('<strong>Error:</strong> The password you entered for the username <strong>admin</strong> is incorrect.');
        $this->assertSame($unknown, $wrong);
        $this->assertSame("Those details didn't work. Please try again.", $unknown);
    }

    public function test_rate_limit_notice_is_kept(): void
    {
        $msg = 'Too many failed login attempts. Please try again in a few minutes.';
        $this->assertSame($msg, Hardening::genericLoginError($msg));
    }

    public function test_empty_stays_empty(): void
    {
        $this->assertSame('', Hardening::genericLoginError(''));
    }
}
