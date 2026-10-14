/* Rashid Ahm MCP - Live Studio.
   One toolbar and one big preview. The preview refreshes by itself when the site changes.
   Edit mode: click text or an image in the preview, then use the small bar that appears. */
(function () {
  'use strict';
  var C = window.RAHM || {};
  var T = function (s) { return (C.i18n && C.i18n[s]) || s; };
  var $ = function (id) { return document.getElementById(id); };
  var el = window.rahmEl;
  var api = function (p, m, b) { return window.rahmApi(C.rest, C.nonce, p, m, b); };

  var st = { pages: [], cur: null, seq: C.seq || 0, edit: false, sel: null, reloadT: null, scroll: 0, last: null };
  var iframe;

  function toast(msg, bad) {
    var t = el('div', 'rst-toast' + (bad ? ' bad' : ''), msg);
    t.setAttribute('role', 'status');
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 3600);
  }

  /* ---------- Pages and preview ---------- */
  function withBust(u) { return u + (u.indexOf('?') > -1 ? '&' : '?') + 'rahm_t=' + Date.now(); }

  function loadPages() {
    api('pages', 'GET').then(function (rows) {
      st.pages = rows;
      var sel = $('rst-page');
      sel.textContent = '';
      rows.forEach(function (r, i) { var o = el('option', '', r.title); o.value = i; sel.appendChild(o); });
      var want = new URLSearchParams(location.search).get('post'), idx = 0;
      if (want) { rows.forEach(function (r, i) { if (String(r.id) === want) { idx = i; } }); }
      sel.value = idx;
      openPage(idx);
    }).catch(function (e) { liveState(false, e); });
  }

  function openPage(i) {
    st.cur = st.pages[i];
    clearSel();
    var u = withBust(st.cur.url);
    diagnose(u);
    iframe.src = u;
  }

  function reloadPreview() {
    try { st.scroll = iframe.contentWindow.scrollY || 0; } catch (e) { st.scroll = 0; }
    var u;
    try { u = iframe.contentWindow.location.href.replace(/([?&])rahm_t=\d+&?/, '$1').replace(/[?&]$/, ''); } catch (e) { u = st.cur && st.cur.url; }
    u = withBust(u || (st.cur && st.cur.url) || C.home);
    diagnose(u);
    iframe.src = u;
  }

  function scheduleReload() { clearTimeout(st.reloadT); st.reloadT = setTimeout(reloadPreview, 500); }

  /* Show a clear message when the preview cannot be displayed (for example a 403) instead of a blank page. */
  function warn(lines, url) {
    var w = $('rst-warn');
    w.textContent = '';
    lines.forEach(function (l, i) { w.appendChild(el(i === 0 ? 'strong' : 'div', '', l)); });
    if (url) { var a = el('a', '', T('Open in a new tab')); a.href = url; a.target = '_blank'; a.rel = 'noopener'; w.appendChild(a); }
    w.hidden = false;
  }
  function unwarn() { $('rst-warn').hidden = true; }

  function diagnose(url) {
    unwarn();
    var same = false;
    try { same = new URL(url, location.href).origin === location.origin; } catch (e) {}
    fetch(url, { credentials: 'same-origin', cache: 'no-store', redirect: 'follow' }).then(function (r) {
      if (!r.ok) {
        warn([T('The preview cannot be shown.'), T('Your site answered with error') + ' ' + r.status + '.', T('The page may be password protected, blocked by a security rule or firewall, or the address is wrong. Open it in a new tab to see the real message.')], url);
        return;
      }
      var xfo = (r.headers.get('x-frame-options') || '').toLowerCase(), csp = (r.headers.get('content-security-policy') || '').toLowerCase();
      var deny = /\bdeny\b/.test(xfo) || (/sameorigin/.test(xfo) && !same) || (/frame-ancestors\s+'none'/.test(csp)) || (/frame-ancestors\s+'self'/.test(csp) && !same);
      if (deny) { warn([T('The preview cannot be shown.'), T('Your site does not allow itself to be shown inside another page (X-Frame-Options or frame-ancestors).')], url); }
    }).catch(function () { /* different domain: the browser hides the answer, so nothing more to say */ });
  }

  function onFrameLoad() {
    var doc;
    try { doc = iframe.contentDocument; void doc.body; } catch (e) { doc = null; }
    if (!doc || !doc.body) {
      st.canEdit = false;
      if ($('rst-warn').hidden) { warn([T('This site address differs from the admin address, so direct editing is blocked by the browser. The preview still refreshes live.')]); }
      return;
    }
    var head = (doc.title + ' ' + (doc.body.textContent || '').slice(0, 300)).toLowerCase();
    if (/\b403\b|forbidden|access denied/.test(head) && (doc.body.textContent || '').length < 1500) {
      warn([T('The preview cannot be shown.'), T('Your site answered with error') + ' 403.', T('The page may be password protected, blocked by a security rule or firewall, or the address is wrong. Open it in a new tab to see the real message.')], iframe.contentWindow.location.href);
    }
    if (st.scroll) { try { iframe.contentWindow.scrollTo(0, st.scroll); } catch (e) {} st.scroll = 0; }
    var cls = doc.body.className || '', m = cls.match(/(?:\bpage-id-|\bpostid-)(\d+)/) || cls.match(/\belementor-page-(\d+)/);
    if (m) {
      var id = parseInt(m[1], 10), found = -1;
      st.pages.forEach(function (p, i) { if (p.id === id) { found = i; } });
      if (found < 0) {
        st.pages.push({ id: id, title: (doc.title || ('#' + id)).replace(/\s[–|-].*$/, ''), url: iframe.contentWindow.location.href, builder: 'classic' });
        found = st.pages.length - 1;
        var o = el('option', '', st.pages[found].title); o.value = found; $('rst-page').appendChild(o);
      }
      st.cur = st.pages[found];
      $('rst-page').value = found;
    }
    if (st.edit) { armFrame(doc); }
  }

  /* ---------- Edit mode ---------- */
  var TEXT_TAGS = 'h1,h2,h3,h4,h5,h6,p,li,a,button,blockquote,figcaption,td,th,label,dt,dd,span,strong,em,b,i';

  function armFrame(doc) {
    if (doc.getElementById('rahm-edit-style')) { return; }
    var s = doc.createElement('style');
    s.id = 'rahm-edit-style';
    s.textContent = '.rahm-hover{outline:2px dashed #B08D57!important;outline-offset:2px;cursor:pointer!important}.rahm-sel{outline:3px solid #14213D!important;outline-offset:2px}[contenteditable="true"]{outline:3px solid #1E7F5C!important;cursor:text!important}';
    doc.head.appendChild(s);
    doc.addEventListener('mouseover', function (e) { if (!st.edit) { return; } var t = pick(e.target); doc.querySelectorAll('.rahm-hover').forEach(function (x) { x.classList.remove('rahm-hover'); }); if (t) { t.node.classList.add('rahm-hover'); } }, true);
    doc.addEventListener('click', function (e) {
      if (!st.edit) { return; }
      if (e.target.closest && e.target.closest('[contenteditable="true"]')) { return; }
      e.preventDefault(); e.stopPropagation();
      var t = pick(e.target);
      if (t) { select(t, doc); }
    }, true);
    doc.addEventListener('dblclick', function (e) {
      if (!st.edit) { return; }
      var t = pick(e.target);
      if (t && t.kind === 'text') { e.preventDefault(); inlineEdit(t); }
    }, true);
    doc.addEventListener('submit', function (e) { if (st.edit) { e.preventDefault(); } }, true);
  }

  function norm(s) { return (s || '').replace(/\s+/g, ' ').trim(); }

  function eid(n) { var e = n.closest && n.closest('.elementor-element[data-id]'); return e ? e.getAttribute('data-id') : ''; }

  /* Decide what the click means: an image or a piece of text. */
  function pick(node) {
    if (!node || !node.closest) { return null; }
    var img = node.tagName === 'IMG' ? node : node.closest('img');
    if (img) { return { kind: 'image', node: img, tag: 'img', src: img.currentSrc || img.src, text: '', eid: eid(img) }; }
    var t = node.closest(TEXT_TAGS);
    while (t && /^(SPAN|STRONG|EM|B|I)$/.test(t.tagName) && t.parentElement && t.parentElement.closest(TEXT_TAGS)) { t = t.parentElement.closest(TEXT_TAGS); }
    if (!t || t.tagName === 'BODY' || t.tagName === 'HTML') { return null; }
    var text = norm(t.innerText || t.textContent);
    if (!text) { return null; }
    return { kind: 'text', node: t, tag: t.tagName.toLowerCase(), text: text, eid: eid(t) };
  }

  function select(t, doc) {
    doc.querySelectorAll('.rahm-sel').forEach(function (x) { x.classList.remove('rahm-sel'); });
    t.node.classList.add('rahm-sel');
    st.sel = t;
    $('rst-selbar').hidden = false;
    $('rst-seltag').textContent = '<' + t.tag + '>' + (t.eid ? ' #' + t.eid : '') + (t.kind === 'text' ? '  ' + t.text.slice(0, 40) + (t.text.length > 40 ? '...' : '') : '');
    $('rst-b-text').hidden = t.kind !== 'text';
    $('rst-b-replace').hidden = t.kind !== 'image';
  }

  function clearSel() {
    st.sel = null;
    $('rst-selbar').hidden = true;
    try { iframe.contentDocument.querySelectorAll('.rahm-sel').forEach(function (x) { x.classList.remove('rahm-sel'); }); } catch (e) {}
  }

  function inlineEdit(t) {
    var n = t.node, before = t.text;
    n.setAttribute('contenteditable', 'true'); n.focus();
    var finish = function () {
      n.removeAttribute('contenteditable');
      n.removeEventListener('blur', finish);
      var after = norm(n.innerText || n.textContent);
      if (after && after !== before) { sendEdit('text', { old: before, new: after, eid: t.eid, tag: t.tag }); }
    };
    n.addEventListener('blur', finish);
    n.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { n.innerText = before; n.blur(); }
      else if (e.key === 'Enter' && !e.shiftKey && /^(H[1-6]|BUTTON|A|LI)$/.test(n.tagName)) { e.preventDefault(); n.blur(); }
    });
  }

  function sendEdit(op, data) {
    if (!st.cur || !st.cur.id) { toast(T('Select an element first.'), true); return Promise.resolve(null); }
    return api('edit', 'POST', Object.assign({ post_id: st.cur.id, op: op }, data)).then(function (r) {
      toast(T('Saved.') + ' ' + (r.summary || ''));
      poll();
      return r;
    }).catch(function (e) { toast(e.message, true); return null; });
  }

  function mediaPick(cb) {
    if (!window.wp || !wp.media) { toast('Media library not available', true); return; }
    var f = wp.media({ title: T('Choose an image'), button: { text: T('Use this image') }, multiple: false, library: { type: 'image' } });
    f.on('select', function () { var a = f.state().get('selection').first().toJSON(); cb({ id: a.id, url: a.url }); });
    f.open();
  }

  /* ---------- Live feed (no panel, just a status line and automatic refresh) ---------- */
  function liveState(ok, err) {
    var l = $('rst-live');
    l.classList.toggle('is-off', !ok);
    $('rst-livetxt').textContent = ok ? T('Live') : T('Offline') + (err && err.status ? ' (' + err.status + ')' : '');
    l.title = ok ? '' : (err && err.message ? err.message : '');
  }

  function showLast() {
    var e = st.last, p = $('rst-last');
    if (!e) { p.textContent = T('No changes yet.'); return; }
    p.textContent = T('Last change') + ': ' + e.by + ' \u00b7 ' + e.summary + ' \u00b7 ' + window.rahmAgo(e.t, T);
  }

  function poll() {
    if (document.hidden) { return; }
    api('feed?since=' + st.seq, 'GET').then(function (d) {
      liveState(true);
      var changed = false;
      (d.entries || []).forEach(function (e) { if (e.kind === 'change') { changed = true; } if (e.kind !== 'read') { st.last = e; } });
      st.seq = d.seq;
      showLast();
      if (changed) { scheduleReload(); }
    }).catch(function (e) { liveState(false, e); });
  }

  /* ---------- Start ---------- */
  document.addEventListener('DOMContentLoaded', function () {
    if (!$('rahm-studio') || window.__rahmStudioStarted) { return; }
    window.__rahmStudioStarted = true;
    iframe = $('rst-iframe');
    iframe.addEventListener('load', onFrameLoad);
    $('rst-page').addEventListener('change', function () { openPage(parseInt(this.value, 10)); });
    $('rst-reload').addEventListener('click', reloadPreview);
    document.querySelectorAll('.rst-devices button').forEach(function (b) {
      b.addEventListener('click', function () {
        document.querySelectorAll('.rst-devices button').forEach(function (x) { x.classList.toggle('is-on', x === b); });
        $('rst-frame').style.width = b.getAttribute('data-w');
      });
    });
    $('rst-edit').addEventListener('change', function () {
      st.edit = this.checked;
      $('rst-add').hidden = !st.edit;
      if (st.edit) { try { armFrame(iframe.contentDocument); } catch (e) {} $('rst-last').textContent = T('Click any text or image in the page. Double-click text to type in place.'); }
      else { clearSel(); showLast(); }
    });
    $('rst-b-cancel').addEventListener('click', clearSel);
    $('rst-b-text').addEventListener('click', function () { if (st.sel && st.sel.kind === 'text') { inlineEdit(st.sel); } });
    $('rst-b-del').addEventListener('click', function () {
      var s = st.sel;
      if (!s) { toast(T('Select an element first.'), true); return; }
      if (!confirm(T('Are you sure you want to delete this element?'))) { return; }
      sendEdit('delete', { old: s.text, tag: s.tag, src: s.src || '', eid: s.eid }).then(function (r) { if (r) { clearSel(); } });
    });
    $('rst-b-replace').addEventListener('click', function () {
      var s = st.sel;
      if (!s || s.kind !== 'image') { toast(T('Select an element first.'), true); return; }
      mediaPick(function (img) { sendEdit('image', { src: s.src, new_src: img.url, new_id: img.id, eid: s.eid }).then(function (r) { if (r) { clearSel(); } }); });
    });
    var addAfter = function () {
      var s = st.sel || {};
      mediaPick(function (img) { sendEdit('insert_image', { new_src: img.url, new_id: img.id, eid: s.eid || '', tag: s.tag || '', old: s.text || '' }); });
    };
    $('rst-b-after').addEventListener('click', addAfter);
    $('rst-add').addEventListener('click', addAfter);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
    loadPages();
    api('feed?since=-1', 'GET').then(function (d) {
      (d.entries || []).forEach(function (e) { if (e.kind !== 'read') { st.last = e; } });
      st.seq = d.seq; showLast();
    }).catch(function (e) { liveState(false, e); });
    setInterval(poll, 3000);
    setInterval(showLast, 30000);
  });
})();
