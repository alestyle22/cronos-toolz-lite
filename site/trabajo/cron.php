<?php
/**
 * trabajo/cron.php — Opcional: revisa los SLA de los tickets y los recordatorios de tareas aunque nadie tenga abierta la intranet.
 * En el Toolz conectado al sitio base también trae y manda los tickets del base (trabajo/base_sync.php).
 * Sin esto igual funciona: lo hace cualquier persona que use Gestión de trabajo (como mucho cada 5 minutos).
 * cPanel › Cron: cada 5 minutos →  php /home/USUARIO/public_html/toolz/trabajo/cron.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/lib.php';
$db = getDB();
wk_install($db);
wk_sla_sweep($db, true);
tk_run_reminders($db, true);
require_once __DIR__ . '/base_sync.php';
if (wb_enabled()) { $r = wb_sync($db, true); if (!$r['ok']) fwrite(STDERR, 'Tickets del base: ' . $r['error'] . PHP_EOL); }   // solo en el Toolz conectado al base
