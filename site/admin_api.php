<?php
/**
 * admin_api.php — API del panel de administración (accesos, marcas, equipos, usuarios, ajustes, auditoría).
 * Lo incluye admin.php dentro de su manejador POST. Espera $db, $currentUser, $in, $act.
 * Si la acción no es de este archivo, vuelve sin hacer nada.
 */

if (!isset($act) || !is_string($act)) return;

$__me = (int)$currentUser['id'];
$__ok = function (array $extra = []) { echo json_encode(['ok' => true] + $extra, JSON_UNESCAPED_UNICODE); exit; };
$__err = function (string $msg, int $code = 400) { http_response_code($code); echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE); exit; };
$__int = fn($v) => (int)$v;
$__ids = fn($a) => array_values(array_unique(array_filter(array_map('intval', is_array($a) ? $a : []))));

// ─────────────────────────────────────────────────────────────
// Inicio: accesos rápidos y secciones (admin_home.js)
// ─────────────────────────────────────────────────────────────
if ($act === 'home_get' || $act === 'home_save') {
    require_once __DIR__ . '/home_lib.php';
    if ($act === 'home_save') {
        $cfg = home_sanitize(is_array($in['config'] ?? null) ? $in['config'] : []);
        if ($in['reset'] ?? false) { getDB()->prepare("DELETE FROM g7_config WHERE cfg_key = 'home_config'")->execute(); $cfg = home_defaults(); }
        else g7_cfg_set('home_config', json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        g7_audit('home.save', 'Inicio', ['tiles' => count($cfg['tiles']), 'reset' => (bool)($in['reset'] ?? false)]);
        $__ok(['config' => $cfg]);
    }
    $mods = [];
    foreach (g7_tool_catalog($__me) as $id => $t) $mods[] = ['id' => $id, 'label' => $t['label'], 'icon' => $t['icon'], 'href' => $t['url'], 'desc' => $t['desc'], 'group' => $t['group']];
    $teams = $db->query("SELECT id, name FROM g7_teams WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $__ok(['config' => home_config(), 'defaults' => home_defaults(), 'sections' => HOME_SECTIONS, 'modules' => $mods,
           'teams' => array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name']], $teams), 'saved' => g7_cfg('home_config') !== null]);
}

// ─────────────────────────────────────────────────────────────
// Snapshot completo para el panel
// ─────────────────────────────────────────────────────────────
function adm_snapshot(PDO $db): array {
    g7_ctx_forget();
    g7_ctx_preload_all();
    $mods = g7_modules();
    $__super = g7_super_ok((int)(getCurrentUser()['id'] ?? 0));
    $__hidden = $__super ? [] : array_keys(array_filter($mods, fn($m) => $m['mode'] === 'superadmins'));
    foreach ($__hidden as $__h) unset($mods[$__h]);
    $users = $db->query("SELECT id, username, display_name, email, role, active, avatar, position, created_at, last_login, last_seen FROM g7_users ORDER BY active DESC, COALESCE(NULLIF(display_name,''), username)")->fetchAll();
    foreach ($users as &$__u) $__u['is_super'] = g7_is_super((int)$__u['id']);
    unset($__u);
    $teams = $db->query("SELECT id, name, slug, description, color, active FROM g7_teams ORDER BY name")->fetchAll();
    $members = $db->query("SELECT team_id, user_id, role, is_primary FROM g7_team_members")->fetchAll();
    $grants = $db->query("SELECT team_id, module, action FROM g7_team_permissions")->fetchAll();
    $overrides = $db->query("SELECT user_id, module, action, effect FROM g7_user_permissions")->fetchAll();
    $bAccess = $db->query("SELECT subject_type, subject_id, brand_id FROM g7_brand_access")->fetchAll();
    $grants = array_filter($grants, fn($g) => !in_array($g['module'], $__hidden, true));
    $overrides = array_filter($overrides, fn($o) => !in_array($o['module'], $__hidden, true));

    $teamOut = [];
    foreach ($teams as $t) {
        $t['id'] = (int)$t['id']; $t['active'] = (int)$t['active'];
        $t['members'] = []; $t['grants'] = []; $t['brands'] = [];
        $teamOut[$t['id']] = $t;
    }
    foreach ($members as $m) if (isset($teamOut[$m['team_id']])) $teamOut[$m['team_id']]['members'][] = ['user_id' => (int)$m['user_id'], 'role' => $m['role'], 'is_primary' => (int)$m['is_primary']];
    foreach ($grants as $g) if (isset($teamOut[$g['team_id']])) $teamOut[$g['team_id']]['grants'][] = $g['module'] . '.' . $g['action'];
    foreach ($bAccess as $b) if ($b['subject_type'] === 'team' && isset($teamOut[$b['subject_id']])) $teamOut[$b['subject_id']]['brands'][] = (int)$b['brand_id'];

    $userOut = [];
    foreach ($users as $u) {
        $uid = (int)$u['id'];
        $ctx = g7_ctx($uid);
        $u['id'] = $uid; $u['active'] = (int)$u['active'];
        $u['teams'] = [];
        foreach ($members as $m) if ((int)$m['user_id'] === $uid) $u['teams'][] = ['team_id' => (int)$m['team_id'], 'role' => $m['role'], 'is_primary' => (int)$m['is_primary']];
        $u['brands_direct'] = $ctx['brands_direct'];
        $u['brands_team'] = $ctx['brands_team'];
        $u['brands_effective'] = g7_user_brand_ids($uid);
        $u['overrides'] = [];
        foreach ($overrides as $o) if ((int)$o['user_id'] === $uid) $u['overrides'][$o['module'] . '.' . $o['action']] = $o['effect'];
        $u['access'] = [];
        foreach ($mods as $mid => $m) {
            foreach ($m['actions'] as $a) {
                [$ok, $code, $why] = g7_explain($uid, $mid, $a);
                $u['access'][$mid . '.' . $a] = [$ok ? 1 : 0, $code, $why];
            }
        }
        $userOut[] = $u;
    }

    $modOut = [];
    foreach ($mods as $id => $m) {
        $allowed = 0;
        foreach ($userOut as $u) if ($u['active'] && $u['access'][$id . '.view'][0]) $allowed++;
        $modOut[] = ['id' => $id, 'label' => $m['label'], 'icon' => $m['icon'], 'group' => $m['group'], 'paths' => $m['paths'], 'url' => $m['url'],
                     'actions' => $m['actions'], 'grant_actions' => $m['grant_actions'] ?? [], 'mode' => $m['mode'], 'core' => !empty($m['core']), 'custom' => !empty($m['custom']),
                     'brand_scoped' => !empty($m['brand_scoped']), 'description' => $m['description'] ?? '', 'allowed_count' => $allowed];
    }

    $brandOut = [];
    foreach (g7_brands(true) as $b) {
        $b['users_direct'] = []; $b['teams'] = [];
        foreach ($bAccess as $a) if ((int)$a['brand_id'] === $b['id']) {
            if ($a['subject_type'] === 'user') $b['users_direct'][] = (int)$a['subject_id']; else $b['teams'][] = (int)$a['subject_id'];
        }
        $brandOut[] = $b;
    }

    $settings = [];
    $schema = g7_settings_schema();
    foreach ($schema as $g) foreach ($g['fields'] as $f) $settings[$f['key']] = (string)g7_setting($f['key']);

    return [
        'me' => (int)(getCurrentUser()['id'] ?? 0),
        'modules' => $modOut, 'users' => $userOut, 'teams' => array_values($teamOut), 'brands' => $brandOut,
        'settings_schema' => $schema, 'settings' => $settings,
        'modes' => array_filter([
            'everyone' => ['label' => 'Todos', 'desc' => 'Cualquier usuario activo', 'icon' => 'fa-globe'],
            'teams'    => ['label' => 'Por equipos', 'desc' => 'Solo equipos o usuarios con permiso', 'icon' => 'fa-people-group'],
            'admins'   => ['label' => 'Solo admins', 'desc' => 'Solo administradores globales', 'icon' => 'fa-user-shield'],
            'superadmins' => ['label' => 'Solo superadmin', 'desc' => 'Solo el superadmin: a los demás admins ni siquiera les aparece', 'icon' => 'fa-crown'],
            'off'      => ['label' => 'Apagado', 'desc' => 'Nadie salvo admins; se oculta del menú', 'icon' => 'fa-power-off'],
        ], fn($k) => $__super || $k !== 'superadmins', ARRAY_FILTER_USE_KEY),
        'action_labels' => ['view' => 'Ver', 'create' => 'Crear', 'edit' => 'Editar', 'delete' => 'Eliminar', 'notes' => 'Ver notas compartidas', 'catalog' => 'Agregar del catálogo', 'config' => 'Configurar', 'design' => 'Diseñar', 'publish' => 'Publicar'],
    ];
}

/** Valores distintos de marca en los datos, con su clasificación actual. */
function adm_brand_values(PDO $db): array {
    $vals = [];
    $add = function ($source, $value, $n) use (&$vals) {
        $value = trim((string)$value);
        $key = $source . '|' . $value;
        if (!isset($vals[$key])) $vals[$key] = ['source' => $source, 'value' => $value, 'count' => 0];
        $vals[$key]['count'] += (int)$n;
    };
    foreach ($db->query("SELECT project, COUNT(*) n FROM g7_content GROUP BY project") as $r) $add('Promos (proyecto)', $r['project'], $r['n']);
    foreach ($db->query("SELECT projects FROM g7_content WHERE projects IS NOT NULL AND projects NOT IN ('','[]')") as $r) {
        foreach ((array)json_decode($r['projects'], true) as $p) $add('Promos (multi-proyecto)', $p, 1);
    }
    foreach (['g7_landings' => 'Landings', 'g7_mailing_exports' => 'Mailing (exportados)', 'g7_mailing_history' => 'Mailing (historial)'] as $t => $label) {
        try { foreach ($db->query("SELECT platform, COUNT(*) n FROM `$t` GROUP BY platform") as $r) $add($label, $r['platform'], $r['n']); } catch (Throwable $e) {}
    }
    $out = [];
    foreach ($vals as $v) { $v['brands'] = g7_brands_for_text($v['value']); $v['norm'] = g7_norm($v['value']); $out[] = $v; }
    usort($out, fn($a, $b) => [count($a['brands']) > 0, $b['count']] <=> [count($b['brands']) > 0, $a['count']]);
    return $out;
}

function adm_policies_set(string $id, array $patch): void {
    $pol = g7_cfg_json('module_policies', []);
    $cur = $pol[$id] ?? [];
    foreach ($patch as $k => $v) { if ($v === null || $v === '') unset($cur[$k]); else $cur[$k] = $v; }
    if ($cur) $pol[$id] = $cur; else unset($pol[$id]);
    g7_cfg_set('module_policies', $pol);
}

function adm_password_ok(string $pw): ?string {
    $min = max(4, (int)g7_setting('password_min_length'));
    return mb_strlen($pw) < $min ? "La contraseña debe tener al menos $min caracteres" : null;
}

switch ($act) {

// ── Lectura ───────────────────────────────────────────────────
case 'acc_snapshot':
    $__ok(adm_snapshot($db) + ['me_super' => g7_is_super($__me), 'me_can_super' => g7_super_ok($__me)]);

case 'brand_values':
    $__ok(['values' => adm_brand_values($db)]);

case 'audit_list':
    $limit = max(10, min(500, $__int($in['limit'] ?? 100)));
    $where = []; $params = [];
    if (!empty($in['q'])) { $where[] = "(a.action LIKE ? OR a.target LIKE ? OR a.detail LIKE ?)"; $q = '%' . $in['q'] . '%'; array_push($params, $q, $q, $q); }
    if (!empty($in['user_id'])) { $where[] = "a.user_id = ?"; $params[] = $__int($in['user_id']); }
    $sql = "SELECT a.*, u.username, u.display_name FROM g7_admin_audit a LEFT JOIN g7_users u ON u.id = a.user_id"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY a.id DESC LIMIT $limit";
    $st = $db->prepare($sql); $st->execute($params);
    $__ok(['rows' => $st->fetchAll()]);

// ── Módulos ───────────────────────────────────────────────────
case 'module_set':
    $id = (string)($in['module'] ?? '');
    $mods = g7_modules();
    if (isset($mods[$id]) && $mods[$id]['mode'] === 'superadmins' && !g7_super_ok($__me)) unset($mods[$id]);
    if (!isset($mods[$id])) $__err('Módulo desconocido');
    $patch = [];
    if (array_key_exists('mode', $in)) {
        if (!empty($mods[$id]['core'])) $__err('Este módulo es básico y siempre está disponible');
        if (!in_array($in['mode'], G7_MODES, true)) $__err('Modo inválido');
        if ($in['mode'] === 'superadmins' && !g7_super_ok($__me)) { g7_audit('super.denied', $id, ['action' => 'module_set'], $__me); $__err('Modo inválido'); }
        $patch['mode'] = $in['mode'];
    }
    foreach (['label', 'icon', 'description'] as $k) if (array_key_exists($k, $in)) $patch[$k] = mb_substr(trim((string)$in[$k]), 0, $k === 'description' ? 300 : 60);
    if (isset($patch['icon']) && $patch['icon'] !== '' && !preg_match('/^fa-[a-z0-9-]+$/', $patch['icon'])) $__err('Ícono inválido');
    adm_policies_set($id, $patch);
    g7_audit('module.update', $id, $patch, $__me);
    $__ok();

case 'module_custom_save':
    $id = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($in['id'] ?? '')));
    $label = trim((string)($in['label'] ?? ''));
    $paths = array_values(array_filter(array_map(fn($p) => ltrim(trim($p), '/'), (array)($in['paths'] ?? [])), 'strlen'));
    if (!$id || !$label || !$paths) $__err('Completa identificador, nombre y al menos una ruta');
    if (isset(g7_base_modules()[$id])) $__err('Ese identificador ya lo usa un módulo del sistema');
    foreach ($paths as $p) if (!preg_match('#^[A-Za-z0-9_\-./]+$#', $p) || strpos($p, '..') !== false) $__err("Ruta inválida: $p");
    $list = array_values(array_filter(g7_cfg_json('modules_custom', []), fn($c) => ($c['id'] ?? '') !== $id));
    $list[] = ['id' => $id, 'label' => mb_substr($label, 0, 60), 'icon' => preg_match('/^fa-[a-z0-9-]+$/', $in['icon'] ?? '') ? $in['icon'] : 'fa-puzzle-piece',
               'group' => mb_substr(trim((string)($in['group'] ?? '')) ?: 'Personalizados', 0, 40), 'paths' => $paths];
    g7_cfg_set('modules_custom', $list);
    g7_audit('module.custom_save', $id, ['label' => $label, 'paths' => $paths], $__me);
    $__ok();

case 'module_custom_delete':
    $id = (string)($in['id'] ?? '');
    g7_cfg_set('modules_custom', array_values(array_filter(g7_cfg_json('modules_custom', []), fn($c) => ($c['id'] ?? '') !== $id)));
    adm_policies_set($id, ['mode' => null, 'label' => null, 'icon' => null, 'description' => null]);
    $db->prepare("DELETE FROM g7_team_permissions WHERE module=?")->execute([$id]);
    $db->prepare("DELETE FROM g7_user_permissions WHERE module=?")->execute([$id]);
    g7_audit('module.custom_delete', $id, null, $__me);
    $__ok();

// ── Permisos por equipo y por usuario ─────────────────────────
case 'team_grant':
    $tid = $__int($in['team_id'] ?? 0); $mod = (string)($in['module'] ?? ''); $a = (string)($in['action_name'] ?? '');
    $mods = g7_modules();
    if (isset($mods[$mod]) && $mods[$mod]['mode'] === 'superadmins' && !g7_super_ok($__me)) unset($mods[$mod]);
    if (!$tid || !isset($mods[$mod]) || !in_array($a, $mods[$mod]['actions'], true)) $__err('Datos inválidos');
    if (!empty($in['granted'])) {
        $db->prepare("INSERT IGNORE INTO g7_team_permissions (team_id, module, action) VALUES (?,?,?)")->execute([$tid, $mod, $a]);
        if ($a !== 'view') $db->prepare("INSERT IGNORE INTO g7_team_permissions (team_id, module, action) VALUES (?,?,'view')")->execute([$tid, $mod]);
    } else {
        $db->prepare("DELETE FROM g7_team_permissions WHERE team_id=? AND module=? AND action=?")->execute([$tid, $mod, $a]);
        if ($a === 'view') $db->prepare("DELETE FROM g7_team_permissions WHERE team_id=? AND module=?")->execute([$tid, $mod]);
    }
    g7_audit('team.grant', "team:$tid", ['module' => $mod, 'action' => $a, 'granted' => !empty($in['granted'])], $__me);
    $__ok();

case 'user_override':
    $uid = $__int($in['user_id'] ?? 0); $mod = (string)($in['module'] ?? ''); $a = (string)($in['action_name'] ?? 'view');
    $effect = (string)($in['effect'] ?? 'inherit');
    $mods = g7_modules();
    if (isset($mods[$mod]) && $mods[$mod]['mode'] === 'superadmins' && !g7_super_ok($__me)) unset($mods[$mod]);
    if (!$uid || !isset($mods[$mod]) || !in_array($a, $mods[$mod]['actions'], true)) $__err('Datos inválidos');
    if ($effect === 'inherit') {
        $db->prepare("DELETE FROM g7_user_permissions WHERE user_id=? AND module=? AND action=?")->execute([$uid, $mod, $a]);
    } elseif (in_array($effect, ['allow', 'deny'], true)) {
        $db->prepare("INSERT INTO g7_user_permissions (user_id, module, action, effect, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE effect=VALUES(effect), updated_by=VALUES(updated_by)")
           ->execute([$uid, $mod, $a, $effect, $__me]);
    } else $__err('Efecto inválido');
    g7_audit('user.override', "user:$uid", ['module' => $mod, 'action' => $a, 'effect' => $effect], $__me);
    $__ok();

case 'user_overrides_clear':
    $uid = $__int($in['user_id'] ?? 0);
    $db->prepare("DELETE FROM g7_user_permissions WHERE user_id=?")->execute([$uid]);
    g7_audit('user.overrides_clear', "user:$uid", null, $__me);
    $__ok();

// ── Usuarios ──────────────────────────────────────────────────
case 'user_save':
    $id = $__int($in['id'] ?? 0);
    $display = mb_substr(trim((string)($in['display_name'] ?? '')), 0, 100);
    $email = trim((string)($in['email'] ?? ''));
    $role = ($in['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
    $active = !empty($in['active']) ? 1 : 0;
    $pw = (string)($in['password'] ?? '');
    $teamIds = $__ids($in['team_ids'] ?? []);
    $primary = $__int($in['primary_team'] ?? 0);
    $brandIds = $__ids($in['brand_ids'] ?? []);
    $teamAdmins = $__ids($in['team_admin_ids'] ?? []);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $__err('Email inválido');
    if ($why = g7_super_denies($__me, $id, $role)) { g7_audit('super.denied', "user:$id", ['action' => 'user_save'], $__me); $__err($why, 403); }

    if (!$id) {
        $username = trim((string)($in['username'] ?? ''));
        if (!preg_match('/^[\p{L}0-9._-]{2,50}$/u', $username)) $__err('Usuario inválido: 2 a 50 caracteres, sin espacios');
        if ($err = adm_password_ok($pw)) $__err($err);
        $dupe = $db->prepare("SELECT id FROM g7_users WHERE username=? OR (email<>'' AND email=?)"); $dupe->execute([$username, $email]);
        if ($dupe->fetchColumn()) $__err('El usuario o email ya existe');
        if ($active && ($why = lic_can_add('users', $db))) $__err($why, 402);   // tope de usuarios del plan
        if ($email === '') $email = $username . '@sin-email.local';
        $db->prepare("INSERT INTO g7_users (username, email, display_name, password, role, active) VALUES (?,?,?,?,?,?)")
           ->execute([$username, $email, $display ?: $username, password_hash($pw, PASSWORD_DEFAULT), $role, $active]);
        $id = (int)$db->lastInsertId();
        if (!$teamIds && ($def = (int)g7_setting('new_user_team'))) $teamIds = [$def];
        g7_audit('user.create', "user:$id", ['username' => $username, 'role' => $role], $__me);
    } else {
        if ($id === $__me && $role !== 'admin') $__err('No puedes quitarte el rol de admin a ti mismo');
        if ($id === $__me && !$active) $__err('No puedes desactivar tu propia cuenta');
        if ($active) {   // reactivar a alguien también cuenta para el tope de usuarios del plan
            $was = $db->prepare('SELECT active FROM g7_users WHERE id=?'); $was->execute([$id]);
            if (!(int)$was->fetchColumn() && ($why = lic_can_add('users', $db))) $__err($why, 402);
        }
        $sets = ['display_name=?', 'role=?', 'active=?']; $vals = [$display, $role, $active];
        if ($email !== '') {
            $dupe = $db->prepare("SELECT id FROM g7_users WHERE email=? AND id<>?"); $dupe->execute([$email, $id]);
            if ($dupe->fetchColumn()) $__err('Ese email ya lo usa otro usuario');
            $sets[] = 'email=?'; $vals[] = $email;
        }
        if ($pw !== '') { if ($err = adm_password_ok($pw)) $__err($err); $sets[] = 'password=?'; $vals[] = password_hash($pw, PASSWORD_DEFAULT); }
        $vals[] = $id;
        $db->prepare("UPDATE g7_users SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);
        g7_audit('user.update', "user:$id", ['role' => $role, 'active' => $active, 'password_changed' => $pw !== ''], $__me);
    }

    if (array_key_exists('team_ids', $in) || !empty($teamIds)) {
        $db->prepare("DELETE FROM g7_team_members WHERE user_id=?" . ($teamIds ? " AND team_id NOT IN (" . implode(',', $teamIds) . ")" : ''))->execute([$id]);
        if ($teamIds && !in_array($primary, $teamIds, true)) $primary = $teamIds[0];
        $ins = $db->prepare("INSERT INTO g7_team_members (team_id, user_id, role, is_primary) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role), is_primary=VALUES(is_primary)");
        foreach ($teamIds as $t) $ins->execute([$t, $id, in_array($t, $teamAdmins, true) ? 'admin' : 'member', $t === $primary ? 1 : 0]);
    }
    if (array_key_exists('brand_ids', $in)) {
        $db->prepare("DELETE FROM g7_brand_access WHERE subject_type='user' AND subject_id=?")->execute([$id]);
        $ins = $db->prepare("INSERT IGNORE INTO g7_brand_access (subject_type, subject_id, brand_id) VALUES ('user',?,?)");
        foreach ($brandIds as $b) $ins->execute([$id, $b]);
    }
    $__ok(['id' => $id]);

case 'user_super':
    if (!g7_is_super($__me)) $__err('Solo el superadmin puede hacer esto.', 403);
    $id = $__int($in['id'] ?? 0); $on = !empty($in['on']);
    $u = $db->prepare("SELECT role, active FROM g7_users WHERE id=?"); $u->execute([$id]); $row = $u->fetch();
    if (!$row) $__err('Usuario no encontrado');
    if ($on && ($row['role'] !== 'admin' || !$row['active'])) $__err('Tiene que ser un admin activo.');
    if (!$on && g7_super_ids() === [$id]) $__err('Tiene que quedar al menos un superadmin.');
    $db->prepare("UPDATE g7_users SET is_super=? WHERE id=?")->execute([$on ? 1 : 0, $id]);
    g7_audit('super.set', "user:$id", ['on' => $on], $__me);
    $__ok();

case 'user_delete':
    $id = $__int($in['id'] ?? 0);
    if ($id === $__me) $__err('No puedes eliminar tu propia cuenta');
    if (g7_is_super($id)) $__err('Primero quitale la marca de superadmin.', 403);
    if ($why = g7_super_denies($__me, $id)) { g7_audit('super.denied', "user:$id", ['action' => 'user_delete'], $__me); $__err($why, 403); }
    $u = $db->prepare("SELECT username FROM g7_users WHERE id=?"); $u->execute([$id]); $name = $u->fetchColumn();
    if (!$name) $__err('Usuario no encontrado');
    $db->prepare("DELETE FROM g7_team_members WHERE user_id=?")->execute([$id]);
    $db->prepare("DELETE FROM g7_user_permissions WHERE user_id=?")->execute([$id]);
    $db->prepare("DELETE FROM g7_brand_access WHERE subject_type='user' AND subject_id=?")->execute([$id]);
    $db->prepare("DELETE FROM g7_users WHERE id=?")->execute([$id]);
    g7_audit('user.delete', "user:$id", ['username' => $name], $__me);
    $__ok();

// ── Equipos ───────────────────────────────────────────────────
case 'team_save':
    $id = $__int($in['id'] ?? 0);
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 100);
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', $in['color'] ?? '') ? $in['color'] : '#ff6600';
    $desc = mb_substr(trim((string)($in['description'] ?? '')), 0, 500);
    if ($name === '') $__err('El equipo necesita un nombre');
    if ($id) {
        $db->prepare("UPDATE g7_teams SET name=?, color=?, description=?, active=? WHERE id=?")->execute([$name, $color, $desc, empty($in['inactive']) ? 1 : 0, $id]);
        g7_audit('team.update', "team:$id", ['name' => $name], $__me);
    } else {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name)), '_') ?: 'equipo';
        $chk = $db->prepare("SELECT COUNT(*) FROM g7_teams WHERE slug=?"); $chk->execute([$slug]);
        if ($chk->fetchColumn()) $slug .= '_' . time();
        $db->prepare("INSERT INTO g7_teams (name, slug, description, color, created_by) VALUES (?,?,?,?,?)")->execute([$name, $slug, $desc, $color, $__me]);
        $id = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT IGNORE INTO g7_team_permissions (team_id, module, action) VALUES (?,?,?)");
        foreach ((array)($in['copy_from_grants'] ?? []) as $g) { [$m, $a] = array_pad(explode('.', (string)$g, 2), 2, 'view'); $ins->execute([$id, $m, $a]); }
        g7_audit('team.create', "team:$id", ['name' => $name], $__me);
    }
    if (array_key_exists('brand_ids', $in)) {
        $db->prepare("DELETE FROM g7_brand_access WHERE subject_type='team' AND subject_id=?")->execute([$id]);
        $ins = $db->prepare("INSERT IGNORE INTO g7_brand_access (subject_type, subject_id, brand_id) VALUES ('team',?,?)");
        foreach ($__ids($in['brand_ids']) as $b) $ins->execute([$id, $b]);
    }
    $__ok(['id' => $id]);

case 'team_delete':
    $id = $__int($in['id'] ?? 0);
    $n = $db->prepare("SELECT name FROM g7_teams WHERE id=?"); $n->execute([$id]); $name = $n->fetchColumn();
    if (!$name) $__err('Equipo no encontrado');
    foreach (["DELETE FROM g7_team_members WHERE team_id=?", "DELETE FROM g7_team_permissions WHERE team_id=?", "DELETE FROM g7_team_config WHERE team_id=?",
              "DELETE FROM g7_brand_access WHERE subject_type='team' AND subject_id=?", "DELETE FROM g7_teams WHERE id=?"] as $sql) $db->prepare($sql)->execute([$id]);
    g7_audit('team.delete', "team:$id", ['name' => $name], $__me);
    $__ok();

case 'team_member':
    $tid = $__int($in['team_id'] ?? 0); $uid = $__int($in['user_id'] ?? 0);
    if (!$tid || !$uid) $__err('Datos inválidos');
    if (empty($in['member'])) {
        $db->prepare("DELETE FROM g7_team_members WHERE team_id=? AND user_id=?")->execute([$tid, $uid]);
        $has = $db->prepare("SELECT COUNT(*) FROM g7_team_members WHERE user_id=? AND is_primary=1"); $has->execute([$uid]);
        if (!$has->fetchColumn()) $db->prepare("UPDATE g7_team_members SET is_primary=1 WHERE user_id=? LIMIT 1")->execute([$uid]);
    } else {
        $has = $db->prepare("SELECT COUNT(*) FROM g7_team_members WHERE user_id=? AND is_primary=1"); $has->execute([$uid]);
        $db->prepare("INSERT INTO g7_team_members (team_id, user_id, role, is_primary) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role)")
           ->execute([$tid, $uid, ($in['role'] ?? '') === 'admin' ? 'admin' : 'member', $has->fetchColumn() ? 0 : 1]);
    }
    g7_audit('team.member', "team:$tid", ['user_id' => $uid, 'member' => !empty($in['member']), 'role' => $in['role'] ?? 'member'], $__me);
    $__ok();

// ── Marcas ────────────────────────────────────────────────────
case 'brand_save':
    $id = $__int($in['id'] ?? 0);
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 100);
    if ($name === '') $__err('La marca necesita un nombre');
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', $in['color'] ?? '') ? $in['color'] : '#ff6600';
    $kw = array_values(array_unique(array_filter(array_map('g7_norm', (array)($in['keywords'] ?? [])), 'strlen')));
    if (!$kw) $kw = [g7_norm($name)];
    $logo = mb_substr(trim((string)($in['logo_url'] ?? '')), 0, 500);
    $active = array_key_exists('active', $in) ? (!empty($in['active']) ? 1 : 0) : 1;
    if ($id) {
        $db->prepare("UPDATE g7_brands SET name=?, color=?, keywords=?, logo_url=?, active=? WHERE id=?")->execute([$name, $color, json_encode($kw), $logo, $active, $id]);
        g7_audit('brand.update', "brand:$id", ['name' => $name, 'keywords' => $kw, 'active' => $active], $__me);
    } else {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_') ?: 'marca';
        $chk = $db->prepare("SELECT COUNT(*) FROM g7_brands WHERE slug=?"); $chk->execute([$slug]);
        if ($chk->fetchColumn()) $slug .= '_' . time();
        $sort = (int)$db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM g7_brands")->fetchColumn();
        $db->prepare("INSERT INTO g7_brands (slug, name, color, keywords, logo_url, sort_order, active) VALUES (?,?,?,?,?,?,?)")->execute([$slug, $name, $color, json_encode($kw), $logo, $sort, $active]);
        $id = (int)$db->lastInsertId();
        g7_audit('brand.create', "brand:$id", ['name' => $name, 'keywords' => $kw], $__me);
    }
    $__ok(['id' => $id]);

case 'brand_delete':
    $id = $__int($in['id'] ?? 0);
    $db->prepare("DELETE FROM g7_brand_access WHERE brand_id=?")->execute([$id]);
    $db->prepare("DELETE FROM g7_brands WHERE id=?")->execute([$id]);
    g7_audit('brand.delete', "brand:$id", null, $__me);
    $__ok();

case 'brand_order':
    $st = $db->prepare("UPDATE g7_brands SET sort_order=? WHERE id=?");
    foreach ($__ids($in['ids'] ?? []) as $i => $bid) $st->execute([$i, $bid]);
    $__ok();

case 'brand_keyword_add':
    $id = $__int($in['brand_id'] ?? 0); $k = g7_norm((string)($in['keyword'] ?? ''));
    if (!$id || $k === '') $__err('Datos inválidos');
    $b = g7_brands(true)[$id] ?? null;
    if (!$b) $__err('Marca no encontrada');
    $kw = array_values(array_unique(array_merge($b['keywords'], [$k])));
    $db->prepare("UPDATE g7_brands SET keywords=? WHERE id=?")->execute([json_encode($kw), $id]);
    g7_audit('brand.keyword_add', "brand:$id", ['keyword' => $k], $__me);
    $__ok();

case 'brand_access':
    // Asignación puntual: {subject_type, subject_id, brand_id, on}
    $type = ($in['subject_type'] ?? '') === 'team' ? 'team' : 'user';
    $sid = $__int($in['subject_id'] ?? 0); $bid = $__int($in['brand_id'] ?? 0);
    if (!$sid || !$bid) $__err('Datos inválidos');
    if (!empty($in['on'])) $db->prepare("INSERT IGNORE INTO g7_brand_access (subject_type, subject_id, brand_id) VALUES (?,?,?)")->execute([$type, $sid, $bid]);
    else $db->prepare("DELETE FROM g7_brand_access WHERE subject_type=? AND subject_id=? AND brand_id=?")->execute([$type, $sid, $bid]);
    g7_audit('brand.access', "$type:$sid", ['brand_id' => $bid, 'on' => !empty($in['on'])], $__me);
    $__ok();

// ── Ajustes ───────────────────────────────────────────────────
case 'settings_save':
    $values = is_array($in['values'] ?? null) ? $in['values'] : [];
    $fields = [];
    foreach (g7_settings_schema() as $g) foreach ($g['fields'] as $f) $fields[$f['key']] = $f;
    $changed = [];
    foreach ($values as $k => $v) {
        if (!isset($fields[$k])) continue;
        $f = $fields[$k]; $v = is_bool($v) ? ($v ? '1' : '0') : trim((string)$v);
        switch ($f['type']) {
            case 'toggle': $v = $v === '1' ? '1' : '0'; break;
            case 'number': $v = (string)max($f['min'] ?? PHP_INT_MIN, min($f['max'] ?? PHP_INT_MAX, (int)$v)); break;
            case 'color':  if (!preg_match('/^#[0-9a-fA-F]{6}$/', $v)) $__err('Color inválido en ' . $f['label']); break;
            case 'select': if (!array_key_exists($v, $f['options'])) $__err('Opción inválida en ' . $f['label']); break;
            default:       $v = mb_substr($v, 0, 4000);
        }
        if ((string)g7_setting($k) !== $v) { g7_cfg_set($k, $v); $changed[$k] = $v; }
    }
    if (array_intersect(array_keys($changed), ['announce_text', 'announce_level', 'announce_link', 'announce_enabled'])) g7_cfg_set('announce_version', (string)time());
    if ($changed) g7_audit('settings.update', implode(',', array_keys($changed)), $changed, $__me);
    $__ok(['changed' => array_keys($changed)]);

case 'config_raw_list':
    $rows = $db->query("SELECT cfg_key, cfg_value, updated_at FROM g7_config ORDER BY cfg_key")->fetchAll();
    $__ok(['rows' => $rows]);

case 'config_raw_save':
    $k = preg_replace('/[^a-z0-9_]/', '', (string)($in['key'] ?? ''));
    if ($k === '') $__err('Clave inválida');
    if (in_array($k, ['access_schema_version'], true)) $__err('Esa clave la maneja el sistema');
    g7_cfg_set($k, (string)($in['value'] ?? ''));
    g7_audit('config.raw_save', $k, ['value' => mb_substr((string)($in['value'] ?? ''), 0, 300)], $__me);
    $__ok();

case 'config_raw_delete':
    $k = (string)($in['key'] ?? '');
    if (in_array($k, ['access_schema_version'], true)) $__err('Esa clave la maneja el sistema');
    $db->prepare("DELETE FROM g7_config WHERE cfg_key=?")->execute([$k]);
    g7_audit('config.raw_delete', $k, null, $__me);
    $__ok();

// ── Respaldo de configuración ─────────────────────────────────
case 'config_export':
    $export = [
        'format' => 'g7-toolz-config', 'version' => 1, 'exported_at' => date('c'),
        'config' => $db->query("SELECT cfg_key, cfg_value FROM g7_config WHERE cfg_key <> 'access_schema_version'")->fetchAll(PDO::FETCH_KEY_PAIR),
        'brands' => $db->query("SELECT id, slug, name, color, logo_url, keywords, sort_order, active FROM g7_brands")->fetchAll(),
        'brand_access' => $db->query("SELECT subject_type, subject_id, brand_id FROM g7_brand_access")->fetchAll(),
        'team_permissions' => $db->query("SELECT team_id, module, action FROM g7_team_permissions")->fetchAll(),
        'user_permissions' => $db->query("SELECT user_id, module, action, effect FROM g7_user_permissions")->fetchAll(),
        'teams' => $db->query("SELECT id, name, slug, color, description, active FROM g7_teams")->fetchAll(),
        'team_members' => $db->query("SELECT team_id, user_id, role, is_primary FROM g7_team_members")->fetchAll(),
    ];
    g7_audit('config.export', '', null, $__me);
    $__ok(['data' => $export]);

case 'config_import':
    $data = $in['data'] ?? null; $parts = (array)($in['parts'] ?? []);
    if (!is_array($data) || ($data['format'] ?? '') !== 'g7-toolz-config') $__err('El archivo no es un respaldo de configuración de Toolz');
    $db->beginTransaction();
    try {
        if (in_array('config', $parts, true)) {
            $st = $db->prepare("INSERT INTO g7_config (cfg_key, cfg_value) VALUES (?,?) ON DUPLICATE KEY UPDATE cfg_value=VALUES(cfg_value)");
            foreach ((array)$data['config'] as $k => $v) if (preg_match('/^[a-z0-9_]+$/', $k) && $k !== 'access_schema_version') $st->execute([$k, $v]);
        }
        if (in_array('brands', $parts, true)) {
            $db->exec("DELETE FROM g7_brand_access"); $db->exec("DELETE FROM g7_brands");
            $st = $db->prepare("INSERT INTO g7_brands (id, slug, name, color, logo_url, keywords, sort_order, active) VALUES (?,?,?,?,?,?,?,?)");
            foreach ((array)$data['brands'] as $b) $st->execute([(int)$b['id'], $b['slug'], $b['name'], $b['color'], $b['logo_url'] ?? '', $b['keywords'], (int)$b['sort_order'], (int)$b['active']]);
            $st = $db->prepare("INSERT IGNORE INTO g7_brand_access (subject_type, subject_id, brand_id) VALUES (?,?,?)");
            foreach ((array)$data['brand_access'] as $a) $st->execute([$a['subject_type'], (int)$a['subject_id'], (int)$a['brand_id']]);
        }
        if (in_array('permissions', $parts, true)) {
            $db->exec("DELETE FROM g7_team_permissions"); $db->exec("DELETE FROM g7_user_permissions");
            $st = $db->prepare("INSERT IGNORE INTO g7_team_permissions (team_id, module, action) VALUES (?,?,?)");
            foreach ((array)$data['team_permissions'] as $p) $st->execute([(int)$p['team_id'], $p['module'], $p['action']]);
            $st = $db->prepare("INSERT IGNORE INTO g7_user_permissions (user_id, module, action, effect) VALUES (?,?,?,?)");
            foreach ((array)$data['user_permissions'] as $p) $st->execute([(int)$p['user_id'], $p['module'], $p['action'], $p['effect']]);
        }
        if (in_array('teams', $parts, true)) {
            $st = $db->prepare("INSERT INTO g7_teams (id, name, slug, color, description, active, created_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), color=VALUES(color), description=VALUES(description), active=VALUES(active)");
            foreach ((array)$data['teams'] as $t) $st->execute([(int)$t['id'], $t['name'], $t['slug'], $t['color'], $t['description'], (int)$t['active'], $__me]);
            $db->exec("DELETE FROM g7_team_members");
            $st = $db->prepare("INSERT IGNORE INTO g7_team_members (team_id, user_id, role, is_primary) VALUES (?,?,?,?)");
            foreach ((array)$data['team_members'] as $m) $st->execute([(int)$m['team_id'], (int)$m['user_id'], $m['role'], (int)$m['is_primary']]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); $__err('No se pudo importar: ' . $e->getMessage()); }
    g7_audit('config.import', implode(',', $parts), ['exported_at' => $data['exported_at'] ?? null], $__me);
    $__ok();
}
// Acción desconocida para este archivo: admin.php sigue con las suyas.
