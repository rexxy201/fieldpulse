<?php

final class PasswordResetTest extends TestCase
{
    public function testIssuePasswordResetSilentlyNoOpsForAnUnknownEmail(): void
    {
        // Must not throw, must not reveal whether the email has an account —
        // that's the whole point (no user enumeration).
        issuePasswordReset('definitely-not-a-real-account@example.test');
        $this->addToAssertionCount(1); // Reaching here without an exception is the assertion.
    }

    public function testFindUserByResetTokenReturnsNullForAnInvalidToken(): void
    {
        $this->assertNull(findUserByResetToken('not-a-real-token'));
        $this->assertNull(findUserByResetToken(''));
    }

    public function testFullResetFlowIssuesAValidTokenThenConsumesIt(): void
    {
        $u = $this->makeUser(['email' => 'reset_' . substr(newUuid(), 0, 8) . '@example.test']);
        issuePasswordReset($u['email']);

        $row = dbFetch("SELECT reset_token_hash, reset_token_expires_at FROM users WHERE id = ?", [$u['id']]);
        $this->assertNotEmpty($row['reset_token_hash'], 'issuePasswordReset should have stored a token hash.');

        // We only have the hash (by design) — reverse-engineer isn't possible,
        // so exercise completePasswordReset() directly and confirm the token
        // fields are cleared and the new password actually verifies.
        completePasswordReset($u['id'], 'BrandNewPassw0rd!');
        $after = dbFetch("SELECT password, reset_token_hash, reset_token_expires_at, failed_login_attempts, locked_until FROM users WHERE id = ?", [$u['id']]);
        $this->assertNull($after['reset_token_hash']);
        $this->assertNull($after['reset_token_expires_at']);
        $this->assertTrue(verifyPassword('BrandNewPassw0rd!', $after['password']));
        $this->assertSame(0, (int)$after['failed_login_attempts']);
        $this->assertNull($after['locked_until']);
    }

    public function testFindUserByResetTokenRejectsAnExpiredToken(): void
    {
        $u = $this->makeUser();
        $token = bin2hex(random_bytes(16));
        dbRun("UPDATE users SET reset_token_hash = ?, reset_token_expires_at = ? WHERE id = ?", [
            hash('sha256', $token),
            date('Y-m-d H:i:s', time() - 60), // already expired
            $u['id'],
        ]);
        $this->assertNull(findUserByResetToken($token));
    }
}
