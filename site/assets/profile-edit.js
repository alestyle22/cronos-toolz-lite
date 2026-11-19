/* assets/profile-edit.js — Editar perfil: un solo formulario para Mi perfil (perfil.php) y Comunidad.
   Datos: <script type="application/json" id="pe-data"> con pf_edit_data() (profile_fields_lib.php) + save_url.
   Guarda con comunidad.php › save_profile (todos los campos, herramientas y aptitudes). Abre con ProfileEdit.open(),
   con cualquier [data-pe-open] (data-pe-focus="birthday" lleva a ese campo) o con openEditModal() de Comunidad.
   ProfileEdit.check(me) da la lista de lo que falta para el perfil completo. Estilos: profile-edit.css. Doc: toolz/COMUNIDAD.md */
(function () {
  'use strict';
  const node = document.getElementById('pe-data'); if (!node) return;
  let D; try { D = JSON.parse(node.textContent); } catch (e) { return; }
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const moving = () => document.documentElement.classList.contains('g7-move');
  const FX = () => (window.G7Juice && window.G7Juice._fx) || null;

  /** Lo que suma al perfil completo: [clave, etiqueta, ¿está?] */
  function check(m) {
    return [
      ['avatar', 'Foto de perfil', !!m.avatar], ['position', 'Cargo', !!m.position], ['bio', 'Bio', !!m.bio], ['location', 'Ubicación', !!m.location],
      ['birthday', 'Cumpleaños', !!m.birthday], ['joined', 'En el equipo desde', !!m.joined], ['tools', 'Herramientas', (m.tools || []).length > 0],
      ['aptitudes', 'Aptitudes', (m.aptitudes || []).length > 0], ['socials', 'Redes o portfolio', !!(m.instagram || m.behance || m.linkedin || m.portfolio)],
    ];
  }
  const BDAY_WARN = 'Si no cargás tu cumpleaños, el equipo no va a saber cuándo es para saludarte.';

  let ov = null, pk = {}, me = null;
  function open(focus) {
    if (ov) return;
    me = JSON.parse(JSON.stringify(D.me));
    const c = check(me), done = c.filter(x => x[2]).length, pct = Math.round(done * 100 / c.length);
    const f = (id, label, val, o = {}) => `<label class="pe-f${o.wide ? ' wide' : ''}"><span>${label}</span>${o.area ? `<textarea id="pe-${id}" rows="3" maxlength="${o.max || 500}" placeholder="${esc(o.ph || '')}">${esc(val)}</textarea>` : `<input id="pe-${id}" type="${o.type || 'text'}" value="${esc(val)}" maxlength="${o.max || 120}" placeholder="${esc(o.ph || '')}"${o.auto ? ` autocomplete="${o.auto}"` : ''}>`}</label>`;
    ov = document.createElement('div'); ov.className = 'pe-ov'; ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true'); ov.setAttribute('aria-label', 'Editar perfil');
    ov.innerHTML = `<div class="pe-card">
      <header class="pe-head">
        <span class="pe-av" id="pe-av">${me.avatar ? `<img src="${esc(me.avatar)}" alt="">` : esc((me.display_name || me.username || '?').charAt(0).toUpperCase())}<label title="Cambiar foto"><i class="fa-solid fa-camera"></i><input type="file" accept="image/png,image/jpeg,image/webp" id="pe-avf" hidden></label></span>
        <div class="pe-ht"><h3><i class="fa-solid fa-user-pen"></i> Editar perfil</h3>
          <div class="pe-prog" title="${done} de ${c.length}"><i style="--p:${pct}%"></i></div>
          <small>${pct === 100 ? '¡Perfil completo! 🎉' : `Perfil ${pct}% completo · faltan: ${esc(c.filter(x => !x[2]).map(x => x[1]).join(', '))}`}</small></div>
        <button type="button" class="pe-x" data-pe-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button>
      </header>
      <div class="pe-body">
        ${me.birthday ? '' : `<div class="pe-warn" id="pe-bwarn"><span>🎂</span><div><b>Falta tu cumpleaños</b><small>${BDAY_WARN}</small></div><button type="button" data-pe-goto="birthday">Agregarlo</button></div>`}
        <section><h4><i class="fa-solid fa-id-card"></i> Sobre vos</h4><div class="pe-grid">
          ${f('display', 'Nombre', me.display_name, { auto: 'name', max: 100 })}${f('position', 'Cargo', me.position, { ph: 'Ej.: Diseñadora, Vendedor, Community manager', max: 100 })}
          ${f('email', 'Email', me.email, { type: 'email', auto: 'email', max: 150 })}${f('phone', 'Teléfono', me.phone, { type: 'tel', auto: 'tel', max: 40 })}
          ${f('location', 'Ubicación', me.location, { ph: 'Ciudad, País', wide: true, max: 120 })}
          ${f('bio', 'Bio', me.bio, { area: true, wide: true, ph: 'Contá en pocas líneas qué hacés y qué te gusta.', max: 500 })}</div></section>
        <section><h4><i class="fa-solid fa-calendar-days"></i> Fechas</h4><div class="pe-grid">
          <label class="pe-f" id="pe-bf"><span>🎂 Cumpleaños <em>se muestra solo el día y el mes</em></span><input id="pe-birthday" type="date" value="${esc(me.birthday)}"></label>
          <label class="pe-f"><span>🥂 En el equipo desde</span><input id="pe-joined" type="date" value="${esc(me.joined)}"></label></div></section>
        <section><h4><i class="fa-solid fa-screwdriver-wrench"></i> Herramientas <small>Programas y tecnologías que usás</small></h4><div id="pe-tools"></div></section>
        <section><h4><i class="fa-solid fa-star"></i> Aptitudes <small>Lo que sabés hacer, aunque no sea diseño. Si no está, escribila.</small></h4><div id="pe-apt"></div></section>
        <section><h4><i class="fa-solid fa-share-nodes"></i> Redes sociales</h4><div class="pe-grid three">
          ${f('instagram', '<i class="fa-brands fa-instagram" style="color:#e1306c"></i> Instagram', me.instagram, { ph: '@usuario', max: 60 })}
          ${f('behance', '<i class="fa-brands fa-behance" style="color:#1769ff"></i> Behance', me.behance, { ph: 'usuario', max: 60 })}
          ${f('linkedin', '<i class="fa-brands fa-linkedin" style="color:#0a66c2"></i> LinkedIn', me.linkedin, { ph: 'in/usuario', max: 120 })}
          ${f('portfolio', '<i class="fa-solid fa-globe" style="color:#60a5fa"></i> Portfolio', me.portfolio, { ph: 'https://…', wide: true, max: 200, type: 'url' })}</div></section>
      </div>
      <footer class="pe-foot"><span class="pe-msg" id="pe-msg" role="status"></span><button type="button" class="pe-btn" data-pe-close>Cancelar</button><button type="button" class="pe-btn pri" id="pe-save"><i class="fa-solid fa-floppy-disk"></i> Guardar</button></footer>
    </div>`;
    document.body.appendChild(ov);
    document.documentElement.classList.add('pe-lock');
    pk.tools = PFPicker.field(ov.querySelector('#pe-tools'), { title: 'Herramientas', help: 'Programas y tecnologías que usás.', button: 'Elegir herramientas', groups: D.tools, selected: me.tools, placeholder: 'Buscar herramienta… (Figma, Excel, Canva)', empty: 'Elegí las que usás.' });
    pk.apt = PFPicker.field(ov.querySelector('#pe-apt'), { title: 'Aptitudes', help: 'Lo que sabés hacer, aunque no sea diseño. Si no está en la lista, escribila.', button: 'Elegir aptitudes', groups: D.aptitudes, selected: me.aptitudes, custom: true, placeholder: 'Buscar o escribir una aptitud… (Ventas, Liderazgo)', empty: 'Elegí o escribí lo que sabés hacer.' });
    if (moving()) ov.querySelector('.pe-card').animate([{ opacity: 0, transform: 'translateY(24px) scale(.97)' }, { opacity: 1, transform: 'none' }], { duration: 380, easing: 'cubic-bezier(.2,.8,.2,1)' });
    ov.querySelector('#pe-birthday').addEventListener('input', e => { const w = ov.querySelector('#pe-bwarn'); if (w) w.classList.toggle('ok', !!e.target.value); });
    ov.querySelector('#pe-avf').addEventListener('change', avatar);
    ov.querySelector('#pe-save').addEventListener('click', save);
    ov.addEventListener('click', e => {
      if (e.target === ov || e.target.closest('[data-pe-close]')) close();
      const g = e.target.closest('[data-pe-goto]'); if (g) goto(g.dataset.peGoto);
    });
    setTimeout(() => focus ? goto(focus) : ov.querySelector('#pe-display')?.focus(), 60);
  }
  function goto(k) {
    const el = ov && ov.querySelector('#pe-' + k); if (!el) return;
    el.scrollIntoView({ block: 'center', behavior: moving() ? 'smooth' : 'auto' });
    const target = /^(INPUT|TEXTAREA)$/.test(el.tagName) ? el : el.querySelector('input, button');
    setTimeout(() => { target && target.focus(); }, moving() ? 350 : 0);
    const lab = el.closest('.pe-f') || el.closest('section'); if (lab) { lab.classList.remove('pe-hl'); void lab.offsetWidth; lab.classList.add('pe-hl'); }
  }
  function close() { if (!ov) return; ov.remove(); ov = null; document.documentElement.classList.remove('pe-lock'); }
  const msg = (t, bad) => { const m = ov && ov.querySelector('#pe-msg'); if (m) { m.textContent = t; m.classList.toggle('bad', !!bad); } };

  async function avatar(e) {
    const file = e.target.files[0]; if (!file) return;
    const box = ov.querySelector('#pe-av'); box.classList.add('busy'); msg('Subiendo foto…');
    try {
      const fd = new FormData(); fd.append('action', 'update_avatar'); fd.append('avatar_file', window.g7PrepImage ? await g7PrepImage(file, { max: 1200 }) : file);
      const r = await fetch('/toolz/user_config.php', { method: 'POST', credentials: 'same-origin', body: fd }).then(x => x.json());
      if (!r || r.error || !r.url) throw new Error((r && r.error) || 'No se pudo subir la foto');
      me.avatar = D.me.avatar = r.url; box.querySelector('img') ? box.querySelector('img').src = r.url : box.insertAdjacentHTML('afterbegin', `<img src="${esc(r.url)}" alt="">`);
      [...box.childNodes].forEach(n => { if (n.nodeType === 3) n.remove(); });
      msg('Foto actualizada ✔');
    } catch (er) { msg(er.message, true); } finally { box.classList.remove('busy'); }
  }

  async function save() {
    const v = id => (ov.querySelector('#pe-' + id)?.value || '').trim();
    const b = ov.querySelector('#pe-save'); b.disabled = true; msg('Guardando…');
    const tools = {}; pk.tools.get().forEach(t => { tools[t] = 1; });
    const fd = new FormData(); fd.append('action', 'save_profile');
    [['display_name', 'display'], ['position'], ['email'], ['phone'], ['location'], ['bio'], ['instagram'], ['behance'], ['linkedin'], ['portfolio'], ['birthday'], ['joined']].forEach(([k, id]) => fd.append(k, v(id || k)));
    fd.append('skills_json', JSON.stringify(tools)); fd.append('aptitudes_json', JSON.stringify(pk.apt.get()));
    fd.append('current_avatar', me.avatar || ''); fd.append('current_cover', me.cover || '');
    try {
      const r = await fetch(D.save_url || '/toolz/comunidad.php', { method: 'POST', credentials: 'same-origin', body: fd }).then(x => x.json()).catch(() => null);
      if (!r || r.status !== 'success') throw new Error((r && (r.error || r.message)) || 'No se pudo guardar el perfil');
      msg('¡Guardado! ✔');
      const f = FX(), br = b.getBoundingClientRect(); if (f && moving()) f.confetti(40, { x: br.left + br.width / 2, y: br.top });
      setTimeout(() => location.reload(), 700);
    } catch (er) { msg(er.message, true); b.disabled = false; }
  }

  document.addEventListener('keydown', e => { if (e.key === 'Escape' && ov) close(); });
  document.addEventListener('click', e => { const o = e.target.closest('[data-pe-open]'); if (o) { e.preventDefault(); open(o.dataset.peFocus || ''); } });
  window.ProfileEdit = { open, close, check, data: D, BDAY_WARN };

  // Mi perfil: tarjeta "Completá tu perfil" con lo que falta y el aviso del cumpleaños
  const FOCUS = { avatar: 'display', position: 'position', bio: 'bio', location: 'location', birthday: 'birthday', joined: 'joined', tools: 'tools', aptitudes: 'apt', socials: 'instagram' };
  const box = document.getElementById('pf-complete');
  if (box) {
    const c = check(D.me), miss = c.filter(x => !x[2]), pct = Math.round((c.length - miss.length) * 100 / c.length);
    let html = '';
    if (!D.me.birthday) html += `<div class="pe-warn pe-warn-page"><span>🎂</span><div><b>No cargaste tu cumpleaños</b><small>${BDAY_WARN}</small></div><button type="button" data-pe-open data-pe-focus="birthday">Agregar cumpleaños</button></div>`;
    if (miss.length) html += `<div class="pe-done"><div class="pe-done-t"><b>Completá tu perfil</b><span>${pct}%</span></div><div class="pe-prog"><i style="--p:${pct}%"></i></div>
      <div class="pe-miss">${miss.map(([k, l]) => `<button type="button" data-pe-open data-pe-focus="${FOCUS[k]}"><i class="fa-solid fa-plus"></i> ${esc(l)}</button>`).join('')}</div></div>`;
    box.innerHTML = html; box.hidden = !html;
  }
  // En Comunidad reemplaza al formulario viejo (openEditModal / ?editar=1)
  if (typeof window.openEditModal === 'function') window.openEditModal = f => open(typeof f === 'string' ? f : '');
  const qs = new URLSearchParams(location.search);
  if (qs.get('editar') === '1' && !document.getElementById('edit-modal')) setTimeout(() => open(qs.get('campo') || ''), 300);
})();
