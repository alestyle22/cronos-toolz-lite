<?php
/**
 * trabajo/lib.php — Gestión de trabajo (2026-10-31): tableros, roles y permisos sobre el módulo Tareas.
 * Plan completo en TRABAJO_PLAN.md. Las tareas siguen siendo g7_tasks; board_id NULL = tablero General.
 *
 * Roles: Administrador (admin global), Director (g7_wk_roles), Jefe de departamento (admin de un equipo),
 * Empleado (el resto) y Solicitante (g7_wk_roles, solo ve lo suyo). "Responsable" es el encargado de un tablero.
 * Todo permiso se decide acá, en el servidor.
 */
require_once __DIR__ . '/../tareas_lib.php';
require_once __DIR__ . '/link_lib.php';   // ticket = tarea, pausa del SLA y avisos de tickets
require_once __DIR__ . '/tk_collab_lib.php';   // colaboradores y menciones en los tickets (TICKETS_COLABORADORES.md)
require_once __DIR__ . '/portal_lib.php';   // Portal de Solicitudes por departamento (SOLICITUDES.md)

const WK_ROLES = [
    'admin'       => 'Administrador',
    'director'    => 'Director',
    'jefe'        => 'Jefe de departamento',
    'empleado'    => 'Empleado',
    'solicitante' => 'Solicitante',
];
const WK_VIS = ['public' => 'Todos', 'team' => 'Su departamento', 'members' => 'Solo sus miembros'];

function wk_install(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    tk_install($db);
    $v = null;
    try { $v = $db->query("SELECT cfg_value FROM g7_config WHERE cfg_key = 'work_schema'")->fetchColumn(); } catch (Throwable $e) {}
    if ($v === '9') return;
    if ($v === '8') { wk_install_v9($db); return; }
    if ($v === '7') { wk_install_v8($db); wk_install_v9($db); return; }
    if (in_array($v, ['1', '2', '3', '4', '5', '6'], true)) {
        if ($v === '1') wk_install_v2($db);
        if ((int)$v <= 2) wk_install_v3($db);
        if ((int)$v <= 3) wk_install_v4($db);
        if ((int)$v <= 4) wk_install_v5($db);
        if ((int)$v <= 5) wk_install_v6($db);
        wk_install_v7($db);
        wk_install_v8($db);
        wk_install_v9($db);
        return;
    }
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_roles (
        user_id INT NOT NULL PRIMARY KEY, role VARCHAR(20) NOT NULL, set_by INT NULL, set_at DATETIME NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_boards (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, description VARCHAR(500) NOT NULL DEFAULT '',
        color VARCHAR(7) NOT NULL DEFAULT '#ff6600', icon VARCHAR(40) NOT NULL DEFAULT 'fa-table-columns',
        team_id INT NULL, visibility VARCHAR(10) NOT NULL DEFAULT 'public', statuses TEXT NULL, is_default TINYINT(1) NOT NULL DEFAULT 0,
        archived TINYINT(1) NOT NULL DEFAULT 0, position INT NOT NULL DEFAULT 0,
        created_by INT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        KEY team_id (team_id), KEY archived (archived)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_board_members (
        board_id INT NOT NULL, user_id INT NOT NULL, role VARCHAR(10) NOT NULL DEFAULT 'member', added_at DATETIME NOT NULL,
        PRIMARY KEY (board_id, user_id), KEY user_id (user_id)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("ALTER TABLE g7_tasks ADD COLUMN IF NOT EXISTS board_id INT NULL AFTER id");
    $db->exec("ALTER TABLE g7_tasks ADD INDEX IF NOT EXISTS board_id (board_id)");
    $db->exec("ALTER TABLE g7_tasks ADD INDEX IF NOT EXISTS completed_at (completed_at)");
    // El tablero General: ahí quedan todas las tareas que ya existían (y las que llegan sin tablero, como las del chat)
    if (!$db->query('SELECT id FROM g7_wk_boards WHERE is_default = 1')->fetchColumn()) {
        $now = tk_now();
        $db->prepare("INSERT INTO g7_wk_boards (name, description, color, icon, visibility, is_default, created_at, updated_at) VALUES (?, ?, '#ff6600', 'fa-table-columns', 'public', 1, ?, ?)")
           ->execute(['General', 'El tablero de siempre: todas las tareas que había antes de los tableros.', $now, $now]);
    }
    // El menú de arriba: "Tareas" pasa a ser la sección Trabajo
    $nav = json_decode((string)$db->query("SELECT cfg_value FROM g7_config WHERE cfg_key = 'nav_links'")->fetchColumn(), true);
    if (is_array($nav)) {
        $ch = false;
        foreach ($nav as &$l) if (($l['href'] ?? '') === '/toolz/tareas.php') { $l['href'] = '/toolz/trabajo/'; if (($l['label'] ?? '') === 'Tareas') $l['label'] = 'Trabajo'; $ch = true; }
        unset($l);
        if ($ch) $db->prepare("UPDATE g7_config SET cfg_value = ? WHERE cfg_key = 'nav_links'")->execute([json_encode($nav, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
    $db->exec("INSERT INTO g7_config (cfg_key, cfg_value) VALUES ('work_schema', '1') ON DUPLICATE KEY UPDATE cfg_value = '1'");
    wk_install_v2($db);
    wk_install_v3($db);
    wk_install_v4($db);
    wk_install_v5($db);
    wk_install_v6($db);
    wk_install_v7($db);
    wk_install_v8($db);
    wk_install_v9($db);
}

/** v2 (fase 2): subtareas, inicio y estimado, dependencias, campos por tablero y las 5 prioridades. */
function wk_install_v2(PDO $db): void {
    $db->exec("ALTER TABLE g7_tasks ADD COLUMN IF NOT EXISTS parent_id INT NULL AFTER board_id");
    $db->exec("ALTER TABLE g7_tasks ADD COLUMN IF NOT EXISTS start_date DATE NULL AFTER due_date");
    $db->exec("ALTER TABLE g7_tasks ADD COLUMN IF NOT EXISTS estimate_min INT NULL AFTER start_date");
    $db->exec("ALTER TABLE g7_tasks ADD INDEX IF NOT EXISTS parent_id (parent_id)");
    // Dependencias: task_id no se puede terminar mientras depends_on siga abierta
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_task_deps (
        task_id INT NOT NULL, depends_on INT NOT NULL, created_by INT NULL, created_at DATETIME NOT NULL,
        PRIMARY KEY (task_id, depends_on), KEY depends_on (depends_on)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_fields (
        id INT AUTO_INCREMENT PRIMARY KEY, board_id INT NOT NULL, name VARCHAR(60) NOT NULL, type VARCHAR(10) NOT NULL,
        options TEXT NULL, position INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, KEY board_id (board_id)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_field_values (
        task_id INT NOT NULL, field_id INT NOT NULL, value VARCHAR(1000) NOT NULL, PRIMARY KEY (task_id, field_id), KEY field_id (field_id)
    ) DEFAULT CHARSET=utf8mb4");
    // Prioridades: si siguen siendo las tres de fábrica, pasan a Baja / Normal / Alta / Urgente / Crítica (mismos ids: nada que remapear)
    $raw = $db->query("SELECT cfg_value FROM g7_config WHERE cfg_key = 'tasks_config'")->fetchColumn();
    $c = $raw ? json_decode($raw, true) : null;
    if (is_array($c) && isset($c['priorities']) && array_column($c['priorities'], 'id') === ['baja', 'media', 'urgente']) {
        $c['priorities'] = TK_DEFAULT_CONFIG['priorities'];
        $db->prepare("UPDATE g7_config SET cfg_value = ? WHERE cfg_key = 'tasks_config'")->execute([json_encode($c, JSON_UNESCAPED_UNICODE)]);
    }
    $db->exec("INSERT INTO g7_config (cfg_key, cfg_value) VALUES ('work_schema', '2') ON DUPLICATE KEY UPDATE cfg_value = '2'");
}

const WK_FIELD_TYPES = ['text' => 'Texto', 'number' => 'Número', 'date' => 'Fecha', 'select' => 'Lista de opciones', 'check' => 'Casilla', 'person' => 'Persona', 'url' => 'Link'];

/** Estados de un tablero: los propios o los generales. */
function wk_statuses(PDO $db, array $board, ?array $cfg = null): array {
    return $board['statuses'] ?: ($cfg ?? tk_config($db))['statuses'];
}

/** Estados de todos los tableros que ve alguien (para mostrar nombre y color de cualquier tarea). id de tablero => lista. */
function wk_statuses_by_board(PDO $db, array $ctx, array $cfg): array {
    $out = [];
    foreach ($db->query('SELECT b.id, b.statuses FROM g7_wk_boards b WHERE ' . wk_board_where($ctx))->fetchAll(PDO::FETCH_ASSOC) as $r)
        $out[(int)$r['id']] = $r['statuses'] ? (json_decode($r['statuses'], true) ?: $cfg['statuses']) : $cfg['statuses'];
    return $out;
}

function wk_fields(PDO $db, int $boardId): array {
    $q = $db->prepare('SELECT id, name, type, options, position FROM g7_wk_fields WHERE board_id = ? ORDER BY position, id'); $q->execute([$boardId]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'type' => $r['type'], 'options' => json_decode($r['options'] ?: '[]', true) ?: [], 'position' => (int)$r['position']], $q->fetchAll(PDO::FETCH_ASSOC));
}

/** Valor válido para un campo según su tipo ('' = vacío) o null si no es válido. */
function wk_field_value(PDO $db, array $f, $v): ?string {
    $v = trim((string)$v);
    if ($v === '') return '';
    switch ($f['type']) {
        case 'number': $n = str_replace(',', '.', $v); return is_numeric($n) ? (string)(0 + $n) : null;
        case 'date':   return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
        case 'select': return in_array($v, array_column($f['options'], 'id'), true) ? $v : null;
        case 'check':  return $v === '1' ? '1' : '';
        case 'person': $q = $db->prepare('SELECT id FROM g7_users WHERE id = ? AND active = 1'); $q->execute([(int)$v]); return $q->fetch() ? (string)(int)$v : null;
        case 'url':    return preg_match('#^https?://#i', $v) && filter_var($v, FILTER_VALIDATE_URL) ? mb_substr($v, 0, 1000) : null;
        default:       return mb_substr($v, 0, 1000);
    }
}

/** ¿Se llega de $from a $to siguiendo dependencias? (para no armar ciclos) */
function wk_dep_reaches(PDO $db, int $from, int $to): bool {
    $seen = []; $stack = [$from];
    $q = $db->prepare('SELECT depends_on FROM g7_wk_task_deps WHERE task_id = ?');
    while ($stack) {
        $x = array_pop($stack);
        if ($x === $to) return true;
        if (isset($seen[$x]) || count($seen) > 2000) continue;
        $seen[$x] = true;
        $q->execute([$x]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $n) $stack[] = (int)$n;
    }
    return false;
}

/** Tareas abiertas que bloquean a una tarea. */
function wk_open_blockers(PDO $db, int $taskId): array {
    $q = $db->prepare('SELECT t.id, t.title FROM g7_wk_task_deps d JOIN g7_tasks t ON t.id = d.depends_on WHERE d.task_id = ? AND t.completed_at IS NULL AND t.archived = 0');
    $q->execute([$taskId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Lo que cuelga de una tarea que se borra: subtareas sueltas, dependencias y valores de campos. */
function wk_task_deleted(PDO $db, int $taskId, ?int $by = null): void {
    wk_tk_task_deleted($db, $taskId, $by);
    $db->prepare('UPDATE g7_tasks SET parent_id = NULL WHERE parent_id = ?')->execute([$taskId]);
    $db->prepare('DELETE FROM g7_wk_task_deps WHERE task_id = ? OR depends_on = ?')->execute([$taskId, $taskId]);
    $db->prepare('DELETE FROM g7_wk_field_values WHERE task_id = ?')->execute([$taskId]);
}

/** Guarda los estados de un tablero (null = generales): mueve las tareas de estados que ya no existen y ajusta completed_at. */
function wk_set_board_statuses(PDO $db, array $board, ?array $statuses, array $remap, array $cfg): void {
    $old = wk_statuses($db, $board, $cfg);
    $new = $statuses ?: $cfg['statuses'];
    $ids = array_column($new, 'id');
    $where = 'COALESCE(board_id, ' . wk_general_id($db) . ') = ' . (int)$board['id'];
    $cur = $db->query("SELECT DISTINCT status FROM g7_tasks WHERE $where")->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_unique(array_merge(array_column($old, 'id'), $cur)) as $gone) {
        if (in_array($gone, $ids, true)) continue;
        $to = in_array($remap[$gone] ?? '', $ids, true) ? $remap[$gone] : $ids[0];
        $db->prepare("UPDATE g7_tasks SET status = ? WHERE status = ? AND $where")->execute([$to, $gone]);
    }
    wk_sync_completed($db, $new, $where);
    $db->prepare('UPDATE g7_wk_boards SET statuses = ?, updated_at = ? WHERE id = ?')
       ->execute([$statuses ? json_encode(array_values($statuses), JSON_UNESCAPED_UNICODE) : null, tk_now(), $board['id']]);
}

/** completed_at sigue a la marca "terminado" de los estados, en las tareas que cumplen $where. */
function wk_sync_completed(PDO $db, array $statuses, string $where): void {
    $done = array_column(array_filter($statuses, fn($s) => !empty($s['done'])), 'id');
    if ($done) {
        $in = implode(',', array_map([$db, 'quote'], $done));
        $db->exec("UPDATE g7_tasks SET completed_at = COALESCE(completed_at, updated_at) WHERE status IN ($in) AND $where");
        $db->exec("UPDATE g7_tasks SET completed_at = NULL WHERE status NOT IN ($in) AND $where");
    } else $db->exec("UPDATE g7_tasks SET completed_at = NULL WHERE $where");
}

/** Condición SQL de las tareas de tableros que usan los estados generales. */
function wk_global_status_where(PDO $db): string {
    return 'COALESCE(board_id, ' . wk_general_id($db) . ') IN (SELECT id FROM g7_wk_boards WHERE statuses IS NULL)';
}

/** Limpia una lista de estados que manda la pantalla. */
function wk_clean_statuses(array $list): array {
    $out = []; $seen = [];
    foreach ($list as $x) {
        $name = mb_substr(trim((string)($x['name'] ?? '')), 0, 40);
        if ($name === '') continue;
        $id = preg_replace('/[^a-z0-9_]/', '', (string)($x['id'] ?? '')) ?: tk_slug($name);
        $base = $id; $n = 2; while (isset($seen[$id])) $id = $base . '_' . $n++;
        $seen[$id] = true;
        $out[] = ['id' => $id, 'name' => $name, 'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($x['color'] ?? '')) ? strtolower($x['color']) : '#8b8b94', 'done' => !empty($x['done'])];
    }
    return array_slice($out, 0, 20);
}

function wk_general_id(PDO $db): int {
    static $id = null;
    if ($id === null) $id = (int)$db->query('SELECT id FROM g7_wk_boards WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    return $id;
}

/** Quién es cada uno en Gestión de trabajo. */
function wk_ctx(PDO $db, int $uid): array {
    static $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    $q = $db->prepare('SELECT role FROM g7_users WHERE id = ?'); $q->execute([$uid]); $gr = (string)$q->fetchColumn();
    $q = $db->prepare('SELECT role FROM g7_wk_roles WHERE user_id = ?'); $q->execute([$uid]); $wr = (string)$q->fetchColumn();
    $q = $db->prepare('SELECT m.team_id, m.role FROM g7_team_members m JOIN g7_teams t ON t.id = m.team_id AND t.active = 1 WHERE m.user_id = ?'); $q->execute([$uid]);
    $teams = []; $lead = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $teams[] = (int)$r['team_id']; if ($r['role'] === 'admin') $lead[] = (int)$r['team_id']; }
    $role = $gr === 'admin' ? 'admin' : ($wr === 'director' ? 'director' : ($lead ? 'jefe' : ($wr === 'solicitante' ? 'solicitante' : 'empleado')));
    return $cache[$uid] = ['id' => $uid, 'role' => $role, 'label' => WK_ROLES[$role], 'teams' => $teams, 'lead' => $lead, 'all' => in_array($role, ['admin', 'director'], true)];
}

function wk_ints(array $a): string { $a = array_values(array_unique(array_map('intval', $a))); return $a ? implode(',', $a) : '0'; }

/** Condición SQL (solo números, sin parámetros) de los tableros que ve alguien. */
function wk_board_where(array $ctx, string $b = 'b'): string {
    if ($ctx['all']) return '1';
    if ($ctx['role'] === 'solicitante') return "EXISTS (SELECT 1 FROM g7_wk_board_members m WHERE m.board_id = $b.id AND m.user_id = {$ctx['id']})";
    $uid = $ctx['id'];
    return "($b.visibility = 'public'"
        . " OR ($b.visibility = 'team' AND $b.team_id IN (" . wk_ints($ctx['teams']) . '))'
        . " OR $b.team_id IN (" . wk_ints($ctx['lead']) . ')'
        . " OR $b.created_by = $uid"
        . " OR EXISTS (SELECT 1 FROM g7_wk_board_members m WHERE m.board_id = $b.id AND m.user_id = $uid))";
}

/** Ids de los tableros visibles (incluye archivados: sus tareas se siguen pudiendo abrir). */
function wk_board_ids(PDO $db, array $ctx): array {
    static $cache = [];
    if (isset($cache[$ctx['id']])) return $cache[$ctx['id']];
    return $cache[$ctx['id']] = array_map('intval', $db->query('SELECT b.id FROM g7_wk_boards b WHERE ' . wk_board_where($ctx))->fetchAll(PDO::FETCH_COLUMN));
}

/** Condición SQL de las tareas que ve alguien: las de sus tableros, más las que tiene asignadas o creó. */
function wk_task_where(PDO $db, array $ctx, string $t = 't'): string {
    if ($ctx['all']) return '1';
    $uid = $ctx['id'];
    return '(COALESCE(' . $t . '.board_id, ' . wk_general_id($db) . ') IN (' . wk_ints(wk_board_ids($db, $ctx)) . ')'
        . " OR $t.created_by = $uid"
        . " OR EXISTS (SELECT 1 FROM g7_task_assignees a WHERE a.task_id = $t.id AND a.user_id = $uid))";
}

function wk_task_visible(PDO $db, array $ctx, int $taskId): bool {
    $q = $db->prepare('SELECT 1 FROM g7_tasks t WHERE t.id = ? AND ' . wk_task_where($db, $ctx)); $q->execute([$taskId]);
    return (bool)$q->fetchColumn();
}

function wk_board(PDO $db, int $id): ?array {
    $q = $db->prepare('SELECT * FROM g7_wk_boards WHERE id = ?'); $q->execute([$id]);
    $b = $q->fetch(PDO::FETCH_ASSOC);
    if (!$b) return null;
    foreach (['id', 'team_id', 'is_default', 'archived', 'position', 'created_by'] as $k) $b[$k] = $b[$k] === null ? null : (int)$b[$k];
    $b['statuses'] = $b['statuses'] ? (json_decode($b['statuses'], true) ?: null) : null;
    return $b;
}

function wk_board_visible(PDO $db, array $ctx, int $id): bool { return in_array($id, wk_board_ids($db, $ctx), true); }

/** Rol de alguien dentro de un tablero: manager, member o ''. */
function wk_board_role(PDO $db, int $boardId, int $uid): string {
    $q = $db->prepare('SELECT role FROM g7_wk_board_members WHERE board_id = ? AND user_id = ?'); $q->execute([$boardId, $uid]);
    return (string)$q->fetchColumn();
}

/** Administrar un tablero (nombre, miembros, archivar): admin/director, jefe de su departamento, su encargado o quien lo creó. */
function wk_can_manage_board(PDO $db, array $ctx, array $b): bool {
    if ($ctx['all']) return true;
    if ($ctx['role'] === 'solicitante') return false;
    if ($b['team_id'] && in_array((int)$b['team_id'], $ctx['lead'], true)) return true;
    if (!$b['is_default'] && (int)$b['created_by'] === $ctx['id']) return true;
    return wk_board_role($db, (int)$b['id'], $ctx['id']) === 'manager';
}

/** Crear un tablero en un departamento (team_id) o sin departamento (0). */
function wk_can_create_board(array $ctx, int $teamId): bool {
    if ($ctx['all']) return true;
    if ($ctx['role'] === 'solicitante') return false;
    return $teamId === 0 || in_array($teamId, $ctx['lead'], true) || in_array($teamId, $ctx['teams'], true);
}

/** Gente cuya carga ve alguien en el Resumen: todos (admin/director), los de sus departamentos (jefe) o nadie más. */
function wk_people_scope(PDO $db, array $ctx): ?array {
    if ($ctx['all']) return null;   // null = todos
    if (!$ctx['lead']) return [];
    return array_map('intval', $db->query('SELECT DISTINCT user_id FROM g7_team_members WHERE team_id IN (' . wk_ints($ctx['lead']) . ')')->fetchAll(PDO::FETCH_COLUMN));
}

/** v3 (fase 3): proyectos con hitos. Las tareas siguen viviendo en sus tableros y se vinculan al proyecto. */
function wk_install_v3(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_projects (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, description TEXT NULL,
        client_id INT NULL, team_id INT NULL, owner_id INT NULL, status VARCHAR(12) NOT NULL DEFAULT 'planificado',
        start_date DATE NULL, due_date DATE NULL, budget_min INT NULL, color VARCHAR(7) NOT NULL DEFAULT '#ff6600',
        visibility VARCHAR(10) NOT NULL DEFAULT 'public', archived TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, closed_at DATETIME NULL,
        KEY team_id (team_id), KEY client_id (client_id), KEY archived (archived)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_project_members (
        project_id INT NOT NULL, user_id INT NOT NULL, role VARCHAR(10) NOT NULL DEFAULT 'member', added_at DATETIME NOT NULL,
        PRIMARY KEY (project_id, user_id), KEY user_id (user_id)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_milestones (
        id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, name VARCHAR(120) NOT NULL, description VARCHAR(500) NOT NULL DEFAULT '',
        due_date DATE NULL, done_at DATETIME NULL, done_by INT NULL, position INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, KEY project_id (project_id)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("ALTER TABLE g7_tasks ADD COLUMN IF NOT EXISTS project_id INT NULL AFTER parent_id");
    $db->exec("ALTER TABLE g7_tasks ADD COLUMN IF NOT EXISTS milestone_id INT NULL AFTER project_id");
    $db->exec("ALTER TABLE g7_tasks ADD INDEX IF NOT EXISTS project_id (project_id)");
    $db->exec("ALTER TABLE g7_tasks ADD INDEX IF NOT EXISTS milestone_id (milestone_id)");
    $db->exec("INSERT INTO g7_config (cfg_key, cfg_value) VALUES ('work_schema', '3') ON DUPLICATE KEY UPDATE cfg_value = '3'");
}

const WK_PROJECT_STATUS = [
    'planificado' => ['Planificado', '#8b8b94'], 'en_curso' => ['En curso', '#3b82f6'], 'en_pausa' => ['En pausa', '#f59e0b'],
    'terminado' => ['Terminado', '#22c55e'], 'cancelado' => ['Cancelado', '#ef4444'],
];

/** Condición SQL de los proyectos que ve alguien (mismas reglas que los tableros). */
function wk_project_where(array $ctx, string $p = 'p'): string {
    if ($ctx['all']) return '1';
    $uid = $ctx['id'];
    $mem = "EXISTS (SELECT 1 FROM g7_wk_project_members m WHERE m.project_id = $p.id AND m.user_id = $uid)";
    if ($ctx['role'] === 'solicitante') return "($mem OR $p.owner_id = $uid)";
    return "($p.visibility = 'public'"
        . " OR ($p.visibility = 'team' AND $p.team_id IN (" . wk_ints($ctx['teams']) . '))'
        . " OR $p.team_id IN (" . wk_ints($ctx['lead']) . ')'
        . " OR $p.created_by = $uid OR $p.owner_id = $uid OR $mem)";
}

function wk_project(PDO $db, int $id): ?array {
    $q = $db->prepare('SELECT * FROM g7_wk_projects WHERE id = ?'); $q->execute([$id]);
    $p = $q->fetch(PDO::FETCH_ASSOC);
    if (!$p) return null;
    foreach (['id', 'client_id', 'team_id', 'owner_id', 'budget_min', 'archived', 'created_by'] as $k) $p[$k] = $p[$k] === null ? null : (int)$p[$k];
    return $p;
}

function wk_project_visible(PDO $db, array $ctx, int $id): bool {
    $q = $db->prepare('SELECT 1 FROM g7_wk_projects p WHERE p.id = ? AND ' . wk_project_where($ctx)); $q->execute([$id]);
    return (bool)$q->fetchColumn();
}

/** Administrar un proyecto: admin/director, jefe de su departamento, su responsable, quien lo creó o un miembro encargado. */
function wk_can_manage_project(PDO $db, array $ctx, array $p): bool {
    if ($ctx['all']) return true;
    if ($ctx['role'] === 'solicitante') return false;
    if ($p['team_id'] && in_array((int)$p['team_id'], $ctx['lead'], true)) return true;
    if ((int)$p['owner_id'] === $ctx['id'] || (int)$p['created_by'] === $ctx['id']) return true;
    $q = $db->prepare("SELECT 1 FROM g7_wk_project_members WHERE project_id = ? AND user_id = ? AND role = 'manager'"); $q->execute([$p['id'], $ctx['id']]);
    return (bool)$q->fetchColumn();
}

/** Avance real de proyectos: tareas (sin contar subtareas), vencidas, horas e hitos. id => datos. */
function wk_project_stats(PDO $db, array $ids): array {
    $out = [];
    if (!$ids) return $out;
    $in = wk_ints($ids);
    $today = date('Y-m-d');
    foreach ($db->query("SELECT project_id, COUNT(*) AS total, SUM(completed_at IS NOT NULL) AS done,
            SUM(completed_at IS NULL AND due_date IS NOT NULL AND due_date < '$today') AS late,
            SUM(COALESCE(estimate_min, 0)) AS est, SUM(estimate_min > 0) AS with_est, SUM(CASE WHEN completed_at IS NOT NULL THEN COALESCE(estimate_min, 0) ELSE 0 END) AS est_done
        FROM g7_tasks WHERE archived = 0 AND parent_id IS NULL AND project_id IN ($in) GROUP BY project_id")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $out[(int)$r['project_id']] = ['total' => (int)$r['total'], 'done' => (int)$r['done'], 'late' => (int)$r['late'], 'est' => (int)$r['est'], 'est_done' => (int)$r['est_done'], 'with_est' => (int)$r['with_est']];
    foreach ($db->query("SELECT t.project_id, SUM(m.minutes) AS m FROM g7_task_time m JOIN g7_tasks t ON t.id = m.task_id WHERE m.ended_at IS NOT NULL AND t.project_id IN ($in) GROUP BY t.project_id")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $out[(int)$r['project_id']]['minutes'] = (int)$r['m'];
    foreach ($db->query("SELECT project_id, COUNT(*) AS n, SUM(done_at IS NOT NULL) AS d, SUM(done_at IS NULL AND due_date < '$today') AS late FROM g7_wk_milestones WHERE project_id IN ($in) GROUP BY project_id")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $out[(int)$r['project_id']] += ['ms' => (int)$r['n'], 'ms_done' => (int)$r['d'], 'ms_late' => (int)$r['late']];
    foreach ($ids as $id) {
        $s = ($out[$id] ?? []) + ['total' => 0, 'done' => 0, 'late' => 0, 'est' => 0, 'est_done' => 0, 'with_est' => 0, 'minutes' => 0, 'ms' => 0, 'ms_done' => 0, 'ms_late' => 0];
        // Avance: por horas estimadas si todas las tareas tienen estimado; si no, por cantidad de tareas
        $byHours = $s['total'] && $s['with_est'] === $s['total'];
        $s['progress'] = $s['total'] ? (int)round(($byHours ? $s['est_done'] / $s['est'] : $s['done'] / $s['total']) * 100) : 0;
        $s['by'] = $byHours ? 'horas estimadas' : 'tareas terminadas';
        $out[$id] = $s;
    }
    return $out;
}

/** Salud de un proyecto según fechas y avance: ok | riesgo | atrasado | (terminado/cancelado/pausa no se evalúan). */
function wk_project_health(array $p, array $s): array {
    if (in_array($p['status'], ['terminado', 'cancelado', 'en_pausa'], true)) return ['key' => 'na', 'label' => wk_st('projects')[$p['status']][0] ?? $p['status']];
    $today = date('Y-m-d');
    if ($p['due_date'] && $p['due_date'] < $today) return ['key' => 'late', 'label' => 'Pasó la fecha de entrega'];
    if ($s['ms_late'] || $s['late']) return ['key' => 'risk', 'label' => trim(($s['ms_late'] ? $s['ms_late'] . ' hito' . ($s['ms_late'] > 1 ? 's' : '') . ' vencido' . ($s['ms_late'] > 1 ? 's' : '') : '') . ($s['ms_late'] && $s['late'] ? ' · ' : '') . ($s['late'] ? $s['late'] . ' tarea' . ($s['late'] > 1 ? 's' : '') . ' vencida' . ($s['late'] > 1 ? 's' : '') : ''))];
    // Si el tiempo transcurrido va más de 25 puntos por delante del avance, está en riesgo
    if ($p['start_date'] && $p['due_date'] && $p['due_date'] > $p['start_date'] && $s['total']) {
        $elapsed = (strtotime($today) - strtotime($p['start_date'])) / (strtotime($p['due_date']) - strtotime($p['start_date'])) * 100;
        if ($elapsed - $s['progress'] > 25) return ['key' => 'risk', 'label' => 'Va ' . (int)round($elapsed) . '% del tiempo y ' . $s['progress'] . '% del trabajo'];
    }
    return ['key' => 'ok', 'label' => 'En camino'];
}

/** v4 (fase 4): solicitudes internas con formularios por departamento, y tickets con SLA, notas internas y escalado. */
function wk_install_v4(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_forms (
        id INT AUTO_INCREMENT PRIMARY KEY, team_id INT NULL, name VARCHAR(100) NOT NULL, description VARCHAR(500) NOT NULL DEFAULT '',
        icon VARCHAR(40) NOT NULL DEFAULT 'fa-inbox', color VARCHAR(7) NOT NULL DEFAULT '#ff6600', fields TEXT NOT NULL,
        board_id INT NULL, assignee_id INT NULL, active TINYINT(1) NOT NULL DEFAULT 1, position INT NOT NULL DEFAULT 0,
        created_by INT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, KEY team_id (team_id)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_requests (
        id INT AUTO_INCREMENT PRIMARY KEY, form_id INT NOT NULL, team_id INT NULL, title VARCHAR(200) NOT NULL, answers TEXT NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'nueva', priority VARCHAR(40) NOT NULL DEFAULT 'media', requester_id INT NOT NULL, assignee_id INT NULL,
        task_id INT NULL, project_id INT NULL, decided_by INT NULL, decided_at DATETIME NULL, decision_note VARCHAR(1000) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, KEY team_id (team_id), KEY requester_id (requester_id), KEY status (status)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_req_msgs (
        id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, user_id INT NULL, body TEXT NOT NULL, internal TINYINT(1) NOT NULL DEFAULT 0,
        kind VARCHAR(8) NOT NULL DEFAULT 'msg', created_at DATETIME NOT NULL, KEY request_id (request_id)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_ticket_cats (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, team_id INT NULL, color VARCHAR(7) NOT NULL DEFAULT '#3b82f6',
        active TINYINT(1) NOT NULL DEFAULT 1, position INT NOT NULL DEFAULT 0
    ) DEFAULT CHARSET=utf8mb4");
    // SLA: minutos para la primera respuesta y para resolver, por categoría (0 = todas) y prioridad
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_sla (
        category_id INT NOT NULL DEFAULT 0, priority VARCHAR(40) NOT NULL, response_min INT NOT NULL, resolution_min INT NOT NULL,
        PRIMARY KEY (category_id, priority)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_tickets (
        id INT AUTO_INCREMENT PRIMARY KEY, subject VARCHAR(200) NOT NULL, description TEXT NOT NULL, category_id INT NULL, team_id INT NULL,
        priority VARCHAR(40) NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'abierto', requester_id INT NOT NULL, assignee_id INT NULL,
        first_response_due DATETIME NULL, resolution_due DATETIME NULL, first_response_at DATETIME NULL, resolved_at DATETIME NULL, closed_at DATETIME NULL,
        escalated TINYINT NOT NULL DEFAULT 0, escalated_at DATETIME NULL, breach_flags TINYINT NOT NULL DEFAULT 0, task_id INT NULL,
        created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        KEY team_id (team_id), KEY requester_id (requester_id), KEY assignee_id (assignee_id), KEY status (status)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_ticket_msgs (
        id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, user_id INT NULL, body TEXT NOT NULL, internal TINYINT(1) NOT NULL DEFAULT 0,
        kind VARCHAR(8) NOT NULL DEFAULT 'msg', created_at DATETIME NOT NULL, KEY ticket_id (ticket_id)
    ) DEFAULT CHARSET=utf8mb4");
    // Configuración de partida (se cambia en Tickets › Configuración): SLA por prioridad y una categoría general
    if (!(int)$db->query('SELECT COUNT(*) FROM g7_wk_sla')->fetchColumn()) {
        $ins = $db->prepare('INSERT INTO g7_wk_sla (category_id, priority, response_min, resolution_min) VALUES (0, ?, ?, ?)');
        foreach (['baja' => [480, 4320], 'media' => [240, 2880], 'alta' => [120, 1440], 'urgente' => [60, 480], 'critica' => [30, 240]] as $p => [$r, $s]) $ins->execute([$p, $r, $s]);
    }
    if (!(int)$db->query('SELECT COUNT(*) FROM g7_wk_ticket_cats')->fetchColumn())
        $db->exec("INSERT INTO g7_wk_ticket_cats (name, team_id, color, position) VALUES ('Soporte general', NULL, '#3b82f6', 1)");
    $db->exec("INSERT INTO g7_config (cfg_key, cfg_value) VALUES ('work_schema', '4') ON DUPLICATE KEY UPDATE cfg_value = '4'");
}

const WK_REQ_STATUS = [
    'nueva' => ['Nueva', '#3b82f6'], 'en_revision' => ['En revisión', '#f59e0b'], 'aprobada' => ['Aprobada', '#22c55e'],
    'rechazada' => ['Rechazada', '#ef4444'], 'convertida' => ['En marcha', '#a855f7'], 'cerrada' => ['Cerrada', '#8b8b94'],
];
const WK_TK_STATUS = [
    'abierto' => ['Abierto', '#3b82f6'], 'en_curso' => ['En curso', '#f59e0b'], 'esperando' => ['Esperando respuesta', '#a855f7'],
    'resuelto' => ['Resuelto', '#22c55e'], 'cerrado' => ['Cerrado', '#8b8b94'],
];
const WK_FORM_TYPES = ['section' => 'Título de sección', 'text' => 'Texto corto', 'textarea' => 'Texto largo', 'number' => 'Número', 'date' => 'Fecha', 'select' => 'Lista de opciones', 'check' => 'Casilla', 'person' => 'Persona', 'url' => 'Link'];

/** ¿Atiende pedidos de este departamento? (admin/director siempre; si no, ser de ese equipo). Sin departamento: solo admin/director. */
function wk_is_agent(array $ctx, ?int $teamId): bool {
    if ($ctx['all']) return true;
    return $teamId && in_array($teamId, $ctx['teams'], true);
}
/**
 * ¿Atiende ESTE ticket? Una sola regla para Trabajo (api.php wk_ticket) y el portal (portal_api.php pa_ticket), que antes
 * diferían: quien atiende el departamento, el responsable, el aprobador (portal) y los responsables de su tarea (ticket = tarea).
 * Quien atiende ve las notas internas y puede cambiar el ticket.
 */
function wk_tk_is_agent(PDO $db, array $ctx, array $t): bool {
    if (wk_is_agent($ctx, !empty($t['team_id']) ? (int)$t['team_id'] : null)) return true;
    if (!empty($t['assignee_id']) && (int)$t['assignee_id'] === $ctx['id']) return true;
    if (!empty($t['approver_id']) && (int)$t['approver_id'] === $ctx['id']) return true;
    if (!empty($t['task_id'])) { $q = $db->prepare('SELECT 1 FROM g7_task_assignees WHERE task_id = ? AND user_id = ?'); $q->execute([(int)$t['task_id'], $ctx['id']]); if ($q->fetchColumn()) return true; }
    return false;
}
/** Departamentos que atiende alguien (null = todos). */
function wk_agent_teams(array $ctx): ?array { return $ctx['all'] ? null : $ctx['teams']; }

/** Aviso en la campana (tipos wk_req / wk_tk; target_id = solicitud o ticket). Nunca a quien hizo la acción. */
function wk_notify(PDO $db, string $type, array $users, int $target, ?int $from, string $body): void {
    $users = array_values(array_unique(array_filter(array_map('intval', $users), fn($u) => $u > 0 && $u !== (int)$from)));
    if (!$users) return;
    $ins = $db->prepare('INSERT INTO g7_notifications (user_id, from_id, type, target_id, profile_id, body) VALUES (?, ?, ?, ?, NULL, ?)');
    foreach ($users as $u) try { $ins->execute([$u, $from ?: null, $type, $target, mb_substr($body, 0, 280)]); } catch (Throwable $e) {}
}

/** Gente de un departamento (o los admins si no hay departamento). $leads = solo sus jefes. */
function wk_team_people(PDO $db, ?int $teamId, bool $leads = false): array {
    if (!$teamId) return array_map('intval', $db->query("SELECT id FROM g7_users WHERE active = 1 AND role = 'admin'")->fetchAll(PDO::FETCH_COLUMN));
    $q = $db->prepare('SELECT m.user_id FROM g7_team_members m JOIN g7_users u ON u.id = m.user_id AND u.active = 1 WHERE m.team_id = ?' . ($leads ? " AND m.role = 'admin'" : ''));
    $q->execute([$teamId]);
    $out = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    return $out ?: ($leads ? wk_team_people($db, null) : []);
}

/** Crea una tarea en un tablero (desde una solicitud o un ticket): primer estado del tablero, responsables y aviso. Devuelve el id. */
function wk_create_task(PDO $db, int $uid, int $boardId, string $title, string $desc, array $assignees, ?string $due, string $priority, ?int $projectId = null): int {
    $b = wk_board($db, $boardId);
    if (!$b) throw new RuntimeException('Ese tablero no existe');
    $cfg = tk_config($db);
    $st = wk_statuses($db, $b, $cfg)[0]['id'];
    if (!in_array($priority, array_column($cfg['priorities'], 'id'), true)) $priority = $cfg['default_priority'];
    $now = tk_now();
    $max = $db->prepare('SELECT COALESCE(MAX(position), 0) FROM g7_tasks WHERE status = ?'); $max->execute([$st]);
    $db->prepare('INSERT INTO g7_tasks (board_id, project_id, title, description, status, priority, due_date, labels, position, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$boardId, $projectId, mb_substr($title, 0, 250), mb_substr($desc, 0, 20000), $st, $priority, $due, '[]', (float)$max->fetchColumn() + 1, $uid, $now, $now]);
    $id = (int)$db->lastInsertId();
    $ok = [];
    $ins = $db->prepare('INSERT IGNORE INTO g7_task_assignees (task_id, user_id) VALUES (?, ?)');
    foreach (array_unique(array_map('intval', $assignees)) as $a) {
        $q = $db->prepare('SELECT id FROM g7_users WHERE id = ? AND active = 1'); $q->execute([$a]);
        if ($q->fetch()) { $ins->execute([$id, $a]); $ok[] = $a; }
    }
    tk_log($db, $id, $uid, 'created');
    if ($ok) tk_notify($db, 'assigned', $ok, ['id' => $id, 'title' => $title], $uid);
    if (function_exists('wk_task_events')) wk_task_events($db, null, ['id' => $id, 'status' => $st, 'priority' => $priority, 'completed_at' => null], $ok, $uid);
    return $id;
}

/** Vencimientos de SLA para un ticket: la regla de su categoría y prioridad, o la general de esa prioridad. */
function wk_sla_for(PDO $db, ?int $catId, string $priority): ?array {
    $q = $db->prepare('SELECT response_min, resolution_min FROM g7_wk_sla WHERE priority = ? AND category_id IN (?, 0) ORDER BY category_id DESC LIMIT 1');
    $q->execute([$priority, (int)$catId]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ? ['response' => (int)$r['response_min'], 'resolution' => (int)$r['resolution_min']] : null;
}

/**
 * Revisa los SLA vencidos (como mucho cada 5 minutos, cuando alguien usa Gestión de trabajo, o desde trabajo/cron.php):
 * primera respuesta vencida → avisa al asignado y a los jefes; resolución vencida → escala (nivel 1) y avisa a los jefes.
 */
function wk_sla_sweep(PDO $db, bool $force = false): void {
    try {
        $last = (int)$db->query("SELECT cfg_value FROM g7_config WHERE cfg_key = 'work_sla_at'")->fetchColumn();
        if (!$force && time() - $last < 300) return;
        $db->prepare("INSERT INTO g7_config (cfg_key, cfg_value) VALUES ('work_sla_at', ?) ON DUPLICATE KEY UPDATE cfg_value = VALUES(cfg_value)")->execute([(string)time()]);
        if (!$db->query("SELECT GET_LOCK('wk_sla', 0)")->fetchColumn()) return;
        $now = tk_now();
        // "Esperando respuesta" frena el reloj; los tickets que nacieron como tarea no se escalan (la tarea ya avisa de su fecha)
        $rows = $db->query("SELECT * FROM g7_wk_tickets WHERE status IN ('abierto', 'en_curso') AND origin <> 'tarea' AND paused_at IS NULL AND (
            (first_response_at IS NULL AND first_response_due < '$now' AND (breach_flags & 1) = 0) OR (resolution_due < '$now' AND (breach_flags & 2) = 0))")->fetchAll(PDO::FETCH_ASSOC);
        $log = $db->prepare("INSERT INTO g7_wk_ticket_msgs (ticket_id, user_id, body, internal, kind, created_at) VALUES (?, NULL, ?, 1, 'event', ?)");
        foreach ($rows as $t) {
            $id = (int)$t['id']; $flags = (int)$t['breach_flags'];
            $leads = wk_team_people($db, $t['team_id'] ? (int)$t['team_id'] : null, true);
            if (!$t['first_response_at'] && $t['first_response_due'] < $now && !($flags & 1)) {
                $flags |= 1;
                $log->execute([$id, 'Se venció el plazo de primera respuesta.', $now]);
                wk_tk_notify($db, 'tk_breach', array_merge($leads, $t['assignee_id'] ? [(int)$t['assignee_id']] : []), $t, null, 'Se venció el plazo de primera respuesta.');
            }
            if ($t['resolution_due'] < $now && !($flags & 2)) {
                $flags |= 2;
                $db->prepare('UPDATE g7_wk_tickets SET escalated = GREATEST(escalated, 1), escalated_at = COALESCE(escalated_at, ?) WHERE id = ?')->execute([$now, $id]);
                $log->execute([$id, 'Se venció el plazo de resolución: el ticket se escaló a los jefes del departamento.', $now]);
                wk_tk_notify($db, 'tk_breach', array_merge($leads, $t['assignee_id'] ? [(int)$t['assignee_id']] : []), $t, null, 'Se venció el plazo de resolución: se escaló a los jefes del departamento.');
            }
            $db->prepare('UPDATE g7_wk_tickets SET breach_flags = ? WHERE id = ?')->execute([$flags, $id]);
        }
        wk_tk_warn($db);   // por vencer y resumen diario
        $db->query("SELECT RELEASE_LOCK('wk_sla')");
        wk_auto_time($db);
    } catch (Throwable $e) {}
}

function wk_user_name(PDO $db, int $id): string {
    $q = $db->prepare("SELECT COALESCE(NULLIF(display_name, ''), username) FROM g7_users WHERE id = ?"); $q->execute([$id]);
    return (string)$q->fetchColumn() ?: '#' . $id;
}

/** v5 (fase 5): automatizaciones (disparador + condiciones + acciones, con historial) y procesos de varios pasos con aprobaciones. */
function wk_install_v5(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_automations (
        id INT AUTO_INCREMENT PRIMARY KEY, board_id INT NULL, name VARCHAR(120) NOT NULL, trigger_key VARCHAR(20) NOT NULL, trigger_opt VARCHAR(80) NOT NULL DEFAULT '',
        conditions TEXT NOT NULL, actions TEXT NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, runs INT NOT NULL DEFAULT 0, last_run_at DATETIME NULL,
        created_by INT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, KEY board_id (board_id), KEY trigger_key (trigger_key)
    ) DEFAULT CHARSET=utf8mb4");
    // fire_key evita repetir un disparador por fecha (vence/venció) para la misma tarea y fecha
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_auto_log (
        id INT AUTO_INCREMENT PRIMARY KEY, automation_id INT NOT NULL, task_id INT NULL, trigger_key VARCHAR(20) NOT NULL, fire_key VARCHAR(40) NULL,
        ok TINYINT(1) NOT NULL DEFAULT 1, detail VARCHAR(1000) NOT NULL DEFAULT '', created_at DATETIME NOT NULL,
        KEY automation_id (automation_id), UNIQUE KEY fire (automation_id, task_id, fire_key)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_processes (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, description VARCHAR(1000) NOT NULL DEFAULT '', team_id INT NULL,
        steps TEXT NOT NULL, starters VARCHAR(10) NOT NULL DEFAULT 'all', active TINYINT(1) NOT NULL DEFAULT 1, color VARCHAR(7) NOT NULL DEFAULT '#ff6600',
        created_by INT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_runs (
        id INT AUTO_INCREMENT PRIMARY KEY, process_id INT NOT NULL, title VARCHAR(200) NOT NULL, notes TEXT NULL, steps TEXT NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'running', started_by INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, finished_at DATETIME NULL,
        KEY process_id (process_id), KEY status (status)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_run_steps (
        id INT AUTO_INCREMENT PRIMARY KEY, run_id INT NOT NULL, step_key VARCHAR(20) NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'active',
        assignee_id INT NULL, task_id INT NULL, decided_by INT NULL, note VARCHAR(1000) NOT NULL DEFAULT '', started_at DATETIME NOT NULL, done_at DATETIME NULL,
        KEY run_id (run_id), KEY task_id (task_id), KEY assignee_id (assignee_id)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("INSERT INTO g7_config (cfg_key, cfg_value) VALUES ('work_schema', '5') ON DUPLICATE KEY UPDATE cfg_value = '5'");
}

// ── Automatizaciones ─────────────────────────────────────────
const WK_TRIGGERS = [
    'created'  => 'Se crea una tarea',
    'status'   => 'Cambia el estado',
    'done'     => 'Se termina una tarea',
    'priority' => 'Cambia la prioridad',
    'assigned' => 'Se asigna a alguien',
    'comment'  => 'Comentan en una tarea',
    'due_soon' => 'Falta poco para la fecha límite',
    'overdue'  => 'Se pasa la fecha límite',
];
const WK_COND_FIELDS = ['status' => 'Estado', 'priority' => 'Prioridad', 'assignee' => 'Responsable', 'unassigned' => 'Sin responsable', 'label' => 'Etiqueta', 'client' => 'Cliente', 'title' => 'Título', 'project' => 'Proyecto'];
const WK_ACTIONS = [
    'set_status' => 'Cambiar el estado', 'set_priority' => 'Cambiar la prioridad', 'assign' => 'Asignar a', 'unassign' => 'Sacar a todos los responsables',
    'add_label' => 'Agregar etiqueta', 'set_due' => 'Poner fecha límite (días desde hoy)', 'notify' => 'Avisar', 'comment' => 'Dejar un comentario',
    'subtask' => 'Crear una subtarea', 'move_board' => 'Mover a otro tablero', 'archive' => 'Archivar',
];

/** Tarea completa para evaluar reglas. */
function wk_auto_task(PDO $db, int $id): ?array {
    $q = $db->prepare('SELECT t.*, (SELECT GROUP_CONCAT(a.user_id) FROM g7_task_assignees a WHERE a.task_id = t.id) AS asg FROM g7_tasks t WHERE t.id = ?'); $q->execute([$id]);
    $t = $q->fetch(PDO::FETCH_ASSOC);
    if (!$t) return null;
    $t['assignees'] = $t['asg'] ? array_map('intval', explode(',', $t['asg'])) : [];
    $t['labels'] = json_decode($t['labels'] ?: '[]', true) ?: [];
    $t['board_id'] = (int)($t['board_id'] ?: wk_general_id($db));
    return $t;
}
function wk_auto_match(array $t, array $conds, array $ev): bool {
    foreach ($conds as $c) {
        $v = (string)($c['value'] ?? ''); $op = $c['op'] ?? 'is';
        switch ($c['field'] ?? '') {
            case 'status': $hit = $t['status'] === $v; break;
            case 'priority': $hit = $t['priority'] === $v; break;
            case 'assignee': $hit = in_array((int)$v, $t['assignees'], true); break;
            case 'unassigned': $hit = !$t['assignees']; break;
            case 'label': $hit = in_array($v, $t['labels'], true); break;
            case 'client': $hit = (int)$t['client_id'] === (int)$v; break;
            case 'project': $hit = (int)$t['project_id'] === (int)$v; break;
            case 'title': $hit = $v !== '' && mb_stripos($t['title'], $v) !== false; break;
            default: $hit = true;
        }
        if ($op === 'is_not' ? $hit : !$hit) return false;
    }
    return true;
}
/** Hace las acciones de una regla sobre una tarea. Las acciones no vuelven a disparar reglas (así no hay bucles). Devuelve el resumen. */
function wk_auto_do(PDO $db, array $a, array $t): string {
    $done = []; $now = tk_now(); $cfg = tk_config($db);
    $by = (int)$a['created_by'] ?: null;
    foreach ($a['actions'] as $x) {
        $v = (string)($x['value'] ?? '');
        switch ($x['type'] ?? '') {
            case 'set_status':
                $b = wk_board($db, $t['board_id']); $sts = wk_statuses($db, $b, $cfg);
                if (!in_array($v, array_column($sts, 'id'), true)) { $done[] = 'estado no válido en este tablero'; break; }
                $isDone = (bool)(array_column($sts, 'done', 'id')[$v] ?? false);
                if ($isDone) $db->prepare('UPDATE g7_tasks SET status = ?, completed_at = COALESCE(completed_at, ?), updated_at = ? WHERE id = ?')->execute([$v, $now, $now, $t['id']]);
                else $db->prepare('UPDATE g7_tasks SET status = ?, completed_at = NULL, updated_at = ? WHERE id = ?')->execute([$v, $now, $t['id']]);
                $done[] = 'estado → ' . tk_name_of($sts, $v); break;
            case 'set_priority':
                if (!in_array($v, array_column($cfg['priorities'], 'id'), true)) break;
                $db->prepare('UPDATE g7_tasks SET priority = ?, updated_at = ? WHERE id = ?')->execute([$v, $now, $t['id']]);
                $done[] = 'prioridad → ' . tk_name_of($cfg['priorities'], $v); break;
            case 'assign':
                $u = (int)$v; $q = $db->prepare('SELECT id FROM g7_users WHERE id = ? AND active = 1'); $q->execute([$u]); if (!$q->fetch()) break;
                $ins = $db->prepare('INSERT IGNORE INTO g7_task_assignees (task_id, user_id) VALUES (?, ?)'); $ins->execute([$t['id'], $u]);
                if ($ins->rowCount()) tk_notify($db, 'assigned', [$u], $t, $by);
                $done[] = 'asignada a ' . wk_user_name($db, $u); break;
            case 'unassign':
                $db->prepare('DELETE FROM g7_task_assignees WHERE task_id = ?')->execute([$t['id']]); $done[] = 'sin responsables'; break;
            case 'add_label':
                if (!in_array($v, array_column($cfg['labels'], 'id'), true) || in_array($v, $t['labels'], true)) break;
                $db->prepare('UPDATE g7_tasks SET labels = ?, updated_at = ? WHERE id = ?')->execute([json_encode(array_values(array_merge($t['labels'], [$v]))), $now, $t['id']]);
                $done[] = 'etiqueta ' . tk_name_of($cfg['labels'], $v); break;
            case 'set_due':
                $d = date('Y-m-d', strtotime('+' . max(0, min(365, (int)$v)) . ' days'));
                $db->prepare('UPDATE g7_tasks SET due_date = ?, updated_at = ? WHERE id = ?')->execute([$d, $now, $t['id']]);
                $done[] = 'fecha límite ' . date('d/m', strtotime($d)); break;
            case 'notify':
                $to = (string)($x['to'] ?? 'assignees');
                $users = $to === 'creator' ? [(int)$t['created_by']] : ($to === 'leads' ? wk_team_people($db, (wk_board($db, $t['board_id'])['team_id'] ?? null) ?: null, true) : (str_starts_with($to, 'user:') ? [(int)substr($to, 5)] : $t['assignees']));
                $msg = mb_substr(trim($v) ?: $a['name'], 0, 200);
                wk_notify($db, 'task_auto', $users, (int)$t['id'], null, $t['title'] . ' — ' . $msg);
                $done[] = 'aviso a ' . count(array_filter($users)) . ' persona' . (count(array_filter($users)) === 1 ? '' : 's'); break;
            case 'comment':
                if (!$by || trim($v) === '') break;
                $db->prepare('INSERT INTO g7_task_comments (task_id, user_id, body, created_at) VALUES (?, ?, ?, ?)')->execute([$t['id'], $by, '⚙ ' . mb_substr($v, 0, 4000) . ' (automatización "' . $a['name'] . '")', $now]);
                $done[] = 'comentario'; break;
            case 'subtask':
                if (trim($v) === '' || $t['parent_id']) break;
                $sts = wk_statuses($db, wk_board($db, $t['board_id']), $cfg);
                $db->prepare('INSERT INTO g7_tasks (board_id, parent_id, project_id, milestone_id, title, description, status, priority, labels, position, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, \'\', ?, ?, \'[]\', 0, ?, ?, ?)')
                   ->execute([$t['board_id'], $t['id'], $t['project_id'], $t['milestone_id'], mb_substr($v, 0, 250), $sts[0]['id'], $t['priority'], $by, $now, $now]);
                $done[] = 'subtarea "' . mb_substr($v, 0, 40) . '"'; break;
            case 'move_board':
                $b = wk_board($db, (int)$v); if (!$b || $b['archived'] || $b['id'] === $t['board_id'] || $t['parent_id']) break;
                $sts = wk_statuses($db, $b, $cfg);
                $st = in_array($t['status'], array_column($sts, 'id'), true) ? $t['status'] : $sts[0]['id'];
                $db->prepare('UPDATE g7_tasks SET board_id = ?, status = ?, updated_at = ? WHERE id = ? OR parent_id = ?')->execute([$b['id'], $st, $now, $t['id'], $t['id']]);
                wk_sync_completed($db, $sts, 'id = ' . (int)$t['id'] . ' OR parent_id = ' . (int)$t['id']);
                $done[] = 'movida a ' . $b['name']; break;
            case 'archive':
                $db->prepare('UPDATE g7_tasks SET archived = 1, updated_at = ? WHERE id = ?')->execute([$now, $t['id']]); $done[] = 'archivada'; break;
        }
    }
    $sum = implode(', ', $done) ?: 'sin cambios';
    tk_log($db, (int)$t['id'], null, 'auto', $a['name'] . ': ' . $sum);
    wk_tk_from_task($db, (int)$t['id'], $by);   // lo que cambió la regla también le llega al ticket
    return $sum;
}
/** Corre las reglas activas de un disparador para una tarea. $ev = datos del cambio (from/to, etc.). */
function wk_auto_run(PDO $db, string $trigger, int $taskId, array $ev = [], ?string $fireKey = null): void {
    static $depth = 0;
    if ($depth > 0) return;   // una acción de una regla nunca dispara otra regla
    $depth++;
    try {
        $t = wk_auto_task($db, $taskId);
        if ($t && !$t['archived']) {
            $q = $db->prepare('SELECT * FROM g7_wk_automations WHERE active = 1 AND trigger_key = ? AND (board_id IS NULL OR board_id = ?)');
            $q->execute([$trigger, $t['board_id']]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $a['conditions'] = json_decode($a['conditions'] ?: '[]', true) ?: [];
                $a['actions'] = json_decode($a['actions'] ?: '[]', true) ?: [];
                // Opción del disparador: estado de destino o prioridad de destino
                if ($a['trigger_opt'] !== '' && in_array($trigger, ['status', 'priority'], true) && ($ev['to'] ?? '') !== $a['trigger_opt']) continue;
                if (!wk_auto_match($t, $a['conditions'], $ev)) continue;
                $now = tk_now();
                if ($fireKey) {
                    $ins = $db->prepare('INSERT IGNORE INTO g7_wk_auto_log (automation_id, task_id, trigger_key, fire_key, ok, detail, created_at) VALUES (?, ?, ?, ?, 1, ?, ?)');
                    $ins->execute([$a['id'], $taskId, $trigger, $fireKey, '', $now]);
                    if (!$ins->rowCount()) continue;   // ya corrió para esta fecha
                    $logId = (int)$db->lastInsertId();
                } else {
                    $db->prepare('INSERT INTO g7_wk_auto_log (automation_id, task_id, trigger_key, ok, detail, created_at) VALUES (?, ?, ?, 1, ?, ?)')->execute([$a['id'], $taskId, $trigger, '', $now]);
                    $logId = (int)$db->lastInsertId();
                }
                try { $sum = wk_auto_do($db, $a, $t); $ok = 1; }
                catch (Throwable $e) { $sum = 'Error: ' . $e->getMessage(); $ok = 0; }
                $db->prepare('UPDATE g7_wk_auto_log SET ok = ?, detail = ? WHERE id = ?')->execute([$ok, mb_substr($sum, 0, 1000), $logId]);
                $db->prepare('UPDATE g7_wk_automations SET runs = runs + 1, last_run_at = ? WHERE id = ?')->execute([$now, $a['id']]);
                $t = wk_auto_task($db, $taskId) ?? $t;   // la siguiente regla ve los cambios de esta
            }
        }
    } catch (Throwable $e) {}
    $depth--;
}
/** Disparadores por fecha (vence pronto / vencida), una vez por tarea y fecha. Corre junto con el control de SLA. */
function wk_auto_time(PDO $db): void {
    try {
        $rules = $db->query("SELECT id, board_id, trigger_key, trigger_opt FROM g7_wk_automations WHERE active = 1 AND trigger_key IN ('due_soon', 'overdue')")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rules) return;
        $g = wk_general_id($db); $today = date('Y-m-d');
        foreach ($rules as $r) {
            $days = $r['trigger_key'] === 'due_soon' ? max(0, min(30, (int)$r['trigger_opt'] ?: 1)) : 0;
            $w = $r['trigger_key'] === 'overdue' ? "due_date < '$today'" : "due_date BETWEEN '$today' AND '" . date('Y-m-d', strtotime("+$days days")) . "'";
            $q = $db->query("SELECT id, due_date FROM g7_tasks WHERE archived = 0 AND completed_at IS NULL AND $w" . ($r['board_id'] ? " AND COALESCE(board_id, $g) = " . (int)$r['board_id'] : '') . ' LIMIT 500');
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) wk_auto_run($db, $r['trigger_key'], (int)$t['id'], [], $r['trigger_key'] . ':' . $t['due_date']);
        }
    } catch (Throwable $e) {}
}

/**
 * Lo que pasó en una tarea (lo llama tareas_api.php después de guardar y wk_create_task al crear):
 * dispara las automatizaciones y avanza los procesos cuya tarea de paso se terminó.
 */
function wk_task_events(PDO $db, ?array $old, array $new, array $added = [], ?int $by = null): void {
    $id = (int)$new['id'];
    if (!$old) wk_auto_run($db, 'created', $id);
    else {
        if ($old['status'] !== $new['status']) wk_auto_run($db, 'status', $id, ['from' => $old['status'], 'to' => $new['status']]);
        if ($old['priority'] !== $new['priority']) wk_auto_run($db, 'priority', $id, ['from' => $old['priority'], 'to' => $new['priority']]);
    }
    if ($added) wk_auto_run($db, 'assigned', $id, ['users' => $added]);
    if ((!$old || !$old['completed_at']) && !empty($new['completed_at'])) {
        wk_auto_run($db, 'done', $id);
        wk_run_task_done($db, $id);
    }
    wk_tk_on_task($db, $old, $id, $by);   // su ticket se pone al día (o nace, si la tarea es nueva)
}

// ── Procesos ─────────────────────────────────────────────────
const WK_STEP_TYPES = ['task' => 'Tarea', 'approval' => 'Aprobación', 'notify' => 'Aviso'];

/** Pasos de un proceso, limpios: [{key, type, name, assignee (user:ID|starter|leads), board, due_days, next, next_no}] */
function wk_clean_steps(array $list): array {
    $out = []; $keys = [];
    foreach (array_slice($list, 0, 40) as $s) {
        $k = preg_replace('/[^a-z0-9_]/', '', (string)($s['key'] ?? '')) ?: 's' . substr(md5(microtime() . count($out)), 0, 6);
        while (isset($keys[$k])) $k .= 'x';
        $keys[$k] = true;
        $out[] = ['key' => $k, 'type' => isset(WK_STEP_TYPES[$s['type'] ?? '']) ? $s['type'] : 'task', 'name' => mb_substr(trim((string)($s['name'] ?? '')), 0, 150) ?: 'Paso',
                  'description' => mb_substr(trim((string)($s['description'] ?? '')), 0, 1000), 'assignee' => preg_match('/^(user:\d+|starter|leads)$/', (string)($s['assignee'] ?? '')) ? $s['assignee'] : 'starter',
                  'board' => (int)($s['board'] ?? 0), 'due_days' => max(0, min(365, (int)($s['due_days'] ?? 0))), 'next' => (string)($s['next'] ?? ''), 'next_no' => (string)($s['next_no'] ?? '')];
    }
    // Las flechas solo pueden apuntar a pasos que existen ('' = termina el proceso)
    foreach ($out as &$s) { foreach (['next', 'next_no'] as $n) if ($s[$n] !== '' && !isset($keys[$s[$n]])) $s[$n] = ''; if ($s['type'] !== 'approval') $s['next_no'] = ''; }
    return $out;
}
function wk_step_assignee(PDO $db, array $step, array $run, ?int $teamId): ?int {
    if (str_starts_with($step['assignee'], 'user:')) return (int)substr($step['assignee'], 5) ?: null;
    if ($step['assignee'] === 'leads') return wk_team_people($db, $teamId, true)[0] ?? null;
    return (int)$run['started_by'];
}
/** Activa un paso de una ejecución: crea su tarea, pide la aprobación o manda el aviso (y sigue). */
function wk_run_activate(PDO $db, int $runId, string $key, int $depth = 0): void {
    if ($depth > 40) return;
    $q = $db->prepare('SELECT r.*, p.team_id FROM g7_wk_runs r JOIN g7_wk_processes p ON p.id = r.process_id WHERE r.id = ?'); $q->execute([$runId]); $run = $q->fetch(PDO::FETCH_ASSOC);
    if (!$run || $run['status'] !== 'running') return;
    $steps = json_decode($run['steps'], true) ?: [];
    if ($key === '') {   // fin del camino
        $open = $db->prepare("SELECT COUNT(*) FROM g7_wk_run_steps WHERE run_id = ? AND status = 'active'"); $open->execute([$runId]);
        if (!(int)$open->fetchColumn()) {
            $db->prepare("UPDATE g7_wk_runs SET status = 'done', finished_at = ?, updated_at = ? WHERE id = ?")->execute([tk_now(), tk_now(), $runId]);
            wk_notify($db, 'wk_run', [(int)$run['started_by']], $runId, null, 'Terminó el proceso: ' . $run['title']);
        }
        return;
    }
    $step = null; foreach ($steps as $s) if ($s['key'] === $key) $step = $s;
    if (!$step) return;
    $team = $run['team_id'] ? (int)$run['team_id'] : null;
    $asg = wk_step_assignee($db, $step, $run, $team);
    // "Jefe del departamento" en una aprobación o aviso: le llega a todos los jefes y decide cualquiera (sin persona fija)
    if ($step['assignee'] === 'leads' && $step['type'] !== 'task') $asg = null;
    $now = tk_now();
    $db->prepare('INSERT INTO g7_wk_run_steps (run_id, step_key, status, assignee_id, started_at) VALUES (?, ?, ?, ?, ?)')->execute([$runId, $key, 'active', $asg, $now]);
    $sid = (int)$db->lastInsertId();
    $db->prepare('UPDATE g7_wk_runs SET updated_at = ? WHERE id = ?')->execute([$now, $runId]);
    if ($step['type'] === 'task') {
        $board = $step['board'] && wk_board($db, $step['board']) ? $step['board'] : wk_general_id($db);
        $tid = wk_create_task($db, (int)$run['started_by'], $board, $step['name'] . ' · ' . $run['title'],
            trim($step['description'] . "\n\nPaso del proceso \"" . $run['title'] . '": /toolz/trabajo/#procesos/run/' . $runId), $asg ? [$asg] : [],
            $step['due_days'] ? date('Y-m-d', strtotime('+' . $step['due_days'] . ' days')) : null, 'media');
        $db->prepare('UPDATE g7_wk_run_steps SET task_id = ? WHERE id = ?')->execute([$tid, $sid]);
    } elseif ($step['type'] === 'approval') {
        wk_notify($db, 'wk_run', $asg ? [$asg] : wk_team_people($db, $team, true), $runId, (int)$run['started_by'], 'Te piden aprobar: ' . $step['name'] . ' · ' . $run['title']);
    } else {   // aviso: se manda y el proceso sigue solo
        wk_notify($db, 'wk_run', $asg ? [$asg] : wk_team_people($db, $team, true), $runId, null, $step['name'] . ' · ' . $run['title'] . ($step['description'] !== '' ? ': ' . $step['description'] : ''));
        $db->prepare("UPDATE g7_wk_run_steps SET status = 'done', done_at = ? WHERE id = ?")->execute([$now, $sid]);
        wk_run_activate($db, $runId, $step['next'], $depth + 1);
    }
}
/** Cierra un paso activo (tarea terminada, aprobado o rechazado) y activa el siguiente. */
function wk_run_step_close(PDO $db, array $rs, string $status, ?int $by = null, string $note = ''): void {
    $db->prepare('UPDATE g7_wk_run_steps SET status = ?, decided_by = ?, note = ?, done_at = ? WHERE id = ? AND status = \'active\'')->execute([$status, $by, mb_substr($note, 0, 1000), tk_now(), $rs['id']]);
    $q = $db->prepare('SELECT steps FROM g7_wk_runs WHERE id = ?'); $q->execute([$rs['run_id']]);
    foreach (json_decode((string)$q->fetchColumn(), true) ?: [] as $s) if ($s['key'] === $rs['step_key']) wk_run_activate($db, (int)$rs['run_id'], $status === 'rejected' ? $s['next_no'] : $s['next']);
}
/** Una tarea de un paso se terminó: el proceso avanza. */
function wk_run_task_done(PDO $db, int $taskId): void {
    try {
        $q = $db->prepare("SELECT * FROM g7_wk_run_steps WHERE task_id = ? AND status = 'active'"); $q->execute([$taskId]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $rs) wk_run_step_close($db, $rs, 'done');
    } catch (Throwable $e) {}
}

/** Abre un ticket (sección Tickets o desde un mensaje del chat): calcula los plazos del SLA y avisa al departamento. Devuelve el id. */
/** $opt: origin (ticket|form|chat), form_id, answers (para los formularios del panel). Nace también su tarea. */
function wk_create_ticket(PDO $db, int $uid, int $catId, string $priority, string $subject, string $desc, array $opt = []): int {
    $q = $db->prepare('SELECT * FROM g7_wk_ticket_cats WHERE id = ? AND active = 1'); $q->execute([$catId]); $c = $q->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new RuntimeException('Elige una categoría');
    $subject = mb_substr(trim($subject), 0, 200); if ($subject === '') throw new RuntimeException('Pon un asunto');
    $desc = mb_substr(trim($desc), 0, 20000); if ($desc === '') throw new RuntimeException('Cuenta qué pasa');
    $cfg = tk_config($db);
    if (!in_array($priority, array_column($cfg['priorities'], 'id'), true)) $priority = $cfg['default_priority'];
    $sla = wk_sla_for($db, (int)$c['id'], $priority);
    $now = tk_now(); $ts = time();
    $origin = isset(WK_TK_ORIGINS[$opt['origin'] ?? '']) ? $opt['origin'] : 'ticket';
    $db->prepare('INSERT INTO g7_wk_tickets (subject, description, category_id, team_id, priority, requester_id, first_response_due, resolution_due, created_at, updated_at, origin, form_id, answers) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$subject, $desc, (int)$c['id'], $c['team_id'] ?: null, $priority, $uid, $sla ? date('Y-m-d H:i:s', $ts + $sla['response'] * 60) : null, $sla ? date('Y-m-d H:i:s', $ts + $sla['resolution'] * 60) : null, $now, $now,
                  $origin, !empty($opt['form_id']) ? (int)$opt['form_id'] : null, isset($opt['answers']) ? json_encode($opt['answers'], JSON_UNESCAPED_UNICODE) : null]);
    $id = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO g7_wk_ticket_msgs (ticket_id, user_id, body, internal, kind, created_at) VALUES (?, ?, ?, 0, 'event', ?)")->execute([$id, $uid, $origin === 'form' ? 'hizo el pedido desde el panel de solicitudes' : 'abrió el ticket', $now]);
    wk_tk_make_task($db, $id, $uid);
    $t = wk_tk_row($db, $id);
    wk_tk_notify($db, 'tk_new', wk_team_people($db, $c['team_id'] ? (int)$c['team_id'] : null), $t, $uid, mb_substr($desc, 0, 200));
    // Respuesta automática del área (trabajo/tk_canned_lib.php); si falla, el ticket igual queda creado
    try { require_once __DIR__ . '/tk_canned_lib.php'; tk_autoreply_send($db, $id); } catch (Throwable $e) {}
    return $id;
}

/** v6 (fase 6): versiones de los adjuntos de las tareas (cada versión nueva apunta a la primera). */
function wk_install_v6(PDO $db): void {
    $db->exec("ALTER TABLE g7_task_files ADD COLUMN IF NOT EXISTS version_of INT NULL");
    $db->exec("ALTER TABLE g7_task_files ADD COLUMN IF NOT EXISTS version INT NOT NULL DEFAULT 1");
    $db->exec("ALTER TABLE g7_task_files ADD INDEX IF NOT EXISTS version_of (version_of)");
    $db->exec("INSERT INTO g7_config (cfg_key, cfg_value) VALUES ('work_schema', '6') ON DUPLICATE KEY UPDATE cfg_value = '6'");
}

/** v7 (fase 7): índices para los informes y el Resumen. */
function wk_install_v7(PDO $db): void {
    $db->exec("ALTER TABLE g7_tasks ADD INDEX IF NOT EXISTS open_due (archived, completed_at, due_date)");
    $db->exec("ALTER TABLE g7_tasks ADD INDEX IF NOT EXISTS created_at (created_at)");
    $db->exec("ALTER TABLE g7_task_activity ADD INDEX IF NOT EXISTS created_at (created_at)");
    $db->exec("ALTER TABLE g7_wk_tickets ADD INDEX IF NOT EXISTS created_at (created_at)");
    $db->exec("ALTER TABLE g7_wk_requests ADD INDEX IF NOT EXISTS created_at (created_at)");
    $db->exec("INSERT INTO g7_config (cfg_key, cfg_value) VALUES ('work_schema', '7') ON DUPLICATE KEY UPDATE cfg_value = '7'");
}

/** Tabla con la que se agrupan las tareas por client_id: g7_clients (con Clientes) o g7_brands (sin Clientes); null si no hay ninguna. */
function wk_cli_table(PDO $db): ?string {
    static $t = false;
    if ($t !== false) return $t;
    foreach (['g7_clients', 'g7_brands'] as $x) if ($db->query("SHOW TABLES LIKE '$x'")->fetchColumn()) return $t = $x;
    return $t = null;
}
function wk_cli_label(PDO $db): string { return wk_cli_table($db) === 'g7_brands' ? 'Marca' : (function_exists('g7_term') ? g7_term('client') : 'Cliente'); }
/** Estados editables (status_lib.php, Admin › Estados) con la forma de las constantes de siempre: clave => [nombre, color]. */
function wk_st(string $list): array {
    if (function_exists('st_pairs') && ($p = st_pairs($list))) return $p;
    return ['projects' => WK_PROJECT_STATUS, 'requests' => WK_REQ_STATUS, 'tickets' => WK_TK_STATUS][$list] ?? [];
}
