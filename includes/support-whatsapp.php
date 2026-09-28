<?php
/**
 * Customer Support — WhatsApp inbox.
 *
 * Customers message the business WhatsApp number; the provider posts each
 * message to api/whatsapp-webhook.php, which files it under a conversation
 * (one per customer number). Agents read and reply on /support/whatsapp.
 *
 * Providers: Africa's Talking (default) or the Meta WhatsApp Cloud API,
 * chosen in Support Settings. WhatsApp only allows free-text replies within
 * 24 hours of the customer's last message; after that a business can only
 * send a pre-approved template (Meta), so the inbox enforces the window.
 */

const CS_WA_PROVIDERS = ['none' => 'Disabled', 'africastalking' => "Africa's Talking", 'meta' => 'Meta WhatsApp Cloud API'];
const CS_WA_WINDOW    = 86400;
const CS_WA_META_API  = 'https://graph.facebook.com/v21.0';
// Delivery states only move forward; 'failed' can replace any of them.
const CS_WA_STATUS_RANK = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];

/** WhatsApp settings with defaults. Secrets stay server-side (see api/app-config.php). */
function csWaSettings(): array {
    $c = getAppConfig();
    return [
        'provider'        => isset(CS_WA_PROVIDERS[$c['waProvider'] ?? '']) ? $c['waProvider'] : 'none',
        'at_username'     => trim((string)($c['atUsername'] ?? '')),
        'at_api_key'      => (string)($c['atApiKey'] ?? ''),
        'at_wa_number'    => trim((string)($c['atWaNumber'] ?? '')),
        'meta_phone_id'   => trim((string)($c['metaWaPhoneNumberId'] ?? '')),
        'meta_token'      => (string)($c['metaWaAccessToken'] ?? ''),
        'meta_app_secret' => (string)($c['metaWaAppSecret'] ?? ''),
        'meta_verify'     => (string)($c['metaWaVerifyToken'] ?? ''),
        'meta_template'   => trim((string)($c['metaWaTemplate'] ?? '')),
        'meta_template_lang' => trim((string)($c['metaWaTemplateLang'] ?? '')) ?: 'en',
        'webhook_secret'  => (string)($c['waWebhookSecret'] ?? ''),
        'auto_reply'      => trim((string)($c['waAutoReply'] ?? '')),
    ];
}

function csWaEnabled(?array $s = null): bool {
    $s ??= csWaSettings();
    if ($s['webhook_secret'] === '') return false;
    return match ($s['provider']) {
        'africastalking' => $s['at_username'] !== '' && $s['at_api_key'] !== '' && $s['at_wa_number'] !== '',
        'meta'           => $s['meta_phone_id'] !== '' && $s['meta_token'] !== '' && $s['meta_app_secret'] !== '',
        default          => false,
    };
}

function csWaWebhookUrl(?array $s = null): string {
    $s ??= csWaSettings();
    return siteBaseUrl() . '/api/whatsapp-webhook?k=' . rawurlencode($s['webhook_secret']);
}

/** Meta signs each webhook with the app secret; unsigned or mismatched bodies are rejected. */
function csWaMetaSignatureValid(string $rawBody, string $header, string $appSecret): bool {
    if ($appSecret === '' || !str_starts_with($header, 'sha256=')) return false;
    return hash_equals('sha256=' . hash_hmac('sha256', $rawBody, $appSecret), $header);
}

/**
 * Normalises a webhook body from either provider into
 *   ['messages' => [[from, name, id, body, type, ts]], 'statuses' => [[id, status, error]]].
 * Africa's Talking's inbound shape isn't documented where we could check it,
 * so that branch accepts the common field names; the raw body is always kept
 * in cs_wa_events so the mapping can be confirmed from real traffic.
 */
function csWaParseWebhook(array $p): array {
    $out = ['messages' => [], 'statuses' => []];

    if (isset($p['entry']) && is_array($p['entry'])) {                     // Meta Cloud API
        foreach ($p['entry'] as $entry) {
            foreach ((array)($entry['changes'] ?? []) as $ch) {
                $v = (array)($ch['value'] ?? []);
                $names = [];
                foreach ((array)($v['contacts'] ?? []) as $ct) $names[(string)($ct['wa_id'] ?? '')] = (string)($ct['profile']['name'] ?? '');
                foreach ((array)($v['messages'] ?? []) as $m) {
                    $type = (string)($m['type'] ?? 'text');
                    $body = match ($type) {
                        'text'        => (string)($m['text']['body'] ?? ''),
                        'button'      => (string)($m['button']['text'] ?? ''),
                        'interactive' => (string)($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? ''),
                        'image', 'video', 'document', 'audio', 'sticker' => (string)($m[$type]['caption'] ?? ''),
                        'location'    => trim(($m['location']['name'] ?? '') . ' ' . ($m['location']['latitude'] ?? '') . ',' . ($m['location']['longitude'] ?? '')),
                        default       => '',
                    };
                    $from = (string)($m['from'] ?? '');
                    $out['messages'][] = ['from' => $from, 'name' => $names[$from] ?? '', 'id' => (string)($m['id'] ?? ''),
                                          'body' => $body, 'type' => $type, 'ts' => (int)($m['timestamp'] ?? time())];
                }
                foreach ((array)($v['statuses'] ?? []) as $st) {
                    $out['statuses'][] = ['id' => (string)($st['id'] ?? ''), 'status' => (string)($st['status'] ?? ''),
                                          'error' => (string)($st['errors'][0]['title'] ?? $st['errors'][0]['message'] ?? '')];
                }
            }
        }
        return $out;
    }

    // Africa's Talking (tolerant): look fields up case-insensitively, one level deep.
    $lc = array_change_key_case($p, CASE_LOWER);
    $g = function (array $keys) use ($lc) {
        foreach ($keys as $k) if (isset($lc[$k]) && is_scalar($lc[$k]) && (string)$lc[$k] !== '') return (string)$lc[$k];
        return '';
    };
    $bodyField = $lc['body'] ?? null;
    $text = is_array($bodyField)
        ? (string)($bodyField['message'] ?? $bodyField['text'] ?? $bodyField['body'] ?? '')
        : ($bodyField !== null ? (string)$bodyField : $g(['message', 'text', 'content']));
    $id     = $g(['messageid', 'id', 'message_id']);
    $status = strtolower($g(['status', 'deliverystatus']));
    $from   = $g(['from', 'phonenumber', 'customernumber', 'sender', 'waid', 'wa_id']);
    $isOutbound = strtolower($g(['direction'])) === 'outbound';

    if ($text !== '' && $from !== '' && !$isOutbound) {
        $out['messages'][] = ['from' => $from, 'name' => $g(['name', 'profilename', 'sendername']), 'id' => $id,
                              'body' => $text, 'type' => strtolower($g(['type', 'messagetype'])) ?: 'text', 'ts' => time()];
    } elseif ($status !== '' && $id !== '') {
        $out['statuses'][] = ['id' => $id, 'status' => $status, 'error' => $g(['failurereason', 'error', 'reason'])];
    }
    return $out;
}

/** Customer numbers are stored as bare international digits (2348031234567). */
function csWaPhone(string $number): string {
    return ltrim(csE164($number), '+');
}

/** Files one inbound message. Returns the conversation id, or null for a duplicate delivery. */
function csWaReceive(array $m, ?array $s = null): ?string {
    $s ??= csWaSettings();
    $phone = csWaPhone($m['from']);
    if ($phone === '') return null;
    if ($m['id'] !== '' && dbFetch("SELECT id FROM cs_wa_messages WHERE provider_message_id = ?", [$m['id']])) return null;

    $now  = date('Y-m-d H:i:s');
    $conv = dbFetch("SELECT * FROM cs_wa_conversations WHERE wa_phone = ?", [$phone]);
    $fresh = !$conv || $conv['status'] === 'closed';
    if (!$conv) {
        $cust = csFindCustomerByPhone($phone);
        $conv = ['id' => newUuid()];
        dbRun("INSERT INTO cs_wa_conversations (id,wa_phone,contact_name,customer_id,status,unread,last_inbound_at,last_message_at,created_at) VALUES (?,?,?,?,'open',1,?,?,?)",
            [$conv['id'], $phone, mb_substr($m['name'], 0, 150), $cust['id'] ?? null, $now, $now, $now]);
    } else {
        // A closed conversation reopens unassigned so the next free agent picks it up.
        // assigned_to before status: MySQL applies SET clauses left to right.
        dbRun("UPDATE cs_wa_conversations SET assigned_to = CASE WHEN status = 'closed' THEN NULL ELSE assigned_to END,
                  unread = unread + 1, last_inbound_at = ?, last_message_at = ?, status = 'open',
                  contact_name = CASE WHEN contact_name = '' THEN ? ELSE contact_name END WHERE id = ?",
            [$now, $now, mb_substr($m['name'], 0, 150), $conv['id']]);
    }
    $body = $m['body'] !== '' ? $m['body'] : '[' . ($m['type'] ?: 'message') . ']';
    dbRun("INSERT INTO cs_wa_messages (id,conversation_id,direction,provider,provider_message_id,body,msg_type,status,created_at) VALUES (?,?,'in',?,?,?,?,'received',?)",
        [newUuid(), $conv['id'], $s['provider'], $m['id'] !== '' ? mb_substr($m['id'], 0, 128) : null, $body, mb_substr($m['type'] ?: 'text', 0, 20), $now]);

    if ($fresh && $s['auto_reply'] !== '' && csWaEnabled($s)) {
        csWaSend(dbFetch("SELECT * FROM cs_wa_conversations WHERE id = ?", [$conv['id']]), $s['auto_reply'], null, false, $s);
    }
    return $conv['id'];
}

function csWaApplyStatus(array $st): void {
    $status = strtolower($st['status']);
    if ($st['id'] === '' || $status === '') return;
    $status = match ($status) { 'success', 'accepted', 'submitted' => 'sent', 'undelivered', 'rejected', 'expired' => 'failed', default => $status };
    $msg = dbFetch("SELECT id, status FROM cs_wa_messages WHERE provider_message_id = ? AND direction = 'out'", [$st['id']]);
    if (!$msg) return;
    if ($status === 'failed') {
        dbRun("UPDATE cs_wa_messages SET status = 'failed', error = ? WHERE id = ?", [mb_substr($st['error'] ?: 'Not delivered', 0, 255), $msg['id']]);
    } elseif (isset(CS_WA_STATUS_RANK[$status]) && CS_WA_STATUS_RANK[$status] > (CS_WA_STATUS_RANK[$msg['status']] ?? -1)) {
        dbRun("UPDATE cs_wa_messages SET status = ? WHERE id = ?", [$status, $msg['id']]);
    }
}

function csWaHandleWebhook(array $payload, ?array $s = null): array {
    $s ??= csWaSettings();
    $p = csWaParseWebhook($payload);
    $n = 0;
    foreach ($p['messages'] as $m) if (csWaReceive($m, $s)) $n++;
    foreach ($p['statuses'] as $st) csWaApplyStatus($st);
    return ['messages' => $n, 'statuses' => count($p['statuses'])];
}

/** True while free-text replies are allowed (within 24h of the customer's last message). */
function csWaWindowOpen(array $conv): bool {
    return !empty($conv['last_inbound_at']) && time() - strtotime($conv['last_inbound_at']) < CS_WA_WINDOW;
}

/**
 * Sends a reply (or, with $template, the configured Meta template) and stores
 * it. $agent is null for automatic replies. Returns ['ok'=>bool,'error'=>…].
 */
function csWaSend(array $conv, string $text, ?array $agent, bool $template = false, ?array $s = null): array {
    $s ??= csWaSettings();
    $text = trim($text);
    if (!csWaEnabled($s)) return ['ok' => false, 'error' => 'WhatsApp is not configured.'];
    if ($template) {
        if ($s['provider'] !== 'meta' || $s['meta_template'] === '') return ['ok' => false, 'error' => 'No message template is configured.'];
        $text = '[Template: ' . $s['meta_template'] . ']';
    } else {
        if ($text === '') return ['ok' => false, 'error' => 'Type a message.'];
        if (mb_strlen($text) > 4096) return ['ok' => false, 'error' => 'Messages are limited to 4096 characters.'];
        if (!csWaWindowOpen($conv)) {
            return ['ok' => false, 'error' => 'More than 24 hours since the customer last wrote. WhatsApp only allows an approved template now'
                . ($s['provider'] === 'meta' && $s['meta_template'] !== '' ? ' — use "Send template".' : ' — call the customer instead.')];
        }
    }

    $to = '+' . $conv['wa_phone'];
    if ($s['provider'] === 'meta') {
        $payload = $template
            ? ['messaging_product' => 'whatsapp', 'to' => $conv['wa_phone'], 'type' => 'template',
               'template' => ['name' => $s['meta_template'], 'language' => ['code' => $s['meta_template_lang']]]]
            : ['messaging_product' => 'whatsapp', 'to' => $conv['wa_phone'], 'type' => 'text', 'text' => ['body' => $text]];
        $r = csHttp('POST', CS_WA_META_API . '/' . rawurlencode($s['meta_phone_id']) . '/messages',
            ['Authorization: Bearer ' . $s['meta_token'], 'Content-Type: application/json'], json_encode($payload));
        $d = json_decode($r['body'], true) ?: [];
        $pid = (string)($d['messages'][0]['id'] ?? '');
        $err = (string)($d['error']['message'] ?? '');
    } else {
        $r = csHttp('POST', 'https://chat.africastalking.com/whatsapp/message/send',
            ['apiKey: ' . $s['at_api_key'], 'Accept: application/json', 'Content-Type: application/json'],
            json_encode(['username' => $s['at_username'], 'waNumber' => csE164($s['at_wa_number']), 'phoneNumber' => $to, 'body' => ['message' => $text]]));
        $d = json_decode($r['body'], true) ?: [];
        $pid = (string)($d['messageId'] ?? $d['id'] ?? $d['data']['messageId'] ?? $d['data']['id'] ?? '');
        $err = (string)($d['errorMessage'] ?? $d['message'] ?? $d['error'] ?? '');
    }
    $ok = $r['status'] >= 200 && $r['status'] < 300;
    if (!$ok) $err = mb_substr($err !== '' ? $err : ($r['error'] !== '' ? $r['error'] : 'HTTP ' . $r['status']), 0, 255);

    $now = date('Y-m-d H:i:s');
    dbRun("INSERT INTO cs_wa_messages (id,conversation_id,direction,provider,provider_message_id,body,msg_type,status,error,agent_id,agent_name,created_at)
           VALUES (?,?,'out',?,?,?,?,?,?,?,?,?)",
        [newUuid(), $conv['id'], $s['provider'], $pid !== '' ? mb_substr($pid, 0, 128) : null, $text, $template ? 'template' : 'text',
         $ok ? 'sent' : 'failed', $ok ? null : $err, $agent['id'] ?? null, $agent ? mb_substr((string)$agent['name'], 0, 150) : 'Auto-reply', $now]);
    dbRun("UPDATE cs_wa_conversations SET last_message_at = ? WHERE id = ?", [$now, $conv['id']]);
    if ($ok && $agent && empty($conv['assigned_to'])) dbRun("UPDATE cs_wa_conversations SET assigned_to = ? WHERE id = ?", [$agent['id'], $conv['id']]);
    if (!$ok) error_log('WhatsApp send failed (' . $s['provider'] . '): ' . $err);
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'WhatsApp did not accept the message: ' . $err];
}
