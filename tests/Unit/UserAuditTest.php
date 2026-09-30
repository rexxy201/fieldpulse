<?php

/** Account changes leave an audit trail; accounts record when they were created. */
final class UserAuditTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['user']);
        parent::tearDown();
    }

    private function lastAudit(string $userId): ?array
    {
        return dbFetch("SELECT action, details FROM audit_logs WHERE entity = 'user' AND entity_id = ? ORDER BY created_at DESC, id DESC LIMIT 1", [$userId]);
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
        $a = $this->lastAudit($u['id']);
        $this->assertSame('password_change', $a['action']);
        $this->assertStringContainsString($u['username'], $a['details']);

        setTemporaryPassword($u['id'], 'Temp-Pass-12345');
        $this->assertSame('password_set', $this->lastAudit($u['id'])['action']);

        completePasswordReset($u['id'], 'Reset-Pass-12345');
        $this->assertSame('password_reset', $this->lastAudit($u['id'])['action']);
    }

    public function testDeletedAccountStaysIdentifiableInTheLog(): void
    {
        $u = $this->makeUser(['role' => 'cx']);
        auditUserChange('user_delete', $u['id'], 'removed');
        dbRun("DELETE FROM users WHERE id = ?", [$u['id']]);
        $this->assertStringContainsString($u['username'] . ' (cx)', $this->lastAudit($u['id'])['details']);
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
