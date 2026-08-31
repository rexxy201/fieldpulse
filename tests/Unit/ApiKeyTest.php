<?php

final class ApiKeyTest extends TestCase
{
    public function testGeneratedKeyHasTheExpectedShapeAndTheHashMatches(): void
    {
        $gen = generateApiKey();
        $this->assertStringStartsWith('fp_live_', $gen['full']);
        $this->assertSame(48 + strlen('fp_live_'), strlen($gen['full']));
        $this->assertSame($gen['prefix'], substr($gen['full'], 0, 14));
        $this->assertSame($gen['hash'], hash('sha256', $gen['full']));
    }

    public function testTwoGeneratedKeysAreNeverTheSame(): void
    {
        $a = generateApiKey();
        $b = generateApiKey();
        $this->assertNotSame($a['full'], $b['full']);
    }

    public function testApiKeyHasScopeChecksTheStoredCommaList(): void
    {
        $key = ['scopes' => 'customers.read, payments.write'];
        $this->assertTrue(apiKeyHasScope($key, 'customers.read'));
        $this->assertTrue(apiKeyHasScope($key, 'payments.write'));
        $this->assertFalse(apiKeyHasScope($key, 'customers.write'));
    }
}
