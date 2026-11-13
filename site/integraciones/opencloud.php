<?php
/**
 * integraciones/opencloud.php — OpenCloud autoalojado (la nube de archivos de la agencia), con UNA cuenta para toda la agencia.
 * Habla con el servidor por WebDAV (listar: PROPFIND, crear carpetas: MKCOL) y, para los links públicos, con la API Graph
 * (createLink) o, si el servidor no la tiene, con la API OCS de compartidos. Usuario + token de aplicación (Basic).
 * Desde Toolz solo se ve la "Carpeta de Toolz" (root) y lo que tiene adentro: toda ruta se normaliza y no puede salir de ahí.
 * El token nunca va al navegador. Lo usan opencloud_api.php (pantalla) y api.php (Probar conexión). Doc: toolz/INTEGRACIONES.md
 */
require_once __DIR__ . '/lib.php';

const OC_MAX_ITEMS = 500;   // elementos como máximo por carpeta listada

/** https://algo (o http://localhost / 127.0.0.1 para probar en local). */
function oc_url_ok(string $u): bool {
    $p = parse_url($u);
    if (!$p || empty($p['host']) || !empty($p['user']) || !empty($p['query']) || !empty($p['fragment'])) return false;
    if (($p['scheme'] ?? '') === 'https') return true;
    return ($p['scheme'] ?? '') === 'http' && in_array($p['host'], ['localhost', '127.0.0.1'], true);
}
function oc_cfg(): array { return int_cfg('opencloud'); }
function oc_ready(): bool { return int_ready('opencloud') && oc_url_ok((string)oc_cfg()['url']); }
/** ¿Esta persona puede adjuntar desde OpenCloud? */
function oc_can_use(array $user): bool { return oc_ready() && (oc_cfg()['who'] !== 'admins' || ($user['role'] ?? '') === 'admin'); }

/**
 * Ruta limpia dentro de la carpeta de Toolz: "/", "/Clientes/Acme"… Sin "..", sin barras repetidas ni caracteres de control.
 * Lanza si intenta salir. $rel es relativa a la carpeta de Toolz.
 */
function oc_clean(string $rel): string {
    $parts = [];
    foreach (explode('/', str_replace('\\', '/', $rel)) as $p) {
        $p = trim($p);
        if ($p === '' || $p === '.') continue;
        if ($p === '..' || preg_match('/[\x00-\x1f]/', $p)) throw new RuntimeException('Esa carpeta no es válida.');
        $parts[] = $p;
    }
    return '/' . implode('/', $parts);
}
/** Carpeta de Toolz en la cuenta (ruta absoluta en la carpeta personal). */
function oc_root(): string { $r = oc_clean((string)(oc_cfg()['root'] ?: '/Toolz')); return $r; }
/** Ruta relativa a la carpeta de Toolz → URL WebDAV. */
function oc_dav_url(string $rel): string {
    $c = oc_cfg(); $r = oc_clean($rel); $abs = rtrim(oc_root(), '/') . ($r === '/' ? '' : $r);
    $enc = implode('/', array_map('rawurlencode', explode('/', $abs)));
    return rtrim((string)$c['url'], '/') . '/' . trim((string)($c['dav_path'] ?: '/remote.php/webdav'), '/') . ($enc === '' ? '/' : $enc);
}
/** Pedido al servidor con la cuenta de la agencia. */
function oc_req(string $method, string $url, array $headers = [], ?string $body = null): array {
    $c = oc_cfg();
    $h = array_merge(['Authorization: Basic ' . base64_encode($c['user'] . ':' . $c['token']), 'Accept: application/json, application/xml;q=0.9, */*;q=0.5'], $headers);
    $r = int_http($method, $url, $h, $body, 25, empty($c['insecure']));
    if ($r['error'] !== '') throw new RuntimeException('No se pudo conectar con OpenCloud. ' . $r['error']);
    if ($r['status'] === 401) throw new RuntimeException('OpenCloud no aceptó el usuario o el token de aplicación. Revisalos en Admin › Integraciones.');
    if ($r['status'] === 403) throw new RuntimeException('La cuenta de OpenCloud no tiene permiso para eso.');
    if ($r['status'] >= 500) throw new RuntimeException('El servidor de OpenCloud respondió con un error (' . $r['status'] . '). Probá en un rato.');
    return $r;
}

const OC_PROPFIND = '<?xml version="1.0" encoding="UTF-8"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop>'
    . '<d:displayname/><d:getlastmodified/><d:getcontentlength/><d:getcontenttype/><d:resourcetype/><oc:fileid/><oc:privatelink/><oc:size/>'
    . '</d:prop></d:propfind>';

/** Lee una respuesta 207 de PROPFIND: [['href', 'name', 'dir', 'size', 'mime', 'modified', 'fileid', 'privatelink'], …]. */
function oc_parse_multistatus(string $xml): array {
    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    libxml_use_internal_errors($prev);
    if ($doc === false) throw new RuntimeException('OpenCloud respondió algo que no se pudo leer.');   // ojo: un SimpleXMLElement con todo en un namespace da false con !
    $doc->registerXPathNamespace('d', 'DAV:'); $doc->registerXPathNamespace('oc', 'http://owncloud.org/ns');
    $out = [];
    foreach ($doc->xpath('//d:response') ?: [] as $resp) {
        $resp->registerXPathNamespace('d', 'DAV:'); $resp->registerXPathNamespace('oc', 'http://owncloud.org/ns');
        $one = fn(string $q) => ($x = $resp->xpath($q)) ? trim((string)$x[0]) : '';
        $href = $one('d:href');
        // Solo las propiedades que llegaron bien (200)
        $ok = $resp->xpath('d:propstat[contains(d:status, " 200 ")]/d:prop') ?: [];
        $p = $ok[0] ?? null; if ($p === null) continue;
        $p->registerXPathNamespace('d', 'DAV:'); $p->registerXPathNamespace('oc', 'http://owncloud.org/ns');
        $v = fn(string $q) => ($x = $p->xpath($q)) ? trim((string)$x[0]) : '';
        $dir = (bool)$p->xpath('d:resourcetype/d:collection');
        $name = rawurldecode(basename(rtrim($href, '/')));
        $out[] = ['href' => $href, 'name' => $name, 'dir' => $dir, 'size' => (int)($dir ? $v('oc:size') : $v('d:getcontentlength')),
            'mime' => $dir ? '' : $v('d:getcontenttype'), 'modified' => ($t = strtotime($v('d:getlastmodified'))) ? date('Y-m-d H:i', $t) : '',
            'fileid' => $v('oc:fileid'), 'privatelink' => $v('oc:privatelink')];
    }
    return $out;
}

/** Contenido de una carpeta (relativa a la de Toolz): ['path', 'self' => datos de la carpeta, 'items' => [...] carpetas primero]. */
function oc_list(string $rel): array {
    $rel = oc_clean($rel);
    $r = oc_req('PROPFIND', oc_dav_url($rel) . '/', ['Depth: 1', 'Content-Type: application/xml; charset=utf-8'], OC_PROPFIND);
    if ($r['status'] === 404) throw new RuntimeException($rel === '/' ? 'La carpeta de Toolz no existe en OpenCloud. Probá la conexión en Admin › Integraciones: la crea sola.' : 'Esa carpeta ya no existe en OpenCloud.');
    if ($r['status'] !== 207) throw new RuntimeException('OpenCloud no devolvió la carpeta (respuesta ' . $r['status'] . ').');
    $all = oc_parse_multistatus($r['body']);
    $self = array_shift($all) ?: ['name' => basename($rel), 'dir' => true];
    $items = array_map(fn($i) => ['path' => rtrim($rel, '/') . '/' . $i['name']] + array_diff_key($i, ['href' => 1]), array_slice($all, 0, OC_MAX_ITEMS));
    usort($items, fn($a, $b) => [!$a['dir'], mb_strtolower($a['name'])] <=> [!$b['dir'], mb_strtolower($b['name'])]);
    return ['path' => $rel, 'self' => array_diff_key($self, ['href' => 1]), 'items' => $items, 'more' => count($all) > OC_MAX_ITEMS];
}
/** Datos de un archivo o carpeta (Depth 0), o null si no existe. */
function oc_stat(string $rel): ?array {
    $rel = oc_clean($rel);
    $r = oc_req('PROPFIND', oc_dav_url($rel), ['Depth: 0', 'Content-Type: application/xml; charset=utf-8'], OC_PROPFIND);
    if ($r['status'] === 404) return null;
    if ($r['status'] !== 207) throw new RuntimeException('OpenCloud no devolvió los datos (respuesta ' . $r['status'] . ').');
    $x = oc_parse_multistatus($r['body'])[0] ?? null;
    return $x ? ['path' => $rel, 'name' => $rel === '/' ? basename(oc_root()) : basename($rel)] + array_diff_key($x, ['href' => 1, 'name' => 1]) : null;
}
/** Crea la carpeta (y las de arriba que falten). Devuelve sus datos. */
function oc_mkdir(string $rel): array {
    $rel = oc_clean($rel); $cur = '';
    // La carpeta de Toolz misma (puede tener varios niveles)
    $rootParts = array_values(array_filter(explode('/', oc_root()), 'strlen')); $acc = '';
    foreach ($rootParts as $p) {
        $acc .= '/' . $p;
        $c = oc_cfg(); $u = rtrim((string)$c['url'], '/') . '/' . trim((string)($c['dav_path'] ?: '/remote.php/webdav'), '/') . implode('/', array_map('rawurlencode', explode('/', $acc)));
        $r = oc_req('MKCOL', $u);
        if (!in_array($r['status'], [201, 405], true)) throw new RuntimeException('No se pudo crear la carpeta de Toolz en OpenCloud (respuesta ' . $r['status'] . ').');
    }
    foreach (array_filter(explode('/', $rel), 'strlen') as $p) {
        $cur .= '/' . $p;
        $r = oc_req('MKCOL', oc_dav_url($cur));
        if (!in_array($r['status'], [201, 405], true)) throw new RuntimeException('No se pudo crear la carpeta "' . $p . '" en OpenCloud (respuesta ' . $r['status'] . ').');
    }
    return oc_stat($rel) ?? ['path' => $rel, 'name' => basename($rel), 'dir' => true, 'fileid' => '', 'privatelink' => ''];
}

/** Link para abrir un archivo o carpeta. $public: link de solo lectura que abre cualquiera (si no, el link interno de OpenCloud). */
function oc_link(string $rel, bool $public): array {
    $st = oc_stat($rel);
    if (!$st) throw new RuntimeException('Ese archivo ya no existe en OpenCloud.');
    $base = rtrim((string)oc_cfg()['url'], '/');
    if (!$public) {
        $url = $st['privatelink'] ?: ($st['fileid'] !== '' ? $base . '/f/' . rawurlencode($st['fileid']) : '');
        if ($url === '') throw new RuntimeException('OpenCloud no dio un link interno para ese archivo.');
        return ['url' => oc_same_host($url) ? $url : $base . '/f/' . rawurlencode($st['fileid']), 'public' => false] + $st;
    }
    // Link público: API Graph (OpenCloud) y, si no está, la API OCS de compartidos
    if ($st['fileid'] !== '' && str_contains($st['fileid'], '!')) {
        $drive = explode('!', $st['fileid'])[0];
        $r = oc_req('POST', $base . '/graph/v1beta1/drives/' . rawurlencode($drive) . '/items/' . rawurlencode($st['fileid']) . '/createLink',
            ['Content-Type: application/json'], json_encode(['type' => 'view']));
        $u = (string)($r['json']['link']['webUrl'] ?? '');
        if (in_array($r['status'], [200, 201], true) && oc_same_host($u)) return ['url' => $u, 'public' => true] + $st;
    }
    $cr = oc_clean($rel); $abs = rtrim(oc_root(), '/') . ($cr === '/' ? '' : $cr); if ($abs === '') $abs = '/';
    $r = oc_req('POST', $base . '/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json', ['OCS-APIRequest: true', 'Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['path' => $abs, 'shareType' => 3, 'permissions' => 1]));
    $u = (string)($r['json']['ocs']['data']['url'] ?? '');
    if (in_array($r['status'], [200, 201], true) && oc_same_host($u)) return ['url' => $u, 'public' => true] + $st;
    throw new RuntimeException('OpenCloud no dejó crear el link público. Revisá que la cuenta pueda compartir con link, o usá el link interno.');
}
/** El link apunta al mismo servidor (no se pega en Toolz un link a cualquier lado). */
function oc_same_host(string $u): bool {
    $a = parse_url($u); $b = parse_url((string)oc_cfg()['url']);
    return $a && $b && in_array($a['scheme'] ?? '', ['https', 'http'], true) && strtolower($a['host'] ?? '') === strtolower($b['host'] ?? '');
}

/** Probar conexión (Admin): entra, crea la carpeta de Toolz y la de clientes si faltan, y cuenta lo que hay. */
function oc_test(): array {
    oc_mkdir('/' . trim((string)(oc_cfg()['clients_dir'] ?: 'Clientes'), '/'));
    $l = oc_list('/');
    return [true, 'Conectado. La carpeta ' . oc_root() . ' tiene ' . count($l['items']) . ' elementos.'];
}

// ── Carpeta de cada cliente ──────────────────────────────────
function oc_install(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS g7_oc_client_folders (
        client_id INT NOT NULL PRIMARY KEY,
        path VARCHAR(1000) NOT NULL,
        public_url VARCHAR(1000) NOT NULL DEFAULT '',
        updated_by INT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function oc_client_folder(PDO $db, int $cid): ?array {
    oc_install($db);
    $q = $db->prepare('SELECT * FROM g7_oc_client_folders WHERE client_id = ?'); $q->execute([$cid]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
function oc_client_set(PDO $db, int $cid, ?string $path, int $uid, string $publicUrl = ''): void {
    oc_install($db);
    if ($path === null) { $db->prepare('DELETE FROM g7_oc_client_folders WHERE client_id = ?')->execute([$cid]); return; }
    $db->prepare('INSERT INTO g7_oc_client_folders (client_id, path, public_url, updated_by, updated_at) VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE path = VALUES(path), public_url = VALUES(public_url), updated_by = VALUES(updated_by), updated_at = NOW()')->execute([$cid, oc_clean($path), $publicUrl, $uid]);
}
/** Nombre de carpeta a partir del nombre del cliente (sin / ni caracteres raros). */
function oc_folder_name(string $name): string {
    $n = trim(preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/u', ' ', $name)); $n = preg_replace('/\s+/u', ' ', $n);
    return mb_substr($n !== '' ? $n : 'Cliente', 0, 120);
}
