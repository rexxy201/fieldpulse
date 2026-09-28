/* Support softphone: Africa's Talking browser client + app glue. */
(function () {
  'use strict';
  var root = document.getElementById('softphone');
  if (!root || typeof Africastalking === 'undefined') return;
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var $ = function (id) { return document.getElementById(id); };
  var client = null, inCall = false, timer = null, startedAt = 0, current = { number: '', customer: null };

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
    return api('lookup', { qs: '&number=' + encodeURIComponent(number) }).then(function (d) { return d.customer || null; }).catch(function () { return null; });
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
    $('spWith').textContent = number; renderCustomer($('spActiveCustomer'), customer);
    setState('active', 'On a call'); startTimer(); open(true);
  }
  function toIdle() {
    inCall = false; clearInterval(timer);
    $('spIncoming').hidden = true; $('spActive').hidden = true; $('spDial').hidden = false; $('spKeypad').hidden = true;
    $('spMute').textContent = 'Mute'; $('spHold').textContent = 'Hold';
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
        current = { number: from, customer: null };
        $('spInFrom').textContent = from; $('spInCustomer').textContent = 'Looking up…';
        $('spIncoming').hidden = false; $('spDial').hidden = true;
        setState('ringing', 'Incoming call'); open(true);
        lookup(from).then(function (c) { current.customer = c; renderCustomer($('spInCustomer'), c); });
      }, false);
      client.on('callaccepted', function () {
        if (root.dataset.state === 'ringing') api('answered', { body: { from: current.number } });
        toActive(current.number, current.customer);
      }, false);
      client.on('hangup', function () {
        var wasCall = inCall; toIdle();
        if (wasCall) showError('');
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
