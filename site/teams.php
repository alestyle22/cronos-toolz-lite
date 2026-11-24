<?php
require_once __DIR__ . '/auth.php';
$currentUser = requireLogin();
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo (SEC-24)
$isAdmin     = ($currentUser['role'] === 'admin');
$db          = getDB();
$me          = $currentUser['id'];
require_once __DIR__ . '/teams_lib.php';   // subequipos: el admin de un equipo también administra los de abajo
g7_team_parents($db);

// Solo admins globales o admins de algún equipo
$myTeams = getUserTeams($me);
if (!$isAdmin && empty($myTeams)) {
    http_response_code(403); die('Acceso denegado');
}

// ── LOAD THEME ────────────────────────────────────────
try {
    $_tp = $db->prepare("SELECT pref_key,pref_value FROM g7_user_prefs WHERE user_id=? AND pref_key IN ('theme','font_size')");
    $_tp->execute([$me]);
    $_tp_prefs = [];
    foreach($_tp->fetchAll() as $_r) $_tp_prefs[$_r['pref_key']] = json_decode($_r['pref_value'],true);
    $_tp_theme = $_tp_prefs['theme'] ?? 'dark';
    $_tp_font  = $_tp_prefs['font_size'] ?? 'medium';
} catch(Exception $e) { $_tp_theme='dark'; $_tp_font='medium'; }

// ── API ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $in  = json_decode(file_get_contents('php://input'), true) ?? [];
    $act = $in['action'] ?? '';

    // ── GET TEAMS ─────────────────────────────────────
    if ($act === 'get_teams') {
        $sql = $isAdmin
            ? "SELECT t.*, COUNT(tm.user_id) as member_count, u.display_name as creator_name
               FROM g7_teams t
               LEFT JOIN g7_team_members tm ON tm.team_id=t.id
               LEFT JOIN g7_users u ON u.id=t.created_by
               GROUP BY t.id ORDER BY t.name"
            : "SELECT t.*, COUNT(tm2.user_id) as member_count, u.display_name as creator_name,
               tm.role as my_role
               FROM g7_teams t
               JOIN g7_team_members tm ON tm.team_id=t.id AND tm.user_id=$me
               LEFT JOIN g7_team_members tm2 ON tm2.team_id=t.id
               LEFT JOIN g7_users u ON u.id=t.created_by
               GROUP BY t.id ORDER BY t.name";
        $teams = $db->query($sql)->fetchAll();
        // Subequipos de los equipos que administra (aunque no sea miembro)
        if (!$isAdmin) {
            $led = array_map(fn($t) => (int)$t['id'], array_filter($teams, fn($t) => ($t['my_role'] ?? '') === 'admin'));
            $have = array_map(fn($t) => (int)$t['id'], $teams);
            $more = array_diff(g7_team_descendants($db, $led), $have);
            if ($more) $teams = array_merge($teams, $db->query("SELECT t.*, COUNT(tm.user_id) as member_count, u.display_name as creator_name, 'admin' as my_role
                FROM g7_teams t LEFT JOIN g7_team_members tm ON tm.team_id=t.id LEFT JOIN g7_users u ON u.id=t.created_by
                WHERE t.id IN (" . implode(',', array_map('intval', $more)) . ") GROUP BY t.id")->fetchAll());
        }
        $teams = g7_team_tree_sort($db, $teams);
        echo json_encode(['ok'=>true, 'teams'=>$teams, 'max_depth'=>G7_TEAM_MAX_DEPTH]); exit;
    }

    // ── GET TEAM DETAIL ───────────────────────────────
    if ($act === 'get_team') {
        $teamId = (int)($in['team_id'] ?? 0);
        if (!$isAdmin && !isTeamAdmin($me, $teamId)) {
            echo json_encode(['error'=>'Sin permiso']); exit;
        }
        // Team info
        $team = $db->prepare("SELECT * FROM g7_teams WHERE id=?");
        $team->execute([$teamId]); $team = $team->fetch();
        if (!$team) { echo json_encode(['error'=>'Equipo no encontrado']); exit; }

        // Members
        $members = $db->prepare("
            SELECT u.id, u.username, u.display_name, u.avatar, u.email, u.active,
                   tm.role as team_role, tm.is_primary
            FROM g7_team_members tm
            JOIN g7_users u ON u.id=tm.user_id
            WHERE tm.team_id=? ORDER BY tm.is_primary DESC, u.display_name ASC
        ");
        $members->execute([$teamId]); $members = $members->fetchAll();

        // Permissions
        $perms = $db->prepare("SELECT module, action FROM g7_team_permissions WHERE team_id=?");
        $perms->execute([$teamId]); $perms = $perms->fetchAll();

        // Config
        $cfg = $db->prepare("SELECT * FROM g7_team_config WHERE team_id=?");
        $cfg->execute([$teamId]); $cfg = $cfg->fetch();

        // Subequipos: de qué equipo depende, cuáles tiene y si puede crear otro
        $names = $db->query("SELECT id, name FROM g7_teams")->fetchAll(PDO::FETCH_KEY_PAIR);
        $par = g7_team_parents($db)[$teamId] ?? null;
        $kids = array_map(fn($c) => ['id' => $c, 'name' => $names[$c] ?? ''], g7_team_children($db, $teamId));
        echo json_encode(['ok'=>true, 'team'=>$team, 'members'=>$members, 'permissions'=>$perms, 'config'=>$cfg,
            'parent' => $par ? ['id' => $par, 'path' => g7_team_path($db, $par, $names)] : null, 'children' => $kids,
            'can_edit' => $isAdmin || isTeamAdmin($me, $teamId), 'can_sub' => g7_team_depth($db, $teamId) < G7_TEAM_MAX_DEPTH]);
        exit;
    }

    // ── CREATE TEAM ───────────────────────────────────
    if ($act === 'create_team') {
        // Un subequipo lo crea un admin global o el admin del equipo de arriba; uno de primer nivel, solo un admin global
        $parent = (int)($in['parent_id'] ?? 0);
        if ($parent ? (!$isAdmin && !isTeamAdmin($me, $parent)) : !$isAdmin) { echo json_encode(['error'=>$parent ? 'Solo el admin de ese equipo crea subequipos' : 'Solo admins globales']); exit; }
        if ($why = g7_team_parent_problem($db, 0, $parent ?: null)) { echo json_encode(['error'=>$why]); exit; }
        $name  = trim($in['name'] ?? '');
        $color = $in['color'] ?? '#ff6600';
        $desc  = trim($in['description'] ?? '');
        if (!$name) { echo json_encode(['error'=>'Nombre requerido']); exit; }
        $slug  = preg_replace('/[^a-z0-9]+/', '_', strtolower($name));
        // Ensure unique slug
        $exist = $db->prepare("SELECT id FROM g7_teams WHERE slug=?");
        $exist->execute([$slug]);
        if ($exist->fetchColumn()) $slug .= '_' . time();

        $db->prepare("INSERT INTO g7_teams (name,slug,description,color,created_by) VALUES (?,?,?,?,?)")
           ->execute([$name, $slug, $desc, $color, $me]);
        $teamId = $db->lastInsertId();

        if ($parent) {
            // Subequipo: queda dentro de su equipo y arranca con sus permisos. Quien lo crea ya lo administra desde arriba (no se suma)
            g7_team_set_parent($db, (int)$teamId, $parent);
            g7_team_copy_permissions($db, $parent, (int)$teamId);
            echo json_encode(['ok'=>true, 'team_id'=>$teamId, 'slug'=>$slug]); exit;
        }

        // Add creator as team admin
        $db->prepare("INSERT INTO g7_team_members (team_id,user_id,role,is_primary) VALUES (?,?,?,0)")
           ->execute([$teamId, $me, 'admin']);

        // Default permissions: view only on all modules
        $defaultPerms = [
            ['promos','view'], ['stock','view'], ['links','view'],
            ['chat','read'], ['monday','view'], ['pages','view'], ['dashboard','view']
        ];
        foreach ($defaultPerms as [$mod, $act2]) {
            $db->prepare("INSERT IGNORE INTO g7_team_permissions (team_id,module,action) VALUES (?,?,?)")
               ->execute([$teamId, $mod, $act2]);
        }

        echo json_encode(['ok'=>true, 'team_id'=>$teamId, 'slug'=>$slug]); exit;
    }

    // ── UPDATE TEAM ───────────────────────────────────
    if ($act === 'update_team') {
        $teamId = (int)($in['team_id'] ?? 0);
        if (!$isAdmin && !isTeamAdmin($me, $teamId)) { echo json_encode(['error'=>'Sin permiso']); exit; }
        $db->prepare("UPDATE g7_teams SET name=?,description=?,color=?,avatar_url=? WHERE id=?")
           ->execute([
               trim($in['name'] ?? ''),
               trim($in['description'] ?? ''),
               $in['color'] ?? '#ff6600',
               trim($in['avatar_url'] ?? '') ?: null,
               $teamId
           ]);
        echo json_encode(['ok'=>true]); exit;
    }

    // ── DELETE TEAM ───────────────────────────────────
    if ($act === 'delete_team') {
        if (!$isAdmin) { echo json_encode(['error'=>'Solo admins globales']); exit; }
        $teamId = (int)($in['team_id'] ?? 0);
        if ($teamId === 1) { echo json_encode(['error'=>'No se puede eliminar el equipo principal']); exit; }
        g7_team_before_delete($db, $teamId);   // sus subequipos suben un nivel
        $db->prepare("DELETE FROM g7_team_members WHERE team_id=?")->execute([$teamId]);
        $db->prepare("DELETE FROM g7_team_permissions WHERE team_id=?")->execute([$teamId]);
        $db->prepare("DELETE FROM g7_team_config WHERE team_id=?")->execute([$teamId]);
        $db->prepare("DELETE FROM g7_teams WHERE id=?")->execute([$teamId]);
        echo json_encode(['ok'=>true]); exit;
    }

    // ── ADD MEMBER ────────────────────────────────────
    if ($act === 'add_member') {
        $teamId = (int)($in['team_id'] ?? 0);
        $userId = (int)($in['user_id'] ?? 0);
        $role   = $in['role'] === 'admin' ? 'admin' : 'member';
        if (!$isAdmin && !isTeamAdmin($me, $teamId)) { echo json_encode(['error'=>'Sin permiso']); exit; }

        // Check if user has a primary team, if not make this primary
        $hasPrimary = $db->prepare("SELECT COUNT(*) FROM g7_team_members WHERE user_id=? AND is_primary=1");
        $hasPrimary->execute([$userId]);
        $isPrimary = (int)$hasPrimary->fetchColumn() === 0 ? 1 : 0;

        $db->prepare("INSERT INTO g7_team_members (team_id,user_id,role,is_primary) VALUES (?,?,?,?)
                      ON DUPLICATE KEY UPDATE role=VALUES(role)")
           ->execute([$teamId, $userId, $role, $isPrimary]);
        echo json_encode(['ok'=>true]); exit;
    }

    // ── REMOVE MEMBER ─────────────────────────────────
    if ($act === 'remove_member') {
        $teamId = (int)($in['team_id'] ?? 0);
        $userId = (int)($in['user_id'] ?? 0);
        if (!$isAdmin && !isTeamAdmin($me, $teamId)) { echo json_encode(['error'=>'Sin permiso']); exit; }
        $db->prepare("DELETE FROM g7_team_members WHERE team_id=? AND user_id=?")->execute([$teamId, $userId]);
        // If was primary, assign new primary
        $db->prepare("UPDATE g7_team_members SET is_primary=1 WHERE user_id=? AND is_primary=0 LIMIT 1")->execute([$userId]);
        echo json_encode(['ok'=>true]); exit;
    }

    // ── UPDATE MEMBER ROLE ────────────────────────────
    if ($act === 'update_member_role') {
        $teamId = (int)($in['team_id'] ?? 0);
        $userId = (int)($in['user_id'] ?? 0);
        $role   = $in['role'] === 'admin' ? 'admin' : 'member';
        if (!$isAdmin && !isTeamAdmin($me, $teamId)) { echo json_encode(['error'=>'Sin permiso']); exit; }
        $db->prepare("UPDATE g7_team_members SET role=? WHERE team_id=? AND user_id=?")->execute([$role, $teamId, $userId]);
        echo json_encode(['ok'=>true]); exit;
    }

    // ── SET PRIMARY TEAM ──────────────────────────────
    if ($act === 'set_primary') {
        $teamId = (int)($in['team_id'] ?? 0);
        $userId = (int)($in['user_id'] ?? $me);
        if (!$isAdmin && $userId !== $me && !isTeamAdmin($me, $teamId)) { echo json_encode(['error'=>'Sin permiso']); exit; }
        $db->prepare("UPDATE g7_team_members SET is_primary=0 WHERE user_id=?")->execute([$userId]);
        $db->prepare("UPDATE g7_team_members SET is_primary=1 WHERE user_id=? AND team_id=?")->execute([$userId, $teamId]);
        echo json_encode(['ok'=>true]); exit;
    }

    // ── SAVE PERMISSIONS ──────────────────────────────
    if ($act === 'save_permissions') {
        $teamId = (int)($in['team_id'] ?? 0);
        // Los permisos sobre módulos los define solo un admin global (Admin › Módulos y permisos)
        if (!$isAdmin) { echo json_encode(['error'=>'Solo un admin global puede cambiar permisos']); exit; }
        $perms  = $in['permissions'] ?? [];
        // Delete existing
        $db->prepare("DELETE FROM g7_team_permissions WHERE team_id=?")->execute([$teamId]);
        // Insert new
        foreach ($perms as $perm) {
            $mod = preg_replace('/[^a-z_]/', '', $perm['module'] ?? '');
            $act2 = preg_replace('/[^a-z_]/', '', $perm['action'] ?? '');
            if ($mod && $act2) {
                $db->prepare("INSERT IGNORE INTO g7_team_permissions (team_id,module,action) VALUES (?,?,?)")
                   ->execute([$teamId, $mod, $act2]);
            }
        }
        echo json_encode(['ok'=>true]); exit;
    }

    // ── GET ALL USERS (for add member) ────────────────
    if ($act === 'get_users') {
        $teamId = (int)($in['team_id'] ?? 0);
        $users = $db->query("SELECT id,username,display_name,avatar,email FROM g7_users WHERE active=1 ORDER BY display_name,username")->fetchAll();
        $members = $db->prepare("SELECT user_id FROM g7_team_members WHERE team_id=?");
        $members->execute([$teamId]);
        $memberIds = $members->fetchAll(PDO::FETCH_COLUMN);
        $available = array_filter($users, fn($u) => !in_array($u['id'], $memberIds));
        echo json_encode(['ok'=>true, 'users'=>array_values($available)]); exit;
    }

    echo json_encode(['error'=>'Acción desconocida']); exit;
}
?>
<!DOCTYPE html>
<html lang="es" class="theme-<?= $_tp_theme ?> font-<?= $_tp_font ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Equipos — <?= htmlspecialchars(g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha384-3B6NwesSXE7YJlcLI9RpRqGf2p/EgVH8BgoKTaUrmKNDkHPStTQ3EyoYjCGXaOTS" crossorigin="anonymous">
<link rel="stylesheet" href="/toolz/g7-theme.css?v=4">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--g7-bg);color:var(--g7-text);font-family:var(--g7-font);min-height:100vh}

/* LAYOUT */
.tm-layout{display:grid;grid-template-columns:300px 1fr;min-height:calc(100vh - 52px)}

/* SIDEBAR */
.tm-sidebar{background:var(--g7-bg-2);border-right:1px solid var(--g7-border);display:flex;flex-direction:column;position:sticky;top:52px;height:calc(100vh - 52px);overflow:hidden}
.tm-sidebar-head{padding:16px;border-bottom:1px solid var(--g7-border);flex-shrink:0}
.tm-sidebar-title{font-size:13px;font-weight:800;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between}
.tm-list{flex:1;overflow-y:auto;padding:8px}
.tm-team-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;cursor:pointer;transition:all .15s;border:1px solid transparent}
.tm-team-item:hover{background:var(--g7-bg-4)}
.tm-team-item.active{background:var(--g7-accent-muted);border-color:var(--g7-accent-border)}
.tm-team-avatar{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:800;flex-shrink:0;color:var(--g7-text)}
.tm-team-name{font-size:13px;font-weight:700;line-height:1.2}
.tm-team-count{font-size:10px;color:var(--g7-text-4)}

/* CONTENT */
.tm-content{padding:28px 32px;overflow-y:auto}
.tm-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;height:60vh;color:var(--g7-text-4);gap:12px;text-align:center}
.tm-empty i{font-size:48px;opacity:.2}

/* TEAM HEADER */
.tm-head{display:flex;align-items:center;gap:16px;margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid var(--g7-border)}
.tm-head-avatar{width:56px;height:56px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:800;color:var(--g7-text);flex-shrink:0}
.tm-head-name{font-size:20px;font-weight:800}
.tm-head-meta{font-size:12px;color:var(--g7-text-4);margin-top:2px}

/* TABS */
.tm-tabs{display:flex;gap:4px;margin-bottom:20px;background:var(--g7-bg-3);padding:4px;border-radius:10px;width:fit-content}
.tm-tab{padding:7px 16px;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;border:none;background:none;color:var(--g7-text-3);transition:all .15s}
.tm-tab.active{background:var(--g7-bg);color:var(--g7-text);box-shadow:0 1px 4px rgba(0,0,0,.2)}
.tm-tab-content{display:none}.tm-tab-content.active{display:block}

/* CARDS */
.tm-card{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:12px;overflow:hidden;margin-bottom:16px}
.tm-card-head{padding:13px 18px;border-bottom:1px solid var(--g7-border);display:flex;align-items:center;justify-content:space-between}
.tm-card-title{font-size:12px;font-weight:700;display:flex;align-items:center;gap:7px;color:var(--g7-text-2)}
.tm-card-title i{color:var(--g7-accent)}
.tm-card-body{padding:16px 18px}

/* MEMBERS */
.tm-member{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--g7-border)}
.tm-member:last-child{border:none}
.tm-member-av{width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0}
.tm-member-av-placeholder{width:36px;height:36px;border-radius:50%;background:var(--g7-accent-muted);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:var(--g7-accent);flex-shrink:0}
.tm-member-name{font-size:13px;font-weight:600;flex:1}
.tm-member-meta{font-size:10px;color:var(--g7-text-4)}

/* PERMISSIONS GRID */
.tm-perm-grid{display:grid;gap:12px}
.tm-perm-module{background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:10px;padding:14px 16px}
.tm-perm-module-name{font-size:11px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.tm-perm-actions{display:flex;gap:8px;flex-wrap:wrap}
.tm-perm-action{display:flex;align-items:center;gap:6px;cursor:pointer}
.tm-perm-action input{accent-color:var(--g7-accent);width:14px;height:14px}
.tm-perm-action span{font-size:12px;font-weight:500}

/* FORMS */
.tm-input{width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 11px;font-size:12px;outline:none;transition:border-color .15s;font-family:var(--g7-font)}
.tm-input:focus{border-color:var(--g7-accent)}
.tm-label{font-size:9px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:4px}
.tm-row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}

/* BUTTONS */
.btn{border:none;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:all .15s}
.btn-primary{background:var(--g7-accent);color:#000}.btn-primary:hover{opacity:.85}
.btn-ghost{background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-3)}.btn-ghost:hover{color:var(--g7-text)}
.btn-danger{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#ef4444}
.btn-sm{padding:4px 10px;font-size:11px}

/* BADGES */
.badge{display:inline-flex;padding:2px 8px;border-radius:20px;font-size:9px;font-weight:700;text-transform:uppercase}
.badge-admin{background:var(--g7-accent-muted);color:var(--g7-accent);border:1px solid var(--g7-accent-border)}
.badge-member{background:var(--g7-bg-5);color:var(--g7-text-4)}
.badge-primary{background:rgba(34,197,94,.1);color:#22c55e;border:1px solid rgba(34,197,94,.2)}

/* MODAL */
.tm-modal{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9000;display:none;align-items:center;justify-content:center;padding:20px}
.tm-modal.open{display:flex}
.tm-modal-box{background:var(--g7-bg-3);border:1px solid var(--g7-border-3);border-radius:16px;width:100%;max-width:480px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden}
.tm-modal-head{padding:14px 18px;border-bottom:1px solid var(--g7-border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.tm-modal-title{font-size:14px;font-weight:700}
.tm-modal-close{background:none;border:none;color:var(--g7-text-4);cursor:pointer;font-size:17px}
.tm-modal-body{flex:1;overflow-y:auto;padding:16px 18px}
.tm-modal-foot{padding:12px 18px;border-top:1px solid var(--g7-border);display:flex;gap:8px;flex-shrink:0}

/* TOAST */
#tm-toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%) translateY(60px);background:var(--g7-success,#22c55e);color:#000;padding:8px 18px;border-radius:8px;font-size:12px;font-weight:700;transition:transform .3s;z-index:9999;pointer-events:none}
</style>
</head>
<body>
<?php include __DIR__ . '/topbar.php'; ?>

<div class="tm-layout">
  <!-- SIDEBAR: lista de equipos -->
  <aside class="tm-sidebar">
    <div class="tm-sidebar-head">
      <div class="tm-sidebar-title">
        <span><i class="fa-solid fa-people-group" style="color:var(--g7-accent);margin-right:6px"></i>Equipos</span>
        <?php if($isAdmin): ?>
        <button class="btn btn-primary btn-sm" data-on-click="tmOpenCreate"><i class="fa-solid fa-plus"></i> Nuevo</button>
        <?php endif; ?>
      </div>
      <input class="tm-input" placeholder="Buscar equipo..." data-on-input="tmFilterTeams" data-input-1="value" style="font-size:11px">
    </div>
    <div class="tm-list" id="tm-team-list">
      <div style="padding:20px;text-align:center;color:var(--g7-text-5);font-size:12px"><i class="fa-solid fa-spinner fa-spin"></i></div>
    </div>
  </aside>

  <!-- CONTENT: detalle del equipo -->
  <div class="tm-content" id="tm-content">
    <div class="tm-empty">
      <i class="fa-solid fa-people-group"></i>
      <div style="font-size:16px;font-weight:700">Selecciona un equipo</div>
      <div style="font-size:12px">Elige un equipo de la lista para ver y editar sus detalles</div>
    </div>
  </div>
</div>

<!-- CREATE TEAM MODAL -->
<div class="tm-modal" id="tm-create-modal">
  <div class="tm-modal-box">
    <div class="tm-modal-head">
      <span class="tm-modal-title"><i class="fa-solid fa-plus" style="color:var(--g7-accent);margin-right:8px"></i>Nuevo equipo</span>
      <button class="tm-modal-close" data-on-click="tmCloseCreate">✕</button>
    </div>
    <div class="tm-modal-body">
      <div class="tm-row2" style="margin-bottom:12px">
        <div>
          <label class="tm-label">Nombre del equipo *</label>
          <input class="tm-input" id="tc-name" placeholder="Ej: Marketing">
        </div>
        <div>
          <label class="tm-label">Color</label>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="color" id="tc-color" value="#ff6600" style="width:36px;height:34px;border:none;border-radius:6px;cursor:pointer;padding:2px;background:var(--g7-bg-4)">
            <div id="tc-color-preview" style="width:36px;height:34px;border-radius:8px;background:var(--g7-accent);flex-shrink:0"></div>
          </div>
        </div>
      </div>
      <div style="margin-bottom:12px">
        <label class="tm-label">Descripción</label>
        <textarea class="tm-input" id="tc-desc" rows="2" placeholder="Qué hace este equipo..."></textarea>
      </div>
      <div id="tc-error" style="display:none;color:#ef4444;font-size:11px;padding:8px;background:rgba(239,68,68,.08);border-radius:7px"></div>
    </div>
    <div class="tm-modal-foot">
      <button class="btn btn-ghost" style="flex:1" data-on-click="tmCloseCreate">Cancelar</button>
      <button class="btn btn-primary" style="flex:2" data-on-click="tmCreateTeam"><i class="fa-solid fa-check"></i> Crear equipo</button>
    </div>
  </div>
</div>

<!-- ADD MEMBER MODAL -->
<div class="tm-modal" id="tm-add-member-modal">
  <div class="tm-modal-box">
    <div class="tm-modal-head">
      <span class="tm-modal-title"><i class="fa-solid fa-user-plus" style="color:var(--g7-accent);margin-right:8px"></i>Agregar miembro</span>
      <button class="tm-modal-close" data-on-click="tmCloseAddMember">✕</button>
    </div>
    <div class="tm-modal-body">
      <input class="tm-input" id="am-search" placeholder="Buscar usuario..." data-on-input="tmFilterUsers" data-input-1="value" style="margin-bottom:10px">
      <div id="am-list" style="display:flex;flex-direction:column;gap:6px;max-height:300px;overflow-y:auto"></div>
    </div>
  </div>
</div>

<div id="tm-toast"></div>

<?php
  // Mismo registro que usa el control de acceso (access.php)
  $__tm = [];
  foreach (g7_modules() as $__id => $__m) if (empty($__m['core'])) $__tm[$__id] = ['label' => $__m['label'], 'icon' => $__m['icon'], 'actions' => $__m['actions']];
?>
<script type="application/json" id="tm-cfg"><?= json_encode(['isAdmin' => (bool)$isAdmin, 'myId' => (int)$me, 'modules' => $__tm], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="/toolz/assets/teams-page.js?v=<?= (int)@filemtime(__DIR__ . '/assets/teams-page.js') ?>"></script>
</body>
</html>
