<?php
/**
 * promos/jira_api.php — Jira en Promos: vincular contenidos con issues de Jira (promos/jira.js). Doc: toolz/JIRA.md
 * POST JSON {action}: state, links {content_id}, search {q}, link {content_id, key}, unlink {content_id, key}.
 * Solo si Admin › Jira API › "Usar Jira en Cronos" tiene Promos prendido. Ver = permiso "ver" de Promos; vincular = "editar".
 * El vínculo vive en Cronos (g7_jira_links): a Jira no se le escribe nada.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../jira_use_lib.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$out = function (array $d, int $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
$me = getCurrentUser();
if (!$me) $out(['error' => 'Tu sesión venció: vuelve a entrar.'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) $out(['error' => 'Pedido inválido.'], 400);
$uid = (int)$me['id']; $role = (string)$me['role']; $isAdmin = $role === 'admin';
$canView = $isAdmin || hasPermission($uid, 'promos', 'view', $role);
$canEdit = $isAdmin || hasPermission($uid, 'promos', 'edit', $role) || hasPermission($uid, 'promos', 'create', $role);
if (!$canView) $out(['error' => 'No tienes acceso a Promos.'], 403);
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$act = (string)($in['action'] ?? '');
if ($act === 'state') $out(['on' => g7_jira_on('promos'), 'can_edit' => $canEdit, 'projects' => g7_jira_features()['projects']]);
if (!g7_jira_on('promos')) $out(['error' => 'Jira no está activado para Promos (Admin › Jira API › Usar Jira en Cronos).'], 409);
$db = getDB();
// El contenido tiene que existir y ser de las marcas de la persona (igual que en Promos)
$content = function () use ($db, $in, $uid, $out) {
    $id = (int)($in['content_id'] ?? 0);
    $brand = g7_brand_sql("CONCAT_WS(' ', project, projects)", g7_brand_scope($uid));
    $q = $db->prepare("SELECT id FROM g7_content WHERE id = ? AND ($brand)"); $q->execute([$id]);
    if (!$q->fetchColumn()) $out(['error' => 'Ese contenido no existe o no tienes acceso.'], 404);
    return 'content:' . $id;
};
try {
    switch ($act) {
        case 'links': $out(['links' => g7_jira_links_refresh($db, $content())]);
        case 'search': $out(['issues' => g7_jira_find(['q' => (string)($in['q'] ?? ''), 'max' => 20])]);
        case 'link':
            if (!$canEdit) $out(['error' => 'No puedes editar contenidos de Promos.'], 403);
            $t = $content(); $links = g7_jira_link($db, $uid, $t, (string)($in['key'] ?? ''));
            g7_audit('promos.jira_link', $t . ' ' . strtoupper((string)$in['key']), null, $uid);
            $out(['links' => $links]);
        case 'unlink':
            if (!$canEdit) $out(['error' => 'No puedes editar contenidos de Promos.'], 403);
            $t = $content(); $links = g7_jira_unlink($db, $t, (string)($in['key'] ?? ''));
            g7_audit('promos.jira_unlink', $t . ' ' . strtoupper((string)$in['key']), null, $uid);
            $out(['links' => $links]);
    }
    $out(['error' => 'Acción desconocida.'], 400);
} catch (G7JiraError $e) { $out(['error' => $e->getMessage()], 502); }
  catch (RuntimeException $e) { $out(['error' => $e->getMessage()], 400); }
