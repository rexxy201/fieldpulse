<?php

/** Account changes leave an audit trail; accounts record when they were created. */
final class UserAuditTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['user']);
        parent::tearDown();
    }

    /** The user's audit entry for $action (entries in the same second have no reliable order, so look up by action). */
    private function audit(string $userId, string $action): ?array
    {
        return dbFetch("SELECT action, details FROM audit_logs WHERE entity = 'user' AND entity_id = ? AND action = ? LIMIT 1", [$userId, $action]);
    }

    public function testNewAccountsRecordCreationTime(): void
    {
        $u = $this->makeUser(['role' => 'engineer']);
        $this->assertNotEmpty(dbFetch("SELECT created_at FROM users WHERE id = ?", [$u['id']])['created_at']);
    }

    public function testPasswordChangesAreAudited(): void
    {
        $u = $this->makeUser(['role' => 'engineer']);
        $_SESSION['user'] = ['id' => $u['id'], 'role' => 'engineer', 'name' => 'T'];
        $this->assertNull(changeOwnPassword($u['id'], 'TestPassw0rd!', 'Brand-New-Pass-7', 'Brand-New-Pass-7'));
        $a = $this->audit($u['id'], 'password_change');
        $this->assertNotNull($a);
        $this->assertStringContainsString($u['username'], $a['details']);

        setTemporaryPassword($u['id'], 'Temp-Pass-12345');
        $this->assertNotNull($this->audit($u['id'], 'password_set'));

        completePasswordReset($u['id'], 'Reset-Pass-12345');
        $this->assertNotNull($this->audit($u['id'], 'password_reset'));
    }

    public function testDeletedAccountStaysIdentifiableInTheLog(): void
    {
        $u = $this->makeUser(['role' => 'cx']);
        auditUserChange('user_delete', $u['id'], 'removed');
        dbRun("DELETE FROM users WHERE id = ?", [$u['id']]);
        $this->assertStringContainsString($u['username'] . ' (cx)', $this->audit($u['id'], 'user_delete')['details']);
    }

    public function testNoticeFallsBackToAdminsWhenTheAuthorizerRoleIsEmpty(): void
    {
        if (dbFetch("SELECT id FROM users WHERE role = 'supervisor-fiber' AND status = 'active'")) {
            $this->markTestSkipped('an active fiber supervisor exists in this database');
        }
        $admin = $this->makeUser(['role' => 'admin']);
        $title = 'Empty role test ' . bin2hex(random_bytes(3));
        notifyPermissionHolders('payment_requests.authorize', $title, 'm', '/x', 's', '<p>x</p>', 'fiber');
        $ids = array_column(dbFetchAll("SELECT user_id FROM notifications WHERE title = ?", [$title]), 'user_id');
        dbRun("DELETE FROM notifications WHERE title = ?", [$title]);
        $this->assertContains($admin['id'], $ids);
    }
}
