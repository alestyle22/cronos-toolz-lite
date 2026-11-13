<?php
/* perfil_seguridad.php — Sección "Seguridad" de Mi perfil (user_config.php): verificación en dos pasos (TOTP).
   Habla con totp_api.php. Doc: toolz/SEGURIDAD_2FA.md */
?>
<style>#sec-seguridad .cfg-input{background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px;font:inherit;width:100%}</style>
<section class="cfg-section" id="sec-seguridad">
  <div class="cfg-section-title">Seguridad</div>
  <div class="cfg-section-sub">Verificación en dos pasos: además de la contraseña, al entrar se pide un código de una app del teléfono.</div>
  <div class="cfg-card">
    <div class="cfg-card-head"><i class="fa-solid fa-shield-halved"></i><span>Verificación en dos pasos</span></div>
    <div id="tf-box" style="padding:14px 16px;font-size:13px;line-height:1.55">Cargando…</div>
  </div>
  <div class="cfg-card" id="tf-admin" style="display:none;margin-top:16px">
    <div class="cfg-card-head"><i class="fa-solid fa-user-shield"></i><span>Superadmin</span></div>
    <div id="tf-admin-box" style="padding:14px 16px;font-size:13px;line-height:1.55"></div>
  </div>
</section>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU" crossorigin="anonymous"></script>
<script src="/toolz/assets/perfil-seguridad.js?v=<?= (int)@filemtime(G7_SITE_ROOT . '/assets/perfil-seguridad.js') ?>"></script>
