<?php
/* integraciones/perfil_google.php — Sección "Google Calendar" de Mi perfil (user_config.php). Solo aparece si la agencia
   activó Google Calendar en Admin › Integraciones. Habla con user_api.php. Doc: toolz/INTEGRACIONES.md */
?>
<section class="cfg-section" id="sec-google">
  <div class="cfg-section-title">Google Calendar</div>
  <div class="cfg-section-sub">Tus eventos de Toolz (visitas, turnos y los personales) se copian solos a tu Google Calendar.</div>
  <div class="cfg-card">
    <div class="cfg-card-head"><i class="fa-brands fa-google"></i><span>Tu cuenta de Google</span></div>
    <div id="gc-box" style="padding:14px 16px;font-size:13px">Cargando…</div>
  </div>
</section>
<script src="/toolz/integraciones/perfil-google.js?v=<?= (int)@filemtime(G7_SITE_ROOT . '/integraciones/perfil-google.js') ?>"></script>
