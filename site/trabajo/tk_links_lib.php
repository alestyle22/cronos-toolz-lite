<?php
/**
 * trabajo/tk_links_lib.php — Clientes y contenidos vinculados a un ticket (2026-11-11). Tarjeta "Vinculados" del detalle.
 * Cada vínculo se muestra con chat/refs_lib.php: el nombre solo aparece si quien mira puede ver ese cliente o contenido.
 * Doc: toolz/CHAT_VINCULOS.md › "Tickets".
 */
require_once dirname(__DIR__) . '/chat/refs_lib.php';

const WK_TK_LINK_KINDS = ['cliente', 'contenido'];

function wk_tk_links_install(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_ticket_links (
        ticket_id INT NOT NULL, kind VARCHAR(12) NOT NULL, ref_id INT NOT NULL, added_by INT NULL, added_at DATETIME NOT NULL,
        PRIMARY KEY (ticket_id, kind, ref_id), KEY ref (kind, ref_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Vínculos del ticket, ya resueltos para quien mira (los que no puede ver vuelven como "sin acceso"). */
function wk_tk_links(PDO $db, int $ticketId, int $uid): array {
    wk_tk_links_install($db);
    $q = $db->prepare('SELECT kind, ref_id FROM g7_wk_ticket_links WHERE ticket_id = ? ORDER BY added_at, kind, ref_id'); $q->execute([$ticketId]);
    $refs = array_map(fn($r) => $r['kind'] . ':' . (int)$r['ref_id'], $q->fetchAll(PDO::FETCH_ASSOC));
    if (!$refs) return [];
    $res = ch_refs_resolve($db, $uid, $refs);
    return array_values(array_map(fn($k) => $res[$k], array_filter($refs, fn($k) => isset($res[$k]))));
}

/** Agrega un vínculo. Solo se puede vincular lo que quien vincula puede ver (y que exista). */
function wk_tk_link_add(PDO $db, int $ticketId, int $uid, string $kind, int $refId): void {
    if (!in_array($kind, WK_TK_LINK_KINDS, true)) throw new RuntimeException('Solo se pueden vincular clientes o contenidos.');
    $r = ch_refs_resolve($db, $uid, ["$kind:$refId"])["$kind:$refId"] ?? null;
    if (!$r || !$r['ok']) throw new RuntimeException('Ese ' . mb_strtolower(CH_REF_KINDS[$kind]['label']) . ' no existe o no tienes acceso.');
    wk_tk_links_install($db);
    $n = $db->prepare('SELECT COUNT(*) FROM g7_wk_ticket_links WHERE ticket_id = ?'); $n->execute([$ticketId]);
    if ((int)$n->fetchColumn() >= 30) throw new RuntimeException('Este ticket ya tiene 30 vínculos.');
    $db->prepare('INSERT IGNORE INTO g7_wk_ticket_links (ticket_id, kind, ref_id, added_by, added_at) VALUES (?, ?, ?, ?, ?)')->execute([$ticketId, $kind, $refId, $uid, date('Y-m-d H:i:s')]);
}

function wk_tk_link_remove(PDO $db, int $ticketId, string $kind, int $refId): void {
    wk_tk_links_install($db);
    $db->prepare('DELETE FROM g7_wk_ticket_links WHERE ticket_id = ? AND kind = ? AND ref_id = ?')->execute([$ticketId, $kind, $refId]);
}
