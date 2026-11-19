/* recomendar/app.js — "Recomienda Cronos" (habla con api.php). Muestra el link propio, lo ganado, las agencias recomendadas,
   el formulario para recomendar y la elección de descontar o cobrar. Los números los calcula el Cronos principal. Doc: toolz/AFILIADOS.md */
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const SYM = { ARS: '$', USD: 'US$', EUR: '€', BRL: 'R$', MXN: 'MX$', CLP: 'CLP$', COP: 'COL$', PEN: 'S/', UYU: '$U', VES: 'Bs.', BOB: 'Bs', PYG: '₲', GTQ: 'Q', CRC: '₡', DOP: 'RD$', HNL: 'L', NIO: 'C$', PAB: 'B/.', CAD: 'CA$', GBP: '£' };
  const money = (n, c) => { const d = ['CLP', 'PYG'].includes(c) ? 0 : 2; return (SYM[c] || c) + ' ' + Number(n || 0).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d }); };
  const fdate = d => d ? new Date(String(d).length === 10 ? d + 'T12:00' : String(d).replace(' ', 'T')).toLocaleDateString('es-AR', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
  const body = $('#rc-body');
  let toastT;
  const toast = (m, bad) => { const t = $('#rc-toast'); t.textContent = m; t.className = 'rc-toast on' + (bad ? ' bad' : ''); clearTimeout(toastT); toastT = setTimeout(() => t.className = 'rc-toast', 3600); };
  async function api(action, data = {}) {
    let r, j;
    try { r = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-RC': '1' }, body: JSON.stringify({ action, ...data }), credentials: 'same-origin' }); }
    catch (e) { toast('No hay conexión con el servidor', true); throw e; }
    try { j = await r.json(); } catch (e) { j = { error: 'El servidor respondió algo inesperado (' + r.status + ')' }; }
    if (!r.ok || j.error) { toast(j.error || 'Error', true); throw new Error(j.error || 'Error'); }
    return j;
  }
  const PILL = { espera: ['En espera', '#64748b'], disponible: ['Disponible', '#22c55e'], reservada: ['Se descuenta en tu próximo pago', '#3b82f6'], aplicada: ['Descontado de tu plan', '#0ea5e9'],
    liquidada: ['En liquidación', '#a855f7'], pagada: ['Pagado', '#16a34a'], falta_tasa: ['Esperando la tasa', '#f59e0b'] };
  const pill = (k, label) => `<span class="rc-pill" style="--c:${(PILL[k] || ['', '#64748b'])[1]}">${esc(label || (PILL[k] || [k])[0])}</span>`;

  async function load() {
    let r;
    try { r = await api('me'); } catch (e) { body.innerHTML = `<div class="rc-card rc-empty"><i class="fa-solid fa-plug-circle-xmark"></i><p>${esc(e.message)}</p><button class="rc-btn" id="rc-retry">Probar de nuevo</button></div>`; $('#rc-retry').onclick = load; return; }
    if (!r.connected) { body.innerHTML = '<div class="rc-card rc-empty"><i class="fa-solid fa-plug"></i><p>Este sitio no está conectado al Cronos principal, así que no participa del programa de recomendados.</p></div>'; return; }
    const s = r.summary;
    if (!s) {
      body.innerHTML = `<div class="rc-card rc-empty"><i class="fa-solid fa-hourglass-half"></i><p>Estamos preparando tu link. Vuelve en unos minutos.</p></div>${r.can.edit ? leadForm() : ''}`;
      bindLead(); return;
    }
    $('#rc-rule').textContent = s.rule + '. Lo descuentas de tu plan o lo cobras.';
    const tot = Object.entries(s.totals || {});
    const queued = (r.queued || []).filter(q => q.kind === 'lead');
    body.innerHTML = `
      <section class="rc-card rc-link"><h2>Tu link</h2><p>Compártelo con agencias que puedan usar Cronos. Quien entre por él y pida una demo queda a tu nombre.</p>
        <div class="rc-copy"><code>${esc(s.link)}</code><button class="rc-btn primary" id="rc-copy"><i class="fa-regular fa-copy"></i> Copiar</button></div>
        <p class="rc-m">Tu código: <b>${esc(s.code)}</b> · ${s.clicks} clics en los últimos 30 días${s.status !== 'activo' ? ' · <b class="rc-bad">Tu participación está en pausa</b>' : ''}</p></section>
      <section class="rc-stats">${tot.length ? tot.map(([c, t], i) => `
        <div class="rc-stat" style="--i:${i}"><small>Disponible (${esc(c)})</small><b>${money(t.disponible, c)}</b><span>En espera ${money(t.espera, c)}${t.falta_tasa ? ` · ${t.falta_tasa} esperando la tasa` : ''}</span></div>
        <div class="rc-stat" style="--i:${i + 1}"><small>${s.mode === 'descuento' ? 'Descontado de tu plan' : 'Ya cobrado'} (${esc(c)})</small><b>${money(s.mode === 'descuento' ? t.usado : t.pagado, c)}</b><span>${s.mode === 'descuento' ? 'Cobrado ' + money(t.pagado, c) : 'Descontado ' + money(t.usado, c)}</span></div>`).join('')
        : '<div class="rc-stat"><small>Ganado</small><b>—</b><span>Todavía no hay pagos de agencias que recomendaste</span></div>'}
        <div class="rc-stat"><small>Agencias recomendadas</small><b>${s.referrals.length}</b><span>${s.referrals.filter(x => x.status === 'cliente').length} ya son clientes</span></div></section>
      <div class="rc-grid">
        <section class="rc-card"><h2>Tus recomendadas</h2>${s.referrals.length || s.leads.length || queued.length ? `<ul class="rc-list">
          ${s.referrals.map(x => `<li><b>${esc(x.agency)}</b><small>desde ${fdate(x.since)}</small>${pill(x.status === 'cliente' ? 'pagada' : 'espera', x.status === 'cliente' ? 'Ya es cliente' : 'Probando Cronos')}</li>`).join('')}
          ${s.leads.map(x => `<li><b>${esc(x.agency)}</b><small>${fdate(x.date)}</small>${pill(x.status === 'descartado' ? 'espera' : 'falta_tasa', x.status_label)}</li>`).join('')}
          ${queued.map(x => `<li><b>${esc(x.agency)}</b><small>recién enviada</small>${pill('espera', 'Enviando…')}</li>`).join('')}</ul>` : '<p class="rc-m">Todavía no recomendaste ninguna. Usa tu link o el formulario.</p>'}</section>
        ${r.can.edit ? leadForm() : ''}
      </div>
      <section class="rc-card"><h2>Lo que ganaste</h2>${s.commissions.length ? `<div class="rc-scroll"><table class="rc-tbl"><thead><tr><th>Fecha</th><th>Agencia</th><th class="r">Monto</th><th>Estado</th></tr></thead><tbody>
        ${s.commissions.map(c => `<tr><td>${fdate(c.date)}</td><td>${esc(c.agency)}${c.kind === 'ajuste' ? '<div class="rc-m">Ajuste por un reembolso</div>' : ''}</td><td class="r">${c.amount === null ? '—' : money(c.amount, c.currency)}</td>
          <td>${pill(c.status, c.status_label)}${c.status === 'espera' ? `<div class="rc-m">Disponible el ${fdate(c.available)}</div>` : ''}</td></tr>`).join('')}</tbody></table></div>` : '<p class="rc-m">Cuando una agencia que recomendaste pague su plan, lo vas a ver acá.</p>'}</section>
      ${prefsCard(s, r.can.config)}
      ${s.terms ? `<section class="rc-card"><h2>Condiciones</h2><p class="rc-terms">${esc(s.terms)}</p></section>` : ''}
      <p class="rc-m rc-foot"><i class="fa-solid fa-rotate"></i> Los números se actualizan cada pocos minutos${s.updated_at ? ' · último dato: ' + esc(s.updated_at.slice(0, 16).replace(' ', ' a las ')) : ''}.</p>`;
    $('#rc-copy').onclick = async () => { try { await navigator.clipboard.writeText(s.link); toast('Link copiado'); } catch (e) { prompt('Copia tu link:', s.link); } };
    bindLead(); bindPrefs(s);
  }

  const leadForm = () => `<section class="rc-card"><h2>Recomendar una agencia</h2><p class="rc-m">Le escribimos nosotros y queda a tu nombre.</p>
    <form id="rc-lead" class="rc-form" novalidate>
      <label>Agencia o empresa<input name="agency" maxlength="160" required></label>
      <label>Persona de contacto<input name="contact" maxlength="160"></label>
      <div class="rc-two"><label>Correo<input name="email" type="email" maxlength="190"></label><label>Teléfono<input name="phone" maxlength="40"></label></div>
      <label>Algo que nos sirva saber <small>(opcional)</small><textarea name="note" maxlength="1000" rows="3"></textarea></label>
      <button class="rc-btn primary" type="submit"><i class="fa-solid fa-paper-plane"></i> Recomendar</button></form></section>`;
  function bindLead() {
    const f = $('#rc-lead'); if (!f) return;
    f.onsubmit = async e => {
      e.preventDefault(); const b = $('button', f); b.disabled = true;
      try { await api('lead', Object.fromEntries(new FormData(f))); f.reset(); toast('¡Gracias! La recomendación ya está en camino.'); load(); } catch (err) {} finally { b.disabled = false; }
    };
  }
  const prefsCard = (s, can) => `<section class="rc-card"><h2>¿Qué hacemos con lo que ganas?</h2>
    ${can ? `<form id="rc-prefs" class="rc-form">
      <label class="rc-opt"><input type="radio" name="mode" value="descuento"${s.mode === 'descuento' ? ' checked' : ''}><span><b>Descontarlo de mi plan</b><small>Se resta de tu próximo pago de Cronos.</small></span></label>
      <label class="rc-opt"><input type="radio" name="mode" value="cobro"${s.mode === 'cobro' ? ' checked' : ''}><span><b>Cobrarlo</b><small>Te lo pagamos cuando juntes el mínimo. ${s.payout_hint ? 'Datos actuales: ' + esc(s.payout_hint) + '.' : ''}</small></span></label>
      <fieldset id="rc-pay" class="rc-pay" hidden><legend>Dónde cobras <small>(viajan protegidos: solo el equipo de pagos de Cronos los puede ver)</small></legend>
        <div class="rc-two"><label>Medio<select name="method"><option value="transferencia">Transferencia bancaria</option><option value="paypal">PayPal</option><option value="usdt">USDT</option><option value="zelle">Zelle</option><option value="pagomovil">Pago Móvil</option><option value="otro">Otro</option></select></label><label>Titular<input name="holder" maxlength="120"></label></div>
        <div class="rc-two"><label>Documento o CUIT <small>(opcional)</small><input name="tax_id" maxlength="40"></label><label>Banco <small>(si aplica)</small><input name="bank" maxlength="80"></label></div>
        <div class="rc-two"><label>Cuenta, CBU o IBAN<input name="account" maxlength="120"></label><label>Alias<input name="alias" maxlength="120"></label></div>
        <div class="rc-two"><label>Correo (PayPal, Zelle)<input name="email" type="email" maxlength="190"></label><label>Billetera USDT o teléfono<input name="wallet" maxlength="120"></label></div>
        <p class="rc-m">${s.payout_hint ? 'Deja todo vacío para conservar los datos que ya cargaste.' : ''}</p></fieldset>
      <button class="rc-btn primary" type="submit">Guardar</button></form>` : `<p>${s.mode === 'descuento' ? 'Se descuenta de tu plan.' : 'Se cobra.'} Solo quien administra la cuenta lo puede cambiar.</p>`}</section>`;
  function bindPrefs(s) {
    const f = $('#rc-prefs'); if (!f) return;
    const sync = () => $('#rc-pay').hidden = f.mode.value !== 'cobro'; $$('input[name=mode]', f).forEach(x => x.onchange = sync); sync();
    f.onsubmit = async e => {
      e.preventDefault(); const b = $('button[type=submit]', f); b.disabled = true;
      const fd = Object.fromEntries(new FormData(f)), mode = fd.mode; delete fd.mode;
      if (fd.wallet && fd.method !== 'usdt' && !fd.phone) { fd.phone = fd.wallet; }
      try { await api('prefs', { mode, payout: mode === 'cobro' ? fd : null }); toast('Guardado. Lo tomamos en unos minutos.'); load(); } catch (err) {} finally { b.disabled = false; }
    };
  }
  load();
})();
