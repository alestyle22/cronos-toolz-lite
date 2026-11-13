<?php
// ═══════════════════════════════════════════════════════
// TOOLZ — Motor de Gamificación
// Include this file from auth.php or wherever needed
// ═══════════════════════════════════════════════════════

// ── CONSTANTS ─────────────────────────────────────────
if (!defined('G7_LEVELS')) define('G7_LEVELS', [
    'rookie' => ['label'=>'Rookie',  'emoji'=>'🌱', 'min'=>0,    'color'=>'#71717a'],
    'junior' => ['label'=>'Junior',  'emoji'=>'⭐', 'min'=>100,  'color'=>'#60a5fa'],
    'senior' => ['label'=>'Senior',  'emoji'=>'🔥', 'min'=>500,  'color'=>'#f59e0b'],
    'expert' => ['label'=>'Expert',  'emoji'=>'💎', 'min'=>1500, 'color'=>'#a78bfa'],
    'legend' => ['label'=>'Legend',  'emoji'=>'🏆', 'min'=>5000, 'color'=>'#ff6600'],
]);

if (!defined('G7_BADGES')) define('G7_BADGES', [
    'first_upload'   => ['label'=>'Primer contenido',   'emoji'=>'🚀', 'desc'=>'Subiste tu primer contenido'],
    'productive_50'  => ['label'=>'Productivo',          'emoji'=>'📦', 'desc'=>'50 assets entregados'],
    'centenario'     => ['label'=>'Centenario',          'emoji'=>'💯', 'desc'=>'100 assets entregados'],
    'sin_fallas'     => ['label'=>'Sin fallas',          'emoji'=>'✅', 'desc'=>'10 aprobaciones directas'],
    'veloz'          => ['label'=>'Veloz',               'emoji'=>'⚡', 'desc'=>'20 entregas en SLA'],
    'en_racha'       => ['label'=>'En racha',            'emoji'=>'🔥', 'desc'=>'7 días seguidos activo'],
    'team_player'    => ['label'=>'Team Player',         'emoji'=>'👥', 'desc'=>'Miembro de 3+ equipos'],
    'top_mes'        => ['label'=>'Top del mes',         'emoji'=>'🏆', 'desc'=>'1° en ranking mensual'],
    'mil_assets'     => ['label'=>'Mil assets',          'emoji'=>'🎯', 'desc'=>'1000 assets en total'],
    'veterano'       => ['label'=>'Veterano',            'emoji'=>'🎖',  'desc'=>'6 meses activo en la plataforma'],
]);

// ── XP AWARD ─────────────────────────────────────────
if (!function_exists('g7AwardXP')) {

function g7AwardXP(PDO $db, int $userId, int $xp, string $reason, ?int $refId = null): void {
    if ($xp === 0) return;

    // Ensure user_xp row exists
    $db->prepare("INSERT IGNORE INTO g7_user_xp (user_id, xp_total, nivel) VALUES (?,0,'rookie')")
       ->execute([$userId]);

    // Get current XP and level
    $cur = $db->prepare("SELECT xp_total, nivel FROM g7_user_xp WHERE user_id=?");
    $cur->execute([$userId]);
    $row = $cur->fetch();
    $oldXP    = (int)($row['xp_total'] ?? 0);
    $oldNivel = $row['nivel'] ?? 'rookie';

    // Add XP (min 0)
    $newXP = max(0, $oldXP + $xp);
    $newNivel = g7CalcLevel($newXP);

    // Update
    $db->prepare("UPDATE g7_user_xp SET xp_total=?, nivel=? WHERE user_id=?")
       ->execute([$newXP, $newNivel, $userId]);

    // Log event
    $db->prepare("INSERT INTO g7_xp_log (user_id, xp, reason, ref_id) VALUES (?,?,?,?)")
       ->execute([$userId, $xp, $reason, $refId]);

    // Level up notification
    if ($newNivel !== $oldNivel) {
        $levelData = G7_LEVELS[$newNivel];
        $db->prepare("INSERT INTO g7_gamif_notif (user_id, type, data) VALUES (?,?,?)")
           ->execute([$userId, 'level_up', json_encode([
               'old_level' => $oldNivel,
               'new_level' => $newNivel,
               'label'     => $levelData['label'],
               'emoji'     => $levelData['emoji'],
           ])]);
    }

    // Check badges
    g7CheckBadges($db, $userId, $refId);
}

// ── CALCULATE LEVEL ───────────────────────────────────
function g7CalcLevel(int $xp): string {
    $level = 'rookie';
    foreach (G7_LEVELS as $key => $data) {
        if ($xp >= $data['min']) $level = $key;
    }
    return $level;
}

// ── CHECK BADGES ──────────────────────────────────────
function g7CheckBadges(PDO $db, int $userId, ?int $refId = null): void {
    // Get already unlocked badges
    $unlocked = $db->prepare("SELECT badge_key FROM g7_user_badges WHERE user_id=?");
    $unlocked->execute([$userId]);
    $have = $unlocked->fetchAll(PDO::FETCH_COLUMN);

    $stats = g7GetUserStats($db, $userId);

    $toCheck = [
        'first_upload'  => $stats['total_contenidos'] >= 1,
        'productive_50' => $stats['total_assets'] >= 50,
        'centenario'    => $stats['total_assets'] >= 100,
        'mil_assets'    => $stats['total_assets'] >= 1000,
        'sin_fallas'    => $stats['aprobaciones_directas'] >= 10,
        'veloz'         => $stats['entregas_en_sla'] >= 20,
        'en_racha'      => $stats['racha_actual'] >= 7,
        'team_player'   => $stats['equipos'] >= 3,
    ];

    foreach ($toCheck as $badgeKey => $earned) {
        if ($earned && !in_array($badgeKey, $have)) {
            g7UnlockBadge($db, $userId, $badgeKey);
        }
    }
}

function g7UnlockBadge(PDO $db, int $userId, string $badgeKey): void {
    try {
        $db->prepare("INSERT IGNORE INTO g7_user_badges (user_id, badge_key) VALUES (?,?)")
           ->execute([$userId, $badgeKey]);
        $badge = G7_BADGES[$badgeKey] ?? ['label'=>$badgeKey,'emoji'=>'🏅'];
        $db->prepare("INSERT INTO g7_gamif_notif (user_id, type, data) VALUES (?,?,?)")
           ->execute([$userId, 'badge', json_encode([
               'badge_key' => $badgeKey,
               'label'     => $badge['label'],
               'emoji'     => $badge['emoji'],
               'desc'      => $badge['desc'] ?? '',
           ])]);
    } catch(Exception $e) {}
}

// ── GET USER STATS ────────────────────────────────────
function g7GetUserStats(PDO $db, int $userId): array {
    // Content stats
    $q = $db->prepare("
        SELECT
            COUNT(*) as total_contenidos,
            SUM(qty_graficas + qty_videos + qty_tareas) as total_assets,
            SUM(CASE WHEN parent_id IS NULL AND qty_revisiones=0 AND qty_rechazos=0 THEN 1 ELSE 0 END) as aprobaciones_directas,
            SUM(CASE WHEN tiempo_entrega_dias IS NOT NULL AND tiempo_entrega_dias<=3 THEN 1 ELSE 0 END) as entregas_en_sla
        FROM g7_content
        WHERE created_by=? AND estado='activo'
    ");
    $q->execute([$userId]);
    $stats = $q->fetch() ?: [];

    // Activity streak
    $streak = 0;
    $checkDay = new DateTime();
    $actQ = $db->prepare("SELECT 1 FROM g7_content WHERE created_by=? AND DATE(created_at)=? AND estado='activo' LIMIT 1");
    for ($i=0; $i<365; $i++) {
        $actQ->execute([$userId, $checkDay->format('Y-m-d')]);
        if ($actQ->fetchColumn()) { $streak++; $checkDay->modify('-1 day'); }
        else break;
    }
    $stats['racha_actual'] = $streak;

    // Teams count
    try {
        $tq = $db->prepare("SELECT COUNT(*) FROM g7_team_members WHERE user_id=?");
        $tq->execute([$userId]);
        $stats['equipos'] = (int)$tq->fetchColumn();
    } catch(Exception $e) { $stats['equipos'] = 0; }

    return $stats;
}

// ── PROCESS CONTENT UPLOAD (call this when saving content) ──
function g7ProcessContentUpload(PDO $db, int $userId, array $item): void {
    $xp = 10; // base XP for upload
    $reasons = [];

    // XP per asset
    $graficas = (int)($item['qty_graficas'] ?? 0);
    $videos   = (int)($item['qty_videos']   ?? 0);
    $tareas   = (int)($item['qty_tareas']   ?? 0);
    $xp += $graficas * 3 + $videos * 5 + $tareas * 2;

    // Bonus: direct approval (no revisions on parent content)
    if (empty($item['parent_id']) && empty($item['edit_type'])) {
        $xp += 5; // bonus for new content (approval tracked separately)
    }

    // Bonus: SLA compliance
    if (!empty($item['tiempo_entrega_dias']) && (int)$item['tiempo_entrega_dias'] <= 3) {
        $xp += 8;
    }

    // Penalty: rejection
    if (($item['edit_type'] ?? '') === 'rechazo') {
        $xp -= 5;
    }

    g7AwardXP($db, $userId, $xp, 'contenido_subido', $item['id'] ?? null);
}

// ── GET PENDING NOTIFICATIONS ─────────────────────────
function g7GetPendingNotifs(PDO $db, int $userId): array {
    $q = $db->prepare("SELECT * FROM g7_gamif_notif WHERE user_id=? AND seen=0 ORDER BY created_at DESC LIMIT 5");
    $q->execute([$userId]);
    return $q->fetchAll();
}

function g7MarkNotifsSeen(PDO $db, int $userId): void {
    $db->prepare("UPDATE g7_gamif_notif SET seen=1 WHERE user_id=? AND seen=0")->execute([$userId]);
}

// ── GET USER GAMIFICATION DATA ────────────────────────
function g7GetUserGamif(PDO $db, int $userId): array {
    $xpRow = $db->prepare("SELECT * FROM g7_user_xp WHERE user_id=?");
    $xpRow->execute([$userId]);
    $xp = $xpRow->fetch() ?: ['xp_total'=>0,'nivel'=>'rookie'];

    $badgeQ = $db->prepare("SELECT badge_key, unlocked_at FROM g7_user_badges WHERE user_id=? ORDER BY unlocked_at ASC");
    $badgeQ->execute([$userId]);
    $badges = $badgeQ->fetchAll();

    $level = G7_LEVELS[$xp['nivel']] ?? G7_LEVELS['rookie'];
    $levels = array_values(G7_LEVELS);
    $nextLevel = null;
    $found = false;
    foreach (G7_LEVELS as $key => $data) {
        if ($found) { $nextLevel = $data; break; }
        if ($key === $xp['nivel']) $found = true;
    }

    return [
        'xp'          => (int)$xp['xp_total'],
        'nivel'       => $xp['nivel'],
        'level_data'  => $level,
        'next_level'  => $nextLevel,
        'badges'      => $badges,
        'progress_pct'=> $nextLevel ? min(100, round(((int)$xp['xp_total'] - $level['min']) / ($nextLevel['min'] - $level['min']) * 100)) : 100,
    ];
}

// ── RECALCULATE ALL XP (admin utility) ───────────────
function g7RecalcAllXP(PDO $db): array {
    $users = $db->query("SELECT id FROM g7_users WHERE active=1")->fetchAll(PDO::FETCH_COLUMN);
    $results = [];

    foreach ($users as $userId) {
        // Clear existing
        $db->prepare("DELETE FROM g7_xp_log WHERE user_id=?")->execute([$userId]);
        $db->prepare("DELETE FROM g7_user_badges WHERE user_id=?")->execute([$userId]);
        $db->prepare("UPDATE g7_user_xp SET xp_total=0, nivel='rookie' WHERE user_id=?")->execute([$userId]);

        // Reprocess all content
        $items = $db->prepare("SELECT * FROM g7_content WHERE created_by=? AND estado='activo' ORDER BY created_at ASC");
        $items->execute([$userId]);
        foreach ($items->fetchAll() as $item) {
            g7ProcessContentUpload($db, $userId, $item);
        }

        // Check top_mes badge
        $topMes = $db->prepare("
            SELECT created_by FROM g7_content
            WHERE YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW()) AND estado='activo'
            GROUP BY created_by ORDER BY COUNT(*) DESC LIMIT 1
        ");
        $topMes->execute();
        $topUser = $topMes->fetchColumn();
        if ($topUser == $userId) {
            g7UnlockBadge($db, $userId, 'top_mes');
        }

        $xpRow = $db->prepare("SELECT xp_total, nivel FROM g7_user_xp WHERE user_id=?");
        $xpRow->execute([$userId]);
        $row = $xpRow->fetch();
        $results[$userId] = $row;
    }
    return $results;
}

}