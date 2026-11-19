<?php
/**
 * avatar_frame_lib.php — Marco de la foto de perfil: cada persona elige uno (Mi configuración › Mi perfil, o el paso de la
 * foto del onboarding) y Cronos lo dibuja alrededor de su foto en todas partes (barra, chat, perfil, comunidad…).
 * Los tipos son los de Profile Studio (social/perfiles/render.js › FRAMES), dibujados con CSS (assets/g7-frames.css).
 * Se guarda como preferencia: g7_user_prefs, clave 'avatar_frame', valor {"type","color","color2"}.
 * topbar.php pasa a cada página los marcos de todos (G7_FRAMES) y assets/g7-frames.js los aplica.
 * Doc: toolz/MARCOS.md
 */

const G7_AF_TYPES = ['ring' => 'Aro', 'double' => 'Doble aro', 'dashed' => 'Punteado', 'gradient' => 'Degradado', 'neon' => 'Neón', 'glow' => 'Luminoso', 'gamer' => 'Gamer', 'minimal' => 'Minimalista'];

/** Valida un marco. Devuelve ['type','color','color2'] o null (= sin marco). */
function g7_af_clean($v): ?array {
    if (!is_array($v)) return null;
    $t = (string)($v['type'] ?? '');
    if (!isset(G7_AF_TYPES[$t])) return null;
    $c = fn($x, $d) => preg_match('/^#[0-9a-fA-F]{6}$/', (string)$x) ? strtolower((string)$x) : $d;
    return ['type' => $t, 'color' => $c($v['color'] ?? '', '#ff6600'), 'color2' => $c($v['color2'] ?? '', '#7c3aed')];
}

/** Marcos de todas las personas activas que eligieron uno: [user_id => marco]. Una consulta por página. */
function g7_af_all(PDO $db): array {
    static $all = null; if ($all !== null) return $all;
    $all = [];
    try {
        $q = $db->query("SELECT p.user_id, p.pref_value FROM g7_user_prefs p JOIN g7_users u ON u.id = p.user_id WHERE p.pref_key = 'avatar_frame' AND u.active = 1");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if ($f = g7_af_clean(json_decode((string)$r['pref_value'], true))) $all[(int)$r['user_id']] = $f;
    } catch (Throwable $e) { $all = []; }
    return $all;
}

/** Guarda (o quita, con null) el marco de una persona. */
function g7_af_save(PDO $db, int $uid, $v): ?array {
    $f = g7_af_clean($v);
    if ($f === null) { $db->prepare("DELETE FROM g7_user_prefs WHERE user_id = ? AND pref_key = 'avatar_frame'")->execute([$uid]); return null; }
    $j = json_encode($f);
    $db->prepare("INSERT INTO g7_user_prefs (user_id, pref_key, pref_value) VALUES (?, 'avatar_frame', ?) ON DUPLICATE KEY UPDATE pref_value = ?")->execute([$uid, $j, $j]);
    return $f;
}
