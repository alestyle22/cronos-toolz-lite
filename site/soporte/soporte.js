/* soporte/soporte.js — Pantalla de Soporte (soporte/index.php): tipo de reporte, capturas, envío y "Mis reportes".
   Antes era un <script> en línea (CSP estricta, SEC-24). Habla con api.php. */
(() => {
  const $ = id => document.getElementById(id), esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  let type = 'problema', shots = [], open = null, list = [];
  async function api(action, body = {}) {
    const r = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, ...body }) });
    const d = await r.json().catch(() => ({ error: 'Respuesta inválida.' }));
    if (!r.ok || d.error) throw new Error(d.error || 'No se pudo completar.'); return d;
  }
  // Tipo de reporte
  const HINT = { problema: ['¿Qué pasó? Cuéntanos los pasos', '1. Entré a… 2. Toqué… 3. Apareció…'], mejora: ['¿Qué te gustaría que haga Cronos?', 'Cuéntanos la idea y para qué te serviría.'], plan: ['¿Qué necesitas?', 'Ej: sumar 5 usuarios más, pasar al plan Pro, más espacio…'] };
  document.querySelectorAll('.sp-type').forEach(b => b.onclick = () => {
    type = b.dataset.type; document.querySelectorAll('.sp-type').forEach(x => { x.classList.toggle('on', x === b); x.setAttribute('aria-checked', x === b); });
    $('sp-body-l').textContent = HINT[type][0]; $('sp-body').placeholder = HINT[type][1];
    $('sp-url-w').style.display = $('sp-block-w').style.display = type === 'problema' ? '' : 'none';
  });
  // Capturas: elegir, arrastrar o pegar
  async function addFiles(files) {
    for (const f of [...files].filter(f => f.type.startsWith('image/'))) {
      if (shots.length >= 3) break;
      let file = f; try { if (window.g7PrepImage) file = await g7PrepImage(f, { max: 1920, maxBytes: 1.5 * 1048576 }) || f; } catch (e) {}
      if (file.size > 4 * 1048576) { $('sp-err').textContent = 'Una imagen pesa demasiado (más de 4 MB).'; continue; }
      shots.push(await new Promise(r => { const fr = new FileReader(); fr.onload = () => r(fr.result); fr.readAsDataURL(file); }));
    }
    drawShots();
  }
  function drawShots() { $('sp-shots').innerHTML = shots.map((s, i) => `<div class="sp-shot"><img src="${s}" alt="Captura ${i + 1}"><button type="button" data-i="${i}" aria-label="Quitar">✕</button></div>`).join(''); }
  $('sp-shots').onclick = e => { const b = e.target.closest('button[data-i]'); if (b) { shots.splice(+b.dataset.i, 1); drawShots(); } };
  $('sp-drop').onclick = () => $('sp-file').click(); $('sp-drop').onkeydown = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $('sp-file').click(); } };
  $('sp-file').onchange = e => { addFiles(e.target.files); e.target.value = ''; };
  $('sp-drop').ondragover = e => { e.preventDefault(); $('sp-drop').classList.add('over'); }; $('sp-drop').ondragleave = () => $('sp-drop').classList.remove('over');
  $('sp-drop').ondrop = e => { e.preventDefault(); $('sp-drop').classList.remove('over'); addFiles(e.dataTransfer.files); };
  document.addEventListener('paste', e => { const f = [...(e.clipboardData?.files || [])]; if (f.length && document.activeElement?.tagName !== 'INPUT') addFiles(f); });
  // Enviar
  $('sp-form').onsubmit = async e => {
    e.preventDefault(); $('sp-err').textContent = '';
    const title = $('sp-title').value.trim(), body = $('sp-body').value.trim();
    if (!title) { $('sp-err').textContent = 'Pon un resumen corto.'; $('sp-title').focus(); return; }
    const b = $('sp-send'); b.disabled = true; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando…';
    try {
      const r = await api('new', { type, title, body, url: type === 'problema' ? $('sp-url').value.trim() : '', blocking: type === 'problema' && $('sp-block').checked, images: shots });
      $('sp-form-card').innerHTML = `<div class="sp-ok"><i class="fa-solid fa-circle-check"></i><b>¡Gracias! Lo recibimos (#${r.ticket.id})</b><p>La respuesta va a aparecer en “Mis reportes”, en esta misma página.</p><button class="sp-btn" type="button" id="sp-again">Mandar otro</button></div>`;
      $('sp-again').onclick = () => location.reload();
      load();
    } catch (er) { $('sp-err').textContent = er.message; b.disabled = false; b.innerHTML = '<i class="fa-regular fa-paper-plane"></i> Enviar'; }
  };
  // Mis reportes
  const fdate = s => new Date(String(s).replace(' ', 'T')).toLocaleString('es', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  function draw() {
    if (!list.length) { $('sp-list').innerHTML = '<div class="sp-empty">Todavía no mandaste ningún reporte.</div>'; return; }
    $('sp-list').innerHTML = list.map(t => `<div class="sp-t" data-id="${t.id}"><div class="sp-t-h"><div><b>#${t.id} · ${esc(t.title)}</b><small>${esc(t.type_label)} · ${esc(fdate(t.created_at))}${t.replies.length ? ' · ' + t.replies.length + (t.replies.length === 1 ? ' mensaje' : ' mensajes') : ''}</small></div><span class="sp-st ${esc(t.status)}">${esc(t.status_label)}</span></div>
      ${open === t.id ? `<div class="sp-conv"><div class="sp-msg"><small>Tú · ${esc(fdate(t.created_at))}</small>${esc(t.body || t.title)}</div>${t.replies.map(m => `<div class="sp-msg ${esc(m.by)}"><small>${m.by === 'cronos' ? 'Equipo de Cronos' : esc(m.name || 'Tú')} · ${esc(fdate(m.at))}</small>${esc(m.text)}</div>`).join('')}
        ${t.status !== 'cerrado' ? `<div class="sp-f" style="margin-top:10px"><textarea class="sp-in" id="sp-rep" style="min-height:70px" placeholder="Agrega un comentario o responde…"></textarea></div><button class="sp-btn" type="button" id="sp-rep-b" style="width:auto">Enviar comentario</button>` : ''}</div>` : ''}</div>`).join('');
  }
  $('sp-list').onclick = async e => {
    if (e.target.closest('.sp-conv')) { if (e.target.id === 'sp-rep-b') { const t = $('sp-rep').value.trim(); if (!t) return; e.target.disabled = true; try { const r = await api('reply', { id: open, text: t }); list = list.map(x => x.id === r.ticket.id ? r.ticket : x); draw(); } catch (er) { alert(er.message); e.target.disabled = false; } } return; }
    const row = e.target.closest('.sp-t'); if (!row) return; open = open === +row.dataset.id ? null : +row.dataset.id; draw();
  };
  async function load() { try { list = (await api('mine')).tickets; draw(); } catch (e) { $('sp-list').innerHTML = `<div class="sp-empty">${esc(e.message)}</div>`; } }
  load();
})();
