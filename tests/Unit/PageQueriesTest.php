<?php

/** Queries pages run at load time must match the real schema (a bad column is a 500). */
final class PageQueriesTest extends TestCase
{
    public function testSerialsPageInstallationListQueryRuns(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/pages/inventory/serials.php');
        $this->assertSame(1, preg_match('/\$installations = dbFetchAll\("([^"]+)"\)/', $src, $m));
        $this->assertIsArray(dbFetchAll($m[1]));
    }
}
