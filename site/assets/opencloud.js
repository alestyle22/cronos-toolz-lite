/* assets/opencloud.js — OpenCloud en Toolz (integraciones/opencloud_api.php). Lo carga topbar.php solo si la integración está conectada.
   1) Botón "OpenCloud" en los cuadros de mensaje (chat, tickets de Trabajo y solicitudes del portal): elegir archivos y pegar su link.
   2) Tarjeta "Carpeta en OpenCloud" en la ficha del cliente (pestaña Archivos): crear o elegir la carpeta, ver lo que tiene, abrirla y compartirla.
   Sin código en línea (CSP). Doc: toolz/INTEGRACIONES.md */
(function () {
  'use strict';
  var API = '/toolz/integraciones/opencloud_api.php', ST = null, stP = null;
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var api = function (action, data) {
    return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-INT': '1' }, body: JSON.stringify(Object.assign({ action: action }, data || {})) })
      .then(function (r) { return r.json().catch(function () { return { error: 'OpenCloud respondió algo inesperado.' }; }).then(function (j) { if (!r.ok || j.error) throw new Error(j.error || 'No se pudo completar.'); return j; }); });
  };
  var status = function () { if (!stP) stP = api('status').then(function (s) { ST = s; return s; }).catch(function () { ST = { on: false }; return ST; }); return stP; };
  var size = function (b) { if (!b) return ''; var u = ['B', 'KB', 'MB', 'GB']; var i = 0; while (b >= 1024 && i < 3) { b /= 1024; i++; } return (i ? b.toFixed(b < 10 ? 1 : 0) : b) + ' ' + u[i]; };
  var icon = function (it) {
    if (it.dir) return 'fa-folder';
    var m = it.mime || '', n = (it.name || '').toLowerCase();
    if (/^image\//.test(m)) return 'fa-file-image'; if (/^video\//.test(m)) return 'fa-file-video'; if (/^audio\//.test(m)) return 'fa-file-audio';
    if (/pdf/.test(m)) return 'fa-file-pdf'; if (/zip|rar|7z|tar/.test(m + n)) return 'fa-file-zipper';
    if (/sheet|excel|\.xlsx?$|\.csv$/.test(m + n)) return 'fa-file-excel'; if (/word|\.docx?$/.test(m + n)) return 'fa-file-word'; if (/presentation|\.pptx?$/.test(m + n)) return 'fa-file-powerpoint';
    return 'fa-file';
  };
  var toast = function (msg, err) {
    var t = document.createElement('div'); t.className = 'oc-toast' + (err ? ' err' : ''); t.textContent = msg; document.body.appendChild(t);
    setTimeout(function () { t.classList.add('on'); }, 10); setTimeout(function () { t.classList.remove('on'); setTimeout(function () { t.remove(); }, 300); }, err ? 4200 : 2400);
  };

  // ── Selector (archivos o una carpeta) ──────────────────────
  /** opts: { mode: 'files' | 'folder', title, path, onPick(items, public) } */
  function picker(opts) {
    var dlg = document.createElement('dialog'); dlg.className = 'oc-dlg'; document.body.appendChild(dlg);
    var S = { path: opts.path || '/', sel: {}, pub: ST && ST.link_mode === 'public', busy: false };
    var close = function () { dlg.close(); dlg.remove(); };
    var crumbs = function (p) {
      var parts = p.split('/').filter(Boolean), acc = '';
      return '<button type="button" data-go="/"><i class="fa-solid fa-cloud"></i> ' + esc(ST.root) + '</button>' + parts.map(function (x) { acc += '/' + x; return '<i class="fa-solid fa-chevron-right"></i><button type="button" data-go="' + esc(acc) + '">' + esc(x) + '</button>'; }).join('');
    };
    var draw = function (d) {
      var n = Object.keys(S.sel).length;
      dlg.innerHTML = '<div class="oc-in"><div class="oc-h"><h3><i class="fa-solid fa-cloud"></i> ' + esc(opts.title || 'Adjuntar desde OpenCloud') + '</h3><button type="button" class="oc-x" data-x aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>'
        + '<div class="oc-crumbs">' + crumbs(S.path) + '</div>'
        + '<div class="oc-list" role="list">' + (d ? (d.items.length ? d.items.map(function (it) {
            var on = !!S.sel[it.path], pick = opts.mode === 'files' && !it.dir;
            return '<div class="oc-row' + (on ? ' on' : '') + '" role="listitem" data-path="' + esc(it.path) + '" data-dir="' + (it.dir ? 1 : 0) + '">'
              + (pick ? '<span class="oc-ck"><i class="fa-solid fa-check"></i></span>' : '<span class="oc-ck none"></span>')
              + '<i class="fa-solid ' + icon(it) + ' oc-ic' + (it.dir ? ' dir' : '') + '"></i><span class="oc-name">' + esc(it.name) + '</span>'
              + '<span class="oc-meta">' + (it.dir ? '' : esc(size(it.size))) + '</span><span class="oc-meta">' + esc(it.modified || '') + '</span>' + (it.dir ? '<i class="fa-solid fa-chevron-right oc-go"></i>' : '<span></span>') + '</div>';
          }).join('') : '<div class="oc-empty"><i class="fa-regular fa-folder-open"></i>Esta carpeta está vacía.</div>') + (d.more ? '<div class="oc-empty">Hay más de 500 elementos: se muestran los primeros.</div>' : '')
          : '<div class="oc-empty"><i class="fa-solid fa-spinner fa-spin"></i>Cargando…</div>') + '</div>'
        + '<div class="oc-f">' + (opts.mode === 'files' ? '<label class="oc-pub"><input type="checkbox" id="oc-pub"' + (S.pub ? ' checked' : '') + '> Link público <small>(lo abre cualquiera, solo lectura)</small></label>' : '<span class="oc-sel">' + esc(S.path === '/' ? ST.root : S.path) + '</span>')
        + '<span class="oc-sp"></span><button type="button" class="oc-b" data-x>Cancelar</button>'
        + (opts.mode === 'files' ? '<button type="button" class="oc-b pri" id="oc-ok"' + (n ? '' : ' disabled') + '><i class="fa-solid fa-paperclip"></i> Adjuntar' + (n ? ' (' + n + ')' : '') + '</button>'
          : '<button type="button" class="oc-b pri" id="oc-ok"' + (S.path === '/' ? ' disabled title="Elegí una carpeta adentro de ' + esc(ST.root) + '"' : '') + '><i class="fa-solid fa-folder-open"></i> Usar esta carpeta</button>') + '</div></div>';
    };
    var load = function (p) {
      S.path = p; draw(null);
      api('list', { path: p }).then(function (d) { S.last = d; draw(d); }).catch(function (e) { dlg.querySelector('.oc-list').innerHTML = '<div class="oc-empty err"><i class="fa-solid fa-triangle-exclamation"></i>' + esc(e.message) + '</div>'; });
    };
    dlg.addEventListener('click', function (e) {
      if (e.target === dlg || e.target.closest('[data-x]')) { close(); return; }
      var go = e.target.closest('[data-go]'); if (go) { load(go.dataset.go); return; }
      var row = e.target.closest('.oc-row');
      if (row) {
        if (row.dataset.dir === '1') { load(row.dataset.path); return; }
        if (opts.mode !== 'files') return;
        var it = (S.last.items || []).filter(function (x) { return x.path === row.dataset.path; })[0];
        if (S.sel[row.dataset.path]) delete S.sel[row.dataset.path]; else S.sel[row.dataset.path] = it;
        draw(S.last); return;
      }
      if (e.target.closest('#oc-ok') && !S.busy) {
        if (opts.mode === 'folder') { close(); opts.onPick(S.path); return; }
        S.busy = true; var b = dlg.querySelector('#oc-ok'); b.disabled = true; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Preparando los links…';
        var list = Object.keys(S.sel).map(function (k) { return S.sel[k]; });
        Promise.all(list.map(function (it) { return api('link', { path: it.path, public: S.pub }).then(function (l) { return Object.assign({}, it, l); }); }))
          .then(function (links) { close(); opts.onPick(links, S.pub); })
          .catch(function (er) { S.busy = false; toast(er.message, true); draw(S.last); });
      }
    });
    dlg.addEventListener('change', function (e) { if (e.target.id === 'oc-pub') S.pub = e.target.checked; });
    dlg.addEventListener('cancel', function () { dlg.remove(); });
    draw(null); dlg.showModal(); load(S.path);
  }

  // ── Botón en los cuadros de mensaje ────────────────────────
  var insert = function (ta, text) {
    var a = ta.selectionStart == null ? ta.value.length : ta.selectionStart, b = ta.selectionEnd == null ? a : ta.selectionEnd;
    var pre = ta.value.slice(0, a), sep = pre && !/\s$/.test(pre) ? '\n' : '';
    ta.value = pre + sep + text + ta.value.slice(b);
    var pos = (pre + sep + text).length; ta.focus(); try { ta.setSelectionRange(pos, pos); } catch (e) {}
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  };
  // Dónde va el botón y cómo se escribe el link en cada cuadro (el chat entiende [texto](link); tickets y solicitudes muestran el link clicable)
  var TARGETS = [
    { sel: '#cc-ta, #cc-tta', place: function (ta) { return { parent: ta.parentNode, before: ta }; }, cls: 'cc-ib oc-trig', label: '<i class="fa-solid fa-cloud"></i>', md: true },
    { sel: 'form#wk-reply textarea[name="body"]', place: function (ta) { var r = ta.form.querySelector('.row:not(.top)'); return r ? { parent: r, before: r.firstChild } : null; }, cls: 'tk-b oc-trig', label: '<i class="fa-solid fa-cloud"></i> OpenCloud' },
    { sel: '#pt-msg', place: function (ta) { var r = document.querySelector('#pt-compose .pt-compose-a'); var l = r && r.querySelector('label'); return r ? { parent: r, before: l ? l.nextSibling : r.firstChild } : null; }, cls: 'wk-btn sm oc-trig', label: '<i class="fa-solid fa-cloud"></i> OpenCloud' },
  ];
  var enhance = function () {
    if (!ST || !ST.can) return;
    TARGETS.forEach(function (t) {
      document.querySelectorAll(t.sel).forEach(function (ta) {
        if (ta.dataset.oc) return; var p = t.place(ta); if (!p) return; ta.dataset.oc = '1';
        var b = document.createElement('button'); b.type = 'button'; b.className = t.cls; b.innerHTML = t.label;
        b.title = 'Adjuntar desde OpenCloud'; b.setAttribute('aria-label', 'Adjuntar desde OpenCloud');
        b.addEventListener('click', function (e) {
          e.preventDefault(); e.stopPropagation();
          picker({ mode: 'files', onPick: function (links) {
            insert(ta, links.map(function (l) { return t.md ? '[📎 ' + l.name.replace(/[\[\]]/g, '') + '](' + l.url + ')' : '📎 ' + l.name + ': ' + l.url; }).join('\n'));
            toast(links.length === 1 ? 'Link de OpenCloud agregado al mensaje' : links.length + ' links de OpenCloud agregados al mensaje');
          } });
        });
        p.parent.insertBefore(b, p.before || null);
      });
    });
    document.querySelectorAll('#cli-oc[data-client]').forEach(function (box) { if (!box.dataset.ocOn) { box.dataset.ocOn = '1'; clientPanel(box, +box.dataset.client); } });
  };

  // ── Carpeta del cliente (ficha › Archivos) ─────────────────
  function clientPanel(box, cid) {
    if (!ST.clients) { box.remove(); return; }
    var S = { sub: '/' };
    var head = '<div class="oc-card-h"><span class="oc-logo"><i class="fa-solid fa-cloud"></i></span><div><b>Carpeta en OpenCloud</b><small>' + esc(ST.server) + '</small></div></div>';
    var render = function (d) {
      if (!d.folder) {
        box.innerHTML = '<div class="oc-card">' + head + '<p class="oc-muted">Este cliente todavía no tiene carpeta en OpenCloud. Se crea en <b>' + esc(ST.root + d.suggest) + '</b>, o podés elegir una que ya exista.</p>'
          + '<div class="oc-acts"><button type="button" class="oc-b pri" data-oc="create"><i class="fa-solid fa-folder-plus"></i> Crear la carpeta</button>' + (d.can_pick ? '<button type="button" class="oc-b" data-oc="pick"><i class="fa-solid fa-folder-tree"></i> Elegir una carpeta</button>' : '') + '</div></div>';
        return;
      }
      var f = d.folder, l = d.list;
      var rel = S.sub === '/' ? '' : S.sub, parts = rel.split('/').filter(Boolean), acc = '';
      box.innerHTML = '<div class="oc-card">' + head
        + '<div class="oc-path"><i class="fa-regular fa-folder"></i> ' + esc(ST.root + f.path) + '</div>'
        + (!f.exists ? '<p class="oc-warn"><i class="fa-solid fa-triangle-exclamation"></i> La carpeta ya no existe en OpenCloud (la movieron o la borraron). Creala de nuevo o elegí otra.</p>' : '')
        + '<div class="oc-acts">' + (f.private_url ? '<a class="oc-b" href="' + esc(f.private_url) + '" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-arrow-up-right-from-square"></i> Abrir en OpenCloud</a>' : '')
        + (f.exists ? '<button type="button" class="oc-b" data-oc="share"><i class="fa-solid fa-link"></i> ' + (f.public_url ? 'Copiar link para compartir' : 'Crear link para compartir') + '</button>' : '<button type="button" class="oc-b pri" data-oc="create"><i class="fa-solid fa-folder-plus"></i> Crear de nuevo</button>')
        + (d.can_pick ? '<button type="button" class="oc-b" data-oc="pick"><i class="fa-solid fa-folder-tree"></i> Cambiar carpeta</button>' : '')
        + '<button type="button" class="oc-b ghost" data-oc="unlink"><i class="fa-solid fa-link-slash"></i> Desvincular</button></div>'
        + (f.public_url ? '<div class="oc-share"><i class="fa-solid fa-globe"></i><input readonly value="' + esc(f.public_url) + '" aria-label="Link para compartir"><button type="button" class="oc-b" data-oc="copy">Copiar</button></div>' : '')
        + (l ? '<div class="oc-crumbs sm"><button type="button" data-sub="/"><i class="fa-regular fa-folder"></i> ' + esc(f.path.split('/').pop()) + '</button>' + parts.map(function (x) { acc += '/' + x; return '<i class="fa-solid fa-chevron-right"></i><button type="button" data-sub="' + esc(acc) + '">' + esc(x) + '</button>'; }).join('') + '</div>'
          + '<div class="oc-list sm">' + (l.items.length ? l.items.map(function (it) {
            return '<div class="oc-row" data-path="' + esc(it.path) + '" data-dir="' + (it.dir ? 1 : 0) + '"><i class="fa-solid ' + icon(it) + ' oc-ic' + (it.dir ? ' dir' : '') + '"></i><span class="oc-name">' + esc(it.name) + '</span><span class="oc-meta">' + (it.dir ? '' : esc(size(it.size))) + '</span><span class="oc-meta">' + esc(it.modified || '') + '</span>'
              + (it.dir ? '<i class="fa-solid fa-chevron-right oc-go"></i>' : '<button type="button" class="oc-mini" data-open="' + esc(it.path) + '" title="Abrir en OpenCloud" aria-label="Abrir ' + esc(it.name) + ' en OpenCloud"><i class="fa-solid fa-arrow-up-right-from-square"></i></button>') + '</div>';
          }).join('') : '<div class="oc-empty"><i class="fa-regular fa-folder-open"></i>Vacía. Subí los archivos desde OpenCloud.</div>') + '</div>' : '')
        + '</div>';
    };
    var load = function () {
      box.innerHTML = '<div class="oc-card">' + head + '<div class="oc-empty"><i class="fa-solid fa-spinner fa-spin"></i>Cargando…</div></div>';
      api('client_get', { client_id: cid }).then(function (d) { S.d = d; S.sub = '/'; render(d); })
        .catch(function (e) { box.innerHTML = '<div class="oc-card">' + head + '<p class="oc-warn"><i class="fa-solid fa-triangle-exclamation"></i> ' + esc(e.message) + '</p></div>'; });
    };
    var sub = function (s) {
      api('client_list', { client_id: cid, sub: s }).then(function (l) { S.sub = l.sub; S.d.list = l; render(S.d); }).catch(function (e) { toast(e.message, true); });
    };
    box.addEventListener('click', function (e) {
      var b = e.target.closest('[data-oc]'), sb = e.target.closest('[data-sub]'), row = e.target.closest('.oc-row'), op = e.target.closest('[data-open]');
      if (op) {
        api('link', { path: op.dataset.open, public: false }).then(function (l) { window.open(l.url, '_blank', 'noopener'); }).catch(function (er) { toast(er.message, true); });
        return;
      }
      if (sb) { sub(sb.dataset.sub); return; }
      if (row && row.dataset.dir === '1') { var base = S.d.folder.path.replace(/\/$/, ''); sub(row.dataset.path.slice(base.length) || '/'); return; }
      if (!b) return;
      var a = b.dataset.oc;
      if (a === 'create') { b.disabled = true; api('client_create', { client_id: cid }).then(function () { toast('Carpeta creada en OpenCloud'); load(); }).catch(function (er) { b.disabled = false; toast(er.message, true); }); }
      if (a === 'pick') picker({ mode: 'folder', title: 'Elegir la carpeta del cliente', path: S.d.folder && S.d.folder.exists ? S.d.folder.path : '/', onPick: function (p) {
        api('client_set', { client_id: cid, path: p }).then(function () { toast('Carpeta vinculada'); load(); }).catch(function (er) { toast(er.message, true); });
      } });
      if (a === 'unlink' && confirm('¿Desvincular la carpeta? En OpenCloud no se borra nada.')) api('client_unlink', { client_id: cid }).then(function () { toast('Carpeta desvinculada'); load(); }).catch(function (er) { toast(er.message, true); });
      if (a === 'share') {
        if (S.d.folder.public_url) { copy(S.d.folder.public_url); return; }
        b.disabled = true; api('client_share', { client_id: cid }).then(function (r) { S.d.folder.public_url = r.url; render(S.d); copy(r.url); }).catch(function (er) { b.disabled = false; toast(er.message, true); });
      }
      if (a === 'copy') copy(S.d.folder.public_url);
    });
    var copy = function (u) {
      (navigator.clipboard ? navigator.clipboard.writeText(u) : Promise.reject()).then(function () { toast('Link copiado: lo abre cualquiera con el link, solo para ver y descargar'); })
        .catch(function () { var i = box.querySelector('.oc-share input'); if (i) { i.select(); toast('Seleccioná y copiá el link'); } });
    };
    load();
  }

  window.G7OpenCloud = { picker: function (o) { return status().then(function () { picker(o); }); } };
  status().then(function (s) {
    if (!s.on) return;
    enhance();
    var q = false;   // los cambios de la página (el chat cambia seguido) se juntan en un cuadro
    new MutationObserver(function () { if (q) return; q = true; requestAnimationFrame(function () { q = false; enhance(); }); }).observe(document.body, { childList: true, subtree: true });
  });
})();
