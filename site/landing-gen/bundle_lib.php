<?php
/**
 * Landings "subidas" (2026-10-30): una landing ya armada (index.html + imágenes, CSS, fuentes…) que se sube
 * como carpeta o ZIP desde Landing Gen. No pasa por la IA ni por el editor: se publica tal cual en
 * /landings/<slug>/ y queda en g7_landings con content_raw = {"type":"bundle", ...}.
 *
 * Subida en tandas para no chocar con max_file_uploads / post_max_size del hosting:
 *   bundle_put (varias veces, mismos token) → bundle_commit.
 * Cada carpeta publicada lleva .landing.json (plataforma, nombre, id): si una carpeta llega por FTP sin fila
 * en la base, lg_bundle_adopt() la registra al listar.
 */

const LG_BUNDLE_EXT = ['html','htm','css','js','mjs','json','txt','map',
    'jpg','jpeg','png','gif','webp','avif','svg','ico',
    'woff','woff2','ttf','otf','eot','mp4','webm','mp3','ogg','wav'];
const LG_BUNDLE_MAX_FILES = 300;
const LG_BUNDLE_MAX_BYTES = 80 * 1024 * 1024;

/** Carpeta pública del dominio (donde vive /landings/): la del paquete de marcas ('pub_root') si existe en este servidor; si no, la raíz web. */
function lg_pub_root(): string {
    $c = g7_pack('landing_gen', []);
    $prod = is_array($c) ? (string)($c['pub_root'] ?? '') : '';
    if ($prod !== '' && is_dir($prod)) return rtrim($prod, '/');
    return rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2)), '/');
}
function lg_pub_dir(): string { return lg_pub_root() . '/landings'; }
/** Origen público de las landings: el del paquete ('pub_origin', https://dominio) si el pedido llega por ese dominio (o no hay pedido); si no, el del pedido. */
function lg_pub_origin(): string {
    $c = g7_pack('landing_gen', []);
    $cfg = is_array($c) ? rtrim((string)($c['pub_origin'] ?? ''), '/') : '';
    $cfgHost = (string)parse_url($cfg, PHP_URL_HOST);
    $host = preg_replace('/[^a-z0-9.:\-\[\]]/i', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($cfgHost !== '' && ($host === '' || str_contains($host, $cfgHost))) return $cfg;
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($host !== '' ? $host : 'localhost');
}
function lg_pub_base(): string { return lg_pub_origin() . '/landings/'; }
function lg_staging_dir(): string { return dirname(__DIR__) . '/_private/lg_staging'; }

function lg_slugify(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
    return trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}

/** Ruta relativa segura (sin .., sin absolutas, extensión permitida) o null. */
function lg_safe_rel(string $p): ?string {
    $p = str_replace('\\', '/', $p);
    if ($p === '' || str_contains($p, "\0") || $p[0] === '/' || preg_match('#^[a-z]:#i', $p)) return null;
    $parts = [];
    foreach (explode('/', $p) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..' || $seg[0] === '.') return null;   // nada de subir carpetas ni archivos ocultos
        if (!preg_match('/^[\w\-. ()@+,]+$/u', $seg)) return null;
        $parts[] = $seg;
    }
    if (!$parts) return null;
    $ext = strtolower(pathinfo(end($parts), PATHINFO_EXTENSION));
    return in_array($ext, LG_BUNDLE_EXT, true) ? implode('/', $parts) : null;
}

function lg_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = "$dir/$f";
        is_dir($p) && !is_link($p) ? lg_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

/** Todos los archivos de una carpeta, como rutas relativas. */
function lg_list_files(string $dir, string $pre = ''): array {
    $out = [];
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..' || $f[0] === '.') continue;
        $p = "$dir/$f";
        if (is_dir($p)) array_push($out, ...lg_list_files($p, "$pre$f/"));
        else $out[] = $pre . $f;
    }
    return $out;
}

/**
 * Botones de llamada a la acción (enlaces con clase cta / btn… que van a una URL http(s)) en ventana nueva:
 * target="_blank" y rel="noopener noreferrer". Los enlaces a anclas (#…) no se tocan. Un target que el
 * enlace ya traiga (p. ej. _top de una plantilla) se respeta, salvo con $force. $n devuelve cuántos cambió.
 */
function lg_cta_new_tab(string $html, bool $force = false, ?int &$n = null): string {
    $n = 0;
    return preg_replace_callback('#<a\b[^>]*>#i', function ($m) use ($force, &$n) {
        $tag = $m[0];
        if (!preg_match('#\sclass\s*=\s*("[^"]*"|\'[^\']*\')#i', $tag, $c) || !preg_match('#(^|[\s"\'])(cta|btn)([\s"\'_-]|$)#i', $c[1])) return $tag;
        if (!preg_match('#\shref\s*=\s*["\']?\s*https?://#i', $tag)) return $tag;
        $orig = $tag;
        if (preg_match('#\starget\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', $tag, $t)) {
            if (strcasecmp(trim($t[1], '"\''), '_blank') !== 0) {
                if (!$force) return $tag;
                $tag = str_replace($t[0], ' target="_blank"', $tag);
            }
        } else {
            $tag = preg_replace('#\s*(/?)>$#', ' target="_blank"$1>', $tag, 1);
        }
        if (preg_match('#\srel\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', $tag, $r)) {
            $vals = preg_split('/\s+/', strtolower(trim($r[1], '"\'')), -1, PREG_SPLIT_NO_EMPTY);
            $vals = array_values(array_unique(array_merge($vals, ['noopener', 'noreferrer'])));
            $tag = str_replace($r[0], ' rel="' . implode(' ', $vals) . '"', $tag);
        } else {
            $tag = preg_replace('#\s*(/?)>$#', ' rel="noopener noreferrer"$1>', $tag, 1);
        }
        if ($tag !== $orig) $n++;
        return $tag;
    }, $html) ?? $html;
}

function lg_beacon(int $id): string {
    // Fuera de producción cuenta en el mismo servidor (si no, una prueba local sumaría visitas a la landing real con ese id)
    $t = str_replace('/landings/', '/toolz/landing-gen/track.php', lg_pub_base());
    return '<script>(function(){var _i='.$id.';var _r=document.referrer||"";try{var fd=new FormData();fd.append("id",_i);fd.append("ref",_r);if(navigator.sendBeacon){navigator.sendBeacon("'.$t.'",fd);}else{fetch("'.$t.'",{method:"POST",body:fd,keepalive:true}).catch(function(){});}}catch(e){}})();</script>';
}

/** Slug libre para la carpeta: plataforma-nombre; si otra landing ya lo usa, se le suma el id. */
function lg_bundle_slug(PDO $db, string $platform, string $name, int $id): string {
    $base = lg_slugify($name) ?: 'landing';
    $pre = lg_slugify($platform);
    if ($pre && !str_starts_with($base, $pre)) $base = "$pre-$base";
    $dir = lg_pub_dir() . '/' . $base;
    if (!file_exists($dir)) return $base;
    $m = @json_decode((string)@file_get_contents("$dir/.landing.json"), true);
    if (is_array($m) && (int)($m['id'] ?? 0) === $id) return $base;
    return "$base-$id";
}

/**
 * Publica la carpeta preparada $src (raíz = donde está $entry) para la landing $id:
 * le pone el seguimiento de visitas, la mueve a /landings/<slug>/ (reemplazando la anterior) y actualiza la fila.
 */
function lg_bundle_publish(PDO $db, int $id, string $platform, string $name, string $src, string $entry, string $oldSlug = ''): array {
    $pub = lg_pub_dir();
    if (!is_dir($pub) && !@mkdir($pub, 0755, true)) throw new RuntimeException('No se pudo crear la carpeta de landings');
    $slug = $oldSlug !== '' ? $oldSlug : lg_bundle_slug($db, $platform, $name, $id);

    // Contador de visitas: se saca el que tuviera (puede ser de otro id, p. ej. al registrar una carpeta por FTP) y se pone el de esta landing
    $html = (string)file_get_contents("$src/$entry");
    $html = preg_replace('#<script>\(function\(\)\{var _i=\d+;.*?landing-gen/track\.php.*?\}\)\(\);</script>#s', '', $html);
    $b = lg_beacon($id);
    $html = stripos($html, '</body>') !== false ? preg_replace('#</body>#i', $b . '</body>', $html, 1) : $html . $b;
    $html = lg_cta_new_tab($html);   // el botón de la promo abre en una pestaña nueva
    file_put_contents("$src/$entry", $html);
    $files = lg_list_files($src);
    $size = 0; foreach ($files as $f) $size += filesize("$src/$f");
    file_put_contents("$src/.landing.json", json_encode(['id' => $id, 'platform' => $platform, 'name' => $name, 'entry' => $entry], JSON_UNESCAPED_UNICODE));
    // Que nada de la carpeta se ejecute en el servidor aunque se cuele un archivo raro
    file_put_contents("$src/.htaccess", "Options -Indexes\n<FilesMatch \"\\.(php[0-9]?|phtml|phar|pht|phps|cgi|pl|py|sh)$\">\nRequire all denied\n</FilesMatch>\n");

    // Cambio de versión casi atómico: la vieja se aparta, la nueva entra, la vieja se borra
    $dst = "$pub/$slug";
    $old = '';
    if (is_dir($dst)) { $old = "$pub/.old-$slug-" . bin2hex(random_bytes(3)); if (!@rename($dst, $old)) throw new RuntimeException('No se pudo reemplazar la versión publicada'); }
    if (!@rename($src, $dst)) {
        if ($old) @rename($old, $dst);
        throw new RuntimeException('No se pudo publicar la carpeta');
    }
    if ($old) lg_rrmdir($old);

    // Imagen para la tarjeta: la primera imagen grande que use la página (mejor si se llama hero y no es la de celular)
    $hero = '';
    $imgs = array_values(array_filter($files, fn($f) => preg_match('/\.(jpe?g|png|webp|avif)$/i', $f) && str_contains($html, basename($f))));
    usort($imgs, fn($a, $b) => (lg_hero_score($b, "$dst/$b") <=> lg_hero_score($a, "$dst/$a")));
    if ($imgs) $hero = lg_pub_base() . $slug . '/' . str_replace('%2F', '/', rawurlencode($imgs[0]));

    $url = lg_pub_base() . $slug . '/' . ($entry === 'index.html' ? '' : str_replace('%2F', '/', rawurlencode($entry)));
    $raw = json_encode(['type' => 'bundle', 'slug' => $slug, 'entry' => $entry, 'files' => count($files), 'bytes' => $size, 'heroImg' => $hero], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $db->prepare("UPDATE g7_landings SET name=?, content_raw=?, html_content=?, public_url=?, published_at=COALESCE(published_at, NOW()), updated_at=NOW() WHERE id=?")
       ->execute([$name, $raw, $html, $url, $id]);
    return ['url' => $url, 'slug' => $slug, 'files' => count($files), 'bytes' => $size];
}
function lg_hero_score(string $rel, string $path): int {
    $s = (int)(@filesize($path) / 1024);
    if (stripos($rel, 'hero') !== false) $s += 100000;
    if (preg_match('/mobile|movil|celu|logo|icon/i', $rel)) $s -= 200000;
    return $s;
}

/** Datos de la landing subida (o null si es una landing común). */
function lg_bundle_info(?array $row): ?array {
    if (!$row) return null;
    $m = json_decode((string)($row['content_raw'] ?? ''), true);
    return is_array($m) && ($m['type'] ?? '') === 'bundle' ? $m : null;
}

/** Carpetas en /landings/ con .landing.json de esta plataforma que no tienen fila (subidas por FTP): se registran. */
function lg_bundle_adopt(PDO $db, string $platform, string $user): void {
    // Bandeja: el FTP de Toolz solo llega a /toolz, así que una landing dejada en _private/lg_inbox/<carpeta>/
    // (con su .landing.json) se publica desde acá en /landings/
    foreach (glob(dirname(__DIR__) . '/_private/lg_inbox/*/.landing.json') ?: [] as $mf) {
        $m = json_decode((string)file_get_contents($mf), true);
        if (!is_array($m) || ($m['platform'] ?? '') !== $platform) continue;
        $dir = dirname($mf);
        $entry = lg_safe_rel((string)($m['entry'] ?? 'index.html')) ?? 'index.html';
        $name = trim((string)($m['name'] ?? '')) ?: basename($dir);
        if (!is_file("$dir/$entry")) continue;
        $db->prepare("INSERT INTO g7_landings (platform,name,content_raw,html_content,created_by) VALUES(?,?,?,?,?)")
           ->execute([$platform, $name, '{"type":"bundle"}', '', (string)($m['by'] ?? $user)]);
        $nid = (int)$db->lastInsertId();
        @unlink($mf);
        try { lg_bundle_publish($db, $nid, $platform, $name, $dir, $entry); }
        catch (Throwable $e) { $db->prepare('DELETE FROM g7_landings WHERE id=?')->execute([$nid]); file_put_contents($mf, json_encode($m)); }
    }
    $pub = lg_pub_dir();
    foreach (glob("$pub/*/.landing.json") ?: [] as $mf) {
        $m = json_decode((string)file_get_contents($mf), true);
        if (!is_array($m) || ($m['platform'] ?? '') !== $platform) continue;
        $id = (int)($m['id'] ?? 0);
        if ($id) { $q = $db->prepare('SELECT COUNT(*) FROM g7_landings WHERE id=?'); $q->execute([$id]); if ($q->fetchColumn()) continue; }
        $dir = dirname($mf); $slug = basename($dir);
        $entry = lg_safe_rel((string)($m['entry'] ?? 'index.html')) ?? 'index.html';
        if (!is_file("$dir/$entry")) continue;
        $name = trim((string)($m['name'] ?? '')) ?: $slug;
        $db->prepare("INSERT INTO g7_landings (platform,name,content_raw,html_content,created_by) VALUES(?,?,?,?,?)")
           ->execute([$platform, $name, '{}', '', (string)($m['by'] ?? $user)]);
        $nid = (int)$db->lastInsertId();
        // Se republica en el lugar (pasa por una copia temporal para reusar lg_bundle_publish)
        $tmp = lg_pub_dir() . '/.adopt-' . bin2hex(random_bytes(4));
        @mkdir($tmp, 0755, true);
        foreach (lg_list_files($dir) as $f) { @mkdir(dirname("$tmp/$f"), 0755, true); copy("$dir/$f", "$tmp/$f"); }
        try { lg_bundle_publish($db, $nid, $platform, $name, $tmp, $entry, $slug); }
        catch (Throwable $e) { lg_rrmdir($tmp); $db->prepare('DELETE FROM g7_landings WHERE id=?')->execute([$nid]); }
    }
}

/** Limpia tandas abandonadas de más de un día. */
function lg_staging_gc(): void {
    foreach (glob(lg_staging_dir() . '/*', GLOB_ONLYDIR) ?: [] as $d) if (filemtime($d) < time() - 86400) lg_rrmdir($d);
}

/** Atiende las acciones bundle_*; devuelve true si la acción era suya. */
function lg_bundle_handle(PDO $db, array $me, bool $isAdmin, string $action, array $data): bool {
    $uid = (int)$me['id'];
    $out = fn(array $r) => print(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    if ($action === 'bundle_limits') {
        $b = function (string $k) { $v = trim((string)ini_get($k)); $n = (float)$v; $u = strtolower(substr($v, -1));
            return (int)($n * ($u === 'g' ? 1073741824 : ($u === 'm' ? 1048576 : ($u === 'k' ? 1024 : 1)))); };
        $out(['ok' => true, 'post' => $b('post_max_size'), 'file' => $b('upload_max_filesize'),
              'count' => (int)ini_get('max_file_uploads') ?: 20, 'maxFiles' => LG_BUNDLE_MAX_FILES, 'maxBytes' => LG_BUNDLE_MAX_BYTES, 'ext' => LG_BUNDLE_EXT]);
        return true;
    }

    if ($action === 'bundle_put') {
        // Una tanda de archivos (multipart: token, paths[], files[])
        $token = (string)($_POST['token'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) { $out(['error' => 'Subida inválida']); return true; }
        $dir = lg_staging_dir() . '/' . $token;
        if (!is_dir($dir)) {
            lg_staging_gc();
            if (!@mkdir($dir, 0755, true)) { $out(['error' => 'No se pudo preparar la subida']); return true; }
            file_put_contents("$dir/.owner", (string)$uid);
        } elseif ((int)@file_get_contents("$dir/.owner") !== $uid) { $out(['error' => 'Subida inválida']); return true; }
        touch($dir);
        $paths = (array)($_POST['paths'] ?? []);
        $f = $_FILES['files'] ?? null;
        if (!$f || !is_array($f['name'])) { $out(['error' => 'No llegaron archivos. Puede que pesen más de lo que acepta el servidor.']); return true; }
        $n = count(lg_list_files($dir)); $bytes = 0;
        foreach (lg_list_files($dir) as $x) $bytes += filesize("$dir/$x");
        foreach ($f['name'] as $i => $orig) {
            $rel = lg_safe_rel((string)($paths[$i] ?? $orig));
            if ($rel === null) { $out(['error' => 'Archivo no permitido: ' . $orig]); return true; }
            if (($f['error'][$i] ?? 1) !== UPLOAD_ERR_OK) { $out(['error' => 'No se pudo recibir ' . $rel . ' (código ' . (int)$f['error'][$i] . ')']); return true; }
            $n++; $bytes += (int)$f['size'][$i];
            if ($n > LG_BUNDLE_MAX_FILES || $bytes > LG_BUNDLE_MAX_BYTES) { $out(['error' => 'La landing supera el máximo de ' . LG_BUNDLE_MAX_FILES . ' archivos u ' . (LG_BUNDLE_MAX_BYTES >> 20) . ' MB']); return true; }
            @mkdir(dirname("$dir/$rel"), 0755, true);
            if (!move_uploaded_file($f['tmp_name'][$i], "$dir/$rel")) { $out(['error' => 'No se pudo guardar ' . $rel]); return true; }
        }
        $out(['ok' => true, 'received' => count($f['name'])]);
        return true;
    }

    if ($action === 'bundle_commit') {
        $token = (string)($data['token'] ?? '');
        $name = trim((string)($data['name'] ?? ''));
        $platform = (string)($data['platform'] ?? '');
        $id = (int)($data['id'] ?? 0);
        $entry = lg_safe_rel((string)($data['entry'] ?? 'index.html'));
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) { $out(['error' => 'Subida inválida']); return true; }
        $dir = lg_staging_dir() . '/' . $token;
        if (!is_dir($dir) || (int)@file_get_contents("$dir/.owner") !== $uid) { $out(['error' => 'La subida venció. Prueba de nuevo.']); return true; }
        if ($name === '') { $out(['error' => 'Pon un nombre']); return true; }
        if (!$entry || !preg_match('/\.html?$/i', $entry) || !is_file("$dir/$entry")) { $out(['error' => 'Falta la página principal (index.html)']); return true; }
        @unlink("$dir/.owner");
        // La raíz publicada es la carpeta de la página principal; lo que quede afuera no se publica
        $root = dirname($entry) === '.' ? '' : dirname($entry) . '/';
        $src = $dir;
        if ($root !== '') {
            $src = lg_staging_dir() . '/' . $token . '-root';
            if (!@rename("$dir/" . rtrim($root, '/'), $src)) { $out(['error' => 'No se pudo preparar la carpeta']); return true; }
            lg_rrmdir($dir);
        }
        $entry = basename($entry);
        $oldSlug = '';
        try {
            if ($id) {
                $q = $db->prepare('SELECT * FROM g7_landings WHERE id=?'); $q->execute([$id]);
                $row = $q->fetch(PDO::FETCH_ASSOC);
                $info = lg_bundle_info($row ?: null);
                if (!$row || !$info) { lg_rrmdir($src); $out(['error' => 'Esa landing no es una landing subida']); return true; }
                $platform = (string)$row['platform'];
                $oldSlug = (string)$info['slug'];
            } else {
                if ($platform === '') { lg_rrmdir($src); $out(['error' => 'Falta la plataforma']); return true; }
                $db->prepare("INSERT INTO g7_landings (platform,name,content_raw,html_content,created_by) VALUES(?,?,?,?,?)")
                   ->execute([$platform, $name, '{"type":"bundle"}', '', $me['username']]);
                $id = (int)$db->lastInsertId();
            }
            $r = lg_bundle_publish($db, $id, $platform, $name, $src, $entry, $oldSlug);
        } catch (Throwable $e) {
            lg_rrmdir($src);
            if (!$oldSlug && $id) $db->prepare('DELETE FROM g7_landings WHERE id=?')->execute([$id]);
            $out(['error' => $e->getMessage()]);
            return true;
        }
        try { g7_audit($oldSlug ? 'landing.bundle_replace' : 'landing.bundle_upload', 'landing:' . $id, ['slug' => $r['slug'], 'files' => $r['files'], 'bytes' => $r['bytes']], $uid); } catch (Throwable $e) {}
        $out(['ok' => true, 'id' => $id] + $r);
        return true;
    }

    if ($action === 'bundle_abort') {
        $token = (string)($data['token'] ?? '');
        $dir = lg_staging_dir() . '/' . $token;
        if (preg_match('/^[a-f0-9]{32}$/', $token) && is_dir($dir) && (int)@file_get_contents("$dir/.owner") === $uid) lg_rrmdir($dir);
        $out(['ok' => true]);
        return true;
    }
    return false;
}
