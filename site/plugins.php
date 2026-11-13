<?php
/**
 * Plugin Router — /toolz/plugins.php
 * Carga el sistema de plugins leyendo cada plugins/{id}/plugin.php
 */
require_once __DIR__ . '/auth.php';
$currentUser = requireLogin();
$isAdmin     = ($currentUser['role'] === 'admin');
$db          = getDB();

// ── PLUGIN REGISTRY ───────────────────────────────────────
// Escanea /toolz/plugins/*/plugin.php y carga metadata
function g7_load_plugins(): array {
    $plugins = [];
    $dirs = glob(__DIR__ . '/plugins/*/plugin.php') ?: [];
    foreach ($dirs as $file) {
        try {
            $meta = include $file;
            if (is_array($meta) && isset($meta['id'])) {
                $meta['dir'] = dirname($file);
                $plugins[$meta['id']] = $meta;
            }
        } catch (Throwable $e) {}
    }
    return $plugins;
}

// ── API: GET REGISTRY (for admin panel JS) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$isAdmin) { echo json_encode(['error'=>'Acceso denegado']); exit; }

    $in     = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $in['action'] ?? '';

    if ($action === 'get_registry') {
        $plugins = g7_load_plugins();
        // Strip 'dir' from output (server path)
        $out = array_map(function($p) {
            unset($p['dir']); return $p;
        }, $plugins);
        // Debug info
        $scanned = glob(__DIR__ . '/plugins/*/plugin.php') ?: [];
        echo json_encode([
            'ok'      => true,
            'plugins' => array_values($out),
            'scanned' => count($scanned),
            'paths'   => array_map('basename', array_map('dirname', $scanned)),
        ]);
        exit;
    }

    // Route to plugin API
    if ($action === 'plugin_action') {
        $pluginId   = $in['plugin_id']  ?? '';
        $subAction  = $in['sub_action'] ?? $in['action'] ?? ''; // the actual plugin action (manual, save, list, etc)
        $plugins    = g7_load_plugins();
        if (!isset($plugins[$pluginId])) {
            echo json_encode(['error' => 'Plugin no encontrado']); exit;
        }
        $apiFile = $plugins[$pluginId]['dir'] . '/api.php';
        if (!file_exists($apiFile)) {
            echo json_encode(['error' => 'Plugin sin API']); exit;
        }
        // Rewrite php://input so api.php sees the sub-action as 'action'
        $in['action'] = $subAction;
        // Make $in available to api.php via a global
        $GLOBALS['_g7_plugin_input'] = $in;
        include $apiFile;
        exit;
    }

    echo json_encode(['error' => 'Acción desconocida']); exit;
}