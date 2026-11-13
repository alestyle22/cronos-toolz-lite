/* assets/comunidad-page.js — Script de comunidad.php (Comunidad), sin código en línea (CSP estricta, SEC-24). Los datos llegan en #cm-cfg. */
const CM_CFG = JSON.parse(document.getElementById('cm-cfg').textContent);   // datos de PHP (comunidad.php)
const ALL_USERS = CM_CFG.users;
const ME_ID = CM_CFG.me;
const IS_ADMIN = !!CM_CFG.isAdmin;
const SKILLS_LIST = CM_CFG.skillsList;
const TYPE_COLORS = {promo:'#ff6600',launch:'#a78bfa',paid:'#4ade80',social:'#60a5fa'};
const TYPE_LABELS = {promo:'Promo',launch:'Lanzamiento',paid:'Paid',social:'Redes'};
let currentProfileId = null;
let skillValues = Object.assign({}, CM_CFG.skills);
let lastPostTime = CM_CFG.lastPostTime;
let teamExpanded = false;
let promoSuggestions = [];
let _promosCache = null;

function av(u,size){const src=safeUrl(u?.avatar),name=u?.display_name||u?.username||'?';if(src)return`<img src="${escH(src)}" style="width:${size}px;height:${size}px;border-radius:50%;object-fit:cover;flex-shrink:0">`;const i=(name[0]||'?').toUpperCase(),c=['#ff6600','#a78bfa','#4ade80','#60a5fa','#f59e0b','#ec4899'][i.charCodeAt(0)%6];return`<div style="width:${size}px;height:${size}px;border-radius:50%;background:${c};display:flex;align-items:center;justify-content:center;font-size:${Math.floor(size*.42)}px;font-weight:700;color:#000;flex-shrink:0">${i}</div>`;}
function escH(s){return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
// Texto para pasar como argumento en un data-click-N="j:…" (g7-on): string JSON escapada para el atributo
function jsA(s){return escH(JSON.stringify(String(s??'')));}
// URL de imagen o enlace: solo rutas del sitio o http(s)
function safeUrl(u){u=String(u??'').trim();return /^(\/(?!\/)|https?:\/\/)/i.test(u)?u:'';}
function hlM(t){return t.replace(/@(\w+)/g,'<span class="mention">@$1</span>');}
// Fechas: instante real con assets/g7-time.js (el servidor manda la hora de almacenamiento, no la del navegador)
const cmD=dt=>(window.G7Time&&G7Time.parse(dt))||new Date(dt);
const cmFmt=(dt,o)=>window.G7Time?G7Time.fmt(dt,o):cmD(dt).toLocaleString('es-AR',o);
function tagoAgo(dt){const d=Math.floor((Date.now()-cmD(dt))/1000);if(d<60)return'ahora';if(d<3600)return Math.floor(d/60)+'m';if(d<86400)return Math.floor(d/3600)+'h';return Math.floor(d/86400)+'d';}
function goToContent(name){window.open('/toolz/promos/?search='+encodeURIComponent(name),'_blank');}
function showToast(msg){const t=document.createElement('div');t.textContent=msg;t.className='toast';document.body.appendChild(t);setTimeout(()=>t.remove(),2500);}

function openLightbox(src){if(!src)return;document.getElementById('lightbox-img').src=src;document.getElementById('lightbox').classList.add('open');}
function closeLightbox(){document.getElementById('lightbox').classList.remove('open');}

function switchFeed(type,navBtn,tabBtn){
  document.getElementById('feed-posts').style.display=type==='feed'?'block':'none';
  document.getElementById('feed-activity').style.display=type==='activity'?'block':'none';
  document.getElementById('feed-working').style.display=type==='working'?'block':'none';
  if(type==='working'){updateWorkingNow();}
  document.querySelectorAll('.feed-tab').forEach(b=>b.classList.remove('active'));
  document.querySelectorAll('.nav-item[id^="nav-"]').forEach(b=>b.classList.remove('active'));
  if(tabBtn)tabBtn.classList.add('active');
  if(navBtn)navBtn.classList.add('active');
  else{const id='nav-'+type;const el=document.getElementById(id);if(el)el.classList.add('active');}
  // Sync tabs too
  document.querySelectorAll('.feed-tab').forEach((b,i)=>{const types=['feed','activity','working'];if(types[i]===type)b.classList.add('active');else b.classList.remove('active');});
}

function toggleSkillTag(btn){
  const skill=btn.dataset.skill;
  const sel=btn.classList.toggle('selected');
  btn.style.border='1px solid '+(sel?'#ff6600':'#27272a');
  btn.style.background=sel?'rgba(255,102,0,.15)':'transparent';
  btn.style.color=sel?'#ff6600':'#71717a';
  skillValues[skill]=sel?1:0;
}

function updateCharCount(ta){const left=140-ta.value.length;const cc=document.getElementById('char-count');cc.textContent=left;cc.className='char-count'+(left<20?' warn':'')+(left<0?' over':'');document.getElementById('pub-btn').disabled=ta.value.trim().length===0||ta.value.length>140;}

function previewPostImg(input){const file=input.files[0];if(!file)return;const r=new FileReader();r.onload=e=>{document.getElementById('img-preview-thumb').src=e.target.result;document.getElementById('img-preview-wrap').style.display='block';};r.readAsDataURL(file);}
function clearPostImg(){document.getElementById('post-img-input').value='';document.getElementById('img-preview-wrap').style.display='none';document.getElementById('img-preview-thumb').src='';}

function linkifyPost(body){
  return escH(body)
    .replace(/\n/g,'<br>')
    .replace(/@(\w+)/g,(match,username)=>{
      const u=ALL_USERS.find(x=>x.username.toLowerCase()===username.toLowerCase());
      if(u)return `<span class="mention-link" data-uid="${u.id}" data-on-click="openProfile" data-click-1="j:${u.id}" style="color:#ff6600;font-weight:700;cursor:pointer">@${escH(username)}</span>`;
      return match;
    })
    .replace(/(https?:\/\/[^\s<]+)/g,(url)=>{
      const display=url.length>40?url.slice(0,40)+'...':url;
      return `<a href="${url}" target="_blank" rel="noopener" style="color:#60a5fa;text-decoration:underline;text-decoration-color:rgba(96,165,250,.4);word-break:break-all" data-hov="ul">${display}</a>`;
    });
}



function handlePostMention(ta){
  const val=ta.value,pos=ta.selectionStart,before=val.slice(0,pos);
  const dd=document.getElementById('post-mention-dd');if(!dd)return;
  // @mention
  const atMatch=before.match(/@(\w*)$/);
  if(atMatch){
    const q=atMatch[1].toLowerCase();
    const res=ALL_USERS.filter(u=>(u.username.toLowerCase().startsWith(q)||(u.display_name||'').toLowerCase().startsWith(q))&&u.id!=ME_ID).slice(0,6);
    if(res.length){
      dd.style.display='block';
      dd.innerHTML=`<div style="font-size:9px;font-weight:700;color:#52525b;text-transform:uppercase;padding:7px 13px 4px;letter-spacing:.08em">Usuarios</div>`+res.map(u=>`<div data-on-click="insertPostMention" data-click-1="s:@" data-click-2="j:${jsA(u.username)}" style="display:flex;align-items:center;gap:10px;padding:8px 13px;cursor:pointer;transition:background .1s" data-hov="bg">
        ${av(u,28)}
        <div><div style="font-size:12px;font-weight:700;color:#e4e4e7">${escH(u.display_name||u.username)}</div><div style="font-size:10px;color:#52525b">@${escH(u.username)}</div></div>
      </div>`).join('');
      return;
    }
  }
  // #promo tag
  const hashMatch=before.match(/#(\w*)$/);
  if(hashMatch){
    const q=hashMatch[1].toLowerCase();
    getPromos().then(promos=>{
      const res=promos.filter(p=>p.name.toLowerCase().includes(q)).slice(0,6);
      if(res.length){
        dd.style.display='block';
        promoSuggestions = res;
        dd.innerHTML=`<div style="font-size:9px;font-weight:700;color:#52525b;text-transform:uppercase;padding:7px 13px 4px;letter-spacing:.08em">Contenido</div>`+res.map((p,i)=>`<div data-on-click="insertPromoMention" data-click-1="j:${i}" style="display:flex;align-items:center;gap:10px;padding:8px 13px;cursor:pointer;transition:background .1s" data-hov="bg">
          <div style="width:28px;height:28px;border-radius:6px;background:rgba(167,139,250,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fa-solid fa-layer-group" style="font-size:11px;color:#a78bfa"></i></div>
          <div><div style="font-size:12px;font-weight:700;color:#e4e4e7">${escH(p.name)}</div><div style="font-size:10px;color:#52525b">${escH(p.type||'')}</div></div>
        </div>`).join('');
        return;
      }
      dd.style.display='none';
    });
    return;
  }
  dd.style.display='none';
}

function insertPostMention(type, value){
  const ta=document.getElementById('post-body'),dd=document.getElementById('post-mention-dd');
  if(!ta)return;
  const val=ta.value,pos=ta.selectionStart,before=val.slice(0,pos);
  const triggerPos=before.lastIndexOf(type);
  const insert=type+value+' ';
  ta.value=val.slice(0,triggerPos)+insert+val.slice(pos);
  ta.focus();const np=triggerPos+insert.length;ta.setSelectionRange(np,np);
  updateCharCount(ta);
  if(dd)dd.style.display='none';
}

async function submitPost(){
  const body=document.getElementById('post-body').value.trim();
  const rawImg=document.getElementById('post-img-input').files[0];
  if((!body&&!rawImg)||body.length>140)return;
  const btn=document.getElementById('pub-btn');btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>';
  try{
    const imgFile=rawImg?await g7PrepImage(rawImg):null;
    const fd=new FormData();fd.append('action','add_post');fd.append('body',body);if(imgFile)fd.append('post_img',imgFile);
    const res=await fetch('',{method:'POST',body:fd});const data=await res.json().catch(()=>null);
    if(data&&data.status==='success'){document.getElementById('post-body').value='';updateCharCount(document.getElementById('post-body'));clearPostImg();prependPost(data.post);}
    else showToast(g7UploadError(data,'No se pudo publicar'));
  }catch(e){showToast('No se pudo publicar: '+e.message);}
  btn.innerHTML='Publicar';btn.disabled=false;
}

function renderReply(r,postId){
  const name=r.display_name||r.username,canDel=r.user_id==ME_ID||IS_ADMIN;
  return`<div class="reply-item" id="reply-${r.id}">
    ${av({avatar:r.avatar,display_name:r.display_name,username:r.username},26)}
    <div class="reply-bubble">
      <div><span class="reply-author" data-on-click="openProfile" data-click-1="j:${r.user_id}">${escH(name)}</span><span class="reply-time">${tagoAgo(r.created_at)}</span>${canDel?`<button class="reply-del" data-on-click="deleteReply" data-click-1="j:${r.id}"><i class="fa-solid fa-trash-can"></i></button>`:''}</div>
      <div class="reply-body">${escH(r.body)}</div>
    </div>
  </div>`;}

function renderPost(post){
  const name=post.display_name||post.username,canDel=post.user_id==ME_ID||IS_ADMIN;
  const emojis={like:'👍',fire:'🔥',heart:'❤️',wow:'😮',clap:'👏'};
  const reactionMap={};(post.reactions||[]).forEach(r=>reactionMap[r.emoji]=r.cnt);
  const reactH=Object.entries(emojis).map(([k,em])=>{const cnt=reactionMap[k]||0,active=post.my_reaction===k;return`<button class="rxn-btn ${active?'active':''}" data-on-click="reactToPost" data-click-1="j:${post.id}" data-click-2="s:${k}">${em}${cnt>0?`<span class="rxn-cnt">${cnt}</span>`:''}</button>`;}).join('');
  const repliesHtml=(post.replies||[]).map(r=>renderReply(r,post.id)).join('');
  const replyCount=(post.replies||[]).length;
  return`<div class="post-card" id="post-${post.id}">
    <div class="post-header">${av({avatar:post.avatar,display_name:post.display_name,username:post.username},38)}<div style="flex:1;min-width:0"><div class="post-author-name" data-on-click="openProfile" data-click-1="j:${post.user_id}">${escH(name)}</div><div class="post-handle">@${escH(post.username)} · ${tagoAgo(post.created_at)}</div></div>${canDel?`<button class="post-del" data-on-click="deletePost" data-click-1="j:${post.id}"><i class="fa-solid fa-trash-can"></i></button>`:''}</div>
    <div class="post-body">${linkifyPost(post.body)}</div>
    ${post.img?`<img src="${escH(post.img)}" class="post-img" data-on-click="openLightbox" data-click-1="j:${jsA(post.img)}" data-on-error="cmHide" data-error-1="this">`:'' }
    <div class="post-reactions" id="reactions-${post.id}">${reactH}</div>
    <div class="post-replies">
      <div id="post-replies-${post.id}" style="display:flex;flex-direction:column;gap:7px;${replyCount>0?'margin-bottom:8px':''}">${repliesHtml}</div>
      <button class="reply-toggle" data-on-click="toggleReplyBox" data-click-1="j:${post.id}"><i class="fa-solid fa-reply" style="font-size:10px"></i>${replyCount>0?`${replyCount} respuesta${replyCount>1?'s':''} · `:''}Responder</button>
      <div class="reply-form" id="reply-box-${post.id}">
        <textarea class="reply-input" id="reply-input-${post.id}" placeholder="Escribe una respuesta..." data-on-keydown="cmReplyKey" data-keydown-1="event" data-keydown-2="j:${post.id}"></textarea>
        <button class="reply-send" data-on-click="submitReplyToPost" data-click-1="j:${post.id}"><i class="fa-solid fa-paper-plane"></i></button>
      </div>
    </div>
  </div>`;}

function prependPost(post){const feed=document.getElementById('feed-posts');const empty=feed.querySelector('div[style*="text-align:center"]');if(empty)empty.remove();feed.insertAdjacentHTML('afterbegin',renderPost(post));lastPostTime=post.created_at;}
async function deletePost(id){if(!confirm('Eliminar?'))return;const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_post',post_id:id})});if((await res.json()).status==='success')document.getElementById('post-'+id)?.remove();}
async function reactToPost(postId,emoji){const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'react',post_id:postId,emoji})});const data=await res.json();if(data.status==='success'){const emojis={like:'👍',fire:'🔥',heart:'❤️',wow:'😮',clap:'👏'};const reactionMap={};data.reactions.forEach(r=>reactionMap[r.emoji]=r.cnt);const wrap=document.getElementById('reactions-'+postId);if(!wrap)return;wrap.innerHTML=Object.entries(emojis).map(([k,em])=>{const cnt=reactionMap[k]||0,active=data.my_reaction===k;return`<button class="rxn-btn ${active?'active':''}" data-on-click="reactToPost" data-click-1="j:${postId}" data-click-2="s:${k}">${em}${cnt>0?`<span class="rxn-cnt">${cnt}</span>`:''}</button>`;}).join('');}}
function toggleReplyBox(postId){const box=document.getElementById('reply-box-'+postId);if(!box)return;box.classList.toggle('open');if(box.classList.contains('open'))document.getElementById('reply-input-'+postId)?.focus();}
async function submitReplyToPost(postId){const ta=document.getElementById('reply-input-'+postId),body=ta?.value.trim();if(!body)return;const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add_reply',post_id:postId,body})});const data=await res.json();if(data.status==='success'){ta.value='';document.getElementById('reply-box-'+postId).classList.remove('open');const cont=document.getElementById('post-replies-'+postId);if(cont){cont.insertAdjacentHTML('beforeend',renderReply(data.reply,postId));cont.style.marginBottom='8px';}}}
async function deleteReply(id){if(!confirm('Eliminar?'))return;const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_reply',reply_id:id})});if((await res.json()).status==='success')document.getElementById('reply-'+id)?.remove();}
async function pollPosts(){try{const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_posts',since:lastPostTime})});const data=await res.json();if(data.posts?.length){data.posts.forEach(p=>prependPost(p));}}catch(e){}}
setInterval(pollPosts,20000);

function slideTeam(dir){}

async function getPromos(){
  if(_promosCache)return _promosCache;
  try{const res=await fetch('/toolz/promos/?api=list',{headers:{'X-Requested-With':'XMLHttpRequest'}});if(res.ok){const d=await res.json();_promosCache=d.items||[];return _promosCache;}}catch(e){}
  return [];
}

function insertPromoMention(idx){
  const ta=document.getElementById('post-body'),dd=document.getElementById('post-mention-dd');
  const p=promoSuggestions[idx];if(!p||!ta)return;
  const val=ta.value,pos=ta.selectionStart,before=val.slice(0,pos);
  const hashPos=before.lastIndexOf('#');
  const insert='#'+p.name+' ';
  ta.value=val.slice(0,hashPos)+insert+val.slice(pos);
  ta.focus();const np=hashPos+insert.length;ta.setSelectionRange(np,np);
  updateCharCount(ta);
  if(dd)dd.style.display='none';
}

// ── STATUS ────────────────────────────────────────────────
function updateOnlineCount(users){
  const online=users.filter(u=>u.online&&u.id!=ME_ID).length;
  const el=document.getElementById('online-count');
  if(el)el.textContent=online>0?online+' online':'';
}

async function updateMyStatus(){
  const avail=document.getElementById('my-availability')?.value||'available';
  const focus=document.getElementById('my-focus')?.value||'';
  const mood=document.getElementById('my-mood')?.value||'';
  await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'update_status',availability:avail,focus_text:focus,mood})});
  showToast('✓ Estado actualizado');
}

// Poll team status every 60s and update right column
async function updateWorkingNow(){
  document.getElementById('working-updated').textContent='Actualizado: '+new Date().toLocaleTimeString('es-AR',{hour:'2-digit',minute:'2-digit'});
}

async function pollTeamStatus(){
  try{
    const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_team_status'})});
    const data=await res.json();
    if(!data.users)return;
    const availColors={available:'#22c55e',busy:'#f59e0b',focus:'#60a5fa',away:'#71717a'};
    // Update working-on panel
    const avColors={available:'#22c55e',busy:'#f59e0b',focus:'#60a5fa',away:'#71717a'};
    const avLabels={available:'Disponible',busy:'En reunión',focus:'Concentrado',away:'Fuera'};
    data.users.forEach(u=>{
      const focusEl=document.querySelector('.wi-focus-'+u.id);
      const dotEl=document.querySelector('.wi-dot-'+u.id);
      const availEl=document.querySelector('.wi-avail-'+u.id);
      const color=u.online?(avColors[u.availability]||'#52525b'):'#3f3f46';
      if(dotEl)dotEl.style.background=color;
      if(focusEl){focusEl.textContent=u.focus_text?'🎯 '+u.focus_text:'Sin foco definido aún...';focusEl.style.color=u.focus_text?'#a1a1aa':'#3f3f46';focusEl.style.fontStyle=u.focus_text?'normal':'italic';}
      if(availEl){const lbl=avLabels[u.availability]||'Disponible';availEl.textContent=lbl;availEl.style.color=u.online?(avColors[u.availability]||'#52525b'):'#52525b';availEl.style.background=u.online?((avColors[u.availability]||'#52525b')+'18'):'#18181b';}
    });
    document.getElementById('working-updated').textContent='Actualizado: '+new Date().toLocaleTimeString('es-AR',{hour:'2-digit',minute:'2-digit'});
    data.users.filter(u=>u.id!=ME_ID).forEach(u=>{
      const dot=document.getElementById('status-dot-'+u.id);
      const focus=document.getElementById('focus-'+u.id);
      const mood=document.getElementById('mood-'+u.id);
      const color=u.online?(availColors[u.availability]||'#52525b'):'#3f3f46';
      if(dot)dot.style.background=color;
      if(focus){if(u.focus_text){focus.textContent='🎯 '+u.focus_text;focus.style.display='block';}else focus.style.display='none';}
      if(mood)mood.textContent=u.mood||'';
    });
    updateOnlineCount(data.users);
    // Heartbeat
    fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'heartbeat_status'})});
  }catch(e){}
}
setInterval(pollTeamStatus,60000);
pollTeamStatus();

// ── NOTIFICATIONS ──────────────────────────────────────────
const NOTIF_TYPE_TEXT={
  comment:'comentó en tu perfil',
  reply:'respondió un comentario'
};
function renderNotifItem(n){
  const name=escH(n.display_name||n.username||'Alguien');
  const text=NOTIF_TYPE_TEXT[n.type]||n.type;
  const unread=!n.read_at;
  return`<div style="display:flex;gap:8px;padding:7px 0;border-bottom:1px solid #141414;align-items:flex-start${unread?';background:rgba(255,102,0,.03)':''}" data-on-click="cmNotifProfile" data-click-1="j:${n.profile_id||0}" style="cursor:pointer">
    ${av({avatar:n.avatar,display_name:n.display_name,username:n.username},26)}
    <div style="flex:1;min-width:0">
      <div style="font-size:11px;color:#a1a1aa;line-height:1.4"><span style="font-weight:700;color:#e4e4e7;cursor:pointer" data-on-click="openProfile|@stop" data-click-1="j:${n.from_id}">${name}</span> ${text}</div>
      ${n.body?`<div style="font-size:10px;color:#52525b;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">"${escH(n.body.slice(0,60))}"</div>`:''}
      <div style="font-size:10px;color:#3f3f46;margin-top:2px">${tagoAgo(n.created_at)}</div>
    </div>
    ${unread?'<div style="width:6px;height:6px;border-radius:50%;background:#ff6600;flex-shrink:0;margin-top:4px"></div>':''}
  </div>`;
}

async function pollNotifications(){
  try{
    const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_notifications'})});
    const data=await res.json();
    const badge=document.getElementById('notif-badge');
    const list=document.getElementById('notif-list');
    if(!list)return;
    if(data.unread>0){if(badge){badge.textContent=data.unread>9?'9+':data.unread;badge.style.display='inline';}}
    else if(badge)badge.style.display='none';
    if(data.notifications?.length){
      list.innerHTML=data.notifications.map(n=>renderNotifItem(n)).join('');
    }
  }catch(e){}
}

async function markNotifsRead(){
  await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'mark_notifications_read'})});
  const badge=document.getElementById('notif-badge');if(badge)badge.style.display='none';
  document.querySelectorAll('#notif-list [style*="background:rgba(255,102,0,.03)"]').forEach(el=>el.style.background='');
}

pollNotifications();
setInterval(pollNotifications,30000);

// ── PIN ────────────────────────────────────────────────────
async function pinPost(postId, btn){
  const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'pin_post',post_id:postId})});
  const data=await res.json();
  if(data.status==='success'){
    showToast(data.pinned?'📌 Post fijado':'Post desfijado');
    btn.style.color=data.pinned?'#f59e0b':'#3f3f46';
    // Move pinned post to top
    if(data.pinned){
      const card=document.getElementById('post-'+postId);
      if(card){
        // Add pin indicator
        if(!card.querySelector('.pin-badge'))card.insertAdjacentHTML('afterbegin','<div class="pin-badge" style="font-size:10px;font-weight:700;color:#f59e0b;margin-bottom:8px;display:flex;align-items:center;gap:5px"><i class="fa-solid fa-thumbtack" style="font-size:9px"></i> Post fijado</div>');
        document.getElementById('feed-posts').prepend(card);
      }
    }
  }
}
async function suggestPin(postId, btn){
  const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'suggest_pin',post_id:postId})});
  if((await res.json()).status==='success'){btn.style.color='#f59e0b';showToast('✓ Sugerencia enviada al admin');}
}

// ── OG LINK PREVIEW ───────────────────────────────────────
let ogPreviewTimeout=null;
let currentOgUrl='';

function detectLink(ta){
  const text=ta.value;
  const urlMatch=text.match(/https?:\/\/[^\s]+/);
  if(urlMatch&&urlMatch[0]!==currentOgUrl){
    currentOgUrl=urlMatch[0];
    clearTimeout(ogPreviewTimeout);
    ogPreviewTimeout=setTimeout(()=>fetchOgPreview(currentOgUrl),600);
  } else if(!urlMatch){
    currentOgUrl='';
    clearOgPreview();
  }
}

async function fetchOgPreview(url){
  const wrap=document.getElementById('og-preview-wrap');if(!wrap)return;
  wrap.innerHTML='<div style="display:flex;align-items:center;gap:8px;color:#52525b;font-size:11px"><i class="fa-solid fa-spinner fa-spin"></i> Cargando preview...</div>';
  wrap.style.display='block';
  try{
    const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'og_preview',url})});
    const d=await res.json();
    if(d.error||!d.title){wrap.style.display='none';return;}
    wrap.innerHTML=`<div style="display:flex;gap:10px;background:#18181b;border:1px solid #27272a;border-radius:8px;overflow:hidden;cursor:pointer" data-on-click="cmOpenUrl" data-click-1="j:${jsA(safeUrl(url))}">
      ${d.image?`<img src="${escH(d.image)}" style="width:80px;height:60px;object-fit:cover;flex-shrink:0" data-on-error="cmHide" data-error-1="this">`:'' }
      <div style="padding:8px;min-width:0">
        <div style="font-size:12px;font-weight:700;color:#e4e4e7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escH(d.title)}</div>
        ${d.description?`<div style="font-size:10px;color:#71717a;margin-top:2px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">${escH(d.description)}</div>`:''}
        <div style="font-size:9px;color:#52525b;margin-top:3px">${escH(url.slice(0,50))}...</div>
      </div>
      <button data-on-click="clearOgPreview|@stop" style="background:none;border:none;color:#52525b;cursor:pointer;padding:6px;flex-shrink:0;align-self:flex-start">✕</button>
    </div>`;
  }catch(e){wrap.style.display='none';}
}

function clearOgPreview(){
  const wrap=document.getElementById('og-preview-wrap');
  if(wrap){wrap.style.display='none';wrap.innerHTML='';}
  currentOgUrl='';
}
function toggleFullTeam(){teamExpanded=!teamExpanded;const full=document.getElementById('team-full'),compact=document.getElementById('team-compact'),btn=document.getElementById('team-toggle-btn');if(full)full.style.display=teamExpanded?'block':'none';if(compact)compact.style.display=teamExpanded?'none':'block';if(btn)btn.textContent=teamExpanded?'Mostrar menos':'Ver todos';}

async function previewAvatar(input){
  const file=input.files[0];if(!file)return;
  document.getElementById('my-avatar-display').innerHTML='<div style="width:64px;height:64px;border-radius:50%;background:#1f1f23;display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-spinner fa-spin" style="color:#ff6600"></i></div>';
  const r=new FileReader();r.onload=e=>{document.getElementById('my-avatar-display').innerHTML=`<img src="${e.target.result}" style="width:64px;height:64px;border-radius:50%;object-fit:cover">`;};r.readAsDataURL(file);
  const fd=new FormData();fd.append('action','save_profile');fd.append('avatar',await g7PrepImage(file,{max:1200}));fd.append('current_avatar',document.getElementById('current-avatar').value);fd.append('current_cover',document.getElementById('current-cover').value);fd.append('display_name',document.getElementById('pf-display')?.value||'');fd.append('position',document.getElementById('pf-position')?.value||'');fd.append('email',document.getElementById('pf-email')?.value||'');fd.append('phone',document.getElementById('pf-phone')?.value||'');fd.append('location',document.getElementById('pf-location')?.value||'');fd.append('bio',document.getElementById('pf-bio')?.value||'');fd.append('skills_json',JSON.stringify(skillValues));fd.append('instagram',document.getElementById('pf-instagram')?.value||'');fd.append('behance',document.getElementById('pf-behance')?.value||'');fd.append('linkedin',document.getElementById('pf-linkedin')?.value||'');fd.append('portfolio',document.getElementById('pf-portfolio')?.value||'');
  const res=await fetch('',{method:'POST',body:fd});const data=await res.json().catch(()=>null);if(data&&data.status==='success'&&data.avatar){document.getElementById('current-avatar').value=data.avatar;showToast('✓ Foto actualizada');}else showToast(g7UploadError(data));
}
async function previewCover(input){
  const file=input.files[0];if(!file)return;
  const r=new FileReader();r.onload=e=>{const el=document.getElementById('my-cover-img');if(el){el.src=e.target.result;}};r.readAsDataURL(file);
  const fd=new FormData();fd.append('action','save_profile');fd.append('cover',await g7PrepImage(file,{max:2400}));fd.append('current_avatar',document.getElementById('current-avatar').value);fd.append('current_cover',document.getElementById('current-cover').value);fd.append('display_name',document.getElementById('pf-display')?.value||'');fd.append('position',document.getElementById('pf-position')?.value||'');fd.append('email',document.getElementById('pf-email')?.value||'');fd.append('phone',document.getElementById('pf-phone')?.value||'');fd.append('location',document.getElementById('pf-location')?.value||'');fd.append('bio',document.getElementById('pf-bio')?.value||'');fd.append('skills_json',JSON.stringify(skillValues));fd.append('instagram',document.getElementById('pf-instagram')?.value||'');fd.append('behance',document.getElementById('pf-behance')?.value||'');fd.append('linkedin',document.getElementById('pf-linkedin')?.value||'');fd.append('portfolio',document.getElementById('pf-portfolio')?.value||'');
  const res=await fetch('',{method:'POST',body:fd});const data=await res.json().catch(()=>null);if(data&&data.status==='success'&&data.cover){document.getElementById('current-cover').value=data.cover;showToast('✓ Portada actualizada');}else showToast(g7UploadError(data));
}

function openEditModal(){document.getElementById('edit-modal').classList.add('open');}
function closeEditModal(){document.getElementById('edit-modal').classList.remove('open');}
async function saveProfile(){
  const fd=new FormData();fd.append('action','save_profile');
  ['display','position','email','phone','location','bio','instagram','behance','linkedin','portfolio'].forEach(f=>fd.append(f==='display'?'display_name':f,document.getElementById('pf-'+f)?.value||''));
  fd.append('skills_json',JSON.stringify(skillValues));fd.append('current_avatar',document.getElementById('current-avatar').value);fd.append('current_cover',document.getElementById('current-cover').value);
  const avf=document.getElementById('avatar-input').files[0];if(avf)fd.append('avatar',await g7PrepImage(avf,{max:1200}));
  const cvf=document.getElementById('cover-input').files[0];if(cvf)fd.append('cover',await g7PrepImage(cvf,{max:2400}));
  const res=await fetch('',{method:'POST',body:fd});const data=await res.json().catch(()=>null);
  if(!data||data.status!=='success'){showToast(g7UploadError(data,'No se pudo guardar el perfil'));return;}
  if(data.status==='success'){if(data.avatar)document.getElementById('current-avatar').value=data.avatar;if(data.cover)document.getElementById('current-cover').value=data.cover;const ok=document.getElementById('save-ok');ok.style.display='inline-flex';setTimeout(()=>{ok.style.display='none';closeEditModal();location.reload();},1500);}
}

function previewMyProfile(){openProfile(ME_ID);}

async function openProfile(uid){
  currentProfileId=uid;
  document.getElementById('pm-left').innerHTML='<div class="loading-pm"><i class="fa-solid fa-spinner fa-spin"></i></div>';
  document.getElementById('comments-list').innerHTML='<div class="loading-pm"><i class="fa-solid fa-spinner fa-spin"></i></div>';
  document.getElementById('new-comment').value='';
  document.getElementById('profile-modal').classList.remove('hidden');
  const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_profile',user_id:uid})});
  const data=await res.json();if(data.error)return;
  const {profile:p,content,comments,stats,posts}=data;
  const name=p.display_name||p.username;
  const col=['#ff6600','#a78bfa','#4ade80','#60a5fa','#f59e0b','#ec4899'][(name[0]||'a').charCodeAt(0)%6];
  const pmCover=document.getElementById('pm-cover');
  if(safeUrl(p.cover)){pmCover.style.backgroundImage=`url(${JSON.stringify(safeUrl(p.cover))})`;pmCover.style.backgroundSize='cover';pmCover.style.backgroundPosition='center';}
  else{pmCover.style.backgroundImage='';pmCover.style.background=`linear-gradient(135deg,${col}33,#0d0d0d)`;}
  const pmAv=document.getElementById('pm-av');pmAv.innerHTML=av({avatar:p.avatar,display_name:p.display_name,username:p.username},56);pmAv.style.cursor=p.avatar?'zoom-in':'default';pmAv.onclick=p.avatar?()=>openLightbox(p.avatar):null;
  let sj={};try{sj=JSON.parse(p.skills_json||'{}');}catch(e){}
  const selectedSkills=Object.keys(sj).filter(k=>sj[k]>0);
  const skillsHtml=selectedSkills.length?'<div class="sec-head"><i class="fa-solid fa-wrench" style="color:#ff6600"></i> Habilidades</div><div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:12px">'+selectedSkills.map(sk=>`<span style="display:inline-flex;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:600;background:rgba(255,102,0,.1);color:#ff6600;border:1px solid rgba(255,102,0,.25)">${escH(sk)}</span>`).join('')+'</div>':'';
  const socials=[];if(p.instagram)socials.push(`<a href="https://instagram.com/${encodeURIComponent(p.instagram.replace('@',''))}" target="_blank" class="sl sl-ig"><i class="fa-brands fa-instagram"></i>${escH(p.instagram)}</a>`);if(p.behance)socials.push(`<a href="https://behance.net/${encodeURIComponent(p.behance)}" target="_blank" class="sl sl-be"><i class="fa-brands fa-behance"></i>${escH(p.behance)}</a>`);if(p.linkedin)socials.push(`<a href="https://linkedin.com/${escH(p.linkedin.replace(/^https?:\/\/(www\.)?linkedin\.com\//i,''))}" target="_blank" class="sl sl-li"><i class="fa-brands fa-linkedin"></i>LinkedIn</a>`);if(/^https?:\/\//i.test(p.portfolio||''))socials.push(`<a href="${escH(p.portfolio)}" target="_blank" class="sl sl-web"><i class="fa-solid fa-globe"></i>Portfolio</a>`);
  const socialsHtml=socials.length?`<div class="social-wrap" style="margin-bottom:12px">${socials.join('')}</div>`:'';
  const fields=[p.email&&`<div class="pm-field"><i class="fa-solid fa-envelope"></i>${escH(p.email)}</div>`,p.phone&&`<div class="pm-field"><i class="fa-solid fa-phone"></i>${escH(p.phone)}</div>`,p.location&&`<div class="pm-field"><i class="fa-solid fa-location-dot"></i>${escH(p.location)}</div>`,p.loc&&p.loc.tz&&window.G7Time&&`<div class="pm-field pm-loc">${G7Time.locHtml(p.loc,p.id==ME_ID)}</div>`].filter(Boolean).join('');
  const contentCards=content.length?`<div class="sec-head"><i class="fa-solid fa-layer-group" style="color:#ff6600"></i> Contenido (${content.length})</div><div class="cm-scroll">${content.map(c=>`<a class="cm-mini" href="/toolz/promos/?search=${encodeURIComponent(c.name)}" target="_blank"><div class="ci">${c.img?`<img src="${c.img.startsWith('/')||c.img.startsWith('http')?c.img:'/toolz/promos/'+c.img}" data-on-error="cmHide" data-error-1="this">`:'<i class="fa-solid fa-tag" style="color:#2a2a2a;font-size:13px"></i>'}</div><div class="cb"><div class="cn">${escH(c.name)}</div><span class="ct" style="background:${TYPE_COLORS[c.type]||'#27272a'}22;color:${TYPE_COLORS[c.type]||'#71717a'}">${TYPE_LABELS[c.type]||c.type}</span></div></a>`).join('')}</div>`:'';
  const emojis={like:'👍',fire:'🔥',heart:'❤️',wow:'😮',clap:'👏'};
  const postsHtml=(posts||[]).length?`<div class="sec-head"><i class="fa-solid fa-rss" style="color:#ff6600"></i> Publicaciones</div>`+(posts||[]).map(post=>{const rm={};(post.reactions||[]).forEach(r=>rm[r.emoji]=r.cnt);const rH=Object.entries(emojis).map(([k,em])=>{const cnt=rm[k]||0,active=post.my_reaction===k;return`<button class="rxn-btn ${active?'active':''}" data-on-click="reactToPost" data-click-1="j:${post.id}" data-click-2="s:${k}" style="font-size:11px;padding:3px 7px">${em}${cnt>0?`<span class="rxn-cnt">${cnt}</span>`:''}</button>`;}).join('');return`<div style="background:#111;border:1px solid #1f1f23;border-radius:10px;padding:10px;margin-bottom:8px"><div style="font-size:13px;color:#e4e4e7;line-height:1.6;margin-bottom:6px">${linkifyPost(post.body)}</div>${post.img?`<img src="${escH(post.img)}" style="width:100%;max-height:150px;object-fit:cover;border-radius:7px;margin-bottom:8px;cursor:zoom-in" data-on-click="openLightbox" data-click-1="j:${jsA(post.img)}">`:''}<div style="display:flex;gap:5px;flex-wrap:wrap">${rH}</div></div>`;}).join(''):'';
  const isSelf=uid==ME_ID;
  // En qué trabaja y sus tareas en curso (trabajo/user_work_lib.php), con los permisos de quien mira; el dibujo es el del chat (chat/refs.js)
  const workHtml=(data.work&&window.CCRefs)?`<div class="sec-head"><i class="fa-solid fa-briefcase" style="color:#ff6600"></i> Trabajo</div><div style="margin-bottom:14px">${CCRefs.work(data.work,isSelf?'Tú':name.split(' ')[0])}</div>`:'';
  document.getElementById('pm-left').innerHTML=`<div class="pm-name">${escH(name)}${isSelf?'<span style="font-size:10px;background:rgba(255,102,0,.1);color:#ff6600;border-radius:4px;padding:2px 6px;margin-left:6px">Tú</span>':''}</div><div class="pm-pos">${p.position?`${escH(p.position)} · `:''}<span style="color:#52525b">@${escH(p.username)}</span> <span class="role-badge ${p.role==='admin'?'rb-admin':'rb-user'}" style="margin-left:4px">${p.role}</span></div><div class="pm-bio">${escH(p.bio||'Sin bio.')}</div>${socialsHtml}${workHtml}${skillsHtml}<div class="pm-stats"><div class="pm-stat"><div class="v">${stats.clicks}</div><div class="l">Clicks</div></div><div class="pm-stat"><div class="v">${stats.content}</div><div class="l">Contenido</div></div><div class="pm-stat"><div class="v">${stats.favs}</div><div class="l">Favoritos</div></div></div>${fields}${contentCards}${postsHtml}`;
  renderComments(comments);
}
function closeProfile(){document.getElementById('profile-modal').classList.add('hidden');currentProfileId=null;}

function renderComments(comments){
  const list=document.getElementById('comments-list');
  if(!comments.length){list.innerHTML='<div class="no-comments"><i class="fa-regular fa-comments" style="display:block;font-size:20px;margin-bottom:8px;opacity:.3"></i>Sin comentarios aún.</div>';return;}
  const roots=comments.filter(c=>!c.parent_id),kids={};comments.filter(c=>c.parent_id).forEach(c=>{(kids[c.parent_id]||(kids[c.parent_id]=[])).push(c);});
  list.innerHTML=roots.map(c=>renderComment(c,kids)).join('');
}
function renderComment(c,kids){
  const name=c.display_name||c.username,canDel=c.author_id==ME_ID||c.profile_id==ME_ID||IS_ADMIN,replies=kids[c.id]||[];
  const t=cmFmt(c.created_at,{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
  return`<div class="comment" id="comment-${c.id}"><div class="cbubble"><div class="ch">${av({avatar:c.avatar,display_name:c.display_name,username:c.username},22)}<span class="ca" data-on-click="openProfile" data-click-1="j:${c.author_id}">${escH(name)}</span><span class="role-badge ${c.role==='admin'?'rb-admin':'rb-user'}">${c.role}</span><span class="ct">${t}</span></div><div class="cb-text">${hlM(escH(c.body))}</div><div class="cactions"><button class="ca-btn" data-on-click="toggleCReply" data-click-1="j:${c.id}" data-click-2="j:${jsA(name)}"><i class="fa-solid fa-reply"></i> Responder</button>${canDel?`<button class="ca-btn del" data-on-click="deleteComment" data-click-1="j:${c.id}"><i class="fa-solid fa-trash-can"></i> Borrar</button>`:''}</div></div><div class="creply-form" id="crf-${c.id}"><div style="position:relative;flex:1"><div id="cdd-${c.id}" class="mention-dd" style="bottom:auto;top:calc(100% + 4px);left:0;right:0"></div><textarea class="ci-area small" id="crta-${c.id}" placeholder="@${escH(name)} ..." data-on-input="handleMentionInput" data-input-1="this" data-input-2="s:crta-${c.id}" data-input-3="s:cdd-${c.id}" rows="2"></textarea></div><button class="ci-send sm" data-on-click="submitReply" data-click-1="j:${c.id}"><i class="fa-solid fa-paper-plane"></i></button></div><div class="creplies" id="crep-${c.id}">${replies.map(r=>renderComment(r,kids)).join('')}</div></div>`;}
function toggleCReply(id,name){const f=document.getElementById('crf-'+id);f.classList.toggle('open');if(f.classList.contains('open')){const ta=document.getElementById('crta-'+id);ta.value='@'+name+' ';ta.focus();}}
async function submitComment(){const body=document.getElementById('new-comment').value.trim();if(!body||!currentProfileId)return;const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add_comment',profile_id:currentProfileId,parent_id:null,body})});const data=await res.json();if(data.status==='success'){document.getElementById('new-comment').value='';appendComment(data.comment,null);}}
async function submitReply(parentId){const ta=document.getElementById('crta-'+parentId),body=ta.value.trim();if(!body||!currentProfileId)return;const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add_comment',profile_id:currentProfileId,parent_id:parentId,body})});const data=await res.json();if(data.status==='success'){ta.value='';document.getElementById('crf-'+parentId).classList.remove('open');appendComment(data.comment,parentId);}}
function appendComment(c,parentId){const html=renderComment(c,{});if(parentId){let cont=document.getElementById('crep-'+parentId);if(!cont){cont=document.createElement('div');cont.className='creplies';cont.id='crep-'+parentId;document.getElementById('comment-'+parentId)?.appendChild(cont);}cont.insertAdjacentHTML('beforeend',html);}else{const list=document.getElementById('comments-list');list.querySelector('.no-comments')?.remove();list.insertAdjacentHTML('beforeend',html);list.scrollTop=list.scrollHeight;}}
async function deleteComment(id){if(!confirm('Eliminar?'))return;const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_comment',comment_id:id})});if((await res.json()).status==='success')document.getElementById('comment-'+id)?.remove();}
function handleMentionInput(ta,taId,ddId){const val=ta.value,pos=ta.selectionStart,before=val.slice(0,pos),match=before.match(/@(\w*)$/),dd=document.getElementById(ddId);if(!dd)return;if(match){const q=match[1].toLowerCase(),res=ALL_USERS.filter(u=>(u.username.toLowerCase().startsWith(q)||(u.display_name||'').toLowerCase().startsWith(q))&&u.id!=ME_ID).slice(0,5);if(res.length){dd.style.display='block';dd.innerHTML=res.map(u=>`<div class="mention-item" data-on-click="insertMention" data-click-1="j:${jsA(u.username)}" data-click-2="j:${jsA(taId)}" data-click-3="j:${jsA(ddId)}">${av(u,22)}<span style="font-size:12px;font-weight:600">${escH(u.display_name||u.username)}</span></div>`).join('');return;}}if(dd)dd.style.display='none';}
function insertMention(username,taId,ddId){const ta=document.getElementById(taId),dd=document.getElementById(ddId);if(!ta)return;const val=ta.value,pos=ta.selectionStart,before=val.slice(0,pos),atPos=before.lastIndexOf('@');ta.value=val.slice(0,atPos)+'@'+username+' '+val.slice(pos);ta.focus();const np=atPos+username.length+2;ta.setSelectionRange(np,np);if(dd)dd.style.display='none';}

let lastActivity=CM_CFG.lastActivity;

function renderActivityItem(a){
  const uname=a.display_name||a.username;
  const icon=TYPE_ICONS[a.type]||'<i class="fa-solid fa-circle-dot" style="color:#52525b"></i>';
  const txt=activityText(a);
  return`<div class="af-item" style="padding:10px 14px">
    <div style="cursor:pointer" data-on-click="openProfile" data-click-1="j:${a.uid}">${av({avatar:a.avatar,display_name:a.display_name,username:a.username},28)}</div>
    <div class="af-icon">${icon}</div>
    <div style="flex:1;min-width:0"><div class="af-text">${txt}</div><div class="af-time">${tagoAgo(a.created_at)} · ${cmFmt(a.created_at,{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'})}</div></div>
  </div>`;
}

async function pollActivity(){
  try{
    const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_activity'})});
    const data=await res.json();
    if(!data.activities?.length)return;
    const list=document.getElementById('activity-list');if(!list)return;
    const newerItems=data.activities.filter(a=>(a.created_at||'')>lastActivity);
    if(newerItems.length){
      newerItems.forEach(a=>{list.insertAdjacentHTML('afterbegin',renderActivityItem(a));});
      lastActivity=data.activities[0].created_at;
    }
  }catch(e){}
}

const TYPE_ICONS={
  'acceso_rapido':'<i class="fa-solid fa-arrow-up-right-from-square" style="color:#a78bfa"></i>',
  'content_create':'<i class="fa-solid fa-plus-circle" style="color:#4ade80"></i>',
  'post_create':'<i class="fa-solid fa-pen-nib" style="color:#4ade80"></i>',
  'link_open':'<i class="fa-solid fa-arrow-up-right-from-square" style="color:#60a5fa"></i>',
  'profile_update':'<i class="fa-solid fa-user-pen" style="color:#a78bfa"></i>',
  'focus_update':'<i class="fa-solid fa-crosshairs" style="color:#60a5fa"></i>',
  'status_update':'<i class="fa-solid fa-circle-dot" style="color:#f59e0b"></i>',
  'login':'<i class="fa-solid fa-right-to-bracket" style="color:#ff6600"></i>'
};
function activityText(a){
  const name=`<span class="user-link" data-on-click="openProfile" data-click-1="j:${a.uid}">${escH(a.display_name||a.username)}</span>`;
  const tool=escH(a.tool_name||a.target_name||'');
  const tn=escH(a.target_name||'');
  if(a.type==='acceso_rapido') return `${name} <span style="color:#71717a">usó</span> <span style="color:#a78bfa;font-weight:700">${tool}</span>`;
  if(a.type==='content_create') return `${name} creó <span class="hl" data-on-click="goToContent" data-click-1="j:${jsA(a.target_name||'')}">${tn}</span>`;
  if(a.type==='post_create') return `${name} publicó: <span style="color:#a1a1aa;font-style:italic">"${tn}"</span>`;
  if(a.type==='focus_update') return `${name} 🎯 trabajando en: <span style="color:#60a5fa">${tn}</span>`;
  if(a.type==='status_update') return `${name} ${{busy:'🟡 está en reunión',focus:'🔵 está concentrado',away:'⚫ está fuera'}[a.target_name]||'actualizó su estado'}`;
  if(a.type==='login') return `${name} inició sesión`;
  return `${name} ${tn}`;
}
setInterval(pollActivity,15000);
document.addEventListener('click',e=>{
  const pmTag = e.target.closest('.pm-tag');
  const promoTag = null;
  if(pmTag){ const uid=pmTag.dataset.uid; if(uid)openProfile(parseInt(uid)); return; }

  if(!e.target.closest('.comp-input')&&!e.target.closest('#post-mention-dd')){
    const dd=document.getElementById('post-mention-dd');if(dd)dd.style.display='none';
  }
});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeLightbox();closeProfile();closeEditModal();}});
const hashId=window.location.hash.match(/^#profile-(\d+)$/);if(hashId)openProfile(parseInt(hashId[1]));

// ── Antes código en línea (CSP estricta, SEC-24) ──
function cmPick(id){ document.getElementById(id).click(); }
function cmHide(el){ el.style.display = 'none'; }
function cmAvatarLb(url){ if (url) openLightbox(url); }
/** Enter manda la respuesta; Shift+Enter hace salto de línea. */
function cmReplyKey(e, id){ if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submitReplyToPost(id); } }
function cmEditBg(el, e){ if (e.target === el) closeEditModal(); }
function cmProfileBg(el, e){ if (e.target === el) closeProfile(); }
function cmNotifProfile(id){ if (id) openProfile(id); }
function cmOpenUrl(url){ window.open(url, '_blank'); }
// Imágenes que fallaron antes de que cargara este archivo
document.querySelectorAll('img[data-on-error="cmHide"]').forEach(i => { if (i.complete && i.naturalWidth === 0 && i.getAttribute('src')) cmHide(i); });
