<?php
/**
 * bienestar/lib_encuestas.php — Clima laboral: constructor de encuestas, respuestas y resultados agregados.
 * Preguntas de escala (1-5 o 1-10), opción única, opción múltiple y abiertas. Público: toda la organización o equipos.
 * Periodicidad: al cerrar una encuesta periódica se crea sola la siguiente, y los resultados muestran la evolución.
 * Privacidad: en las anónimas la respuesta no guarda quién respondió, ni la hora, y su id es al azar (no se puede ordenar
 * por llegada y cruzarla con "quién ya respondió"). Los resultados de una anónima (y de cada equipo) se ven solo con el
 * mínimo de respuestas. Las sensibles son siempre anónimas. Doc: toolz/BIENESTAR.md
 */
const BN_SV_CATS = ['ambiente' => 'Ambiente', 'motivacion' => 'Motivación', 'satisfaccion' => 'Satisfacción', 'comunicacion' => 'Comunicación',
    'relaciones' => 'Relaciones', 'equilibrio' => 'Equilibrio trabajo-vida', 'carga' => 'Carga percibida', 'otra' => 'Otra'];
const BN_SV_TYPES = ['scale' => 'Escala', 'single' => 'Opción única', 'multi' => 'Opción múltiple', 'text' => 'Respuesta abierta'];
const BN_SV_RECUR = ['none' => 'Una sola vez', 'weekly' => 'Cada semana', 'monthly' => 'Cada mes', 'quarterly' => 'Cada tres meses'];
const BN_SV_TEMPLATES = [
    'clima' => ['title' => 'Pulso de clima', 'category' => 'ambiente', 'intro' => 'Cinco preguntas rápidas sobre cómo te sientes en el equipo. Es anónima.', 'questions' => [
        ['type' => 'scale', 'text' => '¿Qué tan a gusto te sientes trabajando en el equipo?', 'scale_max' => 5],
        ['type' => 'scale', 'text' => '¿Te sientes motivado con tu trabajo?', 'scale_max' => 5],
        ['type' => 'scale', 'text' => '¿La comunicación en el equipo es clara?', 'scale_max' => 5],
        ['type' => 'single', 'text' => '¿Recomendarías este lugar a un amigo para trabajar?', 'options' => ['Sí', 'Tal vez', 'No']],
        ['type' => 'text', 'text' => '¿Qué es lo que más ayudaría a mejorar el día a día?', 'required' => false]]],
    'carga' => ['title' => 'Encuesta de saturación', 'category' => 'carga', 'intro' => 'Queremos saber cómo viene la carga para repartirla mejor. No se usa para evaluar a nadie.', 'questions' => [
        ['type' => 'scale', 'text' => '¿Cómo calificarías tu carga de trabajo de las últimas semanas? (1 = muy liviana, 5 = desbordada)', 'scale_max' => 5],
        ['type' => 'scale', 'text' => '¿Te alcanza la jornada para terminar lo que tienes asignado?', 'scale_max' => 5],
        ['type' => 'multi', 'text' => '¿Qué es lo que más suma carga?', 'options' => ['Reuniones', 'Urgencias', 'Interrupciones', 'Tareas poco claras', 'Falta de herramientas', 'Otra cosa']],
        ['type' => 'text', 'text' => '¿Qué cambiarías para trabajar más tranquilo?', 'required' => false]]],
    'equilibrio' => ['title' => 'Equilibrio trabajo-vida', 'category' => 'equilibrio', 'intro' => 'Cómo convive el trabajo con el resto de tu vida. Es anónima.', 'questions' => [
        ['type' => 'scale', 'text' => '¿Puedes desconectarte al terminar tu jornada?', 'scale_max' => 5],
        ['type' => 'scale', 'text' => '¿Tienes tiempo para descansar y para tus cosas?', 'scale_max' => 5],
        ['type' => 'single', 'text' => '¿Con qué frecuencia trabajas fuera de horario?', 'options' => ['Nunca', 'A veces', 'Seguido', 'Casi siempre']],
        ['type' => 'text', 'text' => '¿Qué te ayudaría a tener más equilibrio?', 'required' => false]]],
];

function bn_sv_row(PDO $db, int $id): array {
    $q = $db->prepare('SELECT * FROM g7_bn_surveys WHERE id = ?'); $q->execute([$id]);
    $s = $q->fetch(PDO::FETCH_ASSOC);
    if (!$s) bn_fail('Esa encuesta no está.', 404);
    return $s;
}
function bn_sv_questions(PDO $db, int $sid): array {
    $q = $db->prepare('SELECT * FROM g7_bn_sv_questions WHERE survey_id = ? ORDER BY pos, id'); $q->execute([$sid]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'type' => $r['type'], 'text' => $r['text'], 'options' => json_decode((string)$r['options'], true) ?: [],
        'required' => (bool)$r['required'], 'scale_max' => (int)$r['scale_max']], $q->fetchAll(PDO::FETCH_ASSOC));
}
function bn_sv_state(array $s, string $today): string {
    if ($s['status'] === 'draft') return 'draft';
    if ($s['status'] === 'closed' || $s['end_date'] < $today) return 'closed';
    return $s['start_date'] > $today ? 'scheduled' : 'open';
}
function bn_sv_view(PDO $db, array $s, ?string $today = null): array {
    return ['id' => (int)$s['id'], 'title' => $s['title'], 'intro' => $s['intro'], 'category' => $s['category'], 'category_label' => BN_SV_CATS[$s['category']] ?? 'Otra',
        'anonymous' => (bool)$s['anonymous'], 'sensitive' => (bool)$s['is_sensitive'], 'start_date' => $s['start_date'], 'end_date' => $s['end_date'],
        'recurrence' => $s['recurrence'], 'status' => $s['status'], 'state' => bn_sv_state($s, $today ?? bn_today()), 'audience' => bn_aud_decode($s['audience']) ?? [],
        'program_id' => $s['program_id'] ? (int)$s['program_id'] : null, 'series_id' => (int)($s['series_id'] ?: $s['id']),
        'n_questions' => (int)$db->query('SELECT COUNT(*) FROM g7_bn_sv_questions WHERE survey_id = ' . (int)$s['id'])->fetchColumn()];
}
/** Encuestas de la persona: abiertas para su equipo, con si ya respondió. */
function bn_sv_mine(PDO $db, array $ctx, ?int $now = null): array {
    $today = bn_today($now);
    $q = $db->prepare("SELECT s.*, d.day done_day FROM g7_bn_surveys s LEFT JOIN g7_bn_sv_done d ON d.survey_id = s.id AND d.user_id = ?
        WHERE s.status IN ('published', 'closed') AND s.start_date <= ? AND s.end_date >= ? ORDER BY s.end_date, s.id");
    $q->execute([$ctx['uid'], $today, date('Y-m-d', strtotime("$today -60 days"))]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $s) {
        if (!bn_aud_has(bn_aud_decode($s['audience']), $ctx)) continue;
        $v = bn_sv_view($db, $s, $today);
        if ($v['state'] === 'closed' && !$s['done_day']) continue;   // vencida sin responder: ya no se muestra
        $out[] = $v + ['done' => (bool)$s['done_day']];
    }
    return $out;
}
function bn_sv_pending(PDO $db, array $ctx, ?int $now = null): array { return array_values(array_filter(bn_sv_mine($db, $ctx, $now), fn($s) => !$s['done'] && $s['state'] === 'open')); }
/** Para responder: la encuesta tiene que estar abierta y ser para el equipo de la persona. */
function bn_sv_for_answer(PDO $db, array $ctx, int $id, ?int $now = null): array {
    $s = bn_sv_row($db, $id);
    if ($s['status'] === 'draft' || !bn_aud_has(bn_aud_decode($s['audience']), $ctx)) bn_fail('Esa encuesta no está.', 404);
    $v = bn_sv_view($db, $s, bn_today($now));
    $q = $db->prepare('SELECT 1 FROM g7_bn_sv_done WHERE survey_id = ? AND user_id = ?'); $q->execute([$id, $ctx['uid']]);
    return $v + ['questions' => bn_sv_questions($db, $id), 'done' => (bool)$q->fetchColumn()];
}
/** Guarda una respuesta. $ans: [id de pregunta => valor]. */
function bn_sv_submit(PDO $db, array $ctx, int $id, array $ans, ?int $now = null): void {
    $s = bn_sv_for_answer($db, $ctx, $id, $now);
    if ($s['state'] !== 'open') bn_fail($s['state'] === 'scheduled' ? 'Esta encuesta todavía no abrió.' : 'Esta encuesta ya cerró.');
    if ($s['done']) bn_fail('Ya respondiste esta encuesta. ¡Gracias!');
    $rows = [];
    foreach ($s['questions'] as $qq) {
        $v = $ans[$qq['id']] ?? ($ans[(string)$qq['id']] ?? null);
        $empty = $v === null || $v === '' || $v === [];
        if ($empty) { if ($qq['required']) bn_fail('Falta responder: "' . mb_substr($qq['text'], 0, 60) . '"'); continue; }
        $num = null; $opts = null; $txt = null;
        if ($qq['type'] === 'scale') { $num = (int)$v; if ($num < 1 || $num > $qq['scale_max'] || (string)$num !== (string)$v && !is_int($v)) bn_fail('Respuesta fuera de la escala.'); }
        elseif ($qq['type'] === 'single') { $num = (int)$v; if (!is_numeric($v) || $num < 0 || $num >= count($qq['options'])) bn_fail('Opción inválida.'); }
        elseif ($qq['type'] === 'multi') {
            $sel = array_values(array_unique(array_map('intval', (array)$v)));
            foreach ($sel as $o) if ($o < 0 || $o >= count($qq['options'])) bn_fail('Opción inválida.');
            sort($sel); $opts = implode(',', $sel);
        } else $txt = bn_str($v, 1000, 0, 'La respuesta');
        $rows[] = [$qq['id'], $num, $opts, $txt];
    }
    if (!$rows) bn_fail('Responde al menos una pregunta.');
    $db->beginTransaction();
    try {
        $d = $db->prepare('INSERT INTO g7_bn_sv_done (survey_id, user_id, day) VALUES (?, ?, ?)');
        try { $d->execute([$id, $ctx['uid'], bn_today($now)]); } catch (PDOException $e) { bn_fail('Ya respondiste esta encuesta. ¡Gracias!'); }
        $rid = random_int(1, PHP_INT_MAX);
        $db->prepare('INSERT INTO g7_bn_sv_responses (id, survey_id, user_id, teams) VALUES (?, ?, ?, ?)')->execute([$rid, $id, $s['anonymous'] ? null : $ctx['uid'], implode(',', $ctx['teams'])]);
        $ins = $db->prepare('INSERT INTO g7_bn_sv_answers (response_id, question_id, num, opts, txt) VALUES (?, ?, ?, ?, ?)');
        foreach ($rows as $r) $ins->execute([$rid, ...$r]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    bn_badge($db, $ctx['uid'], 'survey_voice', 0, $now);
}

// ── Gestión ──
function bn_sv_can(array $ctx, array $s): bool { return bn_aud_can(bn_aud_decode($s['audience']), $ctx); }
function bn_sv_save(PDO $db, array $ctx, array $in, ?int $now = null): int {
    bn_need_manage($ctx);
    $id = (int)($in['id'] ?? 0); $has = 0;
    if ($id) { $old = bn_sv_row($db, $id); if (!bn_sv_can($ctx, $old)) bn_fail('No puedes editar esa encuesta.', 403); $has = (int)$db->query("SELECT COUNT(*) FROM g7_bn_sv_responses WHERE survey_id = $id")->fetchColumn(); }
    $s = bn_date($in['start_date'] ?? ''); $e = bn_date($in['end_date'] ?? '');
    if (!$s || !$e) bn_fail('Elige la fecha de publicación y la de vencimiento.');
    if ($e < $s) bn_fail('El vencimiento no puede ser antes de la publicación.');
    if ((strtotime($e) - strtotime($s)) / 86400 > 120) bn_fail('Una encuesta puede estar abierta hasta 120 días.');
    $sens = !empty($in['sensitive']);
    $anon = $sens || !isset($in['anonymous']) || !empty($in['anonymous']);   // anónima por defecto; las sensibles siempre
    $cat = isset(BN_SV_CATS[$in['category'] ?? '']) ? $in['category'] : 'ambiente';
    $rec = isset(BN_SV_RECUR[$in['recurrence'] ?? '']) ? $in['recurrence'] : 'none';
    $aud = bn_aud_encode(bn_aud_clean($db, $in['audience'] ?? [], $ctx));
    $prog = (int)($in['program_id'] ?? 0) ?: null;
    if ($prog) bn_program_row($db, $ctx, $prog, true);
    $title = bn_str($in['title'] ?? '', 140, 3, 'El título'); $intro = bn_str($in['intro'] ?? '', 800, 0, 'La presentación');
    $qs = [];
    foreach ((array)($in['questions'] ?? []) as $q) {
        $type = isset(BN_SV_TYPES[$q['type'] ?? '']) ? $q['type'] : null;
        if (!$type) bn_fail('Hay una pregunta sin tipo.');
        $text = bn_str($q['text'] ?? '', 300, 3, 'Cada pregunta');
        $opts = [];
        if ($type === 'single' || $type === 'multi') {
            foreach ((array)($q['options'] ?? []) as $o) { $o = bn_str($o, 80, 0, 'Cada opción'); if ($o !== '') $opts[] = $o; }
            if (count($opts) < 2 || count($opts) > 12) bn_fail('"' . mb_substr($text, 0, 40) . '": pon entre 2 y 12 opciones.');
            if (count(array_unique(array_map('mb_strtolower', $opts))) !== count($opts)) bn_fail('"' . mb_substr($text, 0, 40) . '": hay opciones repetidas.');
        }
        $qs[] = [$type, $text, $opts ? json_encode($opts, JSON_UNESCAPED_UNICODE) : null, isset($q['required']) && !$q['required'] ? 0 : 1, (int)($q['scale_max'] ?? 5) === 10 ? 10 : 5];
    }
    if (!$qs) bn_fail('Agrega al menos una pregunta.');
    if (count($qs) > 30) bn_fail('Una encuesta puede tener hasta 30 preguntas.');
    $at = date('Y-m-d H:i:s', bn_now($now));
    if ($id && $has) {
        // con respuestas no se cambian las preguntas ni el anonimato (cambiaría el sentido de lo ya respondido)
        if ($anon !== (bool)$old['anonymous']) bn_fail('La encuesta ya tiene respuestas: no se puede cambiar si es anónima.');
        $db->prepare('UPDATE g7_bn_surveys SET title = ?, intro = ?, end_date = ?, program_id = ?, updated_at = ? WHERE id = ?')->execute([$title, $intro, $e, $prog, $at, $id]);
        bn_audit($ctx, 'survey_save', (string)$id, ['title' => $title, 'with_answers' => true]);
        return $id;
    }
    $vals = [$prog, $title, $intro, $cat, $anon ? 1 : 0, $sens ? 1 : 0, $aud, $s, $e, $rec, $at];
    $db->beginTransaction();
    try {
        if ($id) { $db->prepare('UPDATE g7_bn_surveys SET program_id = ?, title = ?, intro = ?, category = ?, anonymous = ?, is_sensitive = ?, audience = ?, start_date = ?, end_date = ?, recurrence = ?, updated_at = ? WHERE id = ?')->execute([...$vals, $id]); $db->prepare('DELETE FROM g7_bn_sv_questions WHERE survey_id = ?')->execute([$id]); }
        else { $db->prepare('INSERT INTO g7_bn_surveys (program_id, title, intro, category, anonymous, is_sensitive, audience, start_date, end_date, recurrence, updated_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$vals, $ctx['uid'], $at]); $id = (int)$db->lastInsertId(); }
        $ins = $db->prepare('INSERT INTO g7_bn_sv_questions (survey_id, pos, type, text, options, required, scale_max) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($qs as $i => $q) $ins->execute([$id, $i, ...$q]);
        $db->commit();
    } catch (Throwable $e2) { if ($db->inTransaction()) $db->rollBack(); throw $e2; }
    bn_audit($ctx, 'survey_save', (string)$id, ['title' => $title, 'anonymous' => $anon, 'audience' => $aud]);
    return $id;
}
/** published | closed | delete (solo sin respuestas). */
function bn_sv_status(PDO $db, array $ctx, int $id, string $to, ?int $now = null): void {
    bn_need_manage($ctx);
    $s = bn_sv_row($db, $id);
    if (!bn_sv_can($ctx, $s)) bn_fail('No puedes cambiar esa encuesta.', 403);
    if ($to === 'delete') {
        if ((int)$db->query("SELECT COUNT(*) FROM g7_bn_sv_responses WHERE survey_id = $id")->fetchColumn()) bn_fail('La encuesta ya tiene respuestas: ciérrala en lugar de borrarla.');
        $db->prepare('DELETE FROM g7_bn_sv_questions WHERE survey_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM g7_bn_surveys WHERE id = ?')->execute([$id]);
    } elseif ($to === 'published') {
        if (!(int)$db->query("SELECT COUNT(*) FROM g7_bn_sv_questions WHERE survey_id = $id")->fetchColumn()) bn_fail('Agrega preguntas antes de publicar.');
        $db->prepare("UPDATE g7_bn_surveys SET status = 'published', updated_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', bn_now($now)), $id]);
        bn_sv_sweep($db, bn_now($now));
    } elseif ($to === 'closed') {
        // cerrar a mano corta la serie: no se crea la siguiente
        $db->prepare("UPDATE g7_bn_surveys SET status = 'closed', next_done = 1, updated_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', bn_now($now)), $id]);
    } else bn_fail('Acción inválida.');
    bn_audit($ctx, 'survey_' . $to, (string)$id, ['title' => $s['title']]);
}
function bn_sv_manage(PDO $db, array $ctx, ?int $now = null): array {
    bn_need_manage($ctx);
    $today = bn_today($now);
    $out = [];
    foreach ($db->query('SELECT * FROM g7_bn_surveys ORDER BY start_date DESC, id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC) as $s) {
        if (!bn_sv_can($ctx, $s)) continue;
        $id = (int)$s['id'];
        $n = (int)$db->query("SELECT COUNT(*) FROM g7_bn_sv_done WHERE survey_id = $id")->fetchColumn();
        $size = count(bn_users_of($db, bn_aud_decode($s['audience'])));
        $out[] = bn_sv_view($db, $s, $today) + ['responses' => $n, 'audience_size' => $size, 'participation' => $size ? min(100, (int)round($n / $size * 100)) : 0,
            'questions' => bn_sv_questions($db, $id), 'locked' => (bool)$n];
    }
    return $out;
}
/** Índice 0-100 de las preguntas de escala de un conjunto de respuestas (null si no hay escalas). */
function bn_sv_score(PDO $db, array $rids, array $questions): ?float {
    $scales = array_filter($questions, fn($q) => $q['type'] === 'scale');
    if (!$scales || !$rids) return null;
    $max = []; foreach ($scales as $q) $max[$q['id']] = $q['scale_max'];
    $vals = [];
    foreach ($db->query('SELECT question_id, num FROM g7_bn_sv_answers WHERE question_id IN (' . implode(',', array_keys($max)) . ') AND response_id IN (' . implode(',', array_map('intval', $rids)) . ') AND num IS NOT NULL') as $r)
        $vals[] = ((int)$r['num'] - 1) / ($max[(int)$r['question_id']] - 1) * 100;
    return $vals ? round(array_sum($vals) / count($vals), 1) : null;
}
/**
 * Resultados agregados. $team: ver solo un equipo (de su alcance). Con anónimas, debajo del mínimo no se muestra nada.
 * Las respuestas abiertas de las anónimas salen mezcladas y sin fecha.
 */
function bn_sv_results(PDO $db, array $ctx, int $id, int $team = 0, ?int $now = null): array {
    bn_need_manage($ctx);
    $s = bn_sv_row($db, $id);
    if (!bn_sv_can($ctx, $s)) bn_fail('Esa encuesta no está.', 404);
    $min = (int)$s['anonymous'] ? bn_min() : 1;
    $qs = bn_sv_questions($db, $id);
    $resp = $db->query("SELECT id, user_id, teams FROM g7_bn_sv_responses WHERE survey_id = $id")->fetchAll(PDO::FETCH_ASSOC);
    $myTeams = array_column(bn_teams_for($db, $ctx), 'name', 'id');
    if ($team) {
        if (!isset($myTeams[$team])) bn_fail('Ese equipo no está en lo que gestionas.', 403);
        $resp = array_values(array_filter($resp, fn($r) => in_array($team, array_map('intval', explode(',', (string)$r['teams'])), true)));
    }
    $size = count(bn_users_of($db, $team ? [$team] : bn_aud_decode($s['audience'])));
    $view = bn_sv_view($db, $s, bn_today($now));
    $base = ['survey' => $view, 'min' => $min, 'team' => $team, 'teams' => array_map(fn($k, $v) => ['id' => $k, 'name' => $v], array_keys($myTeams), $myTeams),
        'participation' => $size && count($resp) >= $min ? min(100, (int)round(count($resp) / $size * 100)) : null];
    // Evolución de la serie (encuestas periódicas)
    $series = (int)($s['series_id'] ?: $s['id']);
    $hist = [];
    foreach ($db->query("SELECT * FROM g7_bn_surveys WHERE (id = $series OR series_id = $series) AND status <> 'draft' ORDER BY start_date")->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $orid = $db->query('SELECT id, teams FROM g7_bn_sv_responses WHERE survey_id = ' . (int)$o['id'])->fetchAll(PDO::FETCH_ASSOC);
        if ($team) $orid = array_values(array_filter($orid, fn($r) => in_array($team, array_map('intval', explode(',', (string)$r['teams'])), true)));
        $ok = count($orid) >= ((int)$o['anonymous'] ? bn_min() : 1);
        $hist[] = ['id' => (int)$o['id'], 'start_date' => $o['start_date'], 'score' => $ok ? bn_sv_score($db, array_column($orid, 'id'), bn_sv_questions($db, (int)$o['id'])) : null, 'ok' => $ok];
    }
    $base['history'] = $hist;
    if (count($resp) < $min) return $base + ['locked' => true, 'responses' => null, 'questions' => [], 'by_team' => [], 'score' => null];
    $rids = array_map(fn($r) => (int)$r['id'], $resp);
    $ans = [];
    foreach ($db->query('SELECT a.* FROM g7_bn_sv_answers a WHERE a.response_id IN (' . implode(',', $rids) . ')') as $a) $ans[(int)$a['question_id']][] = $a;
    $names = [];
    if (!(int)$s['anonymous']) foreach ($db->query('SELECT r.id, u.username, u.display_name FROM g7_bn_sv_responses r JOIN g7_users u ON u.id = r.user_id WHERE r.survey_id = ' . $id) as $u) $names[(string)$u['id']] = (string)($u['display_name'] ?: $u['username']);
    $out = [];
    foreach ($qs as $q) {
        $rows = $ans[$q['id']] ?? []; $r = ['id' => $q['id'], 'type' => $q['type'], 'text' => $q['text'], 'answered' => count($rows)];
        if ($q['type'] === 'scale') {
            $dist = array_fill(1, $q['scale_max'], 0); foreach ($rows as $a) if (isset($dist[(int)$a['num']])) $dist[(int)$a['num']]++;
            $n = count($rows); $fav = $q['scale_max'] === 10 ? [8, 9, 10] : [4, 5];
            $r += ['scale_max' => $q['scale_max'], 'avg' => $n ? round(array_sum(array_column($rows, 'num')) / $n, 2) : null,
                'dist' => array_values(array_map(fn($c) => $n ? (int)round($c / $n * 100) : 0, $dist)),
                'favorable' => $n ? (int)round(array_sum(array_map(fn($k) => $dist[$k], $fav)) / $n * 100) : null];
        } elseif ($q['type'] === 'single' || $q['type'] === 'multi') {
            $cnt = array_fill(0, count($q['options']), 0);
            foreach ($rows as $a) foreach ($q['type'] === 'single' ? [(int)$a['num']] : array_map('intval', explode(',', (string)$a['opts'])) as $o) if (isset($cnt[$o])) $cnt[$o]++;
            $n = count($rows);
            $r += ['options' => array_map(fn($o, $c) => ['label' => $o, 'n' => $c, 'pct' => $n ? (int)round($c / $n * 100) : 0], $q['options'], $cnt)];
        } else {
            $texts = array_values(array_filter(array_map(fn($a) => ['text' => (string)$a['txt'], 'by' => $names[(string)$a['response_id']] ?? null], $rows), fn($t) => $t['text'] !== ''));
            if ((int)$s['anonymous']) shuffle($texts);
            $r += ['texts' => array_slice($texts, 0, 200)];
        }
        $out[] = $r;
    }
    $byTeam = [];
    if (!$team) foreach ($myTeams as $tid => $tname) {
        $tr = array_values(array_filter($resp, fn($x) => in_array($tid, array_map('intval', explode(',', (string)$x['teams'])), true)));
        if (!$tr) continue;
        $ok = count($tr) >= $min;
        $byTeam[] = ['id' => $tid, 'name' => $tname, 'score' => $ok ? bn_sv_score($db, array_column($tr, 'id'), $qs) : null, 'ok' => $ok];
    }
    return $base + ['locked' => false, 'responses' => count($resp), 'questions' => $out, 'by_team' => $byTeam, 'score' => bn_sv_score($db, $rids, $qs)];
}
/** Avisos al abrir, cierre al vencer y la siguiente de las periódicas. */
function bn_sv_sweep(PDO $db, ?int $now = null): void {
    $today = bn_today($now);
    foreach ($db->query("SELECT * FROM g7_bn_surveys WHERE status = 'published' AND notified = 0 AND start_date <= " . $db->quote($today) . ' AND end_date >= ' . $db->quote($today))->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $db->prepare('UPDATE g7_bn_surveys SET notified = 1 WHERE id = ?')->execute([(int)$s['id']]);
        $done = array_map('intval', $db->query('SELECT user_id FROM g7_bn_sv_done WHERE survey_id = ' . (int)$s['id'])->fetchAll(PDO::FETCH_COLUMN));
        bn_notify($db, array_diff(bn_users_of($db, bn_aud_decode($s['audience'])), $done), (int)$s['created_by'] ?: null, 'bn_survey', (int)$s['id'], 'Nueva encuesta: ' . $s['title'] . ((int)$s['anonymous'] ? ' (anónima)' : '') . '. Vence el ' . date('d/m', strtotime($s['end_date'])) . '.', 'surveys');
    }
    foreach ($db->query("SELECT * FROM g7_bn_surveys WHERE status = 'published' AND end_date < " . $db->quote($today))->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $db->prepare("UPDATE g7_bn_surveys SET status = 'closed' WHERE id = ?")->execute([(int)$s['id']]);
        if ($s['recurrence'] === 'none' || (int)$s['next_done']) continue;
        $db->prepare('UPDATE g7_bn_surveys SET next_done = 1 WHERE id = ?')->execute([(int)$s['id']]);
        $step = ['weekly' => '+1 week', 'monthly' => '+1 month', 'quarterly' => '+3 months'][$s['recurrence']];
        $st = $s['start_date']; $en = $s['end_date'];
        do { $st = date('Y-m-d', strtotime("$st $step")); $en = date('Y-m-d', strtotime("$en $step")); } while ($en < $today);   // si se saltearon períodos, va directo al vigente
        $db->prepare("INSERT INTO g7_bn_surveys (series_id, program_id, title, intro, category, anonymous, is_sensitive, audience, start_date, end_date, recurrence, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'published', ?, ?, ?)")
           ->execute([(int)($s['series_id'] ?: $s['id']), $s['program_id'], $s['title'], $s['intro'], $s['category'], $s['anonymous'], $s['is_sensitive'], $s['audience'], $st, $en, $s['recurrence'], $s['created_by'], date('Y-m-d H:i:s', bn_now($now)), date('Y-m-d H:i:s', bn_now($now))]);
        $nid = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO g7_bn_sv_questions (survey_id, pos, type, text, options, required, scale_max) SELECT ?, pos, type, text, options, required, scale_max FROM g7_bn_sv_questions WHERE survey_id = ?')->execute([$nid, (int)$s['id']]);
    }
    // la siguiente de una serie puede abrir hoy mismo: segunda pasada de avisos
    static $again = false;
    if (!$again) { $again = true; bn_sv_sweep($db, $now); $again = false; }
}
