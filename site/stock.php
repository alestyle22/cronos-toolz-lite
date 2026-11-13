<?php
require_once __DIR__ . '/auth.php';
if (g7_site_has('materiales')) { require __DIR__ . '/stock_materiales.php'; exit; }   // Stock como carpetas por cliente
$currentUser = requireLogin();
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo (SEC-24)
$isAdmin     = ($currentUser['role'] === 'admin');
$db          = getDB();
$me          = $currentUser['id'];

$UPLOAD_DIR = __DIR__ . '/stock_files/';
$THUMB_DIR  = __DIR__ . '/stock_files/thumbs/';
$STOCK_URL  = '/toolz/stock_files/';
$MAX_SIZE   = 200 * 1024 * 1024; // 200MB

if (!is_dir($UPLOAD_DIR)) mkdir($UPLOAD_DIR, 0755, true);
if (!is_dir($THUMB_DIR))  mkdir($THUMB_DIR,  0755, true);

$ALLOWED = [
    'image/jpeg','image/png','image/gif','image/webp',
    'video/mp4','video/webm','video/quicktime',
];

function makeSlug($s){ return strtolower(preg_replace('/[^a-z0-9]+/i','-',trim($s))); }
function fmtSz($b){ if($b>=1073741824)return round($b/1073741824,1).' GB';if($b>=1048576)return round($b/1048576,1).' MB';if($b>=1024)return round($b/1024).' KB';return $b.' B'; }
function makeThumb($src, $dest, $w=400){
    $mime=mime_content_type($src);
    $img=null;
    if($mime==='image/jpeg')$img=imagecreatefromjpeg($src);
    elseif($mime==='image/png')$img=imagecreatefrompng($src);
    elseif($mime==='image/gif')$img=imagecreatefromgif($src);
    elseif($mime==='image/webp')$img=imagecreatefromwebp($src);
    if(!$img)return false;
    $ow=imagesx($img);$oh=imagesy($img);
    $h=round($oh*$w/$ow);
    $thumb=imagecreatetruecolor($w,$h);
    // Preserve transparency
    imagealphablending($thumb,false);imagesavealpha($thumb,true);
    imagecopyresampled($thumb,$img,0,0,0,0,$w,$h,$ow,$oh);
    imagewebp($thumb,$dest,80);
    imagedestroy($img);imagedestroy($thumb);
    return true;
}

// ── API ───────────────────────────────────────────────────
$action = '';   // las acciones de video y el paginado se preguntan afuera de este bloque
if ($_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $input  = json_decode(file_get_contents('php://input'),true)??[];
    $action = $input['action']??($_POST['action']??'');

    // ── UPLOAD ──────────────────────────────────────────
    if ($action==='upload' && isset($_FILES['file'])) {
        $file = $_FILES['file'];
        if ($file['error']!==0||$file['size']>$MAX_SIZE){echo json_encode(['error'=>'Error al subir']);exit;}
        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime,$ALLOWED)){echo json_encode(['error'=>'Tipo no permitido']);exit;}
        $ext   = g7_upload_ext_or_die($file['name'], array_merge(G7_EXT_IMG, ['svg'], G7_EXT_MEDIA, G7_EXT_DOCS));
        $fname = time().'_'.bin2hex(random_bytes(5)).'.'.$ext;
        move_uploaded_file($file['tmp_name'],$UPLOAD_DIR.$fname);
        $fileUrl  = $STOCK_URL.$fname;
        $thumbUrl = null;
        $type = str_starts_with($mime,'video/')?'video':($mime==='image/gif'?'gif':'image');
        $w=$h=null;
        if ($type==='image'||$type==='gif') {
            $sz = @getimagesize($UPLOAD_DIR.$fname);
            if($sz){$w=$sz[0];$h=$sz[1];}
            $tname='thumbs/'.pathinfo($fname,PATHINFO_FILENAME).'.webp';
            if(makeThumb($UPLOAD_DIR.$fname,$THUMB_DIR.pathinfo($fname,PATHINFO_FILENAME).'.webp'))
                $thumbUrl=$STOCK_URL.$tname;
        }
        $catId    = $_POST['category_id']&&$_POST['category_id']!==''?(int)$_POST['category_id']:null;
        $title    = trim($_POST['title']??$file['name']);
        $desc     = trim($_POST['description']??'');
        $tagsRaw  = trim($_POST['tags']??'');
        $folderId2 = $_POST['folder_id']&&$_POST['folder_id']!==''?(int)$_POST['folder_id']:null;
        $db->prepare('INSERT INTO g7_stock_items (category_id,folder_id,owner_id,title,description,file_url,thumb_url,file_type,file_size,mime_type,width,height) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$catId,$folderId2,$me,$title,$desc,$fileUrl,$thumbUrl,$type,$file['size'],$mime,$w,$h]);
        $itemId=$db->lastInsertId();
        // Tags
        if($tagsRaw){
            foreach(array_slice(explode(',',strtolower($tagsRaw)),0,20) as $tag){
                $tag=trim($tag);if(!$tag)continue;
                $db->prepare('INSERT IGNORE INTO g7_stock_tags (name) VALUES (?)')->execute([$tag]);
                $tid=$db->prepare('SELECT id FROM g7_stock_tags WHERE name=?');$tid->execute([$tag]);$tid=$tid->fetchColumn();
                $db->prepare('INSERT IGNORE INTO g7_stock_item_tags (item_id,tag_id) VALUES (?,?)')->execute([$itemId,$tid]);
            }
        }
        echo json_encode(['status'=>'success','id'=>$itemId,'thumb'=>$thumbUrl,'url'=>$fileUrl]); exit;
    }

    // ── CREATE CATEGORY ──────────────────────────────
    if ($action==='create_category') {
        $name=trim($input['name']??'');if(!$name){echo json_encode(['error'=>'Nombre requerido']);exit;}
        $slug=makeSlug($name);
        $db->prepare('INSERT IGNORE INTO g7_stock_categories (name,slug) VALUES (?,?)')->execute([$name,$slug]);
        echo json_encode(['status'=>'success','id'=>$db->lastInsertId()]); exit;
    }

    // ── DELETE CATEGORY ──────────────────────────────
    if ($action==='delete_category' && $isAdmin) {
        $db->prepare('DELETE FROM g7_stock_categories WHERE id=?')->execute([(int)($input['id']??0)]);
        echo json_encode(['status'=>'success']); exit;
    }

    // ── UPDATE ITEM ───────────────────────────────────
    if ($action==='update_item') {
        $id=(int)($input['id']??0);
        $row=$db->prepare('SELECT owner_id FROM g7_stock_items WHERE id=?');$row->execute([$id]);$r=$row->fetch();
        if(!$r||(!$isAdmin&&$r['owner_id']!=$me)){echo json_encode(['error'=>'forbidden']);exit;}
        $title=trim($input['title']??'');
        $desc=trim($input['description']??'');
        $catId=isset($input['category_id'])&&$input['category_id']!==''?(int)$input['category_id']:null;
        $db->prepare('UPDATE g7_stock_items SET title=?,description=?,category_id=? WHERE id=?')->execute([$title,$desc,$catId,$id]);
        // Re-tag
        $db->prepare('DELETE FROM g7_stock_item_tags WHERE item_id=?')->execute([$id]);
        foreach(array_slice(explode(',',strtolower($input['tags']??'')),0,20) as $tag){
            $tag=trim($tag);if(!$tag)continue;
            $db->prepare('INSERT IGNORE INTO g7_stock_tags (name) VALUES (?)')->execute([$tag]);
            $tid=$db->prepare('SELECT id FROM g7_stock_tags WHERE name=?');$tid->execute([$tag]);$tid=$tid->fetchColumn();
            $db->prepare('INSERT IGNORE INTO g7_stock_item_tags (item_id,tag_id) VALUES (?,?)')->execute([$id,$tid]);
        }
        echo json_encode(['status'=>'success']); exit;
    }

    // ── DELETE ITEM ───────────────────────────────────
    if ($action==='delete_item') {
        $id=(int)($input['id']??0);
        $row=$db->prepare('SELECT owner_id,file_url,thumb_url FROM g7_stock_items WHERE id=?');$row->execute([$id]);$r=$row->fetch();
        if(!$r||(!$isAdmin&&$r['owner_id']!=$me)){echo json_encode(['error'=>'forbidden']);exit;}
        // Delete files
        foreach(['file_url','thumb_url'] as $k){if($r[$k])@unlink(__DIR__.'/'.ltrim(str_replace('/toolz/','',$r[$k]),'/')); }
        $db->prepare('DELETE FROM g7_stock_item_tags WHERE item_id=?')->execute([$id]);
        $db->prepare('DELETE FROM g7_stock_favorites WHERE item_id=?')->execute([$id]);
        $db->prepare('DELETE FROM g7_stock_items WHERE id=?')->execute([$id]);
        echo json_encode(['status'=>'success']); exit;
    }

    // ── TOGGLE FAVORITE ───────────────────────────────
    if ($action==='toggle_fav') {
        $id=(int)($input['id']??0);
        $chk=$db->prepare('SELECT 1 FROM g7_stock_favorites WHERE user_id=? AND item_id=?');$chk->execute([$me,$id]);
        if($chk->fetchColumn()){$db->prepare('DELETE FROM g7_stock_favorites WHERE user_id=? AND item_id=?')->execute([$me,$id]);$fav=false;}
        else{$db->prepare('INSERT IGNORE INTO g7_stock_favorites (user_id,item_id) VALUES (?,?)')->execute([$me,$id]);$fav=true;}
        echo json_encode(['status'=>'success','fav'=>$fav]); exit;
    }

    // ── INCREMENT DOWNLOAD ────────────────────────────
    if ($action==='download') {
        $id=(int)($input['id']??0);
        $db->prepare('UPDATE g7_stock_items SET downloads=downloads+1 WHERE id=?')->execute([$id]);
        echo json_encode(['status'=>'success']); exit;
    }

    // ── CREATE FOLDER ─────────────────────────────────
    if ($action==='create_folder') {
        $name=trim($input['name']??'');if(!$name){echo json_encode(['error'=>'Nombre requerido']);exit;}
        $catId=isset($input['category_id'])&&$input['category_id']!==''?(int)$input['category_id']:null;
        $parentId=isset($input['parent_id'])&&$input['parent_id']!==''?(int)$input['parent_id']:null;
        $db->prepare('INSERT INTO g7_stock_folders (category_id,parent_id,name,owner_id) VALUES (?,?,?,?)')->execute([$catId,$parentId,$name,$me]);
        echo json_encode(['status'=>'success','id'=>$db->lastInsertId()]); exit;
    }

    // ── RENAME FOLDER ─────────────────────────────────
    if ($action==='rename_folder') {
        $id=(int)($input['id']??0);$name=trim($input['name']??'');
        $row=$db->prepare('SELECT owner_id FROM g7_stock_folders WHERE id=?');$row->execute([$id]);$r=$row->fetch();
        if(!$r||(!$isAdmin&&$r['owner_id']!=$me)){echo json_encode(['error'=>'forbidden']);exit;}
        $db->prepare('UPDATE g7_stock_folders SET name=? WHERE id=?')->execute([$name,$id]);
        echo json_encode(['status'=>'success']); exit;
    }

    // ── DELETE FOLDER ─────────────────────────────────
    if ($action==='delete_folder') {
        $id=(int)($input['id']??0);
        $row=$db->prepare('SELECT owner_id FROM g7_stock_folders WHERE id=?');$row->execute([$id]);$r=$row->fetch();
        if(!$r||(!$isAdmin&&$r['owner_id']!=$me)){echo json_encode(['error'=>'forbidden']);exit;}
        // Move items to parent folder
        $db->prepare('UPDATE g7_stock_items SET folder_id=NULL WHERE folder_id=?')->execute([$id]);
        // Move subfolders to parent
        $db->prepare('UPDATE g7_stock_folders SET parent_id=NULL WHERE parent_id=?')->execute([$id]);
        $db->prepare('DELETE FROM g7_stock_folders WHERE id=?')->execute([$id]);
        echo json_encode(['status'=>'success']); exit;
    }

    // ── GET FOLDERS ───────────────────────────────────
    if ($action==='get_folders') {
        $catId=isset($input['category_id'])&&$input['category_id']!==''?(int)$input['category_id']:null;
        $parentId=isset($input['parent_id'])&&$input['parent_id']!==''?(int)$input['parent_id']:null;
        $pCond=$parentId?'parent_id='.$parentId:'parent_id IS NULL';
        $cCond=$catId?'category_id='.$catId:'category_id IS NULL';
        $rows=$db->query("SELECT f.*,u.display_name,u.username,(SELECT COUNT(*) FROM g7_stock_items i WHERE i.folder_id=f.id) as item_cnt,(SELECT COUNT(*) FROM g7_stock_folders sf WHERE sf.parent_id=f.id) as sub_cnt FROM g7_stock_folders f JOIN g7_users u ON u.id=f.owner_id WHERE $cCond AND $pCond ORDER BY f.name")->fetchAll();
        echo json_encode(['folders'=>$rows]); exit;
    }

}

// ── VIDEO THUMBNAIL ACTIONS ───────────────────────────────
if ($action === 'get_videos_without_thumb') {
    $q = $db->query("
        SELECT id, title, file_url FROM g7_stock_items
        WHERE (file_type='video' OR mime_type LIKE 'video/%')
        AND (thumb_url IS NULL OR thumb_url = '' OR thumb_url = file_url)
        ORDER BY id ASC
        LIMIT 500
    ");
    echo json_encode(['ok'=>true, 'videos'=>$q->fetchAll()]);
    exit;
}

if ($action === 'save_video_thumb') {
    $id    = (int)($input['id'] ?? 0);
    $thumb = $input['thumb'] ?? '';
    if (!$id || !$thumb) { echo json_encode(['ok'=>false,'error'=>'Datos inválidos']); exit; }

    // Decode base64
    if (!preg_match('/^data:image\/(\w+);base64,/', $thumb, $m)) {
        echo json_encode(['ok'=>false,'error'=>'Formato inválido']); exit;
    }
    $imgData = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $thumb));
    if (!$imgData) { echo json_encode(['ok'=>false,'error'=>'Base64 inválido']); exit; }

    // Save thumbnail file
    $thumbDir = __DIR__ . '/stock_files/thumbs/';
    if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);
    $filename = 'vthumb_' . $id . '_' . time() . '.jpg';
    $filepath = $thumbDir . $filename;
    if (!file_put_contents($filepath, $imgData)) {
        echo json_encode(['ok'=>false,'error'=>'No se pudo guardar el archivo']); exit;
    }

    $thumbUrl = '/toolz/stock_files/thumbs/' . $filename;
    $db->prepare('UPDATE g7_stock_items SET thumb_url=? WHERE id=?')->execute([$thumbUrl, $id]);
    echo json_encode(['ok'=>true, 'thumb_url'=>$thumbUrl]);
    exit;
}

// ── PAGINATE ACTION ───────────────────────────────────────
if ($action === 'paginate') {
    $page      = max(0, (int)($input['page'] ?? 0));
    $perPage   = 15;
    $offset    = $page * $perPage;
    $search    = trim($input['q'] ?? '');
    $catSlug   = $input['cat'] ?? '';
    $tagFilter = trim($input['tag'] ?? '');
    $typeFilter= $input['typ'] ?? '';
    $favOnly   = !empty($input['fav']);
    $orderBy   = match($input['sort']??'') {
        'oldest'    => 'i.created_at ASC',
        'title'     => 'i.title ASC',
        'size'      => 'i.file_size DESC',
        'downloads' => 'i.downloads DESC',
        default     => 'i.created_at DESC',
    };

    $where  = ['1=1'];
    $params = [];
    if ($search)     { $where[]="(i.title LIKE ? OR i.description LIKE ? OR EXISTS(SELECT 1 FROM g7_stock_tags tg JOIN g7_stock_item_tags it ON it.tag_id=tg.id WHERE it.item_id=i.id AND tg.name LIKE ?))"; $s="%$search%"; $params=array_merge($params,[$s,$s,$s]); }
    if ($catSlug)    { $where[]='c.slug=?'; $params[]=$catSlug; }
    if ($tagFilter)  { $where[]='EXISTS(SELECT 1 FROM g7_stock_tags tg JOIN g7_stock_item_tags it ON it.tag_id=tg.id WHERE it.item_id=i.id AND tg.name=?)'; $params[]=$tagFilter; }
    if ($typeFilter) { $where[]='i.file_type=?'; $params[]=$typeFilter; }
    if ($favOnly)    { $where[]="EXISTS(SELECT 1 FROM g7_stock_favorites sf WHERE sf.item_id=i.id AND sf.user_id=$me)"; }

    // Total count
    $countSql = "SELECT COUNT(*) FROM g7_stock_items i LEFT JOIN g7_stock_categories c ON c.id=i.category_id JOIN g7_users u ON u.id=i.owner_id WHERE ".implode(' AND ',$where);
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Items
    $sql = "SELECT i.*,c.name as cat_name,c.slug as cat_slug,u.display_name,u.username,u.avatar,
        (SELECT GROUP_CONCAT(tg.name SEPARATOR ',') FROM g7_stock_tags tg JOIN g7_stock_item_tags it ON it.tag_id=tg.id WHERE it.item_id=i.id) as tags,
        (SELECT 1 FROM g7_stock_favorites sf WHERE sf.item_id=i.id AND sf.user_id=$me LIMIT 1) as is_fav
        FROM g7_stock_items i LEFT JOIN g7_stock_categories c ON c.id=i.category_id JOIN g7_users u ON u.id=i.owner_id
        WHERE ".implode(' AND ',$where)." ORDER BY $orderBy LIMIT $perPage OFFSET $offset";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    echo json_encode([
        'ok'       => true,
        'items'    => $items,
        'page'     => $page,
        'total'    => $total,
        'has_more' => ($offset + $perPage) < $total,
        'per_page' => $perPage,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST') { echo json_encode(['error'=>'unknown']); exit; }

// ── LOAD DATA ─────────────────────────────────────────────
$search   = trim($_GET['q'] ?? '');
$catSlug  = $_GET['cat'] ?? '';
$tagFilter= trim($_GET['tag'] ?? '');
$typeFilter= $_GET['type'] ?? '';
$viewMode = $_GET['view'] ?? 'grid'; // grid | masonry
$favOnly  = isset($_GET['fav']);
$sort     = $_GET['sort'] ?? 'newest'; // newest | popular | downloads

$categories = $db->query("SELECT c.*,COUNT(i.id) as cnt FROM g7_stock_categories c LEFT JOIN g7_stock_items i ON i.category_id=c.id GROUP BY c.id ORDER BY c.name")->fetchAll();
$allTags    = $db->query("SELECT t.*,COUNT(it.item_id) as cnt FROM g7_stock_tags t LEFT JOIN g7_stock_item_tags it ON it.tag_id=t.id GROUP BY t.id ORDER BY cnt DESC LIMIT 40")->fetchAll();

// Load folder info for breadcrumb
$folderBreadcrumb = [];
if ($folderFilter ?? null) {
    $bid = $folderFilter;
    while ($bid) {
        $br=$db->prepare('SELECT id,name,parent_id,category_id FROM g7_stock_folders WHERE id=?');$br->execute([$bid]);$brow=$br->fetch();
        if(!$brow)break;
        array_unshift($folderBreadcrumb,$brow);
        $bid=$brow['parent_id'];
    }
}

// Build query
$where  = ['1=1'];
$params = [];
// Folder filter
$folderFilter = isset($_GET['folder_id']) && $_GET['folder_id'] !== '' ? (int)$_GET['folder_id'] : null;
if ($folderFilter !== null) { $where[] = 'i.folder_id=?'; $params[] = $folderFilter; }
elseif ($catSlug) { /* category already filtered via cat join */ }

if ($search)    { $where[]='(i.title LIKE ? OR i.description LIKE ? OR EXISTS(SELECT 1 FROM g7_stock_tags tg JOIN g7_stock_item_tags it2 ON it2.tag_id=tg.id WHERE it2.item_id=i.id AND tg.name LIKE ?))'; $params[]="%$search%"; $params[]="%$search%"; $params[]="%$search%"; }
if ($catSlug)   { $where[]='c.slug=?'; $params[]=$catSlug; }
if ($tagFilter) { $where[]='EXISTS(SELECT 1 FROM g7_stock_tags tg2 JOIN g7_stock_item_tags it3 ON it3.tag_id=tg2.id WHERE it3.item_id=i.id AND tg2.name=?)'; $params[]=$tagFilter; }
if ($typeFilter){ $where[]='i.file_type=?'; $params[]=$typeFilter; }
if ($favOnly)   { $where[]="EXISTS(SELECT 1 FROM g7_stock_favorites sf WHERE sf.item_id=i.id AND sf.user_id=$me)"; }

$orderBy = match($sort){
    'popular'   => 'i.views DESC',
    'downloads' => 'i.downloads DESC',
    default     => 'i.created_at DESC'
};

$sql="SELECT i.*,c.name as cat_name,c.slug as cat_slug,u.display_name,u.username,u.avatar,
    (SELECT GROUP_CONCAT(tg.name SEPARATOR ',') FROM g7_stock_tags tg JOIN g7_stock_item_tags it ON it.tag_id=tg.id WHERE it.item_id=i.id) as tags,
    (SELECT 1 FROM g7_stock_favorites sf WHERE sf.item_id=i.id AND sf.user_id=$me LIMIT 1) as is_fav
    FROM g7_stock_items i LEFT JOIN g7_stock_categories c ON c.id=i.category_id JOIN g7_users u ON u.id=i.owner_id
    WHERE ".implode(' AND ',$where)." ORDER BY $orderBy LIMIT 120";
$stmt=$db->prepare($sql);$stmt->execute($params);
$items=$stmt->fetchAll();

$totalItems = $db->query("SELECT COUNT(*) FROM g7_stock_items")->fetchColumn();
$totalSize  = $db->query("SELECT COALESCE(SUM(file_size),0) FROM g7_stock_items")->fetchColumn();

$currentCat = $catSlug ? array_values(array_filter($categories,fn($c)=>$c['slug']===$catSlug))[0]??null : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Stock - <?= htmlspecialchars(g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha384-3B6NwesSXE7YJlcLI9RpRqGf2p/EgVH8BgoKTaUrmKNDkHPStTQ3EyoYjCGXaOTS" crossorigin="anonymous">
<link rel="stylesheet" href="/toolz/g7-theme.css?v=4">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--g7-bg);color:var(--g7-text);font-family:'Inter',sans-serif;min-height:100vh}
.sk-layout{display:grid;grid-template-columns:230px 1fr;min-height:calc(100vh - 52px)}

/* Sidebar */
.sk-nav{border-right:1px solid var(--g7-border-2);padding:16px 10px;display:flex;flex-direction:column;gap:2px;background:var(--g7-bg-2);overflow-y:auto}
.sk-nav::-webkit-scrollbar{width:2px}
.sk-nav-head{font-size:9px;font-weight:700;color:var(--g7-text-5);text-transform:uppercase;letter-spacing:.1em;padding:8px 8px 4px;margin-top:6px}
.sk-nav-link{display:flex;align-items:center;justify-content:space-between;padding:8px 10px;border-radius:8px;text-decoration:none;color:var(--g7-text-3);font-size:12px;font-weight:600;cursor:pointer;transition:all .15s;border:none;background:none;width:100%;text-align:left}
.sk-nav-link:hover{background:var(--g7-bg-4);color:var(--g7-text)}
.sk-nav-link.active{background:rgba(var(--g7-accent-rgb),.1);color:var(--g7-accent)}
.sk-nav-link i{width:15px;text-align:center;font-size:12px;flex-shrink:0}
.sk-cnt{font-size:10px;color:var(--g7-text-5);background:var(--g7-bg-4);border-radius:4px;padding:1px 6px}
.sk-nav-link.active .sk-cnt{background:rgba(var(--g7-accent-rgb),.15);color:var(--g7-accent)}
.sk-upload-btn{width:100%;background:var(--g7-accent);border:none;color:#000;border-radius:10px;padding:10px;font-size:12px;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;transition:opacity .15s;margin-bottom:14px}
.sk-upload-btn:hover{opacity:.85}
.sk-tag-cloud{display:flex;flex-wrap:wrap;gap:5px;padding:4px 0}
.sk-tag{font-size:10px;padding:3px 8px;border-radius:20px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-3);cursor:pointer;transition:all .15s;text-decoration:none}
.sk-tag:hover{border-color:var(--g7-border-3);color:var(--g7-text)}
.sk-tag.active{background:rgba(var(--g7-accent-rgb),.1);border-color:rgba(var(--g7-accent-rgb),.4);color:var(--g7-accent)}

/* Main */
.sk-main{display:flex;flex-direction:column;min-height:0}
/* Topbar */
.sk-topbar{padding:14px 20px;border-bottom:1px solid var(--g7-border-2);display:flex;align-items:center;gap:10px;flex-shrink:0;flex-wrap:wrap;gap:8px;background:var(--g7-bg-2)}
.sk-search-wrap{flex:1;min-width:200px;position:relative}
.sk-search-wrap i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--g7-text-4);font-size:12px;pointer-events:none}
.sk-search{width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:10px;padding:8px 12px 8px 32px;font-size:13px;outline:none;transition:border-color .15s}
.sk-search:focus{border-color:var(--g7-accent)}
.sk-filter-row{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.sk-select{background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:7px 10px;font-size:12px;outline:none;cursor:pointer}
.sk-btn{border:none;border-radius:8px;padding:7px 12px;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:5px}
.sk-btn-ghost{background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-3)}
.sk-btn-ghost:hover{color:var(--g7-text);border-color:var(--g7-border-3)}
.sk-btn-ghost.active{background:rgba(var(--g7-accent-rgb),.1);border-color:rgba(var(--g7-accent-rgb),.4);color:var(--g7-accent)}

/* Grid */
.sk-content{flex:1;overflow-y:auto;padding:16px 20px}
.sk-content::-webkit-scrollbar{width:4px}
.sk-content::-webkit-scrollbar-thumb{background:var(--g7-bg-5);border-radius:2px}
.sk-info-bar{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.sk-info-text{font-size:12px;color:var(--g7-text-4)}
.sk-info-text span{color:var(--g7-text);font-weight:700}
.sk-grid{columns:4;gap:12px;column-gap:12px}
@media(max-width:1200px){.sk-grid{columns:3}}
@media(max-width:900px){.sk-grid{columns:2}}
.sk-card{break-inside:avoid;margin-bottom:12px;border-radius:12px;overflow:hidden;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);position:relative;cursor:pointer;transition:all .2s;display:block}
.sk-card:hover{border-color:var(--g7-border-3);transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.5)}
.sk-card img,.sk-card video{width:100%;display:block;object-fit:cover}
.sk-card video{max-height:260px}
.sk-card-overlay{position:absolute;inset:0;background:linear-gradient(transparent 40%,rgba(0,0,0,.85));opacity:0;transition:opacity .2s;display:flex;flex-direction:column;justify-content:flex-end;padding:12px}
.sk-card:hover .sk-card-overlay{opacity:1}
.sk-card-title{font-size:12px;font-weight:700;color:var(--g7-text);margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sk-card-meta{font-size:10px;color:rgba(var(--g7-ov),.6);display:flex;align-items:center;gap:8px}
.sk-card-actions{position:absolute;top:8px;right:8px;display:flex;gap:5px;opacity:0;transition:opacity .2s}
.sk-card:hover .sk-card-actions{opacity:1}
.sk-card-btn{width:30px;height:30px;border-radius:7px;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:12px;transition:all .15s;backdrop-filter:blur(8px)}
.sk-card-btn.fav{background:rgba(239,68,68,.2);color:#ef4444}
.sk-card-btn.fav.active,.sk-card-btn.fav:hover{background:#ef4444;color:#fff}
.sk-card-btn.dl{background:rgba(var(--g7-ov),.1);color:var(--g7-text)}
.sk-card-btn.dl:hover{background:rgba(var(--g7-ov),.25)}
.sk-card-btn.edit{background:rgba(var(--g7-ov),.1);color:var(--g7-text)}
.sk-card-btn.edit:hover{background:rgba(var(--g7-accent-rgb),.6)}
.sk-video-thumb{position:relative;width:100%;aspect-ratio:16/9;overflow:hidden;background:var(--g7-bg-3);cursor:pointer;display:flex;align-items:center;justify-content:center}
.sk-vthumb-img{width:100%;height:100%;object-fit:cover;display:block;position:absolute;inset:0}
.sk-vthumb-vid{width:100%;height:100%;object-fit:cover;position:absolute;inset:0}
.sk-vthumb-placeholder{display:flex;flex-direction:column;align-items:center;justify-content:center;width:100%;height:100%;position:absolute;inset:0;background:linear-gradient(135deg,var(--g7-bg-4),var(--g7-bg-2))}
.sk-play-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.2);transition:all .2s;z-index:2}
.sk-video-thumb:hover .sk-play-overlay{background:rgba(0,0,0,.45)}
.sk-play-btn{width:46px;height:46px;border-radius:50%;background:rgba(var(--g7-ov),.92);display:flex;align-items:center;justify-content:center;transition:transform .2s;box-shadow:0 2px 12px rgba(0,0,0,.5)}
.sk-video-thumb:hover .sk-play-btn{transform:scale(1.12)}
.sk-play-btn i{color:#111;font-size:16px;margin-left:3px}
.sk-type-badge{position:absolute;top:8px;left:8px;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);border-radius:5px;padding:3px 7px;font-size:9px;font-weight:700;color:var(--g7-text);text-transform:uppercase;letter-spacing:.06em}
.sk-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;color:var(--g7-text-5);padding:60px 20px;text-align:center}
.sk-empty i{font-size:52px;opacity:.2}
.sk-empty p{font-size:14px}
.sk-empty small{font-size:12px;color:#27272a}

/* Modal backdrop */
.sk-modal{position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:1000;display:none;align-items:center;justify-content:center;padding:20px;animation:skFadeIn .2s ease}
.sk-modal.open{display:flex}
@keyframes skFadeIn{from{opacity:0}to{opacity:1}}
.sk-modal-card{background:var(--g7-bg-3);border:1px solid var(--g7-border-3);border-radius:18px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;padding:22px}
.sk-modal-card::-webkit-scrollbar{width:3px}
.sk-modal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px}
.sk-modal-title{font-size:15px;font-weight:800}
.sk-modal-close{background:none;border:none;color:var(--g7-text-3);font-size:17px;cursor:pointer;padding:2px}
.sk-modal-close:hover{color:var(--g7-text)}
.sk-form-group{margin-bottom:14px}
.sk-form-label{font-size:9px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px}
.sk-input{width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 11px;font-size:12px;outline:none;transition:border-color .15s}
.sk-input:focus{border-color:var(--g7-accent)}
.sk-textarea{resize:vertical;min-height:60px}
.sk-drop-zone{border:2px dashed var(--g7-border-3);border-radius:12px;padding:28px;text-align:center;cursor:pointer;transition:all .2s;position:relative;margin-bottom:10px}
.sk-drop-zone:hover,.sk-drop-zone.drag{border-color:var(--g7-accent);background:rgba(var(--g7-accent-rgb),.04)}
.sk-drop-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer}
.sk-drop-zone i{font-size:28px;color:var(--g7-text-5);margin-bottom:10px;display:block}
.sk-drop-zone p{font-size:12px;color:var(--g7-text-4)}
.sk-progress-bar{height:5px;background:var(--g7-bg-5);border-radius:3px;overflow:hidden;margin-top:10px;display:none}
.sk-progress-fill{height:100%;background:linear-gradient(90deg,var(--g7-accent),var(--g7-accent));width:0;transition:width .3s}
.sk-queue{display:flex;flex-direction:column;gap:6px;margin-top:10px}
.sk-qitem{background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:8px;padding:8px 11px;display:flex;align-items:center;gap:9px;font-size:11px}
.sk-qitem i{color:var(--g7-accent);flex-shrink:0}
.sk-qitem-name{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--g7-text-2)}
.sk-qitem-size{color:var(--g7-text-4);flex-shrink:0}
.sk-qitem-status{font-size:10px;font-weight:700;flex-shrink:0}
.sk-btn-primary{background:var(--g7-accent);color:#000;border:none;border-radius:9px;padding:10px 20px;font-size:12px;font-weight:800;cursor:pointer;width:100%;transition:opacity .15s;margin-top:6px}
.sk-btn-primary:hover{opacity:.85}
.sk-btn-primary:disabled{opacity:.4;cursor:not-allowed}
.sk-tag-input-wrap{display:flex;flex-wrap:wrap;gap:5px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:8px;padding:6px 9px;cursor:text;min-height:36px;align-items:center}
.sk-tag-input-wrap:focus-within{border-color:var(--g7-accent)}
.sk-tag-pill{background:rgba(var(--g7-accent-rgb),.15);border:1px solid rgba(var(--g7-accent-rgb),.3);color:var(--g7-accent);border-radius:20px;padding:2px 8px;font-size:10px;font-weight:700;display:flex;align-items:center;gap:4px}
.sk-tag-pill button{background:none;border:none;color:var(--g7-accent);cursor:pointer;font-size:10px;padding:0;line-height:1}
.sk-tag-input{border:none;background:none;outline:none;color:var(--g7-text);font-size:12px;min-width:80px;flex:1}

/* Folders */
.sk-folder-card{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:10px;padding:12px 14px;display:flex;align-items:center;gap:10px;cursor:pointer;transition:all .15s;position:relative}
.sk-folder-card:hover{border-color:var(--g7-border-3);background:var(--g7-bg-4)}
.sk-folder-icon{font-size:22px;flex-shrink:0}
.sk-folder-name{font-size:12px;font-weight:700;color:var(--g7-text);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sk-folder-cnt{font-size:10px;color:var(--g7-text-4);margin-top:2px}
.sk-folder-menu{position:absolute;top:6px;right:6px;opacity:0;transition:opacity .15s}
.sk-folder-card:hover .sk-folder-menu{opacity:1}
.sk-folder-menu-btn{background:rgba(var(--g7-ov),.05);border:none;color:var(--g7-text-3);border-radius:5px;width:22px;height:22px;cursor:pointer;font-size:10px;display:flex;align-items:center;justify-content:center;transition:all .15s}
.sk-folder-menu-btn:hover{background:rgba(239,68,68,.2);color:#ef4444}
/* Lightbox */
#sk-lightbox{position:fixed;inset:0;background:rgba(0,0,0,.97);z-index:2000;display:none;flex-direction:column;align-items:center;justify-content:center;animation:skFadeIn .15s ease}
#sk-lightbox.open{display:flex}
#sk-lightbox img,#sk-lightbox video{max-width:92vw;max-height:80vh;object-fit:contain;border-radius:10px}
.sk-lb-head{position:fixed;top:0;left:0;right:0;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(rgba(0,0,0,.8),transparent);z-index:10}
.sk-lb-title{font-size:14px;font-weight:700;color:var(--g7-text)}
.sk-lb-actions{display:flex;gap:8px}
.sk-lb-btn{background:rgba(var(--g7-ov),.1);border:none;color:var(--g7-text);border-radius:8px;padding:7px 13px;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;transition:background .15s}
.sk-lb-btn:hover{background:rgba(var(--g7-ov),.2)}
.sk-lb-info{position:fixed;bottom:0;left:0;right:0;padding:14px 20px;background:linear-gradient(transparent,rgba(0,0,0,.8));display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.sk-lb-meta{font-size:11px;color:rgba(var(--g7-ov),.5);display:flex;align-items:center;gap:5px}
.sk-lb-tags{display:flex;gap:5px;flex-wrap:wrap}
.sk-lb-tag{font-size:10px;padding:2px 8px;border-radius:20px;background:rgba(var(--g7-ov),.1);color:rgba(var(--g7-ov),.6);cursor:pointer}
.sk-lb-tag:hover{background:rgba(var(--g7-accent-rgb),.3);color:var(--g7-accent)}
</style>
</head>
<body>
<?php include __DIR__ . '/topbar.php'; ?>

<div class="sk-layout">
  <!-- SIDEBAR -->
  <div class="sk-nav">
    <button class="sk-upload-btn" data-on-click="openUpload">
      <i class="fa-solid fa-cloud-arrow-up"></i> Subir archivo
    </button>
    <div class="sk-nav-head">Explorar</div>
    <a href="stock.php" class="sk-nav-link <?= !$catSlug&&!$favOnly&&!$typeFilter?'active':'' ?>">
      <span><i class="fa-solid fa-grid-2"></i> Todo el stock</span>
      <span class="sk-cnt"><?= $totalItems ?></span>
    </a>
    <a href="stock.php?fav=1" class="sk-nav-link <?= $favOnly?'active':'' ?>">
      <span><i class="fa-solid fa-heart"></i> Mis favoritos</span>
    </a>
    <a href="stock.php?type=image" class="sk-nav-link <?= $typeFilter==='image'?'active':'' ?>">
      <span><i class="fa-solid fa-image"></i> Imágenes</span>
    </a>
    <a href="stock.php?type=video" class="sk-nav-link <?= $typeFilter==='video'?'active':'' ?>">
      <span><i class="fa-solid fa-film"></i> Videos</span>
    </a>
    <a href="stock.php?type=gif" class="sk-nav-link <?= $typeFilter==='gif'?'active':'' ?>">
      <span><i class="fa-solid fa-circle-play"></i> GIFs</span>
    </a>

    <div class="sk-nav-head">Categorías
      <button data-on-click="openNewCat" style="background:none;border:none;color:var(--g7-accent);cursor:pointer;font-size:11px;float:right;padding:0">+ Nueva</button>
    </div>
    <?php foreach($categories as $cat): ?>
    <a href="stock.php?cat=<?= $cat['slug'] ?>" class="sk-nav-link <?= $catSlug===$cat['slug']?'active':'' ?>">
      <span><i class="fa-solid fa-folder"></i> <?= htmlspecialchars($cat['name']) ?></span>
      <span class="sk-cnt"><?= $cat['cnt'] ?></span>
    </a>
    <?php endforeach; ?>

    <?php if($currentCat): ?>
    <div class="sk-nav-head" style="display:flex;align-items:center;justify-content:space-between">
      <span>Carpetas</span>
      <button data-on-click="openNewFolder" style="background:none;border:none;color:var(--g7-accent);cursor:pointer;font-size:11px;padding:0">+ Nueva</button>
    </div>
    <div id="sk-folders-nav"></div>
    <?php endif; ?>
    <?php if($allTags): ?>
    <div class="sk-nav-head">Etiquetas populares</div>
    <div class="sk-tag-cloud" style="padding:0 4px">
      <?php foreach($allTags as $tag): ?>
      <a href="stock.php?tag=<?= urlencode($tag['name']) ?>" class="sk-tag <?= $tagFilter===$tag['name']?'active':'' ?>"><?= htmlspecialchars($tag['name']) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="margin-top:auto;padding-top:14px;border-top:1px solid var(--g7-border-2);margin-top:14px">
      <div style="font-size:10px;color:var(--g7-text-5);padding:4px 8px">
        <i class="fa-solid fa-database" style="margin-right:5px"></i><?= fmtSz($totalSize) ?> · <?= $totalItems ?> archivos
      </div>
    </div>
  </div>

  <!-- MAIN -->
  <div class="sk-main">
    <!-- Top bar -->
    <div class="sk-topbar">
      <form method="get" style="flex:1;min-width:200px" data-on-submit="skNoSubmit">
        <div class="sk-search-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input class="sk-search" type="text" name="q" id="sk-search-input" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar por título, descripción o etiqueta...">
        </div>
      </form>
      <div class="sk-filter-row">
        <select class="sk-select" id="sk-sort" data-on-change="applySort">
          <option value="newest" <?= $sort==='newest'?'selected':'' ?>>Más reciente</option>
          <option value="popular" <?= $sort==='popular'?'selected':'' ?>>Más visto</option>
          <option value="downloads" <?= $sort==='downloads'?'selected':'' ?>>Más descargado</option>
        </select>
        <?php if($currentCat): ?>
        <span style="font-size:12px;color:var(--g7-text-4)"><i class="fa-solid fa-folder" style="color:var(--g7-accent);margin-right:5px"></i><?= htmlspecialchars($currentCat['name']) ?></span>
        <?php endif; ?>
        <?php if($tagFilter): ?>
        <span style="font-size:12px;background:rgba(var(--g7-accent-rgb),.1);border:1px solid rgba(var(--g7-accent-rgb),.3);color:var(--g7-accent);border-radius:20px;padding:3px 10px">#<?= htmlspecialchars($tagFilter) ?> <a href="stock.php" style="color:var(--g7-accent);margin-left:4px;text-decoration:none">✕</a></span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Content -->
    <div class="sk-content">
      <?php if(empty($items)): ?>
      <div class="sk-empty">
        <i class="fa-solid fa-photo-film"></i>
        <p>No hay archivos<?= $search?" para \"$search\"":($favOnly?" en favoritos":($currentCat?" en esta categoría":"")) ?></p>
        <small>Usa el botón "Subir archivo" para agregar contenido al stock</small>
      </div>
      <?php else: ?>
      <!-- Folder breadcrumb -->
      <?php if(!empty($folderBreadcrumb)): ?>
      <div style="display:flex;align-items:center;gap:6px;padding:10px 0;font-size:12px;color:var(--g7-text-4);margin-bottom:4px;flex-wrap:wrap">
        <a href="stock.php?cat=<?= $catSlug ?>" style="color:var(--g7-text-3);text-decoration:none;hover:color:#e4e4e7"><i class="fa-solid fa-folder" style="color:var(--g7-accent);margin-right:4px"></i><?= htmlspecialchars($currentCat['name']) ?></a>
        <?php foreach($folderBreadcrumb as $bi): ?>
        <i class="fa-solid fa-chevron-right" style="font-size:9px"></i>
        <a href="stock.php?cat=<?= $catSlug ?>&folder_id=<?= $bi['id'] ?>" style="color:<?= $bi===end($folderBreadcrumb)?'#e4e4e7':'#71717a' ?>;text-decoration:none"><?= htmlspecialchars($bi['name']) ?></a>
        <?php endforeach; ?>
      </div>

      <!-- LOAD MORE -->
      <div id="sk-load-more-wrap" style="padding:24px;text-align:center;<?= $totalItems <= 15 ? 'display:none' : '' ?>">
        <button id="sk-load-more-btn" class="sk-btn sk-btn-ghost" data-on-click="skLoadMore" style="padding:10px 28px;font-size:13px">
          <i class="fa-solid fa-arrow-down"></i> Cargar más
          <span id="sk-load-more-info" style="font-size:11px;opacity:.6;margin-left:4px">(<?= max(0,$totalItems-15) ?> restantes)</span>
        </button>
        <div id="sk-load-more-spinner" style="display:none;color:var(--g7-text-4);font-size:12px">
          <i class="fa-solid fa-spinner fa-spin"></i> Cargando...
        </div>
      </div>
      <!-- INFINITE SCROLL SENTINEL -->
      <div id="sk-sentinel" style="height:1px"></div>

      <?php endif; ?>
      <div class="sk-info-bar">
        <span class="sk-info-text"><span id="sk-shown-count"><?= count($items) ?></span> de <span id="sk-total-count"><?= $totalItems ?></span> resultado<?= $totalItems!==1?'s':'' ?></span>
      </div>
      <!-- Folder grid -->
      <div id="sk-folder-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin-bottom:16px"></div>
      <div class="sk-grid" id="sk-grid">
        <?php foreach($items as $item): $canEdit=($isAdmin||$item['owner_id']==$me); ?>
        <div class="sk-card" data-on-click="openLightbox" data-click-1="n:<?= (int)$item['id'] ?>">
          <?php 
          $isVideo = $item['file_type']==='video' || str_starts_with($item['mime_type']??'','video/');
          $isGif   = $item['file_type']==='gif'   || $item['mime_type']==='image/gif';
          if($isVideo): ?>
          <div class="sk-video-thumb" data-src="<?= htmlspecialchars($item['file_url']) ?>">
            <?php if($item['thumb_url']): ?>
            <img src="<?= htmlspecialchars($item['thumb_url']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" loading="lazy" class="sk-vthumb-img">
            <?php else: ?>
            <video class="sk-vthumb-vid" preload="none"
              data-src="<?= htmlspecialchars($item['file_url']) ?>">
            </video>
            <div class="sk-vthumb-placeholder">
              <i class="fa-solid fa-film" style="font-size:28px;color:rgba(var(--g7-ov),.2);margin-bottom:8px"></i>
              <span style="font-size:10px;color:rgba(var(--g7-ov),.4);text-align:center;padding:0 8px;word-break:break-word"><?= htmlspecialchars(mb_substr($item['title'],0,40)) ?></span>
            </div>
            <?php endif; ?>
            <div class="sk-play-overlay">
              <div class="sk-play-btn"><i class="fa-solid fa-play"></i></div>
            </div>
          </div>
          <?php elseif($isGif && $item['file_url']): ?>
          <img src="<?= htmlspecialchars($item['file_url']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" loading="lazy" style="max-height:220px">
          <?php elseif($item['thumb_url']): ?>
          <img src="<?= htmlspecialchars($item['thumb_url']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" loading="lazy">
          <?php else: ?>
          <img src="<?= htmlspecialchars($item['file_url']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" loading="lazy">
          <?php endif; ?>
          <div class="sk-type-badge"><?= $item['file_type'] ?></div>
          <div class="sk-card-actions" data-on-click="@stop">
            <button class="sk-card-btn fav <?= $item['is_fav']?'active':'' ?>" data-on-click="toggleFav" data-click-1="n:<?= (int)$item['id'] ?>" data-click-2="this" title="Favorito"><i class="fa-<?= $item['is_fav']?'solid':'regular' ?> fa-heart"></i></button>
            <button class="sk-card-btn dl" data-on-click="downloadItem" data-click-1="n:<?= (int)$item['id'] ?>" data-click-2="s:<?= htmlspecialchars($item['file_url']) ?>" data-click-3="s:<?= htmlspecialchars($item['title']) ?>" title="Descargar"><i class="fa-solid fa-download"></i></button>
            <?php if($canEdit): ?><button class="sk-card-btn edit" data-on-click="openEdit" data-click-1="j:<?= htmlspecialchars(json_encode($item)) ?>" title="Editar"><i class="fa-solid fa-pen"></i></button><?php endif; ?>
          </div>
          <div class="sk-card-overlay">
            <div class="sk-card-title"><?= htmlspecialchars($item['title']) ?></div>
            <div class="sk-card-meta">
              <?php if($item['cat_name']): ?><span><i class="fa-solid fa-folder"></i> <?= htmlspecialchars($item['cat_name']) ?></span><?php endif; ?>
              <span><i class="fa-solid fa-download"></i> <?= $item['downloads'] ?></span>
              <span><?= fmtSz($item['file_size']) ?></span>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- LOAD MORE -->
      <div id="sk-load-more-wrap" style="padding:24px;text-align:center;<?= $totalItems <= 15 ? 'display:none' : '' ?>">
        <button id="sk-load-more-btn" class="sk-btn sk-btn-ghost" data-on-click="skLoadMore" style="padding:10px 28px;font-size:13px">
          <i class="fa-solid fa-arrow-down"></i> Cargar más
          <span id="sk-load-more-info" style="font-size:11px;opacity:.6;margin-left:4px">(<?= max(0,$totalItems-15) ?> restantes)</span>
        </button>
        <div id="sk-load-more-spinner" style="display:none;color:var(--g7-text-4);font-size:12px">
          <i class="fa-solid fa-spinner fa-spin"></i> Cargando...
        </div>
      </div>
      <!-- INFINITE SCROLL SENTINEL -->
      <div id="sk-sentinel" style="height:1px"></div>

      <?php endif; ?>
    </div>
  </div>
</div>

<!-- LIGHTBOX -->
<div id="sk-lightbox" data-on-click="skLbBg" data-click-1="this" data-click-2="event">
  <div class="sk-lb-head">
    <div class="sk-lb-title" id="lb-title"></div>
    <div class="sk-lb-actions">
      <button class="sk-lb-btn" id="lb-fav-btn" data-on-click="lbToggleFav"><i class="fa-regular fa-heart"></i> Favorito</button>
      <button class="sk-lb-btn" data-on-click="lbCopyUrl"><i class="fa-solid fa-copy"></i> Copiar URL</button>
      <button class="sk-lb-btn" data-on-click="lbDownload"><i class="fa-solid fa-download"></i> Descargar</button>
      <button class="sk-lb-btn" data-on-click="closeLightbox"><i class="fa-solid fa-xmark"></i></button>
    </div>
  </div>
  <div id="lb-media"></div>
  <div class="sk-lb-info">
    <div class="sk-lb-meta" id="lb-meta-size"><i class="fa-solid fa-weight-hanging"></i></div>
    <div class="sk-lb-meta" id="lb-meta-dims"><i class="fa-solid fa-expand"></i></div>
    <div class="sk-lb-meta" id="lb-meta-by"><i class="fa-solid fa-user"></i></div>
    <div class="sk-lb-meta" id="lb-meta-cat"><i class="fa-solid fa-folder"></i></div>
    <div class="sk-lb-tags" id="lb-tags"></div>
  </div>
</div>

<!-- UPLOAD MODAL -->
<div class="sk-modal" id="sk-upload-modal">
  <div class="sk-modal-card">
    <div class="sk-modal-head">
      <span class="sk-modal-title"><i class="fa-solid fa-cloud-arrow-up" style="color:var(--g7-accent);margin-right:8px"></i>Subir archivos</span>
      <button class="sk-modal-close" data-on-click="closeUpload"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="sk-drop-zone" id="sk-drop" data-on-dragover="skDragOver" data-dragover-1="this" data-dragover-2="event" data-on-drop="dropFiles" data-drop-1="event">
      <input type="file" id="sk-file-input" multiple accept="image/*,video/*,.gif" data-on-change="skFilesChosen" data-change-1="this">
      <i class="fa-solid fa-cloud-arrow-up"></i>
      <p>Arrastra archivos o haz click</p>
      <p style="font-size:11px;margin-top:4px;color:var(--g7-text-5)">Imágenes, Videos y GIFs · Máx 200MB por archivo</p>
    </div>
    <div class="sk-queue" id="sk-queue"></div>
    <div class="sk-progress-bar" id="sk-progress-bar"><div class="sk-progress-fill" id="sk-progress-fill"></div></div>
    <div class="sk-form-group" style="margin-top:12px" id="up-folder-group" style="display:none">
      <label class="sk-form-label">Carpeta (dentro de la categoría)</label>
      <input type="hidden" id="up-folder-id" value="">
      <div id="up-folder-display" style="font-size:12px;color:var(--g7-text-4);padding:4px 0"></div>
    </div>
    <div class="sk-form-group">
      <label class="sk-form-label">Categoría (opcional)</label>
      <select class="sk-input" id="up-cat">
        <option value="">Sin categoría</option>
        <?php foreach($categories as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="sk-form-group">
      <label class="sk-form-label">Etiquetas (separadas por coma)</label>
      <div class="sk-tag-input-wrap" id="up-tag-wrap" data-on-click="skFocus" data-click-1="s:up-tag-input">
        <input class="sk-tag-input" id="up-tag-input" placeholder="casino, deportes, banner..." data-on-keydown="tagKeydown" data-keydown-1="event">
      </div>
    </div>
    <button class="sk-btn-primary" id="sk-upload-go-btn" data-on-click="uploadAll" disabled>
      <i class="fa-solid fa-cloud-arrow-up"></i> Subir archivos
    </button>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="sk-modal" id="sk-edit-modal">
  <div class="sk-modal-card">
    <div class="sk-modal-head">
      <span class="sk-modal-title"><i class="fa-solid fa-pen" style="color:var(--g7-accent);margin-right:8px"></i>Editar archivo</span>
      <button class="sk-modal-close" data-on-click="closeEdit"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="sk-form-group">
      <label class="sk-form-label">Título</label>
      <input class="sk-input" id="edit-title" type="text">
    </div>
    <div class="sk-form-group">
      <label class="sk-form-label">Descripción</label>
      <textarea class="sk-input sk-textarea" id="edit-desc"></textarea>
    </div>
    <div class="sk-form-group">
      <label class="sk-form-label">Categoría</label>
      <select class="sk-input" id="edit-cat">
        <option value="">Sin categoría</option>
        <?php foreach($categories as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="sk-form-group">
      <label class="sk-form-label">Etiquetas</label>
      <div class="sk-tag-input-wrap" id="edit-tag-wrap" data-on-click="skFocus" data-click-1="s:edit-tag-input">
        <input class="sk-tag-input" id="edit-tag-input" placeholder="tag1, tag2..." data-on-keydown="editTagKeydown" data-keydown-1="event">
      </div>
    </div>
    <div style="display:flex;gap:8px;margin-top:6px">
      <button class="sk-btn-primary" data-on-click="submitEdit" style="flex:1">Guardar cambios</button>
      <button data-on-click="confirmDelete" style="flex:0;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:#ef4444;border-radius:9px;padding:10px 16px;font-size:12px;font-weight:700;cursor:pointer">Eliminar</button>
    </div>
  </div>
</div>

<!-- NEW CAT MODAL -->
<div class="sk-modal" id="sk-cat-modal">
  <div class="sk-modal-card" style="max-width:360px">
    <div class="sk-modal-head">
      <span class="sk-modal-title"><i class="fa-solid fa-folder-plus" style="color:var(--g7-accent);margin-right:8px"></i>Nueva categoría</span>
      <button class="sk-modal-close" data-on-click="skCatClose"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="sk-form-group">
      <label class="sk-form-label">Nombre</label>
      <input class="sk-input" id="new-cat-name" type="text" placeholder="Ej: Casinos, Deportes...">
    </div>
    <button class="sk-btn-primary" data-on-click="createCategory">Crear categoría</button>
  </div>
</div>

<!-- COPY TOAST -->
<div id="sk-toast" style="position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(60px);background:#22c55e;color:#000;padding:9px 18px;border-radius:8px;font-size:12px;font-weight:700;transition:transform .3s;z-index:9999;pointer-events:none">URL copiada</div>

<?php
  $__skFolderName = !empty($folderBreadcrumb) ? end($folderBreadcrumb)['name'] : '';
  $__skParentUrl = !empty($folderBreadcrumb) && count($folderBreadcrumb)>1 ? "stock.php?cat=$catSlug&folder_id=".$folderBreadcrumb[count($folderBreadcrumb)-2]['id'] : "stock.php?cat=$catSlug";
?>
<script type="application/json" id="sk-cfg"><?= json_encode(['total' => (int)$totalItems, 'hasMore' => $totalItems > 15, 'me' => (int)$me, 'folderName' => (string)$__skFolderName, 'catSlug' => (string)$catSlug, 'catId' => (string)($currentCat ? $currentCat['id'] : ''), 'folderId' => (string)(isset($folderFilter) && $folderFilter ? $folderFilter : ''), 'isAdmin' => (bool)$isAdmin, 'parentUrl' => $__skParentUrl], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script type="application/json" id="sk-items"><?= json_encode($items, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
<script src="/toolz/assets/stock-page.js?v=<?= (int)@filemtime(__DIR__ . '/assets/stock-page.js') ?>"></script>
</body>
</html>