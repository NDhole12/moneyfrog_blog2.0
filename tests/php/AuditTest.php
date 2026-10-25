<?php

use MoneyfrogCore\Audit;
use PHPUnit\Framework\TestCase;

final class AuditTest extends TestCase
{
    private function good(): array
    {
        return [
            'two_factor_missing'  => [],
            'file_edit_disabled'  => true,
            'file_mods_disabled'  => true,
            'admin_user_exists'   => false,
            'comments_open_count' => 0,
            'timezone'            => 'Asia/Kolkata',
        ];
    }

    public function test_all_good_has_no_failures(): void
    {
        $this->assertSame([], Audit::failures($this->good()));
    }

    public function test_each_requirement_is_enforced(): void
    {
        $cases = [
            'two_factor_missing'  => ['jane'],
            'file_edit_disabled'  => false,
            'file_mods_disabled'  => false,
            'admin_user_exists'   => true,
            'comments_open_count' => 3,
            'timezone'            => 'UTC',
        ];
        foreach ($cases as $key => $bad) {
            $facts = array_merge($this->good(), [$key => $key === 'two_factor_missing' ? [$bad] : $bad]);
            $this->assertSame([$key], Audit::failures($facts), $key);
        }
    }

    public function test_missing_fact_fails_closed(): void
    {
        $facts = $this->good();
        unset($facts['file_mods_disabled']);
        $this->assertSame(['file_mods_disabled'], Audit::failures($facts));
    }

    public function test_two_factor_missing_skips_service_user_and_non_editors(): void
    {
        $users = [
            ['login' => 'mf-import', 'can_edit' => true, 'providers' => []],
            ['login' => 'MF-Import', 'can_edit' => true, 'providers' => []],
            ['login' => 'subscriber1', 'can_edit' => false, 'providers' => []],
            ['login' => 'jane', 'can_edit' => true, 'providers' => ['Two_Factor_Totp']],
            ['login' => 'bob', 'can_edit' => true, 'providers' => []],
            ['login' => 'amy', 'can_edit' => true, 'providers' => 'not-an-array'],
        ];
        $this->assertSame(['bob', 'amy'], Audit::twoFactorMissing($users));
    }
}
