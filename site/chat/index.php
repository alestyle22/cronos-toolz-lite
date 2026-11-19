<?php
/**
 * chat/ — Chat corporativo (mensajes directos, grupos y canales). La pantalla la arma chat.js con chat/api.php.
 * ?c=ID abre una conversación; ?m=ID lleva a un mensaje.
 */
require_once __DIR__ . '/lib.php';
$currentUser = requireLogin();
require_once dirname(__DIR__) . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo: esta página no tiene código en línea (SEC-24)
$isAdmin = ($currentUser['role'] === 'admin');
$db = getDB(); // topbar.php usa $db y $isAdmin
ch_install();
$v = fn($f) => @filemtime(__DIR__ . '/' . $f);
define('CH_PAGE_IS_CHAT', 1);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,viewport-fit=cover">
<title>Chat · <?= htmlspecialchars(g7_setting('platform_name')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha384-blOohCVdhjmtROpu8+CfTnUWham9nkX7P7OZQMst+RUnhtoY/9qemFAkIKOYxDI3" crossorigin="anonymous">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="chat.css?v=<?= $v('chat.css') ?>">
<link rel="stylesheet" href="refs.css?v=<?= $v('refs.css') ?>">
<link rel="stylesheet" href="chat2.css?v=<?= $v('chat2.css') ?>">
<link rel="stylesheet" href="chat-plus.css?v=<?= $v('chat-plus.css') ?>">
<link rel="stylesheet" href="/toolz/assets/cm-kudos.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/cm-kudos.css') ?>">
</head>
<body>
<?php include __DIR__ . '/../topbar.php'; ?>

<div class="cc" id="cc" aria-label="Chat">
  <nav class="cc-rail" aria-label="Secciones del chat">
    <div class="cc-logo" aria-hidden="true"><i class="fa-solid fa-comments"></i></div>
    <button class="cc-rb on" data-view="home" data-tip="Inicio" aria-label="Inicio"><i class="fa-solid fa-house"></i><span class="cc-dotn" id="cc-n-home" hidden></span></button>
    <button class="cc-rb" data-view="dms" data-tip="Mensajes directos" aria-label="Mensajes directos"><i class="fa-solid fa-user-group"></i><span class="cc-dotn" id="cc-n-dms" hidden></span></button>
    <button class="cc-rb" data-view="people" data-tip="Personas: todo el equipo" aria-label="Personas"><i class="fa-solid fa-address-book"></i></button>
    <button class="cc-rb" data-view="channels" data-tip="Canales" aria-label="Canales"><i class="fa-solid fa-hashtag"></i></button>
    <button class="cc-rb" data-view="activity" data-tip="Actividad" aria-label="Actividad"><i class="fa-regular fa-bell"></i><span class="cc-dotn" id="cc-n-act" hidden></span></button>
    <button class="cc-rb" data-view="search" data-tip="Buscar (Ctrl+Shift+F)" aria-label="Buscar"><i class="fa-solid fa-magnifying-glass"></i></button>
    <button class="cc-rb" data-view="saved" data-tip="Guardados" aria-label="Guardados"><i class="fa-regular fa-bookmark"></i></button>
    <button class="cc-rb" data-view="files" data-tip="Archivos compartidos" aria-label="Archivos compartidos"><i class="fa-solid fa-folder-open"></i></button>
    <button class="cc-rb" data-view="apps" data-tip="Herramientas: recordatorios, programados, anuncios" aria-label="Herramientas"><i class="fa-solid fa-table-cells-large"></i></button>
    <div class="cc-rail-sp"></div>
    <a class="cc-rb" data-href="admin" href="admin.php" data-tip="Administración del chat" aria-label="Administración del chat" hidden><i class="fa-solid fa-shield-halved"></i></a>
    <button class="cc-rb" data-view="settings" data-tip="Configuración" aria-label="Configuración"><i class="fa-solid fa-gear"></i></button>
    <button class="cc-rb cc-me" id="cc-me" data-tip="Tu estado" aria-label="Tu estado"></button>
  </nav>

  <section class="cc-list" id="cc-list" aria-label="Conversaciones"></section>

  <main class="cc-main" id="cc-main">
    <div class="cc-welcome" id="cc-welcome">
      <div>
        <div class="ic"><i class="fa-solid fa-comments"></i></div>
        <h3>Elige una conversación</h3>
        <p>Escribile a alguien del equipo, arma un grupo o súmate a un canal. Con <b>Ctrl+K</b> buscas cualquier conversación.</p>
        <div class="row">
          <button class="cc-btn pri" data-act="new-dm"><i class="fa-solid fa-pen-to-square"></i> Nuevo mensaje</button>
          <button class="cc-btn" data-act="browse"><i class="fa-solid fa-hashtag"></i> Ver canales</button>
        </div>
      </div>
    </div>
  </main>

  <aside class="cc-aside" id="cc-aside" aria-label="Detalles"></aside>
</div>
<div class="cc-toasts" id="cc-toasts" role="status" aria-live="polite"></div>

<script src="/toolz/assets/g7-imgprep.js?v=1"></script>
<script src="emoji.js?v=<?= $v('emoji.js') ?>"></script>
<script src="refs.js?v=<?= $v('refs.js') ?>"></script>
<script src="chat.js?v=<?= $v('chat.js') ?>"></script>
<script src="/toolz/assets/cm-kudos.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/cm-kudos.js') ?>"></script>
<script src="comm.js?v=<?= $v('comm.js') ?>"></script>
<script src="chat-plus.js?v=<?= $v('chat-plus.js') ?>"></script>
</body>
</html>
