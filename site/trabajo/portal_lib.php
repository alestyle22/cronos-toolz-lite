<?php
/**
 * trabajo/portal_lib.php — Portal de Solicitudes por departamento (2026-11-13): departamentos, roles, etapas,
 * disponibilidad, capacidad por día, reparto de la carga, fechas alternativas, asignación automática y automatizaciones.
 * Se apoya en lo que ya existía: equipos (g7_teams = departamentos), tickets (g7_wk_tickets, ticket = tarea),
 * avisos (TK_EVENTS) y formularios (g7_wk_forms, ahora con versiones). Doc: toolz/SOLICITUDES.md
 */
require_once __DIR__ . '/form_lib.php';
require_once __DIR__ . '/estimate_lib.php';

const PT_DAYS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
const PT_OFF_KINDS = ['feriado' => 'Feriado', 'vacaciones' => 'Vacaciones', 'ausencia' => 'Ausencia', 'bloqueo' => 'Bloqueo'];
const PT_URGENT = ['urgente', 'critica'];   // prioridades que pueden usar la reserva para urgencias
/** Etapas de fábrica: cada una se apoya en uno de los 5 estados principales del ticket (que siguen mandando). */
const PT_STAGES_DEFAULT = [
    ['id' => 'nuevo',      'name' => 'Nuevo',                 'status' => 'abierto',  'color' => '#3b82f6', 'icon' => 'fa-inbox'],
    ['id' => 'revision',   'name' => 'Pendiente de revisión', 'status' => 'abierto',  'color' => '#f59e0b', 'icon' => 'fa-hourglass-half'],
    ['id' => 'aprobado',   'name' => 'Aprobado',              'status' => 'abierto',  'color' => '#14b8a6', 'icon' => 'fa-thumbs-up'],
    ['id' => 'progreso',   'name' => 'En progreso',           'status' => 'en_curso', 'color' => '#6366f1', 'icon' => 'fa-person-digging'],
    ['id' => 'en_revision','name' => 'En revisión',           'status' => 'en_curso', 'color' => '#8b5cf6', 'icon' => 'fa-magnifying-glass'],
    ['id' => 'info',       'name' => 'Esperando información', 'status' => 'esperando','color' => '#a855f7', 'icon' => 'fa-circle-question'],
    ['id' => 'pausado',    'name' => 'Pausado',               'status' => 'esperando','color' => '#64748b', 'icon' => 'fa-pause'],
    ['id' => 'completado', 'name' => 'Completado',            'status' => 'resuelto', 'color' => '#22c55e', 'icon' => 'fa-circle-check'],
    ['id' => 'cancelado',  'name' => 'Cancelado',             'status' => 'cerrado',  'color' => '#ef4444', 'icon' => 'fa-ban'],
];
const PT_TRANS_DEFAULT = [
    'nuevo' => ['revision', 'aprobado', 'progreso', 'info', 'cancelado'],
    'revision' => ['aprobado', 'info', 'cancelado'],
    'aprobado' => ['progreso', 'info', 'pausado', 'cancelado'],
    'progreso' => ['en_revision', 'info', 'pausado', 'completado', 'cancelado'],
    'en_revision' => ['progreso', 'info', 'completado', 'cancelado'],
    'info' => ['nuevo', 'revision', 'aprobado', 'progreso', 'cancelado'],
    'pausado' => ['progreso', 'aprobado', 'cancelado'],
    'completado' => ['progreso'],
    'cancelado' => ['nuevo'],
];
/** Etapa que corresponde a un estado principal cuando el ticket cambia desde otro lado (Tickets, tarea, base). */
const PT_STATUS_STAGE = ['abierto' => 'nuevo', 'en_curso' => 'progreso', 'esperando' => 'info', 'resuelto' => 'completado', 'cerrado' => 'completado'];
const PT_DEFAULT_HOURS = ['1' => [['09:00', '18:00']], '2' => [['09:00', '18:00']], '3' => [['09:00', '18:00']], '4' => [['09:00', '18:00']], '5' => [['09:00', '18:00']], '6' => [], '7' => []];

// ── Esquema (work_schema 9) ──────────────────────────────────
function wk_install_v9(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_dept (
        team_id INT NOT NULL PRIMARY KEY, portal_on TINYINT(1) NOT NULL DEFAULT 1, description TEXT NULL, icon VARCHAR(40) NOT NULL DEFAULT 'fa-building',
        color VARCHAR(7) NOT NULL DEFAULT '#ff6600', banner VARCHAR(120) NOT NULL DEFAULT '', visibility VARCHAR(10) NOT NULL DEFAULT 'all', requesters TEXT NULL,
        hours TEXT NULL, cap_day_min INT NOT NULL DEFAULT 0, safety_pct INT NOT NULL DEFAULT 0, reserve_pct INT NOT NULL DEFAULT 0,
        min_lead_h INT NOT NULL DEFAULT 0, max_lead_d INT NOT NULL DEFAULT 180, green_pct INT NOT NULL DEFAULT 70, yellow_pct INT NOT NULL DEFAULT 100,
        overload VARCHAR(10) NOT NULL DEFAULT 'warn', show_estimate TINYINT(1) NOT NULL DEFAULT 1, est_unit VARCHAR(3) NOT NULL DEFAULT 'h',
        approval TINYINT(1) NOT NULL DEFAULT 0, auto_assign TINYINT(1) NOT NULL DEFAULT 0, position INT NOT NULL DEFAULT 0,
        updated_by INT NULL, updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Cada integrante: sus horas (null = las del departamento), dedicación, si produce, si es responsable y su especialidad (categorías)
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_member_cap (
        team_id INT NOT NULL, user_id INT NOT NULL, hours TEXT NULL, cap_pct INT NOT NULL DEFAULT 100, works TINYINT(1) NOT NULL DEFAULT 1,
        is_resp TINYINT(1) NOT NULL DEFAULT 0, skills VARCHAR(500) NOT NULL DEFAULT '', updated_at DATETIME NULL,
        PRIMARY KEY (team_id, user_id), KEY user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Feriados, vacaciones, ausencias y bloqueos. team NULL + user NULL = para todos; minutes NULL = día entero
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_dayoff (
        id INT AUTO_INCREMENT PRIMARY KEY, team_id INT NULL, user_id INT NULL, date_from DATE NOT NULL, date_to DATE NOT NULL,
        kind VARCHAR(12) NOT NULL DEFAULT 'ausencia', minutes INT NULL, note VARCHAR(200) NOT NULL DEFAULT '', created_by INT NULL, created_at DATETIME NOT NULL,
        KEY rng (date_from, date_to), KEY team_id (team_id), KEY user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Excepción por fecha: la capacidad total del departamento ese día (0 = cerrado; ej. un sábado que se trabaja)
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_day_cap (
        team_id INT NOT NULL, day DATE NOT NULL, minutes INT NOT NULL, note VARCHAR(200) NOT NULL DEFAULT '', PRIMARY KEY (team_id, day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Carga: el reparto por día de cada ticket (se borra y se vuelve a escribir entero en cada recálculo)
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_load (
        id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, team_id INT NOT NULL, user_id INT NULL, day DATE NOT NULL, minutes INT NOT NULL, is_over TINYINT(1) NOT NULL DEFAULT 0,
        KEY ticket_id (ticket_id), KEY team_day (team_id, day), KEY user_day (user_id, day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Horas reales cargadas en un ticket
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_tk_time (
        id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, user_id INT NOT NULL, day DATE NOT NULL, minutes INT NOT NULL, note VARCHAR(300) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL, KEY ticket_id (ticket_id), KEY user_day (user_id, day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Adjuntos del portal (formulario y conversación), guardados en _private/solicitudes/
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_tk_files (
        id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NULL, msg_id INT NULL, field_id VARCHAR(40) NULL, token CHAR(32) NOT NULL, name VARCHAR(200) NOT NULL,
        ext VARCHAR(10) NOT NULL, size INT NOT NULL DEFAULT 0, done TINYINT(1) NOT NULL DEFAULT 0, internal TINYINT(1) NOT NULL DEFAULT 0, user_id INT NOT NULL, created_at DATETIME NOT NULL,
        UNIQUE KEY token (token), KEY ticket_id (ticket_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_form_versions (
        id INT AUTO_INCREMENT PRIMARY KEY, form_id INT NOT NULL, version INT NOT NULL, schema_json MEDIUMTEXT NOT NULL, theme TEXT NULL,
        published_by INT NULL, published_at DATETIME NOT NULL, UNIQUE KEY fv (form_id, version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Borradores de quien pide ("guardar y seguir después")
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_pt_drafts (
        user_id INT NOT NULL, form_id INT NOT NULL, data MEDIUMTEXT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (user_id, form_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_pt_autos (
        id INT AUTO_INCREMENT PRIMARY KEY, team_id INT NULL, name VARCHAR(120) NOT NULL, event VARCHAR(20) NOT NULL, cond TEXT NULL, actions TEXT NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1, runs INT NOT NULL DEFAULT 0, last_run_at DATETIME NULL, created_by INT NULL, created_at DATETIME NOT NULL, KEY ev (event)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_pt_audit (
        id INT AUTO_INCREMENT PRIMARY KEY, team_id INT NULL, ticket_id INT NULL, user_id INT NULL, action VARCHAR(40) NOT NULL, detail TEXT NULL, created_at DATETIME NOT NULL,
        KEY ticket_id (ticket_id), KEY team_id (team_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Formularios: borrador, versión publicada, modo, tema, plantilla y ajustes (aprobación, estimación)
    foreach (["draft MEDIUMTEXT NULL", "version INT NOT NULL DEFAULT 0", "theme TEXT NULL", "is_template TINYINT(1) NOT NULL DEFAULT 0",
              "settings TEXT NULL", "schema_v TINYINT NOT NULL DEFAULT 1"] as $c) $db->exec("ALTER TABLE g7_wk_forms ADD COLUMN IF NOT EXISTS $c");
    // Categorías: árbol (categoría → subcategoría → variante), ícono, imagen, tiempos, componentes, reglas y formulario
    foreach (["parent_id INT NULL", "icon VARCHAR(40) NOT NULL DEFAULT ''", "image VARCHAR(120) NOT NULL DEFAULT ''", "description VARCHAR(500) NOT NULL DEFAULT ''",
              "base_min INT NOT NULL DEFAULT 0", "min_min INT NOT NULL DEFAULT 0", "max_min INT NOT NULL DEFAULT 0", "rules TEXT NULL", "form_id INT NULL", "components TEXT NULL"] as $c)
        $db->exec("ALTER TABLE g7_wk_ticket_cats ADD COLUMN IF NOT EXISTS $c");
    // Tickets: etapa, estimación, fechas, horas reales, versión del formulario, aprobación, sobrecarga y anti-duplicados
    foreach (["stage VARCHAR(20) NULL", "est_min INT NULL", "est_detail TEXT NULL", "real_min INT NOT NULL DEFAULT 0", "requested_date DATE NULL", "committed_date DATE NULL",
              "form_version_id INT NULL", "approval VARCHAR(10) NULL", "approver_id INT NULL", "overload TINYINT(1) NOT NULL DEFAULT 0", "overload_note VARCHAR(500) NOT NULL DEFAULT ''",
              "dedupe_key CHAR(40) NULL", "cancelled TINYINT(1) NOT NULL DEFAULT 0", "started_at DATETIME NULL", "completed_at DATETIME NULL"] as $c)
        $db->exec("ALTER TABLE g7_wk_tickets ADD COLUMN IF NOT EXISTS $c");
    $db->exec("ALTER TABLE g7_wk_tickets ADD UNIQUE INDEX IF NOT EXISTS dedupe_key (dedupe_key)");
    $db->exec("ALTER TABLE g7_wk_tickets ADD INDEX IF NOT EXISTS committed_date (committed_date)");
    $db->exec("ALTER TABLE g7_wk_ticket_cats ADD INDEX IF NOT EXISTS parent_id (parent_id)");
    // Los formularios que ya existían pasan al formato 2 y quedan publicados como versión 1 (D: el portal reemplaza al panel)
    $now = tk_now();
    foreach ($db->query('SELECT id, name, fields, active FROM g7_wk_forms WHERE schema_v < 2')->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $s = json_encode(pt_convert_v1(json_decode((string)$f['fields'], true) ?: [], ''), JSON_UNESCAPED_UNICODE);
        $db->prepare('INSERT IGNORE INTO g7_wk_form_versions (form_id, version, schema_json, theme, published_at) VALUES (?, 1, ?, NULL, ?)')->execute([(int)$f['id'], $s, $now]);
        $db->prepare('UPDATE g7_wk_forms SET draft = ?, version = 1, schema_v = 2 WHERE id = ?')->execute([$s, (int)$f['id']]);
    }
    // Etapa de los tickets que ya existían, según su estado
    foreach (PT_STATUS_STAGE as $st => $sg) $db->prepare('UPDATE g7_wk_tickets SET stage = ? WHERE stage IS NULL AND status = ?')->execute([$sg, $st]);
    $db->exec("INSERT INTO g7_config (cfg_key, cfg_value) VALUES ('work_schema', '9') ON DUPLICATE KEY UPDATE cfg_value = '9'");
}

function pt_now(): string { return tk_now(); }
function pt_today(): string { return date('Y-m-d'); }
function pt_audit(PDO $db, ?int $team, ?int $ticket, ?int $uid, string $action, $detail = null): void {
    try { $db->prepare('INSERT INTO g7_wk_pt_audit (team_id, ticket_id, user_id, action, detail, created_at) VALUES (?, ?, ?, ?, ?, ?)')
             ->execute([$team, $ticket, $uid, $action, $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE), pt_now()]); } catch (Throwable $e) {}
}

// ── Departamentos ────────────────────────────────────────────
/** Horario limpio {1..7: [[desde, hasta]…]}. */
function pt_clean_hours($h): array {
    $out = [];
    foreach (PT_DAYS as $d => $_) {
        $rs = [];
        foreach (array_slice((array)($h[$d] ?? $h[(string)$d] ?? []), 0, 6) as $r) {
            if (!is_array($r) || count($r) < 2) continue;
            [$a, $b] = [(string)$r[0], (string)$r[1]];
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $a) || !preg_match('/^([01]\d|2[0-4]):[0-5]\d$/', $b) || $b <= $a) continue;
            $rs[] = [$a, $b];
        }
        usort($rs, fn($x, $y) => strcmp($x[0], $y[0]));
        // sin superponerse
        $clean = []; foreach ($rs as $r) { if ($clean && $r[0] < end($clean)[1]) continue; $clean[] = $r; }
        $out[(string)$d] = $clean;
    }
    return $out;
}
function pt_hours_min(array $ranges): int {
    $m = 0; foreach ($ranges as [$a, $b]) $m += (intval(substr($b, 0, 2)) * 60 + intval(substr($b, 3))) - (intval(substr($a, 0, 2)) * 60 + intval(substr($a, 3)));
    return max(0, $m);
}

/** Ficha del departamento (la crea con los valores de fábrica la primera vez). null si el equipo no existe. */
function pt_dept(PDO $db, int $teamId): ?array {
    $q = $db->prepare('SELECT t.id, t.name, t.description AS team_desc, t.color AS team_color, t.active, d.* FROM g7_teams t LEFT JOIN g7_wk_dept d ON d.team_id = t.id WHERE t.id = ?');
    $q->execute([$teamId]); $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    if ($r['team_id'] === null) {
        $db->prepare('INSERT IGNORE INTO g7_wk_dept (team_id, description, color, hours, updated_at) VALUES (?, ?, ?, ?, ?)')
           ->execute([$teamId, (string)$r['team_desc'], preg_match('/^#[0-9a-f]{6}$/i', (string)$r['team_color']) ? $r['team_color'] : '#ff6600', json_encode(PT_DEFAULT_HOURS), pt_now()]);
        return pt_dept($db, $teamId);
    }
    return pt_dept_norm($r);
}
function pt_dept_norm(array $r): array {
    $o = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'active' => (int)$r['active']];
    foreach (['portal_on', 'cap_day_min', 'safety_pct', 'reserve_pct', 'min_lead_h', 'max_lead_d', 'green_pct', 'yellow_pct', 'show_estimate', 'approval', 'auto_assign', 'position'] as $k) $o[$k] = (int)$r[$k];
    foreach (['description', 'icon', 'color', 'banner', 'visibility', 'overload', 'est_unit'] as $k) $o[$k] = (string)$r[$k];
    $o['requesters'] = json_decode((string)$r['requesters'], true) ?: ['teams' => [], 'users' => []];
    $o['hours'] = pt_clean_hours(json_decode((string)$r['hours'], true) ?: PT_DEFAULT_HOURS);
    return $o;
}
function pt_depts(PDO $db, bool $activeOnly = true): array {
    $ids = $db->query('SELECT id FROM g7_teams' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    $out = []; foreach ($ids as $id) if ($d = pt_dept($db, (int)$id)) $out[] = $d;
    usort($out, fn($a, $b) => [$a['position'], $a['name']] <=> [$b['position'], $b['name']]);
    return $out;
}

// ── Roles del portal ─────────────────────────────────────────
/** Rol de alguien en un departamento: admin | dept_admin | responsable | member | requester | null. */
function pt_role(PDO $db, array $ctx, int $teamId): ?string {
    if ($ctx['role'] === 'admin' || $ctx['role'] === 'director') return 'admin';
    if (in_array($teamId, $ctx['lead'], true)) return 'dept_admin';
    if (in_array($teamId, $ctx['teams'], true)) {
        $q = $db->prepare('SELECT is_resp FROM g7_wk_member_cap WHERE team_id = ? AND user_id = ?'); $q->execute([$teamId, $ctx['id']]);
        return (int)$q->fetchColumn() ? 'responsable' : 'member';
    }
    $d = pt_dept($db, $teamId);
    return $d && pt_can_request($ctx, $d) ? 'requester' : null;
}
/** ¿Configura el departamento? (nombre, horarios, categorías, formularios). */
function pt_can_config(PDO $db, array $ctx, int $teamId): bool { return in_array(pt_role($db, $ctx, $teamId), ['admin', 'dept_admin'], true); }
/** ¿Aprueba, asigna y cambia fechas? */
function pt_can_manage(PDO $db, array $ctx, int $teamId): bool { return in_array(pt_role($db, $ctx, $teamId), ['admin', 'dept_admin', 'responsable'], true); }
/** ¿Puede pedirle algo a este departamento? */
function pt_can_request(array $ctx, array $d): bool {
    if ($ctx['all'] || in_array($d['id'], $ctx['teams'], true)) return true;
    if (!$d['portal_on'] || !$d['active']) return false;
    if ($d['visibility'] === 'all') return true;
    if ($d['visibility'] === 'teams') return (bool)array_intersect($ctx['teams'], array_map('intval', (array)($d['requesters']['teams'] ?? [])));
    if ($d['visibility'] === 'people') return in_array($ctx['id'], array_map('intval', (array)($d['requesters']['users'] ?? [])), true);
    return false;
}

// ── Etapas ───────────────────────────────────────────────────
function pt_stages(PDO $db): array {
    $s = json_decode((string)wk_cfg_get($db, 'work_pt_stages', ''), true);
    $list = is_array($s['stages'] ?? null) && $s['stages'] ? $s['stages'] : PT_STAGES_DEFAULT;
    // Las 9 de fábrica no se pueden borrar (solo renombrar y recolorear); las propias se suman
    $have = array_column($list, 'id');
    foreach (PT_STAGES_DEFAULT as $d) if (!in_array($d['id'], $have, true)) $list[] = $d;
    $trans = is_array($s['trans'] ?? null) ? $s['trans'] : PT_TRANS_DEFAULT;
    return ['stages' => array_values($list), 'trans' => $trans];
}
function pt_stage(PDO $db, ?string $id): ?array { foreach (pt_stages($db)['stages'] as $s) if ($s['id'] === $id) return $s; return null; }
/** Etapa nueva cuando el estado principal cambia desde fuera del portal. */
function pt_stage_for_status(PDO $db, ?string $cur, string $status): string {
    $c = pt_stage($db, $cur);
    if ($c && $c['status'] === $status) return $cur;
    if ($status === 'cerrado' && $cur === 'cancelado') return 'cancelado';
    return PT_STATUS_STAGE[$status] ?? 'nuevo';
}

/** Gancho de wk_tk_set (link_lib.php): mantiene la etapa en línea con el estado y recalcula la carga si cambió algo que la mueve. */
function pt_on_ticket_set(PDO $db, int $id, array $f): void {
    static $busy = false;
    if ($busy) return;
    $busy = true;
    try {
        if (isset($f['status']) && !isset($f['stage'])) {
            $t = wk_tk_row($db, $id);
            if ($t) {
                $sg = pt_stage_for_status($db, $t['stage'], (string)$f['status']);
                $now = pt_now();
                // Fechas de inicio y de fin, también cuando el cambio viene de Tickets, de la tarea o del sitio base
                $started = $t['started_at'] ?: ($f['status'] === 'en_curso' ? $now : null);
                $done = $sg === 'completado' ? ($t['completed_at'] ?: $now) : null;
                if ($sg !== $t['stage'] || $started !== $t['started_at'] || $done !== $t['completed_at'])
                    $db->prepare('UPDATE g7_wk_tickets SET stage = ?, started_at = ?, completed_at = ? WHERE id = ?')->execute([$sg, $started, $done, $id]);
            }
        }
        if (array_intersect(array_keys($f), ['status', 'stage', 'est_min', 'committed_date', 'requested_date', 'assignee_id', 'team_id', 'real_min', 'cancelled', 'approval', 'priority']))
            pt_recalc($db, $id);
    } finally { $busy = false; }
}

// ── Disponibilidad y capacidad ───────────────────────────────
/** Integrantes que producen en un departamento, con sus horas y dedicación. */
function pt_members(PDO $db, int $teamId): array {
    $q = $db->prepare("SELECT m.user_id, m.role, u.display_name, u.username, c.hours, c.cap_pct, c.works, c.is_resp, c.skills
        FROM g7_team_members m JOIN g7_users u ON u.id = m.user_id AND u.active = 1 LEFT JOIN g7_wk_member_cap c ON c.team_id = m.team_id AND c.user_id = m.user_id
        WHERE m.team_id = ? ORDER BY u.display_name");
    $q->execute([$teamId]);
    return array_map(fn($r) => ['id' => (int)$r['user_id'], 'name' => (string)($r['display_name'] ?: $r['username']), 'lead' => $r['role'] === 'admin',
        'hours' => $r['hours'] ? pt_clean_hours(json_decode((string)$r['hours'], true) ?: []) : null, 'cap_pct' => $r['cap_pct'] === null ? 100 : (int)$r['cap_pct'],
        'works' => $r['works'] === null ? 1 : (int)$r['works'], 'is_resp' => (int)$r['is_resp'], 'skills' => array_values(array_filter(array_map('intval', explode(',', (string)$r['skills']))))], $q->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Capacidad día por día de un departamento entre dos fechas (incluidas).
 * Devuelve [día => ['work' => bool, 'cap' => min brutos, 'usable' => tras el margen, 'normal' => sin la reserva para urgencias,
 *   'people' => [uid => min], 'off' => [uid => motivo], 'closed' => motivo|null]].
 */
function pt_capacity(PDO $db, array $d, string $from, string $to): array {
    $members = array_values(array_filter(pt_members($db, $d['id']), fn($m) => $m['works']));
    $q = $db->prepare('SELECT * FROM g7_wk_dayoff WHERE date_from <= ? AND date_to >= ? AND (team_id IS NULL OR team_id = ?)');
    $q->execute([$to, $from, $d['id']]); $offs = $q->fetchAll(PDO::FETCH_ASSOC);
    $q = $db->prepare('SELECT day, minutes FROM g7_wk_day_cap WHERE team_id = ? AND day BETWEEN ? AND ?'); $q->execute([$d['id'], $from, $to]);
    $exc = []; foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $exc[$r['day']] = (int)$r['minutes'];
    $out = [];
    for ($ts = strtotime($from); $ts <= strtotime($to); $ts += 86400) {
        $day = date('Y-m-d', $ts); $wd = (string)date('N', $ts);
        $closed = null; $people = []; $offWho = [];
        foreach ($offs as $o) {
            if ($o['date_from'] > $day || $o['date_to'] < $day) continue;
            if ($o['user_id'] === null && $o['minutes'] === null) $closed = PT_OFF_KINDS[$o['kind']] ?? 'Cerrado';
        }
        foreach ($members as $m) {
            $min = (int)floor(pt_hours_min(($m['hours'] ?? $d['hours'])[$wd] ?? []) * $m['cap_pct'] / 100);
            foreach ($offs as $o) {
                if ((int)$o['user_id'] !== $m['id'] || $o['date_from'] > $day || $o['date_to'] < $day) continue;
                if ($o['minutes'] === null) { $min = 0; $offWho[$m['id']] = PT_OFF_KINDS[$o['kind']] ?? 'Ausente'; }
                else { $min = max(0, $min - (int)$o['minutes']); $offWho[$m['id']] = (PT_OFF_KINDS[$o['kind']] ?? 'Ausente') . ' parcial'; }
            }
            $people[$m['id']] = $closed ? 0 : $min;
        }
        $cap = array_sum($people);
        if (!$members) $cap = $closed ? 0 : pt_hours_min($d['hours'][$wd] ?? []);   // sin integrantes cargados: el horario del departamento como una persona
        if ($d['cap_day_min'] > 0) $cap = min($cap, $d['cap_day_min']);
        if (isset($exc[$day])) { $cap = $exc[$day]; $closed = $cap > 0 ? null : 'Excepción: cerrado'; }
        $usable = (int)floor($cap * (100 - $d['safety_pct']) / 100);
        $out[$day] = ['work' => $cap > 0 && !$closed, 'cap' => $closed ? 0 : $cap, 'usable' => $closed ? 0 : $usable,
                      'normal' => $closed ? 0 : (int)floor($usable * (100 - $d['reserve_pct']) / 100), 'people' => $people, 'off' => $offWho, 'closed' => $closed ?: ($cap > 0 ? null : 'No laborable')];
    }
    return $out;
}

/** Carga ya comprometida por día (del departamento y por persona), sin contar el ticket $except. */
function pt_committed(PDO $db, int $teamId, string $from, string $to, ?int $except = null): array {
    $q = $db->prepare('SELECT day, user_id, SUM(minutes) m, COUNT(DISTINCT ticket_id) n FROM g7_wk_load WHERE team_id = ? AND day BETWEEN ? AND ? AND ticket_id <> ? GROUP BY day, user_id');
    $q->execute([$teamId, $from, $to, (int)$except]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $o = &$out[$r['day']]; $o = $o ?? ['min' => 0, 'people' => [], 'tickets' => 0];
        $o['min'] += (int)$r['m']; $o['tickets'] += (int)$r['n'];
        if ($r['user_id'] !== null) $o['people'][(int)$r['user_id']] = ($o['people'][(int)$r['user_id']] ?? 0) + (int)$r['m'];
        unset($o);
    }
    // tickets distintos por día (un ticket con varias personas no se cuenta dos veces)
    $q = $db->prepare('SELECT day, COUNT(DISTINCT ticket_id) FROM g7_wk_load WHERE team_id = ? AND day BETWEEN ? AND ? AND ticket_id <> ? GROUP BY day');
    $q->execute([$teamId, $from, $to, (int)$except]);
    foreach ($q->fetchAll(PDO::FETCH_KEY_PAIR) as $day => $n) $out[$day]['tickets'] = (int)$n;
    return $out;
}

/**
 * Reparte $work minutos hacia atrás desde $due hasta $start, en días laborables y sin pasar lo libre.
 * Lo que no entra va al día de entrega como sobrecarga. Con $uid se respeta también la capacidad libre de esa persona.
 * Devuelve ['days' => [día => min], 'over' => min, 'over_day' => día].
 */
function pt_plan(array $cap, array $com, int $work, string $start, string $due, bool $urgent, ?int $uid = null): array {
    $days = []; $left = $work;
    if ($due < $start) $due = $start;
    for ($ts = strtotime($due); $left > 0 && $ts >= strtotime($start); $ts -= 86400) {
        $day = date('Y-m-d', $ts);
        $c = $cap[$day] ?? null; if (!$c || !$c['work']) continue;
        $free = ($urgent ? $c['usable'] : $c['normal']) - ($com[$day]['min'] ?? 0);
        if ($uid !== null) $free = min($free, (int)floor(($c['people'][$uid] ?? 0) * (100) / 100) - ($com[$day]['people'][$uid] ?? 0));
        if ($free <= 0) continue;
        $take = min($free, $left); $days[$day] = $take; $left -= $take;
    }
    // El que no entra: al último día laborable que no pase la entrega (o a la entrega misma si no hay ninguno)
    $overDay = $due;
    if ($left > 0) {
        for ($ts = strtotime($due); $ts >= strtotime($start); $ts -= 86400) { $x = date('Y-m-d', $ts); if (!empty($cap[$x]['work'])) { $overDay = $x; break; } }
        $days[$overDay] = ($days[$overDay] ?? 0) + $left;
    }
    ksort($days);
    return ['days' => $days, 'over' => max(0, $left), 'over_day' => $left > 0 ? $overDay : null];
}

/** Primer día en que se terminaría un trabajo empezando en $start y llenando lo libre hacia adelante (fecha de entrega estimada). */
function pt_forward_end(array $cap, array $com, int $work, string $start, bool $urgent): ?string {
    $left = $work;
    foreach ($cap as $day => $c) {
        if ($day < $start || !$c['work']) continue;
        $free = ($urgent ? $c['usable'] : $c['normal']) - ($com[$day]['min'] ?? 0);
        if ($free > 0) $left -= $free;
        if ($left <= 0) return $day;
    }
    return null;
}

/** Primera fecha que se puede pedir (anticipación mínima, en horas laborables corridas → días). */
function pt_min_date(array $d): string {
    $ts = time() + $d['min_lead_h'] * 3600;
    return date('Y-m-d', $ts);
}

/**
 * Calendario de carga para un pedido nuevo: cada día con su capacidad, lo comprometido, lo libre y cómo quedaría si se elige
 * como fecha de entrega. $work = minutos del pedido. Colores: green / yellow / red / gray (no laborable o fuera de rango).
 */
function pt_calendar(PDO $db, array $d, int $work, string $from, string $to, bool $urgent = false, ?int $except = null): array {
    $today = pt_today();
    $start = max($today, $from);
    $capFrom = $today;   // el reparto puede usar días desde hoy aunque el calendario muestre otro mes
    $cap = pt_capacity($db, $d, $capFrom, max($to, $capFrom));
    $com = pt_committed($db, $d['id'], $capFrom, max($to, $capFrom), $except);
    $min = pt_min_date($d); $maxDate = date('Y-m-d', strtotime('+' . max(1, $d['max_lead_d']) . ' days'));
    $out = [];
    for ($ts = strtotime($from); $ts <= strtotime($to); $ts += 86400) {
        $day = date('Y-m-d', $ts);
        $c = $cap[$day] ?? null; $cm = $com[$day] ?? ['min' => 0, 'tickets' => 0, 'people' => []];
        $row = ['day' => $day, 'work' => (bool)($c['work'] ?? false), 'cap' => $c['usable'] ?? 0, 'committed' => $cm['min'], 'tickets' => $cm['tickets'] ?? 0,
                'free' => max(0, ($c ? ($urgent ? $c['usable'] : $c['normal']) : 0) - $cm['min']), 'closed' => $c['closed'] ?? null];
        if ($day < $today || !$c) { $row['color'] = 'gray'; $row['why'] = $day < $today ? 'past' : 'out'; $out[] = $row; continue; }
        if (!$row['work']) { $row['color'] = 'gray'; $row['why'] = 'off'; $out[] = $row; continue; }
        $plan = pt_plan($cap, $com, $work, $start, $day, $urgent);
        $onDay = $plan['days'][$day] ?? 0;
        $row['pct'] = $row['cap'] ? (int)round(($cm['min'] + $work) * 100 / $row['cap']) : 999;   // fórmula pedida: (comprometidas + nueva) / capacidad
        $row['pct_plan'] = $row['cap'] ? (int)round(($cm['min'] + $onDay) * 100 / $row['cap']) : 999;   // con el reparto en varios días
        $row['over'] = $plan['over'];
        $row['plan'] = $plan['days'];
        $row['color'] = $plan['over'] > 0 ? 'red' : ($row['pct_plan'] <= $d['green_pct'] ? 'green' : ($row['pct_plan'] <= $d['yellow_pct'] ? 'yellow' : 'red'));
        if ($day < $min) { $row['color'] = 'gray'; $row['why'] = 'lead'; }
        if ($day > $maxDate) { $row['color'] = 'gray'; $row['why'] = 'max'; }
        $out[] = $row;
    }
    return $out;
}

/** Las primeras $n fechas (desde la mínima) en que el pedido entra sin sobrecarga. */
function pt_alternatives(PDO $db, array $d, int $work, bool $urgent = false, int $n = 3, ?int $except = null, ?string $after = null): array {
    $from = max(pt_min_date($d), $after ?: pt_today());
    $to = date('Y-m-d', strtotime('+' . max(7, min(365, $d['max_lead_d'])) . ' days'));
    $today = pt_today();
    $cap = pt_capacity($db, $d, $today, $to); $com = pt_committed($db, $d['id'], $today, $to, $except);
    $out = [];
    foreach ($cap as $day => $c) {
        if ($day < $from || !$c['work']) continue;
        $p = pt_plan($cap, $com, $work, $today, $day, $urgent);
        if ($p['over'] > 0) continue;
        $pct = $c['usable'] ? (int)round((($com[$day]['min'] ?? 0) + ($p['days'][$day] ?? 0)) * 100 / $c['usable']) : 0;
        $out[] = ['day' => $day, 'pct' => $pct, 'color' => $pct <= $d['green_pct'] ? 'green' : 'yellow', 'plan' => $p['days']];
        if (count($out) >= $n) break;
    }
    return $out;
}

/** ¿Este ticket ocupa capacidad? (abierto, con estimación, no cancelado ni rechazado). */
function pt_counts(array $t): bool {
    return $t['est_min'] !== null && (int)$t['est_min'] > 0 && $t['team_id'] && in_array($t['status'], ['abierto', 'en_curso', 'esperando'], true)
        && !(int)$t['cancelled'] && ($t['approval'] ?? '') !== 'rejected';
}

/** Ejecuta $fn con el candado de capacidad del departamento (dos pedidos a la vez no ven la misma carga libre). */
function pt_with_lock(PDO $db, int $teamId, callable $fn) {
    $name = 'wk_cap_' . substr(md5((defined('DB_NAME') ? DB_NAME : '') . '|' . $teamId), 0, 20);
    $got = (int)$db->query("SELECT GET_LOCK(" . $db->quote($name) . ", 15)")->fetchColumn();
    if (!$got) throw new RuntimeException('El departamento está ocupado calculando otra solicitud. Probá de nuevo en unos segundos.');
    try { return $fn(); }
    finally { $db->query("SELECT RELEASE_LOCK(" . $db->quote($name) . ")"); }
}

/**
 * Recalcula el reparto de un ticket: borra todo lo suyo y lo vuelve a escribir (en una transacción, con el candado del departamento).
 * Así crear, cambiar estimación o fecha, cancelar, completar, reasignar o cambiar la capacidad nunca cuentan dos veces.
 */
function pt_recalc(PDO $db, int $id): array {
    $t = wk_tk_row($db, $id);
    if (!$t) return ['days' => [], 'over' => 0];
    $teamId = (int)$t['team_id'];
    $run = function () use ($db, $id) {
        $t = wk_tk_row($db, $id);   // releído con el candado tomado
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM g7_wk_load WHERE ticket_id = ?')->execute([$id]);
            $res = ['days' => [], 'over' => 0];
            if (pt_counts($t)) {
                $d = pt_dept($db, (int)$t['team_id']);
                $left = max(0, (int)$t['est_min'] - (int)$t['real_min']);
                $today = pt_today();
                $start = max($today, substr((string)$t['created_at'], 0, 10));
                $urgent = in_array($t['priority'], PT_URGENT, true);
                $due = $t['committed_date'] ?: $t['requested_date'];
                $horizon = max($due ?: $today, date('Y-m-d', strtotime('+' . max(30, $d['max_lead_d']) . ' days')));
                $cap = pt_capacity($db, $d, $today, $horizon); $com = pt_committed($db, $d['id'], $today, $horizon, $id);
                if (!$due) $due = pt_forward_end($cap, $com, $left, $start, $urgent) ?: $horizon;   // sin fecha: lo antes posible
                if ($due < $today) $due = $today;   // atrasado: lo que falta queda para hoy
                $uid = $t['assignee_id'] ? (int)$t['assignee_id'] : null;
                if ($uid !== null && !isset(current($cap)['people'][$uid])) $uid = null;   // responsable de otro departamento: cuenta solo para el departamento
                $p = $left > 0 ? pt_plan($cap, $com, $left, $start, $due, $urgent, $uid) : ['days' => [], 'over' => 0, 'over_day' => null];
                if ($uid !== null && $p['over'] > 0) {
                    // la persona no llega pero el departamento sí: se marca igual (la sobrecarga es de la persona)
                }
                $ins = $db->prepare('INSERT INTO g7_wk_load (ticket_id, team_id, user_id, day, minutes, is_over) VALUES (?, ?, ?, ?, ?, ?)');
                foreach ($p['days'] as $day => $m) if ($m > 0) $ins->execute([$id, $d['id'], $uid, $day, $m, $p['over_day'] === $day ? 1 : 0]);
                $res = $p;
                $isOver = $p['over'] > 0 ? 1 : 0;
                if ($isOver !== (int)$t['overload']) $db->prepare('UPDATE g7_wk_tickets SET overload = ? WHERE id = ?')->execute([$isOver, $id]);
            }
            if ($own) $db->commit();
            return $res;
        } catch (Throwable $e) { if ($own && $db->inTransaction()) $db->rollBack(); throw $e; }
    };
    return $teamId ? pt_with_lock($db, $teamId, $run) : $run();
}

/** Recalcula todos los tickets abiertos de un departamento (al cambiar horarios, ausencias o capacidad). Primero los de fecha más cercana. */
function pt_recalc_team(PDO $db, int $teamId): int {
    $q = $db->prepare("SELECT id FROM g7_wk_tickets WHERE team_id = ? AND est_min IS NOT NULL AND status IN ('abierto', 'en_curso', 'esperando') ORDER BY COALESCE(committed_date, requested_date, '9999-12-31'), FIELD(priority, 'critica', 'urgente') DESC, id");
    $q->execute([$teamId]); $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    return pt_with_lock($db, $teamId, function () use ($db, $ids, $teamId) {
        // se libera todo y se vuelve a llenar en orden de entrega: los que vencen antes eligen primero
        $db->prepare('DELETE l FROM g7_wk_load l JOIN g7_wk_tickets t ON t.id = l.ticket_id WHERE l.team_id = ? AND t.id IN (' . wk_ints($ids) . ')')->execute([$teamId]);
        foreach ($ids as $id) pt_recalc($db, $id);   // GET_LOCK es reentrante en la misma conexión (MariaDB 10.0.2+)
        return count($ids);
    });
}
// ── Asignación automática ────────────────────────────────────
/**
 * Elige responsable para un ticket: integrantes que producen, no ausentes, con capacidad libre antes de la entrega.
 * Orden: especialidad en la categoría → más capacidad libre en la ventana → menos tickets pendientes → menos horas comprometidas.
 * Nunca devuelve a alguien ausente todo el período o sin capacidad. Devuelve ['uid' => int|null, 'why' => texto, 'ranking' => […]].
 */
function pt_auto_pick(PDO $db, array $t): array {
    $d = pt_dept($db, (int)$t['team_id']);
    if (!$d) return ['uid' => null, 'why' => 'Sin departamento', 'ranking' => []];
    $today = pt_today();
    $due = $t['committed_date'] ?: $t['requested_date'] ?: date('Y-m-d', strtotime('+14 days'));
    if ($due < $today) $due = $today;
    $cap = pt_capacity($db, $d, $today, $due); $com = pt_committed($db, $d['id'], $today, $due, (int)$t['id']);
    $chain = array_map(fn($c) => (int)$c['id'], pt_cat_chain($db, $t['category_id'] ? (int)$t['category_id'] : null));
    $need = max(0, (int)$t['est_min'] - (int)$t['real_min']);
    $rank = [];
    foreach (pt_members($db, $d['id']) as $m) {
        if (!$m['works']) continue;
        $free = 0; $capSum = 0; $comSum = 0;
        foreach ($cap as $day => $c) { $pc = $c['people'][$m['id']] ?? 0; $capSum += $pc; $cm = $com[$day]['people'][$m['id']] ?? 0; $comSum += $cm; $free += max(0, $pc - $cm); }
        if ($capSum <= 0) { $rank[] = ['uid' => $m['id'], 'name' => $m['name'], 'skip' => 'Ausente o sin horas hasta la entrega']; continue; }
        if ($free <= 0) { $rank[] = ['uid' => $m['id'], 'name' => $m['name'], 'skip' => 'Sin capacidad libre hasta la entrega']; continue; }
        $q = $db->prepare("SELECT COUNT(*) FROM g7_wk_tickets WHERE assignee_id = ? AND status IN ('abierto', 'en_curso', 'esperando') AND id <> ?"); $q->execute([$m['id'], (int)$t['id']]);
        $rank[] = ['uid' => $m['id'], 'name' => $m['name'], 'skill' => (bool)array_intersect($chain, $m['skills']), 'free' => $free, 'fits' => $free >= $need,
                   'pending' => (int)$q->fetchColumn(), 'committed' => $comSum];
    }
    $ok = array_values(array_filter($rank, fn($r) => empty($r['skip'])));
    // primero a quien le alcanza, después especialidad y más libre (de mayor a menor); a igualdad, menos pendientes y menos comprometido
    usort($ok, fn($a, $b) => [$b['fits'], $b['skill'], $b['free'], $a['pending'], $a['committed']] <=> [$a['fits'], $a['skill'], $a['free'], $b['pending'], $b['committed']]);
    if (!$ok) return ['uid' => null, 'why' => 'Nadie del departamento tiene capacidad libre antes de la entrega', 'ranking' => $rank];
    $w = $ok[0];
    $why = ($w['skill'] ? 'especialidad en la categoría, ' : '') . pt_fmt_min($w['free']) . ' libres hasta la entrega, ' . $w['pending'] . ' pendientes' . ($w['fits'] ? '' : ' (no le alcanza todo: quedará sobrecarga)');
    return ['uid' => $w['uid'], 'why' => $why, 'ranking' => array_merge($ok, array_values(array_filter($rank, fn($r) => !empty($r['skip']))))];
}

// ── Avisos y automatizaciones ────────────────────────────────
/** Aviso de un evento del portal a la gente que corresponde (TK_EVENTS pt_*; respeta lo que eligió cada persona). */
function pt_notify(PDO $db, string $event, array $t, ?int $by, string $body = '', array $extra = []): void {
    $req = [(int)$t['requester_id']];
    $resp = $t['assignee_id'] ? [(int)$t['assignee_id']] : [];
    $leads = $t['team_id'] ? wk_team_people($db, (int)$t['team_id'], true) : [];
    $managers = $leads;
    if ($t['team_id']) foreach (pt_members($db, (int)$t['team_id']) as $m) if ($m['is_resp']) $managers[] = $m['id'];
    $to = [
        'pt_received' => $req, 'pt_approved' => $req, 'pt_rejected' => $req, 'pt_info' => $req, 'pt_started' => $req, 'pt_done' => $req,
        'pt_assignee' => $req, 'pt_date' => array_merge($req, $resp),
        'pt_approval' => $t['approver_id'] ? [(int)$t['approver_id']] : $managers,
        'pt_due_soon' => $resp ?: $managers, 'pt_late' => array_merge($resp, $managers), 'pt_overload' => $managers,
    ][$event] ?? [];
    $to = array_merge($to, $extra);
    if ($to) wk_tk_notify($db, $event, $to, $t, $by, $body);
}

/** Corre las automatizaciones del portal para un evento (las acciones no disparan otras reglas). */
function pt_auto_fire(PDO $db, string $event, int $ticketId, ?int $by = null): void {
    static $depth = 0;
    if ($depth > 0) return;
    $depth++;
    try {
        $t = wk_tk_row($db, $ticketId); if (!$t) return;
        $q = $db->prepare('SELECT * FROM g7_wk_pt_autos WHERE active = 1 AND event = ? AND (team_id IS NULL OR team_id = ?) ORDER BY id');
        $q->execute([$event, (int)$t['team_id']]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $t = wk_tk_row($db, $ticketId);
            $facts = ['priority' => $t['priority'], 'category' => (string)$t['category_id'], 'stage' => (string)$t['stage'], 'est_min' => (string)(int)$t['est_min'],
                      'overload' => $t['overload'] ? '1' : '', 'form' => (string)$t['form_id'], 'assignee' => (string)$t['assignee_id'], 'origin' => $t['origin']];
            if (!pt_cond(json_decode((string)$a['cond'], true) ?: null, $facts)) continue;
            $done = [];
            foreach ((json_decode((string)$a['actions'], true) ?: []) as $ac) {
                try { $done[] = pt_auto_do($db, $t, $ac, $by); } catch (Throwable $e) { $done[] = 'error: ' . $e->getMessage(); }
            }
            $db->prepare('UPDATE g7_wk_pt_autos SET runs = runs + 1, last_run_at = ? WHERE id = ?')->execute([pt_now(), (int)$a['id']]);
            wk_tk_event($db, $ticketId, null, 'Automatización "' . $a['name'] . '": ' . implode(' · ', array_filter($done)), true);
        }
    } finally { $depth--; }
}
function pt_auto_do(PDO $db, array $t, array $ac, ?int $by): string {
    switch ($ac['a'] ?? '') {
        case 'assign':
            if (($ac['v'] ?? '') === 'auto') { $p = pt_auto_pick($db, $t); if (!$p['uid']) return 'no se pudo asignar: ' . $p['why']; $u = $p['uid']; }
            else { $u = (int)($ac['v'] ?? 0); if (!$u || !in_array($u, array_column(pt_members($db, (int)$t['team_id']), 'id'), true)) return 'responsable no válido'; }
            wk_tk_set($db, (int)$t['id'], ['assignee_id' => $u]); wk_task_from_tk($db, (int)$t['id'], null, $t['assignee_id'] ? (int)$t['assignee_id'] : null);
            return 'asignado a ' . wk_user_name($db, $u);
        case 'stage':
            $s = pt_stage($db, (string)($ac['v'] ?? '')); if (!$s) return 'etapa no válida';
            $f = ['stage' => $s['id']] + ($s['status'] !== $t['status'] ? wk_tk_status_fields($t, $s['status'], pt_now()) : []);
            wk_tk_set($db, (int)$t['id'], $f); wk_task_from_tk($db, (int)$t['id'], null);
            return 'pasó a ' . $s['name'];
        case 'priority':
            $cfg = tk_config($db); if (!in_array($ac['v'] ?? '', array_column($cfg['priorities'], 'id'), true)) return 'prioridad no válida';
            wk_tk_set($db, (int)$t['id'], ['priority' => $ac['v']]); return 'prioridad ' . $ac['v'];
        case 'notify':
            $who = (string)($ac['to'] ?? 'leads');
            $users = $who === 'requester' ? [(int)$t['requester_id']] : ($who === 'assignee' ? array_filter([(int)$t['assignee_id']]) : ($who === 'leads' ? wk_team_people($db, (int)$t['team_id'], true) : [(int)$who]));
            wk_tk_notify($db, 'tk_status', $users, $t, $by, pt_s($ac['msg'] ?? 'Aviso automático', 280)); return 'aviso enviado';
        case 'note':
            wk_tk_event($db, (int)$t['id'], null, pt_s($ac['v'] ?? '', 1000), true); return 'nota agregada';
    }
    return '';
}

/** Barrido (con el de SLA): avisa entregas por vencer (mañana) y atrasadas, una sola vez por ticket y fecha. */
function pt_sweep(PDO $db): void {
    try {
        $last = (int)wk_cfg_get($db, 'work_pt_sweep_at', '0');
        if (time() - $last < 600) return;
        wk_cfg_put($db, 'work_pt_sweep_at', (string)time());
        if (!$db->query("SELECT GET_LOCK('wk_pt_sweep', 0)")->fetchColumn()) return;
        $today = pt_today(); $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $rows = $db->query("SELECT * FROM g7_wk_tickets WHERE committed_date IS NOT NULL AND committed_date <= '$tomorrow' AND status IN ('abierto', 'en_curso', 'esperando') AND cancelled = 0")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $t) {
            $late = $t['committed_date'] < $today;
            $key = 'work_pt_' . ($late ? 'late' : 'soon') . '_' . $t['id'] . '_' . $t['committed_date'];
            if (wk_cfg_get($db, $key) !== null) continue;
            wk_cfg_put($db, $key, '1');
            pt_notify($db, $late ? 'pt_late' : 'pt_due_soon', $t, null, $late ? 'La fecha comprometida (' . $t['committed_date'] . ') ya pasó.' : 'Se entrega mañana.');
            pt_auto_fire($db, $late ? 'late' : 'due_soon', (int)$t['id']);
        }
        $db->query("SELECT RELEASE_LOCK('wk_pt_sweep')");
    } catch (Throwable $e) {}
}
