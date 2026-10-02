<?php

/** NOC POP Monitor: ping parsing, up/down transitions, outages and alerts. */
final class NocPopTest extends TestCase
{
    private array $popIds = [];

    protected function tearDown(): void
    {
        unset($GLOBALS['nocProbeFake']);
        foreach ($this->popIds as $id) {
            dbRun("DELETE FROM noc_pop_checks WHERE pop_id = ?", [$id]);
            dbRun("DELETE FROM noc_pop_outages WHERE pop_id = ?", [$id]);
            dbRun("DELETE FROM noc_pops WHERE id = ?", [$id]);
        }
        dbRun("DELETE FROM notifications WHERE link = '/noc/pops' AND title LIKE '%Test POP%'");
        parent::tearDown();
    }

    private function pop(array $over = []): array
    {
        $id = newUuid();
        $this->popIds[] = $id;
        $row = array_merge(['name' => 'Test POP ' . substr($id, 0, 6), 'ip_address' => '192.0.2.10', 'down_after' => 3, 'tcp_port' => 8291, 'latency_warn_ms' => null], $over);
        dbRun("INSERT INTO noc_pops (id,name,ip_address,down_after,tcp_port,latency_warn_ms) VALUES (?,?,?,?,?,?)",
            [$id, $row['name'], $row['ip_address'], $row['down_after'], $row['tcp_port'], $row['latency_warn_ms']]);
        return dbFetch("SELECT * FROM noc_pops WHERE id = ?", [$id]);
    }

    private function probe(string $id, array $result): array
    {
        $GLOBALS['nocProbeFake'] = fn() => $result + ['method' => 'ping'];
        nocCheckPops([$id]);
        return dbFetch("SELECT * FROM noc_pops WHERE id = ?", [$id]);
    }

    public function testParsesRealPingOutput(): void
    {
        $ok = "PING 102.216.236.22 (102.216.236.22) 56(84) bytes of data.\n64 bytes from 102.216.236.22: icmp_seq=1 ttl=46 time=244 ms\n\n"
            . "--- 102.216.236.22 ping statistics ---\n2 packets transmitted, 2 received, 0% packet loss, time 1000ms\nrtt min/avg/max/mdev = 244.316/244.348/244.380/0.032 ms";
        $this->assertSame(['loss' => 0.0, 'latency' => 244.348], nocParsePing($ok));
        $dead = "--- 192.0.2.1 ping statistics ---\n3 packets transmitted, 0 received, 100% packet loss, time 2046ms";
        $this->assertSame(['loss' => 100.0, 'latency' => null], nocParsePing($dead));
        $busybox = "3 packets transmitted, 3 packets received, 0% packet loss\nround-trip min/avg/max = 1.1/2.5/3.0 ms";
        $this->assertSame(2.5, nocParsePing($busybox)['latency']);
        $this->assertNull(nocParsePing('sh: ping: command not found'));
    }

    public function testOnlyRealHostsAreAccepted(): void
    {
        $this->assertTrue(nocPopValidHost('102.216.236.22'));
        $this->assertTrue(nocPopValidHost('pop1.mangonetonline.com'));
        $this->assertFalse(nocPopValidHost('1.2.3.4; rm -rf /'));
        $this->assertFalse(nocPopValidHost('-c 1000 example.com'));
    }

    public function testDownOnlyAfterConsecutiveFailuresThenRecovers(): void
    {
        $sup = $this->makeUser(['role' => 'supervisor-noc']);
        $pop = $this->pop(['down_after' => 3]);
        $p = $this->probe($pop['id'], ['loss' => 0.0, 'latency' => 40.0]);
        $this->assertSame('up', $p['status']);

        $this->probe($pop['id'], ['loss' => 100.0, 'latency' => null]);
        $p = $this->probe($pop['id'], ['loss' => 100.0, 'latency' => null]);
        $this->assertSame('up', $p['status'], 'two misses are not an outage');
        $this->assertSame(2, (int)$p['consecutive_failures']);

        $p = $this->probe($pop['id'], ['loss' => 100.0, 'latency' => null]);
        $this->assertSame('down', $p['status']);
        $this->assertNotNull($p['down_since']);
        $this->assertNotNull(dbFetch("SELECT id FROM notifications WHERE user_id = ? AND title LIKE 'POP DOWN:%'", [$sup['id']]));

        $this->probe($pop['id'], ['loss' => 100.0, 'latency' => null]);
        $this->assertSame(1, (int)dbFetch("SELECT COUNT(*) n FROM notifications WHERE user_id = ? AND title LIKE 'POP DOWN:%'", [$sup['id']])['n'], 'one alert per outage');

        $p = $this->probe($pop['id'], ['loss' => 0.0, 'latency' => 35.0]);
        $this->assertSame('up', $p['status']);
        $o = dbFetch("SELECT * FROM noc_pop_outages WHERE pop_id = ?", [$pop['id']]);
        $this->assertNotNull($o['ended_at']);
        $this->assertNotNull(dbFetch("SELECT id FROM notifications WHERE user_id = ? AND title LIKE 'POP recovered:%'", [$sup['id']]));
        // 6 checks, 2 answered.
        $this->assertSame(33.33, nocPopUptime('2000-01-01 00:00:00')[$pop['id']]);
    }

    public function testPacketLossOrSlowReplyIsDegraded(): void
    {
        $pop = $this->pop(['latency_warn_ms' => 200]);
        $this->assertSame('degraded', $this->probe($pop['id'], ['loss' => 33.3, 'latency' => 50.0])['status']);
        $this->assertSame('degraded', $this->probe($pop['id'], ['loss' => 0.0, 'latency' => 244.0])['status']);
        $this->assertSame('up', $this->probe($pop['id'], ['loss' => 0.0, 'latency' => 120.0])['status']);
    }

    public function testAlertsGoToNocSupervisorsAndAdminsOnly(): void
    {
        $noc = $this->makeUser(['role' => 'supervisor-noc']);
        $admin = $this->makeUser(['role' => 'admin']);
        $fiber = $this->makeUser(['role' => 'supervisor-fiber']);
        $ids = array_column(nocPopAlertRecipients(), 'id');
        $this->assertContains($noc['id'], $ids);
        $this->assertContains($admin['id'], $ids);
        $this->assertNotContains($fiber['id'], $ids);
    }

    public function testFallsBackToTcpWhenPingIsUnavailable(): void
    {
        // This sandbox has no ping binary: the probe must fall back to TCP.
        $open = $this->pop(['ip_address' => '127.0.0.1', 'tcp_port' => 3306]);
        $closed = $this->pop(['ip_address' => '127.0.0.1', 'tcp_port' => 1]);
        $r = nocProbePops([$open, $closed]);
        if (($r[$open['id']]['method'] ?? '') === 'ping') $this->markTestSkipped('ping is available here');
        $this->assertSame(0.0, $r[$open['id']]['loss']);
        $this->assertSame('tcp:3306', $r[$open['id']]['method']);
        $this->assertSame(100.0, $r[$closed['id']]['loss']);
    }

    public function testPageIsRouted(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertStringContainsString("pages/noc-pops.php", file_get_contents("$root/index.php"));
        $this->assertStringContainsString("requirePermission('noc.view')", file_get_contents("$root/pages/noc-pops.php"));
    }
}
