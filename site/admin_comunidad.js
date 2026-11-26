/* admin_comunidad.js — Admin › Comunidad (admin_comunidad.php): un interruptor por parte de la Comunidad. Se guarda al tocarlo.
   Usa accApi(), esc() y admToast() del panel. Acciones cm_parts_get / cm_parts_save (admin_api.php). Doc: toolz/COMUNIDAD_PARTES.md */
'use strict';
const CMP = { defs: null, on: null };
async function cmpLoad() {
  const box = document.getElementById('cmp-body'); if (!box) return;
  try { const r = await accApi('cm_parts_get'); CMP.defs = r.defs; CMP.on = r.on; } catch (e) { box.textContent = 'No se pudo cargar.'; return; }
  cmpRender();
}
function cmpRender() {
  const box = document.getElementById('cmp-body'), groups = {};
  CMP.defs.forEach(d => (groups[d.group] = groups[d.group] || []).push(d));
  box.innerHTML = Object.entries(groups).map(([g, list]) => `<div class="cmp-grp"><h3>${esc(g)}</h3><div class="cmp-list">${list.map(d => {
    const on = CMP.on[d.key] !== false;
    return `<label class="cmp-it${on ? '' : ' off'}"><i class="fa-solid ${esc(d.icon)}" aria-hidden="true"></i><span class="cmp-t"><b>${esc(d.label)}</b><small>${esc(d.help)}</small></span>
      <span class="cmp-sw"><input type="checkbox" role="switch" data-cmp="${esc(d.key)}" ${on ? 'checked' : ''} aria-label="${esc(d.label)}"><span></span></span></label>`;
  }).join('')}</div></div>`).join('') + '<p class="cmp-note">Las novedades automáticas apagadas no se acumulan: al prenderlas de nuevo, arrancan desde ese momento.</p>';
}
document.addEventListener('change', async e => {
  const i = e.target.closest && e.target.closest('[data-cmp]'); if (!i) return;
  const k = i.dataset.cmp, on = i.checked, it = i.closest('.cmp-it');
  it.classList.toggle('off', !on); i.disabled = true;
  try {
    const r = await accApi('cm_parts_save', { parts: { [k]: on ? 1 : 0 } }); CMP.on = r.on;
    const d = CMP.defs.find(x => x.key === k); admToast(`${d ? d.label : 'Listo'}: ${on ? 'prendido' : 'apagado'}`);
  } catch (err) { i.checked = !on; it.classList.toggle('off', on); }
  i.disabled = false;
});
if (typeof SECTION_LOADERS !== 'undefined') SECTION_LOADERS.comunidad = () => { cmpLoad().catch(() => {}); };
