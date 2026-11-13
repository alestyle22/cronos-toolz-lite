<?php
/**
 * templates.php — Plantillas de Mailing Maker compartidas con el equipo.
 *   GET                       → lista (solo de las marcas que ve el usuario)
 *   POST {action:'save', name, plat, data}   → guarda una plantilla compartida
 *   POST {action:'delete', id}               → borra (autor o admin)
 * Las plantillas personales siguen en el navegador (localStorage), como antes.
 */
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json; charset=utf-8');
$user = getCurrentUser();
if (!$user) { http_response_code(401); echo json_encode(['error' => 'Iniciá sesión de nuevo']); exit; }
$uid = (int)$user['id'];
$db = getDB();
$db->exec("CREATE TABLE IF NOT EXISTS g7_mailing_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    plat VARCHAR(50) NOT NULL DEFAULT '',
    data MEDIUMTEXT NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_plat (plat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $db->query("SELECT t.id, t.name, t.plat, t.data, t.created_by, t.created_at, COALESCE(NULLIF(u.display_name,''), u.username) AS author
                        FROM g7_mailing_templates t LEFT JOIN g7_users u ON u.id = t.created_by ORDER BY t.id DESC LIMIT 200")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        if (!g7_brand_in_scope($uid, $r['plat'])) continue;
        $d = json_decode($r['data'], true);
        if (!is_array($d)) continue;
        $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'plat' => $r['plat'], 'author' => $r['author'] ?? '',
                  'date' => date('d/m/Y', strtotime($r['created_at'])), 'mine' => (int)$r['created_by'] === $uid, 'tpl' => $d];
    }
    echo json_encode(['templates' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$act = $in['action'] ?? '';

if ($act === 'save') {
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
    $plat = preg_replace('/[^a-z0-9_]/', '', (string)($in['plat'] ?? ''));
    $data = $in['data'] ?? null;
    if ($name === '' || !is_array($data)) { echo json_encode(['error' => 'Faltan datos']); exit; }
    if (!g7_brand_allows_text($uid, $plat)) { echo json_encode(['error' => 'No tenés acceso a esa marca']); exit; }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if (strlen($json) > 2_000_000) { echo json_encode(['error' => 'La plantilla es demasiado grande']); exit; }
    $db->prepare("INSERT INTO g7_mailing_templates (name, plat, data, created_by) VALUES (?,?,?,?)")->execute([$name, $plat, $json, $uid]);
    echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    exit;
}

if ($act === 'delete') {
    $id = (int)($in['id'] ?? 0);
    $q = $db->prepare("SELECT created_by FROM g7_mailing_templates WHERE id=?"); $q->execute([$id]);
    $owner = $q->fetchColumn();
    if ($owner === false) { echo json_encode(['error' => 'No existe']); exit; }
    if ((int)$owner !== $uid && ($user['role'] ?? '') !== 'admin') { echo json_encode(['error' => 'Solo quien la creó o un admin puede borrarla']); exit; }
    $db->prepare("DELETE FROM g7_mailing_templates WHERE id=?")->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['error' => 'Acción desconocida']);
