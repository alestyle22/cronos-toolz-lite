<?php
/**
 * novedades_api.php — Ventana "Qué hay de nuevo" (assets/g7-whatsnew.js). JSON por POST con cabecera X-Nov: 1.
 *   seen {version}   la persona cerró la ventana: no vuelve a aparecer hasta una versión más nueva
 *   all              todas las versiones con novedades (para "Novedades" del menú de la persona)
 * Doc: toolz/NOVEDADES.md
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/novedades_lib.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$out = function (array $d, int $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
$me = getCurrentUser();
if (!$me) $out(['error' => 'Tu sesión venció: vuelve a entrar.'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_NOV'] ?? '') !== '1') $out(['error' => 'Pedido inválido.'], 400);
session_write_close();
$db = getDB(); $uid = (int)$me['id'];
$in = json_decode((string)file_get_contents('php://input'), true) ?: []; $act = (string)($in['action'] ?? '');
$admin = ($me['role'] ?? '') === 'admin'; $super = $admin && function_exists('g7_super_ok') && g7_super_ok($uid);

if ($act === 'seen') {
    $v = (string)($in['version'] ?? '');
    if (!preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}$/', $v) || !isset(g7_nov_all()[$v])) $out(['error' => 'Versión desconocida.'], 422);
    // Nunca para atrás: si ya vio una más nueva, queda esa
    $q = $db->prepare("SELECT pref_value FROM g7_user_prefs WHERE user_id = ? AND pref_key = 'whats_new_seen'"); $q->execute([$uid]);
    $cur = (string)json_decode((string)$q->fetchColumn(), true);
    if ($cur === '' || version_compare($v, $cur, '>')) {
        $db->prepare("INSERT INTO g7_user_prefs (user_id, pref_key, pref_value) VALUES (?, 'whats_new_seen', ?) ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)")->execute([$uid, json_encode($v)]);
    }
    $out(['ok' => true]);
}
if ($act === 'all') {
    $list = [];
    foreach (g7_nov_all() as $v => $rel) {
        $hid = 0; $s = g7_nov_for($rel, $admin, $super, $hid);
        if ($s) $list[] = ['version' => $v, 'title' => $hid ? 'Novedades de la versión ' . $v : (string)($rel['title'] ?? ''), 'date' => (string)($rel['date'] ?? ''), 'slides' => $s];
        if (count($list) >= 6) break;
    }
    $out(['releases' => $list]);
}
$out(['error' => 'Acción desconocida.'], 400);
