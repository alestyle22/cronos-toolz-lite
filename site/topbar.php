<?php
error_reporting(0);
ini_set('display_errors', 0);
// /toolz/topbar.php — include this in every page AFTER requireLogin()
// ── LOAD USER THEME PREFS ─────────────────────────────────
$_tb_db = $db; // use the already-open DB connection
if (!isset($_tb_theme_loaded)) {
    $_tb_theme_loaded = true;
    try {
        $_tb_pref_q = $_tb_db->prepare("SELECT pref_key,pref_value FROM g7_user_prefs WHERE user_id=? AND pref_key IN ('theme','accent_color','font_size')");
        $_tb_pref_q->execute([$currentUser['id']]);
        $_tb_prefs = [];
        foreach($_tb_pref_q->fetchAll() as $_tb_r) {
            $_tb_prefs[$_tb_r['pref_key']] = json_decode($_tb_r['pref_value'], true);
        }
    } catch(Exception $_tb_e) { $_tb_prefs = []; }
    // Sin preferencia propia: valores por defecto del admin (Configuración › Apariencia)
    $_tb_theme      = $_tb_prefs['theme']        ?? (function_exists('g7_setting') ? g7_setting('default_theme') : 'dark');
    $_tb_accent     = $_tb_prefs['accent_color'] ?? (function_exists('g7_setting') ? g7_setting('accent_color') : '#ff6600');
    $_tb_font_size  = $_tb_prefs['font_size']    ?? (function_exists('g7_setting') ? g7_setting('default_font_size') : 'medium');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$_tb_accent)) $_tb_accent = '#ff6600';
    // Load system notification config
    try {
        $_tb_sys_q = $_tb_db->query("SELECT cfg_key,cfg_value FROM g7_config WHERE cfg_key IN ('buzz_enabled','notif_sound_enabled','notif_sound_type','notif_beeps_default')");
        $_tb_sys = [];
        foreach($_tb_sys_q->fetchAll() as $_sc) $_tb_sys[$_sc['cfg_key']] = $_sc['cfg_value'];
    } catch(Exception $e) { $_tb_sys = []; }
    $_tb_buzz_enabled  = ($_tb_sys['buzz_enabled']  ?? '1') === '1';
    $_tb_notif_enabled = ($_tb_sys['notif_sound_enabled'] ?? '1') === '1';
    $_tb_notif_type    = $_tb_sys['notif_sound_type'] ?? 'beep';
    $_tb_beeps_default = (int)($_tb_sys['notif_beeps_default'] ?? 3);
    // Load gamification notifications
    $_tb_gamif_notifs = [];
    try {
        if (function_exists('g7GetPendingNotifs') && isset($_tb_db)) {
            $_tb_gamif_notifs = g7GetPendingNotifs($_tb_db, (int)$_tb_me['id']);
        }
    } catch(Exception $e) { $_tb_gamif_notifs = []; }
    // Apply theme class and accent CSS var to <html>
    $tb_js_theme  = htmlspecialchars($_tb_theme, ENT_QUOTES);
    $tb_js_font   = htmlspecialchars($_tb_font_size, ENT_QUOTES);
    $tb_js_accent = json_encode($_tb_accent);
    // Echo CSS vars inline so they work even without g7-theme.css in <head>
    $accent_r = hexdec(substr($_tb_accent,1,2));
    $accent_g = hexdec(substr($_tb_accent,3,2));
    $accent_b = hexdec(substr($_tb_accent,5,2));
    echo '<style>:root{--g7-accent:'.$_tb_accent.';--g7-accent-rgb:'.$accent_r.','.$accent_g.','.$accent_b.';--g7-accent-muted:rgba('.$accent_r.','.$accent_g.','.$accent_b.',.12);--g7-accent-border:rgba('.$accent_r.','.$accent_g.','.$accent_b.',.28)}</style>';
    echo '<script src="/toolz/assets/theme-boot.js?v=1" data-theme="' . $tb_js_theme . '" data-font="' . $tb_js_font . '" data-accent="' . htmlspecialchars((string)$_tb_accent, ENT_QUOTES) . '" data-rgb="' . $accent_r . ',' . $accent_g . ',' . $accent_b . '"></script>';
    echo '<script src="/toolz/assets/g7-on.js?v=' . (int)@filemtime(__DIR__ . '/assets/g7-on.js') . '"></script>';   // eventos sin código en línea (data-on-…)
    // Fechas en la zona de cada persona (tz_lib.php + assets/g7-time.js, ZONAS_HORARIAS.md)
    if (function_exists('g7_tz_js_config')) echo '<script src="/toolz/assets/g7-time.js?v=' . (int)@filemtime(__DIR__ . '/assets/g7-time.js') . '" data-cfg="' . htmlspecialchars(json_encode(g7_tz_js_config()), ENT_QUOTES) . '"></script>';
}
// Usage: include __DIR__ . '/topbar.php'; (or '/../topbar.php' from subfolders)
// Requires $currentUser and $isAdmin to be defined.
$_tb_avatar = function($av, $name, $size) {
    if ($av) return "<img src=\"$av\" style=\"width:{$size}px;height:{$size}px;border-radius:50%;object-fit:cover;flex-shrink:0\">";
    $i = strtoupper(substr($name,0,1));
    $c = ['#ff6600','#a78bfa','#4ade80','#60a5fa','#f59e0b','#ec4899'][ord($i)%6];
    $fs = floor($size*.4);
    return "<div style=\"width:{$size}px;height:{$size}px;border-radius:50%;background:{$c};display:flex;align-items:center;justify-content:center;font-size:{$fs}px;font-weight:700;color:#000;flex-shrink:0\">{$i}</div>";
};
$_tb_me = $currentUser;
$_tb_meRow = $_tb_db->prepare('SELECT avatar,display_name FROM g7_users WHERE id=?');
$_tb_meRow->execute([$_tb_me['id']]); $_tb_meData = $_tb_meRow->fetch();
$_tb_avatar_html = $_tb_avatar($_tb_meData['avatar']??'', $_tb_meData['display_name']?:$_tb_me['username'], 26);

// Monday urgent tasks count (cached)
$_tb_monday_urgent = 0;
$_tb_monday_overdue = 0;
$_tb_monday_cache = defined('MONDAY_CACHE') ? MONDAY_CACHE : __DIR__ . '/monday_cache.json';
if (file_exists($_tb_monday_cache)) {
    $_tb_mdata = json_decode(file_get_contents($_tb_monday_cache), true);
    $now = time();
    foreach (($_tb_mdata['items'] ?? []) as $_tb_t) {
        $dl = $_tb_t['daysLeft'] ?? null;
        if ($dl !== null && $dl < 0) $_tb_monday_overdue++;
        elseif ($dl !== null && $dl <= 2) $_tb_monday_urgent++;
    }
}
$_tb_monday_alert = $_tb_monday_overdue + $_tb_monday_urgent;
if (function_exists('g7_href_visible') && !g7_href_visible((int)($currentUser['id'] ?? 0), '/toolz/monday.php')) $_tb_monday_alert = 0;
?>
<?php if(function_exists('vocab_js') && !defined('G7_TERMS_JS')){define('G7_TERMS_JS',1);echo vocab_js();}   // window.G7_TERMS y g7Term() para el JS de cada página ?>
<?php if(!defined('G7_CSS_LOADED')){define('G7_CSS_LOADED',1);echo '<link rel="stylesheet" href="/toolz/g7-theme.css?v='.@filemtime(__DIR__.'/g7-theme.css').'">';} ?>
<?php if(!defined('G7_SHELL_CSS')){define('G7_SHELL_CSS',1);echo '<link rel="stylesheet" href="/toolz/g7-shell.css?v='.@filemtime(__DIR__.'/g7-shell.css').'">';} ?>


<?php
  $cur = $_SERVER['REQUEST_URI'];
  // Load nav config from DB BEFORE rendering HTML
  $_tb_nav_cfg = null;
  $_tb_nav_align = 'center';
  $_tb_logo_url = null;
  try {
    if (function_exists('g7_nav_add_once') && (g7_modules()['calendario']['mode'] ?? 'off') !== 'off') { try { g7_nav_add_once('nav_calendario_added', ['href' => '/toolz/calendario/', 'icon' => 'fa-calendar-days', 'label' => 'Calendario', 'enabled' => true], '/toolz/trabajo/'); } catch (Throwable $e) {} }
    // Portal de Solicitudes aparte (2026-11-13): el link aparece una vez al lado de Trabajo; el admin lo puede sacar del menú
    if (function_exists('g7_nav_add_once') && (g7_modules()['solicitudes']['mode'] ?? 'off') !== 'off') { try { g7_nav_add_once('nav_solicitudes_added', ['href' => '/toolz/solicitudes/', 'icon' => 'fa-inbox', 'label' => 'Solicitudes', 'enabled' => true], '/toolz/trabajo/'); } catch (Throwable $e) {} }
    // Juegos (2026-11-11): en agencias que ya eran de Videojuegos (o compraron el extra) el link y el acceso del Inicio aparecen solos, una vez
    if (function_exists('g7_jg_enabled') && g7_jg_enabled() && g7_cfg('jg_links_added') !== '1') { try {
        g7_nav_add_once('nav_juegos_v1', ['href' => '/toolz/juegos/', 'icon' => 'fa-gamepad', 'label' => 'Juegos', 'enabled' => true], '/toolz/clientes.php');
        $_tb_hc = g7_cfg_json('home_config', null);
        if (is_array($_tb_hc) && is_array($_tb_hc['tiles'] ?? null) && !in_array('/toolz/juegos/', array_column($_tb_hc['tiles'], 'href'), true)) {
            $_tb_hc['tiles'][] = ['id' => 'juegos', 'href' => '/toolz/juegos/', 'icon' => 'fa-gamepad', 'name' => 'Juegos', 'desc' => 'Hitos, playtests, Steam y mercado', 'color' => '#f97316', 'badge' => '', 'newtab' => false, 'on' => true, 'audience' => 'all', 'teams' => []];
            g7_cfg_set('home_config', json_encode($_tb_hc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        g7_cfg_set('jg_links_added', '1');
    } catch (Throwable $e) {} }
    $_tb_cfg_q = $_tb_db->query("SELECT cfg_key,cfg_value FROM g7_config WHERE cfg_key IN ('nav_links','nav_align','logo_url','platform_name')");
    foreach($_tb_cfg_q->fetchAll() as $_c) {
      if ($_c['cfg_key']==='nav_links') $_tb_nav_cfg = json_decode($_c['cfg_value'],true);
      if ($_c['cfg_key']==='nav_align') $_tb_nav_align = !empty($_c['cfg_value']) ? $_c['cfg_value'] : 'center';
      if ($_c['cfg_key']==='logo_url')  $_tb_logo_url = !empty($_c['cfg_value']) ? $_c['cfg_value'] : null;
    }
  } catch(Exception $e) {}
?>
<div class="g7-topbar" id="g7-topbar">
  <a href="/toolz/" style="display:flex;align-items:center;flex-shrink:0"><?php if($_tb_logo_url): ?><img src="<?= htmlspecialchars($_tb_logo_url) ?>" alt="Logo" class="g7-logo"><?php else: ?><img src="<?= htmlspecialchars(g7_site('logo')) ?>" alt="Logo" class="g7-logo"><?php endif; ?></a>
  <button class="g7-hamburger" data-g7="mobile-nav" id="g7-hamburger" aria-label="Menu"><i class="fa-solid fa-bars"></i></button>
  <?php
  // ── Selector de marca (Admin › Marcas) ──
  $_tb_uid = (int)($currentUser['id'] ?? 0);
  if (function_exists('g7_brands_enabled') && g7_brands_enabled() && g7_setting_on('brand_switcher')):
    $_tb_bAllowed = g7_user_brand_ids($_tb_uid);
    $_tb_bList = array_values(array_filter(g7_brands(), fn($b) => $_tb_bAllowed === null || in_array($b['id'], $_tb_bAllowed, true)));
    $_tb_bScope = g7_brand_scope($_tb_uid);
    $_tb_bSel = ($_tb_bScope !== null && count($_tb_bScope) === 1 && count($_tb_bList) > 1) ? $_tb_bScope[0] : 0;
    if ($_tb_bList):
  ?>
  <form method="POST" action="/toolz/brand.php" class="g7-brand-switch" title="Marca con la que estás trabajando">
    <input type="hidden" name="back" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/toolz/') ?>">
    <i class="fa-solid fa-tag" style="font-size:10px;color:<?php $__c='#a1a1aa'; foreach($_tb_bList as $__b) if($__b['id']===$_tb_bSel) $__c=$__b['color']; echo htmlspecialchars($__c); ?>"></i>
    <?php if (count($_tb_bList) === 1): ?>
      <span><?= htmlspecialchars($_tb_bList[0]['name']) ?></span>
    <?php else: ?>
    <select name="brand_id" data-g7-autosubmit aria-label="Marca">
      <option value="0"><?= $_tb_bAllowed === null ? 'Todas las marcas' : 'Todas mis marcas' ?></option>
      <?php foreach ($_tb_bList as $__b): ?><option value="<?= (int)$__b['id'] ?>" <?= $__b['id'] === $_tb_bSel ? 'selected' : '' ?>><?= htmlspecialchars($__b['name']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
  </form>
  <style>.g7-brand-switch{display:flex;align-items:center;gap:6px;background:var(--g7-bg-4,var(--g7-bg-4));border:1px solid var(--g7-border-3,var(--g7-border-3));border-radius:8px;padding:0 4px 0 9px;height:30px;font-size:11px;font-weight:700;color:var(--g7-text-2,var(--g7-text));margin-left:10px;flex-shrink:0}
  .g7-brand-switch span{padding-right:6px}.g7-brand-switch select{background:transparent;border:none;color:inherit;font:inherit;outline:none;cursor:pointer;max-width:150px}.g7-brand-switch select option{background:var(--g7-bg-4);color:var(--g7-text)}</style>
  <?php endif; endif; ?>

  <div id="g7-nav" class="g7-nav g7-nav-<?= htmlspecialchars($_tb_nav_align) ?>">
  <?php

  // Default links
  $_tb_default_links = [
    ['href'=>'/toolz/',             'icon'=>'fa-house',         'label'=>'Home',      'enabled'=>true],
    ['href'=>'/toolz/links/',       'icon'=>'fa-book-bookmark', 'label'=>'Biblioteca','enabled'=>true],
    ['href'=>'/toolz/comunidad.php','icon'=>'fa-people-group',  'label'=>'Comunidad', 'enabled'=>true],
    ['href'=>'/toolz/stock.php',    'icon'=>'fa-photo-film',    'label'=>'Stock',     'enabled'=>true],
    ['href'=>'/toolz/chat_page.php','icon'=>'fa-comment-dots',  'label'=>'Chat',      'enabled'=>true],
    ['href'=>'/toolz/stats.php',    'icon'=>'fa-chart-line',    'label'=>'Stats',     'enabled'=>true],
    ['href'=>'/toolz/status.php',   'icon'=>'fa-triangle-exclamation', 'label'=>'Status', 'enabled'=>true],
    ['href'=>'/toolz/todos.php',    'icon'=>'fa-list-check',           'label'=>'To-Do',  'enabled'=>true],
    ['href'=>'/toolz/svg_converter.php', 'icon'=>'fa-vector-square',  'label'=>'SVG→PNG','enabled'=>true],
    ['href'=>'/toolz/imghost.php',       'icon'=>'fa-cloud-arrow-up', 'label'=>'ImgHost', 'enabled'=>true],
    ['href'=>'/toolz/promos/',      'icon'=>'fa-layer-group',   'label'=>'Promos',    'enabled'=>true],
    ['href'=>'/toolz/trabajo/',     'icon'=>'fa-table-columns', 'label'=>'Trabajo',   'enabled'=>true, 'badge'=>'tasks'],
    ['href'=>'/toolz/solicitudes/', 'icon'=>'fa-inbox',         'label'=>'Solicitudes','enabled'=>true],
    ['href'=>'/toolz/planify.php',  'icon'=>'fa-calendar-days', 'label'=>'Planify',   'enabled'=>true],
    ['href'=>'/toolz/webpmaker.php','icon'=>'fa-image',         'label'=>'WebP',      'enabled'=>true],
    ['href'=>'/toolz/monday.php',   'icon'=>'fa-check-square',  'label'=>'Monday',    'enabled'=>true, 'badge'=>true],
  ];
  if ($isAdmin) $_tb_default_links[] = ['href'=>'/toolz/users.php','icon'=>'fa-users','label'=>'Usuarios','enabled'=>true];

  $links = $_tb_nav_cfg ?? $_tb_default_links;
  // Inject monday badge into links
  foreach($links as &$_l) { if(($_l['badge']??false)===true) $_l['badge']=$_tb_monday_alert??0; }
  unset($_l);
  foreach($links as &$_l) {
    if(($_l['badge']??null)==='tasks') {
      $_l['badge'] = 0;
      if (is_file(__DIR__.'/tareas_lib.php')) { require_once __DIR__.'/tareas_lib.php'; $_l['badge'] = tk_my_alert_count($_tb_db, (int)$_tb_me['id']); }
    }
  }
  unset($_l);
  // Vencimientos: el barrido (genera vencimientos y crea las tareas a tiempo) corre con cualquier página, como mucho cada 5 minutos
  if (function_exists('g7_ven_enabled') && g7_site_has('clientes') && g7_ven_enabled() && is_file(__DIR__.'/vencimientos/lib.php') && time() - (int)g7_cfg('ven_sweep_at', 0) >= 300) {
    try { require_once __DIR__.'/vencimientos/lib.php'; ven_install($_tb_db); ven_maybe_sweep($_tb_db); } catch (Throwable $e) { error_log('[vencimientos] ' . $e->getMessage()); }
  }
  // Turnos: recordatorios por email del día siguiente (como mucho cada 10 minutos)
  if (function_exists('g7_ap_enabled') && g7_site_has('clientes') && g7_ap_enabled() && is_file(__DIR__.'/turnos/lib.php') && time() - (int)g7_cfg('ap_remind_at', 0) >= 600) {
    try { require_once __DIR__.'/turnos/lib.php'; ap_install($_tb_db); ap_maybe_remind($_tb_db); } catch (Throwable $e) { error_log('[turnos] ' . $e->getMessage()); }
  }
  // Cobros: los abonos recurrentes arman su borrador el día que toca (como mucho cada 30 minutos)
  if (g7_site_has('clientes') && is_file(__DIR__.'/facturacion/cobros_lib.php') && (int)g7_cfg('fb_schema', 0) && time() - (int)g7_cfg('fb_rec_at', 0) >= 1800) {
    try { require_once __DIR__.'/facturacion/cobros_lib.php'; fb_rec_maybe_run($_tb_db); } catch (Throwable $e) { error_log('[cobros] ' . $e->getMessage()); }
  }
  // Integraciones (Admin › Integraciones): copia a Google Calendar cada 10 minutos y métricas de YouTube una vez por día
  if ((is_file(__DIR__.'/_private/integrations.php') || is_file(__DIR__.'/_private/integrations.json')) && is_file(__DIR__.'/integraciones/gcal.php') && time() - (int)g7_cfg('gc_sync_at', 0) >= 600) {
    try { require_once __DIR__.'/integraciones/gcal.php'; gc_maybe_sync($_tb_db); } catch (Throwable $e) { error_log('[gcal] ' . $e->getMessage()); }
  }
  if ((is_file(__DIR__.'/_private/integrations.php') || is_file(__DIR__.'/_private/integrations.json')) && is_file(__DIR__.'/integraciones/youtube.php') && function_exists('g7_tl_enabled') && g7_tl_enabled() && time() - (int)g7_cfg('yt_refresh_at', 0) >= 86400) {
    try { require_once __DIR__.'/integraciones/youtube.php'; yt_maybe_refresh($_tb_db); } catch (Throwable $e) { error_log('[youtube] ' . $e->getMessage()); }
  }
  // Rubro: si Clientes o Contenidos tienen otro nombre (vocab_lib.php), el menú lo usa, salvo que el admin le haya puesto uno propio distinto de los de fábrica
  if (function_exists('g7_term')) { $_tb_voc = g7_cfg_json('vocab', []);
    foreach ($links as &$_l) {
      if (rtrim((string)$_l['href'], '/') === '/toolz/clientes.php' && isset($_tb_voc['client']) && in_array($_l['label'], ['Clientes', 'Cliente'], true)) $_l['label'] = htmlspecialchars(g7_term('client', 'p'));
      if (rtrim((string)$_l['href'], '/') === '/toolz/promos' && isset($_tb_voc['content']) && in_array($_l['label'], ['Promos', 'Contenidos', 'Promos CMS'], true)) $_l['label'] = htmlspecialchars(g7_term('content', 'p'));
    } unset($_l); }
  // Filter disabled
  // Ocultar lo apagado y los módulos a los que este usuario no tiene acceso
  $_tb_uid = (int)($currentUser['id'] ?? 0);
  $links = array_filter($links, fn($l)=>($l['enabled']??true) && (!function_exists('g7_href_visible') || g7_href_visible($_tb_uid, (string)$l['href'])));
  foreach ($links as $l):
    $active = rtrim($cur,'/')=== rtrim($l['href'],'/') ? 'active' : '';
    $badge  = $l['badge'] ?? 0;
  ?>
    <a href="<?= $l['href'] ?>" class="g7-link <?= $active ?>">
      <i class="fa-solid <?= $l['icon'] ?>" style="font-size:11px"></i>
      <span class="g7-link-label"><?= $l['label'] ?></span>
      <?php if ($badge > 0): ?>
      <span class="g7-nav-badge"><?= $badge > 9 ? '9+' : $badge ?></span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
  </div>

  <div class="g7-spacer"></div>

  <!-- Buscador de herramientas (Ctrl/Cmd + K) -->
  <button type="button" class="g7-launch" data-g7="accesos" title="Buscar herramienta (Ctrl + K)">
    <i class="fa-solid fa-magnifying-glass" style="font-size:11px"></i><span class="g7-launch-label">Buscar herramienta</span><kbd>Ctrl K</kbd>
  </button>

  <?php if($_tb_buzz_enabled): ?>
  <button type="button" data-g7="call" class="g7-btn-icon g7-btn-ping" title="Enviar un ping a alguien del equipo">
    <i class="fa-solid fa-satellite-dish" style="font-size:12px"></i>
  </button>
  <?php endif; ?>

  <?php if (is_file(__DIR__ . '/soporte/index.php')): $_tb_from = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH); ?>
  <!-- SOPORTE: reportar un problema o sugerir una mejora (llega como ticket al Cronos principal) -->
  <a class="g7-btn-icon" href="/toolz/soporte/<?= str_starts_with($_tb_from, '/toolz/soporte') ? '' : '?from=' . rawurlencode($_tb_from) ?>" title="Soporte: reportar un problema o sugerir una mejora" aria-label="Soporte"><i class="fa-regular fa-life-ring" style="font-size:12px"></i></a>
  <?php endif; ?>

  <!-- BELL -->
  <div class="notif-wrap">
    <button class="g7-btn-icon notif-btn" data-g7="notif" id="notif-btn" title="Notificaciones">
      <i class="fa-solid fa-bell" style="font-size:12px"></i>
      <span class="notif-badge" id="notif-badge"></span>
    </button>
    <div class="notif-dropdown" id="notif-dropdown">
      <div class="nd-head">
        <span class="nd-title">Notificaciones</span>
        <button class="nd-mark" data-g7="read-all">Marcar todo como leído</button>
      </div>
      <div class="nd-list" id="nd-list">
        <div class="nd-empty">Sin notificaciones</div>
      </div>
    </div>
  </div>

  <!-- Menú de usuario -->
  <div class="g7-um" id="g7-um">
    <button type="button" class="g7-um-btn" data-g7="user-menu" aria-haspopup="true" aria-expanded="false">
      <?= $_tb_avatar_html ?>
      <span class="g7-um-name"><?= htmlspecialchars($_tb_meData['display_name']?:$_tb_me['username']) ?></span>
      <i class="fa-solid fa-chevron-down"></i>
    </button>
    <div class="g7-um-menu" role="menu">
      <div class="g7-um-head">
        <b><?= htmlspecialchars($_tb_meData['display_name']?:$_tb_me['username']) ?></b>
        <span>@<?= htmlspecialchars($_tb_me['username'] ?? '') ?> · <?= $isAdmin ? 'Admin' : 'Usuario' ?></span>
      </div>
      <a class="g7-um-item" href="/toolz/user_config.php" role="menuitem"><i class="fa-solid fa-user"></i> Mi perfil y preferencias</a>
      <?php if($isAdmin): ?><a class="g7-um-item" href="/toolz/admin.php" role="menuitem"><i class="fa-solid fa-shield-halved"></i> Panel de administración</a><?php endif; ?>
      <div class="g7-um-sep"></div>
      <form method="POST" action="/toolz/logout.php" style="margin:0">
        <button class="g7-um-item danger" type="submit" role="menuitem"><i class="fa-solid fa-right-from-bracket"></i> Cerrar sesión</button>
      </form>
    </div>
  </div>
</div>

<?php
// ── Anuncio global (Admin › Configuración › Anuncio global) ──
if (function_exists('g7_setting') && g7_setting_on('announce_enabled') && trim((string)g7_setting('announce_text')) !== ''):
  $_tb_aud = (string)g7_setting('announce_audience');
  if ($_tb_aud === 'all' || $_tb_aud === '' || in_array((int)$_tb_aud, g7_ctx($_tb_uid)['teams'], true)):
    $_tb_lvl = in_array(g7_setting('announce_level'), ['info','success','warning','danger'], true) ? g7_setting('announce_level') : 'info';
    $_tb_acol = ['info'=>'#60a5fa','success'=>'#22c55e','warning'=>'#f59e0b','danger'=>'#ef4444'][$_tb_lvl];
    $_tb_aico = ['info'=>'fa-circle-info','success'=>'fa-circle-check','warning'=>'fa-triangle-exclamation','danger'=>'fa-bullhorn'][$_tb_lvl];
    $_tb_alink = trim((string)g7_setting('announce_link'));
    $_tb_aver = htmlspecialchars((string)g7_cfg('announce_version', '1'));
?>
<div id="g7-announce" data-v="<?= $_tb_aver ?>" style="display:flex;align-items:center;gap:10px;padding:9px 18px;font-size:12px;line-height:1.45;background:<?= $_tb_acol ?>1a;border-bottom:1px solid <?= $_tb_acol ?>40;color:var(--g7-text,#e4e4e7);position:relative;z-index:150">
  <i class="fa-solid <?= $_tb_aico ?>" style="color:<?= $_tb_acol ?>;flex-shrink:0"></i>
  <div style="flex:1;white-space:pre-line"><?= htmlspecialchars((string)g7_setting('announce_text')) ?><?php if ($_tb_alink && preg_match('#^(https?://|/)#', $_tb_alink)): ?> <a href="<?= htmlspecialchars($_tb_alink) ?>" style="color:<?= $_tb_acol ?>;font-weight:700;margin-left:6px">Ver más →</a><?php endif; ?></div>
  <?php if (g7_setting_on('announce_dismissible')): ?><button type="button" data-g7="announce-close" title="Cerrar" style="background:none;border:none;color:var(--g7-text-4,var(--g7-text-2));cursor:pointer;font-size:14px">✕</button>
<?php endif; ?>
</div>
<?php endif; endif; ?>
<?php if (function_exists('lic_banner') && ($_lb = lic_banner())): /* aviso de licencia: por vencer, en gracia o solo lectura */ ?>
<div class="g7-lic-banner" role="status" style="position:relative;z-index:40;display:flex;gap:10px;align-items:center;justify-content:center;flex-wrap:wrap;padding:8px 16px;font-size:12.5px;font-weight:600;text-align:center;<?= $_lb[0] === 'bad' ? 'background:rgba(239,68,68,.14);color:#fecaca;border-bottom:1px solid rgba(239,68,68,.35)' : 'background:rgba(251,191,36,.12);color:#fde68a;border-bottom:1px solid rgba(251,191,36,.3)' ?>">
  <span><?= htmlspecialchars($_lb[1]) ?></span><?php if (($currentUser['role'] ?? '') === 'admin'): ?><a href="/toolz/admin.php#license" style="color:inherit;text-decoration:underline">Ver licencia</a><?php endif; ?>
</div>
<?php endif; ?>

<!-- MOBILE NAV DRAWER -->
<?php
$_links_mob = $links ?? [];
$_cur_mob   = $cur ?? $_SERVER['REQUEST_URI'];
?>
<nav class="g7-mobile-nav" id="g7-mobile-nav">
  <?php foreach($_links_mob as $_ml):
    $_ma = rtrim($_cur_mob,'/')===rtrim($_ml['href'],'/') ? 'active' : '';
    $_mb = $_ml['badge'] ?? 0;
  ?>
  <a href="<?= $_ml['href'] ?>" class="g7-link <?= $_ma ?>">
    <i class="fa-solid <?= $_ml['icon'] ?>" style="font-size:12px;width:16px;text-align:center"></i>
    <?= $_ml['label'] ?>
    <?php if ($_mb > 0): ?><span class="g7-nav-badge" style="position:static;margin-left:auto"><?= $_mb>9?'9+':$_mb ?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>
  <div class="g7-mobile-nav-sep"></div>
  <div class="g7-mobile-actions">
    <button data-g7="accesos-mobile" class="g7-btn-accesos"><i class="fa-solid fa-magnifying-glass" style="font-size:11px"></i> Buscar herramienta</button>
    <?php if(isset($_tb_buzz_enabled)&&$_tb_buzz_enabled): ?><button data-g7="call-mobile" class="g7-btn-success-ghost"><i class="fa-solid fa-bell" style="font-size:11px"></i> Zumbido</button><?php endif; ?>
  </div>
</nav>


<?php
  // Aviso del navegador (Tareas › Mis avisos): encendido salvo que el usuario lo apague
  $_tb_bn = true;
  try { $_tb_bq = $_tb_db->prepare("SELECT pref_value FROM g7_user_prefs WHERE user_id=? AND pref_key='tasks_notif'"); $_tb_bq->execute([(int)$_tb_me['id']]);
        $_tb_bv = json_decode((string)$_tb_bq->fetchColumn(), true); if (is_array($_tb_bv) && isset($_tb_bv['browser'])) $_tb_bn = (bool)$_tb_bv['browser']; } catch(Exception $e) {}
?>
<?php $tbUsers = $_tb_db->query("SELECT id,username,display_name,avatar FROM g7_users WHERE active=1 AND id!=".(int)$_tb_me['id']." ORDER BY username")->fetchAll(); ?>
<?php echo '<script type="application/json" id="g7-topbar-cfg">' . json_encode(['notifSound' => (bool)$_tb_notif_enabled, 'notifType' => (string)$_tb_notif_type, 'callUsers' => $tbUsers, 'myId' => (int)$_tb_me['id'], 'browserNotif' => (bool)$_tb_bn, 'beeps' => (int)$_tb_beeps_default], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>'; ?>
<script src="/toolz/assets/topbar.js?v=<?= (int)@filemtime(__DIR__ . '/assets/topbar.js') ?>"></script>
<?php // OpenCloud (Admin › Integraciones): botón para adjuntar en los cuadros de mensaje y carpeta del cliente. Solo si está conectado.
try { if (is_file(__DIR__ . '/integraciones/opencloud.php')) { require_once __DIR__ . '/integraciones/opencloud.php';
  if (oc_ready()): ?><link rel="stylesheet" href="/toolz/assets/opencloud.css?v=<?= (int)@filemtime(__DIR__ . '/assets/opencloud.css') ?>"><script src="/toolz/assets/opencloud.js?v=<?= (int)@filemtime(__DIR__ . '/assets/opencloud.js') ?>" defer></script>
<?php endif; } } catch (Throwable $e) {} ?>

<style>#call-send-btn:not(:disabled):hover{background:rgba(34,197,94,.25)!important}.g7-call-user{display:flex;align-items:center;gap:10px;padding:11px 16px;cursor:pointer;border-bottom:1px solid #141414;background:transparent;transition:background .15s}.g7-call-user:hover{background:rgba(255,255,255,.02)}.g7-call-user.sel,.g7-call-user.sel:hover{background:rgba(255,102,0,.06)}</style>
<!-- GAMIFICATION TOASTS -->


<div id="g7-gamif-container" style="position:fixed;bottom:24px;right:24px;display:flex;flex-direction:column-reverse;gap:10px;z-index:99999"></div>

<?php if(!empty($_tb_gamif_notifs)): ?><script type="application/json" id="g7-gamif-data"><?= json_encode($_tb_gamif_notifs, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script><?php endif; ?>

<!-- CALL MODAL -->
<div id="call-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:9000;align-items:center;justify-content:center;padding:20px">
  <div style="background:var(--g7-bg-3);border:1px solid var(--g7-border-3);border-radius:16px;width:100%;max-width:380px;overflow:hidden" data-g7-stop>
    <div style="padding:16px 20px;border-bottom:1px solid var(--g7-border-2);display:flex;align-items:center;justify-content:space-between">
      <div style="font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px">
        <i class="fa-solid fa-bell" style="color:#4ade80"></i> Zumbido
      </div>
      <button data-g7="call-close" style="background:none;border:none;color:var(--g7-text-3);font-size:17px;cursor:pointer">✕</button>
    </div>
    <div style="padding:10px 0;max-height:320px;overflow-y:auto" id="call-user-list"></div>
    <div style="padding:12px 16px;border-top:1px solid var(--g7-border-2)">
      <div style="font-size:9px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px">Mensaje rápido</div>
      <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:10px">
        <button data-g7="preset" data-msg="Estamos en una llamada" data-beeps="5" class="preset-btn" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-2);border-radius:8px;padding:9px 12px;font-size:11px;font-weight:600;cursor:pointer;text-align:left;display:flex;align-items:center;gap:8px;transition:all .15s">
          <span style="font-size:14px">📞</span>
          <span style="flex:1">Estamos en una llamada</span>
          <span style="font-size:9px;color:var(--g7-text-4);background:var(--g7-bg-3);border-radius:4px;padding:2px 6px">5 pitidos</span>
        </button>
        <button data-g7="preset" data-msg="Respondeme el mensaje" data-beeps="3" class="preset-btn" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-2);border-radius:8px;padding:9px 12px;font-size:11px;font-weight:600;cursor:pointer;text-align:left;display:flex;align-items:center;gap:8px;transition:all .15s">
          <span style="font-size:14px">💬</span>
          <span style="flex:1">Respondeme el mensaje</span>
          <span style="font-size:9px;color:var(--g7-text-4);background:var(--g7-bg-3);border-radius:4px;padding:2px 6px">3 pitidos</span>
        </button>
        <button data-g7="preset" data-msg="🚨 URGENTE, SE INCENDIA TODO" data-beeps="30" class="preset-btn" style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:#f87171;border-radius:8px;padding:9px 12px;font-size:11px;font-weight:700;cursor:pointer;text-align:left;display:flex;align-items:center;gap:8px;transition:all .15s">
          <span style="font-size:14px">🚨</span>
          <span style="flex:1">URGENTE, SE INCENDIA TODO</span>
          <span style="font-size:9px;color:#ef4444;background:rgba(239,68,68,.15);border-radius:4px;padding:2px 6px">10 pitidos</span>
        </button>
        <button data-g7="preset" data-msg="" data-beeps="0" class="preset-btn" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-3);border-radius:8px;padding:7px 12px;font-size:11px;cursor:pointer;text-align:left;display:flex;align-items:center;gap:8px;transition:all .15s">
          <span style="font-size:14px">✏️</span>
          <span style="flex:1">Mensaje personalizado</span>
        </button>
      </div>
      <input id="call-message" type="text" placeholder="Escribe un mensaje..." style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 11px;font-size:12px;outline:none;margin-bottom:10px;display:none" data-g7-enter="send-call">
      <input type="hidden" id="call-beeps" value="<?= (int)$_tb_beeps_default ?>">
      <button id="call-send-btn" data-g7="send-call" disabled style="width:100%;background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.3);color:#4ade80;border-radius:8px;padding:10px;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s;display:flex;align-items:center;justify-content:center;gap:6px">
        <i class="fa-solid fa-bell"></i> <span id="call-send-label">Enviar zumbido</span>
      </button>
    </div>
  </div>
</div>
<?php if(isset($currentUser) && (!function_exists('g7_can') || g7_can((int)$currentUser['id'], 'chat'))) include __DIR__ . '/chat/widget.php'; ?>

<!-- BUSCADOR DE HERRAMIENTAS (Ctrl/Cmd + K) -->
<?php
$_tb_pal = [];
$_tb_uidp = (int)($currentUser['id'] ?? 0);
try {
    $_tb_rq = $db->prepare("SELECT tool, MAX(accessed_at) t FROM g7_accesos_rapidos WHERE user_id=? GROUP BY tool ORDER BY t DESC LIMIT 12");
    $_tb_rq->execute([$_tb_uidp]);
    $_tb_recent_tools = array_column($_tb_rq->fetchAll(), 'tool');
} catch (Exception $e) { $_tb_recent_tools = []; }
if (function_exists('g7_tool_catalog')) {
    $_tb_cat = g7_tool_catalog($_tb_uidp);
    $_tb_rank = [];
    foreach ($_tb_recent_tools as $i => $_tb_t) { $_tb_mm = g7_module_for_href($_tb_t); if ($_tb_mm && isset($_tb_cat[$_tb_mm]) && !isset($_tb_rank[$_tb_mm])) $_tb_rank[$_tb_mm] = $i; }
    foreach ($_tb_cat as $_tb_t) $_tb_pal[] = ['n' => $_tb_t['label'], 'd' => $_tb_t['desc'], 'i' => $_tb_t['icon'], 'u' => $_tb_t['url'], 'g' => isset($_tb_rank[$_tb_t['id']]) ? 'Recientes' : $_tb_t['group'], 'r' => $_tb_rank[$_tb_t['id']] ?? 99];
}
$_tb_pal[] = ['n' => 'Inicio', 'd' => 'Volver al inicio', 'i' => 'fa-house', 'u' => '/toolz/', 'g' => 'Cuenta', 'r' => 99];
$_tb_pal[] = ['n' => 'Mi perfil', 'd' => 'Datos, tema y preferencias', 'i' => 'fa-user', 'u' => '/toolz/user_config.php', 'g' => 'Cuenta', 'r' => 99];
if ($isAdmin) $_tb_pal[] = ['n' => 'Panel de administración', 'd' => 'Usuarios, accesos, marcas y ajustes', 'i' => 'fa-shield-halved', 'u' => '/toolz/admin.php', 'g' => 'Cuenta', 'r' => 99];
?>
<div class="g7-pal-bg" id="accesos-modal-bg" data-g7="accesos-bg" aria-hidden="true">
  <div class="g7-pal" role="dialog" aria-label="Buscar herramienta">
    <div class="g7-pal-search">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input id="g7-pal-q" type="text" placeholder="¿Qué quieres abrir?" autocomplete="off" spellcheck="false">
      <kbd>Esc</kbd>
    </div>
    <div class="g7-pal-list" id="g7-pal-list"></div>
    <div class="g7-pal-foot"><span><kbd>↑</kbd><kbd>↓</kbd>moverse</span><span><kbd>Enter</kbd>abrir</span><span><kbd>Ctrl K</kbd>abrir o cerrar</span></div>
  </div>
</div>
<script type="application/json" id="g7-pal-data"><?= json_encode($_tb_pal, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="/toolz/assets/topbar-pal.js?v=<?= (int)@filemtime(__DIR__ . '/assets/topbar-pal.js') ?>"></script>




