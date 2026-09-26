<?php

final class AccessControlTest extends TestCase
{
    protected function tearDown(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        unset($_SESSION['user_id'], $_SESSION['user']);
        parent::tearDown();
    }

    public function testIsActiveUserRowBlocksOnlyExplicitNonActiveStatus(): void
    {
        $this->assertTrue(isActiveUserRow(['status' => 'active']));
        $this->assertTrue(isActiveUserRow(['status' => 'Active']));
        $this->assertTrue(isActiveUserRow(['status' => '']));
        $this->assertTrue(isActiveUserRow([]));
        $this->assertFalse(isActiveUserRow(['status' => 'inactive']));
    }

    public function testInactiveUserCannotSignInEvenWithTheRightPassword(): void
    {
        $u = $this->makeUser(['status' => 'inactive']);
        $result = attemptLogin($u['username'], 'TestPassw0rd!');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('deactivated', $result['error']);
        // A wrong password still gets the generic message.
        $this->assertSame('Invalid username or password.', attemptLogin($u['username'], 'wrong')['error']);
    }

    public function testRefreshSessionUserPicksUpARoleChange(): void
    {
        $u = $this->makeUser(['role' => 'admin']);
        $_SESSION['user_id'] = $u['id'];
        $_SESSION['user'] = ['id' => $u['id'], 'role' => 'admin'];
        dbRun("UPDATE users SET role = 'engineer' WHERE id = ?", [$u['id']]);

        $this->assertTrue(refreshSessionUser());
        $this->assertSame('engineer', $_SESSION['user']['role']);
        $this->assertArrayNotHasKey('password', $_SESSION['user']);
    }

    public function testRefreshSessionUserEndsTheSessionOfADeactivatedUser(): void
    {
        $u = $this->makeUser();
        $_SESSION['user_id'] = $u['id'];
        $_SESSION['user'] = ['id' => $u['id'], 'role' => 'engineer'];
        dbRun("UPDATE users SET status = 'inactive' WHERE id = ?", [$u['id']]);

        $this->assertFalse(refreshSessionUser());
        $this->assertEmpty($_SESSION['user_id'] ?? null);
    }

    public function testRefreshSessionUserEndsTheSessionOfADeletedUser(): void
    {
        $_SESSION['user_id'] = newUuid();
        $_SESSION['user'] = ['id' => $_SESSION['user_id'], 'role' => 'admin'];
        $this->assertFalse(refreshSessionUser());
        $this->assertEmpty($_SESSION['user_id'] ?? null);
    }

    public function testTicketFieldErrorAcceptsKnownValuesAndRejectsMarkup(): void
    {
        $this->assertNull(ticketFieldError([]));
        $this->assertNull(ticketFieldError(['status' => 'in_progress', 'priority' => 'p1']));
        $this->assertNotNull(ticketFieldError(['status' => '"><b>x</b>']));
        $this->assertNotNull(ticketFieldError(['priority' => '"><svg onload=1>']));
        $this->assertNotNull(ticketFieldError(['status' => '']));
    }
}
