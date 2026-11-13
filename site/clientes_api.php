<?php
/**
 * clientes_api.php — Clientes de cronos: fichas, servicios, notas y archivos.
 * La usa admin_clientes.js (página clientes.php). Entra quien tenga el módulo "Clientes" (por defecto, solo admins).
 * Los archivos viven en _private/clientes/<id>/ (carpeta bloqueada para la web) y solo se sirven por acá, con sesión.
 */
require_once __DIR__ . '/auth.php';
if (function_exists('g7_site_has') && !g7_site_has('clientes')) { http_response_code(404); exit; }   // sin la función por sitio (cronos antes de unificar): Clientes siempre está
$me = getCurrentUser();
if (!$me || !g7_can((int)$me['id'], 'clientes')) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['error' => 'No tienes acceso a Clientes']); exit; }
$db = getDB();
require_once __DIR__ . '/clientes_lib.php';   // tablas, contenidos, facturas y envío de archivos (también los usa el dashboard)
cli_install($db);
require_once __DIR__ . '/custom_fields_lib.php';   // tipos de cliente, campos propios y campos fijos ocultos (rubros)
cf_install($db);

/** Tamaño en bytes de un valor de php.ini como "8M". */
function cli_ini_bytes(string $k): int {
    $v = trim((string)ini_get($k)); $n = (int)$v;
    return match (strtoupper(substr($v, -1))) { 'G' => $n << 30, 'M' => $n << 20, 'K' => $n << 10, default => $n };
}
function cli_out(array $v): void { header('Content-Type: application/json; charset=utf-8'); echo json_encode($v, JSON_UNESCAPED_UNICODE); exit; }
function cli_fail(string $m, int $c = 400): void { http_response_code($c); cli_out(['error' => $m]); }
function cli_str($v, int $max): string { return mb_substr(trim((string)$v), 0, $max); }
function cli_date($v): ?string { $v = trim((string)$v); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; }
/** Campos de una factura desde el formulario: el total es el monto base (número o cuenta, ej. 160*2) menos el descuento. */
function cli_inv_fields(array $s): array {
    $expr = cli_str($s['subtotal_expr'] ?? '', 80);
    $sub = cli_calc($expr);
    [$disc, $dAmt] = cli_discount((string)($s['discount'] ?? ''), (float)$sub);
    return [
        'number' => cli_str($s['number'] ?? '', 60), 'concept' => cli_str($s['concept'] ?? '', 250),
        'issue_date' => cli_date($s['issue_date'] ?? ''), 'due_date' => cli_date($s['due_date'] ?? ''),
        'currency' => in_array($s['currency'] ?? '', CLI_CURRENCIES, true) ? $s['currency'] : 'USD',
        'period' => cli_str($s['period'] ?? '', 40), 'description' => cli_str($s['description'] ?? '', 4000),
        'subtotal' => (float)$sub, 'subtotal_expr' => $expr, 'discount' => $disc, 'amount' => round(max(0, (float)$sub - $dAmt), 2),
    ];
}
/** Una factura con su estado y pagos calculados (null si no es de ese cliente). */
function cli_invoice_of(PDO $db, int $cid, int $iid): ?array {
    foreach (cli_invoices($db, $cid) as $i) if ((int)$i['id'] === $iid) return $i;
    return null;
}
/** Las facturas hechas en Facturación (con líneas) no se tocan con el formulario de una línea de esta ficha. */
function cli_fact_guard(PDO $db, int $iid, string $msg): void {
    try { $st = $db->prepare('SELECT source FROM g7_client_invoices WHERE id = ?'); $st->execute([$iid]); $src = $st->fetchColumn(); }
    catch (Throwable $e) { return; }   // todavía no existe Facturación
    if ($src === 'fact') cli_fail($msg, 409);
}
/** Una factura hecha en Facturación solo se cobra, se marca pagada o se borra con el permiso de editar de Facturación. */
function cli_fact_perm(PDO $db, int $iid, array $me): void {
    try { $st = $db->prepare('SELECT source FROM g7_client_invoices WHERE id = ?'); $st->execute([$iid]); $src = $st->fetchColumn(); }
    catch (Throwable $e) { return; }
    if ($src === 'fact' && !g7_can((int)$me['id'], 'facturacion', 'edit')) cli_fail('Esa factura es de Facturación: necesitas permiso para editar Facturación.', 403);
}
/** Lo que se hace desde la ficha sobre una factura de Facturación también queda en su historial. */
function cli_fact_log(PDO $db, int $iid, int $uid, string $action, string $summary): void {
    try {
        $st = $db->prepare('SELECT source FROM g7_client_invoices WHERE id = ?'); $st->execute([$iid]);
        if ($st->fetchColumn() === 'fact') $db->prepare('INSERT INTO g7_invoice_events (invoice_id, user_id, action, summary) VALUES (?,?,?,?)')->execute([$iid, $uid, $action, $summary . ' (desde la ficha del cliente)']);
    } catch (Throwable $e) {}
}
/** ¿Otra factura ya tiene ese número? (también las de Facturación: los números no se repiten) */
function cli_number_taken(PDO $db, string $number, int $exceptId = 0): bool {
    if ($number === '') return false;
    $st = $db->prepare('SELECT COUNT(*) FROM g7_client_invoices WHERE number = ? AND id <> ?'); $st->execute([$number, $exceptId]);
    return (int)$st->fetchColumn() > 0;
}
/** Número siguiente al último: mira todas las facturas con el mismo prefijo (FAC-008 y FAC-0008 son el mismo número). */
function cli_next_free(PDO $db, string $last, string $prefix): string {
    $ref = cli_next_ref($last, $prefix);
    if (!preg_match('/^(.*?)(\d+)$/', $ref, $m)) return $ref;
    $st = $db->prepare('SELECT number FROM g7_client_invoices WHERE number LIKE ?'); $st->execute([addcslashes($m[1], '\\%_') . '%']);
    $max = (int)$m[2] - 1;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) { $rest = substr((string)$n, strlen($m[1])); if ($rest !== '' && ctype_digit($rest)) $max = max($max, (int)$rest); }
    return $m[1] . str_pad((string)($max + 1), strlen($m[2]), '0', STR_PAD_LEFT);
}
function cli_client(PDO $db, int $id): array {
    $st = $db->prepare('SELECT * FROM g7_clients WHERE id = ?'); $st->execute([$id]);
    $c = $st->fetch(PDO::FETCH_ASSOC); if (!$c) cli_fail('Ese cliente no existe', 404);
    $c['brand_colors'] = json_decode($c['brand_colors'] ?? '[]', true) ?: [];
    return $c;
}

// ── Archivos: se sirven solo acá (GET ?file=ID[&dl=1]) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['file'])) {
    $st = $db->prepare('SELECT * FROM g7_client_files WHERE id = ?'); $st->execute([(int)$_GET['file']]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    $path = $f ? CLI_DIR . '/' . (int)$f['client_id'] . '/' . basename($f['stored']) : '';
    if (!$f || !is_file($path)) { http_response_code(404); exit('No existe'); }
    $inline = !isset($_GET['dl']) && preg_match('#^(image/(png|jpeg|webp|gif|avif)|application/pdf)$#', $f['mime']);
    header('Content-Type: ' . ($inline ? $f['mime'] : 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($f['name']));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
    header('Cache-Control: private, max-age=3600');
    readfile($path); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['inv'])) {
    $st = $db->prepare('SELECT client_id, file_name, file_stored FROM g7_client_invoices WHERE id = ?'); $st->execute([(int)$_GET['inv']]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f || $f['file_stored'] === '') { http_response_code(404); exit('No existe'); }
    cli_send_file(CLI_DIR . '/' . (int)$f['client_id'] . '/' . basename($f['file_stored']), $f['file_name'], null, isset($_GET['dl']));
}
// Factura para imprimir o guardar como PDF (?print=ID) y expediente con el historial (?report=ID de cliente)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['print'])) {
    $st = $db->prepare('SELECT client_id FROM g7_client_invoices WHERE id = ?'); $st->execute([(int)$_GET['print']]);
    $c = cli_client($db, (int)$st->fetchColumn()); $i = cli_invoice_of($db, (int)$c['id'], (int)$_GET['print']);
    if (!$i) { http_response_code(404); exit('No existe'); }
    cli_print_page('Factura ' . ($i['number'] ?: '') . ' · ' . $c['name'], cli_invoice_card($i, $c, cli_billing($db), ['pay' => true]));
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['report'])) {
    $c = cli_client($db, (int)$_GET['report']);
    cli_print_page('Historial · ' . $c['name'], cli_report_html($c, cli_invoices($db, (int)$c['id']), cli_billing($db)), false);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cli_fail('Método no permitido', 405);

// ── Subida de archivos (multipart) ─────────────────────────────
// Si el envío supera post_max_size, PHP llega sin datos: se avisa en vez de "acción desconocida"
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/'))
    cli_fail('Los archivos juntos superan lo que acepta el servidor (' . ini_get('post_max_size') . '). Subilos de a menos.');
if (($_POST['action'] ?? '') === 'inv_save') {
    $cid = (int)($_POST['client_id'] ?? 0); cli_client($db, $cid);
    $f = cli_inv_fields($_POST);
    if ($f['subtotal'] <= 0) cli_fail('El monto base no es válido: pon un número o una cuenta, por ejemplo 160 o 160*2');
    $iid = (int)($_POST['id'] ?? 0);
    if ($iid) cli_fact_guard($db, $iid, 'Esta factura se hizo en Facturación: editala desde ahí para no perder sus líneas.');
    if (cli_number_taken($db, $f['number'], $iid)) cli_fail('El número ' . $f['number'] . ' ya lo tiene otra factura. Usa otro.');
    if ($iid) $db->prepare('UPDATE g7_client_invoices SET ' . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f))) . ' WHERE id = ? AND client_id = ?')->execute([...array_values($f), $iid, $cid]);
    else {
        $db->prepare('INSERT INTO g7_client_invoices (`' . implode('`,`', array_keys($f)) . '`, client_id, created_by) VALUES (' . str_repeat('?,', count($f)) . '?, ?)')->execute([...array_values($f), $cid, (int)$me['id']]);
        $iid = (int)$db->lastInsertId();
    }
    $up = $_FILES['file'] ?? null;
    if ($up && $up['error'] !== UPLOAD_ERR_NO_FILE) {
        if (in_array($up['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) cli_fail('El archivo de la factura supera lo que acepta el servidor (' . ini_get('upload_max_filesize') . '). La factura quedó guardada sin archivo.');
        $ext = strtolower(pathinfo((string)$up['name'], PATHINFO_EXTENSION));
        if ($up['error'] !== UPLOAD_ERR_OK || !in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'webp'], true)) cli_fail('La factura tiene que ser PDF o imagen. Quedó guardada sin archivo.');
        $dir = CLI_DIR . '/' . $cid; if (!is_dir($dir)) mkdir($dir, 0755, true);
        $old = $db->prepare('SELECT file_stored FROM g7_client_invoices WHERE id = ? AND client_id = ?'); $old->execute([$iid, $cid]); $old = (string)$old->fetchColumn();
        $stored = 'inv_' . bin2hex(random_bytes(10)) . '.' . $ext;
        if (!move_uploaded_file($up['tmp_name'], "$dir/$stored")) cli_fail('No se pudo guardar el archivo de la factura');
        if ($old && is_file("$dir/" . basename($old))) unlink("$dir/" . basename($old));
        $db->prepare('UPDATE g7_client_invoices SET file_name = ?, file_stored = ? WHERE id = ? AND client_id = ?')->execute([cli_str(basename((string)$up['name']), 200), $stored, $iid, $cid]);
    }
    cli_out(['ok' => true, 'id' => $iid]);
}
if (($_POST['action'] ?? '') === 'upload') {
    $cid = (int)($_POST['client_id'] ?? 0); cli_client($db, $cid);
    $kind = in_array($_POST['kind'] ?? '', CLI_FILE_KINDS, true) ? $_POST['kind'] : 'otro';
    $files = $_FILES['files'] ?? null;
    if (!$files || !is_array($files['name'])) cli_fail('No llegó ningún archivo');
    $dir = CLI_DIR . '/' . $cid;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) cli_fail('No se pudo crear la carpeta del cliente', 500);
    $ok = []; $bad = [];
    foreach ($files['name'] as $i => $orig) {
        $orig = cli_str(basename((string)$orig), 200);
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (in_array($files['error'][$i], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) { $bad[] = "$orig (más grande que lo que acepta el servidor: " . ini_get('upload_max_filesize') . ')'; continue; }
        if ($files['error'][$i] !== UPLOAD_ERR_OK) { $bad[] = "$orig (no se pudo subir)"; continue; }
        if ($files['size'][$i] > CLI_MAX_FILE) { $bad[] = "$orig (más de 25 MB)"; continue; }
        if (!in_array($ext, CLI_EXT, true)) { $bad[] = "$orig (tipo no permitido)"; continue; }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($files['tmp_name'][$i]) ?: 'application/octet-stream';
        $stored = bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file($files['tmp_name'][$i], "$dir/$stored")) { $bad[] = "$orig (no se pudo guardar)"; continue; }
        // En Materiales cada tipo va a su carpeta (Logos, Manual de marca); lo demás, a la carpeta principal del cliente
        $folder = ['logo' => 'Logos', 'manual' => 'Manual de marca'][$kind] ?? '';
        $db->prepare('INSERT INTO g7_client_files (client_id, name, stored, mime, size, kind, folder, uploaded_by) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$cid, $orig, $stored, $mime, (int)$files['size'][$i], $kind, $folder, (int)$me['id']]);
        $ok[] = $orig;
    }
    $db->prepare('UPDATE g7_clients SET updated_at = NOW() WHERE id = ?')->execute([$cid]);
    cli_out(['ok' => true, 'subidos' => $ok, 'rechazados' => $bad]);
}

// Foto de perfil del cliente: llega ya achicada desde el navegador (g7PrepImage) y queda pública en client_avatars/
if (($_POST['action'] ?? '') === 'avatar') {
    $c = cli_client($db, (int)($_POST['client_id'] ?? 0)); $cid = (int)$c['id'];
    $dir = __DIR__ . '/client_avatars';
    // Carpeta pública de fotos: sin ejecutar código y sin listado (la carpeta es de cada sitio y no viaja en las versiones)
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_file($dir . '/.htaccess') && is_file(__DIR__ . '/avatars/.htaccess')) @copy(__DIR__ . '/avatars/.htaccess', $dir . '/.htaccess');
    $old = cli_avatar_url($c);
    if (!empty($_POST['remove'])) {
        if ($old) @unlink($dir . '/' . basename($old));
        $db->prepare("UPDATE g7_clients SET avatar = '', updated_at = NOW() WHERE id = ?")->execute([$cid]);
        cli_out(['ok' => true, 'avatar' => '']);
    }
    $f = $_FILES['image'] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) cli_fail('No llegó ninguna imagen');
    if ($why = g7_upload_problem($f)) cli_fail($why);
    $ext = g7_upload_ext($f['name'], G7_EXT_IMG);
    $info = @getimagesize($f['tmp_name']);
    if (!$ext || !$info) cli_fail('Tiene que ser una imagen PNG, JPG, WebP o GIF');
    $nm = 'c' . $cid . '_' . time() . '.' . $ext;
    if ($why = g7_upload_store($f, $dir, $nm)) cli_fail($why, 500);
    if ($old) @unlink($dir . '/' . basename($old));
    $url = '/toolz/client_avatars/' . $nm;
    $db->prepare('UPDATE g7_clients SET avatar = ?, updated_at = NOW() WHERE id = ?')->execute([$url, $cid]);
    cli_out(['ok' => true, 'avatar' => $url]);
}

// ── Acciones JSON ──────────────────────────────────────────────
$in = json_decode(file_get_contents('php://input'), true) ?? [];
$act = (string)($in['action'] ?? '');

if ($act === 'list') {
    $rows = $db->query("SELECT c.id, c.name, c.status, c.type, c.contact_name, c.email, c.phone, c.updated_at, c.avatar,
            (SELECT COUNT(*) FROM g7_client_services s WHERE s.client_id = c.id) AS svc_total,
            (SELECT COUNT(*) FROM g7_client_services s WHERE s.client_id = c.id AND s.status = 'en_curso') AS svc_active,
            (SELECT f.id FROM g7_client_files f WHERE f.client_id = c.id AND f.kind = 'logo' AND f.mime LIKE 'image/%' AND f.mime <> 'image/svg+xml' ORDER BY f.id DESC LIMIT 1) AS logo_id
        FROM g7_clients c ORDER BY c.updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    // Lo que el servidor deja subir de verdad (puede ser menos que los 25 MB de la herramienta)
    cli_out(['clients' => $rows, 'limits' => ['file' => min(CLI_MAX_FILE, cli_ini_bytes('upload_max_filesize')), 'post' => cli_ini_bytes('post_max_size'), 'count' => (int)ini_get('max_file_uploads')]]);
}

if ($act === 'get') {
    $c = cli_client($db, (int)($in['id'] ?? 0)); $id = (int)$c['id'];
    $q = function (string $sql) use ($db, $id) { $s = $db->prepare($sql); $s->execute([$id]); return $s->fetchAll(PDO::FETCH_ASSOC); };
    $portal = ['email' => $c['portal_email'] ?? '', 'on' => (int)($c['portal_on'] ?? 0), 'last' => $c['portal_last'] ?? null, 'has_pass' => ($c['portal_pass'] ?? '') !== ''];
    unset($c['portal_pass'], $c['portal_email'], $c['portal_on'], $c['portal_last']);
    cli_out([
        // has: lo que trae esta instalación (la edición Lite no trae facturas ni portal de clientes)
        'has' => ['facturas' => is_file(__DIR__ . '/facturacion/index.php'), 'portal' => is_file(__DIR__ . '/portal/index.php')],
        'client' => $c + ['custom' => cf_values($db, 'client', $id)], 'portal' => $portal, 'invoices' => cli_invoices($db, $id), 'contents' => cli_contents($db, $c['name']), 'billing' => cli_billing($db), 'sys' => cli_sys_name(),
        'fact_ok' => isset(g7_modules()['facturacion']) && g7_can((int)$me['id'], 'facturacion'),
        // Borradores de Facturación: el cliente no los ve, pero en la ficha se muestran aparte para no perderlos de vista
        'fact_drafts' => (function () use ($db, $id) { try { $s = $db->prepare("SELECT id, concept, amount, currency, COALESCE(updated_at, created_at) AS updated_at FROM g7_client_invoices WHERE client_id = ? AND source = 'fact' AND state = 'borrador' ORDER BY id DESC"); $s->execute([$id]); return $s->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; } })(),
        'services' => $q("SELECT * FROM g7_client_services WHERE client_id = ? ORDER BY (status = 'terminado'), COALESCE(start_date, '9999-12-31') DESC, id DESC"),
        'notes' => $q("SELECT n.id, n.body, n.created_at, COALESCE(NULLIF(u.display_name, ''), u.username) AS author FROM g7_client_notes n LEFT JOIN g7_users u ON u.id = n.user_id WHERE n.client_id = ? ORDER BY n.id DESC"),
        'files' => $q("SELECT id, name, mime, size, kind, created_at FROM g7_client_files WHERE client_id = ? ORDER BY kind = 'logo' DESC, id DESC"),
    ]);
}

if ($act === 'save') {
    $d = is_array($in['client'] ?? null) ? $in['client'] : [];
    $name = cli_str($d['name'] ?? '', 160);
    if ($name === '') cli_fail('Falta el nombre del cliente o la marca');
    $email = cli_str($d['email'] ?? '', 160);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) cli_fail('El email no es válido');
    $colors = array_values(array_slice(array_filter(is_array($d['brand_colors'] ?? null) ? $d['brand_colors'] : [], fn($c) => is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c)), 0, 12));
    $f = [
        'name' => $name, 'status' => st_has('clients', (string)($d['status'] ?? '')) ? $d['status'] : st_default('clients'),
        'type' => in_array((string)($d['type'] ?? ''), array_column(cf_client_types(), 'key'), true) ? $d['type'] : '',
        'contact_name' => cli_str($d['contact_name'] ?? '', 120), 'email' => $email,
        'phone' => cli_str($d['phone'] ?? '', 60), 'whatsapp' => cli_str($d['whatsapp'] ?? '', 60),
        'legal_name' => cli_str($d['legal_name'] ?? '', 200), 'cuit' => cli_str($d['cuit'] ?? '', 20), 'iva_cond' => cli_str($d['iva_cond'] ?? '', 40),
        'address' => cli_str($d['address'] ?? '', 200), 'city' => cli_str($d['city'] ?? '', 100), 'province' => cli_str($d['province'] ?? '', 100),
        'website' => cli_str($d['website'] ?? '', 250), 'instagram' => cli_str($d['instagram'] ?? '', 250), 'facebook' => cli_str($d['facebook'] ?? '', 250),
        'tiktok' => cli_str($d['tiktok'] ?? '', 250), 'linkedin' => cli_str($d['linkedin'] ?? '', 250), 'youtube' => cli_str($d['youtube'] ?? '', 250),
        'other_links' => cli_str($d['other_links'] ?? '', 4000), 'brand_colors' => json_encode($colors), 'brand_fonts' => cli_str($d['brand_fonts'] ?? '', 250),
        'notes' => cli_str($d['notes'] ?? '', 8000),
    ];
    $custom = is_array($d['custom'] ?? null) ? $d['custom'] : [];
    if ($why = cf_set_values($db, 'client', 0, $custom, true, true)) cli_fail($why);   // los campos propios se validan antes de guardar nada
    $id = (int)($d['id'] ?? 0);
    if ($id) {
        $prevRow = cli_client($db, $id); $prev = $prevRow['name'];
        if (($prevRow['status'] ?? '') === 'terminado' && $f['status'] !== 'terminado' && ($why = lic_can_add('clients', $db))) cli_fail($why);   // reactivar cuenta para el tope
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
        if ($prev !== $name) try {
            $db->prepare('UPDATE g7_content SET project = ? WHERE project = ?')->execute([$name, $prev]);
            $db->prepare('UPDATE g7_content SET projects = REPLACE(projects, ?, ?) WHERE projects LIKE ?')
               ->execute([json_encode($prev, JSON_UNESCAPED_UNICODE), json_encode($name, JSON_UNESCAPED_UNICODE), '%' . json_encode($prev, JSON_UNESCAPED_UNICODE) . '%']);
        } catch (Throwable $e) {}
        $db->prepare("UPDATE g7_clients SET $set, updated_at = NOW() WHERE id = ?")->execute([...array_values($f), $id]);
    } else {
        if ($f['status'] !== 'terminado' && ($why = lic_can_add('clients', $db))) cli_fail($why);   // tope de clientes del plan
        $cols = array_keys($f);
        $db->prepare('INSERT INTO g7_clients (`' . implode('`,`', $cols) . '`, created_by) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ', ?)')
           ->execute([...array_values($f), (int)$me['id']]);
        $id = (int)$db->lastInsertId();
        cli_drive_init($db, $id, (int)$me['id']);   // su carpeta en Materiales, con Logos, Tipografías, etc.
    }
    cf_set_values($db, 'client', $id, $custom);
    cli_out(['ok' => true, 'id' => $id]);
}

if ($act === 'delete') {
    $c = cli_client($db, (int)($in['id'] ?? 0)); $id = (int)$c['id'];
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM g7_client_invoices WHERE client_id = ? AND source = 'fact' AND state <> 'borrador'"); $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) cli_fail('Este cliente tiene facturas emitidas en Facturación: no se puede borrar. Puedes archivarlo.', 409);
    } catch (PDOException $e) {}   // todavía no existe Facturación
    foreach (['g7_client_services', 'g7_client_notes', 'g7_client_files', 'g7_client_folders', 'g7_client_invoices', 'g7_client_payments'] as $t) $db->prepare("DELETE FROM $t WHERE client_id = ?")->execute([$id]);
    $db->prepare('DELETE FROM g7_clients WHERE id = ?')->execute([$id]);
    cf_delete_values($db, 'client', $id);
    if ($av = cli_avatar_url($c)) @unlink(__DIR__ . '/client_avatars/' . basename($av));
    $dir = CLI_DIR . '/' . $id;   // también las miniaturas de Materiales (.th_*), que glob('*') no ve
    if (is_dir($dir)) { foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $p) if (is_file("$dir/$p")) unlink("$dir/$p"); @rmdir($dir); }
    cli_out(['ok' => true]);
}

if ($act === 'svc_save') {
    $s = is_array($in['service'] ?? null) ? $in['service'] : [];
    $cid = (int)($s['client_id'] ?? 0); cli_client($db, $cid);
    $name = cli_str($s['name'] ?? '', 160); if ($name === '') cli_fail('Falta el nombre del servicio o proyecto');
    $f = [$name, cli_str($s['type'] ?? '', 40), st_has('services', (string)($s['status'] ?? '')) ? $s['status'] : st_default('services'),
          cli_date($s['start_date'] ?? ''), cli_date($s['end_date'] ?? ''), cli_str($s['notes'] ?? '', 4000)];
    $sid = (int)($s['id'] ?? 0);
    if ($sid) $db->prepare('UPDATE g7_client_services SET name=?, type=?, status=?, start_date=?, end_date=?, notes=? WHERE id=? AND client_id=?')->execute([...$f, $sid, $cid]);
    else { $db->prepare('INSERT INTO g7_client_services (name, type, status, start_date, end_date, notes, client_id) VALUES (?,?,?,?,?,?,?)')->execute([...$f, $cid]); $sid = (int)$db->lastInsertId(); }
    $db->prepare('UPDATE g7_clients SET updated_at = NOW() WHERE id = ?')->execute([$cid]);
    cli_out(['ok' => true, 'id' => $sid]);
}
if ($act === 'svc_delete') {
    $db->prepare('DELETE FROM g7_client_services WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
    cli_out(['ok' => true]);
}

if ($act === 'note_add') {
    $cid = (int)($in['client_id'] ?? 0); cli_client($db, $cid);
    $body = cli_str($in['body'] ?? '', 8000); if ($body === '') cli_fail('La nota está vacía');
    $db->prepare('INSERT INTO g7_client_notes (client_id, user_id, body) VALUES (?,?,?)')->execute([$cid, (int)$me['id'], $body]);
    $db->prepare('UPDATE g7_clients SET updated_at = NOW() WHERE id = ?')->execute([$cid]);
    cli_out(['ok' => true]);
}
if ($act === 'note_delete') {
    $db->prepare('DELETE FROM g7_client_notes WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
    cli_out(['ok' => true]);
}

if ($act === 'file_kind') {
    $kind = in_array($in['kind'] ?? '', CLI_FILE_KINDS, true) ? $in['kind'] : 'otro';
    $db->prepare('UPDATE g7_client_files SET kind = ? WHERE id = ?')->execute([$kind, (int)($in['id'] ?? 0)]);
    cli_out(['ok' => true]);
}
if ($act === 'file_delete') {
    $st = $db->prepare('SELECT * FROM g7_client_files WHERE id = ?'); $st->execute([(int)($in['id'] ?? 0)]);
    if ($f = $st->fetch(PDO::FETCH_ASSOC)) {
        $p = CLI_DIR . '/' . (int)$f['client_id'] . '/' . basename($f['stored']);
        if (is_file($p)) unlink($p);
        $db->prepare('DELETE FROM g7_client_files WHERE id = ?')->execute([(int)$f['id']]);
    }
    cli_out(['ok' => true]);
}

// ── Acceso al dashboard ───────────────────────────────────────
if ($act === 'access_save') {
    $cid = (int)($in['client_id'] ?? 0); cli_client($db, $cid);
    $email = mb_strtolower(cli_str($in['email'] ?? '', 160));
    $on = !empty($in['on']) ? 1 : 0; $pass = (string)($in['password'] ?? '');
    if ($on && !filter_var($email, FILTER_VALIDATE_EMAIL)) cli_fail('Pon un email válido para el acceso');
    if ($email !== '') {
        $dup = $db->prepare('SELECT name FROM g7_clients WHERE portal_email = ? AND id <> ?'); $dup->execute([$email, $cid]);
        if ($n = $dup->fetchColumn()) cli_fail("Ese email ya es el acceso de \"$n\"");
    }
    if ($pass !== '' && mb_strlen($pass) < 8) cli_fail('La contraseña tiene que tener al menos 8 caracteres');
    $cur = $db->prepare('SELECT portal_pass FROM g7_clients WHERE id = ?'); $cur->execute([$cid]);
    if ($on && $pass === '' && (string)$cur->fetchColumn() === '') cli_fail('Pon una contraseña para activar el acceso');
    $db->prepare('UPDATE g7_clients SET portal_email = ?, portal_on = ?' . ($pass !== '' ? ', portal_pass = ?' : '') . ' WHERE id = ?')
       ->execute($pass !== '' ? [$email, $on, password_hash($pass, PASSWORD_DEFAULT), $cid] : [$email, $on, $cid]);
    cli_out(['ok' => true]);
}

// ── Facturas y pagos ──────────────────────────────────────────
if ($act === 'inv_delete') {
    cli_fact_guard($db, (int)($in['id'] ?? 0), 'Esta factura se hizo en Facturación: ahí se cancela (las emitidas no se borran).');
    $st = $db->prepare('SELECT client_id, file_stored FROM g7_client_invoices WHERE id = ?'); $st->execute([(int)($in['id'] ?? 0)]);
    if ($f = $st->fetch(PDO::FETCH_ASSOC)) {
        if ($f['file_stored'] !== '') @unlink(CLI_DIR . '/' . (int)$f['client_id'] . '/' . basename($f['file_stored']));
        $pq = $db->prepare('SELECT id FROM g7_client_payments WHERE invoice_id = ?'); $pq->execute([(int)$in['id']]);
        try { foreach ($pq->fetchAll(PDO::FETCH_COLUMN) as $pid) cli_payment_delete($db, (int)$pid, (int)$me['id']); }
        catch (InvalidArgumentException $e) { cli_fail('Esta factura tiene un pago que entró en línea: primero devuélvelo desde Finanzas y pagos.', 409); }
        $db->prepare('DELETE FROM g7_client_invoices WHERE id = ?')->execute([(int)$in['id']]);
    }
    cli_out(['ok' => true]);
}
if ($act === 'pay_save') {
    $st = $db->prepare('SELECT client_id FROM g7_client_invoices WHERE id = ?'); $st->execute([(int)($in['invoice_id'] ?? 0)]);
    $cid = (int)$st->fetchColumn(); if (!$cid) cli_fail('Esa factura no existe', 404);
    cli_fact_perm($db, (int)$in['invoice_id'], $me);
    $amount = round((float)str_replace(',', '.', (string)($in['amount'] ?? '0')), 2); if ($amount <= 0) cli_fail('Pon el monto del pago');
    try { cli_record_payment($db, (int)$in['invoice_id'], $amount, cli_date($in['pay_date'] ?? '') ?? date('Y-m-d'),
                             in_array($in['method'] ?? '', CLI_PAY_METHODS, true) ? $in['method'] : 'Otro', cli_str($in['note'] ?? '', 250), (int)$me['id']); }
    catch (InvalidArgumentException $e) { cli_fail($e->getMessage(), 409); }
    cli_fact_log($db, (int)$in['invoice_id'], (int)$me['id'], 'payment', 'Pago de ' . number_format($amount, 2, ',', '.'));
    cli_out(['ok' => true]);
}
// Vista previa en vivo mientras se edita (no guarda nada)
if ($act === 'inv_preview') {
    $c = cli_client($db, (int)($in['client_id'] ?? 0)); $src = is_array($in['inv'] ?? null) ? $in['inv'] : [];
    $base = cli_invoice_of($db, (int)$c['id'], (int)($src['id'] ?? 0)) ?? ['payments' => [], 'paid' => 0, 'paid_date' => null, 'file_name' => ''];
    if (($base['source'] ?? '') === 'fact') { $i = $base; $f = ['subtotal' => (float)$base['subtotal'], 'amount' => (float)$base['amount']]; }   // se muestra tal cual: se edita en Facturación
    else {
    $f = cli_inv_fields($src); $i = array_merge($base, $f);
    $i['balance'] = round($i['amount'] - $base['paid'], 2);
    $i['discount_amount'] = round($f['subtotal'] - $f['amount'], 2);
    $i['status'] = $base['paid'] > 0 && $i['balance'] <= 0.004 ? 'pagada' : ($i['due_date'] && $i['due_date'] < date('Y-m-d') ? 'vencida' : ($base['paid'] > 0 ? 'parcial' : 'pendiente'));
    }
    $doc = '<!doctype html><html><head><meta charset="utf-8"><link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">'
         . '<style>body{margin:0;padding:0 0 4px;background:transparent;font-family:Montserrat,system-ui,sans-serif}' . CLI_INV_CSS . '</style></head><body>'
         . cli_invoice_card($i, $c, cli_billing($db), ['pay' => true]) . '</body></html>';
    cli_out(['html' => $doc, 'subtotal' => $f['subtotal'], 'total' => $f['amount'], 'valid' => $f['subtotal'] > 0]);
}
// "Generar siguiente mes": copia la última factura con la referencia siguiente y fecha de hoy
if ($act === 'inv_next') {
    $c = cli_client($db, (int)($in['client_id'] ?? 0)); $cid = (int)$c['id']; $b = cli_billing($db);
    // La base es la última de una línea: las de Facturación se repiten con Duplicar, desde Facturación
    $last = array_values(array_filter(cli_invoices($db, $cid), fn($i) => ($i['source'] ?? 'clientes') !== 'fact'))[0] ?? null;
    if (!$last) cli_fail('Primero carga la primera factura de este cliente (las de Facturación se repiten con Duplicar, desde Facturación)');
    $issue = date('Y-m-d'); $due = null;
    if ($last['issue_date'] && $last['due_date']) $due = date('Y-m-d', strtotime($issue) + max(0, strtotime($last['due_date']) - strtotime($last['issue_date'])));
    $num = cli_next_free($db, (string)$last['number'], $b['prefix']);   // salta los que ya usó Facturación
    $f = ['number' => $num, 'concept' => $last['concept'], 'issue_date' => $issue, 'due_date' => $due,
          'currency' => $last['currency'], 'period' => $b['period'], 'description' => (string)$last['description'],
          'subtotal' => (float)$last['subtotal'], 'subtotal_expr' => $last['subtotal_expr'] !== '' ? $last['subtotal_expr'] : (string)(float)$last['subtotal'],
          'discount' => '', 'amount' => (float)$last['subtotal']];
    $db->prepare('INSERT INTO g7_client_invoices (`' . implode('`,`', array_keys($f)) . '`, client_id, created_by) VALUES (' . str_repeat('?,', count($f)) . '?, ?)')->execute([...array_values($f), $cid, (int)$me['id']]);
    cli_out(['ok' => true, 'id' => (int)$db->lastInsertId(), 'number' => $f['number']]);
}
// Marcar como pagada: registra un pago por lo que falta
if ($act === 'inv_paid') {
    $st = $db->prepare('SELECT client_id FROM g7_client_invoices WHERE id = ?'); $st->execute([(int)($in['id'] ?? 0)]);
    $cid = (int)$st->fetchColumn(); $i = $cid ? cli_invoice_of($db, $cid, (int)$in['id']) : null;
    if (!$i) cli_fail('Esa factura no existe', 404);
    cli_fact_perm($db, (int)$in['id'], $me);
    if (in_array($i['state'] ?? 'emitida', ['borrador', 'cancelada'], true)) cli_fail('No se puede marcar como pagada una factura en borrador o cancelada.', 409);
    if ($i['balance'] <= 0.004) cli_fail('Esa factura ya está pagada');
    try { cli_record_payment($db, (int)$i['id'], null, cli_date($in['pay_date'] ?? '') ?? date('Y-m-d'), in_array($in['method'] ?? '', CLI_PAY_METHODS, true) ? $in['method'] : 'Otro', '', (int)$me['id']); }
    catch (InvalidArgumentException $e) { cli_fail($e->getMessage(), 409); }
    cli_fact_log($db, (int)$i['id'], (int)$me['id'], 'payment', 'Marcada como pagada');
    cli_out(['ok' => true]);
}
// Finalizar (o reabrir) el contrato: el cliente ve el mensaje de cierre en su dashboard
if ($act === 'contract') {
    $c = cli_client($db, (int)($in['client_id'] ?? 0));
    if (!empty($in['close'])) $db->prepare("UPDATE g7_clients SET status = 'terminado', closed_date = CURDATE(), updated_at = NOW() WHERE id = ?")->execute([(int)$c['id']]);
    else $db->prepare("UPDATE g7_clients SET status = 'activo', closed_date = NULL, updated_at = NOW() WHERE id = ?")->execute([(int)$c['id']]);
    cli_out(['ok' => true]);
}
// Datos de facturación: emisor, medios de pago, textos
if ($act === 'billing_get') cli_out(['billing' => cli_billing($db)]);
if ($act === 'billing_save') {
    $d = is_array($in['billing'] ?? null) ? $in['billing'] : []; $out = [];
    foreach (CLI_BILLING_DEFAULT as $k => $def) if (!is_array($def)) $out[$k] = cli_str($d[$k] ?? '', in_array($k, ['description', 'closing_msg'], true) ? 4000 : 300);
    foreach (['logo', 'footer_url'] as $k) if ($out[$k] !== '' && !preg_match('#^(https?://|/)#i', $out[$k])) cli_fail('El ' . ($k === 'logo' ? 'logo' : 'link del pie') . ' tiene que ser una dirección que empiece con https://');
    if ($out['issuer'] === '') cli_fail('Falta el nombre de quien factura');
    $out['currency'] = in_array($out['currency'], CLI_CURRENCIES, true) ? $out['currency'] : 'USD';
    $out['methods'] = [];
    foreach (array_slice(is_array($d['methods'] ?? null) ? $d['methods'] : [], 0, 6) as $m) {
        $items = [];
        foreach (array_slice(is_array($m['items'] ?? null) ? $m['items'] : [], 0, 5) as $it) {
            $v = cli_str($it['value'] ?? '', 300); if ($v !== '') $items[] = ['label' => cli_str($it['label'] ?? '', 80), 'value' => $v];
        }
        $t = cli_str($m['title'] ?? '', 80);
        if ($t !== '' || $items) $out['methods'][] = ['title' => $t, 'items' => $items];
    }
    $db->prepare("INSERT INTO g7_config (cfg_key, cfg_value) VALUES ('cli_billing', ?) ON DUPLICATE KEY UPDATE cfg_value = VALUES(cfg_value)")->execute([json_encode($out, JSON_UNESCAPED_UNICODE)]);
    cli_out(['ok' => true, 'billing' => cli_billing($db)]);
}
if ($act === 'pay_delete') {
    $st = $db->prepare('SELECT invoice_id FROM g7_client_payments WHERE id = ?'); $st->execute([(int)($in['id'] ?? 0)]);
    if ($pi = (int)$st->fetchColumn()) cli_fact_perm($db, $pi, $me);
    try { cli_payment_delete($db, (int)($in['id'] ?? 0), (int)$me['id']); } catch (InvalidArgumentException $e) { cli_fail($e->getMessage(), 409); }   // en línea: no; manual: anulado en el libro
    if ($pi) cli_fact_log($db, $pi, (int)$me['id'], 'payment_del', 'Pago borrado');
    cli_out(['ok' => true]);
}

cli_fail('Acción desconocida');
