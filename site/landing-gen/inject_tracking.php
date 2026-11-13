<?php
error_reporting(0); ini_set('display_errors', 0);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/bundle_lib.php';   // lg_pub_dir() y lg_beacon(): dónde están las landings y el script de seguimiento
$currentUser = requireLogin();
if ($currentUser['role'] !== 'admin') { http_response_code(403); die('Solo admins'); }
require_once __DIR__ . '/../csp_lib.php'; g7_csp_enforce();   // CSP estricta (SEC-24 fase 2): la página no tiene JS; el script de $script va a los archivos publicados
$db = getDB();

// Ensure table exists
try { $db->exec("CREATE TABLE IF NOT EXISTS g7_landing_views (
    id INT AUTO_INCREMENT PRIMARY KEY, landing_id INT NOT NULL,
    ip VARCHAR(45) DEFAULT '', country VARCHAR(100) DEFAULT '', city VARCHAR(100) DEFAULT '',
    referrer VARCHAR(500) DEFAULT '', ref_domain VARCHAR(200) DEFAULT '',
    device VARCHAR(20) DEFAULT 'desktop', ua TEXT, created_at DATETIME DEFAULT NOW(),
    INDEX idx_landing(landing_id), INDEX idx_created(created_at))");
} catch(Exception $e) {}

function landingSlug(string $name, int $id): string {
    $s = mb_strtolower(trim($name));
    $s = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $s);
    $s = preg_replace('/[\s]+/', '-', $s);
    $s = trim($s, '-') ?: 'landing';
    return $s . '-' . $id;
}

$pubDir = lg_pub_dir();

$rows = $db->query("SELECT id, name, public_url FROM g7_landings WHERE published_at IS NOT NULL ORDER BY id ASC")->fetchAll();

$ok = 0; $skip = 0; $err = 0;
$log = [];

foreach ($rows as $r) {
    $id   = (int)$r['id'];
    $slug = landingSlug($r['name'], $id);
    $file = $pubDir . '/' . $slug . '.html';

    if (!file_exists($file)) {
        // Try to derive from public_url
        if ($r['public_url']) {
            $base = basename(parse_url($r['public_url'], PHP_URL_PATH));
            $file = $pubDir . '/' . $base;
        }
        if (!file_exists($file)) {
            $log[] = "❌ No encontrado: $slug (id=$id)";
            $err++;
            continue;
        }
    }

    $html = file_get_contents($file);
    if ($html === false) { $log[] = "❌ No se pudo leer: $file"; $err++; continue; }

    // Skip if already injected
    if (strpos($html, '/landing-gen/track.php') !== false) {
        $log[] = "⏭ Ya tiene tracking: $slug";
        $skip++;
        continue;
    }

    $script = lg_beacon($id);

    $newHtml = strpos($html, '</body>') !== false
        ? str_replace('</body>', $script . '</body>', $html)
        : $html . $script;

    if (file_put_contents($file, $newHtml) !== false) {
        $log[] = "✅ Inyectado: " . htmlspecialchars($r['name']) . " (id=$id)";
        $ok++;
    } else {
        $log[] = "❌ No se pudo escribir: $file";
        $err++;
    }
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Inject Tracking</title>
<style>body{background:#0a0a0a;color:#e4e4e7;font-family:monospace;padding:24px;font-size:13px}
h2{color:#ff6600;margin-bottom:16px}.line{padding:4px 0;border-bottom:1px solid #1a1a1a}
.sum{margin-top:20px;background:#111;border:1px solid #27272a;border-radius:8px;padding:14px;font-size:14px}
</style></head><body>
<h2>🔧 Inyección de tracking en landings publicadas</h2>
<?php foreach($log as $l): ?><div class="line"><?= $l ?></div><?php endforeach; ?>
<div class="sum">
  ✅ Inyectadas: <b style="color:#4ade80"><?= $ok ?></b> &nbsp;
  ⏭ Ya tenían: <b style="color:#f59e0b"><?= $skip ?></b> &nbsp;
  ❌ Errores: <b style="color:#f87171"><?= $err ?></b>
  <br><br>
  <a href="/toolz/landing-gen/" style="color:#ff6600">← Volver a Landings</a>
  &nbsp;|&nbsp;
  <a href="/toolz/landing-gen/stats.php" style="color:#a78bfa">Ver estadísticas →</a>
</div>
</body></html>
