<?php
require_once __DIR__ . '/auth.php';
$user = getCurrentUser();
if (!$user) { http_response_code(403); exit; }

$db    = getDB();
$input = json_decode(file_get_contents('php://input'), true);
$act   = $input['action'] ?? '';

// ── TRACK CLICK ──
if ($act === 'click') {
    $linkId   = (int)($input['link_id']   ?? 0);
    $linkName = trim($input['link_name']  ?? '');
    $linkUrl  = trim($input['link_url']   ?? '');
    if ($linkId) {
        $db->prepare('INSERT INTO g7_link_clicks (link_id, user_id) VALUES (?,?)')->execute([$linkId, $user['id']]);
        $db->prepare('UPDATE g7_users SET last_link_id=? WHERE id=?')->execute([$linkId, $user['id']]);
        // Log activity
        if ($linkName) {
            $db->prepare('INSERT INTO g7_activity (user_id,type,target_name,target_url) VALUES (?,?,?,?)')
               ->execute([$user['id'], 'link_open', $linkName, $linkUrl]);
        }
        echo json_encode(['status'=>'success']); exit;
    }
}

// ── HEARTBEAT ──
if ($act === 'heartbeat') {
    $now = date('Y-m-d H:i:s');
    $row = $db->prepare('SELECT session_start FROM g7_users WHERE id=?');
    $row->execute([$user['id']]); $u = $row->fetch();
    if ($u['session_start']) {
        $elapsed = time() - strtotime($u['session_start']);
        if ($elapsed < 120) {
            $db->prepare('UPDATE g7_users SET total_time_sec=total_time_sec+?,session_start=? WHERE id=?')
               ->execute([$elapsed, $now, $user['id']]);
        } else {
            $db->prepare('UPDATE g7_users SET session_start=? WHERE id=?')->execute([$now, $user['id']]);
        }
    } else {
        $db->prepare('UPDATE g7_users SET session_start=? WHERE id=?')->execute([$now, $user['id']]);
    }
    echo json_encode(['status'=>'ok']); exit;
}

// ── SESSION START ──
if ($act === 'session_start') {
    $now = date('Y-m-d H:i:s');
    $db->prepare('UPDATE g7_users SET session_start=? WHERE id=?')->execute([$now, $user['id']]);
    // Log login activity (only once per hour)
    $recent = $db->prepare("SELECT id FROM g7_activity WHERE user_id=? AND type='login' AND created_at>DATE_SUB(NOW(),INTERVAL 1 HOUR)");
    $recent->execute([$user['id']]);
    if (!$recent->fetch()) {
        $db->prepare('INSERT INTO g7_activity (user_id,type,target_name) VALUES (?,?,?)')->execute([$user['id'], 'login', '']);
    }
    echo json_encode(['status'=>'ok']); exit;
}

echo json_encode(['error'=>'unknown']);