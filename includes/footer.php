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

// ── Global search ────────────────────────────────────────────────────────
(function() {
  const input   = document.getElementById('globalSearchInput');
  const results = document.getElementById('globalSearchResults');
  if (!input || !results) return;

  const GROUP_LABELS = { tickets: 'Tickets', customers: 'Customers', installations: 'Installations' };
  let debounceTimer, activeIndex = -1, currentItems = [];

  function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function render(data) {
    const groups = data.results || {};
    currentItems = [];
    activeIndex = -1;
    const groupKeys = Object.keys(groups).filter(k => groups[k] && groups[k].length);

    if (!groupKeys.length) {
      results.innerHTML = '<div class="gs-empty">No matches.</div>';
      results.classList.remove('d-none');
      return;
    }
    let html = '';
    groupKeys.forEach(key => {
      html += `<div class="gs-group-label">${GROUP_LABELS[key] || key}</div>`;
      groups[key].forEach(item => {
        currentItems.push(item);
        html += `<a class="gs-item" href="${escH(item.url)}" data-idx="${currentItems.length - 1}">
          <div class="gs-item-title">${escH(item.title)}</div>
          <div class="gs-item-subtitle">${escH(item.subtitle || '')}</div>
        </a>`;
      });
    });
    results.innerHTML = html;
    results.classList.remove('d-none');
  }

  function search(q) {
    if (q.trim().length < 2) {
      results.classList.add('d-none');
      results.innerHTML = '';
      return;
    }
    fetch('/api/search?q=' + encodeURIComponent(q))
      .then(r => r.json())
      .then(render)
      .catch(() => {});
  }

  input.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    const q = input.value;
    debounceTimer = setTimeout(() => search(q), 300);
  });

  input.addEventListener('focus', () => {
    if (input.value.trim().length >= 2 && results.innerHTML) results.classList.remove('d-none');
  });

  // Keyboard navigation through the currently rendered list
  input.addEventListener('keydown', e => {
    const items = results.querySelectorAll('.gs-item');
    if (!items.length) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      activeIndex = Math.min(activeIndex + 1, items.length - 1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      activeIndex = Math.max(activeIndex - 1, 0);
    } else if (e.key === 'Enter') {
      if (activeIndex >= 0 && items[activeIndex]) { window.location.href = items[activeIndex].getAttribute('href'); }
      return;
    } else if (e.key === 'Escape') {
      results.classList.add('d-none');
      input.blur();
      return;
    } else {
      return;
    }
    items.forEach((el, i) => el.classList.toggle('active', i === activeIndex));
    items[activeIndex]?.scrollIntoView({ block: 'nearest' });
  });

  document.addEventListener('click', e => {
    const wrap = document.getElementById('globalSearchWrap');
    if (wrap && !wrap.contains(e.target)) results.classList.add('d-none');
  });

  // "/" focuses global search from anywhere, unless already typing in a field
  document.addEventListener('keydown', e => {
    if (e.key !== '/') return;
    const tag = (e.target.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || e.target.isContentEditable) return;
    e.preventDefault();
    input.focus();
  });
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
