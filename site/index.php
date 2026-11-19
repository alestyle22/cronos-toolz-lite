<?php
ini_set('display_errors','0');
error_reporting(E_ALL);
require_once __DIR__ . '/auth.php';
$currentUser = requireLogin();
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo: esta página no tiene código en línea (SEC-24)
$isAdmin     = ($currentUser['role'] === 'admin');
$db          = getDB();
// Página de solo lectura sobre contenido: los números y listas quedan filtrados por la marca del usuario.
g7_brand_scope_readonly((int)$currentUser['id']);

try {
    $db->exec("CREATE TABLE IF NOT EXISTS g7_accesos_rapidos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        tool VARCHAR(100) NOT NULL,
        tool_name VARCHAR(100) NOT NULL DEFAULT '',
        accessed_at DATETIME DEFAULT NOW(),
        INDEX idx_user (user_id),
        INDEX idx_time (accessed_at)
    )");
} catch(Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'track_acceso') {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS g7_accesos_rapidos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                tool VARCHAR(255) NOT NULL,
                tool_name VARCHAR(255) NOT NULL DEFAULT '',
                meta VARCHAR(50) DEFAULT '',
                accessed_at DATETIME DEFAULT NOW(),
                INDEX idx_user (user_id),
                INDEX idx_time (accessed_at)
            )");
            try { $db->exec("ALTER TABLE g7_accesos_rapidos ADD COLUMN IF NOT EXISTS meta VARCHAR(50) DEFAULT ''"); } catch(Exception $e2) {}
            $tool = substr(trim($_POST['tool'] ?? ''), 0, 255);
            $name = substr(trim($_POST['tool_name'] ?? $tool), 0, 255);
            $meta = substr(trim($_POST['meta'] ?? ''), 0, 50);
            if ($tool) {
                $db->prepare("INSERT INTO g7_accesos_rapidos (user_id, tool, tool_name, meta) VALUES (?,?,?,?)")
                   ->execute([$currentUser['id'], $tool, $name, $meta]);
            }
        } catch(Exception $e) {}
        echo json_encode(['ok'=>true]);
        exit;
    }
}
$_uRow = $db->prepare('SELECT * FROM g7_users WHERE id=?'); $_uRow->execute([$currentUser['id']]); $_uFull = $_uRow->fetch();
if ($_uFull) $currentUser = array_merge($currentUser, $_uFull);

$mondayData = [];
if (is_file(__DIR__ . '/monday_widget.php') && g7_href_visible((int)$currentUser['id'], '/toolz/monday.php')) {
    require_once __DIR__ . '/monday_widget.php';
    $mondayData = fetchFedericoTasks();
}
$mondayItems = $mondayData['items'] ?? [];
$mondayError = $mondayData['error'] ?? null;

$db->prepare('UPDATE g7_users SET session_start=NOW() WHERE id=?')->execute([$currentUser['id']]);

$favs = $db->prepare("SELECT l.*, c.name as cat_name FROM g7_link_favorites f JOIN g7_links l ON l.id=f.link_id JOIN g7_categories c ON c.id=l.category_id WHERE f.user_id=? ORDER BY f.created_at DESC LIMIT 8"); $favs->execute([$currentUser['id']]); $myFavs = $favs->fetchAll();
$popular = $db->query("SELECT l.*, c.name as cat_name, COUNT(k.id) as clicks FROM g7_link_clicks k JOIN g7_links l ON l.id=k.link_id JOIN g7_categories c ON c.id=l.category_id WHERE k.clicked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY k.link_id ORDER BY clicks DESC LIMIT 8")->fetchAll();
$promosSoon = $db->query("SELECT name, project, end_date, type FROM g7_content WHERE end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY end_date ASC LIMIT 6")->fetchAll();
$promosToday = $db->query("SELECT name, project, type FROM g7_content WHERE end_date = CURDATE()")->fetchAll();
$recentContent = $db->query("SELECT name, type, project, created_at FROM g7_content ORDER BY created_at DESC LIMIT 6")->fetchAll();
$myClicksWeek = $db->prepare("SELECT COUNT(*) FROM g7_link_clicks WHERE user_id=? AND clicked_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)"); $myClicksWeek->execute([$currentUser['id']]); $myClicks = $myClicksWeek->fetchColumn();
$totalLinks = $db->query('SELECT COUNT(*) FROM g7_links')->fetchColumn();
$totalFavs = $db->prepare('SELECT COUNT(*) FROM g7_link_favorites WHERE user_id=?'); $totalFavs->execute([$currentUser['id']]); $myFavCount = $totalFavs->fetchColumn();
$activePromos = $db->query("SELECT COUNT(*) FROM g7_content WHERE end_date >= CURDATE() OR end_date IS NULL")->fetchColumn();
$totalContent = $db->query("SELECT COUNT(*) FROM g7_content")->fetchColumn();
$recentPosts = $db->query("SELECT p.*,u.display_name,u.username,u.avatar FROM g7_posts p JOIN g7_users u ON u.id=p.user_id ORDER BY p.created_at DESC LIMIT 4")->fetchAll();
require_once __DIR__ . '/activity_helper.php';
$recentActivity = g7GetRecentActivity($db, 8);
// No mostrar datos de módulos a los que el usuario no tiene acceso
$_uidIdx = (int)$currentUser['id'];
if (!g7_can($_uidIdx, 'promos')) { $promosSoon = $promosToday = $recentContent = []; }
if (!g7_can($_uidIdx, 'links'))  { $myFavs = $popular = []; }
if (!g7_can($_uidIdx, 'monday')) { $mondayItems = []; }
if (!g7_href_visible($_uidIdx, '/toolz/comunidad.php')) { $recentPosts = []; }

$TYPE_LABELS = ['promo'=>'Promo','launch'=>'Lanzamiento','paid'=>'Paid','social'=>'Redes'];
$TYPE_COLORS = ['promo'=>'#ff6600','launch'=>'#a78bfa','paid'=>'#4ade80','social'=>'#60a5fa'];
function rpAvHtml($av,$name,$size){if($av)return'<img src="'.$av.'" style="width:'.$size.'px;height:'.$size.'px;border-radius:50%;object-fit:cover;flex-shrink:0">';$i=strtoupper(substr($name,0,1));$c=['#ff6600','#a78bfa','#4ade80','#60a5fa','#f59e0b','#ec4899'][ord($i)%6];return'<div style="width:'.$size.'px;height:'.$size.'px;border-radius:50%;background:'.$c.';display:flex;align-items:center;justify-content:center;font-size:'.floor($size*.42).'px;font-weight:700;color:#000;flex-shrink:0">'.$i.'</div>';}

try {
    $_idx_pref_q = $db->prepare("SELECT pref_key,pref_value FROM g7_user_prefs WHERE user_id=? AND pref_key IN ('theme','font_size')");
    $_idx_pref_q->execute([$currentUser['id']]);
    $_idx_prefs = [];
    foreach($_idx_pref_q->fetchAll() as $_r) $_idx_prefs[$_r['pref_key']]=json_decode($_r['pref_value'],true);
    $_idx_theme = $_idx_prefs['theme'] ?? 'dark';
    $_idx_font  = $_idx_prefs['font_size'] ?? 'medium';
} catch(Exception $e) { $_idx_theme='dark'; $_idx_font='medium'; }
?>
<?php
// ── Datos de presentación del Inicio (v3) ────────────────────
$d3Name  = $currentUser['display_name'] ?: $currentUser['username'];
$d3First = explode(' ', trim($d3Name))[0];
$d3H     = (int)date('G');
$d3Hello = $d3H < 6 ? 'Buenas noches' : ($d3H < 13 ? 'Buen día' : ($d3H < 20 ? 'Buenas tardes' : 'Buenas noches'));
$d3Days  = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
$d3Mon   = ['','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$d3Date  = ucfirst($d3Days[(int)date('w')]) . ' ' . (int)date('j') . ' de ' . $d3Mon[(int)date('n')];
$d3Cat   = g7_tool_catalog($_uidIdx);
// Placas deportivas abre su hub (ligas, jugadores, generador); el generador viejo sigue en placasdeportivas/
if (isset($d3Cat['placas'])) $d3Cat['placas']['url'] = '/toolz/placasdeportivas/hub.php';
// Accesos rápidos y secciones: los arma el admin en Admin › Inicio y accesos (home_lib.php)
require_once __DIR__ . '/home_lib.php';
$H = home_config();
// Cada persona arma su Inicio (g7_user_prefs 'home_layout'): orden de las secciones, cuáles oculta y qué accesos rápidos no quiere ver.
// Lo que el admin apagó no se puede prender desde acá; lo que la persona no tocó sigue el orden del admin.
$myHome = [];
try {
    $q = $db->prepare("SELECT pref_value FROM g7_user_prefs WHERE user_id = ? AND pref_key = 'home_layout'"); $q->execute([$_uidIdx]);
    $myHome = json_decode((string)$q->fetchColumn(), true) ?: [];
} catch (Throwable $e) {}
$d3AdminSecs = array_values(array_filter($H['sections'], fn($s) => $s['on']));
$byId = array_column($H['sections'], null, 'id'); $mySecs = [];
foreach ((array)($myHome['order'] ?? []) as $id) if (is_string($id) && isset($byId[$id])) { $mySecs[] = $byId[$id]; unset($byId[$id]); }
foreach ($H['sections'] as $s) if (isset($byId[$s['id']])) $mySecs[] = $s;
$myHidden = array_filter((array)($myHome['hidden'] ?? []), 'is_string');
foreach ($mySecs as &$s) if (in_array($s['id'], $myHidden, true)) $s['on'] = false;
unset($s);
$H['sections'] = $mySecs;
$myTilesHidden = array_filter((array)($myHome['tiles_hidden'] ?? []), 'is_string');
$d3AllTiles = [];
$d3Quick = [];
foreach ($H['tiles'] as $t) {
    if (!home_tile_visible($_uidIdx, $t)) continue;
    $d3AllTiles[] = ['id' => $t['id'], 'name' => $t['name'], 'icon' => $t['icon'], 'color' => $t['color']];
    if (in_array($t['id'], $myTilesHidden, true)) continue;
    // Placas deportivas abre el hub aunque el bloque guardado apunte a otra entrada
    if (in_array(rtrim($t['href'], '/'), ['/toolz/placasdeportivas', '/toolz/sport_placas.php'], true)) $t['href'] = '/toolz/placasdeportivas/hub.php';
    $d3Quick[] = $t + ['c' => home_rgb($t['color'])];
}
$d3On = [];
foreach ($H['sections'] as $_s) $d3On[$_s['id']] = $_s['on'];
if (empty($d3On['tiles'])) $d3Quick = [];
$d3QuickMods = array_filter(array_map(fn($t) => str_starts_with($t['href'], '/') ? g7_module_for_href($t['href']) : null, $d3Quick));
// Más herramientas: las que no tienen bloque
$d3Groups = [];
foreach ($d3Cat as $id => $t) if (!in_array($id, $d3QuickMods, true)) $d3Groups[$t['group']][] = $t;
$d3MoreCount = array_sum(array_map('count', $d3Groups));
// Son pocas: una sola grilla, sin subtítulos por grupo
if ($d3Groups) $d3Groups = ['' => array_merge(...array_values($d3Groups))];
// Sigue donde dejaste: últimas herramientas distintas que abrió esta persona
$d3Recent = [];
try {
    $q = $db->prepare("SELECT tool, MAX(accessed_at) t FROM g7_accesos_rapidos WHERE user_id=? GROUP BY tool ORDER BY t DESC LIMIT 15");
    $q->execute([$_uidIdx]);
    foreach ($q->fetchAll() as $r) {
        $m = g7_module_for_href((string)$r['tool']);
        if ($m && isset($d3Cat[$m]) && !isset($d3Recent[$m])) $d3Recent[$m] = $d3Cat[$m] + ['when' => $r['t']];
        if (count($d3Recent) >= 4) break;
    }
} catch (Exception $e) {}
$d3Scope = g7_brand_scope($_uidIdx);
$d3Brand = ($d3Scope !== null && count($d3Scope) === 1) ? (g7_brands()[$d3Scope[0]] ?? null) : null;
$d3Ago = function ($dt) {
    $s = time() - strtotime((string)$dt);
    if ($s < 60) return 'recién'; if ($s < 3600) return 'hace ' . floor($s / 60) . ' min';
    if ($s < 86400) return 'hace ' . floor($s / 3600) . ' h'; if ($s < 172800) return 'ayer';
    return 'hace ' . floor($s / 86400) . ' días';
};
$d3Img = function ($img) {
    if (!$img) return '';
    if (strpos($img, 'http') === 0 || strpos($img, '/toolz/') === 0) return $img;
    return strpos($img, 'link_covers/') === 0 ? '/toolz/' . $img : '/toolz/link_covers/' . basename($img);
};
$d3SoonCount = count($promosSoon);
?>
<!DOCTYPE html>
<html lang="es" class="theme-<?= htmlspecialchars($_idx_theme) ?> font-<?= htmlspecialchars($_idx_font) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Inicio · <?= htmlspecialchars(g7_setting('platform_name') ?: g7_site('name')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<link rel="stylesheet" href="/toolz/g7-theme.css?v=4">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--g7-bg);color:var(--g7-text);font-family:var(--g7-font);min-height:100dvh}
body::before{content:"";position:fixed;inset:0;pointer-events:none;z-index:0;
  background:radial-gradient(900px 420px at 12% -8%,var(--g7-accent-muted),transparent 60%),radial-gradient(700px 380px at 100% 0,rgba(var(--g7-ov),.025),transparent 60%)}
.d3{position:relative;z-index:1;max-width:1240px;margin:0 auto;padding:40px 32px 72px}
.d3 h2{font-size:15px;font-weight:600;letter-spacing:-.01em;color:var(--g7-text);display:flex;align-items:center;gap:8px}
.d3 h2 small{font-size:12px;font-weight:500;color:var(--g7-text-4)}
.d3-sec{margin-top:40px}
.d3-sec-head{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:14px}
.d3-link{font-size:12px;font-weight:500;color:var(--g7-text-3);text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:color .15s}
.d3-link:hover{color:var(--g7-accent)}
.d3-link i{font-size:9px;transition:transform .15s}.d3-link:hover i{transform:translateX(2px)}

/* Encabezado */
.d3-head{display:flex;align-items:flex-end;justify-content:space-between;gap:24px;flex-wrap:wrap}
.d3-date{font-size:12.5px;font-weight:500;color:var(--g7-text-4);margin-bottom:8px}
.d3-head h1{font-size:clamp(26px,3.2vw,36px);font-weight:700;letter-spacing:-.035em;line-height:1.05;text-wrap:balance}
.d3-head h1 span{color:var(--g7-accent)}
.d3-sub{margin-top:10px;font-size:13.5px;color:var(--g7-text-3);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.d3-brand{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;padding:3px 9px;border-radius:7px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-2)}
.d3-brand i{width:7px;height:7px;border-radius:50%}
.d3-search{display:flex;align-items:center;gap:10px;width:min(340px,100%);height:42px;padding:0 12px 0 14px;background:var(--g7-bg-3);border:1px solid var(--g7-border-3);border-radius:12px;transition:border-color .15s,box-shadow .15s}
.d3-search:focus-within{border-color:var(--g7-accent-border);box-shadow:0 0 0 4px var(--g7-accent-muted)}
.d3-search i{color:var(--g7-text-4);font-size:13px}
.d3-search input{flex:1;min-width:0;background:none;border:none;outline:none;color:var(--g7-text);font:500 13.5px var(--g7-font)}
.d3-search input::placeholder{color:var(--g7-text-4)}
.d3-search kbd{font:600 10.5px var(--g7-font-mono);color:var(--g7-text-4);border:1px solid var(--g7-border-3);border-radius:5px;padding:1px 6px}

/* Aviso de hoy */
.d3-alert{margin-top:28px;display:flex;gap:14px;align-items:flex-start;padding:14px 16px;border-radius:12px;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.2)}
.d3-alert>i{color:#f87171;margin-top:2px}
.d3-alert b{display:block;font-size:13px;font-weight:600;color:#fca5a5;margin-bottom:4px}
.d3-alert ul{list-style:none;font-size:12.5px;color:var(--g7-text-2);line-height:1.7}
.d3-alert ul span{color:var(--g7-text-4)}

/* Sigue donde dejaste */
.d3-recent{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px}
.d3-rc{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:12px;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);text-decoration:none;color:inherit;transition:border-color .15s,transform .15s,background .15s}
.d3-rc:hover{border-color:var(--g7-accent-border);background:var(--g7-bg-4);transform:translateY(-1px)}
.d3-rc b{display:block;font-size:13px;font-weight:600}
.d3-rc small{font-size:11.5px;color:var(--g7-text-4)}
.d3-rc .fa-arrow-right{margin-left:auto;font-size:11px;color:var(--g7-text-5);transition:color .15s,transform .15s}
.d3-rc:hover .fa-arrow-right{color:var(--g7-accent);transform:translateX(2px)}

/* Íconos */
.d3-ic{width:38px;height:38px;border-radius:11px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:15px;color:var(--g7-text-2);background:var(--g7-bg-5);border:1px solid var(--g7-border-3);transition:color .15s,background .15s,border-color .15s}
a:hover .d3-ic{color:var(--g7-accent);background:var(--g7-accent-muted);border-color:var(--g7-accent-border)}

/* Herramientas */
.d3-group{margin-top:22px}
.d3-group:first-of-type{margin-top:0}
.d3-group-t{font-size:11.5px;font-weight:600;color:var(--g7-text-4);margin-bottom:10px;padding-left:2px}
.d3-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:10px}
.d3-tool{position:relative;display:flex;align-items:center;gap:14px;padding:14px 16px;border-radius:14px;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);text-decoration:none;color:inherit;overflow:hidden;transition:border-color .2s,background .2s,transform .2s}
.d3-tool::after{content:"";position:absolute;inset:0;pointer-events:none;opacity:0;transition:opacity .2s;
  background:radial-gradient(260px 120px at var(--mx,50%) var(--my,50%),var(--g7-accent-muted),transparent 70%)}
.d3-tool:hover{border-color:var(--g7-border-3);background:var(--g7-bg-4);transform:translateY(-1px)}
.d3-tool:hover::after{opacity:1}
.d3-tool:active{transform:translateY(0) scale(.995)}
.d3-tool b{display:block;font-size:13.5px;font-weight:600;letter-spacing:-.01em}
.d3-tool small{display:block;font-size:12px;color:var(--g7-text-4);margin-top:2px;line-height:1.35}
/* Accesos rápidos (bloques de colores) */
.d3-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(176px,1fr));gap:12px}
.tc{border-radius:16px;overflow:hidden;text-decoration:none;color:var(--g7-text);display:flex;flex-direction:column;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);transition:transform .2s cubic-bezier(.2,.8,.2,1),box-shadow .2s,border-color .2s}
.tc:hover{transform:translateY(-3px);box-shadow:0 18px 40px rgba(0,0,0,.45);border-color:var(--g7-border-3)}
.tc:active{transform:translateY(-1px)}
.tc-art{height:118px;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}
.tc-glow{position:absolute;inset:0;opacity:.3;transition:opacity .25s}
.tc:hover .tc-glow{opacity:.55}
.tc-art-icon{font-size:36px;position:relative;z-index:1;transition:transform .25s cubic-bezier(.2,.8,.2,1)}
.tc:hover .tc-art-icon{transform:scale(1.08)}
.tc-badge{position:absolute;top:9px;right:9px;font:700 9px var(--g7-font-mono);border-radius:5px;padding:2px 6px;letter-spacing:.04em}
.tc-body{padding:12px 14px 14px;display:flex;flex-direction:column;gap:3px}
.tc-name{font-size:13.5px;font-weight:600;letter-spacing:-.01em}
.tc-desc{font-size:11.5px;color:var(--g7-text-4);line-height:1.35}
@media (max-width:640px){.d3-tiles{grid-template-columns:repeat(2,1fr);gap:10px}.tc-art{height:92px}.tc-art-icon{font-size:30px}}
.d3-empty{display:none;padding:34px;text-align:center;border:1px dashed var(--g7-border-3);border-radius:14px;color:var(--g7-text-4);font-size:13px}

/* Columnas inferiores */
.d3-cols{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,1fr);gap:16px;margin-top:40px;align-items:start}
.d3-card{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:16px;padding:20px 20px 14px}
.d3-card .d3-sec-head{margin-bottom:10px}
.d3-row{display:flex;align-items:center;gap:12px;padding:11px 0;border-top:1px solid var(--g7-border)}
.d3-row:first-of-type{border-top:none}
.d3-row b{display:block;font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.d3-row small{font-size:11.5px;color:var(--g7-text-4)}
.d3-days{margin-left:auto;flex-shrink:0;font:600 11px var(--g7-font-mono);padding:3px 8px;border-radius:7px;background:var(--g7-bg-5);color:var(--g7-text-2)}
.d3-days.hot{background:rgba(239,68,68,.1);color:#f87171}
.d3-days.warm{background:rgba(245,158,11,.1);color:#fbbf24}
.d3-none{padding:22px 0 14px;text-align:center;font-size:12.5px;color:var(--g7-text-4)}
.d3-none i{display:block;font-size:18px;margin-bottom:8px;color:var(--g7-text-5)}
.d3-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:18px}
.d3-stat{padding:12px;border-radius:12px;background:var(--g7-bg-4)}
.d3-stat b{display:block;font:600 22px var(--g7-font-mono);letter-spacing:-.03em;font-variant-numeric:tabular-nums}
.d3-stat small{font-size:11px;color:var(--g7-text-4)}
.d3-act{display:flex;gap:10px;align-items:flex-start;padding:8px 0}
.d3-act-ic{width:26px;height:26px;border-radius:8px;background:var(--g7-bg-5);display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0;color:var(--g7-text-3)}
.d3-act-t{font-size:12.5px;color:var(--g7-text-2);line-height:1.45}
.d3-act-t b,.d3-act-t strong{color:var(--g7-text);font-weight:600}
.d3-act-t span[style*="a78bfa"]{color:var(--g7-text)!important;font-weight:600!important}
.d3-act-t span[style*="71717a"]{color:var(--g7-text-4)!important}
.d3-act-time{font-size:11px;color:var(--g7-text-5);margin-top:1px}

/* Biblioteca */
.d3-wk-tabs{display:inline-flex;gap:2px;padding:3px;border-radius:9px;background:var(--g7-bg-3);border:1px solid var(--g7-border-2)}
.d3-wk-tabs button{border:0;background:none;color:var(--g7-text-3);font:600 11.5px var(--g7-font,inherit);padding:5px 10px;border-radius:7px;cursor:pointer}
.d3-wk-tabs button.on{background:var(--g7-bg-5);color:var(--g7-text)}
.d3-wk{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:10px}
.d3-wk[hidden]{display:none}
.d3-wt{display:flex;flex-direction:column;gap:9px;padding:13px 14px;border-radius:13px;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-left:3px solid var(--c);text-decoration:none;color:inherit;transition:border-color .15s,transform .15s}
.d3-wt:hover{transform:translateY(-2px);border-color:var(--g7-accent-border);border-left-color:var(--c)}
.d3-wt-top{display:flex;align-items:flex-start;gap:10px;min-width:0}
.d3-wt-top b{flex:1;min-width:0;font-size:13.5px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.d3-wt-st{flex:none;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:3px 8px;border-radius:999px;color:var(--c);background:color-mix(in srgb,var(--c) 16%,transparent)}
.d3-wt-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:11.5px;color:var(--g7-text-3)}
.d3-wt-meta .late{color:#f87171;font-weight:700}
.d3-wt-meta .soon{color:#fbbf24}
.d3-wt-meta .sp{flex:1}
.d3-wt-bar{height:5px;border-radius:5px;background:var(--g7-bg-5);overflow:hidden}
.d3-wt-bar span{display:block;height:100%;background:var(--g7-accent);border-radius:5px}
.d3-wt-av{display:flex}
.d3-wt-av span,.d3-wt-av img{width:22px;height:22px;border-radius:7px;border:2px solid var(--g7-bg-3);margin-left:-6px;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:#000;object-fit:cover}
.d3-wt-av>:first-child{margin-left:0}
.d3-wk-empty{padding:18px;border:1px dashed var(--g7-border-3);border-radius:13px;color:var(--g7-text-3);font-size:12.5px}
.d3-wk-empty a{color:var(--g7-accent)}
/* Mis pedidos (Inicio): tarjetas con aprobar / calificar */
.d3-rq{position:relative;display:flex;flex-wrap:wrap;align-items:center;gap:6px 12px;padding:12px 14px 12px 16px;border-radius:13px;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);overflow:hidden}
.d3-rq::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--k)}
.d3-rq-t{flex:1;min-width:200px;display:flex;flex-direction:column;gap:2px;text-decoration:none;color:inherit}
.d3-rq-t b{font-size:13.5px;font-weight:600}.d3-rq-t small{font-size:11.5px;color:var(--g7-text-4)}
.d3-rq-code{font-size:10.5px;font-weight:700;color:var(--g7-text-4);letter-spacing:.03em}
.d3-rq-st{font-size:11px;font-weight:700;color:var(--k);background:color-mix(in srgb,var(--k) 14%,transparent);padding:3px 9px;border-radius:999px}
.d3-rq-a{display:flex;align-items:center;gap:8px;width:100%;padding-top:8px;border-top:1px dashed var(--g7-border-2)}
.d3-rq-b{border:1px solid var(--g7-border-3);background:var(--g7-bg-4);color:var(--g7-text-2);border-radius:9px;padding:6px 12px;font:600 12px inherit;cursor:pointer}
.d3-rq-b.ok{background:#16a34a;border-color:#16a34a;color:#fff}
.d3-rq-q{font-size:12px;color:var(--g7-text-3);flex:1}
.d3-rq-stars{display:inline-flex}.d3-rq-stars button{border:0;background:none;font-size:20px;color:var(--g7-bg-5);cursor:pointer;padding:0 2px;transition:color .12s,transform .12s}
.d3-rq-stars:hover button{color:#f59e0b}.d3-rq-stars button:hover~button{color:var(--g7-bg-5)}.d3-rq-stars button:hover{transform:scale(1.15)}
.d3-rq-h{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--g7-text-4);margin:6px 0 2px}
.d3-rq-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#f59e0b;margin-left:4px;vertical-align:1px}
@media (prefers-reduced-motion:reduce){.d3-rq-stars button{transition:none}}
.d3-head-a{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.d3-cust-btn{display:inline-flex;align-items:center;gap:8px;height:38px;padding:0 14px;border-radius:10px;border:1px solid var(--g7-border-3);background:var(--g7-bg-3);color:var(--g7-text-2);font:600 12.5px var(--g7-font,inherit);cursor:pointer;transition:border-color .15s,color .15s}
.d3-cust-btn:hover{border-color:var(--g7-accent-border);color:var(--g7-text)}
.d3-cust-btn:focus-visible{outline:2px solid var(--g7-accent);outline-offset:2px}
.d3-cust{margin:auto;border:1px solid var(--g7-border-3);background:var(--g7-bg-2);color:var(--g7-text);border-radius:18px;padding:0;width:min(560px,calc(100vw - 24px));max-height:calc(100dvh - 40px)}
.d3-cust::backdrop{background:rgba(0,0,0,.65)}
.d3-cust-in{display:flex;flex-direction:column;max-height:calc(100dvh - 40px)}
.d3-cust-h{display:flex;align-items:center;gap:10px;padding:16px 20px;border-bottom:1px solid var(--g7-border-2)}
.d3-cust-h h3{margin:0;font-size:16px;flex:1}
.d3-cust-b{padding:14px 20px 18px;overflow:auto}
.d3-cust-b h4{margin:6px 0 8px;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--g7-text-4)}
.d3-cust-b p{margin:0 0 12px;font-size:12px;color:var(--g7-text-3)}
.d3-cs{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:10px;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);margin-bottom:6px;cursor:grab}
.d3-cs.dragging{opacity:.4}
.d3-cs .g{color:var(--g7-text-5);font-size:11px}
.d3-cs label{flex:1;display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;font-weight:600;min-width:0}
.d3-cs label small{display:block;font-weight:400;font-size:11px;color:var(--g7-text-4);margin-top:1px}
.d3-cs input{accent-color:var(--g7-accent);width:16px;height:16px;flex:none}
.d3-cs button{width:28px;height:28px;border-radius:7px;border:1px solid transparent;background:none;color:var(--g7-text-4);cursor:pointer}
.d3-cs button:hover{background:var(--g7-bg-5);color:var(--g7-text)}
.d3-ct{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:6px}
.d3-ct label{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:9px;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);font-size:12.5px;cursor:pointer;min-width:0}
.d3-ct label span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.d3-ct input{accent-color:var(--g7-accent)}
.d3-cust-f{display:flex;align-items:center;gap:8px;padding:14px 20px;border-top:1px solid var(--g7-border-2)}
.d3-cust-f .sp{flex:1}
.d3-cust-f button{height:36px;padding:0 14px;border-radius:9px;border:1px solid var(--g7-border-3);background:var(--g7-bg-4);color:var(--g7-text-2);font:600 12.5px var(--g7-font,inherit);cursor:pointer}
.d3-cust-f button.p{background:linear-gradient(#ff7700,#e65500);border-color:transparent;color:#fff}
.d3-cust-f button:hover{filter:brightness(1.08)}
.d3-clients{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}
.d3-cl{display:flex;flex-direction:column;gap:12px;padding:14px;border-radius:14px;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);transition:border-color .15s,transform .15s}
.d3-cl:hover{border-color:var(--g7-accent-border);transform:translateY(-2px)}
.d3-cl-top{display:flex;align-items:center;gap:12px;min-width:0;text-decoration:none;color:inherit}
.d3-cl-av{width:44px;height:44px;border-radius:12px;flex:none;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:17px;color:#fff;overflow:hidden}
.d3-cl-av img{width:100%;height:100%;object-fit:cover;background:#fff}
.d3-cl-top b{display:block;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.d3-cl-top small{display:block;color:var(--g7-text-3);font-size:11.5px;margin-top:2px}
.d3-cl-st{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:2px 7px;border-radius:999px;background:var(--g7-bg-5);color:var(--g7-text-3);margin-left:auto;flex:none}
.d3-cl-st.activo{background:rgba(34,197,94,.12);color:#4ade80}
.d3-cl-st.pausado{background:rgba(245,158,11,.12);color:#fbbf24}
.d3-cl-act{display:flex;gap:6px}
.d3-cl-act a{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:6px;height:30px;border-radius:8px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-2);font-size:11.5px;font-weight:600;text-decoration:none;transition:border-color .15s,color .15s}
.d3-cl-act a:hover{border-color:var(--g7-accent-border);color:var(--g7-text)}
.d3-shelf{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(190px,220px);gap:10px;overflow-x:auto;padding-bottom:6px;scrollbar-width:thin}
.d3-res{display:block;border-radius:12px;overflow:hidden;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);text-decoration:none;color:inherit;transition:border-color .15s,transform .15s}
.d3-res:hover{border-color:var(--g7-accent-border);transform:translateY(-1px)}
.d3-res-img{height:96px;background:var(--g7-bg-5);display:flex;align-items:center;justify-content:center;color:var(--g7-text-5);font-size:18px;position:relative}
.d3-res-img img{width:100%;height:100%;object-fit:cover}
.d3-res-img span{position:absolute;top:8px;right:8px;font:600 10px var(--g7-font-mono);padding:2px 7px;border-radius:6px;background:rgba(0,0,0,.65);color:var(--g7-text)}
.d3-res div:last-child{padding:10px 12px}
.d3-res b{display:block;font-size:12.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.d3-res small{font-size:11px;color:var(--g7-text-4)}

/* Entrada escalonada */
@keyframes d3In{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.d3-in{animation:d3In .45s cubic-bezier(.2,.8,.2,1) both}
@media (prefers-reduced-motion:reduce){.d3-in{animation:none}.d3-tool,.d3-rc,.d3-res{transition:none}}

@media (max-width:900px){.d3-cols{grid-template-columns:1fr}}
@media (max-width:640px){.d3{padding:24px 16px 56px}.d3-search{width:100%}.d3-search kbd,.d3-sec-head button.d3-link{display:none}.d3-stats{grid-template-columns:repeat(3,1fr)}.d3-grid{grid-template-columns:1fr}
  .d3-recent{grid-template-columns:1fr 1fr;gap:8px}.d3-rc{flex-direction:column;align-items:flex-start;gap:10px;padding:12px}.d3-rc .fa-arrow-right{display:none}.d3-stat b{font-size:18px}}

/* ── Inicio amable (2026-11-11): colores pastel, formas redondeadas. Misma estructura; cada ícono toma un tono con --h ── */
.d3{--p1:#a78bfa;--p2:#34d399;--p3:#fbbf24;--p4:#60a5fa;--p5:#f472b6;--p6:#fb923c}
html:is(.theme-light,.theme-sand,.theme-sky,.theme-lilac,.theme-mint,.theme-sakura,.theme-lemon,.theme-cloud,.theme-desktop,.theme-classic,.theme-vintage,.theme-mica,.theme-aqua,.theme-studio,.theme-material,.theme-mobile,.theme-glassfrost,.theme-glasspeach,.theme-glassmint,.theme-glasssky,.theme-glasslilac,.theme-glasslemon,.theme-glassblush,.theme-glasspearl,.theme-coral,.theme-lagoon,.theme-peach,.theme-fog,.theme-lime,.theme-latte,.theme-indigo,.theme-pocket,.theme-platinum) .d3{--p1:#7c3aed;--p2:#059669;--p3:#d97706;--p4:#2563eb;--p5:#db2777;--p6:#ea580c}
/* Encabezado en tarjeta con degradé suave y burbujas de fondo */
.d3-head{position:relative;overflow:hidden;align-items:center;padding:28px 30px;border-radius:26px;border:1px solid var(--g7-border-2);
  background:linear-gradient(130deg,color-mix(in srgb,var(--p1) 16%,var(--g7-bg-3)) 0%,color-mix(in srgb,var(--g7-accent) 9%,var(--g7-bg-3)) 55%,color-mix(in srgb,var(--p2) 12%,var(--g7-bg-3)) 100%)}
.d3-head::before,.d3-head::after{content:"";position:absolute;border-radius:50%;pointer-events:none;filter:blur(2px)}
.d3-head::before{width:220px;height:220px;right:-60px;top:-90px;background:radial-gradient(circle,color-mix(in srgb,var(--p1) 30%,transparent),transparent 70%)}
.d3-head::after{width:160px;height:160px;right:180px;bottom:-110px;background:radial-gradient(circle,color-mix(in srgb,var(--p3) 22%,transparent),transparent 70%)}
.d3-head>*{position:relative;z-index:1}
.d3-head h1 span{background:linear-gradient(90deg,var(--g7-accent),var(--p5));-webkit-background-clip:text;background-clip:text;color:transparent}
.d3-chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px}
.d3-chip{display:inline-flex;align-items:center;gap:8px;height:32px;padding:0 13px 0 5px;border-radius:999px;font-size:12.5px;font-weight:600;color:var(--g7-text);text-decoration:none;
  background:color-mix(in srgb,var(--h) 13%,var(--g7-bg-3));border:1px solid color-mix(in srgb,var(--h) 26%,transparent);transition:transform .15s,border-color .15s}
.d3-chip i{width:23px;height:23px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:11px;background:var(--h);color:#fff}
a.d3-chip:hover{transform:translateY(-1px);border-color:var(--h)}
.d3-search{border-radius:999px;background:color-mix(in srgb,var(--g7-bg-2) 75%,transparent)}
.d3-cust-btn{border-radius:999px;background:color-mix(in srgb,var(--g7-bg-2) 75%,transparent)}
/* Títulos de sección con el ícono en un círculo */
.d3-sec-head h2>i{width:28px;height:28px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:color-mix(in srgb,currentColor 15%,transparent)}
/* Íconos de color: una paleta que se repite */
.d3-grid>:nth-child(6n+1),.d3-recent>:nth-child(6n+1),.d3-stats>:nth-child(6n+1),.d3-act:nth-of-type(6n+1){--h:var(--p1)}
.d3-grid>:nth-child(6n+2),.d3-recent>:nth-child(6n+2),.d3-stats>:nth-child(6n+2),.d3-act:nth-of-type(6n+2){--h:var(--p2)}
.d3-grid>:nth-child(6n+3),.d3-recent>:nth-child(6n+3),.d3-stats>:nth-child(6n+3),.d3-act:nth-of-type(6n+3){--h:var(--p3)}
.d3-grid>:nth-child(6n+4),.d3-recent>:nth-child(6n+4),.d3-act:nth-of-type(6n+4){--h:var(--p4)}
.d3-grid>:nth-child(6n+5),.d3-recent>:nth-child(6n+5),.d3-act:nth-of-type(6n+5){--h:var(--p5)}
.d3-grid>:nth-child(6n+6),.d3-recent>:nth-child(6n+6),.d3-act:nth-of-type(6n+6){--h:var(--p6)}
.d3-ic{border-radius:50%;border:none;color:var(--h,var(--g7-accent));background:color-mix(in srgb,var(--h,var(--g7-accent)) 16%,transparent)}
a:hover .d3-ic{color:#fff;background:var(--h,var(--g7-accent));border:none}
.d3-tool,.d3-rc{border-radius:18px}
.d3-tool::after{background:radial-gradient(260px 120px at var(--mx,50%) var(--my,50%),color-mix(in srgb,var(--h,var(--g7-accent)) 14%,transparent),transparent 70%)}
.d3-tool:hover,.d3-rc:hover{border-color:color-mix(in srgb,var(--h,var(--g7-accent)) 40%,transparent)}
/* Accesos rápidos: el ícono dentro de una burbuja */
.tc{border-radius:22px}
.tc-art-icon{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:26px;background:color-mix(in srgb,currentColor 16%,transparent);box-shadow:0 0 0 7px color-mix(in srgb,currentColor 6%,transparent)}
.tc-badge{border-radius:999px;padding:2px 8px}
/* Hover: el círculo se llena con el color del acceso (de adentro hacia afuera), el ícono pasa a blanco y sale una onda */
.tc-art-icon{position:relative;isolation:isolate;transition:color .3s ease,transform .35s cubic-bezier(.34,1.56,.64,1)}
.tc-art-icon::after{content:"";position:absolute;inset:0;border-radius:50%;background:var(--tc);transform:scale(0);transition:transform .55s cubic-bezier(.34,1.56,.64,1);z-index:-1}
.tc:hover .tc-art-icon,.tc:focus-visible .tc-art-icon{color:#fff!important;transform:scale(1.08)}
.tc:hover .tc-art-icon::after,.tc:focus-visible .tc-art-icon::after{transform:scale(1)}
.tc:hover .tc-art-icon{animation:tcRing 1.2s ease-out,tcWiggle .6s ease-in-out .15s}
@keyframes tcWiggle{0%,100%{rotate:0deg}25%{rotate:-12deg}50%{rotate:9deg}75%{rotate:-5deg}}
@keyframes tcRing{0%{box-shadow:0 0 0 0 color-mix(in srgb,var(--tc) 55%,transparent)}100%{box-shadow:0 0 0 28px color-mix(in srgb,var(--tc) 0%,transparent)}}
@media (prefers-reduced-motion:reduce){.tc-art-icon,.tc-art-icon::after{transition:none}.tc:hover .tc-art-icon{animation:none}}
html:is(.theme-light,.theme-sand,.theme-sky,.theme-lilac,.theme-mint,.theme-sakura,.theme-lemon,.theme-cloud,.theme-desktop,.theme-classic,.theme-vintage,.theme-mica,.theme-aqua,.theme-studio,.theme-material,.theme-mobile,.theme-glassfrost,.theme-glasspeach,.theme-glassmint,.theme-glasssky,.theme-glasslilac,.theme-glasslemon,.theme-glassblush,.theme-glasspearl,.theme-coral,.theme-lagoon,.theme-peach,.theme-fog,.theme-lime,.theme-latte,.theme-indigo,.theme-pocket,.theme-platinum) .tc:hover{box-shadow:0 18px 36px rgba(24,24,27,.12)}
@media (max-width:640px){.tc-art-icon{width:52px;height:52px;font-size:21px}}
/* Tareas, clientes y paneles */
.d3-wk-tabs{border-radius:999px}.d3-wk-tabs button{border-radius:999px}
.d3-wk-tabs button.on{background:var(--g7-accent-muted);color:var(--g7-accent)}
.d3-wt{border-radius:18px}
.d3-cl,.d3-card{border-radius:22px}
.d3-cl-av{border-radius:50%}
.d3-cl-act a{border-radius:999px}
.d3-stat{border-radius:16px;background:color-mix(in srgb,var(--h,var(--p1)) 13%,var(--g7-bg-3))}
.d3-stat b{color:var(--h,var(--g7-text))}
.d3-act-ic{border-radius:50%;background:color-mix(in srgb,var(--h,var(--p1)) 16%,transparent)}
.d3-act-ic i{color:var(--h,var(--g7-text-3))!important}
.d3-days{border-radius:999px}
.d3-none i{width:44px;height:44px;margin:0 auto 10px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:17px;color:var(--p2);background:color-mix(in srgb,var(--p2) 15%,transparent)}
.d3-res{border-radius:16px}
.d3-alert{border-radius:18px}
@media (max-width:640px){.d3-head{padding:22px 18px;border-radius:22px}}

/* Escena del saludo (2026-11-11, versión discreta): paisaje SVG según la hora, apagado para que acompañe sin llamar la atención.
   Se ve solo a la derecha, con poca saturación; el texto conserva los colores del tema */
.d3-head{min-height:220px;background:var(--g7-bg-3);border-color:var(--g7-border-2);animation:d3In .45s cubic-bezier(.2,.8,.2,1) both}
.d3-head::before,.d3-head::after{content:none}
.d3-head>.d3-scene{position:absolute;inset:0;z-index:0;pointer-events:none;border-radius:inherit;overflow:hidden}
.d3-scene svg{width:100%;height:100%;display:block;opacity:.55;filter:saturate(.55) brightness(.85)}
html:is(.theme-light,.theme-sand,.theme-sky,.theme-lilac,.theme-mint,.theme-sakura,.theme-lemon,.theme-cloud,.theme-desktop,.theme-classic,.theme-vintage,.theme-mica,.theme-aqua,.theme-studio,.theme-material,.theme-mobile,.theme-glassfrost,.theme-glasspeach,.theme-glassmint,.theme-glasssky,.theme-glasslilac,.theme-glasslemon,.theme-glassblush,.theme-glasspearl,.theme-coral,.theme-lagoon,.theme-peach,.theme-fog,.theme-lime,.theme-latte,.theme-indigo,.theme-pocket,.theme-platinum) .d3-scene svg{opacity:.45;filter:saturate(.5) brightness(1.08)}
.d3-scene::after{content:"";position:absolute;inset:0;background:linear-gradient(90deg,var(--g7-bg-3) 0%,var(--g7-bg-3) 38%,color-mix(in srgb,var(--g7-bg-3) 55%,transparent) 62%,transparent 85%)}
/* Animaciones lentas y suaves */
.sc-star{animation:scTw 5s ease-in-out infinite}
@keyframes scTw{0%,100%{opacity:.25}50%{opacity:.8}}
.sc-glow{transform-box:fill-box;transform-origin:center;animation:scPulse 9s ease-in-out infinite}
@keyframes scPulse{0%,100%{opacity:.7}50%{opacity:1}}
.sc-rays{transform-box:fill-box;transform-origin:center;animation:scSpin 90s linear infinite}
@keyframes scSpin{to{transform:rotate(360deg)}}
.sc-cloud{animation:scDrift 140s linear infinite}
@keyframes scDrift{from{transform:translateX(-260px)}to{transform:translateX(1460px)}}
.sc-shoot{opacity:0;animation:scShoot 24s ease-in infinite 6s}
@keyframes scShoot{0%,94%{opacity:0;transform:translate(0,0)}95%{opacity:.8}100%{opacity:0;transform:translate(-300px,130px)}}
.sc-win{fill:#fde68a;animation:scWin 12s steps(1) infinite}
.sc-dia .sc-win{fill:#fff;opacity:.7;animation:none}
@keyframes scWin{0%,100%{opacity:.9}48%{opacity:.3}52%{opacity:.9}}
.sc-birds{animation:scFly 50s linear infinite}
@keyframes scFly{from{transform:translate(-80px,90px)}to{transform:translate(1280px,40px)}}
.sc-wing{transform-box:fill-box;transform-origin:center;animation:scFlap .9s ease-in-out infinite alternate}
@keyframes scFlap{from{transform:scaleY(1)}to{transform:scaleY(.4)}}
.sc-fly{animation:scBob 8s ease-in-out infinite}
@keyframes scBob{0%,100%{transform:translate(0,0);opacity:.15}50%{transform:translate(10px,-8px);opacity:.7}}
/* la escena se frena con html:not(.g7-move) en assets/dash-hero.css */
@media (max-width:640px){.d3-scene::after{background:linear-gradient(180deg,var(--g7-bg-3) 0%,color-mix(in srgb,var(--g7-bg-3) 70%,transparent) 65%,color-mix(in srgb,var(--g7-bg-3) 40%,transparent))}}
/* Tareas en curso con el formato de Trabajo › Tickets (2026-11-11): indicadores con ícono y filas con franja, código, chips y estado */
#d3-working{margin-top:36px}
#d3-working .d3-sec-head h2{font-size:18px}
.d3-wks{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
.d3-wks[hidden]{display:none}
.d3-kpi{--c:#3b82f6;position:relative;overflow:hidden;display:flex;gap:12px;align-items:center;padding:14px;border-radius:16px;border:1px solid var(--g7-border-2);background:linear-gradient(135deg,color-mix(in srgb,var(--c) 11%,var(--g7-bg-2)),var(--g7-bg-2) 62%);min-width:0}
.d3-kpi .ic{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;flex-shrink:0;background:color-mix(in srgb,var(--c) 18%,transparent);color:var(--c);font-size:16px}
.d3-kpi .tx{min-width:0;display:flex;flex-direction:column}
.d3-kpi small{font-size:11.5px;font-weight:700;color:var(--g7-text-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.d3-kpi b{font-size:24px;font-weight:800;line-height:1.15;font-variant-numeric:tabular-nums;letter-spacing:-.5px}
.d3-kpi .tx>span{font-size:11px;color:var(--g7-text-4);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.d3-kpi.alert{border-color:color-mix(in srgb,var(--c) 40%,var(--g7-border-2))}
.d3-kpi.alert b{color:color-mix(in srgb,var(--c) 85%,var(--g7-text))}
.d3-kpi.alert .ic{animation:d3KpiPulse 2.2s infinite}
@keyframes d3KpiPulse{0%,100%{box-shadow:0 0 0 0 color-mix(in srgb,var(--c) 45%,transparent)}60%{box-shadow:0 0 0 8px color-mix(in srgb,var(--c) 0%,transparent)}}
.d3-wk{display:flex;flex-direction:column;gap:8px}
.d3-trow{--c:#8b8b94;position:relative;display:grid;grid-template-columns:40px minmax(0,1fr) auto;gap:14px;align-items:center;padding:12px 14px 12px 18px;border-radius:14px;border:1px solid var(--g7-border-2);background:var(--g7-bg-2);text-decoration:none;color:inherit;overflow:hidden;
  transition:transform .16s,border-color .16s,box-shadow .16s,background .16s;animation:d3TIn .35s both;animation-delay:calc(var(--i,0) * 30ms)}
@keyframes d3TIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
.d3-trow::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--c)}
.d3-trow:hover{transform:translateX(3px);border-color:var(--g7-border-3);background:var(--g7-bg-3);box-shadow:0 8px 22px -16px rgba(0,0,0,.7)}
.d3-trow:focus-visible{outline:2px solid var(--g7-accent);outline-offset:2px}
.d3-trow.late{border-color:color-mix(in srgb,#ef4444 35%,var(--g7-border-2))}
.d3-tic{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;font-size:15px;color:var(--s);background:color-mix(in srgb,var(--s) 16%,transparent)}
.d3-tmid{min-width:0;display:flex;flex-direction:column;gap:7px}
.d3-ttop{display:flex;align-items:center;gap:8px;min-width:0}
.d3-ttop b{font-weight:700;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.d3-tcode{font:700 11px ui-monospace,SFMono-Regular,Consolas,monospace;color:var(--g7-text-3);padding:3px 7px;border-radius:7px;background:var(--g7-bg-4);flex-shrink:0;letter-spacing:.2px}
.d3-tchips{display:flex;flex-wrap:wrap;gap:6px}
.d3-tchip{--k:#64748b;display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;padding:3px 9px;border-radius:999px;color:color-mix(in srgb,var(--k) 80%,var(--g7-text));background:color-mix(in srgb,var(--k) 14%,transparent);border:1px solid color-mix(in srgb,var(--k) 26%,transparent);white-space:nowrap}
.d3-tchip i{font-size:10.5px}
.d3-tend{display:flex;align-items:center;gap:10px}
.d3-tst{display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:700;padding:4px 10px;border-radius:999px;color:color-mix(in srgb,var(--s) 85%,var(--g7-text));background:color-mix(in srgb,var(--s) 15%,transparent);white-space:nowrap}
.d3-tst i{width:7px;height:7px;border-radius:50%;background:var(--s)}
.d3-tasg{display:flex;align-items:center}
.d3-tasg span,.d3-tasg img{width:26px;height:26px;border-radius:50%;border:2px solid var(--g7-bg-2);margin-left:-7px;display:inline-flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:800;color:#000;object-fit:cover}
.d3-tasg>:first-child{margin-left:0}
.d3-tasg .none{background:none;border:1.5px dashed var(--g7-border-3);color:var(--g7-text-4);font-size:10px}
@media (max-width:900px){.d3-wks{grid-template-columns:1fr 1fr}}
@media (max-width:640px){.d3-trow{grid-template-columns:34px minmax(0,1fr);padding:11px 12px 11px 16px;gap:10px}.d3-tic{width:34px;height:34px}.d3-tend{grid-column:2;justify-content:space-between}.d3-kpi{padding:12px;gap:10px}.d3-kpi .ic{width:34px;height:34px;font-size:14px}.d3-kpi b{font-size:20px}}
@media (prefers-reduced-motion:reduce){.d3-trow{animation:none;transition:none}.d3-kpi.alert .ic{animation:none}}
/* ¿Cómo estás hoy? (2026-11-11): caritas grandes, motivos rápidos, mi semana y el promedio anónimo del equipo (admins) */
.d3-md-priv{font-size:11.5px;color:var(--g7-text-4);display:inline-flex;align-items:center;gap:6px}
.d3-md{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);gap:16px;padding:20px;border-radius:22px;border:1px solid var(--g7-border-2);
  background:linear-gradient(135deg,color-mix(in srgb,#f472b6 7%,var(--g7-bg-3)),var(--g7-bg-3) 55%);transition:opacity .2s}
.d3-md.saving{opacity:.85}
.d3-md-q b{display:block;font-size:16px;font-weight:700;letter-spacing:-.01em}
.d3-md-q small{display:block;font-size:12px;color:var(--g7-text-4);margin-top:3px}
.d3-md-faces{display:flex;gap:10px;margin-top:16px;flex-wrap:wrap}
.d3-md-f{--m:#888;display:flex;flex-direction:column;align-items:center;gap:7px;padding:12px 6px 10px;min-width:78px;flex:1;border-radius:18px;border:1px solid var(--g7-border-2);background:var(--g7-bg-2);color:var(--g7-text-3);cursor:pointer;font:600 11.5px var(--g7-font,inherit);
  transition:transform .25s cubic-bezier(.34,1.56,.64,1),border-color .2s,background .2s,opacity .2s,color .2s}
.d3-md-f i{width:46px;height:46px;border-radius:50%;display:grid;place-items:center;font-size:24px;color:var(--m);background:color-mix(in srgb,var(--m) 14%,transparent);transition:transform .3s cubic-bezier(.34,1.56,.64,1),background .2s,color .2s}
.d3-md-f:hover{transform:translateY(-3px);border-color:color-mix(in srgb,var(--m) 50%,transparent);color:var(--g7-text)}
.d3-md-f:hover i{transform:scale(1.12) rotate(-6deg)}
.d3-md-f:focus-visible{outline:2px solid var(--m);outline-offset:2px}
.d3-md-f.on{border-color:var(--m);background:color-mix(in srgb,var(--m) 12%,var(--g7-bg-2));color:var(--g7-text)}
.d3-md-f.on i{background:var(--m);color:#fff;box-shadow:0 8px 20px -6px color-mix(in srgb,var(--m) 70%,transparent)}
.d3-md-f.dim{opacity:.55}.d3-md-f.dim:hover{opacity:1}
.d3-md-f.pop i{animation:d3MdPop .5s cubic-bezier(.34,1.56,.64,1)}
@keyframes d3MdPop{0%{transform:scale(.7)}60%{transform:scale(1.2) rotate(-8deg)}100%{transform:scale(1)}}
.d3-md-rs{margin-top:14px}
.d3-md-rs small{font-size:11.5px;font-weight:600;color:var(--g7-text-3)}.d3-md-rs em{font-style:normal;font-weight:400;color:var(--g7-text-4)}
.d3-md-rs>div{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.d3-md-r{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 12px;border-radius:999px;border:1px solid var(--g7-border-3);background:var(--g7-bg-2);color:var(--g7-text-2);font:600 12px var(--g7-font,inherit);cursor:pointer;transition:background .15s,border-color .15s,color .15s,transform .15s}
.d3-md-r i{font-size:11px;color:var(--g7-text-4)}
.d3-md-r:hover{border-color:var(--g7-accent-border);transform:translateY(-1px)}
.d3-md-r.on{background:var(--g7-accent-muted);border-color:var(--g7-accent-border);color:var(--g7-text)}.d3-md-r.on i{color:var(--g7-accent)}
.d3-md-side{display:flex;flex-direction:column;gap:14px;padding-left:16px;border-left:1px solid var(--g7-border-2)}
.d3-md-side>div>small{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:700;color:var(--g7-text-3)}
.d3-md-week>div{display:flex;gap:6px;margin-top:8px}
.d3-md-d{--m:#888;flex:1;display:flex;flex-direction:column;align-items:center;gap:4px}
.d3-md-d i{width:30px;height:30px;border-radius:50%;display:grid;place-items:center;font-size:15px;color:#fff;background:var(--m)}
.d3-md-d.empty::before{content:"";width:30px;height:30px;border-radius:50%;border:1.5px dashed var(--g7-border-3)}
.d3-md-d em,.d3-md-bars em{font-style:normal;font-size:10px;font-weight:700;color:var(--g7-text-4)}
.d3-md-tnow{--m:#888;display:flex;align-items:center;gap:10px;margin-top:8px;font-size:12.5px;color:var(--g7-text-3)}
.d3-md-tnow>i{width:34px;height:34px;flex:none;border-radius:50%;display:grid;place-items:center;font-size:17px;color:#fff;background:var(--m)}
.d3-md-tnow.empty>i{background:var(--g7-bg-5);color:var(--g7-text-4);font-size:14px}
.d3-md-tnow b{color:var(--g7-text)}
.d3-md-bars{display:flex;gap:6px;align-items:flex-end;height:58px;margin-top:10px}
.d3-md-bars span{flex:1;height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:4px}
.d3-md-bars i{width:100%;max-width:22px;border-radius:6px 6px 3px 3px;background:var(--g7-accent);transition:height .5s cubic-bezier(.2,.8,.2,1)}
.d3-md-bars i.none{background:var(--g7-bg-5)}
.d3-md-tr{display:flex;flex-wrap:wrap;gap:5px;margin-top:10px}
.d3-md-tr span{display:inline-flex;align-items:center;gap:5px;font-size:11px;padding:3px 8px;border-radius:999px;background:var(--g7-bg-4);color:var(--g7-text-3)}
.d3-md-tr b{color:var(--g7-text)}
.d3-md-avg{display:block;margin-top:8px;font-size:11.5px;color:var(--g7-text-4)}
@media (max-width:900px){.d3-md{grid-template-columns:1fr}.d3-md-side{padding-left:0;border-left:0;padding-top:14px;border-top:1px solid var(--g7-border-2)}}
@media (max-width:640px){.d3-md{padding:16px}.d3-md-f{min-width:0;padding:10px 2px 8px}.d3-md-f i{width:38px;height:38px;font-size:20px}.d3-md-f span{font-size:10.5px}.d3-md-faces{gap:6px;flex-wrap:nowrap}}
@media (prefers-reduced-motion:reduce){.d3-md-f,.d3-md-f i,.d3-md-r{transition:none}.d3-md-f.pop i{animation:none}}
/* ¿Cómo estás hoy? en botón + modal: el botón late hasta que respondes; una respuesta cada 24 h */
.d3-md-btn{--m:#f472b6;position:relative}
.d3-md-btn i{color:var(--m);transition:transform .2s}
.d3-md-btn.beat{border-color:color-mix(in srgb,var(--m) 55%,transparent);color:var(--g7-text);animation:d3MdBeatRing 1.8s ease-out infinite}
.d3-md-btn.beat i{animation:d3MdBeat 1.8s ease-in-out infinite}
@keyframes d3MdBeat{0%,40%,100%{transform:scale(1)}10%{transform:scale(1.28)}20%{transform:scale(1)}30%{transform:scale(1.2)}}
@keyframes d3MdBeatRing{0%{box-shadow:0 0 0 0 color-mix(in srgb,var(--m) 50%,transparent)}70%,100%{box-shadow:0 0 0 12px color-mix(in srgb,var(--m) 0%,transparent)}}
.d3-md-btn.done{border-color:color-mix(in srgb,var(--m) 35%,transparent)}
.d3-md-btn:hover i{transform:scale(1.15)}
.d3-md-dlg{width:min(600px,calc(100vw - 24px))}
.d3-md-dlg .d3-md{display:block;padding:0;border:0;background:none;border-radius:0}
.d3-md-dlg .d3-md-faces{margin-top:14px}
.d3-md-send{display:flex;align-items:center;gap:12px;margin-top:18px;flex-wrap:wrap}
.d3-md-send small{font-size:11.5px;color:var(--g7-text-4)}
.d3-md-go{display:inline-flex;align-items:center;gap:8px;height:40px;padding:0 20px;border-radius:999px;border:0;background:linear-gradient(135deg,#f472b6,#8b5cf6);color:#fff;font:700 13px var(--g7-font,inherit);cursor:pointer;transition:transform .15s,filter .15s,opacity .15s}
.d3-md-go:hover:not(:disabled){transform:translateY(-1px);filter:brightness(1.08)}
.d3-md-go:disabled{opacity:.45;cursor:not-allowed}
.d3-md-err{margin-top:12px;font-size:12.5px;color:#f87171}
.d3-md-done{--m:#888;display:flex;gap:14px;align-items:flex-start;padding:16px;border-radius:18px;background:color-mix(in srgb,var(--m) 10%,var(--g7-bg-3));border:1px solid color-mix(in srgb,var(--m) 30%,transparent)}
.d3-md-done>i{width:54px;height:54px;flex:none;border-radius:50%;display:grid;place-items:center;font-size:28px;color:#fff;background:var(--m);box-shadow:0 10px 24px -8px color-mix(in srgb,var(--m) 70%,transparent)}
.d3-md-done>i.pop{animation:d3MdPop .55s cubic-bezier(.34,1.56,.64,1)}
.d3-md-done b{display:block;font-size:15px}
.d3-md-done small{display:block;margin-top:4px;font-size:12px;color:var(--g7-text-3);line-height:1.5}
.d3-md-rsv{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
.d3-md-r.static{cursor:default;height:26px;font-size:11.5px}.d3-md-r.static:hover{transform:none}
.d3-md-dlg .d3-md-week,.d3-md-dlg .d3-md-team{margin-top:18px;padding-top:16px;border-top:1px solid var(--g7-border-2)}
.d3-md-dlg .d3-md-week>small,.d3-md-dlg .d3-md-team>small{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:700;color:var(--g7-text-3)}
.d3-md-more{display:inline-flex;align-items:center;gap:7px;margin-top:12px;font-size:12px;font-weight:600;color:var(--g7-accent);text-decoration:none}.d3-md-more:hover{text-decoration:underline}
.d3-md-foot{display:flex;align-items:center;gap:8px;padding:12px 20px;border-top:1px solid var(--g7-border-2);font-size:11.5px;color:var(--g7-text-4)}
@media (max-width:640px){.d3-md-btn span{display:none}.d3-md-btn{padding:0 12px}}
@media (prefers-reduced-motion:reduce){.d3-md-btn.beat,.d3-md-btn.beat i{animation:none}}
</style>
<link rel="stylesheet" href="/toolz/assets/dash-hero.css?v=<?= (int)@filemtime(__DIR__ . '/assets/dash-hero.css') ?>">
</head>
<body>
<?php include __DIR__ . '/topbar.php'; ?>

<main class="d3">
  <?php ob_start();   // encabezado y banners: se imprimen al final, cuando ya se sabe qué poner en las pastillas del día ?>
  <header class="d3-head d3-in">
    <?php
    // Escena del saludo (2026-11-11): un paisaje ilustrado en SVG que cambia con la hora, como el saludo. Solo CSS para animar (sin JS, sin código en línea)
    $scK = $d3H >= 6 && $d3H < 13 ? 'dia' : ($d3H >= 13 && $d3H < 20 ? 'tarde' : 'noche');
    if (in_array($_GET['escena'] ?? '', ['dia', 'tarde', 'noche'], true)) $scK = $_GET['escena'];   // para ver las otras escenas: ?escena=dia|tarde|noche
    $scP = [
        'noche' => ['sky' => ['#0f0c29', '#2e1065', '#6b1d6f'], 'hills' => ['#4c1d95', '#3b0764', '#1e1b4b'], 'city' => ['#2a0f4f', '#311463', '#26104a', '#2f1258'], 'cloud' => .10],
        'tarde' => ['sky' => ['#4c1d95', '#be185d', '#fb923c'], 'hills' => ['#9d174d', '#6b21a8', '#3b0764'], 'city' => ['#581c87', '#6b21a8', '#4c1d95', '#701a75'], 'cloud' => .28],
        'dia'   => ['sky' => ['#38bdf8', '#93c5fd', '#fde68a'], 'hills' => ['#86efac', '#34d399', '#10b981'], 'city' => ['#c4b5fd', '#f9a8d4', '#fcd34d', '#93c5fd'], 'cloud' => .75],
    ][$scK];
    mt_srand(11);
    $scStars = '';
    if ($scK !== 'dia') for ($i = 0; $i < ($scK === 'noche' ? 46 : 14); $i++)
        $scStars .= '<circle class="sc-star" cx="' . mt_rand(0, 1200) . '" cy="' . mt_rand(6, 190) . '" r="' . (mt_rand(6, 19) / 10) . '" style="animation-delay:-' . (mt_rand(0, 40) / 10) . 's;animation-duration:' . (mt_rand(20, 50) / 10) . 's"/>';
    // Barrio sobre la colina del medio: edificios con ventanas (de noche titilan)
    $scCity = ''; $x = 690;
    foreach ([[38, 58], [26, 82], [44, 46], [30, 98], [40, 64], [24, 52], [36, 76], [46, 40]] as $k => [$w, $hgt]) {
        $y = 252 - $hgt; $scCity .= '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . ($hgt + 20) . '" rx="3" fill="' . $scP['city'][$k % 4] . '"/>';
        for ($wy = $y + 8; $wy < 236; $wy += 13) for ($wx = $x + 6; $wx < $x + $w - 6; $wx += 10)
            if (mt_rand(0, 9) < ($scK === 'dia' ? 4 : 5)) $scCity .= '<rect class="sc-win" x="' . $wx . '" y="' . $wy . '" width="5" height="6" rx="1" style="animation-delay:-' . mt_rand(0, 90) / 10 . 's"/>';
        $x += $w + 3;
    }
    $scCloud = fn($x, $y, $s, $dur, $del) => '<g class="sc-cloud" style="animation-duration:' . $dur . 's;animation-delay:-' . $del . 's"><g transform="translate(' . $x . ' ' . $y . ') scale(' . $s . ')"><ellipse cx="0" cy="0" rx="46" ry="16"/><ellipse cx="-20" cy="-9" rx="22" ry="17"/><ellipse cx="14" cy="-14" rx="26" ry="21"/></g></g>';
    ?>
    <div class="d3-scene sc-<?= $scK ?>" aria-hidden="true">
      <svg viewBox="0 0 1200 320" preserveAspectRatio="xMaxYMax slice" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <linearGradient id="scSky" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="<?= $scP['sky'][0] ?>"/><stop offset=".55" stop-color="<?= $scP['sky'][1] ?>"/><stop offset="1" stop-color="<?= $scP['sky'][2] ?>"/></linearGradient>
          <radialGradient id="scGlow"><stop offset="0" stop-color="#fff7d6" stop-opacity=".55"/><stop offset="1" stop-color="#fff7d6" stop-opacity="0"/></radialGradient>
          <linearGradient id="scShoot" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity="0"/><stop offset="1" stop-color="#fff"/></linearGradient>
          <radialGradient id="scHead"><stop offset="0" stop-color="#fef08a" stop-opacity=".9"/><stop offset="1" stop-color="#fef08a" stop-opacity="0"/></radialGradient>
          <linearGradient id="scAur" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#22d3ee" stop-opacity="0"/><stop offset=".35" stop-color="#4ade80"/><stop offset=".7" stop-color="#a78bfa"/><stop offset="1" stop-color="#f472b6" stop-opacity="0"/></linearGradient>
          <mask id="scMoon"><rect width="1200" height="320" fill="#fff"/><circle cx="1000" cy="74" r="30" fill="#000"/></mask>
        </defs>
        <rect width="1200" height="320" fill="url(#scSky)"/>
        <?php
        // Capas con paralaje: dash-hero.js mueve --px/--py y cada capa se corre según su --d (2026-11-14)
        $scRoad = 'M-80 306 C180 288 380 301 560 299 C760 297 900 284 1280 293';
        $scCars = '';
        foreach ([['#f43f5e', 24, 0, 1], ['#38bdf8', 31, 9, 1], ['#facc15', 27, 17, -1], ['#a78bfa', 36, 4, -1], ['#34d399', 29, 22, 1]] as $ci => [$col, $dur, $del, $dir]) {
            $lights = $scK === 'dia' ? '' : '<circle cx="17" cy="-6" r="10" fill="url(#scHead)"/><rect x="-15" y="-8" width="2" height="3" fill="#ef4444"/>';
            $scCars .= '<g class="sc-car' . ($dir < 0 ? ' sc-back' : '') . '" style="animation-duration:' . $dur . 's;animation-delay:-' . $del . 's"><g' . ($dir < 0 ? ' transform="scale(-1 1)"' : '') . '>' . $lights
                . '<rect x="-14" y="-10" width="28" height="8" rx="3" fill="' . $col . '"/><path d="M-8 -10 L-5 -16 H5 L9 -10Z" fill="' . $col . '"/><path d="M-6 -10.5 L-4 -14.5 H4 L7 -10.5Z" fill="#e0f2fe" opacity=".75"/>'
                . '<circle cx="-8" cy="-2" r="3" fill="#18181b"/><circle cx="8" cy="-2" r="3" fill="#18181b"/></g></g>';
        }
        $scTrees = '';
        foreach ([430, 452, 476, 590, 612, 640, 668] as $ti => $tx) {
            $th = 14 + ($ti * 7) % 10;
            $scTrees .= '<g class="sc-tree" style="animation-delay:-' . ($ti * .7) . 's;transform-origin:' . $tx . 'px 252px"><rect x="' . ($tx - 1.5) . '" y="' . (252 - $th) . '" width="3" height="' . $th . '" fill="' . $scP['hills'][2] . '"/><circle cx="' . $tx . '" cy="' . (252 - $th) . '" r="' . (8 + $ti % 3 * 2) . '" fill="' . $scP['hills'][0] . '"/></g>';
        }
        $scBlade = fn($x, $y, $l, $c, $rot) => '<path d="M' . $x . ' ' . $y . ' l-2.2 -' . $l . ' l2.2 -2 l2.2 2Z" fill="' . $c . '" transform="rotate(' . $rot . ' ' . $x . ' ' . $y . ')"/>';
        $scTurb = '';
        foreach ([[488, 206, 1, 6], [548, 210, .8, 8.5]] as [$tx, $ty, $ts, $tdur]) {
            $hy = $ty - 52 * $ts; $tc = $scK === 'noche' ? '#4338ca' : '#f8fafc';
            $scTurb .= '<path d="M' . ($tx - 1.6) . ' ' . $ty . ' L' . ($tx - .8) . ' ' . $hy . ' H' . ($tx + .8) . ' L' . ($tx + 1.6) . ' ' . $ty . 'Z" fill="' . $tc . '"/>'
                . '<g class="sc-rotor" style="transform-origin:' . $tx . 'px ' . $hy . 'px;animation-duration:' . $tdur . 's">' . $scBlade($tx, $hy, 26 * $ts, $tc, 0) . $scBlade($tx, $hy, 26 * $ts, $tc, 120) . $scBlade($tx, $hy, 26 * $ts, $tc, 240) . '</g>'
                . '<circle cx="' . $tx . '" cy="' . $hy . '" r="2.4" fill="' . ($scK === 'noche' ? '#ef4444' : '#cbd5e1') . '"' . ($scK === 'noche' ? ' class="sc-beacon"' : '') . '/>';
        }
        $scMsg = '¡' . $d3Hello . ', ' . $d3First . '!';
        $scMsgW = 30 + (int)(mb_strlen($scMsg) * 7.4);
        ?>
        <g class="sc-pl" style="--d:2">
          <g fill="#fff"><?= $scStars ?></g>
          <?php if ($scK === 'noche'): ?>
          <g class="sc-aurora"><path d="M120 120 C300 40 420 150 600 80 C780 10 900 110 1200 50 L1200 0 L120 0Z" fill="url(#scAur)"/><path d="M300 150 C460 90 560 170 760 110 C920 60 1040 140 1200 100 L1200 40 C1000 90 900 20 760 60 C560 120 460 40 300 110Z" fill="url(#scAur)" opacity=".6"/></g>
          <circle class="sc-glow" cx="985" cy="88" r="95" fill="url(#scGlow)"/>
          <g class="sc-sun" data-sc="moon"><circle cx="985" cy="88" r="46" fill="#000" opacity="0"/><circle cx="985" cy="88" r="34" fill="#fef3c7" mask="url(#scMoon)"/></g>
          <g class="sc-shoot"><line x1="380" y1="40" x2="470" y2="10" stroke="url(#scShoot)" stroke-width="2" stroke-linecap="round" transform="rotate(180 425 25)"/></g>
          <g class="sc-shoot sc-shoot2"><line x1="760" y1="30" x2="830" y2="6" stroke="url(#scShoot)" stroke-width="1.6" stroke-linecap="round" transform="rotate(180 795 18)"/></g>
          <?php else: $sy = $scK === 'dia' ? 82 : 196; ?>
          <circle class="sc-glow" cx="985" cy="<?= $sy ?>" r="110" fill="url(#scGlow)"/>
          <g class="sc-sun" data-sc="sun" style="transform-origin:985px <?= $sy ?>px">
            <g class="sc-rays" stroke="<?= $scK === 'dia' ? '#fde047' : '#fed7aa' ?>" stroke-width="5" stroke-linecap="round" opacity=".8">
              <?php for ($a = 0; $a < 360; $a += 30): $r = deg2rad($a); ?><line x1="<?= round(985 + cos($r) * 54) ?>" y1="<?= round($sy + sin($r) * 54) ?>" x2="<?= round(985 + cos($r) * 70) ?>" y2="<?= round($sy + sin($r) * 70) ?>"/><?php endfor; ?>
            </g>
            <circle cx="985" cy="<?= $sy ?>" r="<?= $scK === 'dia' ? 40 : 48 ?>" fill="<?= $scK === 'dia' ? '#fde047' : '#fdba74' ?>"/>
          </g>
          <g class="sc-plane"><g transform="translate(0 112)">
            <path d="M0 0 L22 -3 L28 -9 L32 -9 L30 -2 L40 -1 Q46 0 40 2 L6 4Z" fill="#f8fafc" transform="scale(-1 1) translate(-46 0)"/><path d="M32 0 L22 9 L18 9 L22 0Z" fill="#cbd5e1"/>
            <line x1="46" y1="0" x2="64" y2="0" stroke="#e2e8f0" stroke-width="1"/>
            <g class="sc-flag"><rect x="64" y="-10" width="<?= $scMsgW ?>" height="20" rx="3" fill="#fff" opacity=".93"/><text x="79" y="4" font-family="Inter,system-ui,sans-serif" font-size="12" font-weight="800" fill="<?= $scK === 'dia' ? '#0369a1' : '#9d174d' ?>"><?= htmlspecialchars($scMsg) ?></text></g>
          </g></g>
          <?php endif; ?>
          <?php if ($scK === 'tarde'): ?>
          <g class="sc-balloon"><g transform="translate(720 120)"><path d="M0 -34 C22 -34 26 -10 6 8 L-6 8 C-26 -10 -22 -34 0 -34Z" fill="#f97316"/><path d="M0 -34 C8 -34 9 -10 3 8 L-3 8 C-9 -10 -8 -34 0 -34Z" fill="#fde047"/><line x1="-5" y1="8" x2="-4" y2="16" stroke="#3b0764"/><line x1="5" y1="8" x2="4" y2="16" stroke="#3b0764"/><rect x="-5" y="16" width="10" height="7" rx="1.5" fill="#7c2d12"/></g></g>
          <?php endif; ?>
        </g>
        <g class="sc-pl" style="--d:5">
          <g fill="#fff" opacity="<?= $scP['cloud'] ?>"><?= $scCloud(0, 70, 1, 70, 10) . $scCloud(0, 128, .7, 95, 55) . $scCloud(0, 46, .55, 120, 90) . $scCloud(0, 100, .45, 150, 30) ?></g>
          <?php if ($scK === 'dia'): ?>
          <g class="sc-birds" fill="none" stroke="#1e3a5f" stroke-width="2" stroke-linecap="round" opacity=".55">
            <path class="sc-wing" d="M0 0 q6 -6 12 0 q6 -6 12 0"/><path class="sc-wing" d="M30 14 q5 -5 10 0 q5 -5 10 0" style="animation-delay:-.3s"/><path class="sc-wing" d="M-22 18 q4 -4 8 0 q4 -4 8 0" style="animation-delay:-.6s"/>
          </g>
          <?php endif; ?>
        </g>
        <g class="sc-pl" style="--d:9">
          <path d="M0 214 C150 168 300 190 450 202 C600 214 750 160 900 170 C1050 180 1130 196 1200 186 L1200 320 L0 320Z" fill="<?= $scP['hills'][0] ?>"/>
          <?= $scTurb ?>
          <g><?= $scCity ?></g>
        </g>
        <g class="sc-pl" style="--d:14">
          <path class="sc-hill2" d="M0 262 C200 226 350 246 520 250 C700 256 820 230 1000 240 C1100 245 1160 252 1200 248 L1200 320 L0 320Z" fill="<?= $scP['hills'][1] ?>"/>
          <?= $scTrees ?>
        </g>
        <g class="sc-pl" style="--d:20">
          <path d="M0 296 C180 276 380 291 560 289 C760 287 900 272 1200 282 L1200 320 L0 320Z" fill="<?= $scP['hills'][2] ?>"/>
          <path d="<?= $scRoad ?>" fill="none" stroke="rgba(0,0,0,.35)" stroke-width="13"/>
          <path d="<?= $scRoad ?>" fill="none" stroke="rgba(255,255,255,.4)" stroke-width="1.2" stroke-dasharray="10 12"/>
          <?= $scCars ?>
          <?php if ($scK === 'noche'): ?>
          <g class="sc-flies" fill="#fde68a"><?php for ($i = 0; $i < 12; $i++): ?><circle class="sc-fly" cx="<?= mt_rand(380, 1180) ?>" cy="<?= mt_rand(262, 290) ?>" r="2" style="animation-delay:-<?= mt_rand(0, 60) / 10 ?>s"/><?php endfor; ?></g>
          <?php endif; ?>
        </g>
      </svg>
    </div>
    <div>
      <p class="d3-date"><?= htmlspecialchars($d3Date) ?></p>
      <?php if ($H['greeting']): ?><h1><?= $d3Hello ?>, <span><?= htmlspecialchars($d3First) ?></span></h1><?php endif; ?>
      <p class="d3-sub">
        <?php if ($d3Brand): ?><span class="d3-brand"><i style="background:<?= htmlspecialchars($d3Brand['color']) ?>"></i><?= htmlspecialchars($d3Brand['name']) ?></span><?php endif; ?>
        <?php if ($H['subtitle'] !== ''): ?>
          <?= htmlspecialchars($H['subtitle']) ?>
        <?php elseif (g7_can($_uidIdx, 'promos')): ?>
          <?= $d3SoonCount ? ($d3SoonCount === 1 ? 'Una promo vence esta semana.' : "$d3SoonCount promos vencen esta semana.") : 'Nada vence esta semana.' ?>
        <?php else: ?>
          Elige una herramienta para empezar.
        <?php endif; ?>
      </p>
      <!--D3CHIPS-->
    </div>
    <div class="d3-head-a">
    <!--D3MOOD-->
    <button type="button" class="d3-cust-btn" id="d3-cust-btn" title="Elige qué ver en tu Inicio y en qué orden"><i class="fa-solid fa-sliders"></i> Personalizar</button>
    <?php if ($H['search']): ?>
    <label class="d3-search" title="Atajo: /">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input id="d3q" type="search" placeholder="Filtrar herramientas" autocomplete="off" aria-label="Filtrar herramientas">
      <kbd>/</kbd>
    </label>
    <?php endif; ?>
    </div>
  </header>

  <?php if (is_file(__DIR__ . '/red_lib.php')) { require_once __DIR__ . '/red_lib.php'; echo red_banners_html('dashboard'); }   // banners del Cronos principal ?>
  <?php $d3TopHtml = ob_get_clean(); $HB = []; ob_start(); ?>
  <?php if ($promosToday): ?>
  <div class="d3-alert d3-in" style="animation-delay:.04s">
    <i class="fa-solid fa-circle-exclamation"></i>
    <div><b><?= count($promosToday) === 1 ? 'Vence hoy' : count($promosToday) . ' vencen hoy' ?></b>
      <ul><?php foreach ($promosToday as $p): ?><li><?= htmlspecialchars($p['name']) ?> <span>· <?= htmlspecialchars($p['project'] ?: ($p['type'] ?? '')) ?></span></li><?php endforeach; ?></ul></div>
  </div>
  <?php endif; ?>
  <?php $HB['alert'] = ob_get_clean(); ob_start();
  // Tareas en curso: las que están en proceso y las pendientes (primer estado del tablero). Las mías primero; otra pestaña con las del equipo
  $wkMine = $wkAll = []; $wkSt = [];
  if (!empty($d3On['working']) && g7_can($_uidIdx, 'tareas') && is_file(__DIR__ . '/tareas_lib.php')) {
      try {
          require_once __DIR__ . '/trabajo/lib.php';
          wk_install($db);
          $wkScope = wk_task_where($db, wk_ctx($db, $_uidIdx));   // solo las tareas de los tableros que ve
          $wkCfg = tk_config($db);
          foreach ($wkCfg['statuses'] as $i => $s) {
              if (!empty($s['done'])) continue;
              // "En proceso" por nombre o id; "pendiente" = el primer estado (Pedidos) o uno que se llame así
              $n = mb_strtolower($s['id'] . ' ' . $s['name']);
              if ($i === 0 || str_contains($n, 'proceso') || str_contains($n, 'curso') || str_contains($n, 'pendiente')) $wkSt[$s['id']] = $s + ['rank' => $i === 0 ? 2 : 1];
          }
          if ($wkSt) {
              $in = implode(',', array_map([$db, 'quote'], array_keys($wkSt)));
              $rows = $db->query("SELECT t.id, t.board_id, t.title, t.status, t.due_date, t.client_id, t.priority,
                      (SELECT GROUP_CONCAT(a.user_id) FROM g7_task_assignees a WHERE a.task_id = t.id) AS asg,
                      (SELECT COUNT(*) FROM g7_task_checks k WHERE k.task_id = t.id) AS nck,
                      (SELECT COUNT(*) FROM g7_task_checks k WHERE k.task_id = t.id AND k.done = 1) AS nckd
                  FROM g7_tasks t WHERE t.archived = 0 AND t.status IN ($in) AND $wkScope
                  ORDER BY t.updated_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
              // En proceso antes que pendientes; dentro de cada uno, lo que vence antes primero
              usort($rows, fn($a, $b) => [$wkSt[$a['status']]['rank'], $a['due_date'] ?: '9999-12-31'] <=> [$wkSt[$b['status']]['rank'], $b['due_date'] ?: '9999-12-31']);
              foreach ($rows as $r) {
                  $r['asg'] = $r['asg'] ? array_map('intval', explode(',', $r['asg'])) : [];
                  if (in_array($_uidIdx, $r['asg'], true)) $wkMine[] = $r;
                  $wkAll[] = $r;
              }
          }
      } catch (Throwable $e) { $wkMine = $wkAll = []; }
  }
  // Mis pedidos (tickets que pedí): en curso y resueltos para aprobar o calificar (trabajo/entrega_lib.php, ENTREGAS_CALIFICACION.md)
  $rqOpen = $rqDone = $rqRate = [];
  if (!empty($d3On['working']) && g7_can($_uidIdx, 'tareas') && is_file(__DIR__ . '/trabajo/entrega_lib.php')) {
      try {
          require_once __DIR__ . '/trabajo/entrega_lib.php';
          $rqQ = fn(string $w) => $db->query("SELECT t.id, t.subject, t.status, t.assignee_id, t.resolution_due, t.updated_at, t.resolved_at, tm.name AS team_name FROM g7_wk_tickets t LEFT JOIN g7_teams tm ON tm.id = t.team_id
              WHERE t.requester_id = " . (int)$_uidIdx . " AND t.origin <> 'tarea' AND $w ORDER BY t.updated_at DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
          $rqOpen = $rqQ("t.status IN ('abierto','en_curso','esperando')");
          $rqDone = $rqQ("t.status = 'resuelto'");
          $rqRate = wk_ev_pending($db, (int)$_uidIdx);
      } catch (Throwable $e) { $rqOpen = $rqDone = $rqRate = []; }
  }
  $rqAny = $rqOpen || $rqDone || $rqRate;
  if ($wkAll || $rqAny):
    $wkUsers = []; foreach ($db->query("SELECT id, COALESCE(NULLIF(display_name,''), username) AS name, avatar FROM g7_users WHERE active = 1") as $u) $wkUsers[(int)$u['id']] = $u;
    $wkCli = []; try { foreach ($db->query(g7_site_has('clientes') ? 'SELECT id, name FROM g7_clients' : 'SELECT id, name FROM g7_brands') as $c) $wkCli[(int)$c['id']] = $c['name']; } catch (Throwable $e) {}   // sin Clientes, la tarea es de una marca
    $wkToday = date('Y-m-d');
    // Formato de la lista de Tickets (Trabajo › Tickets): franja con el color de la prioridad, ícono, código, título, chips y estado
    $wkPriAll = []; foreach (($wkCfg['priorities'] ?? []) as $i => $p) $wkPriAll[$p['id']] = $p + ['rank' => $i];
    $wkDays = fn($t) => $t['due_date'] ? (int)round((strtotime($t['due_date']) - strtotime($wkToday)) / 86400) : null;
    // Cada tarea lleva a Trabajo: si es un ticket, al ticket (Trabajo › Tickets); si no, a la tarea en su tablero, como enlaza Trabajo
    $wkTk = [];
    try { $ids = array_map('intval', array_column($wkAll, 'id')); if ($ids) foreach ($db->query('SELECT id, task_id FROM g7_wk_tickets WHERE task_id IN (' . implode(',', $ids) . ')') as $r) $wkTk[(int)$r['task_id']] ??= (int)$r['id']; } catch (Throwable $e) {}
    $wkUrl = fn(array $t) => isset($wkTk[(int)$t['id']]) ? '/toolz/trabajo/#tickets/' . $wkTk[(int)$t['id']] : '/toolz/tareas.php?' . ($t['board_id'] ? 'b=' . (int)$t['board_id'] . '&' : '') . 't=' . (int)$t['id'];
    $wkCard = function (array $t) use ($wkSt, $wkUsers, $wkCli, $wkPriAll, $wkDays, $wkUrl, $wkTk) {
        static $i = 0; $i++; $tk = $wkTk[(int)$t['id']] ?? null;
        $st = $wkSt[$t['status']]; $h = fn($s) => htmlspecialchars((string)$s);
        $chip = fn($c, $html, $title = '') => '<span class="d3-tchip" style="--k:' . $c . '"' . ($title !== '' ? ' title="' . $h($title) . '"' : '') . '>' . $html . '</span>';
        $pr = $wkPriAll[$t['priority']] ?? null; $d = $wkDays($t);
        $chips = '';
        if ($t['client_id'] && isset($wkCli[(int)$t['client_id']])) $chips .= $chip('#3b82f6', '<i class="fa-solid fa-tag"></i>' . $h($wkCli[(int)$t['client_id']]));
        if ($pr && $pr['rank'] >= 2) $chips .= $chip($h($pr['color']), '<i class="fa-solid fa-flag"></i>' . $h($pr['name']));
        if ($d !== null) {
            $txt = $d < 0 ? 'Vencida · hace ' . -$d . ' d' : ($d === 0 ? 'Vence hoy' : ($d === 1 ? 'Vence mañana' : 'Vence · en ' . $d . ' d'));
            $chips .= $chip($d < 0 ? '#ef4444' : ($d <= 2 ? '#eab308' : '#64748b'), '<i class="fa-regular fa-clock"></i>' . $txt, 'Vence el ' . date('d/m/Y', strtotime($t['due_date'])));
        }
        if ($t['nck']) $chips .= $chip('#22c55e', '<i class="fa-regular fa-square-check"></i>' . (int)$t['nckd'] . '/' . (int)$t['nck'] . ' pasos');
        $avs = '';
        foreach (array_slice($t['asg'], 0, 3) as $uid) {
            $u = $wkUsers[$uid] ?? null; if (!$u) continue;
            $avs .= $u['avatar'] ? '<img src="' . $h($u['avatar']) . '" alt="" title="' . $h($u['name']) . '">'
                : '<span style="background:' . ['#ff6600', '#a78bfa', '#4ade80', '#60a5fa', '#f59e0b', '#ec4899'][ord($u['name'][0] ?? 'a') % 6] . '" title="' . $h($u['name']) . '">' . $h(mb_strtoupper(mb_substr($u['name'], 0, 1))) . '</span>';
        }
        if ($avs === '') $avs = '<span class="none" title="Sin asignar"><i class="fa-solid fa-user"></i></span>';
        return '<a class="d3-trow' . ($d !== null && $d < 0 ? ' late' : '') . '" style="--c:' . $h($pr['color'] ?? '#8b8b94') . ';--s:' . $h($st['color']) . ';--i:' . $i . '" href="' . htmlspecialchars($wkUrl($t)) . '">'
            . '<span class="d3-tic"><i class="fa-solid ' . ($tk ? 'fa-ticket' : 'fa-list-check') . '"></i></span>'
            . '<span class="d3-tmid"><span class="d3-ttop"><span class="d3-tcode">' . ($tk ? 'T-' . str_pad((string)$tk, 4, '0', STR_PAD_LEFT) : '#' . (int)$t['id']) . '</span><b>' . $h($t['title']) . '</b></span>'
            . ($chips !== '' ? '<span class="d3-tchips">' . $chips . '</span>' : '') . '</span>'
            . '<span class="d3-tend"><span class="d3-tst"><i></i>' . $h($st['name']) . '</span><span class="d3-tasg">' . $avs . '</span></span></a>';
    };
    // Resumen con el formato de los indicadores de Tickets
    $wkSum = function (array $list) use ($wkDays) {
        $late = $today = $week = 0;
        foreach ($list as $t) { $d = $wkDays($t); if ($d === null) continue; if ($d < 0) $late++; elseif ($d === 0) $today++; elseif ($d <= 7) $week++; }
        $k = fn($c, $ic, $lbl, $n, $sub, $alert = false) => '<div class="d3-kpi' . ($alert ? ' alert' : '') . '" style="--c:' . $c . '"><span class="ic"><i class="fa-solid ' . $ic . '"></i></span><span class="tx"><small>' . $lbl . '</small><b>' . $n . '</b><span>' . $sub . '</span></span></div>';
        return $k('#3b82f6', 'fa-list-check', 'En curso', count($list), 'pendientes y en proceso')
            . $k('#ef4444', 'fa-fire', 'Vencidas', $late, 'pasaron su fecha', $late > 0)
            . $k('#eab308', 'fa-bell', 'Vencen hoy', $today, 'para terminar hoy', $today > 0)
            . $k('#8b5cf6', 'fa-calendar-week', 'Esta semana', $week, 'vencen en 7 días');
    };
    $wkTab = $wkMine ? 'mine' : ($rqDone || $rqRate ? 'rqdone' : ($rqOpen ? 'rqopen' : 'all'));
    // Tarjeta de un pedido: código, asunto, quién lo atiende, estado y, si está resuelto o cerrado, aprobar / calificar (assets/calificar.js)
    $rqNames = []; foreach ($db->query("SELECT id, COALESCE(NULLIF(display_name,''), username) AS name FROM g7_users") as $u) $rqNames[(int)$u['id']] = $u['name'];
    $rqSt = ['abierto' => ['Abierto', '#3b82f6'], 'en_curso' => ['En curso', '#8b5cf6'], 'esperando' => ['Espera tu respuesta', '#f59e0b'], 'resuelto' => ['Resuelto', '#22c55e'], 'cerrado' => ['Cerrado', '#71717a']];
    $rqCard = function (array $t, string $mode) use ($rqNames, $rqSt) {
        $h = fn($s) => htmlspecialchars((string)$s); $id = (int)$t['id']; $st = $rqSt[$t['status'] ?? 'cerrado'] ?? ['', '#888'];
        $who = !empty($t['assignee_id']) ? 'Lo atiende ' . $h($rqNames[(int)$t['assignee_id']] ?? 'alguien del equipo') : 'Todavía sin asignar';
        $team = !empty($t['team_name'] ?? $t['team'] ?? '') ? ' · ' . $h($t['team_name'] ?? $t['team']) : '';
        $act = '';
        if ($mode === 'done') $act = '<div class="d3-rq-a"><button type="button" class="d3-rq-b ok" data-ev="approve" data-id="' . $id . '"><i class="fa-solid fa-check-double"></i> Aprobar y cerrar</button><button type="button" class="d3-rq-b" data-ev="changes" data-id="' . $id . '">Pedir cambios</button></div>';
        if ($mode === 'rate') $act = '<div class="d3-rq-a"><span class="d3-rq-q">¿Cómo te fue?</span><span class="d3-rq-stars" role="group" aria-label="Calificar ' . $h(wk_tk_code($id)) . '">'
            . implode('', array_map(fn($n) => '<button type="button" data-ev="rate" data-id="' . $id . '" data-n="' . $n . '" aria-label="' . $n . ' de 5">★</button>', range(1, 5))) . '</span></div>';
        return '<div class="d3-rq" style="--k:' . $st[1] . '"><a href="/toolz/trabajo/#tickets/' . $id . '" class="d3-rq-t"><span class="d3-rq-code">' . $h(wk_tk_code($id)) . '</span><b>' . $h($t['subject']) . '</b>'
            . '<small>' . $who . $team . '</small></a><span class="d3-rq-st">' . ($mode === 'rate' ? 'Cerrado' : $h($st[0])) . '</span>' . $act . '</div>';
    };
    $rqEmpty = fn($txt) => '<div class="d3-wk-empty">' . $txt . ' <a href="/toolz/trabajo/#mis-solicitudes">Ver Mis solicitudes</a></div>'; ?>
  <section class="d3-sec d3-in" style="animation-delay:.05s" id="d3-working">
    <div class="d3-sec-head"><h2><i class="fa-solid fa-person-digging" style="color:var(--g7-accent);font-size:13px"></i> <?= htmlspecialchars(home_title($H, 'working')) ?></h2>
      <div style="display:flex;align-items:center;gap:10px">
        <div class="d3-wk-tabs" role="tablist" aria-label="Qué ver">
          <button type="button" role="tab" data-wk="mine" class="<?= $wkTab === 'mine' ? 'on' : '' ?>" aria-selected="<?= $wkTab === 'mine' ? 'true' : 'false' ?>">Mis tareas en curso · <?= count($wkMine) ?></button>
          <button type="button" role="tab" data-wk="rqopen" class="<?= $wkTab === 'rqopen' ? 'on' : '' ?>" aria-selected="<?= $wkTab === 'rqopen' ? 'true' : 'false' ?>">Mis pedidos en curso · <?= count($rqOpen) ?></button>
          <button type="button" role="tab" data-wk="rqdone" class="<?= $wkTab === 'rqdone' ? 'on' : '' ?>" aria-selected="<?= $wkTab === 'rqdone' ? 'true' : 'false' ?>">Mis pedidos resueltos · <?= count($rqDone) + count($rqRate) ?><?= $rqDone || $rqRate ? ' <i class="d3-rq-dot" aria-label="Pendientes"></i>' : '' ?></button>
          <button type="button" role="tab" data-wk="all" class="<?= $wkTab === 'all' ? 'on' : '' ?>" aria-selected="<?= $wkTab === 'all' ? 'true' : 'false' ?>">Todo el equipo · <?= count($wkAll) ?></button>
        </div>
        <a class="d3-link" href="/toolz/trabajo/#mis-tareas">Ir a Trabajo <i class="fa-solid fa-chevron-right"></i></a></div></div>
    <div class="d3-wks" data-wkp="mine"<?= $wkTab === 'mine' ? '' : ' hidden' ?>><?= $wkSum($wkMine) ?></div>
    <div class="d3-wks" data-wkp="all"<?= $wkTab === 'all' ? '' : ' hidden' ?>><?= $wkSum($wkAll) ?></div>
    <div class="d3-wk" data-wkp="mine"<?= $wkTab === 'mine' ? '' : ' hidden' ?>><?= $wkMine ? implode('', array_map($wkCard, array_slice($wkMine, 0, 9))) : '<div class="d3-wk-empty">No tienes tareas en proceso ni pendientes. <a href="/toolz/trabajo/">Ir a Trabajo</a></div>' ?></div>
    <div class="d3-wk" data-wkp="all"<?= $wkTab === 'all' ? '' : ' hidden' ?>><?= $wkAll ? implode('', array_map($wkCard, array_slice($wkAll, 0, 12))) : '<div class="d3-wk-empty">Nadie del equipo tiene tareas en curso.</div>' ?></div>
    <div class="d3-wk d3-rqs" data-wkp="rqopen"<?= $wkTab === 'rqopen' ? '' : ' hidden' ?>><?= $rqOpen ? implode('', array_map(fn($t) => $rqCard($t, 'open'), $rqOpen)) : $rqEmpty('No tienes pedidos en curso.') ?></div>
    <div class="d3-wk d3-rqs" data-wkp="rqdone"<?= $wkTab === 'rqdone' ? '' : ' hidden' ?>><?= $rqDone || $rqRate
        ? ($rqDone ? '<p class="d3-rq-h">Te los entregaron: revísalos y apruébalos</p>' . implode('', array_map(fn($t) => $rqCard($t, 'done'), $rqDone)) : '')
          . ($rqRate ? '<p class="d3-rq-h">Cerrados sin calificar</p>' . implode('', array_map(fn($t) => $rqCard($t, 'rate'), $rqRate)) : '')
        : $rqEmpty('No tienes pedidos para aprobar ni calificar.') ?></div>
  </section>
  <script src="/toolz/assets/calificar.js?v=<?= (int)@filemtime(G7_SITE_ROOT . '/assets/calificar.js') ?>"></script>
  <script src="/toolz/assets/home-pedidos.js?v=<?= (int)@filemtime(G7_SITE_ROOT . '/assets/home-pedidos.js') ?>"></script>
  <script src="/toolz/assets/home-1.js?v=<?= (int)@filemtime(G7_SITE_ROOT . '/assets/home-1.js') ?>"></script>
  <?php endif; $HB['working'] = ob_get_clean(); ob_start(); ?>

  <?php if ($d3Recent): ?>
  <section class="d3-sec d3-in" style="animation-delay:.06s" id="d3-recent-sec">
    <div class="d3-sec-head"><h2><?= htmlspecialchars(home_title($H, 'recent')) ?></h2></div>
    <div class="d3-recent">
      <?php foreach ($d3Recent as $t): ?>
      <a class="d3-rc" href="<?= htmlspecialchars($t['url']) ?>" data-on-click="trackAcceso" data-click-1="event" data-click-2="s:<?= htmlspecialchars($t['url']) ?>" data-click-3="s:<?= htmlspecialchars($t['label'], ENT_QUOTES) ?>">
        <span class="d3-ic"><i class="fa-solid <?= htmlspecialchars($t['icon']) ?>"></i></span>
        <span><b><?= htmlspecialchars($t['label']) ?></b><small><?= $d3Ago($t['when']) ?></small></span>
        <i class="fa-solid fa-arrow-right"></i>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
  <?php $HB['recent'] = ob_get_clean(); ob_start(); ?>

  <?php if ($d3Quick): ?>
  <section class="d3-sec d3-in" style="animation-delay:.08s" data-group>
    <div class="d3-sec-head"><h2><i class="fa-solid fa-bolt" style="color:var(--g7-accent);font-size:13px"></i> <?= htmlspecialchars(home_title($H, 'tiles')) ?></h2></div>
    <div class="d3-tiles">
      <?php foreach ($d3Quick as $t): ?>
      <a href="<?= htmlspecialchars($t['href']) ?>" class="tc" style="--tc:rgb(<?= $t['c'] ?>)" data-q="<?= htmlspecialchars(mb_strtolower($t['name'] . ' ' . $t['desc'])) ?>"<?= $t['newtab'] ? ' target="_blank" rel="noopener"' : '' ?> data-on-click="trackAcceso" data-click-1="event" data-click-2="j:<?= htmlspecialchars(json_encode($t['href']), ENT_QUOTES) ?>" data-click-3="j:<?= htmlspecialchars(json_encode($t['name']), ENT_QUOTES) ?>">
        <div class="tc-art" style="background:linear-gradient(145deg,rgba(<?= $t['c'] ?>,.22) 0%,rgba(<?= $t['c'] ?>,.05) 100%)">
          <div class="tc-glow" style="background:radial-gradient(circle at 60% 30%,rgba(<?= $t['c'] ?>,.38),transparent 70%)"></div>
          <i class="fa-solid <?= htmlspecialchars($t['icon']) ?> tc-art-icon" style="color:rgb(<?= $t['c'] ?>)"></i>
          <?php if ($t['badge'] !== ''): ?><span class="tc-badge" style="background:rgba(<?= $t['c'] ?>,.9);color:#000"><?= htmlspecialchars($t['badge']) ?></span><?php endif; ?>
        </div>
        <div class="tc-body"><div class="tc-name"><?= htmlspecialchars($t['name']) ?></div><div class="tc-desc"><?= htmlspecialchars($t['desc']) ?></div></div>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
  <?php $HB['tiles'] = ob_get_clean(); ob_start(); ?>

  <?php if ($d3Groups): ?>
  <section class="d3-sec d3-in" style="animation-delay:.1s">
    <div class="d3-sec-head"><h2><?= htmlspecialchars(home_title($H, 'more')) ?> <small><?= $d3MoreCount ?></small></h2>
      <button type="button" class="d3-link" style="background:none;border:none;cursor:pointer;font-family:inherit" data-on-click="toggleAccesosModal">Buscar con <kbd style="font:600 10px var(--g7-font-mono);border:1px solid var(--g7-border-3);border-radius:4px;padding:0 5px">Ctrl K</kbd></button></div>
    <?php foreach ($d3Groups as $g => $tools): ?>
    <div class="d3-group" data-group>
      <?php if ($g !== ''): ?><div class="d3-group-t"><?= htmlspecialchars($g) ?></div><?php endif; ?>
      <div class="d3-grid">
        <?php foreach ($tools as $t): ?>
        <a class="d3-tool" href="<?= htmlspecialchars($t['url']) ?>" data-q="<?= htmlspecialchars(mb_strtolower($t['label'] . ' ' . $t['desc'] . ' ' . $g)) ?>"
           data-on-click="trackAcceso" data-click-1="event" data-click-2="s:<?= htmlspecialchars($t['url']) ?>" data-click-3="s:<?= htmlspecialchars($t['label'], ENT_QUOTES) ?>">
          <span class="d3-ic"><i class="fa-solid <?= htmlspecialchars($t['icon']) ?>"></i></span>
          <span><b><?= htmlspecialchars($t['label']) ?></b><small><?= htmlspecialchars($t['desc']) ?></small></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
  <?php $HB['more'] = ob_get_clean(); ob_start(); ?>

  <?php $d3PanelSoon = $H['panel_soon'] && g7_can($_uidIdx, 'promos'); $d3PanelSum = $H['panel_summary'] || $H['panel_activity']; ?>
  <?php if ($d3PanelSoon || $d3PanelSum): ?>
  <div class="d3-cols d3-in" style="animation-delay:.14s<?= $d3PanelSoon && $d3PanelSum ? '' : ';grid-template-columns:1fr' ?>">
    <?php if ($d3PanelSoon): ?>
    <section class="d3-card">
      <div class="d3-sec-head"><h2>Vencen pronto</h2><a class="d3-link" href="/toolz/promos/">Ver promos <i class="fa-solid fa-chevron-right"></i></a></div>
      <?php if ($promosSoon): foreach ($promosSoon as $p):
        $dl = (int)round((strtotime($p['end_date']) - strtotime('today')) / 86400);
        $cls = $dl <= 1 ? 'hot' : ($dl <= 3 ? 'warm' : ''); ?>
      <div class="d3-row">
        <div style="min-width:0"><b><?= htmlspecialchars($p['name']) ?></b><small><?= htmlspecialchars($p['project'] ?: ($TYPE_LABELS[$p['type']] ?? $p['type'] ?? '')) ?></small></div>
        <span class="d3-days <?= $cls ?>"><?= $dl <= 0 ? 'hoy' : ($dl === 1 ? 'mañana' : $dl . ' días') ?></span>
      </div>
      <?php endforeach; else: ?>
      <div class="d3-none"><i class="fa-regular fa-calendar-check"></i>Nada vence en los próximos 7 días.</div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($d3PanelSum): ?>
    <section class="d3-card">
      <?php if ($H['panel_summary']): ?>
      <div class="d3-sec-head"><h2>Resumen</h2><?php if (g7_can($_uidIdx, 'stats')): ?><a class="d3-link" href="/toolz/stats.php">Ver stats <i class="fa-solid fa-chevron-right"></i></a><?php endif; ?></div>
      <div class="d3-stats">
        <?php if (g7_can($_uidIdx, 'promos')): ?>
        <div class="d3-stat"><b><?= number_format((int)$activePromos, 0, ',', '.') ?></b><small>Promos activas</small></div>
        <div class="d3-stat"><b><?= number_format((int)$totalContent, 0, ',', '.') ?></b><small>Contenidos</small></div>
        <?php endif; ?>
        <?php if (g7_can($_uidIdx, 'links')): ?>
        <div class="d3-stat"><b><?= number_format((int)$totalLinks, 0, ',', '.') ?></b><small>En la biblioteca</small></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($H['panel_activity']): ?>
      <div class="d3-sec-head" style="margin-bottom:4px"><h2 style="font-size:13px">Actividad del equipo</h2></div>
      <?php $d3Acts = array_slice($recentActivity, 0, 5); if ($d3Acts): foreach ($d3Acts as $a): try { ?>
      <div class="d3-act">
        <div class="d3-act-ic"><?= g7ActivityIcon($a['type'] ?? '') ?></div>
        <div style="min-width:0"><div class="d3-act-t"><?= g7ActivityText($a) ?></div><div class="d3-act-time"><?= g7TimeAgo($a['created_at'] ?? '') ?></div></div>
      </div>
      <?php } catch (Exception $e) {} endforeach; else: ?>
      <div class="d3-none">Todavía no hay actividad.</div>
      <?php endif; ?>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php $HB['panels'] = ob_get_clean(); ?>

  <?php foreach ([['favs', $myFavs, false], ['popular', $popular, true]] as [$sid, $list, $showClicks]): $title = htmlspecialchars(home_title($H, $sid)); ob_start(); if ($list): ?>
  <section class="d3-sec d3-in" style="animation-delay:.18s">
    <div class="d3-sec-head"><h2><?= $title ?></h2><a class="d3-link" href="/toolz/links/">Abrir biblioteca <i class="fa-solid fa-chevron-right"></i></a></div>
    <div class="d3-shelf">
      <?php foreach ($list as $l): $src = $d3Img($l['img'] ?? ''); ?>
      <a class="d3-res" href="<?= htmlspecialchars($l['url']) ?>" target="_blank" rel="noopener" data-on-click="trackClick" data-click-1="n:<?= (int)$l['id'] ?>">
        <div class="d3-res-img"><?php if ($src): ?><img src="<?= htmlspecialchars($src) ?>" alt="" loading="lazy" data-on-error="@remove"><?php else: ?><i class="fa-solid fa-link"></i><?php endif; ?>
          <?php if ($showClicks): ?><span><?= (int)$l['clicks'] ?> clics</span><?php endif; ?></div>
        <div><b><?= htmlspecialchars($l['name']) ?></b><small><?= htmlspecialchars($l['cat_name']) ?></small></div>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; $HB[$sid] = ob_get_clean(); endforeach; ?>



  <?php
  // Clientes: los que no terminaron, con su foto. Ficha (si tiene acceso a Clientes), Materiales y Tareas abiertas
  ob_start();
  $d3CanCli = g7_can($_uidIdx, 'clientes'); $d3CanMat = g7_can($_uidIdx, 'stock'); $d3CanTk = g7_can($_uidIdx, 'tareas');
  $d3Clients = [];
  if (g7_site_has('clientes') && !empty($d3On['clients']) && ($d3CanCli || $d3CanMat)) {
      try {
          $d3Clients = $db->query("SELECT c.id, c.name, c.status, c.avatar,
                  (SELECT COUNT(*) FROM g7_client_files f WHERE f.client_id = c.id) AS files
              FROM g7_clients c WHERE c.status <> 'terminado' ORDER BY (c.status = 'activo') DESC, c.updated_at DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
          if ($d3Clients && $d3CanTk && is_file(__DIR__ . '/tareas_lib.php')) try {   // si falla el conteo de tareas, igual se muestran los clientes
              require_once __DIR__ . '/tareas_lib.php';
              $open = tk_status_ids(tk_config($db), false);
              if ($open) {
                  $st = $db->query('SELECT client_id, COUNT(*) n FROM g7_tasks WHERE client_id IS NOT NULL AND status IN (' . implode(',', array_map([$db, 'quote'], $open)) . ') GROUP BY client_id');
                  $tk = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'n', 'client_id');
                  foreach ($d3Clients as &$c) $c['tasks'] = (int)($tk[$c['id']] ?? 0);
                  unset($c);
              }
          } catch (Throwable $e) {}
      } catch (Throwable $e) { $d3Clients = []; }
  }
  $d3ClSt = function_exists('st_labels') ? array_diff_key(st_labels('clients'), ['terminado' => 1]) : ['potencial' => 'Potencial', 'activo' => 'Activo', 'pausado' => 'Pausado'];   // estados editables (Admin › Estados)
  $d3ClCol = ['#ff6600', '#a78bfa', '#4ade80', '#60a5fa', '#f59e0b', '#ec4899'];
  if ($d3Clients): ?>
  <section class="d3-sec d3-in" style="animation-delay:.09s">
    <div class="d3-sec-head"><h2><i class="fa-solid fa-address-book" style="color:var(--g7-accent);font-size:13px"></i> <?= htmlspecialchars(home_title($H, 'clients')) ?></h2>
      <a class="d3-link" href="<?= $d3CanCli ? '/toolz/clientes.php' : '/toolz/stock.php' ?>">Ver todos <i class="fa-solid fa-chevron-right"></i></a></div>
    <div class="d3-clients">
      <?php foreach ($d3Clients as $c):
        $ini = mb_strtoupper(mb_substr(trim($c['name']) ?: '?', 0, 1));
        $av = preg_match('#^/toolz/client_avatars/[A-Za-z0-9_.-]+$#', (string)$c['avatar']) ? $c['avatar'] : '';
        $main = $d3CanCli ? '/toolz/clientes.php?id=' . (int)$c['id'] : '/toolz/stock.php?c=' . (int)$c['id'];
        $meta = [];
        if (isset($c['tasks'])) $meta[] = $c['tasks'] ? $c['tasks'] . ' tarea' . ($c['tasks'] == 1 ? '' : 's') . ' abierta' . ($c['tasks'] == 1 ? '' : 's') : 'Sin tareas abiertas';
        $meta[] = $c['files'] ? $c['files'] . ' archivo' . ($c['files'] == 1 ? '' : 's') : 'Sin materiales'; ?>
      <div class="d3-cl">
        <a class="d3-cl-top" href="<?= $main ?>">
          <span class="d3-cl-av" style="background:<?= $av ? 'var(--g7-bg-5)' : $d3ClCol[mb_ord($ini) % 6] ?>"><?php if ($av): ?><img src="<?= htmlspecialchars($av) ?>" alt=""><?php else: ?><?= htmlspecialchars($ini) ?><?php endif; ?></span>
          <span style="min-width:0"><b><?= htmlspecialchars($c['name']) ?></b><small><?= htmlspecialchars(implode(' · ', $meta)) ?></small></span>
          <span class="d3-cl-st <?= htmlspecialchars($c['status']) ?>"><?= htmlspecialchars($d3ClSt[$c['status']] ?? $c['status']) ?></span>
        </a>
        <div class="d3-cl-act">
          <?php if ($d3CanMat): ?><a href="/toolz/stock.php?c=<?= (int)$c['id'] ?>"><i class="fa-solid fa-folder-open"></i> Materiales</a><?php endif; ?>
          <?php if ($d3CanTk): ?><a href="/toolz/tareas.php?c=<?= (int)$c['id'] ?>"><i class="fa-solid fa-list-check"></i> Tareas</a><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; $HB['clients'] = ob_get_clean(); ?>
  <?php
  // "¿Cómo estás hoy?": botón en el saludo (late si no respondí en las últimas 24 h) que abre un modal. Lo maneja assets/home-mood.js
  $mdBtn = $mdDlg = '';
  if (is_file(__DIR__ . '/mood_lib.php')) { try {
      require_once __DIR__ . '/mood_lib.php';
      $mdState = ['mine' => mood_mine($db, (int)$_uidIdx)] + ($isAdmin ? ['team' => mood_team($db)] : []);
      $mdCfg = ['levels' => MOOD_LEVELS, 'reasons' => MOOD_REASONS, 'min' => MOOD_MIN_GROUP, 'name' => $d3First ?? '', 'stats' => $isAdmin && g7_can((int)$_uidIdx, 'stats') ? '/toolz/stats.php?section=bienestar' : ''];
      $mdCur = $mdState['mine']['current']; $mdLv = $mdCur ? MOOD_LEVELS[$mdCur['mood']] : null;
      $mdBtn = '<button type="button" class="d3-cust-btn d3-md-btn' . ($mdState['mine']['can'] ? ' beat' : ' done') . '" id="d3-md-btn"'
          . ($mdLv ? ' style="--m:' . $mdLv['color'] . '"' : '') . ' title="' . ($mdState['mine']['can'] ? 'Cuéntanos cómo estás hoy' : 'Ya respondiste: puedes volver a hacerlo en ' . (int)$mdState['mine']['wait_h'] . ' h') . '">'
          . '<i class="fa-solid ' . ($mdLv ? $mdLv['icon'] : 'fa-heart') . '"></i><span>' . ($mdLv ? 'Hoy: ' . htmlspecialchars($mdLv['label']) : '¿Cómo estás hoy?') . '</span></button>';
      $mdDlg = '<dialog class="d3-cust d3-md-dlg" id="d3-md-dlg" aria-labelledby="d3-md-t"><div class="d3-cust-in">'
          . '<div class="d3-cust-h"><h3 id="d3-md-t"><i class="fa-solid fa-heart-pulse" style="color:#f472b6"></i> ¿Cómo estás hoy?</h3><button type="button" class="d3-cust-btn" style="height:32px;padding:0 10px" data-x aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>'
          . '<div class="d3-cust-b"><div class="d3-md" id="d3-mood" data-state="' . htmlspecialchars(json_encode($mdState, JSON_UNESCAPED_UNICODE)) . '" data-cfg="' . htmlspecialchars(json_encode($mdCfg, JSON_UNESCAPED_UNICODE)) . '"></div></div>'
          . '<div class="d3-md-foot"><i class="fa-solid fa-user-secret"></i> Tu respuesta es anónima: el equipo solo ve un promedio.</div></div></dialog>'
          . '<script src="/toolz/assets/home-mood.js?v=' . (int)@filemtime(G7_SITE_ROOT . '/assets/home-mood.js') . '"></script>';
  } catch (Throwable $e) { $mdBtn = $mdDlg = ''; } } ?>


  <?php
  // Pastillas del día bajo el saludo: solo lo que tiene algo para mostrar
  $d3Chips = [];
  if (!empty($wkMine)) $d3Chips[] = ['/toolz/trabajo/#mis-tareas', 'fa-person-digging', 'var(--p1)', count($wkMine) === 1 ? '1 tarea tuya en curso' : count($wkMine) . ' tareas tuyas en curso'];
  if (!empty($promosToday)) $d3Chips[] = ['/toolz/promos/', 'fa-fire', 'var(--p5)', count($promosToday) === 1 ? '1 vence hoy' : count($promosToday) . ' vencen hoy'];
  elseif (!empty($d3SoonCount) && g7_can($_uidIdx, 'promos')) $d3Chips[] = ['/toolz/promos/', 'fa-hourglass-half', 'var(--p3)', $d3SoonCount === 1 ? '1 vence esta semana' : $d3SoonCount . ' vencen esta semana'];
  $d3ClAct = count(array_filter($d3Clients, fn($c) => $c['status'] === 'activo'));
  if ($d3ClAct) {   // "3 clientes activos" con el nombre del rubro ("2 pacientes activos", "1 marca activa")
      $one = $d3ClAct === 1; $fem = function_exists('g7_terms') && (g7_terms()['client']['g'] ?? 'm') === 'f';
      $noun = function_exists('g7_term') ? g7_term('client', $one ? 's' : 'p', true) : ($one ? 'cliente' : 'clientes');
      $d3Chips[] = [$d3CanCli ? '/toolz/clientes.php' : '/toolz/stock.php', 'fa-face-smile', 'var(--p2)', "$d3ClAct $noun " . ($fem ? 'activa' : 'activo') . ($one ? '' : 's')];
  }
  if (!$d3Chips) $d3Chips[] = ['', 'fa-mug-hot', 'var(--p4)', 'Todo en orden por hoy'];
  $d3ChipsHtml = '<div class="d3-chips">' . implode('', array_map(fn($c) => ($c[0] ? '<a class="d3-chip" href="' . htmlspecialchars($c[0]) . '"' : '<span class="d3-chip"') . ' style="--h:' . $c[2] . '"><i class="fa-solid ' . $c[1] . '"></i>' . htmlspecialchars($c[3]) . ($c[0] ? '</a>' : '</span>'), $d3Chips)) . '</div>';
  echo str_replace(['<!--D3CHIPS-->', '<!--D3MOOD-->'], [$d3ChipsHtml, $mdBtn ?? ''], $d3TopHtml) . ($mdDlg ?? '');
  foreach ($H['sections'] as $_s) if ($_s['on']) echo $HB[$_s['id']] ?? ''; ?>

  <?php // Personalizar mi Inicio: secciones que el admin dejó prendidas (en mi orden) y accesos rápidos
  // Textos pensados para quien personaliza su Inicio (los de HOME_SECTIONS hablan al admin)
  $custDesc = ['alert' => 'Las promos que terminan hoy, en rojo.', 'working' => 'Tus tareas en proceso y pendientes, y las del equipo.', 'recent' => 'Las últimas herramientas que abriste.',
      'tiles' => 'Los bloques de colores de las herramientas.', 'clients' => 'Los clientes activos, con su foto.', 'more' => 'Las herramientas que no tienen bloque.',
      'panels' => 'Promos por vencer, números y actividad del equipo.', 'favs' => 'Tus favoritos de la Biblioteca.', 'popular' => 'Lo más abierto de la Biblioteca en la semana.'];
  $custSecs = [];
  foreach ($H['sections'] as $s) foreach ($d3AdminSecs as $a) if ($a['id'] === $s['id']) $custSecs[] = ['id' => $s['id'], 'label' => home_title($H, $s['id']), 'desc' => $custDesc[$s['id']] ?? HOME_SECTIONS[$s['id']]['desc'], 'on' => !in_array($s['id'], $myHidden, true)]; ?>
  <dialog class="d3-cust" id="d3-cust" aria-labelledby="d3-cust-t"><div class="d3-cust-in">
    <div class="d3-cust-h"><h3 id="d3-cust-t">Personalizar mi Inicio</h3><button type="button" class="d3-cust-btn" style="height:32px;padding:0 10px" data-x aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="d3-cust-b">
      <p>Solo cambia tu Inicio: el resto del equipo lo sigue viendo como lo tenga cada uno.</p>
      <h4>Secciones · arrastra para ordenar</h4>
      <div id="d3-cs-list"><?php foreach ($custSecs as $s): ?>
        <div class="d3-cs" draggable="true" data-id="<?= htmlspecialchars($s['id']) ?>"><i class="fa-solid fa-grip-vertical g"></i>
          <label><input type="checkbox" <?= $s['on'] ? 'checked' : '' ?>><span><?= htmlspecialchars($s['label']) ?><small><?= htmlspecialchars($s['desc']) ?></small></span></label>
          <button type="button" data-up aria-label="Subir"><i class="fa-solid fa-arrow-up"></i></button><button type="button" data-down aria-label="Bajar"><i class="fa-solid fa-arrow-down"></i></button></div>
      <?php endforeach; ?></div>
      <?php if ($d3AllTiles): ?>
      <h4 style="margin-top:16px">Accesos rápidos que quieres ver</h4>
      <div class="d3-ct" id="d3-ct"><?php foreach ($d3AllTiles as $t): ?>
        <label><input type="checkbox" value="<?= htmlspecialchars($t['id']) ?>" <?= in_array($t['id'], $myTilesHidden, true) ? '' : 'checked' ?>><i class="fa-solid <?= htmlspecialchars($t['icon']) ?>" style="color:<?= htmlspecialchars($t['color']) ?>"></i><span><?= htmlspecialchars($t['name']) ?></span></label>
      <?php endforeach; ?></div>
      <?php endif; ?>
    </div>
    <div class="d3-cust-f"><button type="button" id="d3-cust-reset">Volver a como estaba</button><span class="sp"></span><button type="button" data-x>Cancelar</button><button type="button" class="p" id="d3-cust-save">Guardar</button></div>
  </div></dialog>
  <script src="/toolz/assets/home-2.js?v=<?= (int)@filemtime(G7_SITE_ROOT . '/assets/home-2.js') ?>"></script>
  <div class="d3-empty" id="d3-empty" style="margin-top:24px">No hay herramientas que coincidan. Prueba con otra palabra.</div>
</main>

<script src="/toolz/assets/home-3.js?v=<?= (int)@filemtime(G7_SITE_ROOT . '/assets/home-3.js') ?>"></script>
<script src="/toolz/assets/dash-hero.js?v=<?= (int)@filemtime(__DIR__ . '/assets/dash-hero.js') ?>"></script>
</body>
</html>
