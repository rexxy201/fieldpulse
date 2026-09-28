<?php

if (!defined('CS_RECORDINGS_DIR')) define('CS_RECORDINGS_DIR', sys_get_temp_dir() . '/fieldops-test-recordings');

/** Customer Support voice: call flow, provider XML, tokens, recordings. */
final class SupportVoiceTest extends TestCase
{
    private array $sessions = [];

    protected function tearDown(): void
    {
        unset($GLOBALS['csHttpFake']);
        foreach ($this->sessions as $sid) {
            $call = dbFetch("SELECT id FROM cs_calls WHERE session_id = ?", [$sid]);
            if ($call) {
                dbRun("DELETE FROM cs_followups WHERE call_id = ?", [$call['id']]);
                dbRun("DELETE FROM cs_interactions WHERE call_id = ?", [$call['id']]);
                dbRun("DELETE FROM cs_calls WHERE id = ?", [$call['id']]);
            }
        }
        dbRun("DELETE FROM cs_agent_status WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'test_%')");
        parent::tearDown();
    }

    private function settings(array $over = []): array
    {
        return array_merge([
            'provider' => 'africastalking', 'at_username' => 'mangonet', 'at_api_key' => 'k', 'at_number' => '+2347000000001',
            'webhook_secret' => 'sec', 'greeting' => 'Thank you for calling.', 'record' => true,
            'announcement' => 'This call may be recorded.', 'retention_days' => 30, 'ivr_enabled' => true,
            'ivr_options' => ['1' => 'Technical support', '2' => 'Billing'], 'fallback_numbers' => [], 'missed_assignee' => '',
        ], $over);
    }

    private function sid(): string
    {
        return $this->sessions[] = 'ATVId_test_' . bin2hex(random_bytes(6));
    }

    private function inbound(string $sid, string $from = '+2348035550101', array $extra = []): array
    {
        return array_merge(['sessionId' => $sid, 'isActive' => '1', 'direction' => 'Inbound', 'callerNumber' => $from, 'destinationNumber' => '+2347000000001'], $extra);
    }

    public function testXmlIsWellFormedAndEscaped(): void
    {
        $xml = csVoiceXml([['say' => 'Tom & "Jerry" <3'], ['dial' => ['numbers' => ['a.b', '+2348030000000'], 'record' => true, 'callerId' => '+2347000000001']]]);
        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc);
        $this->assertSame('Tom & "Jerry" <3', (string)$doc->Say);
        $this->assertSame('a.b,+2348030000000', (string)$doc->Dial['phoneNumbers']);
        $this->assertSame('true', (string)$doc->Dial['record']);
    }

    public function testCallbackParsingIsTolerant(): void
    {
        $e = csParseVoiceCallback(['SESSIONID' => 'x', 'isActive' => '0', 'direction' => 'Outbound', 'durationInSeconds' => '42', 'recordingUrl' => 'https://r/1.mp3']);
        $this->assertSame('x', $e['session_id']);
        $this->assertFalse($e['is_active']);
        $this->assertSame('outbound', $e['direction']);
        $this->assertSame(42, $e['duration']);
        $this->assertSame('https://r/1.mp3', $e['recording_url']);
    }

    public function testInboundWithNobodyAvailableGoesToVoicemailAfterTheMenu(): void
    {
        $s = $this->settings(); $sid = $this->sid();
        $doc = simplexml_load_string(csHandleVoiceCallback($this->inbound($sid), 'answer', $s));
        $this->assertStringContainsString('This call may be recorded.', (string)$doc->Say);
        $this->assertStringContainsString('For Technical support, press 1.', (string)$doc->GetDigits->Say);
        $this->assertStringContainsString('step=menu', (string)$doc->GetDigits['callBackUrl']);
        $this->assertNotEmpty($doc->Record, 'no agent available and no fallback: voicemail');
        $this->assertSame('ringing', dbFetch("SELECT status FROM cs_calls WHERE session_id = ?", [$sid])['status']);
    }

    public function testMenuChoiceIsStoredAndAvailableAgentsAreDialledTogether(): void
    {
        $s = $this->settings(); $sid = $this->sid();
        $agent = $this->makeUser(['role' => 'cx']);
        csSetAgentStatus($agent['id'], 'available');
        csHandleVoiceCallback($this->inbound($sid), 'answer', $s);
        $doc = simplexml_load_string(csHandleVoiceCallback(['sessionId' => $sid, 'isActive' => '1', 'dtmfDigits' => '2'], 'menu', $s));
        $this->assertStringContainsString('mangonet.' . csAgentClientName($agent['id']), (string)$doc->Dial['phoneNumbers']);
        $this->assertSame('false', (string)$doc->Dial['sequential']);
        $this->assertSame('Billing', dbFetch("SELECT ivr_choice FROM cs_calls WHERE session_id = ?", [$sid])['ivr_choice']);
    }

    public function testFallbackMobilesRingInOrderWhenNoAgentIsOnline(): void
    {
        $s = $this->settings(['ivr_enabled' => false, 'fallback_numbers' => ['08030000001', '+2348030000002']]);
        $doc = simplexml_load_string(csHandleVoiceCallback($this->inbound($this->sid()), 'answer', $s));
        $this->assertSame('+2348030000001,+2348030000002', (string)$doc->Dial['phoneNumbers']);
        $this->assertSame('true', (string)$doc->Dial['sequential']);
    }

    public function testUnansweredInboundCallBecomesMissedWithOneFollowUp(): void
    {
        $s = $this->settings(); $sid = $this->sid();
        $sup = $this->makeUser(['role' => 'cx_supervisor']);
        csHandleVoiceCallback($this->inbound($sid), 'answer', $s + []);
        $end = ['sessionId' => $sid, 'isActive' => '0', 'durationInSeconds' => '20', 'hangupCause' => 'NORMAL_CLEARING'];
        csHandleVoiceCallback($end, 'answer', array_merge($s, ['missed_assignee' => $sup['id']]));
        csHandleVoiceCallback($end, 'answer', array_merge($s, ['missed_assignee' => $sup['id']]));   // duplicate notification
        $call = dbFetch("SELECT * FROM cs_calls WHERE session_id = ?", [$sid]);
        $this->assertSame('missed', $call['status']);
        $f = dbFetchAll("SELECT * FROM cs_followups WHERE call_id = ?", [$call['id']]);
        $this->assertCount(1, $f);
        $this->assertSame($sup['id'], $f[0]['assigned_to']);
    }

    public function testAnsweredCallCompletesWithRecording(): void
    {
        $s = $this->settings(); $sid = $this->sid();
        $agent = $this->makeUser(['role' => 'cx']);
        csHandleVoiceCallback($this->inbound($sid, '08035550199'), 'answer', $s);
        $this->assertNotNull(csMarkCallAnswered('+234 803 555 0199', $agent['id']));
        csHandleVoiceCallback(['sessionId' => $sid, 'isActive' => '0', 'durationInSeconds' => '95', 'recordingUrl' => 'https://rec.example/1.mp3'], 'answer', $s);
        $call = dbFetch("SELECT * FROM cs_calls WHERE session_id = ?", [$sid]);
        $this->assertSame('completed', $call['status']);
        $this->assertSame($agent['id'], $call['agent_id']);
        $this->assertSame(95, (int)$call['duration_sec']);
        $this->assertSame('https://rec.example/1.mp3', $call['recording_url']);
        $this->assertSame(0, (int)dbFetch("SELECT COUNT(*) AS n FROM cs_followups WHERE call_id = ?", [$call['id']])['n']);
    }

    public function testVoicemailIsRecordedAndFollowedUp(): void
    {
        $s = $this->settings(); $sid = $this->sid();
        $this->makeUser(['role' => 'cx_supervisor']);
        csHandleVoiceCallback($this->inbound($sid), 'answer', $s);
        csHandleVoiceCallback(['sessionId' => $sid, 'isActive' => '1', 'recordingUrl' => 'https://rec.example/vm.mp3'], 'voicemail', $s);
        csHandleVoiceCallback(['sessionId' => $sid, 'isActive' => '0', 'durationInSeconds' => '40'], 'answer', $s);
        $call = dbFetch("SELECT * FROM cs_calls WHERE session_id = ?", [$sid]);
        $this->assertSame('voicemail', $call['status']);
        $this->assertSame(1, (int)dbFetch("SELECT COUNT(*) AS n FROM cs_followups WHERE call_id = ?", [$call['id']])['n']);
    }

    public function testBrowserAgentDialsOut(): void
    {
        $s = $this->settings(); $sid = $this->sid();
        $agent = $this->makeUser(['role' => 'cx']);
        $doc = simplexml_load_string(csHandleVoiceCallback([
            'sessionId' => $sid, 'isActive' => '1', 'direction' => 'Inbound',
            'callerNumber' => 'mangonet.' . csAgentClientName($agent['id']), 'clientDialedNumber' => '08030000009',
        ], 'answer', $s));
        $this->assertSame('+2348030000009', (string)$doc->Dial['phoneNumbers']);
        $this->assertSame('+2347000000001', (string)$doc->Dial['callerId']);
        $call = dbFetch("SELECT * FROM cs_calls WHERE session_id = ?", [$sid]);
        $this->assertSame('outbound', $call['direction']);
        $this->assertSame($agent['id'], $call['agent_id']);
    }

    public function testCapabilityTokenRequest(): void
    {
        $agent = $this->makeUser(['role' => 'cx']);
        $seen = null;
        $GLOBALS['csHttpFake'] = function ($m, $url, $headers, $body) use (&$seen) {
            $seen = compact('m', 'url', 'headers', 'body');
            return ['status' => 201, 'body' => json_encode(['token' => 'ATCAPtkn_x', 'lifeTimeSec' => '43200']), 'error' => ''];
        };
        $r = csVoiceToken($agent, $this->settings());
        $this->assertTrue($r['ok']);
        $this->assertSame('ATCAPtkn_x', $r['token']);
        $this->assertSame('https://webrtc.africastalking.com/capability-token/request', $seen['url']);
        $this->assertContains('apiKey: k', $seen['headers']);
        $payload = json_decode($seen['body'], true);
        $this->assertSame(csAgentClientName($agent['id']), $payload['clientName']);
        $this->assertMatchesRegularExpression('/^[a-z0-9]+$/', $payload['clientName']);
    }

    public function testRecordingsAreDownloadedThenPurgedAfterRetention(): void
    {
        $s = $this->settings(); $sid = $this->sid();
        csHandleVoiceCallback($this->inbound($sid), 'answer', $s);
        csHandleVoiceCallback(['sessionId' => $sid, 'isActive' => '0', 'durationInSeconds' => '5', 'recordingUrl' => 'https://rec.example/2.mp3'], 'answer', $s);
        $GLOBALS['csHttpFake'] = fn() => ['status' => 200, 'body' => 'ID3fake-audio', 'error' => ''];
        $this->assertGreaterThanOrEqual(1, csFetchPendingRecordings());
        $call = dbFetch("SELECT * FROM cs_calls WHERE session_id = ?", [$sid]);
        $file = CS_RECORDINGS_DIR . '/' . $call['recording_path'];
        $this->assertFileExists($file);

        dbRun("UPDATE cs_calls SET started_at = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 31 * 86400), $call['id']]);
        $this->assertGreaterThanOrEqual(1, csPurgeOldRecordings(30));
        $this->assertFileDoesNotExist($file);
        $after = dbFetch("SELECT recording_path, recording_url, recording_deleted_at FROM cs_calls WHERE id = ?", [$call['id']]);
        $this->assertNull($after['recording_path']);
        $this->assertNull($after['recording_url']);
        $this->assertNotNull($after['recording_deleted_at']);
    }

    public function testLoggedCallUsesTheCallTalkTime(): void
    {
        $s = $this->settings(); $sid = $this->sid();
        $agent = $this->makeUser(['role' => 'cx']);
        csHandleVoiceCallback($this->inbound($sid), 'answer', $s);
        csMarkCallAnswered('+2348035550101', $agent['id']);
        csHandleVoiceCallback(['sessionId' => $sid, 'isActive' => '0', 'durationInSeconds' => '188'], 'answer', $s);
        $call = dbFetch("SELECT id FROM cs_calls WHERE session_id = ?", [$sid]);
        $res = csLogInteraction(['call_id' => $call['id'], 'contact_phone' => '+2348035550101', 'channel' => 'call',
            'wrap_code_id' => dbFetch("SELECT id FROM cs_wrap_codes WHERE active = 1 LIMIT 1")['id'], 'summary' => 'x', 'outcome' => 'resolved'], $agent);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $row = dbFetch("SELECT duration_sec, call_id FROM cs_interactions WHERE id = ?", [$res['id']]);
        $this->assertSame(188, (int)$row['duration_sec']);
        $this->assertSame($call['id'], $row['call_id']);
    }

    public function testManagerRoleAndSecretsStayServerSide(): void
    {
        $perms = array_column(dbFetchAll("SELECT permission FROM role_permissions WHERE role = 'cx_manager'"), 'permission');
        foreach (['support.view', 'support.view_all', 'support.manage', 'reports.view'] as $p) $this->assertContains($p, $perms);
        $src = file_get_contents(__DIR__ . '/../../api/app-config.php');
        foreach (['atApiKey', 'voiceWebhookSecret', 'metaWaAccessToken', 'metaWaAppSecret'] as $k) {
            $this->assertMatchesRegularExpression("/WRITE_ONLY_KEYS = \\[[^\\]]*'$k'/", $src);
        }
        $this->assertNotEmpty(getAppConfig()['voiceWebhookSecret'] ?? dbFetch("SELECT value FROM app_config WHERE " . dbKey() . " = 'voiceWebhookSecret'")['value'] ?? '');
    }

    public function testIvrOptionParsingAndE164(): void
    {
        $this->assertSame(['1' => 'Tech', '3' => 'Sales team'], csParseIvrOptions("1=Tech\nnonsense\n3: Sales team\n"));
        $this->assertSame('+2348030000000', csE164('0803 000 0000'));
        $this->assertSame('+2348030000000', csE164('2348030000000'));
        $this->assertSame('+447700900000', csE164('+44 7700 900000'));
    }
}
