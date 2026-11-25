<?php
error_reporting(0); ini_set('display_errors',0);
require_once __DIR__ . '/presence_lib.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity_helper.php';
$currentUser = requireLogin();
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo (SEC-24)
$isAdmin     = ($currentUser['role'] === 'admin');
$db          = getDB();

$avatarFolder = __DIR__ . '/avatars/';
$coverFolder  = __DIR__ . '/covers/';
if (!is_dir($avatarFolder)) mkdir($avatarFolder, 0755, true);
if (!is_dir($coverFolder))  mkdir($coverFolder,  0755, true);

require_once __DIR__ . '/comunidad_lib.php';   // reconocimientos, feed, menciones y perfil (Comunidad 2)
require_once __DIR__ . '/profile_fields_lib.php';   // herramientas, aptitudes y formulario de perfil compartido

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ct=$_SERVER['CONTENT_TYPE']??'';
    $isJson=strpos($ct,'application/json')!==false;
    $input=$isJson?json_decode(file_get_contents('php://input'),true):null;
    $action=$_POST['action']??($input['action']??'');
    cm_install3($db);

    // ── Comunidad 3: encuestas, café, sincronización ──
    if ($action==='add_poll') {
        $qtext = trim((string)($input['body'] ?? '')); $opts = array_values(array_filter(array_map(fn($o) => mb_substr(trim((string)$o), 0, 80), (array)($input['options'] ?? [])), 'strlen'));
        if (mb_strlen($qtext) < 3 || mb_strlen($qtext) > 280) { echo json_encode(['error' => 'Escribí la pregunta (3 a 280 caracteres).']); exit; }
        if (count($opts) < 2 || count($opts) > 6) { echo json_encode(['error' => 'La encuesta necesita entre 2 y 6 opciones.']); exit; }
        if (count(array_unique(array_map('mb_strtolower', $opts))) !== count($opts)) { echo json_encode(['error' => 'Hay opciones repetidas.']); exit; }
        $id = cm_auto_post($db, (int)$currentUser['id'], 'poll', '', $qtext, '', ['options' => $opts, 'multi' => !empty($input['multi'])]);
        cm_notify_mentions($db, $qtext, (int)$currentUser['id'], 'mention_post', $id);
        try { $db->prepare('INSERT INTO g7_activity (user_id,type,target_name,meta) VALUES (?,?,?,?)')->execute([$currentUser['id'], 'poll', mb_substr($qtext, 0, 60), 'poll']); } catch (Throwable $e) {}
        $row = $db->prepare('SELECT p.*,u.username,u.display_name,u.avatar FROM g7_posts p JOIN g7_users u ON u.id=p.user_id WHERE p.id=?'); $row->execute([$id]);
        echo json_encode(['status' => 'success', 'post' => cm_hydrate($db, [$row->fetch()], (int)$currentUser['id'])[0]]); exit;
    }
    if ($action==='vote_poll') {
        $pid = (int)($input['post_id'] ?? 0); $opt = (int)($input['opt'] ?? -1);
        $q = $db->prepare("SELECT id,user_id,meta FROM g7_posts WHERE id=? AND kind='poll'"); $q->execute([$pid]); $p = $q->fetch(PDO::FETCH_ASSOC);
        if (!$p) { echo json_encode(['error' => 'Esa encuesta ya no está.']); exit; }
        $st = cm_poll_state($db, $p, (int)$currentUser['id']);
        if ($opt < 0 || $opt >= count($st['options'])) { echo json_encode(['error' => 'Opción inválida']); exit; }
        $had = in_array($opt, $st['mine'], true); $firstVote = !$st['mine'];
        if ($had) $db->prepare('DELETE FROM g7_cm_votes WHERE post_id=? AND user_id=? AND opt=?')->execute([$pid, $currentUser['id'], $opt]);
        else { if (!$st['multi']) $db->prepare('DELETE FROM g7_cm_votes WHERE post_id=? AND user_id=?')->execute([$pid, $currentUser['id']]);
            $db->prepare('INSERT IGNORE INTO g7_cm_votes (post_id,user_id,opt) VALUES (?,?,?)')->execute([$pid, $currentUser['id'], $opt]); }
        if ($firstVote && !$had) {
            if (function_exists('g7AwardXP')) try { g7AwardXP($db, (int)$currentUser['id'], 2, 'encuesta', $pid); } catch (Throwable $e) {}
            // a quien preguntó le avisa cada 5 votos
            $nv = (int)$db->query('SELECT COUNT(DISTINCT user_id) FROM g7_cm_votes WHERE post_id=' . $pid)->fetchColumn();
            if ($nv % 5 === 0) pushNotif($db, (int)$p['user_id'], (int)$currentUser['id'], 'poll_vote', $pid, 0, 'Tu encuesta ya tiene ' . $nv . ' votos');
        }
        echo json_encode(['status' => 'success', 'poll' => cm_poll_state($db, $p, (int)$currentUser['id'])]); exit;
    }
    if ($action==='coffee_done') {
        $c = cm_coffee_mine($db, (int)$currentUser['id']); if (!$c) { echo json_encode(['error' => 'No tenés café esta semana.']); exit; }
        $r = $db->prepare('SELECT a FROM g7_cm_coffee WHERE id=?'); $r->execute([$c['id']]); $isA = (int)$r->fetchColumn() === (int)$currentUser['id'];
        $db->prepare('UPDATE g7_cm_coffee SET ' . ($isA ? 'done_a' : 'done_b') . ' = 1 WHERE id=?')->execute([$c['id']]);
        if (!$c['done'] && function_exists('g7AwardXP')) try { g7AwardXP($db, (int)$currentUser['id'], 15, 'cafe', $c['id']); } catch (Throwable $e) {}
        echo json_encode(['status' => 'success', 'coffee' => cm_coffee_mine($db, (int)$currentUser['id'])]); exit;
    }

    // ── Comunidad 2 ──
    if ($action==='feed_page') {
        try { cm_auto_sync($db); } catch (Throwable $e) {}
        $f = (string)($input['filter'] ?? 'all'); if (!in_array($f, ['all', 'kudos', 'mentions', 'img', 'mine', 'polls', 'auto'], true)) $f = 'all';
        echo json_encode(cm_feed($db, $currentUser, $f, mb_substr(trim((string)($input['q'] ?? '')), 0, 60), (int)($input['before'] ?? 0))); exit;
    }
    // Para el chat y otras páginas (chat/comm.js): resumen de una persona y vista previa de una publicación
    if ($action==='mini_profile') {
        $id = (int)($input['user_id'] ?? 0);
        $u = $db->prepare('SELECT id,username,display_name,avatar,position FROM g7_users WHERE id=? AND active=1'); $u->execute([$id]); $u = $u->fetch(PDO::FETCH_ASSOC);
        if (!$u) { echo json_encode(['error' => 'not found']); exit; }
        $x = cm_profile_extra($db, $id);
        $pc = $db->prepare('SELECT COUNT(*) FROM g7_posts WHERE user_id=?'); $pc->execute([$id]);
        echo json_encode(['id' => (int)$u['id'], 'name' => $u['display_name'] ?: $u['username'], 'username' => $u['username'], 'avatar' => (string)$u['avatar'], 'position' => (string)$u['position'],
            'level' => $x['level'] ? ['label' => $x['level']['label'] ?? '', 'emoji' => $x['level']['emoji'] ?? '', 'color' => $x['level']['color'] ?? ''] : null, 'xp' => $x['xp'], 'medals' => $x['medals'],
            'kudos_n' => $x['kudos_n'], 'kudos' => array_slice($x['kudos'], 0, 3), 'posts' => (int)$pc->fetchColumn(), 'ach' => array_slice($x['ach'], 0, 4), 'me' => (int)$currentUser['id'], 'kudos_list' => CM_KUDOS]); exit;
    }
    // Para la pantalla de inicio del chat (chat/chat-plus.js): cumpleaños, pregunta del día y café de la semana
    if ($action==='today') {
        try { cm_auto_sync($db); } catch (Throwable $e) {}
        echo json_encode(cm_today($db, (int)$currentUser['id'])); exit;
    }
    // "Quién sabe qué" (assets/cm-skills.js): todas las personas con sus herramientas y aptitudes, y la afinidad conmigo
    if ($action==='skills_people') {
        $all = pf_people($db); $me = null; foreach ($all as $p) if ($p['id'] === (int)$currentUser['id']) $me = $p;
        foreach ($all as &$p) $p['aff'] = ($me && $p['id'] !== $me['id']) ? pf_affinity($me, $p)['pct'] : null; unset($p);
        echo json_encode(['people' => $all, 'me' => (int)$currentUser['id']], JSON_UNESCAPED_UNICODE); exit;
    }
    if ($action==='mini_post') {
        $q = $db->prepare('SELECT p.id,p.body,p.img,p.kind,p.badge,p.created_at,p.target_user_id,u.id uid,u.username,u.display_name,u.avatar FROM g7_posts p JOIN g7_users u ON u.id=p.user_id WHERE p.id=?'); $q->execute([(int)($input['post_id'] ?? 0)]);
        $p = $q->fetch(PDO::FETCH_ASSOC); if (!$p) { echo json_encode(['error' => 'not found']); exit; }
        $t = null; if ($p['target_user_id']) { $tq = $db->prepare('SELECT COALESCE(NULLIF(display_name,\'\'),username) FROM g7_users WHERE id=?'); $tq->execute([(int)$p['target_user_id']]); $t = $tq->fetchColumn(); }
        $rx = $db->prepare('SELECT COUNT(*) FROM g7_post_reactions WHERE post_id=?'); $rx->execute([(int)$p['id']]);
        $rp = $db->prepare('SELECT COUNT(*) FROM g7_post_replies WHERE post_id=?'); $rp->execute([(int)$p['id']]);
        echo json_encode(['id' => (int)$p['id'], 'body' => mb_substr((string)$p['body'], 0, 220), 'img' => (string)$p['img'], 'kind' => $p['kind'], 'badge' => CM_KUDOS[$p['badge']] ?? null, 'target' => $t, 'created_at' => $p['created_at'],
            'author' => ['id' => (int)$p['uid'], 'name' => $p['display_name'] ?: $p['username'], 'avatar' => (string)$p['avatar']], 'reactions' => (int)$rx->fetchColumn(), 'replies' => (int)$rp->fetchColumn()]); exit;
    }
    if ($action==='edit_post') {
        $pid = (int)($input['post_id'] ?? 0); $body = trim((string)($input['body'] ?? ''));
        $row = $db->prepare('SELECT user_id,img FROM g7_posts WHERE id=?'); $row->execute([$pid]); $p = $row->fetch();
        if (!$p || (int)$p['user_id'] !== (int)$currentUser['id']) { echo json_encode(['error' => 'Solo podés editar tus publicaciones.']); exit; }
        if (mb_strlen($body) > CM_MAX_POST) { echo json_encode(['error' => 'El texto pasa de ' . CM_MAX_POST . ' caracteres']); exit; }
        if ($body === '' && !$p['img']) { echo json_encode(['error' => 'La publicación no puede quedar vacía.']); exit; }
        $db->prepare('UPDATE g7_posts SET body=?,edited_at=NOW() WHERE id=?')->execute([$body, $pid]);
        echo json_encode(['status' => 'success', 'body' => $body, 'edited_at' => date('Y-m-d H:i:s')]); exit;
    }
    if ($action==='give_kudos') {
        // Reglas, aviso y actividad en comunidad_lib.php (cm_give_kudos): las comparte con Bienestar
        try { $id = cm_give_kudos($db, (int)$currentUser['id'], (int)($input['user_id'] ?? 0), (string)($input['badge'] ?? ''), (string)($input['reason'] ?? '')); }
        catch (RuntimeException $e) { echo json_encode(['error' => $e->getMessage()]); exit; }
        $row = $db->prepare('SELECT p.*,u.username,u.display_name,u.avatar FROM g7_posts p JOIN g7_users u ON u.id=p.user_id WHERE p.id=?'); $row->execute([$id]);
        echo json_encode(['status' => 'success', 'post' => cm_hydrate($db, [$row->fetch()], (int)$currentUser['id'])[0]]); exit;
    }

    if ($action==='save_profile') {
        // Avatar y portada: solo rutas de archivos subidos acá. Cualquier otra cosa (URL externa, comillas, javascript:) deja la que estaba.
        $cur=$db->prepare('SELECT avatar,cover FROM g7_users WHERE id=?');$cur->execute([$currentUser['id']]);$cur=$cur->fetch()?:['avatar'=>'','cover'=>''];
        $okPath=fn($p,$dir)=>$p===''||preg_match('#^/toolz/'.$dir.'/[A-Za-z0-9_.-]+$#',$p);
        $avatarPath=(string)($_POST['current_avatar']??'');if(!$okPath($avatarPath,'avatars'))$avatarPath=(string)$cur['avatar'];
        $coverPath=(string)($_POST['current_cover']??'');if(!$okPath($coverPath,'covers'))$coverPath=(string)$cur['cover'];
        foreach (['avatar' => [$avatarFolder, '/toolz/avatars/'], 'cover' => [$coverFolder, '/toolz/covers/']] as $fk => [$fdir, $furl]) {
            if (!isset($_FILES[$fk])) continue;
            if ($why = g7_upload_problem($_FILES[$fk])) { echo json_encode(['status'=>'error','error'=>$why]); exit; }
            if ($_FILES[$fk]['error'] !== UPLOAD_ERR_OK) continue;
            $ext = g7_upload_ext_or_die($_FILES[$fk]['name'], G7_EXT_IMG); $nm = $fk.'_'.$currentUser['id'].'_'.time().'.'.$ext;
            if ($why = g7_upload_store($_FILES[$fk], $fdir, $nm)) { echo json_encode(['status'=>'error','error'=>$why]); exit; }
            if ($fk === 'avatar') $avatarPath = $furl.$nm; else $coverPath = $furl.$nm;
        }
        $skillsJson=json_encode((object)pf_clean_tools($_POST['skills_json']??'{}'));   // solo herramientas de la lista (profile_fields_lib.php)
        // Email válido o vacío; portfolio solo http(s); redes sin caracteres que rompan un atributo
        $pfEmail=trim((string)($_POST['email']??''));if($pfEmail!==''&&!filter_var($pfEmail,FILTER_VALIDATE_EMAIL)){echo json_encode(['status'=>'error','error'=>'El email no es válido.']);exit;}
        if($pfEmail!==''){$dup=$db->prepare('SELECT id FROM g7_users WHERE (email=? OR username=?) AND id<>? LIMIT 1');$dup->execute([$pfEmail,$pfEmail,$currentUser['id']]);if($dup->fetch()){echo json_encode(['status'=>'error','error'=>'Ese email ya lo usa otra persona.']);exit;}}
        $_POST['email']=$pfEmail;
        $pf=trim((string)($_POST['portfolio']??''));if($pf!==''&&!preg_match('#^https?://[^\s"\'<>]+$#i',$pf)){echo json_encode(['status'=>'error','error'=>'El portfolio tiene que ser un enlace http o https.']);exit;}
        foreach(['instagram','behance','linkedin'] as $sk)$_POST[$sk]=preg_replace('/[^A-Za-z0-9_.\-@\/:]/','',(string)($_POST[$sk]??''));
        $db->prepare('UPDATE g7_users SET display_name=?,phone=?,bio=?,position=?,location=?,avatar=?,cover=?,email=?,instagram=?,behance=?,linkedin=?,portfolio=?,skills=?,skills_json=? WHERE id=?')
           ->execute([trim($_POST['display_name']??''),trim($_POST['phone']??''),trim($_POST['bio']??''),trim($_POST['position']??''),trim($_POST['location']??''),$avatarPath,$coverPath,trim($_POST['email']??''),trim($_POST['instagram']??''),trim($_POST['behance']??''),trim($_POST['linkedin']??''),trim($_POST['portfolio']??''),trim($_POST['skills']??''),$skillsJson,$currentUser['id']]);
        // cumpleaños (se usa día y mes) y fecha de ingreso al equipo
        $okD=fn($v)=>preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$v)&&strtotime((string)$v)?(string)$v:null;
        // solo si vinieron en el pedido: cambiar la portada sola no los borra
        try{if(array_key_exists('birthday',$_POST))$db->prepare('UPDATE g7_users SET birthday=? WHERE id=?')->execute([$okD($_POST['birthday']),$currentUser['id']]);
            if(array_key_exists('joined',$_POST))$db->prepare('UPDATE g7_users SET joined_at=? WHERE id=?')->execute([$okD($_POST['joined']),$currentUser['id']]);}catch(Throwable $e){}
        // aptitudes (lo que sabe hacer, más allá de las herramientas)
        if(array_key_exists('aptitudes_json',$_POST)){pf_install($db);try{$db->prepare('UPDATE g7_users SET aptitudes=? WHERE id=?')->execute([json_encode(pf_clean_aptitudes($_POST['aptitudes_json']),JSON_UNESCAPED_UNICODE),$currentUser['id']]);}catch(Throwable $e){}}
        echo json_encode(['status'=>'success','avatar'=>$avatarPath,'cover'=>$coverPath]); exit;
    }
    if ($action==='get_profile') {
        $uid=(int)($input['user_id']??0);
        $u=$db->prepare('SELECT id,username,display_name,email,phone,bio,position,location,avatar,cover,role,last_login,total_time_sec,instagram,behance,linkedin,portfolio,skills,skills_json FROM g7_users WHERE id=?');$u->execute([$uid]);$profile=$u->fetch();if(!$profile){echo json_encode(['error'=>'not found']);exit;}
        $content=$db->prepare("SELECT id,name,type,project,created_at,img FROM g7_content WHERE author=? ORDER BY created_at DESC LIMIT 10");$content->execute([$profile['username']]);$items=$content->fetchAll();
        $s1=$db->prepare("SELECT COUNT(*) FROM g7_link_clicks WHERE user_id=?");$s1->execute([$uid]);
        $s2=$db->prepare("SELECT COUNT(*) FROM g7_content WHERE author=?");$s2->execute([$profile['username']]);
        $s3=$db->prepare("SELECT COUNT(*) FROM g7_link_favorites WHERE user_id=?");$s3->execute([$uid]);
        $crows=$db->prepare("SELECT c.*,u.username,u.display_name,u.avatar,u.role FROM g7_profile_comments c JOIN g7_users u ON u.id=c.author_id WHERE c.profile_id=? ORDER BY c.created_at ASC");$crows->execute([$uid]);
        $posts=$db->prepare("SELECT p.*,u.username,u.display_name,u.avatar FROM g7_posts p JOIN g7_users u ON u.id=p.user_id WHERE p.user_id=? ORDER BY p.created_at DESC LIMIT 10");$posts->execute([$uid]);$postList=$posts->fetchAll();
        foreach($postList as &$post){try{$r=$db->prepare("SELECT emoji,COUNT(*) as cnt FROM g7_post_reactions WHERE post_id=? GROUP BY emoji");$r->execute([$post['id']]);$post['reactions']=$r->fetchAll();$mr=$db->prepare("SELECT emoji FROM g7_post_reactions WHERE post_id=? AND user_id=?");$mr->execute([$post['id'],$currentUser['id']]);$post['my_reaction']=$mr->fetchColumn();}catch(Exception $e){$post['reactions']=[];$post['my_reaction']=false;}$post['replies']=[];}
        $work=null; if (is_file(__DIR__.'/trabajo/lib.php')) { try { require_once __DIR__.'/trabajo/user_work_lib.php'; $work=wk_user_work($db,(int)$currentUser['id'],$uid); } catch (Throwable $e) { $work=null; } }   // en qué trabaja (con los permisos de quien mira)
        $profile['loc']=g7_tz_public(g7_tz_row($uid));   // país, zona y hora local (ciudad solo si la comparte): tz_lib.php
        $extra = cm_profile_extra($db, $uid);   // estado, rango, logros y reconocimientos (comunidad_lib.php)
        $pq = $db->prepare('SELECT p.*,u.username,u.display_name,u.avatar FROM g7_posts p JOIN g7_users u ON u.id=p.user_id WHERE p.user_id=? ORDER BY p.created_at DESC LIMIT 15'); $pq->execute([$uid]);
        $postList = cm_hydrate($db, $pq->fetchAll(), (int)$currentUser['id']);
        echo json_encode(['extra'=>$extra,'work'=>$work,'profile'=>$profile,'content'=>$items,'comments'=>$crows->fetchAll(),'stats'=>['clicks'=>$s1->fetchColumn(),'content'=>$s2->fetchColumn(),'favs'=>$s3->fetchColumn()],'posts'=>$postList]);exit;
    }
    if ($action==='add_post') {
        $body=trim($_POST['body']??($input['body']??''));$imgPath='';
        if (isset($_FILES['post_img'])) {
            if ($why = g7_upload_problem($_FILES['post_img'])) { echo json_encode(['status'=>'error','error'=>$why]); exit; }
            if ($_FILES['post_img']['error'] === UPLOAD_ERR_OK) {
                $ext = g7_upload_ext_or_die($_FILES['post_img']['name'], G7_EXT_IMG); $nm = 'post_'.$currentUser['id'].'_'.time().'.'.$ext;
                if ($why = g7_upload_store($_FILES['post_img'], __DIR__.'/post_imgs/', $nm)) { echo json_encode(['status'=>'error','error'=>$why]); exit; }
                $imgPath = '/toolz/post_imgs/'.$nm;
            }
        }
        // Mucho texto que pasa el límite no se corta en silencio: se avisa
        if (mb_strlen($body) > CM_MAX_POST) { echo json_encode(['status'=>'error','error'=>'El texto pasa de '.CM_MAX_POST.' caracteres']); exit; }
        // Doble clic o reenvío: la misma publicación en los últimos 2 minutos no se repite
        if ($body !== '' && $imgPath === '') { $dq = $db->prepare('SELECT COUNT(*) FROM g7_posts WHERE user_id=? AND body=? AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)'); $dq->execute([$currentUser['id'], $body]); if ((int)$dq->fetchColumn()) { echo json_encode(['status'=>'error','error'=>'Ya publicaste eso recién.']); exit; } }
        if(($body!==''||$imgPath!=='')&&mb_strlen($body)<=CM_MAX_POST){
            try{$db->prepare('INSERT INTO g7_posts (user_id,body,img) VALUES (?,?,?)')->execute([$currentUser['id'],$body,$imgPath]);}catch(Exception $e){$db->prepare('INSERT INTO g7_posts (user_id,body) VALUES (?,?)')->execute([$currentUser['id'],$body]);$imgPath='';}
            $newId=$db->lastInsertId();$row=$db->prepare('SELECT p.*,u.username,u.display_name,u.avatar FROM g7_posts p JOIN g7_users u ON u.id=p.user_id WHERE p.id=?');$row->execute([$newId]);$post=$row->fetch();$post['img']=$imgPath;$post['replies']=[];$post['reactions']=[];$post['my_reaction']=false;
            try{$db->prepare('INSERT INTO g7_activity (user_id,type,target_name,meta) VALUES (?,?,?,?)')->execute([$currentUser['id'],'post_create',mb_substr($body,0,60),'post']);}catch(Exception $e){}
            cm_notify_mentions($db, $body, (int)$currentUser['id'], 'mention_post', (int)$newId);
            $post['kind']='post';$post['target']=null;
            echo json_encode(['status'=>'success','post'=>$post]);exit;
        }
        echo json_encode(['error'=>'invalid']);exit;
    }
    if ($action==='delete_post') {
        $pid=(int)($input['post_id']??0);$row=$db->prepare('SELECT user_id FROM g7_posts WHERE id=?');$row->execute([$pid]);$p=$row->fetch();
        if($p&&($p['user_id']==$currentUser['id']||$isAdmin)){$db->prepare('DELETE FROM g7_posts WHERE id=?')->execute([$pid]);echo json_encode(['status'=>'success']);}
        else echo json_encode(['error'=>'forbidden']);exit;
    }
    if ($action==='react') {
        try { $r = cm_react($db, (int)$currentUser['id'], (int)($input['post_id'] ?? 0), (string)($input['emoji'] ?? 'like')); }   // comunidad_lib.php (también lo usa Bienestar)
        catch (RuntimeException $e) { echo json_encode(['error' => $e->getMessage()]); exit; }
        echo json_encode(['status'=>'success','reactions'=>$r['reactions'],'my_reaction'=>$r['my_reaction']]);exit;
    }
    if ($action==='add_reply') {
        try { $reply = cm_add_reply($db, (int)$currentUser['id'], (int)($input['post_id'] ?? 0), (string)($input['body'] ?? '')); }   // comunidad_lib.php: avisa a quien publicó y a los mencionados
        catch (RuntimeException $e) { echo json_encode(['error'=>$e->getMessage()]); exit; }
        echo json_encode(['status'=>'success','reply'=>$reply]);exit;
    }
    if ($action==='delete_reply') {
        $rid=(int)($input['reply_id']??0);$row=$db->prepare('SELECT user_id FROM g7_post_replies WHERE id=?');$row->execute([$rid]);$r=$row->fetch();
        if($r&&($r['user_id']==$currentUser['id']||$isAdmin)){$db->prepare('DELETE FROM g7_post_replies WHERE id=?')->execute([$rid]);echo json_encode(['status'=>'success']);}
        else echo json_encode(['error'=>'forbidden']);exit;
    }
    if ($action==='get_posts') {
        $since=$input['since']??'2000-01-01';$rows=$db->prepare("SELECT p.*,u.username,u.display_name,u.avatar FROM g7_posts p JOIN g7_users u ON u.id=p.user_id WHERE p.created_at>? ORDER BY p.created_at DESC LIMIT 20");$rows->execute([$since]);
        echo json_encode(['posts'=>cm_hydrate($db,$rows->fetchAll(),(int)$currentUser['id'])]);exit;
    }
    if ($action==='add_comment') {
        $profileId=(int)($input['profile_id']??0);$parentId=$input['parent_id']?(int)$input['parent_id']:null;$body=trim($input['body']??'');
        if($profileId&&$body){$db->prepare('INSERT INTO g7_profile_comments (profile_id,author_id,parent_id,body) VALUES (?,?,?,?)')->execute([$profileId,$currentUser['id'],$parentId,$body]);$newId=$db->lastInsertId();$row=$db->prepare('SELECT c.*,u.username,u.display_name,u.avatar,u.role FROM g7_profile_comments c JOIN g7_users u ON u.id=c.author_id WHERE c.id=?');$row->execute([$newId]);$comment=$row->fetch();pushNotif($db,$profileId,$currentUser['id'],'comment',$newId,$profileId,$body);if($parentId){$par=$db->prepare('SELECT author_id FROM g7_profile_comments WHERE id=?');$par->execute([$parentId]);$parRow=$par->fetch();if($parRow)pushNotif($db,$parRow['author_id'],$currentUser['id'],'reply',$newId,$profileId,$body);}echo json_encode(['status'=>'success','comment'=>$comment]);exit;}
        echo json_encode(['error'=>'missing']);exit;
    }
    if ($action==='delete_comment') {
        $cid=(int)($input['comment_id']??0);$row=$db->prepare('SELECT author_id,profile_id FROM g7_profile_comments WHERE id=?');$row->execute([$cid]);$c=$row->fetch();
        if($c&&($c['author_id']==$currentUser['id']||$c['profile_id']==$currentUser['id']||$isAdmin)){$db->prepare('DELETE FROM g7_profile_comments WHERE id=? OR parent_id=?')->execute([$cid,$cid]);echo json_encode(['status'=>'success']);}
        else echo json_encode(['error'=>'forbidden']);exit;
    }

    // PIN POST
    if ($action==='pin_post') {
        $pid=(int)($input['post_id']??0);
        if($isAdmin){
            $cur=$db->prepare('SELECT pinned FROM g7_posts WHERE id=?');$cur->execute([$pid]);$row=$cur->fetch();
            $newPin=$row?($row['pinned']?0:1):0;
            $db->prepare('UPDATE g7_posts SET pinned=? WHERE id=?')->execute([$newPin,$pid]);
            if($newPin) $db->prepare('UPDATE g7_posts SET pinned=0 WHERE id!=?')->execute([$pid]); // only 1 pinned at a time
            echo json_encode(['status'=>'success','pinned'=>(bool)$newPin]);
        } else echo json_encode(['error'=>'forbidden']);
        exit;
    }
    // SUGGEST PIN
    if ($action==='suggest_pin') {
        $pid=(int)($input['post_id']??0);
        $db->prepare('UPDATE g7_posts SET pin_suggested=1 WHERE id=?')->execute([$pid]);
        echo json_encode(['status'=>'success']); exit;
    }
    // UPDATE STATUS
    if ($action==='update_status') {
        $avail=trim($input['availability']??'available');
        $focus=mb_substr(trim($input['focus_text']??''),0,140);
        $mood=trim($input['mood']??'');
        try{
            $avail=pr_key($avail); $curAv=$db->query('SELECT availability FROM g7_users WHERE id='.(int)$currentUser['id'])->fetchColumn(); if($avail!==pr_key((string)$curAv)) pr_set($db,(int)$currentUser['id'],$avail,$focus);
            $db->prepare('UPDATE g7_users SET focus_text=?,mood=?,last_seen=NOW() WHERE id=?')->execute([$focus,$mood,$currentUser['id']]);
            if($focus) try{$db->prepare('INSERT INTO g7_activity (user_id,type,target_name,meta) VALUES (?,?,?,?)')->execute([$currentUser['id'],'focus_update',mb_substr($focus,0,80),'focus']);}catch(Exception $e2){}
            elseif($avail!=='available') try{$db->prepare('INSERT INTO g7_activity (user_id,type,target_name,meta) VALUES (?,?,?,?)')->execute([$currentUser['id'],'status_update',G7_PRESENCE[$avail][0],'status']);}catch(Exception $e2){}
            echo json_encode(['status'=>'success']);
        }catch(Exception $e){echo json_encode(['status'=>'ok']);}
        exit;
    }
    // HEARTBEAT (update last_seen)
    if ($action==='heartbeat_status') {
        $db->prepare('UPDATE g7_users SET last_seen=NOW() WHERE id=?')->execute([$currentUser['id']]);
        echo json_encode(['status'=>'success']); exit;
    }
    // GET STATUS (for team widget)
    if ($action==='get_team_status') {
        pr_sweep($db);
        $rows=$db->query("SELECT id,username,display_name,avatar,availability,focus_text,mood,last_seen FROM g7_users WHERE active=1")->fetchAll();
        foreach($rows as &$r){ $ls=$r['last_seen']?strtotime($r['last_seen']):0; $r['online']=(time()-$ls)<300; } // 5min = online
        echo json_encode(['users'=>$rows]); exit;
    }
    // GET OG PREVIEW
    if ($action==='og_preview') {
        $url=trim($input['url']??'');
        if(!$url||!filter_var($url,FILTER_VALIDATE_URL)){echo json_encode(['error'=>'invalid']);exit;}
        require_once __DIR__.'/net_lib.php';   // solo http(s) públicas: nada de file://, php:// ni la red interna
        $got=g7_net_fetch($url,['text/html','application/xhtml'],600000,5);
        if(!$got){echo json_encode(['error'=>'fetch failed']);exit;}
        $html=$got['body'];
        $title=$desc=$img='';
        if(preg_match("/property=[\"'](og:title)[\"']/i",$html,$m2)&&preg_match("/content=[\"'](.*?)[\"']/i",$m2[0],$m))$title=$m[1];
        elseif(preg_match("/<title>([^<]+)<\/title>/i",$html,$m))$title=html_entity_decode(strip_tags($m[1]));
        if(preg_match("/property=[\"'](og:description)[\"']/i",$html,$m2)&&preg_match("/content=[\"'](.*?)[\"']/i",$m2[0],$m))$desc=$m[1];
        if(preg_match("/property=[\"'](og:image)[\"']/i",$html,$m2)&&preg_match("/content=[\"'](.*?)[\"']/i",$m2[0],$m))$img=$m[1];
        if($img!==''&&!preg_match('#^https?://#i',$img))$img='';
        echo json_encode(['title'=>html_entity_decode($title),'description'=>html_entity_decode($desc),'image'=>$img,'url'=>$url]); exit;
    }
    if ($action==='get_activity') {
        $items = g7GetRecentActivity($db, 30);
        echo json_encode(['activities'=>$items]); exit;
    }
    if ($action==='get_notifications') {
        try{
            // Solo lo de la Comunidad: los avisos de tickets, ingresos, etc. están en la campanita de arriba
            $tin="'".implode("','",CM_NOTIF_TYPES)."'";
            $rows=$db->prepare("SELECT n.*,u.username,u.display_name,u.avatar FROM g7_notifications n LEFT JOIN g7_users u ON u.id=n.from_id WHERE n.user_id=? AND n.type IN ($tin) ORDER BY n.created_at DESC LIMIT 20");
            $rows->execute([$currentUser['id']]);$notifs=$rows->fetchAll();
            $unread=$db->prepare("SELECT COUNT(*) FROM g7_notifications WHERE user_id=? AND read_at IS NULL AND type IN ($tin)");
            $unread->execute([$currentUser['id']]);
            echo json_encode(['notifications'=>$notifs,'unread'=>(int)$unread->fetchColumn()]);
        }catch(Exception $e){echo json_encode(['notifications'=>[],'unread'=>0]);}
        exit;
    }
    if ($action==='mark_notifications_read') {
        try{$db->prepare("UPDATE g7_notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL AND type IN ('".implode("','",CM_NOTIF_TYPES)."')")->execute([$currentUser['id']]);}catch(Exception $e){}
        echo json_encode(['status'=>'success']); exit;
    }
}

$meRow=$db->prepare('SELECT * FROM g7_users WHERE id=?');$meRow->execute([$currentUser['id']]);$me=$meRow->fetch()??[];try{$db->prepare('UPDATE g7_users SET last_seen=NOW() WHERE id=?')->execute([$currentUser['id']]);}catch(Exception $e){}
try{$users=$db->query("SELECT id,username,display_name,email,phone,bio,position,location,avatar,cover,role,last_login,instagram,behance,linkedin,portfolio,skills,skills_json,active,availability,focus_text,mood,last_seen FROM g7_users WHERE active=1 ORDER BY display_name,username")->fetchAll();}catch(Exception $e){$users=$db->query("SELECT id,username,display_name,email,phone,bio,position,location,avatar,cover,role,last_login,instagram,behance,linkedin,portfolio,skills,skills_json,active FROM g7_users WHERE active=1 ORDER BY display_name,username")->fetchAll();foreach($users as &$ur){$ur['availability']='available';$ur['focus_text']='';$ur['mood']='';$ur['last_seen']=null;}}
cm_install3($db); try { cm_auto_sync($db); } catch (Throwable $e) {} $cmFirst = cm_feed($db, $currentUser); $feedPosts = $cmFirst['posts'];
$activity = g7GetRecentActivity($db, 30);
$userStats=[];foreach($users as $u){try{$s1=$db->prepare("SELECT COUNT(*) FROM g7_link_clicks WHERE user_id=? AND clicked_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)");$s1->execute([$u['id']]);$s2=$db->prepare("SELECT COUNT(*) FROM g7_content WHERE author=?");$s2->execute([$u['username']]);$userStats[$u['id']]=['clicks'=>$s1->fetchColumn(),'content'=>$s2->fetchColumn()];}catch(Exception $e){$userStats[$u['id']]=['clicks'=>0,'content'=>0];}}
$SKILLS_LIST=pf_tools_flat();   // profile_fields_lib.php
$mySkillsJson=json_decode($me['skills_json']??'{}',true)??[];
$myClicksStat=$db->prepare("SELECT COUNT(*) FROM g7_link_clicks WHERE user_id=? AND clicked_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)");$myClicksStat->execute([$currentUser['id']]);
$myContentStat=$db->prepare("SELECT COUNT(*) FROM g7_content WHERE author=?");$myContentStat->execute([$currentUser['username']]);
$myFavsStat=$db->prepare("SELECT COUNT(*) FROM g7_link_favorites WHERE user_id=?");$myFavsStat->execute([$currentUser['id']]);

function avHtml($av,$name,$size){if($av)return"<img src=\"$av\" style=\"width:{$size}px;height:{$size}px;border-radius:50%;object-fit:cover;flex-shrink:0\">";$i=strtoupper(substr($name,0,1));$c=['#ff6600','#a78bfa','#4ade80','#60a5fa','#f59e0b','#ec4899'][ord($i)%6];$fs=floor($size*.42);return"<div style=\"width:{$size}px;height:{$size}px;border-radius:50%;background:{$c};display:flex;align-items:center;justify-content:center;font-size:{$fs}px;font-weight:700;color:#000;flex-shrink:0\">{$i}</div>";}
function timeAgo($d){$diff=time()-strtotime($d);if($diff<60)return'ahora';if($diff<3600)return floor($diff/60).'m';if($diff<86400)return floor($diff/3600).'h';return floor($diff/86400).'d';}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Comunidad - <?= htmlspecialchars(g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0a0a0a;color:#e4e4e7;font-family:'Inter',sans-serif;min-height:100vh}
.page{display:grid;grid-template-columns:280px minmax(0,620px) 260px;gap:0;min-height:calc(100vh - 52px);justify-content:center;max-width:1200px;margin:0 auto}
@media(max-width:1100px){.page{grid-template-columns:260px 1fr}}
@media(max-width:760px){.page{grid-template-columns:1fr}}

/* LEFT SIDEBAR - MY PROFILE */
.left-col{border-right:1px solid #141414;padding:18px 14px;position:sticky;top:52px;height:calc(100vh - 52px);overflow-y:auto;flex-shrink:0}
.left-col::-webkit-scrollbar{width:2px}.left-col::-webkit-scrollbar-thumb{background:#1f1f23}

.profile-cover-wrap{position:relative;border-radius:12px;overflow:hidden;margin-bottom:-30px;cursor:pointer;height:80px}
.profile-cover-wrap img,.profile-cover-placeholder{width:100%;height:100%;object-fit:cover}
.profile-cover-placeholder{background:linear-gradient(135deg,rgba(255,102,0,.4),rgba(139,92,246,.3));display:flex;align-items:center;justify-content:center}
.cover-cam-btn{position:absolute;inset:0;background:rgba(0,0,0,0);display:flex;align-items:center;justify-content:center;transition:background .2s;border:none;cursor:pointer;width:100%}
.cover-cam-btn:hover{background:rgba(0,0,0,.45)}
.cover-cam-btn i{color:#fff;font-size:16px;opacity:0;transition:opacity .2s}
.cover-cam-btn:hover i{opacity:1}

.profile-av-row{display:flex;align-items:flex-end;justify-content:space-between;padding:0 4px;margin-bottom:10px}
.my-av-wrap{border:3px solid #0a0a0a;border-radius:50%;cursor:pointer;position:relative;flex-shrink:0}
.my-av-wrap:hover::after{content:'';position:absolute;inset:0;border-radius:50%;background:rgba(0,0,0,.4)}
.my-name{font-size:15px;font-weight:800;margin-bottom:1px;display:flex;align-items:center;gap:6px}
.my-handle{font-size:11px;color:#52525b;margin-bottom:6px}
.my-pos{font-size:11px;color:#a1a1aa;margin-bottom:8px;display:flex;align-items:center;gap:5px}
.my-bio{font-size:11px;color:#71717a;line-height:1.6;margin-bottom:10px}
.my-stats{display:grid;grid-template-columns:repeat(3,1fr);text-align:center;border:1px solid #1f1f23;border-radius:10px;overflow:hidden;margin-bottom:12px}
.my-stat{padding:8px 4px;background:#111}
.my-stat .v{font-size:14px;font-weight:700;color:#ff6600}
.my-stat .l{font-size:9px;color:#52525b}
.social-wrap{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:10px}
.sl{display:inline-flex;align-items:center;gap:4px;padding:4px 8px;border-radius:6px;font-size:10px;font-weight:600;text-decoration:none;transition:opacity .15s}
.sl:hover{opacity:.8}
.sl-ig{background:rgba(225,48,108,.12);color:#e1306c;border:1px solid rgba(225,48,108,.25)}
.sl-be{background:rgba(23,105,255,.12);color:#1769ff;border:1px solid rgba(23,105,255,.25)}
.sl-li{background:rgba(10,102,194,.12);color:#0a66c2;border:1px solid rgba(10,102,194,.25)}
.sl-web{background:rgba(96,165,250,.12);color:#60a5fa;border:1px solid rgba(96,165,250,.25)}
/* skill tags */
#skills-tag-selector::-webkit-scrollbar{width:3px}
#skills-tag-selector::-webkit-scrollbar-thumb{background:#27272a;border-radius:2px}
.profile-actions{display:flex;gap:6px;margin-bottom:12px}
.btn-edit{flex:1;background:rgba(255,102,0,.1);border:1px solid rgba(255,102,0,.25);color:#ff6600;border-radius:8px;padding:8px;font-size:11px;font-weight:700;cursor:pointer;transition:all .15s;display:flex;align-items:center;justify-content:center;gap:5px}
.btn-edit:hover{background:rgba(255,102,0,.18)}
.btn-preview{background:#18181b;border:1px solid #27272a;color:#71717a;border-radius:8px;padding:8px 10px;font-size:11px;cursor:pointer;transition:all .15s;display:flex;align-items:center;justify-content:center;gap:5px}
.btn-preview:hover{color:#e4e4e7;border-color:#3f3f46}
.pf-row{display:flex;align-items:center;gap:7px;color:#a1a1aa;font-size:11px;margin-bottom:4px}
.pf-row i{width:12px;text-align:center;color:#52525b;flex-shrink:0}
.role-badge{font-size:8px;font-weight:700;border-radius:3px;padding:2px 5px;text-transform:uppercase}
.rb-admin{background:rgba(255,102,0,.12);color:#ff6600}
.rb-user{background:#1f1f23;color:#52525b}
.nav-section{font-size:9px;font-weight:700;color:#3f3f46;text-transform:uppercase;letter-spacing:.1em;margin:14px 0 6px}
.nav-item{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:all .15s;font-size:12px;font-weight:600;color:#71717a;border:none;background:none;width:100%;text-align:left}
.nav-item:hover,.nav-item.active{background:#18181b;color:#e4e4e7}
.nav-item.active{color:#ff6600}
.nav-item i{width:14px;text-align:center;font-size:13px}

/* FEED - CENTER */
.feed-col{border-right:1px solid #141414;min-height:calc(100vh - 52px);max-width:680px;width:100%;padding:18px 20px}
.feed-col::-webkit-scrollbar{display:none}

/* POST COMPOSER */
.composer{background:#111;border:1px solid #1f1f23;border-radius:14px;padding:16px;margin-bottom:18px}
.comp-top{display:flex;gap:10px;margin-bottom:12px}
.comp-input{flex:1;background:#18181b;border:1px solid #27272a;border-radius:10px;padding:10px 14px;font-size:13px;color:#e4e4e7;resize:none;outline:none;min-height:48px;font-family:inherit;line-height:1.5;transition:border-color .15s}
.comp-input:focus{border-color:#ff6600}
.comp-footer{display:flex;align-items:center;justify-content:space-between}
.comp-actions{display:flex;gap:4px}
.comp-action-btn{background:none;border:none;color:#52525b;cursor:pointer;padding:6px 8px;border-radius:6px;font-size:14px;transition:all .15s}
.comp-action-btn:hover{color:#ff6600;background:rgba(255,102,0,.08)}
.char-count{font-size:11px;color:#3f3f46}
.char-count.warn{color:#f59e0b}.char-count.over{color:#ef4444}
.pub-btn{background:#ff6600;color:#000;border:none;border-radius:8px;padding:8px 18px;font-size:12px;font-weight:700;cursor:pointer;transition:opacity .15s}
.pub-btn:hover{opacity:.85}.pub-btn:disabled{opacity:.4;cursor:not-allowed}
.img-preview-wrap{margin-top:10px;position:relative;display:none}
.img-preview-wrap img{max-height:180px;border-radius:8px;object-fit:cover;max-width:100%;display:block}
.img-rm{position:absolute;top:6px;right:6px;background:rgba(0,0,0,.7);border:none;color:#fff;border-radius:50%;width:22px;height:22px;cursor:pointer;font-size:11px;display:flex;align-items:center;justify-content:center}

/* FEED TABS */
.feed-tabs{display:flex;gap:2px;margin-bottom:16px;background:#111;border:1px solid #1f1f23;border-radius:10px;padding:3px}
.feed-tab{flex:1;padding:8px;border:none;background:none;color:#71717a;font-size:12px;font-weight:600;cursor:pointer;border-radius:8px;transition:all .15s}
.feed-tab.active{background:#ff6600;color:#000}

/* POST CARD */
.post-card{background:#111;border:1px solid #1f1f23;border-radius:14px;padding:16px;margin-bottom:14px;transition:border-color .2s}
.post-card:hover{border-color:#1f1f23}
.post-header{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.post-author-name{font-size:13px;font-weight:700;cursor:pointer;color:#e4e4e7;transition:color .15s}
.post-author-name:hover{color:#ff6600}
.post-handle{font-size:11px;color:#52525b;margin-top:1px}
.post-time{font-size:11px;color:#3f3f46;margin-left:auto}
.post-del{background:none;border:none;color:#3f3f46;cursor:pointer;font-size:12px;padding:4px 6px;border-radius:5px;transition:all .15s;flex-shrink:0}
.post-del:hover{color:#ef4444;background:rgba(239,68,68,.08)}
.post-body{font-size:14px;line-height:1.65;color:#e4e4e7;margin-bottom:12px;word-break:break-word}
.post-img{width:100%;border-radius:10px;object-fit:cover;max-height:400px;display:block;cursor:zoom-in;margin-bottom:12px}
.post-reactions{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.rxn-btn{display:flex;align-items:center;gap:5px;padding:5px 10px;border-radius:20px;border:1px solid #27272a;background:#18181b;cursor:pointer;font-size:13px;transition:all .15s;color:#71717a}
.rxn-btn:hover{border-color:#3f3f46;background:#1f1f23;color:#e4e4e7}
.rxn-btn.active{border-color:rgba(255,102,0,.4);background:rgba(255,102,0,.08);color:#ff6600}
.rxn-cnt{font-size:11px;font-weight:700}
.post-replies{margin-top:10px;border-top:1px solid #141414;padding-top:8px}
.reply-item{display:flex;gap:8px;margin-bottom:8px}
.reply-bubble{flex:1;background:#18181b;border-radius:8px;padding:8px 10px}
.reply-author{font-size:11px;font-weight:700;cursor:pointer;color:#e4e4e7;transition:color .15s}
.reply-author:hover{color:#ff6600}
.reply-time{font-size:10px;color:#3f3f46;margin-left:6px}
.reply-body{font-size:12px;color:#a1a1aa;line-height:1.5;margin-top:3px;word-break:break-word}
.reply-del{background:none;border:none;color:#3f3f46;cursor:pointer;font-size:10px;padding:0;float:right;transition:color .15s}
.reply-del:hover{color:#ef4444}
.reply-toggle{background:none;border:none;color:#52525b;font-size:11px;cursor:pointer;display:flex;align-items:center;gap:5px;padding:0;transition:color .15s;margin-top:2px}
.reply-toggle:hover{color:#ff6600}
.reply-form{display:none;margin-top:8px}
.reply-form.open{display:flex;gap:8px;align-items:flex-end}
.reply-input{flex:1;background:#18181b;border:1px solid #27272a;color:#e4e4e7;border-radius:8px;padding:7px 10px;font-size:12px;resize:none;outline:none;min-height:34px;font-family:inherit;line-height:1.5;transition:border-color .15s}
.reply-input:focus{border-color:#ff6600}
.reply-send{background:#ff6600;color:#000;border:none;border-radius:7px;padding:7px 10px;font-size:11px;font-weight:700;cursor:pointer;flex-shrink:0}

/* TEAM SECTION */
.team-section{background:#111;border:1px solid #1f1f23;border-radius:14px;padding:16px;margin-bottom:14px}
.team-scroll{display:flex;gap:10px;overflow-x:auto;padding-bottom:8px;scroll-snap-type:x mandatory}
.team-scroll::-webkit-scrollbar{height:2px}.team-scroll::-webkit-scrollbar-thumb{background:#27272a}
.team-card{flex-shrink:0;width:160px;background:#18181b;border:1px solid #27272a;border-radius:10px;overflow:hidden;cursor:pointer;transition:all .2s;scroll-snap-align:start}
.team-card:hover{border-color:#ff6600;transform:translateY(-2px)}
.tc-cover{height:44px;background:linear-gradient(135deg,rgba(255,102,0,.2),rgba(139,92,246,.15));overflow:hidden;flex-shrink:0}
.tc-cover img{width:100%;height:100%;object-fit:cover}
.tc-body{padding:8px 10px}
.tc-av-row{display:flex;align-items:flex-end;gap:7px;margin-top:-18px;margin-bottom:6px}
.tc-name{font-size:11px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tc-pos{font-size:9px;color:#71717a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tc-bio{font-size:10px;color:#71717a;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:24px;margin-bottom:6px}
.tc-stats{display:flex;justify-content:space-between;border-top:1px solid #141414;padding-top:6px}
.tc-stat .v{font-size:11px;font-weight:700;color:#ff6600;display:block;text-align:center}
.tc-stat .l{font-size:8px;color:#3f3f46;display:block;text-align:center}
.team-expanded{display:none;margin-top:12px;display:none}
.team-exp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px}

/* RIGHT SIDEBAR */
.right-col{padding:18px 14px;position:sticky;top:52px;height:calc(100vh - 52px);overflow-y:auto;border-left:1px solid #141414}
.right-col::-webkit-scrollbar{width:2px}.right-col::-webkit-scrollbar-thumb{background:#1f1f23}
.widget{background:#111;border:1px solid #1f1f23;border-radius:12px;padding:14px;margin-bottom:14px}
.widget-title{font-size:10px;font-weight:700;color:#52525b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.widget-title i{color:#ff6600}
.live-dot{width:6px;height:6px;border-radius:50%;background:#22c55e;animation:pulse 2s infinite;flex-shrink:0}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.af-item{display:flex;gap:8px;padding:8px 0;border-bottom:1px solid #141414;align-items:flex-start}
.af-item:last-child{border:none;padding-bottom:0}
.af-icon{width:24px;height:24px;border-radius:5px;background:#18181b;display:flex;align-items:center;justify-content:center;font-size:10px;flex-shrink:0}
.af-text{font-size:11px;color:#a1a1aa;line-height:1.4;flex:1;min-width:0}
.user-link{font-weight:700;cursor:pointer;color:#e4e4e7}.user-link:hover{color:#ff6600}
.hl{color:#ff6600;cursor:pointer;text-decoration:underline;text-decoration-color:rgba(255,102,0,.3)}.hl:hover{text-decoration-color:#ff6600}
.af-time{font-size:10px;color:#3f3f46;margin-top:2px}
.skill-chip{display:inline-flex;align-items:center;background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.2);color:#60a5fa;border-radius:20px;padding:2px 8px;font-size:10px;font-weight:600;margin:2px}

/* PROFILE MODAL */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:500;display:flex;align-items:center;justify-content:center;padding:16px}
.pm{background:#0d0d0d;border:1px solid #27272a;border-radius:18px;width:100%;max-width:860px;max-height:92vh;overflow:hidden;display:flex;flex-direction:column}
.pm-cover{height:120px;position:relative;flex-shrink:0;overflow:hidden}
.pm-cover-bg{width:100%;height:100%;object-fit:cover}
.pm-cover-grad{position:absolute;inset:0;background:linear-gradient(to bottom,transparent 40%,rgba(0,0,0,.7))}
.pm-av{position:absolute;bottom:-28px;left:22px;border:3px solid #0d0d0d;border-radius:50%;cursor:zoom-in}
.pm-close{position:absolute;top:10px;right:12px;background:rgba(0,0,0,.5);border:none;color:#e4e4e7;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:13px;z-index:1}
.pm-close:hover{background:rgba(0,0,0,.8)}
.pm-body{display:grid;grid-template-columns:1fr 1fr;flex:1;overflow:hidden;min-height:0}
.pm-left{overflow-y:auto;padding:44px 20px 20px;border-right:1px solid #1a1a1a}
.pm-left::-webkit-scrollbar,.pm-right::-webkit-scrollbar{width:2px}
.pm-left::-webkit-scrollbar-thumb,.pm-right::-webkit-scrollbar-thumb{background:#27272a}
.pm-right{overflow-y:auto;display:flex;flex-direction:column;min-height:0}
.pm-name{font-size:17px;font-weight:800;margin-bottom:2px}
.pm-pos{font-size:12px;color:#71717a;margin-bottom:8px}
.pm-bio{font-size:12px;color:#a1a1aa;line-height:1.5;margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid #1a1a1a}
.pm-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:12px}
.pm-stat{background:#111;border:1px solid #1f1f23;border-radius:8px;padding:8px;text-align:center}
.pm-stat .v{font-size:14px;font-weight:700;color:#ff6600}
.pm-stat .l{font-size:9px;color:#52525b}
.pm-field{margin-bottom:7px;font-size:12px;color:#a1a1aa;display:flex;align-items:center;gap:7px}
.pm-field i{color:#52525b;width:14px;text-align:center;flex-shrink:0}
.sec-head{font-size:10px;font-weight:700;color:#52525b;text-transform:uppercase;letter-spacing:.08em;margin:12px 0 7px;display:flex;align-items:center;gap:5px}
.cm-scroll{display:flex;gap:7px;overflow-x:auto;padding-bottom:5px;margin-bottom:10px}
.cm-scroll::-webkit-scrollbar{height:2px}.cm-scroll::-webkit-scrollbar-thumb{background:#27272a}
.cm-mini{flex-shrink:0;width:110px;background:#111;border:1px solid #1f1f23;border-radius:8px;overflow:hidden;cursor:pointer;text-decoration:none;display:block;transition:border-color .15s}
.cm-mini:hover{border-color:#ff6600}
.cm-mini .ci{width:100%;height:60px;background:#1a1a1a;display:flex;align-items:center;justify-content:center;overflow:hidden}
.cm-mini .ci img{width:100%;height:100%;object-fit:cover}
.cm-mini .cb{padding:5px 7px}
.cm-mini .cn{font-size:10px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#e4e4e7}
.cm-mini .ct{font-size:8px;font-weight:700;text-transform:uppercase;border-radius:3px;padding:1px 4px;display:inline-block;margin-top:2px}
.comments-col{flex:1;display:flex;flex-direction:column;min-height:0}
.comments-head{padding:12px 16px;border-bottom:1px solid #1a1a1a;font-size:10px;font-weight:700;color:#52525b;text-transform:uppercase;letter-spacing:.08em;flex-shrink:0}
.comments-list{flex:1;overflow-y:auto;padding:12px 14px}
.comment{margin-bottom:10px}
.cbubble{background:#111;border:1px solid #1f1f23;border-radius:10px;padding:10px 12px}
.ch{display:flex;align-items:center;gap:7px;margin-bottom:5px;flex-wrap:wrap}
.ca{font-size:11px;font-weight:700;cursor:pointer;color:#e4e4e7}.ca:hover{color:#ff6600}
.ct{font-size:10px;color:#3f3f46;margin-left:auto}
.cb-text{font-size:12px;line-height:1.5;color:#a1a1aa;word-break:break-word}
.cb-text .mention{color:#ff6600;font-weight:600}
.cactions{display:flex;gap:8px;margin-top:5px}
.ca-btn{background:none;border:none;font-size:10px;color:#3f3f46;cursor:pointer;transition:color .15s;display:flex;align-items:center;gap:3px;padding:0}
.ca-btn:hover{color:#a1a1aa}.ca-btn.del:hover{color:#ef4444}
.creplies{margin-left:16px;margin-top:7px;border-left:2px solid #1f1f23;padding-left:10px}
.creply-form{margin-top:6px;margin-left:16px;display:none}
.creply-form.open{display:flex;gap:6px;align-items:flex-start}
.ci-area{width:100%;background:#18181b;border:1px solid #27272a;color:#e4e4e7;border-radius:8px;padding:7px 10px;font-size:12px;resize:none;outline:none;min-height:46px;font-family:inherit;line-height:1.5}
.ci-area:focus{border-color:#ff6600}
.ci-area.small{flex:1;min-height:32px;font-size:11px}
.ci-send{background:#ff6600;color:#000;border:none;border-radius:7px;padding:6px 10px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;align-self:flex-end}
.ci-send.sm{padding:5px 8px;font-size:10px}
.no-comments{text-align:center;color:#3f3f46;font-size:12px;padding:22px 0}
.loading-pm{display:flex;align-items:center;justify-content:center;padding:40px;color:#52525b;gap:10px}
.mention-dd{position:absolute;background:#111;border:1px solid #27272a;border-radius:9px;z-index:600;display:none;box-shadow:0 4px 20px rgba(0,0,0,.6);overflow:hidden}
.mention-item{padding:8px 12px;font-size:12px;cursor:pointer;display:flex;align-items:center;gap:8px;transition:background .1s}
.mention-item:hover{background:#18181b}
.comment-input-wrap{padding:11px 14px;border-top:1px solid #1a1a1a;background:#0d0d0d;flex-shrink:0;position:relative}

/* EDIT MODAL */
.edit-bg{position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:600;display:none;align-items:center;justify-content:center;padding:20px}
.edit-bg.open{display:flex}
.edit-card{background:#111;border:1px solid #27272a;border-radius:16px;width:100%;max-width:560px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden}
.ec-head{padding:14px 18px;border-bottom:1px solid #1a1a1a;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.ec-body{overflow-y:auto;flex:1;padding:16px 18px}
.ec-body::-webkit-scrollbar{width:2px}.ec-body::-webkit-scrollbar-thumb{background:#27272a}
.ec-foot{padding:12px 18px;border-top:1px solid #1a1a1a;display:flex;gap:7px;flex-shrink:0}
.eg{display:flex;flex-direction:column;gap:4px;margin-bottom:10px}
.eg label{font-size:9px;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:.08em}
.eg input,.eg textarea{background:#18181b;border:1px solid #27272a;color:#e4e4e7;border-radius:7px;padding:8px 10px;font-size:12px;outline:none;width:100%}
.eg input:focus,.eg textarea:focus{border-color:#ff6600}
.eg textarea{resize:vertical;min-height:54px}
.eg2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.eg3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px}

.cancel-btn{flex:1;background:#18181b;border:1px solid #27272a;color:#71717a;border-radius:8px;padding:9px;font-size:12px;cursor:pointer}
.save-btn{flex:2;background:#ff6600;border:none;color:#000;border-radius:8px;padding:9px;font-size:12px;font-weight:700;cursor:pointer}

/* LIGHTBOX */
.lightbox{position:fixed;inset:0;background:rgba(0,0,0,.95);z-index:9000;display:none;align-items:center;justify-content:center;cursor:zoom-out}
.lightbox.open{display:flex;animation:lbIn .15s ease}
@keyframes lbIn{from{opacity:0}to{opacity:1}}
.lightbox img{max-width:90vw;max-height:90vh;border-radius:12px;object-fit:contain}

/* TOAST */
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#22c55e;color:#000;font-size:12px;font-weight:700;padding:8px 16px;border-radius:20px;z-index:9999;pointer-events:none;animation:fadeIn .2s ease}
@keyframes fadeIn{from{opacity:0;transform:translateX(-50%) translateY(8px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
.hidden{display:none!important}
/* Antes onmouseover/onfocus en línea (CSP estricta, SEC-24) */
.cover-cam-btn:hover{background:rgba(0,0,0,.45)!important}
.cover-cam-btn:hover #cover-cam-icon{opacity:1!important}
#my-focus:focus{border-color:#ff6600!important}
[data-hov="bg"]:hover{background:#18181b!important}
[data-hov="fg"]:hover{color:#ff6600!important}
[data-hov="bgbd"]:hover{background:#18181b!important;border-color:#27272a!important}
[data-hov="bd"]:hover{border-color:#ff6600!important}
[data-hov="ul"]:hover{text-decoration-color:#60a5fa!important}
</style>
<script src="/toolz/assets/g7-imgprep.js?v=1"></script>
<link rel="stylesheet" href="/toolz/assets/cm-kudos.css?v=<?= (int)@filemtime(__DIR__ . '/assets/cm-kudos.css') ?>">
<link rel="stylesheet" href="/toolz/assets/comunidad-plus.css?v=<?= (int)@filemtime(__DIR__ . '/assets/comunidad-plus.css') ?>">
<link rel="stylesheet" href="/toolz/assets/comunidad-epic.css?v=<?= (int)@filemtime(__DIR__ . '/assets/comunidad-epic.css') ?>">
</head>
<body>
<?php include __DIR__ . '/topbar.php'; ?>

<div class="page">
  <!-- LEFT: MY PROFILE -->
  <div class="left-col">
    <!-- Cover -->
    <div class="profile-cover-wrap" style="position:relative">
      <?php if($me['cover']??''): ?>
      <img src="<?= htmlspecialchars($me['cover']) ?>" id="my-cover-img" style="width:100%;height:80px;object-fit:cover;border-radius:12px">
      <?php else: ?>
      <div class="profile-cover-placeholder" style="height:80px;border-radius:12px"></div>
      <?php endif; ?>
      <button class="cover-cam-btn" data-on-click="cmPick" data-click-1="s:cover-input" style="position:absolute;inset:0;border-radius:12px;background:rgba(0,0,0,0);border:none;cursor:pointer;transition:background .2s">
        <i class="fa-solid fa-camera" style="color:#fff;font-size:16px;opacity:0;transition:opacity .2s" id="cover-cam-icon"></i>
      </button>
    </div>
    <input type="file" id="cover-input" accept="image/*" style="display:none" data-on-change="previewCover" data-change-1="this">
    <input type="file" id="avatar-input" accept="image/*" style="display:none" data-on-change="previewAvatar" data-change-1="this">
    <input type="hidden" id="current-avatar" value="<?= htmlspecialchars($me['avatar']??'') ?>">
    <input type="hidden" id="current-cover"  value="<?= htmlspecialchars($me['cover']??'') ?>">

    <!-- Avatar row -->
    <div class="profile-av-row" style="margin-top:-28px;margin-bottom:10px">
      <div style="border:3px solid #0a0a0a;border-radius:50%;cursor:pointer;position:relative" data-on-click="cmAvatarLb" data-click-1="s:<?= htmlspecialchars($me['avatar']??'') ?>" id="my-av-wrap">
        <div id="my-avatar-display"><?= avHtml($me['avatar']??'',$me['display_name']?:$me['username'],64) ?></div>
      </div>
    </div>

    <!-- MY STATUS -->
    <div style="margin-bottom:10px">
      <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:6px">
        <?php
        $moods=[''=>'','🔥'=>'🔥 En modo creativo','😴'=>'😴 Cargando...','🎯'=>'🎯 Enfocado','🤔'=>'🤔 Pensando','✅'=>'✅ Todo resuelto','🚀'=>'🚀 Full gas'];
        $avails=array_map(fn($s)=>['color'=>$s[1],'label'=>trim($s[2].' '.$s[0])],G7_PRESENCE);
        $myAvail=$avails[$me['availability']??'available']??$avails['available'];
        ?>
        <div style="width:8px;height:8px;border-radius:50%;background:<?= $myAvail['color'] ?>;flex-shrink:0"></div>
        <select id="my-availability" data-on-change="updateMyStatus" style="background:transparent;border:none;color:#a1a1aa;font-size:11px;font-weight:600;cursor:pointer;outline:none;padding:0">
          <?php foreach($avails as $k=>$v): ?>
          <option value="<?= $k ?>" <?= ($me['availability']??'')===$k?'selected':'' ?> style="background:#111;color:#e4e4e7"><?= $v['label'] ?></option>
          <?php endforeach; ?>
        </select>
        <select id="my-mood" data-on-change="updateMyStatus" style="background:transparent;border:none;font-size:14px;cursor:pointer;outline:none;padding:0" title="Mood">
          <?php foreach($moods as $k=>$v): ?>
          <option value="<?= $k ?>" <?= ($me['mood']??'')===$k?'selected':'' ?>><?= $k?$k:'😶' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;gap:6px;align-items:center">
        <input type="text" id="my-focus" value="<?= htmlspecialchars($me['focus_text']??'') ?>" placeholder="¿En qué estás trabajando?" maxlength="500" style="flex:1;background:#18181b;border:1px solid #27272a;color:#e4e4e7;border-radius:7px;padding:5px 8px;font-size:11px;outline:none" data-on-keydown="updateMyStatus" data-keydown-key="Enter">
        <button data-on-click="updateMyStatus" style="background:#ff6600;border:none;color:#000;border-radius:6px;padding:5px 8px;font-size:10px;font-weight:700;cursor:pointer">✓</button>
      </div>
    </div>
    <div class="profile-actions">
      <button class="btn-edit" data-on-click="openEditModal"><i class="fa-solid fa-pen-to-square" style="font-size:10px"></i> Editar</button>
      <button class="btn-preview" data-on-click="previewMyProfile" title="Ver mi perfil como los demás"><i class="fa-solid fa-eye" style="font-size:10px"></i></button>
      <button class="btn-preview" data-on-click="cmPick" data-click-1="s:avatar-input" title="Cambiar foto"><i class="fa-solid fa-camera" style="font-size:10px"></i></button>
    </div>

    <div class="my-name"><?= htmlspecialchars($me['display_name']?:$me['username']) ?> <span class="role-badge <?= $isAdmin?'rb-admin':'rb-user' ?>"><?= $me['role'] ?></span></div>
    <div class="my-handle">@<?= htmlspecialchars($me['username']) ?></div>
    <?php if($me['position']??''): ?><div class="my-pos"><i class="fa-solid fa-briefcase" style="font-size:9px;color:#52525b"></i><?= htmlspecialchars($me['position']) ?></div><?php endif; ?>
    <?php if($me['bio']??''): ?><div class="my-bio"><?= nl2br(htmlspecialchars($me['bio'])) ?></div><?php endif; ?>

    <?php if(($me['instagram']??'')||($me['behance']??'')||($me['linkedin']??'')||($me['portfolio']??'')): ?>
    <div class="social-wrap">
      <?php if($me['instagram']??''): ?><a href="https://instagram.com/<?= htmlspecialchars(ltrim($me['instagram'],'@')) ?>" target="_blank" class="sl sl-ig"><i class="fa-brands fa-instagram"></i><?= htmlspecialchars($me['instagram']) ?></a><?php endif; ?>
      <?php if($me['behance']??''): ?><a href="https://behance.net/<?= htmlspecialchars($me['behance']) ?>" target="_blank" class="sl sl-be"><i class="fa-brands fa-behance"></i><?= htmlspecialchars($me['behance']) ?></a><?php endif; ?>
      <?php if($me['linkedin']??''): ?><a href="https://linkedin.com/<?= htmlspecialchars($me['linkedin']) ?>" target="_blank" class="sl sl-li"><i class="fa-brands fa-linkedin"></i>LinkedIn</a><?php endif; ?>
      <?php if($me['portfolio']??''): ?><a href="<?= htmlspecialchars($me['portfolio']) ?>" target="_blank" class="sl sl-web"><i class="fa-solid fa-globe"></i>Portfolio</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php $mySelectedSkills=array_keys(array_filter($mySkillsJson,fn($v)=>$v>0)); if(!empty($mySelectedSkills)): ?>
    <div style="margin-bottom:10px;display:flex;flex-wrap:wrap;gap:5px">
      <?php foreach($mySelectedSkills as $sk): ?>
      <span style="display:inline-flex;padding:3px 9px;border-radius:20px;font-size:10px;font-weight:600;background:rgba(255,102,0,.1);color:#ff6600;border:1px solid rgba(255,102,0,.25)"><?= htmlspecialchars($sk) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="margin-bottom:10px">
      <?php if($me['email']??''): ?><div class="pf-row"><i class="fa-solid fa-envelope"></i><?= htmlspecialchars($me['email']) ?></div><?php endif; ?>
      <?php if($me['phone']??''): ?><div class="pf-row"><i class="fa-solid fa-phone"></i><?= htmlspecialchars($me['phone']) ?></div><?php endif; ?>
      <?php if($me['location']??''): ?><div class="pf-row"><i class="fa-solid fa-location-dot"></i><?= htmlspecialchars($me['location']) ?></div><?php endif; ?>
      <?php $myLoc = g7_tz_public(g7_tz_row((int)$currentUser['id'])); if ($myLoc['tz']): ?><div class="pf-row pf-loc" data-g7-self data-g7-loc="<?= htmlspecialchars(json_encode($myLoc), ENT_QUOTES) ?>" hidden></div><?php else: ?><div class="pf-row"><i class="fa-solid fa-earth-americas"></i><a href="/toolz/user_config.php#ubicacion">Elige tu zona horaria</a></div><?php endif; ?>
    </div>

    <div class="my-stats">
      <div class="my-stat"><div class="v"><?= $myClicksStat->fetchColumn() ?></div><div class="l">Clicks</div></div>
      <div class="my-stat"><div class="v"><?= $myContentStat->fetchColumn() ?></div><div class="l">Contenido</div></div>
      <div class="my-stat"><div class="v"><?= $myFavsStat->fetchColumn() ?></div><div class="l">Favoritos</div></div>
    </div>

    <!-- NAV -->
    <div class="nav-section">Feed</div>
    <button class="nav-item active" id="nav-feed" data-on-click="switchFeed" data-click-1="s:feed" data-click-2="this"><i class="fa-solid fa-rss"></i> Publicaciones</button>
    <button class="nav-item" id="nav-activity" data-on-click="switchFeed" data-click-1="s:activity" data-click-2="this"><i class="fa-solid fa-bolt"></i> Actividad</button>
    <button class="nav-item" id="nav-working" data-on-click="switchFeed" data-click-1="s:working" data-click-2="this"><i class="fa-solid fa-crosshairs"></i> Trabajando en</button>

  </div>

  <!-- CENTER: FEED -->
  <div class="feed-col">
    <!-- COMPOSER -->
    <div class="composer">
      <div class="comp-top">
        <?= avHtml($me['avatar']??'',$me['display_name']?:$me['username'],36) ?>
        <div style="position:relative;flex:1;min-width:0">
          <div id="post-mention-dd" style="position:absolute;bottom:calc(100% + 6px);left:0;right:0;background:#111;border:1px solid #27272a;border-radius:10px;z-index:600;display:none;box-shadow:0 8px 24px rgba(0,0,0,.6);overflow:hidden;max-height:200px;overflow-y:auto"></div>
          <textarea class="comp-input" id="post-body" placeholder="¿Qué quieres compartir? usa @ para mencionar usuarios" maxlength="140" data-on-input="updateCharCount|handlePostMention|detectLink" data-input-1="this" data-input2-1="this" data-input3-1="this" style="width:100%"></textarea>
        </div>
      </div>
      <div id="img-preview-wrap" class="img-preview-wrap">
        <img id="img-preview-thumb" src="" alt="">
        <button class="img-rm" data-on-click="clearPostImg">✕</button>
      </div>
      <div id="og-preview-wrap" style="display:none;margin-top:10px"></div>
      <div class="comp-footer">
        <div class="comp-actions">
          <button class="comp-action-btn" data-on-click="cmPick" data-click-1="s:post-img-input" title="Agregar imagen"><i class="fa-solid fa-image"></i></button>
          <button class="comp-action-btn cm-kudos-btn" type="button" id="cm-kudos-open" title="Reconocer a alguien del equipo"><i class="fa-solid fa-award"></i> Reconocer</button>
          <span class="char-count" id="char-count">500</span>
        </div>
        <button class="pub-btn" id="pub-btn" data-on-click="submitPost" disabled>Publicar</button>
      </div>
    </div>
    <input type="file" id="post-img-input" accept="image/*" style="display:none" data-on-change="previewPostImg" data-change-1="this">

    <!-- FEED TABS -->
    <div class="feed-tabs">
      <button class="feed-tab active" data-on-click="switchFeed" data-click-1="s:feed" data-click-2="null" data-click-3="this">📢 Publicaciones</button>
      <button class="feed-tab" data-on-click="switchFeed" data-click-1="s:activity" data-click-2="null" data-click-3="this">⚡ Actividad</button>
      <button class="feed-tab" data-on-click="switchFeed" data-click-1="s:working" data-click-2="null" data-click-3="this">🎯 Trabajando en</button>
    </div>

    <!-- POSTS -->
    <div class="cm-bar" id="cm-bar"></div>
    <div id="feed-posts" aria-live="polite"></div>
    <div class="cm-more-wrap"><button type="button" class="cm-more" id="cm-more" hidden>Cargar más</button></div>

    <!-- ACTIVITY (hidden by default) -->
    <div id="feed-activity" style="display:none">
      <div style="background:#111;border:1px solid #1f1f23;border-radius:14px;overflow:hidden">
        <div style="padding:12px 16px;border-bottom:1px solid #1a1a1a;display:flex;align-items:center;gap:7px">
          <span class="live-dot"></span>
          <span style="font-size:10px;font-weight:700;color:#52525b;text-transform:uppercase;letter-spacing:.1em">Actividad en tiempo real</span>
        </div>
        <div id="activity-list" style="max-height:70vh;overflow-y:auto">
          <?php foreach($activity as $a): $uname=$a['display_name']?:$a['username']; ?>
          <div class="af-item" style="padding:10px 14px">
            <div style="cursor:pointer" data-on-click="openProfile" data-click-1="n:<?= (int)$a['uid'] ?>"><?= avHtml($a['avatar']??'',$uname,28) ?></div>
            <div class="af-icon"><?= g7ActivityIcon($a['type']??'') ?></div>
            <div style="flex:1;min-width:0">
              <div class="af-text"><span class="user-link" data-on-click="openProfile" data-click-1="n:<?= (int)$a['uid'] ?>"><?= htmlspecialchars($uname) ?></span>
  <?= g7ActivityText($a) ?>
              </div>
              <div class="af-time"><?= g7TimeAgo($a['created_at']??'') ?> · <?= g7_dt($a['created_at'] ?? '', 'd/m H:i') ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>



<div id="feed-working" style="display:none;margin-bottom:14px">
      <div style="background:#111;border:1px solid #1f1f23;border-radius:14px;overflow:hidden">
        <div style="padding:14px 16px;border-bottom:1px solid #1a1a1a;display:flex;align-items:center;justify-content:space-between">
          <span style="font-size:13px;font-weight:700">&#127919; En qu&#233; est&#225; trabajando el equipo</span>
          <span style="font-size:10px;color:#52525b" id="working-updated">&#8212;</span>
        </div>
        <div id="working-list">
          <?php
          $aC=array_map(fn($s)=>$s[1],G7_PRESENCE);
          $aL=array_map(fn($s)=>$s[0],G7_PRESENCE);
          foreach($users as $wu):
            $wo=(time()-strtotime($wu['last_seen']??'2000-01-01'))<300;
            $wc=$aC[$wu['availability']??'available']??'#52525b';
            $wl=$aL[$wu['availability']??'available']??'Disponible';
            $wn=$wu['display_name']?:$wu['username'];
            $wbc=$wo?$wc:'#52525b';
            $wbb=$wo?$wc.'18':'#18181b';
            $wfc=($wu['focus_text']??'')?'#a1a1aa':'#3f3f46';
            $wfs=($wu['focus_text']??'')?'normal':'italic';
            $wft=($wu['focus_text']??'')?htmlspecialchars($wu['focus_text']):'Sin foco definido a\u00fan...';
            $wd=$wo?$wc:'#3f3f46';
          ?>
          <div data-on-click="openProfile" data-click-1="n:<?= (int)$wu['id'] ?>" style="display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid #141414;cursor:pointer;transition:background .15s" data-hov="bg">
            <div style="position:relative;flex-shrink:0">
              <?= avHtml($wu['avatar']??'',$wn,40) ?>
              <div class="wi-dot-<?= $wu['id'] ?>" style="position:absolute;bottom:1px;right:1px;width:10px;height:10px;border-radius:50%;background:<?= $wd ?>;border:2px solid #111"></div>
            </div>
            <div style="flex:1;min-width:0">
              <div style="display:flex;align-items:center;gap:7px;margin-bottom:3px;flex-wrap:wrap">
                <span style="font-size:13px;font-weight:700;color:#e4e4e7"><?= htmlspecialchars($wn) ?></span>
                <?php if($wu['mood']??''): ?><span style="font-size:14px"><?= $wu['mood'] ?></span><?php endif; ?>
                <span class="wi-avail-<?= $wu['id'] ?>" style="font-size:10px;font-weight:600;color:<?= $wbc ?>;background:<?= $wbb ?>;border-radius:20px;padding:2px 8px"><?= $wl ?></span>
              </div>
              <?php if($wu['position']??''): ?><div style="font-size:11px;color:#52525b;margin-bottom:4px"><?= htmlspecialchars($wu['position']) ?></div><?php endif; ?>
              <div class="wi-focus-<?= $wu['id'] ?>" style="font-size:12px;color:<?= $wfc ?>;font-style:<?= $wfs ?>"><?= $wft ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- RIGHT: TEAM WIDGET -->
  <div class="right-col">

    <!-- NOTIFICATIONS -->
    <div style="background:#111;border:1px solid #1f1f23;border-radius:12px;padding:14px;margin-bottom:14px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
        <div style="font-size:10px;font-weight:700;color:#52525b;text-transform:uppercase;letter-spacing:.1em;display:flex;align-items:center;gap:6px">
          <i class="fa-solid fa-bell" style="color:#ff6600"></i>Notificaciones
          <span id="notif-badge" style="display:none;background:#ff6600;color:#000;font-size:9px;font-weight:800;border-radius:20px;padding:1px 6px"></span>
        </div>
        <button data-on-click="markNotifsRead" style="background:none;border:none;color:#3f3f46;font-size:9px;cursor:pointer;font-weight:600;transition:color .15s" data-hov="fg">Marcar leídas</button>
      </div>
      <div id="notif-list" style="max-height:200px;overflow-y:auto">
        <div style="text-align:center;color:#3f3f46;font-size:11px;padding:16px 0"><i class="fa-regular fa-bell" style="display:block;font-size:18px;margin-bottom:6px;opacity:.3"></i>Sin notificaciones</div>
      </div>
    </div>

    <div style="font-size:9px;font-weight:700;color:#3f3f46;text-transform:uppercase;letter-spacing:.1em;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between">
      <span><i class="fa-solid fa-people-group" style="color:#ff6600;margin-right:5px"></i>El equipo <span id="online-count" style="background:rgba(34,197,94,.12);color:#22c55e;border-radius:20px;padding:1px 6px;font-size:8px;font-weight:700;margin-left:4px"></span></span>
      <button id="team-toggle-btn" data-on-click="toggleFullTeam" style="background:none;border:none;color:#52525b;font-size:10px;cursor:pointer;font-weight:600;transition:color .15s" data-hov="fg">Ver todos</button>
    </div>
    <!-- Compact list -->
    <label class="cm-team-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="cm-team-q" placeholder="Buscar en el equipo…" autocomplete="off"></label>
    <div id="team-compact">
      <?php foreach($users as $u): if($u['id']==$currentUser['id'])continue; $st=$userStats[$u['id']]??['clicks'=>0,'content'=>0]; ?>
      <?php
      $uAvailColors=array_map(fn($s)=>$s[1],G7_PRESENCE);
      $uAvailColor=$uAvailColors[$u['availability']??'available']??'#52525b';
      $isOnline=(time()-strtotime($u['last_seen']??'2000-01-01'))<300;
      $dotColor=$isOnline?$uAvailColor:'#3f3f46';
      ?>
      <div data-on-click="openProfile" data-click-1="n:<?= (int)$u['id'] ?>" style="padding:10px;border-radius:10px;cursor:pointer;transition:background .15s;margin-bottom:4px;border:1px solid transparent" data-hov="bgbd">
        <?php $mb=!empty($u['focus_text'])?'6':'0'; ?>
        <div style="display:flex;align-items:center;gap:9px;margin-bottom:<?= $mb ?>px">
          <div style="position:relative;flex-shrink:0">
            <?= avHtml($u['avatar']??'',$u['display_name']?:$u['username'],34) ?>
            <div id="status-dot-<?= $u['id'] ?>" style="position:absolute;bottom:0;right:0;width:9px;height:9px;border-radius:50%;background:<?= $dotColor ?>;border:2px solid #0a0a0a"></div>
          </div>
          <div style="min-width:0;flex:1">
            <div style="display:flex;align-items:center;gap:5px">
              <div style="font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#e4e4e7"><?= htmlspecialchars($u['display_name']?:$u['username']) ?></div>
              <span id="mood-<?= $u['id'] ?>" style="font-size:13px"><?= $u['mood']??'' ?></span>
            </div>
            <?php if($u['position']??''): ?><div style="font-size:10px;color:#52525b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($u['position']) ?></div><?php endif; ?>
          </div>
        </div>
        <?php $focusStyle=empty($u['focus_text'])?'display:none':''; ?>
        <div id="focus-<?= $u['id'] ?>" style="font-size:10px;color:#71717a;background:#18181b;border-radius:5px;padding:4px 7px;line-height:1.4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;<?= $focusStyle ?>">🎯 <?= htmlspecialchars($u['focus_text']??'') ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <!-- Full cards (hidden) -->
    <div id="team-full" style="display:none;margin-top:4px">
      <?php foreach($users as $u): if($u['id']==$currentUser['id'])continue; $st=$userStats[$u['id']]??['clicks'=>0,'content'=>0]; $uSk=json_decode($u['skills_json']??'{}',true)??[];$topSk=array_filter($uSk,fn($v)=>$v>0);arsort($topSk);$topSk=array_slice($topSk,0,2,true); ?>
      <div style="background:#111;border:1px solid #1f1f23;border-radius:10px;overflow:hidden;cursor:pointer;transition:border-color .15s;margin-bottom:8px" data-on-click="openProfile" data-click-1="n:<?= (int)$u['id'] ?>" data-hov="bd">
        <div style="height:40px;background:linear-gradient(135deg,rgba(255,102,0,.2),rgba(139,92,246,.15));overflow:hidden"><?php if($u['cover']??''): ?><img src="<?= htmlspecialchars($u['cover']) ?>" style="width:100%;height:100%;object-fit:cover"><?php endif; ?></div>
        <div style="padding:4px 10px 10px;margin-top:-16px">
          <div style="display:flex;align-items:flex-end;gap:7px;margin-bottom:6px">
            <?= avHtml($u['avatar']??'',$u['display_name']?:$u['username'],32) ?>
            <div style="min-width:0">
              <div style="font-size:11px;font-weight:700;color:#e4e4e7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($u['display_name']?:$u['username']) ?></div>
              <?php if($u['position']??''): ?><div style="font-size:9px;color:#71717a"><?= htmlspecialchars($u['position']) ?></div><?php endif; ?>
            </div>
          </div>
          <?php if(!empty($topSk)): ?><div style="margin-bottom:6px"><?php foreach($topSk as $sk=>$pct): ?><span class="skill-chip" style="font-size:8px;padding:1px 5px"><?= htmlspecialchars($sk) ?></span><?php endforeach; ?></div><?php endif; ?>
          <div style="display:flex;gap:8px;border-top:1px solid #141414;padding-top:6px">
            <div style="text-align:center;flex:1"><div style="font-size:11px;font-weight:700;color:#ff6600"><?= $st['clicks'] ?></div><div style="font-size:8px;color:#3f3f46">Clicks</div></div>
            <div style="text-align:center;flex:1"><div style="font-size:11px;font-weight:700;color:#ff6600"><?= $st['content'] ?></div><div style="font-size:8px;color:#3f3f46">Contenido</div></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<!-- EDIT MODAL -->
<div class="edit-bg" id="edit-modal" data-on-click="cmEditBg" data-click-1="this" data-click-2="event">
  <div class="edit-card">
    <div class="ec-head">
      <div style="font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px"><i class="fa-solid fa-user-pen" style="color:#ff6600"></i> Editar perfil</div>
      <button data-on-click="closeEditModal" style="background:none;border:none;color:#71717a;font-size:17px;cursor:pointer">✕</button>
    </div>
    <div class="ec-body">
      <div class="eg2">
        <div class="eg"><label>Nombre</label><input id="pf-display" value="<?= htmlspecialchars($me['display_name']??'') ?>"></div>
        <div class="eg"><label>Cargo</label><input id="pf-position" value="<?= htmlspecialchars($me['position']??'') ?>" placeholder="Diseñador"></div>
        <div class="eg"><label>Email</label><input type="email" id="pf-email" value="<?= htmlspecialchars($me['email']??'') ?>"></div>
        <div class="eg"><label>Teléfono</label><input id="pf-phone" value="<?= htmlspecialchars($me['phone']??'') ?>"></div>
      </div>
      <div class="eg2"><div class="eg"><label>🎂 Cumpleaños <small style="font-weight:400;opacity:.7">(solo se muestra día y mes)</small></label><input type="date" id="pf-birthday" value="<?= htmlspecialchars((string)($me['birthday'] ?? '')) ?>"></div><div class="eg"><label>🥂 En el equipo desde</label><input type="date" id="pf-joined" value="<?= htmlspecialchars((string)($me['joined_at'] ?? '')) ?>"></div></div>
      <div class="eg"><label>Ubicación</label><input id="pf-location" value="<?= htmlspecialchars($me['location']??'') ?>" placeholder="Ciudad, País"></div>
      <div class="eg"><label>Bio</label><textarea id="pf-bio"><?= htmlspecialchars($me['bio']??'') ?></textarea></div>
      <div style="font-size:9px;font-weight:700;color:#52525b;text-transform:uppercase;letter-spacing:.08em;margin:12px 0 10px;padding-top:10px;border-top:1px solid #1f1f23">Habilidades</div>
      <div style="font-size:11px;color:#52525b;margin-bottom:10px">Selecciona las herramientas y tecnologías que usas</div>
      <div id="skills-tag-selector" style="display:flex;flex-wrap:wrap;gap:6px;max-height:260px;overflow-y:auto;padding-right:4px">
        <?php foreach($SKILLS_LIST as $skill): $selected=!empty($mySkillsJson[$skill]); ?>
        <button type="button" class="skill-tag-btn <?= $selected?'selected':'' ?>" data-skill="<?= htmlspecialchars($skill) ?>" data-on-click="toggleSkillTag" data-click-1="this" style="padding:5px 10px;border-radius:20px;font-size:11px;font-weight:600;cursor:pointer;transition:all .15s;border:1px solid <?= $selected?'#ff6600':'#27272a' ?>;background:<?= $selected?'rgba(255,102,0,.15)':'transparent' ?>;color:<?= $selected?'#ff6600':'#71717a' ?>"><?= htmlspecialchars($skill) ?></button>
        <?php endforeach; ?>
      </div>
      <div style="font-size:9px;font-weight:700;color:#52525b;text-transform:uppercase;letter-spacing:.08em;margin:12px 0 10px;padding-top:10px;border-top:1px solid #1f1f23">Redes sociales</div>
      <div class="eg3">
        <div class="eg"><label><i class="fa-brands fa-instagram" style="color:#e1306c;margin-right:3px"></i>Instagram</label><input id="pf-instagram" value="<?= htmlspecialchars($me['instagram']??'') ?>" placeholder="@usuario"></div>
        <div class="eg"><label><i class="fa-brands fa-behance" style="color:#1769ff;margin-right:3px"></i>Behance</label><input id="pf-behance" value="<?= htmlspecialchars($me['behance']??'') ?>" placeholder="usuario"></div>
        <div class="eg"><label><i class="fa-brands fa-linkedin" style="color:#0a66c2;margin-right:3px"></i>LinkedIn</label><input id="pf-linkedin" value="<?= htmlspecialchars($me['linkedin']??'') ?>" placeholder="in/usuario"></div>
      </div>
      <div class="eg"><label><i class="fa-solid fa-globe" style="color:#60a5fa;margin-right:3px"></i>Portfolio</label><input id="pf-portfolio" value="<?= htmlspecialchars($me['portfolio']??'') ?>" placeholder="https://..."></div>
    </div>
    <div class="ec-foot">
      <button class="cancel-btn" data-on-click="closeEditModal">Cancelar</button>
      <button class="save-btn" data-on-click="saveProfile"><i class="fa-solid fa-floppy-disk" style="margin-right:5px"></i>Guardar</button>
      <span id="save-ok" style="font-size:11px;color:#4ade80;display:none;align-items:center;gap:4px"><i class="fa-solid fa-check"></i> Guardado</span>
    </div>
  </div>
</div>

<!-- PROFILE MODAL -->
<div class="modal-bg hidden" id="profile-modal" data-on-click="cmProfileBg" data-click-1="this" data-click-2="event">
  <div class="pm">
    <div class="pm-cover" id="pm-cover">
      <div class="pm-cover-grad"></div>
      <div class="pm-av" id="pm-av"></div>
      <button class="pm-close" data-on-click="closeProfile"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="pm-body">
      <div class="pm-left" id="pm-left"><div class="loading-pm"><i class="fa-solid fa-spinner fa-spin"></i></div></div>
      <div class="pm-right" id="pm-right">
        <div class="comments-col">
          <div class="comments-head"><i class="fa-solid fa-comments" style="color:#ff6600;margin-right:5px"></i> Comentarios</div>
          <div class="comments-list" id="comments-list"><div class="loading-pm"><i class="fa-solid fa-spinner fa-spin"></i></div></div>
          <div class="comment-input-wrap">
            <div id="mention-dropdown" class="mention-dd" style="bottom:calc(100% + 4px);left:14px;right:14px"></div>
            <textarea class="ci-area" id="new-comment" placeholder="Comenta... usa @usuario para mencionar" rows="2" data-on-input="handleMentionInput" data-input-1="this" data-input-2="s:new-comment" data-input-3="s:mention-dropdown"></textarea>
            <div style="display:flex;justify-content:flex-end;margin-top:6px"><button class="ci-send" data-on-click="submitComment"><i class="fa-solid fa-paper-plane" style="margin-right:5px"></i>Comentar</button></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- LIGHTBOX -->
<div class="lightbox" id="lightbox" data-on-click="closeLightbox"><img id="lightbox-img" src="" alt=""></div>

<?php
  $__cmSkills = [];
  foreach ($SKILLS_LIST as $sk) $__cmSkills[$sk] = !empty($mySkillsJson[$sk]) ? 1 : 0;
  // Comunidad 3: "Hoy en el equipo" (pregunta del día, cumpleaños, café de la semana)
  $__today = cm_today($db, (int)$currentUser['id']);
  // Pulso de la Comunidad (encabezado, tendencias y ranking de la semana)
  $__pulse = ['online' => 0, 'posts_today' => 0, 'kudos_week' => 0, 'trending' => [], 'leaders' => []];
  try {
    $__pulse['online'] = (int)$db->query('SELECT COUNT(*) FROM g7_users WHERE active = 1 AND last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE)')->fetchColumn();
    $__pulse['posts_today'] = (int)$db->query("SELECT COUNT(*) FROM g7_posts WHERE DATE(created_at) = CURDATE() AND kind <> 'qotd'")->fetchColumn();
    $__pulse['kudos_week'] = (int)$db->query("SELECT COUNT(*) FROM g7_posts WHERE kind = 'kudos' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    $__pulse['trending'] = $db->query("SELECT p.id, p.body, p.kind, p.img, u.id uid, COALESCE(NULLIF(u.display_name,''),u.username) name, u.avatar,
        (SELECT COUNT(*) FROM g7_post_reactions r WHERE r.post_id = p.id) + 2 * (SELECT COUNT(*) FROM g7_post_replies r WHERE r.post_id = p.id) score
        FROM g7_posts p JOIN g7_users u ON u.id = p.user_id WHERE p.created_at > DATE_SUB(NOW(), INTERVAL 14 DAY) AND p.kind <> 'qotd' HAVING score > 0 ORDER BY score DESC, p.id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    $__pulse['leaders'] = $db->query("SELECT u.id, COALESCE(NULLIF(u.display_name,''),u.username) name, u.avatar, COUNT(*) n FROM g7_posts p JOIN g7_users u ON u.id = p.target_user_id
        WHERE p.kind = 'kudos' AND p.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY u.id ORDER BY n DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {}
  $__cmCfg = [
    'pulse' => $__pulse,
    'today' => $__today,
    'users' => array_values($users), 'me' => (int)$currentUser['id'], 'isAdmin' => (bool)$isAdmin, 'skillsList' => $SKILLS_LIST, 'skills' => (object)$__cmSkills,
    // la más nueva (no la primera: la fijada puede ser vieja y el sondeo volvía a traer las nuevas, duplicadas)
    'lastPostTime' => $feedPosts ? date('Y-m-d H:i:s', max(array_map(fn($p) => strtotime($p['created_at']), $feedPosts))) : '2000-01-01',
    'lastActivity' => $activity ? date('Y-m-d H:i:s', strtotime($activity[0]['created_at'])) : '2000-01-01',
    'feed' => $cmFirst, 'maxPost' => CM_MAX_POST, 'kudos' => CM_KUDOS, 'meUser' => $currentUser['username'],
  ];
?>
<script type="application/json" id="cm-cfg"><?= json_encode($__cmCfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="/toolz/assets/comunidad-page.js?v=<?= (int)@filemtime(__DIR__ . '/assets/comunidad-page.js') ?>"></script>
<script src="/toolz/assets/cm-kudos.js?v=<?= (int)@filemtime(__DIR__ . '/assets/cm-kudos.js') ?>"></script>
<script src="/toolz/assets/comunidad-plus.js?v=<?= (int)@filemtime(__DIR__ . '/assets/comunidad-plus.js') ?>"></script>
<script src="/toolz/assets/comunidad-epic.js?v=<?= (int)@filemtime(__DIR__ . '/assets/comunidad-epic.js') ?>"></script>
<!-- Editar perfil: formulario compartido con Mi perfil (profile-edit.js, profile_fields_lib.php) -->
<script type="application/json" id="pe-data"><?= json_encode(pf_edit_data($db, (int)$currentUser['id']) + ['save_url' => '/toolz/comunidad.php'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<link rel="stylesheet" href="/toolz/assets/profile-edit.css?v=<?= (int)@filemtime(__DIR__ . '/assets/profile-edit.css') ?>">
<script src="/toolz/assets/profile-picker.js?v=<?= (int)@filemtime(__DIR__ . '/assets/profile-picker.js') ?>"></script>
<script src="/toolz/assets/profile-edit.js?v=<?= (int)@filemtime(__DIR__ . '/assets/profile-edit.js') ?>"></script>
<!-- Quién sabe qué: personas por herramientas y aptitudes (cm-skills.js) -->
<link rel="stylesheet" href="/toolz/assets/cm-skills.css?v=<?= (int)@filemtime(__DIR__ . '/assets/cm-skills.css') ?>">
<script src="/toolz/assets/cm-skills.js?v=<?= (int)@filemtime(__DIR__ . '/assets/cm-skills.js') ?>"></script>
</body>
</html>