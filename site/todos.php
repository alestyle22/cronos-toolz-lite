<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/auth.php';
$currentUser = requireLogin();
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo (SEC-24)
$db = getDB();
$me = $currentUser['id'];
$isAdmin = $currentUser['role'] === 'admin';

// ── AUTO MIGRATION ─────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS g7_todos (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        user_id      INT NOT NULL,
        title        VARCHAR(255) NOT NULL,
        description  TEXT,
        project      VARCHAR(100) DEFAULT '',
        sort_order   INT NOT NULL DEFAULT 0,
        status       ENUM('pending','done') NOT NULL DEFAULT 'pending',
        completed_at DATETIME DEFAULT NULL,
        created_at   DATETIME DEFAULT NOW(),
        INDEX idx_user   (user_id),
        INDEX idx_status (status),
        INDEX idx_sort   (user_id, sort_order)
    )");
} catch(Exception $e) {}

// ── AJAX ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $act   = $input['action'] ?? '';

    if ($act === 'save_todo') {
        $id    = (int)($input['id'] ?? 0);
        $title = trim($input['title'] ?? '');
        $desc  = trim($input['description'] ?? '');
        $proj  = trim($input['project'] ?? '');
        if (!$title) { echo json_encode(['ok'=>false,'error'=>'Título requerido']); exit; }
        if ($id) {
            $q = $db->prepare("SELECT id FROM g7_todos WHERE id=? AND user_id=?");
            $q->execute([$id, $me]); if (!$q->fetch()) { echo json_encode(['ok'=>false]); exit; }
            $db->prepare("UPDATE g7_todos SET title=?,description=?,project=? WHERE id=? AND user_id=?")
               ->execute([$title,$desc,$proj,$id,$me]);
        } else {
            // Get max sort_order for this user
            $maxQ = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM g7_todos WHERE user_id=? AND status='pending'");
            $maxQ->execute([$me]); $sortOrder = (int)$maxQ->fetchColumn();
            $db->prepare("INSERT INTO g7_todos (user_id,title,description,project,sort_order) VALUES (?,?,?,?,?)")
               ->execute([$me,$title,$desc,$proj,$sortOrder]);
            $id = (int)$db->lastInsertId();
        }
        $q = $db->prepare("SELECT * FROM g7_todos WHERE id=?"); $q->execute([$id]);
        echo json_encode(['ok'=>true,'todo'=>$q->fetch()]);
        exit;
    }

    if ($act === 'complete_todo') {
        $id = (int)($input['id'] ?? 0);
        $db->prepare("UPDATE g7_todos SET status='done', completed_at=NOW() WHERE id=? AND user_id=?")
           ->execute([$id, $me]);
        $q = $db->prepare("SELECT * FROM g7_todos WHERE id=?"); $q->execute([$id]);
        echo json_encode(['ok'=>true,'todo'=>$q->fetch()]);
        exit;
    }

    if ($act === 'reopen_todo') {
        $id = (int)($input['id'] ?? 0);
        $db->prepare("UPDATE g7_todos SET status='pending', completed_at=NULL WHERE id=? AND user_id=?")
           ->execute([$id, $me]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($act === 'delete_todo') {
        $id = (int)($input['id'] ?? 0);
        $db->prepare("DELETE FROM g7_todos WHERE id=? AND user_id=?")->execute([$id,$me]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($act === 'reorder') {
        $ids = $input['ids'] ?? [];
        foreach ($ids as $i => $tid) {
            $db->prepare("UPDATE g7_todos SET sort_order=? WHERE id=? AND user_id=?")
               ->execute([$i+1, (int)$tid, $me]);
        }
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($act === 'save_to_promos') {
        $title      = trim($input['title'] ?? '');
        $type       = in_array($input['type']??'',['promo','launch','paid','social','otro','tarea','mailings']) ? $input['type'] : 'tarea';
        $qty        = max(1,(int)($input['qty'] ?? 1));
        $project    = trim($input['project'] ?? '');
        $graficas   = max(0,(int)($input['graficas'] ?? 0));
        $videos     = max(0,(int)($input['videos'] ?? 0));
        $mailingLink= trim($input['mailing_link'] ?? '');
        if (!$title) { echo json_encode(['ok'=>false,'error'=>'Título requerido']); exit; }
        try {
            $db->prepare("INSERT INTO g7_content (name,type,project,qty_graficas,qty_videos,qty_tareas,mailing,author,created_by,start_date,estado)
                          VALUES (?,?,?,?,?,?,?,?,?,NOW(),'activo')")
               ->execute([
                   $title, $type, $project,
                   $graficas, $videos,
                   ($type === 'tarea' || $type === 'mailings') ? $qty : 0,
                   $mailingLink ?: null,
                   $currentUser['username'], $me
               ]);
            $cid = (int)$db->lastInsertId();
            $todoId = (int)($input['todo_id'] ?? 0);
            if ($todoId) {
                $db->prepare("UPDATE g7_todos SET status='done',completed_at=NOW() WHERE id=? AND user_id=?")
                   ->execute([$todoId,$me]);
            }
            echo json_encode(['ok'=>true,'content_id'=>$cid]);
        } catch(Exception $e) {
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Acción desconocida']); exit;
}

// ── LOAD DATA ──────────────────────────────────────────────
$pending = $db->prepare("SELECT * FROM g7_todos WHERE user_id=? AND status='pending' ORDER BY sort_order ASC, created_at ASC");
$pending->execute([$me]); $pendingTodos = $pending->fetchAll();

$done = $db->prepare("SELECT * FROM g7_todos WHERE user_id=? AND status='done' ORDER BY completed_at DESC LIMIT 20");
$done->execute([$me]); $doneTodos = $done->fetchAll();

// Projects from g7_content for autocomplete
try {
    $projects = $db->prepare("SELECT DISTINCT project FROM g7_content WHERE created_by=? AND project!='' ORDER BY project");
    $projects->execute([$me]); $projectList = $projects->fetchAll(PDO::FETCH_COLUMN);
} catch(Exception $e) { $projectList = []; }

$pendingJSON = json_encode($pendingTodos, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$doneJSON    = json_encode($doneTodos,    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>To-Do · <?= htmlspecialchars(g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha384-3B6NwesSXE7YJlcLI9RpRqGf2p/EgVH8BgoKTaUrmKNDkHPStTQ3EyoYjCGXaOTS" crossorigin="anonymous">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#080808;--bg2:#0e0e0e;--bg3:#141414;--bg4:#1a1a1a;--bg5:#222;
  --border:#1f1f1f;--border2:#2a2a2a;--text:#e8e8e8;--text2:#a0a0a0;--text3:#555;
  --accent:#ff6600;--green:#22c55e;--blue:#60a5fa;
}
body{background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;min-height:100vh}
#cw{display:none!important}

/* ── LAYOUT ── */
.td-wrap{max-width:680px;margin:0 auto;padding:28px 20px}

/* ── HEADER ── */
.td-header{display:flex;align-items:center;gap:12px;margin-bottom:24px}
.td-title{font-size:24px;font-weight:800;letter-spacing:-.03em;flex:1}
.td-title span{color:var(--accent)}
.td-stats{display:flex;gap:6px}
.td-stat{background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:10px;font-weight:700;font-family:'DM Mono',monospace;color:var(--text3)}
.td-stat.pending{color:var(--accent)}
.td-stat.done{color:var(--green)}

/* ── QUICK ADD ── */
.td-add-form{background:var(--bg2);border:1px solid var(--border2);border-radius:12px;padding:14px;margin-bottom:20px}
.td-add-row{display:flex;gap:8px;margin-bottom:8px}
.td-inp{background:var(--bg4);border:1px solid var(--border2);color:var(--text);border-radius:8px;padding:9px 12px;font-size:13px;outline:none;font-family:'DM Sans',sans-serif;transition:border-color .15s;width:100%}
.td-inp:focus{border-color:var(--accent)}
.td-add-btn{background:var(--accent);border:none;color:#000;border-radius:8px;padding:9px 16px;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap;transition:opacity .15s;display:flex;align-items:center;gap:5px;flex-shrink:0}
.td-add-btn:hover{opacity:.85}
.td-add-extras{display:flex;gap:8px}
.td-inp-sm{background:var(--bg4);border:1px solid var(--border2);color:var(--text2);border-radius:7px;padding:6px 10px;font-size:11px;outline:none;font-family:'DM Sans',sans-serif;flex:1;transition:border-color .15s}
.td-inp-sm:focus{border-color:var(--accent)}

/* ── SECTION HEADERS ── */
.td-section{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--text3);margin-bottom:10px;display:flex;align-items:center;gap:6px}
.td-section::after{content:'';flex:1;height:1px;background:var(--border)}

/* ── TODO CARD ── */
.td-list{display:flex;flex-direction:column;gap:6px;margin-bottom:24px;min-height:10px}
.td-card{background:var(--bg2);border:1px solid var(--border);border-radius:10px;display:flex;align-items:flex-start;gap:0;transition:all .15s;position:relative;overflow:hidden;cursor:default}
.td-card:hover{border-color:var(--border2);box-shadow:0 4px 16px rgba(0,0,0,.3)}
.td-card.dragging{opacity:.4;transform:scale(.98)}
.td-card.drag-over{border-color:var(--accent);background:rgba(255,102,0,.03)}

/* Drag handle */
.td-drag{width:32px;display:flex;align-items:center;justify-content:center;color:var(--text3);cursor:grab;font-size:11px;flex-shrink:0;align-self:stretch;transition:color .15s}
.td-drag:hover{color:var(--text2)}
.td-drag:active{cursor:grabbing}

/* Priority indicator — top line color based on position */
.td-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--prio-color,transparent)}

/* Check */
.td-check-wrap{padding:14px 4px 14px 0;flex-shrink:0}
.td-check{width:20px;height:20px;border-radius:50%;border:2px solid var(--border2);background:none;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;flex-shrink:0}
.td-check:hover{border-color:var(--green);background:rgba(34,197,94,.08)}
.td-check.done{background:var(--green);border-color:var(--green)}
.td-check.done i{color:#000;font-size:10px}

/* Body */
.td-body{flex:1;padding:12px 0;min-width:0}
.td-card-title{font-size:13px;font-weight:700;color:var(--text);margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.td-card.done-card .td-card-title{text-decoration:line-through;color:var(--text3)}
.td-card-desc{font-size:11px;color:var(--text3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.td-card-proj{display:inline-flex;align-items:center;gap:4px;font-size:9px;font-weight:700;background:rgba(96,165,250,.1);color:var(--blue);border-radius:4px;padding:1px 6px;margin-top:3px}

/* Actions */
.td-actions{display:flex;align-items:center;gap:2px;padding:8px;flex-shrink:0;opacity:0;transition:opacity .15s}
.td-card:hover .td-actions{opacity:1}
.td-act-btn{background:none;border:none;color:var(--text3);cursor:pointer;width:26px;height:26px;border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:11px;transition:all .15s}
.td-act-btn:hover{background:var(--bg4);color:var(--text)}
.td-act-btn.danger:hover{background:rgba(239,68,68,.1);color:#ef4444}

/* Done section */
.td-done-list{display:flex;flex-direction:column;gap:5px}
.td-done-card{background:var(--bg2);border:1px solid var(--border);border-radius:8px;display:flex;align-items:center;gap:8px;padding:8px 12px;opacity:.55;transition:all .15s}
.td-done-card:hover{opacity:1;border-color:var(--border2)}
.td-done-check{width:18px;height:18px;border-radius:50%;background:var(--green);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.td-done-check i{color:#000;font-size:9px}
.td-done-title{font-size:12px;font-weight:600;color:var(--text3);text-decoration:line-through;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.td-done-time{font-size:9px;color:var(--text3);font-family:'DM Mono',monospace;flex-shrink:0}
.td-done-actions{display:flex;gap:3px;flex-shrink:0}
.td-done-btn{background:none;border:1px solid var(--border);color:var(--text3);border-radius:5px;padding:3px 8px;font-size:9px;font-weight:700;cursor:pointer;transition:all .15s}
.td-done-btn:hover{border-color:var(--accent);color:var(--accent)}
.td-done-btn.promos{border-color:rgba(34,197,94,.2);color:var(--green)}
.td-done-btn.promos:hover{background:rgba(34,197,94,.08)}

/* Edit inline */
.td-edit-overlay{display:none;position:absolute;inset:0;background:var(--bg3);border-radius:10px;padding:12px;z-index:10;flex-direction:column;gap:8px}
.td-edit-overlay.open{display:flex;animation:fadeIn .1s ease}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.td-edit-row{display:flex;gap:6px}
.td-edit-save{background:var(--accent);border:none;color:#000;border-radius:7px;padding:6px 14px;font-size:11px;font-weight:800;cursor:pointer}
.td-edit-cancel{background:var(--bg5);border:1px solid var(--border2);color:var(--text2);border-radius:7px;padding:6px 10px;font-size:11px;cursor:pointer}

/* ── PROMOS MODAL ── */
.td-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:500;align-items:center;justify-content:center;padding:16px}
.td-modal-bg.open{display:flex;animation:fadeIn .15s ease}
.td-modal{background:var(--bg2);border:1px solid var(--border2);border-radius:16px;width:100%;max-width:480px;overflow:hidden;box-shadow:0 0 40px rgba(34,197,94,.08);animation:slideUp .2s cubic-bezier(.34,1.56,.64,1)}
@keyframes slideUp{from{transform:translateY(14px);opacity:0}to{transform:none;opacity:1}}
.td-modal-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.td-modal-body{padding:20px;display:flex;flex-direction:column;gap:14px}
.td-modal-foot{padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px}
.td-modal-lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text3);display:block;margin-bottom:5px}
.td-modal-inp{width:100%;background:var(--bg4);border:1px solid var(--border2);color:var(--text);border-radius:8px;padding:9px 12px;font-size:13px;outline:none;font-family:'DM Sans',sans-serif;transition:border-color .15s}
.td-modal-inp:focus{border-color:var(--green)}
.td-modal-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.td-modal-save{background:var(--green);border:none;color:#000;border-radius:8px;padding:10px 20px;font-size:12px;font-weight:800;cursor:pointer;flex:2;display:flex;align-items:center;justify-content:center;gap:6px;transition:opacity .15s}
.td-modal-save:hover{opacity:.85}
.td-modal-cancel{background:var(--bg4);border:1px solid var(--border2);color:var(--text2);border-radius:8px;padding:10px 16px;font-size:12px;font-weight:600;cursor:pointer;flex:1}
.td-modal-skip{background:none;border:none;color:var(--text3);font-size:11px;cursor:pointer;width:100%;text-align:center;margin-top:-6px;transition:color .15s}
.td-modal-skip:hover{color:var(--text2)}

/* Type buttons */
.pm-type-btn{background:var(--bg4);border:1px solid var(--border2);color:var(--text2);border-radius:8px;padding:10px 6px;font-size:10px;font-weight:700;cursor:pointer;text-align:center;transition:all .15s;font-family:'DM Sans',sans-serif}
.pm-type-btn:hover{border-color:var(--accent);color:var(--text)}
.pm-type-btn.sel{border-color:var(--accent);background:rgba(255,102,0,.08);color:var(--text)}

/* Empty state */
.td-empty{text-align:center;padding:32px 20px;color:var(--text3);font-size:12px;border:1px dashed var(--border);border-radius:10px;margin-bottom:24px}
.td-empty i{display:block;font-size:28px;margin-bottom:8px;opacity:.15}
</style>
</head>
<body>
<?php include __DIR__ . '/topbar.php'; ?>

<div class="td-wrap">
  <!-- HEADER -->
  <div class="td-header">
    <div class="td-title">My <span>Tasks</span></div>
    <div class="td-stats">
      <div class="td-stat pending" id="stat-pending"><?= count($pendingTodos) ?> pendientes</div>
      <div class="td-stat done" id="stat-done"><?= count($doneTodos) ?> completadas</div>
    </div>
  </div>

  <!-- QUICK ADD -->
  <div class="td-add-form">
    <div class="td-add-row">
      <input class="td-inp" id="new-title" placeholder="Nueva tarea..." data-on-keydown="quickAdd" data-keydown-key="Enter">
      <button class="td-add-btn" data-on-click="quickAdd"><i class="fa-solid fa-plus"></i> Agregar</button>
    </div>
    <div class="td-add-extras">
      <input class="td-inp-sm" id="new-desc" placeholder="Descripción (opcional)">
      <input class="td-inp-sm" id="new-proj" placeholder="Proyecto" list="proj-list">
      <datalist id="proj-list">
        <?php foreach($projectList as $p): ?><option value="<?= htmlspecialchars($p) ?>"><?php endforeach; ?>
      </datalist>
    </div>
  </div>

  <!-- PENDING LIST -->
  <div class="td-section"><span>Pendientes</span></div>
  <div class="td-list" id="td-list">
  </div>

  <!-- DONE LIST -->
  <div id="td-done-sec">
  <div class="td-section"><span>Completadas recientemente</span></div>
  <div class="td-done-list" id="td-done-list"></div>
  </div>
</div>

<!-- PROMOS MODAL -->
<div class="td-modal-bg" id="promos-modal" data-on-click="tdModalBg" data-click-1="event">
  <div class="td-modal">
    <div class="td-modal-head">
      <i class="fa-solid fa-layer-group" style="color:var(--green);font-size:14px"></i>
      <div style="flex:1">
        <div style="font-size:14px;font-weight:800">¡Tarea completada!</div>
        <div style="font-size:11px;color:var(--text3);margin-top:1px">¿Quieres registrarla en Promos?</div>
      </div>
      <button data-on-click="skipPromos" style="background:none;border:none;color:var(--text3);cursor:pointer;font-size:17px">✕</button>
    </div>
    <div class="td-modal-body">
      <input type="hidden" id="pm-todo-id">

      <!-- TYPE SELECTOR -->
      <div>
        <label class="td-modal-lbl">Tipo de contenido</label>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px" id="pm-type-grid">
          <button type="button" class="pm-type-btn sel" data-type="tarea" data-on-click="selType" data-click-1="s:tarea">
            <i class="fa-solid fa-bolt" style="color:#60a5fa;font-size:14px;display:block;margin-bottom:4px"></i>
            <span>Tarea rápida</span>
          </button>
          <button type="button" class="pm-type-btn" data-type="promo" data-on-click="selType" data-click-1="s:promo">
            <i class="fa-solid fa-trophy" style="color:#ff6600;font-size:14px;display:block;margin-bottom:4px"></i>
            <span>Promo</span>
          </button>
          <button type="button" class="pm-type-btn" data-type="social" data-on-click="selType" data-click-1="s:social">
            <i class="fa-solid fa-hashtag" style="color:#a78bfa;font-size:14px;display:block;margin-bottom:4px"></i>
            <span>Social</span>
          </button>
          <button type="button" class="pm-type-btn" data-type="mailings" data-on-click="selType" data-click-1="s:mailings">
            <i class="fa-solid fa-envelope" style="color:#34d399;font-size:14px;display:block;margin-bottom:4px"></i>
            <span>Mailings</span>
          </button>
        </div>
        <input type="hidden" id="pm-type" value="tarea">
      </div>

      <!-- NOMBRE -->
      <div>
        <label class="td-modal-lbl">Nombre del contenido</label>
        <input class="td-modal-inp" id="pm-title" placeholder="Nombre para Promos...">
      </div>

      <!-- PROYECTO -->
      <div>
        <label class="td-modal-lbl">Proyecto</label>
        <input class="td-modal-inp" id="pm-proj" placeholder="Proyecto..." list="proj-list">
      </div>

      <!-- FIELDS PER TYPE -->
      <!-- tarea -->
      <div id="pm-fields-tarea">
        <div>
          <label class="td-modal-lbl">Cantidad de tareas</label>
          <input class="td-modal-inp" id="pm-qty-tarea" type="number" min="1" value="1">
        </div>
      </div>

      <!-- promo / social -->
      <div id="pm-fields-promo" style="display:none">
        <div class="td-modal-row">
          <div>
            <label class="td-modal-lbl">Gráficas</label>
            <input class="td-modal-inp" id="pm-graficas" type="number" min="0" value="0">
          </div>
          <div>
            <label class="td-modal-lbl">Videos</label>
            <input class="td-modal-inp" id="pm-videos" type="number" min="0" value="0">
          </div>
        </div>
      </div>

      <!-- mailings -->
      <div id="pm-fields-mailings" style="display:none">
        <div class="td-modal-row">
          <div>
            <label class="td-modal-lbl">Cantidad de mailings</label>
            <input class="td-modal-inp" id="pm-qty-mailings" type="number" min="1" value="1">
          </div>
          <div>
            <label class="td-modal-lbl">Link del mailing</label>
            <input class="td-modal-inp" id="pm-mailing-link" placeholder="URL (opcional)">
          </div>
        </div>
      </div>

      <div style="background:rgba(34,197,94,.05);border:1px solid rgba(34,197,94,.15);border-radius:8px;padding:10px 12px;font-size:11px;color:var(--text3);display:flex;align-items:center;gap:8px">
        <i class="fa-solid fa-info-circle" style="color:var(--green);flex-shrink:0"></i>
        Se va a crear una entrada en <b style="color:var(--text)">Promos CMS</b> con los datos indicados.
      </div>
    </div>
    <div class="td-modal-foot">
      <button class="td-modal-cancel" data-on-click="skipPromos">Solo completar</button>
      <button class="td-modal-save" id="pm-save-btn" data-on-click="saveToPromos"><i class="fa-solid fa-arrow-right-to-bracket"></i> Cargar en Promos</button>
    </div>
  </div>
</div>

<script type="application/json" id="td-pending"><?= $pendingJSON ?: '[]' ?></script>
<script type="application/json" id="td-done"><?= $doneJSON ?: '[]' ?></script>
<script src="/toolz/assets/todos-page.js?v=<?= (int)@filemtime(__DIR__ . '/assets/todos-page.js') ?>"></script>
</body>
</html>