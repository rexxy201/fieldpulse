<?php

/** Customer Support: business hours routing and supervisor metrics. */
final class SupportHoursTest extends TestCase
{
    private array $cleanup = [];

    protected function tearDown(): void
    {
        unset($GLOBALS['csHttpFake']);
        foreach ($this->cleanup as [$table, $col, $val]) dbRun("DELETE FROM $table WHERE $col = ?", [$val]);
        parent::tearDown();
    }

    private function allWeek(string $o, string $c): array
    {
        return array_fill_keys(range(1, 7), [$o, $c]);
    }

    public function testHoursParsingDropsInvalidDays(): void
    {
        $h = csParseSupportHours(json_encode(['1' => ['08:00', '17:00'], '2' => ['17:00', '08:00'], '6' => ['9:00', '13:00'], '9' => ['08:00', '10:00'], '7' => 'x']));
        $this->assertSame([1 => ['08:00', '17:00']], $h);
        $this->assertSame([], csParseSupportHours(''));
    }

    public function testOpenAndClosed(): void
    {
        $mon10 = strtotime('2026-09-28 10:00');   // a Monday
        $h = [1 => ['08:00', '17:00']];
        $this->assertTrue(csSupportOpen($h, $mon10));
        $this->assertFalse(csSupportOpen($h, strtotime('2026-09-28 17:00')), 'closing time is exclusive');
        $this->assertFalse(csSupportOpen($h, strtotime('2026-09-27 10:00')), 'Sunday not configured');
        $this->assertTrue(csSupportOpen([], $mon10), 'no hours = always open');
    }

    public function testClosedLineSkipsRingingAndGoesToVoicemail(): void
    {
        $sid = 'ATVId_hours_' . bin2hex(random_bytes(5));
        $this->cleanup[] = ['cs_calls', 'session_id', $sid];
        $agent = $this->makeUser(['role' => 'cx']);
        csSetAgentStatus($agent['id'], 'available');
        $s = csVoiceSettings();
        $s = array_merge($s, ['provider' => 'africastalking', 'at_username' => 'mangonet', 'at_number' => '+2347000000001', 'webhook_secret' => 'sec',
                              'ivr_enabled' => true, 'closed_message' => 'We are closed.']);
        $s['hours'] = array_fill_keys(range(1, 7), ['00:00', '00:01']);   // effectively always closed
        if (csSupportOpen($s['hours'])) $this->markTestSkipped('running in the one open minute');
        $doc = simplexml_load_string(csHandleVoiceCallback(['sessionId' => $sid, 'isActive' => '1', 'direction' => 'Inbound',
            'callerNumber' => '+2348035550101', 'destinationNumber' => '+2347000000001'], 'answer', $s));
        $this->assertEmpty($doc->Dial, 'no phones ring when closed');
        $this->assertEmpty($doc->GetDigits, 'no menu when closed');
        $this->assertStringContainsString('We are closed.', (string)$doc->Record['text'] ?: (string)$doc->Record->Say);
        dbRun("DELETE FROM cs_agent_status WHERE user_id = ?", [$agent['id']]);
    }

    public function testWhatsAppClosedReplyReplacesAutoReplyOutsideHours(): void
    {
        $ph = '23481' . random_int(10000000, 99999999);
        $sent = [];
        $GLOBALS['csHttpFake'] = function ($m, $u, $hd, $b) use (&$sent) { $sent[] = json_decode($b, true)['body']['message']; return ['status' => 201, 'body' => '{}', 'error' => '']; };
        $s = ['provider' => 'africastalking', 'at_username' => 'u', 'at_api_key' => 'k', 'at_wa_number' => '+2347000000009', 'meta_phone_id' => '',
              'meta_token' => '', 'meta_app_secret' => '', 'meta_verify' => '', 'meta_template' => '', 'meta_template_lang' => 'en',
              'webhook_secret' => 's', 'auto_reply' => 'An agent will reply shortly.', 'closed_reply' => 'We are closed; we reply in the morning.',
              'hours' => array_fill_keys(range(1, 7), ['00:00', '00:01'])];
        if (csSupportOpen($s['hours'])) $this->markTestSkipped('running in the one open minute');
        $id = csWaReceive(['from' => $ph, 'name' => '', 'id' => 'h1' . $ph, 'body' => 'Hi', 'type' => 'text', 'ts' => time()], $s);
        $this->cleanup[] = ['cs_wa_messages', 'conversation_id', $id];
        $this->cleanup[] = ['cs_wa_conversations', 'id', $id];
        $this->assertSame(['We are closed; we reply in the morning.'], $sent);
    }

    public function testFirstResponseTimesIgnoreAutoRepliesAndCountWaiting(): void
    {
        $c1 = newUuid(); $c2 = newUuid();
        $agent = $this->makeUser(['role' => 'cx']);
        $t = time() - 3600;
        $ins = function ($conv, $dir, $agentId, $offset, $status = 'sent') use ($t) {
            dbRun("INSERT INTO cs_wa_messages (id,conversation_id,direction,body,status,agent_id,created_at) VALUES (?,?,?,?,?,?,?)",
                [newUuid(), $conv, $dir, 'x', $status, $agentId, date('Y-m-d H:i:s', $t + $offset)]);
        };
        $ins($c1, 'in', null, 0); $ins($c1, 'out', null, 5);          // auto-reply: not a response
        $ins($c1, 'out', $agent['id'], 10, 'failed');                   // failed send: not a response
        $ins($c1, 'in', null, 30); $ins($c1, 'out', $agent['id'], 120); // answered 120s after the first message
        $ins($c2, 'in', null, 60);                                      // still waiting
        $this->cleanup[] = ['cs_wa_messages', 'conversation_id', $c1];
        $this->cleanup[] = ['cs_wa_messages', 'conversation_id', $c2];
        $r = csWaResponseTimes(date('Y-m-d H:i:s', $t - 1));
        $this->assertContains(120, $r['times']);
        $this->assertGreaterThanOrEqual(1, $r['waiting']);
    }

    public function testDashboardIsSupervisorOnlyAndRouted(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertStringContainsString("requirePermission('support.view_all')", file_get_contents("$root/pages/support/dashboard.php"));
        $this->assertStringContainsString("'dashboard' => 'dashboard'", file_get_contents("$root/index.php"));
    }
}
