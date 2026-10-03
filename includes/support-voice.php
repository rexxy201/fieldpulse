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
                            'missed' => 'Missed', 'voicemail' => 'Voicemail', 'queued' => 'On hold', 'callback' => 'Callback requested'];

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
        'hours'             => csParseSupportHours((string)($c['supportHours'] ?? '')),
        'closed_message'    => trim((string)($c['supportClosedMessage'] ?? '')) ?: 'Our office is closed right now.',
        // Routing (see csRouteCall)
        'overflow'          => ($c['voiceQueueOverflow'] ?? '1') === '1',
        'hold_enabled'      => ($c['voiceHoldEnabled'] ?? '1') === '1',
        'hold_max_minutes'  => max(1, min(30, (int)($c['voiceHoldMaxMinutes'] ?? 5))),
        'hold_music_url'    => preg_match('#^https://#i', (string)($c['voiceHoldMusicUrl'] ?? '')) ? (string)$c['voiceHoldMusicUrl'] : '',
        'wrapup_seconds'    => max(0, min(600, (int)($c['voiceWrapUpSeconds'] ?? 30))),
        // Self-service
        'outage_notice'     => ($c['voiceOutageNotice'] ?? '1') === '1',
        'ticket_status'     => ($c['voiceTicketStatus'] ?? '1') === '1',
    ];
}

// ── Business hours (shared by voice and WhatsApp) ────────────────────────────

const CS_WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

/** Stored JSON {"1":["08:00","17:00"],…} (ISO weekday → open, close) → validated array. Empty = always open. */
function csParseSupportHours(string $json): array {
    $out = [];
    foreach ((array)(json_decode($json, true) ?: []) as $d => $span) {
        $d = (int)$d;
        if (!isset(CS_WEEKDAYS[$d]) || !is_array($span) || count($span) !== 2) continue;
        [$o, $c] = array_map('strval', array_values($span));
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $o) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $c) && $o < $c) $out[$d] = [$o, $c];
    }
    return $out;
}

/** Whether the support line is staffed at $ts (default now). No hours configured = always open. */
function csSupportOpen(array $hours, ?int $ts = null): bool {
    if (!$hours) return true;
    $ts ??= time();
    $span = $hours[(int)date('N', $ts)] ?? null;
    if (!$span) return false;
    $now = date('H:i', $ts);
    return $now >= $span[0] && $now < $span[1];
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
 *   ['record' => ['text'=>…, 'maxLength'=>120, 'callBackUrl'=>…]], ['redirect' => url], ['reject' => true]
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
                      . ' callBackUrl="' . $e($v['callBackUrl']) . '"><Say>' . $e($v['text']) . '</Say>'
                      . (!empty($v['play']) ? '<Play url="' . $e($v['play']) . '"/>' : '') . '</GetDigits>';
                break;
            case 'redirect': $out .= '<Redirect>' . $e($v) . '</Redirect>'; break;
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

// ── Routing: menu teams, hold queue, callbacks ───────────────────────────────

/** Agents on each menu option's team: [digit => [user_id, …]]. A digit with no team is answered by everyone. */
function csQueueMembers(): array {
    $out = [];
    foreach (dbFetchAll("SELECT digit, user_id FROM cs_queue_members") as $r) $out[$r['digit']][] = $r['user_id'];
    return $out;
}

/** Agents free to ring now: set Available (in the last 12h) and past the wrap-up window of their last call. */
function csFreeAgentIds(): array {
    $now = date('Y-m-d H:i:s');
    return array_column(dbFetchAll("SELECT user_id FROM cs_agent_status WHERE status = 'available' AND changed_at >= ?
                                    AND (busy_until IS NULL OR busy_until <= ?) ORDER BY changed_at",
                                   [date('Y-m-d H:i:s', time() - 12 * 3600), $now]), 'user_id');
}

/** Agents signed in to take calls, free or not (a reason to hold rather than go to voicemail). */
function csAgentsSignedIn(): int {
    return (int)(dbFetch("SELECT COUNT(*) AS n FROM cs_agent_status WHERE status IN ('available','on_call','wrap_up') AND changed_at >= ?",
                         [date('Y-m-d H:i:s', time() - 12 * 3600)])['n'] ?? 0);
}

/**
 * Browser clients to ring for a menu option: its team's free agents; when the
 * whole team is busy and overflow is on (or the option has no team), any free agent.
 */
function csAvailableAgentTargets(array $s, ?string $digit = null): array {
    $free = csFreeAgentIds();
    $team = ($digit !== null && $digit !== '') ? (csQueueMembers()[$digit] ?? []) : [];
    if ($team) {
        $inTeam = array_values(array_intersect($free, $team));
        if ($inTeam || !($s['overflow'] ?? true)) $free = $inTeam;
    }
    return array_map(fn($id) => $s['at_username'] . '.' . csAgentClientName($id), $free);
}

function csVoicemailAction(array $s, string $lead = 'All our agents are busy.'): array {
    return ['record' => ['text' => $lead . ' Please leave a message with your name and account number after the beep, and we will call you back.',
                         'maxLength' => 120, 'callBackUrl' => csVoiceCallbackUrl('voicemail', $s)]];
}

/** Ring these agents together; if nobody picks up, the caller goes back to the hold queue. */
function csDialActions(array $s, array $clients, ?array $call): array {
    $a = [['dial' => ['numbers' => $clients, 'record' => $s['record'], 'sequential' => false, 'callerId' => $s['at_number'], 'maxDuration' => 3600]]];
    if ($call && ($s['hold_enabled'] ?? false)) $a[] = ['redirect' => csVoiceCallbackUrl('hold', $s)];
    return $a;
}

/**
 * Where an inbound call goes: free agents (team first) → hold queue while
 * agents are signed in but busy → fallback mobiles in order → voicemail.
 */
function csRouteCall(array $s, ?array $call): array {
    $clients = csAvailableAgentTargets($s, $call['queue_digit'] ?? null);
    if ($clients) return csDialActions($s, $clients, $call);
    if ($call && ($s['hold_enabled'] ?? false) && csAgentsSignedIn() > 0) return csHoldActions($s, $call, true);
    $numbers = array_map('csE164', $s['fallback_numbers']);
    if ($numbers) return [['dial' => ['numbers' => $numbers, 'record' => $s['record'], 'sequential' => true, 'callerId' => $s['at_number'], 'maxDuration' => 3600]]];
    return [csVoicemailAction($s)];
}

/** 1 = next. Counts callers for the same menu option who started holding earlier. */
function csQueuePosition(array $call): int {
    return 1 + (int)(dbFetch("SELECT COUNT(*) AS n FROM cs_calls WHERE status = 'queued' AND id <> ? AND COALESCE(queue_digit, '') = ?
                              AND queued_at < ? AND started_at >= ?",
        [$call['id'], (string)($call['queue_digit'] ?? ''), $call['queued_at'] ?? date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() - 3600)])['n'] ?? 0);
}

/** One round of holding (~20s): position, optional music, "press 1 for a callback", then check again. */
function csHoldActions(array $s, array $call, bool $first): array {
    if ($call['status'] !== 'queued') {
        dbRun("UPDATE cs_calls SET status = 'queued', queued_at = COALESCE(queued_at, ?) WHERE id = ?", [date('Y-m-d H:i:s'), $call['id']]);
        $call = dbFetch("SELECT * FROM cs_calls WHERE id = ?", [$call['id']]);
    }
    $pos  = csQueuePosition($call);
    $text = ($first ? 'All our agents are busy right now. ' : '')
          . ($pos > 1 ? "You are number $pos in line. " : 'You are next in line. ')
          . 'Please hold, or press 1 and we will call you back.';
    return [['getDigits' => ['text' => $text, 'play' => $s['hold_music_url'] ?? '', 'numDigits' => 1, 'timeout' => 20,
                             'callBackUrl' => csVoiceCallbackUrl('hold_key', $s)]],
            ['redirect' => csVoiceCallbackUrl('hold', $s)]];
}

// ── Self-service ─────────────────────────────────────────────────────────────

/** The customer's latest unresolved ticket, or null. */
function csOpenTicketFor(?string $customerId): ?array {
    if (!$customerId) return null;
    return dbFetch("SELECT id, ticket_number, status, created_at FROM tickets WHERE customer_id = ? AND status NOT IN ('resolved','closed')
                    ORDER BY created_at DESC LIMIT 1", [$customerId]);
}

/** A POP serving the customer's hub that is down right now, or null. */
function csHubOutage(?string $customerId): ?array {
    if (!$customerId) return null;
    try {
        return dbFetch("SELECT p.name, p.down_since FROM noc_pops p JOIN customers c ON c.hub_id = p.hub_id
                        WHERE c.id = ? AND p.enabled = 1 AND p.status = 'down' ORDER BY p.down_since LIMIT 1", [$customerId]);
    } catch (\Throwable $e) { return null; }
}

/** Menu key for "status of my fault report": 9, or the first of 8/0 when 9 is a menu option. */
function csTicketDigit(array $s): string {
    foreach (['9', '8', '0'] as $d) if (!isset($s['ivr_options'][$d])) return $d;
    return '';
}

function csTicketStatusSpeech(array $t): string {
    $words = ['new' => 'has been received', 'open' => 'is open', 'assigned' => 'has been assigned to an engineer', 'in_progress' => 'is being worked on',
              'pending_confirmation' => 'has been fixed and is waiting for your confirmation'];
    $num = trim((string)$t['ticket_number']) !== '' ? ' number ' . implode(' ', str_split(preg_replace('/[^A-Za-z0-9]/', '', $t['ticket_number']))) : '';
    return "Your fault report$num, opened on " . date('j F', strtotime($t['created_at'])) . ', ' . ($words[$t['status']] ?? 'is open') . '.';
}

/** Spoken before the menu: a known outage in the caller's area. */
function csSelfServiceNotices(array $s, ?array $call): array {
    if (!$call || !($s['outage_notice'] ?? false) || !($o = csHubOutage($call['customer_id']))) return [];
    $since = $o['down_since'] ? ' since ' . date('g:i a', strtotime($o['down_since'])) : '';
    return [['say' => "We are aware of a network outage affecting your area$since. Our engineers are working on it."]];
}

function csMenuActions(array $s, ?array $call): array {
    $parts = array_map(fn($d, $l) => "For $l, press $d.", array_keys($s['ivr_options']), $s['ivr_options']);
    if ($call && ($s['ticket_status'] ?? false) && csTicketDigit($s) !== '' && csOpenTicketFor($call['customer_id'])) {
        $parts[] = 'To hear the status of your fault report, press ' . csTicketDigit($s) . '.';
    }
    // No key pressed within the timeout: the provider carries on to the redirect (general queue).
    return [['getDigits' => ['text' => implode(' ', $parts), 'numDigits' => 1, 'timeout' => 8, 'callBackUrl' => csVoiceCallbackUrl('menu', $s)]],
            ['redirect' => csVoiceCallbackUrl('menu', $s)]];
}

// ── The call flow ────────────────────────────────────────────────────────────

/** After a call: the agent is Available again once the wrap-up window passes. */
function csEndAgentCall(?string $agentId, array $s): void {
    if (!$agentId || csAgentStatus($agentId) !== 'on_call') return;
    dbRun("UPDATE cs_agent_status SET status = 'available', changed_at = ?, busy_until = ? WHERE user_id = ?",
        [date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + (int)($s['wrapup_seconds'] ?? 30)), $agentId]);
}

/**
 * Handles one provider callback and returns the XML to send back ('' when the
 * provider expects no instructions, e.g. the end-of-call notification).
 * Steps: answer (call start and end), menu, hold, hold_key, voicemail.
 */
function csHandleVoiceCallback(array $post, string $step, ?array $s = null): string {
    $s  ??= csVoiceSettings();
    $s   += ['overflow' => true, 'hold_enabled' => false, 'hold_max_minutes' => 5, 'hold_music_url' => '', 'wrapup_seconds' => 30,
             'outage_notice' => false, 'ticket_status' => false, 'hours' => []];
    $ev   = csParseVoiceCallback($post);
    $sid  = $ev['session_id'];
    if ($sid === '') return '';
    $call = dbFetch("SELECT * FROM cs_calls WHERE session_id = ?", [$sid]);
    $now  = date('Y-m-d H:i:s');
    $bye  = csVoiceXml([['say' => 'Thank you for calling. Goodbye.']]);

    // End of call: record the outcome.
    if (!$ev['is_active'] && $step === 'answer') {
        if (!$call) return '';
        // Inbound: answered only once an agent's browser reported it (the
        // duration also counts time in the menu). Outbound: the callee picked up.
        $answered = $call['answered_at'] !== null || ($call['direction'] === 'outbound' && ($ev['duration'] ?? 0) > 0);
        $status   = in_array($call['status'], ['voicemail', 'callback'], true) ? $call['status'] : ($answered ? 'completed' : 'missed');
        dbRun("UPDATE cs_calls SET status = ?, ended_at = ?, duration_sec = ?, recording_url = COALESCE(NULLIF(?, ''), recording_url),
               hangup_cause = ?, cost = ?, currency = ? WHERE id = ?",
            [$status, $now, $ev['duration'], $ev['recording_url'], mb_substr($ev['hangup_cause'], 0, 60), $ev['amount'] ?: null, mb_substr($ev['currency'], 0, 5), $call['id']]);
        if ($status === 'missed' && $call['direction'] === 'inbound') {
            csCreateCallFollowup($call, $call['status'] === 'queued' ? 'Caller hung up while on hold — call them back.' : 'Missed call — call the customer back.', $s);
        }
        csEndAgentCall($call['agent_id'], $s);
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
        $digit = $ev['dtmf'];
        if ($call && $s['ticket_status'] && $digit !== '' && $digit === csTicketDigit($s) && ($t = csOpenTicketFor($call['customer_id']))) {
            return csVoiceXml(array_merge([['say' => csTicketStatusSpeech($t)]], csMenuActions($s, $call)));
        }
        $label = $s['ivr_options'][$digit] ?? null;
        if ($call && $label) {
            dbRun("UPDATE cs_calls SET ivr_choice = ?, queue_digit = ? WHERE id = ?", [$label, $digit, $call['id']]);
            $call['ivr_choice'] = $label; $call['queue_digit'] = $digit;
        }
        return csVoiceXml(array_merge([['say' => $label ? "Connecting you to $label." : 'Connecting you to an agent.']], csRouteCall($s, $call)));
    }

    if ($step === 'hold') {
        // Back from a Dial that was answered and has ended: don't queue the caller again.
        if (!$call || $call['answered_at'] !== null || !in_array($call['status'], ['ringing', 'queued'], true)) return $bye;
        if (time() - strtotime($call['queued_at'] ?? $call['started_at']) >= $s['hold_max_minutes'] * 60) {
            return csVoiceXml([csVoicemailAction($s, 'Sorry to keep you waiting.')]);
        }
        $clients = csAvailableAgentTargets($s, $call['queue_digit']);
        if ($clients && ($call['status'] !== 'queued' || csQueuePosition($call) <= count($clients))) {
            return csVoiceXml(array_merge([['say' => 'Connecting you now.']], csDialActions($s, $clients, $call)));
        }
        if (csAgentsSignedIn() === 0) return csVoiceXml([csVoicemailAction($s, 'Sorry, no agent is free right now.')]);
        return csVoiceXml(csHoldActions($s, $call, $call['status'] !== 'queued'));
    }

    if ($step === 'hold_key') {
        if (!$call) return $bye;
        if ($ev['dtmf'] === '1') {
            dbRun("UPDATE cs_calls SET status = 'callback', callback_requested = 1 WHERE id = ?", [$call['id']]);
            csCreateCallFollowup($call, 'Callback requested while on hold — call the customer back.', $s);
            return csVoiceXml([['say' => 'Thank you. We will call you back as soon as an agent is free. Goodbye.']]);
        }
        return csVoiceXml(csHoldActions($s, $call, false));
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

    if (!$call) {
        csInsertCall($sid, 'inbound', csE164($ev['caller']), $ev['destination'], null);
        $call = dbFetch("SELECT * FROM cs_calls WHERE session_id = ?", [$sid]);
    }
    $intro = [['say' => rtrim($s['greeting']) . ($s['record'] ? ' ' . $s['announcement'] : '')]];
    // Outside business hours: straight to voicemail (a follow-up for the next shift), no ringing.
    if (!csSupportOpen($s['hours'] ?? [])) {
        return csVoiceXml(array_merge($intro, [csVoicemailAction($s, $s['closed_message'] ?? 'Our office is closed right now.')]));
    }
    $intro = array_merge($intro, csSelfServiceNotices($s, $call));
    if ($s['ivr_enabled'] && $s['ivr_options']) return csVoiceXml(array_merge($intro, csMenuActions($s, $call)));
    return csVoiceXml(array_merge($intro, csRouteCall($s, $call)));
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

/** Missed call / voicemail / callback request → a follow-up owned by the configured person (else the first supervisor). */
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

/** The agent's browser reports it answered: attach the ringing (or holding) call from that number to them. */
function csMarkCallAnswered(string $fromNumber, string $agentId): ?string {
    $key = csPhoneKey($fromNumber);
    foreach (dbFetchAll("SELECT id, from_number FROM cs_calls WHERE direction = 'inbound' AND status IN ('ringing','queued') AND started_at >= ? ORDER BY started_at DESC LIMIT 10",
                        [date('Y-m-d H:i:s', time() - 3600)]) as $c) {
        if (csPhoneKey($c['from_number']) === $key) {
            dbRun("UPDATE cs_calls SET status = 'in_progress', agent_id = ?, answered_at = ? WHERE id = ?", [$agentId, date('Y-m-d H:i:s'), $c['id']]);
            return $c['id'];
        }
    }
    return null;
}

/**
 * Everything the agent needs when a call rings: the customer, their open
 * tickets, recent contacts, open follow-ups, an outage in their area, and
 * what the caller chose in the menu / how long they held.
 */
function csCallerContext(string $number): array {
    $out = ['customer' => null, 'call' => null];
    $key = csPhoneKey($number);
    foreach (dbFetchAll("SELECT id, from_number, ivr_choice, queued_at, callback_requested FROM cs_calls WHERE direction = 'inbound'
                         AND status IN ('ringing','queued','in_progress') AND started_at >= ? ORDER BY started_at DESC LIMIT 10",
                        [date('Y-m-d H:i:s', time() - 3600)]) as $c) {
        if (csPhoneKey($c['from_number']) === $key) {
            $out['call'] = ['id' => $c['id'], 'choice' => $c['ivr_choice'], 'held_sec' => $c['queued_at'] ? max(0, time() - strtotime($c['queued_at'])) : null];
            break;
        }
    }
    $c = csFindCustomerByPhone($number);
    if (!$c) return $out;
    $full = dbFetch("SELECT c.id, c.name, c.account_number, c.plan, c.status, c.address, h.name AS hub FROM customers c LEFT JOIN hubs h ON h.id = c.hub_id WHERE c.id = ?", [$c['id']]);
    $out['customer'] = [
        'id' => $full['id'], 'name' => $full['name'], 'account' => $full['account_number'], 'plan' => $full['plan'],
        'status' => $full['status'], 'address' => $full['address'], 'hub' => $full['hub'],
        'tickets' => dbFetchAll("SELECT id, ticket_number, status, created_at FROM tickets WHERE customer_id = ? AND status NOT IN ('resolved','closed') ORDER BY created_at DESC LIMIT 3", [$c['id']]),
        'recent' => dbFetchAll("SELECT i.created_at, i.channel, i.summary, i.agent_name, w.name AS reason FROM cs_interactions i LEFT JOIN cs_wrap_codes w ON w.id = i.wrap_code_id
                                WHERE i.customer_id = ? ORDER BY i.created_at DESC LIMIT 3", [$c['id']]),
        'followups' => (int)(dbFetch("SELECT COUNT(*) AS n FROM cs_followups WHERE customer_id = ? AND status = 'open'", [$c['id']])['n'] ?? 0),
        'outage' => csHubOutage($c['id']),
    ];
    foreach ($out['customer']['recent'] as &$r) $r['summary'] = mb_strimwidth((string)$r['summary'], 0, 140, '…');
    return $out;
}

/** Live hold queue for the supervisor dashboard: [digit|'' => ['waiting' => n, 'longest' => sec]]. */
function csQueueSnapshot(): array {
    $out = [];
    foreach (dbFetchAll("SELECT COALESCE(queue_digit, '') AS d, COUNT(*) AS n, MIN(queued_at) AS first FROM cs_calls
                         WHERE status = 'queued' AND started_at >= ? GROUP BY COALESCE(queue_digit, '')", [date('Y-m-d H:i:s', time() - 3600)]) as $r) {
        $out[$r['d']] = ['waiting' => (int)$r['n'], 'longest' => $r['first'] ? max(0, time() - strtotime($r['first'])) : 0];
    }
    return $out;
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

/**
 * Hands a live inbound call to another agent's browser phone or a mobile
 * number (Africa's Talking call transfer: the agent's leg is replaced, the
 * caller stays connected). $target: 'agent:<user id>' or a phone number.
 */
function csTransferCall(array $call, string $target, array $by, ?array $s = null): array {
    $s ??= csVoiceSettings();
    if (!csVoiceEnabled($s)) return ['ok' => false, 'error' => 'Voice is not configured.'];
    if ($call['direction'] !== 'inbound' || $call['status'] !== 'in_progress') return ['ok' => false, 'error' => 'Only a live inbound call can be transferred.'];
    $toAgent = null;
    if (str_starts_with($target, 'agent:')) {
        $toAgent = dbFetch("SELECT id, name FROM users WHERE id = ? AND status = 'active'", [substr($target, 6)]);
        if (!$toAgent || $toAgent['id'] === $by['id']) return ['ok' => false, 'error' => 'Pick another agent.'];
        $dest = $s['at_username'] . '.' . csAgentClientName($toAgent['id']);
        $label = $toAgent['name'];
    } else {
        $dest = csE164($target);
        if (!preg_match('/^\+\d{7,15}$/', $dest)) return ['ok' => false, 'error' => 'Enter a valid phone number.'];
        $label = $dest;
    }
    $r = csHttp('POST', 'https://voice.africastalking.com/callTransfer',
        ['apiKey: ' . $s['at_api_key'], 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['sessionId' => $call['session_id'], 'phoneNumber' => $dest, 'callLeg' => 'callee']));
    $d = json_decode($r['body'], true) ?: [];
    $status = strtolower((string)($d['status'] ?? ''));
    if ($r['status'] >= 300 || $r['status'] === 0 || in_array($status, ['aborted', 'failed', 'error'], true) || !empty($d['errorMessage'])) {
        error_log('Call transfer error: HTTP ' . $r['status'] . ' ' . mb_substr($r['body'], 0, 300) . ' ' . $r['error']);
        return ['ok' => false, 'error' => 'The provider refused the transfer' . (!empty($d['errorMessage']) ? ': ' . mb_substr((string)$d['errorMessage'], 0, 120) : '.')];
    }
    dbRun("UPDATE cs_calls SET transferred_to = ?, agent_id = COALESCE(?, agent_id) WHERE id = ?", [mb_substr($label, 0, 100), $toAgent['id'] ?? null, $call['id']]);
    csEndAgentCall($by['id'], $s);
    if ($toAgent) csSetAgentStatus($toAgent['id'], 'on_call');
    try { auditLog('update', 'cs_call', $call['id'], "Transferred by {$by['name']} to $label"); } catch (\Throwable $e) {}
    return ['ok' => true, 'to' => $label];
}

// ── Recordings ───────────────────────────────────────────────────────────────

/** Outside the web root, so recordings are only reachable through the permission-checked endpoint. */
function csRecordingsDir(): string {
    return defined('CS_RECORDINGS_DIR') ? CS_RECORDINGS_DIR : dirname(__DIR__, 2) . '/fieldpulse-recordings';
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
