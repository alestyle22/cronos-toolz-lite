/* integraciones/perfil-google.js — antes era un <script> en línea de integraciones/perfil_google.php (CSP estricta, SEC-24 fase 2). */
(() => {
  const box = document.getElementById('gc-box');
  const e = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const dt = s => s ? s.slice(8, 10) + '/' + s.slice(5, 7) + ' ' + s.slice(11, 16) : '';
  async function api(action) {
    const r = await fetch('/toolz/integraciones/user_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-INT': '1' }, credentials: 'same-origin', body: JSON.stringify({ action }) });
    const j = await r.json().catch(() => ({ error: 'El servidor no respondió bien.' })); if (j.error) throw new Error(j.error); return j;
  }
  function render(s) {
    if (!s.connected) { box.innerHTML = `<p style="margin:0 0 12px;color:var(--g7-text-3)">Conecta tu cuenta y Toolz va a crear, mover y borrar en tu calendario principal lo mismo que pasa en tu calendario de acá. Es en una sola dirección: lo que cambies en Google no vuelve a Toolz.</p>
      <a class="btn btn-primary" href="/toolz/integraciones/google_oauth.php?start=1"><i class="fa-brands fa-google"></i> Conectar Google Calendar</a>`; return; }
    box.innerHTML = `<p style="margin:0 0 6px"><i class="fa-solid fa-circle-check" style="color:#22c55e"></i> Conectado${s.email ? ' como <b>' + e(s.email) + '</b>' : ''} desde ${e(dt(s.connected_at))}.</p>
      <p style="margin:0 0 12px;color:var(--g7-text-3)">${s.last_sync ? 'Última copia: ' + e(dt(s.last_sync)) + ' · ' + s.synced + ' eventos en Google.' : 'Todavía no se copió nada.'}${s.last_error ? '<br><span style="color:#f87171">Último error: ' + e(s.last_error) + '</span>' : ''}</p>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-ghost" id="gc-sync"><i class="fa-solid fa-rotate"></i> Sincronizar ahora</button><button class="btn btn-ghost" id="gc-off"><i class="fa-solid fa-link-slash"></i> Desconectar</button></div>`;
    document.getElementById('gc-sync').onclick = async ev => { ev.target.disabled = true; try { const r = await api('gcal_sync'); render(r); cfgToast(`Listo: ${r.result.created} nuevos, ${r.result.updated} actualizados, ${r.result.deleted} borrados.`); } catch (x) { cfgToast(x.message, true); ev.target.disabled = false; } };
    document.getElementById('gc-off').onclick = async () => { if (!confirm('¿Desconectar tu Google Calendar? Lo que ya se copió queda en Google.')) return; try { render(await api('gcal_disconnect')); cfgToast('Desconectado.'); } catch (x) { cfgToast(x.message, true); } };
  }
  api('gcal').then(render).catch(x => box.textContent = x.message);
})();
