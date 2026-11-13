<?php
/**
 * trabajo/user_work_lib.php — "Está trabajando en…" y "Tareas en curso" de una persona, para el chat y su perfil (2026-11-11).
 *  - now: la tarea (o su ticket) con el cronómetro corriendo (g7_task_time sin ended_at, de las últimas 12 horas).
 *  - tasks: las que tiene asignadas sin terminar; primero las que están en proceso, después las pendientes.
 * Todo con los permisos de QUIEN MIRA (wk_task_where): lo que no puede ver no muestra título; "now" dice solo
 * "una tarea" y las tareas ocultas se cuentan en hidden. Doc: toolz/TRABAJANDO_EN.md
 */
require_once __DIR__ . '/lib.php';

function wk_user_work(PDO $db, int $viewer, int $target, int $limit = 8): array {
    $out = ['now' => null, 'tasks' => [], 'hidden' => 0, 'total' => 0];
    if (function_exists('g7_can') && (!g7_can($viewer, 'tareas') || !g7_can($target, 'tareas'))) return $out;
    if (!function_exists('tk_config')) require_once dirname(__DIR__) . '/tareas_lib.php';
    wk_install($db);
    $cfg = tk_config($db);
    $st = []; foreach ($cfg['statuses'] as $i => $s) $st[$s['id']] = $s + ['i' => $i];
    $open = array_keys(array_filter($st, fn($s) => empty($s['done'])));
    if (!$open) return $out;
    $vis = wk_task_where($db, wk_ctx($db, $viewer), 't');
    $tk = function (array $ids) use ($db) {
        if (!$ids) return [];
        $m = []; try { foreach ($db->query('SELECT id, task_id FROM g7_wk_tickets WHERE task_id IN (' . implode(',', array_map('intval', $ids)) . ')') as $r) $m[(int)$r['task_id']] ??= (int)$r['id']; } catch (Throwable $e) {}
        return $m;
    };
    $item = function (array $r, array $tkMap) use ($st) {
        $tid = (int)$r['id']; $tkId = $tkMap[$tid] ?? null; $s = $st[$r['status']] ?? ['name' => $r['status'], 'color' => '#8b8b94'];
        return ['id' => $tid, 'title' => (string)$r['title'], 'status' => (string)$s['name'], 'color' => (string)$s['color'], 'due' => $r['due_date'] ?: null,
            'ticket' => $tkId, 'code' => $tkId ? 'T-' . str_pad((string)$tkId, 4, '0', STR_PAD_LEFT) : '#' . $tid,
            'href' => $tkId ? '/toolz/trabajo/#tickets/' . $tkId : '/toolz/tareas.php?' . ($r['board_id'] ? 'b=' . (int)$r['board_id'] . '&' : '') . 't=' . $tid];
    };
    // Cronómetro corriendo: en qué está ahora
    try {
        $q = $db->prepare('SELECT x.task_id, x.started_at FROM g7_task_time x WHERE x.user_id = ? AND x.ended_at IS NULL AND x.started_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR) ORDER BY x.started_at DESC LIMIT 1');
        $q->execute([$target]);
        if ($run = $q->fetch(PDO::FETCH_ASSOC)) {
            $r = $db->query('SELECT t.id, t.title, t.status, t.due_date, t.board_id FROM g7_tasks t WHERE t.id = ' . (int)$run['task_id'] . " AND ($vis)")->fetch(PDO::FETCH_ASSOC);
            $out['now'] = $r ? $item($r, $tk([(int)$r['id']])) + ['since' => $run['started_at']] : ['hidden' => true, 'since' => $run['started_at']];
        }
    } catch (Throwable $e) {}
    // Tareas en curso (asignadas, sin terminar)
    $in = implode(',', array_map([$db, 'quote'], $open));
    $base = "FROM g7_tasks t JOIN g7_task_assignees a ON a.task_id = t.id AND a.user_id = " . $target . " WHERE t.archived = 0 AND t.parent_id IS NULL AND t.status IN ($in)";
    try {
        $out['total'] = (int)$db->query("SELECT COUNT(*) $base")->fetchColumn();
        $rows = $db->query("SELECT t.id, t.title, t.status, t.due_date, t.board_id $base AND ($vis) ORDER BY t.updated_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Sitios sin subtareas (parent_id) todavía
        $base = str_replace(' AND t.parent_id IS NULL', '', $base);
        $out['total'] = (int)$db->query("SELECT COUNT(*) $base")->fetchColumn();
        $rows = $db->query("SELECT t.id, t.title, t.status, t.due_date, t.board_id $base AND ($vis) ORDER BY t.updated_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    }
    $out['hidden'] = $out['total'] - count($rows);
    // En proceso primero (nombre "proceso/curso"), después el resto; dentro, lo que vence antes
    $rank = function ($r) use ($st) { $s = $st[$r['status']] ?? ['id' => '', 'name' => '', 'i' => 0]; $n = mb_strtolower($s['id'] . ' ' . $s['name']); return str_contains($n, 'proceso') || str_contains($n, 'curso') ? 0 : ($s['i'] === 0 ? 2 : 1); };
    usort($rows, fn($a, $b) => [$rank($a), $a['due_date'] ?: '9999-12-31'] <=> [$rank($b), $b['due_date'] ?: '9999-12-31']);
    $rows = array_slice($rows, 0, $limit);
    $map = $tk(array_column($rows, 'id'));
    $out['tasks'] = array_map(fn($r) => $item($r, $map), $rows);
    $out['more'] = max(0, $out['total'] - $out['hidden'] - count($out['tasks']));
    return $out;
}
