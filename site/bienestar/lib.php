<?php
/**
 * bienestar/lib.php — Módulo Bienestar (2026-11-16): ánimo, hábitos, retos, clima laboral, reconocimientos, carga y ausencias.
 * Se apoya en lo que ya existía en vez de duplicarlo:
 *  - ánimo diario: g7_mood y sus reglas (mood_lib.php, "¿Cómo estás hoy?" del Inicio). Bienestar solo agrega vistas.
 *  - reconocimientos: los kudos de Comunidad (g7_posts kind='kudos', reacciones y respuestas de Comunidad).
 *  - ausencias: g7_wk_dayoff del Portal de Solicitudes (así Trabajo descuenta la capacidad de quien está de vacaciones).
 *  - carga: tareas (g7_tasks) y el reparto por día de Solicitudes (g7_wk_load).
 *  - avisos: la campana (g7_notifications) y permisos: access.php (módulo 'bienestar', acción 'manage').
 * Organización = sitio (cada sitio tiene su base); dentro del sitio, el alcance de quien gestiona son sus equipos.
 * Privacidad: lo emocional es de cada persona. Lo de la organización sale agregado, anónimo y con un mínimo de personas.
 * Doc: toolz/BIENESTAR.md
 */
require_once dirname(__DIR__) . '/mood_lib.php';
require_once dirname(__DIR__) . '/comunidad_lib.php';

const BN_SCHEMA = '1';
const BN_MIN_FLOOR = 3;   // nunca menos de 3 personas detrás de un número agregado (con 2, cada uno deduce lo del otro)

/** Categorías de reconocimiento de Bienestar: claves de CM_KUDOS (Comunidad), así un reconocimiento es uno solo en los dos lados. */
const BN_KUDOS_DEFAULT = ['companerismo', 'equipo', 'creativo', 'compromiso', 'ayuda', 'positiva'];

const BN_LOAD_LEVELS = [
    1 => ['label' => 'Muy liviana', 'color' => '#22c55e'],
    2 => ['label' => 'Liviana',     'color' => '#84cc16'],
    3 => ['label' => 'Justa',       'color' => '#eab308'],
    4 => ['label' => 'Pesada',      'color' => '#f97316'],
    5 => ['label' => 'Desbordada',  'color' => '#ef4444'],
];

const BN_NOTIF_KEYS = [
    'challenges'    => 'Retos nuevos',
    'challenge_end' => 'Retos que terminan',
    'surveys'       => 'Encuestas pendientes',
    'kudos'         => 'Reconocimientos que recibo',
    'habits'        => 'Recordatorios de mis hábitos',
    'leave'         => 'Cambios en mis vacaciones y licencias',
];

/** Insignias de Bienestar: [emoji, nombre, cómo se gana] */
const BN_BADGES = [
    'habit_first'    => ['🌱', 'Primer paso', 'Cumpliste un hábito por primera vez'],
    'streak_7'       => ['🔥', 'Racha de 7', 'Siete días seguidos con un hábito'],
    'streak_30'      => ['🏅', 'Racha de 30', 'Treinta días seguidos con un hábito'],
    'weekly_4'       => ['📅', 'Cuatro semanas', 'Cumpliste un hábito semanal cuatro semanas seguidas'],
    'challenge_done' => ['🎯', 'Reto cumplido', 'Llegaste a tu objetivo en un reto'],
    'team_goal'      => ['🤝', 'Objetivo en equipo', 'Participaste en un reto colectivo que llegó a la meta'],
    'kudos_given'    => ['💌', 'Agradecido', 'Reconociste a un compañero'],
    'survey_voice'   => ['🗣️', 'Tu voz cuenta', 'Respondiste una encuesta de clima'],
    'mood_week'      => ['😊', 'Semana consciente', 'Contaste cómo estabas siete días seguidos'],
];

/** Error con código HTTP: la API lo devuelve tal cual (mensaje pensado para la persona). */
class BnError extends RuntimeException {
    public function __construct(string $msg, int $code = 400) { parent::__construct($msg, $code); }
}
function bn_fail(string $msg, int $code = 400): void { throw new BnError($msg, $code); }

// ═══════════════════════════════════════════════════════════
// INSTALACIÓN (idempotente: CREATE TABLE IF NOT EXISTS, columnas con chequeo)
// ═══════════════════════════════════════════════════════════
function bn_install(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    mood_install($db);   // el ánimo vive en g7_mood (no se toca ni se migra: se conservan todos los registros)
    cm_install($db);
    if (function_exists('g7_cfg') && g7_cfg('bn_schema') === BN_SCHEMA) return;
    $T = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    // Programas: agrupan retos y encuestas (ej. "Mes del descanso")
    $db->exec("CREATE TABLE IF NOT EXISTS g7_bn_programs (
        id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(120) NOT NULL, description VARCHAR(600) NOT NULL DEFAULT '',
        icon VARCHAR(40) NOT NULL DEFAULT 'fa-seedling', color VARCHAR(7) NOT NULL DEFAULT '#22c55e',
        start_date DATE NULL, end_date DATE NULL, audience VARCHAR(255) NULL, active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
    ) $T");
    // Hábitos sugeridos (los arma quien administra) y los de cada persona (privados)
    $db->exec("CREATE TABLE IF NOT EXISTS g7_bn_habit_tpl (
        id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(80) NOT NULL, hint VARCHAR(200) NOT NULL DEFAULT '',
        icon VARCHAR(40) NOT NULL DEFAULT 'fa-leaf', color VARCHAR(7) NOT NULL DEFAULT '#22c55e',
        freq VARCHAR(8) NOT NULL DEFAULT 'daily', target TINYINT NOT NULL DEFAULT 1, active TINYINT(1) NOT NULL DEFAULT 1, sort INT NOT NULL DEFAULT 0
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_bn_habits (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, tpl_id INT NULL, title VARCHAR(80) NOT NULL,
        icon VARCHAR(40) NOT NULL DEFAULT 'fa-leaf', color VARCHAR(7) NOT NULL DEFAULT '#22c55e',
        freq VARCHAR(8) NOT NULL DEFAULT 'daily', target TINYINT NOT NULL DEFAULT 1, days_mask TINYINT NOT NULL DEFAULT 127,
        goal VARCHAR(120) NOT NULL DEFAULT '', remind TINYINT(1) NOT NULL DEFAULT 0, remind_time CHAR(5) NOT NULL DEFAULT '09:00',
        reminded_on DATE NULL, archived TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL,
        KEY user_id (user_id, archived)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_bn_habit_log (
        habit_id INT NOT NULL, day DATE NOT NULL, PRIMARY KEY (habit_id, day)
    ) $T");
    // Retos: individuales (cada uno su objetivo) o colectivos (se suma el avance de todos)
    $db->exec("CREATE TABLE IF NOT EXISTS g7_bn_challenges (
        id INT AUTO_INCREMENT PRIMARY KEY, program_id INT NULL, title VARCHAR(120) NOT NULL, description VARCHAR(1500) NOT NULL DEFAULT '',
        image VARCHAR(200) NOT NULL DEFAULT '', icon VARCHAR(40) NOT NULL DEFAULT 'fa-person-running', color VARCHAR(7) NOT NULL DEFAULT '#3b82f6',
        kind VARCHAR(12) NOT NULL DEFAULT 'colectivo', unit VARCHAR(30) NOT NULL DEFAULT 'veces',
        goal_person INT NOT NULL DEFAULT 0, goal_total INT NOT NULL DEFAULT 0, start_date DATE NOT NULL, end_date DATE NOT NULL,
        audience VARCHAR(255) NULL, reward VARCHAR(200) NOT NULL DEFAULT '', ranking TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(10) NOT NULL DEFAULT 'draft', n_start TINYINT(1) NOT NULL DEFAULT 0, n_soon TINYINT(1) NOT NULL DEFAULT 0, n_end TINYINT(1) NOT NULL DEFAULT 0,
        goal_hit_at DATETIME NULL, created_by INT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        KEY st (status, start_date, end_date)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_bn_ch_members (
        challenge_id INT NOT NULL, user_id INT NOT NULL, progress INT NOT NULL DEFAULT 0, in_ranking TINYINT(1) NOT NULL DEFAULT 1,
        joined_at DATETIME NOT NULL, done_at DATETIME NULL, PRIMARY KEY (challenge_id, user_id), KEY user_id (user_id)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_bn_ch_log (
        id INT AUTO_INCREMENT PRIMARY KEY, challenge_id INT NOT NULL, user_id INT NOT NULL, day DATE NOT NULL, amount INT NOT NULL,
        note VARCHAR(140) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, KEY ch_user (challenge_id, user_id)
    ) $T");
    // Encuestas de clima. Las respuestas anónimas no guardan usuario, ni hora, y su id es al azar (no se ordenan por llegada)
    $db->exec("CREATE TABLE IF NOT EXISTS g7_bn_surveys (
        id INT AUTO_INCREMENT PRIMARY KEY, series_id INT NULL, program_id INT NULL, title VARCHAR(140) NOT NULL, intro VARCHAR(800) NOT NULL DEFAULT '',
        category VARCHAR(16) NOT NULL DEFAULT 'ambiente', anonymous TINYINT(1) NOT NULL DEFAULT 1, is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
        audience VARCHAR(255) NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, recurrence VARCHAR(10) NOT NULL DEFAULT 'none',
        status VARCHAR(10) NOT NULL DEFAULT 'draft', notified TINYINT(1) NOT NULL DEFAULT 0, next_done TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        KEY st (status, start_date, end_date), KEY series_id (series_id)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_bn_sv_questions (
        id INT AUTO_INCREMENT PRIMARY KEY, survey_id INT NOT NULL, pos INT NOT NULL DEFAULT 0, type VARCHAR(8) NOT NULL,
        text VARCHAR(300) NOT NULL, options TEXT NULL, required TINYINT(1) NOT NULL DEFAULT 1, scale_max TINYINT NOT NULL DEFAULT 5,
        KEY survey_id (survey_id)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_bn_sv_responses (
        id BIGINT NOT NULL PRIMARY KEY, survey_id INT NOT NULL, user_id INT NULL, teams VARCHAR(255) NOT NULL DEFAULT '',
        KEY survey_id (survey_id)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_bn_sv_answers (
        response_id BIGINT NOT NULL, question_id INT NOT NULL, num INT NULL, opts VARCHAR(255) NULL, txt VARCHAR(1000) NULL,
        PRIMARY KEY (response_id, question_id), KEY question_id (question_id)
    ) $T");
    // Quién ya respondió (para no pedirle de nuevo): sin vínculo con su respuesta y solo con el día
    $db->exec("CREATE TABLE IF NOT EXISTS g7_bn_sv_done (
        survey_id INT NOT NULL, user_id INT NOT NULL, day DATE NOT NULL, PRIMARY KEY (survey_id, user_id), KEY user_id (user_id)
    ) $T");
    // Carga percibida: una por persona y semana (se puede corregir en la misma semana)
    $db->exec("CREATE TABLE IF NOT EXISTS g7_bn_load (
        user_id INT NOT NULL, week CHAR(8) NOT NULL, level TINYINT NOT NULL, updated_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, week), KEY week (week)
    ) $T");
    // Reconocimientos privados (los públicos son kudos de Comunidad)
    $db->exec("CREATE TABLE IF NOT EXISTS g7_bn_kudos_private (
        id INT AUTO_INCREMENT PRIMARY KEY, from_id INT NOT NULL, to_id INT NOT NULL, badge VARCHAR(24) NOT NULL,
        body VARCHAR(280) NOT NULL, thanked TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL,
        KEY to_id (to_id), KEY from_id (from_id)
    ) $T");
    // Vacaciones y licencias: el pedido y su aprobación. Aprobado, se anota en g7_wk_dayoff (calendario de ausencias de Trabajo)
    $db->exec("CREATE TABLE IF NOT EXISTS g7_bn_leave (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, type VARCHAR(12) NOT NULL, date_from DATE NOT NULL, date_to DATE NOT NULL,
        days INT NOT NULL, note VARCHAR(200) NOT NULL DEFAULT '', status VARCHAR(10) NOT NULL DEFAULT 'pending',
        decided_by INT NULL, decided_at DATETIME NULL, decision_note VARCHAR(200) NOT NULL DEFAULT '', dayoff_id INT NULL,
        created_at DATETIME NOT NULL, KEY user_id (user_id, status), KEY rng (date_from, date_to)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_bn_leave_balance (
        user_id INT NOT NULL, year SMALLINT NOT NULL, days INT NOT NULL, updated_by INT NULL, updated_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, year)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_bn_badges (
        user_id INT NOT NULL, code VARCHAR(20) NOT NULL, ref_id INT NOT NULL DEFAULT 0, earned_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, code, ref_id)
    ) $T");
    // Hábitos sugeridos de fábrica: solo con la tabla vacía (si el admin los borra, no vuelven)
    if (!(int)$db->query('SELECT COUNT(*) FROM g7_bn_habit_tpl')->fetchColumn()) {
        $ins = $db->prepare('INSERT INTO g7_bn_habit_tpl (title, hint, icon, color, freq, target, sort) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            ['Tomar agua', 'Ocho vasos a lo largo del día', 'fa-glass-water', '#06b6d4', 'daily', 1],
            ['Pausa activa', 'Levantarte y estirarte unos minutos', 'fa-person-walking', '#22c55e', 'daily', 1],
            ['Caminar 30 minutos', 'A tu ritmo, cuando puedas', 'fa-shoe-prints', '#3b82f6', 'weekly', 3],
            ['Desconectar a horario', 'Cerrar la compu al terminar la jornada', 'fa-power-off', '#a855f7', 'daily', 1],
            ['Dormir 7 horas', 'Descansar bien también es trabajo', 'fa-bed', '#6366f1', 'daily', 1],
            ['Leer un rato', 'Algo que no sea del trabajo', 'fa-book-open', '#f59e0b', 'weekly', 3],
            ['Respirar y meditar', 'Cinco minutos de calma', 'fa-spa', '#ec4899', 'daily', 1],
            ['Comer sin pantallas', 'El almuerzo, lejos del escritorio', 'fa-utensils', '#ef4444', 'weekly', 3],
        ] as $i => $r) $ins->execute([...$r, $i]);
    }
    if (function_exists('g7_cfg_set')) g7_cfg_set('bn_schema', BN_SCHEMA);
}

// ═══════════════════════════════════════════════════════════
// AJUSTES (Admin › Bienestar). Viven en g7_config.
// ═══════════════════════════════════════════════════════════
function bn_cfg(string $key, $default = null) { return function_exists('g7_cfg') ? g7_cfg($key, $default) : $default; }
/** Mínimo de personas detrás de un número agregado. Nunca menos de 3. */
function bn_min(): int { return max(BN_MIN_FLOOR, min(50, (int)bn_cfg('bn_min_group', MOOD_MIN_GROUP))); }
function bn_flag(string $key, bool $default = true): bool { $v = bn_cfg($key, null); return $v === null || $v === '' ? $default : $v === '1'; }
function bn_vac_days(): int { return max(0, min(60, (int)bn_cfg('bn_vac_days', 14))); }
/** Categorías de reconocimiento activas: claves válidas de CM_KUDOS, en el orden elegido. */
function bn_kudos_cats(): array {
    $sel = json_decode((string)bn_cfg('bn_kudos_cats', ''), true);
    $sel = is_array($sel) ? $sel : BN_KUDOS_DEFAULT;
    $out = [];
    foreach ($sel as $k) if (is_string($k) && isset(CM_KUDOS[$k])) $out[$k] = ['emoji' => CM_KUDOS[$k][0], 'label' => CM_KUDOS[$k][1]];
    return $out ?: array_map(fn($k) => ['emoji' => CM_KUDOS[$k][0], 'label' => CM_KUDOS[$k][1]], array_combine(BN_KUDOS_DEFAULT, BN_KUDOS_DEFAULT));
}

// ═══════════════════════════════════════════════════════════
// QUIÉN ES QUIÉN: contexto, alcance de gestión y público de cada cosa
// ═══════════════════════════════════════════════════════════
/**
 * Contexto de una persona. 'scope' es lo que puede gestionar: null = toda la organización (admin del sitio),
 * o la lista de equipos donde es admin de equipo o tiene el permiso aparte "Gestionar Bienestar" (Admin › Módulos).
 */
function bn_ctx(PDO $db, int $uid): array {
    $q = $db->prepare('SELECT id, username, display_name, avatar, role, active FROM g7_users WHERE id = ?');
    $q->execute([$uid]);
    $u = $q->fetch(PDO::FETCH_ASSOC);
    if (!$u || !(int)$u['active']) bn_fail('Tu sesión venció: vuelve a entrar.', 401);
    $teams = []; $adminOf = [];
    $q = $db->prepare('SELECT m.team_id, m.role FROM g7_team_members m JOIN g7_teams t ON t.id = m.team_id AND t.active = 1 WHERE m.user_id = ?');
    $q->execute([$uid]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $teams[] = (int)$r['team_id']; if ($r['role'] === 'admin') $adminOf[] = (int)$r['team_id']; }
    $admin = $u['role'] === 'admin';
    $grant = !$admin && function_exists('g7_can') && g7_can($uid, 'bienestar', 'manage');
    $scope = $admin ? null : array_values(array_unique(array_merge($adminOf, $grant ? $teams : [])));
    sort($teams);
    return ['uid' => $uid, 'name' => (string)($u['display_name'] ?: $u['username']), 'username' => (string)$u['username'], 'avatar' => (string)$u['avatar'],
        'admin' => $admin, 'teams' => $teams, 'scope' => $scope, 'manage' => $admin || (bool)$scope];
}
/** Público guardado: null = toda la organización; si no, ids de equipos separados por coma. */
function bn_aud_decode($v): ?array { if ($v === null || $v === '') return null; return array_values(array_filter(array_map('intval', explode(',', (string)$v)))); }
function bn_aud_encode(?array $teams): ?string { if (!$teams) return null; $t = array_values(array_unique(array_map('intval', $teams))); sort($t); return implode(',', $t); }
/** ¿La persona está en el público? */
function bn_aud_has(?array $aud, array $ctx): bool { return $aud === null || (bool)array_intersect($aud, $ctx['teams']); }
/** ¿Quien gestiona puede tocar algo con este público? El admin todo; el de equipo solo lo que apunta a sus equipos. */
function bn_aud_can(?array $aud, array $ctx): bool {
    if (!$ctx['manage']) return false;
    if ($ctx['scope'] === null) return true;
    return $aud !== null && !array_diff($aud, $ctx['scope']);
}
/** Valida el público que elige quien gestiona: equipos existentes y dentro de su alcance. */
function bn_aud_clean(PDO $db, $in, array $ctx): ?array {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$in))));
    if ($ids) {
        $ok = array_map('intval', $db->query('SELECT id FROM g7_teams WHERE active = 1 AND id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_COLUMN));
        if (count($ok) !== count($ids)) bn_fail('Alguno de los equipos elegidos ya no existe.');
    }
    if ($ctx['scope'] !== null) {
        if (!$ids) bn_fail('Elige para qué equipos es: solo puedes gestionar los tuyos.', 403);
        if (array_diff($ids, $ctx['scope'])) bn_fail('Solo puedes elegir equipos que gestionas.', 403);
    }
    return $ids ?: null;
}
/** Personas activas de un público (null = todas). */
function bn_users_of(PDO $db, ?array $teams): array {
    if ($teams === null) return array_map('intval', $db->query('SELECT id FROM g7_users WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN));
    if (!$teams) return [];
    return array_map('intval', $db->query('SELECT DISTINCT m.user_id FROM g7_team_members m JOIN g7_users u ON u.id = m.user_id AND u.active = 1 WHERE m.team_id IN (' . implode(',', array_map('intval', $teams)) . ')')->fetchAll(PDO::FETCH_COLUMN));
}
/** Equipos que puede ver quien gestiona (para filtros y selectores). */
function bn_teams_for(PDO $db, array $ctx): array {
    $rows = $db->query('SELECT id, name, color FROM g7_teams WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    return array_values(array_filter(array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'color' => $r['color']], $rows),
        fn($t) => $ctx['scope'] === null || in_array($t['id'], $ctx['scope'], true)));
}
function bn_need_manage(array $ctx): void { if (!$ctx['manage']) bn_fail('Esta parte es para quienes gestionan Bienestar.', 403); }
function bn_need_admin(array $ctx): void { if (!$ctx['admin']) bn_fail('Solo un administrador del sitio puede cambiar esto.', 403); }
/** Trazabilidad: toda operación de gestión queda en la auditoría del sitio (Admin › Auditoría). */
function bn_audit(array $ctx, string $action, string $target = '', $detail = null): void {
    if (function_exists('g7_audit')) g7_audit('bienestar.' . $action, $target, $detail, $ctx['uid']);
}

// ═══════════════════════════════════════════════════════════
// FECHAS Y TEXTOS
// ═══════════════════════════════════════════════════════════
function bn_now(?int $now = null): int { return $now ?? time(); }
function bn_today(?int $now = null): string { return date('Y-m-d', bn_now($now)); }
function bn_date($v): ?string { $v = (string)$v; $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v); return $d && $d->format('Y-m-d') === $v ? $v : null; }
function bn_week(?int $t = null): string { return date('o-\WW', $t ?? time()); }
function bn_str($v, int $max, int $min = 0, string $what = 'El texto'): string {
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$v));
    if (mb_strlen($s) < $min) bn_fail($min <= 1 ? "$what no puede quedar vacío." : "$what tiene que tener al menos $min caracteres.");
    if (mb_strlen($s) > $max) bn_fail("$what puede tener hasta $max caracteres.");
    return $s;
}
const BN_ICONS = ['fa-leaf', 'fa-glass-water', 'fa-person-walking', 'fa-person-running', 'fa-shoe-prints', 'fa-power-off', 'fa-bed', 'fa-book-open', 'fa-spa',
    'fa-utensils', 'fa-apple-whole', 'fa-bicycle', 'fa-dumbbell', 'fa-heart', 'fa-sun', 'fa-moon', 'fa-music', 'fa-seedling', 'fa-mug-hot', 'fa-brain',
    'fa-face-smile', 'fa-hands-holding-heart', 'fa-mountain-sun', 'fa-stairs', 'fa-tree', 'fa-water', 'fa-users', 'fa-trophy', 'fa-star', 'fa-pen'];
const BN_COLORS = ['#22c55e', '#06b6d4', '#3b82f6', '#6366f1', '#a855f7', '#ec4899', '#ef4444', '#f97316', '#f59e0b', '#84cc16', '#14b8a6', '#64748b'];
function bn_icon($v, string $def = 'fa-leaf'): string { return in_array($v, BN_ICONS, true) ? $v : $def; }
function bn_color($v, string $def = '#22c55e'): string { return in_array($v, BN_COLORS, true) ? $v : $def; }

// ═══════════════════════════════════════════════════════════
// AVISOS (campana) con preferencias de cada persona
// ═══════════════════════════════════════════════════════════
function bn_prefs(PDO $db, int $uid): array {
    $def = json_decode((string)bn_cfg('bn_notif_defaults', ''), true);
    $out = [];
    foreach (BN_NOTIF_KEYS as $k => $l) $out[$k] = is_array($def) && array_key_exists($k, $def) ? (bool)$def[$k] : true;
    try {
        $q = $db->prepare("SELECT pref_value FROM g7_user_prefs WHERE user_id = ? AND pref_key = 'bn_notif' ORDER BY id DESC LIMIT 1");
        $q->execute([$uid]);
        $mine = json_decode((string)$q->fetchColumn(), true);
        if (is_array($mine)) foreach ($mine as $k => $v) if (isset($out[$k])) $out[$k] = (bool)$v;
    } catch (Throwable $e) {}
    return $out;
}
function bn_prefs_save(PDO $db, int $uid, $in): array {
    $cur = bn_prefs($db, $uid);
    foreach ((array)$in as $k => $v) if (isset($cur[$k])) $cur[$k] = (bool)$v;
    $db->prepare("DELETE FROM g7_user_prefs WHERE user_id = ? AND pref_key = 'bn_notif'")->execute([$uid]);
    $db->prepare("INSERT INTO g7_user_prefs (user_id, pref_key, pref_value) VALUES (?, 'bn_notif', ?)")->execute([$uid, json_encode($cur)]);
    return $cur;
}
/** Aviso a la campana si la persona no lo apagó. $type: bn_challenge, bn_survey, bn_kudos, bn_habit, bn_leave. */
function bn_notify(PDO $db, array $to, ?int $from, string $type, int $target, string $body, string $prefKey): int {
    $n = 0;
    $ins = $db->prepare('INSERT INTO g7_notifications (user_id, from_id, type, target_id, profile_id, body) VALUES (?, ?, ?, ?, NULL, ?)');
    foreach (array_unique(array_map('intval', $to)) as $u) {
        if ($u <= 0 || $u === $from) continue;
        if (!(bn_prefs($db, $u)[$prefKey] ?? true)) continue;
        try { $ins->execute([$u, $from, $type, $target, mb_substr($body, 0, 280)]); $n++; } catch (Throwable $e) {}
    }
    return $n;
}

// ═══════════════════════════════════════════════════════════
// INSIGNIAS
// ═══════════════════════════════════════════════════════════
/** Da una insignia una sola vez (por referencia). Devuelve true si es nueva. */
function bn_badge(PDO $db, int $uid, string $code, int $ref = 0, ?int $now = null): bool {
    if (!isset(BN_BADGES[$code])) return false;
    $st = $db->prepare('INSERT IGNORE INTO g7_bn_badges (user_id, code, ref_id, earned_at) VALUES (?, ?, ?, ?)');
    $st->execute([$uid, $code, $ref, date('Y-m-d H:i:s', bn_now($now))]);
    $new = $st->rowCount() > 0;
    if ($new && function_exists('g7AwardXP')) { try { g7AwardXP($db, $uid, 10, 'bienestar', $ref ?: null); } catch (Throwable $e) {} }
    return $new;
}
function bn_badges_mine(PDO $db, int $uid): array {
    $q = $db->prepare('SELECT code, COUNT(*) n, MAX(earned_at) last FROM g7_bn_badges WHERE user_id = ? GROUP BY code ORDER BY last DESC');
    $q->execute([$uid]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset(BN_BADGES[$r['code']])) {
        [$e, $l, $d] = BN_BADGES[$r['code']];
        $out[] = ['code' => $r['code'], 'emoji' => $e, 'label' => $l, 'desc' => $d, 'n' => (int)$r['n'], 'last' => $r['last']];
    }
    return $out;
}

// ═══════════════════════════════════════════════════════════
// ÁNIMO: usa g7_mood (mood_lib.php). Acá solo hay vistas: personal y agregada.
// ═══════════════════════════════════════════════════════════
/** Lo mío: hoy (de mood_mine), los últimos $days días, promedios de 7 y 30, distribución y racha de días respondidos. */
function bn_mood_mine(PDO $db, int $uid, int $days = 30, ?int $now = null): array {
    $now = bn_now($now);
    $base = mood_mine($db, $uid, max(30, $days), $now);
    $series = array_slice($base['week'], -$days);
    $vals = fn(array $s) => array_values(array_filter(array_map(fn($d) => $d['mood'], $s), fn($v) => $v !== null));
    $avg = fn(array $v) => $v ? round(array_sum($v) / count($v), 1) : null;
    $v30 = $vals(array_slice($base['week'], -30)); $v7 = $vals(array_slice($base['week'], -7));
    $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    foreach ($v30 as $m) $dist[$m]++;
    // período anterior de 30 días, para comparar
    $q = $db->prepare('SELECT AVG(mood) FROM g7_mood WHERE user_id = ? AND day BETWEEN ? AND ?');
    $q->execute([$uid, date('Y-m-d', strtotime('-59 days', $now)), date('Y-m-d', strtotime('-30 days', $now))]);
    $prev = $q->fetchColumn();
    $streak = 0;
    $all = $base['week'];
    for ($i = count($all) - 1; $i >= 0; $i--) {
        if ($all[$i]['mood'] !== null) $streak++;
        elseif ($i === count($all) - 1) continue;   // hoy todavía sin responder no corta la racha
        else break;
    }
    if ($streak >= 7) bn_badge($db, $uid, 'mood_week', 0, $now);
    return ['current' => $base['current'], 'can' => $base['can'], 'wait_h' => $base['wait_h'], 'series' => $series,
        'avg7' => $avg($v7), 'avg30' => $avg($v30), 'prev30' => $prev !== null && $prev !== false ? round((float)$prev, 1) : null,
        'dist' => $dist, 'answered30' => count($v30), 'streak' => $streak, 'levels' => MOOD_LEVELS, 'reasons' => MOOD_REASONS];
}

/**
 * Ánimo agregado de un grupo (null = toda la organización). Anónimo: un día, una semana o un período solo se muestra
 * si respondieron al menos $min personas DISTINTAS; si no, va vacío y sin decir cuántas hubo. Nunca devuelve usuarios.
 */
function bn_mood_agg(PDO $db, ?array $userIds, int $days = 30, ?int $now = null, ?int $min = null): array {
    $now = bn_now($now); $min = max(BN_MIN_FLOOR, $min ?? bn_min());
    $days = max(7, min(365, $days));
    $to = date('Y-m-d', $now); $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days', $now));
    $pFrom = date('Y-m-d', strtotime("$from -$days days")); $pTo = date('Y-m-d', strtotime("$from -1 day"));
    $empty = ['days' => [], 'weeks' => [], 'avg' => null, 'prev_avg' => null, 'delta' => null, 'trend' => null, 'dist' => null, 'ok' => false, 'min' => $min, 'from' => $from, 'to' => $to];
    if ($userIds !== null && !$userIds) return $empty;
    $in = $userIds === null ? '' : ' AND user_id IN (' . implode(',', array_map('intval', $userIds)) . ')';
    $q = $db->prepare("SELECT user_id, day, mood FROM g7_mood WHERE day BETWEEN ? AND ?$in");
    $q->execute([$pFrom, $to]);
    $cur = []; $prev = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { if ($r['day'] >= $from) $cur[] = $r; else $prev[] = $r; }
    $people = fn(array $rs) => count(array_unique(array_column($rs, 'user_id')));
    $avg = fn(array $rs) => $rs ? round(array_sum(array_column($rs, 'mood')) / count($rs), 2) : null;
    $byDay = []; foreach ($cur as $r) $byDay[$r['day']][] = $r;
    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("$to -$i days")); $rs = $byDay[$d] ?? [];
        $series[] = ['day' => $d, 'avg' => $people($rs) >= $min ? $avg($rs) : null];
    }
    $byWeek = []; foreach ($cur as $r) $byWeek[bn_week(strtotime($r['day']))][] = $r;
    $weeks = [];
    foreach ($byWeek as $w => $rs) $weeks[] = ['week' => $w, 'avg' => $people($rs) >= $min ? $avg($rs) : null];
    usort($weeks, fn($a, $b) => strcmp($a['week'], $b['week']));
    if ($people($cur) < $min) return ['days' => $series, 'weeks' => $weeks] + $empty;
    $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    foreach ($cur as $r) $dist[(int)$r['mood']]++;
    $pct = []; foreach ($dist as $k => $n) $pct[$k] = (int)round($n / count($cur) * 100);
    $a = $avg($cur); $p = $people($prev) >= $min ? $avg($prev) : null;
    $delta = $p !== null ? round($a - $p, 2) : null;
    return ['days' => $series, 'weeks' => $weeks, 'avg' => $a, 'prev_avg' => $p, 'delta' => $delta,
        'trend' => $delta === null ? null : ($delta >= 0.2 ? 'up' : ($delta <= -0.2 ? 'down' : 'flat')),
        'dist' => $pct, 'ok' => true, 'min' => $min, 'from' => $from, 'to' => $to];
}
/** Ánimo por equipo (para quien gestiona): solo equipos de su alcance y con el mínimo de personas. */
function bn_mood_teams(PDO $db, array $ctx, int $days = 30, ?int $now = null): array {
    $out = [];
    foreach (bn_teams_for($db, $ctx) as $t) {
        $a = bn_mood_agg($db, bn_users_of($db, [$t['id']]), $days, $now);
        $out[] = $t + ['avg' => $a['avg'], 'delta' => $a['delta'], 'trend' => $a['trend'], 'ok' => $a['ok']];
    }
    return $out;
}

// ═══════════════════════════════════════════════════════════
// CARGA LABORAL percibida (no evalúa a nadie: lo individual es solo de cada persona)
// ═══════════════════════════════════════════════════════════
function bn_load_set(PDO $db, int $uid, int $level, ?int $now = null): array {
    if (!isset(BN_LOAD_LEVELS[$level])) bn_fail('Elige un nivel de 1 a 5.');
    $now = bn_now($now);
    $db->prepare('INSERT INTO g7_bn_load (user_id, week, level, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE level = VALUES(level), updated_at = VALUES(updated_at)')
       ->execute([$uid, bn_week($now), $level, date('Y-m-d H:i:s', $now)]);
    return ['week' => bn_week($now), 'level' => $level];
}
/** Datos objetivos de mis tareas: abiertas, vencidas, que vencen esta semana y horas planificadas en Solicitudes. */
function bn_task_load(PDO $db, array $userIds, ?int $now = null): array {
    $out = ['open' => 0, 'overdue' => 0, 'due7' => 0, 'planned_h' => null];
    if (!$userIds) return $out;
    $now = bn_now($now); $today = date('Y-m-d', $now); $in = implode(',', array_map('intval', $userIds));
    try {
        $done = [];
        if (is_file(dirname(__DIR__) . '/tareas_lib.php')) { require_once dirname(__DIR__) . '/tareas_lib.php'; $done = tk_status_ids(tk_config($db), true); }
        $notDone = $done ? ' AND t.status NOT IN (' . implode(',', array_map([$db, 'quote'], $done)) . ')' : '';
        $q = $db->prepare("SELECT COUNT(DISTINCT t.id) open, COUNT(DISTINCT CASE WHEN t.due_date < ? THEN t.id END) overdue,
            COUNT(DISTINCT CASE WHEN t.due_date BETWEEN ? AND ? THEN t.id END) due7
            FROM g7_tasks t JOIN g7_task_assignees a ON a.task_id = t.id WHERE a.user_id IN ($in) AND t.archived = 0 AND t.completed_at IS NULL$notDone");
        $q->execute([$today, $today, date('Y-m-d', strtotime('+6 days', $now))]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        $out['open'] = (int)$r['open']; $out['overdue'] = (int)$r['overdue']; $out['due7'] = (int)$r['due7'];
    } catch (Throwable $e) {}
    try {
        $q = $db->prepare("SELECT COALESCE(SUM(minutes), 0) FROM g7_wk_load WHERE user_id IN ($in) AND day BETWEEN ? AND ?");
        $q->execute([$today, date('Y-m-d', strtotime('+6 days', $now))]);
        $out['planned_h'] = round((int)$q->fetchColumn() / 60, 1);
    } catch (Throwable $e) {}
    return $out;
}
/** Mi carga: las últimas 12 semanas, lo de mis tareas y una sugerencia si viene alta. Solo para mí. */
function bn_load_mine(PDO $db, int $uid, ?int $now = null): array {
    $now = bn_now($now);
    $q = $db->prepare('SELECT week, level FROM g7_bn_load WHERE user_id = ? ORDER BY week DESC LIMIT 12');
    $q->execute([$uid]);
    $by = []; foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $by[$r['week']] = (int)$r['level'];
    $hist = [];
    for ($i = 11; $i >= 0; $i--) { $w = bn_week(strtotime("-$i weeks", $now)); $hist[] = ['week' => $w, 'level' => $by[$w] ?? null]; }
    $cur = $by[bn_week($now)] ?? null;
    $last2 = array_slice(array_filter(array_column($hist, 'level'), fn($v) => $v !== null), -2);
    $tasks = bn_task_load($db, [$uid], $now);
    $hint = null;
    if (count($last2) === 2 && min($last2) >= 4) $hint = 'Hace dos semanas que marcas una carga alta. Hablarlo con tu responsable puede ayudar a repartir o reordenar prioridades.';
    elseif ($tasks['overdue'] >= 5) $hint = 'Tienes varias tareas vencidas. Revisar fechas con tu equipo puede bajar la presión.';
    return ['current' => $cur, 'history' => $hist, 'tasks' => $tasks, 'hint' => $hint, 'levels' => BN_LOAD_LEVELS, 'week' => bn_week($now)];
}
/**
 * Carga por equipo (quien gestiona, su alcance): promedio semanal y % de "pesada o desbordada", con el mínimo de personas;
 * posible sobrecarga si dos semanas seguidas el promedio es >= 3,8 o más del 40% la marca alta. Más datos de tareas del equipo.
 */
function bn_load_teams(PDO $db, array $ctx, int $weeks = 8, ?int $now = null): array {
    $now = bn_now($now); $min = bn_min();
    $wk = []; for ($i = $weeks - 1; $i >= 0; $i--) $wk[] = bn_week(strtotime("-$i weeks", $now));
    $groups = [];
    if ($ctx['scope'] === null) $groups[] = ['id' => 0, 'name' => 'Toda la organización', 'color' => '#64748b', 'users' => bn_users_of($db, null)];
    foreach (bn_teams_for($db, $ctx) as $t) $groups[] = $t + ['users' => bn_users_of($db, [$t['id']])];
    $in = implode(',', array_map([$db, 'quote'], $wk));
    $out = [];
    foreach ($groups as $g) {
        $series = []; $alert = 0; $streakHi = 0;
        if ($g['users']) {
            $rows = $db->query("SELECT week, level FROM g7_bn_load WHERE week IN ($in) AND user_id IN (" . implode(',', $g['users']) . ')')->fetchAll(PDO::FETCH_ASSOC);
            $byW = []; foreach ($rows as $r) $byW[$r['week']][] = (int)$r['level'];
            foreach ($wk as $w) {
                $v = $byW[$w] ?? [];
                if (count($v) >= $min) {
                    $avg = round(array_sum($v) / count($v), 1); $hi = (int)round(count(array_filter($v, fn($x) => $x >= 4)) / count($v) * 100);
                    $series[] = ['week' => $w, 'avg' => $avg, 'high' => $hi];
                    $streakHi = ($avg >= 3.8 || $hi > 40) ? $streakHi + 1 : 0;
                    if ($streakHi >= 2) $alert = 1;
                } else { $series[] = ['week' => $w, 'avg' => null, 'high' => null]; $streakHi = 0; }
            }
        }
        $tasks = count($g['users']) >= $min ? bn_task_load($db, $g['users'], $now) : null;
        $last = end($series) ?: ['avg' => null];
        $out[] = ['id' => $g['id'], 'name' => $g['name'], 'color' => $g['color'], 'size' => count($g['users']) >= $min ? count($g['users']) : null,
            'series' => $series, 'avg' => $last['avg'], 'alert' => (bool)$alert,
            'tasks' => $tasks ? ['open_pp' => round($tasks['open'] / max(1, count($g['users'])), 1), 'overdue' => $tasks['overdue'], 'planned_h' => $tasks['planned_h']] : null];
    }
    return ['groups' => $out, 'weeks' => $wk, 'min' => $min];
}

// ═══════════════════════════════════════════════════════════
// RECONOCIMIENTOS: los públicos son kudos de Comunidad (g7_posts); los privados, de Bienestar
// ═══════════════════════════════════════════════════════════
function bn_kudos_give(PDO $db, array $ctx, int $to, string $badge, string $msg, bool $private, ?int $now = null): array {
    $cats = bn_kudos_cats();
    if (!isset($cats[$badge])) bn_fail('Elige una categoría.');
    if ($private) {
        // mismas reglas que Comunidad (cm_kudos_check), y el tope diario cuenta públicos y privados juntos
        cm_kudos_check($db, $ctx['uid'], $to, $badge, $msg);
        $db->prepare('INSERT INTO g7_bn_kudos_private (from_id, to_id, badge, body, created_at) VALUES (?, ?, ?, ?, ?)')
           ->execute([$ctx['uid'], $to, $badge, trim($msg), date('Y-m-d H:i:s', bn_now($now))]);
        $id = (int)$db->lastInsertId();
        bn_notify($db, [$to], $ctx['uid'], 'bn_kudos', $id, CM_KUDOS[$badge][0] . ' ' . CM_KUDOS[$badge][1] . ' (privado) — ' . trim($msg), 'kudos');
        $out = ['private' => true, 'id' => $id];
    } else {
        $id = cm_give_kudos($db, $ctx['uid'], $to, $badge, $msg);   // Comunidad: publica, avisa y anota la actividad
        $out = ['private' => false, 'id' => $id];
    }
    bn_badge($db, $ctx['uid'], 'kudos_given', 0, $now);
    return $out;
}
/**
 * Muro de agradecimientos: kudos públicos de Comunidad. filter: all | received | given; cat: categoría; before: id para paginar;
 * after: id para traer solo los nuevos (actualización en vivo).
 */
function bn_kudos_feed(PDO $db, int $uid, string $filter = 'all', string $cat = '', int $before = 0, int $after = 0, int $limit = 15): array {
    $w = ["p.kind = 'kudos'"]; $a = [];
    if ($filter === 'received') { $w[] = 'p.target_user_id = ?'; $a[] = $uid; }
    elseif ($filter === 'given') { $w[] = 'p.user_id = ?'; $a[] = $uid; }
    if ($cat !== '' && isset(CM_KUDOS[$cat])) { $w[] = 'p.badge = ?'; $a[] = $cat; }
    if ($before > 0) { $w[] = 'p.id < ?'; $a[] = $before; }
    if ($after > 0) { $w[] = 'p.id > ?'; $a[] = $after; }
    $limit = max(1, min(30, $limit));
    $q = $db->prepare('SELECT p.*, u.username, u.display_name, u.avatar FROM g7_posts p JOIN g7_users u ON u.id = p.user_id WHERE ' . implode(' AND ', $w) . ' ORDER BY p.id DESC LIMIT ' . ($limit + 1));
    $q->execute($a);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > $limit; $rows = array_slice($rows, 0, $limit);
    $posts = cm_hydrate($db, $rows, $uid);
    $out = [];
    foreach ($posts as $p) $out[] = bn_kudos_view($p, $uid);
    return ['items' => $out, 'more' => $more];
}
/** Solo lo que la pantalla necesita (sin columnas internas). */
function bn_kudos_view(array $p, int $uid): array {
    $person = fn($u) => $u ? ['id' => (int)$u['id'], 'name' => (string)($u['display_name'] ?: $u['username']), 'avatar' => (string)($u['avatar'] ?? '')] : null;
    return ['id' => (int)$p['id'], 'from' => ['id' => (int)$p['user_id'], 'name' => (string)($p['display_name'] ?: $p['username']), 'avatar' => (string)($p['avatar'] ?? '')],
        'to' => $person($p['target'] ?? null), 'badge' => (string)$p['badge'], 'cat' => isset(CM_KUDOS[$p['badge']]) ? ['emoji' => CM_KUDOS[$p['badge']][0], 'label' => CM_KUDOS[$p['badge']][1]] : ['emoji' => '🏆', 'label' => 'Reconocimiento'],
        'body' => (string)$p['body'], 'at' => (string)$p['created_at'], 'reactions' => $p['reactions'], 'my_reaction' => $p['my_reaction'] ?: null,
        'replies' => array_map(fn($r) => ['id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'name' => (string)($r['display_name'] ?: $r['username']), 'avatar' => (string)($r['avatar'] ?? ''), 'body' => (string)$r['body'], 'at' => (string)$r['created_at']], $p['replies']),
        'mine' => (int)$p['user_id'] === $uid, 'private' => false];
}
/** Mis reconocimientos privados (recibidos y dados). Solo los ven quien lo dio y quien lo recibió. */
function bn_kudos_private(PDO $db, int $uid, int $limit = 30): array {
    $q = $db->prepare('SELECT k.*, f.username fu, f.display_name fd, f.avatar fa, t.username tu, t.display_name td, t.avatar ta
        FROM g7_bn_kudos_private k JOIN g7_users f ON f.id = k.from_id JOIN g7_users t ON t.id = k.to_id
        WHERE k.from_id = ? OR k.to_id = ? ORDER BY k.id DESC LIMIT ' . max(1, min(100, $limit)));
    $q->execute([$uid, $uid]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'private' => true, 'badge' => $r['badge'],
        'cat' => isset(CM_KUDOS[$r['badge']]) ? ['emoji' => CM_KUDOS[$r['badge']][0], 'label' => CM_KUDOS[$r['badge']][1]] : ['emoji' => '🏆', 'label' => 'Reconocimiento'],
        'from' => ['id' => (int)$r['from_id'], 'name' => (string)($r['fd'] ?: $r['fu']), 'avatar' => (string)$r['fa']],
        'to' => ['id' => (int)$r['to_id'], 'name' => (string)($r['td'] ?: $r['tu']), 'avatar' => (string)$r['ta']],
        'body' => (string)$r['body'], 'at' => (string)$r['created_at'], 'thanked' => (bool)$r['thanked'], 'mine' => (int)$r['from_id'] === $uid], $q->fetchAll(PDO::FETCH_ASSOC));
}
/** Quien recibió un reconocimiento privado puede responder "¡Gracias!" (le llega a quien lo dio). */
function bn_kudos_thank(PDO $db, int $uid, int $id): void {
    $q = $db->prepare('SELECT * FROM g7_bn_kudos_private WHERE id = ? AND to_id = ?'); $q->execute([$id, $uid]);
    $k = $q->fetch(PDO::FETCH_ASSOC);
    if (!$k) bn_fail('Ese reconocimiento no está.', 404);
    if ((int)$k['thanked']) return;
    $db->prepare('UPDATE g7_bn_kudos_private SET thanked = 1 WHERE id = ?')->execute([$id]);
    bn_notify($db, [(int)$k['from_id']], $uid, 'bn_kudos', $id, '¡Gracias por tu reconocimiento! 💛', 'kudos');
}
/** Los últimos reconocimientos que recibí (públicos y privados juntos), para la portada. */
function bn_kudos_received(PDO $db, int $uid, int $n = 4): array {
    $pub = bn_kudos_feed($db, $uid, 'received', '', 0, 0, $n)['items'];
    $priv = array_values(array_filter(bn_kudos_private($db, $uid, 20), fn($k) => $k['to']['id'] === $uid));
    $all = array_merge($pub, $priv);
    usort($all, fn($a, $b) => strcmp($b['at'], $a['at']));
    return array_slice($all, 0, $n);
}

require_once __DIR__ . '/lib_habitos.php';
require_once __DIR__ . '/lib_retos.php';
require_once __DIR__ . '/lib_encuestas.php';
require_once __DIR__ . '/lib_ausencias.php';

// ═══════════════════════════════════════════════════════════
// TAREAS PROGRAMADAS (las dispara notif.php al consultar la campana; como mucho cada 10 minutos)
// ═══════════════════════════════════════════════════════════
function bn_sweep(PDO $db, bool $force = false, ?int $now = null): void {
    $now = bn_now($now);
    if (!$force && (int)bn_cfg('bn_sweep_at', 0) > $now - 600) return;
    if (function_exists('g7_cfg_set')) g7_cfg_set('bn_sweep_at', (string)$now);
    bn_ch_sweep($db, $now);
    bn_sv_sweep($db, $now);
}
/** Lo de cada persona al abrir la campana: recordatorio de hábitos pendientes del día. */
function bn_run_user(PDO $db, int $uid, ?int $now = null): void {
    bn_install($db);
    bn_habit_remind($db, $uid, $now);
    bn_sweep($db, false, $now);
}

/** Un kudos público (de Comunidad) que existe; si no, 404. */
function bn_kudos_need_public(PDO $db, int $id): array {
    $q = $db->prepare("SELECT * FROM g7_posts WHERE id = ? AND kind = 'kudos'"); $q->execute([$id]);
    $p = $q->fetch(PDO::FETCH_ASSOC);
    if (!$p) bn_fail('Ese reconocimiento ya no está.', 404);
    return $p;
}

/**
 * Participación agregada de un grupo (null = organización): qué porcentaje usó cada parte en los últimos 7 días.
 * Solo porcentajes, y solo si el grupo y cada conteo llegan al mínimo de personas (si no, null).
 */
function bn_participation(PDO $db, ?array $users, ?int $now = null): array {
    $now = bn_now($now); $min = bn_min();
    $ids = $users ?? bn_users_of($db, null);
    $n = count($ids);
    $keys = ['mood' => null, 'habits' => null, 'challenges' => null, 'load' => null, 'kudos' => null];
    if ($n < $min) return $keys + ['size' => null, 'min' => $min];
    $in = implode(',', array_map('intval', $ids)); $d7 = date('Y-m-d', strtotime('-6 days', $now));
    $cnt = [
        'mood' => "SELECT COUNT(DISTINCT user_id) FROM g7_mood WHERE day >= '$d7' AND user_id IN ($in)",
        'habits' => "SELECT COUNT(DISTINCT h.user_id) FROM g7_bn_habit_log l JOIN g7_bn_habits h ON h.id = l.habit_id WHERE l.day >= '$d7' AND h.user_id IN ($in)",
        'challenges' => "SELECT COUNT(DISTINCT user_id) FROM g7_bn_ch_log WHERE day >= '$d7' AND user_id IN ($in)",
        'load' => "SELECT COUNT(DISTINCT user_id) FROM g7_bn_load WHERE week IN ('" . bn_week($now) . "', '" . bn_week(strtotime('-1 week', $now)) . "') AND user_id IN ($in)",
        'kudos' => "SELECT COUNT(DISTINCT user_id) FROM g7_posts WHERE kind = 'kudos' AND created_at >= '$d7' AND user_id IN ($in)",
    ];
    foreach ($cnt as $k => $sql) { try { $c = (int)$db->query($sql)->fetchColumn(); $keys[$k] = $c >= $min ? (int)round($c / $n * 100) : ($c === 0 ? 0 : null); } catch (Throwable $e) {} }
    return $keys + ['size' => $n, 'min' => $min];
}

/** Lo que la pantalla necesita saber una vez: categorías, niveles, permisos y ajustes visibles. */
function bn_meta(PDO $db, array $ctx): array {
    return ['kudos_cats' => bn_kudos_cats(), 'load_levels' => BN_LOAD_LEVELS, 'mood_levels' => MOOD_LEVELS, 'mood_reasons' => MOOD_REASONS, 'notif' => BN_NOTIF_KEYS,
        'badges' => array_map(fn($b) => ['emoji' => $b[0], 'label' => $b[1], 'desc' => $b[2]], BN_BADGES), 'leave_types' => bn_leave_types(), 'leave_status' => BN_LEAVE_STATUS,
        'manage' => $ctx['manage'], 'admin' => $ctx['admin'], 'org_scope' => $ctx['scope'] === null, 'min' => bn_min(), 'show_org_mood' => bn_flag('bn_show_org_mood'),
        'rankings' => bn_flag('bn_rankings'), 'uid' => $ctx['uid'], 'today' => bn_today(), 'week' => bn_week(), 'sv_cats' => BN_SV_CATS];
}

// ── Admin › Bienestar ──
function bn_admin_state(PDO $db, array $ctx): array {
    $types = json_decode((string)bn_cfg('bn_leave_types', ''), true);
    $defs = json_decode((string)bn_cfg('bn_notif_defaults', ''), true);
    return ['settings' => ['min_group' => bn_min(), 'show_org_mood' => bn_flag('bn_show_org_mood'), 'rankings' => bn_flag('bn_rankings'), 'vac_days' => bn_vac_days(),
            'kudos_cats' => array_keys(bn_kudos_cats()), 'leave_types' => is_array($types) ? array_values(array_unique(array_merge(['vacaciones'], $types))) : array_keys(BN_LEAVE_TYPES),
            'notif_defaults' => array_combine(array_keys(BN_NOTIF_KEYS), array_map(fn($k) => is_array($defs) && array_key_exists($k, $defs) ? (bool)$defs[$k] : true, array_keys(BN_NOTIF_KEYS)))],
        'all_kudos' => array_map(fn($k) => ['emoji' => $k[0], 'label' => $k[1]], CM_KUDOS), 'all_leave' => BN_LEAVE_TYPES, 'notif' => BN_NOTIF_KEYS,
        'tpls' => bn_tpl_list($db, true), 'participation' => bn_participation($db, null), 'mood' => bn_mood_agg($db, null, 30), 'icons' => BN_ICONS, 'colors' => BN_COLORS,
        'counts' => ['challenges' => (int)$db->query("SELECT COUNT(*) FROM g7_bn_challenges WHERE status = 'published'")->fetchColumn(),
            'surveys' => (int)$db->query("SELECT COUNT(*) FROM g7_bn_surveys WHERE status = 'published'")->fetchColumn(),
            'programs' => (int)$db->query('SELECT COUNT(*) FROM g7_bn_programs WHERE active = 1')->fetchColumn(),
            'leave_pending' => (int)$db->query("SELECT COUNT(*) FROM g7_bn_leave WHERE status = 'pending'")->fetchColumn()]];
}
function bn_admin_save(PDO $db, array $ctx, array $in): void {
    bn_need_admin($ctx);
    $s = (array)($in['settings'] ?? []);
    $old = bn_admin_state($db, $ctx)['settings'];
    $min = (int)($s['min_group'] ?? bn_min());
    if ($min < BN_MIN_FLOOR || $min > 50) bn_fail('El mínimo de personas tiene que estar entre ' . BN_MIN_FLOOR . ' y 50.');
    $vac = (int)($s['vac_days'] ?? bn_vac_days());
    if ($vac < 0 || $vac > 60) bn_fail('Los días de vacaciones por año van de 0 a 60.');
    $cats = array_values(array_unique(array_filter((array)($s['kudos_cats'] ?? []), fn($k) => is_string($k) && isset(CM_KUDOS[$k]))));
    if (count($cats) < 2) bn_fail('Deja al menos dos categorías de reconocimiento.');
    $types = array_values(array_unique(array_merge(['vacaciones'], array_filter((array)($s['leave_types'] ?? []), fn($k) => is_string($k) && isset(BN_LEAVE_TYPES[$k])))));
    $defs = []; foreach (BN_NOTIF_KEYS as $k => $l) $defs[$k] = !empty(($s['notif_defaults'] ?? [])[$k]);
    g7_cfg_set('bn_min_group', (string)$min);
    g7_cfg_set('bn_show_org_mood', !empty($s['show_org_mood']) ? '1' : '0');
    g7_cfg_set('bn_rankings', !empty($s['rankings']) ? '1' : '0');
    g7_cfg_set('bn_vac_days', (string)$vac);
    g7_cfg_set('bn_kudos_cats', json_encode($cats));
    g7_cfg_set('bn_leave_types', json_encode($types));
    g7_cfg_set('bn_notif_defaults', json_encode($defs));
    $new = bn_admin_state($db, $ctx)['settings'];
    $diff = []; foreach ($new as $k => $v) if ($v !== ($old[$k] ?? null)) $diff[$k] = ['antes' => $old[$k] ?? null, 'ahora' => $v];
    bn_audit($ctx, 'settings', '', $diff);
}

// ═══════════════════════════════════════════════════════════
// PORTADA: todo lo de la pantalla principal en un solo pedido
// ═══════════════════════════════════════════════════════════
function bn_home(PDO $db, array $ctx, ?int $now = null): array {
    $uid = $ctx['uid'];
    $mood = bn_mood_mine($db, $uid, 14, $now);
    $org = bn_flag('bn_show_org_mood') ? bn_mood_agg($db, null, 30, $now) : null;
    $habits = bn_habit_list($db, $uid, $now);
    $chs = bn_ch_list($db, $ctx, $now);
    return [
        'me' => ['name' => $ctx['name'], 'avatar' => $ctx['avatar']],
        'mood' => ['current' => $mood['current'], 'can' => $mood['can'], 'wait_h' => $mood['wait_h'], 'series' => array_slice($mood['series'], -7),
            'avg7' => $mood['avg7'], 'streak' => $mood['streak']],
        'org_mood' => $org ? ['avg' => $org['avg'], 'trend' => $org['trend'], 'delta' => $org['delta'], 'dist' => $org['dist'], 'ok' => $org['ok'], 'min' => $org['min'],
            'weeks' => array_slice($org['weeks'], -5)] : null,
        'habits' => ['total' => count($habits), 'done_today' => count(array_filter($habits, fn($h) => $h['due_today'] && $h['done_today'])),
            'due_today' => count(array_filter($habits, fn($h) => $h['due_today'])),
            'top' => array_slice(array_map(fn($h) => ['id' => $h['id'], 'title' => $h['title'], 'icon' => $h['icon'], 'color' => $h['color'], 'streak' => $h['streak'], 'unit' => $h['streak_unit'], 'done_today' => $h['done_today'], 'due_today' => $h['due_today']], $habits), 0, 4)],
        'challenges' => array_slice(array_values(array_filter($chs, fn($c) => $c['state'] === 'active')), 0, 3),
        'kudos' => bn_kudos_received($db, $uid, 3),
        'surveys' => array_map(fn($s) => ['id' => $s['id'], 'title' => $s['title'], 'end_date' => $s['end_date'], 'n_questions' => $s['n_questions'], 'anonymous' => $s['anonymous']], bn_sv_pending($db, $ctx, $now)),
        'load' => (function () use ($db, $uid, $now) { $l = bn_load_mine($db, $uid, $now); return ['current' => $l['current'], 'tasks' => $l['tasks'], 'hint' => $l['hint'], 'last' => array_slice($l['history'], -6)]; })(),
        'leave' => bn_leave_summary($db, $uid, $now),
        'badges' => array_slice(bn_badges_mine($db, $uid), 0, 6),
        'programs' => bn_programs_active($db, $ctx, $now),
        'manage' => $ctx['manage'] ? ['approvals' => count(bn_leave_to_decide($db, $ctx))] : null,
    ];
}
