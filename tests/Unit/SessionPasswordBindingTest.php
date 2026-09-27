<?php

/**
 * A password change or reset must end the account's other sessions, while the
 * session that made the change stays signed in.
 */
final class SessionPasswordBindingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    }

    protected function tearDown(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        unset($_SESSION['user_id'], $_SESSION['user'], $_SESSION['pw_fp']);
        parent::tearDown();
    }

    /** Puts this test's session in the state a real sign-in leaves it in. */
    private function signIn(array $u): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        $row = dbFetch("SELECT * FROM users WHERE id = ?", [$u['id']]);
        $_SESSION['user_id'] = $row['id'];
        $_SESSION['user']    = sanitizeUser($row);
        $_SESSION['pw_fp']   = passwordFingerprint($row);
    }

    public function testSessionSurvivesWhenThePasswordIsUnchanged(): void
    {
        $u = $this->makeUser();
        $this->signIn($u);
        $this->assertTrue(refreshSessionUser());
        $this->assertTrue(refreshSessionUser());
    }

    public function testAdminResetEndsTheMembersExistingSession(): void
    {
        $u = $this->makeUser();
        $this->signIn($u);
        setTemporaryPassword($u['id'], 'TempPassw0rd');
        $this->assertFalse(refreshSessionUser());
        $this->assertEmpty($_SESSION['user_id'] ?? null);
    }

    public function testResetLinkEndsExistingSessions(): void
    {
        $u = $this->makeUser();
        $this->signIn($u);
        completePasswordReset($u['id'], 'BrandNewPassw0rd!');
        $this->assertFalse(refreshSessionUser());
    }

    public function testOwnChangeKeepsThisSessionButEndsTheOthers(): void
    {
        $u = $this->makeUser();
        $this->signIn($u);
        $otherSessionFp = $_SESSION['pw_fp'];

        $this->assertNull(changeOwnPassword($u['id'], 'TestPassw0rd!', 'BrandNewPassw0rd!', 'BrandNewPassw0rd!'));
        $this->assertTrue(refreshSessionUser(), 'the session that changed the password stays signed in');

        // Any other session for the account still carries the old fingerprint.
        $_SESSION['pw_fp'] = $otherSessionFp;
        $this->assertFalse(refreshSessionUser());
    }

    public function testChangingSomeoneElsesPasswordLeavesThisSessionAlone(): void
    {
        $me    = $this->makeUser();
        $other = $this->makeUser();
        $this->signIn($me);
        $before = $_SESSION['pw_fp'];
        keepSessionAfterPasswordChange($other['id']);
        $this->assertSame($before, $_SESSION['pw_fp']);
        $this->assertTrue(refreshSessionUser());
    }

    public function testSessionFromBeforeTheCheckIsAdoptedThenBound(): void
    {
        $u = $this->makeUser();
        $this->signIn($u);
        unset($_SESSION['pw_fp']);

        $this->assertTrue(refreshSessionUser(), 'existing sessions are not signed out at deploy');
        $this->assertNotEmpty($_SESSION['pw_fp']);

        setTemporaryPassword($u['id'], 'TempPassw0rd');
        $this->assertFalse(refreshSessionUser(), 'once adopted, a later change still ends it');
    }

    public function testFingerprintDoesNotExposeTheHash(): void
    {
        $u = $this->makeUser();
        $row = dbFetch("SELECT * FROM users WHERE id = ?", [$u['id']]);
        $fp = passwordFingerprint($row);
        $this->assertStringNotContainsString($row['password'], $fp);
        $this->assertSame(64, strlen($fp));
    }
}
