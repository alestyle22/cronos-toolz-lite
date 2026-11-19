/* assets/g7-tour.js — Recorrido del menú: ilumina cada herramienta de la barra y explica para qué sirve.
 * Arranca con ?g7tour=1 (el final del onboarding manda ahí) o desde el menú de usuario › "Recorrido del menú".
 * Los textos salen de TOUR_TEXT (por dirección) y, si no hay, de la descripción del módulo (g7_tool_catalog, que
 * topbar.php deja en #g7-tour-data). Usa el motor de g7-juice para sonido y festejo. Doc: toolz/MOVIMIENTO.md › Recorrido */
(function () {
  'use strict';
  const TOUR_TEXT = [
    [/^\/toolz\/?$|index\.php$/, 'Tu escritorio', 'Lo primero que ves al entrar: tus tareas en curso, lo que dejaste a medias y los accesos rápidos a todo.'],
    [/\/links\//, 'Recursos del equipo', 'Enlaces, guías y materiales ordenados por categoría. Lo que el equipo usa seguido, a mano.'],
    [/\/chat/, 'Mensajes', 'Canales, grupos y conversaciones privadas. Podés mandar emojis, archivos y armar tickets desde un mensaje.'],
    [/stats/, 'Números', 'Producción y métricas: cuánto se hizo, de qué y quién. Ideal para ver cómo viene el mes.'],
    [/\/promos\/?$|\/promos\//, 'Contenidos', 'Promos, lanzamientos y piezas de cada sitio. Se cargan con formulario y se siguen hasta publicarse.'],
    [/\/trabajo/, 'Trabajo', 'Tareas, tickets y proyectos en tableros y listas: quién hace qué y para cuándo.'],
    [/solicitudes/, 'Pedidos', 'Pedidos al equipo con formularios. Cada uno se sigue hasta que se resuelve.'],
    [/calendario/, 'Calendario', 'Fechas, eventos y vencimientos de todo el equipo en un solo lugar.'],
    [/tienda/, 'Tienda', 'Tu tienda online: productos, pedidos, pagos y el diseño de la vitrina.'],
    [/clientes/, 'Clientes', 'La ficha de cada cliente con sus datos, historial y lo que hay en curso.'],
    [/landing-gen/, 'Landing Gen', 'Landings de términos y condiciones con IA: elegís el sitio, pegás el texto y sale lista para publicar.'],
    [/mailingmaker/, 'Mailing Maker', 'Mailings y comunicaciones con la marca de cada sitio, listos para copiar o descargar.'],
    [/placas/, 'Placas', 'Placas para redes y pauta: armás el día o la campaña y descargás todas las piezas.'],
    [/linkgen/, 'Links', 'Enlaces correctos para el CMS de cada sitio en dos clics.'],
    [/webp/, 'Convertidor WebP', 'Achica imágenes a WebP con el peso que necesites, de a muchas a la vez.'],
    [/facturacion|pagos/, 'Cobros', 'Facturación y pagos: lo que se cobró, lo que falta y los recordatorios.'],
    [/resto/, 'Restaurant', 'Carta, pedidos, cocina, salón y caja del restaurante.'],
  ];
  const $ = s => document.querySelector(s);
  const J = () => window.G7Juice || null, F = () => (J() && J()._fx) || null;
  const moving = () => document.documentElement.classList.contains('g7-move');
  const SCALE = [523.3, 587.3, 659.3, 784, 880, 1046.5, 1174.7, 1318.5, 1568, 1760];
  const ding = k => { const f = F(), j = J(); if (!f || !j || !j.soundOn) return; const fr = SCALE[k % SCALE.length]; try { f.tone(fr, .14, { type: 'triangle', vol: .24 }); f.tone(fr * 1.5, .2, { type: 'sine', vol: .07, at: .04 }); } catch (e) {} };
  let data = {}; try { data = JSON.parse(($('#g7-tour-data') || {}).textContent || '{}'); } catch (e) {}

  // Pasos del escritorio (solo en el Inicio): cada parte con lo que se puede hacer ahí
  const secByTitle = re => [...document.querySelectorAll('main.d3 section')].find(s => re.test((s.querySelector('h2') || {}).textContent || ''));
  const DASH = [
    [() => document.querySelector('header.d3-head'), 'fa-solid fa-sun', 'Tu bienvenida', 'Cada día te saluda con la fecha y te cuenta lo importante, como lo que vence esta semana. El dibujo cambia con la hora.'],
    [() => document.querySelector('.d3-chips'), 'fa-solid fa-list-check', 'Tu día de un vistazo', 'Tareas, pedidos y avisos que te esperan. Tocá cualquiera para ir directo.'],
    [() => document.getElementById('d3-md-btn'), 'fa-solid fa-heart-pulse', '¿Cómo estás hoy?', 'Contale al equipo cómo llegás hoy. Es rápido y ayuda a que todos se entiendan mejor.'],
    [() => document.getElementById('d3-cust-btn'), 'fa-solid fa-sliders', 'Personalizar', 'Elegí qué secciones ver en tu Inicio y en qué orden. Lo podés cambiar cuando quieras.'],
    [() => document.querySelector('.d3-search'), 'fa-solid fa-magnifying-glass', 'Buscador', 'Escribí y las herramientas se filtran al instante. Atajo: la tecla /.'],
    [() => document.getElementById('d3-working'), 'fa-solid fa-person-digging', 'Tareas en curso', 'Lo que estás haciendo y lo que pediste, con su estado. Cambiá entre tus tareas, tus pedidos y los del equipo.'],
    [() => document.getElementById('d3-recent-sec'), 'fa-solid fa-clock-rotate-left', 'Sigue donde dejaste', 'Las últimas herramientas que usaste, para retomar en un clic.'],
    [() => secByTitle(/accesos/i), 'fa-solid fa-bolt', 'Accesos rápidos', 'Tus herramientas favoritas en grande. Se arman solas con lo que más usás.'],
    [() => secByTitle(/más herramientas|herramientas/i), 'fa-solid fa-grip', 'Todas tus herramientas', 'Todo lo que tenés habilitado, ordenado por grupo.'],
    [() => secByTitle(/vencen/i), 'fa-solid fa-hourglass-half', 'Vencen pronto', 'Las promos que terminan en los próximos días, para que nada se pase.'],
    [() => secByTitle(/resumen/i), 'fa-solid fa-chart-simple', 'Resumen', 'Los números del equipo y la actividad reciente.'],
    [() => secByTitle(/favorit/i), 'fa-solid fa-star', 'Tus favoritos', 'Los recursos de la biblioteca que marcaste con estrella.'],
  ];
  const isHome = () => !!document.querySelector('main.d3');
  function steps(){
    const out = [];
    if (isHome()) DASH.forEach(([find, icon, title, text]) => { const el = find(); if (el && el.offsetWidth > 0 && el.offsetHeight > 0) out.push({ el, label: 'Escritorio', icon, title, text, big: el.offsetHeight > 80 }); });
    const links = [...document.querySelectorAll('#g7-nav a.g7-link')].filter(a => a.offsetWidth > 0);
    links.forEach(a => {
      const href = new URL(a.getAttribute('href'), location.href).pathname, label = (a.querySelector('.g7-link-label') || a).textContent.trim();
      const hit = TOUR_TEXT.find(([re]) => re.test(href)), srv = data[href.replace(/\/$/, '')] || data[href] || null;
      out.push({ el: a, menu: true, label: 'Menú · ' + label, icon: (a.querySelector('i') || {}).className || 'fa-solid fa-circle', title: hit ? hit[1] : label, text: hit ? hit[2] : (srv && srv.desc) || 'Abrila desde acá cuando la necesites.' });
    });
    return out;
  }

  let S = null;
  function start(){
    if (S) return;
    const list = steps();
    if (!list.length){ mobileList(); return; }
    S = { list, i: -1 };
    const veil = document.createElement('div'); veil.className = 'g7tour-veil';
    const hole = document.createElement('div'); hole.className = 'g7tour-hole';
    const tip = document.createElement('div'); tip.className = 'g7tour-tip'; tip.setAttribute('role', 'dialog'); tip.setAttribute('aria-live', 'polite');
    document.body.append(veil, hole, tip); S.veil = veil; S.hole = hole; S.tip = tip;
    veil.addEventListener('click', () => go(S.i + 1));
    S.key = e => { if (!S) return; if (e.key === 'ArrowRight' || e.key === 'Enter'){ e.preventDefault(); go(S.i + 1); } else if (e.key === 'ArrowLeft'){ e.preventDefault(); go(S.i - 1); } else if (e.key === 'Escape') end(false); };
    addEventListener('keydown', S.key, true);
    S.re = () => S && place();
    addEventListener('resize', S.re); addEventListener('scroll', S.re, true);
    intro();
  }
  function intro(){
    const t = S.tip; S.i = -1;
    t.className = 'g7tour-tip center';
    t.innerHTML = `<div class="g7tour-big"><i class="fa-solid fa-compass"></i></div><h3>Conocé tus herramientas</h3><p>${S.list.some(x => !x.menu) ? `Te muestro tu escritorio y el menú en ${S.list.length} pasos.` : `Te muestro en ${S.list.length} pasos para qué sirve cada cosa del menú.`} Toma un minuto.</p>
      <div class="g7tour-nav"><button type="button" class="g7tour-skip">Ahora no</button><button type="button" class="g7tour-next">Empezar <i class="fa-solid fa-arrow-right"></i></button></div>`;
    S.hole.style.opacity = '0';
    t.querySelector('.g7tour-next').onclick = () => go(0); t.querySelector('.g7tour-skip').onclick = () => end(false);
    if (moving()) t.animate([{ opacity: 0, transform: 'translate(-50%,-50%) scale(.8)' }, { opacity: 1, transform: 'translate(-50%,-50%) scale(1)' }], { duration: 420, easing: 'cubic-bezier(.34,1.56,.64,1)' });
    const f = F(); if (f) f.play('open', 0, true);
    t.querySelector('.g7tour-next').focus();
  }
  function go(i){
    if (!S) return;
    if (i < 0) return intro();
    if (i >= S.list.length) return end(true);
    const back = i < S.i; S.i = i; const st = S.list[i];
    S.list.forEach(x => x.el.classList.remove('g7tour-on')); if (st.menu) st.el.classList.add('g7tour-on');
    // lo del escritorio se centra en la pantalla; la burbuja se reacomoda cuando termina de moverse
    if (!st.menu){ const rr = st.el.getBoundingClientRect(); if (rr.top < 70 || rr.bottom > innerHeight - 40){ st.el.scrollIntoView({ block: rr.height > innerHeight * .6 ? 'start' : 'center', behavior: moving() ? 'smooth' : 'auto' }); setTimeout(place, 420); setTimeout(place, 800); } }
    else if (scrollY > 0) window.scrollTo({ top: 0, behavior: moving() ? 'smooth' : 'auto' });
    const t = S.tip; t.className = 'g7tour-tip';
    t.innerHTML = `<span class="g7tour-arrow"></span>
      <div class="g7tour-head"><span class="g7tour-ico"><i class="${st.icon}"></i></span><div><small>${i + 1} de ${S.list.length} · ${esc(st.label)}</small><h3>${esc(st.title)}</h3></div></div>
      <p>${esc(st.text)}</p>
      <div class="g7tour-dots">${S.list.map((_, k) => `<i class="${k < i ? 'done' : k === i ? 'on' : ''}"></i>`).join('')}</div>
      <div class="g7tour-nav"><button type="button" class="g7tour-skip">Saltar</button><span></span>${i ? '<button type="button" class="g7tour-back"><i class="fa-solid fa-arrow-left"></i></button>' : ''}<button type="button" class="g7tour-next">${i === S.list.length - 1 ? '¡Listo! <i class="fa-solid fa-flag-checkered"></i>' : 'Siguiente <i class="fa-solid fa-arrow-right"></i>'}</button></div>`;
    t.querySelector('.g7tour-next').onclick = () => go(i + 1); t.querySelector('.g7tour-skip').onclick = () => end(false);
    const b = t.querySelector('.g7tour-back'); if (b) b.onclick = () => go(i - 1);
    S.hole.style.opacity = '1';
    place();
    ding(i); const f = F(); if (f && back) f.play('rewind', 0, true);
    if (moving()){
      t.animate([{ opacity: 0, transform: `translateY(10px) scale(.94)` }, { opacity: 1, transform: 'none' }], { duration: 360, easing: 'cubic-bezier(.34,1.56,.64,1)' });
      if (st.menu) st.el.animate([{ scale: '1' }, { scale: '1.12' }, { scale: '1' }], { duration: 420, easing: 'ease-out' });
      const ic = t.querySelector('.g7tour-ico'); if (ic) ic.animate([{ rotate: '-25deg', scale: '.5' }, { rotate: '8deg', scale: '1.15', offset: .6 }, { rotate: '0deg', scale: '1' }], { duration: 520, easing: 'cubic-bezier(.34,1.56,.64,1)' });
    }
    t.querySelector('.g7tour-next').focus({ preventScroll: true });
  }
  function place(){
    if (!S || S.i < 0) return;
    const st = S.list[S.i], el = st.el, r = el.getBoundingClientRect(), pad = st.menu ? 6 : 10;
    Object.assign(S.hole.style, { left: (r.left - pad) + 'px', top: (r.top - pad) + 'px', width: (r.width + pad * 2) + 'px', height: (r.height + pad * 2) + 'px' });
    const t = S.tip, tw = Math.min(330, innerWidth - 24); t.style.width = tw + 'px';
    const cx = r.left + r.width / 2, left = Math.max(12, Math.min(innerWidth - tw - 12, cx - tw / 2));
    // abajo si entra; si no, arriba; si tampoco (sección muy alta), adentro, abajo a la derecha
    const th = t.offsetHeight || 190, below = r.bottom + 16 + th < innerHeight, above = r.top - 16 - th > 64;
    const ar = t.querySelector('.g7tour-arrow');
    t.classList.toggle('up', !below && above); t.classList.toggle('in', !below && !above);
    if (below) t.style.top = (r.bottom + 16) + 'px';
    else if (above) t.style.top = (r.top - 16 - th) + 'px';
    else { t.style.top = Math.max(76, Math.min(innerHeight - th - 16, r.bottom - th - 16)) + 'px'; }
    t.style.left = (!below && !above ? Math.max(12, Math.min(innerWidth - tw - 12, r.right - tw - 16)) : left) + 'px';
    if (ar) ar.style.left = Math.max(18, Math.min(tw - 18, cx - left)) + 'px';
  }
  function end(done){
    if (!S) return;
    const { veil, hole, tip, list } = S;
    list.forEach(x => x.el.classList.remove('g7tour-on'));
    removeEventListener('keydown', S.key, true); removeEventListener('resize', S.re); removeEventListener('scroll', S.re, true);
    S = null;
    const out = () => { veil.remove(); hole.remove(); tip.remove(); };
    if (moving()){ [veil, hole, tip].forEach(n => n.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 260, fill: 'forwards' })); setTimeout(out, 270); } else out();
    try { localStorage.setItem('g7-tour-seen', '1'); } catch (e) {}
    if (done){ const j = J(); if (j) j.celebrate('big', '¡Listo para arrancar!', 'fa-rocket'); }
    else { const f = F(); if (f) f.play('close', 0, true); }
    // sacar ?g7tour de la dirección
    try { const u = new URL(location.href); if (u.searchParams.has('g7tour')){ u.searchParams.delete('g7tour'); history.replaceState(null, '', u.pathname + (u.search || '') + u.hash); } } catch (e) {}
  }
  // En el celular el menú está escondido: una lista con todo
  function mobileList(){
    const items = [...document.querySelectorAll('#g7-mobile-nav a.g7-link')].map(a => { const href = new URL(a.getAttribute('href'), location.href).pathname, hit = TOUR_TEXT.find(([re]) => re.test(href)); return { label: a.textContent.trim(), icon: (a.querySelector('i') || {}).className || '', text: hit ? hit[2] : ((data[href.replace(/\/$/, '')] || {}).desc || '') }; });
    if (!items.length) return;
    const t = document.createElement('div'); t.className = 'g7tour-tip center g7tour-list';
    t.innerHTML = `<h3>Tus herramientas</h3><ul>${items.map(x => `<li><span class="g7tour-ico"><i class="${x.icon}"></i></span><div><b>${esc(x.label)}</b><small>${esc(x.text)}</small></div></li>`).join('')}</ul><div class="g7tour-nav"><span></span><button type="button" class="g7tour-next">¡Entendido!</button></div>`;
    const veil = document.createElement('div'); veil.className = 'g7tour-veil solid'; document.body.append(veil, t);
    t.querySelector('.g7tour-next').onclick = () => { veil.remove(); t.remove(); const j = J(); if (j) j.celebrate('medium', '¡Listo para arrancar!', 'fa-rocket'); };
  }
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  window.G7Tour = { start };
  // Menú de usuario: "Recorrido del menú"
  document.addEventListener('click', e => { const a = e.target.closest && e.target.closest('[data-g7-tour]'); if (!a) return; e.preventDefault(); document.querySelector('.g7-um')?.classList.remove('open'); setTimeout(start, 120); });
  if (new URLSearchParams(location.search).has('g7tour')) addEventListener('load', () => setTimeout(start, 700));
})();
