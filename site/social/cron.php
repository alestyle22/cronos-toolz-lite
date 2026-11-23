<?php
/**
 * social/cron.php — Tareas periódicas de Social Studio (solo por consola). Trae el estado de los tickets vinculados,
 * publica lo programado en las redes, retoma los videos de Instagram en proceso y revisa las cuentas. Recomendado: cada 5 minutos, php /ruta/toolz/social/cron.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../auth.php';
$db = getDB();
require_once __DIR__ . '/report_lib.php';
ss_install($db);
if (is_file(dirname(__DIR__) . '/trabajo/lib.php')) { require_once dirname(__DIR__) . '/trabajo/lib.php'; }
$n = ss_sync_tickets($db);
$m = ss_meta_cron($db);
$in = ss_inbox_process($db, 500);   // Meta notifications that were left pending
$an = 0;
if (date('Y-m-d') !== (string)g7_cfg('ss_an_day', '') && (int)date('G') >= 6) { g7_cfg_set('ss_an_day', date('Y-m-d')); $an = ss_an_sync_all($db)['accounts']; }   // metrics: once a day, early in the morning
$rp = ss_rep_cron($db);   // automatic monthly reports (from the chosen day, with the data already synced)
$sy = 0;
if (time() - (int)g7_cfg('ss_sync_at', '0') >= 3600) { g7_cfg_set('ss_sync_at', (string)time()); $sy = ss_inbox_sync_all($db)['new']; }   // once an hour: DM threads that the webhooks did not bring
echo date('Y-m-d H:i:s') . " social: $n cambios de tickets, {$m['published_steps']} envíos a redes, {$m['checked']} cuentas revisadas, $in mensajes por avisos, $sy por sincronización, $an cuentas con métricas, $rp informes automáticos\n";
