<?php
require_once __DIR__ . '/auth.php';
requireLogin();

$token = 'PEGA_TU_TOKEN_AQUI';

$ch = curl_init('https://api.monday.com/v2');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['query' => '{
        boards(ids: [7527739787]) {
            items_page(limit: 100) {
                items {
                    name
                    column_values {
                        id text value
                        column { title type }
                    }
                }
            }
        }
        users(limit: 50) { id name email }
    }']),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: ' . $token,
        'API-Version: 2024-01',
    ],
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$res = curl_exec($ch);
curl_close($ch);
$data = json_decode($res, true);

echo "<pre style='background:#111;color:#e4e4e7;padding:20px;font-size:12px'>";
echo "<strong>USUARIOS:</strong>\n";
foreach (($data['data']['users'] ?? []) as $u) {
    echo "ID: {$u['id']} — {$u['name']} ({$u['email']})\n";
}
echo "</pre>";
