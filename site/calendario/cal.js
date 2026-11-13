/* calendario/cal.js — Mi calendario: vistas Mes, Semana y Agenda; alta y edición de eventos. Habla con api.php. */
'use strict';
(() => {
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const store = { get(k, d) { try { const v = localStorage.getItem('cal_' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } }, set(k, v) { try { localStorage.setItem('cal_' + k, JSON.stringify(v)); } catch (e) {} } };
  const pad = n => String(n).padStart(2, '0');
  const ymd = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const parse = s => { const [a, b = '00:00:00'] = String(s).split(' '); const [y, m, d] = a.split('-').map(Number); const [h, mi] = b.split(':').map(Number); return new Date(y, m - 1, d, h || 0, mi || 0); };
  const addDays = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
  const startOfWeek = d => { const x = new Date(d.getFullYear(), d.getMonth(), d.getDate()); const w = (x.getDay() + 6) % 7; return addDays(x, -w); };   // semana de lunes a domingo
  const hm = d => `${pad(d.getHours())}:${pad(d.getMinutes())}`;
  const fmt = (d, o) => d.toLocaleDateString('es', o);
  const DOW = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
  const REMIND = [['', 'Sin aviso'], ['0', 'A la hora'], ['10', '10 minutos antes'], ['30', '30 minutos antes'], ['60', '1 hora antes'], ['120', '2 horas antes'], ['1440', '1 día antes'], ['2880', '2 días antes'], ['10080', '1 semana antes']];
  const S = { view: store.get('view', innerWidth < 700 ? 'agenda' : 'month'), cur: new Date(), tasks: store.get('tasks', true) && !!CAL.tasks, data: { events: [], tasks: [] }, seq: 0 };

  async function api(action, body = {}) {
    const r = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, ...body }) });
    const d = await r.json().catch(() => ({ error: 'Respuesta inválida del servidor.' }));
    if (!r.ok || d.error) throw new Error(d.error || 'No se pudo completar.');
    return d;
  }
  function toast(m, bad) { const t = $('cal-toast'); t.textContent = m; t.style.background = bad ? '#ef4444' : '#22c55e'; t.style.color = bad ? '#fff' : '#000'; t.classList.add('on'); clearTimeout(t._h); t._h = setTimeout(() => t.classList.remove('on'), 2400); }

  // ── Rango visible ──────────────────────────────────────
  function range() {
    const c = S.cur;
    if (S.view === 'month') { const first = new Date(c.getFullYear(), c.getMonth(), 1), from = startOfWeek(first); return [from, addDays(from, 42)]; }
    if (S.view === 'week') { const from = startOfWeek(c); return [from, addDays(from, 7)]; }
    const from = new Date(c.getFullYear(), c.getMonth(), c.getDate()); return [from, addDays(from, 60)];
  }
  function label() {
    const c = S.cur, [f, t] = range();
    if (S.view === 'month') return fmt(c, { month: 'long', year: 'numeric' });
    const e = addDays(t, -1);
    if (S.view === 'week') return f.getMonth() === e.getMonth() ? `${f.getDate()} – ${e.getDate()} de ${fmt(f, { month: 'long', year: 'numeric' })}` : `${fmt(f, { day: 'numeric', month: 'short' })} – ${fmt(e, { day: 'numeric', month: 'short', year: 'numeric' })}`;
    return `Desde el ${fmt(f, { day: 'numeric', month: 'long' })}`;
  }
  async function load() {
    const [f, t] = range(), my = ++S.seq;
    const lb = label(); $('cal-label').textContent = lb.charAt(0).toUpperCase() + lb.slice(1);
    document.querySelectorAll('.cal-seg button').forEach(b => { b.classList.toggle('on', b.dataset.view === S.view); b.setAttribute('aria-selected', b.dataset.view === S.view); });
    $('cal-body').setAttribute('aria-busy', 'true');
    try { const d = await api('list', { from: ymd(f), to: ymd(t), tasks: S.tasks }); if (my !== S.seq) return; S.data = d; draw(); }
    catch (e) { $('cal-body').innerHTML = `<div class="cal-empty"><i class="fa-solid fa-triangle-exclamation"></i>${esc(e.message)}</div>`; }
    $('cal-body').setAttribute('aria-busy', 'false');
  }

  // Qué hay cada día: eventos (los de varios días aparecen en cada uno) y tareas
  function byDay() {
    const m = {};
    for (const e of S.data.events) {
      const s = parse(e.start), en = parse(e.end);
      for (let d = new Date(s.getFullYear(), s.getMonth(), s.getDate()); d <= en; d = addDays(d, 1)) (m[ymd(d)] ||= []).push({ k: 'e', e, s, en, first: ymd(d) === ymd(s) });
    }
    for (const t of S.data.tasks) (m[t.date] ||= []).push({ k: 't', t });
    for (const k in m) m[k].sort((a, b) => (a.k === 't') - (b.k === 't') || (b.e?.all_day || 0) - (a.e?.all_day || 0) || (a.s || 0) - (b.s || 0));
    return m;
  }
  const chip = (it, wide) => it.k === 't'
    ? `<button type="button" class="cal-chip task${it.t.done ? ' done' : ''}" data-task="${it.t.id}" title="Tarea: ${esc(it.t.title)}"><span class="n"><i class="fa-regular fa-square-check"></i> ${esc(it.t.title)}</span></button>`
    : `<button type="button" class="cal-chip" style="--c:${esc(it.e.color || '')}" data-ev="${it.e.id}" data-start="${esc(it.e.start)}" title="${esc(it.e.title)}">${!it.e.all_day && it.first ? `<span class="t">${hm(it.s)}</span>` : ''}<span class="n">${esc(it.e.title)}${it.e.repeat_rule ? ' <i class="fa-solid fa-repeat" style="font-size:9px;opacity:.6"></i>' : ''}</span>${wide && it.e.location ? `<span class="t">${esc(it.e.location)}</span>` : ''}</button>`;

  function draw() {
    const m = byDay(), today = window.G7Time ? G7Time.dayKey(new Date()) : ymd(new Date()), [f, t] = range();
    let h = '';
    if (S.view === 'month') {
      h = `<div class="cal-dow">${DOW.map(d => `<span>${d}</span>`).join('')}</div><div class="cal-grid">`;
      for (let d = new Date(f); d < t; d = addDays(d, 1)) {
        const k = ymd(d), items = m[k] || [], show = items.slice(0, 3);
        h += `<div class="cal-day${d.getMonth() !== S.cur.getMonth() ? ' out' : ''}${k === today ? ' today' : ''}" data-day="${k}"><span class="cal-num">${d.getDate()}</span>${show.map(i => chip(i)).join('')}${items.length > 3 ? `<button type="button" class="cal-more" data-goto="${k}">+${items.length - 3} más</button>` : ''}</div>`;
      }
      h += '</div>';
    } else if (S.view === 'week') {
      h = '<div class="cal-week">';
      for (let d = new Date(f); d < t; d = addDays(d, 1)) {
        const k = ymd(d), items = m[k] || [];
        h += `<div class="cal-wcol${k === today ? ' today' : ''}" data-day="${k}"><div class="cal-whead"><small>${DOW[(d.getDay() + 6) % 7]}</small><b>${d.getDate()}</b></div><div>${items.map(i => chip(i, true)).join('') || ''}</div></div>`;
      }
      h += '</div>';
    } else {
      const days = Object.keys(m).filter(k => k >= ymd(f) && k < ymd(t)).sort();
      h = days.length ? '<div class="cal-agenda">' + days.map(k => {
        const d = parse(k);
        return `<div class="cal-aday${k === today ? ' today' : ''}"><div class="cal-adate"><b>${d.getDate()}</b><small>${fmt(d, { weekday: 'long', month: 'short' })}</small></div><div>${m[k].map(it => it.k === 't'
          ? `<button type="button" class="cal-aitem" data-task="${it.t.id}"><span class="dot task"></span><span class="tm">Tarea</span><span class="nm">${esc(it.t.title)}${it.t.done ? ' <span class="ex">Terminada</span>' : ''}</span></button>`
          : `<button type="button" class="cal-aitem" data-ev="${it.e.id}" data-start="${esc(it.e.start)}"><span class="dot" style="--c:${esc(it.e.color || '')}"></span><span class="tm">${it.e.all_day ? 'Todo el día' : it.first ? hm(it.s) + ' – ' + hm(it.en) : 'Sigue'}</span><span class="nm">${esc(it.e.title)}${it.e.repeat_rule ? '<i class="fa-solid fa-repeat rep" title="Se repite"></i>' : ''}${it.e.location ? `<span class="ex"><i class="fa-solid fa-location-dot"></i> ${esc(it.e.location)}</span>` : ''}</span></button>`).join('')}</div></div>`;
      }).join('') + '</div>' : `<div class="cal-empty"><i class="fa-regular fa-calendar"></i>No tienes nada en los próximos 60 días.<br><button class="cal-new" type="button" style="margin-top:14px" data-newev><i class="fa-solid fa-plus"></i> Crear un evento</button></div>`;
    }
    $('cal-body').innerHTML = h;
  }

  // ── Formulario ─────────────────────────────────────────
  let editing = null;
  function openForm(ev, date) {
    const now = new Date(), d = date ? parse(date) : now;
    const s = ev ? parse(ev.base_start) : new Date(d.getFullYear(), d.getMonth(), d.getDate(), date ? 9 : Math.min(now.getHours() + 1, 23), 0);
    const en = ev ? parse(ev.base_end) : new Date(s.getTime() + 3600e3);
    const e = ev || { title: '', notes: '', location: '', all_day: 0, color: CAL.colors[0], repeat_rule: '', repeat_until: null, remind_min: 30 };
    editing = ev ? ev.id : null;
    const f = $('cal-form');
    f.innerHTML = `<h3 id="cal-f-h">${ev ? 'Editar evento' : 'Nuevo evento'}</h3>
      <div class="cal-f"><input class="cal-in big" id="f-title" maxlength="200" placeholder="Título del evento" value="${esc(e.title)}" required aria-label="Título"></div>
      <label class="cal-switch"><input type="checkbox" id="f-allday" ${e.all_day ? 'checked' : ''}> Todo el día</label>
      <div class="cal-row4"><div class="cal-f"><label for="f-d1">Empieza</label><input class="cal-in" type="date" id="f-d1" value="${ymd(s)}"></div>
        <div class="cal-f f-time"><label for="f-t1">Hora</label><input class="cal-in" type="time" id="f-t1" value="${hm(s)}"></div>
        <div class="cal-f"><label for="f-d2">Termina</label><input class="cal-in" type="date" id="f-d2" value="${ymd(en)}"></div>
        <div class="cal-f f-time"><label for="f-t2">Hora</label><input class="cal-in" type="time" id="f-t2" value="${hm(en)}"></div></div>
      <div class="cal-row"><div class="cal-f"><label for="f-rep">Repetir</label><select class="cal-in" id="f-rep">${Object.entries(CAL.repeats).map(([k, v]) => `<option value="${k}"${k === e.repeat_rule ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select></div>
        <div class="cal-f" id="f-until-w"><label for="f-until">Hasta (opcional)</label><input class="cal-in" type="date" id="f-until" value="${esc(e.repeat_until || '')}"></div></div>
      <div class="cal-row"><div class="cal-f"><label for="f-rem">Aviso</label><select class="cal-in" id="f-rem">${REMIND.map(([k, v]) => `<option value="${k}"${String(e.remind_min ?? '') === k ? ' selected' : ''}>${v}</option>`).join('')}</select></div>
        <div class="cal-f"><span class="cal-lbl">Color</span><div class="cal-sw" id="f-color">${CAL.colors.map(c => `<button type="button" data-c="${c}" style="background:${c}" class="${c === (e.color || CAL.colors[0]) ? 'on' : ''}" aria-label="Color ${c}"></button>`).join('')}</div></div></div>
      <div class="cal-f"><label for="f-loc">Lugar o link (opcional)</label><input class="cal-in" id="f-loc" maxlength="200" value="${esc(e.location)}" placeholder="Oficina, Meet, Zoom…"></div>
      <div class="cal-f"><label for="f-notes">Notas (opcional)</label><textarea class="cal-in" id="f-notes" maxlength="5000">${esc(e.notes)}</textarea></div>
      ${ev && ev.repeat_rule ? '<p class="cal-note"><i class="fa-solid fa-repeat"></i> Se repite: los cambios valen para todas las repeticiones.</p>' : ''}
      <p class="cal-note">Avisa en la campana de notificaciones, con la página abierta.</p>
      <div class="cal-err" id="f-err" role="alert"></div>
      <div class="cal-acts">${ev ? '<button type="button" class="del" id="f-del"><i class="fa-regular fa-trash-can"></i> Eliminar</button>' : ''}<span class="sp"></span><button type="button" class="cancel" id="f-cancel">Cancelar</button><button type="submit" class="ok">${ev ? 'Guardar' : 'Crear'}</button></div>`;
    const sync = () => { const ad = $('f-allday').checked; f.querySelectorAll('.f-time').forEach(x => x.style.display = ad ? 'none' : ''); $('f-until-w').style.visibility = $('f-rep').value ? 'visible' : 'hidden'; };
    $('f-allday').onchange = sync; $('f-rep').onchange = sync; sync();
    $('f-d1').onchange = () => { if ($('f-d2').value < $('f-d1').value) $('f-d2').value = $('f-d1').value; };
    $('f-t1').onchange = () => { if ($('f-d2').value === $('f-d1').value && $('f-t2').value <= $('f-t1').value) { const [hh, mm] = $('f-t1').value.split(':').map(Number); $('f-t2').value = `${pad(Math.min(hh + 1, 23))}:${pad(mm)}`; } };
    $('f-color').onclick = ev2 => { const b = ev2.target.closest('button[data-c]'); if (!b) return; $('f-color').querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b)); };
    $('f-cancel').onclick = closeForm;
    if (ev) $('f-del').onclick = async () => {
      if (!confirm(ev.repeat_rule ? '¿Eliminar este evento y todas sus repeticiones?' : '¿Eliminar este evento?')) return;
      try { await api('delete', { id: ev.id }); closeForm(); toast('Evento eliminado'); load(); } catch (er) { $('f-err').textContent = er.message; }
    };
    $('cal-modal').hidden = false; setTimeout(() => $('f-title').focus(), 30);
  }
  function closeForm() { $('cal-modal').hidden = true; editing = null; if (location.hash.startsWith('#e/')) history.replaceState(null, '', location.pathname); }
  $('cal-form').onsubmit = async e => {
    e.preventDefault();
    const b = { id: editing || 0, title: $('f-title').value.trim(), all_day: $('f-allday').checked, date: $('f-d1').value, end_date: $('f-d2').value, start_time: $('f-t1').value, end_time: $('f-t2').value,
      repeat_rule: $('f-rep').value, repeat_until: $('f-until').value, remind_min: $('f-rem').value, color: $('f-color').querySelector('.on')?.dataset.c || '', location: $('f-loc').value, notes: $('f-notes').value };
    if (!b.title) { $('f-err').textContent = 'Pon un título.'; $('f-title').focus(); return; }
    const btn = e.submitter || $('cal-form').querySelector('.ok'); btn.disabled = true;
    try { const wasEdit = !!editing; await api('save', b); closeForm(); toast(wasEdit ? 'Evento guardado' : 'Evento creado'); S.cur = parse(b.date); load(); }
    catch (er) { $('f-err').textContent = er.message; }
    btn.disabled = false;
  };
  $('cal-modal').addEventListener('mousedown', e => { if (e.target === $('cal-modal')) closeForm(); });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !$('cal-modal').hidden) return closeForm();
    if (!$('cal-modal').hidden || /INPUT|TEXTAREA|SELECT/.test(document.activeElement?.tagName || '')) return;
    if (e.key === 'n' || e.key === 'N') { e.preventDefault(); openForm(null); }
    else if (e.key === 't' || e.key === 'T') $('cal-today').click();
    else if (e.key === 'ArrowLeft') $('cal-prev').click(); else if (e.key === 'ArrowRight') $('cal-next').click();
  });

  // ── Clics en el calendario ─────────────────────────────
  $('cal-body').addEventListener('click', e => {
    if (e.target.closest('[data-newev]')) { $('cal-new').click(); return; }   // "Crear un evento" de la agenda vacía
    const t = e.target.closest('[data-task]'); if (t) { location.href = '/toolz/tareas.php?t=' + t.dataset.task; return; }
    const ev = e.target.closest('[data-ev]');
    if (ev) { const it = S.data.events.find(x => x.id === +ev.dataset.ev && x.start === ev.dataset.start) || S.data.events.find(x => x.id === +ev.dataset.ev); if (it) openForm(it); return; }
    const more = e.target.closest('[data-goto]'); if (more) { S.view = 'week'; S.cur = parse(more.dataset.goto); store.set('view', S.view); load(); return; }
    const day = e.target.closest('[data-day]'); if (day) openForm(null, day.dataset.day);
  });
  const step = dir => { const c = S.cur; S.cur = S.view === 'month' ? new Date(c.getFullYear(), c.getMonth() + dir, 1) : addDays(c, dir * (S.view === 'week' ? 7 : 30)); load(); };
  $('cal-prev').onclick = () => step(-1); $('cal-next').onclick = () => step(1);
  $('cal-today').onclick = () => { S.cur = new Date(); load(); };
  $('cal-new').onclick = () => openForm(null, S.view === 'month' && S.cur.getMonth() !== new Date().getMonth() ? ymd(new Date(S.cur.getFullYear(), S.cur.getMonth(), 1)) : null);
  document.querySelectorAll('.cal-seg button').forEach(b => b.onclick = () => { S.view = b.dataset.view; store.set('view', S.view); load(); });
  if ($('cal-show-tasks')) { $('cal-show-tasks').checked = S.tasks; $('cal-show-tasks').onchange = e => { S.tasks = e.target.checked; store.set('tasks', S.tasks); load(); }; }

  // Abrir un evento desde un aviso (#e/ID)
  async function fromHash() {
    const m = location.hash.match(/^#e\/(\d+)/); if (!m) return;
    try { const d = await api('get', { id: +m[1] }); S.cur = parse(d.event.start); await load(); openForm(d.event); } catch (e) { toast(e.message, true); }
  }
  addEventListener('hashchange', fromHash);
  load().then(fromHash);
})();
