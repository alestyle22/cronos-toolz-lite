/* assets/topbar.js — Barra superior (antes en línea en topbar.php). Sin código en línea para poder aplicar una CSP estricta (SEC-24, fase 2).
   Configuración: <script type="application/json" id="g7-topbar-cfg"> que imprime topbar.php. */
const G7TB = (() => { try { return JSON.parse(document.getElementById('g7-topbar-cfg').textContent); } catch (e) { return {}; } })();

// ── Aviso de arriba descartable: si ya se cerró esta versión, no se muestra ──
try { const an = document.getElementById('g7-announce'); if (an && localStorage.getItem('g7_announce_closed') === an.dataset.v) an.remove(); } catch (e) {}

function g7ToggleMobileNav(){var n=document.getElementById('g7-mobile-nav'),b=document.getElementById('g7-hamburger'),o=n.classList.toggle('open');b.innerHTML=o?'<i class="fa-solid fa-xmark"></i>':'<i class="fa-solid fa-bars"></i>';}
document.addEventListener('click',function(e){var n=document.getElementById('g7-mobile-nav'),b=document.getElementById('g7-hamburger');if(n&&n.classList.contains('open')&&!n.contains(e.target)&&!b.contains(e.target)){n.classList.remove('open');b.innerHTML='<i class="fa-solid fa-bars"></i>';}});

// ── NOTIFICATION SYSTEM ───────────────────────────────────
// System notification config (from admin)
const G7_NOTIF_SOUND_ENABLED = !!G7TB.notifSound;
const G7_NOTIF_SOUND_TYPE    = G7TB.notifType;
const G7_BROWSER_NOTIF = !!G7TB.browserNotif;
const G7_BEEPS_DEFAULT       = +G7TB.beeps || 3;

let notifOpen   = false;
let lastNotifId = 0;
let notifSound  = null;

try { notifSound = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJiVkHBZWWSHhIFxXVxnjYyKe2piboqJiH5oaHKKioiCbm94i4uLhHR4gouMjIh7foiMjIyKgIOLjY2NjIaIjI6Ojo2Kio2Ojo6OjYuLjY6Ojo6NjI2Ojo6OjY2NjY6Ojo6Ojo6Ojo+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4='); } catch(e) {}

function playNotifSound() {
  if (!G7_NOTIF_SOUND_ENABLED) return;
  const type = G7_NOTIF_SOUND_TYPE || 'beep';
  try {
    const ctx = new (window.AudioContext||window.webkitAudioContext)();
    if(type==='soft'){
      const o=ctx.createOscillator(),g=ctx.createGain();
      o.connect(g);g.connect(ctx.destination);
      o.frequency.setValueAtTime(523,ctx.currentTime);
      o.frequency.exponentialRampToValueAtTime(659,ctx.currentTime+0.1);
      o.type='sine';g.gain.setValueAtTime(0.2,ctx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.001,ctx.currentTime+0.5);
      o.start(ctx.currentTime);o.stop(ctx.currentTime+0.5);
      return;
    } else if(type==='pop'){
      const o=ctx.createOscillator(),g=ctx.createGain();
      o.connect(g);g.connect(ctx.destination);
      o.frequency.setValueAtTime(300,ctx.currentTime);
      o.frequency.exponentialRampToValueAtTime(100,ctx.currentTime+0.08);
      o.type='sine';g.gain.setValueAtTime(0.4,ctx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.001,ctx.currentTime+0.1);
      o.start(ctx.currentTime);o.stop(ctx.currentTime+0.1);
      return;
    }
  } catch(e) {}
  // Default beep (original code):
  try {
    const ctx = new (window.AudioContext||window.webkitAudioContext)();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.connect(gain); gain.connect(ctx.destination);
    osc.frequency.setValueAtTime(880, ctx.currentTime);
    osc.frequency.exponentialRampToValueAtTime(440, ctx.currentTime+0.1);
    gain.gain.setValueAtTime(0.1, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime+0.3);
    osc.start(ctx.currentTime); osc.stop(ctx.currentTime+0.3);
  } catch(e) {}
}

function notifIcon(type) {
  if (type === 'wk_req') return '<i class="fa-solid fa-inbox" style="color:#a78bfa"></i>';
  if (type === 'wk_tk') return '<i class="fa-solid fa-ticket" style="color:#60a5fa"></i>';
  if (type === 'pt_tk') return '<i class="fa-solid fa-inbox" style="color:#ff7a1a"></i>';
  if (type === 'login_new') return '<i class="fa-solid fa-user-shield" style="color:#f59e0b"></i>';
  if (type === 'login_fail') return '<i class="fa-solid fa-triangle-exclamation" style="color:#ef4444"></i>';
  if (type === 'wk_run') return '<i class="fa-solid fa-diagram-next" style="color:#f59e0b"></i>';
  if (type === 'task_auto') return '<i class="fa-solid fa-gears" style="color:#a78bfa"></i>';
  if (type === 'cal_remind') return '<i class="fa-regular fa-calendar" style="color:var(--g7-accent)"></i>';
  if (type === 'pay') return '<i class="fa-solid fa-wallet" style="color:#22c55e"></i>';
  if (type === 'aff' || type === 'aff_apply') return '<i class="fa-solid fa-handshake" style="color:#ff7a1a"></i>';   // Afiliados (afiliados/lib.php aff_notify_staff)
  if (type === 'resto') return '<i class="fa-solid fa-utensils" style="color:#f97316"></i>';
  if (type === 'social') return '<i class="fa-solid fa-share-nodes" style="color:#ec4899"></i>';
  if (type === 'social_inbox') return '<i class="fa-solid fa-inbox" style="color:#ec4899"></i>';
  if (type === 'social_report') return '<i class="fa-solid fa-file-lines" style="color:#ec4899"></i>';
  if (type === 'pass') return '<i class="fa-solid fa-key" style="color:#f59e0b"></i>';
  if (type === 'onboarding') return '<i class="fa-solid fa-user-check" style="color:#22c55e"></i>';
  // Bienestar (bienestar/lib.php bn_notify)
  if (type && type.startsWith('bn_')) return {bn_challenge:'<i class="fa-solid fa-person-running" style="color:#3b82f6"></i>', bn_survey:'<i class="fa-solid fa-square-poll-vertical" style="color:#a855f7"></i>', bn_kudos:'<i class="fa-solid fa-award" style="color:#ec4899"></i>', bn_habit:'<i class="fa-solid fa-seedling" style="color:#22c55e"></i>', bn_leave:'<i class="fa-solid fa-umbrella-beach" style="color:#06b6d4"></i>'}[type] || '<i class="fa-solid fa-spa" style="color:#22c55e"></i>';
  if (type && type.startsWith('task_')) return {task_assigned:'<i class="fa-solid fa-user-plus" style="color:var(--g7-accent)"></i>', task_mention:'<i class="fa-solid fa-at" style="color:var(--g7-accent)"></i>', task_comment:'<i class="fa-regular fa-comment" style="color:#60a5fa"></i>', task_status:'<i class="fa-solid fa-arrow-right-arrow-left" style="color:#a78bfa"></i>', task_due_soon:'<i class="fa-regular fa-clock" style="color:#fbbf24"></i>', task_overdue:'<i class="fa-solid fa-triangle-exclamation" style="color:#f87171"></i>', task_created:'<i class="fa-solid fa-table-columns" style="color:#4ade80"></i>', task_reply:'<i class="fa-solid fa-reply" style="color:#60a5fa"></i>', task_reaction:'<i class="fa-regular fa-face-smile" style="color:#fbbf24"></i>'}[type] || '<i class="fa-solid fa-table-columns"></i>';
  return {comment:'<i class="fa-solid fa-comment" style="color:#60a5fa"></i>', reply:'<i class="fa-solid fa-reply" style="color:#a78bfa"></i>', mention:'<i class="fa-solid fa-at" style="color:var(--g7-accent)"></i>',
    mention_post:'<i class="fa-solid fa-at" style="color:var(--g7-accent)"></i>', post_reply:'<i class="fa-solid fa-reply" style="color:#a78bfa"></i>', kudos:'<i class="fa-solid fa-award" style="color:#ec4899"></i>'}[type] || '<i class="fa-solid fa-bell"></i>';
}
function notifEsc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function notifText(n) {
  const from = notifEsc(n.display_name || n.username || 'Alguien');
  if (n.type === 'wk_req') return n.from_id ? `<strong>${from}</strong> · solicitud` : 'Novedad en una solicitud';
  if (n.type === 'wk_tk') return n.from_id ? `<strong>${from}</strong> · ticket` : 'Aviso de un ticket';
  if (n.type === 'pt_tk') return n.from_id ? `<strong>${from}</strong> · solicitud` : 'Aviso de una solicitud';
  if (n.type === 'login_new') return 'Nuevo ingreso a tu cuenta';
  if (n.type === 'login_fail') return 'Intentos de entrar a tu cuenta';
  if (n.type === 'wk_run') return n.from_id ? `<strong>${from}</strong> · proceso` : 'Novedad en un proceso';
  if (n.type === 'task_auto') return 'Automatización de una tarea';
  if (n.type === 'cal_remind') return 'Recordatorio de tu calendario';
  if (n.type === 'pay') return 'Finanzas y pagos';
  if (n.type === 'aff' || n.type === 'aff_apply') return 'Afiliados';
  if (n.type === 'resto') return 'Restaurant';
  if (n.type === 'social_report') return 'Informe de Social Studio';
  if (n.type === 'social_inbox') return n.from_id ? `<strong>${from}</strong> · Inbox` : 'Inbox de Social Studio';
  if (n.type === 'social') return n.from_id ? `<strong>${from}</strong> · Social Studio` : 'Social Studio';
  if (n.type === 'pass') return 'Seguridad de tu cuenta';
  if (n.type === 'onboarding') return 'Configuración de tu cuenta';
  if (n.type === 'bn_kudos') return n.from_id ? `<strong>${from}</strong> · Bienestar` : 'Bienestar';
  if (n.type === 'bn_leave') return n.from_id ? `<strong>${from}</strong> · vacaciones y licencias` : 'Vacaciones y licencias';
  if (n.type && n.type.startsWith('bn_')) return ({bn_challenge:'Bienestar · retos', bn_survey:'Bienestar · encuesta', bn_habit:'Bienestar · tus hábitos'})[n.type] || 'Bienestar';
  if (n.type && n.type.startsWith('task_')) return ({task_assigned:`<strong>${from}</strong> te asignó una tarea`, task_mention:`<strong>${from}</strong> te mencionó en una tarea`,
    task_comment:`<strong>${from}</strong> comentó en una tarea`, task_status:`<strong>${from}</strong> cambió el estado de una tarea`, task_due_soon:'Una tarea tuya está por vencer',
    task_overdue:'Una tarea se venció', task_created:`<strong>${from}</strong> creó una tarea`, task_reply:`<strong>${from}</strong> respondió tu comentario`, task_reaction:`<strong>${from}</strong> reaccionó a tu comentario`})[n.type] || 'Novedad en una tarea';
  if (n.type==='kudos') return `<strong>${from}</strong> te dio un reconocimiento`;
  if (n.type==='mention_post') return `<strong>${from}</strong> te mencionó en Comunidad`;
  if (n.type==='post_reply') return `<strong>${from}</strong> respondió tu publicación`;
  if (n.type==='comment') return `<strong>${from}</strong> comentó en tu muro`;
  if (n.type==='reply')   return `<strong>${from}</strong> respondió tu comentario`;
  if (n.type==='mention') return `<strong>${from}</strong> te mencionó en un comentario`;
  return `<strong>${from}</strong> te notificó`;
}
function timeAgoN(dt) {
  // Instante real (g7-time.js): la fecha viene en la zona de almacenamiento, no en la del navegador
  const d = Math.floor((Date.now()-((window.G7Time && G7Time.parse(dt)) || new Date(dt)))/1000);
  if(d<60)return'ahora'; if(d<3600)return Math.floor(d/60)+'m'; if(d<86400)return Math.floor(d/3600)+'h'; return Math.floor(d/86400)+'d';
}

async function fetchNotifs(silent=false) {
  try {
    const res  = await fetch('/toolz/notif.php?action=get');
    if (res.status === 401 || res.status === 403) { g7SessionGone(); return; }   // ¿la cerraron desde otro dispositivo?
    const data = await res.json();
    if (data.badges && window.G7Frames && G7Frames.badges) G7Frames.badges(data.badges);
    const badge = document.getElementById('notif-badge');
    const list  = document.getElementById('nd-list');
    // New notifications sound
    if (!silent && data.notifs.length && data.notifs[0].id > lastNotifId && lastNotifId > 0) {
      const newOnes = data.notifs.filter(n => n.id > lastNotifId && !n.read_at);
      if (newOnes.length) {
        // g7-juice.js: sonido según el tipo, campana que se mueve y tarjetas en pantalla (MOVIMIENTO.md)
        if (window.G7Juice && G7Juice.notify) G7Juice.notify(newOnes, { sound: !!G7_NOTIF_SOUND_ENABLED, once: newOnes[0].id });
        else playNotifSound();
      }
      if (newOnes.length && G7_BROWSER_NOTIF && 'Notification' in window && Notification.permission === 'granted' && document.hidden) {
        newOnes.slice(0, 3).forEach(n => {
          try {
            const tmp = document.createElement('div'); tmp.innerHTML = notifText(n);
            const bn = new Notification(tmp.textContent, { body: n.body || '', tag: 'g7n' + n.id });
            bn.onclick = () => { window.focus(); clickNotif(n.id, n.profile_id || 0, n.type, n.target_id || 0); bn.close(); };
          } catch (e) {}
        });
      }
    }
    if (data.notifs.length) lastNotifId = Math.max(...data.notifs.map(n=>n.id));
    // Badge
    if (data.unread > 0) { badge.textContent = data.unread > 9 ? '9+' : data.unread; badge.classList.add('show'); }
    else badge.classList.remove('show');
    // List
    if (!data.notifs.length) { list.innerHTML='<div class="nd-empty"><i class="fa-regular fa-bell" style="display:block;font-size:20px;margin-bottom:6px;opacity:.3"></i>Sin notificaciones</div>'; return; }
    list.innerHTML = data.notifs.map(n => `
      <div class="nd-item ${!n.read_at?'unread':''}" data-g7d="notif-item" data-id="${+n.id}" data-profile="${+(n.profile_id||0)}" data-type="${notifEsc(n.type)}" data-target="${+(n.target_id||0)}">
        ${!n.read_at?'':''}<div class="nd-icon">${notifIcon(n.type)}</div>
        <div style="flex:1;min-width:0">
          <div class="nd-text">${notifText(n)}</div>
          ${n.body?`<div class="nd-body">"${notifEsc(n.body.slice(0,180))}${n.body.length>180?'…':''}"</div>`:''}
          <div class="nd-time">${timeAgoN(n.created_at)}</div>
        </div>
      </div>`).join('');
  } catch(e) {}
}

// Sesión cerrada mientras la página estaba abierta (desde otro dispositivo, por un admin o al cambiar la contraseña):
// se confirma con sesiones_api.php (401 = ya no hay sesión) y se avisa en pantalla, sin esperar a que la persona haga clic en algo.
let g7GoneShown = false;
async function g7SessionGone() {
  if (g7GoneShown) return;
  try {
    const r = await fetch('/toolz/sesiones_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Sess': '1' }, credentials: 'same-origin', body: '{"action":"alive"}' });
    if (r.status !== 401) return;
    const j = await r.json().catch(() => ({}));
    const why = j.gone || (document.cookie.match(/(?:^|; )g7_bye=([a-z_]+)/) || [])[1] || '';
    const msg = { remote: 'Se cerró desde otro dispositivo (Mi perfil › Dónde iniciaste sesión).', not_me: 'Se cerró desde otro dispositivo (Mi perfil › Dónde iniciaste sesión).',
      password: 'Se cambió la contraseña de tu cuenta desde otro dispositivo.', admin: 'Un administrador cerró tu sesión.',
      locked: 'Tu cuenta quedó bloqueada por seguridad. Te mandamos un email para desbloquearla.' }[why] || 'Tu sesión venció.';
    g7GoneShown = true;
    const d = document.createElement('div');
    d.setAttribute('role', 'alertdialog'); d.setAttribute('aria-modal', 'true'); d.setAttribute('aria-labelledby', 'g7-gone-t');
    d.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.72);display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(3px)';
    d.innerHTML = `<div style="background:var(--g7-bg-3,#151517);border:1px solid var(--g7-border-3,#2a2a2e);border-radius:14px;max-width:380px;width:100%;padding:22px;text-align:center;color:var(--g7-text,#eee);box-shadow:0 20px 60px rgba(0,0,0,.5)">
      <div style="width:52px;height:52px;border-radius:50%;margin:0 auto 12px;display:grid;place-items:center;background:rgba(var(--g7-accent-rgb,255,102,0),.12);color:var(--g7-accent,#f60);font-size:21px"><i class="fa-solid fa-right-from-bracket"></i></div>
      <h3 id="g7-gone-t" style="font-size:17px;margin:0 0 6px">Se cerró tu sesión</h3>
      <p style="font-size:13px;line-height:1.55;color:var(--g7-text-3,#aaa);margin:0 0 16px">${notifEsc(msg)} Si no fuiste vos, entra y cambia tu contraseña.</p>
      <a href="/toolz/login.php" class="btn btn-primary" style="display:inline-flex;justify-content:center;width:100%">Volver a entrar</a></div>`;
    document.body.appendChild(d);
    d.querySelector('a').focus();
  } catch (e) {}
}

async function clickNotif(id, profileId, type = '', targetId = 0) {
  await fetch('/toolz/notif.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'read_one',id})});
  document.getElementById('notif-dropdown').classList.remove('open');
  notifOpen = false;
  if (type === 'login_fail') { window.location.href = '/toolz/user_config.php#sesiones'; fetchNotifs(true); return; }   // intentos fallidos (login_alert_lib.php)
  if (type === 'login_new') { window.location.href = '/toolz/user_config.php' + (targetId ? '?ingreso=' + targetId : '') + '#sesiones'; fetchNotifs(true); return; }   // aviso de ingreso nuevo: ese ingreso en Dónde iniciaste sesión (login_alert_lib.php)
  if (type === 'pt_tk' && targetId) { window.location.href = '/toolz/solicitudes/#solicitudes/t/' + targetId; fetchNotifs(true); return; }   // Portal de Solicitudes (módulo aparte)
  if ((type === 'wk_req' || type === 'wk_tk' || type === 'wk_run') && targetId) { window.location.href = '/toolz/trabajo/#' + ({ wk_req: 'solicitudes/', wk_tk: 'tickets/', wk_run: 'procesos/run/' })[type] + targetId; fetchNotifs(true); return; }
  if (type === 'pass') { window.location.href = '/toolz/password.php'; fetchNotifs(true); return; }
  if (type === 'onboarding') { window.location.href = '/toolz/bienvenida.php'; fetchNotifs(true); return; }
  if (type === 'pay' && targetId) { window.location.href = '/toolz/pagos/admin/#op/' + targetId; fetchNotifs(true); return; }
  if (type === 'aff' || type === 'aff_apply') { window.location.href = '/toolz/afiliados/#' + (type === 'aff_apply' && targetId ? 'af/' + targetId : 'referidos'); fetchNotifs(true); return; }
  if (type === 'resto') { window.location.href = '/toolz/resto/admin/' + (targetId ? '#sucursal/' + targetId : ''); fetchNotifs(true); return; }
  if (type && type.startsWith('bn_')) { window.location.href = '/toolz/bienestar/#' + ({ bn_challenge: 'retos', bn_survey: 'encuestas', bn_kudos: 'reconocimientos', bn_habit: 'habitos', bn_leave: 'ausencias' }[type] || 'inicio') + (targetId && type !== 'bn_kudos' ? '/' + targetId : ''); fetchNotifs(true); return; }   // Bienestar
  if (type === 'cal_remind' && targetId) { window.location.href = '/toolz/calendario/#e/' + targetId; fetchNotifs(true); return; }
  if (type === 'social_report' && targetId) { window.location.href = '/toolz/social/#informes/' + targetId; fetchNotifs(true); return; }   // Social Studio · automatic report
  if (type === 'social_inbox' && targetId) { window.location.href = '/toolz/social/#inbox/c/' + targetId; fetchNotifs(true); return; }   // Social Studio · Inbox
  if (type === 'social') { window.location.href = '/toolz/social/#' + (targetId ? 'p/' + targetId : 'cuentas'); fetchNotifs(true); return; }   // Social Studio (SOCIAL_STUDIO.md)
  if (type.startsWith('task_') && targetId) {
    if (typeof window.tkOpen === 'function') window.tkOpen(targetId); else window.location.href = '/toolz/tareas.php?t=' + targetId;
    fetchNotifs(true); return;
  }
  // Comunidad 2: publicaciones y reconocimientos llevan a la publicación; el muro, al perfil (perfil.php)
  if ((type === 'kudos' || type === 'mention_post' || type === 'post_reply') && targetId) { window.location.href = '/toolz/comunidad.php?post=' + targetId; fetchNotifs(true); return; }
  if (profileId) window.location.href = '/toolz/perfil.php?u=' + profileId + ((type === 'comment' || type === 'reply' || type === 'mention') ? '#muro' : '');
  fetchNotifs(true);
}

async function markAllRead() {
  await fetch('/toolz/notif.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'read_all'})});
  fetchNotifs(true);
}

function toggleNotifDropdown() {
  notifOpen = !notifOpen;
  document.getElementById('notif-dropdown').classList.toggle('open', notifOpen);
  if (notifOpen) fetchNotifs(true);
}

document.addEventListener('click', e => {
  if (!document.getElementById('notif-btn')?.contains(e.target) && !document.getElementById('notif-dropdown')?.contains(e.target)) {
    document.getElementById('notif-dropdown')?.classList.remove('open');
    notifOpen = false;
  }
});

// Fetch on load + every 30s
fetchNotifs(true);
setInterval(() => fetchNotifs(false), 30000);

// ── CALL / PING SYSTEM ────────────────────────────────────
const CALL_USERS = G7TB.callUsers || [];
const MY_ID_TB = +G7TB.myId || 0;

let selectedCallUsers = new Set();

let selectedPresetBeeps = 3;

function selectPreset(btn, message, beeps) {
  // deselect all
  document.querySelectorAll('.preset-btn').forEach(b => {
    b.style.borderColor = b.style.borderColor.includes('68,68') ? 'rgba(239,68,68,.25)' : '#27272a';
    b.style.background  = b.style.background.includes('68,68') ? 'rgba(239,68,68,.08)' : '#18181b';
  });
  // select this one
  btn.style.borderColor = beeps===10 ? 'rgba(239,68,68,.6)' : 'rgba(255,102,0,.5)';
  btn.style.background  = beeps===10 ? 'rgba(239,68,68,.14)' : 'rgba(255,102,0,.1)';

  const msgInput = document.getElementById('call-message');
  if (message === '' && beeps === 0) {
    // custom
    msgInput.style.display = 'block';
    msgInput.focus();
    selectedPresetBeeps = 3;
  } else {
    msgInput.style.display = 'none';
    msgInput.value = message;
    selectedPresetBeeps = beeps;
  }
  document.getElementById('call-beeps').value = beeps;
  // update send button label
  const label = beeps >= 20 ? '🚨 ENVIAR URGENTE' : beeps === 5 ? '🔔 Zumbido' : '🔔 Zumbido';
  document.getElementById('call-send-label').textContent = label;
  document.getElementById('call-send-btn').disabled = selectedCallUsers.size === 0;
}

function openCallModal() {
  selectedCallUsers = new Set();
  renderCallUsers();
  document.getElementById('call-modal').style.display = 'flex';
}
function closeCallModal() {
  document.getElementById('call-modal').style.display = 'none';
  document.querySelectorAll('.preset-btn').forEach(b => {
    b.style.borderColor = b.style.borderColor.includes('68,68') ? 'rgba(239,68,68,.25)' : '#27272a';
    b.style.background  = b.style.background.includes('68,68') ? 'rgba(239,68,68,.08)' : '#18181b';
  });
  document.getElementById('call-message').style.display = 'none';
  document.getElementById('call-message').value = '';
  document.getElementById('call-beeps').value = '3';
  document.getElementById('call-send-label').textContent = 'Enviar zumbido';
}

function avCall(u, size) {
  const avs = /^(\/(?!\/)|https?:\/\/)/i.test(String(u.avatar||'')) ? String(u.avatar).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/'/g,'&#39;') : '';
  if (avs) return `<img src="${avs}" style="width:${size}px;height:${size}px;border-radius:50%;object-fit:cover;flex-shrink:0">`;
  const i=notifEsc((u.display_name||u.username||'?')[0].toUpperCase());
  const c=['#ff6600','#a78bfa','#4ade80','#60a5fa','#f59e0b','#ec4899'][i.charCodeAt(0)%6];
  return `<div style="width:${size}px;height:${size}px;border-radius:50%;background:${c};display:flex;align-items:center;justify-content:center;font-size:${Math.floor(size*.42)}px;font-weight:700;color:#000;flex-shrink:0">${i}</div>`;
}

function renderCallUsers() {
  document.getElementById('call-user-list').innerHTML = CALL_USERS.map(u => {
    const sel = selectedCallUsers.has(u.id);
    return `<div class="g7-call-user${sel?' sel':''}" data-g7d="call-user" data-id="${+u.id}">
      ${avCall(u, 34)}
      <div style="flex:1">
        <div style="font-size:12px;font-weight:600">${notifEsc(u.display_name||u.username)}</div>
        <div style="font-size:10px;color:var(--g7-text-4)">@${notifEsc(u.username)}</div>
      </div>
      <div style="width:20px;height:20px;border-radius:50%;border:2px solid ${sel?'#ff6600':'#27272a'};background:${sel?'#ff6600':'transparent'};display:flex;align-items:center;justify-content:center;transition:all .15s;flex-shrink:0">
        ${sel?'<i class="fa-solid fa-check" style="font-size:9px;color:#000"></i>':''}
      </div>
    </div>`;
  }).join('');
}

function toggleCallUser(id) {
  if (selectedCallUsers.has(id)) selectedCallUsers.delete(id);
  else selectedCallUsers.add(id);
  renderCallUsers();
  document.getElementById('call-send-btn').disabled = selectedCallUsers.size === 0;
}

async function sendCall() {
  const message = document.getElementById('call-message').value.trim();
  const btn = document.getElementById('call-send-btn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...';
  const beeps = parseInt(document.getElementById('call-beeps').value) || 3;
  // Si algo falla, el botón vuelve a estar disponible y dice qué pasó (antes quedaba trabado en "Enviando...")
  let data = {};
  try {
    const res = await fetch('/toolz/ping.php', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'send', to_ids:[...selectedCallUsers], message, beeps: beeps === 10 ? 30 : beeps})
    });
    data = await res.json().catch(() => ({ error: 'El servidor respondió algo inesperado (' + res.status + ').' }));
  } catch (e) { data = { error: 'Sin conexión con el servidor.' }; }
  if (data.status !== 'success') {
    btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> <span id="call-send-label">' + notifEsc(data.error || 'No se pudo enviar') + '</span>';
    btn.style.background = 'rgba(239,68,68,.15)'; btn.style.color = '#f87171';
    setTimeout(() => { btn.innerHTML = '<i class="fa-solid fa-bell"></i> <span id="call-send-label">Enviar zumbido</span>'; btn.style.background = ''; btn.style.color = ''; btn.disabled = selectedCallUsers.size === 0; }, 3500);
    return;
  }
  if (data.status === 'success') {
    btn.innerHTML = '<i class="fa-solid fa-check"></i> ¡Enviado!';
    btn.style.background = 'rgba(34,197,94,.2)';
    btn.style.color = '#4ade80';
    setTimeout(() => {
      closeCallModal();
      btn.innerHTML = '<i class="fa-solid fa-bell"></i> <span id="call-send-label">Enviar zumbido</span>';
      btn.style.background = '';
      btn.style.color = '';
      btn.disabled = false;
    }, 1500);
  }
}

// ── PING RECEIVER ─────────────────────────────────────────
// Los navegadores no dejan sonar audio hasta que la persona tocó la página: se prepara el audio con el primer clic o tecla
// y se reutiliza (antes se creaba uno nuevo en cada zumbido y en algunos navegadores quedaba "suspendido" y no sonaba)
let g7PingCtx = null;
function g7PingAudio() {
  try { g7PingCtx = g7PingCtx || new (window.AudioContext || window.webkitAudioContext)(); if (g7PingCtx.state === 'suspended') g7PingCtx.resume(); } catch (e) {}
  return g7PingCtx;
}
['pointerdown', 'keydown'].forEach(ev => document.addEventListener(ev, () => g7PingAudio(), { once: true, capture: true }));
function playPingSound(count) {
  try {
    const ctx = g7PingAudio(); if (!ctx) return;
    const isUrgent = count >= 20;
    for (let n = 0; n < count; n++) {
      const delay = n * (isUrgent ? 200 : 250);
      const osc  = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.connect(gain); gain.connect(ctx.destination);
      osc.type = isUrgent ? 'square' : 'sine';
      const freq = isUrgent ? (n%2===0 ? 1200 : 800) : 880 - (n * 20);
      osc.frequency.setValueAtTime(freq, ctx.currentTime + delay/1000);
      osc.frequency.exponentialRampToValueAtTime(freq*0.75, ctx.currentTime + delay/1000 + 0.15);
      gain.gain.setValueAtTime(isUrgent ? 0.25 : 0.18, ctx.currentTime + delay/1000);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + delay/1000 + 0.28);
      osc.start(ctx.currentTime + delay/1000);
      osc.stop(ctx.currentTime + delay/1000 + 0.28);
    }
  } catch(e) {}
}

function showPingToast(ping) {
  const name = notifEsc(ping.display_name || ping.username);   // texto de otra persona: siempre escapado
  const toast = document.createElement('div');
  toast.style.cssText = `position:fixed;bottom:24px;right:24px;background:#111;border:1px solid rgba(34,197,94,.4);border-radius:12px;padding:14px 18px;display:flex;align-items:center;gap:12px;z-index:9999;box-shadow:0 8px 32px rgba(0,0,0,.6);animation:slideUp .3s ease;max-width:320px`;
  toast.innerHTML = `
    <div style="width:36px;height:36px;border-radius:50%;background:rgba(34,197,94,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <i class="fa-solid fa-phone" style="color:#4ade80;font-size:14px"></i>
    </div>
    <div style="flex:1;min-width:0">
      <div style="font-size:12px;font-weight:700;color:${parseInt(ping.beeps)>=20?'#ef4444':'#4ade80'}">${parseInt(ping.beeps)>=20?'🚨':'📞'} ${name} ${parseInt(ping.beeps)>=20?'— URGENTE':'te está llamando'}</div>
      ${ping.message ? `<div style="font-size:11px;color:var(--g7-text-2);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${notifEsc(ping.message)}</div>` : ''}
    </div>
    <button data-g7d="remove-parent" style="background:none;border:none;color:var(--g7-text-4);cursor:pointer;font-size:14px;padding:0">✕</button>`;
  if (!document.getElementById('ping-anim')) {
    const s = document.createElement('style');
    s.id = 'ping-anim';
    s.textContent = '@keyframes slideUp{from{transform:translateY(20px);opacity:0}to{transform:none;opacity:1}}';
    document.head.appendChild(s);
  }
  document.body.appendChild(toast);
}

function shakeScreen(times) {
  const body = document.body;
  let count = 0;
  const shake = () => {
    if (count >= times) { body.classList.remove('tb-shaking'); return; }
    body.classList.remove('tb-shaking');
    void body.offsetWidth; // reflow to restart animation
    body.classList.add('tb-shaking');
    count++;
    setTimeout(shake, 650);
  };
  shake();
}

async function checkPings() {
  try {
    const res  = await fetch('/toolz/ping.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'check'})});
    const data = await res.json();
    if (data.pings && data.pings.length) {
      const maxBeeps = Math.max(...data.pings.map(p => parseInt(p.beeps)||3));
      if(G7_NOTIF_SOUND_ENABLED) { if (window.G7Juice && G7Juice.ring) G7Juice.ring(maxBeeps); else playPingSound(maxBeeps); }
      data.pings.forEach(p => showPingToast(p));
      // Shake the screen
      shakeScreen(maxBeeps);
    }
  } catch(e) {}
}

// Check every 10 seconds
setInterval(checkPings, 10000);

// ── Toasts de gamificación ──
// Show gamification notifications
const _gamifNotifs = (() => { const el = document.getElementById('g7-gamif-data'); try { return el ? JSON.parse(el.textContent) : []; } catch (e) { return []; } })();

function g7ShowGamifToast(notif){
  const data = typeof notif.data === 'string' ? JSON.parse(notif.data) : notif.data;
  const isLevelUp = notif.type === 'level_up';
  const toast = document.createElement('div');
  toast.className = 'g7-gamif-toast ' + (isLevelUp ? 'level-up' : 'badge');
  const title = isLevelUp
    ? '¡Subiste de nivel!'
    : '¡Logro desbloqueado!';
  const sub = isLevelUp
    ? `Ahora eres ${data.emoji} ${data.label}`
    : `${data.label} — ${data.desc||''}`;
  toast.innerHTML = `
    <div class="g7-gamif-toast-icon">${data.emoji}</div>
    <div class="g7-gamif-toast-body">
      <div class="g7-gamif-toast-title">${title}</div>
      <div class="g7-gamif-toast-sub">${sub}</div>
    </div>
    <button class="g7-gamif-toast-close" data-g7d="close-toast">✕</button>`;
  document.getElementById('g7-gamif-container').appendChild(toast);
  requestAnimationFrame(()=>{ requestAnimationFrame(()=>{ toast.classList.add('show'); }); });
  setTimeout(()=>{ toast.classList.remove('show'); setTimeout(()=>toast.remove(), 400); }, 6000);
}

// Show toasts with stagger
document.addEventListener('DOMContentLoaded', ()=>{
  if (!_gamifNotifs.length) return;   // sin logros nuevos no hay nada que mostrar ni marcar
  _gamifNotifs.forEach((n, i) => setTimeout(()=>g7ShowGamifToast(n), i * 1200));
  // Mark as seen
  fetch('/toolz/gamification_notif.php', {method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'mark_seen'})}).catch(()=>{});
});

// ── Eventos (antes onclick="…" en el HTML) ──
// Elementos fijos: se enganchan directo (mismo orden que los atributos de antes). Los que arma el JS: un solo detector.
function g7tbAction(a, el, e) {
  switch (a) {
    case 'mobile-nav': g7ToggleMobileNav(); break;
    case 'accesos': toggleAccesosModal(); break;
    case 'accesos-mobile': toggleAccesosModal(); g7ToggleMobileNav(); break;
    case 'call': openCallModal(); break;
    case 'call-mobile': openCallModal(); g7ToggleMobileNav(); break;
    case 'notif': toggleNotifDropdown(); break;
    case 'read-all': markAllRead(); break;
    case 'user-menu': g7UserMenu(e); break;
    case 'announce-close': try { localStorage.setItem('g7_announce_closed', el.parentNode.dataset.v); } catch (x) {} el.parentNode.remove(); break;
    case 'call-close': closeCallModal(); break;
    case 'preset': selectPreset(el, el.dataset.msg || '', +el.dataset.beeps || 0); break;
    case 'send-call': sendCall(); break;
    case 'accesos-bg': if (e.target === el) closeAccesosModal(); break;
    case 'notif-item': clickNotif(+el.dataset.id, +el.dataset.profile, el.dataset.type || '', +el.dataset.target); break;
    case 'call-user': toggleCallUser(+el.dataset.id); break;
    case 'remove-parent': el.parentElement.remove(); break;
    case 'close-toast': { const tt = el.closest('.g7-gamif-toast'); if (tt) tt.remove(); break; }
  }
}
function g7tbBind(root) {
  (root || document).querySelectorAll('[data-g7]').forEach(el => { if (el.__g7) return; el.__g7 = true; el.addEventListener('click', e => g7tbAction(el.dataset.g7, el, e)); });
  (root || document).querySelectorAll('[data-g7-stop]').forEach(el => { if (el.__g7s) return; el.__g7s = true; el.addEventListener('click', e => e.stopPropagation()); });
  (root || document).querySelectorAll('[data-g7-autosubmit]').forEach(el => { if (el.__g7a) return; el.__g7a = true; el.addEventListener('change', () => el.form && el.form.submit()); });
  (root || document).querySelectorAll('[data-g7-enter]').forEach(el => { if (el.__g7e) return; el.__g7e = true; el.addEventListener('keydown', e => { if (e.key === 'Enter') g7tbAction(el.dataset.g7Enter, el, e); }); });
}
g7tbBind();   // lo que ya está en la página
document.addEventListener('DOMContentLoaded', () => g7tbBind());   // lo que viene después (modal del zumbido, buscador)
// En captura: así llega aunque un contenedor corte la propagación (el modal del zumbido lo hace)
document.addEventListener('click', e => { const el = e.target.closest('[data-g7d]'); if (el) g7tbAction(el.dataset.g7d, el, e); }, true);
