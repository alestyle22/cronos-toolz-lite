<?php
/**
 * social/profile_lib.php — Profile Studio (Social Studio › Perfiles): profile pictures for every network.
 * Stage 1: editor without AI. The image is drawn in the browser (perfiles/app.js) from a "state" (JSON: photo, adjustments,
 * background, frame, effects, size); the server keeps the projects of each person (PRIVATE: only their author sees them),
 * the images they use (photo, photo without background, own backgrounds, thumbnail) and a history of versions.
 * Files: g7_ss_files with pf_user_id (served by social_file.php only to their owner). Doc: toolz/SOCIAL_STUDIO.md (Profile Studio)
 */
require_once __DIR__ . '/lib.php';

const SS_PF_EXT = ['jpg', 'jpeg', 'png', 'webp'];
const SS_PF_FILE_MAX = 15 * 1024 * 1024;     // per image (the browser already shrinks the photos before uploading)
const SS_PF_FILES_USER = 800;                // images per person
const SS_PF_PROJECTS_USER = 300;             // projects per person
const SS_PF_STATE_MAX = 200000;              // bytes of the JSON of a project
const SS_PF_VERSIONS = 30;                   // versions kept per project

function ss_pf_need(array $ctx): void {
    if (!g7_can((int)$ctx['id'], 'social')) ss_fail('No tenés acceso a Social Studio.', 403);
    if (function_exists('ss_plan_allows') && !ss_plan_allows('social_profiles')) ss_plan_fail('Profile Studio');
}
/** Files of Profile Studio live in _private/social/pf/<user>/ */
function ss_pf_url(string $token): string { return '/toolz/social_file.php?t=' . $token; }

// ═══════════════════════════════════════════════════════════
// IMAGES
// ═══════════════════════════════════════════════════════════
/** Uploads an image of the person. $kind: photo | cutout | bg | thumb. Returns ['id', 'token', 'url', 'w', 'h']. */
function ss_pf_upload(PDO $db, array $ctx, $f, string $kind): array {
    ss_pf_need($ctx);
    if (!in_array($kind, ['photo', 'cutout', 'bg', 'thumb'], true)) ss_fail('Tipo de imagen desconocido.');
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) ss_fail('Elegí una imagen.');
    if ($pr = g7_upload_problem($f)) ss_fail($pr);
    $ext = g7_upload_ext((string)$f['name'], SS_PF_EXT);
    if ($ext === null) ss_fail('Profile Studio acepta JPG, PNG o WebP.');
    if ((int)$f['size'] > SS_PF_FILE_MAX) ss_fail('La imagen pesa más de 15 MB.');
    $info = @getimagesize($f['tmp_name']);
    if ($info === false || $info[0] < 16 || $info[1] < 16) ss_fail('Esa imagen está dañada o es demasiado chica.');
    if ($info[0] * $info[1] > 40000000) ss_fail('La imagen es enorme (más de 40 megapíxeles): achicala antes de subirla.');
    $q = $db->prepare('SELECT COUNT(*) FROM g7_ss_files WHERE pf_user_id = ?'); $q->execute([$ctx['id']]);
    if ((int)$q->fetchColumn() >= SS_PF_FILES_USER) ss_fail('Llegaste al máximo de imágenes de Profile Studio: borrá proyectos que no uses.');
    if (!ss_rate_ok($db, 'pfup:' . $ctx['id'], 120, 600)) ss_fail('Subiste muchas imágenes seguidas: esperá unos minutos.', 429);
    if (function_exists('lic_can_upload') && ($m = lic_can_upload((int)$f['size']))) ss_fail($m, 403);
    if (!is_dir(ss_files_dir())) @mkdir(ss_files_dir(), 0755, true);
    if (!is_file(ss_files_dir() . '.htaccess')) @file_put_contents(ss_files_dir() . '.htaccess', "Require all denied\nphp_flag engine off\n");
    $token = bin2hex(random_bytes(16));
    $dir = ss_files_dir() . 'pf/' . (int)$ctx['id'] . '/';
    if ($err = g7_upload_store($f, $dir, $token . '.' . $ext)) ss_fail($err, 500);
    $name = $kind . '.' . $ext;
    $db->prepare('INSERT INTO g7_ss_files (post_id, token, name, ext, size, user_id, pf_user_id, created_at) VALUES (0, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$token, $name, $ext, (int)$f['size'], $ctx['id'], $ctx['id'], ss_now()]);
    return ['id' => (int)$db->lastInsertId(), 'token' => $token, 'url' => ss_pf_url($token), 'w' => (int)$info[0], 'h' => (int)$info[1]];
}
/** Keeps bytes produced on the server (AI results) as an image of the person. */
function ss_pf_store_bytes(PDO $db, array $ctx, string $bin, string $ext, string $kind, int $w, int $h): array {
    $q = $db->prepare('SELECT COUNT(*) FROM g7_ss_files WHERE pf_user_id = ?'); $q->execute([$ctx['id']]);
    if ((int)$q->fetchColumn() >= SS_PF_FILES_USER) ss_fail('Llegaste al máximo de imágenes de Profile Studio: borrá proyectos que no uses.');
    if (function_exists('lic_can_upload') && ($m = lic_can_upload(strlen($bin)))) ss_fail($m, 403);
    $token = bin2hex(random_bytes(16));
    $dir = ss_files_dir() . 'pf/' . (int)$ctx['id'] . '/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_file(ss_files_dir() . '.htaccess')) @file_put_contents(ss_files_dir() . '.htaccess', "Require all denied\nphp_flag engine off\n");
    if (@file_put_contents($dir . $token . '.' . $ext, $bin) === false) ss_fail('No se pudo guardar la imagen.', 500);
    $db->prepare('INSERT INTO g7_ss_files (post_id, token, name, ext, size, user_id, pf_user_id, created_at) VALUES (0, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$token, $kind . '.' . $ext, $ext, strlen($bin), $ctx['id'], $ctx['id'], ss_now()]);
    return ['id' => (int)$db->lastInsertId(), 'token' => $token, 'url' => ss_pf_url($token), 'w' => $w, 'h' => $h];
}
/** Tokens of images mentioned in a state (any 32-hex value under keys that end in "token"). */
function ss_pf_state_tokens($s, array &$out = []): array {
    if (is_array($s)) foreach ($s as $k => $v) {
        if (is_string($v) && is_string($k) && str_ends_with(strtolower($k), 'token') && preg_match('/^[a-f0-9]{32}$/', $v)) $out[$v] = true;
        elseif (is_array($v)) ss_pf_state_tokens($v, $out);
    }
    return array_keys($out);
}

// ═══════════════════════════════════════════════════════════
// PROJECTS (private of each person)
// ═══════════════════════════════════════════════════════════
function ss_pf_row(PDO $db, array $ctx, int $id): array {
    ss_pf_need($ctx);
    $q = $db->prepare('SELECT * FROM g7_ss_pf_projects WHERE id = ? AND user_id = ? AND deleted_at IS NULL'); $q->execute([$id, $ctx['id']]);
    $p = $q->fetch(PDO::FETCH_ASSOC);
    if (!$p) ss_fail('Ese proyecto no existe o no es tuyo.', 404);
    return $p;
}
function ss_pf_out(array $p, bool $full = false): array {
    $o = ['id' => (int)$p['id'], 'name' => $p['name'], 'favorite' => (bool)$p['favorite'], 'shared' => (bool)($p['shared'] ?? false), 'thumb' => $p['thumb_token'] ? ss_pf_url($p['thumb_token']) : null,
          'preset' => (string)$p['preset'], 'created_at' => $p['created_at'], 'updated_at' => $p['updated_at']];
    if ($full) $o['state'] = json_decode((string)$p['state'], true) ?: new stdClass();
    return $o;
}
function ss_pf_list(PDO $db, array $ctx): array {
    ss_pf_need($ctx);
    $q = $db->prepare('SELECT * FROM g7_ss_pf_projects WHERE user_id = ? AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 500'); $q->execute([$ctx['id']]);
    return array_map('ss_pf_out', $q->fetchAll(PDO::FETCH_ASSOC));
}
/** Checks a state and the images it uses (only the person's own). Returns the JSON to store. */
function ss_pf_state_json(PDO $db, array $ctx, $state): string {
    if (!is_array($state)) ss_fail('El proyecto vino vacío.');
    $j = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($j === false || strlen($j) > SS_PF_STATE_MAX) ss_fail('El proyecto es demasiado grande para guardarlo.');
    $toks = ss_pf_state_tokens($state);
    if ($toks) {
        $in = implode(',', array_fill(0, count($toks), '?'));
        $q = $db->prepare("SELECT COUNT(*) FROM g7_ss_files WHERE pf_user_id = ? AND token IN ($in)"); $q->execute([$ctx['id'], ...$toks]);
        if ((int)$q->fetchColumn() !== count($toks)) ss_fail('El proyecto usa imágenes que no son tuyas.', 403);
    }
    return $j;
}
/** Creates ($id = 0) or saves a project. $in: name, state, thumb_token, preset. Returns the id. */
function ss_pf_save(PDO $db, array $ctx, int $id, array $in): int {
    ss_pf_need($ctx);
    if ($id) ss_pf_row($db, $ctx, $id);   // first: the project has to be the person's
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120) ?: 'Imagen de perfil';
    $json = ss_pf_state_json($db, $ctx, $in['state'] ?? null);
    $thumb = (string)($in['thumb_token'] ?? '');
    if ($thumb !== '') { $q = $db->prepare('SELECT 1 FROM g7_ss_files WHERE pf_user_id = ? AND token = ?'); $q->execute([$ctx['id'], $thumb]); if (!$q->fetchColumn()) $thumb = ''; }
    $preset = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($in['preset'] ?? ''))) ?: '';
    $now = ss_now();
    if (!$id) {
        $q = $db->prepare('SELECT COUNT(*) FROM g7_ss_pf_projects WHERE user_id = ? AND deleted_at IS NULL'); $q->execute([$ctx['id']]);
        if ((int)$q->fetchColumn() >= SS_PF_PROJECTS_USER) ss_fail('Llegaste al máximo de proyectos: borrá los que no uses.');
        $db->prepare('INSERT INTO g7_ss_pf_projects (user_id, name, state, thumb_token, preset, favorite, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, ?, ?)')
           ->execute([$ctx['id'], $name, $json, $thumb ?: null, $preset, $now, $now]);
        $id = (int)$db->lastInsertId();
        ss_pf_version_add($db, $id, $json, 'Creado');
        return $id;
    }
    $p = ss_pf_row($db, $ctx, $id);
    $db->prepare('UPDATE g7_ss_pf_projects SET name = ?, state = ?, thumb_token = COALESCE(?, thumb_token), preset = ?, updated_at = ? WHERE id = ?')
       ->execute([$name, $json, $thumb ?: null, $preset, $now, $id]);
    if (!empty($in['version'])) ss_pf_version_add($db, $id, $json, mb_substr(trim((string)$in['version']), 0, 80) ?: 'Versión guardada');
    return (int)$p['id'];
}
function ss_pf_version_add(PDO $db, int $id, string $json, string $label): void {
    $db->prepare('INSERT INTO g7_ss_pf_versions (project_id, state, label, created_at) VALUES (?, ?, ?, ?)')->execute([$id, $json, $label, ss_now()]);
    $keep = $db->prepare('SELECT id FROM g7_ss_pf_versions WHERE project_id = ? ORDER BY id DESC LIMIT ' . SS_PF_VERSIONS); $keep->execute([$id]);
    $ids = $keep->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) $db->prepare('DELETE FROM g7_ss_pf_versions WHERE project_id = ? AND id NOT IN (' . implode(',', array_map('intval', $ids)) . ')')->execute([$id]);
}
function ss_pf_versions(PDO $db, array $ctx, int $id): array {
    ss_pf_row($db, $ctx, $id);
    $q = $db->prepare('SELECT id, label, created_at FROM g7_ss_pf_versions WHERE project_id = ? ORDER BY id DESC'); $q->execute([$id]);
    return array_map(fn($v) => ['id' => (int)$v['id'], 'label' => $v['label'], 'at' => $v['created_at']], $q->fetchAll(PDO::FETCH_ASSOC));
}
/** Goes back to a version (the current state is kept as a version first, so nothing is lost). */
function ss_pf_restore(PDO $db, array $ctx, int $id, int $versionId): array {
    $p = ss_pf_row($db, $ctx, $id);
    $q = $db->prepare('SELECT * FROM g7_ss_pf_versions WHERE id = ? AND project_id = ?'); $q->execute([$versionId, $id]);
    $v = $q->fetch(PDO::FETCH_ASSOC);
    if (!$v) ss_fail('Esa versión ya no está.', 404);
    ss_pf_version_add($db, $id, (string)$p['state'], 'Antes de volver a "' . $v['label'] . '"');
    $db->prepare('UPDATE g7_ss_pf_projects SET state = ?, updated_at = ? WHERE id = ?')->execute([$v['state'], ss_now(), $id]);
    return ss_pf_out(ss_pf_row($db, $ctx, $id), true);
}
function ss_pf_duplicate(PDO $db, array $ctx, int $id): int {
    $p = ss_pf_row($db, $ctx, $id);
    return ss_pf_save($db, $ctx, 0, ['name' => mb_substr($p['name'] . ' (copia)', 0, 120), 'state' => json_decode((string)$p['state'], true) ?: [], 'thumb_token' => (string)$p['thumb_token'], 'preset' => $p['preset']]);
}
function ss_pf_set(PDO $db, array $ctx, int $id, array $in): void {
    ss_pf_row($db, $ctx, $id);
    if (array_key_exists('shared', $in)) {
        if (!empty($in['shared']) && g7_cfg('ss_pf_gallery', '') !== '1') ss_fail('La galería de la agencia está apagada (la prende quien configura Social Studio).');
        $db->prepare('UPDATE g7_ss_pf_projects SET shared = ? WHERE id = ?')->execute([!empty($in['shared']) ? 1 : 0, $id]);
    }
    if (array_key_exists('favorite', $in)) $db->prepare('UPDATE g7_ss_pf_projects SET favorite = ? WHERE id = ?')->execute([!empty($in['favorite']) ? 1 : 0, $id]);
    if (isset($in['name'])) { $n = mb_substr(trim((string)$in['name']), 0, 120); if ($n === '') ss_fail('Poné un nombre.'); $db->prepare('UPDATE g7_ss_pf_projects SET name = ? WHERE id = ?')->execute([$n, $id]); }
}
/** Deletes a project. Its images are erased when no other project of the person uses them. */
function ss_pf_delete(PDO $db, array $ctx, int $id): void {
    $p = ss_pf_row($db, $ctx, $id);
    $db->prepare('UPDATE g7_ss_pf_projects SET deleted_at = ? WHERE id = ?')->execute([ss_now(), $id]);
    $db->prepare('DELETE FROM g7_ss_pf_versions WHERE project_id = ?')->execute([$id]);
    ss_pf_gc($db, (int)$ctx['id']);
}
// ═══════════════════════════════════════════════════════════
// AGENCY GALLERY (optional: Social Studio › Configurar turns it on). Only the thumbnail and the look are shared, never the photo.
// ═══════════════════════════════════════════════════════════
function ss_pf_gallery_on(): bool { return g7_cfg('ss_pf_gallery', '') === '1'; }
function ss_pf_gallery_set(PDO $db, array $ctx, bool $on): void {
    ss_need($ctx, 'config');
    g7_cfg_set('ss_pf_gallery', $on ? '1' : '');
    g7_audit('social.pf_gallery', $on ? 'on' : 'off', []);
}
function ss_pf_gallery(PDO $db, array $ctx): array {
    ss_pf_need($ctx);
    if (!ss_pf_gallery_on()) return [];
    $rows = $db->query("SELECT p.id, p.name, p.thumb_token, p.preset, p.updated_at, p.user_id, COALESCE(NULLIF(u.display_name,''), u.username) who
                        FROM g7_ss_pf_projects p JOIN g7_users u ON u.id = p.user_id WHERE p.shared = 1 AND p.deleted_at IS NULL AND p.thumb_token IS NOT NULL ORDER BY p.updated_at DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'thumb' => ss_pf_url($r['thumb_token']), 'preset' => $r['preset'], 'who' => $r['who'], 'mine' => (int)$r['user_id'] === (int)$ctx['id'], 'updated_at' => $r['updated_at']], $rows);
}
/** The look of a shared project (background, frame, effects, filter, adjustments, badge, shape) WITHOUT its images: to reuse it. */
function ss_pf_gallery_look(PDO $db, array $ctx, int $id): array {
    ss_pf_need($ctx);
    if (!ss_pf_gallery_on()) ss_fail('La galería de la agencia está apagada.', 404);
    $q = $db->prepare('SELECT state FROM g7_ss_pf_projects WHERE id = ? AND shared = 1 AND deleted_at IS NULL'); $q->execute([$id]);
    $s = json_decode((string)$q->fetchColumn(), true);
    if (!is_array($s)) ss_fail('Ese diseño ya no está en la galería.', 404);
    $look = array_intersect_key($s, array_flip(['preset', 'shape', 'adj', 'filter', 'bg', 'frame', 'fx', 'badge', 'subject', 'tpl']));
    if (isset($look['bg'])) { if (($look['bg']['type'] ?? '') === 'image') $look['bg']['type'] = 'gradient'; unset($look['bg']['token'], $look['bg']['w'], $look['bg']['h']); }
    return $look;
}

/** Erases the person's images that no live project (or its versions) uses and that are older than a day (not the ones being uploaded now). */
function ss_pf_gc(PDO $db, int $uid): int {
    $used = [];
    $q = $db->prepare('SELECT p.state, p.thumb_token FROM g7_ss_pf_projects p WHERE p.user_id = ? AND p.deleted_at IS NULL'); $q->execute([$uid]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { foreach (ss_pf_state_tokens(json_decode((string)$r['state'], true) ?: []) as $t) $used[$t] = true; if ($r['thumb_token']) $used[$r['thumb_token']] = true; }
    $q = $db->prepare('SELECT v.state FROM g7_ss_pf_versions v JOIN g7_ss_pf_projects p ON p.id = v.project_id WHERE p.user_id = ? AND p.deleted_at IS NULL'); $q->execute([$uid]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $s) foreach (ss_pf_state_tokens(json_decode((string)$s, true) ?: []) as $t) $used[$t] = true;
    $q = $db->prepare('SELECT * FROM g7_ss_files WHERE pf_user_id = ? AND created_at < NOW() - INTERVAL 1 DAY'); $q->execute([$uid]);
    $n = 0;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $f) {
        if (isset($used[$f['token']])) continue;
        @unlink(ss_file_path($f));
        $db->prepare('DELETE FROM g7_ss_files WHERE id = ?')->execute([(int)$f['id']]);
        $n++;
    }
    return $n;
}
