/* assets/perfil-seguridad.js — antes era un <script> en línea de perfil_seguridad.php (CSP estricta, SEC-24 fase 2). */
(() => {
  const box = document.getElementById('tf-box'), adm = document.getElementById('tf-admin'), admBox = document.getElementById('tf-admin-box');
  const e = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  async function api(action, data = {}) {
    const r = await fetch('/toolz/totp_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-2FA': '1' }, credentials: 'same-origin', body: JSON.stringify({ action, ...data }) });
    const j = await r.json().catch(() => ({ error: 'El servidor no respondió bien.' })); if (j.error) throw new Error(j.error); return j;
  }
  const err = (m) => `<p style="color:#ef4444;margin:8px 0 0">${e(m)}</p>`;
  const codesHtml = (list) => `<div style="margin:12px 0;padding:12px;border:1px dashed var(--g7-border-3);border-radius:10px">
      <b>Tus códigos de respaldo</b> · guárdalos en un lugar seguro (gestor de contraseñas o papel). Cada uno sirve una sola vez, para entrar sin el teléfono. <b>No se vuelven a mostrar.</b>
      <pre style="margin:10px 0 0;font:13px/1.8 var(--g7-font-mono,monospace);columns:2">${list.map(e).join('\n')}</pre></div>`;
  let S = null;

  function render(extra = '') {
    if (!S.on) {
      box.innerHTML = `${S.required ? '<p style="color:#fbbf24;margin:0 0 10px"><i class="fa-solid fa-triangle-exclamation"></i> En este sitio es obligatoria: actívala para seguir usando la plataforma.</p>' : ''}
        <p style="margin:0 0 12px;color:var(--g7-text-3)">Necesitas una app de autenticación en el teléfono: Google Authenticator, Microsoft Authenticator, Authy o la de tu gestor de contraseñas.</p>
        <button class="btn btn-primary" id="tf-start"><i class="fa-solid fa-qrcode"></i> Activar</button> <a class="btn btn-ghost" href="/toolz/activar_2fa.php?next=%2Ftoolz%2Fuser_config.php%23seguridad"><i class="fa-solid fa-list-ol"></i> Guía paso a paso</a>${extra}`;
      document.getElementById('tf-start').onclick = start;
    } else {
      box.innerHTML = `<p style="margin:0 0 10px"><i class="fa-solid fa-circle-check" style="color:#22c55e"></i> Activa. Te quedan <b>${S.recovery_left}</b> códigos de respaldo.</p>${extra}
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
          <button class="btn btn-ghost" id="tf-rec"><i class="fa-solid fa-rotate"></i> Nuevos códigos de respaldo</button>
          ${S.required ? '' : '<button class="btn btn-ghost" id="tf-off"><i class="fa-solid fa-power-off"></i> Desactivar</button>'}
        </div><div id="tf-form"></div>`;
      document.getElementById('tf-rec').onclick = () => askCode('Escribe un código de la app para generar códigos nuevos (los anteriores dejan de servir).', false, async (code) => { const r = await api('recovery_new', { code }); S = r; render(codesHtml(r.recovery)); });
      const off = document.getElementById('tf-off'); if (off) off.onclick = () => askCode('Para desactivarla escribe tu contraseña y un código de la app.', true, async (code, password) => { S = await api('disable', { code, password }); render('<p style="color:var(--g7-text-3)">Quedó desactivada.</p>'); });
    }
    renderAdmin();
  }
  function askCode(msg, withPass, fn) {
    const f = document.getElementById('tf-form');
    f.innerHTML = `<div style="margin-top:12px;display:flex;flex-direction:column;gap:8px;max-width:320px"><span style="color:var(--g7-text-3)">${e(msg)}</span>
      ${withPass ? '<input class="cfg-input" type="password" id="tf-pass" placeholder="Contraseña" autocomplete="current-password">' : ''}
      <input class="cfg-input" id="tf-code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" placeholder="Código">
      <button class="btn btn-primary" id="tf-go">Confirmar</button><div id="tf-msg"></div></div>`;
    document.getElementById('tf-go').onclick = async () => {
      try { await fn(document.getElementById('tf-code').value, withPass ? document.getElementById('tf-pass').value : ''); }
      catch (x) { document.getElementById('tf-msg').innerHTML = err(x.message); }
    };
  }
  async function start() {
    try {
      const r = await api('start');
      box.innerHTML = `<p style="margin:0 0 10px">1. Escanea este código con la app (o escribe la clave a mano).</p>
        <div id="tf-qr" style="background:#fff;padding:10px;border-radius:10px;width:max-content"></div>
        <p style="margin:10px 0;color:var(--g7-text-3)">Clave: <code style="user-select:all">${e(r.secret.replace(/(.{4})/g, '$1 ').trim())}</code></p>
        <p style="margin:0 0 6px">2. Escribe el código de 6 números que muestra la app.</p>
        <div style="display:flex;gap:8px;max-width:320px"><input class="cfg-input" id="tf-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456"><button class="btn btn-primary" id="tf-ok">Confirmar</button></div>
        <div id="tf-msg"></div>`;
      if (window.QRCode) new QRCode(document.getElementById('tf-qr'), { text: r.uri, width: 180, height: 180, correctLevel: QRCode.CorrectLevel.M });
      else document.getElementById('tf-qr').textContent = 'No se pudo dibujar el QR: usa la clave.';
      document.getElementById('tf-ok').onclick = async () => {
        try { const j = await api('confirm', { code: document.getElementById('tf-code').value }); S = j; render(codesHtml(j.recovery)); }
        catch (x) { document.getElementById('tf-msg').innerHTML = err(x.message); }
      };
    } catch (x) { box.innerHTML += err(x.message); }
  }
  function renderAdmin() {
    if (!S.is_super) { adm.style.display = 'none'; return; }
    adm.style.display = '';
    const opts = (o, v) => Object.entries(o || {}).map(([k, l]) => `<option value="${e(k)}"${String(k) === String(v) ? ' selected' : ''}>${e(l)}</option>`).join('');
    admBox.innerHTML = `<div style="display:grid;gap:14px">
        <label><b>Verificación en dos pasos obligatoria para</b><br>
          <select class="cfg-input" id="tf-scope" style="margin-top:6px">${opts(S.scopes, S.scope)}</select>
          <span style="display:block;color:var(--g7-text-4);margin-top:4px">Quien no la tenga entra con su contraseña, pero solo puede ir a Mi perfil para activarla. Para exigirla a menos gente se pide un código de tu app.</span></label>
        <label><b>Renovar las contraseñas</b><br>
          <select class="cfg-input" id="tf-pass" style="margin-top:6px">${opts(S.pass_options, S.pass_days)}</select>
          <span style="display:block;color:var(--g7-text-4);margin-top:4px">Al vencer, cada persona tiene que elegir una nueva para seguir (distinta de la actual). Una semana antes le llega un aviso. Al prenderlo, el plazo cuenta desde hoy.</span></label>
      </div>
      <div id="tf-req-msg"></div>
      <p style="margin:14px 0 6px;color:var(--g7-text-3)">Si alguien perdió el teléfono y sus códigos, quítasela acá y que la vuelva a activar:</p>
      <div>${(S.users || []).filter(u => +u.totp_on).map(u => `<div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-top:1px solid var(--g7-border-3)">
        <span>${e(u.name)}${+u.is_super ? ' · superadmin' : u.role === 'admin' ? ' · admin' : ''}</span>
        <button class="btn btn-ghost" data-reset="${+u.id}">Quitar</button></div>`).join('') || '<span style="color:var(--g7-text-4)">Nadie la tiene activa todavía.</span>'}</div>`;
    // CSP: medir 24 h y ver el informe
    const until = +S.csp_until || 0, on = until * 1000 > Date.now();
    admBox.insertAdjacentHTML('beforeend', `<div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--g7-border-3)">
      <b>Política de scripts (CSP), en modo medición</b>
      <p style="margin:4px 0 8px;color:var(--g7-text-3)">Durante 24 h los navegadores avisan qué bloquearía una política estricta (sin bloquear nada). Sirve para preparar el bloqueo real.</p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn ${on ? 'btn-ghost' : 'btn-primary'}" id="csp-toggle">${on ? 'Apagar la medición' : 'Medir 24 h'}</button>
        <button class="btn btn-ghost" id="csp-see">Ver informe</button><button class="btn btn-ghost" id="csp-clear">Borrar informe</button>
        ${on ? `<span style="color:var(--g7-text-4)">Midiendo hasta ${window.G7Time ? G7Time.dateTime(until * 1000) : new Date(until * 1000).toLocaleString()}</span>` : ''}</div><div id="csp-out"></div></div>`);
    document.getElementById('csp-toggle').onclick = async () => { try { S = await api('csp_set', { on: !on }); render(); } catch (x) { alert(x.message); } };
    const showRep = (r) => {
      const rows = (o) => Object.entries(o).map(([k, n]) => `<tr><td style="padding:3px 8px 3px 0">${e(k)}</td><td style="text-align:right">${n}</td></tr>`).join('');
      document.getElementById('csp-out').innerHTML = !r.total ? '<p style="color:var(--g7-text-4);margin-top:10px">Todavía no hay avisos.</p>'
        : `<p style="margin:10px 0 4px">${r.total} avisos en ${r.groups} grupos${r.dropped ? ` (${r.dropped} sin guardar por el tope)` : ''}.</p>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:12px"><div><b>Por causa</b><table>${rows(r.by_cause)}</table></div><div><b>Por página</b><table>${rows(r.by_page)}</table></div></div>
          <details style="margin-top:8px"><summary>Detalle (los 80 grupos más frecuentes)</summary><table style="font-size:11.5px;margin-top:6px">${r.top.map(g => `<tr><td style="padding:2px 8px 2px 0">${g.n}</td><td>${e(g.dir)}</td><td>${e(g.blocked)}</td><td>${e(g.page)}</td><td>${e(g.src)}${g.line ? ':' + g.line : ''}</td><td><code>${e(g.sample)}</code></td></tr>`).join('')}</table></details>`;
    };
    document.getElementById('csp-see').onclick = async () => { try { showRep((await api('csp_report')).report); } catch (x) { alert(x.message); } };
    document.getElementById('csp-clear').onclick = async () => { if (!confirm('¿Borrar el informe de la CSP?')) return; try { showRep((await api('csp_clear')).report); } catch (x) { alert(x.message); } };
    const RANK = { none: 0, super: 1, admins: 2, all: 3 };
    document.getElementById('tf-scope').onchange = async (ev) => {
      const scope = ev.target.value; let code = '';
      if (RANK[scope] < RANK[S.scope]) { code = prompt('Para exigirla a menos gente, escribe un código de tu app:') || ''; if (!code) { ev.target.value = S.scope; return; } }
      try { S = await api('require_set', { scope, code }); render(); }
      catch (x) { ev.target.value = S.scope; document.getElementById('tf-req-msg').innerHTML = err(x.message); }
    };
    document.getElementById('tf-pass').onchange = async (ev) => {
      try { S = await api('pass_policy_set', { days: +ev.target.value }); render(); }
      catch (x) { ev.target.value = S.pass_days; document.getElementById('tf-req-msg').innerHTML = err(x.message); }
    };
    admBox.querySelectorAll('[data-reset]').forEach(b => b.onclick = async () => {
      if (!confirm('¿Quitarle la verificación en dos pasos? Va a entrar solo con la contraseña hasta que la vuelva a activar.')) return;
      try { S = await api('reset_user', { id: +b.dataset.reset }); render(); } catch (x) { alert(x.message); }
    });
  }
  api('state').then(s => { S = s; render(); }).catch(x => { box.innerHTML = err(x.message); });
})();
