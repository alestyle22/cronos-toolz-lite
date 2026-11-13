<?php
/**
 * trabajo/tk_collab_lib.php — Colaboradores y menciones en los tickets (2026-11-12). Doc: toolz/TICKETS_COLABORADORES.md
 *
 * Colaboradores: personas sumadas a un ticket además de quien lo pidió y quien lo atiende. Lo ven, responden y reciben los avisos.
 *   No ven las notas internas salvo que ya atiendan el ticket (su departamento, responsable o gente de su tarea).
 *   Los suman o quitan quien lo atiende y quien lo pidió; cada colaborador puede salirse.
 * Menciones: en una respuesta, @usuario avisa a esa persona (si no tenía acceso, queda como colaboradora). En una nota interna solo
 *   se avisa a quien puede verla. Los contenidos, clientes, tareas y tickets se vinculan con [[tipo:id]] (chat/refs_lib.php).
 */

function wk_tkc_install(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    if (wk_cfg_get($db, 'work_tkc_schema', '0') === '1') return;
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_ticket_collabs (
        ticket_id INT NOT NULL, user_id INT NOT NULL, added_by INT NULL, added_at DATETIME NOT NULL,
        PRIMARY KEY (ticket_id, user_id), KEY user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    wk_cfg_put($db, 'work_tkc_schema', '1');
}

/** Ids de los colaboradores de un ticket. */
function wk_tkc_ids(PDO $db, int $ticketId): array {
    wk_tkc_install($db);
    $q = $db->prepare('SELECT user_id FROM g7_wk_ticket_collabs WHERE ticket_id = ? ORDER BY added_at'); $q->execute([$ticketId]);
    return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}

function wk_tkc_is(PDO $db, int $ticketId, int $uid): bool {
    return in_array($uid, wk_tkc_ids($db, $ticketId), true);
}

/** ¿Atiende el ticket? (departamento, responsable o gente de su tarea; el mismo criterio que wk_ticket() de api.php) */
function wk_tkc_agent(PDO $db, array $t, int $uid): bool {
    $ctx = wk_ctx($db, $uid);
    if (wk_is_agent($ctx, $t['team_id'] ? (int)$t['team_id'] : null) || ($t['assignee_id'] && (int)$t['assignee_id'] === $uid)) return true;
    if (empty($t['task_id'])) return false;
    $q = $db->prepare('SELECT 1 FROM g7_task_assignees WHERE task_id = ? AND user_id = ?'); $q->execute([(int)$t['task_id'], $uid]);
    return (bool)$q->fetchColumn();
}

/** Suma un colaborador (activo). Devuelve false si ya estaba o no corresponde (quien lo pidió o el responsable). */
function wk_tkc_add(PDO $db, array $t, int $uid, int $by, bool $notify = true): bool {
    wk_tkc_install($db);
    if ($uid === (int)$t['requester_id'] || ($t['assignee_id'] && $uid === (int)$t['assignee_id'])) return false;
    $q = $db->prepare('SELECT COALESCE(NULLIF(display_name, \'\'), username) FROM g7_users WHERE id = ? AND active = 1'); $q->execute([$uid]);
    $name = $q->fetchColumn();
    if ($name === false) throw new RuntimeException('Esa persona no existe o está desactivada');
    $ins = $db->prepare('INSERT IGNORE INTO g7_wk_ticket_collabs (ticket_id, user_id, added_by, added_at) VALUES (?, ?, ?, ?)');
    $ins->execute([(int)$t['id'], $uid, $by, tk_now()]);
    if (!$ins->rowCount()) return false;
    wk_tk_log($db, (int)$t['id'], $by, $uid === $by ? 'se sumó como colaborador' : 'sumó a ' . $name . ' como colaborador', false);
    if ($notify && $uid !== $by) wk_tk_notify($db, 'tk_status', [$uid], $t, $by, 'Te sumaron como colaborador del ticket');
    return true;
}

function wk_tkc_remove(PDO $db, array $t, int $uid, int $by): bool {
    wk_tkc_install($db);
    $q = $db->prepare('DELETE FROM g7_wk_ticket_collabs WHERE ticket_id = ? AND user_id = ?'); $q->execute([(int)$t['id'], $uid]);
    if (!$q->rowCount()) return false;
    wk_tk_log($db, (int)$t['id'], $by, $uid === $by ? 'dejó de colaborar' : 'quitó a ' . wk_user_name($db, $uid) . ' de los colaboradores', false);
    return true;
}

/**
 * Menciones de un mensaje recién publicado: avisa a cada @usuario. En una respuesta pública, quien no tenía acceso queda como
 * colaborador. En una nota interna solo se avisa a quien la puede ver. Devuelve ['notified' => [ids], 'skipped' => [nombres]].
 */
function wk_tkc_mentions(PDO $db, array $t, int $by, string $body, bool $internal): array {
    $ids = array_values(array_diff(tk_mentions($db, $body), [$by]));
    $out = ['notified' => [], 'skipped' => []];
    if (!$ids) return $out;
    $collabs = wk_tkc_ids($db, (int)$t['id']);
    foreach ($ids as $u) {
        $isAgent = wk_tkc_agent($db, $t, $u);
        if ($internal && !$isAgent) { $out['skipped'][] = wk_user_name($db, $u); continue; }
        $involved = $isAgent || $u === (int)$t['requester_id'] || in_array($u, $collabs, true);
        if (!$involved) wk_tkc_add($db, $t, $u, $by, false);
        $out['notified'][] = $u;
    }
    if ($out['notified']) tk_notify($db, 'mention', $out['notified'], ['id' => (int)$t['id'], 'title' => wk_tk_code((int)$t['id']) . ' · ' . $t['subject']], $by,
        ($internal ? 'Nota interna: ' : '') . mb_substr($body, 0, 200), ['ticket' => (int)$t['id']]);
    return $out;
}
