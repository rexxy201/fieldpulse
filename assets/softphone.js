/* Support softphone: Africa's Talking browser client + app glue. */
(function () {
  'use strict';
  var root = document.getElementById('softphone');
  if (!root || typeof Africastalking === 'undefined') return;
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var $ = function (id) { return document.getElementById(id); };
  var client = null, inCall = false, timer = null, startedAt = 0, current = { number: '', customer: null, call: null, callId: null };

  function api(action, opts) {
    opts = opts || {};
    return fetch('/api/support-voice?action=' + action + (opts.qs || ''), {
      method: opts.body ? 'POST' : (opts.method || 'GET'),
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: opts.body ? JSON.stringify(opts.body) : undefined,
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }
  function setState(state, label) { root.dataset.state = state; $('spLabel').textContent = label; }
  function showError(msg) { var e = $('spError'); e.textContent = msg || ''; e.hidden = !msg; }
  function open(on) { $('spPanel').hidden = !on; $('spToggle').setAttribute('aria-expanded', on ? 'true' : 'false'); }
  function customerLine(c) { return c ? c.name + ' · ' + c.account : 'Not a known customer'; }
  function renderCustomer(el, c) {
    el.textContent = '';
    if (!c) { el.textContent = 'Not a known customer'; return; }
    var a = document.createElement('a');
    a.href = '/support?customer=' + encodeURIComponent(c.id); a.target = '_blank'; a.rel = 'noopener';
    a.textContent = customerLine(c) + ' ↗';
    el.appendChild(a);
  }
  function lookup(number) {
    return api('lookup', { qs: '&number=' + encodeURIComponent(number) })
      .then(function (d) { current.call = d.call || null; return d.customer || null; })
      .catch(function () { return null; });
  }
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function ago(iso) {
    var s = Math.max(0, (Date.now() - new Date(String(iso).replace(' ', 'T')).getTime()) / 1000);
    return s < 3600 ? Math.round(s / 60) + 'm ago' : s < 86400 ? Math.round(s / 3600) + 'h ago' : Math.round(s / 86400) + 'd ago';
  }
  // Caller details under the name: menu choice, hold time, outage, plan, tickets, recent contacts.
  function renderContext(box, c, call) {
    box.textContent = '';
    if (call && (call.choice || call.held_sec)) {
      box.appendChild(el('div', 'text-muted', [call.choice ? 'Chose: ' + call.choice : '', call.held_sec ? 'held ' + Math.round(call.held_sec / 60) + ' min' : ''].filter(Boolean).join(' · ')));
    }
    if (!c) return;
    if (c.outage) box.appendChild(el('div', 'text-danger fw-semibold', '⚠ Outage in their area: ' + c.outage.name + ' POP down'));
    box.appendChild(el('div', '', [c.plan, c.status, c.hub].filter(Boolean).join(' · ')));
    if (c.address) box.appendChild(el('div', 'text-muted text-truncate', c.address));
    (c.tickets || []).forEach(function (t) {
      var a = el('a', 'd-block', '🎫 ' + (t.ticket_number || 'Ticket') + ' — ' + String(t.status).replace(/_/g, ' ') + ' (' + ago(t.created_at) + ')');
      a.href = '/ticket/' + encodeURIComponent(t.id); a.target = '_blank'; a.rel = 'noopener';
      box.appendChild(a);
    });
    if (c.followups) box.appendChild(el('div', 'text-warning', c.followups + ' open follow-up' + (c.followups > 1 ? 's' : '')));
    (c.recent || []).forEach(function (r) {
      box.appendChild(el('div', 'text-muted', ago(r.created_at) + ' · ' + (r.reason || r.channel) + (r.summary ? ': ' + r.summary : '')));
    });
  }
  function startTimer() {
    startedAt = Date.now(); clearInterval(timer);
    timer = setInterval(function () {
      var s = Math.floor((Date.now() - startedAt) / 1000);
      $('spTimer').textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
    }, 1000);
  }
  function toActive(number, customer) {
    inCall = true; current = { number: number, customer: customer };
    $('spIncoming').hidden = true; $('spActive').hidden = false; $('spDial').hidden = true;
    $('spWith').textContent = number; renderCustomer($('spActiveCustomer'), customer); renderContext($('spActContext'), customer, current.call);
    $('spXfer').hidden = !current.callId;
    setState('active', 'On a call'); startTimer(); open(true);
  }
  function toIdle() {
    inCall = false; clearInterval(timer);
    $('spIncoming').hidden = true; $('spActive').hidden = true; $('spDial').hidden = false; $('spKeypad').hidden = true;
    $('spMute').textContent = 'Mute'; $('spHold').textContent = 'Hold';
    $('spXferBox').hidden = true; $('spXfer').hidden = true; $('spInContext').textContent = ''; $('spActContext').textContent = '';
    setState('ready', 'Phone: ready');
  }

  function connect() {
    var cached = null;
    try { cached = JSON.parse(sessionStorage.getItem('spToken') || 'null'); } catch (e) {}
    var tokenP = cached && cached.expires > Date.now() + 60000
      ? Promise.resolve(cached)
      : api('token', { body: {} }).then(function (d) {
          if (!d.token) throw new Error(d.error || 'No token');
          var t = { token: d.token, expires: Date.now() + (d.lifetime || 43200) * 1000 };
          try { sessionStorage.setItem('spToken', JSON.stringify(t)); } catch (e) {}
          return t;
        });
    tokenP.then(function (t) {
      client = new Africastalking.Client(t.token);
      client.on('ready', function () { toIdle(); }, false);
      client.on('notready', function () { setState('error', 'Phone: not ready'); }, false);
      client.on('offline', function () { try { sessionStorage.removeItem('spToken'); } catch (e) {} setState('error', 'Phone: offline — reload'); }, false);
      client.on('closed', function () { setState('error', 'Phone: disconnected — reload'); }, false);
      client.on('incomingcall', function (p) {
        var from = (p && p.from) || 'Unknown number';
        current = { number: from, customer: null, call: null, callId: null };
        $('spInFrom').textContent = from; $('spInCustomer').textContent = 'Looking up…';
        $('spIncoming').hidden = false; $('spDial').hidden = true;
        setState('ringing', 'Incoming call'); open(true);
        lookup(from).then(function (c) { current.customer = c; renderCustomer($('spInCustomer'), c); renderContext($('spInContext'), c, current.call); });
      }, false);
      client.on('callaccepted', function () {
        if (root.dataset.state === 'ringing') {
          api('answered', { body: { from: current.number } }).then(function (d) {
            current.callId = d.call_id || null; $('spXfer').hidden = !current.callId || !inCall;
          });
        }
        toActive(current.number, current.customer);
      }, false);
      client.on('hangup', function () {
        var wasCall = inCall; toIdle();
        if (wasCall) { showError(''); api('ended', { body: {} }); }
      }, false);
    }).catch(function (e) { setState('error', 'Phone: unavailable'); showError(e.message); });
  }

  $('spToggle').addEventListener('click', function () { open($('spPanel').hidden); });
  $('spAnswer').addEventListener('click', function () { client && client.answer(); });
  $('spDecline').addEventListener('click', function () { client && client.hangup(); toIdle(); });
  $('spHangup').addEventListener('click', function () { client && client.hangup(); });
  $('spMute').addEventListener('click', function () {
    if (!client) return;
    if (this.textContent === 'Mute') { client.mute(); this.textContent = 'Unmute'; } else { client.unmute(); this.textContent = 'Mute'; }
  });
  $('spHold').addEventListener('click', function () {
    if (!client) return;
    if (this.textContent === 'Hold') { client.hold(); this.textContent = 'Resume'; } else { client.unhold(); this.textContent = 'Hold'; }
  });
  $('spKeys').addEventListener('click', function () { $('spKeypad').hidden = !$('spKeypad').hidden; });
  $('spXfer').addEventListener('click', function () {
    var box = $('spXferBox'); box.hidden = !box.hidden;
    if (box.hidden) return;
    var sel = $('spXferAgent');
    sel.length = 1;
    api('agents').then(function (d) {
      (d.agents || []).forEach(function (a) { var o = document.createElement('option'); o.value = 'agent:' + a.id; o.textContent = a.name; sel.appendChild(o); });
      if (!(d.agents || []).length) sel.options[0].textContent = 'No free agents';
    });
  });
  $('spXferGo').addEventListener('click', function () {
    var target = $('spXferAgent').value || $('spXferNumber').value.trim();
    if (!target || !current.callId) return;
    showError('');
    api('transfer', { body: { call_id: current.callId, target: target } }).then(function (d) {
      if (d.ok) { showError(''); $('spXferBox').hidden = true; setState('active', 'Transferred to ' + d.to); }
      else showError(d.error || 'Transfer failed');
    }).catch(function () { showError('Transfer failed'); });
  });
  document.querySelectorAll('#spKeypad [data-dtmf]').forEach(function (b) {
    b.addEventListener('click', function () { client && client.dtmf(b.dataset.dtmf); });
  });
  function dial(number) {
    number = (number || '').replace(/[^\d+]/g, '');
    if (!client || !number) return;
    if (number.charAt(0) === '0') number = '+234' + number.slice(1);
    else if (number.charAt(0) !== '+') number = '+' + number;
    showError('');
    lookup(number).then(function (c) { client.call(number); toActive(number, c); });
  }
  $('spDial').addEventListener('submit', function (e) { e.preventDefault(); dial($('spNumber').value); });
  // Click-to-call: any element with data-call="<number>".
  document.addEventListener('click', function (e) {
    var el = e.target.closest && e.target.closest('[data-call]');
    if (el) { e.preventDefault(); if (!inCall) dial(el.getAttribute('data-call')); }
  });
  window.addEventListener('beforeunload', function (e) { if (inCall) { e.preventDefault(); e.returnValue = ''; } });
  connect();
})();
