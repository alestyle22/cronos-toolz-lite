<?php
require_once __DIR__ . '/../auth.php';
$currentUser = requireLogin();
require_once __DIR__ . '/../csp_lib.php'; g7_csp_enforce();   // CSP estricta (SEC-24 fase 2): sin código en línea
$isAdmin     = ($currentUser['role'] === 'admin');
$db          = getDB();

// ── PAGE ACCESS TRACKING ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS g7_accesos_rapidos (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, tool VARCHAR(255) NOT NULL, tool_name VARCHAR(255) NOT NULL DEFAULT '', meta VARCHAR(50) DEFAULT '', accessed_at DATETIME DEFAULT NOW(), INDEX idx_user(user_id), INDEX idx_time(accessed_at))");
        $db->prepare("INSERT INTO g7_accesos_rapidos (user_id, tool, tool_name, meta) VALUES (?,?,?,?)")
           ->execute([$currentUser['id'], '/toolz/links/', 'Biblioteca', '']);
    } catch(Exception $e) {}
}
// ─────────────────────────────────────────────────────────
$imgFolder   = __DIR__ . '/../link_covers/';
if (!is_dir($imgFolder)) mkdir($imgFolder, 0755, true);

// ── AJAX ACTIONS ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ct    = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = strpos($ct, 'application/json') !== false
           ? json_decode(file_get_contents('php://input'), true)
           : null;
    $action = $input['action'] ?? ($_POST['action'] ?? '');

    // HTML subidos (página propia dentro de la Biblioteca): _private/biblioteca/<id>.html, se ven en links/ver.php?id=N
    try { $db->exec("ALTER TABLE g7_links ADD COLUMN IF NOT EXISTS html_file VARCHAR(80) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
    $htmlDir = __DIR__ . '/../_private/biblioteca/';
    $htmlUrl = fn($id) => '/toolz/links/ver.php?id=' . (int)$id;
    /** Guarda el HTML subido para el enlace $id. Devuelve el nombre del archivo o corta con el error. */
    $storeHtml = function (int $id) use ($db, $htmlDir, $htmlUrl): string {
        $f = $_FILES['html'];
        if ($why = g7_upload_problem($f)) { echo json_encode(['error' => $why]); exit; }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['html', 'htm'], true)) { echo json_encode(['error' => 'Tiene que ser un archivo .html']); exit; }
        if ($f['size'] > 16 * 1024 * 1024) { echo json_encode(['error' => 'El HTML pesa más de 16 MB']); exit; }
        $name = $id . '.html';
        if ($why = g7_upload_store($f, $htmlDir, $name)) { echo json_encode(['error' => $why]); exit; }
        $db->prepare('UPDATE g7_links SET html_file = ?, url = ? WHERE id = ?')->execute([$name, $htmlUrl($id), $id]);
        return $name;
    };
    $hasHtml = isset($_FILES['html']) && $_FILES['html']['error'] !== UPLOAD_ERR_NO_FILE;

    // Admin-only actions gate
    $adminActions = ['add_category','delete_category','reorder_categories','add_link','delete_link','edit_link','save_public_note'];
    if (in_array($action, $adminActions) && !$isAdmin) { echo json_encode(['error'=>'forbidden']); exit; }

    if ($action === 'add_category') {
        $name = trim($input['name'] ?? '');
        if ($name) {
            $max = $db->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM g7_categories')->fetchColumn();
            $db->prepare('INSERT INTO g7_categories (name, sort_order) VALUES (?,?)')->execute([$name, $max]);
            echo json_encode(['status'=>'success','id'=>$db->lastInsertId(),'name'=>$name]);
        }
        exit;
    }
    if ($action === 'delete_category') {
        $db->prepare('DELETE FROM g7_categories WHERE id=?')->execute([$input['id']]);
        echo json_encode(['status'=>'success']); exit;
    }
    if ($action === 'reorder_categories') {
        $stmt = $db->prepare('UPDATE g7_categories SET sort_order=? WHERE id=?');
        foreach (($input['order'] ?? []) as $i => $id) $stmt->execute([$i, $id]);
        echo json_encode(['status'=>'success']); exit;
    }
    if ($action === 'add_link') {
        $imgPath = '';
        if (isset($_FILES['img']) && $_FILES['img']['error'] === 0) {
            $ext=g7_upload_ext_or_die($_FILES['img']['name'],['jpg','jpeg','png','gif','webp','avif','ico','svg']);$newName=time().'_'.uniqid().'.'.$ext;
            if(move_uploaded_file($_FILES['img']['tmp_name'],$imgFolder.$newName))$imgPath='/toolz/link_covers/'.$newName;
        } elseif (!empty($_POST['img_url'])) {
            $remoteUrl=trim($_POST['img_url']);$ch=curl_init($remoteUrl);
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_USERAGENT=>'Mozilla/5.0']);
            $imgData=curl_exec($ch);$ctype=curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);
            if($imgData){$extMap=['image/jpeg'=>'jpg','image/jpg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','image/svg+xml'=>'svg','image/x-icon'=>'ico','image/vnd.microsoft.icon'=>'ico'];
            $mime=strtolower(explode(';',$ctype)[0]);$ext=$extMap[$mime]??null;
            if(!$ext){$path=parse_url($remoteUrl,PHP_URL_PATH);$ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));$ext=preg_replace('/[^a-z0-9]/','',$ext)?:'jpg';}if(!in_array($ext,['jpg','jpeg','png','gif','webp','avif','ico','svg'],true))$ext='jpg';
            $newName=time().'_'.uniqid().'.'.$ext;if(file_put_contents($imgFolder.$newName,$imgData))$imgPath='/toolz/link_covers/'.$newName;}
        }
        $tags=json_encode(array_filter(array_map('trim',explode(',',$_POST['tags']??''))));
        $stmt=$db->prepare('INSERT INTO g7_links (category_id,name,description,url,img,tags,created_by) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$_POST['category_id'],$_POST['name'],$_POST['description'],$hasHtml ? '' : $_POST['url'],$imgPath,$tags,$currentUser['id']]);
        $newId=$db->lastInsertId();
        if ($hasHtml) $storeHtml((int)$newId);$row=$db->query("SELECT * FROM g7_links WHERE id=$newId")->fetch();
        $row['tags']=json_decode($row['tags']?:'[]',true);echo json_encode(['status'=>'success','link'=>$row]);exit;
    }
    if ($action === 'delete_link') {
        $h = $db->prepare('SELECT html_file FROM g7_links WHERE id=?'); $h->execute([$input['id']]); $h = (string)$h->fetchColumn();
        if ($h !== '') @unlink($htmlDir . basename($h));
        $db->prepare('DELETE FROM g7_links WHERE id=?')->execute([$input['id']]);
        echo json_encode(['status'=>'success']); exit;
    }
    if ($action === 'edit_link') {
        $existing=$db->prepare('SELECT img FROM g7_links WHERE id=?');$existing->execute([$_POST['id']]);$row=$existing->fetch();$imgPath=$row['img']??'';
        if(isset($_FILES['img'])&&$_FILES['img']['error']===0){$ext=g7_upload_ext_or_die($_FILES['img']['name'],['jpg','jpeg','png','gif','webp','avif','ico','svg']);$newName=time().'_'.uniqid().'.'.$ext;if(move_uploaded_file($_FILES['img']['tmp_name'],$imgFolder.$newName))$imgPath='/toolz/link_covers/'.$newName;}
        elseif(!empty($_POST['img_url'])){$remoteUrl=trim($_POST['img_url']);$ch=curl_init($remoteUrl);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_USERAGENT=>'Mozilla/5.0']);$imgData=curl_exec($ch);$ctype=curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);if($imgData){$extMap=['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','image/x-icon'=>'ico','image/vnd.microsoft.icon'=>'ico'];$mime=strtolower(explode(';',$ctype)[0]);$ext=$extMap[$mime]??preg_replace('/[^a-z0-9]/','',strtolower(pathinfo(parse_url($remoteUrl,PHP_URL_PATH),PATHINFO_EXTENSION)))?:'jpg';if(!in_array($ext,['jpg','jpeg','png','gif','webp','avif','ico','svg'],true))$ext='jpg';$newName=time().'_'.uniqid().'.'.$ext;if(file_put_contents($imgFolder.$newName,$imgData))$imgPath='/toolz/link_covers/'.$newName;}}
        $tags=json_encode(array_filter(array_map('trim',explode(',',$_POST['tags']??''))));
        $lid = (int)$_POST['id'];
        $prevHtml = $db->prepare('SELECT html_file FROM g7_links WHERE id = ?'); $prevHtml->execute([$lid]); $prevHtml = (string)$prevHtml->fetchColumn();
        $url = $_POST['url'];
        // Si tenía un HTML y ahora apunta a otro lado, el archivo se borra
        if ($prevHtml !== '' && !$hasHtml && $url !== $htmlUrl($lid)) { @unlink($htmlDir . basename($prevHtml)); $db->prepare("UPDATE g7_links SET html_file = '' WHERE id = ?")->execute([$lid]); }
        $db->prepare('UPDATE g7_links SET category_id=?,name=?,description=?,url=?,img=?,tags=? WHERE id=?')->execute([$_POST['category_id'],$_POST['name'],$_POST['description'],$url,$imgPath,$tags,$lid]);
        if ($hasHtml) $storeHtml($lid);
        $row=$db->query("SELECT * FROM g7_links WHERE id=".(int)$_POST['id'])->fetch();$row['tags']=json_decode($row['tags']?:'[]',true);echo json_encode(['status'=>'success','link'=>$row]);exit;
    }
    if ($action === 'toggle_favorite') {
        $linkId=(int)($input['link_id']??0);
        if($linkId){$check=$db->prepare('SELECT 1 FROM g7_link_favorites WHERE user_id=? AND link_id=?');$check->execute([$currentUser['id'],$linkId]);
        if($check->fetch()){$db->prepare('DELETE FROM g7_link_favorites WHERE user_id=? AND link_id=?')->execute([$currentUser['id'],$linkId]);echo json_encode(['status'=>'success','favorited'=>false]);}
        else{$db->prepare('INSERT INTO g7_link_favorites (user_id, link_id) VALUES (?,?)')->execute([$currentUser['id'],$linkId]);echo json_encode(['status'=>'success','favorited'=>true]);}exit;}
    }
    if ($action === 'save_public_note') {
        $linkId=(int)($input['link_id']??0);$note=trim($input['note']??'');
        if($linkId){$db->prepare('UPDATE g7_links SET public_note=? WHERE id=?')->execute([$note,$linkId]);echo json_encode(['status'=>'success','note'=>$note]);exit;}
    }
    if ($action === 'save_note') {
        $linkId=(int)($input['link_id']??0);$note=trim($input['note']??'');
        if($linkId){$db->prepare('INSERT INTO g7_link_notes (link_id, user_id, note) VALUES (?,?,?) ON DUPLICATE KEY UPDATE note=VALUES(note), updated_at=NOW()')->execute([$linkId,$currentUser['id'],$note]);echo json_encode(['status'=>'success']);exit;}
    }
    echo json_encode(['error'=>'unknown action']); exit;
}

// ── GUÍAS PROPIAS ─────────────────────────────────────────
// Páginas que viven dentro de Toolz y se suman solas a la Biblioteca la primera vez. Queda marcado en g7_config:
// si después alguien borra la tarjeta, no vuelve a aparecer.
$g7Guides = [
    'lib_seed_smartico_imagenes' => ['cat' => ['Documentos', 'Diseño', 'General'], 'name' => 'Mapa de imágenes Smartico',
        'desc' => 'Guía para Diseño: qué imágenes lleva cada pieza de Smartico (email, inbox, popup, push, banners, gamificación), medidas y qué entregar por campaña.',
        'url' => '/toolz/guias/smartico-imagenes.php', 'img' => '/toolz/link_covers/guia_smartico_imagenes.png', 'tags' => ['INFORMATIVO', 'RECURSOS']],
    'lib_seed_mesa_ayuda' => ['cat' => ['General'], 'name' => 'Mesa de ayuda (tickets)',
        'desc' => 'Portal de Grupo Latino para abrir tickets a las distintas áreas y seguir su estado.',
        'url' => 'https://grupo-latino.atlassian.net/servicedesk/customer/portals', 'img' => '/toolz/link_covers/mesa_de_ayuda_tickets.png', 'tags' => ['HERRAMIENTA', 'SITIO WEB']],
];
foreach ($g7Guides as $flag => $g) {
    if (g7_cfg($flag)) continue;
    try {
        $catId = null;
        foreach ($g['cat'] as $cn) { $q = $db->prepare('SELECT id FROM g7_categories WHERE name = ? LIMIT 1'); $q->execute([$cn]); if ($catId = $q->fetchColumn()) break; }
        if (!$catId) $catId = $db->query('SELECT id FROM g7_categories ORDER BY sort_order, id LIMIT 1')->fetchColumn();
        $has = $db->prepare('SELECT COUNT(*) FROM g7_links WHERE url = ?'); $has->execute([$g['url']]);
        if ($catId && !$has->fetchColumn()) {
            $db->prepare('INSERT INTO g7_links (category_id,name,description,url,img,tags,created_by) VALUES (?,?,?,?,?,?,?)')
               ->execute([$catId, $g['name'], $g['desc'], $g['url'], $g['img'], json_encode($g['tags']), $currentUser['id']]);
        }
        if ($catId) g7_cfg_set($flag, date('Y-m-d H:i'));
    } catch (Throwable $e) {}
}

// ── LOAD DATA ─────────────────────────────────────────────
$categories = $db->query('SELECT * FROM g7_categories ORDER BY sort_order, name')->fetchAll();
$links      = $db->query('SELECT * FROM g7_links ORDER BY created_at DESC')->fetchAll();
$links = array_map(function($l){$l['tags']=json_decode($l['tags']?:'[]',true);return $l;},$links);
// Las notas compartidas suelen tener usuarios y claves de proveedores: solo se mandan a quien tiene
// el permiso "Ver notas compartidas" (Admin › Módulos › Biblioteca). Al resto le llega solo que existe.
$canNotes = g7_can((int)$currentUser['id'], 'links', 'notes');
// Catálogo Cronos (herramientas del sitio base que se copian a la Biblioteca): permiso aparte y solo si el sitio está conectado. Doc: toolz/CATALOGO.md
require_once __DIR__ . '/catalogo_lib.php';
$canCatalog = g7_can((int)$currentUser['id'], 'links', 'catalog') && cat_connected();
if (!$canNotes) $links = array_map(function($l){ $l['pub_locked'] = trim((string)($l['public_note'] ?? '')) !== ''; $l['public_note'] = ''; return $l; }, $links);
$notesRows=$db->prepare('SELECT link_id, note FROM g7_link_notes WHERE user_id = ?');$notesRows->execute([$currentUser['id']]);
$notes=[];foreach($notesRows->fetchAll() as $n)$notes[$n['link_id']]=$n['note'];
$favRows=$db->prepare('SELECT link_id FROM g7_link_favorites WHERE user_id = ?');$favRows->execute([$currentUser['id']]);
$favorites=array_column($favRows->fetchAll(),'link_id');
$allTags=['SITIO WEB','RECURSOS','DRIVE','ARCHIVO','HERRAMIENTA','STOCK','UTILIDAD','INFORMATIVO','DIGITAIN','CENTRIVO','CASINOS'];
$dataJSON=json_encode(['categories'=>$categories,'links'=>$links,'notes'=>$notes,'favorites'=>$favorites]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Biblioteca · <?= htmlspecialchars(g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<style>
:root{--brand:var(--g7-accent)}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--g7-bg-2);color:var(--g7-text);font-family:'Inter',sans-serif;min-height:100vh}
.filter-bar{padding:10px 32px;border-bottom:1px solid var(--g7-border-2);display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:var(--g7-bg-2);position:sticky;top:52px;z-index:30;box-shadow:0 4px 12px rgba(0,0,0,.4)}
.filter-bar select{background:var(--g7-bg-3);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:6px;padding:5px 8px;font-size:11px;outline:none;cursor:pointer}
.filter-bar select:focus{border-color:var(--brand)}
.search-wrap{position:relative;flex-shrink:0}
.search-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--g7-text-4);font-size:11px;pointer-events:none}
#searchInput{background:var(--g7-bg-3);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:6px 12px 6px 30px;font-size:12px;outline:none;width:220px;transition:border-color .15s}
#searchInput:focus{border-color:var(--brand)}
.tag-filters{display:flex;gap:5px;flex-wrap:wrap}
.tag-pill{padding:3px 10px;border:1px solid var(--g7-border-3);border-radius:999px;font-size:10px;font-weight:600;color:var(--g7-text-3);cursor:pointer;transition:all .15s;background:none;text-transform:uppercase;letter-spacing:.04em}
.tag-pill:hover{border-color:var(--brand);color:var(--brand)}
.tag-pill.active{background:var(--brand);color:#000;border-color:var(--brand)}
.clear-btn{background:none;border:1px solid var(--g7-border-3);color:var(--g7-text-3);border-radius:6px;padding:5px 10px;font-size:11px;cursor:pointer;transition:all .15s;white-space:nowrap}
.clear-btn:hover{color:var(--g7-text);border-color:#71717a}
.main{max-width:1200px;margin:0 auto;padding:32px}
.section{margin-bottom:48px}
.section-header{display:flex;align-items:center;gap:10px;padding-bottom:12px;border-bottom:1px solid var(--g7-border-2);margin-bottom:24px}
.section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--g7-text-4);flex:1}
.section-count{font-size:10px;color:var(--g7-text-5);font-weight:600}
.toggle-section{background:none;border:none;color:var(--g7-text-4);cursor:pointer;font-size:11px;padding:2px 6px;transition:color .15s}
.toggle-section:hover{color:var(--g7-text)}
.admin-section-actions{display:flex;gap:6px}
.del-cat-btn{background:none;border:1px solid transparent;color:var(--g7-text-5);border-radius:5px;padding:3px 7px;font-size:10px;cursor:pointer;transition:all .15s}
.del-cat-btn:hover{border-color:rgba(239,68,68,.3);color:#ef4444}
.add-item-btn{background:none;border:1px solid var(--g7-border-3);color:var(--g7-text-3);border-radius:6px;padding:4px 10px;font-size:10px;font-weight:600;cursor:pointer;transition:all .15s}
.add-item-btn:hover{border-color:var(--brand);color:var(--brand)}
.cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px}
.link-card{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:12px;overflow:hidden;transition:all .2s;text-decoration:none;color:inherit;display:block}
.link-card:hover{border-color:var(--brand);transform:translateY(-2px)}
.link-card .card-img{width:100%;height:130px;background:var(--g7-bg-4);display:flex;align-items:center;justify-content:center;overflow:hidden}
.link-card .card-img img{width:100%;height:100%;object-fit:cover;filter:grayscale(.3);transition:filter .2s}
.link-card:hover .card-img img{filter:grayscale(0)}
.link-card .card-body{padding:12px 13px}
.link-card .card-name{font-size:13px;font-weight:700;margin-bottom:4px;line-height:1.3}
.link-card .card-desc{font-size:11px;color:var(--g7-text-3);margin-bottom:8px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.link-card .card-tags{display:flex;gap:4px;flex-wrap:wrap}
.card-tag{font-size:8px;font-weight:700;padding:2px 5px;border-radius:3px;text-transform:uppercase;background:var(--g7-bg-5);color:var(--g7-text-4)}
.card-admin-row{display:flex;justify-content:flex-end;padding:6px 10px 8px;border-top:1px solid var(--g7-border-2)}
.del-link-btn{background:none;border:none;color:var(--g7-text-5);font-size:11px;cursor:pointer;transition:color .15s;padding:2px 6px}
.del-link-btn:hover{color:#ef4444}
.empty-cat{color:var(--g7-text-5);font-size:12px;padding:20px 0}
.no-results{text-align:center;color:var(--g7-text-5);padding:60px 20px;font-size:13px}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:100;display:flex;align-items:center;justify-content:center;padding:20px}
.modal-card{background:var(--g7-bg-3);border:1px solid var(--g7-border-3);border-radius:16px;padding:24px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto}
.modal-card::-webkit-scrollbar{width:3px}.modal-card::-webkit-scrollbar-thumb{background:var(--g7-bg-5)}
.modal-title{font-size:14px;font-weight:700;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between}
.modal-close{background:none;border:none;color:var(--g7-text-3);font-size:16px;cursor:pointer}
.modal-close:hover{color:var(--g7-text)}
.m-group{display:flex;flex-direction:column;gap:5px;margin-bottom:12px}
.m-group label{font-size:9px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.08em}
.m-group input,.m-group textarea,.m-group select{background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:7px;padding:9px 11px;font-size:12px;outline:none;width:100%}
.m-group input:focus,.m-group textarea:focus,.m-group select:focus{border-color:var(--brand)}
.m-group textarea{resize:vertical;min-height:70px}
.tags-selector{display:flex;gap:6px;flex-wrap:wrap}
.tag-opt{padding:5px 10px;border:1px solid var(--g7-border-3);border-radius:999px;font-size:10px;font-weight:600;color:var(--g7-text-3);cursor:pointer;transition:all .15s;background:none;text-transform:uppercase;user-select:none}
.tag-opt:hover{border-color:var(--g7-border-3);color:var(--g7-text-2)}
.tag-opt.selected{background:var(--brand);color:#000;border-color:var(--brand)}
.m-submit{width:100%;background:var(--brand);color:#000;border:none;border-radius:8px;padding:11px;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;cursor:pointer;margin-top:6px;transition:opacity .15s}
.m-submit:hover{opacity:.85}
.add-cat-bar{display:flex;gap:8px;margin-bottom:24px}
.add-cat-bar input{background:var(--g7-bg-3);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:9px 12px;font-size:12px;outline:none;flex:1}
.add-cat-bar input:focus{border-color:var(--brand)}
.add-cat-bar button{background:var(--brand);color:#000;border:none;border-radius:8px;padding:9px 16px;font-size:11px;font-weight:700;cursor:pointer;transition:opacity .15s;white-space:nowrap}
.add-cat-bar button:hover{opacity:.85}
.hidden{display:none!important}
.note-area{border-top:1px solid var(--g7-border-2);padding:10px 13px;background:var(--g7-bg-2)}
.note-header{display:flex;align-items:center;gap:6px;cursor:pointer;user-select:none}
.note-header span{font-size:10px;font-weight:600;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.06em;flex:1}
.note-header .note-indicator{width:6px;height:6px;border-radius:50%;background:var(--g7-accent);display:none}
.note-header .note-indicator.has-note{display:block}
.note-toggle-icon{font-size:10px;color:var(--g7-text-5);transition:transform .15s}
.note-body{margin-top:8px;display:none}
.note-body.open{display:block}
.note-textarea{width:100%;background:var(--g7-bg-3);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:6px;padding:8px 10px;font-size:11px;resize:vertical;min-height:70px;outline:none;font-family:inherit;line-height:1.5}
.note-textarea:focus{border-color:var(--g7-accent)}
.note-actions{display:flex;align-items:center;gap:6px;margin-top:6px}
.note-save-btn{background:var(--g7-accent);color:#000;border:none;border-radius:5px;padding:4px 10px;font-size:10px;font-weight:700;cursor:pointer;transition:opacity .15s}
.note-save-btn:hover{opacity:.85}
.note-hide-btn{background:none;border:1px solid var(--g7-border-3);color:var(--g7-text-3);border-radius:5px;padding:4px 8px;font-size:10px;cursor:pointer;transition:all .15s}
.note-hide-btn:hover{color:var(--g7-text)}
.note-saved{font-size:10px;color:#4ade80;display:none}
.note-hidden-mask{background:var(--g7-bg-3);border:1px solid var(--g7-border-3);border-radius:6px;padding:8px 10px;font-size:11px;color:var(--g7-text-5);cursor:pointer;display:none;letter-spacing:.15em}

/* SEARCH HIGHLIGHT */
.search-hl{background:rgba(var(--g7-accent-rgb),.22);border-radius:3px;color:#ffb27a;font-weight:600;padding:0 1px}

/* ══ Biblioteca v3 ══ */
body{background:var(--g7-bg,var(--g7-bg));font-family:var(--g7-font,'Inter',sans-serif)}
.lb{max-width:1240px;margin:0 auto;padding:36px 32px 72px}
.lb-head{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;flex-wrap:wrap}
.lb-head h1{font-size:30px;font-weight:700;letter-spacing:-.035em;line-height:1.1}
.lb-head p{font-size:13.5px;color:var(--g7-text-3);margin-top:6px}
.lb-actions{display:flex;gap:8px;flex-wrap:wrap}
.lb-btn{display:inline-flex;align-items:center;gap:7px;height:36px;padding:0 14px;border-radius:10px;font:600 12.5px var(--g7-font,inherit);cursor:pointer;border:1px solid var(--g7-border-3);background:var(--g7-bg-3);color:var(--g7-text);transition:border-color .15s,background .15s,color .15s}
.lb-btn:hover{border-color:var(--g7-border-3);color:var(--g7-text)}
.lb-btn.primary{background:var(--brand);border-color:var(--brand);color:#0a0a0a}
.lb-btn.primary:hover{filter:brightness(1.08)}
.lb-newcat{display:none;gap:8px;margin-top:16px;max-width:520px}
.lb-newcat.open{display:flex}
.lb-newcat input{flex:1;height:38px;background:var(--g7-bg-3);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:10px;padding:0 12px;font:500 13px inherit;outline:none}
.lb-newcat input:focus{border-color:var(--brand)}
.lb-bar{position:sticky;top:56px;z-index:30;margin:24px -32px 0;padding:12px 32px;background:color-mix(in srgb,var(--g7-bg,var(--g7-bg)) 88%,transparent);backdrop-filter:blur(12px);border-bottom:1px solid var(--g7-border-2);display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.lb-search{display:flex;align-items:center;gap:9px;height:38px;width:min(320px,100%);padding:0 12px;background:var(--g7-bg-3);border:1px solid var(--g7-border-3);border-radius:10px;transition:border-color .15s,box-shadow .15s}
.lb-search:focus-within{border-color:color-mix(in srgb,var(--brand) 60%,transparent);box-shadow:0 0 0 4px color-mix(in srgb,var(--brand) 14%,transparent)}
.lb-search i{color:var(--g7-text-4);font-size:12px}
.lb-search input,.lb-search #searchInput{flex:1;min-width:0;width:auto;padding:0;border-radius:0;background:none;border:none;outline:none;color:var(--g7-text);font:500 13px inherit;font-family:inherit}
.lb-search kbd{font:600 10px var(--g7-font-mono,monospace);color:var(--g7-text-4);border:1px solid var(--g7-border-3);border-radius:5px;padding:1px 5px}
.lb-select{height:38px;background:var(--g7-bg-3);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:10px;padding:0 10px;font:500 12.5px inherit;font-family:inherit;outline:none;cursor:pointer}
.lb-tags{display:flex;gap:6px;overflow-x:auto;scrollbar-width:none;flex:1;min-width:0;mask-image:linear-gradient(90deg,#000 92%,transparent)}
.lb-tags::-webkit-scrollbar{display:none}
.tag-pill{flex-shrink:0;height:30px;padding:0 12px;border:1px solid var(--g7-border-3);border-radius:8px;font:500 12px inherit;font-family:inherit;color:var(--g7-text-2);cursor:pointer;background:none;text-transform:none;letter-spacing:0;transition:all .15s}
.tag-pill:hover{border-color:var(--g7-border-3);color:var(--g7-text)}
.tag-pill.active{background:#fafafa;color:#0a0a0a;border-color:#fafafa}
.lb-fav{display:inline-flex;align-items:center;gap:7px;height:38px;padding:0 13px;border-radius:10px;border:1px solid var(--g7-border-3);background:none;color:var(--g7-text);font:500 12.5px inherit;font-family:inherit;cursor:pointer;transition:all .15s;flex-shrink:0}
.lb-fav i{color:var(--g7-text-3)}
.lb-fav.on{border-color:rgba(239,68,68,.45);background:rgba(239,68,68,.1);color:#fecaca}
.lb-fav.on i{color:#ef4444}
.lb-clear{height:38px;padding:0 10px;background:none;border:none;color:var(--g7-text-3);font:500 12.5px inherit;font-family:inherit;cursor:pointer}
.lb-clear:hover{color:var(--g7-text)}
#results-count{font-size:12px;color:var(--g7-text-3);white-space:nowrap}

.section{margin:34px 0 0}
.section-header{display:flex;align-items:center;gap:10px;padding:0 0 12px;margin:0;border:none}
.section-title{font-size:16px;font-weight:600;letter-spacing:-.015em;text-transform:none;color:var(--g7-text);flex:none}
.section-count{font:500 12px inherit;color:var(--g7-text-4);flex:1}
.toggle-section{width:30px;height:30px;border-radius:8px;color:var(--g7-text-3)}
.toggle-section:hover{background:var(--g7-bg-4);color:var(--g7-text)}
.toggle-section i{transition:transform .2s}
.add-item-btn{height:30px;border-radius:8px;font-size:11.5px;font-weight:600;padding:0 11px}
.del-cat-btn{height:30px;border-radius:8px}
.cards-grid{grid-template-columns:repeat(auto-fill,minmax(232px,1fr));gap:14px}
.lb-card{position:relative;display:flex;flex-direction:column;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:16px;overflow:hidden;transition:border-color .2s,transform .2s cubic-bezier(.2,.8,.2,1),box-shadow .2s}
.lb-card:hover{border-color:var(--g7-border-3);transform:translateY(-2px);box-shadow:0 18px 40px rgba(0,0,0,.4)}
.lb-html{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:8px;padding:8px 10px;border:1px dashed var(--g7-border-3);border-radius:8px}
.lb-html.on{border-style:solid;border-color:var(--brand);background:rgba(var(--g7-accent-rgb),.06)}
.lb-html-pick{display:inline-flex;align-items:center;gap:7px;cursor:pointer;font-size:12px;font-weight:600;color:var(--g7-text-2);position:relative}
.lb-html-pick:hover{color:var(--brand)}
.lb-html-pick input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%}
.lb-html-x{border:none;background:none;color:var(--g7-text-4);cursor:pointer;font-size:12px}
.lb-html small{flex-basis:100%;font-size:10.5px;color:var(--g7-text-4)}
.lb-cover{display:block;aspect-ratio:16/9;background:var(--g7-bg-4);overflow:hidden;position:relative}
.lb-cover img{position:relative;z-index:1;width:100%;height:100%;object-fit:cover;transition:transform .4s cubic-bezier(.2,.8,.2,1)}
.lb-card:hover .lb-cover img{transform:scale(1.03)}
.lb-cover.noimg .ph{display:flex}
.lb-cover img.small{width:64px;height:64px;object-fit:contain;position:absolute;inset:0;margin:auto;border-radius:14px}
.lb-cover:has(img.small){background:radial-gradient(circle at 50% 40%,var(--g7-bg-5),var(--g7-bg-3))}
.lb-cover .ph{position:absolute;inset:0;display:none;align-items:center;justify-content:center;color:var(--g7-text-5);font-size:26px}
.lb-fav-btn{position:absolute;top:10px;right:10px;width:32px;height:32px;border-radius:10px;border:none;cursor:pointer;background:color-mix(in srgb, var(--g7-bg-2) 60%, transparent);backdrop-filter:blur(6px);color:var(--g7-text);display:flex;align-items:center;justify-content:center;font-size:13px;transition:background .15s,transform .1s}
.lb-fav-btn:hover{background:color-mix(in srgb, var(--g7-bg-2) 85%, transparent)}
.lb-fav-btn:active{transform:scale(.92)}
.lb-fav-btn.on{color:#ef4444}
.lb-body{display:block;padding:14px 15px 12px;text-decoration:none;color:inherit;flex:1}
.lb-domain{display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--g7-text-3);margin-bottom:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lb-domain i{font-size:10px}
.card-name{font-size:14px;font-weight:600;letter-spacing:-.01em;line-height:1.3;color:var(--g7-text)}
.lb-desc{font-size:12.5px;color:var(--g7-text-3);line-height:1.45;margin-top:5px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.lb-pub{display:flex;gap:7px;margin-top:10px;padding:8px 10px;border-radius:9px;background:rgba(96,165,250,.07);border:1px solid rgba(96,165,250,.16);font-size:12px;color:#bfdbfe;line-height:1.45}
.lb-pub i{color:#60a5fa;font-size:11px;margin-top:2px}
.lb-foot{display:flex;align-items:center;gap:6px;padding:0 10px 10px 15px}
.lb-tag{font-size:11px;font-weight:500;padding:2px 8px;border-radius:6px;background:var(--g7-bg-4);color:var(--g7-text-2);white-space:nowrap}
.lb-foot .sp{flex:1}
.lb-ic{width:30px;height:30px;border-radius:8px;border:none;background:none;color:var(--g7-text-3);cursor:pointer;font-size:12px;position:relative;transition:background .15s,color .15s}
.lb-ic:hover{background:var(--g7-bg-4);color:var(--g7-text)}
.lb-ic.del:hover{color:#f87171}
.lb-ic .dot{position:absolute;top:6px;right:6px;width:6px;height:6px;border-radius:50%;background:var(--brand)}
.lb-ic.has-pub{color:#93c5fd}
.lb-ic.has-pub .dot{background:#60a5fa}
.lb-empty-cat{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px;border:1px dashed var(--g7-border-3);border-radius:14px;color:var(--g7-text-3);font-size:12.5px}
.no-results{padding:70px 20px;color:var(--g7-text-3)}
@media (max-width:700px){.lb{padding:24px 16px 56px}.lb-bar{margin:20px -16px 0;padding:10px 16px}.lb-search{width:100%}.lb-tags{order:5;flex-basis:100%}.cards-grid{grid-template-columns:1fr 1fr;gap:10px}.lb-desc,.lb-domain{display:none}.lb-body{padding:11px 12px}}
@media (max-width:420px){.cards-grid{grid-template-columns:1fr}}
@media (prefers-reduced-motion:reduce){.lb-card,.lb-cover img{transition:none}}
.lb-prev-btn:hover{border-color:#ff6600!important;color:#ff6600!important}
</style>
<link rel="stylesheet" href="/toolz/assets/biblioteca-look.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/biblioteca-look.css') ?>">
</head>
<body>
<?php include __DIR__ . '/../topbar.php'; ?>

<main class="lb" id="main-content">
  <header class="lb-head">
    <div>
      <h1>Biblioteca</h1>
      <p id="lb-summary">Recursos y enlaces del equipo</p>
    </div>
    <?php if ($isAdmin || $canCatalog): ?>
    <div class="lb-actions">
      <?php if ($canCatalog): ?><button class="lb-btn" type="button" id="cat-open" title="Herramientas listas para usar que ofrece Cronos"><i class="fa-solid fa-toolbox"></i> Catálogo Cronos</button><?php endif; ?>
      <?php if ($isAdmin): ?><button class="lb-btn" type="button" data-on-click="lbToggleNewCat"><i class="fa-solid fa-folder-plus"></i> Nueva categoría</button><?php endif; ?>
    </div>
    <?php endif; ?>
  </header>
  <?php if ($isAdmin): ?>
  <div class="lb-newcat" id="lb-newcat">
    <input type="text" id="new-cat-input" placeholder="Nombre de la categoría" data-on-keydown="lbNewCatKey" data-keydown-1="event">
    <button class="lb-btn primary" type="button" data-on-click="addCategory">Crear</button>
  </div>
  <?php endif; ?>

  <div class="lb-bar">
    <label class="lb-search">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" id="searchInput" placeholder="Buscar por nombre, etiqueta o sitio" data-on-input="applyFilters" data-on-keydown="lbSearchKey" data-keydown-1="event" data-keydown-2="this">
      <kbd>/</kbd>
    </label>
    <select id="filt-cat" class="lb-select" data-on-change="applyFilters">
      <option value="">Todas las categorías</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button id="fav-filter-btn" class="lb-fav" type="button" data-on-click="toggleFavFilter"><i class="fa-solid fa-heart"></i> Favoritos <span id="fav-count"></span></button>
    <div class="lb-tags" id="tag-filters">
      <?php foreach ($allTags as $tag): ?>
        <button class="tag-pill" type="button" data-tag="<?= $tag ?>" data-on-click="toggleTagFilter" data-click-1="s:<?= $tag ?>" data-click-2="this"><?= htmlspecialchars(mb_convert_case(mb_strtolower($tag), MB_CASE_TITLE)) ?></button>
      <?php endforeach; ?>
    </div>
    <button class="lb-clear" type="button" data-on-click="clearFilters" id="lb-clear" style="display:none">Limpiar</button>
    <span id="results-count"></span>
  </div>

  <div id="categories-container"></div>
  <div id="no-results" class="no-results hidden">
    <i class="fa-solid fa-magnifying-glass" style="font-size:24px;display:block;margin-bottom:12px;opacity:.2"></i>
    No hay recursos que coincidan. Prueba con otra palabra o limpia los filtros.
  </div>
</main>

<!-- NOTAS -->
<div class="modal-overlay hidden" id="notes-modal">
  <div class="modal-card" style="max-width:440px">
    <div class="modal-title"><span id="nm-title">Notas</span><button class="modal-close" data-on-click="closeNotes">✕</button></div>
    <input type="hidden" id="nm-id">
    <div class="m-group" id="nm-pub-group">
      <label>Nota del equipo <span style="text-transform:none;letter-spacing:0;font-weight:400;color:var(--g7-text-4)">· la ve quien tiene el permiso "Ver notas compartidas"</span></label>
      <textarea id="nm-pub" placeholder="Ej: usar la versión 2024"></textarea>
      <div id="nm-pub-locked" style="display:none;font-size:12.5px;line-height:1.5;color:var(--g7-text-3);background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:9px;padding:10px 12px"><i class="fa-solid fa-lock" style="margin-right:6px;color:var(--g7-text-4)"></i>Este recurso tiene una nota con datos de acceso. Si la necesitas, pídele a un admin el permiso "Ver notas compartidas" de la Biblioteca.</div>
    </div>
    <div class="m-group">
      <label>Mi nota privada <span style="text-transform:none;letter-spacing:0;font-weight:400;color:var(--g7-text-4)">· solo tú la ves</span></label>
      <textarea id="nm-priv" placeholder="Usuario, contraseña de prueba, recordatorios…"></textarea>
    </div>
    <button class="m-submit" data-on-click="saveNotes" id="nm-save">Guardar</button>
  </div>
</div>

<!-- ADD LINK MODAL -->
<div class="modal-overlay hidden" id="add-link-modal">
  <div class="modal-card">
    <div class="modal-title">
      <span><i class="fa-solid fa-plus-circle" style="color:var(--brand);margin-right:8px"></i>Nuevo enlace</span>
      <button class="modal-close" data-on-click="closeAddLink">✕</button>
    </div>
    <input type="hidden" id="ml-cat-id">
    <div class="m-group">
      <label>Enlace</label>
      <div style="display:flex;gap:7px">
        <input type="text" id="ml-url" placeholder="https://..." style="flex:1" data-on-input="clearPreview">
        <button type="button" data-on-click="fetchPreview" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-2);border-radius:7px;padding:0 12px;font-size:11px;font-weight:600;cursor:pointer;white-space:nowrap;transition:all .15s" class="lb-prev-btn">
          <i class="fa-solid fa-wand-magic-sparkles" style="margin-right:5px"></i>Capturar
        </button>
      </div>
      <div class="lb-html" id="ml-html-box">
        <label class="lb-html-pick"><i class="fa-solid fa-file-code"></i> <span id="ml-html-name">o sube un archivo HTML</span><input type="file" id="ml-html" accept=".html,.htm,text/html" data-on-change="pickHtml" data-change-1="s:ml"></label>
        <button type="button" class="lb-html-x" id="ml-html-x" data-on-click="clearHtml" data-click-1="s:ml" hidden aria-label="Quitar el HTML">✕</button>
        <small>Para guías y páginas propias: queda guardada en Toolz y se abre con login.</small>
      </div>
      <div id="preview-result" style="display:none;margin-top:8px;background:var(--g7-bg-2);border:1px solid var(--g7-border-3);border-radius:7px;padding:10px;gap:10px;align-items:center">
        <img id="preview-img-thumb" style="width:64px;height:44px;object-fit:cover;border-radius:5px;flex-shrink:0;background:var(--g7-bg-4)">
        <div style="flex:1;min-width:0">
          <div id="preview-img-url" style="font-size:10px;color:var(--g7-text-4);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></div>
          <div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap">
            <button type="button" data-on-click="useOgImage" style="font-size:10px;font-weight:600;background:rgba(var(--g7-accent-rgb),.1);color:var(--g7-accent);border:1px solid rgba(var(--g7-accent-rgb),.3);border-radius:5px;padding:3px 8px;cursor:pointer">Usar esta imagen</button>
            <button type="button" data-on-click="useFavicon" id="use-fav-btn" style="font-size:10px;font-weight:600;background:var(--g7-bg-5);color:var(--g7-text-3);border:1px solid var(--g7-border-3);border-radius:5px;padding:3px 8px;cursor:pointer">Usar favicon</button>
            <button type="button" data-on-click="clearPreview" style="font-size:10px;color:var(--g7-text-4);background:none;border:none;cursor:pointer;padding:3px 4px">x Limpiar</button>
          </div>
        </div>
      </div>
      <div id="preview-error" style="display:none;font-size:11px;color:#f87171;margin-top:5px"></div>
      <div id="preview-loading" style="display:none;font-size:11px;color:var(--g7-text-3);margin-top:5px"><i class="fa-solid fa-spinner fa-spin" style="margin-right:5px"></i>Obteniendo preview...</div>
    </div>
    <div class="m-group">
      <label>Portada <span style="color:var(--g7-text-4);font-weight:400;text-transform:none;letter-spacing:0">(o captura desde la URL)</span></label>
      <div id="img-selected-preview" style="display:none;margin-bottom:6px">
        <img id="img-selected-thumb" style="width:100%;height:100px;object-fit:cover;border-radius:6px;border:1px solid var(--g7-border-3)">
        <button type="button" data-on-click="clearImgSelection" style="font-size:10px;color:var(--g7-text-4);background:none;border:none;cursor:pointer;margin-top:4px">x Quitar imagen</button>
      </div>
      <input type="file" id="ml-img" accept="image/*" data-on-change="previewSelectedFile">
      <input type="hidden" id="ml-img-url" value="">
    </div>
    <div class="m-group"><label>Nombre</label><input type="text" id="ml-name" placeholder="Nombre del recurso"></div>
    <div class="m-group"><label>Descripción</label><textarea id="ml-desc" placeholder="Descripción breve..."></textarea></div>
    <div class="m-group">
      <label>Etiquetas</label>
      <div class="tags-selector" id="tags-selector">
        <?php foreach ($allTags as $tag): ?>
          <button type="button" class="tag-opt" data-tag="<?= $tag ?>" data-on-click="toggleTag" data-click-1="this"><?= $tag ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <button class="m-submit" data-on-click="submitLink"><i class="fa-solid fa-floppy-disk" style="margin-right:6px"></i>Guardar enlace</button>
  </div>
</div>

<!-- EDIT LINK MODAL -->
<div class="modal-overlay hidden" id="edit-link-modal">
  <div class="modal-card">
    <div class="modal-title">
      <span><i class="fa-solid fa-pen" style="color:var(--brand);margin-right:8px"></i>Editar enlace</span>
      <button class="modal-close" data-on-click="closeEditLink">✕</button>
    </div>
    <input type="hidden" id="el-id">
    <input type="hidden" id="el-cat-id">
    <div class="m-group">
      <label>Enlace</label>
      <div style="display:flex;gap:7px">
        <input type="text" id="el-url" placeholder="https://..." style="flex:1" data-on-input="clearEditPreview">
        <button type="button" data-on-click="fetchEditPreview" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-2);border-radius:7px;padding:0 12px;font-size:11px;font-weight:600;cursor:pointer;white-space:nowrap" class="lb-prev-btn">
          <i class="fa-solid fa-wand-magic-sparkles" style="margin-right:5px"></i>Capturar
        </button>
      </div>
      <div class="lb-html" id="el-html-box">
        <label class="lb-html-pick"><i class="fa-solid fa-file-code"></i> <span id="el-html-name">o sube un archivo HTML</span><input type="file" id="el-html" accept=".html,.htm,text/html" data-on-change="pickHtml" data-change-1="s:el"></label>
        <button type="button" class="lb-html-x" id="el-html-x" data-on-click="clearHtml" data-click-1="s:el" hidden aria-label="Quitar el HTML">✕</button>
        <small>Para guías y páginas propias: queda guardada en Toolz y se abre con login.</small>
      </div>
      <div id="edit-preview-result" style="display:none;margin-top:8px;background:var(--g7-bg-2);border:1px solid var(--g7-border-3);border-radius:7px;padding:10px;gap:10px;align-items:center">
        <img id="edit-preview-thumb" style="width:64px;height:44px;object-fit:cover;border-radius:5px;flex-shrink:0;background:var(--g7-bg-4)">
        <div style="flex:1;min-width:0">
          <div id="edit-preview-url" style="font-size:10px;color:var(--g7-text-4);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></div>
          <div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap">
            <button type="button" data-on-click="useEditOgImage" style="font-size:10px;font-weight:600;background:rgba(var(--g7-accent-rgb),.1);color:var(--g7-accent);border:1px solid rgba(var(--g7-accent-rgb),.3);border-radius:5px;padding:3px 8px;cursor:pointer">Usar esta imagen</button>
            <button type="button" data-on-click="useEditFavicon" id="edit-fav-btn" style="font-size:10px;font-weight:600;background:var(--g7-bg-5);color:var(--g7-text-3);border:1px solid var(--g7-border-3);border-radius:5px;padding:3px 8px;cursor:pointer">Usar favicon</button>
            <button type="button" data-on-click="clearEditPreview" style="font-size:10px;color:var(--g7-text-4);background:none;border:none;cursor:pointer">x Limpiar</button>
          </div>
        </div>
      </div>
      <div id="edit-preview-loading" style="display:none;font-size:11px;color:var(--g7-text-3);margin-top:5px"><i class="fa-solid fa-spinner fa-spin" style="margin-right:5px"></i>Obteniendo preview...</div>
    </div>
    <div class="m-group">
      <label>Portada actual</label>
      <div id="el-img-current" style="margin-bottom:6px">
        <img id="el-img-thumb" style="width:100%;height:90px;object-fit:cover;border-radius:6px;border:1px solid var(--g7-border-3);display:none">
        <span id="el-img-none" style="font-size:11px;color:var(--g7-text-4)">Sin imagen</span>
      </div>
      <label style="margin-top:4px">Nueva imagen (opcional)</label>
      <input type="file" id="el-img" accept="image/*" data-on-change="previewEditFile">
      <input type="hidden" id="el-img-url" value="">
    </div>
    <div class="m-group"><label>Nombre</label><input type="text" id="el-name" placeholder="Nombre del recurso"></div>
    <div class="m-group"><label>Descripción</label><textarea id="el-desc" placeholder="Descripción breve..."></textarea></div>
    <div class="m-group">
      <label>Categoría</label>
      <select id="el-cat">
        <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="m-group">
      <label>Etiquetas</label>
      <div class="tags-selector" id="edit-tags-selector">
        <?php foreach ($allTags as $tag): ?>
          <button type="button" class="tag-opt edit-tag-opt" data-tag="<?= $tag ?>" data-on-click="lbToggleSel" data-click-1="this"><?= $tag ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <button class="m-submit" data-on-click="submitEditLink"><i class="fa-solid fa-floppy-disk" style="margin-right:6px"></i>Guardar cambios</button>
  </div>
</div>

<script type="application/json" id="links-data"><?= json_encode(['DATA' => json_decode($dataJSON), 'IS_ADMIN' => (bool)$isAdmin, 'ALL_TAGS' => $allTags], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="links.js?v=<?= (int)@filemtime(__DIR__ . '/links.js') ?>"></script>
<?php if ($canCatalog): ?><link rel="stylesheet" href="catalogo.css?v=<?= (int)@filemtime(__DIR__ . '/catalogo.css') ?>"><script src="catalogo.js?v=<?= (int)@filemtime(__DIR__ . '/catalogo.js') ?>" defer></script><?php endif; ?>
<script src="/toolz/assets/biblioteca-look.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/biblioteca-look.js') ?>"></script>
</body>
</html>