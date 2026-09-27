<?php

final class LoginThrottleTest extends TestCase
{
    private const IP_A = '203.0.113.10';
    private const IP_B = '198.51.100.20';

    public function testUsernameLockoutOnlyAppliesToTheIpThatFailed(): void
    {
        $u = $this->makeUser(['username' => 'thr_' . substr(newUuid(), 0, 8)]);
        for ($i = 0; $i < LOGIN_MAX_ATTEMPTS; $i++) attemptLogin($u['username'], 'wrong', self::IP_A);

        $fromA = attemptLogin($u['username'], 'TestPassw0rd!', self::IP_A);
        $this->assertFalse($fromA['ok']);
        $this->assertStringContainsString('Too many failed attempts', $fromA['error']);

        // The real user on another connection is unaffected: failures from
        // IP_A can't lock the account out everywhere.
        $this->assertTrue(attemptLogin($u['username'], 'TestPassw0rd!', self::IP_B)['ok']);
    }

    public function testIpIsBlockedAfterTooManyFailuresAcrossUsernames(): void
    {
        $victim = $this->makeUser(['username' => 'thr_v_' . substr(newUuid(), 0, 8)]);
        for ($i = 0; $i < LOGIN_IP_MAX_FAILURES; $i++) {
            attemptLogin('spray_' . $i . '_' . substr(newUuid(), 0, 6), 'Summer2026!', self::IP_A);
        }
        $blocked = attemptLogin($victim['username'], 'TestPassw0rd!', self::IP_A);
        $this->assertFalse($blocked['ok']);
        $this->assertStringContainsString('from your network', $blocked['error']);
        $this->assertTrue(attemptLogin($victim['username'], 'TestPassw0rd!', self::IP_B)['ok']);
    }

    public function testSuccessfulSignInsDoNotCountAndClearFailures(): void
    {
        $u = $this->makeUser(['username' => 'thr_s_' . substr(newUuid(), 0, 8)]);
        // A shared office: many successful sign-ins from one IP never block it.
        for ($i = 0; $i < LOGIN_IP_MAX_FAILURES + 5; $i++) {
            $this->assertTrue(attemptLogin($u['username'], 'TestPassw0rd!', self::IP_A)['ok']);
        }
        // A success clears earlier failures for that username+IP.
        for ($i = 0; $i < LOGIN_MAX_ATTEMPTS - 1; $i++) attemptLogin($u['username'], 'wrong', self::IP_A);
        $this->assertTrue(attemptLogin($u['username'], 'TestPassw0rd!', self::IP_A)['ok']);
        for ($i = 0; $i < LOGIN_MAX_ATTEMPTS - 1; $i++) attemptLogin($u['username'], 'wrong', self::IP_A);
        $this->assertTrue(attemptLogin($u['username'], 'TestPassw0rd!', self::IP_A)['ok']);
    }

    public function testUnknownUsernamesGetTheSameLockMessage(): void
    {
        $ghost = 'no_such_user_' . substr(newUuid(), 0, 8);
        for ($i = 0; $i < LOGIN_MAX_ATTEMPTS; $i++) attemptLogin($ghost, 'wrong', self::IP_A);
        $this->assertStringContainsString('Too many failed attempts', attemptLogin($ghost, 'wrong', self::IP_A)['error']);
    }

    public function testFailuresNoLongerSetThePerAccountLock(): void
    {
        $u = $this->makeUser(['username' => 'thr_l_' . substr(newUuid(), 0, 8)]);
        for ($i = 0; $i < LOGIN_MAX_ATTEMPTS + 2; $i++) attemptLogin($u['username'], 'wrong', self::IP_A);
        $row = dbFetch("SELECT locked_until FROM users WHERE id = ?", [$u['id']]);
        $this->assertNull($row['locked_until']);
    }
}
