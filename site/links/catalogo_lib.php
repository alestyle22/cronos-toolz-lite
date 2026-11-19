<?php
/**
 * links/catalogo_lib.php — Catálogo Cronos en la Biblioteca: herramientas web que viven en el sitio base y se COPIAN a esta instalación.
 * Habla con {central_url}/herramientas_api.php con la clave de este sitio (_private/site.php), igual que Soporte.
 * Cada herramienta agregada es una tarjeta normal de la Biblioteca con su HTML en _private/biblioteca/<id>.html (se abre aislada con
 * links/ver.php) y recuerda de dónde vino: g7_links.cat_tool (id en el catálogo), cat_ver (versión) y cat_sha (huella del HTML).
 * Doc: toolz/CATALOGO.md
 */
require_once dirname(__DIR__) . '/_update/lib.php';

/** Conexión con el base. G7_CAT_CFG solo lo definen las pruebas locales (prepend de dev/base_local), nunca un sitio. */
function cat_cfg(): array { return defined('G7_CAT_CFG') ? array_merge(u_cfg(), G7_CAT_CFG) : u_cfg(); }
function cat_connected(): bool { $c = cat_cfg(); return $c['central_url'] !== '' && $c['secret'] !== '' && $c['id'] !== ''; }

/** Pedido firmado al base. Devuelve el JSON o lanza RuntimeException con un texto claro. */
function cat_call(array $payload, int $timeout = 30): array {
    if (!cat_connected()) throw new RuntimeException('Este sitio todavía no está conectado al Cronos principal: el catálogo no está disponible.');
    $c = cat_cfg();
    if (isset($GLOBALS['CAT_CALL'])) [$code, $body] = ($GLOBALS['CAT_CALL'])($payload);   // las pruebas lo reemplazan
    else {
        try { [$code, $body] = u_post(rtrim($c['central_url'], '/') . '/herramientas_api.php', $c['id'], $c['secret'], $payload, $timeout); }
        catch (Throwable $e) { throw new RuntimeException('No pudimos llegar al Cronos principal. Prueba de nuevo en un rato.'); }
    }
    $j = json_decode((string)$body, true);
    if ($code === 403 && is_array($j) && ($j['error'] ?? '') === 'No autorizado') throw new RuntimeException('El Cronos principal no reconoce este sitio. Avisale a un administrador.');
    if ($code !== 200 || !is_array($j)) throw new RuntimeException(is_array($j) && !empty($j['error']) ? (string)$j['error'] : 'El Cronos principal no respondió bien (código ' . $code . ').');
    return $j;
}

/** Columnas propias del catálogo en g7_links (se crean solas). */
function cat_schema(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    foreach (["html_file VARCHAR(80) NOT NULL DEFAULT ''", "cat_tool VARCHAR(60) NOT NULL DEFAULT ''", 'cat_ver INT NOT NULL DEFAULT 0', "cat_sha CHAR(64) NOT NULL DEFAULT ''"] as $col)
        try { $db->exec('ALTER TABLE g7_links ADD COLUMN IF NOT EXISTS ' . $col); } catch (Throwable $e) {}
}
function cat_html_dir(): string { return dirname(__DIR__) . '/_private/biblioteca/'; }
function cat_cache_dir(): string { return dirname(__DIR__) . '/_private/catalogo_cache/'; }
function cat_cover_ok(string $f): bool { return (bool)preg_match('/^[a-z0-9-]{1,60}_[a-f0-9]{8}\.(png|jpg|webp)$/', $f); }

/** Trae una herramienta del base y comprueba que el HTML llegó entero. Devuelve el paquete. */
function cat_fetch(string $id): array {
    $p = cat_call(['action' => 'get', 'id' => $id], 60);
    $t = (array)($p['tool'] ?? []); $html = (string)($p['html'] ?? '');
    if (($t['id'] ?? '') !== $id || $html === '' || !hash_equals((string)($t['sha256'] ?? ''), hash('sha256', $html))) throw new RuntimeException('La herramienta llegó incompleta. Prueba de nuevo.');
    if (strlen($html) > 2 * 1048576 || preg_match('/<\?(php|=)/i', $html)) throw new RuntimeException('La herramienta no pasó el control de seguridad.');
    return $p;
}
/** Guarda la portada que vino con el paquete en link_covers/. Devuelve la ruta pública o ''. */
function cat_store_cover(array $p): string {
    $c = $p['cover'] ?? null; $f = (string)($p['tool']['cover'] ?? '');
    if (!is_array($c) || !cat_cover_ok($f)) return '';
    $raw = base64_decode((string)($c['data'] ?? ''), true); $info = $raw ? @getimagesizefromstring($raw) : false;
    if (!$raw || !$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) return '';
    $dir = dirname(__DIR__) . '/link_covers/'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'cat_' . $f;
    if (!is_file($dir . $name)) file_put_contents($dir . $name, $raw);
    return '/toolz/link_covers/' . $name;
}
function cat_tags(array $t): string {
    $tags = ['HERRAMIENTA'];
    foreach ((array)($t['tags'] ?? []) as $g) { $g = mb_strtoupper(mb_substr(trim((string)$g), 0, 30)); if ($g !== '' && !in_array($g, $tags, true)) $tags[] = $g; }
    return json_encode(array_slice($tags, 0, 5), JSON_UNESCAPED_UNICODE);
}

/** Agrega una herramienta del catálogo a una categoría. Devuelve el id de la tarjeta nueva. */
function cat_add(PDO $db, string $id, int $catId, int $uid): int {
    cat_schema($db);
    $q = $db->prepare('SELECT COUNT(*) FROM g7_categories WHERE id = ?'); $q->execute([$catId]);
    if (!$q->fetchColumn()) throw new RuntimeException('Elegí una categoría de la Biblioteca.');
    $q = $db->prepare('SELECT id FROM g7_links WHERE cat_tool = ? LIMIT 1'); $q->execute([$id]);
    if ($q->fetchColumn()) throw new RuntimeException('Esa herramienta ya está en tu Biblioteca.');
    $p = cat_fetch($id); $t = $p['tool'];
    $dir = cat_html_dir(); if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('No se pudo crear la carpeta de la Biblioteca.');
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO g7_links (category_id,name,description,url,img,tags,created_by,cat_tool,cat_ver,cat_sha) VALUES (?,?,?,?,?,?,?,?,?,?)')
           ->execute([$catId, mb_substr((string)$t['name'], 0, 250), (string)$t['short'], '', cat_store_cover($p), cat_tags($t), $uid, $id, (int)$t['ver'], (string)$t['sha256']]);
        $lid = (int)$db->lastInsertId(); $file = $lid . '.html';
        if (file_put_contents($dir . $file, (string)$p['html']) === false) throw new RuntimeException('No se pudo guardar la herramienta.');
        $db->prepare('UPDATE g7_links SET html_file = ?, url = ? WHERE id = ?')->execute([$file, '/toolz/links/ver.php?id=' . $lid, $lid]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); if (isset($file)) @unlink($dir . $file); throw $e; }
    return $lid;
}

/** Pasa una tarjeta del catálogo a la última versión. Solo cambia el HTML (y la portada si sigue siendo la del catálogo): nombre, categoría y notas quedan. */
function cat_update(PDO $db, int $linkId): array {
    cat_schema($db);
    $q = $db->prepare('SELECT id, cat_tool, cat_ver, cat_sha, img, html_file FROM g7_links WHERE id = ?'); $q->execute([$linkId]); $l = $q->fetch(PDO::FETCH_ASSOC);
    if (!$l || $l['cat_tool'] === '') throw new RuntimeException('Esa tarjeta no vino del catálogo.');
    $p = cat_fetch((string)$l['cat_tool']); $t = $p['tool'];
    if ((string)$t['sha256'] === (string)$l['cat_sha'] && is_file(cat_html_dir() . $l['html_file'])) return ['ver' => (int)$t['ver'], 'changed' => false];
    $file = $l['html_file'] !== '' ? basename((string)$l['html_file']) : $linkId . '.html';
    $dir = cat_html_dir(); if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (file_put_contents($dir . $file . '.tmp', (string)$p['html']) === false || !rename($dir . $file . '.tmp', $dir . $file)) throw new RuntimeException('No se pudo guardar la versión nueva.');
    $img = (string)$l['img'];
    if ($img === '' || strpos($img, '/toolz/link_covers/cat_') === 0) $img = cat_store_cover($p) ?: $img;
    $db->prepare('UPDATE g7_links SET html_file = ?, url = ?, img = ?, cat_ver = ?, cat_sha = ? WHERE id = ?')->execute([$file, '/toolz/links/ver.php?id=' . $linkId, $img, (int)$t['ver'], (string)$t['sha256'], $linkId]);
    return ['ver' => (int)$t['ver'], 'changed' => true];
}

/**
 * Viste una herramienta del catálogo con el estilo de Cronos de quien la mira: g7-theme.css + assets/herramientas-kit.css,
 * la clase de su tema y tamaño de letra (theme-*, font-*) y su color de acento, igual que topbar.php.
 * Solo agrega estilo: la herramienta sigue aislada (CSP sandbox). Si trae data-cronos-theme="off" en <html>, se deja como está.
 */
function cat_theme_html(string $html, int $uid): string {
    if (preg_match('/<html\b[^>]*data-cronos-theme\s*=\s*["\']?off/i', $html)) return $html;
    $p = [];
    try {
        $q = getDB()->prepare("SELECT pref_key, pref_value FROM g7_user_prefs WHERE user_id = ? AND pref_key IN ('theme', 'accent_color', 'font_size')");
        $q->execute([$uid]); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $p[$r['pref_key']] = json_decode((string)$r['pref_value'], true);
    } catch (Throwable $e) {}
    $set = fn(string $k, string $d) => function_exists('g7_setting') ? ((string)g7_setting($k) ?: $d) : $d;
    $theme = (string)($p['theme'] ?? $set('default_theme', 'dark')); if (!preg_match('/^[a-z0-9-]{1,40}$/', $theme)) $theme = 'dark';
    $font = (string)($p['font_size'] ?? $set('default_font_size', 'medium')); if (!in_array($font, ['small', 'medium', 'large'], true)) $font = 'medium';
    $acc = (string)($p['accent_color'] ?? $set('accent_color', '#ff6600')); if (!preg_match('/^#[0-9a-fA-F]{6}$/', $acc)) $acc = '#ff6600';
    $rgb = hexdec(substr($acc, 1, 2)) . ',' . hexdec(substr($acc, 3, 2)) . ',' . hexdec(substr($acc, 5, 2));
    $root = dirname(__DIR__); $base = defined('G7_BASE') ? G7_BASE : '/toolz/';
    $head = '<link rel="stylesheet" href="' . $base . 'g7-theme.css?v=' . (int)@filemtime("$root/g7-theme.css") . '">'
        . '<link rel="stylesheet" href="' . $base . 'assets/herramientas-kit.css?v=' . (int)@filemtime("$root/assets/herramientas-kit.css") . '">'
        . "<style>html.tk-cronos.tk-cronos{--g7-accent:$acc;--g7-accent-rgb:$rgb;--g7-accent-muted:rgba($rgb,.12);--g7-accent-border:rgba($rgb,.28)}</style>";
    $cls = "theme-$theme font-$font tk-cronos";
    if (preg_match('/<html\b[^>]*>/i', $html, $m)) {
        $tag = preg_match('/\sclass\s*=\s*"([^"]*)"/i', $m[0]) ? preg_replace('/\sclass\s*=\s*"([^"]*)"/i', ' class="$1 ' . $cls . '"', $m[0], 1)
            : substr($m[0], 0, -1) . ' class="' . $cls . '">';
        $html = substr_replace($html, $tag, strpos($html, $m[0]), strlen($m[0]));
    } else $html = '<html class="' . $cls . '">' . $html;
    return preg_match('#</head>#i', $html) ? preg_replace('#</head>#i', $head . '</head>', $html, 1) : preg_replace('/(<html\b[^>]*>)/i', '$1<head>' . $head . '</head>', $html, 1);
}

/** Lista del catálogo con lo que ya hay en esta Biblioteca (have: id de la tarjeta, ver: la versión que tiene). */
function cat_list(PDO $db): array {
    cat_schema($db);
    $r = cat_call(['action' => 'list'], 30);
    $mine = [];
    foreach ($db->query("SELECT id, cat_tool, cat_ver FROM g7_links WHERE cat_tool <> ''")->fetchAll(PDO::FETCH_ASSOC) as $x) $mine[$x['cat_tool']] = ['link' => (int)$x['id'], 'ver' => (int)$x['cat_ver']];
    $tools = [];
    foreach ((array)($r['tools'] ?? []) as $t) {
        $m = $mine[$t['id']] ?? null;
        $tools[] = $t + ['have' => $m['link'] ?? 0, 'have_ver' => $m['ver'] ?? 0, 'outdated' => $m && (int)$t['ver'] > $m['ver']];
    }
    return ['tools' => $tools, 'types' => (array)($r['types'] ?? [])];
}
