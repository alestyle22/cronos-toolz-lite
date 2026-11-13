<?php
error_reporting(0); ini_set('display_errors', 0);
require_once __DIR__ . '/../auth.php';
$currentUser = requireLogin();
require_once __DIR__ . '/../csp_lib.php'; g7_csp_enforce();   // CSP estricta (SEC-24 fase 2): sin código en línea
$isAdmin     = ($currentUser['role'] === 'admin');
$db          = getDB();

// Ensure table exists
try { $db->exec("CREATE TABLE IF NOT EXISTS g7_landing_views (
    id INT AUTO_INCREMENT PRIMARY KEY, landing_id INT NOT NULL,
    ip VARCHAR(45) DEFAULT '', country VARCHAR(100) DEFAULT '', city VARCHAR(100) DEFAULT '',
    referrer VARCHAR(500) DEFAULT '', ref_domain VARCHAR(200) DEFAULT '',
    device VARCHAR(20) DEFAULT 'desktop', ua TEXT, created_at DATETIME DEFAULT NOW(),
    INDEX idx_landing(landing_id), INDEX idx_created(created_at))");
} catch(Exception $e) {}
// Solo lectura: landings y visitas quedan filtradas por la marca del usuario
g7_brand_scope_readonly((int)$currentUser['id']);

// JSON API
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $d      = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $d['action'] ?? '';
    $lid    = (int)($d['landing_id'] ?? 0);
    $days   = min((int)($d['days'] ?? 30), 365);

    if ($action === 'stats') {
        $where  = $lid ? "WHERE landing_id=?" : "WHERE 1";
        $params = $lid ? [$lid] : [];

        // Total
        $tot = $db->prepare("SELECT COUNT(*) FROM g7_landing_views $where AND created_at >= DATE_SUB(NOW(),INTERVAL ? DAY)");
        $tot->execute(array_merge($params, [$days]));

        // Unique IPs
        $uniq = $db->prepare("SELECT COUNT(DISTINCT ip) FROM g7_landing_views $where AND created_at >= DATE_SUB(NOW(),INTERVAL ? DAY)");
        $uniq->execute(array_merge($params, [$days]));

        // Today
        $today = $db->prepare("SELECT COUNT(*) FROM g7_landing_views $where AND DATE(created_at)=CURDATE()");
        $today->execute($params);

        // Views per day (last N days)
        $byDay = $db->prepare("SELECT DATE(created_at) as d, COUNT(*) as cnt FROM g7_landing_views $where AND created_at >= DATE_SUB(NOW(),INTERVAL ? DAY) GROUP BY DATE(created_at) ORDER BY d ASC");
        $byDay->execute(array_merge($params, [$days]));

        // Countries
        $byCountry = $db->prepare("SELECT country, COUNT(*) as cnt FROM g7_landing_views $where AND created_at >= DATE_SUB(NOW(),INTERVAL ? DAY) AND country != '' GROUP BY country ORDER BY cnt DESC LIMIT 15");
        $byCountry->execute(array_merge($params, [$days]));

        // Cities
        $byCity = $db->prepare("SELECT city, country, COUNT(*) as cnt FROM g7_landing_views $where AND created_at >= DATE_SUB(NOW(),INTERVAL ? DAY) AND city != '' GROUP BY city,country ORDER BY cnt DESC LIMIT 10");
        $byCity->execute(array_merge($params, [$days]));

        // Referrers
        $byRef = $db->prepare("SELECT COALESCE(NULLIF(ref_domain,''),'Directo') as src, COUNT(*) as cnt FROM g7_landing_views $where AND created_at >= DATE_SUB(NOW(),INTERVAL ? DAY) GROUP BY src ORDER BY cnt DESC LIMIT 10");
        $byRef->execute(array_merge($params, [$days]));

        // Devices
        $byDev = $db->prepare("SELECT device, COUNT(*) as cnt FROM g7_landing_views $where AND created_at >= DATE_SUB(NOW(),INTERVAL ? DAY) GROUP BY device ORDER BY cnt DESC");
        $byDev->execute(array_merge($params, [$days]));

        // Views per landing (if no specific landing)
        $byLanding = [];
        if (!$lid) {
            $bl = $db->prepare("SELECT l.id, l.name, l.platform, l.public_url, COUNT(v.id) as cnt
                FROM g7_landings l LEFT JOIN g7_landing_views v ON v.landing_id=l.id
                AND v.created_at >= DATE_SUB(NOW(),INTERVAL ? DAY)
                WHERE l.published_at IS NOT NULL
                GROUP BY l.id ORDER BY cnt DESC LIMIT 20");
            $bl->execute([$days]);
            $byLanding = $bl->fetchAll();
        }

        echo json_encode([
            'total'       => (int)$tot->fetchColumn(),
            'unique'      => (int)$uniq->fetchColumn(),
            'today'       => (int)$today->fetchColumn(),
            'by_day'      => $byDay->fetchAll(),
            'by_country'  => $byCountry->fetchAll(),
            'by_city'     => $byCity->fetchAll(),
            'by_referrer' => $byRef->fetchAll(),
            'by_device'   => $byDev->fetchAll(),
            'by_landing'  => $byLanding,
        ]);
        exit;
    }
    echo json_encode(['error' => 'unknown action']); exit;
}

// Landings publicadas para el selector
try {
    $landingsList = $db->query("SELECT l.id, l.name, l.platform, l.public_url,
        (SELECT COUNT(*) FROM g7_landing_views WHERE landing_id=l.id) as total_views
        FROM g7_landings l WHERE l.published_at IS NOT NULL ORDER BY l.name ASC")->fetchAll();
} catch(Exception $e) { $landingsList = []; }

$selectedId = (int)($_GET['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Estadísticas de Landings — G7</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" integrity="sha384-e6nUZLBkQ86NJ6TVVKAeSaK8jWa3NhkYWZFomE39AvDbQWeie9PlQqM3pmYW5d1g" crossorigin="anonymous"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--g7-bg);color:var(--g7-text);font-family:'Inter',sans-serif;min-height:100vh}
.page{max-width:1200px;margin:0 auto;padding:24px 20px}
.page-head{display:flex;align-items:center;gap:14px;margin-bottom:24px;flex-wrap:wrap}
.page-head h1{font-size:20px;font-weight:800;display:flex;align-items:center;gap:9px}
.page-head h1 i{color:var(--g7-accent)}
.back-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;background:var(--g7-bg-3);border:1px solid var(--g7-border-3);color:var(--g7-text-2);text-decoration:none;font-size:12px;font-weight:600;transition:all .15s}
.back-btn:hover{color:var(--g7-text);border-color:var(--g7-border-3)}

/* Controls */
.controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:22px}
.lnd-select{background:var(--g7-bg-3);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:9px;padding:9px 12px;font-size:13px;font-weight:600;outline:none;cursor:pointer;min-width:220px}
.lnd-select:focus{border-color:var(--g7-accent)}
.days-group{display:flex;gap:3px;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:8px;padding:3px}
.day-btn{background:none;border:none;color:var(--g7-text-3);padding:6px 14px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s}
.day-btn.active{background:var(--g7-accent);color:#000}
.refresh-btn{background:none;border:1px solid var(--g7-border-3);color:var(--g7-text-4);border-radius:8px;padding:8px 12px;cursor:pointer;font-size:13px;transition:all .15s}
.refresh-btn:hover{color:var(--g7-accent);border-color:rgba(var(--g7-accent-rgb),.4)}

/* KPI cards */
.kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:22px}
.kpi{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:12px;padding:16px 18px}
.kpi-label{font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.09em;margin-bottom:6px;display:flex;align-items:center;gap:5px}
.kpi-label i{color:var(--g7-accent)}
.kpi-val{font-size:28px;font-weight:800;color:var(--g7-accent);line-height:1}
.kpi-sub{font-size:10px;color:var(--g7-text-5);margin-top:4px}

/* Grid */
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:14px}
@media(max-width:900px){.grid2,.grid3{grid-template-columns:1fr}}

/* Widget */
.wid{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:12px;padding:16px}
.wid-title{font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.09em;margin-bottom:14px;display:flex;align-items:center;gap:6px}
.wid-title i{color:var(--g7-accent)}

/* Bar list */
.bar-list{display:flex;flex-direction:column;gap:8px}
.bar-item{display:flex;align-items:center;gap:10px}
.bar-label{font-size:11px;color:var(--g7-text-2);min-width:0;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bar-track{flex:2;height:6px;background:var(--g7-bg-5);border-radius:3px;overflow:hidden;min-width:60px}
.bar-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,var(--g7-accent),#f59e0b);transition:width .4s ease}
.bar-cnt{font-size:11px;font-weight:700;color:var(--g7-text);min-width:30px;text-align:right}

/* Device pies */
.dev-row{display:flex;gap:10px;align-items:center}
.dev-item{display:flex;flex-direction:column;align-items:center;gap:4px;flex:1}
.dev-icon{font-size:22px;margin-bottom:2px}
.dev-pct{font-size:16px;font-weight:800;color:var(--g7-accent)}
.dev-label{font-size:9px;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em}

/* Landing list */
.land-row{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--g7-border-2)}
.land-row:last-child{border:none}
.land-name{flex:1;font-size:12px;font-weight:700;color:var(--g7-text);min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.land-plat{font-size:9px;color:var(--g7-text-4);background:var(--g7-bg-4);border-radius:4px;padding:2px 6px}
.land-cnt{font-size:13px;font-weight:800;color:var(--g7-accent);min-width:40px;text-align:right}
.land-link{color:var(--g7-text-4);font-size:11px;transition:color .15s;text-decoration:none;flex-shrink:0}
.land-link:hover{color:var(--g7-accent)}

.empty{text-align:center;color:var(--g7-text-5);font-size:12px;padding:24px 0}
.empty i{display:block;font-size:22px;margin-bottom:8px;opacity:.3}
.loading{display:flex;align-items:center;justify-content:center;padding:32px;color:var(--g7-text-4);gap:10px;font-size:13px}
.flag{margin-right:4px}
</style>
</head>
<body>
<?php include __DIR__ . '/../topbar.php'; ?>

<div class="page">
  <div class="page-head">
    <a href="/toolz/landing-gen/" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Mis Landings</a>
    <h1><i class="fa-solid fa-chart-mixed"></i> Estadísticas de Landings</h1>
  </div>

  <div class="controls">
    <select class="lnd-select" id="landing-sel" data-on-change="changeLanding" data-change-1="value">
      <option value="0">📊 Todas las landings</option>
      <?php foreach($landingsList as $l): ?>
      <option value="<?= $l['id'] ?>" <?= $selectedId==$l['id']?'selected':'' ?>>
        <?= htmlspecialchars($l['name']) ?> (<?= (int)$l['total_views'] ?> vistas)
      </option>
      <?php endforeach; ?>
    </select>
    <div class="days-group">
      <button class="day-btn" data-on-click="setDays" data-click-1="n:7" data-click-2="this">7d</button>
      <button class="day-btn active" data-on-click="setDays" data-click-1="n:30" data-click-2="this">30d</button>
      <button class="day-btn" data-on-click="setDays" data-click-1="n:90" data-click-2="this">90d</button>
      <button class="day-btn" data-on-click="setDays" data-click-1="n:365" data-click-2="this">1a</button>
    </div>
    <button class="refresh-btn" data-on-click="loadStats" title="Refrescar"><i class="fa-solid fa-rotate-right"></i></button>
  </div>

  <!-- KPIs -->
  <div class="kpi-row" id="kpi-row">
    <div class="loading"><i class="fa-solid fa-spinner fa-spin"></i> Cargando...</div>
  </div>

  <!-- Chart de línea -->
  <div class="wid" style="margin-bottom:14px">
    <div class="wid-title"><i class="fa-solid fa-chart-line"></i> Visitas por día</div>
    <div style="position:relative;height:180px"><canvas id="chart-days"></canvas></div>
  </div>

  <div class="grid2">
    <!-- Países -->
    <div class="wid">
      <div class="wid-title"><i class="fa-solid fa-earth-americas"></i> Países</div>
      <div class="bar-list" id="list-countries"><div class="empty"><i class="fa-solid fa-globe"></i>Sin datos</div></div>
    </div>
    <!-- Ciudades -->
    <div class="wid">
      <div class="wid-title"><i class="fa-solid fa-city"></i> Ciudades</div>
      <div class="bar-list" id="list-cities"><div class="empty"><i class="fa-solid fa-map-pin"></i>Sin datos</div></div>
    </div>
  </div>

  <div class="grid2">
    <!-- Referrers -->
    <div class="wid">
      <div class="wid-title"><i class="fa-solid fa-share-nodes"></i> Fuentes de tráfico</div>
      <div class="bar-list" id="list-referrers"><div class="empty"><i class="fa-solid fa-link"></i>Sin datos</div></div>
    </div>
    <!-- Dispositivos -->
    <div class="wid">
      <div class="wid-title"><i class="fa-solid fa-mobile-screen-button"></i> Dispositivos</div>
      <div id="dev-wrap"><div class="empty"><i class="fa-solid fa-laptop-mobile"></i>Sin datos</div></div>
    </div>
  </div>

  <!-- Ranking landings (solo si se ven todas) -->
  <div class="wid" id="landing-rank-wid" style="display:none">
    <div class="wid-title"><i class="fa-solid fa-ranking-star"></i> Ranking de landings</div>
    <div id="landing-rank"></div>
  </div>
</div>

<script type="application/json" id="lgs-data"><?= json_encode(['selectedId' => (int)$selectedId]) ?></script>
<script src="/toolz/landing-gen/stats.js?v=<?= (int)@filemtime(__DIR__ . '/stats.js') ?>"></script>
</body>
</html>
