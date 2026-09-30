<?php

/** Role, payment-department and masking rules behind the security fixes. */
final class SecurityHelpersTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['user']);
        parent::tearDown();
    }

    private function actAs(string $role): void
    {
        $_SESSION['user'] = ['id' => 'x', 'role' => $role, 'name' => 'T'];
    }

    public function testOnlyAdminsCreateOrChangeAdmins(): void
    {
        $this->actAs('project_admin');
        $this->assertNotNull(userRoleChangeError(null, 'admin'));
        $this->assertNotNull(userRoleChangeError('admin', 'engineer'), 'cannot demote/edit an admin');
        $this->assertNull(userRoleChangeError('engineer', 'cx'));
        $this->assertNotNull(userRoleChangeError('engineer', 'superuser'), 'unknown role');
        $this->assertFalse(canManageUserAccount(['role' => 'admin']));
        $this->assertTrue(canManageUserAccount(['role' => 'engineer']));

        $this->actAs('admin');
        $this->assertNull(userRoleChangeError('engineer', 'admin'));
        $this->assertTrue(canManageUserAccount(['role' => 'admin']));
    }

    public function testPaymentAuthorizationStaysInTheDepartment(): void
    {
        $this->actAs('supervisor-fiber');
        $this->assertTrue(canAuthorizePaymentFrom('engineer'));
        $this->assertFalse(canAuthorizePaymentFrom('cx'));
        $this->assertFalse(canAuthorizePaymentFrom('vendor'), 'vendors: admins only');
        $this->actAs('cx_manager');
        $this->assertTrue(canAuthorizePaymentFrom('cx'));
        $this->assertFalse(canAuthorizePaymentFrom('noc_engineer'));
        $this->actAs('admin');
        $this->assertTrue(canAuthorizePaymentFrom('vendor'));
    }

    public function testMasking(): void
    {
        $this->assertSame('Chinedu O.', maskPersonName('Chinedu  Okafor'));
        $this->assertSame('Ada', maskPersonName('Ada'));
        $this->assertSame('c•••@example.com', maskEmail('chinedu@example.com'));
        $this->assertSame('', maskEmail(''));
    }

    public function testReportEmailsGoToStaffOnly(): void
    {
        $u = $this->makeUser(['role' => 'engineer']);
        $this->assertSame([strtolower($u['email'])], staffEmailsOnly(strtoupper($u['email']) . ', outsider@evil.test'));
        $this->assertSame([], staffEmailsOnly('outsider@evil.test'));
    }
}
