<?php

/** Payment request and SLA alerts reach the right department only. */
final class NotificationRoutingTest extends TestCase
{
    private string $title;

    protected function setUp(): void
    {
        parent::setUp();
        $this->title = 'Routing test ' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        dbRun("DELETE FROM notifications WHERE title = ?", [$this->title]);
        parent::tearDown();
    }

    private function notifiedIds(): array
    {
        return array_column(dbFetchAll("SELECT user_id FROM notifications WHERE title = ?", [$this->title]), 'user_id');
    }

    private function notify(string $permission, string $department): void
    {
        notifyPermissionHolders($permission, $this->title, 'msg', '/payment-requests', 'subj', '<p>x</p>', $department);
    }

    private function holders(string $permission): array
    {
        $r = array_column(dbFetchAll("SELECT role FROM role_permissions WHERE permission = ?", [$permission]), 'role');
        sort($r);
        return $r;
    }

    public function testWhoMaySignOffAtEachStage(): void
    {
        $auth = $this->holders('payment_requests.authorize');
        foreach (['supervisor-fiber', 'supervisor-noc', 'cx_manager'] as $r) $this->assertContains($r, $auth);
        $this->assertNotContains('project_admin', $auth, 'project admins only raise requests');
        $this->assertNotContains('cx_supervisor', $auth, 'CX authorization moved to the CX Manager');

        $this->assertEmpty(array_diff($this->holders('payment_requests.approve'), ['admin', 'coo_manager']), 'only COO and admins approve');

        $finance = array_column(dbFetchAll("SELECT name FROM roles WHERE department = 'finance'"), 'name');
        $this->assertEmpty(array_diff($this->holders('payment_requests.finance_check'), array_merge($finance, ['admin'])));
        $this->assertNotContains('project_admin', $this->holders('payment_requests.finance_check'));
    }

    public function testNewRequestGoesToTheRequestersDepartmentOnly(): void
    {
        $fiberSup = $this->makeUser(['role' => 'supervisor-fiber']);
        $nocSup   = $this->makeUser(['role' => 'supervisor-noc']);
        $cxMgr    = $this->makeUser(['role' => 'cx_manager']);
        $cxSup    = $this->makeUser(['role' => 'cx_supervisor']);
        $pAdmin   = $this->makeUser(['role' => 'project_admin']);

        $this->notify('payment_requests.authorize', roleDepartment('engineer'));
        $ids = $this->notifiedIds();
        $this->assertContains($fiberSup['id'], $ids);
        foreach ([$nocSup, $cxMgr, $cxSup, $pAdmin] as $u) $this->assertNotContains($u['id'], $ids);

        dbRun("DELETE FROM notifications WHERE title = ?", [$this->title]);
        $this->notify('payment_requests.authorize', roleDepartment('cx'));
        $ids = $this->notifiedIds();
        $this->assertContains($cxMgr['id'], $ids);
        $this->assertNotContains($cxSup['id'], $ids);
        $this->assertNotContains($fiberSup['id'], $ids);
    }

    public function testDepartmentWithoutAnAuthorizerFallsBackToAdmins(): void
    {
        $admin    = $this->makeUser(['role' => 'admin']);
        $fiberSup = $this->makeUser(['role' => 'supervisor-fiber']);
        $pAdmin   = $this->makeUser(['role' => 'project_admin']);
        $this->notify('payment_requests.authorize', roleDepartment('vendor'));
        $ids = $this->notifiedIds();
        $this->assertContains($admin['id'], $ids);
        $this->assertNotContains($fiberSup['id'], $ids);
        $this->assertNotContains($pAdmin['id'], $ids);
    }

    public function testApprovalAndFinanceNoticesStayInTheirDepartments(): void
    {
        $coo    = $this->makeUser(['role' => 'coo_manager']);
        $acct   = $this->makeUser(['role' => 'accountant']);
        $admin  = $this->makeUser(['role' => 'admin']);
        $pAdmin = $this->makeUser(['role' => 'project_admin']);

        $this->notify('payment_requests.approve', 'executive');
        $ids = $this->notifiedIds();
        $this->assertContains($coo['id'], $ids);
        foreach ([$acct, $admin, $pAdmin] as $u) $this->assertNotContains($u['id'], $ids);

        dbRun("DELETE FROM notifications WHERE title = ?", [$this->title]);
        $this->notify('payment_requests.finance_check', 'finance');
        $ids = $this->notifiedIds();
        $this->assertContains($acct['id'], $ids);
        foreach ([$coo, $admin, $pAdmin] as $u) $this->assertNotContains($u['id'], $ids);
    }

    public function testSlaSupervisorsComeFromTheTicketsDepartment(): void
    {
        $nocSup   = $this->makeUser(['role' => 'supervisor-noc']);
        $fiberSup = $this->makeUser(['role' => 'supervisor-fiber']);
        $admin    = $this->makeUser(['role' => 'admin']);
        $ids = array_column(departmentSupervisors(roleDepartment('noc_engineer')), 'id');
        $this->assertContains($nocSup['id'], $ids);
        $this->assertNotContains($fiberSup['id'], $ids);
        $this->assertNotContains($admin['id'], $ids);
        $this->assertContains($admin['id'], array_column(departmentSupervisors(''), 'id'), 'no department: admins');
    }
}
