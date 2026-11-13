<?php
require_once __DIR__ . '/auth.php';
$currentUser = requireLogin();
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo: el Admin ya no tiene código en línea (SEC-24)
if ($currentUser['role'] !== 'admin') {
    http_response_code(403);
    die('<h1 style="font-family:sans-serif;color:#ef4444;padding:40px">403 — Acceso denegado</h1>');
}
$isAdmin = true;
$db = getDB();

// ── LOAD THEME ────────────────────────────────────────────
try {
    $_ap = $db->prepare("SELECT pref_key,pref_value FROM g7_user_prefs WHERE user_id=? AND pref_key IN ('theme','font_size')");
    $_ap->execute([$currentUser['id']]);
    $_ap_prefs = [];
    foreach($_ap->fetchAll() as $_r) $_ap_prefs[$_r['pref_key']] = json_decode($_r['pref_value'],true);
    $_ap_theme = $_ap_prefs['theme'] ?? 'dark';
    $_ap_font  = $_ap_prefs['font_size'] ?? 'medium';
} catch(Exception $e) { $_ap_theme='dark'; $_ap_font='medium'; }

// ── API ACTIONS ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $in  = json_decode(file_get_contents('php://input'), true) ?? [];
    $act = $in['action'] ?? '';
    // Exclusivo del superadmin: claves de IA y Jira, auditoría, respaldo y configuración cruda
    if (in_array($act, ['save_jira', 'test_jira', 'jira_projects', 'save_jira_features', 'save_ia', 'test_claude', 'test_leonardo', 'save_stock', 'test_stock', 'audit_list', 'config_export', 'config_import', 'config_raw_list', 'config_raw_save', 'config_raw_delete', 'module_custom_save', 'module_custom_delete', 'upd_state', 'upd_run', 'save_monday'], true)
        && !g7_super_ok((int)$currentUser['id'])) {
        g7_audit('super.denied', $act, null, (int)$currentUser['id']);
        http_response_code(403); echo json_encode(['error' => 'Solo el superadmin puede hacer esto.']); exit;
    }
    if ($act === 'upd_state' || $act === 'upd_run') { require __DIR__ . '/admin_updates_api.php'; exit; }
    require __DIR__ . '/admin_api.php'; // accesos, marcas, equipos, usuarios, ajustes, auditoría

    // ── USERS ──────────────────────────────────────────────
    if ($act === 'get_users') {
        $rows = $db->query("SELECT id,username,display_name,email,role,active,created_at,last_seen,avatar FROM g7_users ORDER BY created_at DESC");
        echo json_encode(['users' => $rows->fetchAll()]); exit;
    }
    if ($act === 'create_user') {
        if ($why = g7_super_denies((int)$currentUser['id'], 0, ($in['role'] ?? '') === 'admin' ? 'admin' : 'user')) { http_response_code(403); echo json_encode(['error' => $why]); exit; }
        $u = trim($in['username'] ?? ''); $e = trim($in['email'] ?? '');
        $p = $in['password'] ?? ''; $r = ($in['role'] ?? '') === 'admin' ? 'admin' : 'user';
        if (!$u || !$p) { echo json_encode(['error'=>'Usuario y contraseña requeridos']); exit; }
        $exists = $db->prepare('SELECT id FROM g7_users WHERE username=? OR email=?');
        $exists->execute([$u, $e]);
        if ($exists->fetchColumn()) { echo json_encode(['error'=>'El usuario o email ya existe']); exit; }
        if ($why = lic_can_add('users', $db)) { echo json_encode(['error' => $why]); exit; }   // tope de usuarios del plan
        $hash = password_hash($p, PASSWORD_DEFAULT);
        $db->prepare('INSERT INTO g7_users (username,email,display_name,password,role,active) VALUES (?,?,?,?,?,1)')
           ->execute([$u, $e, trim($in['display_name']??$u), $hash, $r]);
        echo json_encode(['ok'=>true,'id'=>$db->lastInsertId()]); exit;
    }
    if ($act === 'update_user') {
        $id = (int)($in['id']??0);
        if ($why = g7_super_denies((int)$currentUser['id'], $id, isset($in['role']) ? ($in['role'] === 'admin' ? 'admin' : 'user') : null)) { http_response_code(403); echo json_encode(['error' => $why]); exit; }
        $fields = [];
        if (isset($in['role']))   { $fields[] = "role='".($in['role']==='admin'?'admin':'user')."'"; }
        if (isset($in['active'])) { $fields[] = "active=".($in['active']?1:0); }
        if (isset($in['display_name'])) { $fields[] = "display_name=".$db->quote(trim($in['display_name'])); }
        if (isset($in['email']))  { $fields[] = "email=".$db->quote(trim($in['email'])); }
        if (isset($in['password']) && $in['password']) { $fields[] = "password=".$db->quote(password_hash($in['password'],PASSWORD_DEFAULT)); }
        if ($fields && $id) {
            $db->prepare("UPDATE g7_users SET ".implode(',',$fields)." WHERE id=?")->execute([$id]);
        }
        echo json_encode(['ok'=>true]); exit;
    }
    if ($act === 'delete_user') {
        $id = (int)($in['id']??0);
        if ($id === (int)$currentUser['id']) { echo json_encode(['error'=>'No puedes eliminar tu propia cuenta']); exit; }
        if (g7_is_super($id)) { http_response_code(403); echo json_encode(['error' => 'Primero quitale la marca de superadmin.']); exit; }
        if ($why = g7_super_denies((int)$currentUser['id'], $id)) { http_response_code(403); echo json_encode(['error' => $why]); exit; }
        $db->prepare('DELETE FROM g7_users WHERE id=?')->execute([$id]);
        echo json_encode(['ok'=>true]); exit;
    }

    // ── SYSTEM CONFIG ──────────────────────────────────────
    // Ajustes heredados (barra superior, avisos, plugins). Un admin común solo lee y escribe estas claves;
    // el resto de g7_config (políticas de módulos, módulos propios, claves) es del superadmin.
    $ADM_LEGACY_KEYS = ['nav_links', 'nav_align', 'logo_url', 'buzz_enabled', 'notif_sound_enabled', 'notif_sound_type', 'notif_beeps_default', 'plugins_state'];
    $admIsSuper = g7_super_ok((int)$currentUser['id']);
    if ($act === 'get_config') {
        $rows = $db->query("SELECT cfg_key,cfg_value FROM g7_config")->fetchAll();
        $cfg = []; foreach($rows as $r) if ($admIsSuper || in_array($r['cfg_key'], $ADM_LEGACY_KEYS, true)) $cfg[$r['cfg_key']] = $r['cfg_value'];
        echo json_encode(['config'=>$cfg]); exit;
    }
    // ── LOGO UPLOAD ────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['f']) && ($_POST['t']??'')==='logo') {
        $f=$_FILES['f'];
        if($f['error']||$f['size']>5*1024*1024){echo json_encode(['error'=>'upload error']);exit;}
        $mime=mime_content_type($f['tmp_name']);
        if(!str_starts_with($mime,'image/')&&$mime!=='image/svg+xml'){echo json_encode(['error'=>'not image']);exit;}
        $dir=__DIR__.'/assets/';if(!is_dir($dir))mkdir($dir,0755,true);
        $ext=g7_upload_ext_or_die($f['name'], array_merge(G7_EXT_IMG, ['svg']));
        $name='logo_'.time().'.'.$ext;
        move_uploaded_file($f['tmp_name'],$dir.$name);
        $url='/toolz/assets/'.$name;
        // Save to config
        $db->prepare("INSERT INTO g7_config (cfg_key,cfg_value) VALUES ('logo_url',?) ON DUPLICATE KEY UPDATE cfg_value=?")->execute([$url,$url]);
        echo json_encode(['ok'=>true,'url'=>$url]);exit;
    }
    if ($act === 'save_config') {
        foreach(($in['config']??[]) as $k => $v) {
            $k = preg_replace('/[^a-z0-9_]/','',$k);
            if (!$k) continue;
            if (!$admIsSuper && !in_array($k, $ADM_LEGACY_KEYS, true)) { http_response_code(403); echo json_encode(['error' => "Solo el superadmin puede cambiar \"$k\"."]); exit; }
            if ($k === 'logo_url' && $v !== '' && !preg_match('#^(https?://|/(?!/))[^"\'<>\s]*$#i', (string)$v)) { echo json_encode(['error' => 'El logo tiene que ser un enlace http(s) o una ruta del sitio.']); exit; }
            $db->prepare("INSERT INTO g7_config (cfg_key,cfg_value) VALUES (?,?) ON DUPLICATE KEY UPDATE cfg_value=?")
               ->execute([$k,$v,$v]);
        }
        g7_audit('legacy.save_config', implode(',', array_keys($in['config'] ?? [])), null);
        echo json_encode(['ok'=>true]); exit;
    }

    // ── INTELIGENCIA ARTIFICIAL (Claude y Leonardo) ─────────
    // Las claves quedan en _private/ia.php; a la página solo vuelve el final de cada una.
    // Bancos de imágenes gratis (Pexels, Pixabay, Unsplash): claves opcionales para Placas › Recursos › Buscar gratis
    if ($act === 'save_stock' || $act === 'test_stock') {
        require_once __DIR__ . '/ia_lib.php';
        $cfg = g7_ia_cfg(); $svcs = ['pexels', 'pixabay', 'unsplash'];
        if ($act === 'save_stock') {
            foreach ($svcs as $s) {
                if (!isset($in[$s])) continue;
                $k = preg_replace('/\s+/', '', (string)($in[$s]['key'] ?? ''));
                if (!empty($in[$s]['clear'])) $cfg['stock'][$s] = '';
                elseif ($k !== '') { if (strlen($k) > 200 || !preg_match('/^[A-Za-z0-9_\-]+$/', $k)) { echo json_encode(['error' => 'La clave de ' . ucfirst($s) . ' no parece válida.']); exit; } $cfg['stock'][$s] = $k; }
            }
            if (!g7_ia_save($cfg)) { echo json_encode(['error' => 'No se pudo guardar la configuración en el servidor.']); exit; }
            echo json_encode(['ok' => true, 'tails' => array_map(fn($s) => $cfg['stock'][$s] ? substr($cfg['stock'][$s], -4) : '', array_combine($svcs, $svcs))]); exit;
        }
        $s = (string)($in['svc'] ?? ''); $k = $cfg['stock'][$s] ?? '';
        if (!in_array($s, $svcs, true) || !$k) { echo json_encode(['error' => 'Primero guarda la clave.']); exit; }
        $u = ['pexels' => 'https://api.pexels.com/v1/search?query=football&per_page=1', 'pixabay' => 'https://pixabay.com/api/?key=' . rawurlencode($k) . '&q=football&per_page=3', 'unsplash' => 'https://api.unsplash.com/search/photos?query=football&per_page=1'][$s];
        $h = ['pexels' => ['Authorization: ' . $k], 'pixabay' => [], 'unsplash' => ['Authorization: Client-ID ' . $k]][$s];
        $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $h]); $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        echo json_encode($code === 200 ? ['ok' => true, 'msg' => 'Conectado con ' . ucfirst($s) . '.'] : ['error' => ucfirst($s) . ' rechazó la clave (código ' . $code . ').']); exit;
    }
    if ($act === 'save_ia' || $act === 'test_claude' || $act === 'test_leonardo') {
        require_once __DIR__ . '/ia_lib.php';
        if ($act === 'save_ia') {
            $cfg = g7_ia_cfg();
            foreach (['claude', 'leonardo'] as $svc) {
                if (!isset($in[$svc])) continue;   // cada tarjeta guarda solo lo suyo
                $k = trim((string)($in[$svc]['key'] ?? ''));
                if (!empty($in[$svc]['clear'])) $cfg[$svc]['key'] = '';
                elseif ($k !== '') {
                    if ($svc === 'claude') {
                        // Solo se frenan los tipos que seguro no sirven; el resto lo juzga "Probar conexión" con la respuesta real de Anthropic
                        $pre = preg_match('/^(sk-ant-[a-z]+\d*-?)/', $k, $mm) ? $mm[1] : mb_substr($k, 0, 8) . '…';
                        $why = preg_match('/^sk-ant-(oat|ort|sid|admin)/', $k) ? 'es un token de inicio de sesión o de administración (de claude.ai, Claude Code o la consola), no una clave para usar la API'
                             : (!str_starts_with($k, 'sk-ant-') ? 'no es una clave de Anthropic' : (strlen($k) < 40 ? 'parece incompleta (¿se copió entera?)' : ''));
                        if ($why) { echo json_encode(['error' => "La clave que pegaste empieza con «{$pre}» y {$why}. En console.anthropic.com › Settings › API Keys toca «Create Key» y copia la clave nueva (empieza con sk-ant-api03- o sk-ant-usr-; se muestra una sola vez)."]); exit; }
                    }
                    if (strlen($k) > 300 || preg_match('/\s/', $k)) { echo json_encode(['error' => 'La clave de ' . ucfirst($svc) . ' no parece válida.']); exit; }
                    $cfg[$svc]['key'] = $k;
                }
                $m = (string)($in[$svc]['model'] ?? '');
                if ($svc === 'claude' && isset(G7_CLAUDE_MODELS[$m])) $cfg[$svc]['model'] = $m;
                if ($svc === 'leonardo' && isset(G7_LEO_MODELS[$m])) $cfg[$svc]['model'] = $m;
            }
            if (!g7_ia_save($cfg)) { echo json_encode(['error' => 'No se pudo guardar la configuración en el servidor.']); exit; }
            g7_audit('ia.save', 'claude:' . ($cfg['claude']['key'] ? 'on' : 'off') . ' leonardo:' . ($cfg['leonardo']['key'] ? 'on' : 'off'), null);
            echo json_encode(['ok' => true, 'claude' => $cfg['claude']['key'] ? substr($cfg['claude']['key'], -6) : '', 'leonardo' => $cfg['leonardo']['key'] ? substr($cfg['leonardo']['key'], -6) : '']); exit;
        }
        try { echo json_encode(['ok' => true, 'msg' => $act === 'test_claude' ? g7_claude_test() : g7_leo_test()]); }
        catch (Throwable $e) { echo json_encode(['error' => $e->getMessage()]); }
        exit;
    }

    // ── JIRA (solo lectura) ─────────────────────────────────
    // El token queda en _private/jira.php; a la página solo vuelve el final.
    if ($act === 'save_jira' || $act === 'test_jira') {
        require_once __DIR__ . '/jira_lib.php';
        if ($act === 'test_jira') {
            try { echo json_encode(g7_jira_test()); }
            catch (Throwable $e) { echo json_encode(['error' => 'No se pudo completar la prueba.']); }
            exit;
        }
        $cfg = g7_jira_cfg();
        $site = g7_jira_site_clean((string)($in['site'] ?? ''));
        if ($site === '') { echo json_encode(['error' => 'La dirección del sitio tiene que ser de Jira Cloud, por ejemplo grupo-latino.atlassian.net.']); exit; }
        $email = trim((string)($in['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['error' => 'El email no parece válido.']); exit; }
        $k = preg_replace('/\s+/', '', (string)($in['token'] ?? ''));
        if (!empty($in['clear'])) $cfg['token'] = '';
        elseif ($k !== '') { if (strlen($k) > 1024) { echo json_encode(['error' => 'El token no parece válido.']); exit; } $cfg['token'] = $k; }
        $cfg['site'] = $site; $cfg['email'] = $email;
        if (!g7_jira_save($cfg)) { echo json_encode(['error' => 'No se pudo guardar la configuración en el servidor.']); exit; }
        g7_audit('jira.save', $site . ' token:' . ($cfg['token'] ? 'on' : 'off'), null);
        echo json_encode(['ok' => true, 'site' => $site, 'tail' => $cfg['token'] ? substr($cfg['token'], -6) : '']); exit;
    }

    // Jira dentro de Cronos (jira_use_lib.php): qué partes lo usan y con qué proyectos
    if ($act === 'jira_projects' || $act === 'save_jira_features') {
        require_once __DIR__ . '/jira_use_lib.php';
        if ($act === 'jira_projects') {
            try { $p = g7_jira_projects(100); echo json_encode(['projects' => array_map(fn($x) => ['key' => (string)($x['key'] ?? ''), 'name' => (string)($x['name'] ?? '')], (array)($p['values'] ?? []))]); }
            catch (G7JiraError $e) { echo json_encode(['error' => $e->getMessage()]); }
            exit;
        }
        $f = g7_jira_features_save($in);
        g7_audit('jira.features', json_encode($f), null);
        echo json_encode(['ok' => true, 'features' => $f, 'ready' => g7_jira_ready()]); exit;
    }

    // ── MONDAY CONFIG ──────────────────────────────────────
    if ($act === 'save_monday') {
        // La configuración vive en _private/monday.php (carpeta bloqueada para la web), no en un archivo público.
        $cfgFile = __DIR__ . '/_private/monday.php';
        $old = is_file($cfgFile) ? (string)file_get_contents($cfgFile) : '';
        $prev = fn($k) => preg_match("/define\('$k',\s*'([^']*)'\)/", $old, $mm) ? $mm[1] : '';
        $token   = trim($in['token']??'');
        if ($token === '') $token = $prev('MONDAY_TOKEN');           // vacío = no cambiar el token guardado
        $boardId = preg_replace('/\D/', '', (string)($in['board_id']??''));
        $userId  = preg_replace('/\D/', '', (string)($in['user_id']??''));
        $content = "<?php\n// Configuración de Monday. La escribe Admin › Monday API; la lee monday_widget.php.\n"
                 . "if (!defined('MONDAY_TOKEN'))   define('MONDAY_TOKEN', " . var_export($token, true) . ");\n"
                 . "if (!defined('MONDAY_BOARD'))   define('MONDAY_BOARD', " . var_export($boardId, true) . ");\n"
                 . "if (!defined('MONDAY_USER_ID')) define('MONDAY_USER_ID', " . var_export($userId, true) . ");\n";
        file_put_contents($cfgFile, $content);
        // Clear cache
        if (file_exists(__DIR__.'/_private/monday_cache.json')) unlink(__DIR__.'/_private/monday_cache.json');
        g7_audit('legacy.save_monday', 'board:' . $boardId, null);
        echo json_encode(['ok'=>true]); exit;
    }

    // ── ACTIVITY LOGS ──────────────────────────────────────
    if ($act === 'get_logs') {
        $limit = min(200, (int)($in['limit']??50));
        $type  = $in['type'] ?? '';
        $sql = "SELECT a.*,u.username,u.display_name FROM g7_activity a LEFT JOIN g7_users u ON u.id=a.user_id";
        $sql .= $type ? " WHERE a.type=?" : '';
        $sql .= " ORDER BY a.created_at DESC LIMIT $limit";
        $q = $db->prepare($sql);
        $q->execute($type ? [$type] : []);
        echo json_encode(['logs'=>$q->fetchAll()]); exit;
    }

    // ── CONTENT STATS ──────────────────────────────────────
    if ($act === 'get_content_stats') {
        $stats = [];
        try { $stats['promos']     = $db->query("SELECT COUNT(*) FROM g7_content")->fetchColumn(); } catch(Exception $e) { $stats['promos']=0; }
        try { $stats['links']      = $db->query("SELECT COUNT(*) FROM g7_links")->fetchColumn(); } catch(Exception $e) { $stats['links']=0; }
        try { $stats['categories'] = $db->query("SELECT COUNT(*) FROM g7_categories")->fetchColumn(); } catch(Exception $e) { $stats['categories']=0; }
        try { $stats['pages']      = $db->query("SELECT COUNT(*) FROM g7_pages")->fetchColumn(); } catch(Exception $e) { $stats['pages']=0; }
        try { $stats['chat_msgs']  = $db->query("SELECT COUNT(*) FROM g7_chat_messages")->fetchColumn(); } catch(Exception $e) { $stats['chat_msgs']=0; }
        try { $stats['chat_groups']= $db->query("SELECT COUNT(*) FROM g7_chat_groups")->fetchColumn(); } catch(Exception $e) { $stats['chat_groups']=0; }
        echo json_encode(['stats'=>$stats]); exit;
    }

    echo json_encode(['error'=>'unknown']); exit;
}

// ── LOAD MONDAY CONFIG ────────────────────────────────────
$mondayCfg = ['token'=>'', 'board_id'=>'', 'user_id'=>''];
try {
    $mwContent = is_file(__DIR__.'/_private/monday.php') ? file_get_contents(__DIR__.'/_private/monday.php') : '';
    preg_match("/define\('MONDAY_TOKEN',\s*'([^']*)'\)/", $mwContent, $m);
    $mondayCfg['token'] = $m[1] ?? '';   // no se imprime en la página: solo se muestra el final
    preg_match("/define\('MONDAY_BOARD',\s*'([^']*)'\)/", $mwContent, $m);
    $mondayCfg['board_id'] = $m[1] ?? '';
    preg_match("/define\('MONDAY_USER_ID',\s*'([^']*)'\)/", $mwContent, $m);
    $mondayCfg['user_id'] = $m[1] ?? '';
} catch(Exception $e) {}

// ── IA: estado (sin mostrar las claves) ──────────────────
require_once __DIR__ . '/ia_lib.php';
$iaCfg = g7_ia_cfg();
$iaTail = fn($k) => $k ? substr($k, -6) : '';
require_once __DIR__ . '/jira_lib.php';
$jiraCfg = g7_jira_cfg();

// ── QUICK STATS ───────────────────────────────────────────
try { $totalUsers = $db->query("SELECT COUNT(*) FROM g7_users")->fetchColumn(); } catch(Exception $e) { $totalUsers=0; }
try { $activeUsers = $db->query("SELECT COUNT(*) FROM g7_users WHERE active=1")->fetchColumn(); } catch(Exception $e) { $activeUsers=0; }
try { $totalContent = $db->query("SELECT COUNT(*) FROM g7_content")->fetchColumn(); } catch(Exception $e) { $totalContent=0; }
try { $totalMsgs = $db->query("SELECT COUNT(*) FROM g7_chat_messages")->fetchColumn(); } catch(Exception $e) { $totalMsgs=0; }
?>
<!DOCTYPE html>
<html lang="es" class="theme-<?= htmlspecialchars($_ap_theme) ?> font-<?= htmlspecialchars($_ap_font) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Admin — <?= htmlspecialchars(g7_site('short')) ?></title>
<?php
$_ap_accent = $_ap_prefs['accent_color'] ?? '#ff6600';
?>
<script src="/toolz/assets/theme-boot.js?v=2" data-accent="<?= htmlspecialchars((string)$_ap_accent, ENT_QUOTES) ?>"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<link rel="stylesheet" href="/toolz/g7-theme.css?v=4">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--g7-bg);color:var(--g7-text);font-family:var(--g7-font);min-height:100vh}

.adm-layout{display:grid;grid-template-columns:220px 1fr;min-height:calc(100vh - 52px)}

/* SIDEBAR */
.adm-nav{background:var(--g7-bg-2);border-right:1px solid var(--g7-border);padding:16px 10px;position:sticky;top:52px;height:calc(100vh - 52px);overflow-y:auto;display:flex;flex-direction:column}
.adm-nav-sep{font-size:9px;font-weight:700;color:var(--g7-text-5);text-transform:uppercase;letter-spacing:.08em;padding:10px 12px 4px;margin-top:4px}
.adm-nav-item{display:flex;align-items:center;gap:9px;padding:8px 12px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:600;color:var(--g7-text-3);transition:all .15s;border:none;background:none;width:100%;text-align:left}
.adm-nav-item:hover{background:var(--g7-bg-4);color:var(--g7-text)}
.adm-nav-item.active{background:var(--g7-accent-muted);color:var(--g7-accent);border:1px solid var(--g7-accent-border)}
.adm-nav-item i{width:16px;text-align:center;font-size:12px}

/* CONTENT */
.adm-content{padding:28px 32px;min-width:0}
.adm-section{display:none}.adm-section.active{display:block}
.adm-title{font-size:20px;font-weight:800;margin-bottom:4px}
.adm-sub{font-size:12px;color:var(--g7-text-4);margin-bottom:24px}

/* STATS ROW */
.adm-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
.adm-stat{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:12px;padding:16px;text-align:center}
.adm-stat-val{font-size:28px;font-weight:800;color:var(--g7-accent)}
.adm-stat-label{font-size:10px;color:var(--g7-text-4);margin-top:3px}

/* TABLE */
.adm-table-wrap{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:12px;overflow:hidden;margin-bottom:20px}
.adm-table{width:100%;border-collapse:collapse}
.adm-table th{padding:10px 14px;font-size:9px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.08em;background:var(--g7-bg-2);border-bottom:1px solid var(--g7-border);text-align:left}
.adm-table td{padding:11px 14px;font-size:12px;border-bottom:1px solid var(--g7-border);vertical-align:middle}
.adm-table tr:last-child td{border-bottom:none}
.adm-table tr:hover td{background:var(--g7-bg-4)}

/* BADGE */
.badge{display:inline-flex;padding:2px 8px;border-radius:20px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.badge-admin{background:var(--g7-accent-muted);color:var(--g7-accent);border:1px solid var(--g7-accent-border)}
.badge-member{background:var(--g7-bg-5);color:var(--g7-text-4)}
.badge-active{background:rgba(34,197,94,.1);color:#22c55e}
.badge-inactive{background:rgba(239,68,68,.1);color:#ef4444}

/* BUTTONS */
.btn{border:none;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:all .15s;white-space:nowrap}
.btn-primary{background:var(--g7-accent);color:#000}.btn-primary:hover{opacity:.85}
.btn-ghost{background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-3)}.btn-ghost:hover{color:var(--g7-text)}
.btn-danger{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#ef4444}.btn-danger:hover{background:rgba(239,68,68,.2)}
.btn-sm{padding:4px 10px;font-size:11px}
.btn-icon{padding:5px 8px;background:var(--g7-bg-5);border:1px solid var(--g7-border-3);border-radius:7px;cursor:pointer;color:var(--g7-text-3);transition:all .15s}.btn-icon:hover{color:var(--g7-text)}

/* FORM */
.adm-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}
.adm-label{font-size:9px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:4px}
.adm-input{width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:7px 10px;font-size:12px;outline:none;transition:border-color .15s;font-family:var(--g7-font)}
.adm-input:focus{border-color:var(--g7-accent)}
.adm-select{width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:7px 10px;font-size:12px;outline:none;cursor:pointer}
.adm-select:focus{border-color:var(--g7-accent)}

/* CARD */
.adm-card{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:12px;overflow:hidden;margin-bottom:16px}
.adm-card-head{padding:13px 18px;border-bottom:1px solid var(--g7-border);display:flex;align-items:center;justify-content:space-between}
.adm-card-title{font-size:12px;font-weight:700;display:flex;align-items:center;gap:7px}
.adm-card-title i{color:var(--g7-accent)}
.adm-card-body{padding:16px 18px}

/* LOG */
.log-item{display:flex;gap:10px;padding:9px 0;border-bottom:1px solid var(--g7-border);align-items:flex-start;font-size:12px}
.log-item:last-child{border:none}
.log-type{flex-shrink:0;width:80px;font-size:9px;font-weight:700;padding:2px 6px;border-radius:4px;background:var(--g7-bg-5);color:var(--g7-text-4);text-align:center;text-transform:uppercase}
.log-user{font-weight:700;color:var(--g7-text);flex-shrink:0}
.log-msg{color:var(--g7-text-2);flex:1;min-width:0}
.log-time{color:var(--g7-text-5);font-size:10px;flex-shrink:0}

/* TOGGLE */
.adm-toggle{position:relative;width:40px;height:22px;cursor:pointer;flex-shrink:0}
.adm-toggle input{opacity:0;width:0;height:0;position:absolute}
.adm-toggle-track{position:absolute;inset:0;background:var(--g7-border-3);border-radius:11px;transition:background .2s}
.adm-toggle input:checked + .adm-toggle-track{background:var(--g7-accent)}
.adm-toggle-thumb{position:absolute;top:3px;left:3px;width:16px;height:16px;background:#fff;border-radius:50%;transition:transform .2s;pointer-events:none}
.adm-toggle input:checked ~ .adm-toggle-thumb{transform:translateX(18px)}

/* MODAL */
.adm-modal{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9000;display:none;align-items:center;justify-content:center;padding:20px}
.adm-modal.open{display:flex}
.adm-modal-box{background:var(--g7-bg-3);border:1px solid var(--g7-border-3);border-radius:16px;width:100%;max-width:460px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden}
.adm-modal-head{padding:14px 18px;border-bottom:1px solid var(--g7-border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.adm-modal-title{font-size:14px;font-weight:700}
.adm-modal-close{background:none;border:none;color:var(--g7-text-4);cursor:pointer;font-size:17px;padding:2px}
.adm-modal-body{flex:1;overflow-y:auto;padding:16px 18px}
.adm-modal-foot{padding:12px 18px;border-top:1px solid var(--g7-border);display:flex;gap:8px;flex-shrink:0}

/* ALIGN BUTTONS */
.adm-align-btn{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px;background:var(--g7-bg-4);border:2px solid var(--g7-border-3);border-radius:10px;color:var(--g7-text-3);transition:all .15s;font-size:12px;font-weight:600;gap:4px;cursor:pointer}
.adm-align-btn:hover{border-color:var(--g7-accent);color:var(--g7-accent)}
.adm-align-btn.active{border-color:var(--g7-accent);color:var(--g7-accent);background:var(--g7-accent-muted)}

/* NAV ITEM ROW */
.nav-item-row{display:flex;align-items:center;gap:8px;padding:8px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:8px;margin-bottom:6px}
.nav-item-row:hover{border-color:var(--g7-border-2)}
.nav-drag-handle{color:var(--g7-text-5);cursor:grab;padding:0 4px;font-size:13px}
.nav-drag-handle:active{cursor:grabbing}

/* SOUND TYPE BUTTONS */
.adm-sound-btn{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:14px;background:var(--g7-bg-4);border:2px solid var(--g7-border-3);border-radius:10px;color:var(--g7-text-3);transition:all .15s;font-size:12px;font-weight:600;gap:3px;cursor:pointer;width:100%}
.adm-sound-btn:hover{border-color:var(--g7-accent);color:var(--g7-accent)}
.adm-sound-btn.active{border-color:var(--g7-accent);color:var(--g7-accent);background:var(--g7-accent-muted)}

/* TOAST */
#adm-toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%) translateY(60px);background:var(--g7-success);color:#000;padding:8px 18px;border-radius:8px;font-size:12px;font-weight:700;transition:transform .3s;z-index:9999;pointer-events:none}
</style>
<script src="/toolz/assets/g7-imgprep.js?v=1"></script>
<style>[data-hov]:hover{border-color:var(--g7-accent)!important}[data-hov2]:hover{border-color:var(--g7-accent)!important;color:var(--g7-accent)!important}</style>
</head>
<body>
<?php include __DIR__ . '/topbar.php'; ?>

<div class="adm-layout">
  <!-- SIDEBAR -->
  <nav class="adm-nav">
    <div style="padding:8px 12px 12px;border-bottom:1px solid var(--g7-border);margin-bottom:8px">
      <div style="font-size:13px;font-weight:800;color:var(--g7-accent)"><i class="fa-solid fa-shield-halved"></i> Panel Admin</div>
      <div style="font-size:10px;color:var(--g7-text-5);margin-top:2px"><?= htmlspecialchars(g7_site('name')) ?></div>
    </div>
    <div class="adm-nav-sep">General</div>
    <button class="adm-nav-item active" data-sec="overview" data-on-click="admShow" data-click-1="s:overview" data-click-2="this"><i class="fa-solid fa-gauge"></i> Resumen</button>
    <div class="adm-nav-sep">Personas y accesos</div>
    <button class="adm-nav-item" data-sec="users" data-on-click="admShow" data-click-1="s:users" data-click-2="this"><i class="fa-solid fa-users"></i> Usuarios</button>
    <button class="adm-nav-item" data-sec="teams" data-on-click="admShow" data-click-1="s:teams" data-click-2="this"><i class="fa-solid fa-people-group"></i> Equipos</button>
    <a class="adm-nav-item" href="/toolz/onboarding_admin.php"><i class="fa-solid fa-user-check"></i> Bienvenida (onboarding)</a>
<?php if (g7_site_has('clientes')): ?>
    <a class="adm-nav-item" href="/toolz/clientes.php" style="text-decoration:none"><i class="fa-solid fa-address-book"></i> <?= htmlspecialchars(g7_term('client', 'p')) ?> <i class="fa-solid fa-arrow-up-right-from-square" style="margin-left:auto;font-size:9px"></i></a>
<?php endif; ?>
    <button class="adm-nav-item" data-sec="brands" data-on-click="admShow" data-click-1="s:brands" data-click-2="this"><i class="fa-solid fa-tags"></i> Marcas</button>
    <button class="adm-nav-item" data-sec="modules" data-on-click="admShow" data-click-1="s:modules" data-click-2="this"><i class="fa-solid fa-key"></i> Módulos y permisos</button>
    <button class="adm-nav-item" data-sec="matrix" data-on-click="admShow" data-click-1="s:matrix" data-click-2="this"><i class="fa-solid fa-table-cells"></i> Matriz de acceso</button>
    <div class="adm-nav-sep">Plataforma</div>
    <button class="adm-nav-item" data-sec="config" data-on-click="admShow" data-click-1="s:config" data-click-2="this"><i class="fa-solid fa-sliders"></i> Configuración</button>
    <button class="adm-nav-item" data-sec="home" data-on-click="admShow" data-click-1="s:home" data-click-2="this"><i class="fa-solid fa-house"></i> Inicio y accesos</button>
    <button class="adm-nav-item" data-sec="rubro" data-on-click="admShow" data-click-1="s:rubro" data-click-2="this"><i class="fa-solid fa-briefcase"></i> Rubro</button>
    <?php if (g7_site_has('clientes') && is_file(__DIR__ . '/admin_portal.php')): ?><button class="adm-nav-item" data-sec="portal" data-on-click="admShow" data-click-1="s:portal" data-click-2="this"><i class="fa-solid fa-door-open"></i> Portal de clientes</button><?php endif; ?>
    <button class="adm-nav-item" data-sec="integraciones" data-on-click="admShow" data-click-1="s:integraciones" data-click-2="this"><i class="fa-solid fa-plug"></i> Integraciones</button>
<?php if (g7_site_has('clientes')): ?>
    <button class="adm-nav-item" data-sec="campos" data-on-click="admShow" data-click-1="s:campos" data-click-2="this"><i class="fa-solid fa-list-check"></i> Campos de la ficha</button>
<?php endif; ?>
    <button class="adm-nav-item" data-sec="estados" data-on-click="admShow" data-click-1="s:estados" data-click-2="this"><i class="fa-solid fa-circle-half-stroke"></i> Estados</button>
    <button class="adm-nav-item" data-sec="topbar" data-on-click="admShow" data-click-1="s:topbar" data-click-2="this"><i class="fa-solid fa-bars"></i> Topbar & Logo</button>
    <button class="adm-nav-item" data-sec="notif" data-on-click="admShow" data-click-1="s:notif" data-click-2="this"><i class="fa-solid fa-bell"></i> Notificaciones</button>
    <button class="adm-nav-item" data-sec="monday" data-on-click="admShow" data-click-1="s:monday" data-click-2="this"><i class="fa-solid fa-check-square"></i> Monday API</button>
<?php if (g7_super_ok((int)$currentUser['id'])): ?>
    <button class="adm-nav-item" data-sec="ia" data-on-click="admShow" data-click-1="s:ia" data-click-2="this"><i class="fa-solid fa-wand-magic-sparkles"></i> Inteligencia artificial</button>
    <button class="adm-nav-item" data-sec="jira" data-on-click="admShow" data-click-1="s:jira" data-click-2="this"><i class="fa-brands fa-jira"></i> Jira API</button>
    <button class="adm-nav-item" data-sec="updates" data-on-click="admShow" data-click-1="s:updates" data-click-2="this"><i class="fa-solid fa-cloud-arrow-down"></i> Actualizaciones</button>
<?php endif; ?>
<?php if (lic_active()): ?>
    <button class="adm-nav-item" data-sec="license" data-on-click="admShow" data-click-1="s:license" data-click-2="this"><i class="fa-solid fa-id-card"></i> Licencia</button>
<?php endif; ?>
    <button class="adm-nav-item" data-sec="plugins" data-on-click="admShow" data-click-1="s:plugins" data-click-2="this"><i class="fa-solid fa-puzzle-piece"></i> Plugins</button>
<?php if (g7_site('base_url') && strlen((string)g7_site('base_sso_secret')) >= 32 && g7_is_super((int)$currentUser['id'])): /* sitio base, con la interfaz de Toolz: admin_base.php */ ?>
    <div class="adm-nav-sep">Red Cronos</div>
    <button class="adm-nav-item" data-sec="base-sitios" data-on-click="admShow" data-click-1="s:base-sitios" data-click-2="this"><i class="fa-solid fa-server"></i> Sitios y versiones</button>
    <button class="adm-nav-item" data-sec="base-licencias" data-on-click="admShow" data-click-1="s:base-licencias" data-click-2="this"><i class="fa-solid fa-id-card-clip"></i> Licencias</button>
    <button class="adm-nav-item" data-sec="base-planes" data-on-click="admShow" data-click-1="s:base-planes" data-click-2="this"><i class="fa-solid fa-layer-group"></i> Planes</button>
    <button class="adm-nav-item" data-sec="base-banners" data-on-click="admShow" data-click-1="s:base-banners" data-click-2="this"><i class="fa-solid fa-bullhorn"></i> Banners</button>
    <button class="adm-nav-item" data-sec="base-tickets" data-on-click="admShow" data-click-1="s:base-tickets" data-click-2="this"><i class="fa-solid fa-life-ring"></i> Tickets <span class="bz-badge" id="bz-tk-badge" style="display:none"></span></button>
    <button class="adm-nav-item" data-sec="base-red" data-on-click="admShow" data-click-1="s:base-red" data-click-2="this"><i class="fa-solid fa-chart-line"></i> Estadísticas de la red</button>
<?php endif; ?>
    <div class="adm-nav-sep">Datos y registros</div>
    <button class="adm-nav-item" data-sec="content" data-on-click="admShow" data-click-1="s:content" data-click-2="this"><i class="fa-solid fa-layer-group"></i> Contenido</button>
<?php if (g7_super_ok((int)$currentUser['id'])): ?>
    <button class="adm-nav-item" data-sec="audit" data-on-click="admShow" data-click-1="s:audit" data-click-2="this"><i class="fa-solid fa-clipboard-check"></i> Auditoría</button>
<?php endif; ?>
    <button class="adm-nav-item" data-sec="logs" data-on-click="admShow" data-click-1="s:logs" data-click-2="this"><i class="fa-solid fa-scroll"></i> Logs de actividad</button>
<?php if (g7_super_ok((int)$currentUser['id'])): ?>
    <button class="adm-nav-item" data-sec="backup" data-on-click="admShow" data-click-1="s:backup" data-click-2="this"><i class="fa-solid fa-box-archive"></i> Respaldo</button>
<?php endif; ?>
    <div style="margin-top:auto;padding-top:16px;border-top:1px solid var(--g7-border);margin-top:auto">
      <a href="/toolz/" class="adm-nav-item" style="text-decoration:none"><i class="fa-solid fa-arrow-left"></i> Volver al dashboard</a>
<?php $_ver = json_decode((string)@file_get_contents(__DIR__ . '/_private/updates/state.json'), true)['version'] ?? ''; ?>
      <div style="padding:6px 12px 0;font-size:10px;color:var(--g7-text-4)" title="Versión instalada por el actualizador"><?= $_ver !== '' ? 'Versión ' . htmlspecialchars($_ver) : 'Sin versión instalada' ?></div>
    </div>
  </nav>

  <!-- CONTENT -->
  <div class="adm-content">
    <?php if (is_file(__DIR__ . '/red_lib.php')) { require_once __DIR__ . '/red_lib.php'; echo red_banners_html('admin'); }   // banners del Cronos principal ?>

    <!-- ── OVERVIEW ── -->
    <section class="adm-section active" id="sec-overview">
      <div class="adm-title">Resumen del sistema</div>
      <div class="adm-sub">Estado general de <?= htmlspecialchars(g7_site('name')) ?></div>
      <div class="adm-stats">
        <div class="adm-stat"><div class="adm-stat-val"><?= $totalUsers ?></div><div class="adm-stat-label">Usuarios totales</div></div>
        <div class="adm-stat"><div class="adm-stat-val"><?= $activeUsers ?></div><div class="adm-stat-label">Usuarios activos</div></div>
        <div class="adm-stat"><div class="adm-stat-val"><?= $totalContent ?></div><div class="adm-stat-label">Ítems de contenido</div></div>
        <div class="adm-stat"><div class="adm-stat-val"><?= number_format($totalMsgs) ?></div><div class="adm-stat-label">Mensajes de chat</div></div>
      </div>
      <div id="content-stats-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px"></div>
      <div id="acc-overview" style="margin-top:16px"></div>
    </section>

    <?php include __DIR__ . '/admin_ui.php'; ?>
    <?php include __DIR__ . '/admin_home.php'; ?>
    <?php include __DIR__ . '/admin_rubro.php'; ?>
    <?php if (is_file(__DIR__ . '/admin_portal.php')) include __DIR__ . '/admin_portal.php'; ?>
    <?php include __DIR__ . '/admin_integraciones.php'; ?>
    <?php if (g7_super_ok((int)$currentUser['id'])) include __DIR__ . '/admin_updates.php'; ?>
    <?php include __DIR__ . '/admin_license.php'; ?>
    <?php include __DIR__ . '/admin_base.php'; ?>

    <!-- ── NOTIFICACIONES ── -->
    <section class="adm-section" id="sec-notif">
      <div class="adm-title">Notificaciones del sistema</div>
      <div class="adm-sub">Configura globalmente el comportamiento de sonidos y zumbidos para toda la plataforma</div>

      <!-- ZUMBIDO -->
      <div class="adm-card" style="margin-bottom:16px">
        <div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-bell"></i> Botón de Zumbido</div></div>
        <div class="adm-card-body" style="display:flex;flex-direction:column;gap:16px">
          <div style="display:flex;align-items:center;justify-content:space-between">
            <div>
              <div style="font-size:13px;font-weight:600">Mostrar botón de Zumbido en la topbar</div>
              <div style="font-size:11px;color:var(--g7-text-4)">Si está desactivado, el botón verde no aparece para ningún usuario</div>
            </div>
            <label class="adm-toggle">
              <input type="checkbox" id="n-buzz-enabled" checked data-on-change="admSaveNotif">
              <div class="adm-toggle-track"></div><div class="adm-toggle-thumb"></div>
            </label>
          </div>
          <div style="display:flex;align-items:center;justify-content:space-between">
            <div>
              <div style="font-size:13px;font-weight:600">Beeps por defecto al enviar zumbido</div>
              <div style="font-size:11px;color:var(--g7-text-4)">Cantidad de pitidos que recibe el destinatario</div>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
              <input type="range" id="n-beeps" min="1" max="10" value="3" style="-webkit-appearance:none;height:4px;border-radius:2px;background:var(--g7-border-3);outline:none;cursor:pointer;width:120px" data-on-input="admBeepsInput" data-input-1="this">
              <span id="n-beeps-val" style="font-size:14px;font-weight:800;color:var(--g7-accent);min-width:20px">3</span>
            </div>
          </div>
        </div>
      </div>

      <!-- SONIDO DE NOTIFICACIONES -->
      <div class="adm-card" style="margin-bottom:16px">
        <div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-volume-high"></i> Sonido de mensajes</div></div>
        <div class="adm-card-body" style="display:flex;flex-direction:column;gap:16px">
          <div style="display:flex;align-items:center;justify-content:space-between">
            <div>
              <div style="font-size:13px;font-weight:600">Activar sonido de notificaciones</div>
              <div style="font-size:11px;color:var(--g7-text-4)">El bip que suena al recibir un mensaje nuevo</div>
            </div>
            <label class="adm-toggle">
              <input type="checkbox" id="n-sound-enabled" checked data-on-change="admSaveNotif">
              <div class="adm-toggle-track"></div><div class="adm-toggle-thumb"></div>
            </label>
          </div>
          <div>
            <div style="font-size:13px;font-weight:600;margin-bottom:10px">Tipo de sonido</div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px" id="sound-type-selector">
              <button class="adm-sound-btn active" data-type="beep" data-on-click="admSetSoundType" data-click-1="s:beep" data-click-2="this">
                <i class="fa-solid fa-wave-square" style="font-size:20px;margin-bottom:5px"></i>
                <span>Beep</span>
                <span style="font-size:9px;opacity:.6">Electrónico</span>
              </button>
              <button class="adm-sound-btn" data-type="soft" data-on-click="admSetSoundType" data-click-1="s:soft" data-click-2="this">
                <i class="fa-solid fa-bell" style="font-size:20px;margin-bottom:5px"></i>
                <span>Suave</span>
                <span style="font-size:9px;opacity:.6">Campana</span>
              </button>
              <button class="adm-sound-btn" data-type="pop" data-on-click="admSetSoundType" data-click-1="s:pop" data-click-2="this">
                <i class="fa-solid fa-circle-dot" style="font-size:20px;margin-bottom:5px"></i>
                <span>Pop</span>
                <span style="font-size:9px;opacity:.6">Burbuja</span>
              </button>
            </div>
            <button class="btn btn-ghost btn-sm" style="margin-top:10px" data-on-click="admPreviewSound"><i class="fa-solid fa-play"></i> Previsualizar sonido</button>
          </div>
        </div>
      </div>

      <button class="btn btn-primary" data-on-click="admSaveNotif"><i class="fa-solid fa-floppy-disk"></i> Guardar configuración</button>
    </section>

    <!-- ── MONDAY API ── -->
    <section class="adm-section" id="sec-monday">
      <div class="adm-title">Monday.com API</div>
      <div class="adm-sub">Configuración de la integración con Monday</div>
      <div class="adm-card">
        <div class="adm-card-head">
          <div class="adm-card-title"><i class="fa-solid fa-plug"></i> Credenciales de API</div>
          <a href="https://monday.com/settings/developer" target="_blank" class="btn btn-ghost btn-sm"><i class="fa-solid fa-arrow-up-right-from-square"></i> Dashboard Monday</a>
        </div>
        <div class="adm-card-body">
          <div style="display:flex;flex-direction:column;gap:12px">
            <div>
              <label class="adm-label">API Token</label>
              <div style="display:flex;gap:8px">
                <input class="adm-input" id="mon-token" type="password" value="" autocomplete="off" placeholder="<?= $mondayCfg['token'] ? 'Guardado (termina en …'.htmlspecialchars(substr($mondayCfg['token'],-6)).'). Vacío = no cambiarlo' : 'eyJhbGciOi...' ?>">
                <button class="btn-icon" data-on-click="admToggleMonToken"><i class="fa-solid fa-eye" id="mon-token-eye"></i></button>
              </div>
              <div style="font-size:10px;color:var(--g7-text-5);margin-top:4px">Obtenelo en Monday → Tu perfil → Developers → API v2 Token</div>
            </div>
            <div class="adm-form-grid">
              <div>
                <label class="adm-label">Board ID</label>
                <input class="adm-input" id="mon-board" value="<?= htmlspecialchars($mondayCfg['board_id']) ?>" placeholder="7527739787">
              </div>
              <div>
                <label class="adm-label">User ID (Monday)</label>
                <input class="adm-input" id="mon-user" value="<?= htmlspecialchars($mondayCfg['user_id']) ?>" placeholder="82429110">
              </div>
            </div>
            <div style="display:flex;gap:8px">
              <button class="btn btn-primary" data-on-click="admSaveMonday"><i class="fa-solid fa-floppy-disk"></i> Guardar y aplicar</button>
              <button class="btn btn-ghost" data-on-click="admTestMonday"><i class="fa-solid fa-vial"></i> Probar conexión</button>
            </div>
            <div id="mon-test-result" style="display:none;font-size:12px;padding:10px 14px;border-radius:8px;background:var(--g7-bg-4)"></div>
          </div>
        </div>
      </div>
    </section>

    <!-- ── INTELIGENCIA ARTIFICIAL ── -->
    <section class="adm-section" id="sec-ia">
      <div class="adm-title">Inteligencia artificial</div>
      <div class="adm-sub">Claves de Claude y Leonardo. Se guardan en el servidor (carpeta privada) y nunca llegan al navegador: acá solo se ve el final.</div>
      <?php foreach ([
        'claude' => ['Claude (Anthropic)', 'fa-comment-dots', 'https://console.anthropic.com/settings/keys', 'Escribe las landings de Landing Gen y busca en la web los datos de los partidos en Placas Deportivas. La clave empieza con sk-ant-api03- o sk-ant-usr- y se crea en console.anthropic.com › Settings › API Keys (la cuenta necesita saldo en Billing).', G7_CLAUDE_MODELS],
        'leonardo' => ['Leonardo.Ai', 'fa-image', 'https://app.leonardo.ai/api-access', 'Genera fondos e imágenes en Placas Deportivas. La clave se crea en app.leonardo.ai › API Access (usa créditos de API de Leonardo).', array_map(fn($m) => $m['n'], G7_LEO_MODELS)],
      ] as $svc => [$title, $icon, $link, $help, $models]): $c = $iaCfg[$svc]; ?>
      <div class="adm-card" style="margin-bottom:14px">
        <div class="adm-card-head">
          <div class="adm-card-title"><i class="fa-solid <?= $icon ?>"></i> <?= $title ?>
            <span id="ia-<?= $svc ?>-state" style="margin-left:8px;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;<?= $c['key'] ? 'background:rgba(34,197,94,.12);color:#22c55e' : 'background:var(--g7-bg-4);color:var(--g7-text-4)' ?>"><?= $c['key'] ? 'Configurada' : 'Sin configurar' ?></span></div>
          <a href="<?= $link ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm"><i class="fa-solid fa-arrow-up-right-from-square"></i> Conseguir la clave</a>
        </div>
        <div class="adm-card-body">
          <div style="display:flex;flex-direction:column;gap:12px">
            <div style="font-size:12px;color:var(--g7-text-4);line-height:1.5"><?= htmlspecialchars($help) ?></div>
            <div>
              <label class="adm-label">Clave de API</label>
              <div style="display:flex;gap:8px">
                <input class="adm-input" id="ia-<?= $svc ?>-key" type="password" value="" autocomplete="off" placeholder="<?= $c['key'] ? 'Guardada (termina en …' . htmlspecialchars($iaTail($c['key'])) . '). Vacío = no cambiarla' : 'Pega la clave acá' ?>">
                <button class="btn-icon" data-on-click="admIaEye" data-click-1="s:<?= $svc ?>" title="Mostrar"><i class="fa-solid fa-eye" id="ia-<?= $svc ?>-eye"></i></button>
              </div>
            </div>
            <div>
              <label class="adm-label">Modelo</label>
              <select class="adm-input" id="ia-<?= $svc ?>-model"><?php foreach ($models as $id => $n): ?><option value="<?= htmlspecialchars($id) ?>"<?= $c['model'] === $id ? ' selected' : '' ?>><?= htmlspecialchars($n) ?></option><?php endforeach; ?></select>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button class="btn btn-primary" data-on-click="admIaSave" data-click-1="s:<?= $svc ?>"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
              <button class="btn btn-ghost" data-on-click="admIaTest" data-click-1="s:<?= $svc ?>" data-click-2="this"><i class="fa-solid fa-vial"></i> Probar conexión</button>
              <button class="btn btn-ghost" data-on-click="admIaClear" data-click-1="s:<?= $svc ?>" style="margin-left:auto"><i class="fa-solid fa-trash"></i> Quitar la clave</button>
            </div>
            <div id="ia-<?= $svc ?>-result" style="display:none;font-size:12px;padding:10px 14px;border-radius:8px;background:var(--g7-bg-4);line-height:1.5"></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="adm-card" style="margin-bottom:14px">
        <div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-images"></i> Bancos de imágenes gratis</div></div>
        <div class="adm-card-body"><div style="display:flex;flex-direction:column;gap:12px">
          <div style="font-size:12px;color:var(--g7-text-4);line-height:1.5">Para Placas › Recursos › <b>Buscar gratis</b>. Openverse, Iconify y Wikimedia Commons funcionan sin clave. Estas tres suman millones de fotos profesionales; las claves son gratis.</div>
          <?php foreach (['pexels' => ['Pexels', 'https://www.pexels.com/api/'], 'pixabay' => ['Pixabay', 'https://pixabay.com/api/docs/'], 'unsplash' => ['Unsplash', 'https://unsplash.com/developers']] as $sk => [$sn, $sl]): $sv = $iaCfg['stock'][$sk] ?? ''; ?>
          <div>
            <label class="adm-label"><?= $sn ?> <span id="st-<?= $sk ?>-state" style="margin-left:6px;font-size:11px;color:<?= $sv ? '#22c55e' : 'var(--g7-text-4)' ?>"><?= $sv ? 'Configurada (…' . htmlspecialchars(substr($sv, -4)) . ')' : 'Sin configurar' ?></span> · <a href="<?= $sl ?>" target="_blank" rel="noopener">conseguir la clave</a></label>
            <div style="display:flex;gap:8px"><input class="adm-input" id="st-<?= $sk ?>-key" type="password" autocomplete="off" placeholder="<?= $sv ? 'Guardada. Vacío = no cambiarla' : 'Pega la clave acá' ?>">
              <button class="btn btn-primary btn-sm" data-on-click="admStock" data-click-1="s:<?= $sk ?>" data-click-2="s:save">Guardar</button><button class="btn btn-ghost btn-sm" data-on-click="admStock" data-click-1="s:<?= $sk ?>" data-click-2="s:test">Probar</button><button class="btn btn-ghost btn-sm" data-on-click="admStock" data-click-1="s:<?= $sk ?>" data-click-2="s:clear" title="Quitar la clave"><i class="fa-solid fa-trash"></i></button></div>
          </div>
          <?php endforeach; ?>
          <div id="st-result" style="display:none;font-size:12px;padding:10px 14px;border-radius:8px;line-height:1.5"></div>
        </div></div>
      </div>
      <?php if (PHP_VERSION_ID < 80100 || !is_file(G7_IA_PHAR)): ?>
      <div class="adm-card"><div class="adm-card-body" style="font-size:12px;color:#f59e0b"><i class="fa-solid fa-triangle-exclamation"></i>
        <?= PHP_VERSION_ID < 80100 ? 'Claude necesita PHP 8.1 o más nuevo y este servidor tiene ' . PHP_VERSION . '.' : 'Falta el paquete de Claude en el servidor (_private/claude-sdk.phar).' ?></div></div>
      <?php endif; ?>
    </section>

<?php if (g7_super_ok((int)$currentUser['id'])): ?>
    <!-- ── JIRA ── -->
    <section class="adm-section" id="sec-jira">
      <div class="adm-title">Jira API</div>
      <div class="adm-sub">Conexión de solo lectura con Jira Cloud: Toolz consulta y no modifica nada allá. El token se guarda en el servidor (carpeta privada) y nunca llega al navegador: acá solo se ve el final.</div>
      <div class="adm-card" style="margin-bottom:14px">
        <div class="adm-card-head">
          <div class="adm-card-title"><i class="fa-brands fa-jira"></i> Conexión
            <span id="jira-state" style="margin-left:8px;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;<?= $jiraCfg['token'] ? 'background:rgba(34,197,94,.12);color:#22c55e' : 'background:var(--g7-bg-4);color:var(--g7-text-4)' ?>"><?= $jiraCfg['token'] ? 'Configurada' : 'Sin configurar' ?></span></div>
          <a href="https://id.atlassian.com/manage-profile/security/api-tokens" target="_blank" rel="noopener" class="btn btn-ghost btn-sm"><i class="fa-solid fa-arrow-up-right-from-square"></i> Conseguir el token</a>
        </div>
        <div class="adm-card-body">
          <div style="display:flex;flex-direction:column;gap:12px">
            <div style="font-size:12px;color:var(--g7-text-4);line-height:1.5">Toolz ve en Jira lo mismo que la cuenta dueña del token: conviene una cuenta dedicada con permiso de solo lectura. En id.atlassian.com › Seguridad › Tokens de API, elige «Crear token de API» (sin ámbitos).</div>
            <div>
              <label class="adm-label">Sitio de Jira</label>
              <input class="adm-input" id="jira-site" value="<?= htmlspecialchars($jiraCfg['site']) ?>" autocomplete="off" placeholder="https://tu-sitio.atlassian.net">
            </div>
            <div>
              <label class="adm-label">Email de la cuenta</label>
              <input class="adm-input" id="jira-email" type="email" value="<?= htmlspecialchars($jiraCfg['email']) ?>" autocomplete="off" placeholder="cuenta@empresa.com">
            </div>
            <div>
              <label class="adm-label">Token de API</label>
              <div style="display:flex;gap:8px">
                <input class="adm-input" id="jira-token" type="password" value="" autocomplete="new-password" placeholder="<?= $jiraCfg['token'] ? 'Guardado (termina en …' . htmlspecialchars($iaTail($jiraCfg['token'])) . '). Vacío = no cambiarlo' : 'Pega el token acá' ?>">
                <button class="btn-icon" data-on-click="admJiraEye" title="Mostrar"><i class="fa-solid fa-eye" id="jira-eye"></i></button>
              </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button class="btn btn-primary" data-on-click="admJiraSave"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
              <button class="btn btn-ghost" data-on-click="admJiraTest" data-click-1="this"><i class="fa-solid fa-vial"></i> Probar conexión</button>
              <button class="btn btn-ghost" data-on-click="admJiraClear" style="margin-left:auto"><i class="fa-solid fa-trash"></i> Quitar el token</button>
            </div>
            <div id="jira-result" style="display:none;font-size:12px;padding:10px 14px;border-radius:8px;background:var(--g7-bg-4);line-height:1.5"></div>
          </div>
        </div>
      </div>
<?php require_once __DIR__ . '/jira_use_lib.php'; $jiraFeat = g7_jira_features(); ?>
      <div class="adm-card" style="margin-bottom:14px">
        <div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-plug"></i> Usar Jira en Cronos</div></div>
        <div class="adm-card-body">
          <div style="display:flex;flex-direction:column;gap:12px">
            <div style="font-size:12px;color:var(--g7-text-4);line-height:1.5">Elige dónde se usa y de qué proyectos. Sigue siendo de solo lectura: vincular un contenido con un issue se guarda en Cronos, no en Jira.</div>
            <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer"><input type="checkbox" id="jira-f-promos"<?= $jiraFeat['promos'] ? ' checked' : '' ?> style="margin-top:3px"><span><b>Promos</b><br><span style="font-size:12px;color:var(--g7-text-4)">En el detalle de cada contenido aparece "Jira" para vincularlo con uno o más issues y ver su estado.</span></span></label>
            <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer"><input type="checkbox" id="jira-f-trabajo"<?= $jiraFeat['trabajo'] ? ' checked' : '' ?> style="margin-top:3px"><span><b>Gestión de trabajo</b><br><span style="font-size:12px;color:var(--g7-text-4)">Suma la sección "Jira" con los tickets de los proyectos elegidos (filtros, los míos, por estado) y los contenidos de Promos vinculados.</span></span></label>
            <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer"><input type="checkbox" id="jira-f-chat"<?= $jiraFeat['chat'] ? ' checked' : '' ?> style="margin-top:3px"><span><b>Chat</b><br><span style="font-size:12px;color:var(--g7-text-4)">En el chat (burbuja y grande) se pueden vincular tickets de Jira desde "Vincular" o escribiendo @ y la clave o el texto, como las tareas y los tickets de Trabajo.</span></span></label>
            <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer"><input type="checkbox" id="jira-f-stats"<?= !empty($jiraFeat['stats']) ? ' checked' : '' ?> style="margin-top:3px"><span><b>Estadísticas</b><br><span style="font-size:12px;color:var(--g7-text-4)">Estadísticas › Jira: informes de los proyectos elegidos (creados y resueltos, tiempo de resolución, atrasados, carga por persona, tipos y prioridades).</span></span></label>
            <div>
              <label class="adm-label">Proyectos de Jira</label>
              <div id="jira-f-projects" data-keys="<?= htmlspecialchars(implode(',', $jiraFeat['projects'])) ?>" style="display:flex;flex-wrap:wrap;gap:8px;font-size:12.5px"><?php foreach ($jiraFeat['projects'] as $k): ?><label style="display:inline-flex;gap:6px;align-items:center;padding:5px 10px;border:1px solid var(--g7-border-3);border-radius:8px"><input type="checkbox" value="<?= htmlspecialchars($k) ?>" checked> <?= htmlspecialchars($k) ?></label><?php endforeach; ?><?= $jiraFeat['projects'] ? '' : '<span style="color:var(--g7-text-4)">Ninguno elegido todavía.</span>' ?></div>
              <button class="btn btn-ghost btn-sm" style="margin-top:8px" data-on-click="admJiraProjects" data-click-1="this"><i class="fa-solid fa-rotate"></i> Traer los proyectos de Jira</button>
            </div>
            <div style="display:flex;gap:8px"><button class="btn btn-primary" data-on-click="admJiraFeatSave"><i class="fa-solid fa-floppy-disk"></i> Guardar</button></div>
            <div id="jira-f-result" style="display:none;font-size:12px;padding:10px 14px;border-radius:8px;background:var(--g7-bg-4);line-height:1.5"></div>
          </div>
        </div>
      </div>
    </section>
<?php endif; ?>

    <!-- ── CONTENT ── -->
    <section class="adm-section" id="sec-content">
      <div class="adm-title">Contenido</div>
      <div class="adm-sub">Estadísticas y accesos rápidos de gestión</div>
      <div id="adm-content-stats" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <a href="/toolz/content.php" class="adm-card" style="text-decoration:none;transition:border-color .2s" data-hov>
          <div class="adm-card-body" style="display:flex;align-items:center;gap:12px">
            <div style="width:42px;height:42px;border-radius:10px;background:var(--g7-accent-muted);display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-layer-group" style="color:var(--g7-accent)"></i></div>
            <div><div style="font-size:13px;font-weight:700">CMS de Promos</div><div style="font-size:11px;color:var(--g7-text-4)">Gestionar promociones y contenido</div></div>
          </div>
        </a>
        <a href="/toolz/library.php" class="adm-card" style="text-decoration:none;transition:border-color .2s" data-hov>
          <div class="adm-card-body" style="display:flex;align-items:center;gap:12px">
            <div style="width:42px;height:42px;border-radius:10px;background:var(--g7-accent-muted);display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-book" style="color:var(--g7-accent)"></i></div>
            <div><div style="font-size:13px;font-weight:700">Biblioteca de Links</div><div style="font-size:11px;color:var(--g7-text-4)">Links y categorías del equipo</div></div>
          </div>
        </a>
        <a href="/toolz/pages.php" class="adm-card" style="text-decoration:none;transition:border-color .2s" data-hov>
          <div class="adm-card-body" style="display:flex;align-items:center;gap:12px">
            <div style="width:42px;height:42px;border-radius:10px;background:var(--g7-accent-muted);display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-file-code" style="color:var(--g7-accent)"></i></div>
            <div><div style="font-size:13px;font-weight:700">Landing Pages</div><div style="font-size:11px;color:var(--g7-text-4)">Builder de páginas de destino</div></div>
          </div>
        </a>
        <a href="/toolz/stock.php" class="adm-card" style="text-decoration:none;transition:border-color .2s" data-hov>
          <div class="adm-card-body" style="display:flex;align-items:center;gap:12px">
            <div style="width:42px;height:42px;border-radius:10px;background:var(--g7-accent-muted);display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-images" style="color:var(--g7-accent)"></i></div>
            <div><div style="font-size:13px;font-weight:700">Stock Gallery</div><div style="font-size:11px;color:var(--g7-text-4)">Galería de imágenes y assets</div></div>
          </div>
        </a>
      </div>

      <!-- HERRAMIENTAS -->
      <div style="margin-top:24px">
        <div style="font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px;display:flex;align-items:center;gap:8px">
          <i class="fa-solid fa-screwdriver-wrench" style="color:var(--g7-accent)"></i> Herramientas
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
          <div class="adm-card" style="cursor:pointer;transition:border-color .2s" data-hov>
            <div class="adm-card-body" style="display:flex;align-items:center;gap:12px">
              <div style="width:42px;height:42px;border-radius:10px;background:var(--g7-accent-muted);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <i class="fa-solid fa-film" style="color:var(--g7-accent)"></i>
              </div>
              <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:700">Generar thumbnails de videos</div>
                <div style="font-size:11px;color:var(--g7-text-4)">Captura el primer frame de cada video sin thumbnail</div>
              </div>
              <button class="btn btn-primary btn-sm" data-on-click="admOpenThumbGenerator" style="flex-shrink:0">
                <i class="fa-solid fa-play"></i> Iniciar
              </button>
            </div>
            <!-- Progress area -->
            <div id="thumb-gen-wrap" style="display:none;padding:0 18px 14px">
              <div style="font-size:11px;color:var(--g7-text-4);margin-bottom:6px" id="thumb-gen-status">Iniciando...</div>
              <div style="height:6px;background:var(--g7-bg-5);border-radius:3px;overflow:hidden">
                <div id="thumb-gen-bar" style="height:100%;width:0;background:linear-gradient(90deg,var(--g7-accent),#ff9500);border-radius:3px;transition:width .3s"></div>
              </div>
              <div style="font-size:10px;color:var(--g7-text-5);margin-top:4px" id="thumb-gen-count"></div>
            </div>
          </div>
        </div>
      </div>

    </section>

    <!-- ── TOPBAR ── -->
    <section class="adm-section" id="sec-topbar">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
        <div class="adm-title">Topbar & Logo</div>
        <button class="btn btn-primary" data-on-click="admSaveTopbar"><i class="fa-solid fa-floppy-disk"></i> Guardar cambios</button>
      </div>
      <div class="adm-sub">Personaliza el menú de navegación y el logo de la plataforma</div>

      <!-- LOGO -->
      <div class="adm-card" style="margin-bottom:16px">
        <div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-image"></i> Logo de la plataforma</div></div>
        <div class="adm-card-body">
          <div style="display:flex;align-items:center;gap:16px;margin-bottom:14px">
            <div style="width:120px;height:50px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:8px;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">
              <img id="adm-logo-preview" src="<?= htmlspecialchars(g7_site('logo')) ?>" style="max-width:110px;max-height:40px;object-fit:contain" data-on-error="admImgBlank">
            </div>
            <div style="flex:1">
              <label class="adm-label">URL del logo</label>
              <input class="adm-input" id="adm-logo-url" placeholder="https://... o sube una imagen abajo" data-on-input="admPreviewLogo" data-input-1="value">
            </div>
          </div>
          <div style="margin-bottom:8px">
            <label class="adm-label">Subir logo desde archivo</label>
            <label class="btn btn-ghost" style="cursor:pointer;width:fit-content">
              <i class="fa-solid fa-upload"></i> Elegir imagen
              <input type="file" accept="image/*" style="display:none" data-on-change="admUploadLogo" data-change-1="this">
            </label>
            <span id="adm-logo-upload-status" style="font-size:11px;color:var(--g7-text-4);margin-left:8px"></span>
          </div>
          <div style="font-size:10px;color:var(--g7-text-5)">Formatos: PNG, SVG, WebP — Recomendado: fondo transparente, 160×48px</div>
        </div>
      </div>

      <!-- ALIGNMENT -->
      <div class="adm-card" style="margin-bottom:16px">
        <div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-align-center"></i> Alineación del menú</div></div>
        <div class="adm-card-body">
          <div style="display:flex;gap:10px">
            <label style="flex:1;cursor:pointer">
              <input type="radio" name="nav-align" value="left" id="align-left" style="display:none">
              <div class="adm-align-btn" data-val="left" data-on-click="admSetAlign" data-click-1="s:left">
                <i class="fa-solid fa-align-left" style="font-size:18px;margin-bottom:5px"></i>
                <span>Izquierda</span>
              </div>
            </label>
            <label style="flex:1;cursor:pointer">
              <input type="radio" name="nav-align" value="center" id="align-center" style="display:none">
              <div class="adm-align-btn active" data-val="center" data-on-click="admSetAlign" data-click-1="s:center">
                <i class="fa-solid fa-align-center" style="font-size:18px;margin-bottom:5px"></i>
                <span>Centro</span>
              </div>
            </label>
            <label style="flex:1;cursor:pointer">
              <input type="radio" name="nav-align" value="right" id="align-right" style="display:none">
              <div class="adm-align-btn" data-val="right" data-on-click="admSetAlign" data-click-1="s:right">
                <i class="fa-solid fa-align-right" style="font-size:18px;margin-bottom:5px"></i>
                <span>Derecha</span>
              </div>
            </label>
          </div>
        </div>
      </div>

      <!-- NAV ITEMS -->
      <div class="adm-card">
        <div class="adm-card-head">
          <div class="adm-card-title"><i class="fa-solid fa-list"></i> Ítems del menú</div>
          <button class="btn btn-ghost btn-sm" data-on-click="admAddNavItem"><i class="fa-solid fa-plus"></i> Agregar</button>
        </div>
        <div class="adm-card-body" style="padding:0">
          <div id="nav-items-list" style="padding:8px">
            <div style="text-align:center;color:var(--g7-text-5);padding:20px">Cargando...</div>
          </div>
        </div>
      </div>
    </section>

    <!-- ── PLUGINS ── -->
    <section class="adm-section" id="sec-plugins">
      <div class="adm-title">Plugins</div>
      <div class="adm-sub">Módulos que extienden la funcionalidad de la plataforma</div>
      <div id="plugins-grid" style="display:grid;grid-template-columns:1fr;gap:14px"></div>
    </section>

    <!-- ── LOGS ── -->
    <section class="adm-section" id="sec-logs">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
        <div class="adm-title">Logs de actividad</div>
        <div style="display:flex;gap:8px">
          <select class="adm-select" id="log-type-filter" data-on-change="admLoadLogs" style="width:auto">
            <option value="">Todos los tipos</option>
            <option value="login">Login</option>
            <option value="content_create">Contenido</option>
            <option value="post_create">Posts</option>
            <option value="link_open">Links</option>
            <option value="profile_update">Perfil</option>
          </select>
          <button class="btn btn-ghost" data-on-click="admLoadLogs"><i class="fa-solid fa-rotate-right"></i> Actualizar</button>
        </div>
      </div>
      <div class="adm-sub">Registro de acciones de usuarios</div>
      <div class="adm-card">
        <div class="adm-card-body" id="logs-container" style="padding:0">
          <div style="padding:20px;text-align:center;color:var(--g7-text-5)">Cargando logs...</div>
        </div>
      </div>
    </section>

  </div>
</div>

<div id="adm-toast"></div>

<!-- ICON PICKER MODAL -->
<div class="adm-modal" id="icon-picker-modal">
  <div class="adm-modal-box" style="max-width:600px">
    <div class="adm-modal-head">
      <span class="adm-modal-title"><i class="fa-solid fa-icons" style="color:var(--g7-accent);margin-right:8px"></i>Elegir ícono</span>
      <button class="adm-modal-close" data-on-click="admCloseIconPicker">✕</button>
    </div>
    <div style="padding:10px 18px;border-bottom:1px solid var(--g7-border);flex-shrink:0">
      <input class="adm-input" id="icon-search" placeholder="Buscar ícono..." data-on-input="admFilterIcons" data-input-1="value" style="font-size:13px">
    </div>
    <div class="adm-modal-body" style="padding:12px 18px" id="icon-picker-body"></div>
  </div>
</div>

<script src="/toolz/assets/admin-page.js?v=<?= (int)@filemtime(__DIR__ . '/assets/admin-page.js') ?>"></script>
<script src="/toolz/admin_ui.js?v=<?= @filemtime(__DIR__ . '/admin_ui.js') ?>"></script>
</body>
</html>