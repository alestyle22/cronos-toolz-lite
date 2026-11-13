<?php
/**
 * chat/widget.php — Burbuja del chat en toda la intranet (la incluye topbar.php).
 * Panel flotante para leer y escribir sin salir de la página (mismas conversaciones que el chat completo,
 * misma API) y "cabezas" de contactos a la derecha: las que fijas y las que tienen mensajes sin leer.
 * En la página del chat no aparece.
 */
if (defined('CH_PAGE_IS_CHAT')) return;
$ccwV = fn($f) => @filemtime(__DIR__ . '/' . $f);
?>
<link rel="stylesheet" href="/toolz/chat/widget.css?v=<?= $ccwV('widget.css') ?>">
<link rel="stylesheet" href="/toolz/chat/refs.css?v=<?= $ccwV('refs.css') ?>">
<div id="ccw-heads" aria-label="Conversaciones fijadas"></div>
<button type="button" id="ccw" aria-label="Abrir el chat" aria-expanded="false" title="Chat">
  <i class="fa-solid fa-comments"></i><span id="ccw-n" hidden></span>
</button>
<section id="ccw-panel" aria-label="Chat" hidden>
  <header class="ccw-h">
    <button type="button" class="ccw-ib" id="ccw-back" aria-label="Volver" hidden><i class="fa-solid fa-arrow-left"></i></button>
    <span class="ccw-hav" id="ccw-hav"></span>
    <div class="ccw-ht"><b id="ccw-title">Chat</b><small id="ccw-sub"></small></div>
    <button type="button" class="ccw-ib" id="ccw-pin" aria-label="Fijar como burbuja" title="Fijar como burbuja" hidden><i class="fa-solid fa-thumbtack"></i></button>
    <a class="ccw-ib" id="ccw-full" href="/toolz/chat/" aria-label="Abrir el chat completo" title="Abrir el chat completo"><i class="fa-solid fa-up-right-and-down-left-from-center"></i></a>
    <button type="button" class="ccw-ib" id="ccw-close" aria-label="Cerrar" title="Cerrar"><i class="fa-solid fa-xmark"></i></button>
  </header>
  <div class="ccw-list" id="ccw-list">
    <div class="ccw-tabs" role="tablist"><button type="button" class="on" data-tab="recent" role="tab">Conversaciones</button><button type="button" data-tab="people" role="tab">Personas</button></div>
    <input class="ccw-q" id="ccw-q" type="search" placeholder="Buscar…" aria-label="Buscar" autocomplete="off">
    <div class="ccw-items" id="ccw-items"><div class="ccw-empty">Cargando…</div></div>
  </div>
  <div class="ccw-conv" id="ccw-conv" hidden>
    <div class="ccw-msgs" id="ccw-msgs" aria-live="polite"></div>
    <div class="ccw-typing" id="ccw-typing"></div>
    <div class="ccw-att" id="ccw-att"></div>
    <form class="ccw-comp" id="ccw-comp" autocomplete="off">
      <button type="button" class="ccw-ib" id="ccw-file-btn" aria-label="Adjuntar archivo" title="Adjuntar archivo"><i class="fa-solid fa-paperclip"></i></button>
      <input type="file" id="ccw-file" multiple hidden>
      <textarea id="ccw-ta" rows="1" placeholder="Escribe un mensaje…" aria-label="Mensaje"></textarea>
      <button type="button" class="ccw-ib" id="ccw-emoji" data-refpk-open aria-label="Emojis" title="Emojis"><i class="fa-regular fa-face-smile"></i></button>
      <button type="button" class="ccw-ib" id="ccw-link" data-refpk-open aria-label="Vincular cliente, contenido, tarea o ticket" title="Vincular cliente, contenido, tarea o ticket"><i class="fa-solid fa-link"></i></button>
      <button type="submit" class="ccw-send" aria-label="Enviar"><i class="fa-solid fa-paper-plane"></i></button>
    </form>
    <div class="ccw-ro" id="ccw-ro" hidden></div>
  </div>
</section>
<script src="/toolz/chat/emoji.js?v=<?= $ccwV('emoji.js') ?>" defer></script>
<script src="/toolz/chat/refs.js?v=<?= $ccwV('refs.js') ?>" defer></script>
<script src="/toolz/chat/widget.js?v=<?= $ccwV('widget.js') ?>" defer></script>
