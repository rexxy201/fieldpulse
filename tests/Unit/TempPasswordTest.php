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
}
