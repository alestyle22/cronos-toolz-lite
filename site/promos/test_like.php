<?php
require_once __DIR__ . '/auth.php';
requireLogin();
$db = getDB();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
echo json_encode([
    'received' => $input,
    'action'   => $input['action'] ?? 'none',
    'tables'   => $db->query("SHOW TABLES LIKE 'g7_content%'")->fetchAll(PDO::FETCH_COLUMN),
]);
