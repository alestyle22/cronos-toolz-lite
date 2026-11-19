<?php
// Herramienta de admins (2026-11-16): pone el botón de la promo (CTA) de las landings YA publicadas de una plataforma
// en ventana nueva (target="_blank" rel="noopener noreferrer"), con lg_cta_new_tab() de bundle_lib.php.
// Las que se publiquen desde ahora ya salen así (editor.php y lg_bundle_publish). Primero muestra qué cambiaría;
// «Aplicar» guarda una copia de cada archivo en _private/lg_cta_backup/<fecha>/ antes de escribirlo.
error_reporting(0); ini_set('display_errors', 0);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/bundle_lib.php';
require_once __DIR__ . '/templates_lib.php';
$currentUser = requireLogin();
if ($currentUser['role'] !== 'admin') { http_response_code(403); die('Solo admins'); }
require_once __DIR__ . '/../csp_lib.php'; g7_csp_enforce();   // sin JS en la página
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (empty($_SESSION['lg_cta_tok'])) $_SESSION['lg_cta_tok'] = bin2hex(random_bytes(16));
$tok = (string)$_SESSION['lg_cta_tok'];

$platform = (string)($_REQUEST['platform'] ?? '');
if (!lg_platform($platform)) $platform = (string)(lg_platforms()[0]['id'] ?? '');
$prefix = lg_platform_info($platform)['slug'];
$apply = $_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($tok, (string)($_POST['tok'] ?? ''));
$pubDir = lg_pub_dir();
$bak = dirname(__DIR__) . '/_private/lg_cta_backup/' . date('Ymd_His');

// Archivos de las landings de la plataforma: <prefijo>-*.html sueltos y los .html de las carpetas <prefijo>-*/
$files = [];
foreach ((array)@scandir($pubDir) as $e) {
    if ($e === '' || $e[0] === '.' || !preg_match('/^' . preg_quote($prefix, '/') . '-/', $e)) continue;
    // Una plataforma cuyo prefijo empieza con este (p. ej. marca-py vs marca) no entra
    $longer = false;
    foreach (lg_platforms() as $p) { $s = lg_platform_info($p['id'])['slug']; if ($s !== $prefix && strlen($s) > strlen($prefix) && str_starts_with($e, "$s-")) $longer = true; }
    if ($longer) continue;
    if (is_file("$pubDir/$e") && preg_match('/\.html?$/i', $e)) $files[] = $e;
    elseif (is_dir("$pubDir/$e")) foreach (lg_list_files("$pubDir/$e") as $f) if (preg_match('/\.html?$/i', $f)) $files[] = "$e/$f";
}
sort($files);

$rows = []; $tot = 0; $err = 0;
foreach ($files as $rel) {
    $path = "$pubDir/$rel";
    $html = (string)@file_get_contents($path);
    $new = lg_cta_new_tab($html, true, $n);
    $ctas = preg_match_all('#<a\b[^>]*\sclass\s*=\s*["\'][^"\']*\b(cta|btn)\b[^>]*>#i', $html);
    // Sin cambios: o ya abren en ventana nueva, o el botón no tiene URL (href="#"): se ve sacándoles el target
    lg_cta_new_tab(preg_replace('#\starget\s*=#i', ' data-t=', $html), true, $conUrl);
    $state = $n ? ($apply ? 'ok' : 'cambia') : ($conUrl ? 'igual' : 'sinurl');
    if ($apply && $n) {
        $b = "$bak/$rel";
        if (!is_dir(dirname($b))) @mkdir(dirname($b), 0755, true);
        if (!@copy($path, $b) || @file_put_contents($path, $new) === false) { $state = 'error'; $err++; }
    }
    if ($n) $tot++;
    $rows[] = [$rel, $ctas, $n, $state];
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><title>CTA en ventana nueva</title>
<style>body{background:#0a0a0a;color:#e4e4e7;font-family:system-ui,sans-serif;padding:24px;font-size:13px}
h2{color:#ff6600;margin:0 0 6px}p{color:#a1a1aa}table{border-collapse:collapse;width:100%;max-width:980px;margin:16px 0}
td,th{padding:6px 10px;border-bottom:1px solid #1f1f22;text-align:left}th{color:#a1a1aa;font-weight:600}
.ok{color:#4ade80}.cambia{color:#f59e0b}.igual{color:#71717a}.sinurl{color:#a1a1aa}.error{color:#f87171}
button{background:#ff6600;color:#fff;border:0;border-radius:8px;padding:10px 18px;font-weight:700;cursor:pointer}
select{background:#18181b;color:#e4e4e7;border:1px solid #3f3f46;border-radius:6px;padding:6px}a{color:#ff6600}</style></head><body>
<h2>Botón de la promo en ventana nueva</h2>
<p>Landings publicadas en <code><?= $h($pubDir) ?></code> que empiezan con <code><?= $h($prefix) ?>-</code>.
Los botones (clase cta / btn) que van a una URL http(s) pasan a abrir en una pestaña nueva; los enlaces a #secciones no se tocan.</p>
<form method="get"><select name="platform"><?php foreach (lg_platforms() as $p): ?>
<option value="<?= $h($p['id']) ?>"<?= $p['id'] === $platform ? ' selected' : '' ?>><?= $h($p['name']) ?></option><?php endforeach; ?>
</select> <button type="submit">Ver</button></form>
<table><tr><th>Archivo</th><th>Botones</th><th>A cambiar</th><th>Estado</th></tr>
<?php foreach ($rows as [$rel, $ctas, $n, $st]): ?>
<tr><td><?= $h($rel) ?></td><td><?= $ctas ?></td><td><?= $n ?></td><td class="<?= $st ?>"><?= ['ok' => 'Cambiado', 'cambia' => 'Por cambiar', 'igual' => 'Ya está', 'sinurl' => 'El botón no tiene URL (no se toca)', 'error' => 'No se pudo escribir'][$st] ?></td></tr>
<?php endforeach; ?>
</table>
<?php if (!$rows): ?><p>No hay landings publicadas de esta plataforma.</p>
<?php elseif ($apply): ?><p class="ok">Cambiados: <?= $tot - $err ?> archivos<?= $err ? " · errores: $err" : '' ?>. Copia de los originales en <code>_private/lg_cta_backup/<?= $h(basename($bak)) ?></code>.</p>
<?php elseif ($tot): ?><form method="post"><input type="hidden" name="tok" value="<?= $h($tok) ?>"><input type="hidden" name="platform" value="<?= $h($platform) ?>">
<button type="submit">Aplicar a <?= $tot ?> archivos</button></form>
<?php else: ?><p class="ok">Todas las landings de esta plataforma ya abren el botón en ventana nueva.</p><?php endif; ?>
<p><a href="index.php">← Volver a Landings</a></p>
</body></html>
