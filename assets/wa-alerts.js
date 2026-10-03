/**
 * WhatsApp alerts on every page for support agents: keeps the sidebar unread
 * badge current and, when a new conversation starts waiting, plays a short
 * tone and shows a desktop notification (if the agent allowed them on the
 * WhatsApp page).
 */
(function () {
  var KEY = 'waUnreadSeen';
  var badges = [document.getElementById('waNavBadge'), document.getElementById('waNavBadgeGroup')];
  var onInbox = location.pathname === '/support/whatsapp';

  function seen() { try { return parseInt(sessionStorage.getItem(KEY) || '-1', 10); } catch (e) { return -1; } }
  function remember(n) { try { sessionStorage.setItem(KEY, String(n)); } catch (e) {} }

  function tone() {
    try {
      var ctx = new (window.AudioContext || window.webkitAudioContext)();
      var o = ctx.createOscillator(), g = ctx.createGain();
      o.frequency.value = 880; g.gain.value = 0.08;
      o.connect(g); g.connect(ctx.destination);
      o.start(); o.stop(ctx.currentTime + 0.25);
    } catch (e) {}
  }

  function alertNew(n) {
    tone();
    if (!('Notification' in window) || Notification.permission !== 'granted' || !document.hidden && onInbox) return;
    var note = new Notification('WhatsApp', { body: n === 1 ? 'A customer is waiting for a reply.' : n + ' conversations are waiting for a reply.', tag: 'support-wa' });
    note.onclick = function () { window.focus(); location.href = '/support/whatsapp'; note.close(); };
  }

  function show(n) {
    badges.forEach(function (b) {
      if (!b) return;
      b.textContent = n > 99 ? '99+' : String(n);
      b.classList.toggle('d-none', n === 0);
    });
  }

  function poll() {
    fetch('/api/support-whatsapp?action=latest', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        var n = d.unread || 0, prev = seen();
        show(n);
        if (prev >= 0 && n > prev) alertNew(n);
        remember(n);
      }).catch(function () {});
  }

  // SMS & email inbox badge (no sound: these are less urgent than chats).
  var inboxBadge = document.getElementById('inboxNavBadge');
  function pollInbox() {
    if (!inboxBadge) return;
    fetch('/api/support-inbox?action=latest', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        var n = d.unread || 0;
        inboxBadge.textContent = n > 99 ? '99+' : String(n);
        inboxBadge.classList.toggle('d-none', n === 0);
      }).catch(function () {});
  }

  poll(); pollInbox();
  setInterval(function () { poll(); pollInbox(); }, 20000);
})();
