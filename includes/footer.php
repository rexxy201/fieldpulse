</div><!-- /page-content -->
</div><!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Notifications ─────────────────────────────────────────────────────────
(function() {
  let notifData = [];

  function timeAgo(dateStr) {
    const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
    if (diff < 60)   return 'just now';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    if (diff < 86400)return Math.floor(diff/3600) + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
  }

  function renderNotifs(data) {
    notifData = data.notifications || [];
    const badge  = document.getElementById('notifBadge');
    const list   = document.getElementById('notifList');
    if (!badge || !list) return;

    const unread = data.unread || 0;
    badge.textContent = unread > 9 ? '9+' : unread;
    badge.classList.toggle('d-none', unread === 0);

    if (!notifData.length) {
      list.innerHTML = '<div class="notif-empty"><i class="bi bi-bell-slash d-block mb-2 fs-4"></i>No notifications yet</div>';
      return;
    }
    list.innerHTML = notifData.map(n => {
      const isUnread = !n.is_read;
      const link = n.link || '#';
      return `<a class="notif-item ${isUnread ? 'unread' : ''}" href="${link}"
                 onclick="markRead('${n.id}', event)">
        <div class="notif-item-dot ${isUnread ? '' : 'read'}"></div>
        <div class="flex-grow-1 min-w-0">
          <div class="notif-item-title">${escH(n.title)}</div>
          <div class="notif-item-msg">${escH(n.message || '')}</div>
          <div class="notif-item-time">${timeAgo(n.created_at)}</div>
        </div>
      </a>`;
    }).join('');
  }

  function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function fetchNotifs() {
    fetch('/api/notifications')
      .then(r => r.json())
      .then(renderNotifs)
      .catch(() => {});
  }

  window.toggleNotifPanel = function() {
    const panel = document.getElementById('notifPanel');
    if (!panel) return;
    const opening = !panel.classList.contains('open');
    panel.classList.toggle('open', opening);
    if (opening) fetchNotifs();
  };

  window.markRead = function(id, e) {
    fetch('/api/notifications', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'mark_read', id})
    }).then(() => {
      const n = notifData.find(x => x.id === id);
      if (n) n.is_read = true;
      const unread = notifData.filter(x => !x.is_read).length;
      const badge = document.getElementById('notifBadge');
      if (badge) { badge.textContent = unread > 9 ? '9+' : unread; badge.classList.toggle('d-none', unread === 0); }
    });
  };

  window.markAllRead = function() {
    fetch('/api/notifications', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'mark_all_read'})
    }).then(() => {
      notifData.forEach(n => n.is_read = true);
      const badge = document.getElementById('notifBadge');
      if (badge) { badge.textContent = '0'; badge.classList.add('d-none'); }
      document.querySelectorAll('.notif-item').forEach(el => {
        el.classList.remove('unread');
        el.querySelector('.notif-item-dot')?.classList.add('read');
      });
    });
  };

  // Close panel when clicking outside
  document.addEventListener('click', e => {
    const wrap = document.getElementById('notifWrap');
    if (wrap && !wrap.contains(e.target)) {
      document.getElementById('notifPanel')?.classList.remove('open');
    }
  });

  // Initial badge load + poll every 60s
  fetchNotifs();
  setInterval(fetchNotifs, 60000);
})();

// ── CSRF: inject token into all POST forms + all fetch() calls ────────────
(function() {
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  if (!token) return;

  // Inject hidden input into every HTML form that uses POST
  document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(form => {
    if (!form.querySelector('input[name="_csrf"]')) {
      const inp = document.createElement('input');
      inp.type  = 'hidden';
      inp.name  = '_csrf';
      inp.value = token;
      form.prepend(inp);
    }
  });

  // Patch global fetch to always send CSRF header for same-origin requests
  const _origFetch = window.fetch.bind(window);
  window.fetch = function(input, init = {}) {
    const url = ((typeof input === 'string') ? input : (input && input.url)) || '';
    // Same-origin = anything that is NOT an absolute URL to a different origin
    // (covers '', relative paths, '/...', and same-origin absolute URLs).
    const isCrossOrigin = /^https?:\/\//i.test(url) && !url.startsWith(window.location.origin);
    if (!isCrossOrigin) {
      init.headers = Object.assign({ 'X-CSRF-Token': token }, init.headers || {});
    }
    return _origFetch(input, init);
  };
})();

// ── Mobile sidebar toggle ─────────────────────────────────────────────────
function toggleSidebar() {
  const sidebar   = document.getElementById('sidebar');
  const backdrop  = document.getElementById('sidebarBackdrop');
  const isOpen    = sidebar.classList.contains('open');
  if (isOpen) {
    closeSidebar();
  } else {
    sidebar.classList.add('open');
    backdrop.classList.add('visible');
    document.body.style.overflow = 'hidden';
  }
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sidebarBackdrop').classList.remove('visible');
  document.body.style.overflow = '';
}
// Close on nav link click (mobile UX)
document.querySelectorAll('#sidebar .nav-link').forEach(link => {
  link.addEventListener('click', () => {
    if (window.innerWidth <= 768) closeSidebar();
  });
});
// Close on Escape key
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeSidebar();
});
</script>
</body>
</html>
