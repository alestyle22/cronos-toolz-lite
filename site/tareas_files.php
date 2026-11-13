<?php
/**
 * tareas_files.php — Adjuntos de las tareas (tareas.js).
 * Los archivos quedan en _private/tareas/<id>/ (carpeta bloqueada para la web) y salen solo por acá, con sesión.
 * Sube en partes (up_chunk → up_done), igual que Materiales: el hosting corta los envíos de más de 2 MB.
 *
 * GET  ?f=ID[&dl=1]                              el adjunto (imágenes y PDF se ven; el resto se descarga)
 * POST multipart action=up_chunk (token, index, chunk)
 * POST JSON {action: up_done, token, parts, size, name, task_id} | {action: up_abort, token}
 */
require_once __DIR__ . '/auth.php';
$me = getCurrentUser();
if (!$me || !g7_can((int)$me['id'], 'tareas')) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['error' => 'No tienes acceso a Tareas']); exit; }
$db = getDB();
require_once __DIR__ . '/tareas_lib.php';
const CLI_EXT = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'avif', 'pdf', 'ai', 'eps', 'psd', 'zip', 'rar', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'ttf', 'otf', 'woff', 'woff2', 'mp4', 'mov'];
const CLI_DRIVE_EXT = ['tif', 'tiff', 'heic', 'bmp', 'ico', 'indd', 'idml', 'cdr', 'xd', 'fig', 'sketch', 'afdesign', 'mp3', 'wav', 'm4a', '7z', 'key', 'pages', 'numbers', 'odt', 'ods', 'rtf', 'webm'];
const CLI_DRIVE_MAX = 300 * 1024 * 1024;
/** Manda un archivo guardado. inline solo para imágenes comunes y PDF; nunca se ejecuta nada (CSP sandbox). */
function cli_send_file(string $path, string $name, ?string $mime = null, bool $download = false): void {
    if (!is_file($path)) { http_response_code(404); exit('No existe'); }
    $mime = $mime ?: ((new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream');
    $inline = !$download && preg_match('#^(image/(png|jpeg|webp|gif|avif)|application/pdf)$#', $mime);
    header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
    header('Cache-Control: private, max-age=3600');
    readfile($path); exit;
}
require_once __DIR__ . '/trabajo/lib.php';
wk_install($db);
$uid = (int)$me['id'];
$wkCtx = wk_ctx($db, $uid);   // solo los adjuntos de tareas que esta persona puede ver
const TKF_DIR = __DIR__ . '/_private/tareas';
const TKF_TMP = TKF_DIR . '/_subidas';
const TKF_FOLDER = 'Entregas';

function tkf_out(array $v): void { header('Content-Type: application/json; charset=utf-8'); echo json_encode($v, JSON_UNESCAPED_UNICODE); exit; }
function tkf_fail(string $m, int $c = 400): void { http_response_code($c); tkf_out(['error' => $m]); }
function tkf_clean_dir(string $d): void { foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $p) @unlink("$d/$p"); @rmdir($d); }

// ── Descargar ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['f'])) {
    $q = $db->prepare('SELECT * FROM g7_task_files WHERE id = ?');
    $q->execute([(int)$_GET['f']]); $f = $q->fetch(PDO::FETCH_ASSOC);
    if (!$f || !wk_task_visible($db, $wkCtx, (int)$f['task_id'])) { http_response_code(404); exit('No existe'); }
    cli_send_file(TKF_DIR . '/' . (int)$f['task_id'] . '/' . basename($f['stored']), $f['name'], $f['mime'], isset($_GET['dl']));
}

// Subidas abandonadas de más de un día
if (is_dir(TKF_TMP) && mt_rand(1, 20) === 1) foreach (glob(TKF_TMP . '/*') ?: [] as $d) if (is_dir($d) && filemtime($d) < time() - 86400) tkf_clean_dir($d);

// ── Partes ──────────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'up_chunk') {
    $tok = (string)($_POST['token'] ?? ''); $idx = (int)($_POST['index'] ?? -1);
    if (!preg_match('/^[a-f0-9]{32}$/', $tok) || $idx < 0 || $idx > 5000) tkf_fail('Pedido inválido');
    $up = $_FILES['chunk'] ?? null;
    if ($why = g7_upload_problem($up)) tkf_fail($why);
    if (!$up || $up['error'] !== UPLOAD_ERR_OK) tkf_fail('No llegó la parte del archivo');
    $dir = TKF_TMP . "/$tok";
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) tkf_fail('No se pudo preparar la subida en el servidor', 500);
    if (is_file("$dir/.owner") && trim(file_get_contents("$dir/.owner")) !== (string)$uid) tkf_fail('Pedido inválido', 403);
    file_put_contents("$dir/.owner", (string)$uid);
    if (!move_uploaded_file($up['tmp_name'], sprintf('%s/%05d', $dir, $idx))) tkf_fail('El servidor no pudo guardar la parte', 500);
    tkf_out(['ok' => true]);
}

$in = json_decode(file_get_contents('php://input'), true) ?? [];
$act = (string)($in['action'] ?? '');
$tok = (string)($in['token'] ?? '');
$dir = TKF_TMP . "/$tok";
$mine = preg_match('/^[a-f0-9]{32}$/', $tok) && is_dir($dir) && trim((string)@file_get_contents("$dir/.owner")) === (string)$uid;

if ($act === 'up_abort') { if ($mine) tkf_clean_dir($dir); tkf_out(['ok' => true]); }

if ($act === 'up_done') {
    if (!$mine) tkf_fail('La subida se perdió: prueba de nuevo');
    $q = $db->prepare('SELECT id, title, client_id FROM g7_tasks WHERE id = ?'); $q->execute([(int)($in['task_id'] ?? 0)]); $t = $q->fetch(PDO::FETCH_ASSOC);
    if (!$t || !wk_task_visible($db, $wkCtx, (int)$t['id'])) { tkf_clean_dir($dir); tkf_fail('Esa tarea no existe', 404); }
    $tid = (int)$t['id']; $cid = 0;   // siempre a la carpeta de la tarea
    // Versión nueva de un adjunto que ya existe (Gestión de trabajo, fase 6): queda vinculada a la primera versión
    $verOf = null; $verN = 1;
    if (!empty($in['version_of'])) {
        $q = $db->prepare('SELECT id, version_of FROM g7_task_files WHERE id = ? AND task_id = ?'); $q->execute([(int)$in['version_of'], $tid]); $vf = $q->fetch(PDO::FETCH_ASSOC);
        if (!$vf) { tkf_clean_dir($dir); tkf_fail('Ese adjunto ya no existe', 404); }
        $verOf = (int)($vf['version_of'] ?: $vf['id']);
        $q = $db->prepare('SELECT COALESCE(MAX(version), 1) + 1 FROM g7_task_files WHERE id = ? OR version_of = ?'); $q->execute([$verOf, $verOf]); $verN = (int)$q->fetchColumn();
    }
    $name = mb_substr(trim(preg_replace('/[\x00-\x1f\\\\\/:*?"<>|]+/u', '-', (string)($in['name'] ?? ''))), 0, 200);
    $size = (int)($in['size'] ?? 0); $parts = (int)($in['parts'] ?? 0);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($name === '' || $name[0] === '.') { tkf_clean_dir($dir); tkf_fail('El archivo no tiene un nombre válido'); }
    if (!in_array($ext, CLI_EXT, true) && !in_array($ext, CLI_DRIVE_EXT, true)) { tkf_clean_dir($dir); tkf_fail("$name: ese tipo de archivo no se puede adjuntar"); }
    if ($size > CLI_DRIVE_MAX) { tkf_clean_dir($dir); tkf_fail("$name pesa más de " . (CLI_DRIVE_MAX >> 20) . ' MB'); }
    $chunks = glob("$dir/[0-9][0-9][0-9][0-9][0-9]") ?: []; sort($chunks);
    if (count($chunks) !== $parts) { tkf_clean_dir($dir); tkf_fail("$name llegó incompleto: prueba de nuevo"); }
    // Con cliente, a sus Materiales; sin cliente, a la carpeta de la tarea
    $dest = $cid ? CLI_DIR . "/$cid" : TKF_DIR . "/$tid";
    if (!is_dir($dest) && !@mkdir($dest, 0755, true)) { tkf_clean_dir($dir); tkf_fail('No se pudo crear la carpeta de destino', 500); }
    $stored = bin2hex(random_bytes(12)) . ($ext === '' ? '' : ".$ext");
    $out = @fopen("$dest/$stored", 'wb');
    if (!$out) { tkf_clean_dir($dir); tkf_fail('El servidor no puede escribir en la carpeta de destino', 500); }
    foreach ($chunks as $ch) { $h = fopen($ch, 'rb'); stream_copy_to_stream($h, $out); fclose($h); }
    fclose($out); tkf_clean_dir($dir);
    if (filesize("$dest/$stored") !== $size) { @unlink("$dest/$stored"); tkf_fail("$name llegó con otro tamaño: prueba de nuevo"); }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file("$dest/$stored") ?: 'application/octet-stream';
    $now = tk_now();
    if (false) {
        $db->prepare('INSERT IGNORE INTO g7_client_folders (client_id, path, created_by) VALUES (?, ?, ?)')->execute([$cid, TKF_FOLDER, $uid]);
        // Nombre libre en la carpeta: "logo.png" → "logo (2).png"
        $q = $db->prepare('SELECT LOWER(name) FROM g7_client_files WHERE client_id = ? AND folder = ?'); $q->execute([$cid, TKF_FOLDER]);
        $used = $q->fetchAll(PDO::FETCH_COLUMN); $final = $name;
        for ($k = 2; in_array(mb_strtolower($final), $used, true); $k++) $final = pathinfo($name, PATHINFO_FILENAME) . " ($k)" . ($ext === '' ? '' : ".$ext");
        $db->prepare('INSERT INTO g7_client_files (client_id, name, stored, mime, size, kind, folder, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$cid, $final, $stored, $mime, $size, 'otro', TKF_FOLDER, $uid]);
        $cf = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO g7_task_files (task_id, client_file_id, uploaded_by, created_at, version_of, version) VALUES (?, ?, ?, ?, ?, ?)')->execute([$tid, $cf, $uid, $now, $verOf, $verN]);
        $db->prepare('UPDATE g7_clients SET updated_at = NOW() WHERE id = ?')->execute([$cid]);
    } else {
        $db->prepare('INSERT INTO g7_task_files (task_id, name, stored, mime, size, uploaded_by, created_at, version_of, version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$tid, $name, $stored, $mime, $size, $uid, $now, $verOf, $verN]);
    }
    $db->prepare('UPDATE g7_tasks SET updated_at = ? WHERE id = ?')->execute([$now, $tid]);
    tk_log($db, $tid, $uid, 'files', ($verOf ? 'subió la versión ' . $verN . ' de ' : 'adjuntó ') . $name);
    tkf_out(['ok' => true, 'in_materials' => (bool)$cid]);
}

tkf_fail('Acción desconocida');
