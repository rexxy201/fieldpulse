<?php

/** Customer Support Phase B: SMS and email inbox, ticket updates to customers, customer timeline. */
final class SupportInboxTest extends TestCase
{
    private array $rows = [];
    private array $addresses = [];

    protected function tearDown(): void
    {
        unset($GLOBALS['csHttpFake'], $GLOBALS['sendEmailFake'], $GLOBALS['csNoticeClock']);
        foreach ($this->addresses as [$ch, $addr]) {
            if ($t = dbFetch("SELECT id FROM cs_threads WHERE channel = ? AND address = ?", [$ch, $addr])) {
                dbRun("DELETE FROM cs_thread_messages WHERE thread_id = ?", [$t['id']]);
                dbRun("DELETE FROM cs_threads WHERE id = ?", [$t['id']]);
            }
        }
        foreach (array_reverse($this->rows) as [$table, $col, $id]) dbRun("DELETE FROM $table WHERE $col = ?", [$id]);
        parent::tearDown();
    }

    private function settings(array $over = []): array
    {
        return array_merge(csInboxSettings(), [
            'at_username' => 'mangonet', 'at_api_key' => 'k', 'sms_enabled' => true, 'sms_sender' => 'MANGONET', 'sms_secret' => 'sec',
            'sms_auto_reply' => '', 'email_enabled' => true, 'email_address' => 'support@mangonet.test',
            'notify_enabled' => true, 'notify_since' => date('Y-m-d H:i:s', time() - 3600), 'notify_channel' => 'sms',
            'notify_events' => ['created', 'assigned', 'resolved'], 'templates' => CS_NOTICE_DEFAULTS, 'hours' => [],
        ], $over);
    }

    private function phone(): string
    {
        $p = '234803' . random_int(1000000, 9999999);
        $this->addresses[] = ['sms', $p];
        return $p;
    }

    private function email(): string
    {
        $e = 'cust' . bin2hex(random_bytes(4)) . '@example.test';
        $this->addresses[] = ['email', $e];
        return $e;
    }

    private function customer(string $phone, string $email = ''): string
    {
        $id = newUuid();
        dbRun("INSERT INTO customers (id,name,phone,email,account_number,status) VALUES (?,?,?,?,?,'active')",
            [$id, 'Ada Obi', '0' . substr($phone, 3), $email, 'ACC-' . substr($id, 0, 6)]);
        $this->rows[] = ['customers', 'id', $id];
        return $id;
    }

    /** Fake Africa's Talking SMS API; records each request. */
    private function fakeSms(array &$sent, int $code = 101): void
    {
        $GLOBALS['csHttpFake'] = function ($m, $url, $h, $body) use (&$sent, $code) {
            parse_str((string)$body, $q);
            $sent[] = ['url' => $url, 'q' => $q, 'headers' => $h];
            return ['status' => 201, 'error' => '', 'body' => json_encode(['SMSMessageData' => ['Message' => 'Sent to 1/1',
                'Recipients' => [['statusCode' => $code, 'number' => $q['to'] ?? '', 'status' => $code === 101 ? 'Success' : 'InvalidPhoneNumber', 'messageId' => 'ATXid_' . count($sent)]]]])];
        };
    }

    public function testInboundSmsIsFiledOnceAndMatchedToTheCustomer(): void
    {
        $phone = $this->phone();
        $cust = $this->customer($phone);
        $s = $this->settings(['sms_auto_reply' => 'Thanks, an agent will reply shortly.']);
        $sent = []; $this->fakeSms($sent);

        $r = csSmsHandleWebhook(['from' => '+' . $phone, 'to' => '12345', 'text' => 'My internet is down', 'id' => 'in-' . $phone], $s);
        $this->assertSame('message', $r['type']);
        csSmsHandleWebhook(['from' => '+' . $phone, 'text' => 'My internet is down', 'id' => 'in-' . $phone], $s);   // duplicate delivery
        $t = dbFetch("SELECT * FROM cs_threads WHERE channel = 'sms' AND address = ?", [$phone]);
        $this->assertSame($cust, $t['customer_id']);
        $this->assertSame(1, (int)$t['unread']);
        $msgs = dbFetchAll("SELECT direction, body, agent_name FROM cs_thread_messages WHERE thread_id = ? ORDER BY created_at, direction", [$t['id']]);
        $this->assertCount(2, $msgs, 'one inbound + one auto-reply');
        $this->assertCount(1, $sent);
        $this->assertSame('https://api.africastalking.com/version1/messaging', $sent[0]['url']);
        $this->assertSame('+' . $phone, $sent[0]['q']['to']);
        $this->assertSame('MANGONET', $sent[0]['q']['from']);

        // Delivery report.
        csSmsHandleWebhook(['id' => 'ATXid_1', 'status' => 'Success', 'phoneNumber' => '+' . $phone], $s);
        $this->assertSame('delivered', dbFetch("SELECT status FROM cs_thread_messages WHERE provider_message_id = 'ATXid_1'")['status']);

        // Closed thread reopens unassigned; no second auto-reply while open.
        $agent = $this->makeUser(['role' => 'cx']);
        dbRun("UPDATE cs_threads SET status = 'closed', assigned_to = ? WHERE id = ?", [$agent['id'], $t['id']]);
        csSmsHandleWebhook(['from' => '+' . $phone, 'text' => 'Still down', 'id' => 'in2-' . $phone], $s);
        $t = dbFetch("SELECT * FROM cs_threads WHERE id = ?", [$t['id']]);
        $this->assertSame('open', $t['status']);
        $this->assertNull($t['assigned_to']);
        csSmsHandleWebhook(['from' => '+' . $phone, 'text' => 'Hello?', 'id' => 'in3-' . $phone], $s);
        $this->assertCount(2, $sent, 'auto-reply only when a conversation starts');
    }

    public function testAgentSmsReplyAndProviderRejection(): void
    {
        $phone = $this->phone();
        $s = $this->settings();
        $agent = $this->makeUser(['role' => 'cx']);
        $t = csThreadFor('sms', $phone);
        $sent = []; $this->fakeSms($sent);
        $this->assertTrue(csThreadSend($t, 'We are on it.', $agent, $s)['ok']);
        $this->assertSame($agent['id'], dbFetch("SELECT assigned_to FROM cs_threads WHERE id = ?", [$t['id']])['assigned_to'], 'replying takes the thread');

        $sent = []; $this->fakeSms($sent, 403);
        $r = csThreadSend($t, 'Second try', $agent, $s);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('InvalidPhoneNumber', $r['error']);
        $this->assertSame('failed', dbFetch("SELECT status FROM cs_thread_messages WHERE thread_id = ? AND body = 'Second try'", [$t['id']])['status']);
        $this->assertFalse(csThreadSend($t, str_repeat('x', 919), $agent, $s)['ok']);
        $this->assertFalse(csSmsSendRaw('+2348030000000', 'x', $this->settings(['sms_enabled' => false]))['ok']);
    }

    public function testEmailIsParsedFiledAndRepliedToInThread(): void
    {
        $from = $this->email();
        $cust = $this->customer($this->phone(), strtoupper($from));
        $raw = "Return-Path: <$from>\r\nFrom: =?UTF-8?B?QWRhIE9iacOp?= <$from>\r\nTo: support@mangonet.test\r\n"
             . "Subject: =?UTF-8?Q?Slow_internet_=E2=80=94_help?=\r\nMessage-ID: <abc123@mail.example.test>\r\nMIME-Version: 1.0\r\n"
             . "Content-Type: multipart/mixed; boundary=\"outer\"\r\n\r\n"
             . "--outer\r\nContent-Type: multipart/alternative; boundary=\"inner\"\r\n\r\n"
             . "--inner\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
             . "My speed is very slow since Monday.=0D=0AAccount ACC-1\r\n\r\nOn Mon, 1 Oct 2026 at 10:00, Support <support@mangonet.test> wrote:\r\n> earlier text\r\n"
             . "--inner\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>ignored html</p>\r\n--inner--\r\n"
             . "--outer\r\nContent-Type: image/png\r\nContent-Disposition: attachment; filename=\"speed.png\"\r\nContent-Transfer-Encoding: base64\r\n\r\niVBORw0KGgo=\r\n--outer--\r\n";
        $m = csParseRawEmail($raw);
        $this->assertSame($from, $m['from_email']);
        $this->assertSame('Ada Obié', $m['from_name']);
        $this->assertSame('Slow internet — help', $m['subject']);
        $this->assertStringContainsString('My speed is very slow since Monday.', $m['body']);
        $this->assertStringNotContainsString('earlier text', $m['body'], 'quoted reply removed');
        $this->assertStringContainsString('1 attachment not shown', $m['body']);
        $this->assertFalse($m['auto']);

        $s = $this->settings();
        $tid = csEmailReceive($raw, $s);
        $this->assertNotNull($tid);
        $this->assertNull(csEmailReceive($raw, $s), 'same Message-ID is a duplicate');
        $t = dbFetch("SELECT * FROM cs_threads WHERE id = ?", [$tid]);
        $this->assertSame($cust, $t['customer_id'], 'matched by email, case-insensitive');
        $this->assertSame('Slow internet — help', $t['subject']);

        $mail = null;
        $GLOBALS['sendEmailFake'] = function ($to, $name, $subject, $html, $text, $opts) use (&$mail) { $mail = compact('to', 'subject', 'text', 'opts'); return true; };
        $agent = $this->makeUser(['role' => 'cx']);
        $this->assertTrue(csThreadSend($t, 'Please restart your router.', $agent, $s)['ok']);
        $this->assertSame($from, $mail['to']);
        $this->assertSame('Re: Slow internet — help', $mail['subject']);
        $this->assertSame('support@mangonet.test', $mail['opts']['replyTo']);
        $this->assertSame('<abc123@mail.example.test>', $mail['opts']['inReplyTo']);
        $this->assertStringEndsWith('@mangonet.test>', $mail['opts']['messageId']);
    }

    public function testAutoRepliesBouncesAndOurOwnMailAreIgnored(): void
    {
        $s = $this->settings();
        $from = $this->email();
        $base = "From: $from\r\nSubject: Out of office\r\nMessage-ID: <" . bin2hex(random_bytes(4)) . "@x>\r\n";
        $this->assertNull(csEmailReceive($base . "Auto-Submitted: auto-replied\r\n\r\nI am away.", $s));
        $this->assertNull(csEmailReceive("From: MAILER-DAEMON@mail.test\r\nSubject: Undelivered\r\n\r\nbounce", $s));
        $this->assertNull(csEmailReceive("From: support@mangonet.test\r\nSubject: loop\r\n\r\nx", $s));
        $this->assertNull(dbFetch("SELECT id FROM cs_threads WHERE channel = 'email' AND address = ?", [$from]));
        // Plain single-part HTML only.
        $m = csParseRawEmail("From: A <$from>\r\nContent-Type: text/html; charset=ISO-8859-1\r\n\r\n<div>Caf\xe9<br>line 2</div><style>p{}</style>");
        $this->assertSame("Café\nline 2", $m['body']);
    }

    public function testTicketUpdatesGoOutOncePerStageAndNeverBackwards(): void
    {
        $GLOBALS['csNoticeClock'] = strtotime('today 10:00');
        $phone = $this->phone();
        $cust = $this->customer($phone);
        $eng = $this->makeUser(['role' => 'engineer', 'name' => 'Musa Engineer']);
        $tid = newUuid();
        dbRun("INSERT INTO tickets (id, ticket_number, customer_id, status, priority, created_at) VALUES (?,?,?,?,?,?)", [$tid, 'TKT-901', $cust, 'open', 'medium', date('Y-m-d H:i:s')]);
        $this->rows[] = ['cs_ticket_notices', 'ticket_id', $tid];
        $this->rows[] = ['tickets', 'id', $tid];
        $s = $this->settings();
        $sent = []; $this->fakeSms($sent);

        $this->assertSame(1, csTicketNoticeSweep([$tid], 48, $s));
        $this->assertSame('Hi Ada, we have received your fault report TKT-901. We will keep you updated.', $sent[0]['q']['message']);
        $this->assertSame(0, csTicketNoticeSweep([$tid], 48, $s), 'not repeated');

        dbRun("UPDATE tickets SET status = 'in_progress', assigned_to = ? WHERE id = ?", [$eng['id'], $tid]);
        $s['templates']['assigned'] = '{name}: {engineer} is on {ticket}.';
        $this->assertSame(1, csTicketNoticeSweep([$tid], 48, $s));
        $this->assertSame('Ada: Musa Engineer is on TKT-901.', $sent[1]['q']['message']);

        dbRun("UPDATE tickets SET status = 'open' WHERE id = ?", [$tid]);
        $this->assertSame(0, csTicketNoticeSweep([$tid], 48, $s), 'never backwards');

        dbRun("UPDATE tickets SET status = 'resolved' WHERE id = ?", [$tid]);
        $GLOBALS['csNoticeClock'] = strtotime('today 22:30');
        $this->assertSame(0, csTicketNoticeSweep([$tid], 48, $s), 'held overnight');
        $GLOBALS['csNoticeClock'] = strtotime('today 07:05');
        $this->assertSame(1, csTicketNoticeSweep(null, 48, $s), 'the cron sweep finds it in the morning');
        $this->assertStringContainsString('has been resolved', $sent[2]['q']['message']);

        // The update is filed in the customer's SMS thread, which stays closed until they reply.
        $t = dbFetch("SELECT * FROM cs_threads WHERE channel = 'sms' AND address = ?", [$phone]);
        $this->assertSame('closed', $t['status']);
        $this->assertSame(3, (int)dbFetch("SELECT COUNT(*) AS n FROM cs_thread_messages WHERE thread_id = ? AND agent_name = 'Ticket update'", [$t['id']])['n']);
        $this->assertSame(['created', 'assigned', 'resolved'], array_column(dbFetchAll("SELECT event FROM cs_ticket_notices WHERE ticket_id = ? ORDER BY created_at, event = 'resolved', event = 'assigned'", [$tid]), 'event'));
    }

    public function testOlderTicketsTurnedOffStagesAndFeatureOffSendNothing(): void
    {
        $GLOBALS['csNoticeClock'] = strtotime('today 12:00');
        $cust = $this->customer($this->phone());
        $old = newUuid(); $new = newUuid();
        dbRun("INSERT INTO tickets (id, ticket_number, customer_id, status, priority, created_at) VALUES (?,?,?,?,?,?)", [$old, 'TKT-OLD', $cust, 'open', 'medium', date('Y-m-d H:i:s', time() - 86400)]);
        dbRun("INSERT INTO tickets (id, ticket_number, customer_id, status, priority, created_at) VALUES (?,?,?,?,?,?)", [$new, 'TKT-NEW', $cust, 'open', 'medium', date('Y-m-d H:i:s')]);
        foreach ([$old, $new] as $id) { $this->rows[] = ['cs_ticket_notices', 'ticket_id', $id]; $this->rows[] = ['tickets', 'id', $id]; }
        $sent = []; $this->fakeSms($sent);

        $this->assertSame(0, csTicketNoticeSweep([$old, $new], 48, $this->settings(['notify_enabled' => false])));
        $s = $this->settings(['notify_events' => ['resolved']]);
        $this->assertSame(0, csTicketNoticeSweep([$old, $new], 48, $s), 'created stage turned off; old ticket predates the feature');
        $this->assertSame('skipped', dbFetch("SELECT status FROM cs_ticket_notices WHERE ticket_id = ?", [$new])['status']);
        $this->assertNull(dbFetch("SELECT id FROM cs_ticket_notices WHERE ticket_id = ?", [$old]));
        dbRun("UPDATE tickets SET status = 'resolved' WHERE id = ?", [$new]);
        $this->assertSame(1, csTicketNoticeSweep([$new], 48, $s));
        $this->assertCount(1, $sent);
    }

    public function testTimelineMergesEveryChannelNewestFirst(): void
    {
        $phone = $this->phone();
        $cust = $this->customer($phone);
        $tid = newUuid();
        dbRun("INSERT INTO tickets (id, ticket_number, customer_id, status, priority, description, created_at) VALUES (?,?,?,?,?,?,?)",
            [$tid, 'TKT-TL', $cust, 'open', 'medium', 'No light on ONT', date('Y-m-d H:i:s', time() - 7200)]);
        $this->rows[] = ['tickets', 'id', $tid];
        $t = csThreadFor('sms', $phone);
        dbRun("INSERT INTO cs_thread_messages (id,thread_id,direction,body,status,created_at) VALUES (?,?,'in','Any update?','received',?)", [newUuid(), $t['id'], date('Y-m-d H:i:s', time() - 60)]);
        $rows = csCustomerTimeline($cust);
        $this->assertSame('sms', $rows[0]['kind']);
        $this->assertSame('Any update?', $rows[0]['body']);
        $this->assertSame('Ticket TKT-TL opened', end($rows)['title']);
        $this->assertStringStartsWith('/support/inbox?t=', $rows[0]['link']);
    }

    public function testEndpointsAndPageAreWiredAndGuarded(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertStringContainsString("'inbox' => 'inbox'", file_get_contents("$root/index.php"));
        $this->assertStringContainsString("requirePermission('support.view')", file_get_contents("$root/pages/support/inbox.php"));
        $this->assertStringContainsString("hash_equals(\$s['sms_secret']", file_get_contents("$root/api/sms-webhook.php"));
        $this->assertStringContainsString('csTicketNoticeSweep()', file_get_contents("$root/api/support-housekeeping.php"));
        $this->assertTrue(is_executable("$root/scripts/email-inbound.php"), 'cPanel pipes need the execute bit');
        $this->assertStringStartsWith('#!/usr/local/bin/php -q', file_get_contents("$root/scripts/email-inbound.php"));
    }
}
