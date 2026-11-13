<?php
/**
 * ping.php — Zumbidos de la barra de arriba (assets/topbar.js): enviar y recibir.
 * 2026-11-11: crea la tabla si el sitio no la tiene (sitios instalados antes de que existiera), valida destinatarios
 * y siempre responde JSON, también cuando algo falla, para que el botón no quede trabado en "Enviando…".
 */
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$user = getCurrentUser();
if (!$user) { http_response_code(401); echo json_encode(['status' => 'error', 'error' => 'Tu sesión venció: vuelve a entrar.']); exit; }
$db    = getDB();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$act   = $input['action'] ?? '';
$fail  = function (string $m, int $code = 400) { http_response_code($code); echo json_encode(['status' => 'error', 'error' => $m], JSON_UNESCAPED_UNICODE); exit; };

/** La tabla venía solo en el instalador: en sitios viejos puede faltar y entonces el zumbido no anda. */
function g7_pings_install(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS g7_pings (
        id INT NOT NULL AUTO_INCREMENT, from_id INT NOT NULL, to_id INT NOT NULL, message VARCHAR(200) DEFAULT '',
        read_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, beeps INT NOT NULL DEFAULT 3,
        PRIMARY KEY (id), KEY from_id (from_id), KEY idx_pings_to (to_id, read_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try { $db->exec('ALTER TABLE g7_pings ADD COLUMN IF NOT EXISTS beeps INT NOT NULL DEFAULT 3'); } catch (Throwable $e) {}
}

try {
    g7_pings_install($db);

    // ── Enviar ──
    if ($act === 'send') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($input['to_ids'] ?? [])), fn($i) => $i > 0 && $i !== (int)$user['id'])));
        if (!$ids) $fail('Elige a quién mandarle el zumbido.');
        $ids = array_slice($ids, 0, 50);
        // Solo a personas activas del sitio
        $ok = $db->query('SELECT id FROM g7_users WHERE active = 1 AND id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_COLUMN);
        if (!$ok) $fail('Esas personas ya no están activas.');
        $message = mb_substr(trim((string)($input['message'] ?? '')), 0, 200);
        $beeps = min(30, max(1, (int)($input['beeps'] ?? 3)));
        $ins = $db->prepare('INSERT INTO g7_pings (from_id, to_id, message, beeps, created_at) VALUES (?, ?, ?, ?, NOW())');
        foreach ($ok as $toId) $ins->execute([(int)$user['id'], (int)$toId, $message, $beeps]);
        echo json_encode(['status' => 'success', 'sent' => count($ok)]); exit;
    }

    // ── Revisar si me llegaron (cada 10 s) ──
    if ($act === 'check') {
        $rows = $db->prepare('SELECT p.id, p.from_id, p.message, p.beeps, p.created_at, u.username, u.display_name, u.avatar
            FROM g7_pings p JOIN g7_users u ON u.id = p.from_id
            WHERE p.to_id = ? AND p.read_at IS NULL AND p.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            ORDER BY p.created_at DESC LIMIT 20');
        $rows->execute([(int)$user['id']]);
        $pings = $rows->fetchAll(PDO::FETCH_ASSOC);
        $db->prepare('UPDATE g7_pings SET read_at = NOW() WHERE to_id = ? AND read_at IS NULL')->execute([(int)$user['id']]);
        echo json_encode(['pings' => $pings], JSON_UNESCAPED_UNICODE); exit;
    }
} catch (Throwable $e) {
    error_log('[ping] ' . $e->getMessage());
    $fail('No se pudo enviar el zumbido. Prueba de nuevo en un momento.', 500);
}

$fail('Acción desconocida.');
