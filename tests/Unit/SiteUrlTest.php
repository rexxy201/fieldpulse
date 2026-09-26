<?php

final class SiteUrlTest extends TestCase
{
    public function testSiteBaseUrlIgnoresTheRequestHostHeader(): void
    {
        $saved = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'attacker.example';
        try {
            $url = siteBaseUrl();
            $this->assertStringNotContainsString('attacker.example', $url);
            $this->assertMatchesRegularExpression('#^https?://[a-z0-9.-]+(:\d+)?$#i', $url);
            if (!defined('SITE_URL')) {
                $this->assertContains($url, [SITE_URL_DEFAULT, rtrim((string)(getAppConfig()['siteUrl'] ?? ''), '/')]);
            }
        } finally {
            if ($saved === null) unset($_SERVER['HTTP_HOST']); else $_SERVER['HTTP_HOST'] = $saved;
        }
    }

    public function testNoCodePathBuildsLinksFromTheHostHeader(): void
    {
        // Guard against the pattern creeping back in.
        $root = dirname(__DIR__, 2);
        $files = array_merge([$root . '/config.php'], glob($root . '/api/*.php'), glob($root . '/api/v1/*.php'),
                             glob($root . '/pages/*.php'), glob($root . '/pages/inventory/*.php'), glob($root . '/includes/*.php'));
        $offenders = [];
        foreach ($files as $f) {
            if (str_contains(file_get_contents($f), "\$_SERVER['HTTP_HOST']")) $offenders[] = basename($f);
        }
        $this->assertSame([], $offenders, 'Use siteBaseUrl() instead of the Host header.');
    }
}
