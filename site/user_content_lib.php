<?php
/**
 * user_content_lib.php — Contenido de un usuario (activo, desactivado o eliminado): a quién figura y pasarlo a otra persona.
 * Lo usa admin_api.php (uc_*). Pantalla: Admin › Contenido (admin_contenido.js). CONTENIDO_USUARIOS.md
 *
 * Un "origen" es 'u:ID' (un usuario, activo o no, o un id que ya no existe) o 'a:nombre' (autor de Promos que ya no existe y no se sabe qué id tenía).
 * Solo cambia de quién es cada cosa: no mueve archivos ni toca el contenido.
 */

/** Qué se puede pasar: [nombre, ícono, tabla, columna del dueño, columna del título, privado]. Lo privado solo lo mueve el superadmin. */
const UC_KINDS = [
    'promos'        => ['Contenido de Promos', 'fa-layer-group', 'g7_content', 'created_by', 'name', 0],
    'stock'         => ['Archivos de Stock', 'fa-images', 'g7_stock_items', 'owner_id', 'title', 0],
    'stock_dirs'    => ['Carpetas de Stock', 'fa-folder', 'g7_stock_folders', 'owner_id', 'name', 0],
    'links'         => ['Links de la biblioteca', 'fa-link', 'g7_links', 'created_by', 'name', 0],
    'landings'      => ['Landings', 'fa-window-maximize', 'g7_lp_projects', 'created_by', 'name', 0],
    'landing_brand' => ['Marcas de landings', 'fa-swatchbook', 'g7_lp_brands', 'created_by', 'name', 0],
    'mails'         => ['Mailings', 'fa-envelope', 'g7_mm2_emails', 'owner_id', 'name', 0],
    'mail_blocks'   => ['Bloques de mailing', 'fa-cubes', 'g7_mm2_blocks', 'owner_id', 'name', 0],
    'mail_tpl'      => ['Plantillas de mailing', 'fa-clone', 'g7_mm2_templates', 'owner_id', 'name', 0],
    'mail_tpl_old'  => ['Plantillas de mailing (clásico)', 'fa-clone', 'g7_mailing_templates', 'created_by', 'name', 0],
    'popups'        => ['Popups', 'fa-window-restore', 'g7_popup_saves', 'owner_id', 'name', 0],
    'mockups'       => ['Mockups', 'fa-mobile-screen', 'g7_mk_projects', 'user_id', 'name', 0],
    'palettes'      => ['Paletas', 'fa-palette', 'g7_pal_palettes', 'user_id', 'name', 0],
    'placas'        => ['Plantillas de placas', 'fa-object-group', 'placas_plantillas', 'user_id', 'nombre', 0],
    'placas_fondos' => ['Fondos de placas', 'fa-image', 'placas_fondos', 'user_id', 'nombre', 0],
    'placas_ligas'  => ['Ligas de placas', 'fa-trophy', 'placas_ligas', 'user_id', 'nombre', 0],
    'social'        => ['Publicaciones de Social', 'fa-hashtag', 'g7_ss_posts', 'owner_id', 'title', 0],
    'blog'          => ['Entradas de blog', 'fa-newspaper', 'g7_bs_posts', 'author_id', 'title', 0],
    'client_files'  => ['Archivos de clientes', 'fa-address-card', 'g7_client_files', 'uploaded_by', 'name', 0],
    'task_files'    => ['Adjuntos de tareas', 'fa-paperclip', 'g7_task_files', 'uploaded_by', 'name', 0],
    'drive'         => ['Archivos del Drive personal', 'fa-hard-drive', 'g7_drive_files', 'owner_id', 'original_name', 1],
    'drive_dirs'    => ['Carpetas del Drive personal', 'fa-folder-open', 'g7_drive_folders', 'owner_id', 'name', 1],
];

/** Los tipos que existen en esta instalación (tabla y columnas). */
function uc_kinds(PDO $db, bool $super): array {
    static $cols = null;
    if ($cols === null) {
        $cols = [];
        foreach ($db->query('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_NUM) as [$t, $c]) $cols[strtolower($t)][$c] = true;
    }
    $out = [];
    foreach (UC_KINDS as $k => $d) {
        if ($d[5] && !$super) continue;
        if (isset($cols[$d[2]]['id'], $cols[$d[2]][$d[3]], $cols[$d[2]][$d[4]])) $out[$k] = $d;
    }
    return $out;
}
function uc_low(?string $s): string { return mb_strtolower(trim((string)$s)); }

/** Usuarios de hoy: [id => fila] y [nombre de usuario en minúsculas => id]. */
function uc_users(PDO $db): array {
    $by = []; $names = [];
    foreach ($db->query('SELECT id, username, display_name, active, role FROM g7_users')->fetchAll(PDO::FETCH_ASSOC) as $u) { $by[(int)$u['id']] = $u; $names[uc_low($u['username'])] = (int)$u['id']; }
    return [$by, $names];
}
/** Cómo se llamaban los eliminados, según la auditoría: [id => nombre de usuario]. */
function uc_gone_names(PDO $db): array {
    $out = [];
    try {
        foreach ($db->query("SELECT target, detail FROM g7_admin_audit WHERE action = 'user.delete' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = json_decode((string)$r['detail'], true);
            if (preg_match('/^user:(\d+)$/', $r['target'], $m) && !empty($d['username'])) $out[(int)$m[1]] = (string)$d['username'];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** De qué origen es cada contenido de Promos que no está a nombre de alguien activo: [id => origen]. */
function uc_promos_map(PDO $db, array $by, array $names, array $gone): array {
    $goneId = [];
    foreach ($gone as $id => $n) if (!isset($by[$id])) $goneId[uc_low($n)] = $id;
    $out = [];
    foreach ($db->query('SELECT id, author, created_by FROM g7_content')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $a = uc_low($r['author']); $c = (int)$r['created_by'];
        if (isset($names[$a])) { if (!$by[$names[$a]]['active']) $out[(int)$r['id']] = 'u:' . $names[$a]; continue; }   // el autor manda
        if (isset($goneId[$a])) $out[(int)$r['id']] = 'u:' . $goneId[$a];
        elseif ($a !== '') $out[(int)$r['id']] = 'a:' . $a;
        elseif ($c && isset($by[$c])) { if (!$by[$c]['active']) $out[(int)$r['id']] = 'u:' . $c; }
        else $out[(int)$r['id']] = $c ? 'u:' . $c : 'a:';
    }
    return $out;
}

/** Orígenes con contenido: [['key', 'name', 'state' => gone|off, 'kinds' => [tipo => cantidad], 'total']]. */
function uc_sources(PDO $db, bool $super): array {
    [$by, $names] = uc_users($db); $gone = uc_gone_names($db);
    $src = [];
    foreach (uc_kinds($db, $super) as $k => $d) {
        if ($k === 'promos') { foreach (array_count_values(uc_promos_map($db, $by, $names, $gone)) as $key => $n) $src[$key][$k] = $n; continue; }
        foreach ($db->query("SELECT `$d[3]` AS o, COUNT(*) AS n FROM `$d[2]` WHERE `$d[3]` IS NOT NULL AND `$d[3]` > 0 GROUP BY `$d[3]`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $o = (int)$r['o'];
            if (isset($by[$o]) && $by[$o]['active']) continue;
            $src["u:$o"][$k] = (int)$r['n'];
        }
    }
    $out = [];
    foreach ($src as $key => $kinds) {
        $key = (string)$key;
        $id = strncmp($key, 'u:', 2) === 0 ? (int)substr($key, 2) : 0;
        $live = $id && isset($by[$id]);
        $name = $live ? ($by[$id]['display_name'] ?: $by[$id]['username']) : ($id ? ($gone[$id] ?? "Usuario #$id") : (substr($key, 2) !== '' ? substr($key, 2) : 'Sin autor'));
        $out[] = ['key' => $key, 'name' => (string)$name, 'state' => $live ? 'off' : 'gone', 'kinds' => $kinds, 'total' => array_sum($kinds)];
    }
    usort($out, fn($a, $b) => [$a['state'] === 'off', -$a['total']] <=> [$b['state'] === 'off', -$b['total']]);
    return $out;
}
/** Todo lo que está a nombre de un usuario (al eliminarlo, o para pasarlo aunque siga activo): [tipo => cantidad]. */
function uc_of_user(PDO $db, int $id, bool $super): array {
    $out = [];
    foreach (uc_kinds($db, $super) as $k => $d) if ($n = count(uc_ids($db, $k, $d, "u:$id"))) $out[$k] = $n;
    return $out;
}

/** Ids de un tipo que son de un origen. */
function uc_ids(PDO $db, string $kind, array $d, string $src): array {
    $id = preg_match('/^u:(\d+)$/', $src, $m) ? (int)$m[1] : 0;
    if ($kind === 'promos') {
        [$by, $names] = uc_users($db);
        if ($id && isset($by[$id])) {
            $q = $db->prepare('SELECT id FROM g7_content WHERE author = ? OR (created_by = ? AND author = \'\')'); $q->execute([$by[$id]['username'], $id]);
            return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        }
        return array_map('intval', array_keys(uc_promos_map($db, $by, $names, uc_gone_names($db)), $src, true));
    }
    if (!$id) return [];
    $q = $db->prepare("SELECT id FROM `$d[2]` WHERE `$d[3]` = ? ORDER BY id DESC"); $q->execute([$id]);
    return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}

/** Elementos para elegir uno por uno (hasta 500, los últimos primero). */
function uc_items(PDO $db, string $kind, string $src, bool $super): array {
    $d = uc_kinds($db, $super)[$kind] ?? null;
    if (!$d) return [];
    $ids = uc_ids($db, $kind, $d, $src); rsort($ids);
    $ids = array_slice($ids, 0, 500);
    if (!$ids) return [];
    return array_map(fn($r) => ['id' => (int)$r['id'], 'title' => trim((string)$r['t']) !== '' ? (string)$r['t'] : '(sin título)'],
        $db->query("SELECT id, `$d[4]` AS t FROM `$d[2]` WHERE id IN (" . implode(',', $ids) . ') ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Pasa contenido de un origen a un usuario. $pick = [tipo => true (todo) | [ids]]. Devuelve [tipo => cantidad movida].
 * Los ids se vuelven a resolver acá: solo se mueve lo que de verdad es de ese origen.
 */
function uc_move(PDO $db, string $src, int $to, array $pick, bool $super, int $by = 0): array {
    $q = $db->prepare('SELECT username FROM g7_users WHERE id = ? AND active = 1'); $q->execute([$to]);
    $toName = $q->fetchColumn();
    if ($toName === false) throw new RuntimeException('Elegí un usuario activo');
    if ($src === "u:$to") throw new RuntimeException('Es el mismo usuario');
    $srcId = preg_match('/^u:(\d+)$/', $src, $m) ? (int)$m[1] : 0;
    uc_install($db);   // antes de la transacción: crear la tabla la cerraría
    $done = []; $was = [];
    $own = !$db->inTransaction(); if ($own) $db->beginTransaction();
    try {
        foreach (uc_kinds($db, $super) as $k => $d) {
            $p = $pick[$k] ?? null;
            if ($p !== true && !is_array($p)) continue;
            $ids = uc_ids($db, $k, $d, $src);
            if (is_array($p)) $ids = array_values(array_intersect($ids, array_map('intval', $p)));
            foreach (array_chunk($ids, 500) as $c) {
                $in = implode(',', $c);
                // Cómo estaba, para poder deshacerlo
                foreach ($db->query("SELECT id, `$d[3]` AS o" . ($k === 'promos' ? ', author AS a' : '') . " FROM `$d[2]` WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r)
                    $was[$k][] = $k === 'promos' ? [(int)$r['id'], $r['o'] === null ? null : (int)$r['o'], $r['a']] : [(int)$r['id'], (int)$r['o']];
                if ($k === 'promos') {
                    // Quien lo cargó cambia solo si es el mismo que se va o ya no existe
                    $db->prepare("UPDATE g7_content SET author = ?, created_by = IF(created_by IS NULL OR created_by = ? OR created_by NOT IN (SELECT id FROM g7_users), ?, created_by) WHERE id IN ($in)")->execute([$toName, $srcId, $to]);
                } else $db->prepare("UPDATE `$d[2]` SET `$d[3]` = ? WHERE id IN ($in)")->execute([$to]);
            }
            if ($ids) $done[$k] = count($ids);
        }
        if ($done) {
            $by = $by ?: null;
            $db->prepare('INSERT INTO g7_content_moves (by_user, src, src_name, to_user, moved, was, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())')
               ->execute([$by, $src, uc_src_name($db, $src), $to, json_encode($done), json_encode($was, JSON_UNESCAPED_UNICODE)]);
        }
        if ($own) $db->commit();
    } catch (Throwable $e) { if ($own) $db->rollBack(); throw $e; }
    return $done;
}

function uc_install(PDO $db): void {
    static $ok = false; if ($ok) return; $ok = true;
    if (g7_cfg('content_moves_schema') === '1') return;
    $db->exec("CREATE TABLE IF NOT EXISTS g7_content_moves (id INT AUTO_INCREMENT PRIMARY KEY, by_user INT NULL, src VARCHAR(120) NOT NULL, src_name VARCHAR(120) NOT NULL DEFAULT '', to_user INT NOT NULL,
        moved TEXT NOT NULL, was MEDIUMTEXT NOT NULL, created_at DATETIME NOT NULL, undone_at DATETIME NULL, undone_by INT NULL, KEY created_at (created_at)) DEFAULT CHARSET=utf8mb4");
    g7_cfg_set('content_moves_schema', '1');
}
function uc_src_name(PDO $db, string $src): string {
    if (!preg_match('/^u:(\d+)$/', $src, $m)) return substr($src, 2) ?: 'Sin autor';
    $q = $db->prepare("SELECT COALESCE(NULLIF(display_name, ''), username) FROM g7_users WHERE id = ?"); $q->execute([(int)$m[1]]);
    return (string)($q->fetchColumn() ?: (uc_gone_names($db)[(int)$m[1]] ?? 'Usuario #' . $m[1]));
}
/** Los últimos pases (30 días). Un admin común ve los suyos; el superadmin, todos. */
function uc_moves(PDO $db, int $me, bool $super): array {
    uc_install($db);
    $q = $db->prepare("SELECT m.id, m.src_name, m.moved, m.created_at, m.undone_at, COALESCE(NULLIF(t.display_name, ''), t.username, '(eliminado)') AS to_name, COALESCE(NULLIF(b.display_name, ''), b.username, '') AS by_name
        FROM g7_content_moves m LEFT JOIN g7_users t ON t.id = m.to_user LEFT JOIN g7_users b ON b.id = m.by_user
        WHERE m.created_at > NOW() - INTERVAL 30 DAY" . ($super ? '' : ' AND m.by_user = ' . $me) . ' ORDER BY m.id DESC LIMIT 10');
    $q->execute();
    return array_map(fn($r) => ['id' => (int)$r['id'], 'from' => $r['src_name'], 'to' => $r['to_name'], 'by' => $r['by_name'], 'at' => $r['created_at'], 'undone' => (bool)$r['undone_at'], 'total' => array_sum(json_decode($r['moved'], true) ?: [])], $q->fetchAll(PDO::FETCH_ASSOC));
}
/** Deshace un pase: cada cosa vuelve a como estaba, salvo lo que ya se borró o se pasó a otra persona. Devuelve [devueltos, no devueltos]. */
function uc_undo(PDO $db, int $id, int $me, bool $super): array {
    uc_install($db);
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT * FROM g7_content_moves WHERE id = ? FOR UPDATE'); $q->execute([$id]); $m = $q->fetch(PDO::FETCH_ASSOC);
        if (!$m || (!$super && (int)$m['by_user'] !== $me)) throw new RuntimeException('Ese pase no existe');
        if ($m['undone_at']) throw new RuntimeException('Ese pase ya se deshizo');
        if (strtotime($m['created_at']) < time() - 30 * 86400) throw new RuntimeException('Pasaron más de 30 días: ya no se puede deshacer');
        $to = (int)$m['to_user']; [$by] = uc_users($db); $toName = (string)($by[$to]['username'] ?? '');
        $back = $left = 0;
        foreach (json_decode($m['was'], true) ?: [] as $k => $rows) {
            $d = uc_kinds($db, true)[$k] ?? null;
            if (!$d || ($d[5] && !$super)) { $left += count($rows); continue; }
            // Estas dos tablas no admiten un dueño que ya no existe
            $fk = in_array($d[2], ['g7_content', 'g7_links'], true);
            foreach ($rows as $r) {
                if ($k === 'promos') {
                    $o = $r[1] !== null && isset($by[$r[1]]) ? $r[1] : null;
                    $u = $db->prepare('UPDATE g7_content SET author = ?, created_by = IF(created_by = ?, ?, created_by) WHERE id = ? AND author = ?'); $u->execute([$r[2], $to, $o, $r[0], $toName]);
                } else {
                    if ($fk && !isset($by[$r[1]])) { $left++; continue; }
                    $u = $db->prepare("UPDATE `$d[2]` SET `$d[3]` = ? WHERE id = ? AND `$d[3]` = ?"); $u->execute([$r[1], $r[0], $to]);
                }
                if ($u->rowCount()) $back++; else $left++;
            }
        }
        $db->prepare('UPDATE g7_content_moves SET undone_at = NOW(), undone_by = ? WHERE id = ?')->execute([$me, $id]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    return [$back, $left];
}
