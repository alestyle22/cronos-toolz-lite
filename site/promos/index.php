<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once 'auth.php';
require_once __DIR__ . '/../csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo: Promos ya no tiene código en línea (SEC-24)
if (!defined('G7_CSS_LOADED')) define('G7_CSS_LOADED', 1);
try { if (file_exists(__DIR__ . '/../gamification.php')) require_once __DIR__ . '/../gamification.php'; } catch(Exception $e) {}
$currentUser = requireLogin();
$isAdmin     = ($currentUser['role'] === 'admin');
$db          = getDB();

// ── PERMISOS DE MÓDULO ───────────────────────────────────
$canView   = $isAdmin || hasPermission($currentUser['id'], 'promos', 'view',   $currentUser['role']);
$canCreate = $isAdmin || hasPermission($currentUser['id'], 'promos', 'create', $currentUser['role']);
$canEdit   = $isAdmin || hasPermission($currentUser['id'], 'promos', 'edit',   $currentUser['role']);
$canDelete = $isAdmin || hasPermission($currentUser['id'], 'promos', 'delete', $currentUser['role']);
if (!$canView && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403); die('Sin permiso para ver Promos.');
}

// ── MARCAS (Admin › Marcas) ──────────────────────────────
// $bWhere filtra los listados; g7_brand_allows_text valida proyectos al crear/editar.
$bUid   = (int)$currentUser['id'];
$bScope = g7_brand_scope($bUid);
$bWhere = g7_brand_sql("CONCAT_WS(' ', project, projects)", $bScope);

// ── PAGE ACCESS TRACKING ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS g7_accesos_rapidos (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, tool VARCHAR(255) NOT NULL, tool_name VARCHAR(255) NOT NULL DEFAULT '', meta VARCHAR(50) DEFAULT '', accessed_at DATETIME DEFAULT NOW(), INDEX idx_user(user_id), INDEX idx_time(accessed_at))");
        $db->prepare("INSERT INTO g7_accesos_rapidos (user_id, tool, tool_name, meta) VALUES (?,?,?,?)")
           ->execute([$currentUser['id'], '/toolz/promos/', 'Promos CMS', '']);
    } catch(Exception $e) {}
}
// ─────────────────────────────────────────────────────────

$imgFolder      = 'imgs/';
$mailingsFolder = 'mailings/';
if (!is_dir($imgFolder))      mkdir($imgFolder,      0755, true);
if (!is_dir($mailingsFolder)) mkdir($mailingsFolder, 0755, true);

// ── COMPLICACIONES Y MOTIVOS DE EDICIÓN ──────────────────
// Las listas (PR_COMPLICATIONS, PR_EDIT_REASONS) están en opciones.php: también las usa Estadísticas.
require_once __DIR__ . '/opciones.php';
// Formularios armables por el admin (categorías y campos): forms_lib.php + forms.js
require_once __DIR__ . '/forms_lib.php';
pf_install($db);
function pr_complications_clean($raw): ?string {
    $a = is_array($raw) ? $raw : json_decode((string)$raw, true);
    if (!is_array($a)) return null;
    $a = array_values(array_unique(array_filter($a, fn($k) => is_string($k) && isset(PR_COMPLICATIONS[$k]))));
    return $a ? json_encode($a) : null;
}
function pr_complications_note($raw, $list): ?string {
    $n = trim((string)$raw);
    return ($n !== '' && $list && in_array('otro', json_decode($list, true), true)) ? mb_substr($n, 0, 300) : null;
}

// ── POST ACTIONS ──────────────────────────────────────────
/** Landings de una pieza: acepta texto (una por línea) o arreglo; devuelve una URL por línea, sin vacías ni repetidas. */
function pr_landings_clean($v): string {
    $list = is_array($v) ? $v : preg_split('/[\r\n]+/', (string)$v);
    $list = array_values(array_unique(array_filter(array_map('trim', $list), 'strlen')));
    return implode("\n", $list);
}
// ── ENSURE PRODUCTION COLUMNS ───────────────────────────────
try {
    $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS content_type VARCHAR(50) NULL DEFAULT NULL");
    $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS qty_tareas INT NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS qty_graficas INT NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS qty_videos INT NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS qty_landings INT NOT NULL DEFAULT 0");
    // Varias landings por pieza: una URL por línea en la misma columna (antes era VARCHAR(500) con una sola)
    $lc = $db->query("SHOW COLUMNS FROM g7_content LIKE 'landing'")->fetch();
    if ($lc && stripos($lc['Type'], 'varchar') === 0) $db->exec("ALTER TABLE g7_content MODIFY COLUMN landing TEXT NULL");
    $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS qty_mailings INT NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS qty_creativos_paid INT NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS qty_assets_rrss INT NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS qty_assets_mobile INT NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS qty_revisiones INT NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS qty_rechazos INT NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS estado ENUM('activo','reemplazado') NOT NULL DEFAULT 'activo'");
    $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS parent_id INT NULL DEFAULT NULL");
    $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS edit_type ENUM('revision','rechazo') NULL DEFAULT NULL");
    $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS tiempo_entrega_dias INT NULL DEFAULT NULL");
    $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS complications TEXT NULL DEFAULT NULL");
    $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS edit_reasons TEXT NULL DEFAULT NULL");
    $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS edit_notes TEXT NULL DEFAULT NULL");
    $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS complications_note VARCHAR(300) NULL DEFAULT NULL");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_content_resources (
        id INT AUTO_INCREMENT PRIMARY KEY,
        content_id INT NOT NULL,
        name VARCHAR(255) NOT NULL DEFAULT '',
        url VARCHAR(1000) NOT NULL DEFAULT '',
        sort_order INT DEFAULT 0,
        INDEX idx_content (content_id)
    )");
} catch(Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── MAILING FILE UPLOAD ───────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'upload_mailing' && isset($_FILES['file'])) {
        header('Content-Type: application/json');
        if (!$canCreate) { echo json_encode(['error'=>'Sin permiso para subir']); exit; }
        $file    = $_FILES['file'];
        $allowed = ['text/html','application/pdf','application/zip','image/jpeg','image/png','image/gif','image/webp'];
        if (!in_array($file['type'], $allowed)) { echo json_encode(['ok'=>false,'error'=>'Tipo no permitido']); exit; }
        $dir = __DIR__ . '/mailings/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $ext  = g7_upload_ext_or_die($file['name'], array_merge(['html', 'htm', 'pdf', 'zip'], G7_EXT_IMG));
        $name = 'mailing_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $dir . $name)) {
            echo json_encode(['ok'=>true, 'url'=>'/toolz/promos/mailings/'.$name]);
        } else { echo json_encode(['ok'=>false,'error'=>'Error al guardar']); }
        exit;
    }

    // SAVE NEW CONTENT
    if (isset($_POST['action']) && $_POST['action'] === 'save') {
        if (!$canCreate) { header('Content-Type: application/json'); echo json_encode(['status'=>'error','msg'=>'Sin permiso para crear contenido']); exit; }
        if (!g7_brand_allows_text($bUid, ($_POST['project'] ?? '') . ' ' . implode(' ', (array)($_POST['projects'] ?? [])))) {
            header('Content-Type: application/json'); echo json_encode(['status'=>'error','msg'=>'No tienes acceso a la marca de ese proyecto']); exit;
        }
        $imgPath = '';
        if (isset($_FILES['cover_img']) && $_FILES['cover_img']['error'] === 0) {
            $ext = g7_upload_ext_or_die($_FILES['cover_img']['name'], G7_EXT_IMG);
            $nm  = time().'_'.uniqid().'.'.$ext;
            if (move_uploaded_file($_FILES['cover_img']['tmp_name'], $imgFolder.$nm)) $imgPath = $imgFolder.$nm;
        }
        $mailingValue = $_POST['mailing_link'] ?? ($_POST['mailings_link'] ?? '');
        if (isset($_FILES['mailing_file']) && $_FILES['mailing_file']['error'] === 0) {
            $pn = preg_replace('/[^a-z0-9]+/','_',strtolower(trim($_POST['name']??'promo')));
            $pp = preg_replace('/[^a-z0-9]+/','_',strtolower(trim($_POST['project']??'general')));
            $mn = 'mailing_'.$pn.'_'.$pp.'.html';
            if (move_uploaded_file($_FILES['mailing_file']['tmp_name'], $mailingsFolder.$mn))
                $mailingValue = g7_site_origin() . '/toolz/promos/mailings/' . $mn;
        }
        $type      = pf_type_ok((string)($_POST['type'] ?? '')) ? (string)$_POST['type'] : 'promo';
        $author    = $isAdmin ? ($_POST['author'] ?? $currentUser['username']) : $currentUser['username'];
        $projects  = isset($_POST['projects']) ? json_encode($_POST['projects']) : '[]';
        $startDate = !empty($_POST['start']) ? $_POST['start'] : null;
        $endDate   = !empty($_POST['end'])   ? $_POST['end']   : null;
        $cf        = $_POST['custom_field'] ?? '';
        $collabIds = json_decode($_POST['collaborators'] ?? '[]', true);
        $mTaskId   = $_POST['monday_task_id']   ?? '';
        $mTaskName = $_POST['monday_task_name']  ?? '';
        $mTaskUrl  = $_POST['monday_task_url']   ?? '';

        $db->prepare('INSERT INTO g7_content
            (type,name,author,project,projects,category,media_type,start_date,end_date,img,mailing,graphics,landing,custom_field,collaborator,monday_task_id,monday_task_name,monday_task_url,content_type,qty_graficas,qty_videos,qty_landings,qty_mailings,qty_creativos_paid,qty_assets_rrss,qty_assets_mobile,tiempo_entrega_dias,qty_tareas,created_at,created_by,complications,complications_note)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURDATE(),?,?,?)')->execute([
            $type, $_POST['name'], $author, $_POST['project']??'', $projects,
            $_POST['category']??'', $_POST['media_type']??'', $startDate, $endDate,
            $imgPath, $mailingValue, $_POST['graphics']??'', pr_landings_clean($_POST['landing']??''),
            $cf, '', $mTaskId, $mTaskName, $mTaskUrl, $_POST['content_type']??'',
            (int)($_POST['qty_graficas']??0), (int)($_POST['qty_videos']??0),
            (int)($_POST['qty_landings']??0), (int)($_POST['qty_mailings']??0),
            (int)($_POST['qty_creativos_paid']??0), (int)($_POST['qty_assets_rrss']??0),
            (int)($_POST['qty_assets_mobile']??0),
            !empty($_POST['tiempo_entrega_dias']) ? (int)$_POST['tiempo_entrega_dias'] : null,
            ($type==='tarea') ? (int)($_POST['tarea_qty']??1) : (($type==='mailings') ? (int)($_POST['mailings_qty']??1) : 0),
            $currentUser['id'],
            $cxList = pr_complications_clean($_POST['complications'] ?? null),
            pr_complications_note($_POST['complications_note'] ?? '', $cxList),
        ]);
        $newId = $db->lastInsertId();
        // Campos agregados por el admin en Formularios
        $pfExtra = pf_extra_clean($_POST['extra'] ?? '', $type);
        $db->prepare('UPDATE g7_content SET extra=? WHERE id=?')->execute([$pfExtra, $newId]);
        try { pf_jira_sync($db, (int)$currentUser['id'], (int)$newId, $pfExtra, null, $type); } catch (Throwable $e) {}   // campos Jira → vínculos
        // ── GAMIFICATION ──────────────────────────────
        if (function_exists('g7ProcessContentUpload')) {
            $gamifItem = array_merge($_POST, ['id'=>$newId,'qty_tareas'=>(int)($_POST['tarea_qty']??0)]);
            g7ProcessContentUpload($db, $currentUser['id'], $gamifItem);
        }
        if (!empty($collabIds)) {
            foreach ($collabIds as $uid) {
                $uid=(int)$uid; if($uid) $db->prepare('INSERT IGNORE INTO g7_content_collaborators (content_id,user_id,added_by) VALUES (?,?,?)')->execute([$newId,$uid,$currentUser['id']]);
            }
        }
                // Save resources for custom type
        if ($type === 'custom') {
            $resources = json_decode($_POST['resources'] ?? '[]', true);
            if (!empty($resources)) {
                try {
                    $db->exec("CREATE TABLE IF NOT EXISTS g7_content_resources (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        content_id INT NOT NULL,
                        name VARCHAR(255) NOT NULL,
                        url VARCHAR(1000) NOT NULL,
                        sort_order INT DEFAULT 0,
                        INDEX idx_content (content_id)
                    )");
                } catch(Exception $e) {}
                foreach ($resources as $i => $res) {
                    $rname = trim($res['name'] ?? '');
                    $rurl  = trim($res['url']  ?? '');
                    if ($rname || $rurl) {
                        $db->prepare("INSERT INTO g7_content_resources (content_id,name,url,sort_order) VALUES (?,?,?,?)")
                           ->execute([$newId, $rname, $rurl, $i]);
                    }
                }
            }
        }
$db->prepare('INSERT INTO g7_activity (user_id,type,target_name,meta) VALUES (?,?,?,?)')->execute([$currentUser['id'],'content_create',$_POST['name'],$type]);
        $item = $db->query("SELECT * FROM g7_content WHERE id=$newId")->fetch();
        $item['projects'] = json_decode($item['projects']?:'[]',true);
        // Entrega de un pedido (trabajo/entrega_lib.php): vincula, responde el ticket con los enlaces y lo pasa a Resuelto
        $delivery = null;
        if ((int)($_POST['deliver_id'] ?? 0) && is_file(__DIR__ . '/../trabajo/entrega_lib.php') && (!function_exists('g7_can') || g7_can((int)$currentUser['id'], 'tareas'))) {
            try { require_once __DIR__ . '/../trabajo/entrega_lib.php'; $delivery = ['ok' => true] + wk_deliver($db, (int)$currentUser['id'], (int)$newId, ($_POST['deliver_kind'] ?? '') === 'tarea' ? 'tarea' : 'ticket', (int)$_POST['deliver_id']); }
            catch (Throwable $e) { $delivery = ['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo entregar el pedido']; }
        }
        echo json_encode(['status'=>'success','item'=>$item,'delivery'=>$delivery]); exit;
    }

    // JSON ACTIONS
    $input = json_decode(file_get_contents('php://input'),true)??[];
    $act   = $input['action'] ?? '';
    if ($act === 'forms_save') pf_handle_save($db, $isAdmin, $input);

    // Marca: cualquier acción sobre un contenido existente exige que sea de una marca visible para el usuario
    $bCid = (int)($input['content_id'] ?? $input['id'] ?? $input['parent_id'] ?? 0);
    if ($bCid && $bScope !== null && $act !== 'delete_content_comment') {
        $bChk = $db->prepare("SELECT COUNT(*) FROM g7_content WHERE id=? AND $bWhere"); $bChk->execute([$bCid]);
        if (!$bChk->fetchColumn()) { echo json_encode(['error' => 'Ese contenido es de una marca a la que no tienes acceso']); exit; }
    }
    if (isset($input['project']) && !g7_brand_allows_text($bUid, (string)$input['project'])) {
        echo json_encode(['error' => 'No tienes acceso a la marca de ese proyecto']); exit;
    }

    if ($act === 'paginate') {
        $page    = max(0, (int)($input['page'] ?? 0));
        $perPage = 15;
        $offset  = $page * $perPage;
        $total   = (int)$db->query("SELECT COUNT(*) FROM g7_content WHERE $bWhere")->fetchColumn();
        $rows    = $db->query("SELECT * FROM g7_content WHERE $bWhere ORDER BY id DESC LIMIT $perPage OFFSET $offset")->fetchAll();
        $mapped  = array_map(function($r) use ($db, $currentUser) {
            $r['projects']        = json_decode($r['projects']?:'[]',true);
            $r['start']           = $r['start_date']??'';
            $r['end']             = $r['end_date']??'';
            $r['img']             = $r['img']??'';
            $r['mailing']         = $r['mailing']??'';
            $r['custom_field']    = $r['custom_field']??'';
            $r['collaborator']    = $r['collaborator']??'';
            $r['monday_task_id']  = $r['monday_task_id']??'';
            $r['monday_task_name']= $r['monday_task_name']??'';
            $r['monday_task_url'] = $r['monday_task_url']??'';
            $r['content_type']    = $r['content_type']??'';
            $r['created_by']      = (int)($r['created_by']??0);
            try {
                $q=$db->prepare("SELECT u.id,u.username,u.display_name,u.avatar FROM g7_content_collaborators c JOIN g7_users u ON u.id=c.user_id WHERE c.content_id=?");
                $q->execute([$r['id']]); $r['collaborators']=$q->fetchAll();
            } catch(Exception $e){$r['collaborators']=[];}
            try {
                $lc=$db->prepare("SELECT COUNT(*) FROM g7_content_likes WHERE content_id=?");$lc->execute([$r['id']]);$r['like_count']=(int)$lc->fetchColumn();
                $ml=$db->prepare("SELECT 1 FROM g7_content_likes WHERE content_id=? AND user_id=?");$ml->execute([$r['id'],$currentUser['id']]);$r['liked']=(bool)$ml->fetchColumn();
                $cc=$db->prepare("SELECT COUNT(*) FROM g7_content_comments WHERE content_id=?");$cc->execute([$r['id']]);$r['comment_count']=(int)$cc->fetchColumn();
            } catch(Exception $e){$r['like_count']=0;$r['liked']=false;$r['comment_count']=0;}
            return $r;
        }, $rows);
        echo json_encode([
            'ok'       => true,
            'items'    => $mapped,
            'page'     => $page,
            'total'    => $total,
            'has_more' => ($offset + $perPage) < $total,
        ]);
        exit;
    }

    if ($act==='delete') {
        if (!$canDelete) { echo json_encode(['status'=>'error','error'=>'Sin permiso para eliminar contenido']); exit; }
        $db->prepare('DELETE FROM g7_content WHERE id=?')->execute([$input['id']]);
        echo json_encode(['status'=>'success']); exit;
    }
    if ($act==='update_content') {
        $cid=$input['content_id']??0;
        $row=$db->prepare('SELECT author,created_by FROM g7_content WHERE id=?'); $row->execute([$cid]); $r=$row->fetch();
        if (!$r||(!$isAdmin&&$r['created_by']!=$currentUser['id']&&$r['author']!==$currentUser['username'])){echo json_encode(['error'=>'forbidden']);exit;}
        $fields=['name','category','media_type','start_date','end_date','graphics','landing','mailing','monday_task_url','monday_task_name','monday_task_id','custom_field','project','type','content_type'];
        if (isset($input['landing'])) $input['landing'] = pr_landings_clean($input['landing']);
        $sets=[];$vals=[];
        if (isset($input['type']) && !pf_type_ok((string)$input['type'])) unset($input['type']);
        if (array_key_exists('extra', $input)) {
            $tq = $db->prepare('SELECT type, extra FROM g7_content WHERE id=?'); $tq->execute([$cid]); $tr = $tq->fetch(PDO::FETCH_ASSOC) ?: ['type' => '', 'extra' => null];
            $pfType = (string)($input['type'] ?? $tr['type']);
            $pfExtra = pf_extra_clean($input['extra'], $pfType);
            $sets[] = 'extra=?'; $vals[] = $pfExtra;
            try { pf_jira_sync($db, (int)$currentUser['id'], (int)$cid, $pfExtra, (string)($tr['extra'] ?? ''), $pfType); } catch (Throwable $e) {}   // campos Jira → vínculos
        }
        foreach($fields as $f){if(isset($input[$f])){$sets[]="$f=?";$vals[]=$input[$f];}}
        if($sets){$vals[]=$cid;$db->prepare('UPDATE g7_content SET '.implode(',',$sets).' WHERE id=?')->execute($vals);}
        if (array_key_exists('complications', $input)) {
            $cxList = pr_complications_clean($input['complications']);
            $db->prepare('UPDATE g7_content SET complications=?, complications_note=? WHERE id=?')
               ->execute([$cxList, pr_complications_note($input['complications_note'] ?? '', $cxList), $cid]);
        }
        // Update resources for custom type
        if (isset($input['resources'])) {
            try { $db->prepare("DELETE FROM g7_content_resources WHERE content_id=?")->execute([$cid]); } catch(Exception $e) {}
            foreach ($input['resources'] as $i => $res) {
                $rname = trim($res['name'] ?? ''); $rurl = trim($res['url'] ?? '');
                if ($rname || $rurl) { try { $db->prepare("INSERT INTO g7_content_resources (content_id,name,url,sort_order) VALUES (?,?,?,?)")->execute([$cid,$rname,$rurl,$i]); } catch(Exception $e) {} }
            }
        }
        echo json_encode(['status'=>'success']); exit;
    }
        // Load mailing HTML
    if ($act==='load_mailing_html') {
        $url = trim($input['url'] ?? '');
        $allowed = str_starts_with($url, '/toolz/') && preg_match('/\.html?$/i', $url);
        if (!$url || !$allowed) { echo json_encode(['ok'=>false,'error'=>'URL no válida']); exit; }
        // Resolve real file path — try multiple strategies
        $path = $_SERVER['DOCUMENT_ROOT'] . $url;
        if (!file_exists($path)) $path = dirname(__DIR__) . $url;
        if (!file_exists($path)) $path = __DIR__ . str_replace('/toolz/promos','', preg_replace('#^/toolz#','',$url));
        if (!file_exists($path)) { echo json_encode(['ok'=>false,'error'=>'No encontrado: '.$path]); exit; }
        if (!is_readable($path)) { echo json_encode(['ok'=>false,'error'=>'Sin permisos: '.$path]); exit; }
        $html = file_get_contents($path);
        if ($html === false) { echo json_encode(['ok'=>false,'error'=>'No se pudo leer: '.$path]); exit; }
        echo json_encode(['ok'=>true,'html'=>$html,'filename'=>basename($path)]);
        exit;
    }

    // Save mailing HTML (overwrite)
    if ($act==='save_mailing_html') {
        $url  = trim($input['url'] ?? '');
        $html = $input['html'] ?? '';
        if (!$url || !str_starts_with($url, '/toolz/') || !preg_match('/\.html?$/i', $url)) {
            echo json_encode(['ok'=>false,'error'=>'URL no válida']); exit;
        }
        $path = $_SERVER['DOCUMENT_ROOT'] . $url;
        if (!file_exists($path)) $path = dirname(__DIR__) . $url;
        if (!file_exists($path)) { echo json_encode(['ok'=>false,'error'=>'Archivo no encontrado']); exit; }
        $mdir = realpath(__DIR__ . '/mailings');
        $real = realpath($path);
        if (!$mdir || !$real || !str_starts_with($real, $mdir . DIRECTORY_SEPARATOR)) { echo json_encode(['ok'=>false,'error'=>'Solo se pueden editar mailings de Promos']); exit; }
        if (file_put_contents($path, $html) !== false) {
            echo json_encode(['ok'=>true,'bytes'=>strlen($html)]);
        } else {
            echo json_encode(['ok'=>false,'error'=>'No se pudo guardar']);
        }
        exit;
    }

    if ($act==='upload_mailing' && isset($_FILES['file'])) {
        $file    = $_FILES['file'];
        $allowed = ['text/html','application/pdf','application/zip','image/jpeg','image/png','image/gif','image/webp'];
        if (!in_array($file['type'], $allowed)) { echo json_encode(['ok'=>false,'error'=>'Tipo no permitido']); exit; }
        $dir  = __DIR__ . '/mailings/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $ext  = g7_upload_ext_or_die($file['name'], array_merge(['html', 'htm', 'pdf', 'zip'], G7_EXT_IMG));
        $name = 'mailing_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $dir . $name)) {
            echo json_encode(['ok'=>true, 'url'=>'/toolz/promos/mailings/'.$name]);
        } else {
            echo json_encode(['ok'=>false,'error'=>'Error al guardar']);
        }
        exit;
    }

    // Load mailing HTML

    // Save mailing HTML (overwrite)

    if ($act==='upload_mailing' && isset($_FILES['file'])) {
        $file    = $_FILES['file'];
        $allowed = ['text/html','application/pdf','application/zip','image/jpeg','image/png','image/gif','image/webp'];
        if (!in_array($file['type'], $allowed)) { echo json_encode(['ok'=>false,'error'=>'Tipo no permitido']); exit; }
        $dir  = __DIR__ . '/mailings/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $ext  = g7_upload_ext_or_die($file['name'], array_merge(['html', 'htm', 'pdf', 'zip'], G7_EXT_IMG));
        $name = 'mailing_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $dir . $name)) {
            echo json_encode(['ok'=>true, 'url'=>'/toolz/promos/mailings/'.$name]);
        } else {
            echo json_encode(['ok'=>false,'error'=>'Error al guardar']);
        }
        exit;
    }

    // Load mailing HTML

    // Save mailing HTML (overwrite)

    if ($act==='upload_mailing' && isset($_FILES['file'])) {
        $file    = $_FILES['file'];
        $allowed = ['text/html','application/pdf','application/zip','image/jpeg','image/png','image/gif','image/webp'];
        if (!in_array($file['type'], $allowed)) { echo json_encode(['ok'=>false,'error'=>'Tipo no permitido']); exit; }
        $dir  = __DIR__ . '/mailings/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $ext  = g7_upload_ext_or_die($file['name'], array_merge(['html', 'htm', 'pdf', 'zip'], G7_EXT_IMG));
        $name = 'mailing_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $dir . $name)) {
            echo json_encode(['ok'=>true, 'url'=>'/toolz/promos/mailings/'.$name]);
        } else { echo json_encode(['ok'=>false,'error'=>'Error al guardar']); }
        exit;
    }

    if ($act==='save_edited') {
        $parentId = (int)($input['parent_id'] ?? 0);
        $editType = in_array($input['edit_type']??'', ['revision','rechazo']) ? $input['edit_type'] : null;
        if (!$parentId || !$editType) { echo json_encode(['ok'=>false,'error'=>'Datos inválidos']); exit; }
        $editReasons = array_values(array_unique(array_filter((array)($input['edit_reasons'] ?? []), fn($k) => is_string($k) && isset(PR_EDIT_REASONS[$editType][$k]))));
        if (!$editReasons) { echo json_encode(['ok'=>false,'error'=>'Elige al menos un motivo']); exit; }

        // Get parent content
        $parent = $db->prepare("SELECT * FROM g7_content WHERE id=? AND estado='activo'");
        $parent->execute([$parentId]);
        $par = $parent->fetch();
        if (!$par) { echo json_encode(['ok'=>false,'error'=>'Contenido original no encontrado o ya reemplazado']); exit; }

        // Mark original as reemplazado
        $db->prepare("UPDATE g7_content SET estado='reemplazado' WHERE id=?")->execute([$parentId]);

        // Update revision/rechazo counts on parent
        if ($editType === 'revision') {
            $db->prepare("UPDATE g7_content SET qty_revisiones=qty_revisiones+1 WHERE id=?")->execute([$parentId]);
        } else {
            $db->prepare("UPDATE g7_content SET qty_rechazos=qty_rechazos+1 WHERE id=?")->execute([$parentId]);
        }

        // Create new entry inheriting parent data
        $notes = trim($input['notes'] ?? '');
        $stmt = $db->prepare("INSERT INTO g7_content
            (type,name,author,project,projects,category,media_type,start_date,end_date,
             mailing,graphics,landing,monday_task_id,monday_task_name,monday_task_url,content_type,
             qty_graficas,qty_videos,qty_landings,qty_mailings,qty_creativos_paid,qty_assets_rrss,qty_assets_mobile,
             tiempo_entrega_dias,estado,parent_id,edit_type,custom_field,
             created_at,created_by,edit_reasons,edit_notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'',CURDATE(),?,?,?)");
        $stmt->execute([
            $par['type'], $par['name'], $par['author'], $par['project'], $par['projects'],
            $par['category'], $par['media_type'], $par['start_date'], $par['end_date'],
            !empty($input['mailing'])  ? trim($input['mailing'])  : $par['mailing'],
            !empty($input['graphics']) ? trim($input['graphics']) : $par['graphics'],
            !empty($input['landing'])  ? pr_landings_clean($input['landing'])  : $par['landing'],
            $par['monday_task_id'], $par['monday_task_name'], $par['monday_task_url'], $par['content_type'],
            (int)($input['qty_graficas']??0), (int)($input['qty_videos']??0),
            (int)($input['qty_landings']??0), (int)($input['qty_mailings']??0),
            (int)($input['qty_creativos_paid']??0), (int)($input['qty_assets_rrss']??0),
            (int)($input['qty_assets_mobile']??0),
            !empty($input['tiempo_entrega_dias']) ? (int)$input['tiempo_entrega_dias'] : null,
            'activo', $parentId, $editType,
            $currentUser['id'],
            json_encode($editReasons),
            $notes !== '' ? mb_substr($notes, 0, 1000) : null,
        ]);
        $newId = $db->lastInsertId();
        $newItem = $db->prepare("SELECT * FROM g7_content WHERE id=?");
        $newItem->execute([$newId]);
        $item = $newItem->fetch();
        $item['projects'] = json_decode($item['projects']??'[]', true);
        $item['start'] = $item['start_date']??'';
        $item['end']   = $item['end_date']??'';
        $item['qty_graficas']       = (int)($item['qty_graficas']??0);
        $item['qty_videos']         = (int)($item['qty_videos']??0);
        $item['qty_landings']       = (int)($item['qty_landings']??0);
        $item['qty_mailings']       = (int)($item['qty_mailings']??0);
        $item['qty_creativos_paid'] = (int)($item['qty_creativos_paid']??0);
        $item['qty_assets_rrss']    = (int)($item['qty_assets_rrss']??0);
        $item['qty_assets_mobile']  = (int)($item['qty_assets_mobile']??0);
        $item['content_type']       = $item['content_type']??'';
        $item['estado']             = $item['estado']??'activo';
        $item['parent_id']          = (int)($item['parent_id']??0);
        $item['edit_type']          = $item['edit_type']??null;
        $item['created_by']         = (int)($item['created_by']??0);
        $item['collaborators']      = [];
        $item['like_count']         = 0;
        $item['liked']              = false;
        $item['comment_count']      = 0;
        if (function_exists('g7ProcessContentUpload')) { g7ProcessContentUpload($db, $currentUser['id'], $item); }
        echo json_encode(['ok'=>true, 'item'=>$item]);
        exit;
    }

    if ($act==='get_resources') {
        $cid=(int)($input['content_id']??0);
        try {
            $q=$db->prepare("SELECT * FROM g7_content_resources WHERE content_id=? ORDER BY sort_order ASC");
            $q->execute([$cid]);
            echo json_encode(['status'=>'success','resources'=>$q->fetchAll()]);
        } catch(Exception $e) { echo json_encode(['status'=>'success','resources'=>[]]); }
        exit;
    }
    if ($act==='add_collaborator') {
        $cid=(int)($input['content_id']??0);$uid=(int)($input['user_id']??0);
        if($cid&&$uid){$db->prepare('INSERT IGNORE INTO g7_content_collaborators (content_id,user_id,added_by) VALUES (?,?,?)')->execute([$cid,$uid,$currentUser['id']]);$u=$db->prepare('SELECT id,username,display_name,avatar FROM g7_users WHERE id=?');$u->execute([$uid]);echo json_encode(['status'=>'success','user'=>$u->fetch()]);}
        else echo json_encode(['error'=>'missing']); exit;
    }
    if ($act==='remove_collaborator') {
        $cid=(int)($input['content_id']??0);$uid=(int)($input['user_id']??0);
        if($cid&&$uid) $db->prepare('DELETE FROM g7_content_collaborators WHERE content_id=? AND user_id=?')->execute([$cid,$uid]);
        echo json_encode(['status'=>'success']); exit;
    }
    if ($act==='save_monday_task') {
        $cid=(int)($input['content_id']??0);
        if($cid) $db->prepare('UPDATE g7_content SET monday_task_id=?,monday_task_name=?,monday_task_url=? WHERE id=?')->execute([trim($input['monday_task_id']??''),trim($input['monday_task_name']??''),trim($input['monday_task_url']??''),$cid]);
        echo json_encode(['status'=>'success']); exit;
    }
    if ($act==='get_content') {
        $cid=(int)($input['content_id']??0);
        $item=$db->query("SELECT * FROM g7_content WHERE id=$cid")->fetch();
        if(!$item){echo json_encode(['error'=>'not found']);exit;}
        $item['projects']=json_decode($item['projects']?:'[]',true);
        $comments=$db->prepare("SELECT c.*,u.username,u.display_name,u.avatar,u.role FROM g7_content_comments c JOIN g7_users u ON u.id=c.author_id WHERE c.content_id=? ORDER BY c.created_at ASC");$comments->execute([$cid]);
        $lc=$db->query("SELECT COUNT(*) FROM g7_content_likes WHERE content_id=$cid")->fetchColumn();
        $ml=$db->prepare("SELECT 1 FROM g7_content_likes WHERE content_id=? AND user_id=?");$ml->execute([$cid,$currentUser['id']]);
        $collabs=$db->prepare("SELECT u.id,u.username,u.display_name,u.avatar FROM g7_content_collaborators c JOIN g7_users u ON u.id=c.user_id WHERE c.content_id=?");$collabs->execute([$cid]);
        echo json_encode(['item'=>$item,'comments'=>$comments->fetchAll(),'likes'=>(int)$lc,'liked'=>(bool)$ml->fetch(),'collaborators'=>$collabs->fetchAll()]); exit;
    }
    if ($act==='toggle_like') {
        $cid=(int)($input['content_id']??0);
        $chk=$db->prepare("SELECT 1 FROM g7_content_likes WHERE content_id=? AND user_id=?");$chk->execute([$cid,$currentUser['id']]);
        if($chk->fetch()) $db->prepare("DELETE FROM g7_content_likes WHERE content_id=? AND user_id=?")->execute([$cid,$currentUser['id']]);
        else $db->prepare("INSERT INTO g7_content_likes (content_id,user_id) VALUES (?,?)")->execute([$cid,$currentUser['id']]);
        $chk2=$db->prepare("SELECT 1 FROM g7_content_likes WHERE content_id=? AND user_id=?");$chk2->execute([$cid,$currentUser['id']]);
        $lc=$db->query("SELECT COUNT(*) FROM g7_content_likes WHERE content_id=$cid")->fetchColumn();
        echo json_encode(['status'=>'success','liked'=>(bool)$chk2->fetch(),'likes'=>(int)$lc]); exit;
    }
    if ($act==='add_content_comment') {
        $cid=(int)($input['content_id']??0);$parentId=$input['parent_id']?(int)$input['parent_id']:null;$body=trim($input['body']??'');
        if($cid&&$body){
            $db->prepare("INSERT INTO g7_content_comments (content_id,author_id,parent_id,body) VALUES (?,?,?,?)")->execute([$cid,$currentUser['id'],$parentId,$body]);
            $newId=$db->lastInsertId();
            $row=$db->prepare("SELECT c.*,u.username,u.display_name,u.avatar,u.role FROM g7_content_comments c JOIN g7_users u ON u.id=c.author_id WHERE c.id=?");$row->execute([$newId]);
            preg_match_all('/@(\w+)/',$body,$men);
            foreach(array_unique($men[1]) as $mu){$mur=$db->prepare("SELECT id FROM g7_users WHERE username=?");$mur->execute([$mu]);$muu=$mur->fetch();if($muu&&$muu['id']!=$currentUser['id'])$db->prepare("INSERT INTO g7_notifications (user_id,from_id,type,target_id,profile_id,body) VALUES (?,?,'mention',?,?,?)")->execute([$muu['id'],$currentUser['id'],$newId,$currentUser['id'],mb_substr($body,0,280)]);}
            if($parentId){$par=$db->prepare("SELECT author_id FROM g7_content_comments WHERE id=?");$par->execute([$parentId]);$pr=$par->fetch();if($pr&&$pr['author_id']!=$currentUser['id'])$db->prepare("INSERT INTO g7_notifications (user_id,from_id,type,target_id,profile_id,body) VALUES (?,?,'reply',?,?,?)")->execute([$pr['author_id'],$currentUser['id'],$newId,$currentUser['id'],mb_substr($body,0,280)]);}
            echo json_encode(['status'=>'success','comment'=>$row->fetch()]); exit;
        }
        echo json_encode(['error'=>'missing']); exit;
    }
    if ($act==='delete_content_comment') {
        $cid=(int)($input['comment_id']??0);
        $row=$db->prepare("SELECT author_id FROM g7_content_comments WHERE id=?");$row->execute([$cid]);$c=$row->fetch();
        if($c&&($c['author_id']==$currentUser['id']||$isAdmin)) $db->prepare("DELETE FROM g7_content_comments WHERE id=? OR parent_id=?")->execute([$cid,$cid]);
        echo json_encode(['status'=>'success']); exit;
    }
    echo json_encode(['error'=>'unknown']); exit;
}


// ── Arma cada contenido para el navegador. Consultas agrupadas: 4 en total, no 4 por contenido. ──
function promos_enrich(PDO $db, array $rows, int $uid): array {
    if (!$rows) return [];
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
    $collabs = $likes = $mine = $comments = [];
    try { foreach ($db->query("SELECT c.content_id, u.id, u.username, u.display_name, u.avatar FROM g7_content_collaborators c JOIN g7_users u ON u.id=c.user_id WHERE c.content_id IN ($ids)") as $c) { $cid = $c['content_id']; unset($c['content_id']); $collabs[$cid][] = $c; } } catch (Exception $e) {}
    try { foreach ($db->query("SELECT content_id, COUNT(*) n, SUM(user_id=$uid) mine FROM g7_content_likes WHERE content_id IN ($ids) GROUP BY content_id") as $l) { $likes[$l['content_id']] = (int)$l['n']; $mine[$l['content_id']] = (int)$l['mine'] > 0; } } catch (Exception $e) {}
    try { foreach ($db->query("SELECT content_id, COUNT(*) n FROM g7_content_comments WHERE content_id IN ($ids) GROUP BY content_id") as $c) $comments[$c['content_id']] = (int)$c['n']; } catch (Exception $e) {}
    $ints = ['qty_graficas','qty_videos','qty_landings','qty_mailings','qty_creativos_paid','qty_assets_rrss','qty_assets_mobile','parent_id','qty_tareas','created_by'];
    return array_map(function ($r) use ($collabs, $likes, $mine, $comments, $ints) {
        $r['projects'] = json_decode($r['projects'] ?: '[]', true) ?: [];
        $r['start'] = $r['start_date'] ?? ''; $r['end'] = $r['end_date'] ?? '';
        foreach (['img','custom_field','collaborator','monday_task_id','monday_task_name','monday_task_url','content_type','mailing'] as $k) $r[$k] = $r[$k] ?? '';
        foreach ($ints as $k) $r[$k] = (int)($r[$k] ?? 0);
        $r['estado'] = $r['estado'] ?? 'activo';
        $r['edit_type'] = $r['edit_type'] ?? null;
        $r['collaborators'] = $collabs[$r['id']] ?? [];
        $r['like_count'] = $likes[$r['id']] ?? 0;
        $r['liked'] = $mine[$r['id']] ?? false;
        $r['comment_count'] = $comments[$r['id']] ?? 0;
        return $r;
    }, $rows);
}

// ── LOAD DATA ─────────────────────────────────────────────
$totalItems = (int)$db->query("SELECT COUNT(*) FROM g7_content WHERE $bWhere")->fetchColumn();
$rows = $db->query("SELECT * FROM g7_content WHERE $bWhere ORDER BY id DESC")->fetchAll();
$items = promos_enrich($db, $rows, (int)$currentUser['id']);

$itemsJSON = json_encode($items, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$authors   = $db->query("SELECT username FROM g7_users WHERE active=1 ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);
$usersJSON = json_encode($db->query("SELECT id,username,display_name,avatar FROM g7_users WHERE active=1 ORDER BY username")->fetchAll(), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= function_exists('g7_term') && isset(g7_cfg_json('vocab', [])['content']) ? htmlspecialchars(g7_term('content', 'p')) : 'Promos CMS' ?> - <?= htmlspecialchars(g7_site('short')) ?></title>
<link rel="stylesheet" href="/toolz/g7-theme.css?v=4">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha384-3B6NwesSXE7YJlcLI9RpRqGf2p/EgVH8BgoKTaUrmKNDkHPStTQ3EyoYjCGXaOTS" crossorigin="anonymous">
<style>
:root{--brand:var(--g7-accent)}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--g7-bg);color:var(--g7-text);font-family:'Inter',sans-serif;overflow:hidden;height:100vh;display:flex;flex-direction:column}
.inner-bar{padding:10px 18px;border-bottom:1px solid var(--g7-border-3);display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex-shrink:0;background:var(--g7-bg-2)}
.inner-bar h2{font-size:14px;font-weight:700;flex:1;white-space:nowrap}
.inner-bar h2 span{color:var(--brand)}
.search-wrap{position:relative;display:flex;align-items:center}
.si{position:absolute;left:9px;width:13px;height:13px;color:var(--g7-text-4);pointer-events:none}
.search-input{background:var(--bg3);border:1px solid var(--brd);color:var(--txt);border-radius:7px;padding:7px 10px 7px 28px;font-size:12px;outline:none;width:200px}
.search-input:focus{border-color:var(--brand)}
.view-toggle{display:flex;border:1px solid var(--brd);border-radius:6px;overflow:hidden}
.view-toggle button{padding:6px 10px;background:none;border:none;color:var(--txt3);cursor:pointer;transition:all .15s}
.view-toggle button.active{background:var(--brd2);color:var(--txt)}
.filter-bar{display:flex;align-items:center;gap:6px;padding:7px 18px;border-bottom:1px solid var(--brd);flex-wrap:wrap;flex-shrink:0}
.flt,.dflt{background:var(--bg3);border:1px solid var(--brd);color:var(--txt);border-radius:6px;padding:5px 8px;font-size:11px;outline:none}
.flt:focus,.dflt:focus{border-color:var(--brand)}
.clear-btn{background:none;border:1px solid var(--brd);color:var(--txt3);border-radius:6px;padding:5px 9px;font-size:11px;cursor:pointer;transition:all .15s}
.clear-btn:hover{color:var(--txt);border-color:var(--txt3)}
.main-layout{display:flex;flex:1;overflow:hidden}
.sidebar{width:220px;min-width:220px;border-right:1px solid var(--brd);overflow-y:auto;padding:14px 10px;flex-shrink:0;transition:width .25s;position:relative;background:color-mix(in srgb, var(--g7-bg-2) 60%, transparent)}
.sidebar.collapsed{width:36px!important;min-width:36px;padding:0;overflow:hidden}.collapsed .sb-btn,.collapsed .sb-section,.collapsed .sb-label,.collapsed .badge,.collapsed .new-content-btn{display:none!important}.collapsed .collapse-btn{border-top:none;width:36px;padding:8px 0}
.sidebar::-webkit-scrollbar{width:3px}
.sidebar::-webkit-scrollbar-thumb{background:var(--brd2)}
.sb-section{font-size:9px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.12em;padding:16px 6px 5px;display:flex;align-items:center;gap:8px}
.sb-section::after{content:'';flex:1;height:1px;background:var(--brd)}
.sb-btn{width:100%;display:flex;align-items:center;gap:6px;padding:6px 8px;border-radius:7px;border:none;background:none;color:var(--txt2);font-size:12px;cursor:pointer;transition:all .15s;text-align:left}
.sb-btn:hover{background:rgba(var(--g7-ov),.04);color:var(--txt)}
.sb-btn.active{background:rgba(var(--g7-accent-rgb),.1);color:var(--g7-accent);font-weight:600}
#s-active.active{background:rgba(34,197,94,.1);color:#22c55e}
#s-inactive.active{background:rgba(82,82,91,.12);color:var(--g7-text-2)}
#t-launch.active{background:rgba(139,92,246,.1);color:#a78bfa}
#t-paid.active{background:rgba(34,197,94,.1);color:#4ade80}
#t-social.active{background:rgba(59,130,246,.1);color:#60a5fa}
#t-tarea.active{background:rgba(251,191,36,.1);color:#fbbf24}
#t-mailings.active{background:rgba(244,114,182,.1);color:#f472b6}
.sb-label{flex:1;text-align:left}
.badge{font-size:9px;font-weight:700;color:var(--g7-text-3);background:rgba(var(--g7-ov),.05);border:1px solid rgba(var(--g7-ov),.07);border-radius:10px;padding:1px 6px;min-width:20px;text-align:center}
.collapse-btn{width:100%;padding:8px;background:none;border:none;border-top:1px solid var(--brd);color:var(--txt3);cursor:pointer;font-size:10px;transition:color .15s;margin-top:auto}
.collapse-btn:hover{color:var(--txt)}
.content{flex:1;overflow-y:auto;padding:14px 18px}
.content::-webkit-scrollbar{width:3px}
.content::-webkit-scrollbar-thumb{background:var(--brd2)}
.grid-view{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:11px}
.list-view{display:flex;flex-direction:column;gap:7px}
.item-card{background:var(--bg3);border:1px solid var(--brd);border-radius:12px;overflow:hidden;cursor:pointer;transition:border-color .2s,box-shadow .2s,transform .2s}
.item-card:hover{border-color:var(--brand);box-shadow:0 0 18px rgba(var(--g7-accent-rgb),.1);transform:translateY(-2px)}
.item-card.inactive{opacity:.5;border-style:dashed}
.item-card[data-type="promo"]{border:2px solid var(--g7-border-2);border-top:2px solid rgba(var(--g7-accent-rgb),.7)}
.item-card[data-type="launch"]{border:2px solid var(--g7-border-2);border-top:2px solid rgba(139,92,246,.6)}
.item-card[data-type="paid"]{border:2px solid var(--g7-border-2);border-top:2px solid rgba(74,222,128,.6)}
.item-card[data-type="social"]{border:2px solid var(--g7-border-2);border-top:2px solid rgba(59,130,246,.6)}
.item-card[data-type="tarea"]{border:2px solid var(--g7-border-2);border-top:2px solid rgba(251,191,36,.6)}
.item-card[data-type="mailings"]{border:2px solid var(--g7-border-2);border-top:2px solid rgba(244,114,182,.6)}
.card-img-g{width:100%;height:120px;background:var(--g7-bg-2);display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative}
.card-img-g img{width:100%;height:100%;object-fit:cover;object-position:top}
.card-body-g{padding:12px 14px}
.card-list{display:flex;align-items:center}
.card-img-l{width:70px;height:52px;flex-shrink:0;background:var(--g7-bg-2);display:flex;align-items:center;justify-content:center;overflow:hidden}
.card-img-l img{width:100%;height:100%;object-fit:cover}
.card-body-l{padding:8px 12px;flex:1;display:flex;align-items:center;gap:12px;min-width:0}
.type-tag{font-size:8px;font-weight:700;border-radius:4px;padding:2px 6px;text-transform:uppercase;display:inline-block;letter-spacing:.04em}
.type-promo{background:rgba(var(--g7-accent-rgb),.15);color:var(--brand);border:1px solid rgba(var(--g7-accent-rgb),.25)}
.type-launch{background:rgba(139,92,246,.12);color:#a78bfa;border:1px solid rgba(139,92,246,.22)}
.type-paid{background:rgba(34,197,94,.12);color:#4ade80;border:1px solid rgba(34,197,94,.22)}
.type-social{background:rgba(59,130,246,.12);color:#60a5fa;border:1px solid rgba(59,130,246,.22)}
.type-otro{background:rgba(161,161,170,.1);color:var(--g7-text-2);border:1px solid rgba(161,161,170,.18)}
.type-tarea{background:rgba(251,191,36,.1);color:#fbbf24;border:1px solid rgba(251,191,36,.2)}
.type-mailings{background:rgba(244,114,182,.1);color:#f472b6;border:1px solid rgba(244,114,182,.2)}
.proj-tag{font-size:8px;font-weight:600;border:1px solid var(--brd2);color:var(--txt3);border-radius:4px;padding:2px 6px;text-transform:uppercase;display:inline-block;background:transparent}
.status-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.status-pill.on{background:rgba(34,197,94,.1);color:#22c55e;border:1px solid rgba(34,197,94,.2)}
.status-pill.off{background:rgba(82,82,91,.12);color:var(--g7-text-3);border:1px solid rgba(82,82,91,.2)}
.status-pill .sdot{width:5px;height:5px;border-radius:50%;flex-shrink:0;display:inline-block}
.status-pill.on .sdot{background:#22c55e}
.status-pill.off .sdot{background:#71717a}
.chip{border-radius:4px;padding:2px 6px;font-size:9px;font-weight:600;text-transform:uppercase;white-space:nowrap;display:inline-block}
.chip-cat{background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.2);color:#60a5fa}
.chip-media{background:rgba(167,139,250,.1);border:1px solid rgba(167,139,250,.2);color:#a78bfa}
.chip-author{background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.18);color:#d4a017}
.card-dates{display:flex;align-items:center;gap:5px;font-size:10px;color:var(--g7-text-3);margin-bottom:7px;background:rgba(var(--g7-ov),.03);border-radius:5px;padding:3px 7px;width:fit-content}
.card-dates i{font-size:9px;color:var(--g7-text-4)}
.links-row{display:flex;gap:5px;margin-top:8px;flex-wrap:wrap}
.link-chip{padding:3px 9px;background:rgba(var(--g7-accent-rgb),.07);border:1px solid rgba(var(--g7-accent-rgb),.2);border-radius:4px;font-size:9px;font-weight:700;color:#c05500;text-decoration:none;transition:all .15s}
.link-chip:hover{background:rgba(var(--g7-accent-rgb),.15);color:var(--g7-accent);border-color:rgba(var(--g7-accent-rgb),.4)}
.empty{text-align:center;color:var(--txt3);padding:50px 20px;font-size:13px}
.panel-overlay{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:50;display:flex;justify-content:flex-end;backdrop-filter:blur(4px)}
.detail-panel{width:700px;max-width:95vw;background:var(--bg2);border-left:1px solid var(--brd);height:100%;display:flex;flex-direction:row}
.dp-left{width:300px;min-width:260px;border-right:1px solid var(--brd);display:flex;flex-direction:column;overflow-y:auto}
.dp-left::-webkit-scrollbar{width:3px}
.dp-left::-webkit-scrollbar-thumb{background:var(--brd2)}
.dp-right{flex:1;display:flex;flex-direction:column;min-height:0}
.panel-head{padding:13px 14px;border-bottom:1px solid var(--brd);display:flex;align-items:center;gap:6px;flex-shrink:0;flex-wrap:wrap}
.panel-close{background:none;border:none;color:var(--txt3);cursor:pointer;font-size:16px;padding:0;margin-left:auto;transition:color .15s}
.panel-close:hover{color:var(--txt)}
.panel-img{width:100%;height:160px;object-fit:cover;flex-shrink:0}
.panel-body{padding:14px;flex:1}
.panel-label{font-size:9px;font-weight:700;color:var(--txt3);letter-spacing:.1em;text-transform:uppercase;margin-bottom:3px}
.panel-value{font-size:13px;color:var(--txt);margin-bottom:12px}
.panel-link{display:block;padding:9px 11px;background:var(--bg3);border:1px solid var(--brd);border-radius:7px;color:var(--txt);text-decoration:none;font-size:11px;margin-bottom:7px;transition:border-color .15s;word-break:break-all}
.panel-link:hover{border-color:var(--brand);color:var(--brand)}
.lm-list{display:grid;gap:6px}
.lm-row{display:flex;gap:6px;align-items:center}
.lm-row input{flex:1;min-width:0}
.lm-del{flex:none;width:30px;height:30px;border-radius:7px;border:1px solid var(--g7-border-3);background:transparent;color:var(--g7-text-3);cursor:pointer;transition:color .15s,border-color .15s}
.lm-del:hover{color:#ef4444;border-color:rgba(239,68,68,.5)}
.lm-add{justify-self:start;background:none;border:1px dashed var(--g7-border-3);color:var(--g7-text-3);border-radius:7px;padding:5px 10px;font-size:11px;font-weight:600;cursor:pointer;transition:color .15s,border-color .15s}
.lm-add:hover{color:var(--g7-accent);border-color:var(--g7-accent)}
.panel-link .ll{font-weight:700;color:var(--txt3);font-size:9px;text-transform:uppercase;display:block;margin-bottom:2px}
.del-btn{padding:9px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:#ef4444;border-radius:7px;font-size:11px;font-weight:600;cursor:pointer;transition:all .15s;flex:1}
.del-btn:hover{background:rgba(239,68,68,.18)}
/* ── FORM MODAL ───────────────────────────────────────── */
.form-view{
  position:fixed;inset:0;z-index:200;
  display:flex;align-items:center;justify-content:center;
  background:rgba(0,0,0,.6);
  backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);
  padding:20px;overflow-y:auto;
  animation:fvIn .22s ease both;
}
.form-view.hidden{display:none!important}
.form-view.closing{animation:fvOut .2s ease both}
@keyframes fvIn  {from{opacity:0}to{opacity:1}}
@keyframes fvOut {from{opacity:1}to{opacity:0}}

.form-card{
  background:
    linear-gradient(145deg, rgba(var(--g7-accent-rgb),.07) 0%, transparent 38%),
    linear-gradient(color-mix(in srgb, var(--g7-bg-2) 97%, transparent), color-mix(in srgb, var(--g7-bg-2) 96%, transparent));
  border:1px solid transparent;
  border-top-color:rgba(var(--g7-accent-rgb),.22);
  border-left-color:rgba(var(--g7-ov),.07);
  border-right-color:rgba(var(--g7-ov),.05);
  border-bottom-color:rgba(var(--g7-ov),.04);
  border-radius:20px;
  width:100%;max-width:720px;max-height:90vh;
  backdrop-filter:blur(32px) saturate(1.4);-webkit-backdrop-filter:blur(32px) saturate(1.4);
  box-shadow:
    0 40px 80px rgba(0,0,0,.7),
    0 0 0 1px rgba(var(--g7-accent-rgb),.08),
    inset 0 1px 0 rgba(var(--g7-ov),.06);
  animation:fcIn .3s cubic-bezier(.34,1.4,.64,1) both;
  display:flex;flex-direction:column;overflow:hidden;
}
/* ── Modal header / body / footer ── */
.fc-head{
  flex-shrink:0;padding:20px 24px 16px;
  border-bottom:1px solid rgba(var(--g7-ov),.06);
  display:flex;align-items:center;justify-content:space-between;
  background:linear-gradient(180deg,rgba(var(--g7-accent-rgb),.04) 0%,transparent 100%);
}
.fc-head-title{font-size:15px;font-weight:800;display:flex;align-items:center;gap:9px;letter-spacing:-.01em}
.fc-head-title i{color:var(--brand);font-size:14px}
.fc-head-subtitle{font-size:10px;font-weight:400;color:rgba(var(--g7-ov),.3);margin-left:3px;letter-spacing:normal}
.fc-close{
  background:rgba(var(--g7-ov),.06);border:none;color:var(--g7-text-3);
  width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-size:13px;cursor:pointer;transition:all .15s;flex-shrink:0;
}
.fc-close:hover{background:rgba(var(--g7-ov),.12);color:var(--g7-text)}
.fc-body{flex:1;overflow-y:auto;padding:18px 22px;min-height:0;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.1) transparent}
.fc-foot{
  flex-shrink:0;padding:14px 22px;
  border-top:1px solid rgba(var(--g7-ov),.06);
  display:flex;gap:10px;align-items:center;
}
.fc-cancel-btn{
  background:rgba(var(--g7-ov),.05);border:1px solid rgba(var(--g7-ov),.08);
  color:var(--g7-text-3);border-radius:9px;padding:11px 18px;
  font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;
  cursor:pointer;transition:all .15s;white-space:nowrap;
}
.fc-cancel-btn:hover{background:rgba(var(--g7-ov),.1);color:var(--g7-text)}
.form-view.closing .form-card{animation:fcOut .2s ease both}
@keyframes fcIn  {from{opacity:0;transform:scale(.92) translateY(22px)}to{opacity:1;transform:scale(1) translateY(0)}}
@keyframes fcOut {from{opacity:1;transform:scale(1) translateY(0)}to{opacity:0;transform:scale(.94) translateY(12px)}}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1/-1}
.form-group label{font-size:9px;font-weight:700;color:rgba(var(--g7-ov),.38);text-transform:uppercase;letter-spacing:.1em;display:flex;align-items:center;gap:5px}
.form-group input,.form-group select,.form-group textarea{
  background:rgba(var(--g7-ov),.05);
  border:1px solid rgba(var(--g7-ov),.09);
  color:var(--g7-text);border-radius:9px;padding:10px 13px;font-size:12px;
  outline:none;width:100%;transition:background .15s,border-color .15s,box-shadow .15s;
  font-family:inherit;
}
.form-group input::placeholder,.form-group textarea::placeholder{color:rgba(var(--g7-ov),.22)}
.form-group input:hover,.form-group select:hover,.form-group textarea:hover{background:rgba(var(--g7-ov),.08);border-color:rgba(var(--g7-ov),.15)}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{
  background:rgba(var(--g7-accent-rgb),.06);border-color:rgba(var(--g7-accent-rgb),.5);
  box-shadow:0 0 0 3px rgba(var(--g7-accent-rgb),.1);
}
.form-group input[type="file"]{padding:7px 10px;cursor:pointer}
.form-group select option{background:var(--g7-bg-3);color:var(--g7-text)}
.form-section{
  font-size:9px;font-weight:700;color:rgba(var(--g7-accent-rgb),.7);letter-spacing:.12em;text-transform:uppercase;
  grid-column:1/-1;padding:12px 0 4px;border-top:1px solid rgba(var(--g7-ov),.06);margin-top:6px;
  display:flex;align-items:center;gap:7px;
}
.form-section::before{content:'';display:block;width:3px;height:10px;background:var(--brand);border-radius:2px;opacity:.7}
.toggle-row{display:flex;border:1px solid rgba(var(--g7-ov),.09);border-radius:9px;overflow:hidden;background:rgba(var(--g7-ov),.03)}
.toggle-row button{flex:1;padding:9px;background:none;border:none;color:rgba(var(--g7-ov),.35);font-size:11px;font-weight:600;cursor:pointer;transition:all .15s}
.toggle-row button.active{background:rgba(var(--g7-ov),.1);color:var(--g7-text)}
.submit-btn{flex:1;background:linear-gradient(135deg,var(--g7-accent-hover),var(--g7-accent-hover));color:#000;border:none;border-radius:9px;padding:12px;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;transition:all .18s;box-shadow:0 4px 20px rgba(var(--g7-accent-rgb),.3),inset 0 1px 0 rgba(var(--g7-ov),.2)}
.submit-btn:hover{transform:translateY(-1px);box-shadow:0 8px 28px rgba(var(--g7-accent-rgb),.45),inset 0 1px 0 rgba(var(--g7-ov),.2)}
.submit-btn:active{transform:translateY(0);box-shadow:0 2px 10px rgba(var(--g7-accent-rgb),.3)}
/* contadores de producción */
.cx-block{margin-top:14px;padding:12px 14px;border:1px solid var(--g7-border-3);border-radius:12px;background:var(--g7-bg-3)}
.cx-yes{display:flex;align-items:center;gap:9px;font-size:12.5px;font-weight:600;color:var(--g7-text);cursor:pointer;user-select:none}
.cx-yes input{width:16px;height:16px;margin:0;accent-color:#f59e0b;cursor:pointer}
.cx-row{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.cx-q{font-size:12.5px;font-weight:600;color:var(--g7-text)}
.cx-yn{display:inline-flex;border:1px solid var(--g7-border-3);border-radius:8px;overflow:hidden;background:var(--g7-bg-4);flex-shrink:0}
.cx-yn button{background:none;border:none;padding:5px 16px;font:600 12px var(--g7-font,inherit);color:var(--g7-text-3);cursor:pointer;transition:background .15s,color .15s}
.cx-yn button+button{border-left:1px solid var(--g7-border-3)}
.cx-yn button:hover{color:var(--g7-text)}
.cx-yn button[data-v="0"].on{background:rgba(var(--g7-ov),.1);color:var(--g7-text)}
.cx-yn button[data-v="1"].on{background:rgba(245,158,11,.16);color:#f59e0b}
.cx-hint{font-size:11px;color:var(--g7-text-4);margin:3px 0 0 25px}
.cx-opts{display:flex;flex-wrap:wrap;gap:6px;margin-top:11px}
.cx-opt{display:inline-flex;align-items:center;gap:6px;padding:6px 11px;border:1px solid var(--g7-border-3);border-radius:999px;font-size:11.5px;color:var(--g7-text-2);background:var(--g7-bg-4);cursor:pointer;user-select:none;transition:border-color .15s,background .15s,color .15s}
.cx-opt input{display:none}
.cx-opt i{font-size:10px;display:none}
.cx-opt:hover{border-color:var(--g7-text-4);color:var(--g7-text)}
.cx-opt.on{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.5);color:#f59e0b}
.cx-opt.on i{display:inline}
.cx-note{width:100%;margin-top:9px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:8px;color:var(--g7-text);padding:8px 10px;font-size:12px;outline:none;box-sizing:border-box}
.cx-note:focus{border-color:rgba(245,158,11,.6)}
.cx-off{display:none!important}
.cx-opt.on.rej{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.5);color:#f87171}
.cx-chip.rej{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.3);color:#f87171}
.em-links-info{display:flex;flex-direction:column;gap:5px;margin-bottom:12px;padding:10px 12px;border:1px solid var(--g7-border-3);border-radius:10px;background:var(--g7-bg-3)}
.em-li-head{font-size:9px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.08em;margin-bottom:2px}
.em-li-row{display:flex;align-items:center;gap:8px;font-size:11.5px;color:var(--g7-text-2);text-decoration:none;min-width:0}
.em-li-row:hover .em-li-v{color:var(--g7-accent)}
.em-li-row i{width:14px;text-align:center;color:var(--g7-text-4);flex-shrink:0}
.em-li-k{font-weight:600;color:var(--g7-text);flex-shrink:0}
.em-li-v{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--g7-text-3)}
.em-li-none{font-size:11.5px;color:var(--g7-text-4)}
.cx-chips{display:flex;flex-wrap:wrap;gap:5px}
.cx-chip{font-size:10.5px;padding:3px 9px;border-radius:999px;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);color:#f59e0b}
.prod-section{border-top:1px solid rgba(var(--g7-ov),.06);margin-top:14px;padding-top:14px}
.prod-header{font-size:9px;font-weight:700;color:rgba(var(--g7-accent-rgb),.7);text-transform:uppercase;letter-spacing:.12em;margin-bottom:10px;display:flex;align-items:center;gap:7px}
.prod-header::before{content:'';display:block;width:3px;height:10px;background:var(--brand);border-radius:2px;opacity:.7}
.qty-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.qty-item{background:rgba(var(--g7-ov),.03);border:1px solid rgba(var(--g7-ov),.07);border-radius:10px;padding:10px 12px;transition:border-color .15s}
.qty-item:hover{border-color:rgba(var(--g7-ov),.12)}
.qty-label-sm{font-size:9px;font-weight:700;color:rgba(var(--g7-ov),.32);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px}
.qty-ctrl{display:flex;align-items:center;gap:6px}
.qty-btn{width:26px;height:26px;flex-shrink:0;border-radius:6px;border:1px solid rgba(var(--g7-ov),.1);background:rgba(var(--g7-ov),.06);color:var(--g7-text);font-size:15px;line-height:1;cursor:pointer;transition:all .15s;display:flex;align-items:center;justify-content:center}
.qty-btn:hover{background:rgba(var(--g7-ov),.14);border-color:rgba(var(--g7-ov),.22);color:var(--g7-text)}
.qty-inp{flex:1;background:none;border:none;color:var(--g7-text);font-size:17px;font-weight:700;text-align:center;outline:none;width:0;min-width:0;font-family:inherit}
/* fc-foot gradient */
.fc-foot{background:linear-gradient(0deg,rgba(var(--g7-accent-rgb),.05) 0%,transparent 100%)}
/* barra de progreso */
.fc-progress{flex-shrink:0;padding:12px 24px 0;display:flex;align-items:center;gap:10px}
.fc-prog-bar{flex:1;height:4px;background:rgba(var(--g7-ov),.07);border-radius:99px;overflow:hidden}
.fc-prog-fill{height:100%;width:0%;border-radius:99px;transition:width .4s cubic-bezier(.4,0,.2,1),background .3s}
.fc-prog-label{font-size:10px;color:rgba(var(--g7-ov),.28);white-space:nowrap;min-width:80px;text-align:right}
.fc-prog-label span{font-weight:800;transition:color .3s}
.type-tabs{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:18px}
.type-tab{
  padding:9px 6px;border-radius:9px;border:1px solid var(--brd);
  background:rgba(var(--g7-ov),.02);color:var(--txt3);
  font-size:10px;font-weight:700;cursor:pointer;transition:all .15s;
  display:flex;flex-direction:column;align-items:center;gap:5px;
  line-height:1;text-align:center;
}
.type-tab i{font-size:14px;transition:transform .15s}
.type-tab:hover{border-color:rgba(var(--g7-ov),.2);color:var(--txt);background:rgba(var(--g7-ov),.05)}
.type-tab:hover i{transform:scale(1.15)}
/* colores únicos por tipo de contenido */
[data-type="promo"].active  {--tc:var(--g7-accent);border-color:var(--g7-accent);color:var(--g7-accent);background:rgba(var(--g7-accent-rgb),.13);box-shadow:0 0 14px rgba(var(--g7-accent-rgb),.2)}
[data-type="launch"].active {--tc:#a855f7;border-color:#a855f7;color:#a855f7;background:rgba(168,85,247,.13);box-shadow:0 0 14px rgba(168,85,247,.2)}
[data-type="paid"].active   {--tc:#22c55e;border-color:#22c55e;color:#22c55e;background:rgba(34,197,94,.13);box-shadow:0 0 14px rgba(34,197,94,.2)}
[data-type="social"].active {--tc:#3b82f6;border-color:#3b82f6;color:#3b82f6;background:rgba(59,130,246,.13);box-shadow:0 0 14px rgba(59,130,246,.2)}
[data-type="otro"].active   {--tc:#f59e0b;border-color:#f59e0b;color:#f59e0b;background:rgba(245,158,11,.13);box-shadow:0 0 14px rgba(245,158,11,.2)}
[data-type="custom"].active {--tc:#06b6d4;border-color:#06b6d4;color:#06b6d4;background:rgba(6,182,212,.13);box-shadow:0 0 14px rgba(6,182,212,.2)}
[data-type="tarea"].active  {--tc:#ec4899;border-color:#ec4899;color:#ec4899;background:rgba(236,72,153,.13);box-shadow:0 0 14px rgba(236,72,153,.2)}
[data-type="mailings"].active{--tc:#14b8a6;border-color:#14b8a6;color:#14b8a6;background:rgba(20,184,166,.13);box-shadow:0 0 14px rgba(20,184,166,.2)}
.projects-check{display:flex;flex-direction:column;gap:7px;background:var(--bg2);border:1px solid var(--brd);border-radius:7px;padding:10px 12px}
.projects-check label{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--txt2);cursor:pointer;font-weight:normal;text-transform:none;letter-spacing:normal}
.projects-check input[type="checkbox"]{width:14px;height:14px;accent-color:var(--brand);cursor:pointer;flex-shrink:0}
.author-info{background:var(--bg2);border:1px solid var(--brd);border-radius:7px;padding:9px 11px;font-size:12px;color:var(--txt2);display:flex;align-items:center;gap:7px}
.msg-modal{position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:100;display:flex;align-items:center;justify-content:center;padding:20px}
.msg-card{background:var(--bg3);border:1px solid var(--brd);border-radius:14px;padding:22px;max-width:460px;width:100%)}
.msg-card h3{font-size:14px;font-weight:700;margin-bottom:12px}
.msg-card textarea{width:100%;height:150px;background:var(--bg2);border:1px solid var(--brd);color:var(--txt);border-radius:7px;padding:11px;font-size:11px;resize:none;outline:none}
.copy-btn{width:100%;background:var(--brand);color:#000;border:none;border-radius:7px;padding:10px;font-size:12px;font-weight:700;cursor:pointer;margin-top:10px;transition:opacity .15s}
.copy-btn:hover{opacity:.85}
.hidden{display:none!important}
/* Comments in detail */
.dc-comment{margin-bottom:10px}
.dc-bubble{background:var(--bg3);border:1px solid var(--brd);border-radius:9px;padding:9px 11px}
.dc-header{display:flex;align-items:center;gap:7px;margin-bottom:5px;flex-wrap:wrap}
.dc-author{font-size:11px;font-weight:700;color:var(--txt);cursor:pointer}
.dc-author:hover{color:var(--g7-accent)}
.dc-time{font-size:10px;color:var(--txt3);margin-left:auto}
.dc-body{font-size:12px;line-height:1.5;color:var(--txt2);word-break:break-word}
.dc-body .mention{color:var(--g7-accent);font-weight:600}
.dc-actions{display:flex;gap:8px;margin-top:5px}
.dc-btn{background:none;border:none;font-size:10px;color:var(--txt3);cursor:pointer;transition:color .15s;padding:0;display:flex;align-items:center;gap:3px}
.dc-btn:hover{color:var(--txt2)}
.dc-btn.del:hover{color:#ef4444}
.dc-replies{margin-left:16px;margin-top:8px;border-left:2px solid var(--brd);padding-left:10px}
.dc-reply-form{margin-top:6px;margin-left:16px;display:none}
.dc-reply-form.open{display:flex;gap:6px;align-items:flex-start}
.dc-reply-ta{flex:1;background:var(--bg2);border:1px solid var(--brd);color:var(--txt);border-radius:7px;padding:6px 9px;font-size:11px;resize:none;outline:none;min-height:36px;font-family:inherit}
.dc-reply-ta:focus{border-color:var(--brand)}
.dc-send{background:var(--brand);color:#000;border:none;border-radius:6px;padding:6px 10px;font-size:10px;font-weight:700;cursor:pointer;white-space:nowrap;align-self:flex-end}
.dc-no-comments{text-align:center;color:var(--txt3);font-size:12px;padding:20px 0}
.dc-mention-dd{position:absolute;background:var(--bg3);border:1px solid var(--brd);border-radius:9px;z-index:600;display:none;box-shadow:0 4px 30px rgba(0,0,0,.8);overflow:hidden;min-width:180px}
.dc-mention-item{padding:8px 12px;font-size:12px;cursor:pointer;display:flex;align-items:center;gap:8px;transition:background .1s}
.dc-mention-item:hover{background:var(--bg2)}
.role-badge{font-size:8px;font-weight:700;border-radius:3px;padding:2px 5px;text-transform:uppercase}
.rb-admin{background:rgba(var(--g7-accent-rgb),.12);color:var(--g7-accent)}
.rb-user{background:var(--brd);color:var(--txt3)}
.exp-period-btn.active{background:rgba(167,139,250,.12);border-color:rgba(167,139,250,.4);color:#a78bfa}

/* ── TYPE TOP BORDERS ── */
.item-card[data-type="promo"]{border-top:2px solid rgba(var(--g7-accent-rgb),.6)}
.item-card[data-type="launch"]{border-top:2px solid rgba(167,139,250,.6)}
.item-card[data-type="paid"]{border-top:2px solid rgba(74,222,128,.6)}
.item-card[data-type="social"]{border-top:2px solid rgba(96,165,250,.6)}
.item-card[data-type="otro"]{border-top:2px solid rgba(100,100,100,.5)}
.item-card[data-type="tarea"]{border-top:2px solid rgba(96,165,250,.6)}
.item-card[data-type="mailings"]{border-top:2px solid rgba(167,139,250,.6)}
/* ── PANEL DIFFERENTIATION ── */
.detail-panel{background:linear-gradient(160deg,var(--g7-bg-2) 0%,var(--g7-bg-3) 100%)!important;box-shadow:-20px 0 60px rgba(0,0,0,.6)}
.dp-left{background:var(--g7-bg)!important}
.dp-right{background:var(--g7-bg-3)!important}
.panel-head{background:var(--g7-bg)!important;border-bottom:1px solid rgba(var(--g7-accent-rgb),.2)!important;position:relative}
.panel-head::after{content:'';position:absolute;bottom:0;left:0;right:0;height:1px;background:linear-gradient(90deg,rgba(var(--g7-accent-rgb),.4),transparent)}
.panel-body{background:transparent}
.panel-img{border-bottom:1px solid rgba(var(--g7-accent-rgb),.15)}
/* dp-right header */
.dp-right>div:first-child{background:var(--g7-bg)!important;border-bottom-color:rgba(var(--g7-accent-rgb),.15)!important}
/* Comments area */
.dc-bubble{background:var(--g7-bg-3)!important;border-color:var(--g7-border-3)!important}
.dc-reply-ta{background:var(--g7-bg)!important;border-color:var(--g7-border-3)!important}
/* Action buttons at bottom */
.detail-panel [style*="#18181b"]{background:var(--g7-bg-3)!important}
/* Like/comment row */
.detail-panel [style*="border-bottom:1px solid #27272a"]{border-bottom-color:var(--g7-border-3)!important}
/* Sections dividers */
.detail-panel [style*="border-top:1px solid #27272a"]{border-top-color:var(--g7-border-3)!important}
.detail-panel [style*="border-top:1px solid #1a1a1a"]{border-top-color:var(--g7-border-3)!important}
/* Panel label styling */
.panel-label{color:rgba(var(--g7-accent-rgb),.7)!important;font-size:8px!important}
/* Inner grid values */
.panel-value{color:#d4d4f0!important}
/* msg-card fix */
.msg-card{background:var(--g7-bg-3)!important;border-color:var(--g7-border-3)!important}
/* Scrollbar in dp-right */
.dp-right::-webkit-scrollbar{width:3px}
.dp-right::-webkit-scrollbar-thumb{background:var(--g7-bg-5)}

/* ══ Promos v3: tipografía, tarjetas e imágenes ══ */
body{font-family:var(--g7-font,'Inter',sans-serif)}
.inner-bar{padding:14px 20px;background:var(--g7-bg,var(--g7-bg));border-bottom-color:var(--g7-border-2)}
.inner-bar h2{font-size:18px;font-weight:700;letter-spacing:-.02em;flex:0 1 auto}
.pr-results{flex:1;font-size:12px;color:var(--g7-text-3);font-weight:500}
.search-input{height:36px;width:260px;border-radius:10px;font-size:13px;background:var(--g7-bg-3);border-color:var(--g7-border-3);transition:border-color .15s,box-shadow .15s}
.search-input:focus{box-shadow:0 0 0 4px rgba(var(--g7-accent-rgb),.12)}
.view-toggle{border-radius:10px;border-color:var(--g7-border-3)}
.view-toggle button{height:34px;padding:0 12px}
.filter-bar{padding:10px 20px;gap:8px;border-bottom-color:var(--g7-border-2)}
.filter-bar select,.filter-bar input{height:32px;border-radius:9px!important;font-size:12px!important}
.sidebar{background:var(--g7-bg-2);border-right-color:var(--g7-border-2);padding:16px 10px}
.sb-btn{padding:7px 10px;border-radius:9px;font-size:12.5px}
.sb-btn.active{background:rgba(var(--g7-accent-rgb),.1);color:var(--g7-accent-hover)}
.grid-view{grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px}
.item-card{border-radius:16px;border:1px solid var(--g7-border-2);background:var(--g7-bg-3);transition:border-color .2s,transform .2s cubic-bezier(.2,.8,.2,1),box-shadow .2s}
.item-card:hover{border-color:var(--g7-border-3);box-shadow:0 18px 40px rgba(0,0,0,.45);transform:translateY(-2px)}
.item-card[data-type]{border:1px solid var(--g7-border-2)}
.item-card[data-type="promo"]{box-shadow:inset 0 2px 0 rgba(var(--g7-accent-rgb),.75)}
.item-card[data-type="launch"]{box-shadow:inset 0 2px 0 rgba(139,92,246,.7)}
.item-card[data-type="paid"]{box-shadow:inset 0 2px 0 rgba(74,222,128,.7)}
.item-card[data-type="social"]{box-shadow:inset 0 2px 0 rgba(59,130,246,.7)}
.item-card[data-type="tarea"]{box-shadow:inset 0 2px 0 rgba(251,191,36,.7)}
.item-card[data-type="mailings"]{box-shadow:inset 0 2px 0 rgba(244,114,182,.7)}
.item-card.inactive{opacity:.55;border-style:dashed}
.card-img-g{height:auto;aspect-ratio:16/9;background:var(--g7-bg-4)}
.card-img-g img{height:100%!important;transition:transform .4s cubic-bezier(.2,.8,.2,1)}
.item-card:hover .card-img-g img{transform:scale(1.03)}
.card-body-g{padding:14px 15px 12px}
.type-tag,.proj-tag{font-size:10px;letter-spacing:.02em;border-radius:6px;padding:2px 7px}
.proj-tag{text-transform:none;font-weight:500;color:var(--g7-text-2);border-color:var(--g7-border-3)}
.chip{font-size:10.5px;text-transform:none;font-weight:500;border-radius:6px;padding:2px 7px}
.chip-cat,.chip-media,.chip-author{background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-2)}
.status-pill{font-size:10px;text-transform:none;letter-spacing:0;padding:2px 8px}
.link-chip{font-size:10.5px;font-weight:600;border-radius:7px;padding:3px 9px;color:#ff9a57;background:rgba(var(--g7-accent-rgb),.06)}
.pr-noimg{display:flex;align-items:center;justify-content:center;color:rgba(var(--g7-ov),.14);font-size:20px;background:radial-gradient(120% 90% at 30% 20%,rgba(var(--g7-ov),.05),transparent 60%),var(--g7-bg-3)}
.item-card[data-type="promo"] .pr-noimg{background:radial-gradient(120% 90% at 30% 20%,rgba(var(--g7-accent-rgb),.16),transparent 65%),var(--g7-bg-3);color:rgba(255,140,60,.35)}
.item-card[data-type="launch"] .pr-noimg{background:radial-gradient(120% 90% at 30% 20%,rgba(139,92,246,.18),transparent 65%),var(--g7-bg-3);color:rgba(167,139,250,.4)}
.item-card[data-type="paid"] .pr-noimg{background:radial-gradient(120% 90% at 30% 20%,rgba(74,222,128,.14),transparent 65%),var(--g7-bg-3);color:rgba(74,222,128,.35)}
.item-card[data-type="social"] .pr-noimg{background:radial-gradient(120% 90% at 30% 20%,rgba(59,130,246,.16),transparent 65%),var(--g7-bg-3);color:rgba(96,165,250,.4)}
.item-card[data-type="otro"] .pr-noimg,.item-card[data-type="custom"] .pr-noimg{background:radial-gradient(120% 90% at 30% 20%,rgba(245,158,11,.13),transparent 65%),var(--g7-bg-3);color:rgba(251,191,36,.35)}
.multi-toggle{display:flex!important;align-items:flex-start;gap:10px;cursor:pointer;text-transform:none!important;letter-spacing:0!important;padding:11px 12px;border:1px dashed var(--g7-border-3);border-radius:10px;background:var(--g7-bg-3);transition:border-color .15s,background .15s}
.multi-toggle:hover{border-color:var(--g7-border-3)}
.multi-toggle input{accent-color:var(--g7-accent);width:16px;height:16px;margin-top:1px;flex-shrink:0}
.multi-toggle b{display:block;font-size:12.5px;font-weight:600;color:var(--g7-text)}
.multi-toggle small{display:block;font-size:11.5px;font-weight:400;color:var(--g7-text-3);margin-top:2px}
#multi-wrap.on .multi-toggle{border-style:solid;border-color:rgba(var(--g7-accent-rgb),.4);background:rgba(var(--g7-accent-rgb),.05)}
#multi-projects{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:4px 12px}
#multi-projects label{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--g7-text);cursor:pointer;padding:5px 2px;text-transform:none;letter-spacing:0;font-weight:500}
#multi-projects input{accent-color:var(--g7-accent);width:15px;height:15px}
.multi-hint{margin-top:8px;font-size:12px;color:var(--g7-text-2);line-height:1.5}
.multi-hint b{color:#ffb27a;font-weight:600}
.single-off{opacity:.35;pointer-events:none}
.pr-more-wrap{grid-column:1/-1;display:flex;justify-content:center;padding:18px 0 8px}
.pr-more{display:inline-flex;align-items:center;gap:10px;height:40px;padding:0 18px;border-radius:11px;border:1px solid var(--g7-border-3);background:var(--g7-bg-3);color:var(--g7-text);font:600 13px var(--g7-font,inherit);cursor:pointer;transition:border-color .15s,background .15s}
.pr-more:hover{border-color:rgba(var(--g7-accent-rgb),.45);background:var(--g7-bg-4)}
.pr-more span{font-weight:500;color:var(--g7-text-3);font-size:12px}
@media (prefers-reduced-motion:reduce){.item-card,.card-img-g img{transition:none}}
/* Entrega de un pedido (promos/entrega.js) */
.pd-opt{font-weight:500;color:var(--g7-text-5,#777);font-size:.85em;margin-left:4px}
.pd-pick{position:relative}.pd-pick[hidden]{display:none}
.pd-res{position:absolute;left:0;right:0;top:calc(100% + 4px);z-index:60;background:var(--g7-bg-3,#1a1a1d);border:1px solid var(--g7-border-3,#333);border-radius:10px;box-shadow:0 12px 32px rgba(0,0,0,.35);padding:6px;max-height:280px;overflow-y:auto}
.pd-res[hidden]{display:none}
.pd-res button{display:flex;gap:10px;align-items:flex-start;width:100%;text-align:left;border:0;background:none;color:inherit;padding:8px 10px;border-radius:8px;cursor:pointer;font:inherit}
.pd-res button.on,.pd-res button:hover{background:var(--g7-bg-4,#222)}
.pd-res button i{color:var(--g7-accent);margin-top:3px}
.pd-res b,.pd-sel b{display:block;font-size:13px}.pd-res small,.pd-sel small{display:block;font-size:11px;color:var(--g7-text-4,#888)}
.pd-empty{padding:10px;font-size:12px;color:var(--g7-text-4,#888)}
.pd-sel{display:flex;gap:10px;align-items:center;padding:10px 12px;border-radius:10px;border:1px solid rgba(var(--g7-accent-rgb),.35);background:rgba(var(--g7-accent-rgb),.08)}
.pd-sel[hidden]{display:none}.pd-sel>i{color:var(--g7-accent)}.pd-sel span{flex:1;min-width:0}
.pd-x{border:0;background:none;color:var(--g7-text-3,#aaa);cursor:pointer;padding:4px 6px;border-radius:6px}.pd-x:hover{color:var(--g7-text,#fff)}
.pd-help{display:block;margin-top:4px;font-size:11px;color:var(--g7-text-4,#888)}
.pd-toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:9999;background:#16a34a;color:#fff;padding:10px 16px;border-radius:10px;font-size:13px;box-shadow:0 10px 30px rgba(0,0,0,.3);max-width:90vw}
.pd-toast.err{background:#dc2626}.pd-toast a{color:#fff;font-weight:700;margin-left:6px}
</style>
<style id="fc-compact">
/* Formulario de alta más corto (2026-10-26): mismos campos, menos alto */
#form-container .fc-body{padding:14px 20px 16px}
#form-container .fc-progress{padding:0;gap:0}
#form-container .fc-prog-label{display:none}
#form-container .fc-prog-bar{height:2px;border-radius:0}
#form-container .type-tabs{grid-template-columns:repeat(8,1fr);gap:5px;margin-bottom:14px}
#form-container .type-tab{padding:7px 3px;gap:4px;font-size:9.5px;font-weight:600;line-height:1.15}
#form-container .type-tab i{font-size:13px}
#form-container .form-grid{gap:10px 12px}
#form-container .form-group{gap:4px}
#form-container .form-group input,#form-container .form-group select,#form-container .form-group textarea{padding:8px 11px}
#form-container .form-group input[type="file"]{padding:5px 8px}
#form-container .fc-lbl-row{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:18px}
#form-container .fc-mini-link{display:inline-flex;align-items:center;gap:5px;background:none;border:none;padding:0;color:var(--g7-text-3);font-size:11px;font-weight:600;cursor:pointer;font-family:inherit}
#form-container .fc-mini-link:hover{color:var(--g7-accent)}
#form-container .fc-mini-toggle{border-radius:6px}
#form-container .fc-mini-toggle button{padding:2px 9px;font-size:10px}
#form-container .toggle-row button{padding:6px}
#form-container .multi-toggle{padding:8px 11px;align-items:center}
#form-container .multi-toggle small{display:none}
#form-container .projects-check{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:6px 12px;padding:9px 11px}
#form-container .prod-section{margin-top:12px;padding-top:10px}
#form-container .prod-header{margin-bottom:7px}
#form-container .qty-item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:5px 6px 5px 12px}
#form-container .qty-label-sm{margin:0}
#form-container .qty-ctrl{width:118px;flex-shrink:0}
#form-container .qty-inp{font-size:15px}
#form-container .cx-block{margin-top:10px;padding:9px 12px}
@media (max-width:640px){#form-container .type-tabs{grid-template-columns:repeat(4,1fr)}}
@media (max-width:520px){#form-container .qty-grid{grid-template-columns:1fr}}
/* Bordes y fondos al pasar el mouse (antes onmouseover/onmouseout en línea; CSP estricta) */
[data-hov=nc]:hover{background:rgba(255,102,0,.2)!important}
[data-hov=more]:hover{border-color:#ff6600!important}
[data-hov=collab]:hover{border-color:#4ade80!important;color:#4ade80!important}
[data-hov=row]:hover{background:rgba(255,255,255,.02)!important}
[data-hov=em]:hover{background:#18181b!important}
</style>
</head>
<body>
<?php include __DIR__ . '/../topbar.php'; ?>
<style>
#g7-topbar,#g7-topbar.g7-topbar{position:relative!important;top:0!important;flex-shrink:0!important;width:100%!important;margin:0!important;margin-top:-25px!important;z-index:200!important;}
body{padding-top:0!important;}
</style>

<!-- INNER BAR -->
<div class="inner-bar">
  <h2 id="view-title">Todo el <span>contenido</span></h2><span id="pr-results" class="pr-results"></span>
  <div class="search-wrap">
    <svg class="si" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    <input class="search-input" id="searchInput" placeholder="Buscar..." data-on-input="renderItems">
  </div>
  <div class="view-toggle">
    <button id="vt-grid" class="active" data-on-click="setViewMode" data-click-1="s:grid"><i class="fa-solid fa-table-cells" style="font-size:11px"></i></button>
    <button id="vt-list" data-on-click="setViewMode" data-click-1="s:list"><i class="fa-solid fa-list" style="font-size:11px"></i></button>
  </div>
</div>

<!-- FILTER BAR -->
<div class="filter-bar" id="filter-bar">
  <select class="flt" id="filt-type" data-on-change="renderItems">
    <option value="">Tipo</option>
    <option value="promo">Promos / Torneos</option>
    <option value="launch">Lanzamientos</option>
    <option value="paid">Paid</option>
    <option value="social">Redes Sociales</option>
    <option value="otro">Otro</option>
  </select>
  <select class="flt" id="filt-cat" data-on-change="renderItems">
    <option value="">Categoría</option>
    <?php foreach($db->query("SELECT DISTINCT category FROM g7_content WHERE category!='' ORDER BY category") as $cat): ?>
    <option><?= htmlspecialchars($cat['category']) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="flt" id="filt-author" data-on-change="renderItems">
    <option value="">Autor</option>
    <?php foreach($authors as $a): ?>
    <option><?= htmlspecialchars($a) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="flt" id="filt-media" data-on-change="renderItems">
    <option value="">Formato</option>
    <option value="imagen">Imagen</option>
    <option value="video">Video</option>
  </select>
  <input type="date" class="dflt" id="filt-date-from" data-on-change="renderItems" title="Desde">
  <span style="color:var(--g7-text-4);font-size:10px">→</span>
  <input type="date" class="dflt" id="filt-date-to" data-on-change="renderItems" title="Hasta">
  <button class="clear-btn" data-on-click="clearFilters">✕ Limpiar</button>
  <button data-on-click="openExportModal" style="background:rgba(167,139,250,.1);border:1px solid rgba(167,139,250,.3);color:#a78bfa;border-radius:6px;padding:5px 10px;font-size:11px;cursor:pointer;display:flex;align-items:center;gap:5px;white-space:nowrap;transition:all .15s"><i class="fa-solid fa-file-export"></i> Exportar</button>
</div>

<!-- MAIN LAYOUT -->
<div class="main-layout">
  <!-- SIDEBAR -->
  <div class="sidebar" id="sidebar">
    <div class="sb-section">Estado</div>
    <button class="sb-btn active" id="s-all"      data-on-click="setStatus" data-click-1="s:all" data-click-2="this"><i class="fa-solid fa-border-all" style="width:13px;font-size:11px"></i><span class="sb-label">Todos</span><span class="badge" id="cnt-all">0</span></button>
    <button class="sb-btn"        id="s-active"   data-on-click="setStatus" data-click-1="s:active" data-click-2="this"><i class="fa-solid fa-circle-check" style="width:13px;font-size:11px;color:#22c55e"></i><span class="sb-label">Activos</span><span class="badge" id="cnt-active">0</span></button>
    <button class="sb-btn"        id="s-inactive" data-on-click="setStatus" data-click-1="s:inactive" data-click-2="this"><i class="fa-solid fa-circle-xmark" style="width:13px;font-size:11px;color:var(--g7-text-4)"></i><span class="sb-label">Finalizados</span><span class="badge" id="cnt-inactive">0</span></button>
    <div class="sb-section">Tipo</div>
    <button class="sb-btn" id="t-all"    data-on-click="setType" data-click-1="s:all" data-click-2="this"><i class="fa-solid fa-layer-group" style="width:13px;font-size:11px"></i><span class="sb-label">Todos</span><span class="badge" id="cnt-t-all">-</span></button>
    <button class="sb-btn" id="t-promo"  data-on-click="setType" data-click-1="s:promo" data-click-2="this"><i class="fa-solid fa-tag" style="width:13px;font-size:11px;color:var(--g7-accent)"></i><span class="sb-label">Promos</span><span class="badge" id="cnt-t-promo">0</span></button>
    <button class="sb-btn" id="t-launch" data-on-click="setType" data-click-1="s:launch" data-click-2="this"><i class="fa-solid fa-rocket" style="width:13px;font-size:11px;color:#a78bfa"></i><span class="sb-label">Lanzamientos</span><span class="badge" id="cnt-t-launch">0</span></button>
    <button class="sb-btn" id="t-paid"   data-on-click="setType" data-click-1="s:paid" data-click-2="this"><i class="fa-solid fa-dollar-sign" style="width:13px;font-size:11px;color:#4ade80"></i><span class="sb-label">Paid</span><span class="badge" id="cnt-t-paid">0</span></button>
    <button class="sb-btn" id="t-social" data-on-click="setType" data-click-1="s:social" data-click-2="this"><i class="fa-solid fa-share-nodes" style="width:13px;font-size:11px;color:#60a5fa"></i><span class="sb-label">Redes</span><span class="badge" id="cnt-t-social">0</span></button>
    <button class="sb-btn" id="t-otro"   data-on-click="setType" data-click-1="s:otro" data-click-2="this"><i class="fa-solid fa-ellipsis" style="width:13px;font-size:11px;color:var(--g7-text-2)"></i><span class="sb-label">Otro</span><span class="badge" id="cnt-t-otro">0</span></button>
    <button class="sb-btn" id="t-tarea"  data-on-click="setType" data-click-1="s:tarea" data-click-2="this"><i class="fa-solid fa-bolt" style="width:13px;font-size:11px;color:#60a5fa"></i><span class="sb-label" style="margin-left:8px">Tareas rápidas</span></button>
    <button class="sb-btn" id="t-mailings" data-on-click="setType" data-click-1="s:mailings" data-click-2="this"><i class="fa-solid fa-envelope-open-text" style="width:13px;font-size:11px;color:#a78bfa"></i><span class="sb-label" style="margin-left:8px">Mailings</span></button>
    <div class="sb-section">Proyecto</div>
    <button class="sb-btn" id="p-all"       data-on-click="setProject" data-click-1="s:all" data-click-2="this"><i class="fa-solid fa-layer-group" style="width:13px;font-size:10px;color:var(--g7-text-3)"></i><span class="sb-label">Todos</span></button>
    <button class="sb-btn" id="p-otros"     data-on-click="setProject" data-click-1="s:Otros" data-click-2="this"><span style="width:8px;height:8px;border-radius:50%;background:#71717a;flex-shrink:0;display:inline-block"></span><span class="sb-label">Otros</span><span class="badge" id="cnt-p-otros">0</span></button>
    <div style="flex:1"></div>
    <div style="padding:10px 8px">
      <button class="new-content-btn" data-on-click="showView" data-click-1="s:create" style="width:100%;background:rgba(var(--g7-accent-rgb),.1);border:1px solid rgba(var(--g7-accent-rgb),.25);color:var(--g7-accent);border-radius:8px;padding:9px 10px;font-size:11px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;transition:all .15s" data-hov="nc">
        <i class="fa-solid fa-plus" style="font-size:10px"></i><span class="sb-label">Nuevo Contenido</span>
      </button>
    </div>
    <button class="collapse-btn" data-on-click="toggleSidebar" title="Abrir/cerrar panel"><i class="fa-solid fa-chevron-left" id="col-icon" style="transition:transform .2s"></i></button>
  </div>

  <!-- CONTENT -->
  <div class="content" id="content-wrap">
    <div id="form-container" class="form-view hidden">
      <div class="form-card">
        <div class="fc-head">
          <div class="fc-head-title"><i class="fa-solid fa-plus-circle"></i> Nuevo contenido <span class="fc-head-subtitle">Completa los datos del contenido</span></div>
          <button class="fc-close" data-on-click="showView" data-click-1="s:list" title="Cerrar">✕</button>
        </div>
        <div class="fc-progress">
          <div class="fc-prog-bar"><div class="fc-prog-fill" id="fc-prog-fill"></div></div>
          <div class="fc-prog-label"><span id="fc-prog-pct">0%</span> completado</div>
        </div>
        <div class="fc-body">
        <div class="type-tabs">
          <button class="type-tab active" data-type="promo" data-on-click="selectFormType" data-click-1="s:promo" data-click-2="this"><i class="fa-solid fa-tag"></i>Promo / Torneo</button>
          <button class="type-tab" data-type="launch" data-on-click="selectFormType" data-click-1="s:launch" data-click-2="this"><i class="fa-solid fa-rocket"></i>Lanzamiento</button>
          <button class="type-tab" data-type="paid" data-on-click="selectFormType" data-click-1="s:paid" data-click-2="this"><i class="fa-solid fa-dollar-sign"></i>Paid</button>
          <button class="type-tab" data-type="social" data-on-click="selectFormType" data-click-1="s:social" data-click-2="this"><i class="fa-solid fa-share-nodes"></i>Redes Sociales</button>
          <button class="type-tab" data-type="otro" data-on-click="selectFormType" data-click-1="s:otro" data-click-2="this"><i class="fa-solid fa-ellipsis"></i>Otro</button>
          <button class="type-tab" data-type="custom" data-on-click="selectFormType" data-click-1="s:custom" data-click-2="this"><i class="fa-solid fa-puzzle-piece"></i>Custom</button>
          <button class="type-tab" data-type="tarea" data-on-click="selectFormType" data-click-1="s:tarea" data-click-2="this"><i class="fa-solid fa-bolt"></i>Tarea rápida</button>
          <button class="type-tab" data-type="mailings" data-on-click="selectFormType" data-click-1="s:mailings" data-click-2="this"><i class="fa-solid fa-envelope-open-text"></i>Mailings</button>
          
        </div>
        <div class="form-grid">
          <div class="form-group full">
            <div class="fc-lbl-row"><label for="f-name">Nombre</label><button type="button" class="fc-mini-link" data-on-click="openCollabModal" data-click-1="s:new"><i class="fa-solid fa-user-plus"></i>Agregar colaborador</button></div>
            <input type="text" id="f-name" placeholder="Nombre del contenido">
            <!-- Collab + Monday (ocultos) -->
            <input type="hidden" id="f-collaborator">
            <input type="hidden" id="f-monday-task-id">
            <input type="hidden" id="f-monday-task-name">
            <input type="hidden" id="f-monday-task-url">
            <div id="form-link-preview" style="display:none;margin-top:4px;gap:6px;flex-wrap:wrap"></div>
          </div>
<?php if (is_file(__DIR__ . '/../trabajo/entrega_lib.php') && (!function_exists('g7_can') || g7_can((int)$currentUser['id'], 'tareas'))): ?>
          <!-- Entrega de un pedido (promos/entrega.js, ENTREGAS_CALIFICACION.md) -->
          <div class="form-group full pd-wrap" id="pd-wrap">
            <label for="pd-q">Entrega de un pedido <span class="pd-opt">opcional</span></label>
            <div class="pd-pick" id="pd-pick">
              <input type="search" id="pd-q" placeholder="Busca el ticket o la tarea que entregas (número o texto)" autocomplete="off" aria-label="Ticket o tarea que entregas">
              <div class="pd-res" id="pd-res" hidden></div>
            </div>
            <div class="pd-sel" id="pd-sel" hidden></div>
            <input type="hidden" id="pd-kind"><input type="hidden" id="pd-id">
            <small class="pd-help">Al guardar, el pedido recibe los enlaces como respuesta y pasa a Resuelto.</small>
          </div>
<?php endif; ?>
          <!-- PROMO fields -->
          <div class="form-group f-promo">
            <label>Proyecto</label>
            <select id="f-project"><option>Otros</option></select>
          </div>
          <div class="form-group f-promo">
            <label>Categoría</label>
            <select id="f-category" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px;font-size:12px;outline:none">
              <option value="">Seleccionar...</option>
              <option value="Casino">Casino</option>
              <option value="Deportes">Deportes</option>
              <option value="General">General</option>
            </select>
          </div>
          <div class="form-group full f-promo" id="multi-wrap">
            <label class="multi-toggle"><input type="checkbox" id="f-multi" data-on-change="toggleMulti"> <span><b>Tarea de múltiples plataformas</b><small>La producción se reparte en partes iguales entre las plataformas que elijas</small></span></label>
            <div class="projects-check" id="multi-projects" style="display:none;margin-top:10px">
              <label><input type="checkbox" name="mprojects[]" value="Otros" data-on-change="updateMulti"> Otros</label>
            </div>
            <div class="multi-hint" id="multi-hint" style="display:none"></div>
          </div>
          <div class="form-group f-promo">
            <div class="fc-lbl-row"><label>Mailing</label>
              <div class="toggle-row fc-mini-toggle" id="mailing-toggle">
                <button type="button" class="active" data-on-click="setMailingMode" data-click-1="s:link" data-click-2="this">Link</button>
                <button type="button" data-on-click="setMailingMode" data-click-1="s:file" data-click-2="this">Archivo</button>
              </div>
            </div>
            <div id="mailing-link-wrap"><input type="text" id="f-mailing-link" placeholder="URL del mailing" style="width:100%"></div>
            <div id="mailing-file-wrap" style="display:none"><input type="file" id="f-mailing-file" accept=".html,.htm" style="width:100%"></div>
          </div>
          <div class="form-group f-promo">
            <label>Landings</label><div id="f-landing" class="lm-list" data-ph="URL de la landing"></div>
          </div>
          <!-- LAUNCH fields -->
          <div class="form-group full f-launch hidden">
            <label>Proyectos</label>
            <div class="projects-check">
              <label><input type="checkbox" name="projects[]" value="Otros"> Otros</label>
            </div>
          </div>
          <!-- PAID/SOCIAL/OTRO fields -->
          <div class="form-group full f-paidso hidden">
            <label>Proyecto</label>
            <select id="f-paidso-project"><option>Otros</option></select>
          </div>
          <div class="form-group f-paidso hidden">
            <label>Formato</label>
            <div class="toggle-row" id="media-toggle">
              <button class="active" data-on-click="setMediaType" data-click-1="s:imagen" data-click-2="this">Imagen</button>
              <button data-on-click="setMediaType" data-click-1="s:video" data-click-2="this">Video</button>
            </div>
            <input type="hidden" id="f-media-type" value="imagen">
          </div>
          <div class="form-group f-paidso hidden" id="video-count-wrap" style="display:none">
            <label>Cantidad de videos</label>
            <input type="number" id="f-video-count" min="1" max="99" placeholder="Ej: 3" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px;font-size:12px;outline:none" data-on-input="updateNameWithVideos">
          </div>
          <!-- OTRO custom field -->
          <div class="form-group full f-otro hidden">
            <label>Campo personalizado <span style="color:var(--g7-text-4);font-weight:400;text-transform:none">(opcional)</span></label>
            <div style="display:flex;gap:8px">
              <input type="text" id="f-custom-label" placeholder="Nombre del campo (ej: Cliente)">
              <input type="text" id="f-custom-value" placeholder="Valor...">
            </div>
          </div>

          <!-- CUSTOM type fields -->
          <div class="form-group full f-custom hidden">
            <label>Tipo de contenido</label>
            <select id="f-content-type" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px;font-size:12px;outline:none">
              <option value="">Seleccionar tipo...</option>
              <option value="torneo">Torneo</option>
              <option value="promo">Promo</option>
              <option value="paid">Placa Paid</option>
              <option value="otros">Otros</option>
            </select>
          </div>
          <div class="form-group full f-custom hidden">
            <label>Fecha</label>
            <input type="date" id="f-custom-date">
          </div>
          <div class="form-group full f-custom hidden">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
              <label style="margin:0">Recursos</label>
              <button type="button" data-on-click="addResourceField" style="background:rgba(var(--g7-accent-rgb),.1);border:1px solid rgba(var(--g7-accent-rgb),.25);color:var(--brand);border-radius:7px;padding:4px 12px;font-size:11px;font-weight:700;cursor:pointer">
                <i class="fa-solid fa-plus"></i> Agregar recurso
              </button>
            </div>
            <div id="f-resources-list" style="display:flex;flex-direction:column;gap:8px">
              <!-- Resource pairs added dynamically -->
            </div>
          </div>

          <!-- TAREA RÁPIDA fields -->
          <div class="form-group full f-tarea hidden">
            <label>Cantidad de tareas</label>
            <input type="number" id="f-tarea-qty" min="1" value="1" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 11px;font-size:13px;outline:none">
          </div>
          <div class="form-group full f-tarea hidden">
            <label>Descripción breve <span style="color:var(--g7-text-4);font-weight:400;text-transform:none">(opcional)</span></label>
            <textarea id="f-tarea-desc" rows="2" placeholder="Ej: Config DNS, ajuste banner, update plugin..." style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 11px;font-size:13px;outline:none;resize:vertical;font-family:inherit"></textarea>
          </div>

          <!-- MAILINGS fields -->
          <div class="form-group full f-mailings hidden">
            <label>Cantidad de mailings</label>
            <input type="number" id="f-mailings-qty" min="1" value="1" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 11px;font-size:13px;outline:none">
          </div>
          <div class="form-group full f-mailings hidden">
            <label>Link o archivo del mailing <span style="color:var(--g7-text-4);font-weight:400;text-transform:none">(opcional)</span></label>
            <div style="display:flex;gap:7px;align-items:center">
              <input type="text" id="f-mailings-link" placeholder="URL del mailing..." style="flex:1;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 11px;font-size:13px;outline:none">
              <label style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-3);border-radius:8px;padding:8px 12px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0">
                <i class="fa-solid fa-upload"></i> Subir
                <input type="file" id="f-mailings-file" accept=".html,.htm,.pdf,image/*" style="display:none" data-on-change="uploadMailingFile" data-change-1="this">
              </label>
            </div>
            <div id="f-mailings-preview" style="display:none;margin-top:5px;font-size:10px;color:#22c55e;display:flex;align-items:center;gap:5px">
              <i class="fa-solid fa-check-circle"></i>
              <span id="f-mailings-fname"></span>
              <button data-on-click="clearMailingFile" style="background:none;border:none;color:var(--g7-text-4);cursor:pointer;font-size:11px">✕</button>
            </div>
          </div>
          <!-- Common fields -->
          <div class="form-group">
            <label>Portada</label><input type="file" id="f-img" accept="image/*,video/*">
          </div>
          <div class="form-group">
            <label>Gráficas (URL)</label><input type="text" id="f-graphics" placeholder="Drive, Figma...">
          </div>
          <?php if($isAdmin): ?>
          <div class="form-group">
            <label>Autor</label>
            <select id="f-author">
              <?php foreach($authors as $a): ?>
              <option value="<?= htmlspecialchars($a) ?>" <?= $a===$currentUser['username']?'selected':'' ?>><?= htmlspecialchars($a) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
        </div>

        <!-- CANTIDADES DE PRODUCCIÓN -->
        <div class="prod-section">
          <div class="prod-header"><i class="fa-solid fa-chart-bar"></i>Producción</div>
          <div class="qty-grid">
            <div class="qty-item">
              <div class="qty-label-sm">Gráficas</div>
              <div class="qty-ctrl">
                <button type="button" class="qty-btn" data-on-click="adjQty" data-click-1="s:f-qty-graficas" data-click-2="n:-1">−</button>
                <input type="number" id="f-qty-graficas" class="qty-inp" min="0" value="0">
                <button type="button" class="qty-btn" data-on-click="adjQty" data-click-1="s:f-qty-graficas" data-click-2="n:1">+</button>
              </div>
            </div>
            <div class="qty-item">
              <div class="qty-label-sm">Videos</div>
              <div class="qty-ctrl">
                <button type="button" class="qty-btn" data-on-click="adjQty" data-click-1="s:f-qty-videos" data-click-2="n:-1">−</button>
                <input type="number" id="f-qty-videos" class="qty-inp" min="0" value="0">
                <button type="button" class="qty-btn" data-on-click="adjQty" data-click-1="s:f-qty-videos" data-click-2="n:1">+</button>
              </div>
            </div>
          </div>
          <input type="hidden" id="f-qty-landings" value="0">
          <input type="hidden" id="f-qty-mailings" value="0">
          <input type="hidden" id="f-qty-paid" value="0">
          <input type="hidden" id="f-qty-rrss" value="0">
          <input type="hidden" id="f-qty-mobile" value="0">
          <input type="hidden" id="f-qty-dias" value="">
        </div>

        <!-- COMPLICACIONES DEL PEDIDO -->
        <div class="cx-block" id="f-cx"></div>

        </div><!-- /fc-body -->
        <div class="fc-foot">
          <button class="fc-cancel-btn" data-on-click="showView" data-click-1="s:list">Cancelar</button>
          <button class="submit-btn" data-on-click="saveItem"><i class="fa-solid fa-floppy-disk" style="margin-right:6px"></i>Guardar contenido</button>
        </div>
      </div>
    </div>
    <div id="item-container" class="grid-view"></div>
    <!-- LOAD MORE -->
    <div id="pr-load-more-wrap" style="padding:24px;text-align:center;display:none">
      <button id="pr-load-more-btn" data-on-click="prLoadMore" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:10px;padding:10px 28px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:all .15s" data-hov="more">
        <i class="fa-solid fa-arrow-down"></i> Cargar más
        <span id="pr-load-more-count" style="font-size:10px;opacity:.5">(<?= max(0, $totalItems-15) ?> restantes)</span>
      </button>
      <div id="pr-load-spinner" style="display:none;color:var(--g7-text-4);font-size:12px"><i class="fa-solid fa-spinner fa-spin"></i> Cargando...</div>
    </div>
    <div id="pr-sentinel" style="height:1px"></div>
  </div>
</div>

<!-- NEW CONTENT BUTTON -->
<div style="position:fixed;bottom:22px;right:22px;z-index:40">
  <button data-on-click="showView" data-click-1="s:create" style="background:var(--brand);color:#000;border:none;border-radius:50%;width:50px;height:50px;font-size:20px;cursor:pointer;box-shadow:0 4px 16px rgba(var(--g7-accent-rgb),.4);transition:all .2s" title="Nuevo contenido">+</button>
  <button data-on-click="openEditedModal" style="position:fixed;right:24px;bottom:84px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:50%;width:48px;height:48px;font-size:18px;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.4);transition:all .2s;display:flex;align-items:center;justify-content:center" title="Contenido editado"><i class="fa-solid fa-rotate" style="font-size:16px"></i></button>
</div>

<!-- MSG MODAL -->
<div class="msg-modal hidden" id="msg-modal">
  <div class="msg-card">
    <h3><i class="fa-solid fa-circle-check" style="color:#22c55e;margin-right:8px"></i>Contenido guardado</h3>
    <p style="font-size:11px;color:var(--g7-text-3);margin-bottom:12px">Copia el mensaje para compartir:</p>
    <textarea id="msg-area" readonly></textarea>
    <button class="copy-btn" data-on-click="copyAndClose"><i class="fa-solid fa-copy" style="margin-right:6px"></i>Copiar y cerrar</button>
  </div>
</div>

<!-- DETAIL PANEL -->
<div class="panel-overlay hidden" id="detail-panel" data-on-click="closePanelOverlay" data-click-1="event" data-click-2="this">
  <div class="detail-panel">
    <!-- LEFT: info -->
    <div class="dp-left">
      <div class="panel-head">
        <span id="d-type-tag" class="type-tag"></span>
        <span id="d-proj-tag"></span>
        <button class="panel-close" data-on-click="closeDetail">✕</button>
      </div>
      <img id="d-img" class="panel-img" style="display:none">
      <div class="panel-body">
        <div class="panel-label">Nombre</div>
        <div class="panel-value" id="d-name" style="font-size:14px;font-weight:700;margin-bottom:10px"></div>
        <!-- LIKE ROW -->
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid var(--g7-border-3)">
          <button id="d-like-btn" data-on-click="toggleLikeDetail" style="background:none;border:none;cursor:pointer;display:flex;align-items:center;gap:6px;padding:0">
            <i id="d-like-icon" class="fa-regular fa-heart" style="font-size:16px;color:var(--g7-text-4)"></i>
            <span id="d-like-count" style="font-size:12px;color:var(--g7-text-3);font-weight:600">0</span>
          </button>
          <div style="display:flex;align-items:center;gap:5px">
            <i class="fa-regular fa-comment" style="font-size:14px;color:var(--g7-text-4)"></i>
            <span id="d-cmt-count" style="font-size:12px;color:var(--g7-text-3);font-weight:600">0</span>
          </div>
        </div>
        <!-- Fields -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-bottom:12px">
          <div>
            <div class="panel-label">Autor</div>
            <div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap">
              <div class="panel-value" id="d-author" style="margin-bottom:0"></div>
              <div id="d-collab-inline" style="display:flex;gap:4px;flex-wrap:wrap"></div>
            </div>
          </div>
          <div id="d-cat-wrap"><div class="panel-label">Categoría</div><div class="panel-value" id="d-cat"></div></div>
          <div id="d-media-wrap"><div class="panel-label">Formato</div><div class="panel-value" id="d-media"></div></div>
          <div id="d-start-wrap"><div class="panel-label">Inicio</div><div class="panel-value" id="d-start"></div></div>
          <div id="d-end-wrap"><div class="panel-label">Fin</div><div class="panel-value" id="d-end"></div></div>
          <div><div class="panel-label">Creado</div><div class="panel-value" id="d-created"></div></div>
        </div>
        <div id="d-status-wrap"><div class="panel-label">Estado</div><div class="panel-value" id="d-status" style="margin-bottom:14px"></div></div>
        <div id="d-custom-wrap" style="display:none;margin-bottom:10px">
          <div class="panel-label" id="d-custom-label"></div>
          <div class="panel-value" id="d-custom-value"></div>
        </div>
        <!-- Links -->
        <div style="border-top:1px solid var(--g7-border-3);padding-top:11px">
          <div class="panel-label" style="margin-bottom:8px">Enlaces</div>
          <div id="d-mailing-wrap" style="display:flex;align-items:center;gap:6px">
            <a id="d-mailing" class="panel-link" target="_blank" style="flex:1"><span class="ll">Mailing</span><span id="d-mailing-u"></span></a>
            <button id="d-mailing-edit-btn" data-on-click="openMailingEditor" title="Editar HTML" style="display:none;background:rgba(var(--g7-accent-rgb),.1);border:1px solid rgba(var(--g7-accent-rgb),.25);color:var(--g7-accent);border-radius:6px;padding:4px 8px;font-size:10px;font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0"><i class="fa-solid fa-code"></i> Editar HTML</button>
          </div>
          <div id="d-graphics-wrap"><a id="d-graphics" class="panel-link" target="_blank"><span class="ll">Gráficas</span><span id="d-graphics-u"></span></a></div>
          <div id="d-landing-wrap"></div>
          <div id="d-monday-wrap" style="display:none">
            <a id="d-monday-link" href="#" target="_blank" class="panel-link" style="color:var(--g7-accent);border-color:rgba(var(--g7-accent-rgb),.25);background:rgba(var(--g7-accent-rgb),.05)">
              <span class="ll" style="color:var(--g7-accent)"><i class="fa-brands fa-square-square" style="margin-right:4px"></i>Monday</span>
              <span id="d-monday-name" style="color:var(--g7-accent);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px"></span>
            </a>
          </div>
          <div id="d-jira-wrap" style="display:none"></div><!-- Jira: promos/jira.js -->
          <!-- RESOURCES (custom type only) -->
          <div id="d-resources-wrap" style="display:none">
            <div style="font-size:9px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.08em;margin-top:10px;margin-bottom:6px">Recursos</div>
            <div id="d-resources-list"></div>
          </div>
        </div>
      </div>
      <!-- Collaborators section -->
      <div style="padding:0 12px 10px;border-top:1px solid var(--g7-border-2)">
        <div id="d-em-wrap" style="display:none"><div class="panel-label" id="d-em-title" style="margin:10px 0 6px">Motivos</div><div id="d-em-list" class="cx-chips"></div><div id="d-em-notes" style="display:none;margin-top:7px;font-size:11.5px;line-height:1.5;color:var(--g7-text-2);white-space:pre-wrap"></div></div>
        <div id="d-cx-wrap" style="display:none"><div class="panel-label" style="margin:10px 0 6px">Complicaciones</div><div id="d-cx-list" class="cx-chips"></div></div>
        <div class="panel-label" style="margin:10px 0 6px">Colaboradores</div>
        <div id="d-collab-list" style="display:flex;flex-wrap:wrap;gap:5px;align-items:center">
          <div id="d-collab-empty" style="font-size:11px;color:var(--g7-text-5)">Sin colaboradores</div>
        </div>
      </div>
      <!-- Actions -->
      <div style="padding:0 12px 8px;display:flex;gap:6px">
        <button data-on-click="openCollabModal" data-click-1="s:detail" style="flex:1;background:var(--g7-bg-4);border:1px dashed var(--g7-border-3);color:var(--g7-text-3);border-radius:7px;padding:6px;font-size:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:4px" data-hov="collab"><i class="fa-solid fa-user-plus" style="font-size:9px"></i> Colaborador</button>
      </div>
      <div style="display:flex;gap:7px;padding:0 12px 12px">
        <button id="d-copy-msg-btn" data-on-click="copyDetailMessage" style="flex:1;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:#22c55e;border-radius:7px;padding:8px;font-size:11px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:5px"><i class="fa-solid fa-copy" style="font-size:10px"></i> Copiar mensaje</button>
        <button id="d-edit-btn" data-on-click="openEditModal" style="flex:1;background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.25);color:#60a5fa;border-radius:7px;padding:8px;font-size:11px;font-weight:700;cursor:pointer;display:none;align-items:center;justify-content:center;gap:5px"><i class="fa-solid fa-pen-to-square" style="font-size:10px"></i> Editar</button>
        <button class="del-btn" id="d-del-btn"><i class="fa-solid fa-trash-can" style="font-size:11px;margin-right:6px"></i>Eliminar</button>
      </div>
    </div>
    <!-- RIGHT: comments -->
    <div class="dp-right">
      <div style="padding:13px 14px;border-bottom:1px solid var(--g7-border-3);font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.08em;flex-shrink:0">
        <i class="fa-solid fa-comments" style="color:var(--g7-accent);margin-right:5px"></i> Comentarios
      </div>
      <div id="d-comments-list" style="flex:1;overflow-y:auto;padding:12px 14px;min-height:0"></div>
      <div style="padding:11px 14px;border-top:1px solid var(--g7-border-3);background:var(--g7-bg-2);flex-shrink:0;position:relative">
        <div id="d-mention-dd" class="dc-mention-dd" style="bottom:calc(100% + 4px);left:14px;right:14px"></div>
        <textarea id="d-new-comment" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px;font-size:12px;resize:none;outline:none;min-height:52px;font-family:inherit;line-height:1.5" placeholder="Comenta... usa @usuario para mencionar" data-on-input="handleDMention" data-input-1="this" data-on-keydown="dCommentKey" data-keydown-1="event"></textarea>
        <div style="display:flex;justify-content:flex-end;margin-top:6px">
          <button data-on-click="submitContentComment" style="background:var(--g7-accent);color:#000;border:none;border-radius:7px;padding:7px 14px;font-size:11px;font-weight:700;cursor:pointer"><i class="fa-solid fa-paper-plane" style="margin-right:5px"></i>Comentar</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- EDIT MODAL -->
<div id="edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:600;align-items:center;justify-content:center;padding:20px" data-on-click="prIfSelf" data-click-1="event" data-click-2="this" data-click-3="s:closeEditModal">
  <div style="background:var(--g7-bg-3);border:1px solid var(--g7-border-3);border-radius:16px;width:100%;max-width:500px;max-height:88vh;display:flex;flex-direction:column;overflow:hidden">
    <div style="padding:15px 20px;border-bottom:1px solid var(--g7-border-2);display:flex;align-items:center;justify-content:space-between;flex-shrink:0">
      <div style="font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px"><i class="fa-solid fa-pen-to-square" style="color:#60a5fa"></i> Editar contenido</div>
      <button data-on-click="closeEditModal" style="background:none;border:none;color:var(--g7-text-3);font-size:17px;cursor:pointer">✕</button>
    </div>
    <div style="overflow-y:auto;flex:1;padding:18px 20px;display:flex;flex-direction:column;gap:14px">
      <div><label style="font-size:9px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:5px">Nombre</label><input id="e-name" type="text" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:9px 11px;font-size:13px;outline:none"></div>
      <!-- Tipo de contenido (editable) -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div><label style="font-size:9px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:5px">Tipo</label><select id="e-type" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px;font-size:12px;outline:none"><option value="promo">Promo</option><option value="launch">Launch</option><option value="paid">Paid</option><option value="social">Social</option><option value="otro">Otro</option><option value="custom">Custom</option></select></div>
        <div id="e-content-type-wrap"><label style="font-size:9px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:5px">Subtipo</label><select id="e-content-type" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px;font-size:12px;outline:none"><option value="">— Sin subtipo —</option><option value="torneo">Torneo</option><option value="promo">Promo</option><option value="paid">Placa Paid</option><option value="otros">Otros</option></select></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div><label style="font-size:9px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:5px">Categoría</label><select id="e-category" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px;font-size:12px;outline:none"><option value="">Seleccionar...</option><option value="Casino">Casino</option><option value="Deportes">Deportes</option><option value="General">General</option></select></div>
        <div><label style="font-size:9px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:5px">Formato</label><select id="e-media" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px;font-size:12px;outline:none"><option value="imagen">Imagen</option><option value="video">Video</option><option value="gif">GIF</option><option value="otro">Otro</option></select></div>
        <div><label style="font-size:9px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:5px">Fecha inicio</label><input id="e-start" type="date" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px;font-size:12px;outline:none"></div>
        <div><label style="font-size:9px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:5px">Fecha fin</label><input id="e-end" type="date" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px;font-size:12px;outline:none"></div>
        <div><label style="font-size:9px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:5px">Proyecto</label><select id="e-project" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px;font-size:12px;outline:none"><option>Otros</option></select></div>
        <div><label style="font-size:9px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:5px">Formato</label><select id="e-media" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px;font-size:12px;outline:none"><option value="imagen">Imagen</option><option value="video">Video</option></select></div>
      </div>
      <div><label style="font-size:9px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:5px">Gráficas</label><input id="e-graphics" type="text" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px;font-size:12px;outline:none"></div>
      <div><label style="font-size:9px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:5px">Landings</label><div id="e-landing" class="lm-list" data-ph="URL de la landing" data-style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px;font-size:12px;outline:none"></div></div>
      <div><label style="font-size:9px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:5px">Mailing</label><input id="e-mailing" type="text" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px;font-size:12px;outline:none"></div>
      <div class="cx-block" id="e-cx" style="margin-top:0"></div>
      <!-- Resources (custom type only) -->
      <div id="e-resources-wrap" style="display:none">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
          <label style="font-size:9px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.08em">Recursos</label>
          <button type="button" data-on-click="eAddResource" style="background:rgba(var(--g7-accent-rgb),.1);border:1px solid rgba(var(--g7-accent-rgb),.25);color:var(--brand);border-radius:7px;padding:3px 10px;font-size:11px;font-weight:700;cursor:pointer"><i class="fa-solid fa-plus"></i> Agregar</button>
        </div>
        <div id="e-resources-list" style="display:flex;flex-direction:column;gap:7px"></div>
      </div>
    </div>
    <div style="padding:14px 20px;border-top:1px solid var(--g7-border-2);display:flex;gap:8px;flex-shrink:0">
      <button data-on-click="closeEditModal" style="flex:1;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-3);border-radius:8px;padding:10px;font-size:12px;cursor:pointer">Cancelar</button>
      <button data-on-click="saveEdit" id="e-save-btn" style="flex:2;background:#60a5fa;border:none;color:#000;border-radius:8px;padding:10px;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px"><i class="fa-solid fa-floppy-disk"></i> Guardar cambios</button>
    </div>
  </div>
</div>

<!-- COLLAB MODAL -->
<div id="collab-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:600;align-items:center;justify-content:center;padding:20px" data-on-click="prIfSelf" data-click-1="event" data-click-2="this" data-click-3="s:closeCollabModal">
  <div style="background:var(--g7-bg-3);border:1px solid var(--g7-border-3);border-radius:16px;width:100%;max-width:380px;overflow:hidden">
    <div style="padding:15px 18px;border-bottom:1px solid var(--g7-border-2);display:flex;align-items:center;justify-content:space-between">
      <div style="font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px"><i class="fa-solid fa-user-plus" style="color:#4ade80"></i> Colaboradores</div>
      <button data-on-click="closeCollabModal" style="background:none;border:none;color:var(--g7-text-3);font-size:17px;cursor:pointer">✕</button>
    </div>
    <div id="collab-user-list" style="max-height:360px;overflow-y:auto"></div>
  </div>
</div>

<!-- MONDAY MODAL -->
<div id="monday-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:600;align-items:center;justify-content:center;padding:20px" data-on-click="prIfSelf" data-click-1="event" data-click-2="this" data-click-3="s:closeMondayModal">
  <div style="background:var(--g7-bg-3);border:1px solid var(--g7-border-3);border-radius:16px;width:100%;max-width:460px;overflow:hidden;display:flex;flex-direction:column;max-height:80vh">
    <div style="padding:15px 18px;border-bottom:1px solid var(--g7-border-2);display:flex;align-items:center;justify-content:space-between;flex-shrink:0">
      <div style="font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px"><i class="fa-brands fa-square-square" style="color:var(--g7-accent)"></i> Vincular tarea Monday</div>
      <button data-on-click="closeMondayModal" style="background:none;border:none;color:var(--g7-text-3);font-size:17px;cursor:pointer">✕</button>
    </div>
    <div style="padding:10px 14px;border-bottom:1px solid var(--g7-border-2);flex-shrink:0">
      <input id="monday-modal-search" type="text" placeholder="Buscar tarea..." data-on-input="mondaySearch" data-input-1="value" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 11px;font-size:12px;outline:none">
    </div>
    <div id="monday-modal-list" style="overflow-y:auto;flex:1"></div>
  </div>
</div>

<!-- EXPORT MODAL -->
<div id="export-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:500;align-items:center;justify-content:center;padding:20px" data-on-click="prIfSelf" data-click-1="event" data-click-2="this" data-click-3="s:closeExportModal">
  <div style="background:var(--g7-bg-3);border:1px solid var(--g7-border-3);border-radius:16px;width:100%;max-width:460px;overflow:hidden">
    <div style="padding:16px 20px;border-bottom:1px solid var(--g7-border-2);display:flex;align-items:center;justify-content:space-between">
      <div style="font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px"><i class="fa-solid fa-file-export" style="color:#a78bfa"></i> Exportar mi contenido</div>
      <button data-on-click="closeExportModal" style="background:none;border:none;color:var(--g7-text-3);font-size:17px;cursor:pointer">✕</button>
    </div>
    <div style="padding:16px 20px">
      <div style="font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px">Período rápido</div>
      <div style="display:flex;gap:6px;margin-bottom:10px">
        <button class="exp-period-btn" data-days="5"  data-on-click="generateExport" data-click-1="n:5"  style="flex:1;padding:7px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-2);border-radius:7px;font-size:11px;font-weight:600;cursor:pointer">5d</button>
        <button class="exp-period-btn" data-days="10" data-on-click="generateExport" data-click-1="n:10" style="flex:1;padding:7px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-2);border-radius:7px;font-size:11px;font-weight:600;cursor:pointer">10d</button>
        <button class="exp-period-btn" data-days="15" data-on-click="generateExport" data-click-1="n:15" style="flex:1;padding:7px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-2);border-radius:7px;font-size:11px;font-weight:600;cursor:pointer">15d</button>
        <button class="exp-period-btn" data-days="30" data-on-click="generateExport" data-click-1="n:30" style="flex:1;padding:7px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-2);border-radius:7px;font-size:11px;font-weight:600;cursor:pointer">30d</button>
      </div>
      <div style="font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px">Rango personalizado</div>
      <div style="display:flex;gap:8px;margin-bottom:14px;align-items:center">
        <input type="date" id="exp-from" style="flex:1;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:7px;padding:7px 10px;font-size:11px;outline:none" data-on-input="generateExportCustom">
        <span style="color:var(--g7-text-4);font-size:11px">&#8594;</span>
        <input type="date" id="exp-to" style="flex:1;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:7px;padding:7px 10px;font-size:11px;outline:none" data-on-input="generateExportCustom">
      </div>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
        <div style="font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.08em">Listado</div>
        <span id="export-count" style="font-size:10px;color:var(--g7-text-3)"></span>
      </div>
      <textarea id="export-text" readonly style="width:100%;background:var(--g7-bg-2);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:12px;font-size:12px;resize:none;outline:none;min-height:180px;font-family:inherit;line-height:1.7"></textarea>
      <button id="copy-export-btn" data-on-click="copyExport" style="width:100%;margin-top:10px;background:rgba(167,139,250,.12);border:1px solid rgba(167,139,250,.3);color:#a78bfa;border-radius:8px;padding:10px;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px"><i class="fa-solid fa-copy"></i> Copiar listado</button>
    </div>
  </div>
</div>

<?php
// Proyectos de Promos: con un rubro elegido (o una lista guardada en g7_config.promos_projects) salen de los clientes activos
// más esa lista. Sin rubro ni lista, los del paquete de marcas del sitio (marcas/marcas.php → 'promos_projects');
// sin paquete, los clientes activos y «Otros». proyectos.js arma la barra, los selects y las casillas con esta lista.
$pfProjects = null;
try {
    if (g7_cfg('rubro', '') !== '' || g7_cfg('promos_projects') !== null) {
        $pfProjects = [];
        if (g7_site_has('clientes')) foreach (getDB()->query("SELECT name FROM g7_clients WHERE status <> 'terminado' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $_n) $pfProjects[] = (string)$_n;
        foreach (g7_cfg_json('promos_projects', ['Otros']) as $_n) if (is_string($_n) && trim($_n) !== '' && !in_array(trim($_n), $pfProjects, true)) $pfProjects[] = trim($_n);
    }
    if ($pfProjects === null && ($_pk = g7_pack('promos_projects', [])) && is_array($_pk)) $pfProjects = array_values(array_filter(array_map('strval', $_pk), fn($n) => trim($n) !== ''));
    if ($pfProjects === null) {
        $pfProjects = [];
        if (g7_site_has('clientes')) foreach (getDB()->query("SELECT name FROM g7_clients WHERE status <> 'terminado' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $_n) $pfProjects[] = (string)$_n;
        $pfProjects[] = 'Otros';
    }
} catch (Throwable $e) { $pfProjects = ['Otros']; }
?>
<?php
// Datos para el JS (CSP estricta: sin scripts en línea). Los lee assets/json-globals.js.
require_once dirname(__DIR__) . '/jira_use_lib.php';
$prJsonF = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE;
$prData = [
    'items' => $items, 'total' => (int)$totalItems, 'isAdmin' => (bool)$isAdmin,
    'me' => $currentUser['username'], 'meId' => (int)$currentUser['id'],
    'currentUser' => ['id' => $currentUser['id'], 'username' => $currentUser['username'], 'role' => $currentUser['role']],
    'users' => json_decode($usersJSON, true) ?: [],
    'cx' => array_map(fn($k, $l) => ['k' => $k, 'l' => $l], array_keys(PR_COMPLICATIONS), PR_COMPLICATIONS),
    'emReasons' => PR_EDIT_REASONS,
    // Para forms.js y proyectos.js: promos.js los pasa a window.PF_PROJECTS, PF_CONFIG, PF_JIRA y PF_ADMIN
    'pf' => ['projects' => $pfProjects, 'config' => pf_config(), 'jira' => g7_jira_on('promos'), 'admin' => (bool)$isAdmin],   // jira: campo "Jira: vincular con ticket"
];
?>
<script type="application/json" data-global="PR_DATA"><?= json_encode($prData, $prJsonF) ?></script>
<script src="/toolz/assets/json-globals.js?v=1"></script>
<script src="/toolz/promos/promos.js?v=<?= (int)@filemtime(__DIR__ . '/promos.js') ?>"></script>
<!-- CONTENIDO EDITADO MODAL -->
<div id="edited-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:700;align-items:center;justify-content:center;padding:16px">
  <div style="background:var(--g7-bg-3);border:1px solid var(--g7-border-3);border-radius:16px;width:100%;max-width:640px;max-height:92vh;display:flex;flex-direction:column;overflow:hidden">
    <!-- Header -->
    <div style="padding:14px 18px;border-bottom:1px solid var(--g7-border-2);display:flex;align-items:center;justify-content:space-between;flex-shrink:0">
      <span style="font-size:14px;font-weight:700"><i class="fa-solid fa-rotate" style="color:var(--g7-accent);margin-right:8px"></i>Contenido editado</span>
      <button data-on-click="closeEditedModal" style="background:none;border:none;color:var(--g7-text-4);cursor:pointer;font-size:17px">✕</button>
    </div>
    <div style="flex:1;overflow-y:auto;padding:16px 18px">

      <!-- Step 1: Select original -->
      <div id="em-step1">
        <div style="font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px">1. Selecciona el contenido original</div>
        <div style="position:relative;margin-bottom:8px">
          <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--g7-text-4);font-size:11px;pointer-events:none"></i>
          <input id="em-search" type="text" placeholder="Buscar por nombre o proyecto..." data-on-input="emFilterItems" data-input-1="value"
            style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px 8px 32px;font-size:12px;outline:none">
        </div>
        <div id="em-items-list" style="max-height:220px;overflow-y:auto;border:1px solid var(--g7-border-3);border-radius:8px;background:var(--g7-bg-2)"></div>
        <div id="em-selected-wrap" style="display:none;margin-top:10px;background:rgba(var(--g7-accent-rgb),.06);border:1px solid rgba(var(--g7-accent-rgb),.2);border-radius:8px;padding:10px 12px;display:flex;align-items:center;gap:10px">
          <i class="fa-solid fa-check-circle" style="color:var(--g7-accent);font-size:16px;flex-shrink:0"></i>
          <div style="flex:1;min-width:0">
            <div id="em-selected-name" style="font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></div>
            <div id="em-selected-meta" style="font-size:10px;color:var(--g7-text-3);margin-top:1px"></div>
          </div>
          <button data-on-click="emClearSelection" style="background:none;border:none;color:var(--g7-text-4);cursor:pointer;font-size:12px">✕</button>
        </div>
      </div>

      <!-- Step 2: Edit type -->
      <div id="em-step2" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--g7-border-2)">
        <div style="font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px">2. Tipo de edición</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
          <label id="em-type-revision" style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--g7-bg-4);border:2px solid var(--g7-border-3);border-radius:10px;cursor:pointer;transition:all .15s" data-on-click="emSelectType" data-click-1="s:revision">
            <input type="radio" name="em-type" value="revision" style="accent-color:var(--g7-accent);width:15px;height:15px">
            <div>
              <div style="font-size:13px;font-weight:700">Revisión</div>
              <div style="font-size:10px;color:var(--g7-text-4)">El cliente pidió cambios</div>
            </div>
          </label>
          <label id="em-type-rechazo" style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--g7-bg-4);border:2px solid var(--g7-border-3);border-radius:10px;cursor:pointer;transition:all .15s" data-on-click="emSelectType" data-click-1="s:rechazo">
            <input type="radio" name="em-type" value="rechazo" style="accent-color:#ef4444;width:15px;height:15px">
            <div>
              <div style="font-size:13px;font-weight:700">Rechazo</div>
              <div style="font-size:10px;color:var(--g7-text-4)">El contenido fue rechazado</div>
            </div>
          </label>
        </div>
        <div id="em-reasons" style="display:none;margin:-4px 0 14px">
          <div id="em-reasons-title" style="font-size:12px;font-weight:600;color:var(--g7-text-2);margin-bottom:8px"></div>
          <div id="em-reasons-list" class="cx-opts" style="margin-top:0"></div>
        </div>
      </div>

      <!-- Step 3: New quantities -->
      <div id="em-step3" style="margin-top:4px;padding-top:16px;border-top:1px solid var(--g7-border-2)">
        <div style="font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px">3. Cantidades del nuevo entregable</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px">
          <div><label style="font-size:9px;font-weight:700;color:var(--g7-text-4);display:block;margin-bottom:3px">Gráficas</label><input type="number" id="em-qty-graficas" min="0" value="0" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:7px;padding:6px 8px;font-size:12px;outline:none;text-align:center"></div>
          <div><label style="font-size:9px;font-weight:700;color:var(--g7-text-4);display:block;margin-bottom:3px">Videos</label><input type="number" id="em-qty-videos" min="0" value="0" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:7px;padding:6px 8px;font-size:12px;outline:none;text-align:center"></div>
          <input type="hidden" id="em-qty-landings" value="0">
          <input type="hidden" id="em-qty-mailings" value="0">
          <input type="hidden" id="em-qty-paid" value="0">
          <input type="hidden" id="em-qty-rrss" value="0">
          <input type="hidden" id="em-qty-mobile" value="0">
          <input type="hidden" id="em-qty-dias" value="">
        </div>

        <!-- Links: se copian de la tarea original -->
        <div id="em-links-info" class="em-links-info"></div>
        <div>
          <label style="font-size:9px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:5px">Notas <span style="font-weight:400;text-transform:none;color:var(--g7-text-5)">(opcional)</span></label>
          <textarea id="em-notes" placeholder="Ej: Pidieron cambiar el monto del premio y agrandar el logo" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 11px;font-size:12px;outline:none;resize:vertical;min-height:60px;font-family:inherit"></textarea>
        </div>
      </div>

      <div id="em-error" style="display:none;color:#ef4444;font-size:11px;padding:8px 10px;background:rgba(239,68,68,.08);border-radius:7px;margin-top:10px"></div>
    </div>

    <!-- Footer -->
    <div style="padding:12px 18px;border-top:1px solid var(--g7-border-2);display:flex;gap:8px;flex-shrink:0">
      <button data-on-click="closeEditedModal" style="flex:1;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-3);border-radius:8px;padding:10px;font-size:12px;font-weight:700;cursor:pointer">Cancelar</button>
      <button data-on-click="saveEditedContent" id="em-save-btn" style="flex:2;background:var(--g7-accent);border:none;color:#000;border-radius:8px;padding:10px;font-size:12px;font-weight:800;cursor:pointer"><i class="fa-solid fa-rotate"></i> Registrar edición</button>
    </div>
  </div>
</div>

<div id="promo-toast" style="position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(80px);background:var(--g7-bg-4);border:1px solid rgba(74,222,128,.35);color:#4ade80;border-radius:10px;padding:10px 20px;font-size:12px;font-weight:700;transition:transform .3s cubic-bezier(.34,1.4,.64,1);z-index:9999;pointer-events:none;white-space:nowrap"></div>

<?php // Marcas (Admin › Marcas): los mismos datos que arma g7_brand_js() en access.php, en JSON + promos/brands.js (sin scripts en línea)
$prBrands = null;
if (g7_brands_enabled() && ($prScope = g7_brand_scope($bUid)) !== null) $prBrands = ['scope' => array_values($prScope), 'unassigned' => g7_setting_on('brand_unassigned_visible'),
    'brands' => array_values(array_map(fn($b) => ['id' => $b['id'], 'name' => $b['name'], 'k' => $b['keywords']], g7_brands()))];
if ($prBrands !== null): ?><script type="application/json" data-global="G7_BRANDS"><?= json_encode($prBrands, $prJsonF) ?></script><script src="/toolz/assets/json-globals.js?v=1"></script><script src="/toolz/promos/brands.js?v=<?= (int)@filemtime(__DIR__ . '/brands.js') ?>"></script><?php endif; ?>
<script src="/toolz/promos/proyectos.js?v=<?= (int)@filemtime(__DIR__ . '/proyectos.js') ?>"></script>
<script src="/toolz/promos/forms.js?v=<?= (int)@filemtime(__DIR__ . '/forms.js') ?>"></script>
<script src="/toolz/promos/jira.js?v=<?= (int)@filemtime(__DIR__ . '/jira.js') ?>"></script>
<script src="/toolz/promos/entrega.js?v=<?= (int)@filemtime(__DIR__ . '/entrega.js') ?>"></script>
</body>

<!-- MAILING HTML EDITOR MODAL -->
<div id="mailing-editor-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:800;flex-direction:column">
  <div style="background:var(--g7-bg-2);border-bottom:1px solid var(--g7-border-2);padding:10px 16px;display:flex;align-items:center;gap:12px;flex-shrink:0">
    <i class="fa-solid fa-code" style="color:var(--g7-accent)"></i>
    <span style="font-size:13px;font-weight:800">Editor de mailing</span>
    <span id="me-filename" style="font-size:10px;color:var(--g7-text-4);font-family:monospace"></span>
    <div style="margin-left:auto;display:flex;align-items:center;gap:8px">
      <span id="me-status" style="font-size:10px;color:var(--g7-text-4)"></span>
      <button data-on-click="meTogglePreview" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-2);border-radius:6px;padding:5px 12px;font-size:11px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:5px"><i class="fa-solid fa-eye"></i> Preview</button>
      <button data-on-click="meSave" id="me-save-btn" style="background:var(--g7-accent);border:none;color:#000;border-radius:6px;padding:6px 16px;font-size:12px;font-weight:800;cursor:pointer;display:flex;align-items:center;gap:5px"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
      <button data-on-click="closeMailingEditor" style="background:none;border:none;color:var(--g7-text-4);cursor:pointer;font-size:18px">✕</button>
    </div>
  </div>
  <div style="flex:1;display:flex;overflow:hidden;min-height:0">
    <!-- Editor -->
    <div id="me-editor-pane" style="flex:1;display:flex;flex-direction:column;min-width:0;border-right:1px solid var(--g7-border-2)">
      <div style="background:var(--g7-bg);padding:4px 12px;border-bottom:1px solid var(--g7-border-2);display:flex;align-items:center;gap:6px">
        <span style="font-size:9px;font-weight:700;color:var(--g7-text-5);text-transform:uppercase;letter-spacing:.1em">HTML</span>
        <div style="margin-left:auto;display:flex;gap:5px">
          <button data-on-click="meFormat" style="background:none;border:1px solid var(--g7-border-3);color:var(--g7-text-4);border-radius:5px;padding:2px 8px;font-size:10px;cursor:pointer"><i class="fa-solid fa-wand-magic-sparkles"></i> Formatear</button>
          <button data-on-click="meWrap" data-click-1="s:b" style="background:none;border:1px solid var(--g7-border-3);color:var(--g7-text-4);border-radius:5px;padding:2px 8px;font-size:10px;cursor:pointer;font-weight:700">B</button>
          <button data-on-click="meWrap" data-click-1="s:i" style="background:none;border:1px solid var(--g7-border-3);color:var(--g7-text-4);border-radius:5px;padding:2px 8px;font-size:10px;cursor:pointer;font-style:italic">I</button>
          <button data-on-click="meWrap" data-click-1="s:a href=&quot;&quot;" style="background:none;border:1px solid var(--g7-border-3);color:var(--g7-text-4);border-radius:5px;padding:2px 8px;font-size:10px;cursor:pointer">🔗</button>
        </div>
      </div>
      <textarea id="me-code" spellcheck="false" style="flex:1;background:var(--g7-bg);color:var(--g7-text);padding:16px;font-family:monospace;font-size:12px;line-height:1.7;border:none;outline:none;resize:none;tab-size:2;overflow:auto" data-on-input="meUpdatePreview" data-on-keydown="meHandleKey" data-keydown-1="event" placeholder="HTML del mailing..."></textarea>
    </div>
    <!-- Preview -->
    <div id="me-preview-pane" style="flex:1;display:flex;flex-direction:column;min-width:0">
      <div style="background:var(--g7-bg);padding:4px 12px;border-bottom:1px solid var(--g7-border-2);display:flex;align-items:center;gap:8px">
        <span style="font-size:9px;font-weight:700;color:var(--g7-text-5);text-transform:uppercase;letter-spacing:.1em">Preview</span>
        <div style="margin-left:auto;display:flex;gap:5px;align-items:center">
          <button data-on-click="meSetWidth" data-click-1="n:375" data-w="375" class="me-wb" style="background:none;border:1px solid var(--g7-border-3);color:var(--g7-text-4);border-radius:4px;padding:1px 7px;font-size:9px;cursor:pointer">📱 375px</button>
          <button data-on-click="meSetWidth" data-click-1="n:600" data-w="600" class="me-wb" style="background:none;border:1px solid var(--g7-border-3);color:var(--g7-text-4);border-radius:4px;padding:1px 7px;font-size:9px;cursor:pointer">📧 600px</button>
          <button data-on-click="meSetWidth" data-click-1="s:100%" data-w="100" class="me-wb" style="background:rgba(var(--g7-accent-rgb),.1);border:1px solid rgba(var(--g7-accent-rgb),.3);color:var(--g7-accent);border-radius:4px;padding:1px 7px;font-size:9px;cursor:pointer">🖥 Full</button>
        </div>
      </div>
      <div style="flex:1;overflow:auto;background:#ccc;display:flex;justify-content:center">
        <iframe id="me-preview" style="width:100%;height:100%;border:none;background:#fff;transition:width .2s"></iframe>
      </div>
    </div>
  </div>
</div>
</html>
