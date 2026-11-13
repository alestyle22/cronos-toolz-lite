<?php
/**
 * instalar.php — Asistente para instalar la plataforma desde cero en un hosting nuevo.
 * Se sube el paquete a /toolz/, se abre esta página y pide: base de datos, marca (obligatoria), herramientas y el primer admin.
 * Escribe _private/site.php y _private/secrets.php, crea las tablas base (install/schema.php) y deja el actualizador listo.
 * Una vez instalado no hace nada más (_private/installed.php o _private/site.php): por eso puede viajar en las versiones. Doc: toolz/INSTALADOR.md
 */
declare(strict_types=1);
const I_ROOT = __DIR__;
const I_PRIV = __DIR__ . '/_private';
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

// ── Ya instalado: no se puede volver a correr ─────────────
// Cualquier rastro de un sitio en uso (configuración, claves, licencia, actualizaciones) bloquea el instalador:
// así no se puede reinstalar un sitio que funciona con valores por defecto y quedarse con él.
if (is_file(I_PRIV . '/installed.php') || is_file(I_PRIV . '/site.php') || is_file(I_PRIV . '/secrets.php')
    || is_file(I_PRIV . '/license.json') || is_dir(I_PRIV . '/updates') || is_file(I_ROOT . '/config.local.php')) {
    http_response_code(403);
    i_page('Ya está instalado', '<div class="lockcard"><div class="badge-ok" style="margin:0 auto 26px"><i class="fa-solid fa-lock"></i></div><h1>Ya está instalada</h1><p class="lead" style="margin:0 auto 26px">Este asistente corre una sola vez. Para entrar, usa el login.</p><a class="btn pri" href="/toolz/login.php">Ir al login <i class="fa-solid fa-arrow-right"></i></a></div>');
    exit;
}

// ── Herramientas que se pueden elegir (id de módulo => texto); 'on' = marcada de entrada ──
const I_TOOLS = [
    'Equipo y trabajo' => [
        'tareas' => ['Gestión de trabajo', 'Tareas, tableros, proyectos, solicitudes e informes', true],
        'todos' => ['To-Do', 'Lista de pendientes de cada persona', true],
        'calendario' => ['Mi calendario', 'Calendario personal con eventos, avisos y las tareas con fecha', true],
        'chat' => ['Chat', 'Canales, mensajes directos, hilos y la burbuja flotante', true],
        'comunidad' => ['Comunidad', 'Muro del equipo con posteos y perfiles', true],
        'links' => ['Biblioteca', 'Links, guías y recursos del equipo', true],
        'status' => ['Status', 'Avisos de incidencias', false],
        'encuesta' => ['Encuesta', 'Encuestas internas', false],
    ],
    'Clientes' => [
        'clientes' => ['Clientes y Facturación', 'Fichas de clientes, servicios, facturas con PDF y portal para que cada cliente vea lo suyo', true],
        'stock' => ['Materiales', 'Carpetas de archivos por cliente (o un banco de imágenes si no usas Clientes)', true],
    ],
    'Contenido' => [
        'promos' => ['Promos CMS', 'Calendario de contenidos con revisiones', false],
        'pages' => ['Pages', 'Páginas simples publicadas desde la plataforma', false],
        'planify' => ['Planify', 'Planificador de posteos', false],
        'stats' => ['Stats', 'Estadísticas de producción de contenidos', false],
        'imghost' => ['Image Host', 'Subir imágenes y compartir el link', false],
    ],
    'Herramientas' => [
        'webp' => ['Convertidor de imágenes', 'WebP, JPG, PNG y AVIF', true],
        'jpg' => ['WebP a JPG', 'Conversión rápida', false],
        'svg' => ['SVG a PNG', 'Exporta SVG a imagen', true],
        'recolorear' => ['Recolorear SVG', 'Cambia colores de un SVG', true],
        'renombrador' => ['Renombrador', 'Descarga imágenes renombradas en ZIP', true],
        'claude' => ['Asistente de IA', 'Chat con Claude (necesita una clave de Anthropic)', false],
    ],
    'Casino y apuestas' => [
        'placas' => ['Placas deportivas', 'Placas de partidos y resultados', false],
        'placas_diarias' => ['Placas diarias', 'Placas del día con IA', false],
        'placas_paid' => ['Placas paid', 'Placas para pauta', false],
        'promos_gen' => ['Generador de promos', 'Promos con plantillas', false],
        'landings' => ['Landing Gen', 'Landings de casino', false],
        'mailing' => ['Mailing Maker', 'Mailings y comunicaciones', false],
        'popups' => ['Popup Gen', 'Popups para sitios de apuestas', false],
        'linkgen' => ['Links Generator', 'Links con seguimiento', false],
        'monday' => ['Monday', 'Conexión con Monday.com', false],
    ],
];
/** Edición del paquete (install/release.php 'edition'; _update/editions.php). null = completa. */
function i_edition(): ?array {
    static $e = false;
    if ($e !== false) return $e;
    $e = null;
    if (is_file(I_ROOT . '/install/release.php') && is_file(I_ROOT . '/_update/editions.php')) {
        $rel = require I_ROOT . '/install/release.php';
        require_once I_ROOT . '/_update/editions.php';
        $e = u_edition_def((string)($rel['edition'] ?? ''));
    }
    return $e;
}
/** Herramientas que ofrece el paso 5: en una edición, solo las que trae (Landing Gen y Mailing pasan a Contenido). */
function i_tools(): array {
    $ed = i_edition();
    if (!$ed) return I_TOOLS;
    $off = array_flip($ed['modules_off']); $o = [];
    foreach (I_TOOLS as $g => $tools) foreach ($tools as $id => $t) {
        if (isset($off[$id])) continue;
        if ($id === 'clientes') $t = ['Clientes', 'Fichas de clientes, servicios, notas y archivos', true];
        $o[$g === 'Casino y apuestas' ? 'Contenido' : $g][$id] = $t;
    }
    return $o;
}
// Entrada del menú de arriba para cada herramienta (en este orden)
const I_NAV = [
    'tareas' => ['/toolz/trabajo/', 'fa-table-columns', 'Trabajo'], 'clientes' => ['/toolz/clientes.php', 'fa-address-book', 'Clientes'],
    'facturacion' => ['/toolz/facturacion/', 'fa-file-invoice-dollar', 'Facturación'], 'chat' => ['/toolz/chat/', 'fa-comment-dots', 'Chat'],
    'comunidad' => ['/toolz/comunidad.php', 'fa-users', 'Comunidad'], 'links' => ['/toolz/links/', 'fa-tag', 'Biblioteca'],
    'stock' => ['/toolz/stock.php', 'fa-folder-open', 'Materiales'], 'promos' => ['/toolz/promos/', 'fa-layer-group', 'Promos'],
    'todos' => ['/toolz/todos.php', 'fa-list-check', 'To-Do'], 'calendario' => ['/toolz/calendario/', 'fa-calendar-days', 'Calendario'], 'stats' => ['/toolz/stats.php', 'fa-chart-line', 'Estadísticas'],
];

// ── Requisitos ─────────────────────────────────────────────
function i_checks(): array {
    $c = [];
    $c[] = ['PHP 8.0 o más nuevo', version_compare(PHP_VERSION, '8.0', '>='), 'Tiene ' . PHP_VERSION];
    foreach (['pdo_mysql' => 'Conexión a MySQL', 'mbstring' => 'Textos con acentos', 'curl' => 'Pedidos a otros sitios', 'zip' => 'Actualizaciones y descargas en ZIP',
              'openssl' => 'Firmas de las actualizaciones', 'gd' => 'Miniaturas e imágenes', 'json' => 'Datos'] as $ext => $why)
        $c[] = ["Extensión $ext ($why)", extension_loaded($ext), extension_loaded($ext) ? 'Lista' : 'Falta: pídela al hosting'];
    $c[] = ['La plataforma está en /toolz/', i_base_ok(), i_base_ok() ? 'Sí' : 'Está en ' . i_base() . ': sube la carpeta como /toolz/ en la raíz del sitio'];
    $c[] = ['Se puede escribir en la carpeta', is_writable(I_ROOT) && (!is_dir(I_PRIV) || is_writable(I_PRIV)), 'Hace falta para guardar la configuración y los archivos subidos'];
    $c[] = ['Están las tablas base', is_file(I_ROOT . '/install/schema.php'), 'install/schema.php'];
    return $c;
}
function i_base(): string { return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/toolz/instalar.php')), '/'); }
function i_base_ok(): bool { return i_base() === '/toolz'; }
function i_url(): string {
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $host = preg_replace('/[^a-z0-9.:\-\[\]]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https' : 'http') . '://' . $host . '/toolz';
}
function i_slug(string $s): string { $s = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s); return trim(preg_replace('/[^a-z0-9]+/', '_', $s), '_') ?: 'sitio'; }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ── Instalar ───────────────────────────────────────────────
// Botón "Probar conexión" del paso 2: solo dice si conecta y si la base está libre
if (($_POST['test_db'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = new PDO('mysql:host=' . (trim((string)($_POST['db_host'] ?? '')) ?: 'localhost') . ';dbname=' . trim((string)($_POST['db_name'] ?? '')) . ';charset=utf8mb4',
            trim((string)($_POST['db_user'] ?? '')), (string)($_POST['db_pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
        $used = $pdo->query("SHOW TABLES LIKE 'g7_users'")->fetchColumn() && (int)$pdo->query('SELECT COUNT(*) FROM g7_users')->fetchColumn() > 0;
        echo json_encode($used ? ['ok' => false, 'msg' => 'Conecta, pero esa base ya tiene una instalación. Usa una vacía.'] : ['ok' => true, 'msg' => 'Conecta y está libre. MySQL ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION)]);
    } catch (Throwable $e) { echo json_encode(['ok' => false, 'msg' => 'No conecta. Revisa el servidor, el nombre, el usuario y la contraseña.']); }
    exit;
}
/** Rubros disponibles (site/rubros/*.json), en orden. */
function i_rubros(): array {
    $o = [];
    foreach (glob(I_ROOT . '/rubros/*.json') ?: [] as $f) { $r = json_decode((string)file_get_contents($f), true); if (is_array($r) && !empty($r['id'])) $o[$r['id']] = $r; }
    uasort($o, fn($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));
    return $o;
}
/** En qué paso del asistente se arregla cada error (para abrirlo ahí). */
function i_step_of(string $e): int {
    foreach ([1 => ['requisito'], 7 => ['equipo:'], 4 => ['rubro'], 2 => ['base', 'conectar'], 3 => ['logo', 'plataforma', 'imagen', 'ícono', 'zona horaria'], 6 => ['admin', 'contraseña']] as $n => $words)
        foreach ($words as $w) if (mb_stripos($e, $w) !== false) return $n;
    return 8;
}

$errors = []; $done = null; $in = $_POST;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try { $done = i_install($in, $_FILES, $errors); }
    catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

function i_install(array $in, array $files, array &$errors): ?array {
    foreach (i_checks() as [$label, $ok]) if (!$ok) $errors[] = "Falta un requisito: $label.";
    $v = fn($k, $max = 200) => trim(mb_substr((string)($in[$k] ?? ''), 0, $max));
    $db = ['host' => $v('db_host', 100) ?: 'localhost', 'name' => $v('db_name', 64), 'user' => $v('db_user', 64), 'pass' => (string)($in['db_pass'] ?? '')];
    $name = $v('name', 80); $short = $v('short', 30) ?: $name;
    $user = strtolower($v('admin_user', 50)); $mail = $v('admin_email', 100); $pass = (string)($in['admin_pass'] ?? '');
    $accent = preg_match('/^#[0-9a-f]{6}$/i', (string)($in['accent'] ?? '')) ? strtolower($in['accent']) : '#ff6600';
    if (!$db['name'] || !$db['user']) $errors[] = 'Completa el nombre de la base y el usuario.';
    if ($name === '') $errors[] = 'Pon el nombre de la plataforma: es obligatorio.';
    if (empty($files['logo']['tmp_name']) || !is_uploaded_file($files['logo']['tmp_name'])) $errors[] = 'Sube el logo: es obligatorio.';
    if (!preg_match('/^[a-z0-9._-]{3,50}$/', $user)) $errors[] = 'El usuario del admin va en minúsculas, sin espacios (3 a 50 letras, números, punto o guion).';
    if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) $errors[] = 'El email del admin no es válido.';
    if (strlen($pass) < 8) $errors[] = 'La contraseña del admin tiene que tener al menos 8 caracteres.';
    if ($pass !== (string)($in['admin_pass2'] ?? '')) $errors[] = 'Las dos contraseñas no coinciden.';
    $tz = (string)($in['timezone'] ?? '');
    if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) $errors[] = 'Elige una zona horaria de la lista.';
    $team = i_team($in, $user, $mail, $errors);
    $rubro = (string)($in['rubro'] ?? 'agencia');
    if (!isset(i_rubros()[$rubro])) $errors[] = 'Elige un rubro de la lista.';
    $logo = $errors ? null : i_image($files['logo'], 'logo', $errors);
    $fav = (!$errors && !empty($files['favicon']['tmp_name'])) ? i_image($files['favicon'], 'favicon', $errors) : null;
    if ($errors) return null;

    // Base: tiene que conectar y estar vacía de esta plataforma
    try {
        $pdo = new PDO('mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4', $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
    } catch (Throwable $e) { $errors[] = 'No se pudo conectar a la base con esos datos. Revisa el servidor, el nombre, el usuario y la contraseña.'; return null; }
    if ($pdo->query("SHOW TABLES LIKE 'g7_users'")->fetchColumn() && (int)$pdo->query('SELECT COUNT(*) FROM g7_users')->fetchColumn() > 0) {
        $errors[] = 'Esa base ya tiene usuarios de la plataforma. Usa una base vacía.'; return null;
    }

    // Herramientas elegidas
    $all = []; foreach (i_tools() as $g) $all += $g;
    $ed = i_edition();
    $chosen = array_values(array_intersect(array_keys($all), (array)($in['tools'] ?? [])));
    $funciones = [];
    if (in_array('clientes', $chosen, true)) { $funciones[] = 'clientes'; if (in_array('stock', $chosen, true)) $funciones[] = 'materiales'; }
    // Lo no elegido queda apagado como política (se prende en Admin › Módulos y permisos); placas_ia todavía no se distribuye
    $off = array_values(array_diff(array_keys($all), $chosen, ['clientes']));

    // Tablas base
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');   // las tablas se referencian entre sí: el orden no importa
    foreach (require I_ROOT . '/install/schema.php' as $sql) $pdo->exec($sql);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    // Configuración del sitio (fuera de la web; ninguna actualización la pisa)
    if (!is_dir(I_PRIV) && !mkdir(I_PRIV, 0755, true)) throw new RuntimeException('No se pudo crear la carpeta _private.');
    file_put_contents(I_PRIV . '/.htaccess', "# Nada de esta carpeta se sirve por web\nRequire all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    $id = i_slug($short);
    $site = ['id' => $id, 'name' => $name, 'short' => $short, 'url' => i_url(), 'logo' => $logo, 'favicon' => $fav ?: '',
        'db_host' => $db['host'], 'db_name' => $db['name'], 'db_user' => $db['user'], 'session_name' => substr($id, 0, 20) . '_session',
        'funciones' => $funciones, 'modulos_off' => ['placas_ia'], 'marcas_azar' => false, 'propios' => []];
    // Edición lite: lo que no trae queda apagado por el sitio (ni los admins lo prenden) y el actualizador no instala sus archivos
    if ($ed) { $site['edition'] = $ed['id']; $site['modulos_off'] = array_values(array_unique(array_merge($site['modulos_off'], $ed['modules_off']))); }
    $central = trim((string)($in['central'] ?? ''));
    // La instalación tiene que quedar en la red Cronos: sin el base no se activa (ver g7_activation_guard en auth.php)
    if (!preg_match('#^https://[a-z0-9.\-]+(/[a-z0-9._\-/]*)?$#i', $central)) throw new RuntimeException('Escribe la dirección del sitio base de la red Cronos (empieza con https://).');
    $site['central_url'] = rtrim($central, '/'); $site['secret'] = bin2hex(random_bytes(32));
    i_write(I_PRIV . '/site.php', "<?php\n// Configuración de este sitio. La escribió instalar.php el " . date('Y-m-d H:i') . ". Ninguna actualización la pisa.\nreturn " . var_export($site, true) . ";\n");
    i_write(I_PRIV . '/secrets.php', "<?php\n// Claves de este sitio. Carpeta bloqueada para la web.\ndefine('DB_PASS', " . var_export($db['pass'], true) . ");\n");

    // Configuración inicial y menú
    $nav = [['href' => '/toolz/', 'icon' => 'fa-house', 'label' => 'Inicio', 'enabled' => true]];
    $navIds = $chosen; if (in_array('clientes', $chosen, true) && !in_array('facturacion', $site['modulos_off'], true)) $navIds[] = 'facturacion';
    foreach (I_NAV as $mid => [$href, $icon, $label]) if (in_array($mid, $navIds, true)) $nav[] = ['href' => $href, 'icon' => $icon, 'label' => ($mid === 'stock' && !in_array('materiales', $funciones, true)) ? 'Stock' : $label, 'enabled' => true] + ($mid === 'tareas' ? ['badge' => 'tasks'] : []);
    $cfg = ['platform_name' => $name, 'accent_color' => $accent, 'timezone' => $tz, 'allow_register' => '0',
        'nav_links' => json_encode($nav, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'module_policies' => json_encode(array_fill_keys($off, ['mode' => 'off']), JSON_UNESCAPED_SLASHES), 'nav_clientes_added' => '1', 'nav_calendario_added' => '1', 'nav_facturacion_added' => '1', 'nav_materiales_v1' => '1'];
    $st = $pdo->prepare('INSERT INTO g7_config (cfg_key, cfg_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE cfg_value = VALUES(cfg_value)');
    foreach ($cfg as $k => $val) $st->execute([$k, $val]);

    // Primer admin (superadmin)
    $pdo->prepare("INSERT INTO g7_users (username, email, password, role, active, display_name, is_super) VALUES (?, ?, ?, 'admin', 1, ?, 1)")
        ->execute([$user, $mail, password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $v('admin_name', 100) ?: $user]);

    // Equipo: cada persona con usuario y contraseña temporal; el mensaje para mandarle se muestra una sola vez
    $login = i_url() . '/login.php';
    $msgs = [['name' => $v('admin_name', 100) ?: $user, 'role' => 'Administrador principal', 'text' => i_msg($v('admin_name', 100) ?: $user, $name, $login, $user, $pass, 'owner')]];
    $ins = $pdo->prepare("INSERT INTO g7_users (username, email, password, role, active, display_name, is_super) VALUES (?, ?, ?, ?, 1, ?, 0)");
    foreach ($team as $t) {
        $ins->execute([$t['user'], $t['email'], password_hash($t['pass'], PASSWORD_BCRYPT, ['cost' => 11]), $t['role'], $t['name']]);
        $msgs[] = ['name' => $t['name'], 'role' => $t['role'] === 'admin' ? 'Administrador' : 'Equipo', 'text' => i_msg($t['name'], $name, $login, $t['user'], $t['pass'], $t['role'])];
    }

    // Actualizador: versión con la que se instaló y qué archivos son de esa versión
    $ver = '';
    if (is_file(I_ROOT . '/install/release.php') && is_file(I_ROOT . '/_update/lib.php')) {
        $rel = require I_ROOT . '/install/release.php';
        require_once I_ROOT . '/_update/lib.php';
        $ver = (string)($rel['version'] ?? '');
        $files = array_filter((array)($rel['files'] ?? []), fn($p) => !u_protected((string)$p), ARRAY_FILTER_USE_KEY);
        u_json_write(U_DIR . '/manifest.json', $files);
        u_state_save(['version' => $ver, 'seq' => 0, 'history' => [['at' => date('Y-m-d H:i:s'), 'action' => 'instaló', 'version' => $ver, 'detail' => 'Instalación nueva']]]);
    }
    // Rubro: nombres, estados, campos, contenidos, Inicio, plantillas y conceptos. Las herramientas y el color ya los eligió la persona.
    // Va al final, con la plataforma ya configurada (rubro_lib usa la misma conexión y g7_config). Si falla, la instalación igual queda andando.
    $rubroErr = '';
    try {
        require_once I_ROOT . '/auth.php';
        require_once I_ROOT . '/rubro_lib.php';
        $rdb = getDB();
        rubro_apply($rdb, $rubro, array_values(array_diff(array_keys(RUBRO_PARTS), ['modules', 'look'])));
        if (!empty($in['samples'])) rubro_samples_seed($rdb, $rubro, (int)$rdb->query("SELECT id FROM g7_users WHERE username = " . $rdb->quote($user))->fetchColumn());
    } catch (Throwable $e) { $rubroErr = $e->getMessage(); }
    if (!is_dir(I_PRIV . '/updates')) @mkdir(I_PRIV . '/updates', 0755, true);
    i_write(I_PRIV . '/updates/activation_pending', date('c'));
    i_write(I_PRIV . '/installed.php', "<?php return " . var_export(['at' => date('Y-m-d H:i:s'), 'version' => $ver, 'by' => $user], true) . ";\n");

    $code = isset($site['secret']) ? base64_encode(json_encode(['id' => $id, 'name' => $name, 'url' => $site['url'], 'secret' => $site['secret']])) : '';
    $portal = in_array('clientes', $funciones, true) && is_file(I_ROOT . '/portal/index.php') ? preg_replace('#/toolz$#', '/', i_url()) : '';
    return ['rubro_error' => $rubroErr, 'name' => $name, 'user' => $user, 'version' => $ver, 'code' => $code, 'central' => $site['central_url'] ?? '', 'msgs' => $msgs, 'login' => $login, 'portal' => $portal,
        'root_ok' => is_file(dirname(I_ROOT) . '/index.php') && strpos((string)@file_get_contents(dirname(I_ROOT) . '/index.php'), 'toolz/portal') !== false];
}
/** Personas del paso Equipo: valida emails, arma el usuario desde el email (sin repetir) y una contraseña temporal. */
function i_team(array $in, string $adminUser, string $adminMail, array &$errors): array {
    $out = []; $users = [$adminUser => 1]; $mails = [strtolower($adminMail) => 1];
    foreach (array_slice((array)($in['team'] ?? []), 0, 80) as $r) {
        if (!is_array($r)) continue;
        $name = trim(mb_substr((string)($r['name'] ?? ''), 0, 100)); $mail = strtolower(trim(mb_substr((string)($r['email'] ?? ''), 0, 100)));
        if ($name === '' && $mail === '') continue;
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Equipo: falta el email de ' . ($name ?: 'una persona') . ' o no es válido.'; continue; }
        if (isset($mails[$mail])) { $errors[] = "Equipo: $mail aparece dos veces."; continue; }
        $mails[$mail] = 1;
        $base = substr(preg_replace('/[^a-z0-9._-]/', '', strtolower(explode('@', $mail)[0])), 0, 40);
        if (strlen($base) < 3) $base = 'usuario';
        $u = $base; $k = 2; while (isset($users[$u])) $u = $base . $k++;
        $users[$u] = 1;
        $out[] = ['name' => $name ?: $u, 'email' => $mail, 'user' => $u, 'role' => ($r['role'] ?? '') === 'admin' ? 'admin' : 'user', 'pass' => i_pass()];
    }
    return $out;
}
/** Una fila del paso Equipo (la usa también el JS con el mismo formato). */
function i_team_row(int $i, array $r): string {
    return '<div class="trow"><input class="inp" name="team[' . $i . '][name]" placeholder="Nombre y apellido" maxlength="100" value="' . h($r['name'] ?? '') . '">'
        . '<input class="inp" type="email" name="team[' . $i . '][email]" placeholder="email@empresa.com" maxlength="100" value="' . h($r['email'] ?? '') . '">'
        . '<select class="inp" name="team[' . $i . '][role]"><option value="user">Equipo</option><option value="admin"' . (($r['role'] ?? '') === 'admin' ? ' selected' : '') . '>Administrador</option></select>'
        . '<button type="button" class="x" title="Quitar" aria-label="Quitar"><i class="fa-solid fa-xmark"></i></button></div>';
}
/** Contraseña temporal fácil de dictar: sin letras que se confunden (l, 1, O, 0). */
function i_pass(): string {
    $a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789'; $p = '';
    for ($i = 0; $i < 12; $i++) { $p .= $a[random_int(0, strlen($a) - 1)]; if ($i === 3 || $i === 7) $p .= '-'; }
    return $p;
}
/** Mensaje de bienvenida para copiar y mandar por WhatsApp o email. */
function i_msg(string $person, string $platform, string $login, string $user, string $pass, string $role): string {
    $first = explode(' ', trim($person))[0] ?: $person;
    $t = "Hola $first:\n\nYa tienes acceso a $platform.\n\nEntra en: $login\nUsuario: $user\nContraseña" . ($role === 'owner' ? '' : ' temporal') . ": $pass\n\n";
    if ($role === 'owner') return $t . "Eres el administrador principal: desde Admin sumas personas, prendes herramientas y cambias la marca.";
    return $t . ($role === 'admin' ? "Tienes permisos de administrador. " : '') . "Cuando entres, cambia la contraseña en Mi perfil.";
}
/** Selector de zona horaria: países primero, después todas las zonas. */
const I_TZ = [
    'Latinoamérica y el Caribe' => ['America/Argentina/Buenos_Aires' => 'Argentina', 'America/Belize' => 'Belice', 'America/La_Paz' => 'Bolivia', 'America/Sao_Paulo' => 'Brasil (Brasilia, São Paulo)',
        'America/Manaus' => 'Brasil (Manaos)', 'America/Santiago' => 'Chile', 'America/Bogota' => 'Colombia', 'America/Costa_Rica' => 'Costa Rica', 'America/Havana' => 'Cuba',
        'America/Guayaquil' => 'Ecuador', 'America/El_Salvador' => 'El Salvador', 'America/Guatemala' => 'Guatemala', 'America/Port-au-Prince' => 'Haití', 'America/Tegucigalpa' => 'Honduras',
        'America/Jamaica' => 'Jamaica', 'America/Mexico_City' => 'México (Centro)', 'America/Cancun' => 'México (Cancún)', 'America/Mazatlan' => 'México (Pacífico)', 'America/Tijuana' => 'México (Tijuana)',
        'America/Managua' => 'Nicaragua', 'America/Panama' => 'Panamá', 'America/Asuncion' => 'Paraguay', 'America/Lima' => 'Perú', 'America/Puerto_Rico' => 'Puerto Rico',
        'America/Santo_Domingo' => 'República Dominicana', 'America/Port_of_Spain' => 'Trinidad y Tobago', 'America/Montevideo' => 'Uruguay', 'America/Caracas' => 'Venezuela'],
    'Norteamérica' => ['America/Toronto' => 'Canadá (Este)', 'America/Winnipeg' => 'Canadá (Centro)', 'America/Edmonton' => 'Canadá (Montaña)', 'America/Vancouver' => 'Canadá (Pacífico)',
        'America/New_York' => 'Estados Unidos (Este)', 'America/Chicago' => 'Estados Unidos (Centro)', 'America/Denver' => 'Estados Unidos (Montaña)', 'America/Phoenix' => 'Estados Unidos (Arizona)',
        'America/Los_Angeles' => 'Estados Unidos (Pacífico)', 'America/Anchorage' => 'Estados Unidos (Alaska)', 'Pacific/Honolulu' => 'Estados Unidos (Hawái)'],
    'Europa' => ['Europe/Berlin' => 'Alemania', 'Europe/Andorra' => 'Andorra', 'Europe/Vienna' => 'Austria', 'Europe/Brussels' => 'Bélgica', 'Europe/Copenhagen' => 'Dinamarca',
        'Europe/Madrid' => 'España', 'Atlantic/Canary' => 'España (Canarias)', 'Europe/Paris' => 'Francia', 'Europe/Athens' => 'Grecia', 'Europe/Dublin' => 'Irlanda', 'Europe/Rome' => 'Italia',
        'Europe/Amsterdam' => 'Países Bajos', 'Europe/Warsaw' => 'Polonia', 'Europe/Lisbon' => 'Portugal', 'Europe/London' => 'Reino Unido', 'Europe/Bucharest' => 'Rumania',
        'Europe/Stockholm' => 'Suecia', 'Europe/Zurich' => 'Suiza', 'Europe/Kyiv' => 'Ucrania', 'Europe/Kiev' => 'Ucrania'],
    'Otras regiones' => ['Australia/Sydney' => 'Australia (Sídney)', 'Asia/Shanghai' => 'China', 'Asia/Dubai' => 'Emiratos Árabes Unidos', 'Asia/Manila' => 'Filipinas', 'Asia/Kolkata' => 'India',
        'Asia/Jerusalem' => 'Israel', 'Asia/Tokyo' => 'Japón', 'Pacific/Auckland' => 'Nueva Zelanda', 'Africa/Johannesburg' => 'Sudáfrica', 'Europe/Istanbul' => 'Turquía', 'UTC' => 'UTC (hora universal)'],
];
function i_tz_select(string $cur): string {
    $ok = array_flip(DateTimeZone::listIdentifiers()); $done = false;
    $o = '<select class="inp" id="timezone" name="timezone"' . ($cur !== '' ? ' data-posted="1"' : '') . '>';
    $opt = function (string $tz, string $label) use ($cur, &$done) { $sel = !$done && $tz === $cur; if ($sel) $done = true; return '<option value="' . h($tz) . '"' . ($sel ? ' selected' : '') . '>' . h($label) . '</option>'; };
    $seen = [];
    foreach (I_TZ as $group => $list) {
        $o .= '<optgroup label="' . h($group) . '">';
        foreach ($list as $tz => $label) if (isset($ok[$tz]) && !isset($seen[$label])) { $seen[$label] = 1; $o .= $opt($tz, $label); }
        $o .= '</optgroup>';
    }
    $o .= '<optgroup label="Todas las zonas horarias">';
    foreach (array_keys($ok) as $tz) $o .= $opt($tz, str_replace(['_', '/'], [' ', ' / '], $tz));
    return $o . '</optgroup></select>';
}
function i_write(string $f, string $data): void {
    if (file_put_contents($f, $data, LOCK_EX) === false) throw new RuntimeException('No se pudo escribir ' . basename($f) . '. Revisa los permisos de la carpeta.');
    @chmod($f, 0640);
}
/** Guarda una imagen subida en /toolz/brand/ y devuelve su dirección. */
function i_image(array $f, string $base, array &$errors): ?string {
    $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || ($f['size'] ?? 0) > 3 * 1024 * 1024) { $errors[] = 'La imagen tiene que pesar menos de 3 MB.'; return null; }
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !isset($types[$info[2]])) { $errors[] = 'El ' . ($base === 'logo' ? 'logo' : 'ícono') . ' tiene que ser PNG, JPG, WebP o GIF.'; return null; }
    $dir = I_ROOT . '/brand';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = $base . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $types[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) { $errors[] = 'No se pudo guardar la imagen.'; return null; }
    return '/toolz/brand/' . $name;
}

// ── Pantalla ───────────────────────────────────────────────
const I_ICONS = ['tareas' => 'fa-table-columns', 'todos' => 'fa-list-check', 'calendario' => 'fa-calendar-days', 'chat' => 'fa-comments', 'comunidad' => 'fa-people-group', 'links' => 'fa-book-bookmark',
    'status' => 'fa-triangle-exclamation', 'encuesta' => 'fa-square-poll-vertical', 'clientes' => 'fa-address-book', 'stock' => 'fa-folder-open', 'promos' => 'fa-layer-group',
    'pages' => 'fa-file-lines', 'planify' => 'fa-calendar-days', 'stats' => 'fa-chart-line', 'imghost' => 'fa-cloud-arrow-up', 'webp' => 'fa-image', 'jpg' => 'fa-file-image',
    'svg' => 'fa-vector-square', 'recolorear' => 'fa-fill-drip', 'renombrador' => 'fa-i-cursor', 'claude' => 'fa-wand-magic-sparkles', 'placas' => 'fa-futbol',
    'placas_diarias' => 'fa-calendar-day', 'placas_paid' => 'fa-bullhorn', 'promos_gen' => 'fa-tags', 'landings' => 'fa-file-code', 'mailing' => 'fa-envelope',
    'popups' => 'fa-window-restore', 'linkgen' => 'fa-link', 'monday' => 'fa-square-check'];
const I_STEPS = [1 => 'Requisitos', 2 => 'Base de datos', 3 => 'Marca', 4 => 'Rubro', 5 => 'Herramientas', 6 => 'Administrador', 7 => 'Equipo', 8 => 'Actualizaciones'];

function i_page(string $title, string $body, string $accent = '#ff6a00'): void { ?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title><?= h($title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<script>document.documentElement.classList.add('js')</script>
<style>
:root{--acc:<?= h($accent) ?>;--bg:#0b0b0d;--side:#111114;--card:#141417;--card2:#1a1a1e;--line:rgba(255,255,255,.08);--line2:rgba(255,255,255,.14);--text:#f2f2f4;--mute:#9c9ca6;--dim:#6b6b75;--ok:#34d399;--bad:#f87171}
*{box-sizing:border-box}html,body{min-height:100%}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.55 Inter,system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}
body::before{content:"";position:fixed;inset:0;pointer-events:none;z-index:0;background:radial-gradient(900px 520px at 85% -10%,color-mix(in srgb,var(--acc) 22%,transparent),transparent 70%),
  linear-gradient(var(--line) 1px,transparent 1px) 0 0/48px 48px,linear-gradient(90deg,var(--line) 1px,transparent 1px) 0 0/48px 48px;
  -webkit-mask-image:linear-gradient(180deg,#000 0,rgba(0,0,0,.35) 60%,transparent);mask-image:linear-gradient(180deg,#000 0,rgba(0,0,0,.35) 60%,transparent)}
h1,h2,.disp{font-family:"Bricolage Grotesque",Inter,sans-serif;letter-spacing:-.025em}
.shell{position:relative;z-index:1;display:grid;grid-template-columns:290px minmax(0,1fr);min-height:100vh}
aside{background:linear-gradient(180deg,var(--side),#0d0d10);border-right:1px solid var(--line);padding:34px 26px;position:sticky;top:0;height:100vh;display:flex;flex-direction:column}
.brandmark{display:flex;align-items:center;gap:10px;font:800 17px "Bricolage Grotesque",sans-serif;margin-bottom:38px}
.brandmark i{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:var(--acc);color:#0b0b0d;font-size:15px;box-shadow:0 8px 24px color-mix(in srgb,var(--acc) 45%,transparent)}
.steps{list-style:none;margin:0;padding:0;position:relative}
.steps::before{content:"";position:absolute;left:17px;top:18px;bottom:18px;width:2px;background:var(--line)}
.steps li{position:relative;display:flex;align-items:center;gap:14px;padding:9px 0;color:var(--dim);font-weight:600;font-size:14px;transition:color .2s}
.steps li .n{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;background:var(--side);border:2px solid var(--line2);font-size:13px;position:relative;z-index:1;transition:all .25s}
.steps li.on{color:var(--text)}.steps li.on .n{border-color:var(--acc);color:var(--acc);box-shadow:0 0 0 5px color-mix(in srgb,var(--acc) 16%,transparent)}
.steps li.done{color:var(--mute);cursor:pointer}.steps li.done .n{background:var(--acc);border-color:var(--acc);color:#0b0b0d}
.aside-foot{margin-top:auto;font-size:12.5px;color:var(--dim);line-height:1.5}.aside-foot b{color:var(--mute);font-weight:600}
main{padding:56px clamp(16px,5vw,72px) 40px;max-width:1000px;width:100%}
.eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--acc);margin:0}
h1{font-size:clamp(30px,4.4vw,44px);line-height:1.05;margin:10px 0 10px;font-weight:800}
.lead{color:var(--mute);font-size:16px;margin:0 0 30px;max-width:620px}
.rubros{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:10px}.rub{display:flex;gap:12px;align-items:flex-start;padding:14px;border:1.5px solid var(--line2);border-radius:14px;cursor:pointer;position:relative;background:var(--side)}.rub input{position:absolute;opacity:0}.rub .ic{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;flex-shrink:0;background:var(--rc);color:#fff}.rub b{display:block;font-size:14px}.rub span span{display:block;font-size:12.5px;color:var(--mute);margin-top:3px;line-height:1.45}.rub .ck{position:absolute;top:10px;right:10px;width:20px;height:20px;border-radius:50%;display:none;place-items:center;background:var(--rc);color:#fff;font-size:10px}.rub:has(input:checked){border-color:var(--rc);box-shadow:0 0 0 1px var(--rc) inset}.rub:has(input:checked) .ck{display:grid}.rub:has(input:focus-visible){outline:2px solid var(--acc);outline-offset:2px}
.step{animation:in .35s cubic-bezier(.2,.7,.2,1)}.js .step{display:none}.js .step.on{display:block}
@keyframes in{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.panel{background:color-mix(in srgb,var(--card) 92%,transparent);border:1px solid var(--line);border-radius:20px;padding:26px;box-shadow:0 30px 60px -30px rgba(0,0,0,.6)}
.panel+.panel{margin-top:16px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 18px}
.l{display:block;font-size:13px;font-weight:600;color:var(--mute);margin-bottom:7px}
.inp{width:100%;background:#0d0d10;border:1px solid var(--line2);color:var(--text);border-radius:12px;padding:12px 14px;font:inherit;transition:border-color .15s,box-shadow .15s}
.inp:focus{outline:0;border-color:var(--acc);box-shadow:0 0 0 4px color-mix(in srgb,var(--acc) 18%,transparent)}.inp::placeholder{color:var(--dim)}
select.inp{appearance:none;background-image:linear-gradient(45deg,transparent 50%,var(--mute) 50%),linear-gradient(135deg,var(--mute) 50%,transparent 50%);background-position:calc(100% - 20px) 52%,calc(100% - 15px) 52%;background-size:5px 5px;background-repeat:no-repeat}
.hint{font-size:12.5px;color:var(--dim);margin-top:6px}
.reqs{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.req{display:flex;gap:12px;align-items:flex-start;padding:13px 14px;border-radius:14px;background:var(--card2);border:1px solid var(--line)}
.req i{margin-top:3px;font-size:15px}.req.ok i{color:var(--ok)}.req.bad{border-color:color-mix(in srgb,var(--bad) 45%,transparent)}.req.bad i{color:var(--bad)}
.req b{display:block;font-weight:600;font-size:14px}.req span{font-size:12.5px;color:var(--mute)}
.summary{display:flex;align-items:center;gap:14px;margin-bottom:18px;padding:16px 18px;border-radius:14px;background:color-mix(in srgb,var(--ok) 10%,transparent);border:1px solid color-mix(in srgb,var(--ok) 30%,transparent)}
.summary.bad{background:color-mix(in srgb,var(--bad) 10%,transparent);border-color:color-mix(in srgb,var(--bad) 35%,transparent)}
.summary>i{font-size:22px;color:var(--ok)}.summary.bad>i{color:var(--bad)}.summary b{display:block}.summary span{color:var(--mute);font-size:13.5px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:9px;border-radius:12px;padding:12px 20px;font:600 15px Inter,sans-serif;border:1px solid var(--line2);background:var(--card2);color:var(--text);cursor:pointer;text-decoration:none;transition:transform .12s,filter .15s,background .15s}
.btn:hover{background:#222227}.btn:active{transform:translateY(1px)}
.btn.pri{background:var(--acc);border-color:var(--acc);color:#0b0b0d;box-shadow:0 12px 30px -10px color-mix(in srgb,var(--acc) 70%,transparent)}.btn.pri:hover{filter:brightness(1.08);background:var(--acc)}
.btn:disabled{opacity:.45;cursor:not-allowed;filter:none}
.nav{position:sticky;bottom:0;z-index:5;display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:26px;padding:18px 0;background:linear-gradient(0deg,var(--bg) 65%,transparent)}
.dbtest{display:flex;align-items:center;gap:12px;margin-top:18px;flex-wrap:wrap}.dbmsg{font-size:13.5px;color:var(--mute)}.dbmsg.ok{color:var(--ok)}.dbmsg.bad{color:var(--bad)}
.brand-wrap{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start}
.drop{display:flex;align-items:center;gap:14px;padding:14px;border:1.5px dashed var(--line2);border-radius:14px;cursor:pointer;transition:border-color .15s,background .15s;background:#0d0d10}
.drop:hover,.drop.over{border-color:var(--acc);background:color-mix(in srgb,var(--acc) 6%,#0d0d10)}
.drop .th{width:64px;height:48px;border-radius:10px;background:var(--card2) center/contain no-repeat;display:grid;place-items:center;color:var(--dim);flex-shrink:0;border:1px solid var(--line)}
.drop b{display:block;font-size:14px}.drop span span{font-size:12.5px;color:var(--dim)}.drop input{position:absolute;width:1px;height:1px;opacity:0}
.swatches{display:flex;gap:9px;flex-wrap:wrap;align-items:center}
.sw{width:34px;height:34px;border-radius:50%;border:2px solid transparent;cursor:pointer;box-shadow:inset 0 0 0 1px rgba(255,255,255,.12);transition:transform .12s;padding:0}.sw:hover{transform:scale(1.08)}.sw.on{border-color:var(--text)}
.sw.custom{background:conic-gradient(#f43f5e,#eab308,#10b981,#06b6d4,#3b82f6,#8b5cf6,#f43f5e);position:relative;overflow:hidden}.sw.custom input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.preview{position:sticky;top:24px}
.mock{border-radius:16px;overflow:hidden;border:1px solid var(--line2);background:#0f0f12;box-shadow:0 40px 80px -40px rgba(0,0,0,.8)}
.mock-bar{display:flex;gap:6px;padding:10px 12px;background:#18181c;border-bottom:1px solid var(--line)}.mock-bar i{width:9px;height:9px;border-radius:50%;background:#2c2c32}
.mock-top{display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--line)}
.mock-logo{height:24px;max-width:110px;object-fit:contain;display:none}.mock-name{font-weight:700;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mock-pills{display:flex;gap:6px;margin-left:auto}.mock-pills span{font-size:11px;padding:4px 9px;border-radius:20px;background:#1d1d22;color:var(--mute)}.mock-pills span.on{background:color-mix(in srgb,var(--acc) 18%,transparent);color:var(--acc)}
.mock-body{padding:30px 22px;display:grid;place-items:center}
.mock-login{width:min(250px,100%);background:#16161a;border:1px solid var(--line);border-radius:14px;padding:20px;text-align:center}
.mock-login img{height:34px;max-width:150px;object-fit:contain;margin:0 auto 10px;display:none}
.mock-login .t{font:800 17px "Bricolage Grotesque",sans-serif;margin-bottom:12px}
.mock-login .f{height:30px;border-radius:8px;background:#0d0d10;border:1px solid var(--line);margin-bottom:8px}
.mock-login .b{height:32px;border-radius:8px;background:var(--acc);margin-top:4px}
.mock-cap{font-size:12px;color:var(--dim);text-align:center;margin-top:10px}
.tg{margin-bottom:22px}.tg-h{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px}
.tg-h h3{margin:0;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--mute)}
.tg-h button{background:none;border:0;color:var(--acc);font:600 12.5px Inter,sans-serif;cursor:pointer}
.tools{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px}
.tool{position:relative;display:flex;gap:12px;padding:14px 38px 14px 14px;border-radius:14px;background:var(--card2);border:1px solid var(--line);cursor:pointer;transition:border-color .15s,background .15s,transform .12s}
.tool:hover{border-color:var(--line2);transform:translateY(-1px)}
.tool input{position:absolute;opacity:0;pointer-events:none}
.tool .ic{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;background:#0d0d10;color:var(--mute);flex-shrink:0;transition:all .15s}
.tool b{display:block;font-weight:600;font-size:14px}.tool span span{font-size:12.5px;color:var(--dim);line-height:1.4;display:block}
.tool .ck{position:absolute;top:12px;right:12px;width:20px;height:20px;border-radius:6px;border:1.5px solid var(--line2);display:grid;place-items:center;font-size:11px;color:transparent;transition:all .15s}
.tool:has(input:checked){border-color:color-mix(in srgb,var(--acc) 55%,transparent);background:color-mix(in srgb,var(--acc) 7%,var(--card2))}
.tool:has(input:checked) .ic{background:var(--acc);color:#0b0b0d}.tool:has(input:checked) .ck{background:var(--acc);border-color:var(--acc);color:#0b0b0d}
.tool:has(input:focus-visible){outline:2px solid var(--acc);outline-offset:2px}
details.more{border-top:1px solid var(--line);padding-top:16px}details.more summary{cursor:pointer;color:var(--mute);font-weight:600;font-size:14px;list-style:none;display:flex;align-items:center;gap:8px}
details.more summary::-webkit-details-marker{display:none}
details.more summary::before{content:"\f054";font:900 11px "Font Awesome 6 Free";transition:transform .2s}details.more[open] summary::before{transform:rotate(90deg)}
details.more .tools{margin-top:12px}
.meter{height:6px;border-radius:6px;background:#202026;margin-top:8px;overflow:hidden}.meter i{display:block;height:100%;width:0;border-radius:6px;transition:width .25s,background .25s}
.switch{position:relative;display:flex;align-items:center;gap:14px;padding:16px;border-radius:14px;background:var(--card2);border:1px solid var(--line);cursor:pointer}
.switch input{position:absolute;opacity:0}.switch .tr{width:46px;height:26px;border-radius:26px;background:#2a2a30;position:relative;flex-shrink:0;transition:background .2s}
.switch .tr::after{content:"";position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;transition:transform .2s}
.switch:has(input:checked) .tr{background:var(--acc)}.switch:has(input:checked) .tr::after{transform:translateX(20px)}
.switch b{display:block}.switch span span{font-size:13px;color:var(--mute)}
.recap{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-top:16px}
.recap div{padding:14px;border-radius:14px;background:var(--card2);border:1px solid var(--line)}.recap small{display:block;font-size:11.5px;color:var(--dim);text-transform:uppercase;letter-spacing:.08em;margin-bottom:3px}.recap b{font-weight:600;word-break:break-word}
.errs{display:flex;gap:14px;padding:16px 18px;border-radius:14px;margin-bottom:18px;background:color-mix(in srgb,var(--bad) 10%,transparent);border:1px solid color-mix(in srgb,var(--bad) 35%,transparent);color:#fecaca}
.errs i{color:var(--bad);margin-top:3px}.errs ul{margin:4px 0 0;padding-left:18px}
.busy{position:fixed;inset:0;background:rgba(8,8,10,.84);backdrop-filter:blur(6px);display:none;place-items:center;z-index:50;text-align:center;padding:16px}
.busy.on{display:grid}.spin{width:56px;height:56px;border-radius:50%;border:3px solid var(--line2);border-top-color:var(--acc);animation:sp 1s linear infinite;margin:0 auto 18px}@keyframes sp{to{transform:rotate(360deg)}}
.busy p{color:var(--mute);margin:6px 0 0}
.done-wrap{position:relative;z-index:1;max-width:680px;margin:0 auto;padding:12vh 16px 80px}
.badge-ok{width:72px;height:72px;border-radius:22px;display:grid;place-items:center;background:var(--acc);color:#0b0b0d;font-size:30px;box-shadow:0 20px 50px -12px color-mix(in srgb,var(--acc) 70%,transparent);animation:pop .5s cubic-bezier(.2,1.4,.4,1);margin-bottom:28px}
@keyframes pop{from{transform:scale(.4);opacity:0}to{transform:none;opacity:1}}
.code{display:flex;gap:10px;align-items:stretch;margin-top:14px}.code textarea{flex:1;min-height:84px;resize:none;font:12px/1.5 ui-monospace,Consolas,monospace}
.team-head,.trow{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1.4fr) 150px 42px;gap:10px;align-items:center}
.team-head{font-size:11.5px;color:var(--dim);font-weight:700;margin-bottom:8px;text-transform:uppercase;letter-spacing:.08em}
.trow{margin-bottom:10px}.trow .x{width:42px;height:44px;border-radius:12px;border:1px solid var(--line);background:none;color:var(--dim);cursor:pointer}.trow .x:hover{color:var(--bad);border-color:color-mix(in srgb,var(--bad) 40%,transparent)}
.team-acts{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:6px}
.msgs-h{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.msg{margin-top:14px;border:1px solid var(--line);border-radius:14px;padding:14px;background:var(--card2)}
.msg-h{display:flex;align-items:center;gap:10px;margin-bottom:10px}.msg-h b{font-weight:600}.msg-h .tag{font-size:11.5px;color:var(--acc);background:color-mix(in srgb,var(--acc) 14%,transparent);padding:2px 9px;border-radius:20px}
.msg-h .btn{margin-left:auto;padding:7px 12px;font-size:13px}.msg textarea{min-height:170px;resize:vertical;font:13px/1.55 Inter,sans-serif}
.lockcard{position:relative;z-index:1;max-width:520px;margin:18vh auto;padding:0 16px;text-align:center}
@media(max-width:900px){.shell{grid-template-columns:1fr}aside{position:static;height:auto;padding:18px 16px;border-right:0;border-bottom:1px solid var(--line)}
 .brandmark{margin-bottom:14px}.steps{display:flex;gap:6px}.steps::before{display:none}.steps li{padding:0;flex:1;justify-content:center}.steps li .n{width:30px;height:30px;font-size:12px}.steps li .tx,.aside-foot{display:none}
 main{padding-top:28px}.brand-wrap{grid-template-columns:1fr}.preview{position:static}}
@media(max-width:700px){.team-head{display:none}.trow{grid-template-columns:1fr 42px;padding:12px;border:1px solid var(--line);border-radius:14px}.trow .inp{grid-column:1}.trow .x{grid-column:2;grid-row:1}}
@media(max-width:600px){.grid,.reqs{grid-template-columns:1fr}.panel{padding:18px;border-radius:16px}.nav .btn{flex:1}.code{flex-direction:column}.mock-pills span:not(.on){display:none}}
@media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
</style></head><body><?= $body ?></body></html>
<?php }

// ── Instalación lista ──────────────────────────────────────
if ($done) {
    ob_start(); ?>
<div class="done-wrap">
  <div class="badge-ok"><i class="fa-solid fa-check"></i></div>
  <p class="eyebrow">Instalación terminada<?= $done['version'] ? ' · versión ' . h($done['version']) : '' ?></p>
  <h1><?= h($done['name']) ?> ya está lista</h1>
  <?php if (!empty($done['rubro_error'])): ?><p class="lead" style="color:#fbbf24">No se pudo aplicar el rubro (<?= h($done['rubro_error']) ?>). La plataforma anda igual: elígelo de nuevo en Admin › Rubro.</p><?php endif; ?>
  <p class="lead">Entra con el usuario <b style="color:var(--text)"><?= h($done['user']) ?></b> y la contraseña que elegiste. Abajo están los mensajes de acceso para mandar a cada persona.</p>
  <?php if ($done['portal']): ?>
  <div class="panel">
    <h2 style="margin:0 0 6px;font-size:19px"><i class="fa-solid fa-id-card" style="color:var(--acc);margin-right:8px"></i>Panel de clientes</h2>
    <p style="color:var(--mute);margin:0;font-size:14px">Cada cliente entra en <a href="<?= h($done['portal']) ?>" target="_blank" rel="noopener"><?= h($done['portal']) ?></a> y ve sus contenidos y sus facturas. El acceso de cada uno se activa en Clientes › ficha del cliente › Dashboard.<?php if (!$done['root_ok']): ?> <b style="color:var(--text)">Falta el index.php de la raíz:</b> súbelo desde el paquete de instalación para que el panel aparezca en esa dirección.<?php endif; ?></p>
  </div>
  <?php endif; ?>
  <?php if ($done['msgs']): ?>
  <div class="panel">
    <div class="msgs-h"><h2 style="margin:0;font-size:19px"><i class="fa-regular fa-paper-plane" style="color:var(--acc);margin-right:8px"></i>Mensajes de acceso</h2><button class="btn" type="button" id="copy-all"><i class="fa-regular fa-copy"></i> Copiar todos</button></div>
    <p style="color:var(--mute);margin:8px 0 0;font-size:14px">Copia cada mensaje y mandáselo a esa persona por WhatsApp o email. <b style="color:var(--text)">Las contraseñas no se vuelven a mostrar:</b> si se pierden, se cambian desde Admin › Usuarios.</p>
    <?php foreach ($done['msgs'] as $k => $m): ?>
    <div class="msg"><div class="msg-h"><b><?= h($m['name']) ?></b><span class="tag"><?= h($m['role']) ?></span><button class="btn copy" type="button" data-k="<?= $k ?>"><i class="fa-regular fa-copy"></i> Copiar</button></div><textarea class="inp" id="msg-<?= $k ?>" readonly><?= h($m['text']) ?></textarea></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($done['code']): ?>
  <div class="panel">
    <h2 style="margin:0 0 6px;font-size:19px"><i class="fa-solid fa-link" style="color:var(--acc);margin-right:8px"></i>Último paso: activarla en la red Cronos</h2>
    <p style="color:#fbbf24;margin:0 0 8px;font-size:14px"><b>La plataforma todavía no se puede usar:</b> queda habilitada cuando el sitio base la conecta con este código.</p>
    <p style="color:var(--mute);margin:0;font-size:14px">Pega este código en el sitio base (<?= h($done['central']) ?>) › Agregar sitio. Hasta entonces no llegan versiones nuevas. Si lo pierdes, está en Admin › Actualizaciones.</p>
    <div class="code"><textarea class="inp" id="code" readonly><?= h($done['code']) ?></textarea><button class="btn" type="button" id="copy"><i class="fa-regular fa-copy"></i> Copiar</button></div>
  </div>
  <?php endif; ?>
  <div class="panel">
    <h2 style="margin:0 0 10px;font-size:19px">Antes de sumar al equipo</h2>
    <ul style="margin:0 0 20px;padding-left:20px;color:var(--mute);font-size:14px;line-height:1.8"><li>Pídele al hosting un límite de subida de 64 MB o más, para los archivos de clientes.</li><li>Carga en Admin las claves que vayas a usar: inteligencia artificial, email.</li><li>Este asistente ya no vuelve a correr.</li></ul>
    <a class="btn pri" href="/toolz/login.php" style="width:100%">Ir al login <i class="fa-solid fa-arrow-right"></i></a>
  </div>
</div>
<script>
const cp = (text, b) => { const ok = () => { const o = b.innerHTML; b.innerHTML = '<i class="fa-solid fa-check"></i> Copiado'; setTimeout(() => b.innerHTML = o, 1800); };
  if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(ok); else { const t = document.createElement('textarea'); t.value = text; document.body.append(t); t.select(); document.execCommand('copy'); t.remove(); ok(); } };
const c = document.getElementById('copy'); if (c) c.onclick = () => cp(document.getElementById('code').value, c);
document.querySelectorAll('.copy').forEach(b => b.onclick = () => cp(document.getElementById('msg-' + b.dataset.k).value, b));
const all = document.getElementById('copy-all'); if (all) all.onclick = () => cp([...document.querySelectorAll('.msg textarea')].map(t => t.value).join('\n\n────────────\n\n'), all);
</script>
<?php i_page('Instalación lista', ob_get_clean(), preg_match('/^#[0-9a-f]{6}$/i', (string)($in['accent'] ?? '')) ? (string)$in['accent'] : '#ff6a00'); exit;
}

// ── Asistente ──────────────────────────────────────────────
$checks = i_checks(); $allOk = !in_array(false, array_column($checks, 1), true); $nBad = count(array_filter($checks, fn($c) => !$c[1]));
$sent = $_SERVER['REQUEST_METHOD'] === 'POST'; $val = fn($k, $d = '') => h($in[$k] ?? $d);
$start = $errors ? i_step_of($errors[0]) : 1;
$accent = preg_match('/^#[0-9a-f]{6}$/i', (string)($in['accent'] ?? '')) ? strtolower((string)$in['accent']) : '#ff6a00';
$errBox = fn(int $n) => ($errors && $n === $start) ? '<div class="errs"><i class="fa-solid fa-circle-exclamation"></i><div><b>Revisa esto antes de seguir</b><ul>' . implode('', array_map(fn($e) => '<li>' . h($e) . '</li>', $errors)) . '</ul></div></div>' : '';
$sel = (array)($in['tools'] ?? []);
ob_start(); ?>
<div class="shell">
<aside>
  <div class="brandmark"><i class="fa-solid fa-bolt"></i> Instalación<?= i_edition() ? ' · ' . h(i_edition()['name']) : '' ?></div>
  <ol class="steps" id="steps"><?php foreach (I_STEPS as $n => $t) echo '<li data-n="' . $n . '"><span class="n">' . $n . '</span><span class="tx">' . h($t) . '</span></li>'; ?></ol>
  <div class="aside-foot"><b>Tarda un minuto.</b><br>Nada se guarda hasta el último paso: puedes ir y volver.</div>
</aside>
<main>
<form method="post" enctype="multipart/form-data" autocomplete="off" id="f" novalidate>

<section class="step" data-step="1">
  <p class="eyebrow"><i class="fa-solid fa-server"></i> Paso 1 de 8</p>
  <h1>Bienvenido. Revisemos el hosting.</h1>
  <p class="lead">El asistente instala la plataforma con tu marca y tus herramientas. Primero, lo que necesita del servidor.</p>
  <?= $errBox(1) ?>
  <div class="summary<?= $allOk ? '' : ' bad' ?>"><i class="fa-solid <?= $allOk ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i><div><b><?= $allOk ? 'Todo listo para instalar' : ($nBad === 1 ? 'Falta 1 cosa' : "Faltan $nBad cosas") ?></b><span><?= $allOk ? 'El servidor tiene todo lo necesario.' : 'Pídeselo al hosting y recarga esta página.' ?></span></div></div>
  <div class="reqs"><?php foreach ($checks as [$label, $ok, $note]) echo '<div class="req ' . ($ok ? 'ok' : 'bad') . '"><i class="fa-solid ' . ($ok ? 'fa-check' : 'fa-xmark') . '"></i><div><b>' . h($label) . '</b><span>' . h($note) . '</span></div></div>'; ?></div>
</section>

<section class="step" data-step="2">
  <p class="eyebrow"><i class="fa-solid fa-database"></i> Paso 2 de 8</p>
  <h1>Conecta la base de datos</h1>
  <p class="lead">Créala vacía en el panel del hosting (por ejemplo cPanel › Bases de datos MySQL) con un usuario que tenga todos los permisos sobre ella.</p>
  <?= $errBox(2) ?>
  <div class="panel"><div class="grid">
    <div><label class="l" for="db_name">Nombre de la base</label><input class="inp" id="db_name" name="db_name" value="<?= $val('db_name') ?>" required placeholder="usuario_plataforma"></div>
    <div><label class="l" for="db_user">Usuario</label><input class="inp" id="db_user" name="db_user" value="<?= $val('db_user') ?>" required></div>
    <div><label class="l" for="db_pass">Contraseña</label><input class="inp" type="password" id="db_pass" name="db_pass" autocomplete="new-password"></div>
    <div><label class="l" for="db_host">Servidor</label><input class="inp" id="db_host" name="db_host" value="<?= $val('db_host', 'localhost') ?>"><div class="hint">Casi siempre es localhost.</div></div>
  </div>
  <div class="dbtest"><button class="btn" type="button" id="dbtest"><i class="fa-solid fa-plug"></i> Probar conexión</button><span class="dbmsg" id="dbmsg" role="status"></span></div></div>
</section>

<section class="step" data-step="3">
  <p class="eyebrow"><i class="fa-solid fa-palette"></i> Paso 3 de 8</p>
  <h1>Tu marca, de punta a punta</h1>
  <p class="lead">El nombre y el logo aparecen en el login, la barra de arriba, los títulos, las facturas y los correos.</p>
  <?= $errBox(3) ?>
  <div class="brand-wrap">
    <div class="panel" style="display:grid;gap:16px">
      <div><label class="l" for="name">Nombre de la plataforma</label><input class="inp" id="name" name="name" value="<?= $val('name') ?>" maxlength="80" required placeholder="Ej: Estudio Norte"></div>
      <div><label class="l" for="short">Nombre corto <span style="color:var(--dim);font-weight:500">· para las pestañas</span></label><input class="inp" id="short" name="short" value="<?= $val('short') ?>" maxlength="30" placeholder="Vacío = el mismo"></div>
      <div><span class="l">Logo</span><label class="drop" id="drop-logo"><span class="th" id="th-logo"><i class="fa-regular fa-image"></i></span><span><b id="lb-logo">Elige o suelta el logo</b><span>PNG, JPG o WebP · fondo transparente · hasta 3 MB</span></span><input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" required></label></div>
      <div><span class="l">Ícono de la pestaña <span style="color:var(--dim);font-weight:500">· opcional, cuadrado</span></span><label class="drop" id="drop-fav"><span class="th" id="th-fav" style="width:48px"><i class="fa-regular fa-square"></i></span><span><b id="lb-fav">Elige el ícono</b><span>Si no subes uno, se usa el logo</span></span><input type="file" id="favicon" name="favicon" accept="image/png,image/jpeg,image/webp,image/gif"></label></div>
      <div><span class="l">Color principal</span><div class="swatches" id="sw"><?php foreach (['#ff6a00', '#f43f5e', '#8b5cf6', '#3b82f6', '#06b6d4', '#10b981', '#eab308'] as $c) echo '<button type="button" class="sw' . ($c === $accent ? ' on' : '') . '" style="background:' . $c . '" data-c="' . $c . '" aria-label="Color ' . $c . '"></button>'; ?><label class="sw custom" title="Otro color"><input type="color" id="accent" name="accent" value="<?= h($accent) ?>" aria-label="Elegir otro color"></label></div></div>
      <div><label class="l" for="timezone">País y zona horaria</label><?= i_tz_select((string)($in['timezone'] ?? '')) ?><div class="hint" id="tz-now"></div></div>
    </div>
    <div class="preview">
      <div class="mock"><div class="mock-bar"><i></i><i></i><i></i></div>
        <div class="mock-top"><img class="mock-logo" id="pv-logo" alt=""><span class="mock-name" id="pv-name">Tu plataforma</span><div class="mock-pills"><span class="on">Inicio</span><span>Trabajo</span><span>Chat</span></div></div>
        <div class="mock-body"><div class="mock-login"><img id="pv-logo2" alt=""><div class="t" id="pv-name2">Tu plataforma</div><div class="f"></div><div class="f"></div><div class="b"></div></div></div>
      </div>
      <div class="mock-cap">Así se va a ver. Todo se puede cambiar después en Admin.</div>
    </div>
  </div>
</section>

<section class="step" data-step="4">
  <p class="eyebrow"><i class="fa-solid fa-briefcase"></i> Paso 4 de 8</p>
  <h1>¿A qué se dedica tu negocio?</h1>
  <p class="lead">La plataforma se acomoda al rubro: cómo se llaman las cosas, los estados, los datos de cada ficha, el Inicio y las herramientas sugeridas. Todo se puede cambiar después en Admin › Rubro.</p>
  <?= $errBox(4) ?>
  <div class="panel">
    <div class="rubros"><?php $rSel = (string)($in['rubro'] ?? 'agencia'); foreach (i_rubros() as $r) echo '<label class="rub" style="--rc:' . h($r['accent']) . '"><input type="radio" name="rubro" value="' . h($r['id']) . '"' . ($r['id'] === $rSel ? ' checked' : '') . ' data-mods="' . h(implode(',', $r['modules_on'])) . '" data-client="' . h($r['vocab']['client']['p'] ?? 'Clientes') . '"><span class="ic"><i class="fa-solid ' . h($r['icon']) . '"></i></span><span><b>' . h($r['name']) . '</b><span>' . h($r['desc']) . '</span></span><span class="ck"><i class="fa-solid fa-check"></i></span></label>'; ?></div>
    <label class="switch" style="margin-top:16px"><input type="checkbox" name="samples" value="1"<?= !empty($in['samples']) ? ' checked' : '' ?>><span class="tr"></span><span><b>Cargar datos de ejemplo</b><span>Algunas fichas y tareas de muestra para recorrer la plataforma. Se borran con un botón en Admin › Rubro.</span></span></label>
  </div>
</section>

<section class="step" data-step="5">
  <p class="eyebrow"><i class="fa-solid fa-toolbox"></i> Paso 5 de 8</p>
  <h1>Elige las herramientas</h1>
  <p class="lead">Marca lo que va a usar tu equipo. Lo demás queda apagado y se prende cuando quieras desde Admin › Módulos y permisos.</p>
  <?= $errBox(4) ?>
  <div class="panel">
  <?php foreach (i_tools() as $group => $tools) {
      $cards = '';
      foreach ($tools as $id => [$label, $desc, $on]) $cards .= '<label class="tool"><input type="checkbox" name="tools[]" value="' . h($id) . '"' . (($sent ? in_array($id, $sel, true) : $on) ? ' checked' : '') . '><span class="ic"><i class="fa-solid ' . (I_ICONS[$id] ?? 'fa-puzzle-piece') . '"></i></span><span><b>' . h($label) . '</b><span>' . h($desc) . '</span></span><span class="ck"><i class="fa-solid fa-check"></i></span></label>';
      if ($group === 'Casino y apuestas') echo '<details class="more"><summary>Herramientas para casino y apuestas</summary><div class="tools">' . $cards . '</div></details>';
      else echo '<div class="tg"><div class="tg-h"><h3>' . h($group) . '</h3><button type="button" class="all">Todas</button></div><div class="tools">' . $cards . '</div></div>';
  } ?>
  </div>
</section>

<section class="step" data-step="6">
  <p class="eyebrow"><i class="fa-solid fa-user-shield"></i> Paso 6 de 8</p>
  <h1>Crea tu cuenta de administrador</h1>
  <p class="lead">Esta cuenta puede todo, incluidas las actualizaciones. Al resto del equipo lo sumas después desde Admin.</p>
  <?= $errBox(5) ?>
  <div class="panel"><div class="grid">
    <div><label class="l" for="admin_name">Tu nombre</label><input class="inp" id="admin_name" name="admin_name" value="<?= $val('admin_name') ?>" placeholder="Ana Pérez"></div>
    <div><label class="l" for="admin_email">Email</label><input class="inp" type="email" id="admin_email" name="admin_email" value="<?= $val('admin_email') ?>" required placeholder="ana@estudio.com"></div>
    <div><label class="l" for="admin_user">Usuario para entrar</label><input class="inp" id="admin_user" name="admin_user" value="<?= $val('admin_user', 'admin') ?>" required pattern="[a-z0-9._\-]{3,50}"><div class="hint">En minúsculas, sin espacios.</div></div>
    <div></div>
    <div><label class="l" for="admin_pass">Contraseña <button type="button" id="pw-gen" style="float:right;background:none;border:0;color:var(--acc);font:600 12.5px Inter,sans-serif;cursor:pointer"><i class="fa-solid fa-wand-magic-sparkles"></i> Generar una</button></label><input class="inp" type="password" id="admin_pass" name="admin_pass" minlength="8" required autocomplete="new-password"><div class="meter"><i id="meter"></i></div><div class="hint" id="meter-t">8 caracteres o más.</div></div>
    <div><label class="l" for="admin_pass2">Repetila</label><input class="inp" type="password" id="admin_pass2" name="admin_pass2" minlength="8" required autocomplete="new-password"><div class="hint" id="match"></div></div>
  </div></div>
</section>

<section class="step" data-step="7">
  <p class="eyebrow"><i class="fa-solid fa-users"></i> Paso 7 de 8</p>
  <h1>Suma al equipo</h1>
  <p class="lead">Opcional. Cada persona recibe un usuario y una contraseña temporal, y al terminar te damos el mensaje listo para mandarle. Más adelante se suman otras desde Admin › Usuarios.</p>
  <?= $errBox(6) ?>
  <div class="panel">
    <div class="team-head"><span>Nombre</span><span>Email</span><span>Rol</span><span></span></div>
    <div id="team"><?php $rows = array_values(array_filter((array)($in['team'] ?? []), 'is_array')); if (!$rows) $rows = [[]]; foreach ($rows as $i => $r) echo i_team_row($i, $r); ?></div>
    <div class="team-acts"><button type="button" class="btn" id="team-add"><i class="fa-solid fa-user-plus"></i> Agregar persona</button><button type="button" class="btn" id="paste-t"><i class="fa-regular fa-paste"></i> Pegar una lista</button><span class="hint" id="team-n" style="margin:0 0 0 auto"></span></div>
    <div id="paste-box" hidden style="margin-top:16px"><label class="l" for="paste">Una persona por línea: nombre y email, separados por coma, punto y coma o tabulación (sirve copiar dos columnas de una planilla). Si la línea dice <b>admin</b>, entra como administrador.</label>
      <textarea class="inp" id="paste" rows="5" placeholder="Juan Gómez, juan@cliente.com&#10;María López; maria@cliente.com; admin"></textarea>
      <button type="button" class="btn pri" id="paste-go" style="margin-top:10px">Agregar a la lista</button></div>
  </div>
</section>

<section class="step" data-step="8">
  <p class="eyebrow"><i class="fa-solid fa-cloud-arrow-down"></i> Paso 8 de 8</p>
  <h1>Último paso: actualizaciones</h1>
  <p class="lead">Conectada al sitio base, la plataforma recibe arreglos y funciones nuevas con un clic desde Admin › Actualizaciones.</p>
  <?= $errBox(7) ?>
  <div class="panel" style="display:grid;gap:14px">
    <input type="hidden" name="connect" value="1" id="connect">
    <p style="margin:0;font-size:14px;line-height:1.6"><b>La plataforma se activa al conectarla a la red Cronos.</b> Al terminar te da un código: el sitio base lo carga en Red Cronos › Agregar sitio y, desde ese momento, el sistema queda habilitado y recibe las actualizaciones. Hasta entonces no se puede usar.</p>
    <div id="central-box"><label class="l" for="central">Dirección del sitio base</label><input class="inp" type="url" id="central" name="central" value="<?= $val('central', 'https://cronostoolz.com/base') ?>"></div>
  </div>
  <div class="recap" id="recap"></div>
</section>

<div class="nav">
  <button class="btn" type="button" id="back"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
  <button class="btn pri" type="submit" id="next">Empezar <i class="fa-solid fa-arrow-right"></i></button>
</div>
</form>
</main>
</div>
<div class="busy" id="busy" role="status"><div><div class="spin"></div><h2 class="disp" style="margin:0;font-size:24px">Instalando…</h2><p>Creando las tablas y guardando tu configuración. No cierres esta página.</p></div></div>
<script>
(() => {
  const $ = id => document.getElementById(id), f = $('f'), steps = [...document.querySelectorAll('.step')], items = [...document.querySelectorAll('#steps li')];
  const reqOk = <?= $allOk ? 'true' : 'false' ?>; let cur = <?= (int)$start ?>, maxSeen = <?= $errors ? 8 : 1 ?>;
  function show(n) {
    cur = n; maxSeen = Math.max(maxSeen, n);
    steps.forEach(s => s.classList.toggle('on', +s.dataset.step === n));
    items.forEach(li => { const k = +li.dataset.n, d = k < maxSeen && k !== n; li.classList.toggle('on', k === n); li.classList.toggle('done', d); li.querySelector('.n').innerHTML = d ? '<i class="fa-solid fa-check"></i>' : k; });
    $('back').style.visibility = n === 1 ? 'hidden' : 'visible';
    $('next').innerHTML = n === 1 ? 'Empezar <i class="fa-solid fa-arrow-right"></i>' : n === 8 ? '<i class="fa-solid fa-bolt"></i> Instalar' : 'Siguiente <i class="fa-solid fa-arrow-right"></i>';
    $('next').disabled = n === 1 && !reqOk;
    if (n === 8) recap();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    const first = steps[n - 1].querySelector('input.inp,select'); if (first && n > 1) setTimeout(() => first.focus({ preventScroll: true }), 60);
  }
  function valid(n) {
    for (const el of steps[n - 1].querySelectorAll('input[required],input[pattern],input[type=email],input[type=url]')) {

      if (el.checkValidity()) continue;
      if (el.type === 'file') { $('drop-logo').classList.add('over'); $('lb-logo').textContent = 'Falta el logo: es obligatorio'; $('drop-logo').scrollIntoView({ block: 'center', behavior: 'smooth' }); return false; }
      el.reportValidity(); return false;
    }
    if (n === 6 && $('admin_pass').value !== $('admin_pass2').value) { $('admin_pass2').setCustomValidity('Las contraseñas no coinciden'); $('admin_pass2').reportValidity(); $('admin_pass2').setCustomValidity(''); return false; }
    return true;
  }
  f.addEventListener('submit', e => {
    e.preventDefault();
    if (!valid(cur)) return;
    if (cur < 8) return show(cur + 1);
    for (let k = 2; k <= 6; k++) if (!valid(k)) { show(k); setTimeout(() => valid(k), 400); return; }
    $('busy').classList.add('on'); $('next').disabled = true; f.submit();
  });
  $('back').onclick = () => cur > 1 && show(cur - 1);
  items.forEach(li => li.onclick = () => { const k = +li.dataset.n; if (k <= maxSeen && k !== cur) show(k); });

  // Probar conexión
  $('dbtest').onclick = async () => {
    const m = $('dbmsg'), b = $('dbtest'), fd = new FormData(); fd.append('test_db', '1');
    ['db_host', 'db_name', 'db_user', 'db_pass'].forEach(k => fd.append(k, $(k).value));
    b.disabled = true; m.className = 'dbmsg'; m.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Probando…';
    try { const r = await (await fetch(location.pathname, { method: 'POST', body: fd })).json(); m.className = 'dbmsg ' + (r.ok ? 'ok' : 'bad'); m.innerHTML = `<i class="fa-solid ${r.ok ? 'fa-circle-check' : 'fa-circle-xmark'}"></i> ` + String(r.msg).replace(/</g, '&lt;'); }
    catch (e) { m.className = 'dbmsg bad'; m.textContent = 'No se pudo probar. Sigue igual: se comprueba al instalar.'; }
    b.disabled = false;
  };

  // Marca en vivo
  const setAcc = c => { document.documentElement.style.setProperty('--acc', c); document.querySelectorAll('.sw[data-c]').forEach(s => s.classList.toggle('on', s.dataset.c === c)); };
  document.querySelectorAll('.sw[data-c]').forEach(s => s.onclick = () => { $('accent').value = s.dataset.c; setAcc(s.dataset.c); });
  $('accent').oninput = e => setAcc(e.target.value);
  const nm = () => { const v = $('name').value.trim() || 'Tu plataforma'; $('pv-name').textContent = v; $('pv-name2').textContent = v; };
  $('name').oninput = nm; nm();
  function pic(input, th, lb, imgs) {
    const file = input.files[0]; if (!file) return;
    if (file.size > 3 * 1024 * 1024) { lb.textContent = 'Pesa más de 3 MB: elige otra'; input.value = ''; return; }
    const url = URL.createObjectURL(file);
    th.style.backgroundImage = `url(${url})`; th.innerHTML = ''; lb.textContent = file.name; th.closest('.drop').classList.remove('over');
    imgs.forEach(i => { i.src = url; i.style.display = 'block'; });
    if (imgs.length) $('pv-name').style.display = 'none';
  }
  $('logo').onchange = () => pic($('logo'), $('th-logo'), $('lb-logo'), [$('pv-logo'), $('pv-logo2')]);
  $('favicon').onchange = () => pic($('favicon'), $('th-fav'), $('lb-fav'), []);
  for (const [drop, input] of [['drop-logo', 'logo'], ['drop-fav', 'favicon']]) {
    const d = $(drop);
    d.ondragover = e => { e.preventDefault(); d.classList.add('over'); }; d.ondragleave = () => d.classList.remove('over');
    d.ondrop = e => { e.preventDefault(); d.classList.remove('over'); if (e.dataTransfer.files.length) { $(input).files = e.dataTransfer.files; $(input).onchange(); } };
  }

  // Herramientas: "Todas" marca o desmarca el grupo
  document.querySelectorAll('.tg .all').forEach(b => b.onclick = () => { const cbs = [...b.closest('.tg').querySelectorAll('input')], on = !cbs.every(c => c.checked); cbs.forEach(c => c.checked = on); });

  // Contraseña
  $('admin_pass2').oninput = () => { const a = $('admin_pass').value, b = $('admin_pass2').value; $('match').innerHTML = !b ? '' : a === b ? '<span style="color:var(--ok)"><i class="fa-solid fa-check"></i> Coinciden</span>' : '<span style="color:var(--bad)">Todavía no coinciden</span>'; };
  $('admin_pass').oninput = () => {
    const p = $('admin_pass').value; let s = 0; if (p.length >= 8) s++; if (p.length >= 12) s++; if (/[A-Z]/.test(p) && /[a-z]/.test(p)) s++; if (/\d/.test(p)) s++; if (/[^A-Za-z0-9]/.test(p)) s++;
    const k = p ? Math.max(1, s) : 0, lv = [['', '8 caracteres o más.'], ['#f87171', 'Débil'], ['#fbbf24', 'Aceptable'], ['#a3e635', 'Buena'], ['#34d399', 'Muy buena'], ['#34d399', 'Excelente']][k];
    $('meter').style.width = k * 20 + '%'; $('meter').style.background = lv[0]; $('meter-t').textContent = p.length && p.length < 8 ? `Te faltan ${8 - p.length} caracteres.` : lv[1];
    $('admin_pass2').oninput();
  };

  // Generar la contraseña del admin: la muestra en los dos campos para que se pueda copiar
  $('pw-gen').onclick = () => {
    const sets = ['abcdefghjkmnpqrstuvwxyz', 'ABCDEFGHJKMNPQRSTUVWXYZ', '23456789', '!#$%&*+-=?@'], all = sets.join('');
    const rnd = n => { const a = new Uint32Array(1); crypto.getRandomValues(a); return a[0] % n; };
    const out = sets.map(s => s[rnd(s.length)]); while (out.length < 14) out.push(all[rnd(all.length)]);
    for (let i = out.length - 1; i > 0; i--) { const j = rnd(i + 1); [out[i], out[j]] = [out[j], out[i]]; }
    ['admin_pass', 'admin_pass2'].forEach(k => { $(k).value = out.join(''); $(k).type = 'text'; });
    $('admin_pass').oninput(); $('admin_pass').select();
  };

  // Resumen antes de instalar

  // Rubro: marca las herramientas que sugiere (se pueden cambiar en el paso siguiente)
  f.querySelectorAll('input[name=rubro]').forEach(r => r.addEventListener('change', () => {
    const mods = r.dataset.mods.split(',');
    f.querySelectorAll('input[name="tools[]"]').forEach(t => { t.checked = mods.includes(t.value); });
  }));
  function recap() {
    const n = f.querySelectorAll('input[name="tools[]"]:checked').length, e = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    $('recap').innerHTML = [['Plataforma', $('name').value || '—'], ['Rubro', (f.querySelector('input[name=rubro]:checked')?.closest('.rub')?.querySelector('b')?.textContent) || '—'], ['Base de datos', $('db_name').value || '—'], ['Herramientas', n + (n === 1 ? ' elegida' : ' elegidas')], ['Administrador', $('admin_user').value || '—'], ['Equipo', teamCount() ? teamCount() + (teamCount() === 1 ? ' persona' : ' personas') : 'Nadie más por ahora']]
      .map(([k, v]) => `<div><small>${k}</small><b>${e(v)}</b></div>`).join('');
  }
  // Zona horaria: la del navegador si está en la lista, y la hora de allá
  const tzSel = $('timezone');
  if (!tzSel.dataset.posted) { try { const b = Intl.DateTimeFormat().resolvedOptions().timeZone, o = [...tzSel.options].find(x => x.value === b); if (o) o.selected = true; } catch (e) {} }
  const tzNow = () => { try { $('tz-now').textContent = 'Hora en esa zona: ' + new Date().toLocaleTimeString('es', { timeZone: tzSel.value, hour: '2-digit', minute: '2-digit' }); } catch (e) { $('tz-now').textContent = ''; } };
  tzSel.onchange = tzNow; tzNow();

  // Equipo: filas, pegar una lista y contador
  let tIdx = 1000;
  const row = (r = {}) => `<div class="trow"><input class="inp" name="team[${tIdx}][name]" placeholder="Nombre y apellido" maxlength="100" value="${esc(r.name || '')}"><input class="inp" type="email" name="team[${tIdx}][email]" placeholder="email@empresa.com" maxlength="100" value="${esc(r.email || '')}"><select class="inp" name="team[${tIdx++}][role]"><option value="user">Equipo</option><option value="admin"${r.role === 'admin' ? ' selected' : ''}>Administrador</option></select><button type="button" class="x" title="Quitar" aria-label="Quitar"><i class="fa-solid fa-xmark"></i></button></div>`;
  const esc = v => String(v).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const teamCount = () => [...document.querySelectorAll('#team .trow')].filter(r => r.querySelector('input[type=email]').value.trim()).length;
  const tCount = () => { const n = teamCount(); $('team-n').textContent = n ? `${n} ${n === 1 ? 'persona' : 'personas'} en la lista` : ''; };
  $('team').addEventListener('click', e => { const x = e.target.closest('.x'); if (!x) return; const rows = document.querySelectorAll('#team .trow'); if (rows.length > 1) x.closest('.trow').remove(); else rows[0].querySelectorAll('input').forEach(i => i.value = ''); tCount(); });
  $('team').addEventListener('input', tCount);
  $('team-add').onclick = () => { $('team').insertAdjacentHTML('beforeend', row()); $('team').lastElementChild.querySelector('input').focus(); };
  $('paste-t').onclick = () => { $('paste-box').hidden = !$('paste-box').hidden; if (!$('paste-box').hidden) $('paste').focus(); };
  $('paste-go').onclick = () => {
    const lines = $('paste').value.split(/\r?\n/).map(l => l.trim()).filter(Boolean); let n = 0;
    lines.forEach(l => {
      const parts = l.split(/[,;\t]/).map(p => p.trim()).filter(Boolean), email = parts.find(p => /@/.test(p)); if (!email) return;
      const name = parts.filter(p => p !== email && !/^admin(istrador)?$/i.test(p)).join(' '), role = parts.some(p => /^admin(istrador)?$/i.test(p)) ? 'admin' : 'user';
      const empty = [...document.querySelectorAll('#team .trow')].find(r => [...r.querySelectorAll('input')].every(i => !i.value.trim()));
      if (empty) empty.remove();
      $('team').insertAdjacentHTML('beforeend', row({ name, email, role })); n++;
    });
    $('paste').value = ''; $('paste-box').hidden = true; tCount();
    if (!n) alert('No encontré emails en esa lista.');
  };
  tCount();
  show(cur);
})();
</script>
<?php i_page('Instalar la plataforma', ob_get_clean(), $accent);
