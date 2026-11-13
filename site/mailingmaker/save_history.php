<?php
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
$user = getCurrentUser();
if(!$user){ http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }
$db = getDB();

$db->exec("CREATE TABLE IF NOT EXISTS g7_mailing_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  user_name VARCHAR(100),
  platform VARCHAR(50),
  plat_name VARCHAR(100),
  h1 TEXT,
  action VARCHAR(20) DEFAULT 'download',
  state_json LONGTEXT,
  created_at DATETIME DEFAULT NOW(),
  INDEX idx_created (created_at),
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$data = json_decode(file_get_contents('php://input'), true);
if(!$data){ echo json_encode(['error'=>'No data']); exit; }
if(!g7_brand_allows_text((int)$user['id'], (string)($data['platform'] ?? ''))){ echo json_encode(['error'=>'No tenés acceso a esa marca']); exit; }

$db->prepare("INSERT INTO g7_mailing_history (user_id,user_name,platform,plat_name,h1,action,state_json) VALUES (?,?,?,?,?,?,?)")
   ->execute([
     $user['id'],
     $user['name'] ?? $user['username'] ?? '',
     $data['platform'] ?? '',
     $data['plat_name'] ?? '',
     $data['h1'] ?? '',
     $data['action'] ?? 'download',
     $data['state_json'] ?? null,
   ]);

echo json_encode(['ok'=>true, 'id'=>$db->lastInsertId()]);
