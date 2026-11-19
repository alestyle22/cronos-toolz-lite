<?php
/**
 * sesiones_api.php — Mi perfil › Dónde iniciaste sesión (perfil_sesiones.php). JSON por POST con cabecera X-Sess: 1.
 * Acciones de cada persona (solo ve y toca lo suyo):
 *   alive                  ¿sigue abierta esta sesión? (topbar.js lo usa para avisar al momento si la cerraron)
 *   list {offset?}         sesiones abiertas, historial (de a 40), intentos fallidos de las últimas 48 h y dispositivos de confianza
 *   end {id}               cierra una sesión de otro dispositivo (rige en su próximo pedido)
 *   end_others             cierra todas menos la de este navegador
 *   confirm {id, me}       "Fui yo" (me = true) o "No fui yo" (me = false: cierra esa sesión y quita los dispositivos de confianza)
 *   rename {id, name}      nombre propio para el dispositivo de ese ingreso ("PC de la oficina"); vacío lo borra
 *   trust_remove {id?}     quita un dispositivo de confianza (o todos, sin id)
 *   lock_me                bloqueo de emergencia: cierra todo (también esta sesión) y manda el email para desbloquear
 * Solo superadmin:
 *   team {user_id?, cc?, alerts?, offset?}   ingresos de todo el equipo, con filtros
 *   team_end {id}          cierra una sesión de otra persona
 *   team_end_user {user_id}  cierra todas sus sesiones y le quita los dispositivos de confianza
 *   team_unlock {user_id}  libera una cuenta bloqueada
 *   block_add {kind: ip|country, value, hours (0 = siempre), note}   bloquea la entrada desde esa IP o ese país
 *   block_remove {id}
 * El pedido de team trae también: cuentas bloqueadas, intentos fallidos de todo el equipo (48 h) y los bloqueos vigentes.
 * Doc: toolz/SEGURIDAD_LOGIN.md
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/login_alert_lib.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$out = function (array $d, int $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
$me = getCurrentUser();
if (!$me) $out(['error' => 'Tu sesión venció: vuelve a entrar.', 'gone' => (string)($GLOBALS['g7_lh_bye'] ?? '')], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_SESS'] ?? '') !== '1') $out(['error' => 'Pedido inválido.'], 400);
$db = getDB(); g7_ld_install($db);
$uid = (int)$me['id']; $mySid = (string)($_SESSION['g7_login_sid'] ?? '');
$in = json_decode((string)file_get_contents('php://input'), true) ?: []; $act = (string)($in['action'] ?? '');
session_write_close();   // de acá en adelante no se toca la sesión: no traba otros pedidos
if ($act === 'alive') $out(['ok' => true]);

$isSuper = function_exists('g7_super_ok') && g7_super_ok($uid) && ($me['role'] ?? '') === 'admin';
// Una sesión sin actividad más allá de la duración configurada ya venció (PHP la borra sola)
$lifeH = (int)(function_exists('g7_setting') ? g7_setting('session_hours') : 0);
$life = ($lifeH >= 1 && $lifeH <= 720) ? $lifeH * 3600 : SESSION_LIFETIME;

$row = function (array $r) use ($mySid, $life): array {
    $cur = hash_equals((string)$r['sid'], $mySid);
    $status = $r['ended_at'] !== null ? ((string)$r['ended_by'] === 'logout' ? 'closed' : 'remote')
        : ($cur ? 'current' : (time() - (int)$r['ls'] > $life ? 'expired' : 'active'));
    $l = (string)$r['label'];
    $o = ['id' => (int)$r['id'], 'label' => $l, 'name' => (string)($r['dname'] ?? ''),
        'kind' => preg_match('/iPhone|Android/', $l) ? 'mobile' : (preg_match('/iPad/', $l) ? 'tablet' : 'desktop'),
        'place' => (string)$r['place'], 'cc' => (string)$r['country_code'], 'ip' => (string)$r['ip'], 'last_ip' => (string)$r['last_ip'],
        'lat' => $r['lat'] !== null ? (float)$r['lat'] : null, 'lon' => $r['lon'] !== null ? (float)$r['lon'] : null,
        'method' => (string)$r['method'], 'new_device' => (bool)$r['new_device'], 'alert' => (string)$r['alert'] ?: ((bool)$r['new_device'] ? 'device' : ''),
        'confirmed' => (string)$r['confirmed'], 'created' => (int)$r['ca'], 'last_seen' => (int)$r['ls'],
        'ended' => $r['ended_at'] !== null ? (int)$r['ea'] : null, 'ended_by' => (string)$r['ended_by'], 'status' => $status];
    if (isset($r['uname'])) { $o['user_id'] = (int)$r['user_id']; $o['user'] = (string)$r['uname']; }
    return $o;
};
$cols = 'h.id, h.user_id, h.sid, h.label, h.place, h.country_code, h.ip, h.last_ip, h.lat, h.lon, h.method, h.new_device, h.alert, h.confirmed, h.ended_at, h.ended_by,
    UNIX_TIMESTAMP(h.created_at) ca, UNIX_TIMESTAMP(h.last_seen) ls, UNIX_TIMESTAMP(h.ended_at) ea, d.name dname';
$join = 'g7_login_history h LEFT JOIN g7_login_devices d ON d.user_id = h.user_id AND d.fp = h.fp';
$mine = function (int $id) use ($db, $uid, $cols, $join) {
    $q = $db->prepare("SELECT $cols FROM $join WHERE h.id = ? AND h.user_id = ?"); $q->execute([$id, $uid]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
};
$list = function (int $offset = 0) use ($db, $uid, $me, $cols, $join, $row, $life, $isSuper) {
    $q = $db->prepare("SELECT $cols FROM $join WHERE h.user_id = ? AND h.ended_at IS NULL AND h.last_seen > NOW() - INTERVAL $life SECOND ORDER BY h.last_seen DESC LIMIT 50");
    $q->execute([$uid]); $active = array_map($row, $q->fetchAll(PDO::FETCH_ASSOC));
    usort($active, fn($a, $b) => ($b['status'] === 'current') <=> ($a['status'] === 'current') ?: $b['last_seen'] <=> $a['last_seen']);
    $q = $db->prepare("SELECT $cols FROM $join WHERE h.user_id = ? AND h.method <> 'session' ORDER BY h.created_at DESC, h.id DESC LIMIT 41 OFFSET " . max(0, $offset));
    $q->execute([$uid]); $hist = $q->fetchAll(PDO::FETCH_ASSOC);
    // Intentos fallidos de las últimas 48 h (g7_login_failures guarda 2 días): con el usuario, el email o el código de 2 pasos
    $u = $db->prepare('SELECT username, email FROM g7_users WHERE id = ?'); $u->execute([$uid]); $u = $u->fetch(PDO::FETCH_ASSOC) ?: [];
    $keys = array_values(array_unique(array_filter([mb_strtolower((string)($u['username'] ?? '')), mb_strtolower((string)($u['email'] ?? '')), "2fa:$uid"])));
    $keys = array_merge($keys, array_map(fn($k) => "ok:$k", $keys));   // los de antes de entrar bien quedan con "ok:" adelante (auth.php)
    $failed = [];
    try {
        $in = implode(',', array_fill(0, count($keys), '?'));
        $q = $db->prepare("SELECT ip, SUM(username LIKE '2fa:%' OR username LIKE 'ok:2fa:%') codes, COUNT(*) n, UNIX_TIMESTAMP(MAX(created_at)) last
            FROM g7_login_failures WHERE username IN ($in) AND created_at > NOW() - INTERVAL 2 DAY GROUP BY ip ORDER BY last DESC LIMIT 20");
        $q->execute($keys); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $geo = [];   // el lugar, de lo ya guardado (sin JOIN: las dos tablas pueden tener distinta intercalación)
        if ($rows) {
            $g = $db->prepare('SELECT ip, place, country_code FROM g7_ip_geo WHERE ip IN (' . implode(',', array_fill(0, count($rows), '?')) . ')');
            $g->execute(array_column($rows, 'ip'));
            foreach ($g->fetchAll(PDO::FETCH_ASSOC) as $x) $geo[$x['ip']] = $x;
        }
        foreach ($rows as $f) $failed[] = ['ip' => (string)$f['ip'], 'n' => (int)$f['n'], 'codes' => (int)$f['codes'], 'last' => (int)$f['last'],
            'place' => (string)($geo[$f['ip']]['place'] ?? ''), 'cc' => (string)($geo[$f['ip']]['country_code'] ?? '')];
    } catch (Throwable $e) {}
    $q = $db->prepare('SELECT id, label, ip, place, UNIX_TIMESTAMP(created_at) created, UNIX_TIMESTAMP(last_used) used, UNIX_TIMESTAMP(expires_at) expires, fp FROM g7_login_trusted WHERE user_id = ? AND expires_at > NOW() ORDER BY last_used DESC');
    $q->execute([$uid]); $myFp = g7_ld_fp(g7_ld_ua()); $myTok = hash('sha256', (string)($_COOKIE[g7_td_cookie()] ?? ''));
    $tq = $db->prepare('SELECT 1 FROM g7_login_trusted WHERE token_hash = ? AND user_id = ?'); $tq->execute([$myTok, $uid]); $thisTrusted = (bool)$tq->fetchColumn();
    $trusted = array_map(fn($t) => ['id' => (int)$t['id'], 'label' => (string)$t['label'], 'ip' => (string)$t['ip'], 'place' => (string)$t['place'],
        'created' => (int)$t['created'], 'used' => (int)$t['used'], 'expires' => (int)$t['expires'], 'here' => $thisTrusted && hash_equals((string)$t['fp'], $myFp)], $q->fetchAll(PDO::FETCH_ASSOC));
    return ['active' => $active, 'history' => array_map($row, array_slice($hist, 0, 40)), 'more' => count($hist) > 40, 'offset' => $offset,
        'failed' => $failed, 'trusted' => $trusted, 'remember_on' => g7_td_enabled(), 'is_super' => $isSuper];
};
$team = function (array $f) use ($db, $cols, $join, $row, $uid) {
    $where = ["h.method <> 'session'"]; $args = [];
    if (!empty($f['user_id'])) { $where[] = 'h.user_id = ?'; $args[] = (int)$f['user_id']; }
    if (!empty($f['cc']) && preg_match('/^[A-Z]{2}$/', (string)$f['cc'])) { $where[] = 'h.country_code = ?'; $args[] = (string)$f['cc']; }
    if (!empty($f['alerts'])) $where[] = "(h.alert <> '' OR h.new_device = 1 OR h.confirmed = 'not_me')";
    $off = max(0, (int)($f['offset'] ?? 0));
    $q = $db->prepare("SELECT $cols, COALESCE(NULLIF(u.display_name, ''), u.username) uname FROM $join JOIN g7_users u ON u.id = h.user_id
        WHERE " . implode(' AND ', $where) . " ORDER BY h.created_at DESC, h.id DESC LIMIT 101 OFFSET $off");
    $q->execute($args); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $users = $db->query("SELECT id, COALESCE(NULLIF(display_name, ''), username) name FROM g7_users WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $ccs = $db->query("SELECT country_code cc, MAX(place) place, COUNT(*) n FROM g7_login_history WHERE country_code <> '' GROUP BY country_code ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);
    $open = $db->query("SELECT user_id, COUNT(*) n FROM g7_login_history WHERE ended_at IS NULL GROUP BY user_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    // Cuentas bloqueadas
    $locked = [];
    try { $locked = $db->query("SELECT l.user_id, UNIX_TIMESTAMP(l.locked_at) at, l.reason, COALESCE(NULLIF(u.display_name, ''), u.username) name FROM g7_account_locks l JOIN g7_users u ON u.id = l.user_id ORDER BY l.locked_at DESC")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    // Intentos fallidos de todo el equipo (48 h), por IP, con el lugar ya guardado
    $failed = [];
    try {
        $fr = $db->query("SELECT ip, COUNT(*) n, UNIX_TIMESTAMP(MAX(created_at)) last, GROUP_CONCAT(DISTINCT username ORDER BY username SEPARATOR '\n') who
            FROM g7_login_failures WHERE created_at > NOW() - INTERVAL 2 DAY AND username NOT LIKE 'pw:%' AND username NOT LIKE '2fa-cfg:%' GROUP BY ip ORDER BY n DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
        $geo = [];
        if ($fr) { $g = $db->prepare('SELECT ip, place, country_code FROM g7_ip_geo WHERE ip IN (' . implode(',', array_fill(0, count($fr), '?')) . ')'); $g->execute(array_column($fr, 'ip')); foreach ($g->fetchAll(PDO::FETCH_ASSOC) as $x) $geo[$x['ip']] = $x; }
        $names = $db->query("SELECT id, COALESCE(NULLIF(display_name, ''), username) n FROM g7_users")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($fr as $x) {
            $who = [];
            foreach (explode("\n", (string)$x['who']) as $w) {
                $w = preg_replace('/^ok:/', '', $w);
                $who[] = preg_match('/^2fa:(\d+)$/', $w, $m) ? (($names[(int)$m[1]] ?? 'alguien') . ' (código)') : $w;
            }
            $failed[] = ['ip' => (string)$x['ip'], 'n' => (int)$x['n'], 'last' => (int)$x['last'], 'who' => array_values(array_unique($who)),
                'place' => (string)($geo[$x['ip']]['place'] ?? ''), 'cc' => (string)($geo[$x['ip']]['country_code'] ?? '')];
        }
    } catch (Throwable $e) {}
    $myIp = g7_ld_ip();
    return ['rows' => array_map($row, array_slice($rows, 0, 100)), 'more' => count($rows) > 100, 'offset' => $off,
        'locked' => array_map(fn($l) => ['user_id' => (int)$l['user_id'], 'name' => (string)$l['name'], 'at' => (int)$l['at'], 'reason' => (string)$l['reason']], $locked),
        'failed' => $failed, 'blocks' => g7_blk_list(), 'my_ip' => $myIp,
        'users' => array_map(fn($u) => ['id' => (int)$u['id'], 'name' => (string)$u['name'], 'open' => (int)($open[$u['id']] ?? 0)], $users),
        'countries' => array_map(fn($c) => ['cc' => (string)$c['cc'], 'name' => trim((string)preg_replace('/^.*,\s*/', '', (string)$c['place'])), 'n' => (int)$c['n']], $ccs)];
};

try {
    if ($act === 'list') $out($list((int)($in['offset'] ?? 0)));

    if ($act === 'end') {
        $r = $mine((int)($in['id'] ?? 0));
        if (!$r) $out(['error' => 'No se encontró esa sesión.'], 404);
        if (hash_equals((string)$r['sid'], $mySid)) $out(['error' => 'Esta es la sesión que estás usando: para cerrarla usa Salir.'], 409);
        $db->prepare("UPDATE g7_login_history SET ended_at = NOW(), ended_by = 'remote' WHERE id = ? AND ended_at IS NULL")->execute([(int)$r['id']]);
        g7_audit('auth.session_end', 'user:' . $uid, ['id' => (int)$r['id'], 'device' => $r['label'], 'ip' => $r['ip']], $uid);
        $out(['ok' => true] + $list());
    }

    if ($act === 'end_others') {
        $n = g7_lh_close_others($db, $uid, $mySid, 'remote');
        g7_audit('auth.session_end_all', 'user:' . $uid, ['n' => $n], $uid);
        $out(['ok' => true, 'closed' => $n] + $list());
    }

    if ($act === 'confirm') {
        $r = $mine((int)($in['id'] ?? 0));
        if (!$r) $out(['error' => 'No se encontró ese ingreso.'], 404);
        $itsMe = !empty($in['me']);
        if (!$itsMe && hash_equals((string)$r['sid'], $mySid)) $out(['error' => 'Ese ingreso es el de este navegador.'], 409);
        $db->prepare('UPDATE g7_login_history SET confirmed = ? WHERE id = ?')->execute([$itsMe ? 'me' : 'not_me', (int)$r['id']]);
        $closed = false;
        if (!$itsMe) {
            $q = $db->prepare("UPDATE g7_login_history SET ended_at = NOW(), ended_by = 'not_me' WHERE id = ? AND ended_at IS NULL"); $q->execute([(int)$r['id']]);
            $closed = $q->rowCount() > 0;
            g7_td_revoke($db, $uid);   // un dispositivo de confianza podría ser el del intruso: se pide el código de nuevo en todos
            g7_audit('auth.not_me', 'user:' . $uid, ['id' => (int)$r['id'], 'device' => $r['label'], 'ip' => $r['ip'], 'place' => $r['place']], $uid);
        }
        $out(['ok' => true, 'closed' => $closed] + $list());
    }

    if ($act === 'rename') {
        $r = $mine((int)($in['id'] ?? 0));
        if (!$r) $out(['error' => 'No se encontró ese dispositivo.'], 404);
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($in['name'] ?? ''))));
        if (mb_strlen($name) > 60) $out(['error' => 'El nombre puede tener hasta 60 letras.'], 422);
        $q = $db->prepare('SELECT fp FROM g7_login_history WHERE id = ?'); $q->execute([(int)$r['id']]); $fp = (string)$q->fetchColumn();
        $db->prepare('INSERT INTO g7_login_devices (user_id, fp, label, first_seen, last_seen, last_ip, name) VALUES (?, ?, ?, NOW(), NOW(), ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)')
            ->execute([$uid, $fp, (string)$r['label'], (string)$r['ip'], $name]);
        $out(['ok' => true] + $list());
    }

    if ($act === 'trust_remove') {
        $n = g7_td_revoke($db, $uid, (int)($in['id'] ?? 0));
        g7_audit('auth.trusted_remove', 'user:' . $uid, ['n' => $n], $uid);
        $out(['ok' => true, 'removed' => $n] + $list());
    }

    if ($act === 'lock_me') {
        g7_acct_lock($db, $uid, $uid, 'self');
        if (!headers_sent()) setcookie('g7_bye', 'locked', ['expires' => time() + 600, 'path' => '/toolz/', 'secure' => g7_is_https(), 'samesite' => 'Lax']);
        $out(['ok' => true, 'logout' => true]);
    }

    if (in_array($act, ['team', 'team_end', 'team_end_user', 'team_unlock', 'block_add', 'block_remove'], true)) {
        if (!$isSuper) $out(['error' => 'Solo un superadmin puede ver los ingresos del equipo.'], 403);
        $f = (array)($in['filter'] ?? []);
        if ($act === 'team_end') {
            $q = $db->prepare('SELECT id, user_id, sid, label, ip FROM g7_login_history WHERE id = ?'); $q->execute([(int)($in['id'] ?? 0)]); $r = $q->fetch(PDO::FETCH_ASSOC);
            if (!$r) $out(['error' => 'No se encontró esa sesión.'], 404);
            if (hash_equals((string)$r['sid'], $mySid)) $out(['error' => 'Es tu propia sesión: para cerrarla usa Salir.'], 409);
            $db->prepare("UPDATE g7_login_history SET ended_at = NOW(), ended_by = 'admin' WHERE id = ? AND ended_at IS NULL")->execute([(int)$r['id']]);
            g7_audit('auth.admin_session_end', 'user:' . (int)$r['user_id'], ['id' => (int)$r['id'], 'device' => $r['label'], 'ip' => $r['ip']], $uid);
        }
        if ($act === 'team_unlock') {
            $target = (int)($in['user_id'] ?? 0);
            if ($target <= 0) $out(['error' => 'Falta la persona.'], 400);
            g7_acct_unlock($db, $target, $uid);
        }
        if ($act === 'block_add') {
            $kind = (string)($in['kind'] ?? ''); $val = trim((string)($in['value'] ?? ''));
            if ($kind === 'ip') { if (!filter_var($val, FILTER_VALIDATE_IP)) $out(['error' => 'Esa IP no es válida.'], 422); }
            elseif ($kind === 'country') { $val = strtoupper($val); if (!preg_match('/^[A-Z]{2}$/', $val)) $out(['error' => 'Ese país no es válido.'], 422); }
            else $out(['error' => 'Tipo de bloqueo desconocido.'], 400);
            // Nunca la propia conexión: un superadmin no puede dejarse afuera a sí mismo
            $myIp = g7_ld_ip();
            if ($kind === 'ip' && $val === $myIp) $out(['error' => 'Es la IP desde la que estás conectado: te dejarías afuera.'], 409);
            if ($kind === 'country' && $val === g7_geo_lookup($db, $myIp)[0]) $out(['error' => 'Es el país desde el que estás conectado: te dejarías afuera.'], 409);
            $hours = max(0, min(24 * 365, (int)($in['hours'] ?? 0)));
            $list = array_values(array_filter(g7_blk_list(), fn($b) => !($b['kind'] === $kind && $b['value'] === $val)));
            $list[] = ['id' => bin2hex(random_bytes(4)), 'kind' => $kind, 'value' => $val, 'note' => mb_substr(trim(strip_tags((string)($in['note'] ?? ''))), 0, 120),
                'by' => $uid, 'at' => time(), 'until' => $hours ? time() + $hours * 3600 : 0];
            g7_blk_save($list);
            g7_audit('auth.block_add', $kind . ':' . $val, ['hours' => $hours], $uid);
        }
        if ($act === 'block_remove') {
            $id = (string)($in['id'] ?? '');
            $list = g7_blk_list(); $gone = array_values(array_filter($list, fn($b) => $b['id'] === $id));
            g7_blk_save(array_values(array_filter($list, fn($b) => $b['id'] !== $id)));
            if ($gone) g7_audit('auth.block_remove', $gone[0]['kind'] . ':' . $gone[0]['value'], null, $uid);
        }
        if ($act === 'team_end_user') {
            $target = (int)($in['user_id'] ?? 0);
            if ($target <= 0) $out(['error' => 'Falta la persona.'], 400);
            $n = g7_lh_close_others($db, $target, $target === $uid ? $mySid : '', 'admin');   // a uno mismo no lo saca de este navegador
            $t = g7_td_revoke($db, $target);
            g7_audit('auth.admin_session_end_all', 'user:' . $target, ['n' => $n, 'trusted' => $t], $uid);
            $out(['ok' => true, 'closed' => $n] + $team($f));
        }
        $out(['ok' => true] + $team($f));
    }

    $out(['error' => 'Acción desconocida.'], 400);
} catch (Throwable $e) {
    $out(['error' => 'No se pudo completar. Prueba de nuevo.'], 500);
}
