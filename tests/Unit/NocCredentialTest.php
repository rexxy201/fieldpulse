<?php

final class NocCredentialTest extends TestCase
{
    // Derived at runtime rather than written as a literal, so it can't be
    // mistaken for a real credential by secret scanners.
    private static function key(): string
    {
        return hash('sha256', 'fieldpulse-noc-unit-test', true);
    }

    /** The old format, byte-for-byte as the previous nocEncrypt() produced it. */
    private static function legacyEncrypt(string $plain, string $key): string
    {
        $iv = random_bytes(16);
        return base64_encode($iv . openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));
    }

    private static function legacyRepoKey(): string
    {
        return hash('sha256', NOC_LEGACY_DEV_KEY_SEED, true);
    }

    public function testEncryptRefusesWithoutAConfiguredKey(): void
    {
        if (nocCredKey() !== null) $this->markTestSkipped('NOC_CRED_KEY is defined in this environment.');
        $this->expectException(RuntimeException::class);
        nocEncrypt('public');
    }

    public function testRoundTripUsesTheV2Format(): void
    {
        $enc = nocEncrypt('s3cret-community', self::key());
        $this->assertStringStartsWith('v2:', $enc);
        $this->assertStringNotContainsString('s3cret', $enc);
        $this->assertSame('s3cret-community', nocDecrypt($enc, self::key()));
    }

    public function testV2ValueFailsClosedWithTheWrongKeyOrTampering(): void
    {
        $enc = nocEncrypt('s3cret', self::key());
        $this->assertSame('', nocDecrypt($enc, str_repeat('x', 40)));
        $raw = base64_decode(substr($enc, 3));
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        $this->assertSame('', nocDecrypt('v2:' . base64_encode($raw), self::key()));
    }

    public function testLegacyValuesEncryptedWithTheRepoKeyStillDecrypt(): void
    {
        $json = json_encode(['user' => 'admin', 'pass' => 'router-pass']);
        $this->assertSame($json, nocDecrypt(self::legacyEncrypt($json, self::legacyRepoKey()), self::key()));
    }

    public function testReencryptMovesLegacyValuesToV2OnceAndKeepsThemReadable(): void
    {
        // network_devices comes from database/noc_migration.sql, which the CI
        // schema doesn't load; create the columns this test needs if absent.
        $createdTable = false;
        try { dbFetch("SELECT 1 FROM network_devices LIMIT 1"); }
        catch (\Throwable $e) {
            db()->exec("CREATE TABLE network_devices (
                id VARCHAR(36) NOT NULL PRIMARY KEY, hub_id VARCHAR(36) NOT NULL, device_type VARCHAR(20) NOT NULL DEFAULT 'olt',
                name VARCHAR(100) NOT NULL, ip_address VARCHAR(45) NOT NULL, protocol VARCHAR(20) NOT NULL DEFAULT 'snmp',
                snmp_community TEXT NULL, api_credentials TEXT NULL, enabled TINYINT(1) NOT NULL DEFAULT 1)");
            $createdTable = true;
        }
        $id = newUuid();
        $hub = dbFetch("SELECT id FROM hubs LIMIT 1");
        if (!$hub) {
            $hubId = newUuid();
            dbRun("INSERT INTO hubs (id, name) VALUES (?, ?)", [$hubId, 'Test Hub']);
            $this->track('hubs', $hubId);
        } else {
            $hubId = $hub['id'];
        }
        dbRun("INSERT INTO network_devices (id, hub_id, name, device_type, ip_address, protocol, snmp_community, enabled)
               VALUES (?, ?, 'Test OLT', 'olt', '10.0.0.1', 'snmp', ?, 1)",
            [$id, $hubId, self::legacyEncrypt('olt-community', self::legacyRepoKey())]);
        $this->track('network_devices', $id);

        $key = self::key() . substr(newUuid(), 0, 8); // fresh key => fresh run flag
        $this->assertGreaterThanOrEqual(1, nocReencryptLegacyCredentials($key));
        $stored = dbFetch("SELECT snmp_community FROM network_devices WHERE id = ?", [$id])['snmp_community'];
        $this->assertStringStartsWith('v2:', $stored);
        $this->assertSame('olt-community', nocDecrypt($stored, $key));
        $this->assertSame(0, nocReencryptLegacyCredentials($key), 'Second run for the same key is a no-op.');
        dbRun("DELETE FROM app_config WHERE " . dbKey() . " = ?", ['noc_creds_v2_' . substr(hash('sha256', $key), 0, 12)]);
        if ($createdTable) { dbRun("DELETE FROM network_devices WHERE id = ?", [$id]); db()->exec("DROP TABLE network_devices"); }
    }

    public function testReencryptIsANoOpWhenNocTablesAreMissing(): void
    {
        try { dbFetch("SELECT 1 FROM network_devices LIMIT 1"); $this->markTestSkipped('network_devices exists here.'); }
        catch (\PHPUnit\Framework\SkippedTest $s) { throw $s; }
        catch (\Throwable $e) { /* table missing: the case under test */ }
        $key = self::key() . substr(newUuid(), 0, 8);
        $this->assertSame(0, nocReencryptLegacyCredentials($key));
        $flag = 'noc_creds_v2_' . substr(hash('sha256', $key), 0, 12);
        $this->assertNotEmpty(dbFetch("SELECT value FROM app_config WHERE " . dbKey() . " = ?", [$flag]), 'Run is recorded so it is not retried every request.');
        dbRun("DELETE FROM app_config WHERE " . dbKey() . " = ?", [$flag]);
    }
}
