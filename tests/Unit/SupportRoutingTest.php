<?php

/** Customer Support Phase A: menu teams, hold queue, callbacks, self-service, transfers, caller popup, POP cron watchdog. */
final class SupportRoutingTest extends TestCase
{
    private array $sessions = [];
    private array $rows = [];

    protected function tearDown(): void
    {
        unset($GLOBALS['csHttpFake'], $GLOBALS['nocProbeFake']);
        foreach ($this->sessions as $sid) {
            if ($call = dbFetch("SELECT id FROM cs_calls WHERE session_id = ?", [$sid])) {
                dbRun("DELETE FROM cs_followups WHERE call_id = ?", [$call['id']]);
                dbRun("DELETE FROM cs_calls WHERE id = ?", [$call['id']]);
            }
        }
        foreach (array_reverse($this->rows) as [$table, $col, $id]) dbRun("DELETE FROM $table WHERE $col = ?", [$id]);
        dbRun("DELETE FROM cs_queue_members");
        dbRun("DELETE FROM cs_agent_status WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'test_%')");
        dbRun("DELETE FROM app_config WHERE " . dbKey() . " IN ('popCronLastRun','popCronAlertedAt')");
        dbRun("DELETE FROM notifications WHERE link = '/noc/pops' AND title LIKE 'POP Monitor:%'");
        parent::tearDown();
    }

    private function settings(array $over = []): array
    {
        return array_merge([
            'provider' => 'africastalking', 'at_username' => 'mangonet', 'at_api_key' => 'k', 'at_number' => '+2347000000001',
            'webhook_secret' => 'sec', 'greeting' => 'Thank you for calling.', 'record' => true,
            'announcement' => 'This call may be recorded.', 'retention_days' => 30, 'ivr_enabled' => true,
            'ivr_options' => ['1' => 'Technical support', '2' => 'Billing'], 'fallback_numbers' => [], 'missed_assignee' => '',
            'hours' => [], 'overflow' => true, 'hold_enabled' => true, 'hold_max_minutes' => 5, 'hold_music_url' => '',
            'wrapup_seconds' => 30, 'outage_notice' => true, 'ticket_status' => true,
        ], $over);
    }

    private function sid(): string { return $this->sessions[] = 'ATVId_rt_' . bin2hex(random_bytes(6)); }

    private function phone(): string { return '+234803' . random_int(1000000, 9999999); }

    private function call(array $s, string $from, ?string $digit = null): string
    {
        $sid = $this->sid();
        csHandleVoiceCallback(['sessionId' => $sid, 'isActive' => '1', 'direction' => 'Inbound', 'callerNumber' => $from, 'destinationNumber' => '+2347000000001'], 'answer', $s);
        return $sid;
    }

    private function step(string $sid, string $step, array $s, array $extra = []): SimpleXMLElement
    {
        return simplexml_load_string(csHandleVoiceCallback(['sessionId' => $sid, 'isActive' => '1'] + $extra, $step, $s));
    }

    private function row(string $sid): array { return dbFetch("SELECT * FROM cs_calls WHERE session_id = ?", [$sid]); }

    private function agent(string $status = 'available'): array
    {
        $u = $this->makeUser(['role' => 'cx']);
        csSetAgentStatus($u['id'], $status);
        return $u;
    }

    private function customer(string $phone, ?string $hubId = null): string
    {
        $id = newUuid();
        dbRun("INSERT INTO customers (id,name,phone,account_number,plan,status,hub_id) VALUES (?,?,?,?,?,?,?)",
            [$id, 'Routing Test Customer', $phone, 'ACC-' . substr($id, 0, 6), '50 Mbps', 'active', $hubId]);
        $this->rows[] = ['customers', 'id', $id];
        return $id;
    }

    private function client(array $u): string { return 'mangonet.' . csAgentClientName($u['id']); }

    public function testMenuOptionRingsItsTeamThenOverflows(): void
    {
        $tech = $this->agent(); $other = $this->agent();
        dbRun("INSERT INTO cs_queue_members (digit, user_id) VALUES ('1', ?)", [$tech['id']]);
        $s = $this->settings();

        $doc = $this->step($this->call($s, $this->phone()), 'menu', $s, ['dtmfDigits' => '1']);
        $nums = (string)$doc->Dial['phoneNumbers'];
        $this->assertStringContainsString($this->client($tech), $nums);
        $this->assertStringNotContainsString($this->client($other), $nums, 'team members only while one is free');
        $this->assertStringContainsString('step=hold', (string)$doc->Redirect, 'unanswered ring goes back to the queue');

        csSetAgentStatus($tech['id'], 'on_call');
        $doc = $this->step($this->call($s, $this->phone()), 'menu', $s, ['dtmfDigits' => '1']);
        $this->assertStringContainsString($this->client($other), (string)$doc->Dial['phoneNumbers'], 'team busy: overflow to any free agent');

        $s2 = $this->settings(['overflow' => false]);
        $sid = $this->call($s2, $this->phone());
        $doc = $this->step($sid, 'menu', $s2, ['dtmfDigits' => '1']);
        $this->assertEmpty($doc->Dial, 'overflow off: no other team is rung');
        $this->assertStringContainsString('step=hold_key', (string)$doc->GetDigits['callBackUrl']);
        $this->assertSame('queued', $this->row($sid)['status']);
        $this->assertSame('1', $this->row($sid)['queue_digit']);
    }

    public function testBusyAgentsPutCallersInLine(): void
    {
        $busy = $this->agent('on_call');
        $s = $this->settings();
        $a = $this->call($s, $this->phone());
        $docA = $this->step($a, 'menu', $s, ['dtmfDigits' => '2']);
        $this->assertStringContainsString('You are next in line', (string)$docA->GetDigits->Say);
        dbRun("UPDATE cs_calls SET queued_at = ? WHERE session_id = ?", [date('Y-m-d H:i:s', time() - 30), $a]);
        $b = $this->call($s, $this->phone());
        $docB = $this->step($b, 'menu', $s, ['dtmfDigits' => '2']);
        $this->assertStringContainsString('number 2 in line', (string)$docB->GetDigits->Say);
        $this->assertSame('queued', $this->row($b)['status']);
        $this->assertSame(['2' => ['waiting' => 2]], array_map(fn($q) => ['waiting' => $q['waiting']], array_intersect_key(csQueueSnapshot(), ['2' => 1])));
    }

    public function testFirstInLineIsConnectedBeforeLaterCallers(): void
    {
        $busy = $this->agent('on_call');
        $s = $this->settings();
        $a = $this->call($s, $this->phone()); $this->step($a, 'menu', $s, ['dtmfDigits' => '2']);
        dbRun("UPDATE cs_calls SET queued_at = ? WHERE session_id = ?", [date('Y-m-d H:i:s', time() - 30), $a]);
        $b = $this->call($s, $this->phone()); $this->step($b, 'menu', $s, ['dtmfDigits' => '2']);
        csSetAgentStatus($busy['id'], 'available');

        $this->assertEmpty($this->step($b, 'hold', $s)->Dial, 'second in line waits');
        $this->assertStringContainsString($this->client($busy), (string)$this->step($a, 'hold', $s)->Dial['phoneNumbers']);
    }

    public function testCallbackRequestFromHold(): void
    {
        $this->agent('on_call');
        $sup = $this->makeUser(['role' => 'cx_supervisor']);
        $s = $this->settings(['missed_assignee' => $sup['id']]);
        $sid = $this->call($s, $this->phone());
        $this->step($sid, 'menu', $s, ['dtmfDigits' => '1']);
        $doc = $this->step($sid, 'hold_key', $s, ['dtmfDigits' => '1']);
        $this->assertStringContainsString('call you back', (string)$doc->Say);
        csHandleVoiceCallback(['sessionId' => $sid, 'isActive' => '0', 'durationInSeconds' => '50'], 'answer', $s);
        $call = $this->row($sid);
        $this->assertSame('callback', $call['status']);
        $this->assertSame(1, (int)$call['callback_requested']);
        $f = dbFetchAll("SELECT * FROM cs_followups WHERE call_id = ?", [$call['id']]);
        $this->assertCount(1, $f);
        $this->assertStringContainsString('Callback requested', $f[0]['note']);
        $this->assertSame($sup['id'], $f[0]['assigned_to']);
    }

    public function testHoldEndsInVoicemailAfterTheLimitAndAnsweredCallsAreNotRequeued(): void
    {
        $this->agent('on_call');
        $s = $this->settings();
        $sid = $this->call($s, $this->phone());
        $this->step($sid, 'menu', $s, ['dtmfDigits' => '1']);
        dbRun("UPDATE cs_calls SET queued_at = ? WHERE session_id = ?", [date('Y-m-d H:i:s', time() - 6 * 60), $sid]);
        $this->assertNotEmpty($this->step($sid, 'hold', $s)->Record);

        $agent = $this->agent();
        $sid2 = $this->call($s, $from = $this->phone());
        $this->step($sid2, 'menu', $s, ['dtmfDigits' => '1']);
        csMarkCallAnswered($from, $agent['id']);
        $doc = $this->step($sid2, 'hold', $s);
        $this->assertEmpty($doc->Dial);
        $this->assertEmpty($doc->GetDigits);
        $this->assertStringContainsString('Goodbye', (string)$doc->Say);
    }

    public function testHangingUpWhileHoldingIsAMissedCallWithANote(): void
    {
        $this->agent('on_call');
        $this->makeUser(['role' => 'cx_supervisor']);
        $s = $this->settings();
        $sid = $this->call($s, $this->phone());
        $this->step($sid, 'menu', $s, ['dtmfDigits' => '1']);
        csHandleVoiceCallback(['sessionId' => $sid, 'isActive' => '0', 'durationInSeconds' => '90'], 'answer', $s);
        $call = $this->row($sid);
        $this->assertSame('missed', $call['status']);
        $this->assertStringContainsString('on hold', dbFetch("SELECT note FROM cs_followups WHERE call_id = ?", [$call['id']])['note']);
    }

    public function testAgentIsRingableAgainAfterWrapUp(): void
    {
        $agent = $this->agent();
        $s = $this->settings();
        $sid = $this->call($s, $from = $this->phone());
        csMarkCallAnswered($from, $agent['id']);
        csSetAgentStatus($agent['id'], 'on_call');
        csHandleVoiceCallback(['sessionId' => $sid, 'isActive' => '0', 'durationInSeconds' => '60'], 'answer', $s);
        $this->assertSame('available', csAgentStatus($agent['id']));
        $this->assertNotContains($agent['id'], csFreeAgentIds(), 'inside the wrap-up window');
        dbRun("UPDATE cs_agent_status SET busy_until = ? WHERE user_id = ?", [date('Y-m-d H:i:s', time() - 1), $agent['id']]);
        $this->assertContains($agent['id'], csFreeAgentIds());

        // Choosing a status ends wrap-up straight away; a break is never rung.
        csEndAgentCall($agent['id'], $s);
        csSetAgentStatus($agent['id'], 'on_call'); csEndAgentCall($agent['id'], $s);
        csSetAgentStatus($agent['id'], 'available');
        $this->assertContains($agent['id'], csFreeAgentIds());
        csSetAgentStatus($agent['id'], 'break');
        $this->assertNotContains($agent['id'], csFreeAgentIds());
    }

    public function testOutageNoticeAndTicketStatusSelfService(): void
    {
        $hub = newUuid();
        dbRun("INSERT INTO hubs (id, name) VALUES (?, 'Routing Test Hub')", [$hub]);
        $this->rows[] = ['hubs', 'id', $hub];
        $pop = newUuid();
        dbRun("INSERT INTO noc_pops (id,name,hub_id,ip_address,status,down_since) VALUES (?,?,?,?,?,?)", [$pop, 'Osborne', $hub, '192.0.2.50', 'down', date('Y-m-d H:i:s', time() - 1800)]);
        $this->rows[] = ['noc_pops', 'id', $pop];
        $from = $this->phone();
        $cust = $this->customer($from, $hub);
        $t = newUuid();
        dbRun("INSERT INTO tickets (id, ticket_number, customer_id, status, priority, created_at) VALUES (?,?,?,?,?,?)", [$t, 'TKT-77', $cust, 'in_progress', 'medium', date('Y-m-d H:i:s')]);
        $this->rows[] = ['tickets', 'id', $t];

        $s = $this->settings();
        $sid = $this->sid();
        $doc = simplexml_load_string(csHandleVoiceCallback(['sessionId' => $sid, 'isActive' => '1', 'direction' => 'Inbound', 'callerNumber' => $from], 'answer', $s));
        $this->assertStringContainsString('network outage affecting your area', $doc->asXML());
        $this->assertStringContainsString('status of your fault report, press 9', (string)$doc->GetDigits->Say);

        $doc = $this->step($sid, 'menu', $s, ['dtmfDigits' => '9']);
        $this->assertStringContainsString('T K T 7 7', (string)$doc->Say);
        $this->assertStringContainsString('is being worked on', (string)$doc->Say);
        $this->assertNotEmpty($doc->GetDigits, 'back to the menu');

        // Popup context for the agent.
        $ctx = csCallerContext($from);
        $this->assertSame($cust, $ctx['customer']['id']);
        $this->assertSame('Routing Test Hub', $ctx['customer']['hub']);
        $this->assertSame('TKT-77', $ctx['customer']['tickets'][0]['ticket_number']);
        $this->assertSame('Osborne', $ctx['customer']['outage']['name']);
        $this->assertSame($this->row($sid)['id'], $ctx['call']['id']);

        // Turned off: neither is offered.
        $off = $this->settings(['outage_notice' => false, 'ticket_status' => false]);
        $doc = simplexml_load_string(csHandleVoiceCallback(['sessionId' => $this->sid(), 'isActive' => '1', 'callerNumber' => $from], 'answer', $off));
        $this->assertStringNotContainsString('outage', $doc->asXML());
        $this->assertStringNotContainsString('fault report', $doc->asXML());
        $this->assertSame('8', csTicketDigit(['ivr_options' => ['9' => 'Sales']]));
    }

    public function testTransferToAnotherAgent(): void
    {
        $me = $this->agent(); $next = $this->agent();
        $s = $this->settings();
        $sid = $this->call($s, $from = $this->phone());
        $callId = csMarkCallAnswered($from, $me['id']);
        csSetAgentStatus($me['id'], 'on_call');
        $seen = null;
        $GLOBALS['csHttpFake'] = function ($m, $url, $h, $body) use (&$seen) {
            $seen = compact('url', 'body');
            return ['status' => 200, 'body' => json_encode(['status' => 'Success']), 'error' => ''];
        };
        $r = csTransferCall($this->row($sid), 'agent:' . $next['id'], $me, $s);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame('https://voice.africastalking.com/callTransfer', $seen['url']);
        parse_str($seen['body'], $q);
        $this->assertSame($sid, $q['sessionId']);
        $this->assertSame($this->client($next), $q['phoneNumber']);
        $call = dbFetch("SELECT * FROM cs_calls WHERE id = ?", [$callId]);
        $this->assertSame($next['id'], $call['agent_id']);
        $this->assertSame($next['name'], $call['transferred_to']);
        $this->assertSame('on_call', csAgentStatus($next['id']));
        $this->assertSame('available', csAgentStatus($me['id']));

        $GLOBALS['csHttpFake'] = fn() => ['status' => 400, 'body' => json_encode(['errorMessage' => 'Invalid session']), 'error' => ''];
        $this->assertFalse(csTransferCall(dbFetch("SELECT * FROM cs_calls WHERE id = ?", [$callId]), '+2348030000009', $next, $s)['ok']);
        $this->assertFalse(csTransferCall(['direction' => 'inbound', 'status' => 'completed'] + $call, '+2348030000009', $next, $s)['ok']);
        $this->assertFalse(csTransferCall($call, 'agent:' . $next['id'], $next, $s)['ok'], 'not to yourself');
        $this->assertFalse(csTransferCall($call, '12', $next, $s)['ok']);
    }

    public function testTransferEndpointOnlyActsOnYourOwnCall(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/api/support-voice.php');
        $this->assertMatchesRegularExpression('/SELECT \* FROM cs_calls WHERE id = \? AND agent_id = \?/', $src);
        $this->assertStringContainsString("verifyCsrf()", $src);
        $this->assertStringContainsString("'hold', 'hold_key'", file_get_contents(dirname(__DIR__, 2) . '/api/voice-callback.php'));
    }

    public function testXmlRedirectAndHoldMusic(): void
    {
        $doc = simplexml_load_string(csVoiceXml([
            ['getDigits' => ['text' => 'Hold', 'play' => 'https://x.test/m.mp3?a=1&b=2', 'callBackUrl' => 'https://x.test/cb']],
            ['redirect' => 'https://x.test/r?k=1&step=hold'],
        ]));
        $this->assertSame('https://x.test/m.mp3?a=1&b=2', (string)$doc->GetDigits->Play['url']);
        $this->assertSame('https://x.test/r?k=1&step=hold', (string)$doc->Redirect);
    }

    public function testPopCronWatchdogChecksAndAlertsOnce(): void
    {
        $pop = newUuid();
        dbRun("INSERT INTO noc_pops (id,name,ip_address,created_at) VALUES (?,?,?,?)", [$pop, 'Watchdog POP', '192.0.2.60', date('Y-m-d H:i:s', time() - 3600)]);
        $this->rows[] = ['noc_pop_checks', 'pop_id', $pop];
        $this->rows[] = ['noc_pops', 'id', $pop];
        $admin = $this->makeUser(['role' => 'admin']);
        $GLOBALS['nocProbeFake'] = fn() => ['loss' => 0.0, 'latency' => 20.0, 'method' => 'ping'];

        dbUpsertConfig('popCronLastRun', date('Y-m-d H:i:s', time() - 30));
        $this->assertSame('ok', nocPopWatchdog());
        $this->assertNull(dbFetch("SELECT last_check_at FROM noc_pops WHERE id = ?", [$pop])['last_check_at']);

        dbUpsertConfig('popCronLastRun', date('Y-m-d H:i:s', time() - 1800));
        @$r = nocPopWatchdog();
        $this->assertSame('stale', $r);
        $this->assertNotNull(dbFetch("SELECT last_check_at FROM noc_pops WHERE id = ?", [$pop])['last_check_at'], 'checked by the backup');
        @nocPopWatchdog();
        $this->assertSame(1, (int)dbFetch("SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND title LIKE 'POP Monitor:%'", [$admin['id']])['n'], 'alerted once');
    }
}
