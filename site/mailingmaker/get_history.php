<?php
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
$user = getCurrentUser();
if(!$user){ http_response_code(401); echo json_encode([]); exit; }
$db = getDB();

try{
  $rows = $db->query(
    "SELECT id,user_name,platform,plat_name,h1,action,state_json,created_at
     FROM g7_mailing_history
     WHERE " . g7_brand_sql('platform', g7_brand_scope((int)$user['id'])) . "
     ORDER BY created_at DESC
     LIMIT 30"
  )->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode($rows);
}catch(Exception $e){
  echo json_encode([]);
}
