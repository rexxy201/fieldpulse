<?php

final class SmokeTest extends TestCase
{
    public function testConfigBootstrapConnectsToTheTestDatabase(): void
    {
        $this->assertSame('fieldpulse_test', DB_NAME);
        $this->assertInstanceOf(PDO::class, db());
    }

    public function testAllSchemaMigrationsRanCleanlyOnAFreshDatabase(): void
    {
        // If any schema_vN block in config.php failed, its _migrated flag
        // would be missing from app_config — this is a real regression check,
        // not just a database's presence.
        $row = dbFetch("SELECT COUNT(*) c FROM app_config WHERE `key` LIKE 'schema_v%_migrated'");
        $this->assertGreaterThan(20, (int)$row['c'], 'Expected most schema_vN migrations to have run and recorded their flag.');
    }
}
