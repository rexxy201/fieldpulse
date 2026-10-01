<?php

/**
 * Attacks from the security review, run over real HTTP against the built-in
 * server: each must now be refused. (Same harness as PaymentRequestApprovalTest.)
 */
final class SecurityLocksTest extends TestCase
{
    private static $serverProcess;
    private static string $baseUrl;
    private string $jar;

    public static function setUpBeforeClass(): void
    {
        $port = 8972;
        self::$baseUrl = "http://127.0.0.1:{$port}";
        $docroot = dirname(__DIR__, 2);
        $cmd = sprintf('php -S 127.0.0.1:%d -t %s %s', $port, escapeshellarg($docroot), escapeshellarg($docroot . DIRECTORY_SEPARATOR . 'index.php'));
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        self::$serverProcess = proc_open($cmd, $descriptors, $pipes, $docroot, null);
        if (!is_resource(self::$serverProcess)) self::fail('Could not start the built-in PHP server.');
        for ($i = 0; $i < 20; $i++) {
            $ch = curl_init(self::$baseUrl . '/login');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
            $ok = curl_exec($ch) !== false;
            curl_close($ch);
            if ($ok) break;
            usleep(150000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) { proc_terminate(self::$serverProcess); proc_close(self::$serverProcess); }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->jar = sys_get_temp_dir() . '/fp_sec_' . bin2hex(random_bytes(4)) . '.txt';
    }

    protected function tearDown(): void
    {
        @unlink($this->jar);
        dbRun("DELETE FROM rate_limit_hits WHERE bucket IN ('password_change','login')");
        parent::tearDown();
    }

    /** @return array{0:int,1:string} */
    private function request(string $method, string $path, array $post = [], ?string $json = null, string $csrf = ''): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 10]);
        if ($method !== 'GET') curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]);
        } elseif ($post) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, (string)$body];
    }

    /** Signs in as a fresh user with $role; returns [user, csrf token]. */
    private function loginAs(string $role): array
    {
        $u = $this->makeUser(['role' => $role]);
        [$st] = $this->request('POST', '/login', ['username' => $u['username'], 'password' => 'TestPassw0rd!']);
        $this->assertSame(302, $st, "login as $role");
        [, $body] = $this->request('GET', '/account');
        preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m);
        return [$u, $m[1] ?? ''];
    }

    public function testCannotChangeSomeoneElsesPassword(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        [, $csrf] = $this->loginAs('vendor');
        [$st] = $this->request('PATCH', "/api/users/{$admin['id']}/password", [], json_encode(['currentPassword' => 'TestPassw0rd!', 'newPassword' => 'Hijacked-Pass-1']), $csrf);
        $this->assertSame(403, $st, 'even with the right current password');
        $this->assertTrue(verifyPassword('TestPassw0rd!', dbFetch("SELECT password FROM users WHERE id=?", [$admin['id']])['password']));
    }

    public function testOwnPasswordGuessesAreRateLimited(): void
    {
        [$me, $csrf] = $this->loginAs('engineer');
        $codes = [];
        for ($i = 0; $i < 6; $i++) {
            [$codes[]] = $this->request('PATCH', "/api/users/{$me['id']}/password", [], json_encode(['currentPassword' => "wrong$i", 'newPassword' => 'Another-Pass-9']), $csrf);
        }
        $this->assertSame(400, $codes[0]);
        $this->assertSame(429, $codes[5]);
    }

    public function testTeamViewerCannotCreateAnAdmin(): void
    {
        [, $csrf] = $this->loginAs('supervisor-fiber');   // team.view, not team.manage
        $uname = 'test_evil_' . bin2hex(random_bytes(3));
        [$st] = $this->request('POST', '/team', ['_csrf' => $csrf, '_action' => 'add_user', 'username' => $uname, 'name' => 'Evil', 'role' => 'admin']);
        $this->assertSame(302, $st, 'refused: redirected away instead of the page rendering the new member');
        $this->assertNull(dbFetch("SELECT id FROM users WHERE username = ?", [$uname]));
    }

    public function testProjectAdminCannotPromoteToAdmin(): void
    {
        $victim = $this->makeUser(['role' => 'engineer']);
        [, $csrf] = $this->loginAs('project_admin');
        $this->request('POST', '/team', ['_csrf' => $csrf, '_action' => 'edit_user', 'id' => $victim['id'], 'name' => 'X', 'role' => 'admin']);
        $this->assertSame('engineer', dbFetch("SELECT role FROM users WHERE id=?", [$victim['id']])['role']);
        [$st] = $this->request('PATCH', "/api/users/{$victim['id']}", [], json_encode(['role' => 'admin']), $csrf);
        $this->assertSame(403, $st);
        $this->assertSame('engineer', dbFetch("SELECT role FROM users WHERE id=?", [$victim['id']])['role']);
    }

    public function testTeamChangesAreAudited(): void
    {
        $victim = $this->makeUser(['role' => 'engineer']);
        [, $csrf] = $this->loginAs('admin');
        $this->request('POST', '/team', ['_csrf' => $csrf, '_action' => 'edit_user', 'id' => $victim['id'], 'name' => 'X', 'role' => 'cx', 'status' => 'active']);
        $a = dbFetch("SELECT action, details FROM audit_logs WHERE entity = 'user' AND entity_id = ? AND action = 'user_update' LIMIT 1", [$victim['id']]);
        $this->assertNotNull($a);
        $this->assertStringContainsString('role engineer → cx', $a['details']);
    }

    public function testAutoDispatchNeedsAssignPermission(): void
    {
        [, $csrf] = $this->loginAs('vendor');
        [$st] = $this->request('POST', '/api/tickets/auto-dispatch', [], '{}', $csrf);
        $this->assertSame(403, $st);
    }

    public function testUnusedDataEndpointsAreGone(): void
    {
        $this->loginAs('vendor');
        foreach (['/api/dashboard', '/api/analytics'] as $p) {
            [$st, $body] = $this->request('GET', $p);
            $this->assertNotSame(200, $st, $p);
            $this->assertStringNotContainsString('customer_name', $body, $p);
        }
    }

    public function testPublicPortalMasksPersonalDetails(): void
    {
        // The portal also lists the customer's ONUs; onu_units comes from
        // database/noc_migration.sql, which a bare test database may not have.
        if (!dbFetch("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'onu_units'")) {
            $this->markTestSkipped('onu_units not in this database (database/noc_migration.sql)');
        }
        $acct = 'SEC-' . random_int(100000, 999999);
        $id = newUuid();
        dbRun("INSERT INTO customers (id,name,email,phone,account_number,status) VALUES (?,?,?,?,?,'active')",
            [$id, 'Chinedu Okafor', 'chinedu.okafor@example.test', '08031234567', $acct]);
        $this->track('customers', $id);
        [$st, $body] = $this->request('GET', '/portal?account=' . urlencode($acct));
        $this->assertSame(200, $st);
        $this->assertStringContainsString('Chinedu O.', $body);
        $this->assertStringNotContainsString('Okafor', $body);
        $this->assertStringNotContainsString('chinedu.okafor@', $body);
    }
}
