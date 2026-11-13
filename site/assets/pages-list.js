/* assets/pages-list.js — Lista de landings de pages.php, sin código en línea (CSP estricta, SEC-24). */
const U='/toolz/pages.php';
async function newPage(){
  const t=prompt('Nombre:','Nueva Landing');if(!t?.trim())return;
  const r=await fetch(U,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save',title:t.trim(),slug:'',published:1,bg_type:'gradient',bg_value:'linear-gradient(135deg,#0b1622,#1e293b)',primary_color:'#f04e23',cta_text:'Deposita ahora',cta_url:'#',cta_bottom_text:'',cta_bottom_url:'#',hero_img_url:'',logo_url:'',subtitle:'',badge_text:'',bottom_title:'',bottom_subtitle:'',terms:[],bg_position:'center',bg_size:'cover',show_dot_grid:1,hero_glow_color:'#f04e23',hero_glow_opacity:0.2,hero_gradient_left:'rgba(0,90,180,0.18)',hero_gradient_right:'rgba(240,78,35,0.10)',footer_bg:'linear-gradient(135deg,#0d1e31,#0b1622)',footer_glow_color:'#f04e23',show_phone_frame:1,phone_frame_color:'#0d1b2a'})});
  const d=await r.json();
  if(d.ok)location.href='pages.php?edit='+d.id;
  else alert('Error: '+(d.error||'?'));
}
async function delPage(id,btn){
  if(!confirm('¿Eliminar?'))return;
  const r=await fetch(U,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id})});
  const d=await r.json();if(d.ok)btn.closest('.card').remove();
}
async function dupPage(id){
  const r=await fetch(U,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'dup',id})});
  const d=await r.json();if(d.ok)location.href='pages.php?edit='+d.id;
}

function pgGo(url){ location.href=url; }
