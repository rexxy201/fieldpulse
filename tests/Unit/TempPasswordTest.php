<?php

final class TempPasswordTest extends TestCase
{
    public function testGenerateTempPasswordIsRandomAndAvoidsAmbiguousCharacters(): void
    {
        $a = generateTempPassword();
        $b = generateTempPassword();
        $this->assertSame(12, strlen($a));
        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Za-km-z2-9]+$/', $a);
    }

    public function testChangeOwnPasswordRejectsWrongCurrentShortOrMismatchedOrUnchanged(): void
    {
        $u = $this->makeUser();
        $this->assertNotNull(changeOwnPassword($u['id'], 'wrong', 'NewPassw0rd!', 'NewPassw0rd!'));
        $this->assertNotNull(changeOwnPassword($u['id'], 'TestPassw0rd!', 'short', 'short'));
        $this->assertNotNull(changeOwnPassword($u['id'], 'TestPassw0rd!', 'NewPassw0rd!', 'Different1!'));
        $this->assertNotNull(changeOwnPassword($u['id'], 'TestPassw0rd!', 'TestPassw0rd!', 'TestPassw0rd!'));
        $row = dbFetch("SELECT password FROM users WHERE id = ?", [$u['id']]);
        $this->assertTrue(verifyPassword('TestPassw0rd!', $row['password']), 'A rejected change must not alter the password.');
    }

    public function testChangeOwnPasswordSetsNewPasswordAndClearsMustChangeFlag(): void
    {
        $u = $this->makeUser();
        dbRun("UPDATE users SET must_change_password = 1 WHERE id = ?", [$u['id']]);

        $this->assertNull(changeOwnPassword($u['id'], 'TestPassw0rd!', 'BrandNewPassw0rd!', 'BrandNewPassw0rd!'));

        $row = dbFetch("SELECT password, must_change_password FROM users WHERE id = ?", [$u['id']]);
        $this->assertTrue(verifyPassword('BrandNewPassw0rd!', $row['password']));
        $this->assertSame(0, (int)$row['must_change_password']);
    }

    public function testCompletePasswordResetClearsMustChangeFlag(): void
    {
        $u = $this->makeUser();
        dbRun("UPDATE users SET must_change_password = 1 WHERE id = ?", [$u['id']]);
        completePasswordReset($u['id'], 'BrandNewPassw0rd!');
        $row = dbFetch("SELECT must_change_password FROM users WHERE id = ?", [$u['id']]);
        $this->assertSame(0, (int)$row['must_change_password']);
    }

    public function testSetTemporaryPasswordFlagsUserAndSetsExpiry(): void
    {
        $u = $this->makeUser();
        setTemporaryPassword($u['id'], 'TempPassw0rd');
        $row = dbFetch("SELECT password, must_change_password, temp_password_expires_at FROM users WHERE id = ?", [$u['id']]);
        $this->assertTrue(verifyPassword('TempPassw0rd', $row['password']));
        $this->assertSame(1, (int)$row['must_change_password']);
        $expiresIn = strtotime($row['temp_password_expires_at']) - time();
        $this->assertEqualsWithDelta(TEMP_PASSWORD_HOURS * 3600, $expiresIn, 120);
    }

    public function testUnexpiredTemporaryPasswordCanSignIn(): void
    {
        $u = $this->makeUser();
        setTemporaryPassword($u['id'], 'TempPassw0rd');
        $this->assertTrue(attemptLogin($u['username'], 'TempPassw0rd')['ok']);
    }

    public function testExpiredTemporaryPasswordIsRejectedWithoutCountingAsFailure(): void
    {
        $u = $this->makeUser();
        setTemporaryPassword($u['id'], 'TempPassw0rd');
        dbRun("UPDATE users SET temp_password_expires_at = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 60), $u['id']]);

        $result = attemptLogin($u['username'], 'TempPassw0rd');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('expired', $result['error']);
        $row = dbFetch("SELECT failed_login_attempts FROM users WHERE id = ?", [$u['id']]);
        $this->assertSame(0, (int)$row['failed_login_attempts']);

        // A wrong password still gets the generic message: expiry is only
        // revealed once the password itself is correct.
        $this->assertSame('Invalid username or password.', attemptLogin($u['username'], 'wrong')['error']);
    }

    public function testExpiryDoesNotApplyOnceThePasswordHasBeenChanged(): void
    {
        $u = $this->makeUser();
        setTemporaryPassword($u['id'], 'TempPassw0rd');
        $this->assertNull(changeOwnPassword($u['id'], 'TempPassw0rd', 'MyOwnPassw0rd', 'MyOwnPassw0rd'));
        $row = dbFetch("SELECT temp_password_expires_at FROM users WHERE id = ?", [$u['id']]);
        $this->assertNull($row['temp_password_expires_at']);
        $this->assertTrue(attemptLogin($u['username'], 'MyOwnPassw0rd')['ok']);
    }
}
