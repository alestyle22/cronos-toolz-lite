<?php
error_reporting(0);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/gamification.php';
$currentUser = requireLogin();
$db = getDB();
header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (($input['action'] ?? '') === 'mark_seen') {
    g7MarkNotifsSeen($db, $currentUser['id']);
    echo json_encode(['ok' => true]);
}
