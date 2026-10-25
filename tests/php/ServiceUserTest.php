<?php

use MoneyfrogCore\ServiceUser;
use PHPUnit\Framework\TestCase;

// Unit tests run without WordPress; a minimal stand-in is enough for instanceof checks.
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(public string $code = '', public string $message = '')
        {
        }
    }
}

final class ServiceUserTest extends TestCase
{
    public function test_service_login_is_recognised_in_any_case_and_spacing(): void
    {
        $this->assertTrue(ServiceUser::isServiceLogin('mf-import'));
        $this->assertTrue(ServiceUser::isServiceLogin(' MF-Import '));
        $this->assertFalse(ServiceUser::isServiceLogin('mf-importer'));
        $this->assertFalse(ServiceUser::isServiceLogin(''));
    }

    public function test_authenticate_rejects_service_user_even_with_valid_credentials(): void
    {
        $user = new class {
            public string $user_login = 'mf-import';
        };
        $this->assertInstanceOf(\WP_Error::class, ServiceUser::rejectLogin($user, 'mf-import'));
        $this->assertInstanceOf(\WP_Error::class, ServiceUser::rejectLogin(null, 'MF-IMPORT'));
        $other = new class {
            public string $user_login = 'someone';
        };
        $this->assertSame($other, ServiceUser::rejectLogin($other, 'someone'));
    }
}
