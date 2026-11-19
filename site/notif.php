<?php
require_once __DIR__ . '/auth.php';
$user = getCurrentUser();
if (!$user) { http_response_code(403); exit; }
$db    = getDB();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$act   = $input['action'] ?? ($_GET['action'] ?? '');

// ── GET UNREAD COUNT + LATEST ──
if ($act === 'get') {
    // Push a la app de Android: barrido de novedades después de responder (mobile/push.php)
    if (is_file(__DIR__ . '/mobile/push.php')) { require_once __DIR__ . '/mobile/push.php'; mb_push_later(); }
    // Recordatorios de vencimiento de Tareas (corren como mucho cada 10 minutos)
    if (is_file(__DIR__ . '/tareas_lib.php')) { require_once __DIR__ . '/tareas_lib.php'; tk_run_reminders($db); }
    // Avisos del calendario personal: solo los de esta persona, en cada consulta (es una consulta chica)
    if (is_file(__DIR__ . '/calendario/lib.php') && (g7_modules()['calendario']['mode'] ?? 'off') !== 'off') { require_once __DIR__ . '/calendario/lib.php'; cal_run_reminders($db, (int)$user['id']); }
    // Bienestar: recordatorio de hábitos de esta persona y, como mucho cada 10 minutos, avisos de retos y encuestas (bienestar/lib.php)
    if (is_file(__DIR__ . '/bienestar/lib.php') && g7_can((int)$user['id'], 'bienestar')) { try { require_once __DIR__ . '/bienestar/lib.php'; bn_run_user($db, (int)$user['id']); } catch (Throwable $e) {} }
    $stmt = $db->prepare("
        SELECT n.*, u.username, u.display_name, u.avatar
        FROM g7_notifications n
        LEFT JOIN g7_users u ON u.id = n.from_id
        WHERE n.user_id = ?
        ORDER BY n.created_at DESC LIMIT 20
    ");
    $stmt->execute([$user['id']]);
    $notifs = $stmt->fetchAll();
    $unread = count(array_filter($notifs, fn($n) => !$n['read_at']));
    echo json_encode(['notifs' => $notifs, 'unread' => $unread]);
    exit;
}

// ── MARK ALL READ ──
if ($act === 'read_all') {
    $db->prepare("UPDATE g7_notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL")->execute([$user['id']]);
    echo json_encode(['status' => 'success']);
    exit;
}

// ── MARK ONE READ ──
if ($act === 'read_one') {
    $db->prepare("UPDATE g7_notifications SET read_at=NOW() WHERE id=? AND user_id=?")->execute([$input['id'], $user['id']]);
    echo json_encode(['status' => 'success']);
    exit;
}

echo json_encode(['error' => 'unknown action']);
