<?php
/**
 * mood_api.php — "¿Cómo estás hoy?" del Inicio (assets/home-mood.js). Cada persona guarda y lee lo suyo;
 * los admins reciben además el promedio anónimo del equipo (mood_team). Doc: toolz/ANIMO.md
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mood_lib.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$out = function (array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
$me = getCurrentUser();
if (!$me) $out(['error' => 'Tu sesión venció: vuelve a entrar.'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !preg_match('#^application/json\s*(;|$)#i', (string)($_SERVER['CONTENT_TYPE'] ?? ''))) $out(['error' => 'Pedido inválido.'], 400);
$db = getDB();
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$uid = (int)$me['id']; $admin = ($me['role'] ?? '') === 'admin';
$state = fn() => ['mine' => mood_mine($db, $uid)] + ($admin ? ['team' => mood_team($db)] : []);
try {
    switch ((string)($in['action'] ?? '')) {
        case 'get': $out($state());
        case 'set': mood_set($db, $uid, (int)($in['mood'] ?? 0), $in['reasons'] ?? []); $out(['ok' => true] + $state());
    }
    $out(['error' => 'Acción desconocida.'], 400);
} catch (RuntimeException $e) { $out(['error' => $e->getMessage()], 400); }
