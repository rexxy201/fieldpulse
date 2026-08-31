<?php

final class TwoFactorTest extends TestCase
{
    public function testIssueThenVerifySucceedsWithTheRealCode(): void
    {
        $u = $this->makeUser();
        issueTwoFactorCode($u['id'], $u['email'], $u['name']);

        $hash = dbFetch("SELECT twofa_code_hash FROM users WHERE id = ?", [$u['id']])['twofa_code_hash'];
        $this->assertNotEmpty($hash);

        // We don't have the plaintext code (by design, only its hash is
        // stored) — set a known one directly to test verifyTwoFactorCode()'s
        // own matching/expiry/single-use logic in isolation.
        dbRun("UPDATE users SET twofa_code_hash = ?, twofa_code_expires_at = ? WHERE id = ?", [
            hash('sha256', '123456'),
            date('Y-m-d H:i:s', time() + 600),
            $u['id'],
        ]);
        $this->assertTrue(verifyTwoFactorCode($u['id'], '123456'));
    }

    public function testVerifyRejectsTheWrongCode(): void
    {
        $u = $this->makeUser();
        dbRun("UPDATE users SET twofa_code_hash = ?, twofa_code_expires_at = ? WHERE id = ?", [
            hash('sha256', '123456'),
            date('Y-m-d H:i:s', time() + 600),
            $u['id'],
        ]);
        $this->assertFalse(verifyTwoFactorCode($u['id'], '999999'));
    }

    public function testVerifyRejectsAnExpiredCode(): void
    {
        $u = $this->makeUser();
        dbRun("UPDATE users SET twofa_code_hash = ?, twofa_code_expires_at = ? WHERE id = ?", [
            hash('sha256', '123456'),
            date('Y-m-d H:i:s', time() - 60),
            $u['id'],
        ]);
        $this->assertFalse(verifyTwoFactorCode($u['id'], '123456'));
    }

    public function testACodeIsSingleUse(): void
    {
        $u = $this->makeUser();
        dbRun("UPDATE users SET twofa_code_hash = ?, twofa_code_expires_at = ? WHERE id = ?", [
            hash('sha256', '123456'),
            date('Y-m-d H:i:s', time() + 600),
            $u['id'],
        ]);
        $this->assertTrue(verifyTwoFactorCode($u['id'], '123456'), 'First use should succeed.');
        $this->assertFalse(verifyTwoFactorCode($u['id'], '123456'), 'Second use of the same code must fail.');
    }
}
