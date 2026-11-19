/* assets/perfil-sesiones.js — Mi perfil › Dónde iniciaste sesión (perfil_sesiones.php ↔ sesiones_api.php).
   ?ingreso=ID (desde el aviso de ingreso nuevo, en la campana o el email) muestra arriba ese ingreso con "Fui yo" / "No fui yo".
   El mapa (Leaflet, el mismo de resto/geo.js) se carga recién cuando la sección está a la vista. */
(() => {
  const $ = id => document.getElementById(id);
  const sec = $('sec-sesiones'); if (!sec) return;
  const e = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  async function api(action, data = {}) {
    const r = await fetch('/toolz/sesiones_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Sess': '1' }, credentials: 'same-origin', body: JSON.stringify({ action, ...data }) });
    const j = await r.json().catch(() => ({ error: 'El servidor no respondió bien.' }));
    if (r.status === 401) { location.href = '/toolz/login.php'; throw new Error(j.error); }
    if (j.error) throw new Error(j.error); return j;
  }
  const toast = (m, bad) => (typeof cfgToast === 'function' ? cfgToast(m, bad) : alert(m));
  const T = window.G7Time;
  const ms = s => s * 1000;
  const dt = s => T ? T.dateTime(ms(s)) : new Date(ms(s)).toLocaleString('es-AR');
  const hm = s => T ? T.time(ms(s)) : new Date(ms(s)).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
  const dayLabel = s => T && T.dayLabel ? T.dayLabel(ms(s)) : new Date(ms(s)).toLocaleDateString('es-AR', { weekday: 'long', day: 'numeric', month: 'long' });
  const ago = s => {
    const d = Math.max(0, Date.now() / 1000 - s);
    if (d < 120) return 'Activa ahora';
    if (d < 3600) return `Hace ${Math.round(d / 60)} min`;
    if (d < 86400) return `Hace ${Math.round(d / 3600)} h`;
    return dt(s);
  };
  const reduced = () => matchMedia('(prefers-reduced-motion: reduce)').matches;
  const icon = k => k === 'mobile' ? 'fa-mobile-screen' : k === 'tablet' ? 'fa-tablet-screen-button' : 'fa-desktop';
  const place = r => r.place ? e(r.place) : (r.cc ? e(r.cc) : 'Ubicación desconocida');
  const title = r => r.name ? `${e(r.name)} <small>${e(r.label)}</small>` : e(r.label);
  const METHOD = { '2fa': 'Con código', recovery: 'Código de respaldo', trusted: 'Dispositivo de confianza' };
  const STATUS = {
    current: '<span class="ses-tag ok">Este navegador</span>', active: '<span class="ses-tag ok">Abierta</span>',
    expired: '<span class="ses-tag">Vencida</span>', closed: '<span class="ses-tag">Cerrada</span>',
  };
  const ENDED = { remote: 'Cerrada a distancia', not_me: 'Cerrada: no fuiste vos', password: 'Cerrada al cambiar la contraseña', admin: 'Cerrada por un administrador', locked: 'Cerrada al bloquear la cuenta', logout: 'Cerrada' };
  const status = r => r.status === 'remote' ? `<span class="ses-tag ${r.ended_by === 'not_me' ? 'bad' : ''}">${e(ENDED[r.ended_by] || 'Cerrada a distancia')}</span>` : (STATUS[r.status] || '');
  const ALERT = { device: '<span class="ses-tag new">Dispositivo nuevo</span>', country: '<span class="ses-tag new">País nuevo</span>' };
  const tags = r => (ALERT[r.alert] || '') + (METHOD[r.method] ? `<span class="ses-tag">${METHOD[r.method]}</span>` : '')
    + (r.confirmed === 'me' ? '<span class="ses-tag ok">Fuiste vos</span>' : r.confirmed === 'not_me' ? '<span class="ses-tag bad">No fuiste vos</span>' : '');
  const asks = r => r.alert && !r.confirmed && r.status !== 'current';
  const pen = r => `<button class="ses-pen" data-rename="${r.id}" title="Ponerle un nombre" aria-label="Ponerle un nombre a este dispositivo"><i class="fa-solid fa-pen"></i></button>`;

  let D = null, hist = [], focus = +(new URLSearchParams(location.search).get('ingreso') || 0), reviewDone = null, renaming = 0;

  function renderActive() {
    const box = $('ses-active'), a = D.active || [];
    if (!a.length) { box.innerHTML = '<div class="ses-empty">No hay sesiones abiertas.</div>'; $('ses-active-foot').style.display = 'none'; return; }
    box.innerHTML = a.map(r => {
      const live = r.status === 'current' || ago(r.last_seen) === 'Activa ahora';
      const t = renaming === r.id
        ? `<input class="ses-name-in" id="ses-name-in" maxlength="60" placeholder="${e(r.label)}" value="${e(r.name)}"> <button class="btn btn-primary" data-save-name="${r.id}" style="font-size:11.5px;padding:4px 10px">Guardar</button>`
        : `${title(r)} ${pen(r)} ${r.status === 'current' ? '<span class="ses-tag ok">Este navegador</span>' : ''}`;
      return `<div class="ses-row" data-row="${r.id}">
        <div class="ses-ic ${live || Date.now() / 1000 - r.last_seen < 600 ? 'on' : ''}"><i class="fa-solid ${icon(r.kind)}"></i></div>
        <div class="ses-main"><div class="ses-t">${t}</div>
          <div class="ses-d">${place(r)} · ${live ? '<span class="ses-now">Activa ahora</span>' : 'Última actividad: ' + e(ago(r.last_seen))}<br>IP ${e(r.last_ip || r.ip)} · Entró el ${e(dt(r.created))}</div></div>
        ${r.status === 'current' ? '' : `<button class="btn btn-ghost" data-end="${r.id}"><i class="fa-solid fa-right-from-bracket"></i> Cerrar sesión</button>`}
      </div>`;
    }).join('');
    $('ses-active-foot').style.display = a.some(r => r.status !== 'current') ? '' : 'none';
    const inp = $('ses-name-in'); if (inp) { inp.focus(); inp.select(); }
  }

  function renderHist() {
    const box = $('ses-hist');
    if (!hist.length) { box.innerHTML = '<div class="ses-empty">Todavía no hay ingresos registrados.</div>'; $('ses-more').style.display = 'none'; return; }
    let last = '';
    box.innerHTML = hist.map(r => {
      const day = dayLabel(r.created), head = day !== last ? `<div class="ses-day">${e(day)}</div>` : ''; last = day;
      return `${head}<div class="ses-row${r.id === focus ? ' hl' : ''}" data-row="${r.id}" id="ses-h-${r.id}">
        <div class="ses-ic"><i class="fa-solid ${icon(r.kind)}"></i></div>
        <div class="ses-main"><div class="ses-t">${title(r)} ${tags(r)}</div>
          <div class="ses-d"><b>${place(r)}</b> · IP ${e(r.ip)}</div>
          ${asks(r) ? `<div class="ses-act"><span class="ses-d" style="margin:0 4px 0 0">¿Fuiste vos?</span>
            <button class="btn btn-ghost" data-me="${r.id}"><i class="fa-solid fa-check"></i> Fui yo</button>
            <button class="btn btn-ghost" data-notme="${r.id}" style="color:#ef4444"><i class="fa-solid fa-triangle-exclamation"></i> No fui yo</button></div>` : ''}
        </div>
        <div class="ses-st">${e(hm(r.created))}<br>${status(r)}</div>
      </div>`;
    }).join('');
    $('ses-more').style.display = D.more ? '' : 'none';
  }

  function renderFailed() {
    const f = D.failed || [];
    $('ses-failed-card').style.display = f.length ? '' : 'none';
    $('ses-failed').innerHTML = f.map(x => `<div class="ses-row">
        <div class="ses-ic warn"><i class="fa-solid ${x.codes ? 'fa-key' : 'fa-lock'}"></i></div>
        <div class="ses-main"><div class="ses-t">${x.n} ${x.n === 1 ? 'intento fallido' : 'intentos fallidos'}${x.codes ? ` <span class="ses-tag bad">${x.codes} con la contraseña correcta</span>` : ''}</div>
          <div class="ses-d"><b>${x.place ? e(x.place) : 'Ubicación desconocida'}</b> · IP ${e(x.ip)} · el último, ${e(dt(x.last))}</div>
          ${x.codes ? '<div class="ses-d" style="color:#fca5a5">Alguien escribió bien tu contraseña pero no el código. Si no fuiste vos, <a href="#" data-pw="1" style="color:inherit;font-weight:600">cámbiala ahora</a>.</div>' : ''}</div>
      </div>`).join('');
  }

  function renderTrust() {
    const t = D.trusted || [];
    $('ses-trust-card').style.display = t.length ? '' : 'none';
    $('ses-trust').innerHTML = t.map(x => `<div class="ses-row">
        <div class="ses-ic"><i class="fa-solid fa-shield-halved"></i></div>
        <div class="ses-main"><div class="ses-t">${e(x.label)} ${x.here ? '<span class="ses-tag ok">Este navegador</span>' : ''}</div>
          <div class="ses-d">${x.place ? e(x.place) + ' · ' : ''}IP ${e(x.ip)} · Usado por última vez ${e(dt(x.used))} · vence el ${e(T ? T.date(ms(x.expires)) : new Date(ms(x.expires)).toLocaleDateString('es-AR'))}</div></div>
        <button class="btn btn-ghost" data-untrust="${x.id}">Quitar</button>
      </div>`).join('');
  }

  function renderReview() {
    const box = $('ses-review');
    if (reviewDone) { box.innerHTML = reviewDone; return; }
    const r = focus && hist.find(x => x.id === focus);
    if (!r) { box.innerHTML = ''; return; }
    const open = r.status === 'active' || r.status === 'current';
    const why = r.alert === 'country' ? 'Es la primera vez que tu cuenta entra desde ese país.' : r.alert === 'device' ? 'Es un dispositivo que no habías usado.' : '';
    box.innerHTML = `<div class="cfg-card ses-review"><div style="padding:16px 18px;display:flex;gap:14px;align-items:flex-start">
        <div class="ses-ic" style="width:44px;height:44px;font-size:18px;color:var(--g7-accent)"><i class="fa-solid fa-user-shield"></i></div>
        <div style="flex:1;min-width:0"><h4>¿Fuiste vos?</h4>
          <div class="ses-d" style="font-size:12.5px">Ingreso desde <b>${title(r)}</b>, ${place(r)}, IP ${e(r.ip)}, el ${e(dt(r.created))}. ${e(why)} ${open ? 'La sesión sigue abierta.' : 'Esa sesión ya no está abierta.'}</div>
          ${r.status === 'current' ? '<div class="ses-d" style="margin-top:6px">Es el navegador que estás usando ahora.</div>' : r.confirmed ? `<div class="ses-act">${tags({ confirmed: r.confirmed })}</div>` : `<div class="ses-act">
            <button class="btn btn-primary" data-me="${r.id}"><i class="fa-solid fa-check"></i> Sí, fui yo</button>
            <button class="btn btn-ghost" data-notme="${r.id}" style="color:#ef4444"><i class="fa-solid fa-triangle-exclamation"></i> No fui yo</button></div>`}
        </div></div></div>`;
  }

  // ── Mapa ───────────────────────────────────────────────
  let map = null, layer = null, leafletP = null;
  function leaflet() {
    if (window.L) return Promise.resolve(window.L);
    if (leafletP) return leafletP;
    const CDN = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/';
    leafletP = new Promise((ok, bad) => {
      const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = CDN + 'leaflet.min.css'; css.integrity = 'sha384-c6Rcwz4e4CITMbu/NBmnNS8yN2sC3cUElMEMfP3vqqKFp7GOYaaBBCqmaWBjmkjb'; css.crossOrigin = 'anonymous'; document.head.appendChild(css);
      const js = document.createElement('script'); js.src = CDN + 'leaflet.min.js'; js.integrity = 'sha384-NElt3Op+9NBMCYaef5HxeJmU4Xeard/Lku8ek6hoPTvYkQPh3zLIrJP7KiRocsxO'; js.crossOrigin = 'anonymous';
      js.onload = () => ok(window.L); js.onerror = () => { leafletP = null; bad(new Error('No se pudo cargar el mapa.')); }; document.head.appendChild(js);
    });
    return leafletP;
  }
  function mapPoints() {
    const by = {};
    for (const r of hist.concat(D.active || [])) {
      if (r.lat == null || r.lon == null) continue;
      const k = r.lat + ',' + r.lon, p = by[k] || (by[k] = { lat: r.lat, lon: r.lon, place: r.place, n: 0, last: 0, bad: false, focus: false, ids: new Set() });
      if (p.ids.has(r.id)) continue; p.ids.add(r.id);
      p.n++; p.last = Math.max(p.last, r.created); p.bad = p.bad || r.confirmed === 'not_me'; p.focus = p.focus || r.id === focus;
    }
    return Object.values(by);
  }
  async function renderMap() {
    const pts = mapPoints();
    $('ses-map-card').style.display = pts.length ? '' : 'none';
    if (!pts.length || !sec.classList.contains('active')) return;
    try {
      const L = await leaflet();
      if (!map) {
        map = L.map($('ses-map'), { scrollWheelZoom: false, zoomControl: true, attributionControl: true, worldCopyJump: true });
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 12, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>' }).addTo(map);
      }
      if (layer) layer.remove();
      layer = L.layerGroup().addTo(map);
      const bounds = [];
      for (const p of pts) {
        const m = L.marker([p.lat, p.lon], { icon: L.divIcon({ className: '', html: `<div class="ses-pin${p.bad ? ' bad' : ''}"></div>`, iconSize: [14, 14], iconAnchor: [7, 7] }), title: p.place, keyboard: true });
        m.bindPopup(`<b>${e(p.place || 'Ubicación desconocida')}</b><br>${p.n} ${p.n === 1 ? 'ingreso' : 'ingresos'} · el último, ${e(dt(p.last))}${p.bad ? '<br><span style="color:#ef4444">Marcaste uno como "No fui yo"</span>' : ''}`);
        m.addTo(layer); bounds.push([p.lat, p.lon]);
        if (p.focus) setTimeout(() => m.openPopup(), 300);
      }
      map.invalidateSize();
      if (bounds.length === 1) map.setView(bounds[0], 6, { animate: false });
      else map.fitBounds(bounds, { padding: [30, 30], maxZoom: 6, animate: !reduced() });
    } catch (x) { $('ses-map').innerHTML = `<div class="ses-empty">${e(x.message)}</div>`; }
  }

  // ── Superadmin: ingresos del equipo ──────────────────────────
  let TM = null, teamRows = [];
  const teamFilter = () => ({ user_id: +$('ses-tf-user').value || 0, cc: $('ses-tf-cc').value, alerts: $('ses-tf-alerts').checked });
  function renderTeam() {
    const fill = (sel, opts) => { const v = sel.value; sel.innerHTML = sel.options[0].outerHTML + opts; sel.value = v; };
    fill($('ses-tf-user'), (TM.users || []).map(u => `<option value="${u.id}">${e(u.name)}${u.open ? ` (${u.open} ${u.open === 1 ? 'abierta' : 'abiertas'})` : ''}</option>`).join(''));
    fill($('ses-tf-cc'), (TM.countries || []).map(c => `<option value="${e(c.cc)}">${e(c.name || c.cc)} (${c.n})</option>`).join(''));
    $('ses-tf-endall').style.display = $('ses-tf-user').value ? '' : 'none';
    const box = $('ses-team');
    if (!teamRows.length) { box.innerHTML = '<div class="ses-empty">No hay ingresos con esos filtros.</div>'; $('ses-team-more').style.display = 'none'; return; }
    box.innerHTML = teamRows.map(r => `<div class="ses-row">
        <div class="ses-ic${r.confirmed === 'not_me' ? ' warn' : ''}"><i class="fa-solid ${icon(r.kind)}"></i></div>
        <div class="ses-main"><div class="ses-t">${e(r.user)} · ${title(r)} ${tags(r)}</div>
          <div class="ses-d"><b>${place(r)}</b> · IP ${e(r.ip)} · ${e(dt(r.created))}</div></div>
        <div class="ses-st">${status(r)}${r.status === 'active' ? `<br><button class="btn btn-ghost" data-team-end="${r.id}" style="font-size:11px;padding:4px 8px;margin-top:4px">Cerrar</button>` : ''}</div>
      </div>`).join('');
    $('ses-team-more').style.display = TM.more ? '' : 'none';
  }
  async function loadTeam(offset = 0) {
    const j = await api('team', { filter: { ...teamFilter(), offset } });
    TM = j; teamRows = offset ? teamRows.concat(j.rows) : j.rows; renderTeam(); renderGuard();
  }
  const takeTeam = j => { TM = j; teamRows = j.rows; renderTeam(); renderGuard(); };

  // Superadmin: cuentas bloqueadas, intentos fallidos del equipo (con bloquear IP o país) y bloqueos vigentes
  const DUR = '<select data-dur aria-label="Por cuánto tiempo"><option value="24">24 horas</option><option value="168" selected>7 días</option><option value="720">30 días</option><option value="0">Siempre</option></select>';
  function renderGuard() {
    $('ses-guard-card').style.display = '';
    const L = TM.locked || [], F = TM.failed || [], B = TM.blocks || [];
    const until = b => b.until ? 'hasta el ' + dt(b.until) : 'para siempre';
    $('ses-guard').innerHTML =
      (L.length ? '<div class="ses-sub">Cuentas bloqueadas</div>' + L.map(l => `<div class="ses-row">
          <div class="ses-ic warn"><i class="fa-solid fa-lock"></i></div>
          <div class="ses-main"><div class="ses-t">${e(l.name)}</div><div class="ses-d">Bloqueada el ${e(dt(l.at))} · ${l.reason === 'self' ? 'por la persona, desde Mi perfil' : l.reason === 'email' ? 'por la persona, desde un email' : e(l.reason)}</div></div>
          <button class="btn btn-ghost" data-unlock="${l.user_id}">Desbloquear</button></div>`).join('') : '')
      + '<div class="ses-sub">Intentos fallidos del equipo (últimas 48 h)</div>'
      + (F.length ? F.map(f => `<div class="ses-row" data-fip="${e(f.ip)}" data-fcc="${e(f.cc)}">
          <div class="ses-ic warn"><i class="fa-solid fa-triangle-exclamation"></i></div>
          <div class="ses-main"><div class="ses-t">${f.n} ${f.n === 1 ? 'intento' : 'intentos'} · IP ${e(f.ip)}</div>
            <div class="ses-d"><b>${f.place ? e(f.place) : 'Ubicación desconocida'}</b> · el último, ${e(dt(f.last))}<br>Con: ${f.who.map(e).join(', ')}</div>
            <div class="ses-blk">${DUR}<button class="btn btn-ghost" data-block="ip">Bloquear IP</button>${f.cc ? `<button class="btn btn-ghost" data-block="country">Bloquear país (${e(f.cc)})</button>` : ''}</div></div></div>`).join('')
        : '<div class="ses-empty">No hubo intentos fallidos.</div>')
      + '<div class="ses-sub">Bloqueos vigentes</div>'
      + (B.length ? B.map(b => `<div class="ses-row">
          <div class="ses-ic"><i class="fa-solid ${b.kind === 'ip' ? 'fa-network-wired' : 'fa-earth-americas'}"></i></div>
          <div class="ses-main"><div class="ses-t">${b.kind === 'ip' ? 'IP ' + e(b.value) : 'País ' + e(b.value)}</div><div class="ses-d">Desde el ${e(dt(b.at))}, ${e(until(b))}${b.note ? ' · ' + e(b.note) : ''}</div></div>
          <button class="btn btn-ghost" data-unblock="${e(b.id)}">Quitar</button></div>`).join('')
        : '<div class="ses-empty">Ninguno. Desde esta conexión estás en la IP ' + e(TM.my_ip || '') + ' (no se puede bloquear).</div>');
  }

  function render() {
    renderActive(); renderHist(); renderFailed(); renderTrust(); renderReview(); renderMap();
    if (D.is_super && $('ses-team-card').style.display === 'none') { $('ses-team-card').style.display = ''; loadTeam().catch(x => { $('ses-team').innerHTML = `<div class="ses-empty">${e(x.message)}</div>`; }); }
  }
  const take = j => { D = j; hist = j.history; render(); };

  async function load(offset = 0) {
    const j = await api('list', { offset });
    D = j; hist = offset ? hist.concat(j.history) : j.history;
    // El ingreso del aviso puede ser más viejo que los 40 primeros: se sigue cargando hasta encontrarlo (tope: 5 páginas)
    if (focus && !hist.some(x => x.id === focus) && j.more && offset < 200) return load(offset + 40);
    render();
  }
  const scrollFocus = () => { const el = $('ses-h-' + focus); if (el && sec.classList.contains('active')) el.scrollIntoView({ block: 'center', behavior: reduced() ? 'auto' : 'smooth' }); };
  const openPw = () => { if (typeof cfgShowPasswordModal === 'function') cfgShowPasswordModal(); };

  async function saveName(id) {
    const inp = $('ses-name-in'); if (!inp) return;
    const j = await api('rename', { id, name: inp.value }); renaming = 0; take(j); toast('Nombre guardado ✓');
  }

  sec.addEventListener('keydown', ev => {
    if (ev.target.id !== 'ses-name-in') return;
    if (ev.key === 'Enter') { ev.preventDefault(); saveName(renaming).catch(x => toast(x.message, true)); }
    if (ev.key === 'Escape') { renaming = 0; renderActive(); }
  });
  sec.addEventListener('change', ev => { if (ev.target.closest('.ses-filters')) loadTeam().catch(x => toast(x.message, true)); });
  sec.addEventListener('click', async ev => {
    const a = ev.target.closest('[data-pw]'); if (a) { ev.preventDefault(); openPw(); return; }
    const b = ev.target.closest('button'); if (!b) return;
    try {
      if (b.dataset.rename) { renaming = +b.dataset.rename; renderActive(); }
      else if (b.dataset.saveName) { b.disabled = true; await saveName(+b.dataset.saveName); }
      else if (b.dataset.end) {
        if (!confirm('¿Cerrar la sesión en ese dispositivo? Va a tener que volver a entrar con la contraseña.')) return;
        b.disabled = true; take(await api('end', { id: +b.dataset.end })); toast('Sesión cerrada ✓');
      } else if (b.id === 'ses-end-all') {
        if (!confirm('¿Cerrar todas las demás sesiones? Este navegador sigue abierto.')) return;
        b.disabled = true; const j = await api('end_others'); take(j); b.disabled = false;
        toast(j.closed ? `Se cerraron ${j.closed} ${j.closed === 1 ? 'sesión' : 'sesiones'} ✓` : 'No había otras sesiones abiertas');
      } else if (b.id === 'ses-more') {
        b.disabled = true; await load(hist.length); b.disabled = false;
      } else if (b.dataset.me) {
        b.disabled = true; take(await api('confirm', { id: +b.dataset.me, me: true })); toast('Listo, gracias ✓');
      } else if (b.dataset.notme) {
        if (!confirm('Vamos a cerrar esa sesión y a pedir el código de 2 pasos de nuevo en todos tus dispositivos. Después cambia tu contraseña.')) return;
        b.disabled = true; const j = await api('confirm', { id: +b.dataset.notme, me: false });
        reviewDone = `<div class="cfg-card ses-review bad"><div class="ses-done"><b style="color:#ef4444"><i class="fa-solid fa-lock"></i> ${j.closed ? 'Cerramos esa sesión.' : 'Esa sesión ya estaba cerrada.'}</b><br>
          Para que no pueda volver a entrar, cambia tu contraseña ahora: al cambiarla se cierran todas las sesiones de otros dispositivos. Y activa la verificación en dos pasos.
          <div class="ses-act" style="margin-top:10px"><button class="btn btn-primary" data-pw="1"><i class="fa-solid fa-key"></i> Cambiar contraseña</button>
          <a class="btn btn-ghost" href="#seguridad"><i class="fa-solid fa-shield-halved"></i> Verificación en dos pasos</a></div></div></div>`;
        take(j); window.scrollTo({ top: 0, behavior: reduced() ? 'auto' : 'smooth' });
      } else if (b.dataset.untrust) {
        b.disabled = true; take(await api('trust_remove', { id: +b.dataset.untrust })); toast('Dispositivo quitado ✓');
      } else if (b.id === 'ses-trust-all') {
        if (!confirm('¿Quitar todos los dispositivos de confianza? La próxima vez te va a pedir el código en todos.')) return;
        take(await api('trust_remove')); toast('Listo ✓');
      } else if (b.dataset.teamEnd) {
        if (!confirm('¿Cerrar esa sesión? La persona va a tener que volver a entrar.')) return;
        b.disabled = true; takeTeam(await api('team_end', { id: +b.dataset.teamEnd, filter: teamFilter() })); toast('Sesión cerrada ✓');
      } else if (b.id === 'ses-tf-endall') {
        const sel = $('ses-tf-user'), name = sel.options[sel.selectedIndex].text.replace(/\s*\(.*\)$/, '');
        if (!confirm(`¿Cerrar todas las sesiones de ${name} y quitarle los dispositivos de confianza?`)) return;
        b.disabled = true; const j = await api('team_end_user', { user_id: +sel.value, filter: teamFilter() }); takeTeam(j); b.disabled = false;
        toast(j.closed ? `Se cerraron ${j.closed} ${j.closed === 1 ? 'sesión' : 'sesiones'} ✓` : 'No tenía sesiones abiertas');
        load().catch(() => {});   // por si eran las propias
      } else if (b.id === 'ses-lock-me') {
        if (!confirm('¿Bloquear tu cuenta? Se cierran TODAS las sesiones (también esta) y nadie puede entrar hasta que elijas una contraseña nueva con el enlace que te llega por email.')) return;
        b.disabled = true; await api('lock_me'); location.href = '/toolz/login.php';
      } else if (b.dataset.unlock) {
        if (!confirm('¿Desbloquear esa cuenta? Va a poder entrar con su contraseña de siempre.')) return;
        b.disabled = true; takeTeam(await api('team_unlock', { user_id: +b.dataset.unlock, filter: teamFilter() })); toast('Cuenta desbloqueada ✓');
      } else if (b.dataset.block) {
        const row = b.closest('[data-fip]'), kind = b.dataset.block, value = kind === 'ip' ? row.dataset.fip : row.dataset.fcc;
        const hours = +row.querySelector('[data-dur]').value;
        if (!confirm(kind === 'ip' ? `¿Bloquear la entrada desde la IP ${value}?` : `¿Bloquear la entrada desde todo el país ${value}? Nadie del equipo va a poder entrar desde ahí (ni de viaje).`)) return;
        b.disabled = true; takeTeam(await api('block_add', { kind, value, hours, filter: teamFilter() })); toast('Bloqueado ✓');
      } else if (b.dataset.unblock) {
        b.disabled = true; takeTeam(await api('block_remove', { id: b.dataset.unblock, filter: teamFilter() })); toast('Bloqueo quitado ✓');
      } else if (b.id === 'ses-team-more') {
        b.disabled = true; await loadTeam(teamRows.length); b.disabled = false;
      }
    } catch (x) { b.disabled = false; toast(x.message, true); }
  });

  // Al cambiar la contraseña (user-config.js) se cierran las demás sesiones: se actualiza la lista
  document.addEventListener('g7:pw-changed', () => { reviewDone = null; load().catch(() => {}); });
  // Al entrar a la sección se vuelve a pedir (puede haber cambiado), se dibuja el mapa y se lleva la vista al ingreso del aviso
  window.addEventListener('hashchange', () => { if (location.hash === '#sesiones') setTimeout(() => load().then(scrollFocus).catch(() => {}), 30); });
  // Al llegar con #sesiones, user-config.js muestra la sección después de que cargaron los datos: el mapa se dibuja cuando queda visible
  let shown = sec.classList.contains('active');
  new MutationObserver(() => {
    const now = sec.classList.contains('active');
    if (now && !shown && D) { renderMap(); setTimeout(scrollFocus, 50); }
    shown = now;
  }).observe(sec, { attributes: true, attributeFilter: ['class'] });

  load().then(() => setTimeout(scrollFocus, 50)).catch(x => { $('ses-active').innerHTML = `<div class="ses-empty" style="color:#ef4444">${e(x.message)}</div>`; $('ses-hist').innerHTML = ''; });
})();
