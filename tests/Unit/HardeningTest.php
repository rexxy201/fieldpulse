<?php

/** Cron tokens, session cookie, asset versioning and the serials page escaping. */
final class HardeningTest extends TestCase
{
    public function testSessionCookieIsSameSiteLax(): void
    {
        $this->assertSame('Lax', session_get_cookie_params()['samesite']);
    }

    public function testAssetUrlAddsTheFileModificationTime(): void
    {
        $url = assetUrl('/assets/style.css');
        $this->assertSame('/assets/style.css?v=' . filemtime(__DIR__ . '/../../assets/style.css'), $url);
        $this->assertSame('/assets/missing.css', assetUrl('/assets/missing.css'));
    }

    public function testCronEndpointsUseTheSharedConstantTimeGuard(): void
    {
        foreach (glob(__DIR__ . '/../../api/*.php') as $f) {
            $src = file_get_contents($f);
            $this->assertStringNotContainsString("\$_GET['token'] ?? '') !==", $src, basename($f) . ' compares the token directly');
            if (str_contains($src, 'call via cron')) {
                $this->assertMatchesRegularExpression("/requireCronToken\\('\\w+'\\);/", $src, basename($f) . ' has no token guard');
            }
        }
    }

    public function testAppConfigNeverReturnsCronTokensOrSmsSecretsToNonAdmins(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/app-config.php');
        foreach (['slaCheckToken', 'installSyncToken'] as $k) {
            $this->assertMatchesRegularExpression("/WRITE_ONLY_KEYS = \\[[^\\]]*'$k'/", $src);
        }
        foreach (['smsApiKey', 'smsApiSecret', 'smsUsername'] as $k) {
            $this->assertMatchesRegularExpression("/SENSITIVE_KEYS = \\[[^\\]]*'$k'/s", $src);
        }
    }

    public function testSerialsPageKeepsValuesOutOfInlineJavascript(): void
    {
        $src = file_get_contents(__DIR__ . '/../../pages/inventory/serials.php');
        $this->assertDoesNotMatchRegularExpression("/onclick=\"\\w+\\('\\$\\{/", $src);
        $this->assertStringContainsString("'\"':'&quot;'", $src);
        $this->assertStringContainsString("\"'\":'&#39;'", $src);
    }

    public function testTicketCheckinsUsesTheSameCollationAsTickets(): void
    {
        if (DB_TYPE !== 'mysql') $this->markTestSkipped('MySQL only');
        $this->assertSame(tableCollation('tickets'), tableCollation('ticket_checkins'));
        // The join My Jobs runs must not raise "Illegal mix of collations".
        dbFetchAll("SELECT t.id FROM tickets t LEFT JOIN ticket_checkins ci ON ci.ticket_id = t.id LIMIT 1");
        $this->addToAssertionCount(1);
    }

    public function testNoPlaceholdersInLimitOrOffset(): void
    {
        // dbFetchAll() binds every parameter as a string; MariaDB rejects
        // LIMIT '50'. Interpolate cast ints instead.
        $root = __DIR__ . '/../../';
        foreach (['pages', 'api', 'includes'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $dir)) as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                    $this->assertDoesNotMatchRegularExpression('/\b(LIMIT|OFFSET)\s+\?/i', file_get_contents($f->getPathname()), $f->getPathname());
                }
            }
        }
    }
}
