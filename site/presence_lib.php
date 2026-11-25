<?php
/**
 * presence_lib.php — Estado de cada persona (Disponible, En reunión, Almorzando…) e insignia sobre su foto.
 * Un solo catálogo para el perfil, el chat y Comunidad. Se guarda en g7_users.availability (clave), focus_text (mensaje)
 * y availability_until (cuándo vuelve solo a Disponible). La insignia elegida, en g7_user_prefs 'avatar_badge'.
 * Doc: toolz/ESTADOS.md
 */

/** clave => [nombre, color, emoji, minutos que dura por defecto (0 = hasta que la cambie)] */
const G7_PRESENCE = [
    'available' => ['Disponible', '#22c55e', '', 0],
    'busy'      => ['Ocupado', '#f59e0b', '⛔', 120],
    'meeting'   => ['En reunión', '#f59e0b', '📅', 60],
    'call'      => ['En una llamada', '#f59e0b', '📞', 30],
    'focus'     => ['Concentrado', '#60a5fa', '🎯', 120],
    'dnd'       => ['No molestar', '#ef4444', '🔕', 120],
    'lunch'     => ['Almorzando', '#fb923c', '🍽️', 60],
    'break'     => ['En un descanso', '#a78bfa', '☕', 15],
    'brb'       => ['Vuelvo en un rato', '#a78bfa', '⏳', 30],
    'medical'   => ['En el médico', '#38bdf8', '🩺', 180],
    'errand'    => ['Haciendo un trámite', '#38bdf8', '📄', 120],
    'commute'   => ['En camino', '#38bdf8', '🚗', 60],
    'remote'    => ['Trabajando desde casa', '#2dd4bf', '🏠', 480],
    'visit'     => ['Visitando a un cliente', '#2dd4bf', '🤝', 180],
    'training'  => ['En una capacitación', '#818cf8', '🎓', 120],
    'away'      => ['Ausente', '#a1a1aa', '💤', 0],
    'offhours'  => ['Fuera de horario', '#71717a', '🌙', 0],
    'leave'     => ['De vacaciones', '#f472b6', '🏖️', 0],
    'sick'      => ['De licencia', '#f472b6', '🤒', 0],
];
/** Insignias para la foto cuando no hay un estado especial: clave => [emoji, nombre] */
const G7_BADGES = [
    'star' => ['⭐', 'Estrella'], 'fire' => ['🔥', 'Fuego'], 'rocket' => ['🚀', 'Cohete'], 'crown' => ['👑', 'Corona'], 'bolt' => ['⚡', 'Rayo'],
    'heart' => ['❤️', 'Corazón'], 'palette' => ['🎨', 'Diseño'], 'code' => ['💻', 'Código'], 'pen' => ['✍️', 'Redacción'], 'camera' => ['📷', 'Foto'],
    'video' => ['🎬', 'Video'], 'chart' => ['📈', 'Datos'], 'mega' => ['📣', 'Marketing'], 'idea' => ['💡', 'Ideas'], 'game' => ['🎮', 'Juegos'],
    'music' => ['🎧', 'Música'], 'ball' => ['⚽', 'Fútbol'], 'coffee' => ['🧉', 'Mate'], 'plant' => ['🌱', 'Nuevo en el equipo'], 'cake' => ['🎂', 'Cumpleaños'],
];

function pr_install(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    if (g7_cfg('presence_schema') === '1') return;
    $db->exec('ALTER TABLE g7_users ADD COLUMN IF NOT EXISTS availability_until DATETIME NULL');
    g7_cfg_set('presence_schema', '1');
}
function pr_key(?string $k): string { return isset(G7_PRESENCE[(string)$k]) ? (string)$k : 'available'; }

/** Los estados con hora de fin que ya pasó vuelven a Disponible. */
function pr_sweep(PDO $db): void {
    pr_install($db);
    if (!$db->query('SELECT 1 FROM g7_users WHERE availability_until IS NOT NULL AND availability_until <= NOW() LIMIT 1')->fetchColumn()) return;
    $db->exec("UPDATE g7_users SET availability = 'available', focus_text = '', availability_until = NULL WHERE availability_until IS NOT NULL AND availability_until <= NOW()");
}
/** Cambia el estado. $min: minutos hasta volver a Disponible; null = lo habitual de ese estado; 0 = hasta que lo cambie. */
function pr_set(PDO $db, int $uid, string $key, string $text, ?int $min = null): array {
    pr_install($db);
    $key = pr_key($key);
    $text = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($text))), 0, 140);
    $min = $key === 'available' ? 0 : max(0, min(525600, $min ?? G7_PRESENCE[$key][3]));
    $until = $min ? date('Y-m-d H:i:s', time() + $min * 60) : null;
    $db->prepare('UPDATE g7_users SET availability = ?, focus_text = ?, availability_until = ?, last_seen = NOW() WHERE id = ?')->execute([$key, $text, $until, $uid]);
    return ['key' => $key, 'text' => $text, 'until' => $until];
}
function pr_badge_set(PDO $db, int $uid, string $b): void {
    if (!isset(G7_BADGES[$b])) { $db->prepare("DELETE FROM g7_user_prefs WHERE user_id = ? AND pref_key = 'avatar_badge'")->execute([$uid]); return; }
    $db->prepare("INSERT INTO g7_user_prefs (user_id, pref_key, pref_value) VALUES (?, 'avatar_badge', ?) ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)")->execute([$uid, $b]);
}
function pr_badge_get(PDO $db, int $uid): string {
    $q = $db->prepare("SELECT pref_value FROM g7_user_prefs WHERE user_id = ? AND pref_key = 'avatar_badge'"); $q->execute([$uid]);
    $b = (string)$q->fetchColumn();
    return isset(G7_BADGES[$b]) ? $b : '';
}
/** Insignias de todos: [user_id => [emoji, título, color, clave del estado]]. El estado gana a la insignia elegida; un estado viejo (2 días sin entrar) no se muestra. */
function pr_badges(PDO $db): array {
    $out = [];
    try {
        pr_sweep($db);
        foreach ($db->query("SELECT u.id, u.availability, u.focus_text, u.last_seen > NOW() - INTERVAL 2 DAY AS fresh, p.pref_value AS b
            FROM g7_users u LEFT JOIN g7_user_prefs p ON p.user_id = u.id AND p.pref_key = 'avatar_badge'
            WHERE u.active = 1 AND (COALESCE(u.availability, 'available') <> 'available' OR p.pref_value IS NOT NULL)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = pr_key($r['availability']); $s = G7_PRESENCE[$k];
            if ($s[2] !== '' && ($r['fresh'] || in_array($k, ['leave', 'sick'], true))) $out[(int)$r['id']] = [$s[2], $s[0] . ((string)$r['focus_text'] !== '' ? ' · ' . $r['focus_text'] : ''), $s[1], $k];
            elseif (isset(G7_BADGES[(string)$r['b']])) $out[(int)$r['id']] = [G7_BADGES[$r['b']][0], G7_BADGES[$r['b']][1], ''];
        }
    } catch (Throwable $e) {}
    return $out;
}
