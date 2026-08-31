<?php

final class AuthTest extends TestCase
{
    public function testPasswordHashAndVerifyRoundTrip(): void
    {
        $hash = hashPassword('correct horse battery staple');
        $this->assertTrue(verifyPassword('correct horse battery staple', $hash));
        $this->assertFalse(verifyPassword('wrong password', $hash));
    }

    public function testVerifyPasswordFailsClosedOnACorruptedOrLegacyHash(): void
    {
        // No plaintext fallback, ever — anything not a bcrypt hash must fail,
        // not silently accept.
        $this->assertFalse(verifyPassword('anything', 'not-a-real-hash'));
        $this->assertFalse(verifyPassword('anything', ''));
    }

    public function testAttemptLoginSucceedsWithCorrectCredentials(): void
    {
        $u = $this->makeUser(['username' => 'login_ok_' . substr(newUuid(), 0, 8)]);
        $result = attemptLogin($u['username'], 'TestPassw0rd!');
        $this->assertTrue($result['ok']);
        $this->assertSame($u['id'], $result['user']['id']);
    }

    public function testAttemptLoginFailsWithWrongPassword(): void
    {
        $u = $this->makeUser(['username' => 'login_bad_' . substr(newUuid(), 0, 8)]);
        $result = attemptLogin($u['username'], 'totally wrong');
        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['error']);
    }

    public function testAttemptLoginLocksOutAfterTooManyFailures(): void
    {
        $u = $this->makeUser(['username' => 'login_lock_' . substr(newUuid(), 0, 8)]);
        for ($i = 0; $i < LOGIN_MAX_ATTEMPTS; $i++) {
            attemptLogin($u['username'], 'wrong');
        }
        // Even the CORRECT password must now be refused — that's the point of a lockout.
        $result = attemptLogin($u['username'], 'TestPassw0rd!');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Too many failed attempts', $result['error']);
    }
}
