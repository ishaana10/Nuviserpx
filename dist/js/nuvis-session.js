/* Tells the user when their session has ended, instead of leaving a click hanging on a dead page. */
(function () {
  'use strict';
  var url = window.NUVIS_PING, login = window.NUVIS_LOGIN;
  if (!url || !login) return;
  var expired = false, lastOk = Date.now(), checking = null, shown = false;

  function ping(cb) {
    var done = false;
    var x = new XMLHttpRequest();
    x.open('GET', url + '?_=' + Date.now(), true);
    x.timeout = 5000;
    function fin(ok) { if (done) return; done = true; if (ok !== null) { expired = !ok; if (ok) lastOk = Date.now(); } if (cb) cb(!expired); sync(); }
    x.onload = function () {
      var ok = null;
      try { ok = !!JSON.parse(x.responseText).ok; } catch (e) { ok = null; }   // not JSON: a maintenance or proxy page; do not guess
      fin(ok);
    };
    x.onerror = x.ontimeout = function () { fin(null); };
    x.send();
  }

  function sync() { if (expired) show(); else hide(); }

  function el() {
    var d = document.getElementById('nv-session-modal');
    if (d) return d;
    d = document.createElement('div');
    d.id = 'nv-session-modal';
    d.setAttribute('role', 'alertdialog');
    d.setAttribute('aria-modal', 'true');
    d.setAttribute('aria-labelledby', 'nv-sess-t');
    d.style.cssText = 'position:fixed;inset:0;z-index:20000;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.55)';
    d.innerHTML = '<div style="background:#fff;color:#1e293b;max-width:420px;width:92%;border-radius:8px;padding:24px;box-shadow:0 20px 50px rgba(0,0,0,.35)">' +
      '<h5 id="nv-sess-t" style="margin:0 0 8px;font-weight:600"><i class="fas fa-lock" style="margin-right:8px;color:#b45309"></i>Your session has ended</h5>' +
      '<p style="margin:0 0 16px;color:#475569">You were signed out, so this page can no longer save or load anything. Sign in again to carry on. ' +
      'If you have unsaved work here, sign in using a new tab, then come back and save.</p>' +
      '<div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">' +
      '<button type="button" id="nv-sess-stay" class="btn btn-default btn-sm">Stay on this page</button>' +
      '<a id="nv-sess-new" class="btn btn-outline-primary btn-sm" href="' + login + '" target="_blank" rel="noopener">Sign in in a new tab</a>' +
      '<a id="nv-sess-go" class="btn btn-primary btn-sm" href="' + login + '">Sign in again</a></div></div>';
    document.body.appendChild(d);
    d.querySelector('#nv-sess-stay').addEventListener('click', function () { d.style.display = 'none'; shown = false; });
    return d;
  }
  function show() { if (!document.body) return; var d = el(); if (!shown) { d.style.display = 'flex'; shown = true; var g = d.querySelector('#nv-sess-go'); if (g) g.focus(); } }
  function hide() { var d = document.getElementById('nv-session-modal'); if (d) { d.style.display = 'none'; } shown = false; }

  // Navigation and form clicks: block only when already known to be expired. A stale check runs in the
  // background and never delays the click; a dead session is caught by the page that loads next.
  function guard(e) {
    if (expired) { e.preventDefault(); e.stopImmediatePropagation(); show(); return; }
    if (Date.now() - lastOk >= 15000) ping();
  }

  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('a[href],button,input[type=submit]') : null;
    if (!t || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
    if (t.closest('#nv-session-modal')) return;
    if (t.tagName === 'A') {
      var h = t.getAttribute('href') || '';
      if (h === '' || h.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(h) || t.target === '_blank' || t.hasAttribute('data-toggle') || t.hasAttribute('data-widget') || t.hasAttribute('download')) return;
      if (/login\.php/.test(h)) return;
      guard(e);
    } else if (expired) {
      guard(e);
    }
  }, true);

  document.addEventListener('submit', function (e) {
    if (expired) { e.preventDefault(); e.stopImmediatePropagation(); show(); }
  }, true);

  // Any failed request is a cue to check; jQuery may not be loaded yet, so wait for it.
  function hookAjax() {
    if (!window.jQuery) return setTimeout(hookAjax, 200);
    jQuery(document).ajaxError(function () { ping(); });
    jQuery(document).ajaxSend(function (ev, xhr) { if (expired) { xhr.abort(); show(); } });
  }
  hookAjax();

  document.addEventListener('visibilitychange', function () { if (!document.hidden) ping(); });
  window.addEventListener('focus', function () { ping(); });
  setInterval(function () { if (!document.hidden) ping(); }, 60000);
})();
