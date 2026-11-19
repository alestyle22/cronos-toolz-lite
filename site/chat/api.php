<?php
/**
 * chat/api.php — API del chat corporativo.
 *   POST JSON {action, ...}            (con cabecera X-CH: 1, así un formulario de otro sitio no puede usarla)
 *   POST multipart action=up_chunk     (subida en partes: el hosting corta en 2 MB)
 *   GET  ?action=file&id=N             (descarga con permiso)
 * Todos los permisos se verifican acá; la pantalla solo esconde botones.
 */
require_once __DIR__ . '/lib.php';

function out(array $a, int $code = 200): void {
    http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode($a, JSON_UNESCAPED_UNICODE); exit;
}
function fail(string $msg, int $code = 400): void { out(['ok' => false, 'error' => $msg], $code); }

$me = getCurrentUser();
if (!$me) fail('Tu sesión venció: vuelve a entrar', 401);
if (function_exists('g7_can') && !g7_can((int)$me['id'], 'chat')) fail('No tienes acceso al chat', 403);
session_write_close(); // la sincronización corre seguido: no bloquear la sesión
$uid = (int)$me['id'];
$db = ch_db();
ch_install();

// ── Descarga de archivos ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['action'] ?? '') !== 'file') fail('Pedido inválido');
    $q = $db->prepare('SELECT f.*, m.deleted_at FROM g7_ch_files f LEFT JOIN g7_ch_messages m ON m.id = f.message_id WHERE f.id = ?');
    $q->execute([(int)($_GET['id'] ?? 0)]);
    $f = $q->fetch(PDO::FETCH_ASSOC);
    $c = $f ? ch_conv((int)$f['conv_id']) : null;
    // message_id NULL = subido y todavía sin mandar (solo lo ve quien lo subió); 0 = imagen de la conversación
    $ok = $f && $c && !$f['deleted_at'] && ($f['message_id'] === null ? (int)$f['user_id'] === $uid : ch_can_read($c, $me));
    if (!$ok) { http_response_code(404); exit('Archivo no disponible'); }
    if (str_starts_with($f['stored'], 'legacy:')) {
        $u = substr($f['stored'], 7);
        if (!preg_match('#^/toolz/chat_files/[\w.\-]+$#', $u)) { http_response_code(404); exit; }
        header('Location: ' . $u); exit;
    }
    $path = CH_FILES . '/' . $f['stored'];
    if (!is_file($path)) { http_response_code(404); exit('El archivo ya no está en el servidor'); }
    $mime = $f['mime'] ?: 'application/octet-stream';
    $inline = preg_match('#^(image/(jpeg|png|gif|webp|avif)|video/(mp4|webm|quicktime)|audio/\w+|application/pdf)$#', $mime) && empty($_GET['dl']);
    $size = filesize($path); $start = 0; $end = $size - 1;
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: sandbox; default-src \'none\'; img-src \'self\'; media-src \'self\'; style-src \'unsafe-inline\'');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($f['name']));
    header('Cache-Control: private, max-age=86400');
    header('Accept-Ranges: bytes');
    if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'] ?? '', $rm)) {
        if ($rm[1] !== '') { $start = (int)$rm[1]; if ($rm[2] !== '') $end = min((int)$rm[2], $size - 1); }
        elseif ($rm[2] !== '') $start = max(0, $size - (int)$rm[2]);
        if ($start > $end) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
        http_response_code(206); header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    $fh = fopen($path, 'rb'); fseek($fh, $start); $left = $end - $start + 1;
    while ($left > 0 && !feof($fh)) { $buf = fread($fh, min(65536, $left)); echo $buf; $left -= strlen($buf); }
    fclose($fh); exit;
}

if (($_SERVER['HTTP_X_CH'] ?? '') !== '1') fail('Pedido inválido', 403);

// ── Subida en partes ────────────────────────────────────────────
if (is_dir(CH_TMP) && mt_rand(1, 30) === 1) foreach (glob(CH_TMP . '/*') ?: [] as $d) if (is_dir($d) && filemtime($d) < time() - 86400) { array_map('unlink', glob("$d/*") ?: []); @unlink("$d/.owner"); @rmdir($d); }
if (($_POST['action'] ?? '') === 'up_chunk') {
    $tok = (string)($_POST['token'] ?? ''); $idx = (int)($_POST['index'] ?? -1);
    if (!preg_match('/^[a-f0-9]{32}$/', $tok) || $idx < 0 || $idx > 20000) fail('Pedido inválido');
    $up = $_FILES['chunk'] ?? null;
    if ($why = g7_upload_problem($up)) fail($why);
    if (!$up || $up['error'] !== UPLOAD_ERR_OK) fail('No llegó la parte del archivo');
    $dir = CH_TMP . "/$tok";
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) fail('No se pudo preparar la subida', 500);
    if (is_file("$dir/.owner") && trim(file_get_contents("$dir/.owner")) !== (string)$uid) fail('Pedido inválido', 403);
    file_put_contents("$dir/.owner", (string)$uid);
    if (!move_uploaded_file($up['tmp_name'], sprintf('%s/%05d', $dir, $idx))) fail('El servidor no pudo guardar la parte', 500);
    out(['ok' => true]);
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$act = (string)($in['action'] ?? '');
$int = fn($k) => (int)($in[$k] ?? 0);
$users = ch_users();
$me['role'] = $users[$uid]['role'] ?? $me['role'];
$admin = ch_is_admin($me);

/** Conversación que el usuario puede leer, o corta. */
$need = function (int $id, string $what = 'read') use ($me): array {
    $c = ch_conv($id);
    if (!$c || ($c['archived'] && $what !== 'read')) fail('Esa conversación no existe', 404);
    $ok = match ($what) { 'read' => ch_can_read($c, $me), 'post' => ch_can_post($c, $me), 'manage' => ch_can_manage($c, $me), 'member' => (bool)ch_member((int)$c['id'], (int)$me['id']) };
    if (!$ok) fail($what === 'post' && $c['announce'] ? 'En este canal solo publican sus administradores' : 'No tienes permiso para esto', 403);
    return $c;
};
$name = fn($id) => $users[$id]['name'] ?? 'Alguien';
$system = function (int $conv, string $text) use ($uid) { ch_insert_message($conv, $uid, $text, ['kind' => 'system']); };
$cleanName = fn($s, $max = 80) => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)$s))), 0, $max);
$chanName = function ($s) {
    $s = mb_strtolower(trim((string)$s));
    $s = preg_replace('/[\s_]+/u', '-', $s);
    $s = preg_replace('/[^\p{L}\p{N}\-]+/u', '', $s);
    return mb_substr(trim($s, '-'), 0, 60);
};

require __DIR__ . '/api2.php';
require __DIR__ . '/api_admin.php';

switch ($act) {

// ── Arranque ────────────────────────────────────────────────────
case 'boot': {
    $db->prepare('UPDATE g7_users SET last_seen = NOW() WHERE id = ?')->execute([$uid]);
    $cats = $db->query('SELECT id, name, sort FROM g7_ch_categories ORDER BY sort, id')->fetchAll(PDO::FETCH_ASSOC);
    $pub = [];
    foreach ($users as $u) $pub[] = ['id' => $u['id'], 'name' => $u['name'], 'username' => $u['username'], 'avatar' => $u['avatar'], 'position' => $u['position'],
        'email' => $u['email'], 'dept' => $u['dept'], 'active' => $u['active'], 'status' => $u['status'], 'status_text' => $u['status_text'], 'online' => ch_online($u['last_seen']), 'last_seen' => $u['last_seen'], 'loc' => $u['loc']];
    $teams = [];
    try { $teams = $db->query('SELECT id, name, color FROM g7_teams WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    out(['ok' => true, 'me' => ['id' => $uid, 'admin' => $admin], 'users' => $pub, 'teams' => $teams, 'categories' => $cats,
        'convs' => ch_conv_summaries($uid), 'last_event' => ch_last_event(), 'statuses' => CH_STATUSES,
        'max_bytes' => ch_max_bytes(), 'exts' => array_values(array_diff(array_merge(G7_EXT_IMG, G7_EXT_MEDIA, G7_EXT_DOCS), ch_policy()['blocked_ext'])),
        'prefs' => ch_prefs($uid), 'notif_unread' => (int)$db->query("SELECT COUNT(*) FROM g7_ch_notifs WHERE user_id = $uid AND read_at IS NULL")->fetchColumn(),
        'tasks' => !function_exists('g7_can') || g7_can($uid, 'tareas')]);
}

// ── Sincronización ──────────────────────────────────────────────
case 'sync': {
    if (is_file(__DIR__ . '/../mobile/push.php')) { require_once __DIR__ . '/../mobile/push.php'; mb_push_later(); }   // push a la app (después de responder)
    $after = $int('after'); $open = $int('open');
    $db->prepare('UPDATE g7_users SET last_seen = NOW() WHERE id = ? AND (last_seen IS NULL OR last_seen < NOW() - INTERVAL 30 SECOND)')->execute([$uid]);
    // Limpieza de vez en cuando: los eventos solo sirven para sincronizar (una pestaña vieja recarga todo)
    ch_dispatch();
    if (mt_rand(1, 400) === 1) { $db->exec('DELETE FROM g7_ch_events WHERE created_at < NOW() - INTERVAL 7 DAY'); $db->exec('DELETE FROM g7_ch_typing WHERE at < NOW() - INTERVAL 1 MINUTE'); }
    $openOk = 0;
    if ($open && ($c = ch_conv($open)) && ch_can_read($c, $me)) $openOk = $open;
    $q = $db->prepare("SELECT id, conv_id, kind, ref_id, user_id, target_user FROM g7_ch_events
        WHERE id > ? AND (conv_id IN (SELECT conv_id FROM g7_ch_members WHERE user_id = ?) OR conv_id = ? OR conv_id = 0 OR target_user = ?) ORDER BY id LIMIT 400");
    $q->execute([$after, $uid, $openOk, $uid]);
    $ev = $q->fetchAll(PDO::FETCH_ASSOC);
    $last = $ev ? (int)end($ev)['id'] : max($after, 0);
    if (!$ev && $after > ch_last_event()) $last = ch_last_event(); // base reiniciada
    $msgIds = []; $convIds = []; $gone = []; $cats = false; $notifIds = [];
    foreach ($ev as $e) {
        if ($e['kind'] === 'cats') { $cats = true; continue; }
        if ($e['kind'] === 'notif') { if ((int)$e['target_user'] === $uid) $notifIds[] = (int)$e['ref_id']; continue; }
        $convIds[(int)$e['conv_id']] = 1;
        if (in_array($e['kind'], ['msg', 'upd'], true) && $e['ref_id']) $msgIds[] = (int)$e['ref_id'];
        if ($e['kind'] === 'gone' && (int)$e['target_user'] === $uid) $gone[(int)$e['conv_id']] = 1;
    }
    $summ = ch_conv_summaries($uid, array_keys($convIds));
    $mine = array_column($summ, 'id');
    foreach (array_keys($gone) as $g) if (in_array($g, $mine, true)) unset($gone[$g]);
    $messages = array_values(array_filter(ch_messages_by_ids($msgIds, $uid), fn($m) => in_array($m['conv_id'], $mine, true) || $m['conv_id'] === $openOk));
    $res = ['ok' => true, 'last_event' => $last, 'more' => count($ev) === 400, 'messages' => $messages, 'convs' => $summ, 'gone' => array_keys($gone)];
    if ($notifIds) {
        $res['notifs'] = array_map(fn($n) => ['id' => (int)$n['id'], 'kind' => $n['kind'], 'conv_id' => $n['conv_id'] ? (int)$n['conv_id'] : null, 'message_id' => $n['message_id'] ? (int)$n['message_id'] : null,
            'actor_id' => (int)$n['actor_id'], 'extra' => $n['extra'], 'created_at' => $n['created_at'], 'read' => (bool)$n['read_at']],
            $db->query('SELECT * FROM g7_ch_notifs WHERE user_id = ' . $uid . ' AND id IN (' . implode(',', $notifIds) . ') ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        $res['notif_unread'] = (int)$db->query("SELECT COUNT(*) FROM g7_ch_notifs WHERE user_id = $uid AND read_at IS NULL")->fetchColumn();
    }
    if ($cats) $res['categories'] = $db->query('SELECT id, name, sort FROM g7_ch_categories ORDER BY sort, id')->fetchAll(PDO::FETCH_ASSOC);
    if ($openOk) {
        $r = $db->prepare('SELECT user_id, last_read_id FROM g7_ch_members WHERE conv_id = ?'); $r->execute([$openOk]);
        $res['reads'] = array_map(fn($x) => ['user_id' => (int)$x['user_id'], 'last_read_id' => (int)$x['last_read_id']], $r->fetchAll(PDO::FETCH_ASSOC));
        $t = $db->prepare('SELECT user_id FROM g7_ch_typing WHERE conv_id = ? AND user_id <> ? AND at > NOW() - INTERVAL 6 SECOND'); $t->execute([$openOk, $uid]);
        $res['typing'] = array_map('intval', $t->fetchAll(PDO::FETCH_COLUMN));
    }
    if (!empty($in['presence'])) {
        $res['presence'] = [];
        foreach ($db->query('SELECT id, availability, focus_text, last_seen FROM g7_users WHERE active = 1') as $u)
            $res['presence'][] = ['id' => (int)$u['id'], 'status' => isset(CH_STATUSES[$u['availability'] ?? '']) ? $u['availability'] : 'available', 'status_text' => (string)$u['focus_text'], 'online' => ch_online($u['last_seen']), 'last_seen' => $u['last_seen']];
    }
    out($res);
}

// ── Historial ───────────────────────────────────────────────────
case 'history': {
    $c = $need($int('conv'));
    $lim = max(10, min(100, $int('limit') ?: CH_PAGE));
    $before = $int('before'); $after = $int('after'); $around = $int('around');
    $base = 'SELECT * FROM g7_ch_messages WHERE conv_id = ' . (int)$c['id'] . ' AND parent_id IS NULL';
    if ($around) {
        $a = $db->query("$base AND id <= $around ORDER BY id DESC LIMIT " . intdiv($lim, 2))->fetchAll(PDO::FETCH_ASSOC);
        $b = $db->query("$base AND id > $around ORDER BY id ASC LIMIT " . intdiv($lim, 2))->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_merge(array_reverse($a), $b);
        $more_before = count($a) === intdiv($lim, 2); $more_after = count($b) === intdiv($lim, 2);
    } elseif ($after) {
        $rows = $db->query("$base AND id > $after ORDER BY id ASC LIMIT $lim")->fetchAll(PDO::FETCH_ASSOC);
        $more_before = true; $more_after = count($rows) === $lim;
    } else {
        $rows = array_reverse($db->query("$base" . ($before ? " AND id < $before" : '') . " ORDER BY id DESC LIMIT $lim")->fetchAll(PDO::FETCH_ASSOC));
        $more_before = count($rows) === $lim; $more_after = false;
    }
    $m = ch_member((int)$c['id'], $uid);
    $r = $db->prepare('SELECT user_id, last_read_id FROM g7_ch_members WHERE conv_id = ?'); $r->execute([$c['id']]);
    out(['ok' => true, 'messages' => ch_render($rows, $uid), 'more_before' => $more_before, 'more_after' => $more_after,
         'member' => (bool)$m, 'last_read_id' => $m ? (int)$m['last_read_id'] : 0,
         'reads' => array_map(fn($x) => ['user_id' => (int)$x['user_id'], 'last_read_id' => (int)$x['last_read_id']], $r->fetchAll(PDO::FETCH_ASSOC)),
         'can_post' => ch_can_post($c, $me), 'can_manage' => ch_can_manage($c, $me), 'can_join' => !$m && ch_can_join($c, $me)]);
}

case 'thread': {
    $q = $db->prepare('SELECT * FROM g7_ch_messages WHERE id = ? AND parent_id IS NULL'); $q->execute([$int('id')]);
    $root = $q->fetch(PDO::FETCH_ASSOC);
    if (!$root) fail('Ese mensaje no existe', 404);
    $need((int)$root['conv_id']);
    $rows = $db->query('SELECT * FROM g7_ch_messages WHERE parent_id = ' . (int)$root['id'] . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    out(['ok' => true, 'root' => ch_render([$root], $uid)[0], 'replies' => ch_render($rows, $uid)]);
}

// ── Escribir ────────────────────────────────────────────────────
case 'send': {
    $c = $need($int('conv'), 'post');
    $cid = mb_substr(preg_replace('/[^\w\-]/', '', (string)($in['client_id'] ?? '')), 0, 40) ?: null;
    if ($cid) {
        $d = $db->prepare('SELECT id FROM g7_ch_messages WHERE user_id = ? AND client_id = ? LIMIT 1'); $d->execute([$uid, $cid]);
        if ($dup = $d->fetchColumn()) out(['ok' => true, 'message' => ch_messages_by_ids([(int)$dup], $uid)[0]]);
    }
    // Freno al spam: 20 mensajes cada 10 segundos
    $rl = $db->prepare('SELECT COUNT(*) FROM g7_ch_messages WHERE user_id = ? AND created_at > NOW() - INTERVAL 10 SECOND'); $rl->execute([$uid]);
    if ($rl->fetchColumn() >= ch_policy()['rate_per_10s']) fail('Estás mandando mensajes muy rápido: espera unos segundos', 429);
    $body = ch_clean_body((string)($in['body'] ?? ''));
    $fileIds = array_slice(array_values(array_unique(array_map('intval', (array)($in['files'] ?? [])))), 0, 10);
    if ($body === '' && !$fileIds) fail('El mensaje está vacío');
    $parent = $int('parent_id') ?: null; $reply = $int('reply_to') ?: null;
    foreach (['parent' => $parent, 'reply' => $reply] as $k => $mid) {
        if (!$mid) continue;
        $q = $db->prepare('SELECT conv_id, parent_id FROM g7_ch_messages WHERE id = ?'); $q->execute([$mid]); $x = $q->fetch(PDO::FETCH_ASSOC);
        if (!$x || (int)$x['conv_id'] !== (int)$c['id'] || ($k === 'parent' && $x['parent_id'])) fail('El mensaje al que respondes ya no está');
    }
    $db->beginTransaction();
    $id = ch_insert_message((int)$c['id'], $uid, $body, ['parent_id' => $parent, 'reply_to_id' => $reply, 'client_id' => $cid]);
    if ($fileIds) {
        $db->prepare('UPDATE g7_ch_files SET message_id = ? WHERE id IN (' . implode(',', $fileIds) . ') AND user_id = ? AND conv_id = ? AND message_id IS NULL')->execute([$id, $uid, $c['id']]);
    }
    $db->commit();
    $db->prepare('DELETE FROM g7_ch_typing WHERE conv_id = ? AND user_id = ?')->execute([$c['id'], $uid]);
    ch_after_send($id);
    out(['ok' => true, 'message' => ch_messages_by_ids([$id], $uid)[0]]);
}

case 'edit': {
    $q = $db->prepare('SELECT * FROM g7_ch_messages WHERE id = ?'); $q->execute([$int('id')]); $m = $q->fetch(PDO::FETCH_ASSOC);
    if (!$m || $m['deleted_at'] || $m['kind'] === 'system') fail('Ese mensaje ya no se puede editar', 404);
    if ((int)$m['user_id'] !== $uid) fail('Solo puedes editar tus mensajes', 403);
    $need((int)$m['conv_id'], 'member');
    $body = ch_clean_body((string)($in['body'] ?? ''));
    $hasFiles = (int)$db->query('SELECT COUNT(*) FROM g7_ch_files WHERE message_id = ' . (int)$m['id'])->fetchColumn();
    if ($body === '' && !$hasFiles) fail('El mensaje no puede quedar vacío: bórralo');
    if ($body !== $m['body']) {
        $db->prepare('UPDATE g7_ch_messages SET body = ?, edited_at = NOW() WHERE id = ?')->execute([$body, $m['id']]);
        ch_event((int)$m['conv_id'], 'upd', (int)$m['id'], $uid);
        // Menciones nuevas al editar: solo avisan a quienes no estaban mencionados
        $st = $db->prepare('INSERT IGNORE INTO g7_ch_mentions (message_id, user_id) VALUES (?,?)');
        foreach (ch_parse_mentions($body, (int)$m['conv_id'], $uid) as $u) { $st->execute([$m['id'], $u]); if ($st->rowCount()) ch_notify($u, 'mention', (int)$m['conv_id'], (int)$m['id'], $uid, mb_substr($body, 0, 200)); }
    }
    out(['ok' => true, 'message' => ch_messages_by_ids([(int)$m['id']], $uid)[0]]);
}

case 'delete': {
    $q = $db->prepare('SELECT * FROM g7_ch_messages WHERE id = ?'); $q->execute([$int('id')]); $m = $q->fetch(PDO::FETCH_ASSOC);
    if (!$m || $m['deleted_at']) fail('Ese mensaje ya no está', 404);
    $c = $need((int)$m['conv_id']);
    if ((int)$m['user_id'] !== $uid && !ch_can_manage($c, $me)) fail('Solo puedes borrar tus mensajes', 403);
    if ((int)$m['user_id'] !== $uid) ch_audit($uid, 'msg_delete', (int)$c['id'], 'mensaje #' . $m['id'] . ' de ' . $name((int)$m['user_id']), mb_substr($m['body'], 0, 1000));
    $db->prepare('UPDATE g7_ch_messages SET deleted_at = NOW(), pinned_at = NULL WHERE id = ?')->execute([$m['id']]);
    $db->prepare('DELETE FROM g7_ch_reactions WHERE message_id = ?')->execute([$m['id']]);
    ch_event((int)$m['conv_id'], 'upd', (int)$m['id'], $uid);
    if ($m['parent_id']) {
        $db->prepare('UPDATE g7_ch_messages SET thread_count = GREATEST(thread_count - 1, 0) WHERE id = ?')->execute([$m['parent_id']]);
        ch_event((int)$m['conv_id'], 'upd', (int)$m['parent_id'], $uid);
    }
    out(['ok' => true, 'message' => ch_messages_by_ids([(int)$m['id']], $uid)[0]]);
}

case 'react': {
    $q = $db->prepare('SELECT * FROM g7_ch_messages WHERE id = ?'); $q->execute([$int('id')]); $m = $q->fetch(PDO::FETCH_ASSOC);
    if (!$m || $m['deleted_at']) fail('Ese mensaje ya no está', 404);
    $need((int)$m['conv_id'], 'member');
    $e = trim((string)($in['emoji'] ?? ''));
    // Solo emojis: nada de letras, números ni signos de HTML
    if ($e === '' || mb_strlen($e) > 8 || strlen($e) > 16 || preg_match('/[\p{L}\p{N}<>&"\'\s]/u', $e)) fail('Reacción inválida');
    $d = $db->prepare('DELETE FROM g7_ch_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?'); $d->execute([$m['id'], $uid, $e]);
    if (!$d->rowCount()) {
        $n = $db->prepare('SELECT COUNT(DISTINCT emoji) FROM g7_ch_reactions WHERE message_id = ?'); $n->execute([$m['id']]);
        if ($n->fetchColumn() >= 20) fail('Ese mensaje ya tiene demasiadas reacciones');
        $db->prepare('INSERT INTO g7_ch_reactions (message_id, user_id, emoji) VALUES (?,?,?)')->execute([$m['id'], $uid, $e]);
        // Aviso al autor (uno por persona y mensaje, para no llenar la actividad)
        $seen = $db->prepare("SELECT COUNT(*) FROM g7_ch_notifs WHERE user_id = ? AND kind = 'reaction' AND message_id = ? AND actor_id = ?"); $seen->execute([$m['user_id'], $m['id'], $uid]);
        if (!$seen->fetchColumn()) ch_notify((int)$m['user_id'], 'reaction', (int)$m['conv_id'], (int)$m['id'], $uid, $e);
    }
    ch_event((int)$m['conv_id'], 'upd', (int)$m['id'], $uid);
    out(['ok' => true, 'message' => ch_messages_by_ids([(int)$m['id']], $uid)[0]]);
}

case 'read': {
    $c = $need($int('conv'), 'member');
    $lid = min($int('last_id'), (int)$c['last_msg_id']);
    $u = $db->prepare('UPDATE g7_ch_members SET last_read_id = ? WHERE conv_id = ? AND user_id = ? AND last_read_id < ?');
    $u->execute([$lid, $c['id'], $uid, $lid]);
    if ($u->rowCount()) ch_event((int)$c['id'], 'read', $lid, $uid);
    out(['ok' => true]);
}

case 'typing': {
    $c = $need($int('conv'), 'member');
    $db->prepare('REPLACE INTO g7_ch_typing (conv_id, user_id, at) VALUES (?,?,NOW())')->execute([$c['id'], $uid]);
    out(['ok' => true]);
}

// ── Conversaciones ──────────────────────────────────────────────
case 'dm_open': {
    $other = $int('user_id');
    if (!isset($users[$other]) || !$users[$other]['active']) fail('Esa persona no está activa en la intranet');
    $key = min($uid, $other) . ':' . max($uid, $other);
    $q = $db->prepare('SELECT id FROM g7_ch_convs WHERE dm_key = ?'); $q->execute([$key]);
    $id = (int)$q->fetchColumn();
    if (!$id) {
        $db->prepare("INSERT INTO g7_ch_convs (kind, visibility, dm_key, created_by) VALUES ('dm','private',?,?)")->execute([$key, $uid]);
        $id = (int)$db->lastInsertId();
        $st = $db->prepare('INSERT IGNORE INTO g7_ch_members (conv_id, user_id) VALUES (?,?)');
        foreach (array_unique([$uid, $other]) as $u) $st->execute([$id, $u]);
        ch_event($id, 'conv', null, $uid);
    }
    out(['ok' => true, 'conv' => ch_conv_summaries($uid, [$id])[0] ?? null]);
}

case 'group_create':
case 'channel_create': {
    $isCh = $act === 'channel_create';
    $nm = $isCh ? $chanName($in['name'] ?? '') : $cleanName($in['name'] ?? '');
    if ($nm === '') fail($isCh ? 'Pon un nombre para el canal' : 'Pon un nombre para el grupo');
    $members = array_values(array_unique(array_filter(array_map('intval', (array)($in['members'] ?? [])), fn($u) => isset($users[$u]) && $users[$u]['active'])));
    if (!$isCh && count(array_diff($members, [$uid])) < 1) fail('Suma al menos una persona al grupo');
    $vis = $isCh && in_array($in['visibility'] ?? '', ['public', 'private', 'restricted'], true) ? $in['visibility'] : ($isCh ? 'public' : 'private');
    $teamsAllowed = $vis === 'restricted' ? implode(',', array_slice(array_unique(array_filter(array_map('intval', (array)($in['allowed_teams'] ?? [])))), 0, 30)) : '';
    if ($vis === 'restricted' && $teamsAllowed === '') fail('Elige qué equipos pueden entrar al canal');
    $announce = $isCh && !empty($in['announce']) ? 1 : 0;
    $cat = $isCh ? ($int('category_id') ?: null) : null;
    if ($cat && !$db->query('SELECT id FROM g7_ch_categories WHERE id = ' . (int)$cat)->fetchColumn()) $cat = null;
    if ($isCh) {
        $dup = $db->prepare("SELECT id FROM g7_ch_convs WHERE kind = 'channel' AND archived = 0 AND name = ?"); $dup->execute([$nm]);
        if ($dup->fetchColumn()) fail("Ya hay un canal #$nm");
    }
    $db->beginTransaction();
    $db->prepare('INSERT INTO g7_ch_convs (kind, name, topic, category_id, visibility, announce, allowed_teams, created_by, sort) VALUES (?,?,?,?,?,?,?,?,?)')
       ->execute([$isCh ? 'channel' : 'group', $nm, $cleanName($in['topic'] ?? '', 300), $cat, $vis, $announce, $teamsAllowed, $uid, 1000]);
    $id = (int)$db->lastInsertId();
    $st = $db->prepare('INSERT IGNORE INTO g7_ch_members (conv_id, user_id, role) VALUES (?,?,?)');
    $st->execute([$id, $uid, 'owner']);
    foreach ($members as $u) if ($u !== $uid) $st->execute([$id, $u, 'member']);
    $system($id, $isCh ? "creó el canal #$nm" : "creó el grupo «{$nm}»");
    $db->commit();
    out(['ok' => true, 'conv' => ch_conv_summaries($uid, [$id])[0]]);
}

case 'conv_update': {
    $c = $need($int('conv'), 'manage');
    $sets = []; $vals = []; $notes = [];
    if (array_key_exists('name', $in)) {
        $nm = $c['kind'] === 'channel' ? $chanName($in['name']) : $cleanName($in['name']);
        if ($nm === '') fail('El nombre no puede quedar vacío');
        if ($nm !== $c['name']) {
            if ($c['kind'] === 'channel') { $dup = $db->prepare("SELECT id FROM g7_ch_convs WHERE kind='channel' AND archived=0 AND name=? AND id<>?"); $dup->execute([$nm, $c['id']]); if ($dup->fetchColumn()) fail("Ya hay un canal #$nm"); }
            $sets[] = 'name = ?'; $vals[] = $nm; $notes[] = $c['kind'] === 'channel' ? "cambió el nombre a #$nm" : "cambió el nombre a «{$nm}»";
        }
    }
    if (array_key_exists('topic', $in)) { $t = $cleanName($in['topic'], 300); if ($t !== $c['topic']) { $sets[] = 'topic = ?'; $vals[] = $t; $notes[] = $t === '' ? 'borró la descripción' : 'cambió la descripción'; } }
    if ($c['kind'] === 'channel') {
        if (array_key_exists('category_id', $in)) { $cat = $int('category_id') ?: null; if ($cat && !$db->query('SELECT id FROM g7_ch_categories WHERE id=' . $cat)->fetchColumn()) $cat = null; $sets[] = 'category_id = ?'; $vals[] = $cat; }
        if (isset($in['visibility']) && in_array($in['visibility'], ['public', 'private', 'restricted'], true) && $in['visibility'] !== $c['visibility']) {
            $sets[] = 'visibility = ?'; $vals[] = $in['visibility'];
            $notes[] = ['public' => 'hizo público el canal', 'private' => 'hizo privado el canal', 'restricted' => 'restringió el canal a algunos equipos'][$in['visibility']];
        }
        if (array_key_exists('allowed_teams', $in)) { $sets[] = 'allowed_teams = ?'; $vals[] = implode(',', array_slice(array_unique(array_filter(array_map('intval', (array)$in['allowed_teams']))), 0, 30)); }
        if (array_key_exists('announce', $in) && (int)!empty($in['announce']) !== (int)$c['announce']) { $sets[] = 'announce = ?'; $vals[] = (int)!empty($in['announce']); $notes[] = !empty($in['announce']) ? 'lo convirtió en canal de anuncios' : 'permitió que todos publiquen'; }
    }
    if ($sets) { $vals[] = $c['id']; $db->prepare('UPDATE g7_ch_convs SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals); }
    $nc = ch_conv((int)$c['id']);
    if ($nc['visibility'] === 'restricted' && $nc['allowed_teams'] === '') fail('Elige qué equipos pueden entrar al canal');
    foreach ($notes as $n) $system((int)$c['id'], $n);
    if ($notes) ch_audit($uid, 'conv_update', (int)$c['id'], $c['kind'] === 'channel' ? '#' . $nc['name'] : $nc['name'], implode('; ', $notes));
    ch_event((int)$c['id'], 'conv', null, $uid);
    out(['ok' => true, 'conv' => ch_conv_summaries($uid, [(int)$c['id']])[0] ?? null]);
}

case 'avatar': {
    // Imagen del grupo/canal: viene como archivo ya subido en partes (up_done con avatar=1)
    $c = $need($int('conv'), 'manage');
    $f = $db->prepare('SELECT * FROM g7_ch_files WHERE id = ? AND user_id = ? AND message_id IS NULL'); $f->execute([$int('file_id'), $uid]); $f = $f->fetch(PDO::FETCH_ASSOC);
    if ($f && !str_starts_with($f['mime'], 'image/')) fail('La imagen tiene que ser JPG, PNG, WebP o GIF');
    $db->prepare('UPDATE g7_ch_convs SET avatar = ? WHERE id = ?')->execute([$f ? 'api.php?action=file&id=' . (int)$f['id'] : '', $c['id']]);
    if ($f) $db->prepare('UPDATE g7_ch_files SET conv_id = ?, message_id = 0 WHERE id = ?')->execute([$c['id'], $f['id']]);
    $system((int)$c['id'], $f ? 'cambió la imagen' : 'quitó la imagen');
    ch_event((int)$c['id'], 'conv', null, $uid);
    out(['ok' => true, 'conv' => ch_conv_summaries($uid, [(int)$c['id']])[0] ?? null]);
}

case 'conv_delete': {
    $c = $need($int('conv'));
    $m = ch_member((int)$c['id'], $uid);
    $can = $c['kind'] === 'channel' ? ($admin || ($m && $m['role'] === 'owner')) : ($c['kind'] === 'group' && $m && $m['role'] === 'owner');
    if (!$can) fail($c['kind'] === 'channel' ? 'Solo quien creó el canal o un admin lo puede borrar' : 'Solo quien creó el grupo lo puede borrar', 403);
    $mem = $db->prepare('SELECT user_id FROM g7_ch_members WHERE conv_id = ?'); $mem->execute([$c['id']]);
    $db->prepare('UPDATE g7_ch_convs SET archived = 1, dm_key = NULL WHERE id = ?')->execute([$c['id']]);
    foreach ($mem->fetchAll(PDO::FETCH_COLUMN) as $u) ch_event((int)$c['id'], 'gone', null, $uid, (int)$u);
    ch_audit($uid, 'conv_delete', (int)$c['id'], ($c['kind'] === 'channel' ? '#' : '') . $c['name']);
    $db->prepare('DELETE FROM g7_ch_members WHERE conv_id = ?')->execute([$c['id']]);
    out(['ok' => true]);
}

case 'info': {
    $c = $need($int('conv'));
    $mem = $db->prepare('SELECT user_id, role, joined_at FROM g7_ch_members WHERE conv_id = ? ORDER BY FIELD(role, "owner","admin","member"), joined_at'); $mem->execute([$c['id']]);
    $files = $db->prepare('SELECT f.id, f.name, f.size, f.mime, f.user_id, f.created_at, f.message_id FROM g7_ch_files f JOIN g7_ch_messages m ON m.id = f.message_id AND m.deleted_at IS NULL WHERE f.conv_id = ? ORDER BY f.id DESC LIMIT 60');
    $files->execute([$c['id']]);
    $pins = $db->prepare('SELECT * FROM g7_ch_messages WHERE conv_id = ? AND pinned_at IS NOT NULL AND deleted_at IS NULL ORDER BY pinned_at DESC LIMIT 50'); $pins->execute([$c['id']]);
    out(['ok' => true,
        'members' => array_map(fn($x) => ['user_id' => (int)$x['user_id'], 'role' => $x['role'], 'joined_at' => $x['joined_at']], $mem->fetchAll(PDO::FETCH_ASSOC)),
        'files' => array_map(fn($f) => ['id' => (int)$f['id'], 'name' => $f['name'], 'size' => (int)$f['size'], 'mime' => $f['mime'], 'user_id' => (int)$f['user_id'], 'created_at' => $f['created_at'], 'message_id' => (int)$f['message_id'], 'url' => 'api.php?action=file&id=' . (int)$f['id']], $files->fetchAll(PDO::FETCH_ASSOC)),
        'pins' => ch_render($pins->fetchAll(PDO::FETCH_ASSOC), $uid),
        'can_manage' => ch_can_manage($c, $me), 'is_owner' => ($m = ch_member((int)$c['id'], $uid)) && $m['role'] === 'owner']);
}

case 'members_add': {
    $c = $need($int('conv'));
    if ($c['kind'] === 'dm') fail('A un mensaje directo no se le suman personas: arma un grupo');
    if (!ch_can_manage($c, $me) && !($c['kind'] === 'channel' && $c['visibility'] === 'public' && ch_member((int)$c['id'], $uid))) fail('Solo los administradores pueden sumar personas', 403);
    $add = array_values(array_unique(array_filter(array_map('intval', (array)($in['user_ids'] ?? [])), fn($u) => isset($users[$u]) && $users[$u]['active'])));
    $st = $db->prepare('INSERT IGNORE INTO g7_ch_members (conv_id, user_id) VALUES (?,?)');
    $added = [];
    foreach ($add as $u) { $st->execute([$c['id'], $u]); if ($st->rowCount()) $added[] = $u; }
    if ($added) {
        $db->prepare('UPDATE g7_ch_members SET last_read_id = GREATEST(last_read_id, ?) WHERE conv_id = ? AND user_id IN (' . implode(',', $added) . ')')->execute([max(0, (int)$c['last_msg_id'] - 20), $c['id']]);
        $system((int)$c['id'], 'sumó a ' . implode(', ', array_map($name, $added)));
        foreach ($added as $u) { ch_event((int)$c['id'], 'conv', null, $uid, $u); ch_notify($u, 'invite', (int)$c['id'], null, $uid, $c['name']); }
    }
    out(['ok' => true, 'added' => $added]);
}

case 'member_remove': {
    $c = $need($int('conv'), 'manage');
    $who = $int('user_id');
    $m = ch_member((int)$c['id'], $who);
    if (!$m) fail('Esa persona ya no está');
    if ($m['role'] === 'owner') fail('A quien creó la conversación no se lo puede sacar');
    if ($m['role'] === 'admin' && !(($mm = ch_member((int)$c['id'], $uid)) && $mm['role'] === 'owner') && !$admin) fail('Solo el dueño saca administradores', 403);
    $db->prepare('DELETE FROM g7_ch_members WHERE conv_id = ? AND user_id = ?')->execute([$c['id'], $who]);
    $system((int)$c['id'], 'sacó a ' . $name($who));
    ch_audit($uid, 'member_remove', (int)$c['id'], $name($who));
    ch_event((int)$c['id'], 'gone', null, $uid, $who);
    out(['ok' => true]);
}

case 'member_role': {
    $c = $need($int('conv'));
    $mm = ch_member((int)$c['id'], $uid);
    if (!(($mm && $mm['role'] === 'owner') || ($c['kind'] === 'channel' && $admin))) fail('Solo el dueño nombra administradores', 403);
    $who = $int('user_id'); $role = ($in['role'] ?? '') === 'admin' ? 'admin' : 'member';
    $m = ch_member((int)$c['id'], $who);
    if (!$m || $m['role'] === 'owner') fail('No se puede cambiar el rol de esa persona');
    $db->prepare('UPDATE g7_ch_members SET role = ? WHERE conv_id = ? AND user_id = ?')->execute([$role, $c['id'], $who]);
    $system((int)$c['id'], ($role === 'admin' ? 'hizo administrador a ' : 'le sacó la administración a ') . $name($who));
    ch_audit($uid, 'member_role', (int)$c['id'], $name($who), $role);
    ch_event((int)$c['id'], 'conv', null, $uid);
    out(['ok' => true]);
}

case 'join': {
    $c = ch_conv($int('conv'));
    if (!$c || !ch_can_join($c, $me)) fail('No puedes entrar a ese canal', 403);
    $db->prepare('INSERT IGNORE INTO g7_ch_members (conv_id, user_id, last_read_id) VALUES (?,?,?)')->execute([$c['id'], $uid, (int)$c['last_msg_id']]);
    $system((int)$c['id'], 'se unió al canal');
    out(['ok' => true, 'conv' => ch_conv_summaries($uid, [(int)$c['id']])[0] ?? null]);
}

case 'leave': {
    $c = $need($int('conv'), 'member');
    if ($c['kind'] === 'dm') fail('Los mensajes directos no se abandonan');
    $m = ch_member((int)$c['id'], $uid);
    $db->prepare('DELETE FROM g7_ch_members WHERE conv_id = ? AND user_id = ?')->execute([$c['id'], $uid]);
    if ($m['role'] === 'owner') {
        // La conversación pasa al administrador más antiguo, o a quien esté hace más tiempo
        $n = $db->prepare('SELECT user_id FROM g7_ch_members WHERE conv_id = ? ORDER BY role = "admin" DESC, joined_at LIMIT 1'); $n->execute([$c['id']]);
        if ($next = $n->fetchColumn()) $db->prepare('UPDATE g7_ch_members SET role = "owner" WHERE conv_id = ? AND user_id = ?')->execute([$c['id'], $next]);
        elseif ($c['kind'] === 'group') $db->prepare('UPDATE g7_ch_convs SET archived = 1 WHERE id = ?')->execute([$c['id']]);
    }
    $system((int)$c['id'], $c['kind'] === 'channel' ? 'salió del canal' : 'salió del grupo');
    ch_event((int)$c['id'], 'gone', null, $uid, $uid);
    out(['ok' => true]);
}

case 'browse': {
    $rows = $db->query("SELECT c.*, (SELECT COUNT(*) FROM g7_ch_members m WHERE m.conv_id = c.id) n,
        EXISTS(SELECT 1 FROM g7_ch_members m WHERE m.conv_id = c.id AND m.user_id = $uid) joined
        FROM g7_ch_convs c WHERE c.kind = 'channel' AND c.archived = 0 ORDER BY c.name")->fetchAll(PDO::FETCH_ASSOC);
    $list = [];
    foreach ($rows as $r) {
        if (!$r['joined'] && !ch_can_join($r, $me)) continue;
        $list[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'topic' => $r['topic'], 'visibility' => $r['visibility'], 'announce' => (bool)$r['announce'],
            'category_id' => $r['category_id'] ? (int)$r['category_id'] : null, 'members' => (int)$r['n'], 'joined' => (bool)$r['joined'], 'last_msg_at' => $r['last_msg_at']];
    }
    out(['ok' => true, 'channels' => $list]);
}

case 'pref': {
    $c = $need($int('conv'), 'member');
    foreach (['muted', 'favorite'] as $k) if (array_key_exists($k, $in))
        $db->prepare("UPDATE g7_ch_members SET $k = ? WHERE conv_id = ? AND user_id = ?")->execute([(int)!empty($in[$k]), $c['id'], $uid]);
    out(['ok' => true, 'conv' => ch_conv_summaries($uid, [(int)$c['id']])[0] ?? null]);
}

case 'status': {
    $s = (string)($in['status'] ?? 'available');
    if (!isset(CH_STATUSES[$s])) fail('Estado inválido');
    $t = $cleanName($in['text'] ?? '', 140);
    $db->prepare('UPDATE g7_users SET availability = ?, focus_text = ?, last_seen = NOW() WHERE id = ?')->execute([$s, $t, $uid]);
    out(['ok' => true]);
}

// ── Categorías (admin) ──────────────────────────────────────────
case 'cat_save': {
    if (!$admin) fail('Solo los administradores ordenan las categorías', 403);
    $nm = $cleanName($in['name'] ?? '', 60);
    if ($nm === '') fail('Pon un nombre para la categoría');
    if ($id = $int('id')) $db->prepare('UPDATE g7_ch_categories SET name = ? WHERE id = ?')->execute([$nm, $id]);
    else { $db->prepare('INSERT INTO g7_ch_categories (name, sort) VALUES (?, (SELECT COALESCE(MAX(sort),0)+1 FROM g7_ch_categories x))')->execute([$nm]); $id = (int)$db->lastInsertId(); }
    ch_event(0, 'cats', $id, $uid);
    ch_audit($uid, 'cat_save', null, $nm);
    out(['ok' => true, 'categories' => $db->query('SELECT id, name, sort FROM g7_ch_categories ORDER BY sort, id')->fetchAll(PDO::FETCH_ASSOC)]);
}
case 'cat_delete': {
    if (!$admin) fail('Solo los administradores ordenan las categorías', 403);
    $id = $int('id');
    $db->prepare('UPDATE g7_ch_convs SET category_id = NULL WHERE category_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM g7_ch_categories WHERE id = ?')->execute([$id]);
    ch_audit($uid, 'cat_delete', null, '#' . $id);
    ch_event(0, 'cats', $id, $uid);
    out(['ok' => true, 'categories' => $db->query('SELECT id, name, sort FROM g7_ch_categories ORDER BY sort, id')->fetchAll(PDO::FETCH_ASSOC)]);
}
case 'order': {
    // Orden de categorías y de canales dentro de cada una (arrastrando en la lista)
    if (!$admin) fail('Solo los administradores ordenan los canales', 403);
    $st = $db->prepare('UPDATE g7_ch_categories SET sort = ? WHERE id = ?');
    foreach (array_values((array)($in['categories'] ?? [])) as $i => $id) $st->execute([$i, (int)$id]);
    $st = $db->prepare("UPDATE g7_ch_convs SET sort = ?, category_id = ? WHERE id = ? AND kind = 'channel'");
    foreach ((array)($in['channels'] ?? []) as $row) {
        $cat = (int)($row['category_id'] ?? 0) ?: null;
        foreach (array_values((array)($row['ids'] ?? [])) as $i => $id) $st->execute([$i, $cat, (int)$id]);
    }
    ch_event(0, 'cats', null, $uid);
    out(['ok' => true]);
}

// ── Archivos ────────────────────────────────────────────────────
case 'up_done': {
    $tok = (string)($in['token'] ?? ''); $parts = $int('parts'); $size = $int('size');
    $dir = CH_TMP . "/$tok";
    $clean = function () use ($dir) { array_map('unlink', glob("$dir/*") ?: []); @unlink("$dir/.owner"); @rmdir($dir); };
    if (!preg_match('/^[a-f0-9]{32}$/', $tok) || !is_dir($dir) || trim((string)@file_get_contents("$dir/.owner")) !== (string)$uid) fail('La subida se perdió: prueba de nuevo');
    $c = ch_conv($int('conv'));
    $isAvatar = !empty($in['avatar']);
    if (!$c || !($isAvatar ? ch_can_manage($c, $me) : ch_can_post($c, $me))) { $clean(); fail('No puedes mandar archivos acá', 403); }
    $fname = mb_substr(trim(preg_replace('/[\x00-\x1f\\\\\/:*?"<>|]+/u', '-', (string)($in['name'] ?? ''))), 0, 200);
    if ($fname === '' || $fname[0] === '.') { $clean(); fail('El archivo no tiene un nombre válido'); }
    if (!ch_allowed_ext($fname)) { $clean(); fail("$fname: ese tipo de archivo no se puede mandar"); }
    if ($size > ch_max_bytes()) { $clean(); fail("$fname pesa más de " . (ch_max_bytes() >> 20) . ' MB'); }
    if (($quota = ch_policy()['quota_mb']) > 0) {
        $used = (int)$db->query("SELECT COALESCE(SUM(size),0) FROM g7_ch_files WHERE user_id = $uid AND stored NOT LIKE 'legacy:%'")->fetchColumn();
        if ($used + $size > $quota * 1048576) { $clean(); fail("Llegaste al límite de $quota MB de archivos en el chat"); }
    }
    $chunks = glob("$dir/[0-9][0-9][0-9][0-9][0-9]") ?: []; sort($chunks);
    if (count($chunks) !== $parts) { $clean(); fail("$fname llegó incompleto: prueba de nuevo"); }
    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
    $sub = date('Ym');
    if (!is_dir(CH_FILES . "/$sub") && !@mkdir(CH_FILES . "/$sub", 0755, true)) { $clean(); fail('El servidor no puede guardar archivos del chat', 500); }
    $stored = "$sub/" . bin2hex(random_bytes(12)) . ".$ext";
    $outF = @fopen(CH_FILES . "/$stored", 'wb');
    if (!$outF) { $clean(); fail('El servidor no puede guardar archivos del chat', 500); }
    foreach ($chunks as $ch) { $i2 = fopen($ch, 'rb'); stream_copy_to_stream($i2, $outF); fclose($i2); }
    fclose($outF); $clean();
    if (filesize(CH_FILES . "/$stored") !== $size) { @unlink(CH_FILES . "/$stored"); fail("$fname llegó con otro tamaño: prueba de nuevo"); }
    // El tipo real lo dice el contenido, no el navegador
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file(CH_FILES . "/$stored") ?: 'application/octet-stream';
    if (in_array($ext, G7_EXT_IMG, true) && !str_starts_with($mime, 'image/')) { @unlink(CH_FILES . "/$stored"); fail("$fname no es una imagen válida"); }
    if (preg_match('#(html|javascript|x-php|svg)#i', $mime)) $mime = 'application/octet-stream';
    $db->prepare('INSERT INTO g7_ch_files (conv_id, user_id, name, stored, size, mime) VALUES (?,?,?,?,?,?)')->execute([$c['id'], $uid, $fname, $stored, $size, $mime]);
    $fid = (int)$db->lastInsertId();
    out(['ok' => true, 'file' => ['id' => $fid, 'name' => $fname, 'size' => $size, 'mime' => $mime, 'url' => 'api.php?action=file&id=' . $fid]]);
}
case 'up_abort': {
    $tok = (string)($in['token'] ?? ''); $dir = CH_TMP . "/$tok";
    if (preg_match('/^[a-f0-9]{32}$/', $tok) && is_dir($dir) && trim((string)@file_get_contents("$dir/.owner")) === (string)$uid) { array_map('unlink', glob("$dir/*") ?: []); @unlink("$dir/.owner"); @rmdir($dir); }
    out(['ok' => true]);
}

// ── Archivos compartidos en mis conversaciones ──────────────────
case 'files': {
    $where = ['f.conv_id IN (SELECT conv_id FROM g7_ch_members WHERE user_id = ' . $uid . ')', 'm.deleted_at IS NULL'];
    $args = [];
    if (($q = trim((string)($in['q'] ?? ''))) !== '') { $where[] = 'f.name LIKE ?'; $args[] = '%' . addcslashes($q, '%_\\') . '%'; }
    $k = (string)($in['kind'] ?? '');
    if ($k === 'img') $where[] = "f.mime LIKE 'image/%'";
    elseif ($k === 'media') $where[] = "(f.mime LIKE 'video/%' OR f.mime LIKE 'audio/%')";
    elseif ($k === 'docs') $where[] = "f.mime NOT LIKE 'image/%' AND f.mime NOT LIKE 'video/%' AND f.mime NOT LIKE 'audio/%'";
    if ($c = $int('conv')) { $where[] = 'f.conv_id = ?'; $args[] = $c; }
    if ($u = $int('user')) { $where[] = 'f.user_id = ?'; $args[] = $u; }
    $st = $db->prepare('SELECT f.id, f.name, f.size, f.mime, f.user_id, f.conv_id, f.message_id, f.created_at FROM g7_ch_files f JOIN g7_ch_messages m ON m.id = f.message_id WHERE ' . implode(' AND ', $where) . ' ORDER BY f.id DESC LIMIT 150');
    $st->execute($args);
    out(['ok' => true, 'files' => array_map(fn($f) => ['id' => (int)$f['id'], 'name' => $f['name'], 'size' => (int)$f['size'], 'mime' => $f['mime'], 'user_id' => (int)$f['user_id'], 'conv_id' => (int)$f['conv_id'], 'message_id' => (int)$f['message_id'], 'created_at' => $f['created_at'], 'url' => 'api.php?action=file&id=' . (int)$f['id']], $st->fetchAll(PDO::FETCH_ASSOC))]);
}

case 'locate': {
    // Enlace a un mensaje: en qué conversación está (si la puede leer)
    $q = $db->prepare('SELECT id, conv_id, parent_id FROM g7_ch_messages WHERE id = ?'); $q->execute([$int('id')]); $m = $q->fetch(PDO::FETCH_ASSOC);
    if (!$m || !($c = ch_conv((int)$m['conv_id'])) || !ch_can_read($c, $me)) fail('Ese mensaje no existe o no tienes acceso', 404);
    out(['ok' => true, 'conv' => (int)$m['conv_id'], 'id' => (int)($m['parent_id'] ?: $m['id'])]);
}

// ── Vínculos a clientes, contenidos, tareas y tickets ([[tipo:id]] en el texto; refs_lib.php) ──
// ── En qué trabaja una persona y sus tareas en curso (trabajo/user_work_lib.php), con los permisos de quien mira ──
case 'user_work': {
    $w = null;
    if (is_file(dirname(__DIR__) . '/trabajo/lib.php')) { try { require_once dirname(__DIR__) . '/trabajo/user_work_lib.php'; $w = wk_user_work(getDB(), $uid, $int('user')); } catch (Throwable $e) { $w = null; } }
    out(['ok' => true, 'work' => $w]);
}
case 'ref_kinds': {
    require_once __DIR__ . '/refs_lib.php';
    $ks = ch_ref_kinds($uid); if (!empty($in['only'])) $ks = array_values(array_intersect($ks, (array)$in['only']));
    out(['ok' => true, 'kinds' => array_map(fn($k) => ['kind' => $k] + CH_REF_KINDS[$k], $ks)]);
}
case 'ref_search': {
    require_once __DIR__ . '/refs_lib.php';
    $q = (string)($in['q'] ?? ''); $k = (string)($in['kind'] ?? ''); $only = array_values(array_filter((array)($in['only'] ?? []), 'is_string'));   // only: limitar tipos (el @ busca clientes y tickets)
    $items = $k !== '' || !$only ? ch_refs_search(getDB(), $uid, $q, $k) : array_merge(...array_map(fn($x) => ch_refs_search(getDB(), $uid, $q, $x), array_slice($only, 0, 4)));
    out(['ok' => true, 'items' => $items]);
}
case 'ref_resolve': {
    // Solo devuelve el título de lo que QUIEN LEE puede ver: lo demás vuelve como "Sin acceso"
    require_once __DIR__ . '/refs_lib.php';
    out(['ok' => true, 'refs' => ch_refs_resolve(getDB(), $uid, (array)($in['refs'] ?? []))]);
}

// ── Burbuja de la intranet: solo el total sin leer ──────────────
case 'unread_total': {
    $n = 0;
    foreach (ch_conv_summaries($uid) as $c) if (!$c['muted']) $n += $c['unread'];
    out(['ok' => true, 'unread' => $n]);
}

default: fail('Acción desconocida');
}
