<?php

/**
 * Staff contact details from GET /api/users, and the rule that only the
 * router (index.php) may be requested as a URL.
 */
final class DirectAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    }

    protected function tearDown(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        unset($_SESSION['user_id'], $_SESSION['user']);
        parent::tearDown();
    }

    private function actAs(array $u): void
    {
        $_SESSION['user_id'] = $u['id'];
        $_SESSION['user']    = sanitizeUser($u);
    }

    public function testUsersWithoutTeamViewGetNoContactDetails(): void
    {
        // "cx" has no team.view in the default role permissions.
        $this->assertFalse(in_array('team.view', array_column(
            dbFetchAll("SELECT permission FROM role_permissions WHERE role = 'cx'"), 'permission'), true));
        $this->actAs($this->makeUser(['role' => 'cx']));
        $cols = usersListColumns();
        foreach (['email', 'phone', 'username', 'status'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $cols);
        }
        $row = dbFetch($cols . " FROM users LIMIT 1");
        $this->assertSame(['id', 'name', 'role'], array_keys($row));
    }

    public function testAdminsStillGetTheFullList(): void
    {
        $this->actAs($this->makeUser(['role' => 'admin']));
        $row = dbFetch(usersListColumns() . " FROM users LIMIT 1");
        foreach (['email', 'phone', 'username', 'status'] as $shown) {
            $this->assertArrayHasKey($shown, $row);
        }
        $this->assertArrayNotHasKey('password', $row);
    }

    public function testHtaccessOnlyLetsTheRouterRunAsAUrl(): void
    {
        $ht = file_get_contents(__DIR__ . '/../../.htaccess');
        $this->assertStringContainsString('RewriteRule ^index\.php$ - [L]', $ht);
        $this->assertMatchesRegularExpression('/RewriteRule \\\\\.\(php\[0-9\]\?\|phtml\|phar\)\(\/\|\$\) - \[NC,R=404,L\]/', $ht);
        $this->assertStringContainsString('RewriteRule ^(vendor|includes|tests|scripts|database|graphify-out)(/|$) - [NC,R=404,L]', $ht);
        // The live document root is the git checkout: .git/ must never be served.
        $this->assertStringContainsString('RewriteRule (^|/)\\.(?!well-known(/|$)) - [NC,R=404,L]', $ht);
        $this->assertStringContainsString('<FilesMatch "^(error_log|php_errorlog)$">', $ht);
        // The block has to come before the front-controller rule to take effect.
        $this->assertLessThan(strpos($ht, 'RewriteRule ^ index.php'), strpos($ht, 'R=404'));
    }
}
