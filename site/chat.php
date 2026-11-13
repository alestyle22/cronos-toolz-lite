<?php
// API del chat viejo, retirada: el chat nuevo usa /toolz/chat/api.php (ver CHAT_PLAN.md).
http_response_code(410);
header('Content-Type: application/json');
echo json_encode(['error' => 'El chat se actualizó: recarga la página']);
