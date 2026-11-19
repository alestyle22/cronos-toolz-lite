<?php
/**
 * bienestar/lib_retos.php — Retos semanales (individuales o colectivos) y programas de bienestar.
 * Participar es voluntario: cada persona se suma, carga su avance y puede salir (y se borra lo suyo). Se priorizan los
 * objetivos colectivos (la suma de todos). El ranking es opcional (lo prende quien crea el reto, si Admin lo permite) y
 * cada persona elige si aparece. Quien gestiona ve participación y progreso agregados de lo que está en su alcance.
 * Doc: toolz/BIENESTAR.md
 */
const BN_CH_KINDS = ['colectivo' => 'Colectivo', 'individual' => 'Individual'];

function bn_ch_row(PDO $db, int $id): array {
    $q = $db->prepare('SELECT * FROM g7_bn_challenges WHERE id = ?'); $q->execute([$id]);
    $c = $q->fetch(PDO::FETCH_ASSOC);
    if (!$c) bn_fail('Ese reto no está.', 404);
    return $c;
}
/** Un reto que la persona puede ver (publicado y para su equipo); si no, 404 (no se dice que existe). */
function bn_ch_visible(PDO $db, array $ctx, int $id): array {
    $c = bn_ch_row($db, $id);
    if ($c['status'] !== 'published' || !bn_aud_has(bn_aud_decode($c['audience']), $ctx)) bn_fail('Ese reto no está.', 404);
    return $c;
}
function bn_ch_state(array $c, string $today): string { return $c['start_date'] > $today ? 'upcoming' : ($c['end_date'] < $today ? 'ended' : 'active'); }
function bn_ch_img(string $p): string { return $p !== '' ? '/toolz/bienestar/' . $p : ''; }

/** Retos visibles para la persona: los que vienen, los activos y los terminados hace menos de 30 días. */
function bn_ch_list(PDO $db, array $ctx, ?int $now = null): array {
    $today = bn_today($now);
    $q = $db->prepare("SELECT * FROM g7_bn_challenges WHERE status = 'published' AND end_date >= ? ORDER BY start_date <= ? DESC, end_date, id");
    $q->execute([date('Y-m-d', strtotime("$today -30 days")), $today]);
    $rows = array_values(array_filter($q->fetchAll(PDO::FETCH_ASSOC), fn($c) => bn_aud_has(bn_aud_decode($c['audience']), $ctx)));
    if (!$rows) return [];
    $ids = implode(',', array_map(fn($c) => (int)$c['id'], $rows));
    $agg = []; $mine = [];
    foreach ($db->query("SELECT challenge_id, COUNT(*) n, COALESCE(SUM(progress), 0) total, SUM(done_at IS NOT NULL) done FROM g7_bn_ch_members WHERE challenge_id IN ($ids) GROUP BY challenge_id") as $r) $agg[(int)$r['challenge_id']] = $r;
    $q = $db->prepare("SELECT * FROM g7_bn_ch_members WHERE challenge_id IN ($ids) AND user_id = ?"); $q->execute([$ctx['uid']]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $mine[(int)$r['challenge_id']] = $r;
    $rankOk = bn_flag('bn_rankings');
    $out = [];
    foreach ($rows as $c) {
        $id = (int)$c['id']; $a = $agg[$id] ?? ['n' => 0, 'total' => 0, 'done' => 0]; $m = $mine[$id] ?? null;
        $state = bn_ch_state($c, $today);
        $rank = [];
        if ($rankOk && (int)$c['ranking'] && (int)$a['n'] > 0) {
            $rq = $db->prepare('SELECT m.user_id, m.progress, u.username, u.display_name, u.avatar FROM g7_bn_ch_members m JOIN g7_users u ON u.id = m.user_id WHERE m.challenge_id = ? AND m.in_ranking = 1 AND m.progress > 0 ORDER BY m.progress DESC, m.joined_at LIMIT 5');
            $rq->execute([$id]);
            foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $i => $r) $rank[] = ['pos' => $i + 1, 'name' => (string)($r['display_name'] ?: $r['username']), 'avatar' => (string)$r['avatar'], 'progress' => (int)$r['progress'], 'me' => (int)$r['user_id'] === $ctx['uid']];
        }
        $goalT = (int)$c['goal_total']; $goalP = (int)$c['goal_person'];
        $out[] = ['id' => $id, 'title' => $c['title'], 'description' => $c['description'], 'image' => bn_ch_img($c['image']), 'icon' => $c['icon'], 'color' => $c['color'],
            'kind' => $c['kind'], 'unit' => $c['unit'], 'goal_person' => $goalP, 'goal_total' => $goalT, 'start_date' => $c['start_date'], 'end_date' => $c['end_date'],
            'reward' => $c['reward'], 'state' => $state, 'days_left' => $state === 'ended' ? 0 : (int)round((strtotime($c['end_date']) - strtotime($today)) / 86400) + 1,
            'participants' => (int)$a['n'], 'total' => (int)$a['total'], 'pct_total' => $goalT ? min(100, (int)floor((int)$a['total'] / $goalT * 100)) : null,
            'goal_hit' => $c['goal_hit_at'] !== null, 'joined' => (bool)$m, 'my_progress' => $m ? (int)$m['progress'] : 0,
            'pct_mine' => $m && $goalP ? min(100, (int)floor((int)$m['progress'] / $goalP * 100)) : null, 'my_done' => $m && $m['done_at'] !== null,
            'in_ranking' => $m ? (bool)$m['in_ranking'] : true, 'ranking' => $rankOk && (int)$c['ranking'], 'top' => $rank, 'program_id' => $c['program_id'] ? (int)$c['program_id'] : null];
    }
    return $out;
}
function bn_ch_join(PDO $db, array $ctx, int $id, bool $inRanking = true, ?int $now = null): void {
    $c = bn_ch_visible($db, $ctx, $id);
    if (bn_ch_state($c, bn_today($now)) === 'ended') bn_fail('Ese reto ya terminó.');
    $db->prepare('INSERT INTO g7_bn_ch_members (challenge_id, user_id, in_ranking, joined_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE in_ranking = VALUES(in_ranking)')
       ->execute([$id, $ctx['uid'], $inRanking ? 1 : 0, date('Y-m-d H:i:s', bn_now($now))]);
}
/** Salir del reto borra lo cargado por la persona (participación voluntaria: no queda nada suyo). */
function bn_ch_leave(PDO $db, array $ctx, int $id): void {
    bn_ch_visible($db, $ctx, $id);
    $db->prepare('DELETE FROM g7_bn_ch_log WHERE challenge_id = ? AND user_id = ?')->execute([$id, $ctx['uid']]);
    $db->prepare('DELETE FROM g7_bn_ch_members WHERE challenge_id = ? AND user_id = ?')->execute([$id, $ctx['uid']]);
}
/** Registra un avance. Devuelve el reto actualizado e insignias nuevas. */
function bn_ch_log(PDO $db, array $ctx, int $id, int $amount, string $note = '', ?int $now = null): array {
    $c = bn_ch_visible($db, $ctx, $id);
    $today = bn_today($now);
    $st = bn_ch_state($c, $today);
    if ($st === 'upcoming') bn_fail('El reto todavía no empezó: arranca el ' . date('d/m', strtotime($c['start_date'])) . '.');
    if ($st === 'ended') bn_fail('Ese reto ya terminó.');
    $q = $db->prepare('SELECT * FROM g7_bn_ch_members WHERE challenge_id = ? AND user_id = ?'); $q->execute([$id, $ctx['uid']]);
    $m = $q->fetch(PDO::FETCH_ASSOC);
    if (!$m) bn_fail('Primero súmate al reto.');
    if ($amount < 1 || $amount > 100000) bn_fail('Carga un número entre 1 y 100.000.');
    $note = bn_str($note, 140, 0, 'La nota');
    $at = date('Y-m-d H:i:s', bn_now($now));
    $db->prepare('INSERT INTO g7_bn_ch_log (challenge_id, user_id, day, amount, note, created_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $ctx['uid'], $today, $amount, $note, $at]);
    $db->prepare('UPDATE g7_bn_ch_members SET progress = progress + ? WHERE challenge_id = ? AND user_id = ?')->execute([$amount, $id, $ctx['uid']]);
    $badges = [];
    $prog = (int)$m['progress'] + $amount;
    if ((int)$c['goal_person'] > 0 && $prog >= (int)$c['goal_person'] && $m['done_at'] === null) {
        $db->prepare('UPDATE g7_bn_ch_members SET done_at = ? WHERE challenge_id = ? AND user_id = ?')->execute([$at, $id, $ctx['uid']]);
        if (bn_badge($db, $ctx['uid'], 'challenge_done', $id, $now)) $badges[] = 'challenge_done';
    }
    if ((int)$c['goal_total'] > 0 && $c['goal_hit_at'] === null) {
        $sum = (int)$db->query('SELECT COALESCE(SUM(progress), 0) FROM g7_bn_ch_members WHERE challenge_id = ' . $id)->fetchColumn();
        if ($sum >= (int)$c['goal_total']) {
            // "goal_hit_at IS NULL" en el UPDATE evita dos festejos si dos personas cargan a la vez: solo uno cambia la fila
            $up =$db->prepare('UPDATE g7_bn_challenges SET goal_hit_at = ? WHERE id = ? AND goal_hit_at IS NULL'); $up->execute([$at, $id]);
            if ($up->rowCount()) {
                $members = array_map('intval', $db->query('SELECT user_id FROM g7_bn_ch_members WHERE challenge_id = ' . $id)->fetchAll(PDO::FETCH_COLUMN));
                foreach ($members as $u2) if (bn_badge($db, $u2, 'team_goal', $id, $now) && $u2 === $ctx['uid']) $badges[] = 'team_goal';
                bn_notify($db, $members, null, 'bn_challenge', $id, '¡Lo lograron! El reto "' . $c['title'] . '" llegó a la meta entre todos 🎉', 'challenge_end');
            }
        }
    }
    $view = array_values(array_filter(bn_ch_list($db, $ctx, $now), fn($x) => $x['id'] === $id))[0] ?? null;
    return ['challenge' => $view, 'badges' => array_map(fn($b) => ['code' => $b, 'emoji' => BN_BADGES[$b][0], 'label' => BN_BADGES[$b][1]], array_unique($badges))];
}
/** Mis avances en un reto (para corregir el último). */
function bn_ch_mylog(PDO $db, array $ctx, int $id): array {
    bn_ch_visible($db, $ctx, $id);
    $q = $db->prepare('SELECT id, day, amount, note FROM g7_bn_ch_log WHERE challenge_id = ? AND user_id = ? ORDER BY id DESC LIMIT 30'); $q->execute([$id, $ctx['uid']]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'day' => $r['day'], 'amount' => (int)$r['amount'], 'note' => $r['note']], $q->fetchAll(PDO::FETCH_ASSOC));
}
/** Borra un avance propio de un reto que sigue activo. */
function bn_ch_log_delete(PDO $db, array $ctx, int $logId, ?int $now = null): void {
    $q = $db->prepare('SELECT * FROM g7_bn_ch_log WHERE id = ? AND user_id = ?'); $q->execute([$logId, $ctx['uid']]);
    $l = $q->fetch(PDO::FETCH_ASSOC);
    if (!$l) bn_fail('Ese avance no está.', 404);
    $c = bn_ch_visible($db, $ctx, (int)$l['challenge_id']);
    if (bn_ch_state($c, bn_today($now)) === 'ended') bn_fail('El reto ya terminó: no se puede cambiar.');
    $db->prepare('DELETE FROM g7_bn_ch_log WHERE id = ?')->execute([$logId]);
    $db->prepare('UPDATE g7_bn_ch_members SET progress = GREATEST(0, progress - ?), done_at = IF(? > 0 AND GREATEST(0, progress - ?) < ?, NULL, done_at) WHERE challenge_id = ? AND user_id = ?')
       ->execute([(int)$l['amount'], (int)$c['goal_person'], (int)$l['amount'], (int)$c['goal_person'], (int)$l['challenge_id'], $ctx['uid']]);
}

// ── Gestión ──
/** Crea o edita un reto (queda en borrador hasta publicarlo). */
function bn_ch_save(PDO $db, array $ctx, array $in, ?int $now = null): int {
    bn_need_manage($ctx);
    $id = (int)($in['id'] ?? 0);
    if ($id) { $old = bn_ch_row($db, $id); if (!bn_aud_can(bn_aud_decode($old['audience']), $ctx)) bn_fail('No puedes editar ese reto.', 403); }
    $kind = isset(BN_CH_KINDS[$in['kind'] ?? '']) ? $in['kind'] : 'colectivo';
    $gp = max(0, min(10000000, (int)($in['goal_person'] ?? 0))); $gt = max(0, min(100000000, (int)($in['goal_total'] ?? 0)));
    if ($kind === 'individual' && !$gp) bn_fail('Pon el objetivo de cada persona.');
    if ($kind === 'colectivo' && !$gt) bn_fail('Pon la meta del grupo (la suma de todos).');
    $s = bn_date($in['start_date'] ?? ''); $e = bn_date($in['end_date'] ?? '');
    if (!$s || !$e) bn_fail('Elige las fechas de inicio y de fin.');
    if ($e < $s) bn_fail('El fin no puede ser antes del inicio.');
    if ((strtotime($e) - strtotime($s)) / 86400 > 366) bn_fail('Un reto puede durar hasta un año.');
    $aud = bn_aud_clean($db, $in['audience'] ?? [], $ctx);
    $img = (string)($in['image'] ?? '');
    if ($img !== '' && !preg_match('#^files/ch_[a-f0-9]{16}\.(jpg|png|webp)$#', $img)) $img = '';
    $prog = (int)($in['program_id'] ?? 0) ?: null;
    if ($prog) bn_program_row($db, $ctx, $prog, true);
    $vals = [$prog, bn_str($in['title'] ?? '', 120, 3, 'El título'), bn_str($in['description'] ?? '', 1500, 0, 'La descripción'), $img, bn_icon($in['icon'] ?? '', 'fa-person-running'),
        bn_color($in['color'] ?? '', '#3b82f6'), $kind, bn_str($in['unit'] ?? 'veces', 30, 1, 'La unidad'), $gp, $gt, $s, $e, bn_aud_encode($aud),
        bn_str($in['reward'] ?? '', 200, 0, 'La recompensa'), empty($in['ranking']) ? 0 : 1, date('Y-m-d H:i:s', bn_now($now))];
    if ($id) $db->prepare('UPDATE g7_bn_challenges SET program_id = ?, title = ?, description = ?, image = ?, icon = ?, color = ?, kind = ?, unit = ?, goal_person = ?, goal_total = ?, start_date = ?, end_date = ?, audience = ?, reward = ?, ranking = ?, updated_at = ? WHERE id = ?')->execute([...$vals, $id]);
    else { $db->prepare('INSERT INTO g7_bn_challenges (program_id, title, description, image, icon, color, kind, unit, goal_person, goal_total, start_date, end_date, audience, reward, ranking, updated_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$vals, $ctx['uid'], $vals[15]]); $id = (int)$db->lastInsertId(); }
    bn_audit($ctx, 'challenge_save', (string)$id, ['title' => $vals[1], 'audience' => $vals[12]]);
    return $id;
}
/** publish | archive | delete (borrar solo si nadie se sumó). Publicar un reto que ya empezó avisa al público en el momento. */
function bn_ch_status(PDO $db, array $ctx, int $id, string $to, ?int $now = null): void {
    bn_need_manage($ctx);
    $c = bn_ch_row($db, $id);
    if (!bn_aud_can(bn_aud_decode($c['audience']), $ctx)) bn_fail('No puedes cambiar ese reto.', 403);
    if ($to === 'delete') {
        if ((int)$db->query('SELECT COUNT(*) FROM g7_bn_ch_members WHERE challenge_id = ' . $id)->fetchColumn()) bn_fail('Ya hay personas en este reto: archívalo en lugar de borrarlo.');
        $db->prepare('DELETE FROM g7_bn_challenges WHERE id = ?')->execute([$id]);
    } elseif (in_array($to, ['published', 'archived', 'draft'], true)) {
        $db->prepare('UPDATE g7_bn_challenges SET status = ?, updated_at = ? WHERE id = ?')->execute([$to, date('Y-m-d H:i:s', bn_now($now)), $id]);
        if ($to === 'published') bn_ch_sweep($db, bn_now($now));
    } else bn_fail('Acción inválida.');
    bn_audit($ctx, 'challenge_' . $to, (string)$id, ['title' => $c['title']]);
}
/** Lo que gestiona la persona, con participación y progreso agregados. */
function bn_ch_manage(PDO $db, array $ctx, ?int $now = null): array {
    bn_need_manage($ctx);
    $today = bn_today($now);
    $rows = array_values(array_filter($db->query("SELECT * FROM g7_bn_challenges ORDER BY status = 'archived', start_date DESC, id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC),
        fn($c) => bn_aud_can(bn_aud_decode($c['audience']), $ctx)));
    $out = [];
    foreach ($rows as $c) {
        $id = (int)$c['id'];
        $a = $db->query("SELECT COUNT(*) n, COALESCE(SUM(progress), 0) total, SUM(done_at IS NOT NULL) done FROM g7_bn_ch_members WHERE challenge_id = $id")->fetch(PDO::FETCH_ASSOC);
        $act = (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM g7_bn_ch_log WHERE challenge_id = $id AND day >= '" . date('Y-m-d', strtotime("$today -6 days")) . "'")->fetchColumn();
        $aud = bn_aud_decode($c['audience']); $size = count(bn_users_of($db, $aud));
        $out[] = ['id' => $id, 'title' => $c['title'], 'description' => $c['description'], 'image' => $c['image'], 'image_url' => bn_ch_img($c['image']), 'icon' => $c['icon'], 'color' => $c['color'],
            'kind' => $c['kind'], 'unit' => $c['unit'], 'goal_person' => (int)$c['goal_person'], 'goal_total' => (int)$c['goal_total'], 'start_date' => $c['start_date'], 'end_date' => $c['end_date'],
            'audience' => $aud ?? [], 'reward' => $c['reward'], 'ranking' => (bool)$c['ranking'], 'status' => $c['status'], 'state' => bn_ch_state($c, $today), 'program_id' => $c['program_id'] ? (int)$c['program_id'] : null,
            'stats' => ['audience' => $size, 'participants' => (int)$a['n'], 'participation' => $size ? (int)round((int)$a['n'] / $size * 100) : 0, 'total' => (int)$a['total'],
                'pct_total' => (int)$c['goal_total'] ? min(100, (int)floor((int)$a['total'] / (int)$c['goal_total'] * 100)) : null, 'completed' => (int)$a['done'], 'active7' => $act]];
    }
    return $out;
}
/** Imagen de un reto: jpg, png o webp de hasta 3 MB. Se guarda con nombre al azar en bienestar/files/ (sin ejecutar código). */
function bn_ch_upload(array $ctx, $f): string {
    bn_need_manage($ctx);
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) bn_fail(function_exists('g7_upload_problem') && ($p = g7_upload_problem($f)) ? $p : 'No llegó ninguna imagen.');
    if ((int)$f['size'] > 3 * 1048576) bn_fail('La imagen puede pesar hasta 3 MB.');
    $info = @getimagesize($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if (!$ext) bn_fail('Sube una imagen JPG, PNG o WebP.');
    $dir = __DIR__ . '/files';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "# Solo imágenes: nada de esta carpeta se ejecuta\n<FilesMatch \"\\.(php\\d?|phtml|phar|cgi|pl|py|sh)$\">\n  Require all denied\n</FilesMatch>\nphp_flag engine off\n");
    $name = 'ch_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $ok = is_uploaded_file($f['tmp_name']) ? @move_uploaded_file($f['tmp_name'], "$dir/$name") : @copy($f['tmp_name'], "$dir/$name");
    if (!$ok) bn_fail('El servidor no pudo guardar la imagen.', 500);
    bn_audit($ctx, 'challenge_image', $name);
    return 'files/' . $name;
}
/** Avisos automáticos: reto que empieza, que está por terminar y que terminó (con el resultado). */
function bn_ch_sweep(PDO $db, ?int $now = null): void {
    $today = bn_today($now);
    foreach ($db->query("SELECT * FROM g7_bn_challenges WHERE status = 'published' AND (n_start = 0 OR n_soon = 0 OR n_end = 0)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $id = (int)$c['id'];
        $members = fn() => array_map('intval', $db->query("SELECT user_id FROM g7_bn_ch_members WHERE challenge_id = $id")->fetchAll(PDO::FETCH_COLUMN));
        if (!(int)$c['n_start'] && $c['start_date'] <= $today && $c['end_date'] >= $today) {
            $db->prepare('UPDATE g7_bn_challenges SET n_start = 1 WHERE id = ?')->execute([$id]);
            bn_notify($db, bn_users_of($db, bn_aud_decode($c['audience'])), (int)$c['created_by'] ?: null, 'bn_challenge', $id, 'Nuevo reto: ' . $c['title'] . ($c['kind'] === 'colectivo' ? ' (entre todos)' : ''), 'challenges');
        }
        if (!(int)$c['n_soon'] && $c['end_date'] >= $today && $c['end_date'] <= date('Y-m-d', strtotime("$today +2 days")) && $c['start_date'] < $today) {
            $db->prepare('UPDATE g7_bn_challenges SET n_soon = 1 WHERE id = ?')->execute([$id]);
            $left = (int)round((strtotime($c['end_date']) - strtotime($today)) / 86400) + 1;
            bn_notify($db, $members(), null, 'bn_challenge', $id, 'Al reto "' . $c['title'] . '" le ' . ($left === 1 ? 'queda 1 día' : "quedan $left días") . '. ¡Último empujón!', 'challenge_end');
        }
        if (!(int)$c['n_end'] && $c['end_date'] < $today) {
            $db->prepare('UPDATE g7_bn_challenges SET n_end = 1, n_start = 1, n_soon = 1 WHERE id = ?')->execute([$id]);
            $sum = (int)$db->query("SELECT COALESCE(SUM(progress), 0) FROM g7_bn_ch_members WHERE challenge_id = $id")->fetchColumn();
            $res = (int)$c['goal_total'] ? ($sum >= (int)$c['goal_total'] ? '¡llegaron a la meta!' : "sumaron $sum de " . (int)$c['goal_total'] . ' ' . $c['unit'] . '.') : 'gracias por participar.';
            bn_notify($db, $members(), null, 'bn_challenge', $id, 'Terminó el reto "' . $c['title'] . '": ' . $res, 'challenge_end');
        }
    }
}

// ── Programas ──
function bn_program_row(PDO $db, array $ctx, int $id, bool $forManage = false): array {
    $q = $db->prepare('SELECT * FROM g7_bn_programs WHERE id = ?'); $q->execute([$id]);
    $p = $q->fetch(PDO::FETCH_ASSOC);
    if (!$p || ($forManage && !bn_aud_can(bn_aud_decode($p['audience']), $ctx))) bn_fail('Ese programa no está.', 404);
    return $p;
}
function bn_program_view(PDO $db, array $p): array {
    $id = (int)$p['id'];
    return ['id' => $id, 'title' => $p['title'], 'description' => $p['description'], 'icon' => $p['icon'], 'color' => $p['color'], 'start_date' => $p['start_date'], 'end_date' => $p['end_date'],
        'audience' => bn_aud_decode($p['audience']) ?? [], 'active' => (bool)$p['active'],
        'n_challenges' => (int)$db->query("SELECT COUNT(*) FROM g7_bn_challenges WHERE program_id = $id AND status = 'published'")->fetchColumn(),
        'n_surveys' => (int)$db->query("SELECT COUNT(*) FROM g7_bn_surveys WHERE program_id = $id AND status = 'published'")->fetchColumn()];
}
function bn_programs_active(PDO $db, array $ctx, ?int $now = null): array {
    $today = bn_today($now);
    $q = $db->prepare('SELECT * FROM g7_bn_programs WHERE active = 1 AND (start_date IS NULL OR start_date <= ?) AND (end_date IS NULL OR end_date >= ?) ORDER BY id DESC LIMIT 6');
    $q->execute([$today, $today]);
    return array_map(fn($p) => bn_program_view($db, $p), array_values(array_filter($q->fetchAll(PDO::FETCH_ASSOC), fn($p) => bn_aud_has(bn_aud_decode($p['audience']), $ctx))));
}
function bn_programs_manage(PDO $db, array $ctx): array {
    bn_need_manage($ctx);
    return array_map(fn($p) => bn_program_view($db, $p), array_values(array_filter($db->query('SELECT * FROM g7_bn_programs ORDER BY active DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC), fn($p) => bn_aud_can(bn_aud_decode($p['audience']), $ctx))));
}
function bn_program_save(PDO $db, array $ctx, array $in, ?int $now = null): int {
    bn_need_manage($ctx);
    $id = (int)($in['id'] ?? 0);
    if ($id) bn_program_row($db, $ctx, $id, true);
    $s = ($in['start_date'] ?? '') === '' ? null : bn_date($in['start_date']); $e = ($in['end_date'] ?? '') === '' ? null : bn_date($in['end_date']);
    if (($in['start_date'] ?? '') !== '' && !$s || ($in['end_date'] ?? '') !== '' && !$e) bn_fail('Fecha inválida.');
    if ($s && $e && $e < $s) bn_fail('El fin no puede ser antes del inicio.');
    $at = date('Y-m-d H:i:s', bn_now($now));
    $vals = [bn_str($in['title'] ?? '', 120, 3, 'El nombre'), bn_str($in['description'] ?? '', 600, 0, 'La descripción'), bn_icon($in['icon'] ?? '', 'fa-seedling'), bn_color($in['color'] ?? ''),
        $s, $e, bn_aud_encode(bn_aud_clean($db, $in['audience'] ?? [], $ctx)), isset($in['active']) && !$in['active'] ? 0 : 1, $at];
    if ($id) $db->prepare('UPDATE g7_bn_programs SET title = ?, description = ?, icon = ?, color = ?, start_date = ?, end_date = ?, audience = ?, active = ?, updated_at = ? WHERE id = ?')->execute([...$vals, $id]);
    else { $db->prepare('INSERT INTO g7_bn_programs (title, description, icon, color, start_date, end_date, audience, active, updated_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$vals, $ctx['uid'], $at]); $id = (int)$db->lastInsertId(); }
    bn_audit($ctx, 'program_save', (string)$id, ['title' => $vals[0]]);
    return $id;
}
function bn_program_delete(PDO $db, array $ctx, int $id): void {
    bn_program_row($db, $ctx, $id, true);
    $db->prepare('UPDATE g7_bn_challenges SET program_id = NULL WHERE program_id = ?')->execute([$id]);
    $db->prepare('UPDATE g7_bn_surveys SET program_id = NULL WHERE program_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM g7_bn_programs WHERE id = ?')->execute([$id]);
    bn_audit($ctx, 'program_delete', (string)$id);
}
