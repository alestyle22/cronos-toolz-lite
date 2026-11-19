<?php
/* perfil_sesiones.php — Sección "Dónde iniciaste sesión" de Mi perfil (user_config.php): sesiones abiertas, mapa, intentos
   fallidos, dispositivos de confianza, historial con ubicación aproximada y, para superadmins, los ingresos del equipo.
   Habla con sesiones_api.php. Doc: toolz/SEGURIDAD_LOGIN.md */
// Abierta sola no es una página: se va a Mi perfil › Dónde iniciaste sesión
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { header('Location: /toolz/user_config.php#sesiones', true, 302); exit; }
?>
<style>
#sec-sesiones .ses-row{display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid var(--g7-border-2);transition:background .3s}
#sec-sesiones .ses-row:last-child{border-bottom:none}
#sec-sesiones .ses-ic{width:38px;height:38px;border-radius:50%;background:var(--g7-bg-4);display:grid;place-items:center;flex-shrink:0;color:var(--g7-text-3);font-size:15px;position:relative}
#sec-sesiones .ses-ic.on::after{content:'';position:absolute;right:0;bottom:1px;width:10px;height:10px;border-radius:50%;background:#22c55e;border:2px solid var(--g7-bg-3)}
#sec-sesiones .ses-ic.warn{background:rgba(239,68,68,.12);color:#ef4444}
#sec-sesiones .ses-main{flex:1;min-width:0}
#sec-sesiones .ses-t{font-size:13px;font-weight:600;display:flex;flex-wrap:wrap;align-items:center;gap:6px}
#sec-sesiones .ses-t small{font-weight:500;color:var(--g7-text-4);font-size:11.5px}
#sec-sesiones .ses-d{font-size:11.5px;color:var(--g7-text-4);line-height:1.5;margin-top:2px}
#sec-sesiones .ses-d b{color:var(--g7-text-3);font-weight:600}
#sec-sesiones .ses-now{color:#22c55e;font-weight:600}
#sec-sesiones .ses-tag{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:2px 6px;border-radius:5px;background:var(--g7-bg-5);color:var(--g7-text-3)}
#sec-sesiones .ses-tag.new{background:rgba(245,158,11,.15);color:#f59e0b}
#sec-sesiones .ses-tag.bad{background:rgba(239,68,68,.15);color:#ef4444}
#sec-sesiones .ses-tag.ok{background:rgba(34,197,94,.13);color:#22c55e}
#sec-sesiones .ses-st{font-size:11px;color:var(--g7-text-4);white-space:nowrap;text-align:right}
#sec-sesiones .ses-act{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;align-items:center}
#sec-sesiones .ses-act .btn,#sec-sesiones .ses-row>.btn{font-size:11.5px;padding:5px 10px}
#sec-sesiones .ses-pen{background:none;border:none;color:var(--g7-text-5);cursor:pointer;font-size:11px;padding:2px 4px;border-radius:5px}
#sec-sesiones .ses-pen:hover{color:var(--g7-accent);background:var(--g7-bg-4)}
#sec-sesiones .ses-name-in{background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:7px;padding:4px 8px;font:inherit;font-size:12.5px;width:190px;max-width:100%}
#sec-sesiones .ses-row.hl{background:rgba(var(--g7-accent-rgb),.09);box-shadow:inset 3px 0 0 var(--g7-accent)}
#sec-sesiones .ses-day{padding:8px 18px 6px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--g7-text-5);background:var(--g7-bg-2);border-bottom:1px solid var(--g7-border-2)}
#sec-sesiones .ses-foot{padding:12px 18px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;border-top:1px solid var(--g7-border-2)}
#sec-sesiones .ses-note{font-size:11px;color:var(--g7-text-5);line-height:1.5}
#sec-sesiones .ses-empty{padding:16px 18px;font-size:12.5px;color:var(--g7-text-4)}
#sec-sesiones .ses-review{border:1px solid rgba(var(--g7-accent-rgb),.35);background:linear-gradient(180deg,rgba(var(--g7-accent-rgb),.08),transparent 70%)}
#sec-sesiones .ses-review.bad{border-color:rgba(239,68,68,.35);background:linear-gradient(180deg,rgba(239,68,68,.08),transparent 70%)}
#sec-sesiones .ses-review h4{font-size:15px;margin:0 0 4px}
#sec-sesiones .ses-done{padding:14px 18px;font-size:13px;line-height:1.55}
#sec-sesiones .ses-map{height:260px;background:var(--g7-bg-2)}
#sec-sesiones .ses-map .leaflet-tile-pane{filter:invert(1) hue-rotate(180deg) brightness(.85) contrast(.9) saturate(.6)}
html.theme-light #sec-sesiones .ses-map .leaflet-tile-pane{filter:none}
#sec-sesiones .ses-map .leaflet-popup-content{font:12px/1.5 inherit;margin:10px 12px}
#sec-sesiones .ses-pin{width:14px;height:14px;border-radius:50%;background:var(--g7-accent);border:2px solid #fff;box-shadow:0 0 0 4px rgba(var(--g7-accent-rgb),.25)}
#sec-sesiones .ses-pin.bad{background:#ef4444;box-shadow:0 0 0 4px rgba(239,68,68,.3)}
#sec-sesiones .ses-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:12px 18px;border-bottom:1px solid var(--g7-border-2)}
#sec-sesiones .ses-filters select{background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:6px 10px;font:inherit;font-size:12px}
#sec-sesiones .ses-emerg{border-color:rgba(239,68,68,.3)}
#sec-sesiones .ses-emerg .cfg-card-head i{color:#ef4444}
#sec-sesiones .ses-sub{padding:10px 18px 6px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--g7-text-4);background:var(--g7-bg-2);border-bottom:1px solid var(--g7-border-2)}
#sec-sesiones .ses-blk{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:8px}
#sec-sesiones .ses-blk select{background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:7px;padding:4px 8px;font:inherit;font-size:11.5px}
#sec-sesiones .ses-blk .btn{font-size:11.5px;padding:4px 10px}
#sec-sesiones .ses-filters label{font-size:12px;color:var(--g7-text-3);display:flex;gap:6px;align-items:center}
@media (max-width:600px){#sec-sesiones .ses-row{flex-wrap:wrap}#sec-sesiones .ses-st{width:100%;text-align:left;padding-left:50px}#sec-sesiones .ses-map{height:200px}}
@media (prefers-reduced-motion:no-preference){#sec-sesiones .ses-row.hl{animation:sesPulse 1.6s ease-out 2}}
@keyframes sesPulse{0%{background:rgba(var(--g7-accent-rgb),.28)}100%{background:rgba(var(--g7-accent-rgb),.09)}}
</style>
<section class="cfg-section" id="sec-sesiones">
  <div class="cfg-section-title">Dónde iniciaste sesión</div>
  <div class="cfg-section-sub">Los dispositivos con tu cuenta abierta y cada ingreso, con su ubicación aproximada. Si ves uno que no reconoces, ciérralo y cambia tu contraseña.</div>
  <div id="ses-review"></div>
  <div class="cfg-card">
    <div class="cfg-card-head"><i class="fa-solid fa-laptop"></i><span>Sesiones abiertas</span></div>
    <div id="ses-active"><div class="ses-empty">Cargando…</div></div>
    <div class="ses-foot" id="ses-active-foot" style="display:none">
      <span class="ses-note">Cerrar una sesión la saca de la cuenta en ese dispositivo en segundos. Con el lápiz le pones un nombre al dispositivo.</span>
      <button class="btn btn-ghost" id="ses-end-all"><i class="fa-solid fa-right-from-bracket"></i> Cerrar todas las demás</button>
    </div>
  </div>
  <div class="cfg-card" id="ses-map-card" style="display:none">
    <div class="cfg-card-head"><i class="fa-solid fa-map-location-dot"></i><span>Mapa de ingresos</span></div>
    <div class="ses-map" id="ses-map"></div>
    <div class="ses-foot"><span class="ses-note">Los lugares de tus ingresos de los últimos 6 meses. En rojo, los que marcaste como "No fui yo".</span></div>
  </div>
  <div class="cfg-card" id="ses-failed-card" style="display:none">
    <div class="cfg-card-head"><i class="fa-solid fa-triangle-exclamation"></i><span>Intentos fallidos (últimas 48 h)</span></div>
    <div id="ses-failed"></div>
  </div>
  <div class="cfg-card" id="ses-trust-card" style="display:none">
    <div class="cfg-card-head"><i class="fa-solid fa-mobile-screen-button"></i><span>Dispositivos de confianza</span></div>
    <div id="ses-trust"></div>
    <div class="ses-foot">
      <span class="ses-note">Estos dispositivos no piden el código de verificación en dos pasos por 30 días. Se borran solos si cambias la contraseña o marcas un ingreso como "No fui yo".</span>
      <button class="btn btn-ghost" id="ses-trust-all">Quitar todos</button>
    </div>
  </div>
  <div class="cfg-card">
    <div class="cfg-card-head"><i class="fa-solid fa-clock-rotate-left"></i><span>Historial de inicios de sesión</span></div>
    <div id="ses-hist"><div class="ses-empty">Cargando…</div></div>
    <div class="ses-foot">
      <span class="ses-note">La ubicación sale de la IP: es aproximada y a veces muestra la ciudad de tu proveedor de internet (o la del VPN). Se guardan los últimos 6 meses.</span>
      <button class="btn btn-ghost" id="ses-more" style="display:none">Ver más</button>
    </div>
  </div>
  <div class="cfg-card ses-emerg">
    <div class="cfg-card-head"><i class="fa-solid fa-lock"></i><span>Emergencia</span></div>
    <div class="ses-row"><div class="ses-main"><div class="ses-t">Bloquear mi cuenta</div>
      <div class="ses-d">Si crees que alguien tiene tu contraseña: cierra todas las sesiones (también esta), quita los dispositivos de confianza y nadie puede entrar, ni con la contraseña correcta. Te llega un email para desbloquearla eligiendo una contraseña nueva.</div></div>
      <button class="btn btn-ghost" id="ses-lock-me" style="color:#ef4444;border-color:rgba(239,68,68,.4)"><i class="fa-solid fa-lock"></i> Bloquear mi cuenta</button></div>
  </div>
  <div class="cfg-card" id="ses-guard-card" style="display:none;margin-top:22px">
    <div class="cfg-card-head"><i class="fa-solid fa-shield-virus"></i><span>Superadmin · Bloqueos y alertas</span></div>
    <div id="ses-guard"></div>
  </div>
  <div class="cfg-card" id="ses-team-card" style="display:none">
    <div class="cfg-card-head"><i class="fa-solid fa-user-shield"></i><span>Superadmin · Ingresos del equipo</span></div>
    <div class="ses-filters">
      <select id="ses-tf-user" aria-label="Persona"><option value="">Todas las personas</option></select>
      <select id="ses-tf-cc" aria-label="País"><option value="">Todos los países</option></select>
      <label><input type="checkbox" id="ses-tf-alerts"> Solo con aviso</label>
      <button class="btn btn-ghost" id="ses-tf-endall" style="display:none;margin-left:auto"><i class="fa-solid fa-right-from-bracket"></i> Cerrar todas sus sesiones</button>
    </div>
    <div id="ses-team"><div class="ses-empty">Cargando…</div></div>
    <div class="ses-foot">
      <span class="ses-note">Sirve cuando alguien deja el equipo o pierde el teléfono. Cerrar todas sus sesiones también le quita los dispositivos de confianza.</span>
      <button class="btn btn-ghost" id="ses-team-more" style="display:none">Ver más</button>
    </div>
  </div>
</section>
<script src="/toolz/assets/perfil-sesiones.js?v=<?= (int)@filemtime(G7_SITE_ROOT . '/assets/perfil-sesiones.js') ?>"></script>
