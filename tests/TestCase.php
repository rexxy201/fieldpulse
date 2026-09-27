<?php

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Shared helpers for tests that need real rows in the test database.
 * Every test tracks what it inserted and deletes it in tearDown() — no
 * transaction-rollback trick, since some of the app's own code (e.g.
 * auditLog()) opens its own connection-level statements that don't always
 * play nicely wrapped in an outer test transaction across every path.
 */
abstract class TestCase extends BaseTestCase
{
    /** @var array<int, array{table: string, id: string}> */
    private array $cleanup = [];

    protected function track(string $table, string $id): void
    {
        $this->cleanup[] = ['table' => $table, 'id' => $id];
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $row) {
            try {
                dbRun("DELETE FROM {$row['table']} WHERE id = ?", [$row['id']]);
            } catch (\Throwable $e) {
                // Best-effort — a row a test already deleted itself is fine.
            }
        }
        $this->cleanup = [];
        // Sign-in throttling counters are keyed by IP, and every test signs in
        // from the same CLI "IP"; clear them so tests (and reruns) don't trip
        // each other's limits.
        try { dbRun("DELETE FROM rate_limit_hits WHERE bucket LIKE 'login_fail%'"); } catch (\Throwable $e) {}
        parent::tearDown();
    }

    /** Creates a throwaway user row for this test, cleaned up automatically. */
    protected function makeUser(array $overrides = []): array
    {
        $id = newUuid();
        $defaults = [
            'id'       => $id,
            'username' => 'test_' . substr($id, 0, 8),
            'name'     => 'Test User',
            'email'    => substr($id, 0, 8) . '@example.test',
            'phone'    => '',
            'role'     => 'engineer',
            'password' => hashPassword('TestPassw0rd!'),
            'status'   => 'active',
        ];
        $row = array_merge($defaults, $overrides);
        dbRun(
            "INSERT INTO users (id,username,name,email,phone,role,password,status) VALUES (?,?,?,?,?,?,?,?)",
            [$row['id'], $row['username'], $row['name'], $row['email'], $row['phone'], $row['role'], $row['password'], $row['status']]
        );
        $this->track('users', $id);
        return $row;
    }
}
