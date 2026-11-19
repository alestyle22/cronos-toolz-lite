<?php
/**
 * Cronos Mobile — "pulso": lo nuevo desde la última consulta, para las notificaciones del teléfono cuando la app está
 * cerrada (WorkManager cada 15 minutos) o en segundo plano. Mientras no haya Firebase (v2), es la única vía de aviso.
 *
 * Tres cursores independientes (la app guarda el último de cada uno):
 *   since_msg     mensajes de chat nuevos para mí (DM, grupos y canales según las preferencias del chat)
 *   since_chnotif actividad del chat (menciones, respuestas, reacciones…): g7_ch_notifs
 *   since_notif   campana de la plataforma: g7_notifications (tickets, tareas, comunidad, calendario…)
 * Con un cursor en 0 solo devuelve el último id (la primera vez no aparecen avisos viejos).
 *
 * Respeta lo mismo que la web: conversación silenciada, nivel de aviso por conversación (todo / menciones / nada) y las
 * preferencias del chat (dm, groups, channels). No notifica mensajes propios ni borrados.
 */
require_once dirname(__DIR__) . '/app_notif_lib.php';

function mb_pulse(PDO $db, int $uid, int $sinceMsg, int $sinceNotif, int $sinceChNotif): array {
    // Los mismos recordatorios que corre la campana de la web: así llegan aunque nadie tenga el sitio abierto
    $root = dirname(__DIR__);
    if (is_file("$root/tareas_lib.php")) { try { require_once "$root/tareas_lib.php"; tk_run_reminders($db); } catch (Throwable $e) {} }
    if (is_file("$root/calendario/lib.php") && g7_can($uid, 'calendario')) { try { require_once "$root/calendario/lib.php"; cal_run_reminders($db, $uid); } catch (Throwable $e) {} }
    if (is_file("$root/bienestar/lib.php") && g7_can($uid, 'bienestar')) { try { require_once "$root/bienestar/lib.php"; bn_run_user($db, $uid); } catch (Throwable $e) {} }

    $out = ['messages' => [], 'chat_activity' => [], 'notifications' => []];
    $names = [];
    $name = function (int $id) use ($db, &$names): array {
        if (!isset($names[$id])) {
            $q = $db->prepare('SELECT display_name, username, avatar FROM g7_users WHERE id = ?'); $q->execute([$id]);
            $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
            $names[$id] = ['name' => (string)(($r['display_name'] ?? '') ?: ($r['username'] ?? 'Alguien')), 'avatar' => mb_url($r['avatar'] ?? '')];
        }
        return $names[$id];
    };

    // ── Chat: mensajes ──
    if (g7_can($uid, 'chat')) {
        try {
            $max = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM g7_ch_messages')->fetchColumn();
            $out['last_msg'] = $max;
            if ($sinceMsg > 0) {
                $prefs = ['dm' => true, 'groups' => true, 'channels' => false];
                $p = $db->prepare('SELECT data FROM g7_ch_prefs WHERE user_id = ?'); $p->execute([$uid]);
                $d = json_decode((string)$p->fetchColumn(), true); if (is_array($d)) $prefs = array_merge($prefs, array_intersect_key($d, $prefs));
                $me = $db->prepare('SELECT username FROM g7_users WHERE id = ?'); $me->execute([$uid]); $myName = (string)$me->fetchColumn();
                $q = $db->prepare("SELECT x.id, x.conv_id, x.user_id, x.body, x.kind, x.parent_id, x.created_at, c.kind AS ckind, c.name AS cname, m.notify,
                        EXISTS (SELECT 1 FROM g7_ch_mentions mn WHERE mn.message_id = x.id AND mn.user_id = ?) AS mentioned,
                        (SELECT COUNT(*) FROM g7_ch_files f WHERE f.message_id = x.id) AS nfiles
                    FROM g7_ch_messages x JOIN g7_ch_members m ON m.conv_id = x.conv_id AND m.user_id = ? JOIN g7_ch_convs c ON c.id = x.conv_id AND c.archived = 0
                    WHERE x.id > ? AND x.user_id <> ? AND x.deleted_at IS NULL AND x.kind IN ('text','poll','announce','reminder') AND m.muted = 0 AND x.id > m.last_read_id
                    ORDER BY x.id ASC LIMIT 60");
                $q->execute([$uid, $uid, $sinceMsg, $uid]);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $mentioned = (bool)(int)$r['mentioned'] || ($myName !== '' && stripos((string)$r['body'], '@' . $myName) !== false);
                    $level = (string)($r['notify'] ?: 'all');
                    if ($level === 'none') continue;
                    $kindPref = ['dm' => 'dm', 'group' => 'groups', 'channel' => 'channels'][$r['ckind']] ?? 'groups';
                    // En canales, por defecto solo las menciones; nivel "menciones" en cualquier conversación, igual
                    if (!$mentioned && ($level === 'mentions' || empty($prefs[$kindPref]))) continue;
                    if ($r['parent_id'] !== null && !$mentioned) continue;   // respuestas en hilos: solo si me nombran (el resto va por actividad)
                    $who = $name((int)$r['user_id']);
                    $body = preg_replace('/\[\[(\w+):([\w-]+)\]\]/', '[$1 $2]', (string)$r['body']);
                    $body = trim(preg_replace('/\s+/', ' ', $body));
                    if ($body === '' && (int)$r['nfiles'] > 0) $body = (int)$r['nfiles'] === 1 ? '📎 Archivo' : '📎 ' . (int)$r['nfiles'] . ' archivos';
                    if ($r['kind'] === 'poll') $body = '📊 Encuesta';
                    $out['messages'][] = ['id' => (int)$r['id'], 'conv_id' => (int)$r['conv_id'], 'conv_kind' => $r['ckind'],
                        'conv_name' => $r['ckind'] === 'dm' ? $who['name'] : (string)$r['cname'],
                        'user_id' => (int)$r['user_id'], 'user' => $who['name'], 'avatar' => $who['avatar'],
                        'body' => mb_substr($body, 0, 300), 'mention' => $mentioned, 'thread' => $r['parent_id'] !== null, 'created_at' => $r['created_at']];
                }
            }
        } catch (Throwable $e) { $out['last_msg'] = $sinceMsg; }

        // ── Chat: actividad (menciones, respuestas, reacciones, invitaciones, fijados, recordatorios) ──
        try {
            $out['last_chnotif'] = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM g7_ch_notifs')->fetchColumn();
            if ($sinceChNotif > 0) {
                $q = $db->prepare('SELECT n.*, c.name AS cname, c.kind AS ckind FROM g7_ch_notifs n LEFT JOIN g7_ch_convs c ON c.id = n.conv_id
                    WHERE n.user_id = ? AND n.id > ? AND n.read_at IS NULL ORDER BY n.id ASC LIMIT 30');
                $q->execute([$uid, $sinceChNotif]);
                $label = ['mention' => 'te mencionó', 'reply' => 'respondió en un hilo que seguís', 'reaction' => 'reaccionó a tu mensaje', 'invite' => 'te sumó a una conversación',
                          'pin' => 'fijó tu mensaje', 'announce' => 'publicó un anuncio', 'reminder' => 'Recordatorio', 'sched_fail' => 'No se pudo enviar un mensaje programado'];
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $n) {
                    // Las menciones ya llegan como mensaje (arriba): no se repiten
                    if ($n['kind'] === 'mention') continue;
                    $who = $n['actor_id'] ? $name((int)$n['actor_id']) : ['name' => '', 'avatar' => null];
                    $l = $label[$n['kind']] ?? 'Actividad en el chat';
                    $title = in_array($n['kind'], ['reminder', 'sched_fail'], true) ? $l : trim($who['name'] . ' ' . $l);
                    $out['chat_activity'][] = ['id' => (int)$n['id'], 'kind' => $n['kind'], 'conv_id' => (int)$n['conv_id'], 'message_id' => (int)$n['message_id'],
                        'title' => $title, 'body' => mb_substr((string)$n['extra'], 0, 240), 'conv_name' => (string)$n['cname'], 'created_at' => $n['created_at']];
                }
            }
        } catch (Throwable $e) { $out['last_chnotif'] = $sinceChNotif; }
    }

    // ── Campana de la plataforma ──
    $q = $db->prepare('SELECT COALESCE(MAX(id),0) m, SUM(read_at IS NULL) u FROM g7_notifications WHERE user_id = ?');
    $q->execute([$uid]); $agg = $q->fetch(PDO::FETCH_ASSOC);
    $out['last_notif'] = (int)$agg['m']; $out['unread_notif'] = (int)$agg['u'];
    if ($sinceNotif > 0) {
        $s = $db->prepare('SELECT n.id, n.type, n.from_id, n.target_id, n.profile_id, n.body, n.created_at, u.username, u.display_name
            FROM g7_notifications n LEFT JOIN g7_users u ON u.id = n.from_id
            WHERE n.user_id = ? AND n.id > ? AND n.read_at IS NULL ORDER BY n.id ASC LIMIT 20');
        $s->execute([$uid, $sinceNotif]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $n) $out['notifications'][] = ['id' => (int)$n['id'], 'type' => (string)$n['type'], 'title' => g7_app_title($n),
            'body' => mb_substr((string)$n['body'], 0, 240), 'target_id' => (int)$n['target_id'], 'profile_id' => (int)$n['profile_id'],
            'web' => g7_app_target($n), 'created_at' => (string)$n['created_at']];
    }
    return $out;
}
