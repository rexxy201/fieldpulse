<?php

/** Customer Support WhatsApp: webhook parsing, conversations, 24h window, sending. */
final class SupportWhatsAppTest extends TestCase
{
    private array $phones = [];

    protected function tearDown(): void
    {
        unset($GLOBALS['csHttpFake']);
        foreach ($this->phones as $p) {
            $c = dbFetch("SELECT id FROM cs_wa_conversations WHERE wa_phone = ?", [$p]);
            if ($c) {
                dbRun("DELETE FROM cs_wa_messages WHERE conversation_id = ?", [$c['id']]);
                dbRun("DELETE FROM cs_interactions WHERE wa_conversation_id = ?", [$c['id']]);
                dbRun("DELETE FROM cs_wa_conversations WHERE id = ?", [$c['id']]);
            }
        }
        parent::tearDown();
    }

    private function settings(array $over = []): array
    {
        return array_merge([
            'provider' => 'africastalking', 'at_username' => 'mangonet', 'at_api_key' => 'k', 'at_wa_number' => '+2347000000009',
            'meta_phone_id' => '', 'meta_token' => '', 'meta_app_secret' => '', 'meta_verify' => 'v', 'meta_template' => '',
            'meta_template_lang' => 'en', 'webhook_secret' => 'sec', 'auto_reply' => '',
        ], $over);
    }

    private function phone(): string
    {
        return $this->phones[] = '23480' . random_int(10000000, 99999999);
    }

    private function metaPayload(string $from, string $id, string $text): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Ada']]],
            'messages' => [['from' => $from, 'id' => $id, 'timestamp' => (string)time(), 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]];
    }

    private function fakeHttp(int $status, array $body, ?array &$captured = null): void
    {
        $GLOBALS['csHttpFake'] = function ($m, $url, $headers, $b) use ($status, $body, &$captured) {
            $captured = ['url' => $url, 'headers' => $headers, 'body' => json_decode((string)$b, true)];
            return ['status' => $status, 'body' => json_encode($body), 'error' => ''];
        };
    }

    public function testMetaWebhookIsParsed(): void
    {
        $p = csWaParseWebhook($this->metaPayload('2348031112222', 'wamid.1', 'My internet is down'));
        $this->assertSame([['from' => '2348031112222', 'name' => 'Ada', 'id' => 'wamid.1', 'body' => 'My internet is down', 'type' => 'text']],
            array_map(fn($m) => array_diff_key($m, ['ts' => 1]), $p['messages']));
        $s = csWaParseWebhook(['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.9', 'status' => 'read']]]]]]]]);
        $this->assertSame('read', $s['statuses'][0]['status']);
    }

    public function testAfricasTalkingWebhookParsingIsTolerant(): void
    {
        $p = csWaParseWebhook(['From' => '+2348031112222', 'body' => ['message' => 'Hello'], 'messageId' => 'at-1']);
        $this->assertSame('Hello', $p['messages'][0]['body']);
        $this->assertSame('at-1', $p['messages'][0]['id']);
        $st = csWaParseWebhook(['id' => 'at-2', 'status' => 'Delivered']);
        $this->assertSame([], $st['messages']);
        $this->assertSame('delivered', $st['statuses'][0]['status']);
    }

    public function testMetaSignatureCheck(): void
    {
        $body = '{"a":1}';
        $this->assertTrue(csWaMetaSignatureValid($body, 'sha256=' . hash_hmac('sha256', $body, 'appsecret'), 'appsecret'));
        $this->assertFalse(csWaMetaSignatureValid($body, 'sha256=' . hash_hmac('sha256', $body, 'other'), 'appsecret'));
        $this->assertFalse(csWaMetaSignatureValid($body, '', 'appsecret'));
        $this->assertFalse(csWaMetaSignatureValid($body, 'sha256=x', ''), 'no app secret configured: reject');
    }

    public function testInboundMessagesThreadIntoOneConversationAndDuplicatesAreIgnored(): void
    {
        $s = $this->settings(); $ph = $this->phone();
        $r1 = csWaHandleWebhook($this->metaPayload($ph, 'wamid.a' . $ph, 'Hi'), $s);
        csWaHandleWebhook($this->metaPayload($ph, 'wamid.a' . $ph, 'Hi'), $s);          // provider retry
        csWaHandleWebhook($this->metaPayload($ph, 'wamid.b' . $ph, 'Still down'), $s);
        $this->assertSame(1, $r1['messages']);
        $conv = dbFetch("SELECT * FROM cs_wa_conversations WHERE wa_phone = ?", [$ph]);
        $this->assertSame('open', $conv['status']);
        $this->assertSame(2, (int)$conv['unread']);
        $this->assertSame('Ada', $conv['contact_name']);
        $this->assertSame(2, (int)dbFetch("SELECT COUNT(*) AS n FROM cs_wa_messages WHERE conversation_id = ?", [$conv['id']])['n']);
    }

    public function testClosedConversationReopensUnassigned(): void
    {
        $s = $this->settings(); $ph = $this->phone();
        $agent = $this->makeUser(['role' => 'cx']);
        $id = csWaReceive(['from' => $ph, 'name' => '', 'id' => 'x1' . $ph, 'body' => 'Hi', 'type' => 'text', 'ts' => time()], $s);
        dbRun("UPDATE cs_wa_conversations SET status = 'closed', assigned_to = ? WHERE id = ?", [$agent['id'], $id]);
        csWaReceive(['from' => $ph, 'name' => '', 'id' => 'x2' . $ph, 'body' => 'Again', 'type' => 'text', 'ts' => time()], $s);
        $c = dbFetch("SELECT * FROM cs_wa_conversations WHERE id = ?", [$id]);
        $this->assertSame('open', $c['status']);
        $this->assertNull($c['assigned_to']);
    }

    public function testReplyViaAfricasTalkingAssignsTheAgentAndTracksDelivery(): void
    {
        $s = $this->settings(); $ph = $this->phone();
        $agent = $this->makeUser(['role' => 'cx']);
        $id = csWaReceive(['from' => $ph, 'name' => '', 'id' => 'y1' . $ph, 'body' => 'Hi', 'type' => 'text', 'ts' => time()], $s);
        $this->fakeHttp(201, ['messageId' => 'ATX' . $ph], $req);
        $r = csWaSend(dbFetch("SELECT * FROM cs_wa_conversations WHERE id = ?", [$id]), 'We are on it', $agent, false, $s);
        $this->assertTrue($r['ok']);
        $this->assertSame('https://chat.africastalking.com/whatsapp/message/send', $req['url']);
        $this->assertSame(['username' => 'mangonet', 'waNumber' => '+2347000000009', 'phoneNumber' => '+' . $ph, 'body' => ['message' => 'We are on it']], $req['body']);
        $this->assertSame($agent['id'], dbFetch("SELECT assigned_to FROM cs_wa_conversations WHERE id = ?", [$id])['assigned_to']);

        csWaApplyStatus(['id' => 'ATX' . $ph, 'status' => 'read', 'error' => '']);
        csWaApplyStatus(['id' => 'ATX' . $ph, 'status' => 'delivered', 'error' => '']);   // late, out of order
        $this->assertSame('read', dbFetch("SELECT status FROM cs_wa_messages WHERE provider_message_id = ?", ['ATX' . $ph])['status']);
    }

    public function testTwentyFourHourWindowBlocksFreeTextButAllowsMetaTemplate(): void
    {
        $s = $this->settings(['provider' => 'meta', 'meta_phone_id' => '123', 'meta_token' => 't', 'meta_app_secret' => 'a', 'meta_template' => 'follow_up']);
        $ph = $this->phone();
        $id = csWaReceive(['from' => $ph, 'name' => '', 'id' => 'z1' . $ph, 'body' => 'Hi', 'type' => 'text', 'ts' => time()], $s);
        dbRun("UPDATE cs_wa_conversations SET last_inbound_at = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 90000), $id]);
        $conv = dbFetch("SELECT * FROM cs_wa_conversations WHERE id = ?", [$id]);
        $this->assertFalse(csWaWindowOpen($conv));

        $this->fakeHttp(200, ['messages' => [['id' => 'wamid.t' . $ph]]], $req);
        $blocked = csWaSend($conv, 'Hello again', null, false, $s);
        $this->assertFalse($blocked['ok']);
        $this->assertNull($req, 'nothing sent to the provider');

        $this->assertTrue(csWaSend($conv, '', null, true, $s)['ok']);
        $this->assertSame('https://graph.facebook.com/v21.0/123/messages', $req['url']);
        $this->assertContains('Authorization: Bearer t', $req['headers']);
        $this->assertSame(['name' => 'follow_up', 'language' => ['code' => 'en']], $req['body']['template']);
    }

    public function testFailedSendIsStoredWithTheProviderError(): void
    {
        $s = $this->settings(); $ph = $this->phone();
        $id = csWaReceive(['from' => $ph, 'name' => '', 'id' => 'f1' . $ph, 'body' => 'Hi', 'type' => 'text', 'ts' => time()], $s);
        $this->fakeHttp(400, ['errorMessage' => 'Invalid waNumber']);
        $r = csWaSend(dbFetch("SELECT * FROM cs_wa_conversations WHERE id = ?", [$id]), 'Hello', null, false, $s);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Invalid waNumber', $r['error']);
        $m = dbFetch("SELECT status, error FROM cs_wa_messages WHERE conversation_id = ? AND direction = 'out'", [$id]);
        $this->assertSame(['status' => 'failed', 'error' => 'Invalid waNumber'], $m);
    }

    public function testAutoReplyOnlyOnANewConversation(): void
    {
        $s = $this->settings(['auto_reply' => 'Thanks, an agent will reply shortly.']); $ph = $this->phone();
        $sent = 0;
        $GLOBALS['csHttpFake'] = function () use (&$sent) { $sent++; return ['status' => 201, 'body' => '{}', 'error' => '']; };
        csWaReceive(['from' => $ph, 'name' => '', 'id' => 'r1' . $ph, 'body' => 'Hi', 'type' => 'text', 'ts' => time()], $s);
        csWaReceive(['from' => $ph, 'name' => '', 'id' => 'r2' . $ph, 'body' => 'Hello?', 'type' => 'text', 'ts' => time()], $s);
        $this->assertSame(1, $sent);
    }

    public function testInteractionCanBeLinkedToAConversation(): void
    {
        $s = $this->settings(); $ph = $this->phone();
        $agent = $this->makeUser(['role' => 'cx']);
        $id = csWaReceive(['from' => $ph, 'name' => 'Bola', 'id' => 'i1' . $ph, 'body' => 'Bill?', 'type' => 'text', 'ts' => time()], $s);
        $wrap = dbFetch("SELECT id FROM cs_wrap_codes WHERE active = 1 LIMIT 1");
        $r = csLogInteraction(['channel' => 'whatsapp', 'outcome' => 'resolved', 'contact_phone' => '+' . $ph, 'wrap_code_id' => $wrap['id'],
                               'summary' => 'Explained bill', 'wa_conversation_id' => $id], $agent);
        $this->assertTrue($r['ok']);
        $this->assertSame($id, dbFetch("SELECT wa_conversation_id FROM cs_interactions WHERE id = ?", [$r['id']])['wa_conversation_id']);
    }

    public function testWebhookSecretsAreWriteOnly(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/api/app-config.php');
        foreach (['waWebhookSecret', 'metaWaAccessToken', 'metaWaAppSecret'] as $k) $this->assertStringContainsString("'$k'", $src);
    }
}
