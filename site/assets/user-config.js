/* assets/user-config.js — antes era un <script> en línea de user_config.php (CSP estricta, SEC-24 fase 2). */
const CFG_URL = '/toolz/user_config.php';
function $(id){ return document.getElementById(id); }

// ── NAV ───────────────────────────────────────────────────
function cfgShow(sec, btn){
  document.querySelectorAll('.cfg-section').forEach(s=>s.classList.remove('active'));
  document.querySelectorAll('.cfg-nav-item').forEach(b=>b.classList.remove('active'));
  $('sec-'+sec).classList.add('active');
  btn.classList.add('active');
  // Update URL hash
  location.hash = sec;
}
// Restore from hash
window.addEventListener('load', ()=>{
  const hash = location.hash.slice(1);
  if(hash){
    const btn = document.querySelector(`.cfg-nav-item[data-click-1="s:${hash}"]`);   // los botones ya no tienen onclick (CSP)
    if(btn) cfgShow(hash, btn);
  }
  cfgPreviewAccent($('cfg-accent-color')?.value || '#ff6600');
  cfgCheckNotifPerm();
});

// ── SAVE PREF ─────────────────────────────────────────────
async function cfgSave(key, value){
  try{
    const r = await fetch(CFG_URL, {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({key, value})
    });
    const d = await r.json();
    if(d.ok) cfgToast('Guardado ✓');
    else cfgToast('Error: '+(d.error||'?'), true);
  } catch(e){ cfgToast('Error de conexión', true); }
}

// ── THEME ─────────────────────────────────────────────────
async function cfgSetTheme(theme, accent){
  document.querySelectorAll('.cfg-theme').forEach(btn => btn.classList.toggle('active', btn.id === 'theme-btn-'+theme));
  // Se aplica al instante en esta página; las demás lo toman al abrirse
  const h = document.documentElement;
  h.className = h.className.replace(/theme-[a-z-]+/g,'').trim() + ' theme-'+theme;
  await cfgSave('theme', theme);
  // Cada tema trae su color de acento (después se puede cambiar abajo)
  if (accent) cfgSetAccent(accent);
}

// ── ACCENT COLOR ──────────────────────────────────────────
function cfgPreviewAccent(color){
  $('cfg-accent-preview').style.background = color;
  if (!/^#[0-9a-fA-F]{6}$/.test(color)) return;
  // Vista previa en vivo: el acento y su versión RGB (la usan las transparencias)
  const h = document.documentElement.style;
  h.setProperty('--g7-accent', color);
  h.setProperty('--g7-accent-rgb', [1,3,5].map(i => parseInt(color.substr(i,2),16)).join(','));
}
function cfgSetAccent(color){
  $('cfg-accent-color').value = color;
  $('cfg-accent-hex').value = color;
  cfgPreviewAccent(color);
  cfgSave('accent_color', color);
  document.querySelectorAll('.cfg-swatch').forEach(s=>{
    s.classList.toggle('active', s.style.background === color || s.title === color);
  });
}

// ── AVATAR ────────────────────────────────────────────────
async function cfgUploadAvatar(input){
  const file = input.files[0]; if(!file) return;
  try{
    const fd = new FormData(); fd.append('avatar_file', await g7PrepImage(file, {max:1200})); fd.append('action','update_avatar');
    const r = await fetch('/toolz/user_config.php', {method:'POST', body:fd});
    const d = await r.json().catch(()=>null) || {};
    if(d.status==='success'||d.url){
      const el = $('cfg-avatar-img');
      if(el.tagName==='IMG') el.src = d.url||d.avatar_url;
      else { const img=document.createElement('img'); img.src=d.url||d.avatar_url; img.className='cfg-avatar'; img.id='cfg-avatar-img'; el.replaceWith(img); }
      cfgToast('Avatar actualizado ✓');
    } else { cfgToast(g7UploadError(d), true); }
  } catch(e){ cfgToast('Error: '+e.message, true); }
  input.value='';
}

// ── DISPLAY NAME ──────────────────────────────────────────
async function cfgSaveName(){
  const name = $('cfg-display-name').value.trim();
  if(!name) return;
  try{
    const r = await fetch('/toolz/users.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'update_name', display_name:name})
    });
    const d = await r.json();
    cfgToast(d.status==='success'?'Nombre actualizado ✓':'Error al guardar', d.status!=='success');
  } catch(e){ cfgToast('Error: '+e.message, true); }
}

// ── PASSWORD ──────────────────────────────────────────────
function cfgShowPasswordModal(){ $('cfg-pw-modal').style.display='flex'; }
async function cfgChangePassword(){
  const cur     = $('cfg-pw-cur').value;
  const newpw   = $('cfg-pw-new').value;
  const confirm = $('cfg-pw-confirm').value;
  const err     = $('cfg-pw-error');
  err.style.display='none';
  if(!cur||!newpw){ err.textContent='Completa todos los campos'; err.style.display='block'; return; }
  if(newpw !== confirm){ err.textContent='Las contraseñas no coinciden'; err.style.display='block'; return; }
  try{
    const r = await fetch('/toolz/password_api.php', {   // largo mínimo, distinta de la actual y fecha de cambio (rotación): lo controla el servidor
      method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json', 'X-PW':'1'},
      body: JSON.stringify({action:'change', current:cur, new:newpw, confirm})
    });
    const d = await r.json();
    if(d.ok){
      $('cfg-pw-modal').style.display='none';
      $('cfg-pw-cur').value=''; $('cfg-pw-new').value=''; $('cfg-pw-confirm').value='';
      cfgToast('Contraseña actualizada ✓');
    } else { err.textContent=d.error||'Contraseña actual incorrecta'; err.style.display='block'; }
  } catch(e){ err.textContent='Error: '+e.message; err.style.display='block'; }
}

// ── DESKTOP NOTIFICATIONS ─────────────────────────────────
function cfgCheckNotifPerm(){
  const el = $('cfg-notif-perm');
  if(!('Notification' in window)){ if(el) el.textContent='No soportado'; return; }
  if(el) el.textContent = Notification.permission === 'granted' ? '✓ Permitido' : Notification.permission === 'denied' ? '✗ Bloqueado' : 'No configurado';
}
async function cfgToggleDesktopNotif(checked){
  if(checked && Notification.permission !== 'granted'){
    const perm = await Notification.requestPermission();
    if(perm !== 'granted'){
      $('cfg-notif-desktop').checked = false;
      cfgToast('Permiso de notificaciones denegado', true);
      cfgCheckNotifPerm(); return;
    }
  }
  cfgSave('notif_desktop', checked);
  cfgCheckNotifPerm();
}

// ── TOAST ─────────────────────────────────────────────────
function cfgToast(msg, isError=false){
  const t = $('cfg-toast');
  t.textContent = msg;
  t.style.background = isError ? '#ef4444' : '#22c55e';
  t.style.color = '#000';
  t.style.transform = 'translateX(-50%) translateY(0)';
  setTimeout(()=>t.style.transform='translateX(-50%) translateY(60px)', 2000);
}

// Funciones para los eventos que antes eran varias instrucciones en línea
function cfgAccentFromColor(el) { document.getElementById('cfg-accent-hex').value = el.value; cfgPreviewAccent(el.value); }
function cfgAccentFromHex(el) { document.getElementById('cfg-accent-color').value = el.value; cfgPreviewAccent(el.value); }
function cfgSaveAccent() { cfgSave('accent_color', document.getElementById('cfg-accent-color').value); }
function cfgClosePw() { document.getElementById('cfg-pw-modal').style.display = 'none'; }
