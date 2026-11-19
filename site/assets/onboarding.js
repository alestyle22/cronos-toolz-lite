/* onboarding.js — Bienvenida y configuración inicial (bienvenida.php). Habla con onboarding_api.php (perfil, preferencias,
   estado) y con totp_api.php (la verificación en dos pasos de siempre). Los datos se validan acá para avisar al momento,
   pero quien decide es el servidor. Doc: toolz/ONBOARDING.md */
'use strict';
// Marco de la foto (avatar_frame_lib.php): el id sale del dato que deja topbar.php; g7-frames.js se carga diferido, así que
// el selector se arma cuando ya está (no depende de cuánto tarde en dibujarse el paso)
const frMe = () => { try { return +(JSON.parse(document.getElementById('g7-frames-data')?.textContent || '{}').me || 0); } catch (e) { return 0; } };
const frReady = cb => { if (window.G7Frames) cb(); else document.addEventListener('DOMContentLoaded', () => { if (window.G7Frames) cb(); }, { once: true }); };
(function () {
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const NEXT = $('ob').dataset.next || '/toolz/';
  const S = { st: null, step: 0, sub: 0, subLast: 3, draft: {}, prefs: {}, totp: null, codes: null };
  const STEPS = [['profile', 'Perfil', 'fa-user'], ['prefs', 'Preferencias', 'fa-sliders'], ['security', 'Seguridad', 'fa-shield-halved'], ['done', 'Listo', 'fa-flag-checkered']];
  // Pasos que se ven: el Perfil del servidor (paso 0) son 4 pantallas cortas. [paso del servidor, pantalla, nombre, ícono]
  const UI = [[0, 0, 'Vos', 'fa-user'], [0, 1, 'Sobre vos', 'fa-address-card'], [0, 2, 'Fechas', 'fa-cake-candles'], [0, 3, 'Lo que sabés', 'fa-star'],
    [1, 0, 'Preferencias', 'fa-sliders'], [2, 0, 'Seguridad', 'fa-shield-halved'], [3, 0, 'Listo', 'fa-flag-checkered']];
  const uiPos = () => Math.max(0, UI.findIndex(x => x[0] === S.step && x[1] === (S.step === 0 ? S.sub : 0)));
  function stepperHtml() {
    const done = S.st.steps_done, pos = uiPos();
    return `<div class="ob-steps"><div class="ob-bar"><i style="--p:${Math.round(pos * 100 / (UI.length - 1))}%"></i></div>
      <ol class="many">${UI.map(([st, , l, i], n) => { const ok = n !== pos && (n < pos || done.includes(STEPS[st][0])); return `<li class="${n === pos ? 'on' : ok ? 'ok' : ''}"><span>${ok ? '<i class="fa-solid fa-check"></i>' : `<i class="fa-solid ${i}"></i>`}</span><em>${l}</em></li>`; }).join('')}</ol></div>`;
  }
  let tT;
  const toast = (m, err = false) => { const t = $('ob-toast'); t.textContent = m; t.classList.toggle('err', err); t.classList.add('show'); clearTimeout(tT); tT = setTimeout(() => t.classList.remove('show'), err ? 4500 : 2600); };
  async function call(url, hdr, body) {
    const r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', ...hdr }, body: JSON.stringify(body) });
    let j; try { j = await r.json(); } catch (e) { throw new Error('El servidor respondió algo inesperado. Probá de nuevo.'); }
    if (r.status === 401) { location.href = '/toolz/login.php'; throw new Error(j.error); }
    if (!r.ok || j.error) { const e = new Error(j.error || 'No se pudo completar'); e.data = j; throw e; }
    return j;
  }
  const api = (action, data = {}) => call('/toolz/onboarding_api.php', { 'X-OB': '1' }, { action, ...data });
  const totp = (action, data = {}) => call('/toolz/totp_api.php', { 'X-2FA': '1' }, { action, ...data });
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ── Arranque ──
  (async function boot() {
    try { S.st = await api('state'); } catch (e) { $('ob-main').innerHTML = `<div class="ob-card"><p>${esc(e.message)}</p></div>`; return; }
    applyColors();
    if (S.st.status === 'done' && !new URLSearchParams(location.search).has('ver')) { finalScreen(true); return; }
    if (!S.st.started && S.st.status === 'pending') welcome();
    else { S.step = Math.min(firstOpenStep(), 3); wizard(); }
  })();
  function applyColors() {
    const c = S.st.cfg.colors || {};
    if (c.accent) document.documentElement.style.setProperty('--ob-a', c.accent);
    if (c.accent2) document.documentElement.style.setProperty('--ob-b', c.accent2);
  }
  /** Primer paso que falta (para retomar). Con "solo lo que falta", el perfil. */
  function firstOpenStep() {
    const d = S.st.steps_done, last = S.st.last_step;
    if (S.st.force_mode === '2fa') return 2;
    if (S.st.force_mode === 'missing') return 0;
    for (let i = 0; i < 3; i++) if (!d.includes(STEPS[i][0]) && !(i === 1 && !S.st.cfg.steps.prefs && last > 1)) return Math.max(i, Math.min(last, i));
    return 3;
  }
  const firstName = () => (S.st.user.first_name || S.st.user.display_name || S.st.user.username || '').split(' ')[0];

  // ── Pantalla de bienvenida ──
  function welcome() {
    const t = S.st.cfg.texts;
    $('ob-main').innerHTML = `<section class="ob-welcome ob-enter">
      <div class="ob-art" aria-hidden="true">${art()}</div>
      <span class="ob-kicker"><i class="fa-solid fa-hand-sparkles"></i> Hola, ${esc(firstName())}</span>
      <h1>${esc(t.welcome_title)}</h1>
      <p class="ob-lead">${esc(t.welcome_text)}</p>
      ${S.st.forced && S.st.force_reason ? `<div class="ob-note"><i class="fa-solid fa-circle-info"></i><span>${esc(S.st.force_reason)}</span></div>` : ''}
      <ol class="ob-path">${UI.map(([, , l, i], n) => `<li style="--n:${n}"><span><i class="fa-solid ${i}"></i></span><b>${l}</b></li>`).join('')}</ol>
      <button type="button" class="ob-btn primary big" id="ob-go">${esc(t.welcome_cta)} <i class="fa-solid fa-arrow-right"></i></button>
      <small class="ob-hint">Lleva unos 3 minutos. Si salís, retomás desde donde quedaste.</small></section>`;
    $('ob-go').addEventListener('click', async () => { $('ob-go').disabled = true; try { S.st = await api('start'); S.step = 0; S.sub = 0; wizard(1); } catch (e) { toast(e.message, true); $('ob-go').disabled = false; } });
    $('ob-go').focus();
  }
  function art() {
    return `<svg viewBox="0 0 320 200" class="ob-svg"><defs><linearGradient id="obg" x1="0" x2="1" y1="0" y2="1"><stop offset="0" stop-color="var(--ob-a)"/><stop offset="1" stop-color="var(--ob-b)"/></linearGradient></defs>
      <circle cx="160" cy="100" r="78" fill="url(#obg)" opacity=".14" class="ob-pulse"/>
      <rect x="92" y="46" width="136" height="104" rx="16" fill="var(--g7-bg-2)" stroke="var(--g7-border-3)"/>
      <circle cx="122" cy="80" r="14" fill="url(#obg)"/><rect x="144" y="70" width="64" height="8" rx="4" fill="var(--g7-text-4)" opacity=".5"/><rect x="144" y="84" width="44" height="6" rx="3" fill="var(--g7-text-4)" opacity=".3"/>
      <rect x="108" y="106" width="104" height="8" rx="4" fill="var(--g7-text-4)" opacity=".25"/><rect x="108" y="120" width="76" height="8" rx="4" fill="var(--g7-text-4)" opacity=".25"/>
      <g class="ob-float"><circle cx="236" cy="56" r="18" fill="var(--g7-bg-2)" stroke="var(--ob-a)" stroke-width="2"/><path d="M229 56l5 5 9-10" stroke="var(--ob-a)" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/></g>
      <g class="ob-float2"><rect x="62" y="122" width="34" height="40" rx="9" fill="var(--g7-bg-2)" stroke="var(--ob-b)" stroke-width="2"/><path d="M71 137v-5a8 8 0 0 1 16 0v5" stroke="var(--ob-b)" stroke-width="2.5" fill="none"/><circle cx="79" cy="148" r="3" fill="var(--ob-b)"/></g>
      <circle cx="252" cy="140" r="4" fill="var(--ob-a)" class="ob-dot"/><circle cx="70" cy="70" r="3" fill="var(--ob-b)" class="ob-dot d2"/><circle cx="270" cy="96" r="2.5" fill="var(--ob-b)" class="ob-dot d3"/></svg>`;
  }

  // ── Asistente ──
  function wizard(dir = 0) {
    const done = S.st.steps_done;
    $('ob-main').innerHTML = `<div class="ob-wiz ob-enter">
      ${stepperHtml()}
      <div class="ob-card" id="ob-card"></div></div>`;
    const card = $('ob-card');
    [stepProfile, stepPrefs, stepSecurity, stepSummary][S.step](card);
    if (dir && !reduce) card.animate([{ opacity: 0, transform: `translateX(${dir * 24}px)` }, { opacity: 1, transform: 'none' }], { duration: 300, easing: 'cubic-bezier(.2,.7,.2,1)' });
    api('step', { step: S.step }).catch(() => {});
    window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
  }
  const head = (icon, title, sub) => `<header class="ob-h"><span class="ob-hic"><i class="fa-solid ${icon}"></i></span><div><h2>${title}</h2><p>${sub}</p></div></header>`;
  const navHtml = (opts = {}) => `<footer class="ob-nav">${S.step ? '<button type="button" class="ob-btn ghost" id="ob-back"><i class="fa-solid fa-arrow-left"></i> Volver</button>' : '<span></span>'}<span class="sp"></span>${opts.extra || ''}<button type="button" class="ob-btn primary" id="ob-next"${opts.disabled ? ' disabled' : ''}>${opts.label || 'Continuar'} <i class="fa-solid fa-arrow-right"></i></button></footer>`;
  function bindNav(onNext) {
    $('ob-back')?.addEventListener('click', () => { keepDraft(); S.step--; if (S.step === 0) S.sub = S.subLast; wizard(-1); });
    $('ob-next')?.addEventListener('click', async () => {
      const b = $('ob-next'); b.disabled = true; const html = b.innerHTML; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Guardando…';
      try { if (await onNext() !== false) { S.step++; wizard(1); } } catch (e) { toast(e.message, true); }
      finally { if (document.body.contains(b)) { b.disabled = false; b.innerHTML = html; } }
    });
  }
  let keepDraft = () => {};

  // Paso 1: perfil
  function stepProfile(card) {
    const F = S.st.cfg.fields, u = { ...S.st.user, ...S.draft };
    const onlyMissing = S.st.force_mode === 'missing' && S.st.missing.length && !S.showAll;
    const show = f => F[f] && F[f] !== 'hidden' && (!onlyMissing || S.st.missing.includes(f));
    const req = f => F[f] === 'required' ? ' <b class="req">*</b>' : ' <small>opcional</small>';
    const okMark = f => ((Array.isArray(u[f]) ? u[f].length : u[f]) && !S.st.missing.includes(f)) ? '<i class="fa-solid fa-circle-check ob-ok" title="Ya está configurado"></i>' : '';
    const inp = (f, label, ph, max, cls = '') => show(f) ? `<label class="ob-f ${cls}" data-f="${f}"><span>${label}${req(f)} ${okMark(f)}</span><input class="ob-in" name="${f}" value="${esc(u[f] || '')}" maxlength="${max}" placeholder="${esc(ph)}" autocomplete="${{ first_name: 'given-name', last_name: 'family-name', display_name: 'nickname' }[f] || 'off'}"><em class="ob-err"></em></label>` : '';
    const dateInp = (f, label, help) => show(f) ? `<label class="ob-f" data-f="${f}"><span>${label}${req(f)} ${okMark(f)}</span><input class="ob-in" type="date" name="${f}" value="${esc(u[f] || '')}" max="${new Date().toISOString().slice(0, 10)}"><em class="ob-help">${help}</em><em class="ob-err"></em></label>` : '';
    const countries = S.st.tz ? S.st.tz.countries : {};
    // El navegador puede dar un nombre viejo (America/Buenos_Aires): se busca el actual por la última parte (America/Argentina/Buenos_Aires)
    const allZones = Object.values(countries).flat();
    const rawTz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    const tzDetected = allZones.includes(rawTz) ? rawTz : (allZones.find(z => z.split('/').pop() === rawTz.split('/').pop()) || ({ 'Asia/Calcutta': 'Asia/Kolkata', 'Asia/Saigon': 'Asia/Ho_Chi_Minh', 'Asia/Katmandu': 'Asia/Kathmandu', 'Europe/Kiev': 'Europe/Kyiv' })[rawTz] || '');
    let names = null; try { names = new Intl.DisplayNames(['es'], { type: 'region' }); } catch (e) {}
    const ccList = Object.keys(countries).sort((a, b) => (names ? names.of(a) : a).localeCompare(names ? names.of(b) : b, 'es'));
    const detCC = ccList.find(cc => (countries[cc] || []).includes(tzDetected)) || '';
    const curCC = u.country || (!u.timezone ? detCC : ccList.find(cc => (countries[cc] || []).includes(u.timezone)) || '');
    card.innerHTML = '<div id="ob-subh"></div>' +
      (onlyMissing ? `<button type="button" class="ob-link" id="ob-all">Ver todos los datos</button>` : '') +
      `<form class="ob-form" id="ob-pf" novalidate>
        <div class="ob-sub" data-sub="0">
        ${show('avatar') ? `<div class="ob-av" data-f="avatar"><span class="ob-avimg" id="ob-avimg"${frMe() ? ` data-g7-uid="${frMe()}"` : ''}${u.avatar ? ` style="background-image:url('${esc(u.avatar)}')"` : ''}>${u.avatar ? '' : esc((firstName()[0] || '?').toUpperCase())}</span><div><b>Foto de perfil${req('avatar')}</b><small>JPG, PNG o WebP. Se achica sola.</small><label class="ob-btn sm"><i class="fa-solid fa-camera"></i> ${u.avatar ? 'Cambiar' : 'Subir'} foto<input type="file" accept="image/png,image/jpeg,image/webp" id="ob-avf" hidden></label></div><em class="ob-err"></em></div>
          ${frMe() ? `<div class="ob-frame"><b>Marco de la foto</b><small>Se ve alrededor de tu foto en todo Cronos. Lo podés cambiar cuando quieras en Mi configuración › Mi perfil.</small><div id="ob-fpick"></div></div>` : ''}` : ''}
        <div class="ob-grid">${inp('first_name', 'Nombre', 'Ej.: Lucía', 80)}${inp('last_name', 'Apellido', 'Ej.: Fernández', 80)}</div>
        <div class="ob-grid">${inp('display_name', 'Nombre visible', 'Cómo te ven los demás', 100)}${F.username === 'show' && !onlyMissing ? `<label class="ob-f"><span>Nombre de usuario</span><input class="ob-in" value="${esc(u.username)}" disabled><em class="ob-help">Con este entrás. Lo cambia administración.</em></label>` : ''}</div>
        <div class="ob-grid">${inp('position', 'Cargo o puesto', 'Ej.: Diseñadora', 100)}
          ${show('department') && u.teams.length ? `<label class="ob-f" data-f="department"><span>Departamento${req('department')}</span><select class="ob-in" name="department">${u.teams.map(t => `<option value="${t.id}"${+u.department === t.id ? ' selected' : ''}>${esc(t.name)}</option>`).join('')}</select><em class="ob-help">${u.teams.length > 1 ? 'Elegí el principal entre tus equipos.' : 'Tu equipo.'}</em></label>` : ''}</div>
        </div><div class="ob-sub" data-sub="1">
        ${show('bio') ? `<label class="ob-f" data-f="bio"><span>Sobre vos${req('bio')}</span><textarea class="ob-in" name="bio" maxlength="300" rows="2" placeholder="Una línea sobre lo que hacés">${esc(u.bio || '')}</textarea><em class="ob-err"></em></label>` : ''}
        ${(show('email') || show('phone')) ? `<div class="ob-grid">${inp('email', 'Email', 'tu@email.com', 150)}${inp('phone', 'Teléfono', 'Ej.: +54 9 11 1234-5678', 40)}</div>` : ''}
        ${show('location') ? `<div class="ob-grid one">${inp('location', 'Ubicación', 'Ciudad, País', 120)}</div>` : ''}
        ${(show('country') || show('timezone')) ? `<div class="ob-grid">
          ${show('country') || show('timezone') ? `<label class="ob-f" data-f="country"><span>País${F.country === 'required' ? ' <b class="req">*</b>' : ' <small>opcional</small>'}</span><select class="ob-in" name="country"><option value="">Elegí…</option>${ccList.map(cc => `<option value="${cc}"${cc === curCC ? ' selected' : ''}>${esc(names ? names.of(cc) : cc)}</option>`).join('')}</select><em class="ob-err"></em></label>` : ''}
          ${show('timezone') ? `<label class="ob-f" data-f="timezone"><span>Zona horaria${req('timezone')} ${okMark('timezone')}</span><select class="ob-in" name="timezone"></select><em class="ob-err"></em></label>` : ''}</div>
          ${tzDetected && show('timezone') ? `<div class="ob-detect" id="ob-detect"><i class="fa-solid fa-location-crosshairs"></i><span>Tu dispositivo está en <b>${esc(tzDetected.replace(/_/g, ' '))}</b> (${new Date().toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' })}).</span><button type="button" class="ob-btn sm" id="ob-usetz">Usar esta</button></div>` : ''}` : ''}
        ${show('language') ? `<label class="ob-f" data-f="language"><span>Idioma preferido${req('language')}</span><select class="ob-in" name="language">${Object.entries(S.st.langs).map(([k, l]) => `<option value="${k}"${u.language === k ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select><em class="ob-help">Por ahora la plataforma está en español: tu preferencia queda guardada.</em></label>` : ''}
        </div><div class="ob-sub" data-sub="2">
        ${(show('birthday') || show('joined')) ? `<div class="ob-grid">${dateInp('birthday', '🎂 Cumpleaños', 'Se muestra solo el día y el mes')}${dateInp('joined', '🥂 En el equipo desde', 'Para festejar los aniversarios')}</div>
          ${show('birthday') ? `<div class="ob-bwarn" id="ob-bwarn"${u.birthday ? ' hidden' : ''}><span>🎂</span><div><b>Si no cargás tu cumpleaños…</b><small>el equipo no va a saber cuándo es para saludarte.</small></div></div>` : ''}` : ''}
        </div><div class="ob-sub" data-sub="3">
        ${show('tools') && window.PFPicker && S.st.pf ? `<div class="ob-f ob-pk" data-f="tools"><span>Herramientas${req('tools')} ${okMark('tools')}</span><div id="ob-tools"></div><em class="ob-err"></em></div>` : ''}
        ${show('aptitudes') && window.PFPicker && S.st.pf ? `<div class="ob-f ob-pk" data-f="aptitudes"><span>Aptitudes${req('aptitudes')} ${okMark('aptitudes')}</span><div id="ob-apt"></div><em class="ob-err"></em></div>` : ''}
        </div>
      </form>` + `<footer class="ob-nav"><button type="button" class="ob-btn ghost" id="ob-pback"><i class="fa-solid fa-arrow-left"></i> Volver</button><span class="sp"></span><button type="button" class="ob-btn primary" id="ob-next">Continuar <i class="fa-solid fa-arrow-right"></i></button></footer>`;
    const fm = $('ob-pf');
    // Herramientas y aptitudes (assets/profile-picker.js, las mismas listas que Comunidad y Mi perfil)
    const pk = {};
    if ($('ob-tools')) pk.tools = PFPicker.field($('ob-tools'), { title: 'Herramientas', help: 'Programas y tecnologías que usás.', button: 'Elegir herramientas', groups: S.st.pf.tools, selected: u.tools || [], placeholder: 'Buscar herramienta… (Figma, Excel, Canva)', empty: 'Elegí las que usás.' });
    if ($('ob-apt')) pk.aptitudes = PFPicker.field($('ob-apt'), { title: 'Aptitudes', help: 'Lo que sabés hacer, aunque no sea diseño. Si no está en la lista, escribila.', button: 'Elegir aptitudes', groups: S.st.pf.aptitudes, selected: u.aptitudes || [], custom: true, placeholder: 'Buscar o escribir una aptitud… (Ventas, Liderazgo)', empty: 'Elegí o escribí lo que sabés hacer.' });
    // Pantallas del perfil: solo las que tienen algo para completar
    const SUBH = [['fa-id-card', 'Contanos quién sos', onlyMissing ? 'Solo te pedimos lo que falta. Lo demás ya está bien.' : 'Así te van a reconocer en el equipo. Lo que ya estaba configurado aparece cargado.'],
      ['fa-address-card', 'Sobre vos', 'Una línea sobre lo que hacés y cómo encontrarte.'], ['fa-cake-candles', 'Fechas para festejar', 'Tu cumpleaños y desde cuándo estás en el equipo.'],
      ['fa-star', 'Lo que sabés hacer', 'Las herramientas que usás y tus aptitudes, aunque no sean de diseño.']];
    const subs = [...fm.querySelectorAll('.ob-sub')].filter(d => d.querySelector('.ob-f, .ob-av, .ob-bwarn'));
    fm.querySelectorAll('.ob-sub').forEach(d => { if (!subs.includes(d)) d.remove(); });
    S.subLast = subs.length ? +subs[subs.length - 1].dataset.sub : 0;
    let si = Math.max(0, subs.findIndex(d => +d.dataset.sub >= S.sub)); if (S.sub > S.subLast) si = subs.length - 1;
    const showSub = (i, dir = 0) => {
      si = Math.max(0, Math.min(subs.length - 1, i)); S.sub = subs.length ? +subs[si].dataset.sub : 0;
      subs.forEach((d, n) => { d.hidden = n !== si; });
      const [ic, t, sub] = SUBH[S.sub]; $('ob-subh').innerHTML = head(ic, t, sub);
      const st = document.querySelector('.ob-steps'); if (st) st.outerHTML = stepperHtml();
      $('ob-pback').hidden = si === 0;
      if (dir && !reduce) card.animate([{ opacity: 0, transform: `translateX(${dir * 24}px)` }, { opacity: 1, transform: 'none' }], { duration: 280, easing: 'cubic-bezier(.2,.7,.2,1)' });
      if (dir) window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
    };
    showSub(si);
    $('ob-pback').addEventListener('click', () => { keepDraft(); showSub(si - 1, -1); });
    const subOf = el => subs.findIndex(d => d.contains(el));
    fm.querySelector('[name=birthday]')?.addEventListener('input', e => { const w = $('ob-bwarn'); if (w) w.hidden = !!e.target.value; });
    const fillTz = (cc, keep) => {
      const sel = fm.querySelector('[name=timezone]'); if (!sel) return;
      const list = cc ? (countries[cc] || []) : Object.values(countries).flat();
      const cur = keep || sel.value || u.timezone || (list.includes(tzDetected) ? tzDetected : '');
      sel.innerHTML = `<option value="">Elegí…</option>` + list.map(z => `<option value="${esc(z)}"${z === cur ? ' selected' : ''}>${esc(z.replace(/_/g, ' ').replace(/^.*?\//, ''))} · ${zoneTime(z)}</option>`).join('');
      if (list.length === 1) sel.value = list[0];
    };
    fillTz(curCC, u.timezone);
    fm.querySelector('[name=country]')?.addEventListener('change', e => fillTz(e.target.value));
    $('ob-usetz')?.addEventListener('click', () => { const cc = fm.querySelector('[name=country]'); if (cc) cc.value = detCC; fillTz(detCC, tzDetected); fm.querySelector('[name=timezone]').value = tzDetected; validateField('timezone'); toast('Zona horaria tomada de tu dispositivo'); });
    $('ob-all')?.addEventListener('click', () => { keepDraft(); S.showAll = true; S.sub = 0; stepProfile(card); });
    $('ob-avf')?.addEventListener('change', async e => {
      const f = e.target.files[0]; if (!f) return;
      if (!/^image\/(png|jpeg|webp)$/.test(f.type)) { toast('Subí una imagen JPG, PNG o WebP', true); return; }
      const fd = new FormData(); fd.append('action', 'update_avatar'); fd.append('avatar_file', await shrink(f), 'avatar.' + (f.type === 'image/png' ? 'png' : 'jpg'));
      const box = $('ob-avimg'); box.classList.add('busy');
      try { const r = await fetch('/toolz/user_config.php', { method: 'POST', credentials: 'same-origin', body: fd }).then(x => x.json()); if (r.error) throw new Error(r.error); S.st.user.avatar = r.url; box.style.backgroundImage = `url('${r.url}')`; box.textContent = ''; toast('Foto actualizada'); }
      catch (er) { toast(er.message, true); } finally { box.classList.remove('busy'); }
    });
    // Marco de la foto (avatar_frame_lib.php): se ve enseguida en la foto de arriba y se guarda solo
    if ($('ob-fpick')) frReady(() => { let ft = 0;
      G7Frames.picker($('ob-fpick'), G7Frames.frames[G7Frames.me] || null, f => {
        G7Frames.set(G7Frames.me, f); clearTimeout(ft);
        ft = setTimeout(() => fetch('/toolz/user_config.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ key: 'avatar_frame', value: f || {} }) })
          .then(x => x.json()).then(d => { if (!d.ok) throw new Error(d.error || 'No se pudo guardar el marco'); }).catch(er => toast(er.message, true)), 500);
      }); });
    const validateField = name => {
      const el = fm.querySelector(`[name=${name}]`); if (!el) return '';
      const v = el.value.trim(), f = name, lab = el.closest('.ob-f');
      let m = '';
      if (F[f] === 'required' && !v) m = 'Este dato es obligatorio';
      else if ((f === 'first_name' || f === 'last_name') && v && !/^[\p{L}][\p{L}\p{M} '\-.]*$/u.test(v)) m = 'Usá solo letras';
      else if (f === 'display_name' && v && v.length < 2) m = 'Poné al menos 2 caracteres';
      else if (f === 'email' && v && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) m = 'El email no es válido';
      else if (/[<>]/.test(v)) m = 'No uses < ni >';
      if (lab) { lab.classList.toggle('bad', !!m); lab.classList.toggle('good', !m && !!v); const e = lab.querySelector('.ob-err'); if (e) e.textContent = m; }
      return m;
    };
    fm.querySelectorAll('.ob-in[name]').forEach(el => { el.addEventListener('input', () => { if (el.closest('.bad')) validateField(el.name); }); el.addEventListener('blur', () => validateField(el.name)); el.addEventListener('change', () => validateField(el.name)); });
    // Nombre visible: se arma solo con nombre y apellido mientras nadie lo toque
    const dn = fm.querySelector('[name=display_name]'); let dnTouched = !!(u.display_name && u.display_name !== u.username);
    dn?.addEventListener('input', () => { dnTouched = true; });
    ['first_name', 'last_name'].forEach(n => fm.querySelector(`[name=${n}]`)?.addEventListener('input', () => { if (dn && !dnTouched) dn.value = [fm.querySelector('[name=first_name]')?.value, fm.querySelector('[name=last_name]')?.value].filter(Boolean).join(' ').trim(); }));
    keepDraft = () => { fm.querySelectorAll('.ob-in[name]').forEach(el => { S.draft[el.name] = el.value; }); Object.entries(pk).forEach(([k, p]) => { S.draft[k] = p.get(); }); };
    bindNav(async () => {
      if (si < subs.length - 1) {
        const badHere = [...subs[si].querySelectorAll('.ob-in[name]')].map(el => validateField(el.name)).filter(Boolean);
        if (badHere.length) { subs[si].querySelector('.bad .ob-in')?.focus(); shake(subs[si].querySelector('.bad')); return false; }
        if (S.sub === 0 && S.st.cfg.fields.avatar === 'required' && !S.st.user.avatar) { toast('Subí una foto de perfil', true); return false; }
        keepDraft(); showSub(si + 1, 1); return false;
      }
      const bad = [...fm.querySelectorAll('.ob-in[name]')].map(el => validateField(el.name)).filter(Boolean);
      if (bad.length) { const b0 = fm.querySelector('.bad'); showSub(subOf(b0), -1); }
      if (S.st.cfg.fields.avatar === 'required' && !S.st.user.avatar) { toast('Subí una foto de perfil', true); return false; }
      if (bad.length) { fm.querySelector('.bad .ob-in')?.focus(); shake(fm.querySelector('.bad')); return false; }
      keepDraft();
      const profile = {}; fm.querySelectorAll('.ob-in[name]').forEach(el => { profile[el.name] = el.value.trim(); });
      Object.entries(pk).forEach(([k, p]) => { profile[k] = p.get(); });
      for (const [k, p] of Object.entries(pk)) if (F[k] === 'required' && !p.get().length) { const lab = fm.querySelector(`[data-f="${k}"]`); lab.classList.add('bad'); lab.querySelector('.ob-err').textContent = k === 'tools' ? 'Elegí al menos una herramienta' : 'Elegí o escribí al menos una aptitud'; showSub(subOf(lab)); shake(lab); return false; }
      try { S.st = await api('save_profile', { profile }); S.draft = {}; }
      catch (e) { if (e.data && e.data.errors) { for (const [k, m] of Object.entries(e.data.errors)) { const lab = fm.querySelector(`[data-f="${k}"]`); if (lab) { lab.classList.add('bad'); const x = lab.querySelector('.ob-err'); if (x) x.textContent = m; } } const b0 = fm.querySelector('.bad'); if (b0) showSub(subOf(b0), -1); shake(b0); } throw e; }
    });
  }
  function zoneTime(z) { try { return new Date().toLocaleTimeString('es', { timeZone: z, hour: '2-digit', minute: '2-digit' }); } catch (e) { return ''; } }
  function shake(el) { if (!el || reduce) return; el.animate([{ transform: 'translateX(0)' }, { transform: 'translateX(-6px)' }, { transform: 'translateX(6px)' }, { transform: 'translateX(0)' }], { duration: 280 }); }
  /** Achica la foto en el navegador (el hosting limita el peso de cada pedido). */
  function shrink(file) {
    return new Promise(res => {
      const img = new Image(); img.onload = () => {
        const s = Math.min(1, 512 / Math.max(img.width, img.height)); const c = document.createElement('canvas'); c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); c.toBlob(b => res(b || file), file.type === 'image/png' ? 'image/png' : 'image/jpeg', .88); URL.revokeObjectURL(img.src);
      }; img.onerror = () => res(file); img.src = URL.createObjectURL(file);
    });
  }

  // Paso 2: preferencias
  function stepPrefs(card) {
    const p = S.prefs = { ...S.st.prefs, ...S.prefs };
    const tog = (k, icon, label, sub) => `<label class="ob-tog"><span class="ic"><i class="fa-solid ${icon}"></i></span><span class="tx"><b>${label}</b><small>${sub}</small></span><input type="checkbox" data-k="${k}"${p[k] ? ' checked' : ''}><span class="sw"></span></label>`;
    card.innerHTML = head('fa-sliders', 'Personalizá tu experiencia', 'Todo se puede cambiar después en Mi perfil. Si no sabés, dejá lo que viene marcado.') + `
      <div class="ob-sec"><h3>Tema</h3>${themePicker(p.theme)}</div>
      ${S.st.cfg.fields.language === 'hidden' ? `<div class="ob-sec"><h3>Idioma</h3><select class="ob-in" data-k="language">${Object.entries(S.st.langs).map(([k, l]) => `<option value="${k}"${(p.language || S.st.user.language) === k ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select></div>` : ''}
      <div class="ob-sec"><h3>Avisos</h3>${tog('notif_app', 'fa-bell', 'En la campana', 'Tareas, tickets y solicitudes que te tocan')}${tog('notif_email', 'fa-envelope', 'Por email', 'Lo importante: asignaciones, vencimientos y respuestas')}${tog('notif_desktop', 'fa-display', 'En el navegador', 'Un aviso del sistema aunque la pestaña esté de fondo')}${tog('notif_sound', 'fa-volume-high', 'Con sonido', 'Un sonido corto al llegar algo nuevo')}</div>
      <div class="ob-sec"><h3>Movimiento y sonido</h3>${tog('dashboard_animations', 'fa-wand-magic-sparkles', 'Animaciones', 'Botones que rebotan, ventanas que entran con resorte, festejos y confeti')}${tog('motion_force', 'fa-bolt', 'Animaciones aunque el sistema las apague', matchMedia('(prefers-reduced-motion: reduce)').matches ? 'Tu equipo tiene las animaciones apagadas: con esto se ven igual' : 'Por si Windows o el celular tienen "reducir movimiento"')}${tog('ui_sounds', 'fa-volume-high', 'Sonidos de la interfaz', 'Toques suaves al guardar, abrir, copiar o terminar algo')}</div>
      <div class="ob-sec"><h3>Comunicación</h3>${tog('privacy_online', 'fa-circle', 'Mostrar que estoy en línea', 'El equipo ve el punto verde en el chat')}${tog('privacy_read_receipts', 'fa-check-double', 'Confirmaciones de lectura', 'Mostrar cuándo leíste un mensaje')}${tog('chat_enter_send', 'fa-keyboard', 'Enter envía el mensaje', 'Con Shift + Enter hacés un salto de línea')}</div>
      <button type="button" class="ob-link" id="ob-defaults"><i class="fa-solid fa-rotate-left"></i> Usar los valores predeterminados</button>` + navHtml({ label: 'Guardar y seguir' });
    card.querySelectorAll('[data-theme]').forEach(b => b.addEventListener('click', () => { p.theme = b.dataset.theme; card.querySelectorAll('[data-theme]').forEach(x => x.classList.toggle('on', x === b)); previewTheme(p.theme); }));
    card.querySelectorAll('input[data-k]').forEach(x => x.addEventListener('change', () => { p[x.dataset.k] = x.checked; livePref(x.dataset.k, x.checked, p); }));
    card.querySelector('select[data-k]')?.addEventListener('change', e => { p.language = e.target.value; });
    $('ob-defaults').addEventListener('click', () => { S.prefs = { ...S.st.defaults }; previewTheme(S.prefs.theme); stepPrefs(card); toast('Volviste a los valores predeterminados'); });
    keepDraft = () => {};
    bindNav(async () => { S.st = await api('save_prefs', { prefs: p }); });
  }
  // Selector de temas: "Automático" y todos los de la plataforma, por grupo (lista única: theme_list.php)
  function themePicker(cur) {
    const list = (S.st && S.st.themes) || [{ k: 'dark', label: 'Oscuro', bg: '#0a0a0a', card: '#18181b', accent: '#ff6600', group: 'Oscuros' }, { k: 'light', label: 'Claro', bg: '#f4f2ee', card: '#ffffff', accent: '#e26212', group: 'Claros', light: true }];
    const sw = t => `<button type="button" class="ob-tm${cur === t.k ? ' on' : ''}" data-theme="${esc(t.k)}" title="${esc(t.label)}" style="--t-bg:${esc(t.bg)};--t-card:${esc(t.card)};--t-acc:${esc(t.accent)}"><span class="ob-tm-prev"><i></i><b></b><b></b></span><span class="ob-tm-name">${esc(t.label)}</span></button>`;
    const ORDER = ['Oscuros', 'Claros', 'Vidrio', 'Especiales'], ICON = { Oscuros: 'fa-moon', Claros: 'fa-sun', Vidrio: 'fa-gem', Especiales: 'fa-wand-magic-sparkles' };
    const groups = ORDER.map(n => ({ n, l: list.filter(t => t.group === n) })).filter(g => g.l.length);
    list.forEach(t => { if (!ORDER.includes(t.group)){ let g = groups.find(x => x.n === t.group); if (!g) groups.push(g = { n: t.group, l: [] }); g.l.push(t); } });
    const curG = (list.find(t => t.k === cur) || {}).group || groups[0].n;
    // Pestañas por grupo; el grupo del tema elegido queda abierto
    return `<div class="ob-tms"><button type="button" class="ob-tm ob-tm-auto${cur === 'auto' ? ' on' : ''}" data-theme="auto"><span class="ob-tm-prev"><i class="fa-solid fa-circle-half-stroke"></i></span><span class="ob-tm-name">Automático</span></button></div>`
      + `<div class="ob-tm-tabs" role="tablist">${groups.map(g => `<button type="button" role="tab" class="ob-tm-tab${g.n === curG ? ' on' : ''}" data-g="${esc(g.n)}"><i class="fa-solid ${ICON[g.n] || 'fa-palette'}"></i> ${esc(g.n)} <small>${g.l.length}</small></button>`).join('')}</div>`
      + groups.map(g => `<div class="ob-tms ob-tm-panel" data-g="${esc(g.n)}"${g.n === curG ? '' : ' hidden'}>${g.l.map(sw).join('')}</div>`).join('');
  }
  document.addEventListener('click', e => {
    const tab = e.target.closest && e.target.closest('.ob-tm-tab'); if (!tab) return;
    const box = tab.closest('.ob-sec') || document;
    box.querySelectorAll('.ob-tm-tab').forEach(t => t.classList.toggle('on', t === tab));
    box.querySelectorAll('.ob-tm-panel').forEach(p => { p.hidden = p.dataset.g !== tab.dataset.g; });
  });
  // Movimiento y sonido: se prueban al momento, sin esperar a guardar
  function livePref(k, v, p) {
    const J = window.G7Juice; if (!J) return;
    if (k === 'ui_sounds'){ J.sound(v); if (v) J.test('success'); }
    if (k === 'dashboard_animations' || k === 'motion_force'){
      const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches, on = !!p.dashboard_animations && (!reduce || !!p.motion_force);
      document.documentElement.classList.toggle('g7-move', on);
      if (on && v && J.celebrate) J.celebrate('small');
    }
  }
  function previewTheme(t) {
    const h = document.documentElement;
    let k = t;
    if (t === 'auto') k = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    h.className = h.className.replace(/(^|\s)theme-[a-z-]+/g, ' ').trim() + ' theme-' + k;
    const info = ((S.st && S.st.themes) || []).find(x => x.k === k);
    h.setAttribute('data-theme', info && info.light ? 'light' : (k === 'light' ? 'light' : 'dark'));
    // el acento del tema (si la persona no eligió uno propio todavía)
    if (info){ h.style.setProperty('--g7-accent', info.accent); const m = info.accent.match(/^#([0-9a-f]{6})$/i); if (m){ const n = parseInt(m[1], 16); h.style.setProperty('--g7-accent-rgb', ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255)); } }
  }

  // Paso 3: seguridad (verificación en dos pasos con la app; la activa totp_api.php)
  function stepSecurity(card) {
    const sec = S.st.security;
    if (sec.enabled && (sec.ack || S.st.steps_done.includes('security'))) {
      card.innerHTML = head('fa-shield-halved', 'Tu cuenta está protegida', 'La verificación en dos pasos ya está activa.') + `<div class="ob-secok"><i class="fa-solid fa-shield-check"></i><p>Cuando entres te vamos a pedir el código de tu app. Tus códigos de recuperación se ven o renuevan en Mi perfil › Seguridad.</p></div>` + navHtml();
      bindNav(async () => {}); return;
    }
    if (sec.enabled && S.codes) return showCodes(card);
    if (sec.enabled) {   // la activó (en otro momento) pero no confirmó los códigos: se le ofrece renovarlos para verlos
      card.innerHTML = head('fa-key', 'Guardá tus códigos de recuperación', 'La verificación ya está activa, pero falta confirmar que tenés tus códigos de recuperación.') + `
        <p class="ob-p">Por seguridad no los volvemos a mostrar. Generá unos nuevos con un código de tu app (los anteriores dejan de servir).</p>
        <div class="ob-code" id="ob-code6">${'<input inputmode="numeric" maxlength="1" aria-label="Dígito">'.repeat(6)}</div><em class="ob-err" id="ob-c6err"></em>` + navHtml({ label: 'Generar códigos' });
      bindCode6();
      bindNav(async () => { const r = await totp('recovery_new', { code: code6() }); S.codes = r.recovery; S.st.security.enabled = true; showCodes(card); return false; });
      return;
    }
    card.innerHTML = head('fa-shield-halved', 'Protegé tu cuenta', sec.required ? 'Este sitio pide la verificación en dos pasos para todas las cuentas como la tuya.' : 'Te recomendamos activar la verificación en dos pasos.') + `
      <div class="ob-why"><div><i class="fa-solid fa-key"></i><b>Además de tu contraseña…</b><span>Al entrar te pedimos un código de 6 números que cambia cada 30 segundos.</span></div>
        <div><i class="fa-solid fa-mobile-screen"></i><b>…con una app en tu celular</b><span>Google Authenticator, Microsoft Authenticator, Authy o 1Password.</span></div>
        <div><i class="fa-solid fa-user-shield"></i><b>Si te roban la contraseña</b><span>nadie puede entrar sin tu teléfono.</span></div></div>
      <div id="ob-tf"></div>` + navHtml({ label: 'Activar ahora', extra: sec.can_skip ? '<button type="button" class="ob-btn ghost" id="ob-skip">Más tarde</button>' : '' });
    $('ob-skip')?.addEventListener('click', async () => { try { S.st = await api('security_skip'); S.step++; wizard(1); } catch (e) { toast(e.message, true); } });
    bindNav(async () => { await startTotp(card); return false; });
  }
  async function startTotp(card) {
    const r = await totp('start');
    S.totp = r;
    card.innerHTML = head('fa-qrcode', 'Escaneá el código', 'Abrí tu app autenticadora, tocá "+" y escaneá este código QR.') + `
      <div class="ob-qrwrap"><div class="ob-qr" id="ob-qr" role="img" aria-label="Código QR para la app"></div>
        <div class="ob-qrside"><ol class="ob-howto"><li>Abrí la app (Google Authenticator, Microsoft Authenticator, Authy o 1Password).</li><li>Tocá <b>Agregar</b> o <b>+</b> y elegí <b>Escanear código QR</b>.</li><li>Escribí abajo el código de 6 números que te muestra.</li></ol>
        <details class="ob-manual"><summary><i class="fa-solid fa-keyboard"></i> ¿No podés escanear? Escribí la clave</summary><div class="ob-key"><code id="ob-key">${esc(r.secret.replace(/(.{4})/g, '$1 ').trim())}</code><button type="button" class="ob-btn sm" id="ob-copykey"><i class="fa-regular fa-copy"></i> Copiar</button></div><small>Tipo: basado en tiempo · 6 dígitos · cada 30 segundos.</small></details></div></div>
      <label class="ob-lbl">Código de la app</label><div class="ob-code" id="ob-code6">${'<input inputmode="numeric" maxlength="1" aria-label="Dígito">'.repeat(6)}</div><em class="ob-err" id="ob-c6err"></em>` + navHtml({ label: 'Verificar código' });
    if (window.QRCode) new QRCode($('ob-qr'), { text: r.uri, width: 184, height: 184, correctLevel: QRCode.CorrectLevel.M });
    else $('ob-qr').innerHTML = '<p class="ob-p">No se pudo dibujar el QR: usá la clave.</p>';
    $('ob-copykey').addEventListener('click', () => copy(r.secret, 'Clave copiada'));
    bindCode6();
    bindNav(async () => {
      const c = code6(); if (c.length !== 6) { $('ob-c6err').textContent = 'Escribí los 6 números'; shake($('ob-code6')); return false; }
      try { const x = await totp('confirm', { code: c }); S.codes = x.recovery; S.st.security.enabled = true; successAnim(card, () => showCodes(card)); }
      catch (e) { $('ob-c6err').textContent = e.message; shake($('ob-code6')); $('ob-code6').querySelectorAll('input').forEach(i => { i.value = ''; }); $('ob-code6').querySelector('input').focus(); }
      return false;
    });
    const back = $('ob-back'); if (back) { const nb = back.cloneNode(true); back.replaceWith(nb); nb.addEventListener('click', () => stepSecurity(card)); }   // "Volver" vuelve a la explicación
    $('ob-code6').querySelector('input').focus();
  }
  function bindCode6() {
    const ins = [...$('ob-code6').querySelectorAll('input')];
    ins.forEach((i, n) => {
      i.addEventListener('input', () => { i.value = i.value.replace(/\D/g, '').slice(-1); if (i.value && ins[n + 1]) ins[n + 1].focus(); $('ob-c6err').textContent = ''; if (code6().length === 6) $('ob-next')?.click(); });
      i.addEventListener('keydown', e => { if (e.key === 'Backspace' && !i.value && ins[n - 1]) ins[n - 1].focus(); if (e.key === 'ArrowLeft' && ins[n - 1]) ins[n - 1].focus(); if (e.key === 'ArrowRight' && ins[n + 1]) ins[n + 1].focus(); });
      i.addEventListener('paste', e => { const t = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6); if (!t) return; e.preventDefault(); t.split('').forEach((d, k) => { if (ins[k]) ins[k].value = d; }); ins[Math.min(5, t.length)].focus(); if (t.length === 6) $('ob-next')?.click(); });
    });
  }
  const code6 = () => [...$('ob-code6').querySelectorAll('input')].map(i => i.value).join('');
  function successAnim(card, then) {
    card.innerHTML = `<div class="ob-success"><svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="M15 27l7 7 15-16"/></svg><h2>¡Verificación activada!</h2><p>Desde ahora, tu cuenta pide el código de tu app al entrar.</p></div>`;
    setTimeout(then, reduce ? 300 : 1500);
  }
  function showCodes(card) {
    const codes = S.codes || [];
    const txt = `Códigos de recuperación de ${S.st.platform} (${S.st.user.username})\nCada código sirve UNA sola vez para entrar sin el teléfono.\nGuardalos en un lugar seguro.\n\n${codes.join('\n')}\n\nGenerados el ${new Date().toLocaleString('es')}\n`;
    card.innerHTML = head('fa-life-ring', 'Guardá tus códigos de recuperación', 'Si perdés el teléfono, con uno de estos códigos podés entrar. Cada uno sirve una sola vez y no los vamos a volver a mostrar.') + `
      <div class="ob-codes">${codes.map(c => `<code>${esc(c)}</code>`).join('')}</div>
      <div class="ob-codes-a"><button type="button" class="ob-btn sm" id="ob-ccopy"><i class="fa-regular fa-copy"></i> Copiar</button><button type="button" class="ob-btn sm" id="ob-cdl"><i class="fa-solid fa-download"></i> Descargar .txt</button></div>
      <label class="ob-check"><input type="checkbox" id="ob-saved"><span>Los guardé en un lugar seguro (gestor de contraseñas, papel guardado…)</span></label>` + navHtml({ label: 'Continuar', disabled: true });
    $('ob-ccopy').addEventListener('click', () => copy(codes.join('\n'), 'Códigos copiados'));
    $('ob-cdl').addEventListener('click', () => { const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([txt], { type: 'text/plain' })); a.download = 'codigos-recuperacion-' + (S.st.user.username || 'cuenta') + '.txt'; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 2000); });
    $('ob-saved').addEventListener('change', e => { $('ob-next').disabled = !e.target.checked; });
    const back = $('ob-back'); if (back) back.remove();
    bindNav(async () => { S.st = await api('security_ack', { saved: $('ob-saved').checked }); S.codes = null; });
  }
  function copy(t, msg) { (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(() => toast(msg)).catch(() => { const ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); toast(msg); } catch (e) { toast('No se pudo copiar: seleccionalo a mano', true); } ta.remove(); }); }

  // Paso 4: resumen y fin
  function stepSummary(card) {
    const st = S.st, u = st.user, sec = st.security, done = st.steps_done;
    const item = (ok, t, sub, warn) => `<li class="${ok ? 'ok' : warn ? 'warn' : ''}"><span><i class="fa-solid ${ok ? 'fa-check' : warn ? 'fa-triangle-exclamation' : 'fa-minus'}"></i></span><div><b>${t}</b><small>${sub}</small></div></li>`;
    card.innerHTML = head('fa-list-check', 'Todo listo para empezar', 'Revisá el resumen. Podés volver a cualquier paso.') + `
      <ul class="ob-sum">${item(!st.missing.length, 'Perfil configurado', st.missing.length ? 'Falta: ' + st.missing.length + ' dato(s)' : esc([u.first_name, u.last_name].filter(Boolean).join(' ') || u.display_name) + (u.position ? ' · ' + esc(u.position) : ''), st.missing.length)}
        ${item(done.includes('prefs'), 'Preferencias guardadas', done.includes('prefs') ? 'Tema, avisos y comunicación' : (st.cfg.steps.prefs ? 'Falta guardarlas' : 'Se usan las predeterminadas'), st.cfg.steps.prefs && !done.includes('prefs'))}
        ${item(!!u.timezone, 'Zona horaria establecida', u.timezone ? esc(u.timezone.replace(/_/g, ' ')) + ' · ' + zoneTime(u.timezone) : 'Se usa la del sitio', st.cfg.fields.timezone === 'required' && !u.timezone)}
        ${item(sec.enabled, 'Verificación en dos pasos', sec.enabled ? 'Activada' : sec.skipped ? 'La dejaste para más tarde' : 'Sin activar', sec.required && !sec.enabled)}
        ${item(sec.enabled && (sec.ack || done.includes('security')), 'Códigos de recuperación', sec.enabled ? 'Generados y guardados' : '—', sec.required && !sec.ack)}</ul>
      ${Object.keys(st.pending || {}).length ? `<div class="ob-note warn"><i class="fa-solid fa-triangle-exclamation"></i><span>${esc(Object.values(st.pending).join(' · '))}</span></div>` : ''}` + navHtml({ label: 'Terminar' });
    bindNav(async () => {
      try { S.st = await api('finish'); } catch (e) {
        const p = (e.data && e.data.pending) || {};
        if (p.profile) { S.step = 0; S.sub = 0; wizard(-1); } else if (p.prefs) { S.step = 1; wizard(-1); } else if (p.security) { S.step = 2; wizard(-1); }
        throw e;
      }
      finalScreen(); return false;
    });
  }
  function finalScreen(already = false) {
    const t = S.st.cfg.texts;
    $('ob-main').innerHTML = `<section class="ob-final ob-enter"><div class="ob-burst" aria-hidden="true">${Array.from({ length: 18 }, (_, i) => `<i style="--a:${i * 20}deg;--d:${(i % 5) * 40}ms"></i>`).join('')}</div>
      <div class="ob-success"><svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="M15 27l7 7 15-16"/></svg></div>
      <h1>${esc(t.final_title)}</h1><p class="ob-lead">${esc(already ? 'Tu cuenta ya está configurada.' : t.final_text)}</p>
      <a class="ob-btn primary big" href="${esc(NEXT + (NEXT.includes('?') ? '&' : '?') + 'g7tour=1')}" id="ob-end"><i class="fa-solid fa-compass"></i> Conocé tus herramientas <i class="fa-solid fa-arrow-right"></i></a>
      <a class="ob-link" href="${esc(NEXT)}">${esc(t.final_cta)}</a>
      ${already ? '<a class="ob-link" href="?ver=1">Revisar mi configuración</a>' : ''}</section>`;
    $('ob-end').focus();
  }
})();
