/* mailingmaker/comms.js — Generador de comunicaciones (antes en línea en comms.php; CSP estricta, SEC-24 fase 2). */
'use strict';

// ════════════════════════════════════════════════════════════
// CONFIG DE PLATAFORMAS
// Los datos de las marcas del sitio vienen del paquete de marcas (marcas_js.php → window.MM_PACK.comms, ver mm_pack.js);
// sin paquete se usa este ejemplo neutro. Campos: name, logoUrl (sin logo va el nombre), paymentUrl, bgHeader,
// accentColor, ctaColor, ctaTextColor, mainUrl, profileUrl, depositUrl, brandName.
// ════════════════════════════════════════════════════════════
const MM_EXAMPLE = {
  defaultPlat: 'ejemplo',
  cards: [ { id:'ejemplo', label:'Mi marca', tag:'Marca de ejemplo' } ],
  lgDomains: { ejemplo:'example.com' },
  platforms: {
    ejemplo: {
      name:'Mi marca',
      logoUrl:'',
      paymentUrl:'',
      bgHeader:'#000000',
      accentColor:'#ff4800',
      ctaColor:'#27cc2a',
      ctaTextColor:'#ffffff',
      mainUrl:'https://example.com/',
      profileUrl:'https://example.com/?profile=1',
      depositUrl:'https://example.com/?deposit=1',
      brandName:'Mi marca',
    },
  },
};
const MMP = mmPack('comms', MM_EXAMPLE);
const PC = MMP.platforms;
const SERVER_BASE = MMP.serverBase;   // URL pública de mailingmaker/ (imágenes subidas)
const MM_HOSTS = mmPackHosts(MMP, ['mainUrl', 'profileUrl', 'depositUrl']);   // un CTA con estos dominios todavía es "el de la plataforma"

let curPlat = MMP.defaultPlat;
let heroImg = { url:'', exp:'' };   // url=preview, exp=URL absoluta del servidor
let userRows = [
  {label:'ID',      value:'${PlayerId}'},
  {label:'Usuario', value:'${UserName}'},
];
let steps = [
  'Recibí el código en tu mail',
  'Verificá desde tu <a style="color:#ff4800;" href="' + (PC[curPlat].profileUrl || PC[curPlat].mainUrl || '#') + '">perfil</a>',
  'Confirmá y listo 🎉',
];

// ════════════════════════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════════════════════════
function eh(s){return (s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function xe(s){return (s||'').replace(/"/g,'&quot;');}
function G(id){return document.getElementById(id).value;}
function toAbsUrl(u){
  if(!u) return '';
  if(u.startsWith('http://')||u.startsWith('https://')||u.startsWith('data:')) return u;
  if(u.startsWith('./')) return SERVER_BASE + u.substring(2);
  if(u.startsWith('/'))  return SERVER_BASE + u.substring(1);
  return SERVER_BASE + u;
}
function buildExportImgUrl(srvUrl){
  // srvUrl es la URL que devuelve proxy.php (suele venir como ./imgs/xxx.jpg)
  if(!srvUrl) return '';
  if(srvUrl.startsWith('http')) return srvUrl;
  return toAbsUrl(srvUrl);
}

// ════════════════════════════════════════════════════════════
// PLATAFORMA
// ════════════════════════════════════════════════════════════
function selectPlat(el, plat){
  document.querySelectorAll('.plat-card').forEach(c => c.classList.remove('active'));
  el.classList.add('active');
  curPlat = plat;
  loadPlat(plat);
  update();
}
function loadPlat(plat){
  const c = PC[plat];
  document.getElementById('linkLogo').value    = c.mainUrl    || '';
  document.getElementById('linkProfile').value = c.profileUrl || '';
  // Si el CTA está en default, actualizarlo según plataforma
  const ctaInput = document.getElementById('inpCtaUrl');
  if(!ctaInput.value || MM_HOSTS.some(h => ctaInput.value.includes(h))){
    ctaInput.value = c.depositUrl;
  }
}

// ════════════════════════════════════════════════════════════
// USER ROWS
// ════════════════════════════════════════════════════════════
function addUserRow(){
  userRows.push({label:'Nueva', value:''});
  renderUserRows(); update();
}
function removeUserRow(i){
  userRows.splice(i,1);
  renderUserRows(); update();
}
function updateUserRow(i, field, val){
  if(!userRows[i]) return;
  userRows[i][field] = val;
  update();
}
function renderUserRows(){
  const el = document.getElementById('userRowsList');
  el.innerHTML = userRows.map((r,i) => `
    <div class="user-row">
      <input type="text" value="${eh(r.label)}" placeholder="Etiqueta" data-on-input="updateUserRow" data-input-1="j:${i}" data-input-2="s:label" data-input-3="value">
      <input type="text" value="${eh(r.value)}" placeholder="\${Valor}" data-on-input="updateUserRow" data-input-1="j:${i}" data-input-2="s:value" data-input-3="value">
      <button data-on-click="removeUserRow" data-click-1="j:${i}">✕</button>
    </div>`).join('');
}

// ════════════════════════════════════════════════════════════
// STEPS
// ════════════════════════════════════════════════════════════
function addStep(){
  steps.push('');
  renderSteps(); update();
}
function removeStep(i){
  steps.splice(i,1);
  renderSteps(); update();
}
function updateStep(i, val){
  steps[i] = val;
  update();
}
function renderSteps(){
  const el = document.getElementById('stepsList');
  const emojis = ['1️⃣','2️⃣','3️⃣','4️⃣','5️⃣','6️⃣','7️⃣','8️⃣','9️⃣'];
  el.innerHTML = steps.map((s,i) => `
    <div class="step-row">
      <div class="step-num">${emojis[i]||(i+1)+'.'}</div>
      <input type="text" value="${eh(s)}" placeholder="Texto del paso (acepta HTML)" data-on-input="updateStep" data-input-1="j:${i}" data-input-2="value">
      <button data-on-click="removeStep" data-click-1="j:${i}">✕</button>
    </div>`).join('');
}

// ════════════════════════════════════════════════════════════
// MODAL IMAGEN
// ════════════════════════════════════════════════════════════
let galSelected = null;

function openImgModal(){
  document.getElementById('imgModal').classList.add('open');
  switchImgTab('upload');
  galSelected = null;
}
function closeImgModal(){
  document.getElementById('imgModal').classList.remove('open');
  galSelected = null;
}
function switchImgTab(t){
  ['upload','gallery'].forEach(x => {
    document.getElementById('itab-'+x).classList.toggle('active', x===t);
    document.getElementById('ipanel-'+x).classList.toggle('active', x===t);
  });
  if(t==='gallery') loadGallery();
}

async function doUpload(input){
  const file = input.files[0];
  if(!file) return;
  const st = document.getElementById('upStatus');
  st.textContent = 'Subiendo...';

  // Preview local
  const reader = new FileReader();
  reader.onload = e => {
    document.getElementById('upPreview').src = e.target.result;
    document.getElementById('upPreview').style.display = 'block';
  };
  reader.readAsDataURL(file);

  // Upload al servidor via proxy del mailingmaker
  const fd = new FormData();
  fd.append('action', 'upload_image');
  fd.append('type', 'mailing');
  fd.append('file', file);
  try{
    const r = await fetch('proxy.php', {method:'POST', body:fd});
    const d = await r.json();
    if(d.error){ st.textContent = '❌ '+d.error; return; }
    st.textContent = '✓ Subido al servidor';
    setHeroImg(reader.result || toAbsUrl(d.url), d.url);
    setTimeout(closeImgModal, 600);
  }catch(e){ st.textContent = '❌ Error: '+e.message; }
}

async function loadGallery(){
  const grid = document.getElementById('galGrid');
  const empty = document.getElementById('galEmpty');
  grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:var(--text3);font-size:11px;padding:20px;">Cargando...</div>';
  try{
    const fd = new FormData();
    fd.append('action','list_imgs');
    fd.append('type','mailing');
    const r = await fetch('proxy.php', {method:'POST', body:fd});
    const d = await r.json();
    if(d.error || !d.imgs || !d.imgs.length){
      grid.innerHTML = '';
      empty.style.display = 'block';
      return;
    }
    empty.style.display = 'none';
    grid.innerHTML = d.imgs.map(img => `
      <div class="gal-item" data-url="${xe(img.url)}" data-on-click="selectGalItem" data-click-1="this">
        <img src="${xe(img.absUrl||toAbsUrl(img.url))}" loading="lazy">
        <button class="gal-del" data-on-click="deleteGalImg" data-click-1="s:${xe(img.url)}">✕</button>
      </div>`).join('');
  }catch(e){
    grid.innerHTML = '<div style="grid-column:1/-1;color:var(--red);font-size:11px;padding:20px;text-align:center;">Error: '+e.message+'</div>';
  }
}
function selectGalItem(el){
  document.querySelectorAll('.gal-item').forEach(x => x.classList.remove('selected'));
  el.classList.add('selected');
  galSelected = {
    url: el.dataset.url,
    absUrl: el.querySelector('img').src,
  };
  document.getElementById('applyGalBtn').style.display = '';
}
function applyGal(){
  if(!galSelected) return;
  setHeroImg(galSelected.absUrl, galSelected.url);
  closeImgModal();
}
async function deleteGalImg(url){
  if(!confirm('¿Eliminar esta imagen del servidor?')) return;
  const fd = new FormData();
  fd.append('action','delete_img');
  fd.append('type','mailing');
  fd.append('url', url);
  await fetch('proxy.php', {method:'POST', body:fd});
  loadGallery();
}

function setHeroImg(previewUrl, serverUrl){
  heroImg.url = previewUrl;
  heroImg.exp = buildExportImgUrl(serverUrl);
  const pv = document.getElementById('heroImgPreview');
  pv.innerHTML = `<img src="${xe(previewUrl)}" style="width:100%;border-radius:6px;border:1px solid rgba(255,200,80,0.15);">
    <div style="font-size:9px;color:var(--text3);font-family:monospace;margin-top:3px;word-break:break-all;">${xe(heroImg.exp)}</div>`;
  update();
}

// ════════════════════════════════════════════════════════════
// GENERADOR DE LINKS
// ════════════════════════════════════════════════════════════
let lgTargetField = null;
function openLinkGen(fieldId){
  lgTargetField = fieldId;
  // Pre-seleccionar proyecto según plataforma
  document.getElementById('lgProject').value = MMP.lgDomains[curPlat] || (MMP.lgProjects[0] ? MMP.lgProjects[0].domain : '');
  lgBuild();
  document.getElementById('linkModal').classList.add('open');
}
function closeLinkGen(){
  document.getElementById('linkModal').classList.remove('open');
}
function lgBuild(){
  const proj = G('lgProject');
  const act  = G('lgAction');
  document.getElementById('lgResult').value = 'https://' + proj + act;
}
function lgApply(){
  if(!lgTargetField) return;
  document.getElementById(lgTargetField).value = G('lgResult');
  closeLinkGen();
  update();
}

// ════════════════════════════════════════════════════════════
// RENDERIZADO
// ════════════════════════════════════════════════════════════
function V(){
  return {
    c: PC[curPlat],
    hello:        G('inpHello'),
    intro:        G('inpIntro'),
    userBoxTitle: G('inpUserBoxTitle'),
    showUserBox:  document.getElementById('chkUserBox').checked,
    hlTitle:      G('inpHlTitle'),
    hlText:       G('inpHlText'),
    showHl:       document.getElementById('chkHighlight').checked,
    showSteps:    document.getElementById('chkSteps').checked,
    showPagos:    document.getElementById('chkPagos').checked,
    ctaText:      G('inpCtaText'),
    ctaUrl:       G('inpCtaUrl'),
    linkLogo:     G('linkLogo'),
    linkProfile:  G('linkProfile'),
  };
}

function update(){
  const v = V();
  document.getElementById('previewMail').innerHTML = genHTML(v, true);
}

function genHTML(v, isPreview){
  const c = v.c;
  const imgSrc = isPreview ? (heroImg.url || '') : (heroImg.exp || '');
  const userRowsHtml = userRows.map(r =>
    `<tr><td style="color:#666;">${eh(r.label)}</td><td><strong>${eh(r.value)}</strong></td></tr>`
  ).join('');
  const emojis = ['1️⃣','2️⃣','3️⃣','4️⃣','5️⃣','6️⃣','7️⃣','8️⃣','9️⃣'];
  const stepsHtml = steps.map((s,i) =>
    `<p style="margin: 0 0 4px;">${emojis[i]||(i+1)+'.'} ${s}</p>`
  ).join('');

  return `<table width="100%" cellspacing="0" cellpadding="0" bgcolor="#f7f8f9">
<tbody>
<tr>
<td align="center">
<table style="max-width: 520px;" width="100%" cellspacing="0" cellpadding="0" bgcolor="${c.bgHeader}">
<tbody>
<tr>
<td style="padding: 20px 0;" align="center"><a href="${xe(v.linkLogo||c.mainUrl)}"> ${c.logoUrl ? `<img style="display: block; border: 0;" src="${xe(c.logoUrl)}" width="146">` : `<span style="font-family: Arial, sans-serif; font-size: 22px; font-weight: bold; text-decoration: none; color: ${/^#?f{3,6}$/i.test(c.bgHeader||'') ? '#111111' : '#ffffff'};">${eh(c.name)}</span>`} </a></td>
</tr>
</tbody>
</table>
<table style="max-width: 480px; background: #fff;" width="100%" cellspacing="0" cellpadding="0">
<tbody>
${imgSrc ? `<tr>
<td style="padding: 16px;"><img style="border-radius: 12px;" src="${xe(imgSrc)}" width="100%"></td>
</tr>` : ''}
<tr>
<td style="padding: 0 16px;" align="center">
<h2 style="margin: 0 0 8px; font-size: 20px; color: #111;">${v.hello}</h2>
<p style="margin: 0 0 12px; font-size: 14px; color: #111;">${v.intro}</p>
</td>
</tr>
${v.showUserBox ? `<tr>
<td style="padding: 0 16px 16px;">
<table style="background: #f2f2f2; border-radius: 8px; font-size: 13px;" width="100%" cellspacing="0" cellpadding="6">
<tbody>
<tr>
<td colspan="2"><strong>${eh(v.userBoxTitle)}</strong></td>
</tr>
${userRowsHtml}
</tbody>
</table>
</td>
</tr>` : ''}
${v.showHl ? `<tr>
<td style="padding: 0 16px 16px;">
<h3 style="margin: 0 0 6px; font-size: 16px; color: #111;">${v.hlTitle}</h3>
<p style="margin: 0; font-size: 14px; color: #111;">${v.hlText}</p>
</td>
</tr>` : ''}
${v.showSteps && steps.length ? `<tr>
<td style="padding: 0 16px 16px; font-size: 14px; color: #111;">
${stepsHtml}
</td>
</tr>` : ''}
<tr>
<td style="padding: 16px;" align="center"><a style="background: ${c.ctaColor}; color: ${c.ctaTextColor}; text-decoration: none; font-size: 16px; font-weight: bold; padding: 12px 24px; border-radius: 8px; display: inline-block;" href="${xe(v.ctaUrl)}"> ${eh(v.ctaText)} </a></td>
</tr>
${v.showPagos && c.paymentUrl ? `<tr>
<td style="padding: 0 16px 16px;" align="center"><img style="border: 0;" src="${xe(c.paymentUrl)}" width="85%"></td>
</tr>` : ''}
</tbody>
</table>
</td>
</tr>
</tbody>
</table>`;
}

function genFullHTML(){
  const v = V();
  const body = genHTML(v, false);
  return `<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>${eh(v.hello)}</title>
<!--[if mso]>
<style>body,table,td{font-family:Arial,Helvetica,sans-serif!important}</style>
<![endif]-->
</head>
<body style="margin:0;padding:0;background:#f7f8f9;">
${body}
</body>
</html>`;
}

// ════════════════════════════════════════════════════════════
// EXPORT
// ════════════════════════════════════════════════════════════
function logExport(){
  const v = V();
  fetch('log_mailing.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ platform: 'comms_'+curPlat, h1: v.hello }),
  }).catch(()=>{});
}
function downloadHTML(){
  const html = genFullHTML();
  const blob = new Blob([html], {type:'text/html;charset=utf-8'});
  const url  = URL.createObjectURL(blob);
  const a = document.createElement('a');
  const slug = (V().hello||'comms').substring(0,28).replace(/[^a-zA-Z0-9áéíóúñ]/g,'_');
  a.href = url;
  a.download = `comms_${curPlat}_${slug}.html`;
  a.click();
  URL.revokeObjectURL(url);
  logExport();
}
async function copyHTML(){
  const el = document.getElementById('cpSt');
  try{
    await navigator.clipboard.writeText(genFullHTML());
    el.style.color = 'var(--green)';
    el.textContent = '✓ HTML copiado al portapapeles';
    logExport();
  }catch(e){
    el.style.color = 'var(--red)';
    el.textContent = 'No se pudo copiar — usá Descargar HTML';
  }
  setTimeout(()=> el.textContent = '', 3000);
}

// ════════════════════════════════════════════════════════════
// PLANTILLAS (localStorage)
// ════════════════════════════════════════════════════════════
const TPL_KEY = 'comms_tpls_v1';
function getTpls(){
  try{ return JSON.parse(localStorage.getItem(TPL_KEY)||'[]'); }
  catch(e){ return []; }
}
function setTpls(arr){
  localStorage.setItem(TPL_KEY, JSON.stringify(arr));
}
function saveTemplatePrompt(){
  const name = prompt('Nombre de la plantilla:');
  if(!name) return;
  const tpls = getTpls();
  if(tpls.length >= 20){ alert('Máximo 20 plantillas. Eliminá alguna primero.'); return; }
  const v = V();
  // Generar thumbnail (mini preview HTML inline en data URL — usamos canvas con foreignObject)
  const tpl = {
    name, plat: curPlat, ts: Date.now(),
    hero: heroImg.url, heroExp: heroImg.exp,
    hello: v.hello, intro: v.intro,
    userBoxTitle: v.userBoxTitle, showUserBox: v.showUserBox, userRows: [...userRows],
    hlTitle: v.hlTitle, hlText: v.hlText, showHl: v.showHl,
    steps: [...steps], showSteps: v.showSteps,
    ctaText: v.ctaText, ctaUrl: v.ctaUrl,
    showPagos: v.showPagos,
    linkLogo: v.linkLogo, linkProfile: v.linkProfile,
  };
  tpls.unshift(tpl);
  setTpls(tpls);
  alert('✓ Plantilla guardada.');
}
function openTplModal(){
  renderTpls();
  document.getElementById('tplModal').classList.add('open');
}
function closeTplModal(){
  document.getElementById('tplModal').classList.remove('open');
}
function renderTpls(){
  const tpls = getTpls();
  const grid = document.getElementById('tplGrid');
  const empty = document.getElementById('tplEmpty');
  if(!tpls.length){
    grid.innerHTML = '';
    empty.style.display = 'block';
    return;
  }
  empty.style.display = 'none';
  grid.innerHTML = tpls.map((t,i) => {
    const date = new Date(t.ts).toLocaleDateString('es-AR');
    return `<div class="tpl-card" data-on-click="applyTpl" data-click-1="j:${i}">
      <div class="tpl-thumb">${t.hero?`<img src="${xe(t.hero)}">`:'<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#888;font-size:11px;">Sin imagen</div>'}</div>
      <div class="tpl-name">${eh(t.name)}</div>
      <div class="tpl-meta">${date} · ${PC[t.plat]?PC[t.plat].name:t.plat}</div>
      <button class="tpl-del" data-on-click="deleteTpl" data-click-1="j:${i}">✕</button>
    </div>`;
  }).join('');
}
function applyTpl(i){
  const t = getTpls()[i];
  if(!t) return;
  curPlat = MMP.key(t.plat);   // plantilla de una plataforma que este sitio ya no tiene: queda la de por defecto
  document.querySelectorAll('.plat-card').forEach(c => c.classList.toggle('active', c.dataset.plat === curPlat));
  loadPlat(curPlat);
  document.getElementById('inpHello').value        = t.hello       || '';
  document.getElementById('inpIntro').value        = t.intro       || '';
  document.getElementById('inpUserBoxTitle').value = t.userBoxTitle|| 'Tus datos';
  document.getElementById('chkUserBox').checked    = !!t.showUserBox;
  document.getElementById('inpHlTitle').value      = t.hlTitle     || '';
  document.getElementById('inpHlText').value       = t.hlText      || '';
  document.getElementById('chkHighlight').checked  = !!t.showHl;
  document.getElementById('chkSteps').checked      = !!t.showSteps;
  document.getElementById('chkPagos').checked      = !!t.showPagos;
  document.getElementById('inpCtaText').value      = t.ctaText     || '';
  document.getElementById('inpCtaUrl').value       = t.ctaUrl      || '';
  document.getElementById('linkLogo').value        = t.linkLogo    || '';
  document.getElementById('linkProfile').value     = t.linkProfile || '';
  userRows = Array.isArray(t.userRows) ? [...t.userRows] : [];
  steps    = Array.isArray(t.steps) ? [...t.steps] : [];
  if(t.hero){ heroImg.url = t.hero; heroImg.exp = t.heroExp||''; setHeroImg(t.hero, t.heroExp); }
  else      { heroImg = {url:'',exp:''}; document.getElementById('heroImgPreview').innerHTML=''; }
  renderUserRows(); renderSteps();
  closeTplModal(); update();
}
function deleteTpl(i){
  if(!confirm('¿Eliminar esta plantilla?')) return;
  const arr = getTpls();
  arr.splice(i,1);
  setTpls(arr);
  renderTpls();
}

// ════════════════════════════════════════════════════════════
// INIT
// ════════════════════════════════════════════════════════════
function init(){
  // Tarjetas de plataforma, proyectos del generador de links y texto de ejemplo: desde los datos (paquete o ejemplo)
  mmRenderPlatCards(document.getElementById('platGrid'), MMP, curPlat);
  mmFillProjectSelect(document.getElementById('lgProject'), MMP);
  document.getElementById('inpIntro').value = 'Tu cuenta en <strong>' + (PC[curPlat].brandName || PC[curPlat].name) + '</strong> se creó con éxito ✅';
  // Plat default
  loadPlat(curPlat);
  renderUserRows();
  renderSteps();
  update();
}
init();
