<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once __DIR__ . '/auth.php';
try { if (file_exists(__DIR__ . '/gamification.php')) require_once __DIR__ . '/gamification.php'; } catch(Exception $e) {}

// ── HELPER FUNCTIONS ─────────────────────────────────────
if (!function_exists('getCmpData')) {
function getCmpData($db, $start, $end){
    $q=$db->prepare("SELECT COUNT(*) as registros, SUM(qty_graficas) as graficas, SUM(qty_videos) as videos, SUM(qty_graficas+qty_videos) as assets, SUM(qty_tareas) as tareas,
               SUM(CASE WHEN type='mailings' THEN qty_tareas ELSE 0 END) as qty_mailings_total, SUM(qty_revisiones) as revisiones, SUM(qty_rechazos) as rechazos FROM g7_content WHERE DATE(created_at) BETWEEN ? AND ? AND estado='activo'");
    $q->execute([$start,$end]); return $q->fetch();
}
function getCmpByProj($db,$start,$end){
    $q=$db->prepare("SELECT project, COUNT(*) as registros, SUM(qty_graficas+qty_videos) as assets FROM g7_content WHERE DATE(created_at) BETWEEN ? AND ? AND estado='activo' AND type!='tarea' AND project!='' GROUP BY project ORDER BY registros DESC LIMIT 15");
    $q->execute([$start,$end]); return $q->fetchAll();
}
function delta($cur,$prev){
    $d=(int)$cur-(int)$prev;
    if($prev==0) return ['','var(--g7-text-5)'];
    $pct=round(($d/$prev)*100);
    $color=$d>0?'#22c55e':($d<0?'#ef4444':'var(--g7-text-5)');
    $sign=$d>0?'+':'';
    return ["{$sign}{$pct}%",$color];
}
}

$currentUser = requireLogin();
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo (SEC-24)
$isAdmin     = ($currentUser['role'] === 'admin');
$db          = getDB();
// Solo lectura sobre contenido, landings y mailings: todo queda filtrado por la marca del usuario (Admin › Marcas)
g7_brand_scope_readonly((int)$currentUser['id']);

$period = $_GET['period'] ?? '30';
$section = $_GET['section'] ?? 'overview';
$periodMap = ['7'=>'7 días','30'=>'30 días','90'=>'90 días','365'=>'1 año','all'=>'Todo'];
if (!isset($periodMap[$period])) $period = '30';
$dateFilter = $period === 'all' ? '' : "AND clicked_at >= DATE_SUB(NOW(), INTERVAL $period DAY)";
$daysLimit  = $period === 'all' ? 365 : (int)$period;

try {
    $totalClicks  = $db->query("SELECT COUNT(*) FROM g7_link_clicks WHERE 1=1 $dateFilter")->fetchColumn();
    $totalLinks   = $db->query('SELECT COUNT(*) FROM g7_links')->fetchColumn();
    $totalCats    = $db->query('SELECT COUNT(*) FROM g7_categories')->fetchColumn();
    $totalUsers   = $db->query('SELECT COUNT(*) FROM g7_users WHERE active=1')->fetchColumn();
    $totalFavs    = $db->query('SELECT COUNT(*) FROM g7_link_favorites')->fetchColumn();
    $totalContent = $db->query('SELECT COUNT(*) FROM g7_content')->fetchColumn();
    $activePromos = $db->query("SELECT COUNT(*) FROM g7_content WHERE end_date >= CURDATE() OR end_date IS NULL")->fetchColumn();
    $clicksPerDay = $db->query("SELECT DATE(clicked_at) as day, COUNT(*) as total FROM g7_link_clicks WHERE clicked_at >= DATE_SUB(NOW(), INTERVAL $daysLimit DAY) GROUP BY DATE(clicked_at) ORDER BY day ASC")->fetchAll();
    $topLinks     = $db->query("SELECT l.name, l.url, c.name as category, COUNT(k.id) as clicks FROM g7_link_clicks k JOIN g7_links l ON l.id=k.link_id JOIN g7_categories c ON c.id=l.category_id WHERE 1=1 $dateFilter GROUP BY k.link_id ORDER BY clicks DESC LIMIT 10")->fetchAll();
    $clicksByCat  = $db->query("SELECT c.name, COUNT(k.id) as clicks FROM g7_link_clicks k JOIN g7_links l ON l.id=k.link_id JOIN g7_categories c ON c.id=l.category_id WHERE 1=1 $dateFilter GROUP BY l.category_id ORDER BY clicks DESC")->fetchAll();
    $peakHours    = $db->query("SELECT HOUR(clicked_at) as hour, COUNT(*) as total FROM g7_link_clicks WHERE 1=1 $dateFilter GROUP BY HOUR(clicked_at) ORDER BY hour ASC")->fetchAll();
    $clicksByDay  = $db->query("SELECT DAYOFWEEK(clicked_at) as dow, COUNT(*) as total FROM g7_link_clicks WHERE 1=1 $dateFilter GROUP BY dow ORDER BY dow")->fetchAll();
    $myRow = $db->prepare("SELECT COUNT(*) as my_clicks FROM g7_link_clicks WHERE user_id=? $dateFilter");
    $myRow->execute([$currentUser['id']]); $myClicks = $myRow->fetchColumn();
    $myFavs = $db->prepare("SELECT COUNT(*) FROM g7_link_favorites WHERE user_id=?"); $myFavs->execute([$currentUser['id']]); $myFavCount = $myFavs->fetchColumn();
    $meRow = $db->prepare('SELECT total_time_sec,last_login,display_name,avatar FROM g7_users WHERE id=?'); $meRow->execute([$currentUser['id']]); $me = $meRow->fetch();
    try{$myNotes=$db->prepare("SELECT COUNT(*) FROM g7_link_notes WHERE user_id=? AND note!=''");$myNotes->execute([$currentUser['id']]);$myNoteCount=$myNotes->fetchColumn();}catch(Exception $e2){$myNoteCount=0;}
    try{$myTopLinks=$db->prepare("SELECT l.name,l.url,COUNT(*) as clicks FROM g7_link_clicks k JOIN g7_links l ON l.id=k.link_id WHERE k.user_id=? $dateFilter GROUP BY k.link_id ORDER BY clicks DESC LIMIT 8");$myTopLinks->execute([$currentUser['id']]);$myTop=$myTopLinks->fetchAll();}catch(Exception $e2){$myTop=[];}
    try{$lastLinkRow=$db->prepare("SELECT l.name,l.url,k.clicked_at FROM g7_link_clicks k JOIN g7_links l ON l.id=k.link_id WHERE k.user_id=? ORDER BY k.clicked_at DESC LIMIT 1");$lastLinkRow->execute([$currentUser['id']]);$lastLink=$lastLinkRow->fetch();}catch(Exception $e2){$lastLink=null;}
    try{$totalPosts=$db->query('SELECT COUNT(*) FROM g7_posts')->fetchColumn();}catch(Exception $e2){$totalPosts=0;}
    try{$postsPerDay=$db->query("SELECT DATE(created_at) as day, COUNT(*) as total FROM g7_posts WHERE created_at >= DATE_SUB(NOW(), INTERVAL $daysLimit DAY) GROUP BY DATE(created_at) ORDER BY day ASC")->fetchAll();}catch(Exception $e2){$postsPerDay=[];}
    try{$topPosters=$db->query("SELECT u.display_name,u.username,u.avatar,COUNT(p.id) as cnt FROM g7_posts p JOIN g7_users u ON u.id=p.user_id GROUP BY p.user_id ORDER BY cnt DESC LIMIT 8")->fetchAll();}catch(Exception $e2){$topPosters=[];}
    try{$topReacted=$db->query("SELECT p.body,u.display_name,u.username,COUNT(r.id) as reactions FROM g7_post_reactions r JOIN g7_posts p ON p.id=r.post_id JOIN g7_users u ON u.id=p.user_id GROUP BY r.post_id ORDER BY reactions DESC LIMIT 5")->fetchAll();}catch(Exception $e2){$topReacted=[];}
    try{$activityTypes=$db->query("SELECT type, COUNT(*) as cnt FROM g7_activity WHERE created_at >= DATE_SUB(NOW(), INTERVAL $daysLimit DAY) GROUP BY type ORDER BY cnt DESC")->fetchAll();}catch(Exception $e2){$activityTypes=[];}
    try{$activityByUser=$db->query("SELECT u.display_name,u.username,u.avatar,COUNT(a.id) as cnt FROM g7_activity a JOIN g7_users u ON u.id=a.user_id WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL $daysLimit DAY) GROUP BY a.user_id ORDER BY cnt DESC")->fetchAll();}catch(Exception $e2){$activityByUser=[];}
    try{$totalReactions=$db->query("SELECT COUNT(*) FROM g7_post_reactions")->fetchColumn();}catch(Exception $e2){$totalReactions=0;}
    try{$totalReplies=$db->query("SELECT COUNT(*) FROM g7_post_replies")->fetchColumn();}catch(Exception $e2){$totalReplies=0;}
    try{$totalComments=$db->query("SELECT COUNT(*) FROM g7_profile_comments")->fetchColumn();}catch(Exception $e2){$totalComments=0;}
    $totalActivity = $db->query("SELECT COUNT(*) FROM g7_activity WHERE created_at >= DATE_SUB(NOW(), INTERVAL $daysLimit DAY)")->fetchColumn();
    $contentByType = $db->query("SELECT type, COUNT(*) as cnt FROM g7_content GROUP BY type ORDER BY cnt DESC")->fetchAll();
    $contentByUser = $db->query("SELECT author, COUNT(*) as cnt FROM g7_content GROUP BY author ORDER BY cnt DESC LIMIT 8")->fetchAll();
    $contentPerDay = $db->query("SELECT DATE(created_at) as day, COUNT(*) as total FROM g7_content WHERE created_at >= DATE_SUB(NOW(), INTERVAL $daysLimit DAY) GROUP BY DATE(created_at) ORDER BY day ASC")->fetchAll();
    $recentContent = $db->query("SELECT name,type,author,created_at FROM g7_content ORDER BY created_at DESC LIMIT 10")->fetchAll();
    $expiring      = $db->query("SELECT COUNT(*) FROM g7_content WHERE end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)")->fetchColumn();
    $usersStats = [];
    if ($isAdmin) {
        try{$usersStats=$db->query("SELECT u.id,u.username,u.display_name,u.role,u.last_login,u.total_time_sec,u.avatar,(SELECT COUNT(*) FROM g7_link_clicks k WHERE k.user_id=u.id) as clicks,(SELECT COUNT(*) FROM g7_link_favorites f WHERE f.user_id=u.id) as favs,(SELECT COUNT(*) FROM g7_posts p WHERE p.user_id=u.id) as posts FROM g7_users u WHERE u.active=1 ORDER BY clicks DESC")->fetchAll();}catch(Exception $e2){$usersStats=[];}
    }
    // ── LANDING STATS ─────────────────────────────────────────
    $landingTotalViews=0;$landingUniqueViews=0;$landingViewsToday=0;$landingViewsPerDay=[];$landingTopByViews=[];$landingByCountry=[];$landingByDevice=[];$landingByReferrer=[];$landingTotalPublished=0;
    try {
        $landingTotalViews    = $db->query("SELECT COUNT(*) FROM g7_landing_views WHERE created_at>=DATE_SUB(NOW(),INTERVAL $daysLimit DAY)")->fetchColumn();
        $landingTotalPublished= $db->query("SELECT COUNT(*) FROM g7_landings WHERE published_at IS NOT NULL")->fetchColumn();
        if ($section === 'landings') {
            $landingUniqueViews   = $db->query("SELECT COUNT(DISTINCT ip) FROM g7_landing_views WHERE created_at>=DATE_SUB(NOW(),INTERVAL $daysLimit DAY)")->fetchColumn();
            $landingViewsToday    = $db->query("SELECT COUNT(*) FROM g7_landing_views WHERE DATE(created_at)=CURDATE()")->fetchColumn();
            $landingViewsPerDay   = $db->query("SELECT DATE(created_at) as day, COUNT(*) as total FROM g7_landing_views WHERE created_at>=DATE_SUB(NOW(),INTERVAL $daysLimit DAY) GROUP BY DATE(created_at) ORDER BY day ASC")->fetchAll();
            $landingTopByViews    = $db->query("SELECT l.id,l.name,l.platform,l.public_url,COALESCE(v.views,0) as views,COALESCE(v.unique_views,0) as unique_views FROM g7_landings l LEFT JOIN (SELECT landing_id, COUNT(*) as views, COUNT(DISTINCT ip) as unique_views FROM g7_landing_views WHERE created_at>=DATE_SUB(NOW(),INTERVAL $daysLimit DAY) GROUP BY landing_id) v ON v.landing_id=l.id WHERE l.published_at IS NOT NULL ORDER BY views DESC LIMIT 10")->fetchAll();
            $landingByCountry     = $db->query("SELECT country,COUNT(*) as cnt FROM g7_landing_views WHERE created_at>=DATE_SUB(NOW(),INTERVAL $daysLimit DAY) AND country!='' GROUP BY country ORDER BY cnt DESC LIMIT 10")->fetchAll();
            $landingByDevice      = $db->query("SELECT device,COUNT(*) as cnt FROM g7_landing_views WHERE created_at>=DATE_SUB(NOW(),INTERVAL $daysLimit DAY) GROUP BY device ORDER BY cnt DESC")->fetchAll();
            $landingByReferrer    = $db->query("SELECT COALESCE(NULLIF(ref_domain,''),'Directo') as src,COUNT(*) as cnt FROM g7_landing_views WHERE created_at>=DATE_SUB(NOW(),INTERVAL $daysLimit DAY) GROUP BY src ORDER BY cnt DESC LIMIT 8")->fetchAll();
        }
    } catch(Exception $e2) {}
} catch(Exception $e) {
    die('Error al cargar datos: ' . $e->getMessage());
}

function fmtTime($sec){if(!$sec)return'--';$h=floor($sec/3600);$m=floor(($sec%3600)/60);return $h?"{$h}h {$m}m":"{$m}m";}
function fmtDate($d){return $d?date('d/m H:i',strtotime($d)):'--';}
function avSmall($av,$name,$size=28){if($av)return'<img src="'.htmlspecialchars($av).'" style="width:'.$size.'px;height:'.$size.'px;border-radius:50%;object-fit:cover;flex-shrink:0">';$i=strtoupper(substr($name,0,1));$c=['#ff6600','#a78bfa','#4ade80','#60a5fa','#f59e0b','#ec4899'][ord($i)%6];$fs=floor($size*.4);return'<div style="width:'.$size.'px;height:'.$size.'px;border-radius:50%;background:'.$c.';display:flex;align-items:center;justify-content:center;font-size:'.$fs.'px;font-weight:700;color:#000;flex-shrink:0">'.$i.'</div>';}

// ── HELPER FUNCTIONS FOR STATS ───────────────────────────
function getCmpData($db, $start, $end){
    $q=$db->prepare("SELECT COUNT(*) as registros, SUM(qty_graficas) as graficas, SUM(qty_videos) as videos, SUM(qty_graficas+qty_videos) as assets, SUM(qty_tareas) as tareas, SUM(qty_revisiones) as revisiones, SUM(qty_rechazos) as rechazos FROM g7_content WHERE DATE(created_at) BETWEEN ? AND ? AND estado='activo'");
    $q->execute([$start,$end]); return $q->fetch();
}
function getCmpByProj($db,$start,$end){
    $q=$db->prepare("SELECT project, COUNT(*) as registros, SUM(qty_graficas+qty_videos) as assets FROM g7_content WHERE DATE(created_at) BETWEEN ? AND ? AND estado='activo' AND type!='tarea' AND project!='' GROUP BY project ORDER BY registros DESC LIMIT 15");
    $q->execute([$start,$end]); return $q->fetchAll();
}
function delta($cur,$prev){
    $d=$cur-$prev;
    if($prev==0) return ['','var(--g7-text-5)'];
    $pct=round(($d/$prev)*100);
    $color=$d>0?'#22c55e':($d<0?'#ef4444':'var(--g7-text-5)');
    $sign=$d>0?'+':'';
    return ["{$sign}{$pct}%",$color];
}

$navSections = ['overview'=>['icon'=>'fa-gauge-high','label'=>'Resumen'],'library'=>['icon'=>'fa-book-bookmark','label'=>'Biblioteca'],'community'=>['icon'=>'fa-people-group','label'=>'Comunidad'],'content'=>['icon'=>'fa-layer-group','label'=>'Contenido']];
if ($isAdmin) $navSections['users'] = ['icon'=>'fa-users','label'=>'Usuarios'];
if ($isAdmin) $navSections['bienestar'] = ['icon'=>'fa-heart-pulse','label'=>'Bienestar'];   // "¿Cómo estás hoy?", anónimo (mood_lib.php)
$navSections['produccion'] = ['icon'=>'fa-chart-bar','label'=>'Producción'];
$navSections['timeline']   = ['icon'=>'fa-calendar-days','label'=>'Timeline'];
$navSections['ranking']    = ['icon'=>'fa-trophy','label'=>'Ranking'];
$navSections['gamificacion']= ['icon'=>'fa-medal','label'=>'Gamificación'];
$navSections['tendencia']  = ['icon'=>'fa-chart-line','label'=>'Tendencia'];
$navSections['heatmap']    = ['icon'=>'fa-fire','label'=>'Heatmap'];
$navSections['aprobacion'] = ['icon'=>'fa-circle-check','label'=>'Aprobación'];
$navSections['tiempos']    = ['icon'=>'fa-stopwatch','label'=>'Tiempos'];
$navSections['vencidos']   = ['icon'=>'fa-triangle-exclamation','label'=>'Vencidos'];
$navSections['landings']   = ['icon'=>'fa-rocket','label'=>'Landings'];
$navSections['actividad']  = ['icon'=>'fa-bolt','label'=>'Actividad'];
$navSections['comparativa']= ['icon'=>'fa-code-compare','label'=>'Comparativa'];
// Jira (Admin › Jira API › Estadísticas): informes de los proyectos elegidos
require_once __DIR__ . '/jira_stats_lib.php';
if (g7_jira_stats_on()) $navSections['jira'] = ['icon'=>'fa-brands fa-jira','label'=>'Jira'];
// Exportar el informe de Jira (Excel o PDF) con las opciones elegidas: sale antes de dibujar la página
if (($_GET['section'] ?? '') === 'jira' && isset($_GET['export']) && g7_jira_stats_on()) { require __DIR__ . '/stats_jira_export.php'; exit; }
// Qué estados de Jira cuentan como entregado (solo admins): POST JSON con la cabecera X-JS
if (($_GET['section'] ?? '') === 'jira' && isset($_GET['deliver']) && g7_jira_stats_on()) {
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_JS'] ?? '') !== '1') { http_response_code(400); echo '{"error":"Pedido inválido"}'; exit; }
    if (($currentUser['role'] ?? '') !== 'admin') { http_response_code(403); echo '{"error":"Solo los administradores cambian esto"}'; exit; }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $cfg = g7_jira_stats_cfg_save(($in['mode'] ?? '') === 'auto' ? null : array_map('strval', (array)($in['states'] ?? [])));
    g7_audit('jira.stats_deliver', $cfg['deliver'] === null ? 'automático' : implode(', ', $cfg['deliver']));
    echo json_encode(['ok' => true] + $cfg, JSON_UNESCAPED_UNICODE); exit;
}
$navSections['changelog'] = ['icon'=>'fa-clock-rotate-left','label'=>'Changelog'];
// Secciones de módulos ocultos (Admin › Módulos) no se muestran
$_stUid = (int)$currentUser['id'];
if (!g7_href_visible($_stUid, '/toolz/comunidad.php')) unset($navSections['community']);
if (!g7_href_visible($_stUid, '/toolz/links/'))        unset($navSections['library']);
if (!g7_href_visible($_stUid, '/toolz/landing-gen/'))  unset($navSections['landings']);
if (!isset($navSections[$section])) $section = 'overview';
$TYPE_COLORS = ['promo'=>'#ff6600','launch'=>'#a78bfa','paid'=>'#4ade80','social'=>'#60a5fa'];
// ── CHANGELOG POST ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    header('Content-Type: application/json');
    $in  = json_decode(file_get_contents('php://input'), true) ?? [];
    $act = $in['action'] ?? '';

    if ($act === 'add_changelog') {
        $text = trim($in['text'] ?? '');
        if (!$text) { echo json_encode(['error'=>'Texto requerido']); exit; }
        try {
            $db->prepare("INSERT INTO g7_changelog (user_id, text) VALUES (?,?)")->execute([(int)$currentUser['id'], $text]);
            $id = $db->lastInsertId();
            $q = $db->prepare("SELECT c.*, u.display_name, u.username, u.avatar FROM g7_changelog c JOIN g7_users u ON u.id=c.user_id WHERE c.id=?");
            $q->execute([$id]);
            $entry = $q->fetch();
            if (!$entry) {
                // Fallback: return basic info
                $entry = ['id'=>$id,'text'=>$text,'created_at'=>date('Y-m-d H:i:s'),'display_name'=>$currentUser['display_name']??$currentUser['username'],'username'=>$currentUser['username'],'avatar'=>$currentUser['avatar']??''];
            }
            echo json_encode(['ok'=>true, 'entry'=>$entry]);
        } catch(Exception $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }
    if ($act === 'delete_changelog') {
        $id = (int)($in['id'] ?? 0);
        $db->prepare("DELETE FROM g7_changelog WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }
    echo json_encode(['error'=>'unknown']); exit;
}

// ── ENSURE CHANGELOG TABLE ────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS g7_changelog (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        text       TEXT NOT NULL,
        created_at DATETIME DEFAULT NOW(),
        INDEX idx_created (created_at)
    )");
} catch(Exception $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Stats - <?= htmlspecialchars(g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha384-3B6NwesSXE7YJlcLI9RpRqGf2p/EgVH8BgoKTaUrmKNDkHPStTQ3EyoYjCGXaOTS" crossorigin="anonymous">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js" integrity="sha384-DhxhYObIMeMNGyAG7iK11OHzBIKyEIeRL0ad1iFPAOwZB8iirUlTT0O/WJJUk8+o" crossorigin="anonymous"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--g7-bg);color:var(--g7-text);font-family:'Inter',sans-serif;min-height:100vh}
.dash{display:grid;grid-template-columns:220px 1fr;min-height:calc(100vh - 52px);max-width:1400px;margin:0 auto}
.stats-nav{border-right:1px solid var(--g7-border-2);padding:20px 14px;position:sticky;top:52px;height:calc(100vh - 52px);overflow-y:auto;display:flex;flex-direction:column;gap:2px}
.stats-nav::-webkit-scrollbar{width:2px}
.nav-head{font-size:9px;font-weight:700;color:var(--g7-text-5);text-transform:uppercase;letter-spacing:.1em;padding:10px 8px 4px}
.nav-link{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:8px;text-decoration:none;color:var(--g7-text-3);font-size:12px;font-weight:600;transition:all .15s}
.nav-link:hover{background:var(--g7-bg-4);color:var(--g7-text)}
.nav-link.active{background:rgba(var(--g7-accent-rgb),.1);color:var(--g7-accent)}
.nav-link i{width:15px;text-align:center;font-size:13px}
.period-group{margin-top:auto;padding-top:14px;border-top:1px solid var(--g7-border-2)}
.period-btn{display:flex;align-items:center;justify-content:space-between;width:100%;padding:7px 10px;border-radius:7px;border:none;background:none;color:var(--g7-text-3);font-size:11px;font-weight:600;cursor:pointer;transition:all .15s;text-decoration:none}
.period-btn:hover{background:var(--g7-bg-4);color:var(--g7-text)}
.period-btn.active{background:rgba(var(--g7-accent-rgb),.1);color:var(--g7-accent)}
.stats-main{padding:24px 28px;min-width:0;overflow-x:hidden}
.stats-main::-webkit-scrollbar{display:none}
/* Celular: el menú pasa arriba, en una fila que se desliza, y el contenido usa todo el ancho (antes quedaban 155 px en 375) */
@media(max-width:760px){.dash{grid-template-columns:1fr}.stats-nav{position:static;height:auto;flex-direction:row;align-items:center;overflow-x:auto;overflow-y:hidden;border-right:0;border-bottom:1px solid var(--g7-border-2);padding:8px 10px;gap:4px}.stats-nav .nav-head{display:none}.nav-link{white-space:nowrap;flex:none}.period-group{display:flex;gap:4px;margin:0 0 0 8px;padding:0 0 0 8px;border-top:0;border-left:1px solid var(--g7-border-2)}.period-btn{width:auto;white-space:nowrap}.stats-main{padding:16px}}
.page-header{margin-bottom:20px}
.page-title{font-size:20px;font-weight:800}
.page-title span{color:var(--g7-accent)}
.page-sub{font-size:11px;color:var(--g7-text-4);margin-top:2px}
.metrics{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:22px}
.mc{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:12px;padding:14px 16px;transition:border-color .2s}
.mc:hover{border-color:var(--g7-border-3)}
.mc-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.mc-icon{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
.mc-val{font-size:26px;font-weight:800;line-height:1;margin-bottom:4px}
.mc-label{font-size:10px;color:var(--g7-text-4);font-weight:600}
.mc-sub{font-size:10px;color:var(--g7-text-5);margin-top:3px}
.card{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:12px;padding:16px 18px;margin-bottom:16px}
.card-title{font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.1em;margin-bottom:14px;display:flex;align-items:center;gap:7px}
.card-title i{color:var(--g7-accent)}
.chart-wrap{position:relative;height:200px}
.chart-wrap.tall{height:260px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px}
@media(max-width:900px){.grid-2{grid-template-columns:1fr}}
table{width:100%;border-collapse:collapse}
th{font-size:9px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.08em;padding:7px 10px;text-align:left;border-bottom:1px solid var(--g7-border-2)}
td{padding:8px 10px;font-size:12px;border-bottom:1px solid var(--g7-border-2);vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(var(--g7-ov),.02)}
.rank{font-size:11px;font-weight:700;color:var(--g7-text-5);width:22px}
.badge{font-size:8px;font-weight:700;border-radius:3px;padding:2px 5px;text-transform:uppercase}
.badge-admin{background:rgba(var(--g7-accent-rgb),.12);color:var(--g7-accent)}
.badge-user{background:var(--g7-bg-5);color:var(--g7-text-4)}
.bar-bg{height:5px;background:var(--g7-bg-5);border-radius:3px;overflow:hidden}
.bar-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,var(--g7-accent),#ff9500);transition:width .4s}
.bar-val{font-size:11px;font-weight:700;color:var(--g7-accent);min-width:28px;text-align:right}
.user-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--g7-border-2)}
.user-row:last-child{border:none;padding-bottom:0}
.u-name{font-size:12px;font-weight:700;color:var(--g7-text)}
.u-sub{font-size:10px;color:var(--g7-text-4)}
.u-val{margin-left:auto;font-size:13px;font-weight:700;color:var(--g7-accent);flex-shrink:0}
.my-card{background:linear-gradient(135deg,rgba(var(--g7-accent-rgb),.05),rgba(139,92,246,.03));border:1px solid rgba(var(--g7-accent-rgb),.15);border-radius:12px;padding:16px 18px;margin-bottom:16px}
/* Antes onmouseover/onfocus en línea (CSP estricta, SEC-24) */
.sts-hrow:hover{background:var(--g7-bg-3)}
.sts-hop:hover{opacity:.75}
.tl-link:hover{border-color:var(--hov)!important}
#cl-input:focus{border-color:var(--g7-accent)!important}
.cl-del:hover{opacity:1!important;color:#ef4444!important}
</style>
</head>
<body>
<?php include __DIR__ . '/topbar.php'; ?>

<div class="dash">
  <!-- LEFT NAV -->
  <div class="stats-nav">
    <div class="nav-head">Estadísticas</div>
    <?php foreach($navSections as $key=>$nav): ?>
    <a href="?section=<?= $key ?>&period=<?= $period ?>" class="nav-link <?= $section===$key?'active':'' ?>">
      <i class="<?= str_starts_with($nav['icon'], 'fa-brands') ? $nav['icon'] : 'fa-solid ' . $nav['icon'] ?>"></i><?= $nav['label'] ?>
    </a>
    <?php endforeach; ?>
    <?php if ($section !== 'jira'): // Jira tiene su propio rango de fechas ?>
    <div class="period-group">
      <div class="nav-head" style="padding-top:0">Período</div>
      <?php foreach($periodMap as $k=>$v): ?>
      <a href="?section=<?= $section ?>&period=<?= $k ?>" class="period-btn <?= $period===$k?'active':'' ?>">
        <?= $v ?><?php if($period===$k): ?><i class="fa-solid fa-check" style="font-size:9px"></i><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- MAIN -->
  <div class="stats-main">

  <?php if($section==='overview'): ?>
    <div class="page-header"><div class="page-title">Resumen <span>general</span></div><div class="page-sub"><?= $periodMap[$period] ?> &middot; todos los módulos</div></div>
    <div class="my-card">
      <div class="card-title"><i class="fa-solid fa-circle-user"></i> Tu actividad &mdash; <?= htmlspecialchars($me['display_name']?:$currentUser['username']) ?></div>
      <div class="metrics" style="margin-bottom:0">
        <div class="mc"><div class="mc-top"><div class="mc-icon" style="background:rgba(var(--g7-accent-rgb),.1)"><i class="fa-solid fa-computer-mouse" style="color:var(--g7-accent)"></i></div></div><div class="mc-val" style="color:var(--g7-accent)"><?= number_format($myClicks) ?></div><div class="mc-label">Clicks míos</div></div>
        <div class="mc"><div class="mc-top"><div class="mc-icon" style="background:rgba(239,68,68,.1)"><i class="fa-solid fa-heart" style="color:#ef4444"></i></div></div><div class="mc-val"><?= $myFavCount ?></div><div class="mc-label">Favoritos</div></div>
        <div class="mc"><div class="mc-top"><div class="mc-icon" style="background:rgba(96,165,250,.1)"><i class="fa-solid fa-note-sticky" style="color:#60a5fa"></i></div></div><div class="mc-val"><?= $myNoteCount ?></div><div class="mc-label">Notas</div></div>
        <div class="mc"><div class="mc-top"><div class="mc-icon" style="background:rgba(74,222,128,.1)"><i class="fa-solid fa-clock" style="color:#4ade80"></i></div></div><div class="mc-val" style="font-size:18px"><?= fmtTime($me['total_time_sec']) ?></div><div class="mc-label">Tiempo conectado</div></div>
        <div class="mc"><div class="mc-top"><div class="mc-icon" style="background:rgba(167,139,250,.1)"><i class="fa-solid fa-right-to-bracket" style="color:#a78bfa"></i></div></div><div class="mc-val" style="font-size:14px"><?= fmtDate($me['last_login']) ?></div><div class="mc-label">Último login</div></div>
      </div>
    </div>
    <div class="metrics">
      <div class="mc" style="border-top:2px solid var(--g7-accent)"><div class="mc-top"><div class="mc-icon" style="background:rgba(var(--g7-accent-rgb),.1)"><i class="fa-solid fa-arrow-pointer" style="color:var(--g7-accent)"></i></div></div><div class="mc-val" style="color:var(--g7-accent)"><?= number_format($totalClicks) ?></div><div class="mc-label">Clicks totales</div><div class="mc-sub"><?= $periodMap[$period] ?></div></div>
      <div class="mc" style="border-top:2px solid #60a5fa"><div class="mc-top"><div class="mc-icon" style="background:rgba(96,165,250,.1)"><i class="fa-solid fa-link" style="color:#60a5fa"></i></div></div><div class="mc-val" style="color:#60a5fa"><?= $totalLinks ?></div><div class="mc-label">Recursos</div><div class="mc-sub"><?= $totalCats ?> categorías</div></div>
      <div class="mc" style="border-top:2px solid #a78bfa"><div class="mc-top"><div class="mc-icon" style="background:rgba(167,139,250,.1)"><i class="fa-solid fa-layer-group" style="color:#a78bfa"></i></div></div><div class="mc-val" style="color:#a78bfa"><?= $totalContent ?></div><div class="mc-label">Contenido CMS</div><div class="mc-sub"><?= $activePromos ?> activos</div></div>
      <div class="mc" style="border-top:2px solid #4ade80"><div class="mc-top"><div class="mc-icon" style="background:rgba(74,222,128,.1)"><i class="fa-solid fa-rss" style="color:#4ade80"></i></div></div><div class="mc-val" style="color:#4ade80"><?= $totalPosts ?></div><div class="mc-label">Posts comunidad</div></div>
      <div class="mc" style="border-top:2px solid #f59e0b"><div class="mc-top"><div class="mc-icon" style="background:rgba(245,158,11,.1)"><i class="fa-solid fa-users" style="color:#f59e0b"></i></div></div><div class="mc-val" style="color:#f59e0b"><?= $totalUsers ?></div><div class="mc-label">Usuarios activos</div></div>
      <div class="mc" style="border-top:2px solid #ec4899"><div class="mc-top"><div class="mc-icon" style="background:rgba(236,72,153,.1)"><i class="fa-solid fa-heart" style="color:#ec4899"></i></div></div><div class="mc-val" style="color:#ec4899"><?= $totalFavs ?></div><div class="mc-label">Favoritos totales</div></div>
      <div class="mc" style="border-top:2px solid #34d399"><div class="mc-top"><div class="mc-icon" style="background:rgba(52,211,153,.1)"><i class="fa-solid fa-rocket" style="color:#34d399"></i></div></div><div class="mc-val" style="color:#34d399"><?= number_format($landingTotalViews) ?></div><div class="mc-label">Vistas landings</div><div class="mc-sub"><?= $landingTotalPublished ?> publicadas</div></div>
    </div>
    <div class="grid-2">
      <div class="card"><div class="card-title"><i class="fa-solid fa-chart-bar"></i> Clicks por día</div><div class="chart-wrap"><canvas id="chartDays"></canvas></div></div>
      <div class="card"><div class="card-title"><i class="fa-solid fa-clock"></i> Horas pico</div><div class="chart-wrap"><canvas id="chartHours"></canvas></div></div>
    </div>
    <div class="grid-2">
      <div class="card">
        <div class="card-title"><i class="fa-solid fa-trophy"></i> Top enlaces</div>
        <?php if($topLinks): $mx=max(array_column($topLinks,'clicks')); foreach($topLinks as $i=>$r): ?>
        <div style="margin-bottom:8px">
          <div style="display:flex;align-items:center;gap:7px;margin-bottom:4px">
            <span class="rank"><?= $i+1 ?></span>
            <a href="<?= htmlspecialchars($r['url']) ?>" target="_blank" style="font-size:12px;font-weight:600;color:var(--g7-text);text-decoration:none;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($r['name']) ?></a>
            <span class="bar-val"><?= $r['clicks'] ?></span>
          </div>
          <div class="bar-bg"><div class="bar-fill" style="width:<?= round($r['clicks']/$mx*100) ?>%"></div></div>
        </div>
        <?php endforeach; else: ?><p style="color:var(--g7-text-5);font-size:12px">Sin datos.</p><?php endif; ?>
      </div>
      <div class="card">
        <div class="card-title"><i class="fa-solid fa-folder"></i> Por categoría</div>
        <?php if($clicksByCat): $mx=max(array_column($clicksByCat,'clicks')); foreach($clicksByCat as $r): ?>
        <div style="margin-bottom:8px">
          <div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="font-size:12px;font-weight:600"><?= htmlspecialchars($r['name']) ?></span><span class="bar-val"><?= $r['clicks'] ?></span></div>
          <div class="bar-bg"><div class="bar-fill" style="width:<?= round($r['clicks']/$mx*100) ?>%"></div></div>
        </div>
        <?php endforeach; else: ?><p style="color:var(--g7-text-5);font-size:12px">Sin datos.</p><?php endif; ?>
      </div>
    </div>

  <?php elseif($section==='library'): ?>
    <div class="page-header"><div class="page-title">Biblioteca <span>Stats</span></div><div class="page-sub"><?= $periodMap[$period] ?></div></div>
    <div class="metrics">
      <div class="mc" style="border-top:2px solid var(--g7-accent)"><div class="mc-top"><div class="mc-icon" style="background:rgba(var(--g7-accent-rgb),.1)"><i class="fa-solid fa-arrow-pointer" style="color:var(--g7-accent)"></i></div></div><div class="mc-val" style="color:var(--g7-accent)"><?= number_format($totalClicks) ?></div><div class="mc-label">Clicks totales</div></div>
      <div class="mc" style="border-top:2px solid #60a5fa"><div class="mc-top"><div class="mc-icon" style="background:rgba(96,165,250,.1)"><i class="fa-solid fa-link" style="color:#60a5fa"></i></div></div><div class="mc-val" style="color:#60a5fa"><?= $totalLinks ?></div><div class="mc-label">Recursos</div></div>
      <div class="mc" style="border-top:2px solid #a78bfa"><div class="mc-top"><div class="mc-icon" style="background:rgba(167,139,250,.1)"><i class="fa-solid fa-folder" style="color:#a78bfa"></i></div></div><div class="mc-val" style="color:#a78bfa"><?= $totalCats ?></div><div class="mc-label">Categorías</div></div>
      <div class="mc" style="border-top:2px solid #ec4899"><div class="mc-top"><div class="mc-icon" style="background:rgba(236,72,153,.1)"><i class="fa-solid fa-heart" style="color:#ec4899"></i></div></div><div class="mc-val" style="color:#ec4899"><?= $totalFavs ?></div><div class="mc-label">Favoritos</div></div>
      <div class="mc"><div class="mc-top"><div class="mc-icon" style="background:rgba(var(--g7-accent-rgb),.1)"><i class="fa-solid fa-computer-mouse" style="color:var(--g7-accent)"></i></div></div><div class="mc-val"><?= number_format($myClicks) ?></div><div class="mc-label">Mis clicks</div></div>
    </div>
    <div class="card"><div class="card-title"><i class="fa-solid fa-chart-line"></i> Clicks por día</div><div class="chart-wrap tall"><canvas id="chartDays"></canvas></div></div>
    <div class="grid-2">
      <div class="card"><div class="card-title"><i class="fa-solid fa-clock"></i> Horas pico</div><div class="chart-wrap"><canvas id="chartHours"></canvas></div></div>
      <div class="card"><div class="card-title"><i class="fa-solid fa-calendar-week"></i> Días de la semana</div><div class="chart-wrap"><canvas id="chartDow"></canvas></div></div>
    </div>
    <div class="grid-2">
      <div class="card">
        <div class="card-title"><i class="fa-solid fa-trophy"></i> Top 10 enlaces</div>
        <?php if($topLinks): $mx=max(array_column($topLinks,'clicks')); foreach($topLinks as $i=>$r): ?>
        <div style="margin-bottom:9px">
          <div style="display:flex;align-items:center;gap:7px;margin-bottom:4px">
            <span class="rank"><?= $i+1 ?></span>
            <a href="<?= htmlspecialchars($r['url']) ?>" target="_blank" style="font-size:12px;font-weight:600;color:var(--g7-text);text-decoration:none;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($r['name']) ?></a>
            <span class="bar-val"><?= $r['clicks'] ?></span>
          </div>
          <div class="bar-bg"><div class="bar-fill" style="width:<?= round($r['clicks']/$mx*100) ?>%"></div></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
      <div class="card">
        <div class="card-title"><i class="fa-solid fa-folder"></i> Por categoría</div>
        <?php if($clicksByCat): $mx=max(array_column($clicksByCat,'clicks')); foreach($clicksByCat as $r): ?>
        <div style="margin-bottom:9px">
          <div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="font-size:12px;font-weight:600"><?= htmlspecialchars($r['name']) ?></span><span class="bar-val"><?= $r['clicks'] ?></span></div>
          <div class="bar-bg"><div class="bar-fill" style="width:<?= round($r['clicks']/$mx*100) ?>%"></div></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <?php if($myTop): ?>
    <div class="my-card">
      <div class="card-title"><i class="fa-solid fa-star"></i> Mis recursos más usados</div>
      <?php $mx=max(array_column($myTop,'clicks')); foreach($myTop as $i=>$r): ?>
      <div style="margin-bottom:9px">
        <div style="display:flex;align-items:center;gap:7px;margin-bottom:4px">
          <span class="rank"><?= $i+1 ?></span>
          <a href="<?= htmlspecialchars($r['url']) ?>" target="_blank" style="font-size:12px;font-weight:600;color:var(--g7-text);text-decoration:none;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($r['name']) ?></a>
          <span class="bar-val"><?= $r['clicks'] ?></span>
        </div>
        <div class="bar-bg"><div class="bar-fill" style="width:<?= round($r['clicks']/$mx*100) ?>%"></div></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  <?php elseif($section==='community'): ?>
    <div class="page-header"><div class="page-title">Comunidad <span>Stats</span></div><div class="page-sub"><?= $periodMap[$period] ?></div></div>
    <div class="metrics">
      <div class="mc" style="border-top:2px solid #4ade80"><div class="mc-top"><div class="mc-icon" style="background:rgba(74,222,128,.1)"><i class="fa-solid fa-pen-nib" style="color:#4ade80"></i></div></div><div class="mc-val" style="color:#4ade80"><?= $totalPosts ?></div><div class="mc-label">Posts totales</div></div>
      <div class="mc" style="border-top:2px solid #ef4444"><div class="mc-top"><div class="mc-icon" style="background:rgba(239,68,68,.1)"><i class="fa-solid fa-heart" style="color:#ef4444"></i></div></div><div class="mc-val" style="color:#ef4444"><?= $totalReactions ?></div><div class="mc-label">Reacciones</div></div>
      <div class="mc" style="border-top:2px solid #60a5fa"><div class="mc-top"><div class="mc-icon" style="background:rgba(96,165,250,.1)"><i class="fa-solid fa-reply" style="color:#60a5fa"></i></div></div><div class="mc-val" style="color:#60a5fa"><?= $totalReplies ?></div><div class="mc-label">Respuestas</div></div>
      <div class="mc" style="border-top:2px solid #a78bfa"><div class="mc-top"><div class="mc-icon" style="background:rgba(167,139,250,.1)"><i class="fa-solid fa-bolt" style="color:#a78bfa"></i></div></div><div class="mc-val" style="color:#a78bfa"><?= $totalActivity ?></div><div class="mc-label">Actividad</div></div>
      <div class="mc" style="border-top:2px solid #f59e0b"><div class="mc-top"><div class="mc-icon" style="background:rgba(245,158,11,.1)"><i class="fa-solid fa-comments" style="color:#f59e0b"></i></div></div><div class="mc-val" style="color:#f59e0b"><?= $totalComments ?></div><div class="mc-label">Comentarios</div></div>
    </div>
    <div class="grid-2">
      <div class="card"><div class="card-title"><i class="fa-solid fa-chart-line"></i> Posts por día</div><div class="chart-wrap"><canvas id="chartPosts"></canvas></div></div>
      <div class="card"><div class="card-title"><i class="fa-solid fa-bolt"></i> Tipos de actividad</div><div class="chart-wrap"><canvas id="chartActivity"></canvas></div></div>
    </div>
    <div class="grid-2">
      <div class="card">
        <div class="card-title"><i class="fa-solid fa-pen-nib"></i> Top publicadores</div>
        <?php foreach($topPosters as $r): ?>
        <div class="user-row">
          <?= avSmall($r['avatar']??'',$r['display_name']?:$r['username']) ?>
          <div><div class="u-name"><?= htmlspecialchars($r['display_name']?:$r['username']) ?></div></div>
          <div class="u-val"><?= $r['cnt'] ?> posts</div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="card">
        <div class="card-title"><i class="fa-solid fa-fire"></i> Posts más reaccionados</div>
        <?php foreach($topReacted as $r): ?>
        <div style="padding:8px 0;border-bottom:1px solid var(--g7-border-2)">
          <div style="font-size:11px;color:var(--g7-text-2);margin-bottom:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars(mb_substr($r['body'],0,60)) ?></div>
          <div style="font-size:10px;color:var(--g7-text-4);display:flex;gap:8px">
            <span><?= htmlspecialchars($r['display_name']?:$r['username']) ?></span>
            <span style="color:#ef4444;font-weight:700">&#10084; <?= $r['reactions'] ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if($isAdmin && $activityByUser): $mx=max(array_column($activityByUser,'cnt')); ?>
    <div class="card">
      <div class="card-title"><i class="fa-solid fa-users"></i> Actividad por usuario</div>
      <?php foreach($activityByUser as $r): ?>
      <div class="user-row">
        <?= avSmall($r['avatar']??'',$r['display_name']?:$r['username']) ?>
        <div style="flex:1;min-width:0"><div class="u-name"><?= htmlspecialchars($r['display_name']?:$r['username']) ?></div></div>
        <div style="flex:2;padding:0 12px"><div class="bar-bg"><div class="bar-fill" style="width:<?= round($r['cnt']/$mx*100) ?>%"></div></div></div>
        <div class="u-val"><?= $r['cnt'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  <?php elseif($section==='content'): ?>
    <div class="page-header"><div class="page-title">Contenido <span>CMS</span></div><div class="page-sub"><?= $periodMap[$period] ?></div></div>
    <div class="metrics">
      <div class="mc" style="border-top:2px solid #a78bfa"><div class="mc-top"><div class="mc-icon" style="background:rgba(167,139,250,.1)"><i class="fa-solid fa-layer-group" style="color:#a78bfa"></i></div></div><div class="mc-val" style="color:#a78bfa"><?= $totalContent ?></div><div class="mc-label">Total</div></div>
      <div class="mc" style="border-top:2px solid #4ade80"><div class="mc-top"><div class="mc-icon" style="background:rgba(74,222,128,.1)"><i class="fa-solid fa-check-circle" style="color:#4ade80"></i></div></div><div class="mc-val" style="color:#4ade80"><?= $activePromos ?></div><div class="mc-label">Activos</div></div>
      <div class="mc" style="border-top:2px solid #f59e0b"><div class="mc-top"><div class="mc-icon" style="background:rgba(245,158,11,.1)"><i class="fa-solid fa-calendar-xmark" style="color:#f59e0b"></i></div></div><div class="mc-val" style="color:#f59e0b"><?= $expiring ?></div><div class="mc-label">Vencen esta semana</div></div>
    </div>
    <div class="grid-2">
      <div class="card"><div class="card-title"><i class="fa-solid fa-chart-line"></i> Creado por día</div><div class="chart-wrap"><canvas id="chartContentDay"></canvas></div></div>
      <div class="card"><div class="card-title"><i class="fa-solid fa-chart-pie"></i> Por tipo</div><div class="chart-wrap"><canvas id="chartContentType"></canvas></div></div>
    </div>
    <div class="grid-2">
      <div class="card">
        <div class="card-title"><i class="fa-solid fa-user-pen"></i> Por autor</div>
        <?php if($contentByUser): $mx=max(array_column($contentByUser,'cnt')); foreach($contentByUser as $r): ?>
        <div style="margin-bottom:9px">
          <div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="font-size:12px;font-weight:600"><?= htmlspecialchars($r['author']) ?></span><span class="bar-val"><?= $r['cnt'] ?></span></div>
          <div class="bar-bg"><div class="bar-fill" style="width:<?= round($r['cnt']/$mx*100) ?>%"></div></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
      <div class="card">
        <div class="card-title"><i class="fa-solid fa-clock"></i> Reciente</div>
        <?php foreach($recentContent as $r): $col=$TYPE_COLORS[$r['type']]??'#52525b'; ?>
        <div style="display:flex;align-items:center;gap:9px;padding:7px 0;border-bottom:1px solid var(--g7-border-2)">
          <div style="width:7px;height:7px;border-radius:50%;background:<?= $col ?>;flex-shrink:0"></div>
          <div style="flex:1;min-width:0"><div style="font-size:11px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($r['name']) ?></div><div style="font-size:10px;color:var(--g7-text-4)"><?= htmlspecialchars($r['author']) ?> &middot; <?= fmtDate($r['created_at']) ?></div></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  <?php elseif($section==='users' && $isAdmin): ?>
    <div class="page-header"><div class="page-title">Usuarios <span>Stats</span></div><div class="page-sub"><?= $periodMap[$period] ?></div></div>
    <div class="card" style="padding:0;overflow:hidden">
      <table>
        <thead><tr><th>Usuario</th><th>Último login</th><th>Tiempo</th><th>Clicks</th><th>Favoritos</th><th>Posts</th></tr></thead>
        <tbody>
          <?php foreach($usersStats as $u): ?>
          <tr>
            <td><div style="display:flex;align-items:center;gap:8px"><?= avSmall($u['avatar']??'',$u['display_name']?:$u['username'],26) ?><div><div style="font-weight:600"><?= htmlspecialchars($u['display_name']?:$u['username']) ?></div><span class="badge <?= $u['role']==='admin'?'badge-admin':'badge-user' ?>"><?= $u['role'] ?></span></div></div></td>
            <td style="color:var(--g7-text-3);font-size:11px"><?= fmtDate($u['last_login']) ?></td>
            <td style="font-weight:600"><?= fmtTime($u['total_time_sec']) ?></td>
            <td><span style="font-weight:700;color:var(--g7-accent)"><?= number_format($u['clicks']) ?></span></td>
            <td><span style="color:#ef4444"><?= $u['favs'] ?></span></td>
            <td><span style="color:#4ade80"><?= $u['posts'] ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if($usersStats): $maxClicks=max(array_column($usersStats,'clicks')?:[1]); $maxTime=max(array_column($usersStats,'total_time_sec')?:[1]); ?>
    <div class="grid-2">
      <div class="card">
        <div class="card-title"><i class="fa-solid fa-trophy"></i> Clicks por usuario</div>
        <?php foreach($usersStats as $u): if(!$u['clicks'])continue; ?>
        <div style="margin-bottom:9px">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px"><?= avSmall($u['avatar']??'',$u['display_name']?:$u['username'],20) ?><span style="font-size:12px;font-weight:600;flex:1"><?= htmlspecialchars($u['display_name']?:$u['username']) ?></span><span class="bar-val"><?= $u['clicks'] ?></span></div>
          <div class="bar-bg"><div class="bar-fill" style="width:<?= round($u['clicks']/$maxClicks*100) ?>%"></div></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="card">
        <div class="card-title"><i class="fa-solid fa-clock"></i> Tiempo conectado</div>
        <?php foreach($usersStats as $u): if(!$u['total_time_sec'])continue; ?>
        <div style="margin-bottom:9px">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px"><?= avSmall($u['avatar']??'',$u['display_name']?:$u['username'],20) ?><span style="font-size:12px;font-weight:600;flex:1"><?= htmlspecialchars($u['display_name']?:$u['username']) ?></span><span style="font-size:11px;font-weight:700;color:#60a5fa"><?= fmtTime($u['total_time_sec']) ?></span></div>
          <div class="bar-bg"><div class="bar-fill" style="width:<?= round($u['total_time_sec']/$maxTime*100) ?>%;background:linear-gradient(90deg,#60a5fa,#818cf8)"></div></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  
  <?php elseif($section==='produccion'): ?>
    <?php
    // ── FILTROS ──────────────────────────────────────────────
    $pMes     = $_GET['mes']     ?? date('Y-m');
    $pProyect = $_GET['proyecto'] ?? '';

    // Mes anterior y siguiente para nav
    $mesDt   = DateTime::createFromFormat('Y-m', $pMes);
    $prevMes = (clone $mesDt)->modify('-1 month')->format('Y-m');
    $nextMes = (clone $mesDt)->modify('+1 month')->format('Y-m');
    $mesLabel = strftime('%B %Y', $mesDt->getTimestamp()) ?: $pMes;

    // ── Producción del mes (cálculo compartido con el reporte: stats_produccion.php) ──
    // Proyectos unificados, reparto multiplataforma, y revisiones/rechazos en el mes en que se registraron.
    require_once __DIR__ . '/stats_produccion.php';
    if ($pProyect !== '') $pProyect = g7_prod_canon($pProyect);
    $prodMes = g7_prod_mes($db, $mesDt, $pProyect);
    ['data' => $data, 'tot' => $tot, 'proyectos' => $proyectos, 'multiCount' => $multiCount] = $prodMes;

    // Cantidades: enteras si dan justo, con un decimal si el reparto deja fracción
    $fq = fn($n) => abs($n - round($n)) < 0.05 ? number_format(round($n), 0, ',', '.') : number_format($n, 1, ',', '.');

    // Landings creadas en el mes
    $landingsProdStmt = $db->prepare("SELECT id,name,platform,created_by,created_at,published_at FROM g7_landings WHERE YEAR(created_at)=? AND MONTH(created_at)=? ORDER BY created_at DESC");
    $landingsProdStmt->execute([$mesDt->format('Y'), $mesDt->format('n')]);
    $landingsProd = $landingsProdStmt->fetchAll();
    $landingsProdPub = count(array_filter($landingsProd, fn($l)=>!empty($l['published_at'])));

    // Landings agrupadas por plataforma para columna en tabla de proyectos
    $landingsByPlatStmt = $db->prepare("SELECT platform, COUNT(*) as cnt FROM g7_landings WHERE YEAR(created_at)=? AND MONTH(created_at)=? GROUP BY platform");
    $landingsByPlatStmt->execute([$mesDt->format('Y'), $mesDt->format('n')]);
    $landingsByPlat = [];
    foreach($landingsByPlatStmt->fetchAll() as $lbp) {
        $landingsByPlat[strtolower(trim($lbp['platform']))] = (int)$lbp['cnt'];
    }
    // Función para buscar landings de un proyecto (match flexible)
    $getLandingsForProj = function(string $proj) use ($landingsByPlat): int {
        $key = strtolower(trim($proj));
        if(isset($landingsByPlat[$key])) return $landingsByPlat[$key];
        foreach($landingsByPlat as $plat => $cnt) {
            if(str_contains($key, $plat) || str_contains($plat, $key)) return $cnt;
        }
        return 0;
    };
    ?>

    <div class="page-header">
      <div class="page-title">Pro<span>ducción</span></div>
      <div class="page-sub">Reporte mensual de assets por proyecto<?php if (!empty($multiCount)): ?> · <?= (int)$multiCount ?> contenido<?= $multiCount === 1 ? '' : 's' ?> multiplataforma repartido<?= $multiCount === 1 ? '' : 's' ?> en partes iguales<?php endif; ?></div>
    </div>

    <!-- FILTROS -->
    <div class="card" style="margin-bottom:16px">
      <form method="GET" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <input type="hidden" name="section" value="produccion">
        <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">

        <!-- Navegación de mes -->
        <div style="display:flex;align-items:center;gap:6px">
          <a href="?section=produccion&mes=<?= $prevMes ?>&proyecto=<?= urlencode($pProyect) ?>" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-3);border-radius:7px;padding:6px 10px;text-decoration:none;font-size:12px;display:flex;align-items:center;gap:4px">‹</a>
          <input type="month" name="mes" value="<?= htmlspecialchars($pMes) ?>" data-on-change="stsSubmit" data-change-1="this" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:7px;padding:6px 10px;font-size:12px;outline:none;cursor:pointer">
          <a href="?section=produccion&mes=<?= $nextMes ?>&proyecto=<?= urlencode($pProyect) ?>" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-3);border-radius:7px;padding:6px 10px;text-decoration:none;font-size:12px">›</a>
        </div>

        <!-- Filtro proyecto -->
        <select name="proyecto" data-on-change="stsSubmit" data-change-1="this" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:7px;padding:6px 10px;font-size:12px;outline:none">
          <option value="">Todos los proyectos</option>
          <?php foreach($proyectos as $p): ?>
          <option value="<?= htmlspecialchars($p) ?>" <?= $pProyect===$p?'selected':'' ?>><?= htmlspecialchars($p) ?></option>
          <?php endforeach; ?>
        </select>

        <!-- Export -->
        <a href="stats_reporte.php?mes=<?= urlencode($pMes) ?>&proyecto=<?= urlencode($pProyect) ?>" target="_blank" rel="noopener" style="margin-left:auto;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:7px;padding:7px 14px;font-size:12px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px" title="Reporte del mes para imprimir o guardar como PDF">
          <i class="fa-solid fa-file-lines"></i> Reporte del mes
        </a>
        <button type="button" data-on-click="exportProduccionCSV" style="background:var(--g7-accent);border:none;color:#000;border-radius:7px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:5px">
          <i class="fa-solid fa-download"></i> Exportar CSV
        </button>
      </form>
    </div>

    <!-- KPI CARDS -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:16px">
      <?php
      $kpis = [
        ['label'=>'Total Assets','value'=>(int)round($tot['total_assets']??0),'icon'=>'fa-images','color'=>'var(--g7-accent)'],
        ['label'=>'Gráficas','value'=>(int)round($tot['graficas']??0),'icon'=>'fa-image','color'=>'#60a5fa'],
        ['label'=>'Videos','value'=>(int)round($tot['videos']??0),'icon'=>'fa-film','color'=>'#a78bfa'],
        ['label'=>'Revisiones + Rechazos','value'=>(int)round(($tot['revisiones']??0)+($tot['rechazos']??0)),'icon'=>'fa-rotate','color'=>'#f59e0b'],
        ['label'=>'Landings creadas','value'=>count($landingsProd),'icon'=>'fa-rocket','color'=>'#34d399','sub'=>$landingsProdPub.' publicadas'],
      ];
      foreach($kpis as $kpi): ?>
      <div class="card" style="padding:14px 16px">
        <div style="display:flex;align-items:center;gap:10px">
          <div style="width:38px;height:38px;border-radius:9px;background:<?= $kpi['color'] ?>20;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fa-solid <?= $kpi['icon'] ?>" style="color:<?= $kpi['color'] ?>;font-size:16px"></i>
          </div>
          <div>
            <div style="font-size:22px;font-weight:800;line-height:1"><?= number_format($kpi['value']) ?></div>
            <div style="font-size:10px;color:var(--g7-text-4);margin-top:2px"><?= $kpi['label'] ?></div>
            <?php if(!empty($kpi['sub'])): ?><div style="font-size:9px;color:var(--g7-text-5);margin-top:2px"><?= $kpi['sub'] ?></div><?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- TABLA POR PROYECTO -->
    <div class="card" id="prod-table-wrap">
      <?php if(empty($data)): ?>
      <div style="text-align:center;padding:32px;color:var(--g7-text-5)">
        <i class="fa-solid fa-chart-bar" style="font-size:28px;display:block;margin-bottom:10px;opacity:.2"></i>
        Sin datos para este período<?= $pProyect ? ' y proyecto' : '' ?>
      </div>
      <?php else: ?>
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:12px" id="prod-table">
          <thead>
            <tr style="border-bottom:2px solid var(--g7-border)">
              <th style="text-align:left;padding:10px 12px;font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;white-space:nowrap">Proyecto</th>
              <th style="text-align:center;padding:10px 8px;font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em">Gráficas</th>
              <th style="text-align:center;padding:10px 8px;font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em">Videos</th>
              <th style="text-align:center;padding:10px 8px;font-size:10px;font-weight:700;color:var(--g7-accent);text-transform:uppercase;letter-spacing:.07em">Total</th>
              <th style="text-align:center;padding:10px 8px;font-size:10px;font-weight:700;color:#60a5fa;text-transform:uppercase;letter-spacing:.07em">Tareas</th>
              <th style="text-align:center;padding:10px 8px;font-size:10px;font-weight:700;color:#a78bfa;text-transform:uppercase;letter-spacing:.07em">Mailings</th>
              <th style="text-align:center;padding:10px 8px;font-size:10px;font-weight:700;color:#34d399;text-transform:uppercase;letter-spacing:.07em">Landings</th>
              <th style="text-align:center;padding:10px 8px;font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em">Revisiones</th>
              <th style="text-align:center;padding:10px 8px;font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em">Rechazos</th>
              <th style="text-align:center;padding:10px 8px;font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em">% Rechazo</th>
              <th style="text-align:center;padding:10px 8px;font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em">Días prom.</th>
              <th style="text-align:center;padding:10px 8px;font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em">SLA</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($data as $row):
            $total   = (float)($row['total_assets'] ?? 0);
            $rechazo = (float)($row['rechazos'] ?? 0);
            $rowCont = (int)($row['total_contenidos'] ?? 0);
            $rechPct = $rowCont > 0 ? round($rechazo / $rowCont * 100, 1) : 0;
            $slaOk   = (int)($row['sla_ok'] ?? 0);
            $slaTot  = (int)($row['sla_total'] ?? 0);
            $slaPct  = $slaTot > 0 ? round($slaOk / $slaTot * 100) : null;
            $avgDias = $row['avg_dias'] ? round($row['avg_dias'], 1) : null;
            $proj    = $row['project'] ?: 'Sin proyecto';
          ?>
          <tr class="sts-hrow" style="border-bottom:1px solid var(--g7-border);transition:background .1s">
            <td style="padding:10px 12px;font-weight:600">
              <a href="?section=produccion&mes=<?= htmlspecialchars($pMes) ?>&proyecto=<?= urlencode($row['project']) ?>" style="color:var(--g7-text);text-decoration:none">
                <?= htmlspecialchars($proj) ?>
              </a><?php if (!empty($row['multi'])): ?> <span title="Incluye contenidos multiplataforma repartidos en partes iguales" style="font-size:10px;font-weight:600;color:var(--g7-text-4);background:var(--g7-bg-4);border-radius:5px;padding:1px 6px;margin-left:4px"><?= (int)$row['multi'] ?> compartido<?= (int)$row['multi'] === 1 ? '' : 's' ?></span><?php endif; ?>
            </td>
            <td style="text-align:center;padding:10px 8px"><?= $fq((float)($row['graficas']??0)) ?></td>
            <td style="text-align:center;padding:10px 8px"><?= $fq((float)($row['videos']??0)) ?></td>
            <td style="text-align:center;padding:10px 8px;font-weight:800;color:var(--g7-accent)"><?= $fq($total) ?></td>
            <td style="text-align:center;padding:10px 8px;color:<?= (int)($row['tareas']??0)>0?'#60a5fa':'var(--g7-text-4)' ?>"><?= $fq((float)($row['tareas']??0)) ?></td>
            <td style="text-align:center;padding:10px 8px;color:<?= (int)($row['qty_mailings_total']??0)>0?'#a78bfa':'var(--g7-text-4)' ?>"><?= $fq((float)($row['qty_mailings_total']??0)) ?></td>
            <?php $lCount = $getLandingsForProj($row['project']??''); ?>
            <td style="text-align:center;padding:10px 8px;color:<?= $lCount>0?'#34d399':'var(--g7-text-4)' ?>"><?= $lCount > 0 ? $lCount : '—' ?></td>
            <td style="text-align:center;padding:10px 8px;color:<?= (int)($row['revisiones']??0)>0?'#f59e0b':'var(--g7-text-4)' ?>"><?= $fq((float)($row['revisiones']??0)) ?></td>
            <td style="text-align:center;padding:10px 8px;color:<?= $rechazo>0?'#ef4444':'var(--g7-text-4)' ?>"><?= $fq($rechazo) ?></td>
            <td style="text-align:center;padding:10px 8px">
              <?php if($rowCont > 0): ?>
              <span style="padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;background:<?= $rechPct>20?'rgba(239,68,68,.1)':($rechPct>10?'rgba(245,158,11,.1)':'rgba(34,197,94,.1)') ?>;color:<?= $rechPct>20?'#ef4444':($rechPct>10?'#f59e0b':'#22c55e') ?>"><?= $rechPct ?>%</span>
              <?php else: ?><span style="color:var(--g7-text-5)">—</span><?php endif; ?>
            </td>
            <td style="text-align:center;padding:10px 8px;color:var(--g7-text-3)"><?= $avgDias !== null ? $avgDias.'d' : '—' ?></td>
            <td style="text-align:center;padding:10px 8px">
              <?php if($slaPct !== null): ?>
              <span style="padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;background:<?= $slaPct>=80?'rgba(34,197,94,.1)':($slaPct>=60?'rgba(245,158,11,.1)':'rgba(239,68,68,.1)') ?>;color:<?= $slaPct>=80?'#22c55e':($slaPct>=60?'#f59e0b':'#ef4444') ?>"><?= $slaPct ?>%</span>
              <?php else: ?><span style="color:var(--g7-text-5)">—</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <!-- TOTALES -->
          <tfoot>
            <tr style="border-top:2px solid var(--g7-border);font-weight:700;background:var(--g7-bg-2)">
              <td style="padding:10px 12px;font-size:11px;color:var(--g7-text-3)">TOTAL</td>
              <td style="text-align:center;padding:10px 8px"><?= $fq(array_sum(array_column($data,'graficas'))) ?></td>
              <td style="text-align:center;padding:10px 8px"><?= $fq(array_sum(array_column($data,'videos'))) ?></td>
              <td style="text-align:center;padding:10px 8px;color:var(--g7-accent)"><?= $fq((float)($tot['total_assets']??0)) ?></td>
              <td style="text-align:center;padding:10px 8px;color:#60a5fa"><?= $fq(array_sum(array_column($data,'tareas'))) ?></td>
              <td style="text-align:center;padding:10px 8px;color:#a78bfa"><?= $fq(array_sum(array_column($data,'qty_mailings_total'))) ?></td>
              <td style="text-align:center;padding:10px 8px;color:#34d399;font-weight:800"><?= count($landingsProd) > 0 ? count($landingsProd) : '—' ?></td>
              <td style="text-align:center;padding:10px 8px;color:#f59e0b"><?= $fq((float)($tot['revisiones']??0)) ?></td>
              <td style="text-align:center;padding:10px 8px;color:#ef4444"><?= $fq((float)($tot['rechazos']??0)) ?></td>
              <td style="text-align:center;padding:10px 8px">
                <?php $totCont=(int)($tot['total_contenidos']??0); $totRech=(float)($tot['rechazos']??0); ?>
                <?= $totCont>0 ? round($totRech/$totCont*100,1).'%' : '—' ?>
              </td>
              <td style="text-align:center;padding:10px 8px"><?= $tot['avg_dias'] ? round($tot['avg_dias'],1).'d' : '—' ?></td>
              <td style="text-align:center;padding:10px 8px">—</td>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- REVISIONES, RECHAZOS Y COMPLICACIONES DEL MES -->
    <?php
      $pm = $prodMes;
      $bars = function (array $counts, array $labels, string $color, string $empty = 'Sin datos este mes') {
          if (!$counts) return '<div style="font-size:12px;color:var(--g7-text-4);padding:6px 0">' . $empty . '</div>';
          $max = max($counts); $h = '';
          foreach ($counts as $k => $n) {
              $w = $max ? round($n / $max * 100) : 0;
              $h .= '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:12px"><span style="flex:0 0 46%;color:var(--g7-text-2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' . htmlspecialchars($labels[$k] ?? $k) . '">' . htmlspecialchars($labels[$k] ?? $k) . '</span>'
                  . '<span style="flex:1;height:6px;border-radius:3px;background:var(--g7-bg-4);overflow:hidden"><span style="display:block;height:100%;width:' . $w . '%;background:' . $color . '"></span></span>'
                  . '<b style="width:22px;text-align:right;color:var(--g7-text)">' . (int)$n . '</b></div>';
          }
          return $h;
      };
      $nRev = count(array_filter($pm['ediciones'], fn($e) => $e['tipo'] === 'revision'));
      $nRech = count($pm['ediciones']) - $nRev;
    ?>
    <div class="card" style="margin-top:16px">
      <div class="card-title" style="margin-bottom:14px"><i class="fa-solid fa-rotate" style="color:#f59e0b"></i> Revisiones, rechazos y complicaciones del mes</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px">
        <div>
          <div style="font-size:10px;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:.07em;margin-bottom:9px">Cambios pedidos · <?= $nRev ?> revisi<?= $nRev === 1 ? 'ón' : 'ones' ?></div>
          <?= $bars($pm['motivos']['revision'], PR_EDIT_REASONS['revision'], '#f59e0b', $nRev ? 'Las revisiones de este mes no tienen motivos cargados' : 'Sin revisiones este mes') ?>
        </div>
        <div>
          <div style="font-size:10px;font-weight:700;color:#ef4444;text-transform:uppercase;letter-spacing:.07em;margin-bottom:9px">Motivos de rechazo · <?= $nRech ?> rechazo<?= $nRech === 1 ? '' : 's' ?></div>
          <?= $bars($pm['motivos']['rechazo'], PR_EDIT_REASONS['rechazo'], '#ef4444', $nRech ? 'Los rechazos de este mes no tienen motivos cargados' : 'Sin rechazos este mes') ?>
        </div>
        <div>
          <div style="font-size:10px;font-weight:700;color:#fbbf24;text-transform:uppercase;letter-spacing:.07em;margin-bottom:9px">Complicaciones · <?= (int)$pm['conComplicaciones'] ?> de <?= (int)$tot['total_contenidos'] ?> pedidos</div>
          <?= $bars($pm['complicaciones'], PR_COMPLICATIONS, '#fbbf24', 'Nadie marcó complicaciones este mes') ?>
        </div>
      </div>
      <?php if ($pm['ediciones']): ?>
      <div style="overflow-x:auto;margin-top:14px;border-top:1px solid var(--g7-border-2);padding-top:12px">
        <table style="width:100%;border-collapse:collapse;font-size:12px">
          <thead><tr style="color:var(--g7-text-4);font-size:10px;text-transform:uppercase;letter-spacing:.06em">
            <th style="text-align:left;padding:6px 8px">Fecha</th><th style="text-align:left;padding:6px 8px">Contenido</th><th style="text-align:left;padding:6px 8px">Proyecto</th>
            <th style="text-align:left;padding:6px 8px">Tipo</th><th style="text-align:left;padding:6px 8px">Motivos</th><th style="text-align:left;padding:6px 8px">Notas</th><th style="text-align:left;padding:6px 8px">Registró</th>
          </tr></thead>
          <tbody>
          <?php foreach ($pm['ediciones'] as $e): $rj = $e['tipo'] === 'rechazo'; ?>
            <tr style="border-top:1px solid var(--g7-border)">
              <td style="padding:7px 8px;white-space:nowrap;color:var(--g7-text-3)"><?= htmlspecialchars(date('d/m', strtotime($e['fecha']))) ?></td>
              <td style="padding:7px 8px;font-weight:600"><?= htmlspecialchars($e['name']) ?></td>
              <td style="padding:7px 8px;color:var(--g7-text-2)"><?= htmlspecialchars($e['project']) ?></td>
              <td style="padding:7px 8px;font-weight:700;color:<?= $rj ? '#ef4444' : '#f59e0b' ?>"><?= $rj ? 'Rechazo' : 'Revisión' ?></td>
              <td style="padding:7px 8px;color:var(--g7-text-2)"><?= $e['motivos'] ? htmlspecialchars(implode(', ', $e['motivos'])) : '<span style="color:var(--g7-text-5)">Sin motivo cargado</span>' ?></td>
              <td style="padding:7px 8px;color:var(--g7-text-3)"><?= $e['notas'] !== '' ? htmlspecialchars($e['notas']) : '—' ?></td>
              <td style="padding:7px 8px;color:var(--g7-text-3)"><?= htmlspecialchars((string)$e['registro']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- LANDINGS DEL MES -->
    <?php if(!empty($landingsProd)): ?>
    <div class="card" style="margin-top:16px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <div class="card-title" style="margin-bottom:0"><i class="fa-solid fa-rocket" style="color:#34d399"></i> Landings creadas este mes</div>
        <a href="/toolz/landing-gen/" style="font-size:11px;color:#34d399;text-decoration:none;font-weight:600">Ver todas →</a>
      </div>
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:12px">
          <thead>
            <tr style="border-bottom:2px solid var(--g7-border)">
              <th style="text-align:left;padding:8px 10px;font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em">Nombre</th>
              <th style="text-align:center;padding:8px 10px;font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em">Plataforma</th>
              <th style="text-align:center;padding:8px 10px;font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em">Creador</th>
              <th style="text-align:center;padding:8px 10px;font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em">Fecha</th>
              <th style="text-align:center;padding:8px 10px;font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em">Estado</th>
              <th style="text-align:center;padding:8px 10px;font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em">Stats</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($landingsProd as $lp):
            $isPub = !empty($lp['published_at']);
            $fechaC = date('d/m H:i', strtotime($lp['created_at']));
          ?>
          <tr class="sts-hrow" style="border-bottom:1px solid var(--g7-border)">
            <td style="padding:9px 10px;font-weight:600"><?= htmlspecialchars($lp['name']) ?></td>
            <td style="text-align:center;padding:9px 10px">
              <span style="background:rgba(var(--g7-accent-rgb),.1);color:var(--g7-accent);border-radius:4px;padding:2px 8px;font-size:10px;font-weight:700"><?= htmlspecialchars($lp['platform']) ?></span>
            </td>
            <td style="text-align:center;padding:9px 10px;color:var(--g7-text-3)"><?= htmlspecialchars($lp['created_by']) ?></td>
            <td style="text-align:center;padding:9px 10px;color:var(--g7-text-4)"><?= $fechaC ?></td>
            <td style="text-align:center;padding:9px 10px">
              <?php if($isPub): ?>
              <span style="background:rgba(34,197,94,.1);color:#22c55e;border:1px solid rgba(34,197,94,.2);border-radius:20px;padding:2px 8px;font-size:10px;font-weight:700">
                <i class="fa-solid fa-circle" style="font-size:4px"></i> Publicada
              </span>
              <?php else: ?>
              <span style="background:rgba(var(--g7-ov),.04);color:var(--g7-text-4);border:1px solid var(--g7-border);border-radius:20px;padding:2px 8px;font-size:10px;font-weight:700">Borrador</span>
              <?php endif; ?>
            </td>
            <td style="text-align:center;padding:9px 10px">
              <?php if($isPub): ?>
              <a href="/toolz/landing-gen/stats.php?id=<?= $lp['id'] ?>" style="color:#a78bfa;font-size:12px" title="Ver estadísticas"><i class="fa-solid fa-chart-mixed"></i></a>
              <?php else: ?>
              <span style="color:var(--g7-text-5)">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="border-top:2px solid var(--g7-border);font-weight:700;background:var(--g7-bg-2)">
              <td colspan="4" style="padding:8px 10px;font-size:11px;color:var(--g7-text-3)">TOTAL: <?= count($landingsProd) ?> landing<?= count($landingsProd)!=1?'s':'' ?></td>
              <td style="text-align:center;padding:8px 10px;color:#22c55e"><?= $landingsProdPub ?> publicada<?= $landingsProdPub!=1?'s':'' ?></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <script src="/toolz/assets/stats-produccion.js?v=<?= (int)@filemtime(__DIR__ . '/assets/stats-produccion.js') ?>"></script>

  <?php elseif($section==='timeline'): ?>
    <?php
    $tMes     = $_GET['mes']     ?? date('Y-m');
    $tProyect = $_GET['proyecto'] ?? '';
    $tMesDt   = DateTime::createFromFormat('Y-m', $tMes);
    $tPrevMes = (clone $tMesDt)->modify('-1 month')->format('Y-m');
    $tNextMes = (clone $tMesDt)->modify('+1 month')->format('Y-m');
    $tYear    = (int)$tMesDt->format('Y');
    $tMonth   = (int)$tMesDt->format('n');
    $tDays    = (int)$tMesDt->format('t'); // days in month

    // Proyectos disponibles
    $tProyectos = $db->query("SELECT DISTINCT project FROM g7_content WHERE project!='' ORDER BY project")->fetchAll(PDO::FETCH_COLUMN);

    // Get all content for the month
    $tWhere  = "YEAR(created_at)=? AND MONTH(created_at)=? AND estado='activo'";
    $tParams = [$tYear, $tMonth];
    if ($tProyect) { $tWhere .= " AND project=?"; $tParams[] = $tProyect; }

    $tRows = $db->prepare("SELECT id,name,type,project,category,graphics,landing,mailing,edit_type,qty_graficas,qty_videos,qty_tareas,created_at FROM g7_content WHERE $tWhere ORDER BY created_at ASC");
    $tRows->execute($tParams);
    $tItems = $tRows->fetchAll();

    // Group by day
    $tByDay = [];
    foreach ($tItems as $item) {
        $day = (int)date('j', strtotime($item['created_at']));
        $tByDay[$day][] = $item;
    }

    // Get unique projects for color coding
    $tAllProjects = array_unique(array_filter(array_column($tItems, 'project')));
    $tProjectColors = ['#ff6600','#a78bfa','#4ade80','#60a5fa','#f59e0b','#ec4899','#14b8a6','#f97316','#8b5cf6','#06b6d4'];
    $tProjColorMap = [];
    foreach (array_values($tAllProjects) as $i => $p) {
        $tProjColorMap[$p] = $tProjectColors[$i % count($tProjectColors)];
    }
    ?>

    <div class="page-header">
      <div class="page-title">Time<span>line</span></div>
      <div class="page-sub">Calendario de entregas por día</div>
    </div>

    <!-- FILTROS -->
    <div class="card" style="margin-bottom:16px">
      <form method="GET" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <input type="hidden" name="section" value="timeline">
        <div style="display:flex;align-items:center;gap:6px">
          <a href="?section=timeline&mes=<?= $tPrevMes ?>&proyecto=<?= urlencode($tProyect) ?>" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-3);border-radius:7px;padding:6px 12px;text-decoration:none;font-size:13px">‹</a>
          <span style="font-size:14px;font-weight:700;min-width:140px;text-align:center"><?= $tMesDt->format('F Y') ?></span>
          <a href="?section=timeline&mes=<?= $tNextMes ?>&proyecto=<?= urlencode($tProyect) ?>" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-3);border-radius:7px;padding:6px 12px;text-decoration:none;font-size:13px">›</a>
        </div>
        <input type="hidden" name="mes" value="<?= htmlspecialchars($tMes) ?>">
        <select name="proyecto" data-on-change="stsSubmit" data-change-1="this" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:7px;padding:6px 10px;font-size:12px;outline:none">
          <option value="">Todos los proyectos</option>
          <?php foreach($tProyectos as $p): ?>
          <option value="<?= htmlspecialchars($p) ?>" <?= $tProyect===$p?'selected':'' ?>><?= htmlspecialchars($p) ?></option>
          <?php endforeach; ?>
        </select>
        <!-- Leyenda de proyectos -->
        <?php if(!$tProyect && !empty($tProjColorMap)): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-left:auto">
          <?php foreach($tProjColorMap as $proj=>$color): ?>
          <div style="display:flex;align-items:center;gap:5px">
            <div style="width:10px;height:10px;border-radius:50%;background:<?= $color ?>;flex-shrink:0"></div>
            <span style="font-size:10px;color:var(--g7-text-3)"><?= htmlspecialchars($proj) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </form>
    </div>

    <!-- RESUMEN DEL MES -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px">
      <?php
      $tTotal  = count($tItems);
      $tGraf   = array_sum(array_column($tItems,'qty_graficas'));
      $tVids   = array_sum(array_column($tItems,'qty_videos'));
      $tTareas = array_sum(array_column($tItems,'qty_tareas'));
      $kpis2 = [
        ['Entregas','fa-box','var(--g7-accent)',$tTotal],
        ['Gráficas','fa-image','#60a5fa',$tGraf],
        ['Videos','fa-film','#a78bfa',$tVids],
        ['Tareas rápidas','fa-bolt','#4ade80',$tTareas],
      ];
      foreach($kpis2 as [$lbl,$icon,$col,$val]): ?>
      <div class="card" style="padding:12px 14px;display:flex;align-items:center;gap:10px">
        <i class="fa-solid <?= $icon ?>" style="font-size:18px;color:<?= $col ?>"></i>
        <div>
          <div style="font-size:20px;font-weight:800;line-height:1"><?= number_format($val) ?></div>
          <div style="font-size:10px;color:var(--g7-text-4);margin-top:2px"><?= $lbl ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- CALENDARIO -->
    <div class="card" style="padding:0;overflow:hidden">
      <!-- Días de la semana header -->
      <div style="display:grid;grid-template-columns:repeat(7,1fr);border-bottom:1px solid var(--g7-border)">
        <?php foreach(['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'] as $d): ?>
        <div style="padding:8px;text-align:center;font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.06em"><?= $d ?></div>
        <?php endforeach; ?>
      </div>
      <!-- Grilla de días -->
      <?php
      $firstDayOfWeek = (int)date('w', mktime(0,0,0,$tMonth,1,$tYear)); // 0=Sun
      $totalCells = $firstDayOfWeek + $tDays;
      $totalRows  = ceil($totalCells / 7);
      $today      = (int)date('j');
      $isThisMonth= (date('Y-m') === $tMes);
      ?>
      <div style="display:grid;grid-template-columns:repeat(7,1fr)">
        <?php for($cell=0; $cell < $totalRows*7; $cell++):
          $day = $cell - $firstDayOfWeek + 1;
          $isValid = $day >= 1 && $day <= $tDays;
          $isToday = $isValid && $isThisMonth && $day === $today;
          $items   = $isValid ? ($tByDay[$day] ?? []) : [];
          $hasItems= !empty($items);
          $isWeekend = ($cell % 7 === 0 || $cell % 7 === 6);
        ?>
        <div style="min-height:90px;padding:6px;border-right:1px solid var(--g7-border);border-bottom:1px solid var(--g7-border);<?= !$isValid?'opacity:.2':'' ?>;<?= $isWeekend?'background:rgba(255,255,255,.01)':'' ?>">
          <?php if($isValid): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
            <span style="font-size:11px;font-weight:<?= $isToday?'800':'600' ?>;color:<?= $isToday?'var(--g7-accent)':'var(--g7-text-4)' ?>;<?= $isToday?'background:var(--g7-accent);color:#000;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px':'' ?>">
              <?= $day ?>
            </span>
            <?php if($hasItems): ?>
            <span style="font-size:9px;font-weight:700;color:var(--g7-accent);background:rgba(var(--g7-accent-rgb),.1);padding:1px 5px;border-radius:10px"><?= count($items) ?></span>
            <?php endif; ?>
          </div>
          <?php foreach($items as $item):
            $color = $tProjColorMap[$item['project']] ?? 'var(--g7-accent)';
            $typeIcon = match($item['type']) {
              'tarea'  => '⚡',
              'launch' => '🚀',
              'paid'   => '💰',
              'social' => '📱',
              default  => '📄'
            };
            $isEdited = !empty($item['edit_type']);
            $total = (int)($item['qty_graficas']??0) + (int)($item['qty_videos']??0) + (int)($item['qty_tareas']??0);
          ?>
          <div data-on-click="tlOpenDetail" data-click-1="n:<?= (int)$item['id'] ?>" style="background:<?= $color ?>15;border-left:2px solid <?= $color ?>;border-radius:0 4px 4px 0;padding:2px 5px;margin-bottom:2px;cursor:pointer;transition:opacity .1s" class="sts-hop" title="<?= htmlspecialchars($item['name']) ?>">
            <div style="font-size:9px;font-weight:700;color:var(--g7-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%">
              <?= $typeIcon ?> <?= htmlspecialchars(mb_substr($item['name'],0,18)) ?>
            </div>
            <?php if($total > 0 || $isEdited): ?>
            <div style="font-size:8px;color:var(--g7-text-5);display:flex;gap:4px;align-items:center">
              <?php if((int)($item['qty_graficas']??0)>0): ?><span><?= $item['qty_graficas'] ?>g</span><?php endif; ?>
              <?php if((int)($item['qty_videos']??0)>0): ?><span><?= $item['qty_videos'] ?>v</span><?php endif; ?>
              <?php if((int)($item['qty_tareas']??0)>0): ?><span><?= $item['qty_tareas'] ?>t</span><?php endif; ?>
              <?php if($isEdited): ?><span style="color:<?= $item['edit_type']==='rechazo'?'#ef4444':'#f59e0b' ?>"><?= $item['edit_type']==='rechazo'?'✗':'↩' ?></span><?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php endfor; ?>
      </div>
    </div>

    <!-- DETAIL DRAWER (slide in from right) -->
    <div id="tl-drawer" style="position:fixed;top:0;right:-420px;bottom:0;width:420px;background:var(--g7-bg-3);border-left:1px solid var(--g7-border);z-index:500;display:flex;flex-direction:column;transition:right .25s;box-shadow:-4px 0 24px rgba(0,0,0,.4)">
      <div style="padding:14px 18px;border-bottom:1px solid var(--g7-border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0">
        <span style="font-size:14px;font-weight:700" id="tl-drawer-title">Detalle</span>
        <button data-on-click="tlCloseDrawer" style="background:none;border:none;color:var(--g7-text-4);cursor:pointer;font-size:18px">✕</button>
      </div>
      <div id="tl-drawer-body" style="flex:1;overflow-y:auto;padding:16px 18px"></div>
    </div>
    <div id="tl-overlay" data-on-click="tlCloseDrawer" style="display:none;position:fixed;inset:0;z-index:499;background:rgba(0,0,0,.3)"></div>

    <script type="application/json" id="tl-data"><?= json_encode(['items' => array_map(fn($i)=>[
      'id'           => (int)$i['id'],
      'name'         => $i['name'],
      'type'         => $i['type'],
      'project'      => $i['project'],
      'category'     => $i['category'],
      'edit_type'    => $i['edit_type'],
      'qty_graficas' => (int)($i['qty_graficas']??0),
      'qty_videos'   => (int)($i['qty_videos']??0),
      'qty_tareas'   => (int)($i['qty_tareas']??0),
      'graphics'     => $i['graphics'],
      'landing'      => $i['landing'],
      'mailing'      => $i['mailing'],
      'created_at'   => $i['created_at'],
    ], $tItems), 'colors' => $tProjColorMap], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script src="/toolz/assets/stats-timeline.js?v=<?= (int)@filemtime(__DIR__ . '/assets/stats-timeline.js') ?>"></script>

  <?php elseif($section==='ranking'): ?>
    <?php
    // Período seleccionado: week | biweekly | month
    $rPeriod = $_GET['rperiod'] ?? 'month';
    $rRef    = $_GET['rref']    ?? date('Y-m-d'); // fecha de referencia

    // Calcular rango de fechas según período
    $rRefDt = new DateTime($rRef);
    switch($rPeriod){
      case 'week':
        // Semana lunes-domingo que contiene $rRef
        $dow = (int)$rRefDt->format('N'); // 1=Mon 7=Sun
        $rStart = (clone $rRefDt)->modify('-'.($dow-1).' days')->format('Y-m-d');
        $rEnd   = (clone $rRefDt)->modify('+'.(7-$dow).' days')->format('Y-m-d');
        $rLabel = 'Semana del '.(new DateTime($rStart))->format('d/m').' al '.(new DateTime($rEnd))->format('d/m/Y');
        $rPrevRef = (clone $rRefDt)->modify('-7 days')->format('Y-m-d');
        $rNextRef = (clone $rRefDt)->modify('+7 days')->format('Y-m-d');
        break;
      case 'biweekly':
        // Quincena 1-15 o 16-fin de mes
        $day = (int)$rRefDt->format('j');
        if($day <= 15){
          $rStart = $rRefDt->format('Y-m-01');
          $rEnd   = $rRefDt->format('Y-m-15');
          $rLabel = '1ª Quincena de '.$rRefDt->format('F Y');
          $rPrevRef = (clone $rRefDt)->modify('-1 day')->format('Y-m-d');
          $rNextRef = $rRefDt->format('Y-m-16');
        } else {
          $rStart = $rRefDt->format('Y-m-16');
          $rEnd   = $rRefDt->format('Y-m-t');
          $rLabel = '2ª Quincena de '.$rRefDt->format('F Y');
          $rPrevRef = $rRefDt->format('Y-m-15');
          $rNextRef = (clone $rRefDt)->modify('first day of next month')->format('Y-m-d');
        }
        break;
      default: // month
        $rStart = $rRefDt->format('Y-m-01');
        $rEnd   = $rRefDt->format('Y-m-t');
        $rLabel = $rRefDt->format('F Y');
        $rPrevRef = (clone $rRefDt)->modify('-1 month')->format('Y-m-d');
        $rNextRef = (clone $rRefDt)->modify('+1 month')->format('Y-m-d');
    }

    // Query ranking
    $rRows = $db->prepare("
        SELECT
            u.id, u.username, u.display_name, u.avatar,
            COUNT(*) as total,
            SUM(CASE WHEN c.type='tarea' THEN 1 ELSE 0 END) as tareas,
            SUM(CASE WHEN c.type!='tarea' THEN 1 ELSE 0 END) as contenidos,
            SUM(c.qty_graficas) as graficas,
            SUM(c.qty_videos)   as videos,
            SUM(c.qty_tareas)   as qty_tareas,
            SUM(c.qty_graficas + c.qty_videos + c.qty_tareas) as total_assets,
            SUM(CASE WHEN c.edit_type='rechazo' THEN 1 ELSE 0 END) as rechazos
        FROM g7_content c
        JOIN g7_users u ON u.id = c.created_by
        WHERE DATE(c.created_at) BETWEEN ? AND ?
          AND c.estado = 'activo'
        GROUP BY u.id
        ORDER BY total DESC, total_assets DESC
    ");
    $rRows->execute([$rStart, $rEnd]);
    $rData = $rRows->fetchAll();

    // Total del período
    $rTotalContent = array_sum(array_column($rData,'contenidos'));
    $rTotalTareas  = array_sum(array_column($rData,'tareas'));
    ?>

    <div class="page-header">
      <div class="page-title">Ran<span>king</span></div>
      <div class="page-sub">Producción por usuario en el período seleccionado</div>
    </div>

    <!-- PERIOD SELECTOR -->
    <div class="card" style="margin-bottom:16px">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <!-- Tipo de período -->
        <div style="display:flex;gap:4px;background:var(--g7-bg-4);padding:3px;border-radius:8px">
          <?php foreach(['week'=>'Semana','biweekly'=>'Quincena','month'=>'Mes'] as $k=>$v): ?>
          <a href="?section=ranking&rperiod=<?= $k ?>&rref=<?= $rRef ?>" style="padding:6px 14px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;background:<?= $rPeriod===$k?'var(--g7-bg)':'transparent' ?>;color:<?= $rPeriod===$k?'var(--g7-accent)':'var(--g7-text-4)' ?>;box-shadow:<?= $rPeriod===$k?'0 1px 4px rgba(0,0,0,.2)':'' ?>;transition:all .15s"><?= $v ?></a>
          <?php endforeach; ?>
        </div>
        <!-- Navegación -->
        <div style="display:flex;align-items:center;gap:6px">
          <a href="?section=ranking&rperiod=<?= $rPeriod ?>&rref=<?= $rPrevRef ?>" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-3);border-radius:7px;padding:6px 12px;text-decoration:none;font-size:13px">‹</a>
          <span style="font-size:13px;font-weight:700;min-width:200px;text-align:center;color:var(--g7-text)"><?= $rLabel ?></span>
          <a href="?section=ranking&rperiod=<?= $rPeriod ?>&rref=<?= $rNextRef ?>" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-3);border-radius:7px;padding:6px 12px;text-decoration:none;font-size:13px">›</a>
        </div>
        <div style="margin-left:auto;font-size:11px;color:var(--g7-text-4)"><?= $rStart ?> → <?= $rEnd ?></div>
      </div>
    </div>

    <?php if(empty($rData)): ?>
    <div class="card" style="text-align:center;padding:40px;color:var(--g7-text-5)">
      <i class="fa-solid fa-trophy" style="font-size:32px;display:block;margin-bottom:12px;opacity:.2"></i>
      Sin actividad en este período
    </div>
    <?php else: ?>

    <!-- PODIUM (top 3) -->
    <?php if(count($rData) >= 2): ?>
    <div style="display:flex;align-items:flex-end;justify-content:center;gap:12px;margin-bottom:20px;padding:0 20px">
      <?php
      $podium = array_slice($rData, 0, 3);
      // Visual order: 2nd left, 1st center, 3rd right
      // $podium[0]=1st, $podium[1]=2nd, $podium[2]=3rd
      $order  = count($podium)===1 ? [0] : (count($podium)===2 ? [1,0] : [1,0,2]);
      // Visual layout: left=2nd place, center=1st place, right=3rd place
      $heights    = [120, 160, 100]; // indexed by visual slot
      $rankMedals = ['🥇','🥈','🥉'];   // indexed by RANK (0=1st,1=2nd,2=3rd)
      $rankColors = ['#f59e0b','#a0aec0','#cd7f32']; // indexed by RANK
      foreach($order as $visPos=>$dataIdx):
        if(!isset($podium[$dataIdx])) continue;
        $u     = $podium[$dataIdx];
        $name  = $u['display_name']?:$u['username'];
        $initial = strtoupper(mb_substr($name,0,1));
        $h     = $heights[$visPos];        // height by visual slot
        $rank  = $dataIdx + 1;             // real rank: 1, 2, or 3
        $c     = $rankColors[$dataIdx];    // color by real rank
        $medal = $rankMedals[$dataIdx];    // medal by real rank
      ?>
      <div style="display:flex;flex-direction:column;align-items:center;flex:1;max-width:160px">
        <!-- Avatar -->
        <div style="margin-bottom:8px;position:relative">
          <?php if($u['avatar']): ?>
          <img src="<?= htmlspecialchars($u['avatar']) ?>" style="width:52px;height:52px;border-radius:50%;object-fit:cover;border:3px solid <?= $c ?>">
          <?php else: ?>
          <div style="width:52px;height:52px;border-radius:50%;background:<?= $c ?>25;border:3px solid <?= $c ?>;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;color:<?= $c ?>"><?= $initial ?></div>
          <?php endif; ?>
          <div style="position:absolute;bottom:-4px;right:-4px;font-size:18px;line-height:1"><?= $medal ?></div>
        </div>
        <div style="font-size:12px;font-weight:800;text-align:center;margin-bottom:4px;color:var(--g7-text)"><?= htmlspecialchars(mb_substr($name,0,14)) ?></div>
        <div style="font-size:11px;font-weight:700;color:<?= $c ?>;margin-bottom:6px"><?= $u['total'] ?> registros</div>
        <!-- Podium base -->
        <div style="width:100%;height:<?= $h ?>px;background:linear-gradient(180deg,<?= $c ?>40,<?= $c ?>15);border:1px solid <?= $c ?>40;border-radius:8px 8px 0 0;display:flex;align-items:center;justify-content:center">
          <span style="font-size:28px;font-weight:900;color:<?= $c ?>"><?= $rank ?>°</span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- TABLA COMPLETA -->
    <div class="card">
      <div style="font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;margin-bottom:12px">Tabla completa</div>
      <?php foreach($rData as $i=>$u):
        $name = $u['display_name']?:$u['username'];
        $initial = strtoupper(mb_substr($name,0,1));
        $pct = $rTotalContent > 0 ? round($u['contenidos']/$rTotalContent*100) : 0;
        $barColors = ['#f59e0b','#a0aec0','#cd7f32'];
        $barColor  = $i < 3 ? $barColors[$i] : 'var(--g7-accent)';
        $maxTotal  = max(array_column($rData,'total'));
        $barWidth  = $maxTotal > 0 ? round($u['total']/$maxTotal*100) : 0;
      ?>
      <div style="display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--g7-border)">
        <!-- Posición -->
        <div style="width:28px;text-align:center;font-size:14px;font-weight:800;color:<?= $i<3?$barColors[$i]:'var(--g7-text-5)' ?>;flex-shrink:0"><?= $i+1 ?></div>
        <!-- Avatar -->
        <?php if($u['avatar']): ?>
        <img src="<?= htmlspecialchars($u['avatar']) ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;flex-shrink:0">
        <?php else: ?>
        <div style="width:40px;height:40px;border-radius:50%;background:<?= $barColor ?>20;border:2px solid <?= $barColor ?>40;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:<?= $barColor ?>;flex-shrink:0"><?= $initial ?></div>
        <?php endif; ?>
        <!-- Info + barra -->
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
            <span style="font-size:13px;font-weight:700"><?= htmlspecialchars($name) ?></span>
            <span style="font-size:12px;font-weight:800;color:var(--g7-accent)"><?= $u['total'] ?></span>
          </div>
          <!-- Barra de progreso -->
          <div style="height:5px;background:var(--g7-bg-5);border-radius:3px;overflow:hidden;margin-bottom:5px">
            <div style="height:100%;width:<?= $barWidth ?>%;background:<?= $barColor ?>;border-radius:3px;transition:width .5s"></div>
          </div>
          <!-- Detalles -->
          <div style="display:flex;gap:10px;font-size:10px;color:var(--g7-text-5);flex-wrap:wrap">
            <?php if($u['contenidos']>0): ?><span>📄 <?= $u['contenidos'] ?> contenidos</span><?php endif; ?>
            <?php if($u['tareas']>0): ?><span>⚡ <?= $u['tareas'] ?> tareas</span><?php endif; ?>
            <?php if($u['graficas']>0): ?><span>🖼 <?= $u['graficas'] ?>g</span><?php endif; ?>
            <?php if($u['videos']>0): ?><span>🎬 <?= $u['videos'] ?>v</span><?php endif; ?>
            <?php if($u['rechazos']>0): ?><span style="color:#ef4444">✗ <?= $u['rechazos'] ?> rechazos</span><?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  <?php elseif($section==='tendencia'): ?>
    <?php
    // Last 6 months of data grouped by month and project
    $tndRows = $db->query("
        SELECT DATE_FORMAT(created_at,'%Y-%m') as mes,
               project,
               SUM(qty_graficas) as graficas,
               SUM(qty_videos) as videos,
               SUM(qty_graficas+qty_videos) as total,
               COUNT(*) as registros
        FROM g7_content
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
          AND type != 'tarea' AND estado='activo'
        GROUP BY mes, project
        ORDER BY mes ASC, total DESC
    ")->fetchAll();

    // Build months array (last 6)
    $tndMonths = [];
    for($i=5;$i>=0;$i--){
        $tndMonths[] = date('Y-m', strtotime("-$i months"));
    }

    // Build projects list
    $tndProjects = array_unique(array_column($tndRows,'project'));
    $tndColors   = ['#ff6600','#a78bfa','#4ade80','#60a5fa','#f59e0b','#ec4899','#14b8a6','#f97316','#8b5cf6'];

    // Index data
    $tndByMonthProj = [];
    foreach($tndRows as $r){
        $tndByMonthProj[$r['mes']][$r['project']] = $r;
    }

    // Global totals per month
    $tndGlobalRows = $db->query("
        SELECT DATE_FORMAT(created_at,'%Y-%m') as mes,
               SUM(qty_graficas) as graficas,
               SUM(qty_videos) as videos,
               COUNT(*) as registros
        FROM g7_content
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
          AND type != 'tarea' AND estado='activo'
        GROUP BY mes ORDER BY mes ASC
    ")->fetchAll();
    $tndGlobal = [];
    foreach($tndGlobalRows as $r) $tndGlobal[$r['mes']] = $r;
    ?>
    <div class="page-header">
      <div class="page-title">Ten<span>dencia</span></div>
      <div class="page-sub">Evolución mensual de producción — últimos 6 meses</div>
    </div>

    <!-- Global chart -->
    <div class="card" style="margin-bottom:16px">
      <div style="font-size:11px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;margin-bottom:14px">Total de assets por mes</div>
      <div style="display:flex;align-items:flex-end;gap:8px;height:160px">
        <?php
        $maxTotal = max(1, max(array_map(fn($m)=>($tndGlobal[$m]['graficas']??0)+($tndGlobal[$m]['videos']??0), $tndMonths)));
        foreach($tndMonths as $mes):
          $g = (int)($tndGlobal[$mes]['graficas']??0);
          $v = (int)($tndGlobal[$mes]['videos']??0);
          $tot = $g + $v;
          $hPct = round($tot/$maxTotal*100);
          $gPct = $tot>0?round($g/$tot*100):0;
          $label = date('M', strtotime($mes.'-01'));
        ?>
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px">
          <div style="font-size:10px;font-weight:700;color:var(--g7-text-3)"><?= $tot ?: '' ?></div>
          <div style="width:100%;flex:1;display:flex;flex-direction:column;justify-content:flex-end">
            <div style="width:100%;background:var(--g7-bg-5);border-radius:4px;overflow:hidden;height:<?= max(4,$hPct) ?>%;min-height:4px">
              <div style="width:<?= $gPct ?>%;height:100%;background:#60a5fa;float:left"></div>
              <div style="width:<?= 100-$gPct ?>%;height:100%;background:#a78bfa;float:left"></div>
            </div>
          </div>
          <div style="font-size:9px;color:var(--g7-text-5);font-weight:600"><?= $label ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;gap:14px;margin-top:10px;justify-content:center">
        <div style="display:flex;align-items:center;gap:5px;font-size:10px;color:var(--g7-text-4)"><div style="width:10px;height:10px;border-radius:2px;background:#60a5fa"></div>Gráficas</div>
        <div style="display:flex;align-items:center;gap:5px;font-size:10px;color:var(--g7-text-4)"><div style="width:10px;height:10px;border-radius:2px;background:#a78bfa"></div>Videos</div>
      </div>
    </div>

    <!-- Per project table -->
    <div class="card">
      <div style="font-size:11px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;margin-bottom:12px">Por proyecto</div>
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:12px">
          <thead>
            <tr style="border-bottom:2px solid var(--g7-border)">
              <th style="text-align:left;padding:8px 10px;font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase">Proyecto</th>
              <?php foreach($tndMonths as $mes): ?>
              <th style="text-align:center;padding:8px 6px;font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase"><?= date('M', strtotime($mes.'-01')) ?></th>
              <?php endforeach; ?>
              <th style="text-align:center;padding:8px 10px;font-size:10px;font-weight:700;color:var(--g7-accent);text-transform:uppercase">Total</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($tndProjects as $pi=>$proj):
            $rowTotal=0;
          ?>
          <tr class="sts-hrow" style="border-bottom:1px solid var(--g7-border)">
            <td style="padding:8px 10px;font-weight:600;white-space:nowrap">
              <div style="display:flex;align-items:center;gap:7px">
                <div style="width:8px;height:8px;border-radius:50%;background:<?= $tndColors[$pi%count($tndColors)] ?>;flex-shrink:0"></div>
                <?= htmlspecialchars($proj?:'Sin proyecto') ?>
              </div>
            </td>
            <?php foreach($tndMonths as $mes):
              $d = $tndByMonthProj[$mes][$proj]??null;
              $v = $d ? (int)$d['total'] : 0;
              $rowTotal += $v;
            ?>
            <td style="text-align:center;padding:8px 6px;color:<?= $v>0?'var(--g7-text)':'var(--g7-text-5)' ?>"><?= $v ?: '—' ?></td>
            <?php endforeach; ?>
            <td style="text-align:center;padding:8px 10px;font-weight:800;color:var(--g7-accent)"><?= $rowTotal ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif($section==='heatmap'): ?>
    <?php
    // Activity heatmap - last 52 weeks
    $hmRows = $db->query("
        SELECT DATE(created_at) as dia, COUNT(*) as total
        FROM g7_content
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 52 WEEK)
          AND estado='activo'
        GROUP BY dia
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    $maxDay = max(1, max(array_values($hmRows) ?: [1]));

    // Build 52 weeks grid starting from Monday
    $today = new DateTime();
    $startDate = (clone $today)->modify('-51 weeks')->modify('monday this week');
    ?>
    <div class="page-header">
      <div class="page-title">Heat<span>map</span></div>
      <div class="page-sub">Actividad diaria — últimas 52 semanas</div>
    </div>

    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
        <div style="font-size:11px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em">Registros por día</div>
        <div style="display:flex;align-items:center;gap:6px;font-size:10px;color:var(--g7-text-5)">
          Menos <div style="display:flex;gap:3px">
            <?php foreach(['var(--g7-bg-5)','#ff660033','#ff660066','#ff6600aa','#ff6600'] as $c): ?>
            <div style="width:12px;height:12px;border-radius:2px;background:<?= $c ?>"></div>
            <?php endforeach; ?>
          </div> Más
        </div>
      </div>

      <!-- Day labels -->
      <div style="display:flex;gap:0">
        <div style="width:28px;flex-shrink:0"></div>
        <div style="flex:1;display:grid;grid-template-columns:repeat(53,1fr);gap:2px">
          <?php
          $cur = clone $startDate;
          $prevMonth = '';
          for($w=0;$w<53;$w++){
            $month = $cur->format('M');
            echo '<div style="font-size:8px;color:var(--g7-text-5);text-align:center;height:14px;overflow:hidden">';
            if($month !== $prevMonth){ echo $month; $prevMonth=$month; }
            echo '</div>';
            $cur->modify('+1 week');
          }
          ?>
        </div>
      </div>

      <div style="display:flex;gap:0;margin-top:2px">
        <!-- Weekday labels -->
        <div style="width:28px;flex-shrink:0;display:flex;flex-direction:column;gap:2px">
          <?php foreach(['L','M','X','J','V','S','D'] as $dl): ?>
          <div style="font-size:8px;color:var(--g7-text-5);height:13px;display:flex;align-items:center"><?= $dl ?></div>
          <?php endforeach; ?>
        </div>
        <!-- Grid -->
        <div style="flex:1;display:grid;grid-template-columns:repeat(53,1fr);grid-template-rows:repeat(7,1fr);gap:2px;grid-auto-flow:column">
          <?php
          $cur = clone $startDate;
          $endDate = clone $today;
          for($w=0;$w<53;$w++){
            for($d=0;$d<7;$d++){
              $dayStr = $cur->format('Y-m-d');
              $count  = $hmRows[$dayStr] ?? 0;
              $isFuture = $cur > $endDate;
              if($isFuture){
                echo '<div style="width:13px;height:13px;border-radius:2px;background:transparent"></div>';
              } else {
                $intensity = $count===0?0:min(4,ceil($count/$maxDay*4));
                $colors2 = ['var(--g7-bg-5)','#ff660033','#ff660066','#ff6600aa','#ff6600'];
                $c = $colors2[$intensity];
                $title = $count ? "$count registros el $dayStr" : $dayStr;
                $onclick = $count ? ' data-on-click="stsGo" data-click-1="s:?section=timeline&amp;mes='.$cur->format('Y-m').'"' : '';
                echo '<div title="'.$title.'" style="width:13px;height:13px;border-radius:2px;background:'.$c.';cursor:'.($count?'pointer':'default').'"'.$onclick.'></div>';
              }
              $cur->modify('+1 day');
            }
          }
          ?>
        </div>
      </div>

      <!-- Stats -->
      <div style="display:flex;gap:20px;margin-top:14px;padding-top:12px;border-top:1px solid var(--g7-border)">
        <?php
        $hmTotal  = array_sum($hmRows);
        $hmDays   = count(array_filter($hmRows));
        $hmStreak = 0; $hmCurStreak = 0;
        $checkDay = clone $today;
        for($i=0;$i<365;$i++){
          $ds = $checkDay->format('Y-m-d');
          if(isset($hmRows[$ds])){ $hmCurStreak++; $hmStreak=max($hmStreak,$hmCurStreak); }
          else { $hmCurStreak=0; }
          $checkDay->modify('-1 day');
        }
        ?>
        <div><div style="font-size:18px;font-weight:800;color:var(--g7-accent)"><?= $hmTotal ?></div><div style="font-size:10px;color:var(--g7-text-4)">registros totales</div></div>
        <div><div style="font-size:18px;font-weight:800"><?= $hmDays ?></div><div style="font-size:10px;color:var(--g7-text-4)">días activos</div></div>
        <div><div style="font-size:18px;font-weight:800"><?= $maxDay ?></div><div style="font-size:10px;color:var(--g7-text-4)">máximo en un día</div></div>
        <div><div style="font-size:18px;font-weight:800;color:#f59e0b"><?= $hmStreak ?></div><div style="font-size:10px;color:var(--g7-text-4)">racha máxima (días)</div></div>
      </div>
    </div>

  <?php elseif($section==='aprobacion'): ?>
    <?php
    $apMes = $_GET['mes'] ?? date('Y-m');
    $apDt  = DateTime::createFromFormat('Y-m',$apMes);
    $apPrev= (clone $apDt)->modify('-1 month')->format('Y-m');
    $apNext= (clone $apDt)->modify('+1 month')->format('Y-m');

    // Approval rate per project
    $apRows = $db->prepare("
        SELECT project,
               COUNT(*) as total,
               SUM(CASE WHEN parent_id IS NULL AND qty_revisiones=0 AND qty_rechazos=0 THEN 1 ELSE 0 END) as sin_revision,
               SUM(qty_revisiones) as revisiones,
               SUM(qty_rechazos)   as rechazos,
               SUM(qty_graficas+qty_videos) as assets
        FROM g7_content
        WHERE YEAR(created_at)=? AND MONTH(created_at)=?
          AND type!='tarea' AND estado='activo'
        GROUP BY project ORDER BY total DESC
    ");
    $apRows->execute([$apDt->format('Y'),$apDt->format('n')]);
    $apData = $apRows->fetchAll();

    // Top projects by volume (all time)
    $topProj = $db->query("
        SELECT project, COUNT(*) as total, SUM(qty_graficas+qty_videos) as assets
        FROM g7_content WHERE type!='tarea' AND estado='activo' AND project!=''
        GROUP BY project ORDER BY total DESC LIMIT 10
    ")->fetchAll();
    ?>
    <div class="page-header">
      <div class="page-title">Apro<span>bación</span></div>
      <div class="page-sub">Tasa de aprobación y proyectos más activos</div>
    </div>

    <div class="card" style="margin-bottom:16px">
      <form method="GET" style="display:flex;align-items:center;gap:8px">
        <input type="hidden" name="section" value="aprobacion">
        <a href="?section=aprobacion&mes=<?= $apPrev ?>" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-3);border-radius:7px;padding:6px 12px;text-decoration:none">‹</a>
        <input type="month" name="mes" value="<?= $apMes ?>" data-on-change="stsSubmit" data-change-1="this" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:7px;padding:6px 10px;font-size:12px;outline:none">
        <a href="?section=aprobacion&mes=<?= $apNext ?>" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-3);border-radius:7px;padding:6px 12px;text-decoration:none">›</a>
      </form>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
      <!-- Approval rate table -->
      <div class="card">
        <div style="font-size:11px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;margin-bottom:12px">Tasa de aprobación por proyecto</div>
        <?php if(empty($apData)): ?>
        <div style="text-align:center;color:var(--g7-text-5);padding:20px;font-size:12px">Sin datos</div>
        <?php else: foreach($apData as $r):
          $apTotal = (int)$r['total'];
          $apSinRev= (int)$r['sin_revision'];
          $apPct   = $apTotal>0?round($apSinRev/$apTotal*100):0;
          $apColor = $apPct>=80?'#22c55e':($apPct>=60?'#f59e0b':'#ef4444');
        ?>
        <div style="margin-bottom:12px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
            <span style="font-size:12px;font-weight:600"><?= htmlspecialchars($r['project']?:'Sin proyecto') ?></span>
            <span style="font-size:13px;font-weight:800;color:<?= $apColor ?>"><?= $apPct ?>%</span>
          </div>
          <div style="height:6px;background:var(--g7-bg-5);border-radius:3px;overflow:hidden">
            <div style="width:<?= $apPct ?>%;height:100%;background:<?= $apColor ?>;border-radius:3px"></div>
          </div>
          <div style="font-size:9px;color:var(--g7-text-5);margin-top:3px"><?= $apTotal ?> registros · <?= $r['revisiones'] ?> rev · <?= $r['rechazos'] ?> rec</div>
        </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- Top projects all time -->
      <div class="card">
        <div style="font-size:11px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;margin-bottom:12px">Proyectos más activos (histórico)</div>
        <?php $maxTP=max(1,max(array_column($topProj,'total')??[1]));
        foreach($topProj as $i=>$p): ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
          <span style="font-size:12px;font-weight:800;color:var(--g7-text-5);width:18px;text-align:right"><?= $i+1 ?></span>
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:3px">
              <span style="font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($p['project']) ?></span>
              <span style="font-size:11px;font-weight:700;color:var(--g7-accent);flex-shrink:0;margin-left:8px"><?= $p['total'] ?></span>
            </div>
            <div style="height:5px;background:var(--g7-bg-5);border-radius:3px;overflow:hidden">
              <div style="width:<?= round($p['total']/$maxTP*100) ?>%;height:100%;background:var(--g7-accent);border-radius:3px"></div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  <?php elseif($section==='tiempos'): ?>
    <?php
    $tiRows = $db->query("
        SELECT type, project,
               AVG(tiempo_entrega_dias) as avg_dias,
               MIN(tiempo_entrega_dias) as min_dias,
               MAX(tiempo_entrega_dias) as max_dias,
               COUNT(CASE WHEN tiempo_entrega_dias IS NOT NULL THEN 1 END) as con_tiempo,
               COUNT(*) as total
        FROM g7_content
        WHERE estado='activo' AND type!='tarea'
        GROUP BY type, project
        HAVING con_tiempo > 0
        ORDER BY avg_dias DESC
    ")->fetchAll();

    $tiByType = $db->query("
        SELECT type,
               AVG(tiempo_entrega_dias) as avg_dias,
               MIN(tiempo_entrega_dias) as min_dias,
               MAX(tiempo_entrega_dias) as max_dias,
               COUNT(CASE WHEN tiempo_entrega_dias IS NOT NULL THEN 1 END) as con_tiempo
        FROM g7_content WHERE estado='activo' AND type!='tarea' AND tiempo_entrega_dias IS NOT NULL
        GROUP BY type ORDER BY avg_dias DESC
    ")->fetchAll();

    $TYPE_LABELS_PHP = ['promo'=>'Promo','launch'=>'Launch','paid'=>'Paid','social'=>'Social','otro'=>'Otro','custom'=>'Custom'];
    ?>
    <div class="page-header">
      <div class="page-title">Tiem<span>pos</span></div>
      <div class="page-sub">Tiempo promedio de entrega por tipo y proyecto</div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
      <!-- By type -->
      <div class="card">
        <div style="font-size:11px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;margin-bottom:12px">Por tipo de contenido</div>
        <?php if(empty($tiByType)): ?>
        <div style="text-align:center;color:var(--g7-text-5);padding:20px;font-size:12px">Sin datos de tiempo registrados</div>
        <?php else: $maxAvg=max(1,max(array_column($tiByType,'avg_dias')));
        foreach($tiByType as $r):
          $label=$TYPE_LABELS_PHP[$r['type']]??$r['type'];
          $avg=round($r['avg_dias'],1);
          $color=$avg<=2?'#22c55e':($avg<=5?'#f59e0b':'#ef4444');
        ?>
        <div style="margin-bottom:12px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
            <span style="font-size:12px;font-weight:600"><?= $label ?></span>
            <div style="text-align:right">
              <span style="font-size:14px;font-weight:800;color:<?= $color ?>"><?= $avg ?>d</span>
              <span style="font-size:9px;color:var(--g7-text-5);margin-left:4px"><?= $r['min_dias'] ?>–<?= $r['max_dias'] ?>d</span>
            </div>
          </div>
          <div style="height:6px;background:var(--g7-bg-5);border-radius:3px;overflow:hidden">
            <div style="width:<?= round($avg/$maxAvg*100) ?>%;height:100%;background:<?= $color ?>;border-radius:3px"></div>
          </div>
          <div style="font-size:9px;color:var(--g7-text-5);margin-top:2px"><?= $r['con_tiempo'] ?> registros con tiempo</div>
        </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- By project (top 8) -->
      <div class="card">
        <div style="font-size:11px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;margin-bottom:12px">Por proyecto</div>
        <?php
        $tiByProj = $db->query("
            SELECT project, AVG(tiempo_entrega_dias) as avg_dias,
                   COUNT(CASE WHEN tiempo_entrega_dias IS NOT NULL THEN 1 END) as con_tiempo
            FROM g7_content WHERE estado='activo' AND type!='tarea' AND tiempo_entrega_dias IS NOT NULL AND project!=''
            GROUP BY project HAVING con_tiempo>0 ORDER BY avg_dias ASC LIMIT 10
        ")->fetchAll();
        if(empty($tiByProj)):?>
        <div style="text-align:center;color:var(--g7-text-5);padding:20px;font-size:12px">Sin datos</div>
        <?php else: $maxP=max(1,max(array_column($tiByProj,'avg_dias')));
        foreach($tiByProj as $r):
          $avg=round($r['avg_dias'],1);
          $color=$avg<=2?'#22c55e':($avg<=5?'#f59e0b':'#ef4444');
        ?>
        <div style="margin-bottom:10px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:3px">
            <span style="font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($r['project']) ?></span>
            <span style="font-size:13px;font-weight:800;color:<?= $color ?>;flex-shrink:0;margin-left:8px"><?= $avg ?>d</span>
          </div>
          <div style="height:5px;background:var(--g7-bg-5);border-radius:3px;overflow:hidden">
            <div style="width:<?= round($avg/$maxP*100) ?>%;height:100%;background:<?= $color ?>;border-radius:3px"></div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

  <?php elseif($section==='vencidos'): ?>
    <?php
    $vRows = $db->query("
        SELECT c.*, u.display_name, u.username, u.avatar,
               DATEDIFF(NOW(), c.end_date) as dias_vencido
        FROM g7_content c
        JOIN g7_users u ON u.id=c.created_by
        WHERE c.end_date < CURDATE()
          AND c.estado='activo'
          AND c.type!='tarea'
          AND c.project IS NOT NULL AND c.project!=''
        ORDER BY dias_vencido DESC
        LIMIT 50
    ")->fetchAll();

    // SLA by project
    $slaRows = $db->query("
        SELECT project,
               COUNT(*) as total,
               SUM(CASE WHEN tiempo_entrega_dias IS NOT NULL AND tiempo_entrega_dias<=3 THEN 1 ELSE 0 END) as dentro_sla,
               COUNT(CASE WHEN tiempo_entrega_dias IS NOT NULL THEN 1 END) as con_tiempo
        FROM g7_content
        WHERE estado='activo' AND type!='tarea' AND project!=''
        GROUP BY project HAVING con_tiempo>0
        ORDER BY dentro_sla/con_tiempo DESC
    ")->fetchAll();
    ?>
    <div class="page-header">
      <div class="page-title">Ven<span>cidos</span></div>
      <div class="page-sub">Contenidos vencidos y SLA por proyecto</div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
      <!-- Vencidos -->
      <div class="card">
        <div style="font-size:11px;font-weight:700;color:#ef4444;text-transform:uppercase;letter-spacing:.07em;margin-bottom:12px">
          <i class="fa-solid fa-triangle-exclamation" style="margin-right:5px"></i>
          <?= count($vRows) ?> contenidos vencidos
        </div>
        <?php if(empty($vRows)): ?>
        <div style="text-align:center;color:#22c55e;padding:20px;font-size:12px">
          <i class="fa-solid fa-circle-check" style="font-size:24px;display:block;margin-bottom:8px"></i>
          Todo al día 🎉
        </div>
        <?php else: foreach($vRows as $r): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--g7-border)">
          <div style="flex:1;min-width:0">
            <div style="font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($r['name']) ?></div>
            <div style="font-size:10px;color:var(--g7-text-5)"><?= htmlspecialchars($r['project']) ?> · venció <?= $r['end_date'] ?></div>
          </div>
          <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;background:rgba(239,68,68,.1);color:#ef4444;flex-shrink:0">+<?= $r['dias_vencido'] ?>d</span>
        </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- SLA by project -->
      <div class="card">
        <div style="font-size:11px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;margin-bottom:12px">SLA por proyecto (≤3 días)</div>
        <?php if(empty($slaRows)): ?>
        <div style="text-align:center;color:var(--g7-text-5);padding:20px;font-size:12px">Sin datos de tiempo registrados</div>
        <?php else: foreach($slaRows as $r):
          $slaPct = round($r['dentro_sla']/$r['con_tiempo']*100);
          $slaColor = $slaPct>=80?'#22c55e':($slaPct>=60?'#f59e0b':'#ef4444');
        ?>
        <div style="margin-bottom:10px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:3px">
            <span style="font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($r['project']) ?></span>
            <span style="font-size:13px;font-weight:800;color:<?= $slaColor ?>;flex-shrink:0;margin-left:8px"><?= $slaPct ?>%</span>
          </div>
          <div style="height:5px;background:var(--g7-bg-5);border-radius:3px;overflow:hidden">
            <div style="width:<?= $slaPct ?>%;height:100%;background:<?= $slaColor ?>;border-radius:3px"></div>
          </div>
          <div style="font-size:9px;color:var(--g7-text-5);margin-top:2px"><?= $r['dentro_sla'] ?>/<?= $r['con_tiempo'] ?> en tiempo</div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

  <?php elseif($section==='actividad'): ?>
    <?php
    $actRows = $db->query("
        SELECT c.id, c.name, c.type, c.project, c.edit_type, c.qty_graficas, c.qty_videos, c.qty_tareas,
               c.created_at, u.display_name, u.username, u.avatar
        FROM g7_content c
        JOIN g7_users u ON u.id=c.created_by
        WHERE c.estado='activo'
        ORDER BY c.created_at DESC
        LIMIT 50
    ")->fetchAll();
    $TYPE_LABELS_PHP2 = ['promo'=>'Promo','launch'=>'Launch','paid'=>'Paid','social'=>'Social','otro'=>'Otro','custom'=>'Custom','tarea'=>'Tarea'];
    ?>
    <div class="page-header">
      <div class="page-title">Acti<span>vidad</span></div>
      <div class="page-sub">Últimos 50 registros en tiempo real</div>
    </div>

    <div class="card">
      <?php foreach($actRows as $r):
        $name = $r['display_name']?:$r['username'];
        $initial = strtoupper(mb_substr($name,0,1));
        $dt = new DateTime($r['created_at']);
        $now = new DateTime();
        $diff = $now->diff($dt);
        if($diff->days>0) $timeAgo=$diff->days.'d';
        elseif($diff->h>0) $timeAgo=$diff->h.'h';
        elseif($diff->i>0) $timeAgo=$diff->i.'min';
        else $timeAgo='ahora';
        $typeLabel=$TYPE_LABELS_PHP2[$r['type']]??$r['type'];
        $icon=['promo'=>'fa-tag','launch'=>'fa-rocket','paid'=>'fa-dollar-sign','social'=>'fa-share-nodes','tarea'=>'fa-bolt','custom'=>'fa-puzzle-piece'][$r['type']]??'fa-layer-group';
        $isEdit=!empty($r['edit_type']);
        $total=(int)($r['qty_graficas']??0)+(int)($r['qty_videos']??0)+(int)($r['qty_tareas']??0);
      ?>
      <div style="display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-bottom:1px solid var(--g7-border)">
        <?php if($r['avatar']): ?>
        <img src="<?= htmlspecialchars($r['avatar']) ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0;margin-top:2px">
        <?php else: ?>
        <div style="width:32px;height:32px;border-radius:50%;background:var(--g7-accent-muted);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--g7-accent);flex-shrink:0;margin-top:2px"><?= $initial ?></div>
        <?php endif; ?>
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
            <span style="font-size:12px;font-weight:700"><?= htmlspecialchars($name) ?></span>
            <span style="font-size:10px;color:var(--g7-text-5)">subió</span>
            <span style="font-size:11px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px"><?= htmlspecialchars($r['name']) ?></span>
            <?php if($isEdit): ?><span style="font-size:9px;padding:1px 6px;border-radius:20px;background:<?= $r['edit_type']==='rechazo'?'rgba(239,68,68,.1)':'rgba(245,158,11,.1)' ?>;color:<?= $r['edit_type']==='rechazo'?'#ef4444':'#f59e0b' ?>"><?= $r['edit_type'] ?></span><?php endif; ?>
          </div>
          <div style="display:flex;align-items:center;gap:8px;margin-top:3px;flex-wrap:wrap">
            <span style="font-size:10px;color:var(--g7-text-5)"><i class="fa-solid <?= $icon ?>" style="margin-right:3px"></i><?= $typeLabel ?></span>
            <?php if($r['project']): ?><span style="font-size:10px;color:var(--g7-text-5)">· <?= htmlspecialchars($r['project']) ?></span><?php endif; ?>
            <?php if($total>0): ?><span style="font-size:10px;color:var(--g7-accent)">· <?= $total ?> assets</span><?php endif; ?>
          </div>
        </div>
        <span style="font-size:10px;color:var(--g7-text-5);flex-shrink:0;margin-top:2px"><?= $timeAgo ?></span>
      </div>
      <?php endforeach; ?>
    </div>

  <?php elseif($section==='comparativa'): ?>
    <?php
    $cmpPeriod = $_GET['cperiod'] ?? 'month';
    // Current period
    switch($cmpPeriod){
      case 'week':
        $dow = (int)date('N');
        $curStart = date('Y-m-d', strtotime('-'.($dow-1).' days'));
        $curEnd   = date('Y-m-d', strtotime('+'.(7-$dow).' days'));
        $prevStart= date('Y-m-d', strtotime($curStart.' -7 days'));
        $prevEnd  = date('Y-m-d', strtotime($curEnd.' -7 days'));
        $curLabel = 'Esta semana';
        $prevLabel= 'Semana anterior';
        break;
      case 'biweekly':
        $day = (int)date('j');
        if($day<=15){
          $curStart=date('Y-m-01'); $curEnd=date('Y-m-15');
          $prevStart=date('Y-m-16', strtotime('last month')); $prevEnd=date('Y-m-t', strtotime('last month'));
        } else {
          $curStart=date('Y-m-16'); $curEnd=date('Y-m-t');
          $prevStart=date('Y-m-01'); $prevEnd=date('Y-m-15');
        }
        $curLabel='Esta quincena'; $prevLabel='Quincena anterior';
        break;
      default:
        $curStart=date('Y-m-01'); $curEnd=date('Y-m-t');
        $prevStart=date('Y-m-01', strtotime('last month')); $prevEnd=date('Y-m-t', strtotime('last month'));
        $curLabel=date('F Y'); $prevLabel=date('F Y', strtotime('last month'));
    }

    // getCmpData and getCmpByProj defined at top of file

    $cmpCur  = getCmpData($db,$curStart,$curEnd);
    $cmpPrev = getCmpData($db,$prevStart,$prevEnd);
    $cmpCurP = getCmpByProj($db,$curStart,$curEnd);
    $cmpPrevP= getCmpByProj($db,$prevStart,$prevEnd);

    // delta defined at top of file
    ?>
    <div class="page-header">
      <div class="page-title">Compa<span>rativa</span></div>
      <div class="page-sub">Este período vs el anterior</div>
    </div>

    <!-- Period selector -->
    <div class="card" style="margin-bottom:16px">
      <div style="display:flex;gap:4px;background:var(--g7-bg-4);padding:3px;border-radius:8px;width:fit-content">
        <?php foreach(['week'=>'Semana','biweekly'=>'Quincena','month'=>'Mes'] as $k=>$v): ?>
        <a href="?section=comparativa&cperiod=<?= $k ?>" style="padding:6px 14px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;background:<?= $cmpPeriod===$k?'var(--g7-bg)':'transparent' ?>;color:<?= $cmpPeriod===$k?'var(--g7-accent)':'var(--g7-text-4)' ?>;transition:all .15s"><?= $v ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- KPI comparison -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px">
    <?php
    $kpiCmps=[
      ['Registros','fa-box','registros'],
      ['Assets','fa-images','assets'],
      ['Revisiones','fa-rotate','revisiones'],
      ['Rechazos','fa-xmark','rechazos'],
    ];
    foreach($kpiCmps as [$lbl,$icon,$key]):
      $cv=(int)($cmpCur[$key]??0); $pv=(int)($cmpPrev[$key]??0);
      [$dStr,$dColor]=delta($cv,$pv);
    ?>
    <div class="card" style="padding:14px 16px">
      <div style="font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px"><i class="fa-solid <?= $icon ?>" style="margin-right:5px"></i><?= $lbl ?></div>
      <div style="display:flex;align-items:flex-end;gap:8px">
        <div style="font-size:26px;font-weight:900;line-height:1"><?= $cv ?></div>
        <?php if($dStr): ?><div style="font-size:11px;font-weight:700;color:<?= $dColor ?>;margin-bottom:3px"><?= $dStr ?></div><?php endif; ?>
      </div>
      <div style="font-size:10px;color:var(--g7-text-5);margin-top:4px">vs <?= $pv ?> (<?= $prevLabel ?>)</div>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- Side by side by project -->
    <div class="card">
      <div style="font-size:11px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;margin-bottom:14px">Por proyecto</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <?php foreach([[$curLabel,$cmpCurP,'var(--g7-accent)'],[$prevLabel,$cmpPrevP,'var(--g7-text-5)']] as [$lbl,$data,$col]): ?>
        <div>
          <div style="font-size:11px;font-weight:700;color:<?= $col ?>;margin-bottom:10px"><?= $lbl ?></div>
          <?php $maxCR=max(1,max(array_column($data,'registros')??[1]));
          foreach($data as $r): ?>
          <div style="margin-bottom:8px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:3px">
              <span style="font-size:11px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($r['project']) ?></span>
              <span style="font-size:11px;font-weight:700;color:<?= $col ?>;flex-shrink:0;margin-left:8px"><?= $r['registros'] ?></span>
            </div>
            <div style="height:4px;background:var(--g7-bg-5);border-radius:2px;overflow:hidden">
              <div style="width:<?= round($r['registros']/$maxCR*100) ?>%;height:100%;background:<?= $col ?>;border-radius:2px;opacity:.8"></div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if(empty($data)): ?><div style="font-size:11px;color:var(--g7-text-5)">Sin datos</div><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  <?php elseif($section==='gamificacion'): ?>
    <?php
    if (!function_exists('g7GetUserGamif')) {
        echo '<div class="card" style="padding:20px;text-align:center;color:var(--g7-text-5)">Sistema de gamificación no disponible. Sube el archivo gamification.php a /toolz/</div>';
    } else {
        // Get all users with their gamification data
        $gamifUsers = $db->query("
            SELECT u.id, u.username, u.display_name, u.avatar,
                   COALESCE(x.xp_total,0) as xp_total,
                   COALESCE(x.nivel,'rookie') as nivel
            FROM g7_users u
            LEFT JOIN g7_user_xp x ON x.user_id=u.id
            WHERE u.active=1
            ORDER BY xp_total DESC
        ")->fetchAll();

        // Admin: recalc button
        if ($isAdmin && isset($_GET['recalc'])) {
            g7RecalcAllXP($db);
            header('Location: ?section=gamificacion');
            exit;
        }
    }
    ?>

    <div class="page-header">
      <div class="page-title">Gamifi<span>cación</span></div>
      <div class="page-sub">Niveles, XP y logros del equipo</div>
    </div>

    <?php if(!function_exists('g7GetUserGamif')): ?>
    <div class="card" style="padding:24px;text-align:center;color:var(--g7-text-5)">
      <i class="fa-solid fa-medal" style="font-size:32px;display:block;margin-bottom:12px;opacity:.2"></i>
      Sube el archivo <strong>gamification.php</strong> a /toolz/ para activar este módulo
    </div>
    <?php else: ?>

    <?php if($isAdmin): ?>
    <div style="margin-bottom:12px;text-align:right">
      <a href="?section=gamificacion&recalc=1" data-on-click="stsConfirm" data-click-1="s:¿Recalcular todo el XP desde cero?" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-3);border-radius:7px;padding:6px 12px;text-decoration:none;font-size:11px;font-weight:700">
        <i class="fa-solid fa-rotate-right"></i> Recalcular XP
      </a>
    </div>
    <?php endif; ?>

    <!-- LEVEL LEGEND -->
    <div class="card" style="margin-bottom:16px">
      <div style="font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;margin-bottom:12px">Niveles</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php foreach(G7_LEVELS as $key=>$lv): ?>
        <div style="display:flex;align-items:center;gap:6px;padding:6px 12px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:20px">
          <span style="font-size:16px"><?= $lv['emoji'] ?></span>
          <div>
            <div style="font-size:11px;font-weight:700;color:<?= $lv['color'] ?>"><?= $lv['label'] ?></div>
            <div style="font-size:9px;color:var(--g7-text-5)"><?= number_format($lv['min']) ?> XP</div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- LEADERBOARD -->
    <div class="card" style="margin-bottom:16px">
      <div style="font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;margin-bottom:14px">Tabla de XP</div>
      <?php foreach($gamifUsers as $i=>$u):
        $gData = g7GetUserGamif($db, $u['id']);
        $lv    = $gData['level_data'];
        $name  = $u['display_name']?:$u['username'];
        $maxXP = max(1, $gamifUsers[0]['xp_total'] ?? 1);
        $barW  = round($u['xp_total']/$maxXP*100);
        $posColors = ['#f59e0b','#a0aec0','#cd7f32'];
        $posColor  = $i < 3 ? $posColors[$i] : 'var(--g7-text-5)';
      ?>
      <div style="display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--g7-border)">
        <div style="width:24px;text-align:center;font-size:14px;font-weight:800;color:<?= $posColor ?>;flex-shrink:0"><?= $i+1 ?></div>
        <?php if($u['avatar']): ?>
        <img src="<?= htmlspecialchars($u['avatar']) ?>" style="width:42px;height:42px;border-radius:50%;object-fit:cover;flex-shrink:0">
        <?php else: ?>
        <div style="width:42px;height:42px;border-radius:50%;background:<?= $lv['color'] ?>20;border:2px solid <?= $lv['color'] ?>40;display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:700;color:<?= $lv['color'] ?>;flex-shrink:0"><?= strtoupper(mb_substr($name,0,1)) ?></div>
        <?php endif; ?>
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
            <span style="font-size:13px;font-weight:700"><?= htmlspecialchars($name) ?></span>
            <span style="font-size:13px"><?= $lv['emoji'] ?></span>
            <span style="font-size:10px;font-weight:700;color:<?= $lv['color'] ?>"><?= $lv['label'] ?></span>
            <!-- Badges -->
            <?php foreach($gData['badges'] as $b):
              $bData = G7_BADGES[$b['badge_key']] ?? null;
              if($bData): ?>
            <span title="<?= htmlspecialchars($bData['label']) ?>"><?= $bData['emoji'] ?></span>
            <?php endif; endforeach; ?>
          </div>
          <div style="display:flex;align-items:center;gap:8px">
            <div style="flex:1;height:6px;background:var(--g7-bg-5);border-radius:3px;overflow:hidden">
              <div style="width:<?= $barW ?>%;height:100%;background:<?= $lv['color'] ?>;border-radius:3px;transition:width .5s"></div>
            </div>
            <span style="font-size:11px;font-weight:800;color:<?= $lv['color'] ?>;flex-shrink:0"><?= number_format($u['xp_total']) ?> XP</span>
          </div>
          <?php if($gData['next_level']): ?>
          <div style="font-size:9px;color:var(--g7-text-5);margin-top:2px">
            <?= $gData['progress_pct'] ?>% hacia <?= $gData['next_level']['emoji'] ?> <?= $gData['next_level']['label'] ?>
            (<?= number_format($gData['next_level']['min'] - $u['xp_total']) ?> XP restantes)
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- BADGES OVERVIEW -->
    <div class="card">
      <div style="font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;margin-bottom:14px">Logros disponibles</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px">
        <?php foreach(G7_BADGES as $key=>$badge):
          // Check how many users have it
          $cnt = $db->prepare("SELECT COUNT(*) FROM g7_user_badges WHERE badge_key=?");
          $cnt->execute([$key]);
          $holders = (int)$cnt->fetchColumn();
          $myBadge = $db->prepare("SELECT unlocked_at FROM g7_user_badges WHERE user_id=? AND badge_key=?");
          $myBadge->execute([$currentUser['id'], $key]);
          $myRow = $myBadge->fetch();
          $iHave = (bool)$myRow;
        ?>
        <div style="padding:12px 14px;background:var(--g7-bg-4);border:1px solid <?= $iHave?'var(--g7-accent-border)':'var(--g7-border-3)' ?>;border-radius:10px;<?= $iHave?'':'opacity:.6' ?>">
          <div style="font-size:24px;margin-bottom:6px"><?= $badge['emoji'] ?></div>
          <div style="font-size:12px;font-weight:700;margin-bottom:2px"><?= $badge['label'] ?></div>
          <div style="font-size:10px;color:var(--g7-text-4);margin-bottom:6px"><?= $badge['desc'] ?></div>
          <div style="font-size:9px;color:var(--g7-text-5)">
            <?php if($iHave): ?>
            <span style="color:#22c55e"><i class="fa-solid fa-check" style="margin-right:3px"></i>Desbloqueado <?= date('d/m/Y', strtotime($myRow['unlocked_at'])) ?></span>
            <?php else: ?>
            <?= $holders ?> usuario<?= $holders!==1?'s':'' ?> lo tienen
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  <?php elseif($section==='bienestar' && $isAdmin):
    // ── BIENESTAR: "¿Cómo estás hoy?" del Inicio, siempre anónimo (mood_report: grupos de 3 personas o más). Gráficos en assets/stats-bienestar.js
    require_once __DIR__ . '/mood_lib.php';
    $bw = mood_report($db, $daysLimit);
    $bk = $bw['kpi']; $bLv = mood_level_of($bk['avg']); $bL = $bLv ? MOOD_LEVELS[$bLv] : null;
    $bNum = fn($v, $d = 1) => $v === null ? '—' : str_replace('.', ',', (string)round($v, $d));
    $bIns = ['good' => ['fa-circle-check', '#22c55e'], 'warn' => ['fa-triangle-exclamation', '#f59e0b'], 'info' => ['fa-lightbulb', '#60a5fa']];
    $bReasons = function (array $c, string $empty) { $max = $c ? max($c) : 1; $h = '';
        foreach ($c as $k => $n) $h .= '<div class="bw-rr"><span><i class="fa-solid ' . MOOD_REASONS[$k]['icon'] . '"></i>' . htmlspecialchars(MOOD_REASONS[$k]['label']) . '</span><b>' . (int)$n . '</b><i class="bw-bar" style="width:' . round($n / $max * 100) . '%"></i></div>';
        return $h ?: '<div class="bw-empty">' . $empty . '</div>'; };
  ?>
    <style>
    .bw-note{display:flex;gap:12px;align-items:center;padding:12px 16px;border-radius:12px;background:rgba(244,114,182,.07);border:1px solid rgba(244,114,182,.22);font-size:12.5px;color:var(--g7-text-2);margin-bottom:16px}
    .bw-note>i{font-size:18px;color:#f472b6}
    .bw-ins{display:flex;flex-direction:column;gap:8px;margin-bottom:16px}
    .bw-in{display:flex;gap:10px;align-items:flex-start;padding:11px 14px;border-radius:12px;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-left:3px solid var(--c);font-size:13px;line-height:1.45}
    .bw-in i{color:var(--c);margin-top:2px}
    .bw-face{display:inline-grid;place-items:center;width:30px;height:30px;border-radius:50%;color:#fff;font-size:15px}
    .bw-rr{position:relative;display:flex;justify-content:space-between;align-items:center;gap:10px;padding:7px 10px;margin-bottom:5px;border-radius:8px;overflow:hidden;font-size:12.5px}
    .bw-rr span{display:inline-flex;align-items:center;gap:8px;position:relative;z-index:1}.bw-rr span i{color:var(--g7-text-4);width:14px;text-align:center}
    .bw-rr b{position:relative;z-index:1;font-variant-numeric:tabular-nums}
    .bw-rr .bw-bar{position:absolute;left:0;top:0;bottom:0;background:var(--c,rgba(167,139,250,.14));border-radius:8px}
    .bw-low .bw-rr{--c:rgba(239,68,68,.14)}.bw-high .bw-rr{--c:rgba(34,197,94,.14)}
    .bw-empty{font-size:12px;color:var(--g7-text-4);padding:10px 0}
    .bw-team{display:grid;grid-template-columns:minmax(0,180px) 1fr 54px;gap:12px;align-items:center;padding:8px 0;border-top:1px solid var(--g7-border);font-size:12.5px}
    .bw-team:first-of-type{border-top:0}
    .bw-team span{display:flex;align-items:center;gap:8px;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.bw-team span i{width:9px;height:9px;border-radius:50%;flex:none}
    .bw-team .tr{height:8px;border-radius:8px;background:var(--g7-bg-5);overflow:hidden}.bw-team .tr i{display:block;height:100%;border-radius:8px}
    .bw-team b{text-align:right;font-variant-numeric:tabular-nums}.bw-team small{color:var(--g7-text-4);text-align:right}
    .grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:16px}
    @media (max-width:900px){.grid-3{grid-template-columns:1fr}.bw-team{grid-template-columns:minmax(0,120px) 1fr 44px}}
    </style>
    <div class="page-header"><div class="page-title">Bienes<span>tar</span></div><div class="page-sub"><?= $periodMap[$period] ?> &middot; respuestas de "¿Cómo estás hoy?" del Inicio</div></div>
    <div class="bw-note"><i class="fa-solid fa-user-secret"></i><div><b>Siempre anónimo.</b> Nadie puede ver qué respondió cada persona. Cada número sale de al menos <?= (int)$bw['min'] ?> personas distintas; los días o grupos con menos respuestas aparecen vacíos.</div></div>

    <?php if ($bw['insights']): ?><div class="bw-ins"><?php foreach ($bw['insights'] as [$kind, $txt]): [$ic, $c] = $bIns[$kind] ?? $bIns['info']; ?>
      <div class="bw-in" style="--c:<?= $c ?>"><i class="fa-solid <?= $ic ?>"></i><span><?= htmlspecialchars($txt) ?></span></div>
    <?php endforeach; ?></div><?php endif; ?>

    <div class="metrics">
      <div class="mc" style="border-top:2px solid <?= $bL['color'] ?? '#a78bfa' ?>"><div class="mc-top"><div class="mc-icon" style="background:rgba(167,139,250,.1)"><i class="fa-solid fa-heart-pulse" style="color:#f472b6"></i></div><?php if ($bL): ?><span class="bw-face" style="background:<?= $bL['color'] ?>" title="<?= htmlspecialchars($bL['label']) ?>"><i class="fa-solid <?= $bL['icon'] ?>"></i></span><?php endif; ?></div>
        <div class="mc-val"><?= $bNum($bk['avg']) ?><small style="font-size:13px;color:var(--g7-text-4)"> /5</small></div><div class="mc-label">Ánimo del equipo<?= $bL ? ' · ' . htmlspecialchars($bL['label']) : '' ?></div>
        <?php if ($bk['delta'] !== null): ?><div class="mc-sub" style="color:<?= $bk['delta'] >= 0 ? '#22c55e' : '#ef4444' ?>"><?= $bk['delta'] >= 0 ? '+' : '−' ?><?= $bNum(abs($bk['delta'])) ?> vs. período anterior</div><?php endif; ?></div>
      <div class="mc" style="border-top:2px solid #60a5fa"><div class="mc-top"><div class="mc-icon" style="background:rgba(96,165,250,.1)"><i class="fa-solid fa-hand" style="color:#60a5fa"></i></div></div>
        <div class="mc-val"><?= $bk['participation'] === null ? '—' : $bk['participation'] . '%' ?></div><div class="mc-label">Participación diaria</div><div class="mc-sub"><?= $bk['people'] ? (int)$bk['people'] . ' de ' . (int)$bk['active'] . ' personas respondieron' : 'pocas respuestas' ?></div></div>
      <div class="mc" style="border-top:2px solid #22c55e"><div class="mc-top"><div class="mc-icon" style="background:rgba(34,197,94,.1)"><i class="fa-solid fa-face-smile" style="color:#22c55e"></i></div></div>
        <div class="mc-val"><?= $bk['high'] === null ? '—' : $bk['high'] . '%' ?></div><div class="mc-label">Bien o Excelente</div></div>
      <div class="mc" style="border-top:2px solid #ef4444"><div class="mc-top"><div class="mc-icon" style="background:rgba(239,68,68,.1)"><i class="fa-solid fa-face-frown" style="color:#ef4444"></i></div></div>
        <div class="mc-val"><?= $bk['low'] === null ? '—' : $bk['low'] . '%' ?></div><div class="mc-label">Mal o Muy mal</div></div>
      <div class="mc" style="border-top:2px solid #f59e0b"><div class="mc-top"><div class="mc-icon" style="background:rgba(245,158,11,.1)"><i class="fa-solid fa-calendar-check" style="color:#f59e0b"></i></div></div>
        <div class="mc-val"><?= (int)$bk['days_ok'] ?></div><div class="mc-label">Días con datos</div><div class="mc-sub">de <?= (int)$daysLimit ?> del período</div></div>
    </div>

    <div class="card" id="bw-data" data-report="<?= htmlspecialchars(json_encode(['days' => $bw['days'], 'dow' => $bw['dow'], 'levels' => MOOD_LEVELS], JSON_UNESCAPED_UNICODE)) ?>">
      <div class="card-title"><i class="fa-solid fa-chart-line"></i> Estado diario del equipo · cuántos respondieron cada carita y el promedio</div>
      <div class="chart-wrap tall"><canvas id="bwDaily"></canvas></div>
    </div>
    <div class="grid-2">
      <div class="card"><div class="card-title"><i class="fa-solid fa-calendar-week"></i> Ánimo por día de la semana</div><div class="chart-wrap"><canvas id="bwDow"></canvas></div></div>
      <div class="card"><div class="card-title"><i class="fa-solid fa-people-group"></i> Por equipo</div>
        <?php $bTeams = array_filter($bw['teams'], fn($t) => $t['size'] > 0); if ($bTeams): foreach ($bTeams as $t): $tl = mood_level_of($t['avg']); ?>
        <div class="bw-team"><span><i style="background:<?= htmlspecialchars($t['color']) ?>"></i><?= htmlspecialchars($t['name']) ?></span>
          <?php if ($t['avg'] !== null): ?><div class="tr"><i style="width:<?= round($t['avg'] / 5 * 100) ?>%;background:<?= MOOD_LEVELS[$tl]['color'] ?>"></i></div><b><?= $bNum($t['avg']) ?></b>
          <?php else: ?><div class="tr"></div><small>pocas resp.</small><?php endif; ?></div>
        <?php endforeach; else: ?><div class="bw-empty">No hay equipos armados (Admin › Equipos).</div><?php endif; ?>
      </div>
    </div>
    <div class="grid-3">
      <div class="card"><div class="card-title"><i class="fa-solid fa-tags"></i> Motivos más elegidos</div><?= $bReasons($bw['reasons'], 'Todavía no hay suficientes respuestas con motivos.') ?></div>
      <div class="card bw-low"><div class="card-title"><i class="fa-solid fa-face-frown" style="color:#ef4444"></i> En los días malos</div><?= $bReasons($bw['reasons_low'], 'Pocas respuestas de "Mal" o "Muy mal" para mostrar.') ?></div>
      <div class="card bw-high"><div class="card-title"><i class="fa-solid fa-face-smile" style="color:#22c55e"></i> En los días buenos</div><?= $bReasons($bw['reasons_high'], 'Pocas respuestas de "Bien" o "Excelente" para mostrar.') ?></div>
    </div>
    <script src="/toolz/assets/stats-bienestar.js?v=<?= (int)@filemtime(__DIR__ . '/assets/stats-bienestar.js') ?>"></script>

  <?php elseif($section==='changelog'): ?>
    <div class="page-header">
      <div class="page-title">Change<span>log</span></div>
      <div class="page-sub">Historial de actualizaciones de la plataforma</div>
    </div>

    <?php if($isAdmin): ?>
    <div class="card" style="margin-bottom:20px">
      <div class="card-title"><i class="fa-solid fa-plus"></i> Nueva entrada</div>
      <div style="display:flex;gap:10px;margin-top:12px">
        <input type="text" id="cl-input" placeholder="Ej: Se agregó la sección de equipos con permisos por módulo..." 
          style="flex:1;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 12px;font-size:13px;outline:none;font-family:var(--g7-font)"
          data-on-keydown="clAdd" data-keydown-key="Enter"
          >
        <button data-on-click="clAdd" style="background:var(--g7-accent);border:none;color:#000;border-radius:8px;padding:8px 18px;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px">
          <i class="fa-solid fa-plus"></i> Agregar
        </button>
      </div>
      <div id="cl-error" style="display:none;color:#ef4444;font-size:11px;margin-top:6px"></div>
    </div>
    <?php endif; ?>

    <div class="card" id="cl-list-wrap">
      <?php
      try {
        $clEntries = $db->query("
          SELECT c.*, u.display_name, u.username, u.avatar
          FROM g7_changelog c
          JOIN g7_users u ON u.id=c.user_id
          ORDER BY c.created_at DESC
          LIMIT 200
        ")->fetchAll();
      } catch(Exception $e) { $clEntries = []; }
      ?>
      <?php if(empty($clEntries)): ?>
      <div style="text-align:center;color:var(--g7-text-5);padding:32px;font-size:13px">
        <i class="fa-solid fa-clock-rotate-left" style="font-size:28px;display:block;margin-bottom:10px;opacity:.3"></i>
        Sin entradas todavía
      </div>
      <?php else: ?>
      <?php
        $prevDate = '';
        foreach($clEntries as $entry):
          $entryDate = date('d/m/Y', strtotime($entry['created_at']));
          $entryTime = date('H:i', strtotime($entry['created_at']));
          $authorName = $entry['display_name'] ?: $entry['username'];
      ?>
      <?php if($entryDate !== $prevDate): ?>
        <div style="font-size:10px;font-weight:700;color:var(--g7-text-5);text-transform:uppercase;letter-spacing:.08em;padding:<?= $prevDate ? '14px' : '0' ?> 0 8px;<?= $prevDate ? 'border-top:1px solid var(--g7-border);margin-top:6px' : '' ?>"><?= $entryDate ?></div>
        <?php $prevDate = $entryDate; ?>
      <?php endif; ?>
      <div class="cl-entry" id="cle-<?= $entry['id'] ?>" style="display:flex;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid var(--g7-border)">
        <div style="display:flex;align-items:center;gap:4px;flex-shrink:0;padding-top:2px">
          <span style="font-size:11px;color:var(--g7-text-4);min-width:36px"><?= $entryTime ?></span>
          <?php if($entry['avatar']): ?>
          <img src="<?= htmlspecialchars($entry['avatar']) ?>" style="width:22px;height:22px;border-radius:50%;object-fit:cover">
          <?php else: ?>
          <div style="width:22px;height:22px;border-radius:50%;background:var(--g7-accent-muted);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:var(--g7-accent)"><?= strtoupper(mb_substr($authorName,0,1)) ?></div>
          <?php endif; ?>
        </div>
        <div style="flex:1;min-width:0">
          <span style="font-size:13px;color:var(--g7-text)"><?= htmlspecialchars($entry['text']) ?></span>
          <span style="font-size:10px;color:var(--g7-text-5);margin-left:8px">— <?= htmlspecialchars($authorName) ?></span>
        </div>
        <?php if($isAdmin): ?>
        <button data-on-click="clDelete" data-click-1="n:<?= (int)$entry['id'] ?>" class="cl-del" style="background:none;border:none;color:var(--g7-text-5);cursor:pointer;font-size:11px;padding:2px 5px;opacity:.5;transition:opacity .15s" title="Eliminar"><i class="fa-solid fa-xmark"></i></button>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <script src="/toolz/assets/stats-changelog.js?v=<?= (int)@filemtime(__DIR__ . '/assets/stats-changelog.js') ?>"></script>

<?php elseif($section==='landings'): ?>
    <?php
    $COUNTRY_FLAGS=['Argentina'=>'🇦🇷','Uruguay'=>'🇺🇾','Chile'=>'🇨🇱','Brasil'=>'🇧🇷','Colombia'=>'🇨🇴','México'=>'🇲🇽','Peru'=>'🇵🇪','Venezuela'=>'🇻🇪','España'=>'🇪🇸','United States'=>'🇺🇸','United Kingdom'=>'🇬🇧'];
    $DEV_ICONS=['desktop'=>'🖥️','mobile'=>'📱','tablet'=>'📟'];
    $REF_ICONS=['Directo'=>'🔗','google.com'=>'🔍','facebook.com'=>'📘','instagram.com'=>'📸','twitter.com'=>'🐦','t.co'=>'🐦','whatsapp.com'=>'💬','telegram.org'=>'✈️','tiktok.com'=>'🎵'];
    $devMap=[];foreach($landingByDevice as $r)$devMap[$r['device']]=(int)$r['cnt'];
    $devTotal=array_sum($devMap)?:1;
    $maxCountry=$landingByCountry?max(array_column($landingByCountry,'cnt')):1;
    $maxRef=$landingByReferrer?max(array_column($landingByReferrer,'cnt')):1;
    $maxTop=$landingTopByViews?max(array_column($landingTopByViews,'views')):1;
    ?>
    <div class="page-header">
      <div class="page-title">Landings <span>estadísticas</span></div>
      <div class="page-sub"><?= $periodMap[$period] ?> &middot; <?= $landingTotalPublished ?> publicadas</div>
    </div>
    <div class="metrics">
      <div class="mc" style="border-top:2px solid #34d399"><div class="mc-top"><div class="mc-icon" style="background:rgba(52,211,153,.1)"><i class="fa-solid fa-eye" style="color:#34d399"></i></div></div><div class="mc-val" style="color:#34d399"><?= number_format($landingTotalViews) ?></div><div class="mc-label">Vistas totales</div><div class="mc-sub"><?= $periodMap[$period] ?></div></div>
      <div class="mc" style="border-top:2px solid #60a5fa"><div class="mc-top"><div class="mc-icon" style="background:rgba(96,165,250,.1)"><i class="fa-solid fa-user" style="color:#60a5fa"></i></div></div><div class="mc-val" style="color:#60a5fa"><?= number_format($landingUniqueViews) ?></div><div class="mc-label">Visitantes únicos</div><div class="mc-sub">por IP</div></div>
      <div class="mc" style="border-top:2px solid #f59e0b"><div class="mc-top"><div class="mc-icon" style="background:rgba(245,158,11,.1)"><i class="fa-solid fa-calendar-day" style="color:#f59e0b"></i></div></div><div class="mc-val" style="color:#f59e0b"><?= number_format($landingViewsToday) ?></div><div class="mc-label">Vistas hoy</div></div>
      <div class="mc" style="border-top:2px solid #a78bfa"><div class="mc-top"><div class="mc-icon" style="background:rgba(167,139,250,.1)"><i class="fa-solid fa-rocket" style="color:#a78bfa"></i></div></div><div class="mc-val" style="color:#a78bfa"><?= $landingTotalPublished ?></div><div class="mc-label">Publicadas</div><div class="mc-sub"><a href="/toolz/landing-gen/" style="color:#a78bfa;text-decoration:none;font-size:10px">Ir a landings →</a></div></div>
      <div class="mc" style="border-top:2px solid var(--g7-accent)"><div class="mc-top"><div class="mc-icon" style="background:rgba(var(--g7-accent-rgb),.1)"><i class="fa-solid fa-chart-line" style="color:var(--g7-accent)"></i></div></div><div class="mc-val" style="color:var(--g7-accent)"><?= $daysLimit>0?round($landingTotalViews/$daysLimit):0 ?></div><div class="mc-label">Prom. vistas/día</div></div>
    </div>

    <div class="card" style="margin-bottom:16px">
      <div class="card-title"><i class="fa-solid fa-chart-bar" style="color:#34d399"></i> Vistas por día</div>
      <div class="chart-wrap"><canvas id="chartLandingDays"></canvas></div>
    </div>

    <div class="grid-2" style="margin-bottom:16px">
      <!-- Top landings -->
      <div class="card">
        <div class="card-title"><i class="fa-solid fa-ranking-star" style="color:#f59e0b"></i> Ranking de landings</div>
        <?php if($landingTopByViews): foreach($landingTopByViews as $i=>$r): $pct=$maxTop>0?round($r['views']/$maxTop*100):0; ?>
        <div style="margin-bottom:10px">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
            <span style="font-size:11px;font-weight:700;color:var(--g7-text-4);min-width:16px"><?= $i+1 ?>.</span>
            <span style="font-size:12px;font-weight:700;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($r['name']) ?></span>
            <span style="font-size:10px;color:var(--g7-text-4);background:var(--g7-bg-4);border-radius:4px;padding:2px 6px"><?= htmlspecialchars($r['platform']) ?></span>
            <span style="font-size:12px;font-weight:800;color:#34d399;min-width:32px;text-align:right"><?= number_format($r['views']) ?></span>
            <?php if($r['public_url']): ?><a href="/toolz/landing-gen/stats.php?id=<?= $r['id'] ?>" style="color:#a78bfa;font-size:11px" title="Ver stats detalladas"><i class="fa-solid fa-chart-mixed"></i></a><?php endif; ?>
          </div>
          <div style="display:flex;align-items:center;gap:8px">
            <div style="width:16px"></div>
            <div style="flex:1;height:5px;background:var(--g7-bg-5);border-radius:3px;overflow:hidden"><div style="height:100%;width:<?= $pct ?>%;background:linear-gradient(90deg,#34d399,#60a5fa);border-radius:3px"></div></div>
            <span style="font-size:9px;color:var(--g7-text-4);min-width:60px"><?= number_format($r['unique_views']) ?> únicos</span>
          </div>
        </div>
        <?php endforeach; else: ?>
        <div style="text-align:center;color:var(--g7-text-5);font-size:12px;padding:20px"><i class="fa-solid fa-rocket" style="display:block;font-size:20px;margin-bottom:8px;opacity:.3"></i>Sin datos aún.<br>Publica una landing para empezar a medir.</div>
        <?php endif; ?>
      </div>

      <!-- Dispositivos + Fuentes -->
      <div style="display:flex;flex-direction:column;gap:14px">
        <div class="card" style="flex:1">
          <div class="card-title"><i class="fa-solid fa-mobile-screen-button" style="color:#60a5fa"></i> Dispositivos</div>
          <div style="display:flex;gap:12px;justify-content:space-around;padding:8px 0">
            <?php foreach(['desktop'=>'PC','mobile'=>'Móvil','tablet'=>'Tablet'] as $dev=>$lbl): $cnt=$devMap[$dev]??0; $pct=round($cnt/$devTotal*100); ?>
            <div style="text-align:center">
              <div style="font-size:24px;margin-bottom:4px"><?= $DEV_ICONS[$dev] ?></div>
              <div style="font-size:20px;font-weight:800;color:#34d399"><?= $pct ?>%</div>
              <div style="height:4px;width:60px;background:var(--g7-bg-5);border-radius:2px;margin:5px auto"><div style="height:100%;width:<?= $pct ?>%;background:#34d399;border-radius:2px"></div></div>
              <div style="font-size:9px;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.06em"><?= $lbl ?></div>
              <div style="font-size:10px;color:var(--g7-text-5);margin-top:2px"><?= number_format($cnt) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="card" style="flex:1">
          <div class="card-title"><i class="fa-solid fa-share-nodes" style="color:#a78bfa"></i> Fuentes de tráfico</div>
          <?php foreach($landingByReferrer as $r): $pct=$maxRef>0?round($r['cnt']/$maxRef*100):0; ?>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:7px">
            <span style="font-size:13px"><?= $REF_ICONS[$r['src']]??'🌐' ?></span>
            <span style="font-size:11px;flex:1;color:var(--g7-text-2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($r['src']) ?></span>
            <div style="width:70px;height:5px;background:var(--g7-bg-5);border-radius:3px"><div style="height:100%;width:<?= $pct ?>%;background:#a78bfa;border-radius:3px"></div></div>
            <span style="font-size:11px;font-weight:700;min-width:28px;text-align:right"><?= $r['cnt'] ?></span>
          </div>
          <?php endforeach; if(!$landingByReferrer): ?><div style="color:var(--g7-text-5);font-size:12px;text-align:center;padding:12px">Sin datos aún</div><?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Países -->
    <div class="card">
      <div class="card-title"><i class="fa-solid fa-earth-americas" style="color:#f59e0b"></i> Países</div>
      <?php if($landingByCountry): ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:8px">
        <?php foreach($landingByCountry as $r): $pct=$maxCountry>0?round($r['cnt']/$maxCountry*100):0; ?>
        <div style="display:flex;align-items:center;gap:10px">
          <span style="font-size:16px"><?= $COUNTRY_FLAGS[$r['country']]??'🌍' ?></span>
          <span style="font-size:11px;flex:1;color:var(--g7-text-2)"><?= htmlspecialchars($r['country']) ?></span>
          <div style="width:80px;height:5px;background:var(--g7-bg-5);border-radius:3px"><div style="height:100%;width:<?= $pct ?>%;background:linear-gradient(90deg,#f59e0b,#ff6600);border-radius:3px"></div></div>
          <span style="font-size:11px;font-weight:700;min-width:28px;text-align:right"><?= $r['cnt'] ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?><div style="text-align:center;color:var(--g7-text-5);font-size:12px;padding:16px">Sin datos de países aún</div><?php endif; ?>
    </div>

<?php elseif($section==='jira' && g7_jira_stats_on()): include __DIR__ . '/stats_jira.php'; ?>

<?php endif; ?>

  </div>
</div>

<script type="application/json" id="sts-chart-data"><?= json_encode(['daysData' => $clicksPerDay, 'hoursRaw' => $peakHours, 'dowData' => $clicksByDay, 'postsData' => $postsPerDay, 'actTypes' => $activityTypes, 'contentDayData' => $contentPerDay, 'contentTypeData' => $contentByType, 'landingDaysRaw' => $landingViewsPerDay, 'daysLimit' => $daysLimit], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="/toolz/assets/stats-charts.js?v=<?= (int)@filemtime(__DIR__ . '/assets/stats-charts.js') ?>"></script>
</body>