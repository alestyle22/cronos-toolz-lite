<?php
// /toolz/mailingmaker/log_mailing.php
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json');

$user = getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$body     = json_decode(file_get_contents('php://input'), true);
$platform = trim($body['platform'] ?? '');
$h1       = trim($body['h1']       ?? '');
$html     = $body['html']          ?? '';
$name     = trim($body['name']     ?? '');

if (!$platform) {
    http_response_code(400);
    echo json_encode(['error' => 'Falta platform']);
    exit;
}

if (!g7_brand_allows_text((int)$user['id'], $platform)) {
    http_response_code(403);
    echo json_encode(['error' => 'No tenés acceso a esa marca']);
    exit;
}

$platform = substr($platform, 0, 40);
$h1       = substr($h1,       0, 255);
$name     = $name ?: ($platform . ($h1 ? ' — ' . $h1 : ''));
$name     = substr($name, 0, 255);

$mailingUrl = '';

try {
    $db = getDB();

    // Log en tabla de exports (backward compat)
    $db->prepare('INSERT INTO g7_mailing_exports (user_id, username, platform, h1, exported_at) VALUES (?, ?, ?, ?, NOW())')
       ->execute([$user['id'], $user['username'], $platform, $h1]);

    // Si viene HTML, guardar archivo y crear entrada en g7_content
    if ($html) {
        $mailingsDir = dirname(__DIR__) . '/promos/mailings';   // misma carpeta que sirve /toolz/promos/mailings/
        if (!is_dir($mailingsDir)) mkdir($mailingsDir, 0755, true);

        $slug   = preg_replace('/[^a-z0-9]+/', '_', strtolower($platform));
        $h1slug = preg_replace('/[^a-z0-9]+/', '_', strtolower(substr($h1, 0, 30)));
        $fname  = date('Ymd_His') . '_' . $user['username'] . '_' . $slug . ($h1slug ? '_' . $h1slug : '') . '.html';
        $fpath  = $mailingsDir . '/' . $fname;

        file_put_contents($fpath, $html);
        $mailingUrl = '/toolz/promos/mailings/' . $fname;

        $db->prepare('INSERT INTO g7_content
            (type, name, author, project, projects, category, media_type,
             mailing, qty_tareas, qty_mailings, estado, created_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, NOW(), ?)')
           ->execute([
               'mailings', $name, $user['username'], $platform, '[]',
               '', 'mailing', $mailingUrl, 'activo', $user['id']
           ]);
    }

    echo json_encode(['ok' => true, 'url' => $mailingUrl]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error: ' . $e->getMessage()]);
}
