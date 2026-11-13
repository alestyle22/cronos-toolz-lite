<?php
/**
 * chat/cron.php — Opcional: despacha mensajes programados, recordatorios y retención aunque nadie tenga el chat abierto.
 * Sin esto igual funciona: lo hace la sincronización de cualquier persona conectada.
 * cPanel › Cron: cada minuto →  php /home/USUARIO/public_html/toolz/chat/cron.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';
ch_install();
ch_dispatch();
