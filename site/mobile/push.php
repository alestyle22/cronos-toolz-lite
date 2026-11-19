<?php
/**
 * Cronos Mobile — push (FCM) desde la agencia.
 *
 * La agencia NO tiene la credencial de Firebase: le pide al sitio base (push_api.php, firmado con la clave de este sitio, como
 * Soporte) que despierte a los teléfonos. El aviso no lleva contenido ({"t":"pulse"}): la app consulta mobile/api.php?action=pulse
 * con su sesión y arma las notificaciones (respeta silenciados, menciones y lo ya leído).
 *
 * Barrido (mb_push_sweep): cada vez que alguien consulta el chat (chat/api.php sync) o la campana (notif.php get), como mucho
 * cada 5 segundos, se buscan mensajes de chat y avisos de la campana nuevos desde el último barrido y se despierta a sus
 * destinatarios que tengan la app. Corre después de responder (no demora a quien consultó). Así no hubo que tocar los 13
 * lugares que crean avisos de la campana.
 */

const MB_PUSH_EVERY = 5;   // segundos mínimos entre barridos

/** Programa el barrido para después de responder. */
function mb_push_later(): void {
    static $done = false; if ($done) return; $done = true;
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        try { mb_push_sweep(getDB()); } catch (Throwable $e) { error_log('mb_push: ' . $e->getMessage()); }
    });
}

function mb_push_sweep(PDO $db): void {
    require_once __DIR__ . '/lib.php';
    mb_install($db);
    // Sin teléfonos con push registrados no hay nada que hacer (una consulta chica)
    if (!$db->query("SELECT 1 FROM g7_mobile_devices WHERE push_token <> '' AND revoked_at IS NULL LIMIT 1")->fetchColumn()) return;
    if ((int)g7_cfg('mb_push_at', 0) > time() - MB_PUSH_EVERY) return;
    if (!(int)$db->query("SELECT GET_LOCK('mb_push', 0)")->fetchColumn()) return;
    try {
        g7_cfg_set('mb_push_at', (string)time());
        $users = [];
        // Chat: miembros de la conversación que no la silenciaron y que no sean quien escribió
        $maxMsg = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM g7_ch_messages')->fetchColumn();
        $curMsg = (int)g7_cfg('mb_push_msg', 0);
        if ($curMsg > 0 && $maxMsg > $curMsg) {
            $q = $db->prepare("SELECT DISTINCT m.user_id FROM g7_ch_messages x JOIN g7_ch_members m ON m.conv_id = x.conv_id
                WHERE x.id > ? AND x.id <= ? AND x.deleted_at IS NULL AND x.kind IN ('text','poll','announce','reminder') AND m.user_id <> x.user_id AND m.muted = 0 AND m.notify <> 'none'");
            $q->execute([$curMsg, $maxMsg]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $u) $users[(int)$u] = 1;
        }
        if ($maxMsg !== $curMsg) g7_cfg_set('mb_push_msg', (string)$maxMsg);
        // Campana
        $maxN = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM g7_notifications')->fetchColumn();
        $curN = (int)g7_cfg('mb_push_notif', 0);
        if ($curN > 0 && $maxN > $curN) {
            $q = $db->prepare('SELECT DISTINCT user_id FROM g7_notifications WHERE id > ? AND id <= ? AND read_at IS NULL');
            $q->execute([$curN, $maxN]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $u) $users[(int)$u] = 1;
        }
        if ($maxN !== $curN) g7_cfg_set('mb_push_notif', (string)$maxN);
        // Actividad del chat (respuestas en hilos, reacciones…)
        try {
            $maxA = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM g7_ch_notifs')->fetchColumn();
            $curA = (int)g7_cfg('mb_push_chn', 0);
            if ($curA > 0 && $maxA > $curA) {
                $q = $db->prepare('SELECT DISTINCT user_id FROM g7_ch_notifs WHERE id > ? AND id <= ?');
                $q->execute([$curA, $maxA]);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $u) $users[(int)$u] = 1;
            }
            if ($maxA !== $curA) g7_cfg_set('mb_push_chn', (string)$maxA);
        } catch (Throwable $e) {}
        if ($users) mb_push_users($db, array_keys($users));
    } finally { $db->query("SELECT RELEASE_LOCK('mb_push')"); }
}

/** Despierta los teléfonos (con la app y push registrado) de estas personas. Devuelve cuántos tokens se mandaron. */
function mb_push_users(PDO $db, array $uids): int {
    $uids = array_values(array_filter(array_map('intval', $uids)));
    if (!$uids) return 0;
    $in = implode(',', $uids);
    $rows = $db->query("SELECT id, push_token FROM g7_mobile_devices WHERE user_id IN ($in) AND push_token <> '' AND revoked_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;
    require_once dirname(__DIR__) . '/_update/lib.php';
    $c = u_cfg();
    if (empty($c['central_url']) || empty($c['secret']) || empty($c['id'])) return 0;   // sitio sin conectar a la red: queda el respaldo de 15 minutos
    $tokens = array_values(array_unique(array_column($rows, 'push_token')));
    try {
        [$code, $body] = u_post(rtrim($c['central_url'], '/') . '/push_api.php', $c['id'], $c['secret'], ['action' => 'send', 'tokens' => array_slice($tokens, 0, 500)], 20);
    } catch (Throwable $e) { error_log('mb_push: ' . $e->getMessage()); return 0; }
    $r = json_decode((string)$body, true) ?: [];
    // Tokens que Firebase ya no reconoce (app desinstalada): se borran para no insistir
    $bad = array_values(array_filter((array)($r['invalid'] ?? []), 'is_string'));
    if ($bad) {
        $q = $db->prepare("UPDATE g7_mobile_devices SET push_token = '' WHERE push_token = ?");
        foreach ($bad as $t) $q->execute([$t]);
    }
    return (int)($r['sent'] ?? 0);
}
