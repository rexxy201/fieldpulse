<?php
/**
 * Customer Support — voice calls.
 *
 * Provider-neutral call handling with an Africa's Talking implementation:
 *  - the provider calls api/voice-callback.php for every call event and we
 *    answer with call-control XML (greeting, menu, dial agents, voicemail);
 *  - agents take and make calls in the browser (Africa's Talking WebRTC
 *    client), authorised by a short-lived capability token;
 *  - recordings are downloaded to a folder outside the web root and deleted
 *    after the configured retention period.
 *
 * Adding another provider means another csVoiceProvider* branch in the few
 * functions marked "provider-specific"; the call table and UI are shared.
 */

const CS_VOICE_PROVIDERS = ['none' => 'Disabled', 'africastalking' => "Africa's Talking"];
const CS_CALL_STATUSES   = ['ringing' => 'Ringing', 'in_progress' => 'In progress', 'completed' => 'Completed',
                            'missed' => 'Missed', 'voicemail' => 'Voicemail'];

/** Voice settings with defaults. Secrets stay server-side (see api/app-config.php). */
function csVoiceSettings(): array {
    $c = getAppConfig();
    return [
        'provider'          => $c['voiceProvider'] ?? 'none',
        'at_username'       => trim((string)($c['atUsername'] ?? '')),
        'at_api_key'        => (string)($c['atApiKey'] ?? ''),
        'at_number'         => trim((string)($c['atVoiceNumber'] ?? '')),
        'webhook_secret'    => (string)($c['voiceWebhookSecret'] ?? ''),
        'greeting'          => trim((string)($c['voiceGreeting'] ?? '')) ?: 'Thank you for calling. ',
        'record'            => ($c['voiceRecordCalls'] ?? '1') === '1',
        'announcement'      => trim((string)($c['voiceRecordingNotice'] ?? '')) ?: 'This call may be recorded for quality and training purposes.',
        'retention_days'    => max(1, (int)($c['voiceRecordingRetentionDays'] ?? 30)),
        'ivr_enabled'       => ($c['voiceIvrEnabled'] ?? '1') === '1',
        'ivr_options'       => csParseIvrOptions((string)($c['voiceIvrOptions'] ?? "1=Technical support\n2=Billing and payments\n3=New connection and sales")),
        'fallback_numbers'  => array_values(array_filter(array_map('trim', explode(',', (string)($c['voiceFallbackNumbers'] ?? ''))))),
        'missed_assignee'   => (string)($c['voiceMissedCallAssignee'] ?? ''),
    ];
}

function csVoiceEnabled(?array $s = null): bool {
    $s ??= csVoiceSettings();
    return $s['provider'] === 'africastalking' && $s['at_username'] !== '' && $s['at_api_key'] !== '' && $s['at_number'] !== '' && $s['webhook_secret'] !== '';
}

/** "1=Technical support" lines → [digit => label]. */
function csParseIvrOptions(string $text): array {
    $out = [];
    foreach (preg_split('/\R/', $text) as $line) {
        if (preg_match('/^\s*([0-9])\s*[=:\-]\s*(.+?)\s*$/', $line, $m)) $out[$m[1]] = mb_substr($m[2], 0, 60);
    }
    return $out;
}

/** The URL the provider must call; the secret stops anyone else driving our IVR or faking call records. */
function csVoiceCallbackUrl(string $step = 'answer', ?array $s = null): string {
    $s ??= csVoiceSettings();
    return siteBaseUrl() . '/api/voice-callback?k=' . rawurlencode($s['webhook_secret']) . '&step=' . rawurlencode($step);
}

/** Browser client name for an agent: stable, letters and digits only. */
function csAgentClientName(string $userId): string {
    return 'agent' . substr(preg_replace('/[^a-z0-9]/', '', strtolower($userId)), 0, 16);
}

function csUserFromClientName(string $clientName): ?array {
    $clientName = preg_replace('/^.*\./', '', $clientName);   // "username.agentxxxx" → "agentxxxx"
    if (!str_starts_with($clientName, 'agent')) return null;
    foreach (dbFetchAll("SELECT id, name FROM users") as $u) {
        if (csAgentClientName($u['id']) === $clientName) return $u;
    }
    return null;
}

/** +234… for Nigerian local numbers (0803… / 234803…); leaves other + numbers alone. */
function csE164(string $number, string $country = '234'): string {
    $d = preg_replace('/[^\d+]/', '', $number);
    if (str_starts_with($d, '+')) return $d;
    if (str_starts_with($d, $country)) return '+' . $d;
    if (str_starts_with($d, '0')) return '+' . $country . substr($d, 1);
    return $d === '' ? '' : '+' . $d;
}

// ── Call-control XML (provider-specific: Africa's Talking) ───────────────────

/**
 * Builds <Response> XML from a list of actions:
 *   ['say' => 'text'], ['play' => url],
 *   ['getDigits' => ['text'=>…, 'numDigits'=>1, 'timeout'=>10, 'callBackUrl'=>…]],
 *   ['dial' => ['numbers'=>[…], 'record'=>bool, 'sequential'=>bool, 'callerId'=>…, 'maxDuration'=>…]],
 *   ['record' => ['text'=>…, 'maxLength'=>120, 'callBackUrl'=>…]], ['reject' => true]
 */
function csVoiceXml(array $actions): string {
    $e   = fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $out = '<?xml version="1.0" encoding="UTF-8"?><Response>';
    foreach ($actions as $a) {
        $type = array_key_first($a); $v = $a[$type];
        switch ($type) {
            case 'say':  $out .= '<Say>' . $e($v) . '</Say>'; break;
            case 'play': $out .= '<Play url="' . $e($v) . '"/>'; break;
            case 'getDigits':
                $out .= '<GetDigits numDigits="' . (int)($v['numDigits'] ?? 1) . '" timeout="' . (int)($v['timeout'] ?? 10) . '"'
                      . (isset($v['finishOnKey']) ? ' finishOnKey="' . $e($v['finishOnKey']) . '"' : '')
                      . ' callBackUrl="' . $e($v['callBackUrl']) . '"><Say>' . $e($v['text']) . '</Say></GetDigits>';
                break;
            case 'dial':
                $out .= '<Dial phoneNumbers="' . $e(implode(',', $v['numbers'])) . '"'
                      . ' record="' . (!empty($v['record']) ? 'true' : 'false') . '"'
                      . ' sequential="' . (!empty($v['sequential']) ? 'true' : 'false') . '"'
                      . (!empty($v['callerId']) ? ' callerId="' . $e($v['callerId']) . '"' : '')
                      . (!empty($v['maxDuration']) ? ' maxDuration="' . (int)$v['maxDuration'] . '"' : '')
                      . '/>';
                break;
            case 'record':
                $out .= '<Record finishOnKey="#" maxLength="' . (int)($v['maxLength'] ?? 120) . '" trimSilence="true" playBeep="true"'
                      . ' callBackUrl="' . $e($v['callBackUrl']) . '"><Say>' . $e($v['text']) . '</Say></Record>';
                break;
            case 'reject': $out .= '<Reject/>'; break;
        }
    }
    return $out . '</Response>';
}

/**
 * Normalises a provider callback (POST fields) into our own names. Africa's
 * Talking's field names are matched case-insensitively and with the known
 * variants, since callbacks differ slightly between call legs and events.
 */
function csParseVoiceCallback(array $p): array {
    $lc = array_change_key_case($p, CASE_LOWER);
    $g  = function (string ...$keys) use ($lc) { foreach ($keys as $k) { $k = strtolower($k); if (isset($lc[$k]) && $lc[$k] !== '') return (string)$lc[$k]; } return ''; };
    $dir = strtolower($g('direction'));
    return [
        'session_id'    => $g('sessionId', 'session_id'),
        'is_active'     => $g('isActive', 'is_active') === '1' || strtolower($g('isActive')) === 'true',
        'direction'     => str_starts_with($dir, 'out') ? 'outbound' : 'inbound',
        'caller'        => $g('callerNumber', 'from'),
        'destination'   => $g('destinationNumber', 'to'),
        'client_dialed' => $g('clientDialedNumber'),
        'dtmf'          => $g('dtmfDigits', 'digits'),
        'recording_url' => $g('recordingUrl', 'recording_url'),
        'duration'      => $g('durationInSeconds', 'duration') !== '' ? (int)$g('durationInSeconds', 'duration') : null,
        'hangup_cause'  => $g('hangupCause'),
        'amount'        => $g('amount'),
        'currency'      => $g('currencyCode'),
        'state'         => $g('callSessionState'),
    ];
}

// ── The call flow ────────────────────────────────────────────────────────────

/** Browser clients of agents who set themselves Available in the workspace. */
function csAvailableAgentTargets(array $s): array {
    $rows = dbFetchAll("SELECT user_id FROM cs_agent_status WHERE status = 'available' AND changed_at >= ?", [date('Y-m-d H:i:s', time() - 12 * 3600)]);
    return array_map(fn($r) => $s['at_username'] . '.' . csAgentClientName($r['user_id']), $rows);
}

function csDialOrVoicemail(array $s): array {
    $clients = csAvailableAgentTargets($s);
    $numbers = $clients ?: array_map('csE164', $s['fallback_numbers']);
    if (!$numbers) {
        return [['record' => ['text' => 'All our agents are busy. Please leave a message with your name and account number after the beep, and we will call you back.',
                              'maxLength' => 120, 'callBackUrl' => csVoiceCallbackUrl('voicemail', $s)]]];
    }
    // Browser clients ring together; fallback mobiles ring one after another.
    return [['dial' => ['numbers' => $numbers, 'record' => $s['record'], 'sequential' => !$clients, 'callerId' => $s['at_number'], 'maxDuration' => 3600]]];
}

/**
 * Handles one provider callback and returns the XML to send back ('' when the
 * provider expects no instructions, e.g. the end-of-call notification).
 */
function csHandleVoiceCallback(array $post, string $step, ?array $s = null): string {
    $s  ??= csVoiceSettings();
    $ev   = csParseVoiceCallback($post);
    $sid  = $ev['session_id'];
    if ($sid === '') return '';
    $call = dbFetch("SELECT * FROM cs_calls WHERE session_id = ?", [$sid]);
    $now  = date('Y-m-d H:i:s');

    // End of call: record the outcome.
    if (!$ev['is_active'] && $step === 'answer') {
        if (!$call) return '';
        // Inbound: answered only once an agent's browser reported it (the
        // duration also counts time in the menu). Outbound: the callee picked up.
        $answered = $call['answered_at'] !== null || ($call['direction'] === 'outbound' && ($ev['duration'] ?? 0) > 0);
        $status   = $call['status'] === 'voicemail' ? 'voicemail' : ($answered ? 'completed' : 'missed');
        dbRun("UPDATE cs_calls SET status = ?, ended_at = ?, duration_sec = ?, recording_url = COALESCE(NULLIF(?, ''), recording_url),
               hangup_cause = ?, cost = ?, currency = ? WHERE id = ?",
            [$status, $now, $ev['duration'], $ev['recording_url'], mb_substr($ev['hangup_cause'], 0, 60), $ev['amount'] ?: null, mb_substr($ev['currency'], 0, 5), $call['id']]);
        if ($status === 'missed' && $call['direction'] === 'inbound') csCreateCallFollowup($call, 'Missed call — call the customer back.', $s);
        return '';
    }

    if ($step === 'voicemail') {
        if ($call) {
            dbRun("UPDATE cs_calls SET status = 'voicemail', recording_url = COALESCE(NULLIF(?, ''), recording_url) WHERE id = ?", [$ev['recording_url'], $call['id']]);
            csCreateCallFollowup($call, 'Voicemail left — listen to it and call the customer back.', $s);
        }
        return csVoiceXml([['say' => 'Thank you. We will call you back. Goodbye.']]);
    }

    if ($step === 'menu') {
        $label = $s['ivr_options'][$ev['dtmf']] ?? null;
        if ($call && $label) dbRun("UPDATE cs_calls SET ivr_choice = ? WHERE id = ?", [$label, $call['id']]);
        return csVoiceXml(array_merge([['say' => $label ? "Connecting you to $label." : 'Connecting you to an agent.']], csDialOrVoicemail($s)));
    }

    // step 'answer', call just started.
    $fromClient = $ev['client_dialed'] !== '' || str_contains($ev['caller'], '.');
    if ($fromClient) {
        // An agent dialling out from the browser.
        $agent = csUserFromClientName($ev['caller']);
        $to    = csE164($ev['client_dialed'] ?: $ev['destination']);
        if (!$call) {
            csInsertCall($sid, 'outbound', $s['at_number'], $to, $agent);
        }
        if (!preg_match('/^\+\d{7,15}$/', $to)) return csVoiceXml([['say' => 'That number is not valid.']]);
        return csVoiceXml([['dial' => ['numbers' => [$to], 'record' => $s['record'], 'callerId' => $s['at_number'], 'maxDuration' => 3600]]]);
    }

    if (!$call) csInsertCall($sid, 'inbound', csE164($ev['caller']), $ev['destination'], null);
    $intro = [['say' => rtrim($s['greeting']) . ($s['record'] ? ' ' . $s['announcement'] : '')]];
    if ($s['ivr_enabled'] && $s['ivr_options']) {
        $menu = implode(' ', array_map(fn($d, $l) => "For $l, press $d.", array_keys($s['ivr_options']), $s['ivr_options']));
        // No key pressed within the timeout: the provider carries on with the actions after GetDigits.
        return csVoiceXml(array_merge($intro, [['getDigits' => ['text' => $menu, 'numDigits' => 1, 'timeout' => 8, 'callBackUrl' => csVoiceCallbackUrl('menu', $s)]]], csDialOrVoicemail($s)));
    }
    return csVoiceXml(array_merge($intro, csDialOrVoicemail($s)));
}

function csInsertCall(string $sessionId, string $direction, string $from, string $to, ?array $agent): string {
    $id = newUuid();
    $customerNumber = $direction === 'inbound' ? $from : $to;
    $customer = csFindCustomerByPhone($customerNumber);
    dbRun("INSERT INTO cs_calls (id,session_id,direction,from_number,to_number,customer_id,agent_id,status,started_at,answered_at) VALUES (?,?,?,?,?,?,?,?,?,?)",
        [$id, $sessionId, $direction, mb_substr($from, 0, 40), mb_substr($to, 0, 40), $customer['id'] ?? null, $agent['id'] ?? null,
         $direction === 'outbound' ? 'in_progress' : 'ringing', date('Y-m-d H:i:s'), null]);
    return $id;
}

function csFindCustomerByPhone(string $number): ?array {
    $key = csPhoneKey($number);
    if (strlen($key) < 7) return null;
    foreach (dbFetchAll("SELECT id, name, account_number, phone FROM customers WHERE phone LIKE ? LIMIT 20", ['%' . substr($key, -7) . '%']) as $c) {
        if (csPhoneKey($c['phone']) === $key) return $c;
    }
    return null;
}

/** Missed call / voicemail → a follow-up owned by the configured person (else the first supervisor). */
function csCreateCallFollowup(array $call, string $note, array $s): void {
    if (dbFetch("SELECT id FROM cs_followups WHERE call_id = ?", [$call['id']])) return;
    $owner = $s['missed_assignee'] !== '' ? dbFetch("SELECT id FROM users WHERE id = ?", [$s['missed_assignee']]) : null;
    $owner ??= dbFetch("SELECT u.id FROM users u JOIN role_permissions rp ON rp.role = u.role WHERE rp.permission = 'support.view_all' ORDER BY u.name LIMIT 1")
            ?? dbFetch("SELECT id FROM users WHERE role = 'admin' ORDER BY name LIMIT 1");
    if (!$owner) return;
    $cust = $call['customer_id'] ? dbFetch("SELECT name FROM customers WHERE id = ?", [$call['customer_id']]) : null;
    dbRun("INSERT INTO cs_followups (id,call_id,customer_id,contact_name,contact_phone,assigned_to,created_by,due_at,note,status) VALUES (?,?,?,?,?,?,?,?,?,'open')",
        [newUuid(), $call['id'], $call['customer_id'], $cust['name'] ?? '', $call['from_number'], $owner['id'], $owner['id'], date('Y-m-d H:i:s'), $note]);
}

/** The agent's browser reports it answered: attach the ringing call from that number to them. */
function csMarkCallAnswered(string $fromNumber, string $agentId): ?string {
    $key = csPhoneKey($fromNumber);
    foreach (dbFetchAll("SELECT id, from_number FROM cs_calls WHERE direction = 'inbound' AND status = 'ringing' AND started_at >= ? ORDER BY started_at DESC LIMIT 10",
                        [date('Y-m-d H:i:s', time() - 600)]) as $c) {
        if (csPhoneKey($c['from_number']) === $key) {
            dbRun("UPDATE cs_calls SET status = 'in_progress', agent_id = ?, answered_at = ? WHERE id = ?", [$agentId, date('Y-m-d H:i:s'), $c['id']]);
            return $c['id'];
        }
    }
    return null;
}

// ── Provider API calls (provider-specific: Africa's Talking) ─────────────────

/** HTTP helper; tests replace it by setting $GLOBALS['csHttpFake'] to a callable. */
function csHttp(string $method, string $url, array $headers, ?string $body = null): array {
    if (isset($GLOBALS['csHttpFake']) && is_callable($GLOBALS['csHttpFake'])) return ($GLOBALS['csHttpFake'])($method, $url, $headers, $body);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
                            CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => true]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['status' => $code, 'body' => $resp === false ? '' : (string)$resp, 'error' => $err];
}

/** Capability token for the agent's browser phone. */
function csVoiceToken(array $user, ?array $s = null): array {
    $s ??= csVoiceSettings();
    if (!csVoiceEnabled($s)) return ['ok' => false, 'error' => 'Voice is not configured.'];
    $r = csHttp('POST', 'https://webrtc.africastalking.com/capability-token/request',
        ['apiKey: ' . $s['at_api_key'], 'Accept: application/json', 'Content-Type: application/json'],
        json_encode(['username' => $s['at_username'], 'clientName' => csAgentClientName($user['id']), 'phoneNumber' => $s['at_number'],
                     'incoming' => true, 'outgoing' => true, 'expire' => '43200s']));
    $d = json_decode($r['body'], true);
    if ($r['status'] >= 300 || empty($d['token'])) {
        error_log('Voice token error: HTTP ' . $r['status'] . ' ' . mb_substr($r['body'], 0, 300) . ' ' . $r['error']);
        return ['ok' => false, 'error' => 'Could not get a phone token from the provider.'];
    }
    return ['ok' => true, 'token' => $d['token'], 'clientName' => csAgentClientName($user['id']), 'lifetime' => (int)($d['lifeTimeSec'] ?? 43200)];
}

// ── Recordings ───────────────────────────────────────────────────────────────

/** Outside the web root, so recordings are only reachable through the permission-checked endpoint. */
function csRecordingsDir(): string {
    return defined('CS_RECORDINGS_DIR') ? CS_RECORDINGS_DIR : dirname(__DIR__, 2) . '/fieldops-recordings';
}

/** Downloads recordings not yet stored locally. Returns how many were saved. */
function csFetchPendingRecordings(int $limit = 20): int {
    $n = 0;
    foreach (dbFetchAll("SELECT id, recording_url, started_at FROM cs_calls WHERE recording_url IS NOT NULL AND recording_url <> ''
                         AND recording_path IS NULL AND recording_deleted_at IS NULL ORDER BY started_at LIMIT $limit") as $c) {
        if (!preg_match('#^https://#i', $c['recording_url'])) continue;
        $r = csHttp('GET', $c['recording_url'], []);
        if ($r['status'] !== 200 || $r['body'] === '') { error_log("Recording download failed for call {$c['id']}: HTTP {$r['status']}"); continue; }
        $rel = date('Y/m', strtotime($c['started_at'])) . '/' . $c['id'] . '.mp3';
        $abs = csRecordingsDir() . '/' . $rel;
        if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0750, true)) { error_log('Cannot create ' . dirname($abs)); break; }
        if (file_put_contents($abs, $r['body']) === false) continue;
        dbRun("UPDATE cs_calls SET recording_path = ? WHERE id = ?", [$rel, $c['id']]);
        $n++;
    }
    return $n;
}

/** Deletes recordings older than the retention period (files and links). Returns how many. */
function csPurgeOldRecordings(int $days): int {
    $n = 0;
    foreach (dbFetchAll("SELECT id, recording_path FROM cs_calls WHERE started_at < ? AND recording_deleted_at IS NULL
                         AND ((recording_path IS NOT NULL) OR (recording_url IS NOT NULL AND recording_url <> ''))",
                        [date('Y-m-d H:i:s', time() - $days * 86400)]) as $c) {
        if ($c['recording_path']) {
            $abs = csRecordingsDir() . '/' . $c['recording_path'];
            if (is_file($abs)) @unlink($abs);
        }
        dbRun("UPDATE cs_calls SET recording_path = NULL, recording_url = NULL, recording_deleted_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $c['id']]);
        $n++;
    }
    return $n;
}
