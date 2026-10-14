/* Rashid Ahm MCP - shared dashboard scripts: copy buttons and score buttons. */
(function () {
  'use strict';

  window.rahmApi = function (rest, nonce, path, method, body) {
    var opt = { method: method || 'GET', headers: { 'X-WP-Nonce': nonce }, credentials: 'same-origin' };
    if (body instanceof FormData) { opt.body = body; }
    else if (body) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
    return fetch(rest + path, opt).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { var err = new Error((j && (j.error || j.message)) || ('HTTP ' + r.status)); err.status = r.status; err.code = j && j.code; throw err; }
        return j;
      });
    });
  };

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text != null) { e.textContent = text; }
    return e;
  }
  window.rahmEl = el;

  window.rahmAgo = function (t, T) {
    var s = Math.max(0, Math.round(Date.now() / 1000 - t));
    T = T || function (x) { return x; };
    if (s < 10) { return T('just now'); }
    if (s < 60) { return s + ' ' + T('seconds ago'); }
    if (s < 3600) { return Math.round(s / 60) + ' ' + T('minutes ago'); }
    if (s < 86400) { return Math.round(s / 3600) + ' ' + T('hours ago'); }
    return new Date(t * 1000).toLocaleDateString();
  };

  /* Copy buttons */
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-copy]');
    if (!b) { return; }
    var f = b.parentNode.querySelector('input,textarea');
    if (!f) { return; }
    f.focus(); f.select();
    var done = function () { var o = b.textContent; b.textContent = '\u2713'; setTimeout(function () { b.textContent = o; }, 1400); };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(f.value).then(done, function () { document.execCommand('copy'); done(); }); }
    else { document.execCommand('copy'); done(); }
  });

  document.addEventListener('DOMContentLoaded', function () {
    var sc = document.getElementById('rahm-scores');
    if (sc) {
      var r2 = sc.getAttribute('data-rest'), n2 = sc.getAttribute('data-nonce');
      sc.addEventListener('click', function (e) {
        var b = e.target.closest('[data-run]');
        if (!b) { return; }
        b.disabled = true; b.textContent = '...';
        window.rahmApi(r2, n2, 'audit', 'POST', { role: b.getAttribute('data-run') }).then(function () { location.reload(); }).catch(function (x) { b.disabled = false; b.textContent = '!'; alert(x.message); });
      });
    }
  });
})();
