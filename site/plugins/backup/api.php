<?php
/**
 * Backup Plugin — API Handler
 * Llamado por plugins.php?plugin=backup&action=...
 */

// Auth and DB already handled by plugins.php router
// $currentUser and $db are available from the including scope
// El respaldo lleva la base completa (con los hashes de las contraseñas): solo el superadmin
if (!isset($currentUser) || $currentUser['role'] !== 'admin' || !g7_super_ok((int)$currentUser['id'])) {
    if (isset($currentUser) && function_exists('g7_audit')) g7_audit('super.denied', 'plugin_backup', null, (int)$currentUser['id']);
    http_response_code(403); echo json_encode(['error' => 'Solo el superadmin puede hacer respaldos.']); exit;
}
$in     = $GLOBALS['_g7_plugin_input'] ?? (json_decode(file_get_contents('php://input'), true) ?? []);
$action = $in['action'] ?? '';
$config = $in['config'] ?? [];

// Config values with defaults
$includeDb    = ($config['include_db']    ?? true)  !== false;
$includeFiles = ($config['include_files'] ?? true)  !== false;
$storagePath  = __DIR__ . '/storage/';   // fija: no se acepta una carpeta elegida desde el navegador (podría ser pública)
$keepCount    = max(1, min(30, (int)($config['keep'] ?? 5)));

// ── LIST ──────────────────────────────────────────────────
if ($action === 'list') {
    $files = glob($storagePath . 'g7_backup_*.zip') ?: [];
    $list  = array_map(fn($f) => [
        'name' => basename($f),
        'size' => _formatSize(filesize($f)),
        'date' => date('d/m/Y H:i', filemtime($f)),
    ], $files);
    usort($list, fn($a, $b) => strcmp($b['name'], $a['name']));
    echo json_encode(['ok' => true, 'backups' => $list]);
    exit;
}

// ── DELETE ────────────────────────────────────────────────
if ($action === 'delete') {
    $file = basename($in['file'] ?? '');
    if (!$file || !str_starts_with($file, 'g7_backup_')) {
        echo json_encode(['error' => 'Archivo inválido']); exit;
    }
    $path = $storagePath . $file;
    if (file_exists($path)) unlink($path);
    echo json_encode(['ok' => true]);
    exit;
}

// ── BUILD ZIP (shared by manual + save) ───────────────────
if (!in_array($action, ['manual', 'save'])) {
    echo json_encode(['error' => 'Acción desconocida']); exit;
}

$timestamp = date('Y-m-d_H-i-s');
$zipName   = "g7_backup_{$timestamp}.zip";
$zipTmp    = sys_get_temp_dir() . '/' . $zipName;

$zip = new ZipArchive();
if ($zip->open($zipTmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    echo json_encode(['error' => 'No se pudo crear el archivo ZIP']); exit;
}

// Add manifest
$zip->addFromString('manifest.json', json_encode([
    'created_at'     => date('c'),
    'created_by'     => $currentUser['username'],
    'includes_db'    => $includeDb,
    'includes_files' => $includeFiles,
    'platform'       => (string)g7_site('name'),
    'plugin_version' => '1.0',
], JSON_PRETTY_PRINT));

// Add files
if ($includeFiles) {
    $baseDir  = dirname(__DIR__, 2); // /toolz/
    // Fuera del ZIP: claves y archivos privados (_private), temporales, respaldos y la configuración local
    $skipList = ['backups', 'plugins/backup/storage', '_private', 'temp', 'config.local.php'];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iter as $file) {
        $realPath = $file->getRealPath();
        $relative = str_replace('\\', '/', substr($realPath, strlen($baseDir) + 1));
        $skip = false;
        foreach ($skipList as $s) {
            if (strpos($relative, $s) === 0) { $skip = true; break; }
        }
        if ($skip) continue;
        if ($file->isFile() && $file->getSize() > 50 * 1024 * 1024) continue; // skip >50MB
        if ($file->isDir())  $zip->addEmptyDir('files/' . $relative);
        else                 $zip->addFile($realPath, 'files/' . $relative);
    }
}

// Add DB dump
if ($includeDb) {
    try {
        $sql  = "-- " . g7_site('name') . " DB Backup — {$timestamp}\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $create = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n{$create[1]};\n\n";
            $rows = $db->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                $cols  = '`' . implode('`,`', array_keys($rows[0])) . '`';
                $chunk = [];
                foreach ($rows as $row) {
                    $vals    = array_map(fn($v) => $v === null ? 'NULL' : $db->quote($v), array_values($row));
                    $chunk[] = '(' . implode(',', $vals) . ')';
                    if (count($chunk) >= 100) {
                        $sql .= "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $chunk) . ";\n";
                        $chunk = [];
                    }
                }
                if ($chunk) $sql .= "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $chunk) . ";\n\n";
            }
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        $zip->addFromString('database/dump_' . $timestamp . '.sql', $sql);
    } catch (Exception $e) {
        $zip->addFromString('database/ERROR.txt', $e->getMessage());
    }
}

$zip->close();

// ── DELIVER ───────────────────────────────────────────────
if ($action === 'manual') {
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($zipTmp));
    header('Pragma: no-cache');
    readfile($zipTmp);
    unlink($zipTmp);
    exit;
}

// Save to server
if (!is_dir($storagePath)) mkdir($storagePath, 0755, true);
rename($zipTmp, $storagePath . $zipName);

// Cleanup old backups
$existing = glob($storagePath . 'g7_backup_*.zip') ?: [];
if (count($existing) > $keepCount) {
    usort($existing, fn($a, $b) => filemtime($a) - filemtime($b));
    foreach (array_slice($existing, 0, count($existing) - $keepCount) as $old) unlink($old);
}

// Return updated list
$files = glob($storagePath . 'g7_backup_*.zip') ?: [];
$list  = array_map(fn($f) => [
    'name' => basename($f),
    'size' => _formatSize(filesize($f)),
    'date' => date('d/m/Y H:i', filemtime($f)),
], $files);
usort($list, fn($a, $b) => strcmp($b['name'], $a['name']));

echo json_encode(['ok' => true, 'filename' => $zipName, 'size' => _formatSize(filesize($storagePath . $zipName)), 'backups' => $list]);

function _formatSize(int $bytes): string {
    if ($bytes >= 1024 * 1024) return round($bytes / 1024 / 1024, 2) . ' MB';
    return round($bytes / 1024, 1) . ' KB';
}
