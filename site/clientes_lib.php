<?php
/**
 * clientes_lib.php — Lo que comparten Admin › Clientes (clientes_api.php) y el dashboard de cada cliente (/index.php de cronos):
 * tablas, contenidos de un cliente (los de Promos cuyo proyecto es su nombre), facturas con su estado y envío de archivos.
 */
// El dashboard no pasa por access.php (que fija la zona horaria): sin esto "hoy" y "vencida" se calculan en UTC
if (date_default_timezone_get() === 'UTC') date_default_timezone_set('America/Argentina/Buenos_Aires');
const CLI_DIR = __DIR__ . '/_private/clientes';   // carpeta bloqueada para la web: los archivos salen solo por PHP
const CLI_STATUS = ['potencial', 'activo', 'pausado', 'terminado'];
const CLI_SVC_STATUS = ['pendiente', 'en_curso', 'pausado', 'terminado'];
const CLI_FILE_KINDS = ['logo', 'manual', 'otro'];
const CLI_MAX_FILE = 25 * 1024 * 1024;
const CLI_EXT = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'avif', 'pdf', 'ai', 'eps', 'psd', 'zip', 'rar', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'ttf', 'otf', 'woff', 'woff2', 'mp4', 'mov'];
const CLI_CURRENCIES = ['ARS', 'USD', 'EUR'];
const CLI_PAY_METHODS = ['Transferencia', 'Efectivo', 'Mercado Pago', 'Tarjeta', 'Otro'];

function cli_install(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS g7_clients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'potencial',
        contact_name VARCHAR(120) NOT NULL DEFAULT '', email VARCHAR(160) NOT NULL DEFAULT '',
        phone VARCHAR(60) NOT NULL DEFAULT '', whatsapp VARCHAR(60) NOT NULL DEFAULT '',
        legal_name VARCHAR(200) NOT NULL DEFAULT '', cuit VARCHAR(20) NOT NULL DEFAULT '', iva_cond VARCHAR(40) NOT NULL DEFAULT '',
        address VARCHAR(200) NOT NULL DEFAULT '', city VARCHAR(100) NOT NULL DEFAULT '', province VARCHAR(100) NOT NULL DEFAULT '',
        website VARCHAR(250) NOT NULL DEFAULT '', instagram VARCHAR(250) NOT NULL DEFAULT '', facebook VARCHAR(250) NOT NULL DEFAULT '',
        tiktok VARCHAR(250) NOT NULL DEFAULT '', linkedin VARCHAR(250) NOT NULL DEFAULT '', youtube VARCHAR(250) NOT NULL DEFAULT '',
        other_links TEXT NULL, brand_colors TEXT NULL, brand_fonts VARCHAR(250) NOT NULL DEFAULT '', notes TEXT NULL,
        created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY status (status)
    ) DEFAULT CHARSET=utf8mb4");
    // Acceso al dashboard del cliente
    $db->exec("ALTER TABLE g7_clients ADD COLUMN IF NOT EXISTS portal_email VARCHAR(160) NOT NULL DEFAULT ''");
    $db->exec("ALTER TABLE g7_clients ADD COLUMN IF NOT EXISTS portal_pass VARCHAR(255) NOT NULL DEFAULT ''");
    $db->exec("ALTER TABLE g7_clients ADD COLUMN IF NOT EXISTS portal_on TINYINT(1) NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE g7_clients ADD COLUMN IF NOT EXISTS portal_last DATETIME NULL");
    $db->exec("ALTER TABLE g7_clients ADD COLUMN IF NOT EXISTS type VARCHAR(30) NOT NULL DEFAULT ''");   // tipo de cliente del rubro (custom_fields_lib.php)
    $db->exec("CREATE TABLE IF NOT EXISTS g7_client_services (
        id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL,
        name VARCHAR(160) NOT NULL, type VARCHAR(40) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'pendiente',
        start_date DATE NULL, end_date DATE NULL, notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY client (client_id)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_client_notes (
        id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, user_id INT NULL, body TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY client (client_id)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_client_files (
        id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, name VARCHAR(200) NOT NULL, stored VARCHAR(80) NOT NULL,
        mime VARCHAR(100) NOT NULL DEFAULT '', size INT NOT NULL DEFAULT 0, kind VARCHAR(20) NOT NULL DEFAULT 'otro',
        uploaded_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY client (client_id)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_client_invoices (
        id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL,
        number VARCHAR(60) NOT NULL DEFAULT '', concept VARCHAR(250) NOT NULL DEFAULT '',
        issue_date DATE NULL, due_date DATE NULL, amount DECIMAL(14,2) NOT NULL DEFAULT 0, currency VARCHAR(3) NOT NULL DEFAULT 'ARS',
        file_name VARCHAR(200) NOT NULL DEFAULT '', file_stored VARCHAR(80) NOT NULL DEFAULT '',
        created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY client (client_id)
    ) DEFAULT CHARSET=utf8mb4");
    // Factura con el formato de Cronos: período, descripción, subtotal (acepta fórmula) y descuento; amount = total final
    $db->exec("ALTER TABLE g7_client_invoices ADD COLUMN IF NOT EXISTS period VARCHAR(40) NOT NULL DEFAULT ''");
    $db->exec("ALTER TABLE g7_client_invoices ADD COLUMN IF NOT EXISTS description TEXT NULL");
    $db->exec("ALTER TABLE g7_client_invoices ADD COLUMN IF NOT EXISTS subtotal DECIMAL(14,2) NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE g7_client_invoices ADD COLUMN IF NOT EXISTS subtotal_expr VARCHAR(80) NOT NULL DEFAULT ''");
    $db->exec("ALTER TABLE g7_client_invoices ADD COLUMN IF NOT EXISTS discount VARCHAR(20) NOT NULL DEFAULT ''");
    $db->exec("ALTER TABLE g7_clients ADD COLUMN IF NOT EXISTS closed_date DATE NULL");   // contrato finalizado
    $db->exec("CREATE TABLE IF NOT EXISTS g7_client_payments (
        id INT AUTO_INCREMENT PRIMARY KEY, invoice_id INT NOT NULL, client_id INT NOT NULL,
        pay_date DATE NULL, amount DECIMAL(14,2) NOT NULL DEFAULT 0, method VARCHAR(40) NOT NULL DEFAULT '', note VARCHAR(250) NOT NULL DEFAULT '',
        created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY invoice (invoice_id), KEY client (client_id)
    ) DEFAULT CHARSET=utf8mb4");
    // Foto de perfil del cliente (pública: client_avatars/) y Materiales: los archivos del cliente con carpetas, como un drive
    $db->exec("ALTER TABLE g7_clients ADD COLUMN IF NOT EXISTS avatar VARCHAR(300) NOT NULL DEFAULT ''");
    $db->exec("ALTER TABLE g7_clients ADD COLUMN IF NOT EXISTS drive_ready TINYINT(1) NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE g7_client_files ADD COLUMN IF NOT EXISTS folder VARCHAR(255) NOT NULL DEFAULT ''");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_client_folders (
        id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, path VARCHAR(255) NOT NULL,
        created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY cp (client_id, path)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
}

// ── Materiales (drive de cada cliente) ──────────────────────────
// Los archivos siguen en _private/clientes/<id>/ con nombre al azar; la carpeta es solo un dato (g7_client_files.folder),
// así mover o renombrar una carpeta es un UPDATE y nunca toca el disco. Las carpetas vacías se guardan en g7_client_folders.
const CLI_DRIVE_FOLDERS = ['Logos', 'Tipografías', 'Manual de marca', 'Fotos e imágenes', 'Documentos'];
const CLI_DRIVE_EXT = ['tif', 'tiff', 'heic', 'bmp', 'ico', 'indd', 'idml', 'cdr', 'xd', 'fig', 'sketch', 'afdesign', 'mp3', 'wav', 'm4a', '7z', 'key', 'pages', 'numbers', 'odt', 'ods', 'rtf', 'webm'];
const CLI_DRIVE_MAX = 300 * 1024 * 1024;   // por archivo: entra en partes, así no lo frena el límite de subida del hosting

/** Ruta de carpeta limpia ("Logos/Versiones"): sin "..", sin barras dobles ni caracteres raros. '' = raíz del cliente. */
function cli_folder_clean(string $p): ?string {
    $parts = [];
    foreach (preg_split('#[/\\\\]+#', $p) as $seg) {
        $seg = trim(preg_replace('/[\x00-\x1f<>:"|?*]+/u', '', $seg));
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') return null;
        $parts[] = mb_substr($seg, 0, 80);
    }
    $out = implode('/', $parts);
    return mb_strlen($out) > 250 ? null : $out;
}

/** Carpeta del cliente en disco y sus carpetas de siempre (una sola vez por cliente). */
function cli_drive_init(PDO $db, int $cid, ?int $uid = null): void {
    $dir = CLI_DIR . '/' . $cid;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $st = $db->prepare('SELECT drive_ready FROM g7_clients WHERE id = ?'); $st->execute([$cid]);
    if ((int)$st->fetchColumn() === 1) return;
    $ins = $db->prepare('INSERT IGNORE INTO g7_client_folders (client_id, path, created_by) VALUES (?,?,?)');
    // Carpetas del rubro (rubro_lib.php → g7_config.client_folders); sin rubro, las de siempre
    $folders = function_exists('g7_cfg_json') ? (g7_cfg_json('client_folders', []) ?: CLI_DRIVE_FOLDERS) : CLI_DRIVE_FOLDERS;
    foreach ($folders as $f) $ins->execute([$cid, mb_substr((string)$f, 0, 120), $uid]);
    // Los logos y manuales que ya estaban cargados en la ficha van a su carpeta
    $db->prepare("UPDATE g7_client_files SET folder = CASE kind WHEN 'logo' THEN 'Logos' WHEN 'manual' THEN 'Manual de marca' ELSE folder END WHERE client_id = ? AND folder = ''")->execute([$cid]);
    $db->prepare('UPDATE g7_clients SET drive_ready = 1 WHERE id = ?')->execute([$cid]);
}

/** URL de la foto de perfil del cliente ('' si no tiene). */
function cli_avatar_url(array $c): string {
    $a = (string)($c['avatar'] ?? '');
    return preg_match('#^/toolz/client_avatars/[A-Za-z0-9_.-]+$#', $a) ? $a : '';
}

/** Contenidos de Promos de un cliente: proyecto principal o uno de sus proyectos = nombre del cliente. Solo versiones vigentes. */
function cli_contents(PDO $db, string $name): array {
    if ($name === '') return [];
    $where = "FROM g7_content WHERE (project = ? OR projects LIKE ?) AND (estado IS NULL OR estado = 'activo') ORDER BY COALESCE(start_date, DATE(created_at)) DESC, id DESC";
    $args = [$name, '%' . json_encode($name, JSON_UNESCAPED_UNICODE) . '%'];
    $full = 'id, type, name, category, media_type, content_type, start_date, end_date, img, mailing, landing, graphics, custom_field, created_at,
             qty_graficas, qty_videos, qty_landings, qty_mailings, qty_creativos_paid, qty_assets_rrss, qty_assets_mobile';
    $rows = null;
    // Las columnas de cantidades las agrega Promos; si todavía no existen, alcanza con lo básico
    foreach ([$full, 'id, type, name, category, start_date, end_date, img, mailing, landing, graphics, created_at'] as $cols) {
        try { $q = $db->prepare("SELECT $cols $where"); $q->execute($args); $rows = $q->fetchAll(PDO::FETCH_ASSOC); break; } catch (Throwable $e) {}
    }
    if ($rows === null) return [];
    if ($rows) {
        $ids = array_column($rows, 'id');
        try {
            $r = $db->query('SELECT content_id, name, url FROM g7_content_resources WHERE content_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY sort_order, id');
            $res = []; foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $x) $res[$x['content_id']][] = $x;
            foreach ($rows as &$row) $row['resources'] = $res[$row['id']] ?? [];
        } catch (Throwable $e) {}
    }
    return $rows;
}

/**
 * ÚNICA forma de registrar un cobro manual de una factura (Facturación, ficha del cliente y "marcar pagada").
 * Bloquea la factura (FOR UPDATE) y recalcula el saldo adentro de la transacción: dos cobros a la vez no la pagan dos veces.
 * $amount null = todo el saldo. Devuelve [amount, currency, balance] o lanza InvalidArgumentException con el motivo.
 */
function cli_record_payment(PDO $db, int $iid, ?float $amount, string $date, string $method, string $note, ?int $uid): array {
    try {   // moneda del pago (columna nueva de la pasarela): fuera de la transacción, un ALTER la cerraría
        $db->exec("ALTER TABLE g7_client_payments ADD COLUMN IF NOT EXISTS currency CHAR(3) NOT NULL DEFAULT ''");
    } catch (Throwable $e) {}
    $ledger = cli_pay_ledger_on($db);
    $own = !$db->inTransaction(); if ($own) $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT * FROM g7_client_invoices WHERE id = ? FOR UPDATE'); $st->execute([$iid]);
        $i = $st->fetch(PDO::FETCH_ASSOC); if (!$i) throw new InvalidArgumentException('Esa factura no existe');
        $state = (string)($i['state'] ?? 'emitida');
        if ($state === 'borrador') throw new InvalidArgumentException('Primero emite la factura');
        if ($state === 'cancelada') throw new InvalidArgumentException('Una factura cancelada no recibe pagos');
        $p = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM g7_client_payments WHERE invoice_id = ?'); $p->execute([$iid]);
        $balance = round((float)$i['amount'] - (float)$p->fetchColumn(), 2);
        if ($balance <= 0.004) throw new InvalidArgumentException('Esta factura ya está pagada');
        $amount = $amount === null ? $balance : round($amount, 2);
        if ($amount <= 0) throw new InvalidArgumentException('Pon el monto del pago');
        if ($amount > $balance + 0.004) throw new InvalidArgumentException('El pago supera el saldo de la factura (' . number_format($balance, 2, ',', '.') . ')');
        $cur = (string)($i['currency'] ?? '') ?: 'ARS';
        $db->prepare('INSERT INTO g7_client_payments (invoice_id, client_id, pay_date, amount, method, note, created_by, currency) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$iid, (int)$i['client_id'], $date, $amount, $method, $note, $uid, $cur]);
        if ($ledger) {   // también al libro único de Finanzas y pagos
            $pid = (int)$db->lastInsertId();
            $db->prepare('UPDATE g7_client_payments SET pay_ledger_id = ? WHERE id = ?')->execute([pay_ledger_manual($db, $i, $pid, $amount, $cur, $method, $uid), $pid]);
        }
        try { $db->prepare('UPDATE g7_client_invoices SET rev = rev + 1, updated_at = NOW() WHERE id = ?')->execute([$iid]); } catch (Throwable $e) {}   // rev: solo con Facturación
        if ($own) $db->commit();
        return ['amount' => $amount, 'currency' => $cur, 'balance' => round($balance - $amount, 2)];
    } catch (Throwable $e) { if ($own && $db->inTransaction()) $db->rollBack(); throw $e; }
}

/** ¿Está la pasarela (Finanzas y pagos)? Deja listas sus tablas: llamar FUERA de una transacción. */
function cli_pay_ledger_on(PDO $db): bool {
    static $on = null; if ($on !== null) return $on;
    $f = __DIR__ . '/pagos/lib.php'; if (!is_file($f)) return $on = false;
    try { require_once $f; if (!$db->inTransaction()) { pay_install($db); pay_invoice_payments_columns($db); } return $on = true; } catch (Throwable $e) { return $on = false; }
}
/**
 * Borra un pago de factura. Si entró en línea (Finanzas y pagos) no se borra: se devuelve con un reembolso desde ahí.
 * Si es manual y está en el libro, queda anulado con un ajuste. Devuelve el pago borrado o lanza InvalidArgumentException.
 */
function cli_payment_delete(PDO $db, int $paymentId, ?int $uid): array {
    $ledger = cli_pay_ledger_on($db);
    $own = !$db->inTransaction(); if ($own) $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT * FROM g7_client_payments WHERE id = ? FOR UPDATE'); $st->execute([$paymentId]);
        $p = $st->fetch(PDO::FETCH_ASSOC); if (!$p) throw new InvalidArgumentException('Ese pago no existe');
        if ($ledger && !empty($p['pay_ledger_id'])) {
            $q = $db->prepare('SELECT provider FROM g7_pay_ledger WHERE id = ?'); $q->execute([(int)$p['pay_ledger_id']]); $prov = (string)$q->fetchColumn();
            if ($prov !== '' && $prov !== 'manual') throw new InvalidArgumentException('Este pago entró en línea: para devolverlo, haz un reembolso desde Finanzas y pagos.');
            if ($prov === 'manual') pay_ledger_manual_void($db, (int)$p['pay_ledger_id'], $uid, 'Pago borrado en Facturación');
        }
        if ((float)$p['amount'] < 0 && str_starts_with((string)$p['method'], 'Reembolso')) throw new InvalidArgumentException('Este movimiento es un reembolso de Finanzas y pagos: no se borra.');
        $db->prepare('DELETE FROM g7_client_payments WHERE id = ?')->execute([$paymentId]);
        try { $db->prepare('UPDATE g7_client_invoices SET rev = rev + 1, updated_at = NOW() WHERE id = ?')->execute([(int)$p['invoice_id']]); } catch (Throwable $e) {}
        if ($own) $db->commit();
        return $p;
    } catch (Throwable $e) { if ($own && $db->inTransaction()) $db->rollBack(); throw $e; }
}

/** Facturas de un cliente con sus pagos, lo pagado, el saldo y el estado (pagada / parcial / vencida / pendiente). */
function cli_invoices(PDO $db, int $cid): array {
    $inv = $db->prepare('SELECT * FROM g7_client_invoices WHERE client_id = ? ORDER BY COALESCE(issue_date, DATE(created_at)) DESC, id DESC');
    $inv->execute([$cid]);
    // Los borradores y las canceladas de Facturación no las ve el cliente ni cuentan en su saldo
    $rows = array_values(array_filter($inv->fetchAll(PDO::FETCH_ASSOC), fn($r) => ($r['state'] ?? 'emitida') === 'emitida'));
    $items = [];
    $fact = array_column(array_filter($rows, fn($r) => ($r['source'] ?? '') === 'fact'), 'id');
    if ($fact) try {
        foreach ($db->query('SELECT * FROM g7_invoice_items WHERE invoice_id IN (' . implode(',', array_map('intval', $fact)) . ') ORDER BY invoice_id, sort, id')->fetchAll(PDO::FETCH_ASSOC) as $it) $items[$it['invoice_id']][] = $it;
    } catch (Throwable $e) {}
    $pay = $db->prepare('SELECT * FROM g7_client_payments WHERE client_id = ? ORDER BY pay_date DESC, id DESC');
    $pay->execute([$cid]); $byInv = [];
    foreach ($pay->fetchAll(PDO::FETCH_ASSOC) as $p) $byInv[$p['invoice_id']][] = $p;
    $today = date('Y-m-d');
    foreach ($rows as &$r) {
        $r['payments'] = $byInv[$r['id']] ?? [];
        $r['paid'] = round(array_sum(array_column($r['payments'], 'amount')), 2);
        $r['balance'] = round((float)$r['amount'] - $r['paid'], 2);
        $r['status'] = $r['balance'] <= 0.004 ? 'pagada' : ($r['due_date'] && $r['due_date'] < $today ? 'vencida' : ($r['paid'] > 0 ? 'parcial' : 'pendiente'));
        $r['paid_date'] = $r['payments'] ? max(array_column($r['payments'], 'pay_date')) : null;
        if ((float)$r['subtotal'] <= 0) $r['subtotal'] = $r['amount'];          // facturas cargadas antes del formato nuevo
        $r['discount_amount'] = round(max(0, (float)$r['subtotal'] - (float)$r['amount']), 2);
        if (($r['source'] ?? '') === 'fact') {                                    // hecha en Facturación: líneas, IVA y datos del cliente al emitir
            $r['items'] = $items[$r['id']] ?? [];
            $r['discount_amount'] = round(max(0, (float)$r['subtotal'] + (float)$r['tax_total'] - (float)$r['amount']), 2);
            $snap = json_decode((string)($r['client_snapshot'] ?? ''), true);
            $r['client_snapshot'] = is_array($snap) ? $snap : null;
        }
        unset($r['design_snapshot'], $r['fiscal_data'], $r['num_key']);
        unset($r['file_stored']);
        $r['has_file'] = $r['file_name'] !== '';
    }
    return $rows;
}

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

// ── Facturación con el formato de Cronos (plantilla "Cronos Operating System") ──
// Los datos del emisor, los medios de pago y el mensaje de cierre se editan en Admin › Clientes › Datos de facturación.
const CLI_BILLING_DEFAULT = [
    'issuer' => '',
    'tagline' => '',
    'logo' => '',
    'prefix' => 'FAC-',
    'currency' => 'USD',
    'period' => '1 Mes',
    'description' => '',
    'footer_text' => '',
    'footer_url' => '',
    'closing_title' => '¡Contrato Finalizado con Éxito!',
    'closing_msg' => "Gracias por confiar en nosotros. Fue un placer trabajar juntos.",
    'signature' => '',
    'methods' => [],
];
const CLI_MONTHS = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

/** Firma de las facturas y del portal: "Cronos Operating System" en cronos, el nombre del sitio en los demás. */
function cli_sys_name(): string { return g7_site('id') === 'cronos' ? 'Cronos Operating System' : (string)g7_site('name'); }
function cli_billing(PDO $db): array {
    // Arranca con la marca del sitio y sin medios de pago. Los datos propios de un sitio (emisor, logo, medios de pago…) van en
    // Admin › Clientes › Datos de facturación o en su paquete de marcas (marcas/marcas.php, clave 'cli_billing'): nunca en el código.
    $b = ['issuer' => (string)g7_site('name'), 'logo' => (string)g7_site('logo'), 'signature' => 'Equipo ' . g7_site('name')] + CLI_BILLING_DEFAULT;
    $pk = function_exists('g7_pack') ? g7_pack('cli_billing') : null;
    if (is_array($pk)) $b = array_merge($b, array_intersect_key($pk, $b));
    try {
        $st = $db->prepare("SELECT cfg_value FROM g7_config WHERE cfg_key = 'cli_billing'"); $st->execute();
        $saved = json_decode((string)$st->fetchColumn(), true);
        if (is_array($saved)) $b = array_merge($b, array_intersect_key($saved, $b));
    } catch (Throwable $e) {}
    return $b;
}

/** "160*2", "1.500,50 + 200", "(80+40)/2" → número; null si no es una cuenta válida. Sin eval. */
function cli_calc(string $e): ?float {
    $e = str_replace([' ', '$'], '', $e);
    if (str_contains($e, ',')) $e = str_replace(['.', ','], ['', '.'], $e);   // coma decimal: 1.500,50 → 1500.50
    if ($e === '' || strlen($e) > 80 || !preg_match('#^[0-9.+\-*/()]+$#', $e)) return null;
    $p = 0; $v = cli__calc_sum($e, $p);
    return ($v === null || $p !== strlen($e) || !is_finite($v)) ? null : round($v, 2);
}
function cli__calc_sum(string $e, int &$p): ?float {
    $v = cli__calc_mul($e, $p);
    while ($v !== null && $p < strlen($e) && ($e[$p] === '+' || $e[$p] === '-')) {
        $op = $e[$p++]; $r = cli__calc_mul($e, $p); if ($r === null) return null;
        $v = $op === '+' ? $v + $r : $v - $r;
    }
    return $v;
}
function cli__calc_mul(string $e, int &$p): ?float {
    $v = cli__calc_atom($e, $p);
    while ($v !== null && $p < strlen($e) && ($e[$p] === '*' || $e[$p] === '/')) {
        $op = $e[$p++]; $r = cli__calc_atom($e, $p);
        if ($r === null || ($op === '/' && abs($r) < 1e-9)) return null;
        $v = $op === '*' ? $v * $r : $v / $r;
    }
    return $v;
}
function cli__calc_atom(string $e, int &$p): ?float {
    if ($p >= strlen($e)) return null;
    if ($e[$p] === '-') { $p++; $v = cli__calc_atom($e, $p); return $v === null ? null : -$v; }
    if ($e[$p] === '(') {
        $p++; $v = cli__calc_sum($e, $p);
        if ($v === null || ($e[$p] ?? '') !== ')') return null;
        $p++; return $v;
    }
    if (!preg_match('/\G(?:\d+(?:\.\d+)?|\.\d+)/', $e, $m, 0, $p)) return null;
    $p += strlen($m[0]); return (float)$m[0];
}
/** Descuento escrito como "10%" (porcentaje) o "15" (monto fijo). Devuelve [texto normalizado, monto]. */
function cli_discount(string $d, float $sub): array {
    $d = str_replace([' ', ','], ['', '.'], trim($d));
    if (!preg_match('/^\d+(\.\d+)?%?$/', $d) || (float)$d <= 0) return ['', 0.0];
    $amt = str_ends_with($d, '%') ? $sub * min(100, (float)$d) / 100 : min($sub, (float)$d);
    return [$d, round($amt, 2)];
}
/** FAC-007 → FAC-008 (respeta los ceros). Sin número al final, empieza con el prefijo. */
function cli_next_ref(string $last, string $prefix): string {
    if (preg_match('/^(.*?)(\d+)$/', trim($last), $m)) return $m[1] . str_pad((string)((int)$m[2] + 1), strlen($m[2]), '0', STR_PAD_LEFT);
    return $prefix . '001';
}
function cli_long_date(?string $d): string {
    if (!$d || !($t = strtotime($d))) return '';
    return (int)date('j', $t) . ' de ' . CLI_MONTHS[(int)date('n', $t) - 1] . ', ' . date('Y', $t);
}
function cli_money($n, string $cur): string { return '$' . number_format((float)$n, 2, ',', '.') . ' ' . (in_array($cur, ['USD', 'EUR'], true) ? $cur : 'ARS'); }

/**
 * La factura como en la plantilla: encabezado del emisor, facturado a, servicio, totales, sello de pago y medios de pago.
 * Todo va escapado. $opt: pay (mostrar medios de pago si no está pagada), file_url (factura original subida), anchor.
 */
function cli_invoice_card(array $i, array $client, array $b, array $opt = []): string {
    $h = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $cur = $i['currency'] ?? 'USD';
    $status = $i['status'] ?? 'pendiente';
    $paid = $status === 'pagada';
    $sub = (float)($i['subtotal'] ?? 0) > 0 ? (float)$i['subtotal'] : (float)($i['amount'] ?? 0);
    $disc = (float)($i['discount_amount'] ?? max(0, $sub - (float)($i['amount'] ?? 0)));
    $total = (float)($i['amount'] ?? $sub - $disc);
    $words = preg_split('/\s+/', trim($b['issuer'])); $last = count($words) > 1 ? array_pop($words) : '';
    $issuer = $h(implode(' ', $words)) . ($last !== '' ? ' <span>' . $h($last) . '</span>' : '');
    $badge = $paid ? 'Documento pagado y saldado' : ($status === 'vencida' ? 'Factura vencida' : 'Documento oficial de cobro');
    $stamp = $paid ? '✅ Pagado' . ($i['paid_date'] ? ' el <span>' . $h(cli_long_date($i['paid_date'])) . '</span>' : '')
        : ($status === 'parcial' ? '◐ Pago parcial · falta ' . $h(cli_money($i['balance'] ?? $total, $cur))
        : ($status === 'vencida' ? '⚠ Vencida' . ($i['due_date'] ? ' el ' . $h(cli_long_date($i['due_date'])) : '') : '⏳ Pendiente de pago'));
    $multi = isset($i['items']) && is_array($i['items']);                    // hecha en Facturación (con líneas e IVA)
    if (!empty($i['client_snapshot']) && is_array($i['client_snapshot'])) $client = array_merge($client, $i['client_snapshot']);   // datos al emitir
    $billTo = trim((string)($client['legal_name'] ?? '')) ?: (string)($client['name'] ?? '');
    $fiscal = implode(' · ', array_filter([($client['cuit'] ?? '') !== '' ? 'CUIT ' . $client['cuit'] : '', $client['iva_cond'] ?? '', $client['address'] ?? '']));

    $o = '<article class="cx-inv cx-' . $h($status) . '"' . (!empty($opt['anchor']) ? ' id="' . $h($opt['anchor']) . '"' : '') . '>';
    $o .= '<header class="cx-hero"><span class="cx-badge">' . $h($badge) . '</span><div class="cx-hc">';
    $o .= '<div class="cx-brand">' . ($b['logo'] !== '' ? '<img src="' . $h($b['logo']) . '" alt="" referrerpolicy="no-referrer">' : '')
        . '<div><h2>' . $issuer . '</h2>' . ($b['tagline'] !== '' ? '<p>' . $h($b['tagline']) . '</p>' : '') . '</div></div>';
    $o .= '<div class="cx-info"><h3>FACTURA DE SERVICIOS</h3>'
        . ($i['issue_date'] ?? '' ? '<p>Fecha: ' . $h(cli_long_date($i['issue_date'])) . '</p>' : '')
        . (($i['number'] ?? '') !== '' ? '<p>Ref: ' . $h($i['number']) . '</p>' : '')
        . ($i['due_date'] ?? '' ? '<p>Vence: ' . $h(cli_long_date($i['due_date'])) . '</p>' : '') . '</div>';
    $o .= '</div></header><div class="cx-body">';
    $o .= '<div class="cx-client"><h4>Facturado a:</h4><p>' . $h($billTo ?: '—') . '</p>' . ($fiscal !== '' ? '<small>' . $h($fiscal) . '</small>' : '') . '</div>';
    if ($multi) {
        $num = fn($n) => rtrim(rtrim(number_format((float)$n, 3, ',', '.'), '0'), ',');
        $o .= (($i['concept'] ?? '') !== '' ? '<div class="cx-it" style="margin-bottom:10px">' . $h($i['concept']) . '</div>' : '')
            . '<div class="cx-table"><table><thead><tr><th>Descripción</th><th class="r">Cant.</th><th class="r">Precio</th><th class="r">Importe</th></tr></thead><tbody>';
        foreach ($i['items'] as $it) {
            $o .= '<tr><td><div class="cx-it">' . $h($it['description']) . '</div>'
                . (trim((string)($it['detail'] ?? '')) !== '' ? '<div class="cx-id">' . nl2br($h(trim($it['detail']))) . '</div>' : '')
                . ((float)$it['disc_pct'] > 0 ? '<div class="cx-id">Descuento ' . $h($num($it['disc_pct'])) . '%</div>' : '') . '</td>'
                . '<td class="r">' . $h($num($it['qty'])) . '</td><td class="r">' . $h(cli_money($it['unit_price'], $cur)) . '</td><td class="r cx-price">' . $h(cli_money($it['line_total'], $cur)) . '</td></tr>';
        }
        $o .= '</tbody></table></div>';
        $taxRows = '';
        $rates = [];
        foreach ($i['items'] as $it) if ((float)$it['tax_pct'] > 0) $rates[(string)(float)$it['tax_pct']] = true;
        if ((float)($i['tax_total'] ?? 0) > 0) $taxRows = '<div class="cx-tr"><span>IVA' . ($rates ? ' (' . $h(implode(' / ', array_map(fn($r) => $num($r) . '%', array_keys($rates)))) . ')' : '') . '</span><span>' . $h(cli_money($i['tax_total'], $cur)) . '</span></div>';
    } else {
        $o .= '<div class="cx-table"><table><thead><tr><th>Descripción del servicio</th><th class="r">Período</th><th class="r">Total</th></tr></thead><tbody><tr>'
            . '<td><div class="cx-it">' . $h(($i['concept'] ?? '') ?: 'Servicios') . '</div>'
            . (trim((string)($i['description'] ?? '')) !== '' ? '<div class="cx-id">' . nl2br($h(trim($i['description']))) . '</div>' : '') . '</td>'
            . '<td class="r">' . $h(($i['period'] ?? '') ?: '—') . '</td><td class="r cx-price">' . $h(cli_money($sub, $cur)) . '</td></tr></tbody></table></div>';
        $taxRows = '';
    }
    $o .= '<div class="cx-totals"><div class="cx-tbox"><div class="cx-tr"><span>Subtotal</span><span>' . $h(cli_money($sub, $cur)) . '</span></div>'
        . ($disc > 0 ? '<div class="cx-tr cx-disc"><span>Descuento aplicado' . (str_ends_with((string)($i['discount'] ?? ''), '%') ? ' (' . $h($i['discount']) . ')' : '') . '</span><span>-' . $h(cli_money($disc, $cur)) . '</span></div>' : '')
        . $taxRows
        . '<div class="cx-tr cx-final"><span>Total a pagar</span><span>' . $h(cli_money($total, $cur)) . '</span></div>'
        . '<div class="cx-stamp">' . $stamp . '</div></div></div>';
    if (!empty($i['payments'])) {
        $o .= '<div class="cx-pays"><h4>Pagos registrados</h4>';
        foreach ($i['payments'] as $p) $o .= '<div class="cx-pr"><span>' . $h(cli_long_date($p['pay_date'])) . ($p['method'] !== '' ? ' · ' . $h($p['method']) : '') . ($p['note'] !== '' ? ' · ' . $h($p['note']) : '') . '</span><b>' . $h(cli_money($p['amount'], $cur)) . '</b></div>';
        $o .= '</div>';
    }
    if (!$paid && !empty($opt['pay']) && $b['methods']) {
        $o .= '<div class="cx-pay" id="pagar-' . (int)($i['id'] ?? 0) . '">';
        foreach ($b['methods'] as $m) {
            $o .= '<div class="cx-pbox"><h4>' . $h($m['title']) . '</h4>';
            foreach ($m['items'] as $it) $o .= '<div class="cx-cg"><span class="cx-cl">' . $h($it['label']) . '</span><button type="button" class="cx-copy" data-copy="' . $h($it['value']) . '"><span class="cx-cv">' . $h($it['value']) . '</span><span class="cx-cb">Copiar</span></button></div>';
            $o .= '</div>';
        }
        $o .= '</div>';
    }
    if ($multi) foreach (['notes' => 'Observaciones', 'terms' => 'Condiciones'] as $k => $lbl)
        if (trim((string)($i[$k] ?? '')) !== '') $o .= '<div class="cx-pays"><h4>' . $lbl . '</h4><div class="cx-id">' . nl2br($h(trim($i[$k]))) . '</div></div>';
    if ($multi && ($i['doc_type'] ?? 'interno') === 'interno') $o .= '<p class="cx-file" style="color:#888">Comprobante interno. No válido como factura fiscal.</p>';
    if (!empty($opt['file_url'])) $o .= '<p class="cx-file"><a href="' . $h($opt['file_url']) . '" target="_blank" rel="noopener">📄 Ver la factura original (' . $h($i['file_name'] ?? 'archivo') . ')</a></p>';
    $o .= '<footer class="cx-foot">' . ($b['footer_url'] !== '' ? '<p>' . $h($b['footer_text']) . ' <a href="' . $h($b['footer_url']) . '" target="_blank" rel="noopener">' . $h(preg_replace('#^https?://(www\.)?#', '', $b['footer_url'])) . '</a></p>' : '')
        . '<p class="cx-sys">' . htmlspecialchars(cli_sys_name()) . '.</p></footer>';
    return $o . '</div></article>';
}

/** Estilos de la factura (los usan el dashboard, la vista previa del admin y la versión para imprimir). */
const CLI_INV_CSS = <<<'CSS'
.cx-inv{--cx-acc:#FF7F00;--cx-grad:linear-gradient(-30deg,#FF7F00,#D85006);background:#141414;border:1px solid #262626;border-radius:16px;width:100%;box-shadow:0 20px 40px rgba(0,0,0,.6);overflow:hidden;position:relative;color:#d1d5db;font-size:14px;line-height:1.5}
.cx-hero{background:linear-gradient(135deg,#181818 0%,#0d0d0d 100%);border-bottom:1px solid #262626;padding:30px 40px;position:relative}
.cx-hero::before{content:'';position:absolute;top:0;left:0;width:100%;height:4px;background:var(--cx-grad)}
.cx-pagada .cx-hero::before{background:linear-gradient(90deg,#10b981,#059669)}
.cx-vencida .cx-hero::before{background:linear-gradient(90deg,#ef4444,#b91c1c)}
.cx-badge{display:inline-block;background:rgba(255,127,0,.1);color:var(--cx-acc);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;padding:6px 12px;border-radius:20px;border:1px solid rgba(255,127,0,.3);margin-bottom:12px}
.cx-pagada .cx-badge{background:rgba(16,185,129,.1);color:#10b981;border-color:rgba(16,185,129,.3)}
.cx-vencida .cx-badge{background:rgba(239,68,68,.1);color:#ef4444;border-color:rgba(239,68,68,.3)}
.cx-hc{display:flex;justify-content:space-between;align-items:flex-start;gap:20px}
.cx-brand{display:flex;align-items:center;gap:12px;min-width:0}
.cx-brand img{height:42px;width:auto;object-fit:contain;display:block}
.cx-brand h2{font-size:20px;font-weight:700;color:#fff;margin:0;line-height:1.25}
.cx-brand h2 span{color:var(--cx-acc)}
.cx-brand p{font-size:12.5px;color:#888;margin:2px 0 0}
.cx-info{text-align:right;flex-shrink:0}
.cx-info h3{font-size:15px;color:#fff;font-weight:600;margin:0}
.cx-info p{font-size:13px;color:#888;margin:4px 0 0}
.cx-body{padding:40px}
.cx-client{background:#1a1a1a;border:1px solid #262626;border-radius:10px;padding:20px;margin-bottom:24px}
.cx-client h4,.cx-pays h4,.cx-pbox h4{font-size:12px;text-transform:uppercase;letter-spacing:1px;color:var(--cx-acc);margin:0 0 8px;font-weight:600}
.cx-client p{font-size:16px;color:#e5e7eb;font-weight:600;margin:0}
.cx-client small{display:block;font-size:12.5px;color:#888;margin-top:4px}
.cx-table{width:100%;margin-bottom:24px;overflow-x:auto}
.cx-table table{width:100%;border-collapse:collapse;text-align:left}
.cx-table th{background:#1a1a1a;color:#888;font-size:12px;text-transform:uppercase;padding:12px 16px;border-bottom:1px solid #262626;font-weight:600}
.cx-table td{padding:16px;font-size:14px;color:#d1d5db;border-bottom:1px solid #1c1c1c;vertical-align:top}
.cx-table .r{text-align:right;white-space:nowrap}
.cx-it{font-weight:600;color:#fff;margin-bottom:6px}
.cx-id{font-size:13px;color:#9ca3af;line-height:1.55}
.cx-price{font-weight:600;color:#fff}
.cx-totals{display:flex;justify-content:flex-end;margin-bottom:30px}
.cx-tbox{width:320px;max-width:100%;background:#1a1a1a;border:1px solid #262626;border-radius:10px;padding:16px 20px}
.cx-tr{display:flex;justify-content:space-between;gap:12px;margin-bottom:8px;font-size:14px;color:#9ca3af;font-variant-numeric:tabular-nums}
.cx-tr.cx-disc{color:#10b981}
.cx-tr.cx-final{margin-top:12px;padding-top:12px;border-top:1px solid #2e2e2e;font-size:16px;font-weight:700;color:#fff}
.cx-tr.cx-final span:last-child{color:var(--cx-acc)}
.cx-pagada .cx-tr.cx-final span:last-child{color:#10b981;text-decoration:line-through}
.cx-stamp{margin-top:6px;font-size:12px;font-weight:700;color:#f59e0b}
.cx-pagada .cx-stamp{color:#10b981}.cx-vencida .cx-stamp{color:#ef4444}
.cx-pays{background:#1a1a1a;border:1px solid #262626;border-radius:10px;padding:16px 20px;margin-bottom:30px}
.cx-pr{display:flex;justify-content:space-between;gap:12px;font-size:13px;color:#9ca3af;padding:6px 0;border-top:1px solid #222}
.cx-pr:first-of-type{border-top:0}
.cx-pr b{color:#fff;font-variant-numeric:tabular-nums}
.cx-pay{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px;margin-bottom:30px;scroll-margin-top:90px}
.cx-pbox{background:#1a1a1a;border:1px solid #262626;border-radius:10px;padding:24px}
.cx-pbox h4{text-align:center;margin-bottom:14px}
.cx-cg{margin-bottom:12px}.cx-cg:last-child{margin-bottom:0}
.cx-cl{font-size:11px;color:#9ca3af;margin-bottom:4px;display:block}
.cx-copy{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;background:#111;border:1px solid #262626;border-radius:6px;padding:8px 12px;cursor:pointer;transition:border-color .2s;font:inherit;text-align:left}
.cx-copy:hover{border-color:var(--cx-acc)}
.cx-cv{font-size:13px;font-weight:600;color:#fff;word-break:break-all}
.cx-cb{background:rgba(255,127,0,.1);color:var(--cx-acc);border:1px solid rgba(255,127,0,.3);border-radius:4px;padding:4px 8px;font-size:11px;font-weight:600;white-space:nowrap}
.cx-file{margin:-10px 0 24px;font-size:13px}.cx-file a{color:var(--cx-acc);font-weight:600;text-decoration:none}
.cx-foot{margin-top:30px;text-align:center;font-size:12px;color:#888;border-top:1px solid #1c1c1c;padding-top:20px;line-height:1.6}
.cx-foot p{margin:0}
.cx-foot a{color:var(--cx-acc);text-decoration:none;font-weight:600}
.cx-foot .cx-sys{margin-top:6px;font-size:11px;color:#555}
@media (max-width:680px){.cx-body{padding:20px}.cx-hero{padding:22px 20px}.cx-hc{flex-direction:column}.cx-info{text-align:left}.cx-totals{justify-content:stretch}.cx-tbox{width:100%}}
CSS;

/** Página suelta para imprimir o guardar como PDF (se abre el diálogo de impresión sola). */
function cli_print_page(string $title, string $body, bool $dark = true): void {
    require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta: la página no tiene código en línea (SEC-24)
    header('Content-Type: text/html; charset=utf-8'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . $t . '</title><link rel="icon" type="image/png" href="' . htmlspecialchars((string)(g7_site('favicon') ?: g7_site('logo'))) . '">'
        . '<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet"><style>'
        . '*{box-sizing:border-box}body{margin:0;padding:28px;font-family:Montserrat,system-ui,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact;'
        . ($dark ? 'background:#080808;color:#f3f4f6}' : 'background:#fff;color:#111}')
        . '.pp{max-width:820px;margin:0 auto}.pp-bar{max-width:820px;margin:0 auto 16px;display:flex;justify-content:flex-end;gap:8px}'
        . '.pp-bar button{font:600 13px inherit;font-family:inherit;border-radius:8px;padding:9px 16px;cursor:pointer;border:0;background:linear-gradient(-30deg,#FF7F00,#D85006);color:#fff}'
        . '@page{size:A4;margin:10mm}@media print{body{padding:0}.pp-bar{display:none}.cx-inv{box-shadow:none}.cx-cb{display:none}}'
        . CLI_INV_CSS . '</style></head><body><div class="pp-bar"><button type="button" id="pp-print">Imprimir o guardar como PDF</button></div><div class="pp">' . $body . '</div>'
        . '<script src="/toolz/clientes-print.js?v=' . (int)@filemtime(__DIR__ . '/clientes-print.js') . '"></script></body></html>';
    exit;
}

/** Expediente con el historial de facturas (como el PDF de la plantilla). */
function cli_report_html(array $client, array $invoices, array $b): string {
    $h = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $ST = ['pagada' => 'Pagado', 'parcial' => 'Pago parcial', 'vencida' => 'Vencida', 'pendiente' => 'Pendiente de pago'];
    $COL = ['pagada' => '#10b981', 'parcial' => '#d97706', 'vencida' => '#dc2626', 'pendiente' => '#d97706'];
    $o = '<style>.rp h1{color:#FF7F00;font-size:24px;margin:0 0 5px}.rp p{color:#555;font-size:14px;margin:0 0 22px}.rp table{width:100%;border-collapse:collapse;margin-top:15px}'
        . '.rp th{background:#f4f4f4;color:#333;text-transform:uppercase;font-size:11px;padding:12px;border:1px solid #ddd;text-align:left;letter-spacing:1px}'
        . '.rp td{border:1px solid #ddd;padding:10px;font-size:13px;color:#555}.rp td b{color:#111}.rp .r{text-align:right;white-space:nowrap}'
        . '.rp .closed{background:rgba(255,127,0,.08);border:1px solid rgba(255,127,0,.3);padding:15px;border-radius:8px;margin-bottom:20px;color:#c2410c;font-size:13px}'
        . '.rp .ft{margin-top:40px;font-size:12px;text-align:center;color:#888;border-top:1px solid #eee;padding-top:20px}</style><div class="rp">';
    $o .= '<h1>' . $h($b['issuer']) . ' — Expediente e historial</h1><p><strong>Cliente:</strong> ' . $h($client['name']) . '</p>';
    if (($client['status'] ?? '') === 'terminado')
        $o .= '<div class="closed"><strong>🔒 Contrato finalizado</strong><br>Fecha de cierre: <strong>' . $h(cli_long_date($client['closed_date'] ?? null) ?: 'sin registrar') . '</strong></div>';
    $o .= '<table><thead><tr><th>Referencia</th><th>Fecha</th><th>Período</th><th>Servicio</th><th class="r">Total</th><th>Estado</th></tr></thead><tbody>';
    foreach (array_reverse($invoices) as $i) {
        $st = $ST[$i['status']] . ($i['status'] === 'pagada' && $i['paid_date'] ? ' (' . cli_long_date($i['paid_date']) . ')' : '');
        $o .= '<tr><td><b>' . $h($i['number'] ?: '—') . '</b></td><td>' . $h(cli_long_date($i['issue_date'])) . '</td><td>' . $h($i['period'] ?: '—') . '</td><td>' . $h($i['concept'] ?: '—') . '</td>'
            . '<td class="r">' . $h(cli_money($i['amount'], $i['currency'])) . '</td><td style="color:' . $COL[$i['status']] . ';font-weight:700">' . $h($st) . '</td></tr>';
    }
    if (!$invoices) $o .= '<tr><td colspan="6">Todavía no hay facturas.</td></tr>';
    return $o . '</tbody></table><div class="ft">' . $h(cli_sys_name()) . ' — Generado para ' . $h($client['name']) . ' | ' . $h($b['issuer']) . '</div></div>';
}
