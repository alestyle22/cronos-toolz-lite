<?php
/**
 * materiales_api.php — Materiales de cada cliente (logos, tipografías, manuales…) organizados en carpetas, como un drive.
 * La usa materiales.js (página stock.php). Entra quien tenga el módulo "Stock / Materiales"; borrar es del admin o de quien subió.
 * Son los mismos archivos de la pestaña Archivos de cada cliente (g7_client_files): acá se suman las carpetas.
 * Subida en partes (up_chunk → up_done): el hosting corta los envíos de más de 2 MB, así que cada archivo viaja en pedazos.
 *
 * GET  ?f=ID[&dl=1]          archivo (imágenes y PDF se ven en el navegador; el resto se descarga)
 * GET  ?th=ID                miniatura WebP de una imagen (se genera la primera vez)
 * GET  ?zip=CID[&path=…]     carpeta entera en .zip
 * POST JSON {action: clients|open|search|mkdir|rename_folder|delete_folder|rename_file|move|delete_file|up_done|up_abort}
 * POST multipart action=up_chunk (token, index, chunk)
 */
require_once __DIR__ . '/auth.php';
if (!g7_site_has('materiales')) { http_response_code(404); exit; }
$me = getCurrentUser();
if (!$me || !g7_can((int)$me['id'], 'stock')) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['error' => 'No tienes acceso a Materiales']); exit; }
$db = getDB();
require_once __DIR__ . '/clientes_lib.php';
cli_install($db);
$uid = (int)$me['id'];
$isAdmin = ($me['role'] ?? '') === 'admin';
const MAT_TMP = CLI_DIR . '/_subidas';

function mat_out(array $v): void { header('Content-Type: application/json; charset=utf-8'); echo json_encode($v, JSON_UNESCAPED_UNICODE); exit; }
function mat_fail(string $m, int $c = 400): void { http_response_code($c); mat_out(['error' => $m]); }
function mat_ini_bytes(string $k): int {
    $v = trim((string)ini_get($k)); $n = (int)$v;
    return match (strtoupper(substr($v, -1))) { 'G' => $n << 30, 'M' => $n << 20, 'K' => $n << 10, default => $n };
}
/** Tamaño de cada parte: lo que deja pasar el servidor con margen, entre 512 KB y 8 MB. */
function mat_chunk_size(): int {
    $lim = min(mat_ini_bytes('upload_max_filesize') ?: 2 << 20, mat_ini_bytes('post_max_size') ?: 8 << 20);
    return max(512 << 10, min(8 << 20, $lim - (128 << 10)));
}
function mat_client(PDO $db, int $cid): array {
    $st = $db->prepare('SELECT id, name, status, avatar FROM g7_clients WHERE id = ?'); $st->execute([$cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC); if (!$c) mat_fail('Ese cliente no existe', 404);
    return $c;
}
function mat_file(PDO $db, int $id): array {
    $st = $db->prepare('SELECT * FROM g7_client_files WHERE id = ?'); $st->execute([$id]);
    $f = $st->fetch(PDO::FETCH_ASSOC); if (!$f) mat_fail('Ese archivo ya no existe', 404);
    return $f;
}
function mat_path(array $f): string { return CLI_DIR . '/' . (int)$f['client_id'] . '/' . basename($f['stored']); }
function mat_folder($v): string { $p = cli_folder_clean((string)$v); if ($p === null) mat_fail('Ese nombre de carpeta no sirve'); return $p; }
function mat_can_delete(array $f, int $uid, bool $isAdmin): bool { return $isAdmin || (int)$f['uploaded_by'] === $uid; }
/** "Logos/Viejos" → ["Logos", "Logos/Viejos"]: para que una carpeta creada adentro de otra deje a la de arriba guardada. */
function mat_parents(string $p): array { $o = []; $acc = ''; foreach (explode('/', $p) as $s) { $acc = $acc === '' ? $s : "$acc/$s"; $o[] = $acc; } return $o; }
function mat_ensure_folder(PDO $db, int $cid, string $p, int $uid): void {
    if ($p === '') return;
    $ins = $db->prepare('INSERT IGNORE INTO g7_client_folders (client_id, path, created_by) VALUES (?,?,?)');
    foreach (mat_parents($p) as $x) $ins->execute([$cid, $x, $uid]);
}
/** Nombre libre dentro de una carpeta: "logo.png" → "logo (2).png" si ya existe. */
function mat_free_name(PDO $db, int $cid, string $folder, string $name, int $exceptId = 0): string {
    $st = $db->prepare('SELECT name FROM g7_client_files WHERE client_id = ? AND folder = ? AND id <> ?'); $st->execute([$cid, $folder, $exceptId]);
    $used = array_map('mb_strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array(mb_strtolower($name), $used, true)) return $name;
    $ext = pathinfo($name, PATHINFO_EXTENSION); $base = $ext === '' ? $name : mb_substr($name, 0, -mb_strlen($ext) - 1);
    for ($k = 2; ; $k++) { $n = "$base ($k)" . ($ext === '' ? '' : ".$ext"); if (!in_array(mb_strtolower($n), $used, true)) return $n; }
}
function mat_allowed_ext(string $name): bool { $e = strtolower(pathinfo($name, PATHINFO_EXTENSION)); return in_array($e, CLI_EXT, true) || in_array($e, CLI_DRIVE_EXT, true); }

// ── Descargas ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['f'])) {
    $f = mat_file($db, (int)$_GET['f']);
    cli_send_file(mat_path($f), $f['name'], $f['mime'], isset($_GET['dl']));
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['th'])) {
    $f = mat_file($db, (int)$_GET['th']); $src = mat_path($f);
    if (!preg_match('#^image/(png|jpeg|webp|gif)$#', $f['mime']) || !function_exists('imagewebp')) cli_send_file($src, $f['name'], $f['mime']);
    $th = CLI_DIR . '/' . (int)$f['client_id'] . '/.th_' . pathinfo(basename($f['stored']), PATHINFO_FILENAME) . '.webp';
    if (!is_file($th) && is_file($src) && filesize($src) < 40 << 20) {
        $info = @getimagesize($src);
        if ($info && $info[0] * $info[1] < 40e6) {
            $im = @imagecreatefromstring(file_get_contents($src));
            if ($im) {
                $w = imagesx($im); $h = imagesy($im); $s = min(1, 480 / max($w, $h));
                $t = imagecreatetruecolor(max(1, (int)($w * $s)), max(1, (int)($h * $s)));
                imagealphablending($t, false); imagesavealpha($t, true);
                imagefill($t, 0, 0, imagecolorallocatealpha($t, 0, 0, 0, 127));
                imagecopyresampled($t, $im, 0, 0, 0, 0, imagesx($t), imagesy($t), $w, $h);
                @imagewebp($t, $th, 80); imagedestroy($t); imagedestroy($im);
            }
        }
    }
    is_file($th) ? cli_send_file($th, 'miniatura.webp', 'image/webp') : cli_send_file($src, $f['name'], $f['mime']);
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['zip'])) {
    $c = mat_client($db, (int)$_GET['zip']); $cid = (int)$c['id'];
    $base = cli_folder_clean((string)($_GET['path'] ?? '')) ?? '';
    if (!class_exists('ZipArchive')) { http_response_code(500); exit('El servidor no puede armar archivos .zip'); }
    $st = $db->prepare("SELECT * FROM g7_client_files WHERE client_id = ? AND (? = '' OR folder = ? OR folder LIKE ?)");
    $st->execute([$cid, $base, $base, $base . '/%']);
    $files = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$files) { http_response_code(404); exit('Esta carpeta no tiene archivos'); }
    @set_time_limit(300);
    $tmp = tempnam(sys_get_temp_dir(), 'mat'); $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $used = [];
    foreach ($files as $f) {
        if (!is_file(mat_path($f))) continue;
        $rel = ltrim(mb_substr($f['folder'], mb_strlen($base)), '/');
        $n = ($rel === '' ? '' : "$rel/") . $f['name'];
        for ($k = 2; isset($used[mb_strtolower($n)]); $k++) $n = ($rel === '' ? '' : "$rel/") . pathinfo($f['name'], PATHINFO_FILENAME) . " ($k)." . pathinfo($f['name'], PATHINFO_EXTENSION);
        $used[mb_strtolower($n)] = 1;
        $zip->addFile(mat_path($f), $n);
    }
    $zip->close();
    $zname = preg_replace('/[\\\\\/:*?"<>|]+/', '-', $c['name'] . ($base === '' ? '' : ' - ' . str_replace('/', ' - ', $base))) . '.zip';
    header('Content-Type: application/zip'); header('Content-Length: ' . filesize($tmp));
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($zname));
    readfile($tmp); @unlink($tmp); exit;
}

// ── Subida en partes ────────────────────────────────────────────
// Limpieza: subidas abandonadas de más de un día
if (is_dir(MAT_TMP) && mt_rand(1, 20) === 1) foreach (glob(MAT_TMP . '/*') ?: [] as $d) if (is_dir($d) && filemtime($d) < time() - 86400) { array_map('unlink', glob("$d/*") ?: []); @rmdir($d); }

if (($_POST['action'] ?? '') === 'up_chunk') {
    $tok = (string)($_POST['token'] ?? ''); $idx = (int)($_POST['index'] ?? -1);
    if (!preg_match('/^[a-f0-9]{32}$/', $tok) || $idx < 0 || $idx > 5000) mat_fail('Pedido inválido');
    $up = $_FILES['chunk'] ?? null;
    if ($why = g7_upload_problem($up)) mat_fail($why);
    if (!$up || $up['error'] !== UPLOAD_ERR_OK) mat_fail('No llegó la parte del archivo');
    $dir = MAT_TMP . "/$tok";
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) mat_fail('No se pudo preparar la subida en el servidor', 500);
    if (is_file("$dir/.owner") && trim(file_get_contents("$dir/.owner")) !== (string)$uid) mat_fail('Pedido inválido', 403);
    file_put_contents("$dir/.owner", (string)$uid);
    if (!move_uploaded_file($up['tmp_name'], sprintf('%s/%05d', $dir, $idx))) mat_fail('El servidor no pudo guardar la parte', 500);
    mat_out(['ok' => true]);
}

// ── Acciones JSON ───────────────────────────────────────────────
$in = json_decode(file_get_contents('php://input'), true) ?? [];
$act = (string)($in['action'] ?? '');

if ($act === 'up_done') {
    $tok = (string)($in['token'] ?? ''); $parts = (int)($in['parts'] ?? 0); $size = (int)($in['size'] ?? 0);
    $dir = MAT_TMP . "/$tok";
    if (!preg_match('/^[a-f0-9]{32}$/', $tok) || !is_dir($dir) || trim((string)@file_get_contents("$dir/.owner")) !== (string)$uid) mat_fail('La subida se perdió: prueba de nuevo');
    $clean = function () use ($dir) { array_map('unlink', glob("$dir/*") ?: []); @unlink("$dir/.owner"); @rmdir($dir); };
    $c = mat_client($db, (int)($in['client_id'] ?? 0)); $cid = (int)$c['id'];
    $folder = mat_folder($in['folder'] ?? '');
    $name = mb_substr(trim(preg_replace('/[\x00-\x1f\\\\\/:*?"<>|]+/u', '-', (string)($in['name'] ?? ''))), 0, 200);
    if ($name === '' || $name[0] === '.') { $clean(); mat_fail('El archivo no tiene un nombre válido'); }
    if (!mat_allowed_ext($name)) { $clean(); mat_fail("$name: ese tipo de archivo no se puede subir"); }
    if ($size > CLI_DRIVE_MAX) { $clean(); mat_fail("$name pesa más de " . (CLI_DRIVE_MAX >> 20) . ' MB'); }
    $chunks = glob("$dir/[0-9][0-9][0-9][0-9][0-9]") ?: []; sort($chunks);
    if (count($chunks) !== $parts) { $clean(); mat_fail("$name llegó incompleto: prueba de nuevo"); }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $stored = bin2hex(random_bytes(12)) . ($ext === '' ? '' : ".$ext");
    $cdir = CLI_DIR . "/$cid"; if (!is_dir($cdir)) @mkdir($cdir, 0755, true);
    $out = @fopen("$cdir/$stored", 'wb');
    if (!$out) { $clean(); mat_fail('El servidor no puede escribir en la carpeta del cliente', 500); }
    foreach ($chunks as $ch) { $in2 = fopen($ch, 'rb'); stream_copy_to_stream($in2, $out); fclose($in2); }
    fclose($out); $clean();
    if (filesize("$cdir/$stored") !== $size) { @unlink("$cdir/$stored"); mat_fail("$name llegó con otro tamaño: prueba de nuevo"); }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file("$cdir/$stored") ?: 'application/octet-stream';
    mat_ensure_folder($db, $cid, $folder, $uid);
    $name = mat_free_name($db, $cid, $folder, $name);
    $kind = $folder === 'Logos' || str_starts_with($folder, 'Logos/') ? 'logo' : ($folder === 'Manual de marca' ? 'manual' : 'otro');
    $db->prepare('INSERT INTO g7_client_files (client_id, name, stored, mime, size, kind, folder, uploaded_by) VALUES (?,?,?,?,?,?,?,?)')
       ->execute([$cid, $name, $stored, $mime, $size, $kind, $folder, $uid]);
    $db->prepare('UPDATE g7_clients SET updated_at = NOW() WHERE id = ?')->execute([$cid]);
    mat_out(['ok' => true, 'id' => (int)$db->lastInsertId(), 'name' => $name]);
}
if ($act === 'up_abort') {
    $tok = (string)($in['token'] ?? ''); $dir = MAT_TMP . "/$tok";
    if (preg_match('/^[a-f0-9]{32}$/', $tok) && is_dir($dir) && trim((string)@file_get_contents("$dir/.owner")) === (string)$uid) { array_map('unlink', glob("$dir/*") ?: []); @unlink("$dir/.owner"); @rmdir($dir); }
    mat_out(['ok' => true]);
}

// Carpetas de cada cliente: la lista de todos, con totales
if ($act === 'clients') {
    foreach ($db->query('SELECT id FROM g7_clients WHERE drive_ready = 0')->fetchAll(PDO::FETCH_COLUMN) as $cid) cli_drive_init($db, (int)$cid, $uid);
    $rows = $db->query("SELECT c.id, c.name, c.status, c.avatar, COUNT(f.id) AS files, COALESCE(SUM(f.size), 0) AS bytes, MAX(f.created_at) AS last_up
        FROM g7_clients c LEFT JOIN g7_client_files f ON f.client_id = c.id
        GROUP BY c.id ORDER BY (c.status = 'terminado'), c.name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['avatar'] = cli_avatar_url($r); $r['files'] = (int)$r['files']; $r['bytes'] = (int)$r['bytes']; }
    mat_out(['clients' => $rows, 'chunk' => mat_chunk_size(), 'max' => CLI_DRIVE_MAX, 'admin' => $isAdmin]);
}

// Contenido de una carpeta: subcarpetas (con cuántos archivos tienen adentro) y archivos
if ($act === 'open') {
    $c = mat_client($db, (int)($in['client_id'] ?? 0)); $cid = (int)$c['id'];
    cli_drive_init($db, $cid, $uid);
    $path = mat_folder($in['path'] ?? '');
    $st = $db->prepare('SELECT path FROM g7_client_folders WHERE client_id = ?'); $st->execute([$cid]);
    $all = $st->fetchAll(PDO::FETCH_COLUMN);
    $st = $db->prepare('SELECT folder, COUNT(*) n, SUM(size) b FROM g7_client_files WHERE client_id = ? GROUP BY folder'); $st->execute([$cid]);
    $counts = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $counts[$r['folder']] = [(int)$r['n'], (int)$r['b']]; if ($r['folder'] !== '') $all[] = $r['folder']; }
    $pre = $path === '' ? '' : "$path/";
    $subs = [];
    foreach (array_unique($all) as $p) {
        if ($pre !== '' && !str_starts_with($p, $pre)) continue;
        $rest = mb_substr($p, mb_strlen($pre)); if ($rest === '') continue;
        $name = explode('/', $rest)[0]; $full = $pre . $name;
        $subs[$full] ??= ['path' => $full, 'name' => $name, 'files' => 0, 'bytes' => 0];
    }
    foreach ($counts as $fp => [$n, $b]) foreach ($subs as $sp => &$s) if ($fp === $sp || str_starts_with($fp, "$sp/")) { $s['files'] += $n; $s['bytes'] += $b; }
    unset($s);
    uasort($subs, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    $st = $db->prepare("SELECT f.id, f.name, f.mime, f.size, f.created_at, f.uploaded_by, COALESCE(NULLIF(u.display_name, ''), u.username) AS by_name
        FROM g7_client_files f LEFT JOIN g7_users u ON u.id = f.uploaded_by WHERE f.client_id = ? AND f.folder = ? ORDER BY f.name");
    $st->execute([$cid, $path]);
    $files = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($files as &$f) { $f['size'] = (int)$f['size']; $f['can_delete'] = mat_can_delete($f, $uid, $isAdmin); }
    unset($f);
    $c['avatar'] = cli_avatar_url($c);
    $known = in_array($path, $all, true) || $path === '';
    mat_out(['client' => $c, 'path' => $path, 'exists' => $known, 'folders' => array_values($subs), 'files' => $files, 'all_folders' => array_values(array_unique($all))]);
}

// Buscar por nombre en todos los clientes (o en uno)
if ($act === 'search') {
    $q = trim((string)($in['q'] ?? '')); if (mb_strlen($q) < 2) mat_out(['files' => []]);
    $cid = (int)($in['client_id'] ?? 0);
    $st = $db->prepare("SELECT f.id, f.name, f.mime, f.size, f.folder, f.created_at, f.client_id, f.uploaded_by, c.name AS client_name
        FROM g7_client_files f JOIN g7_clients c ON c.id = f.client_id WHERE f.name LIKE ? AND (? = 0 OR f.client_id = ?) ORDER BY f.created_at DESC LIMIT 200");
    $st->execute(['%' . addcslashes($q, '%_\\') . '%', $cid, $cid]);
    $files = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($files as &$f) { $f['size'] = (int)$f['size']; $f['can_delete'] = mat_can_delete($f, $uid, $isAdmin); }
    mat_out(['files' => $files]);
}

if ($act === 'mkdir') {
    $c = mat_client($db, (int)($in['client_id'] ?? 0));
    $base = mat_folder($in['path'] ?? ''); $name = mat_folder($in['name'] ?? '');
    if ($name === '' || str_contains($name, '/')) mat_fail('Pon un nombre de carpeta, sin barras');
    $p = $base === '' ? $name : "$base/$name";
    mat_ensure_folder($db, (int)$c['id'], $p, $uid);
    mat_out(['ok' => true, 'path' => $p]);
}

// Renombrar una carpeta mueve todo lo que tiene adentro (es solo un dato: no se toca el disco)
if ($act === 'rename_folder') {
    $c = mat_client($db, (int)($in['client_id'] ?? 0)); $cid = (int)$c['id'];
    $old = mat_folder($in['path'] ?? ''); $new = mat_folder($in['name'] ?? '');
    if ($old === '' || $new === '' || str_contains($new, '/')) mat_fail('Pon un nombre de carpeta, sin barras');
    $to = (str_contains($old, '/') ? substr($old, 0, strrpos($old, '/')) . '/' : '') . $new;
    if ($to === $old) mat_out(['ok' => true, 'path' => $to]);
    $chk = $db->prepare('SELECT (SELECT COUNT(*) FROM g7_client_folders WHERE client_id = ? AND path = ?) + (SELECT COUNT(*) FROM g7_client_files WHERE client_id = ? AND folder = ?)');
    $chk->execute([$cid, $to, $cid, $to]);
    if ($chk->fetchColumn() > 0) mat_fail('Ya hay una carpeta con ese nombre');
    $len = mb_strlen($old) + 1;
    $db->beginTransaction();
    $db->prepare('UPDATE g7_client_folders SET path = CONCAT(?, SUBSTRING(path, ?)) WHERE client_id = ? AND (path = ? OR path LIKE ?)')->execute([$to, $len, $cid, $old, "$old/%"]);
    $db->prepare('UPDATE g7_client_files SET folder = CONCAT(?, SUBSTRING(folder, ?)) WHERE client_id = ? AND (folder = ? OR folder LIKE ?)')->execute([$to, $len, $cid, $old, "$old/%"]);
    mat_ensure_folder($db, $cid, $to, $uid);
    $db->commit();
    mat_out(['ok' => true, 'path' => $to]);
}

if ($act === 'delete_folder') {
    $c = mat_client($db, (int)($in['client_id'] ?? 0)); $cid = (int)$c['id'];
    $p = mat_folder($in['path'] ?? ''); if ($p === '') mat_fail('La carpeta del cliente no se borra desde acá');
    $st = $db->prepare('SELECT * FROM g7_client_files WHERE client_id = ? AND (folder = ? OR folder LIKE ?)'); $st->execute([$cid, $p, "$p/%"]);
    $files = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($files && !$isAdmin) {
        $others = array_filter($files, fn($f) => !mat_can_delete($f, $uid, false));
        if ($others) mat_fail('Esta carpeta tiene archivos que subieron otras personas: solo un admin puede borrarla');
    }
    foreach ($files as $f) { @unlink(mat_path($f)); @unlink(CLI_DIR . "/$cid/.th_" . pathinfo(basename($f['stored']), PATHINFO_FILENAME) . '.webp'); }
    $db->prepare('DELETE FROM g7_client_files WHERE client_id = ? AND (folder = ? OR folder LIKE ?)')->execute([$cid, $p, "$p/%"]);
    $db->prepare('DELETE FROM g7_client_folders WHERE client_id = ? AND (path = ? OR path LIKE ?)')->execute([$cid, $p, "$p/%"]);
    mat_out(['ok' => true, 'deleted' => count($files)]);
}

if ($act === 'rename_file') {
    $f = mat_file($db, (int)($in['id'] ?? 0));
    $name = mb_substr(trim(preg_replace('/[\x00-\x1f\\\\\/:*?"<>|]+/u', '-', (string)($in['name'] ?? ''))), 0, 200);
    if ($name === '' || $name[0] === '.') mat_fail('Pon un nombre');
    // La extensión no se cambia: si la sacan, se repone
    $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
    if ($ext !== '' && strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== strtolower($ext)) $name .= ".$ext";
    $name = mat_free_name($db, (int)$f['client_id'], $f['folder'], $name, (int)$f['id']);
    $db->prepare('UPDATE g7_client_files SET name = ? WHERE id = ?')->execute([$name, (int)$f['id']]);
    mat_out(['ok' => true, 'name' => $name]);
}

if ($act === 'move') {
    $ids = array_slice(array_values(array_filter(array_map('intval', (array)($in['ids'] ?? [])))), 0, 500);
    $c = mat_client($db, (int)($in['client_id'] ?? 0)); $cid = (int)$c['id'];
    $to = mat_folder($in['folder'] ?? '');
    mat_ensure_folder($db, $cid, $to, $uid);
    $n = 0;
    foreach ($ids as $id) {
        $f = mat_file($db, $id); if ((int)$f['client_id'] !== $cid) continue;
        $name = mat_free_name($db, $cid, $to, $f['name'], $id);
        $db->prepare('UPDATE g7_client_files SET folder = ?, name = ? WHERE id = ?')->execute([$to, $name, $id]); $n++;
    }
    mat_out(['ok' => true, 'moved' => $n]);
}

if ($act === 'delete_file') {
    $ids = array_slice(array_values(array_filter(array_map('intval', (array)($in['ids'] ?? [$in['id'] ?? 0])))), 0, 500);
    $n = 0; $denied = 0;
    foreach ($ids as $id) {
        $st = $db->prepare('SELECT * FROM g7_client_files WHERE id = ?'); $st->execute([$id]); $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) continue;
        if (!mat_can_delete($f, $uid, $isAdmin)) { $denied++; continue; }
        @unlink(mat_path($f)); @unlink(CLI_DIR . '/' . (int)$f['client_id'] . '/.th_' . pathinfo(basename($f['stored']), PATHINFO_FILENAME) . '.webp');
        $db->prepare('DELETE FROM g7_client_files WHERE id = ?')->execute([$id]); $n++;
    }
    if (!$n && $denied) mat_fail('Solo un admin o quien subió el archivo puede borrarlo');
    mat_out(['ok' => true, 'deleted' => $n, 'denied' => $denied]);
}

mat_fail('Acción desconocida');
