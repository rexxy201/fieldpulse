<?php
/**
 * Customer Support — SMS and email inbox, automatic ticket updates to
 * customers, and the per-customer timeline across every channel.
 *
 *  - SMS (Africa's Talking, same username/API key as voice): customers text
 *    the support number or shortcode; api/sms-webhook.php files each message
 *    in a thread per number; agents reply from /support/inbox.
 *  - Email: mail to the support address is piped into scripts/email-inbound.php
 *    (cPanel forwarder "Pipe to a Program"), filed in a thread per sender, and
 *    answered through the normal SMTP settings with Reply-To set back to the
 *    support address so the conversation stays in the inbox.
 *  - Ticket updates: when a customer's ticket is logged, gets an engineer, or
 *    is resolved, the customer gets one SMS (or WhatsApp while a chat is open).
 *    csTicketNoticeSweep() works out what is due from the ticket's current
 *    status, so it is safe to call after any save and from cron.
 */

const CS_THREAD_CHANNELS = ['sms' => 'SMS', 'email' => 'Email'];
const CS_NOTICE_RANK     = ['created' => 1, 'assigned' => 2, 'resolved' => 3];
const CS_NOTICE_FOR_STATUS = ['new' => 'created', 'open' => 'created', 'assigned' => 'assigned', 'in_progress' => 'assigned',
                              'pending_confirmation' => 'resolved', 'resolved' => 'resolved'];
const CS_NOTICE_DEFAULTS = [
    'created'  => 'Hi {name}, we have received your fault report {ticket}. We will keep you updated.',
    'assigned' => 'Hi {name}, an engineer has been assigned to your fault report {ticket}.',
    'resolved' => 'Hi {name}, your fault report {ticket} has been resolved. If you still have a problem, reply to this message or call us.',
];

function csInboxSettings(): array {
    $c = getAppConfig();
    $tpl = [];
    foreach (CS_NOTICE_DEFAULTS as $ev => $def) $tpl[$ev] = trim((string)($c['ticketNotice' . ucfirst($ev)] ?? '')) ?: $def;
    return [
        'at_username'    => trim((string)($c['atUsername'] ?? '')),
        'at_api_key'     => (string)($c['atApiKey'] ?? ''),
        'sms_enabled'    => ($c['smsEnabled'] ?? '0') === '1',
        'sms_sender'     => trim((string)($c['atSmsSender'] ?? '')),
        'sms_secret'     => (string)($c['smsWebhookSecret'] ?? ''),
        'sms_auto_reply' => trim((string)($c['smsAutoReply'] ?? '')),
        'email_enabled'  => ($c['emailInboxEnabled'] ?? '0') === '1',
        'email_address'  => strtolower(trim((string)($c['supportEmailAddress'] ?? ''))),
        'notify_enabled' => ($c['ticketNotifyEnabled'] ?? '0') === '1',
        'notify_since'   => (string)($c['ticketNotifyEnabledAt'] ?? ''),
        'notify_channel' => ($c['ticketNotifyChannel'] ?? 'sms') === 'whatsapp_first' ? 'whatsapp_first' : 'sms',
        'notify_events'  => array_values(array_intersect(array_keys(CS_NOTICE_RANK), explode(',', (string)($c['ticketNotifyEvents'] ?? 'created,assigned,resolved')))),
        'templates'      => $tpl,
        'hours'          => csParseSupportHours((string)($c['supportHours'] ?? '')),
    ];
}

function csSmsEnabled(?array $s = null): bool {
    $s ??= csInboxSettings();
    return $s['sms_enabled'] && $s['at_username'] !== '' && $s['at_api_key'] !== '' && $s['sms_secret'] !== '';
}

function csSmsWebhookUrl(?array $s = null): string {
    $s ??= csInboxSettings();
    return siteBaseUrl() . '/api/sms-webhook?k=' . rawurlencode($s['sms_secret']);
}

/** Thread address for a channel: bare international digits for SMS, lower-case address for email. */
function csThreadAddress(string $channel, string $raw): string {
    if ($channel === 'sms') return ltrim(csE164($raw), '+');
    $raw = strtolower(trim($raw));
    return filter_var($raw, FILTER_VALIDATE_EMAIL) ? $raw : '';
}

function csThreadCustomer(string $channel, string $address): ?array {
    if ($channel === 'sms') return csFindCustomerByPhone($address);
    return dbFetch("SELECT id, name FROM customers WHERE LOWER(email) = ? LIMIT 1", [$address]);
}

/** Finds or creates the thread for an address. New threads start $status ('open' for inbound, 'closed' for our own first message). */
function csThreadFor(string $channel, string $address, string $name = '', string $subject = '', string $status = 'open'): array {
    $t = dbFetch("SELECT * FROM cs_threads WHERE channel = ? AND address = ?", [$channel, $address]);
    if ($t) return $t;
    $cust = csThreadCustomer($channel, $address);
    $id   = newUuid();
    $now  = date('Y-m-d H:i:s');
    dbRun("INSERT INTO cs_threads (id,channel,address,contact_name,customer_id,subject,status,unread,last_message_at,created_at) VALUES (?,?,?,?,?,?,?,0,?,?)",
        [$id, $channel, $address, mb_substr($name, 0, 150), $cust['id'] ?? null, mb_substr($subject, 0, 255), $status, $now, $now]);
    return dbFetch("SELECT * FROM cs_threads WHERE id = ?", [$id]);
}

/** Files one inbound SMS or email. Returns the thread id, or null for a duplicate / unusable message. */
function csThreadReceive(string $channel, string $from, string $name, string $subject, string $body, string $providerId, ?array $s = null): ?string {
    $s ??= csInboxSettings();
    $address = csThreadAddress($channel, $from);
    if ($address === '') return null;
    if ($providerId !== '' && dbFetch("SELECT id FROM cs_thread_messages WHERE provider_message_id = ? AND direction = 'in'", [mb_substr($providerId, 0, 255)])) return null;

    $existing = dbFetch("SELECT * FROM cs_threads WHERE channel = ? AND address = ?", [$channel, $address]);
    $fresh    = !$existing || $existing['status'] === 'closed';
    $t        = $existing ?: csThreadFor($channel, $address, $name, $subject);
    $now      = date('Y-m-d H:i:s');
    // A closed thread reopens unassigned so the next free agent picks it up
    // (assigned_to before status: MySQL applies SET clauses left to right).
    dbRun("UPDATE cs_threads SET assigned_to = CASE WHEN status = 'closed' THEN NULL ELSE assigned_to END,
              unread = unread + 1, last_inbound_at = ?, last_message_at = ?, status = 'open',
              subject = CASE WHEN ? <> '' THEN ? ELSE subject END,
              contact_name = CASE WHEN contact_name = '' THEN ? ELSE contact_name END WHERE id = ?",
        [$now, $now, mb_substr($subject, 0, 255), mb_substr($subject, 0, 255), mb_substr($name, 0, 150), $t['id']]);
    dbRun("INSERT INTO cs_thread_messages (id,thread_id,direction,provider_message_id,subject,body,status,created_at) VALUES (?,?,'in',?,?,?,'received',?)",
        [newUuid(), $t['id'], $providerId !== '' ? mb_substr($providerId, 0, 255) : null, mb_substr($subject, 0, 255), $body !== '' ? $body : '(empty message)', $now]);

    // SMS only: the closed-hours or normal auto-reply when a conversation starts. Never for email (mail loops).
    if ($channel === 'sms' && $fresh && csSmsEnabled($s)) {
        $closed = (string)(getAppConfig()['waClosedReply'] ?? '');
        $reply  = !csSupportOpen($s['hours']) && $closed !== '' ? $closed : $s['sms_auto_reply'];
        if ($reply !== '') csThreadSend(dbFetch("SELECT * FROM cs_threads WHERE id = ?", [$t['id']]), $reply, null, $s, '', 'Auto-reply');
    }
    return $t['id'];
}

/** Sends one SMS through Africa's Talking. Returns ['ok', 'id', 'error']. */
function csSmsSendRaw(string $to, string $text, ?array $s = null): array {
    $s ??= csInboxSettings();
    if (!csSmsEnabled($s)) return ['ok' => false, 'id' => '', 'error' => 'SMS is not configured.'];
    $to = csE164($to);
    if (!preg_match('/^\+\d{7,15}$/', $to)) return ['ok' => false, 'id' => '', 'error' => 'Invalid phone number.'];
    $f = ['username' => $s['at_username'], 'to' => $to, 'message' => $text];
    if ($s['sms_sender'] !== '') $f['from'] = $s['sms_sender'];
    $r = csHttp('POST', 'https://api.africastalking.com/version1/messaging',
        ['apiKey: ' . $s['at_api_key'], 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'], http_build_query($f));
    $d   = json_decode($r['body'], true) ?: [];
    $rec = $d['SMSMessageData']['Recipients'][0] ?? null;
    $code = (int)($rec['statusCode'] ?? 0);
    if ($r['status'] >= 200 && $r['status'] < 300 && $code >= 100 && $code <= 102) {
        return ['ok' => true, 'id' => (string)($rec['messageId'] ?? ''), 'error' => ''];
    }
    $err = (string)($rec['status'] ?? ($d['SMSMessageData']['Message'] ?? '')) ?: ($r['error'] !== '' ? $r['error'] : 'HTTP ' . $r['status']);
    error_log('SMS send failed: ' . mb_substr($err . ' ' . $r['body'], 0, 300));
    return ['ok' => false, 'id' => '', 'error' => mb_substr($err, 0, 255)];
}

/**
 * Sends a reply on a thread (SMS or email) and stores it. $agent is null for
 * automatic messages ($label names them). Returns ['ok' => bool, 'error' => …].
 */
function csThreadSend(array $t, string $text, ?array $agent, ?array $s = null, string $subject = '', string $label = ''): array {
    $s  ??= csInboxSettings();
    $text = trim($text);
    if ($text === '') return ['ok' => false, 'error' => 'Type a message.'];
    $pid = ''; $err = '';
    if ($t['channel'] === 'sms') {
        if (mb_strlen($text) > 918) return ['ok' => false, 'error' => 'SMS replies are limited to 918 characters (6 messages).'];
        $r = csSmsSendRaw($t['address'], $text, $s);
        $ok = $r['ok']; $pid = $r['id']; $err = $r['error'];
    } else {
        if (!$s['email_enabled'] || $s['email_address'] === '') return ['ok' => false, 'error' => 'The email inbox is not configured.'];
        $subject = trim($subject) !== '' ? trim($subject) : (preg_match('/^re:/i', (string)$t['subject']) ? (string)$t['subject'] : 'Re: ' . ($t['subject'] !== '' ? $t['subject'] : 'Your message'));
        $last = dbFetch("SELECT provider_message_id FROM cs_thread_messages WHERE thread_id = ? AND direction = 'in' AND provider_message_id IS NOT NULL ORDER BY created_at DESC LIMIT 1", [$t['id']]);
        $domain = substr(strrchr($s['email_address'], '@') ?: '@fieldpulse.local', 1);
        $pid  = '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
        $html = '<div style="font-family:sans-serif;font-size:14px">' . nl2br(htmlspecialchars($text)) . '</div>';
        try {
            $ok = sendEmail($t['address'], (string)$t['contact_name'], $subject, $html, $text, array_filter([
                'replyTo' => $s['email_address'], 'messageId' => $pid,
                'inReplyTo' => $last['provider_message_id'] ?? null, 'references' => $last['provider_message_id'] ?? null]));
        } catch (\Throwable $e) { $ok = false; $err = mb_substr($e->getMessage(), 0, 255); }
        if (!$ok && $err === '') $err = 'The mail server did not accept the message.';
    }
    $now = date('Y-m-d H:i:s');
    dbRun("INSERT INTO cs_thread_messages (id,thread_id,direction,provider_message_id,subject,body,status,error,agent_id,agent_name,created_at) VALUES (?,?,'out',?,?,?,?,?,?,?,?)",
        [newUuid(), $t['id'], $pid !== '' ? mb_substr($pid, 0, 255) : null, mb_substr($subject, 0, 255), $text, $ok ? 'sent' : 'failed', $ok ? null : $err,
         $agent['id'] ?? null, $agent ? mb_substr((string)$agent['name'], 0, 150) : ($label ?: 'System'), $now]);
    dbRun("UPDATE cs_threads SET last_message_at = ? WHERE id = ?", [$now, $t['id']]);
    if ($ok && $agent && empty($t['assigned_to'])) dbRun("UPDATE cs_threads SET assigned_to = ? WHERE id = ?", [$agent['id'], $t['id']]);
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => ($t['channel'] === 'sms' ? 'SMS not sent: ' : 'Email not sent: ') . $err];
}

/** Africa's Talking SMS callbacks: an incoming message (from/text/id) or a delivery report (id/status). */
function csSmsHandleWebhook(array $p, ?array $s = null): array {
    $s ??= csInboxSettings();
    $lc = array_change_key_case($p, CASE_LOWER);
    if (isset($lc['text']) && isset($lc['from'])) {
        $tid = csThreadReceive('sms', (string)$lc['from'], '', '', trim((string)$lc['text']), (string)($lc['id'] ?? ''), $s);
        return ['type' => 'message', 'thread' => $tid];
    }
    if (isset($lc['id'], $lc['status'])) {
        $st = strtolower((string)$lc['status']);
        $map = ['success' => 'delivered', 'sent' => 'sent', 'submitted' => 'sent', 'buffered' => 'sent',
                'failed' => 'failed', 'rejected' => 'failed', 'expired' => 'failed'];
        if (isset($map[$st])) {
            dbRun("UPDATE cs_thread_messages SET status = ?, error = ? WHERE provider_message_id = ? AND direction = 'out'",
                [$map[$st], $map[$st] === 'failed' ? mb_substr((string)($lc['failurereason'] ?? 'Not delivered'), 0, 255) : null, (string)$lc['id']]);
        }
        return ['type' => 'report'];
    }
    return ['type' => 'unknown'];
}

// ── Email parsing ────────────────────────────────────────────────────────────

/** Splits a raw message/part into [headers (lower-case name => value), body]. Folded headers are unfolded. */
function csMailSplit(string $raw): array {
    $raw = str_replace("\r\n", "\n", $raw);
    $pos = strpos($raw, "\n\n");
    $head = $pos === false ? $raw : substr($raw, 0, $pos);
    $body = $pos === false ? '' : substr($raw, $pos + 2);
    $headers = [];
    foreach (explode("\n", preg_replace("/\n[ \t]+/", ' ', $head)) as $line) {
        if (!str_contains($line, ':')) continue;
        [$k, $v] = explode(':', $line, 2);
        $k = strtolower(trim($k));
        if (!isset($headers[$k])) $headers[$k] = trim($v);   // first wins (Received etc. repeat)
    }
    return [$headers, $body];
}

function csMailDecodeHeader(string $v): string {
    $d = function_exists('iconv_mime_decode') ? @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : false;
    return trim($d !== false ? $d : mb_decode_mimeheader($v));
}

function csMailParam(string $header, string $name): string {
    return preg_match('/' . $name . '\s*=\s*"?([^";]+)"?/i', $header, $m) ? trim($m[1]) : '';
}

/** Decodes a leaf part's body to UTF-8 text. */
function csMailDecodeBody(array $h, string $body): string {
    $enc = strtolower($h['content-transfer-encoding'] ?? '');
    if ($enc === 'base64') $body = (string)base64_decode(preg_replace('/\s+/', '', $body));
    elseif ($enc === 'quoted-printable') $body = quoted_printable_decode($body);
    $cs = strtoupper(csMailParam($h['content-type'] ?? '', 'charset') ?: 'UTF-8');
    if ($cs !== 'UTF-8' && $cs !== 'US-ASCII') {
        $conv = @mb_convert_encoding($body, 'UTF-8', $cs);
        if (is_string($conv)) $body = $conv;
    }
    return mb_check_encoding($body, 'UTF-8') ? $body : mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
}

/** Walks a MIME tree collecting the first text/plain and text/html and counting attachments. */
function csMailWalk(array $h, string $body, array &$out, int $depth = 0): void {
    $type = strtolower(trim(explode(';', $h['content-type'] ?? 'text/plain')[0]));
    $disp = strtolower($h['content-disposition'] ?? '');
    if (str_starts_with($type, 'multipart/') && $depth < 5) {
        $b = csMailParam($h['content-type'] ?? '', 'boundary');
        if ($b === '') return;
        foreach (array_slice(explode('--' . $b, $body), 1) as $part) {
            if (str_starts_with($part, '--')) break;
            [$ph, $pb] = csMailSplit(ltrim($part, "\n"));
            csMailWalk($ph, $pb, $out, $depth + 1);
        }
        return;
    }
    if (str_starts_with($disp, 'attachment') || (!str_starts_with($type, 'text/'))) { $out['attachments']++; return; }
    if ($type === 'text/plain' && $out['text'] === null) $out['text'] = csMailDecodeBody($h, $body);
    elseif ($type === 'text/html' && $out['html'] === null) $out['html'] = csMailDecodeBody($h, $body);
}

/** Cuts the quoted earlier conversation from a reply ("On … wrote:", "-----Original Message-----", "> " lines). */
function csMailStripQuote(string $text): string {
    $lines = explode("\n", str_replace("\r\n", "\n", $text));
    $out = [];
    foreach ($lines as $i => $line) {
        $t = trim($line);
        if (preg_match('/^(On .{4,200} wrote:|-{2,}\s*Original Message\s*-{2,}|From: .+ Sent: .+)$/i', $t)) break;
        if ($t !== '' && str_starts_with($t, '>') && !array_filter(array_slice($lines, $i), fn($l) => trim($l) !== '' && !str_starts_with(trim($l), '>'))) break;
        $out[] = $line;
    }
    return trim(implode("\n", $out));
}

/**
 * Parses a raw RFC 822 message: ['from_email','from_name','subject','message_id',
 * 'body','attachments','auto' (auto-reply/bounce/list mail, never filed)].
 */
function csParseRawEmail(string $raw): array {
    [$h, $body] = csMailSplit($raw);
    $from = csMailDecodeHeader($h['from'] ?? '');
    $email = preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : (preg_match('/[^\s<>"]+@[^\s<>"]+/', $from, $m2) ? $m2[0] : '');
    $name  = trim(preg_replace('/<[^>]*>/', '', $from), " \"'");
    if (strcasecmp($name, $email) === 0) $name = '';
    $out = ['text' => null, 'html' => null, 'attachments' => 0];
    csMailWalk($h, $body, $out);
    $text = $out['text'];
    if ($text === null && $out['html'] !== null) {
        $text = html_entity_decode(strip_tags(preg_replace(['#<(br|/p|/div|/tr|/h\d)[^>]*>#i', '#<(style|script)[^>]*>.*?</\1>#is'], ["\n", ''], $out['html'])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $text = trim(preg_replace("/\n{3,}/", "\n\n", csMailStripQuote((string)$text)));
    if ($out['attachments']) $text .= ($text !== '' ? "\n\n" : '') . '[' . $out['attachments'] . ' attachment' . ($out['attachments'] > 1 ? 's' : '') . ' not shown — check the mailbox]';
    $auto = (isset($h['auto-submitted']) && strtolower($h['auto-submitted']) !== 'no')
         || preg_match('/^(bulk|junk|list|auto_reply)$/i', $h['precedence'] ?? '')
         || isset($h['list-id']) || isset($h['x-autoreply']) || isset($h['x-autorespond'])
         || preg_match('/^(mailer-daemon|postmaster|no-?reply|do-?not-?reply)@/i', $email);
    return ['from_email' => strtolower(trim($email)), 'from_name' => mb_substr($name, 0, 150), 'subject' => mb_substr(csMailDecodeHeader($h['subject'] ?? ''), 0, 255),
            'message_id' => trim($h['message-id'] ?? ''), 'body' => mb_substr($text, 0, 60000), 'attachments' => $out['attachments'], 'auto' => (bool)$auto];
}

/** Files one raw inbound email. Returns the thread id, or null when skipped (auto mail, our own address, duplicate). */
function csEmailReceive(string $raw, ?array $s = null): ?string {
    $s ??= csInboxSettings();
    $m = csParseRawEmail($raw);
    $own = strtolower((string)(preg_match('/<([^>]+)>/', (string)(getAppConfig()['smtpFrom'] ?? ''), $x) ? $x[1] : (getAppConfig()['smtpFrom'] ?? '')));
    if ($m['auto'] || $m['from_email'] === '' || $m['from_email'] === $s['email_address'] || ($own !== '' && $m['from_email'] === $own)) return null;
    return csThreadReceive('email', $m['from_email'], $m['from_name'], $m['subject'], $m['body'], $m['message_id'], $s);
}

// ── Ticket updates to customers ──────────────────────────────────────────────

/** No automatic texts at night; the 5-minute cron sweep sends what's due after 07:00. */
function csNoticeHoursOk(?int $ts = null): bool {
    $hr = (int)date('G', $ts ?? ($GLOBALS['csNoticeClock'] ?? time()));   // tests set csNoticeClock
    return $hr >= 7 && $hr < 21;
}

function csRenderNotice(string $tpl, array $t): string {
    $first = trim(explode(' ', trim((string)$t['customer_name']))[0] ?? '') ?: 'there';
    return trim(strtr($tpl, ['{name}' => $first, '{ticket}' => (string)($t['ticket_number'] ?: 'your ticket'), '{engineer}' => (string)($t['engineer'] ?? '') ?: 'an engineer']));
}

/**
 * Sends each customer the one update their ticket's current status calls for
 * (created → assigned → resolved; each at most once, never backwards).
 * $ticketIds limits the check; otherwise tickets changed in the last $hours.
 * Only tickets created after the feature was switched on. Returns how many were sent.
 */
function csTicketNoticeSweep(?array $ticketIds = null, int $hours = 48, ?array $s = null): int {
    $s ??= csInboxSettings();
    if (!$s['notify_enabled'] || $s['notify_since'] === '' || !csNoticeHoursOk()) return 0;
    $where = "t.created_at >= ? AND COALESCE(t.ticket_scope, 'customer') = 'customer' AND t.status IN ('" . implode("','", array_keys(CS_NOTICE_FOR_STATUS)) . "')";
    $params = [$s['notify_since']];
    if ($ticketIds) { $where .= " AND t.id IN (" . implode(',', array_fill(0, count($ticketIds), '?')) . ")"; $params = array_merge($params, array_values($ticketIds)); }
    else { $where .= " AND COALESCE(t.updated_at, t.created_at) >= ?"; $params[] = date('Y-m-d H:i:s', time() - $hours * 3600); }
    $rows = dbFetchAll("SELECT t.id, t.ticket_number, t.status, c.id AS customer_id, c.name AS customer_name, c.phone, u.name AS engineer
                        FROM tickets t JOIN customers c ON c.id = t.customer_id LEFT JOIN users u ON u.id = t.assigned_to
                        WHERE $where LIMIT 200", $params);
    if (!$rows) return 0;
    $sent = [];
    foreach (dbFetchAll("SELECT ticket_id, event FROM cs_ticket_notices WHERE ticket_id IN (" . implode(',', array_fill(0, count($rows), '?')) . ")", array_column($rows, 'id')) as $n) {
        $sent[$n['ticket_id']] = max($sent[$n['ticket_id']] ?? 0, CS_NOTICE_RANK[$n['event']] ?? 0);
    }
    $count = 0;
    foreach ($rows as $t) {
        $event = CS_NOTICE_FOR_STATUS[$t['status']];
        if (CS_NOTICE_RANK[$event] <= ($sent[$t['id']] ?? 0)) continue;
        $phone = csE164((string)$t['phone']);
        $text  = csRenderNotice($s['templates'][$event], $t);
        if (!in_array($event, $s['notify_events'], true)) {
            // Turned off: record it as skipped so a later stage still goes out and this one never does.
            dbRun("INSERT INTO cs_ticket_notices (id,ticket_id,event,channel,to_address,body,status,error,created_at) VALUES (?,?,?,'none',?,?,'skipped','Event turned off',?)",
                [newUuid(), $t['id'], $event, $phone, $text, date('Y-m-d H:i:s')]);
            continue;
        }
        $r = csDeliverNotice($phone, $text, $s);
        dbRun("INSERT INTO cs_ticket_notices (id,ticket_id,event,channel,to_address,body,status,error,created_at) VALUES (?,?,?,?,?,?,?,?,?)",
            [newUuid(), $t['id'], $event, $r['channel'], $phone, $text, $r['ok'] ? 'sent' : 'failed', $r['ok'] ? null : mb_substr($r['error'], 0, 255), date('Y-m-d H:i:s')]);
        if ($r['ok']) $count++;
    }
    return $count;
}

/** WhatsApp while the customer has an open 24h chat (if chosen), else SMS (filed in their SMS thread so replies have context). */
function csDeliverNotice(string $phone, string $text, array $s): array {
    if (!preg_match('/^\+\d{7,15}$/', $phone)) return ['ok' => false, 'channel' => 'none', 'error' => 'Customer has no valid phone number.'];
    if ($s['notify_channel'] === 'whatsapp_first' && csWaEnabled()) {
        $conv = dbFetch("SELECT * FROM cs_wa_conversations WHERE wa_phone = ?", [ltrim($phone, '+')]);
        if ($conv && csWaWindowOpen($conv)) {
            $r = csWaSend($conv, $text, null);
            if ($r['ok']) return ['ok' => true, 'channel' => 'whatsapp', 'error' => ''];
        }
    }
    if (!csSmsEnabled($s)) return ['ok' => false, 'channel' => 'sms', 'error' => 'SMS is not configured.'];
    $thread = csThreadFor('sms', ltrim($phone, '+'), '', '', 'closed');
    $r = csThreadSend($thread, $text, null, $s, '', 'Ticket update');
    return ['ok' => $r['ok'], 'channel' => 'sms', 'error' => $r['error'] ?? ''];
}

// ── Customer timeline ────────────────────────────────────────────────────────

/**
 * Everything that happened with a customer, newest first: calls, logged
 * contacts, WhatsApp, SMS and email messages, tickets and follow-ups.
 * Rows: ['at','kind','icon','title','body','link'].
 */
function csCustomerTimeline(string $customerId, int $limit = 60): array {
    $limit = max(1, min(200, $limit));
    $rows = [];
    foreach (dbFetchAll("SELECT c.id, c.direction, c.status, c.started_at, c.duration_sec, c.ivr_choice, u.name AS agent FROM cs_calls c LEFT JOIN users u ON u.id = c.agent_id
                         WHERE c.customer_id = ? ORDER BY c.started_at DESC LIMIT $limit", [$customerId]) as $r) {
        $rows[] = ['at' => $r['started_at'], 'kind' => 'call', 'icon' => $r['direction'] === 'inbound' ? 'bi-telephone-inbound' : 'bi-telephone-outbound',
                   'title' => ucfirst($r['direction']) . ' call · ' . (CS_CALL_STATUSES[$r['status']] ?? $r['status'])
                            . ($r['duration_sec'] ? ' · ' . sprintf('%d:%02d', intdiv((int)$r['duration_sec'], 60), (int)$r['duration_sec'] % 60) : '')
                            . ($r['agent'] ? ' · ' . $r['agent'] : ''),
                   'body' => $r['ivr_choice'] ? 'Menu: ' . $r['ivr_choice'] : '', 'link' => '/support/calls'];
    }
    foreach (dbFetchAll("SELECT i.created_at, i.channel, i.summary, i.agent_name, w.name AS reason FROM cs_interactions i LEFT JOIN cs_wrap_codes w ON w.id = i.wrap_code_id
                         WHERE i.customer_id = ? ORDER BY i.created_at DESC LIMIT $limit", [$customerId]) as $r) {
        $rows[] = ['at' => $r['created_at'], 'kind' => 'note', 'icon' => 'bi-journal-text',
                   'title' => 'Logged ' . (CS_CHANNELS[$r['channel']] ?? $r['channel']) . ' contact · ' . ($r['reason'] ?: '') . ' · ' . $r['agent_name'], 'body' => (string)$r['summary'], 'link' => ''];
    }
    foreach (dbFetchAll("SELECT m.created_at, m.direction, m.body, m.agent_name, m.status, c.id AS conv FROM cs_wa_messages m JOIN cs_wa_conversations c ON c.id = m.conversation_id
                         WHERE c.customer_id = ? ORDER BY m.created_at DESC LIMIT $limit", [$customerId]) as $r) {
        $rows[] = ['at' => $r['created_at'], 'kind' => 'whatsapp', 'icon' => 'bi-whatsapp',
                   'title' => 'WhatsApp ' . ($r['direction'] === 'in' ? 'from customer' : 'to customer · ' . ($r['agent_name'] ?: '')) . ($r['status'] === 'failed' ? ' · failed' : ''),
                   'body' => (string)$r['body'], 'link' => '/support/whatsapp?c=' . rawurlencode($r['conv'])];
    }
    foreach (dbFetchAll("SELECT m.created_at, m.direction, m.subject, m.body, m.agent_name, m.status, t.channel, t.id AS thread FROM cs_thread_messages m JOIN cs_threads t ON t.id = m.thread_id
                         WHERE t.customer_id = ? ORDER BY m.created_at DESC LIMIT $limit", [$customerId]) as $r) {
        $rows[] = ['at' => $r['created_at'], 'kind' => $r['channel'], 'icon' => $r['channel'] === 'sms' ? 'bi-chat-dots' : 'bi-envelope',
                   'title' => (CS_THREAD_CHANNELS[$r['channel']] ?? $r['channel']) . ' ' . ($r['direction'] === 'in' ? 'from customer' : 'to customer · ' . ($r['agent_name'] ?: ''))
                            . ($r['subject'] !== '' && $r['channel'] === 'email' ? ' · ' . $r['subject'] : '') . ($r['status'] === 'failed' ? ' · failed' : ''),
                   'body' => (string)$r['body'], 'link' => '/support/inbox?t=' . rawurlencode($r['thread'])];
    }
    foreach (dbFetchAll("SELECT id, ticket_number, status, description, created_at, resolved_at FROM tickets WHERE customer_id = ? ORDER BY created_at DESC LIMIT $limit", [$customerId]) as $r) {
        $rows[] = ['at' => $r['created_at'], 'kind' => 'ticket', 'icon' => 'bi-ticket-perforated', 'title' => 'Ticket ' . $r['ticket_number'] . ' opened',
                   'body' => (string)$r['description'], 'link' => '/ticket/' . rawurlencode($r['id'])];
        if ($r['resolved_at']) $rows[] = ['at' => $r['resolved_at'], 'kind' => 'ticket', 'icon' => 'bi-check2-circle', 'title' => 'Ticket ' . $r['ticket_number'] . ' resolved', 'body' => '', 'link' => '/ticket/' . rawurlencode($r['id'])];
    }
    foreach (dbFetchAll("SELECT f.created_at, f.note, f.status, u.name AS owner FROM cs_followups f LEFT JOIN users u ON u.id = f.assigned_to
                         WHERE f.customer_id = ? ORDER BY f.created_at DESC LIMIT $limit", [$customerId]) as $r) {
        $rows[] = ['at' => $r['created_at'], 'kind' => 'followup', 'icon' => 'bi-alarm', 'title' => 'Follow-up for ' . ($r['owner'] ?: '—') . ' · ' . $r['status'], 'body' => (string)$r['note'], 'link' => '/support/followups'];
    }
    usort($rows, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
    return array_slice($rows, 0, $limit);
}
