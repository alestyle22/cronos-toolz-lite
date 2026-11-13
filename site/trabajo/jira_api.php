<?php
/**
 * trabajo/jira_api.php — Sección "Jira" de Gestión de trabajo (trabajo/jira.js). Solo lectura. Doc: toolz/JIRA.md
 * POST JSON {action: issues, q, project, cat (new|indeterminate|done), mine (bool)}.
 * Solo si Admin › Jira API › "Usar Jira en Cronos" tiene Gestión de trabajo prendido. Las consultas van acotadas a los
 * proyectos elegidos. "Los míos" busca la persona de Jira por el email del usuario de Cronos.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../jira_use_lib.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$out = function (array $d, int $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
$me = getCurrentUser();
if (!$me) $out(['error' => 'Tu sesión venció: vuelve a entrar.'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) $out(['error' => 'Pedido inválido.'], 400);
if (!g7_jira_on('trabajo')) $out(['error' => 'Jira no está activado para Gestión de trabajo (Admin › Jira API › Usar Jira en Cronos).'], 409);
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$uid = (int)$me['id']; $db = getDB();
session_write_close();
try {
    if (($in['action'] ?? '') !== 'issues') $out(['error' => 'Acción desconocida.'], 400);
    $f = ['q' => (string)($in['q'] ?? ''), 'project' => (string)($in['project'] ?? ''), 'cat' => (string)($in['cat'] ?? ''), 'max' => 100];
    $mine = null;
    if (!empty($in['mine'])) {
        $mine = g7_jira_account_of($db, $uid);
        if (!$mine) $out(['issues' => [], 'contents' => (object)[], 'projects' => g7_jira_features()['projects'], 'mine_missing' => true]);
        $f['assignee'] = $mine;
    }
    $issues = g7_jira_find($f);
    $out(['issues' => $issues, 'contents' => (object)g7_jira_contents_of($db, $uid, array_column($issues, 'key')), 'projects' => g7_jira_features()['projects'],
        'site' => g7_jira_cfg()['site']]);
} catch (G7JiraError $e) { $out(['error' => $e->getMessage()], 502); }
