<?php
/**
 * social/lib.php — Social Studio, Etapa 1: Content Planner (publicaciones, estados configurables, calendario) y
 * "Convertir en ticket" (tickets reales de Trabajo, con vínculo de ida y vuelta y reglas de estado).
 * Todo permiso, alcance y validación se decide acá; api.php solo despacha y la pantalla solo muestra.
 * Doc: toolz/SOCIAL_STUDIO.md
 */

const SS_SCHEMA = '10';   // 2: accounts, OAuth, publishing, webhooks · 3: Inbox · 4: Analytics · 5: Report Maker · 6: TikTok, LinkedIn, X, YouTube · 7: central connection · 8: Profile Studio · 9: Profile Studio AI · 10: Profile Studio gallery

/** Redes: clave => [nombre, ícono de Font Awesome (fa-brands), color]. */
const SS_NETWORKS = [
    'instagram' => ['Instagram', 'fa-instagram', '#e1306c'],
    'facebook'  => ['Facebook', 'fa-facebook', '#1877f2'],
    'tiktok'    => ['TikTok', 'fa-tiktok', '#25f4ee'],
    'youtube'   => ['YouTube', 'fa-youtube', '#ff0033'],
    'linkedin'  => ['LinkedIn', 'fa-linkedin', '#0a66c2'],
    'x'         => ['X', 'fa-x-twitter', '#a1a1aa'],
    'threads'   => ['Threads', 'fa-threads', '#a1a1aa'],
    'pinterest' => ['Pinterest', 'fa-pinterest', '#e60023'],
];
const SS_FORMATS = ['imagen' => 'Imagen', 'carrusel' => 'Carrusel', 'video' => 'Video', 'reel' => 'Reel', 'historia' => 'Historia',
                    'texto' => 'Texto', 'video_corto' => 'Video corto', 'personalizado' => 'Personalizado'];
/**
 * Tipos de estado: cada estado configurable pertenece a uno. Las reglas y los permisos miran el tipo, no el nombre,
 * así la agencia puede renombrar, recolorear, ordenar o sumar estados sin romper nada.
 * rank: avance editorial (las reglas automáticas nunca bajan de rank). null = las reglas no lo tocan.
 */
const SS_KINDS = [
    'idea'      => ['Idea', 0],
    'pending'   => ['Pendiente', 1],
    'design'    => ['En diseño', 2],
    'video'     => ['En producción de video', 2],
    'changes'   => ['Requiere cambios', 2],
    'review'    => ['En revisión', 3],
    'approved'  => ['Aprobado', 4],
    'scheduled' => ['Programado', 5],
    'published' => ['Publicado', 6],
    'cancelled' => ['Cancelado', 7],
    'custom'    => ['Propio', null],
];
/** Estados que ninguna regla automática toca: ahí manda la decisión editorial. */
const SS_PROTECTED = ['approved', 'scheduled', 'published', 'cancelled'];
/** Permiso que hace falta para llevar una publicación a (o sacarla de) un estado de este tipo. */
const SS_KIND_PERM = ['approved' => 'approve', 'scheduled' => 'schedule', 'published' => 'publish'];
const SS_DEFAULT_STATUSES = [
    ['idea', 'Idea', '#a78bfa'], ['pending', 'Pendiente', '#94a3b8'], ['design', 'En diseño', '#3b82f6'],
    ['video', 'En producción de video', '#06b6d4'], ['review', 'En revisión', '#f59e0b'], ['changes', 'Requiere cambios', '#ef4444'],
    ['approved', 'Aprobado', '#22c55e'], ['scheduled', 'Programado', '#14b8a6'], ['published', 'Publicado', '#10b981'], ['cancelled', 'Cancelado', '#52525b'],
];
/** Tipos de ticket que se crean desde una publicación (el nombre va en el asunto). */
const SS_TICKET_KINDS = ['diseno' => 'Diseño', 'video' => 'Video', 'copy' => 'Copy', 'otro' => 'Otro'];
/** Estados de ticket de Trabajo que cuentan como terminados. */
const SS_TK_DONE = ['resuelto', 'cerrado'];
const SS_FILE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp4', 'webm', 'mov', 'm4v', 'mp3', 'wav', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'zip', 'psd', 'ai', 'fig', 'eps', 'svg'];
const SS_FILE_MAX = 200 * 1024 * 1024;
const SS_MIME = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
                 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'm4v' => 'video/mp4', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'pdf' => 'application/pdf'];

class SsError extends RuntimeException {}
function ss_fail(string $msg, int $code = 400): void { throw new SsError($msg, $code); }

// ═══════════════════════════════════════════════════════════
// INSTALACIÓN
// ═══════════════════════════════════════════════════════════
function ss_install(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('g7_cfg') && g7_cfg('ss_schema') === SS_SCHEMA) return;
    $T = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_status (
        id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(12) NOT NULL, name VARCHAR(40) NOT NULL, color CHAR(7) NOT NULL DEFAULT '#94a3b8',
        sort INT NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_posts (
        id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, description TEXT NULL, idea TEXT NULL,
        pub_date DATE NULL, pub_time TIME NULL, brand_id INT NULL, client_id INT NULL, networks VARCHAR(120) NOT NULL DEFAULT '',
        format VARCHAR(20) NOT NULL DEFAULT 'imagen', format_custom VARCHAR(60) NOT NULL DEFAULT '', owner_id INT NULL, status_id INT NOT NULL,
        copy TEXT NULL, hashtags TEXT NULL, links TEXT NULL, refs TEXT NULL, notes TEXT NULL, tags VARCHAR(500) NOT NULL DEFAULT '',
        planify_id INT NULL, created_by INT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME NULL,
        KEY pub (pub_date), KEY brand (brand_id), KEY client (client_id), KEY owner (owner_id), KEY status (status_id), UNIQUE KEY planify (planify_id)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_files (
        id INT AUTO_INCREMENT PRIMARY KEY, post_id INT NOT NULL, token CHAR(32) NOT NULL, name VARCHAR(200) NOT NULL, ext VARCHAR(8) NOT NULL,
        size BIGINT NOT NULL DEFAULT 0, user_id INT NULL, created_at DATETIME NOT NULL, UNIQUE KEY token (token), KEY post (post_id)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_tickets (
        post_id INT NOT NULL, ticket_id INT NOT NULL, kind VARCHAR(12) NOT NULL DEFAULT 'otro', last_status VARCHAR(20) NOT NULL DEFAULT 'abierto',
        created_by INT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (post_id, ticket_id), KEY ticket (ticket_id)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_log (
        id INT AUTO_INCREMENT PRIMARY KEY, post_id INT NOT NULL, user_id INT NULL, kind VARCHAR(16) NOT NULL, body VARCHAR(600) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL, KEY post (post_id, id)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_rate (k VARCHAR(80) NOT NULL, at INT NOT NULL, KEY k (k, at)) $T");
    // Etapa 2: cuentas de redes (tokens cifrados), conexiones OAuth en curso, destinos de publicación, webhooks y registro de llamadas
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY, provider VARCHAR(12) NOT NULL, kind VARCHAR(16) NOT NULL, ext_id VARCHAR(64) NOT NULL, name VARCHAR(190) NOT NULL DEFAULT '',
        username VARCHAR(120) NOT NULL DEFAULT '', picture VARCHAR(1000) NOT NULL DEFAULT '', page_id VARCHAR(64) NOT NULL DEFAULT '', brand_id INT NULL, client_id INT NULL,
        token_enc TEXT NULL, token_expires DATETIME NULL, scopes TEXT NULL, status VARCHAR(14) NOT NULL DEFAULT 'ok', status_msg VARCHAR(300) NOT NULL DEFAULT '',
        user_ext_id VARCHAR(64) NOT NULL DEFAULT '', subscribed TINYINT(1) NOT NULL DEFAULT 0, connected_by INT NULL, connected_at DATETIME NOT NULL, last_check_at DATETIME NULL,
        last_sync_at DATETIME NULL, updated_at DATETIME NOT NULL, UNIQUE KEY ext (provider, kind, ext_id), KEY brand (brand_id), KEY client (client_id)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_oauth (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, provider VARCHAR(12) NOT NULL, state_hash CHAR(64) NOT NULL, nonce_hash CHAR(64) NOT NULL,
        payload_enc MEDIUMTEXT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL, UNIQUE KEY st (state_hash)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_targets (
        id INT AUTO_INCREMENT PRIMARY KEY, post_id INT NOT NULL, account_id INT NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'scheduled', run_at DATETIME NULL,
        container_id VARCHAR(64) NOT NULL DEFAULT '', children TEXT NULL, ext_id VARCHAR(64) NOT NULL DEFAULT '', permalink VARCHAR(1000) NOT NULL DEFAULT '', error VARCHAR(600) NOT NULL DEFAULT '',
        attempts INT NOT NULL DEFAULT 0, created_by INT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, published_at DATETIME NULL,
        UNIQUE KEY pa (post_id, account_id), KEY due (status, run_at)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_webhook (
        id INT AUTO_INCREMENT PRIMARY KEY, provider VARCHAR(12) NOT NULL, object VARCHAR(40) NOT NULL DEFAULT '', payload MEDIUMTEXT NOT NULL,
        received_at DATETIME NOT NULL, processed_at DATETIME NULL, KEY pend (processed_at)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_sync_log (
        id INT AUTO_INCREMENT PRIMARY KEY, account_id INT NULL, action VARCHAR(40) NOT NULL, ok TINYINT(1) NOT NULL, http INT NOT NULL DEFAULT 0, msg VARCHAR(600) NOT NULL DEFAULT '',
        at DATETIME NOT NULL, KEY acc (account_id, id), KEY at (at)
    ) $T");
    // Stage 3: Inbox. A conversation = a DM thread or a top-level comment with its replies.
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_conv (
        id INT AUTO_INCREMENT PRIMARY KEY, account_id INT NOT NULL, kind VARCHAR(8) NOT NULL, thread VARCHAR(120) NOT NULL,
        contact_id VARCHAR(64) NOT NULL DEFAULT '', contact_name VARCHAR(190) NOT NULL DEFAULT '', contact_username VARCHAR(120) NOT NULL DEFAULT '', contact_pic VARCHAR(1000) NOT NULL DEFAULT '',
        post_id VARCHAR(80) NOT NULL DEFAULT '', post_link VARCHAR(1000) NOT NULL DEFAULT '', post_text VARCHAR(300) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'open', priority VARCHAR(10) NOT NULL DEFAULT 'normal', assignee_id INT NULL, team_id INT NULL, tags VARCHAR(500) NOT NULL DEFAULT '',
        unread INT NOT NULL DEFAULT 0, last_msg_at DATETIME NULL, last_in_at DATETIME NULL, last_text VARCHAR(300) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        UNIQUE KEY th (account_id, kind, thread), KEY last (last_msg_at), KEY assignee (assignee_id), KEY team (team_id), KEY status (status)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_msgs (
        id INT AUTO_INCREMENT PRIMARY KEY, conv_id INT NOT NULL, dir VARCHAR(6) NOT NULL, ext_id VARCHAR(190) NULL, body TEXT NULL, attachments TEXT NULL,
        author VARCHAR(190) NOT NULL DEFAULT '', user_id INT NULL, state VARCHAR(10) NOT NULL DEFAULT 'ok', error VARCHAR(400) NOT NULL DEFAULT '', created_at DATETIME NOT NULL,
        UNIQUE KEY ext (conv_id, ext_id), KEY conv (conv_id, id)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_replies (
        id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(80) NOT NULL, body TEXT NOT NULL, category VARCHAR(40) NOT NULL DEFAULT '', brand_id INT NULL,
        uses INT NOT NULL DEFAULT 0, created_by INT NULL, updated_at DATETIME NOT NULL, KEY cat (category)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_conv_tickets (conv_id INT NOT NULL, ticket_id INT NOT NULL, created_by INT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (conv_id, ticket_id), KEY t (ticket_id)) $T");
    // Stage 4: Analytics. Daily value of each metric per account (only those the API gives), posts with their metrics and goals per brand or client.
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_metrics (account_id INT NOT NULL, day DATE NOT NULL, metric VARCHAR(48) NOT NULL, value BIGINT NOT NULL DEFAULT 0, PRIMARY KEY (account_id, metric, day), KEY day (day)) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_media (
        id INT AUTO_INCREMENT PRIMARY KEY, account_id INT NOT NULL, ext_id VARCHAR(80) NOT NULL, media_type VARCHAR(24) NOT NULL DEFAULT '', caption VARCHAR(500) NOT NULL DEFAULT '',
        permalink VARCHAR(1000) NOT NULL DEFAULT '', thumb VARCHAR(1000) NOT NULL DEFAULT '', published_at DATETIME NULL, post_id INT NULL, metrics TEXT NULL, synced_at DATETIME NULL,
        UNIQUE KEY ext (account_id, ext_id), KEY pub (published_at)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_goals (
        id INT AUTO_INCREMENT PRIMARY KEY, scope VARCHAR(8) NOT NULL DEFAULT 'brand', scope_id INT NOT NULL DEFAULT 0, metric VARCHAR(48) NOT NULL, target BIGINT NOT NULL,
        created_by INT NULL, updated_at DATETIME NOT NULL, UNIQUE KEY g (scope, scope_id, metric)
    ) $T");
    // Stage 5: Report Maker. Each report saves a "snapshot" of the data at the moment it is built (the history does not change on its own).
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_reports (
        id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(160) NOT NULL, scope VARCHAR(8) NOT NULL DEFAULT 'brand', scope_id INT NOT NULL DEFAULT 0,
        date_from DATE NOT NULL, date_to DATE NOT NULL, compare VARCHAR(6) NOT NULL DEFAULT 'prev', template_id INT NULL, sections TEXT NULL, texts MEDIUMTEXT NULL, snapshot MEDIUMTEXT NULL,
        status VARCHAR(8) NOT NULL DEFAULT 'draft', token CHAR(40) NULL, auto TINYINT(1) NOT NULL DEFAULT 0, sent_to VARCHAR(1000) NOT NULL DEFAULT '', sent_at DATETIME NULL,
        built_at DATETIME NULL, created_by INT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE KEY token (token), KEY scope (scope, scope_id)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_report_tpl (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, sections TEXT NOT NULL, metric VARCHAR(48) NOT NULL DEFAULT 'interactions', intro TEXT NULL,
        color CHAR(7) NOT NULL DEFAULT '', is_default TINYINT(1) NOT NULL DEFAULT 0, created_by INT NULL, updated_at DATETIME NOT NULL
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_report_sched (
        id INT AUTO_INCREMENT PRIMARY KEY, scope VARCHAR(8) NOT NULL DEFAULT 'brand', scope_id INT NOT NULL DEFAULT 0, template_id INT NULL, recipients VARCHAR(1000) NOT NULL DEFAULT '',
        day INT NOT NULL DEFAULT 2, active TINYINT(1) NOT NULL DEFAULT 1, last_month CHAR(7) NOT NULL DEFAULT '', created_by INT NULL, updated_at DATETIME NOT NULL
    ) $T");
    $db->exec("ALTER TABLE g7_ss_files ADD COLUMN IF NOT EXISTS conv_id INT NULL");   // files attached in the Inbox (post_id = 0)
    $db->exec("ALTER TABLE g7_ss_webhook ADD COLUMN IF NOT EXISTS note VARCHAR(200) NOT NULL DEFAULT ''");
    // Stage 6: options of each send (TikTok privacy, YouTube privacy...) and longer ids (LinkedIn URNs, TikTok publish ids)
    $db->exec("ALTER TABLE g7_ss_targets ADD COLUMN IF NOT EXISTS opts TEXT NULL");
    $db->exec("ALTER TABLE g7_ss_targets MODIFY container_id VARCHAR(160) NOT NULL DEFAULT '', MODIFY ext_id VARCHAR(160) NOT NULL DEFAULT ''");
    $db->exec("ALTER TABLE g7_ss_media MODIFY ext_id VARCHAR(160) NOT NULL");
    // Central connection (hub_lib.php): sites authorized by the central, routes of Meta notifications, nonces of signed requests
    $db->exec("ALTER TABLE g7_ss_oauth ADD COLUMN IF NOT EXISTS site_id INT NULL, ADD COLUMN IF NOT EXISTS client_state VARCHAR(64) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS pick_hash CHAR(64) NULL");
    $db->exec("ALTER TABLE g7_ss_accounts ADD COLUMN IF NOT EXISTS proof_enc TEXT NULL");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_hub_sites (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, origin VARCHAR(190) NOT NULL, secret_enc TEXT NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL, created_at DATETIME NOT NULL, last_seen_at DATETIME NULL, UNIQUE KEY origin (origin)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_hub_routes (
        site_id INT NOT NULL, kind VARCHAR(16) NOT NULL, ext_id VARCHAR(64) NOT NULL, at DATETIME NOT NULL, PRIMARY KEY (kind, ext_id), KEY site (site_id)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_hub_nonce (nonce CHAR(32) NOT NULL PRIMARY KEY, at DATETIME NOT NULL) $T");
    // Profile Studio (profile_lib.php): private projects of each person, their versions and their images (g7_ss_files.pf_user_id)
    $db->exec("ALTER TABLE g7_ss_files ADD COLUMN IF NOT EXISTS pf_user_id INT NULL, ADD INDEX IF NOT EXISTS pfu (pf_user_id)");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_pf_projects (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, name VARCHAR(120) NOT NULL, state MEDIUMTEXT NOT NULL, thumb_token CHAR(32) NULL, preset VARCHAR(30) NOT NULL DEFAULT '',
        favorite TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME NULL, KEY usr (user_id, deleted_at, updated_at)
    ) $T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_pf_ai (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, project_id INT NULL, op VARCHAR(40) NOT NULL, model VARCHAR(40) NOT NULL DEFAULT '', job VARCHAR(80) NOT NULL,
        kind VARCHAR(4) NOT NULL DEFAULT 'gen', prompt TEXT NULL, cost DECIMAL(10,2) NULL, status VARCHAR(10) NOT NULL DEFAULT 'PENDING', result TEXT NULL, error VARCHAR(300) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL, done_at DATETIME NULL, KEY usr (user_id, id), KEY st (status)
    ) $T");
    $db->exec("ALTER TABLE g7_ss_pf_projects ADD COLUMN IF NOT EXISTS shared TINYINT(1) NOT NULL DEFAULT 0");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ss_pf_versions (
        id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, state MEDIUMTEXT NOT NULL, label VARCHAR(80) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, KEY prj (project_id, id)
    ) $T");
    if (!(int)$db->query('SELECT COUNT(*) FROM g7_ss_status')->fetchColumn()) {
        $ins = $db->prepare('INSERT INTO g7_ss_status (kind, name, color, sort) VALUES (?, ?, ?, ?)');
        foreach (SS_DEFAULT_STATUSES as $i => [$k, $n, $c]) $ins->execute([$k, $n, $c, $i]);
    }
    if (function_exists('g7_cfg_set')) g7_cfg_set('ss_schema', SS_SCHEMA);
}

function ss_now(): string { return date('Y-m-d H:i:s'); }
function ss_url(string $hash = ''): string { return g7_site_origin() . '/toolz/social/' . ($hash !== '' ? '#' . $hash : ''); }

// ═══════════════════════════════════════════════════════════
// QUIÉN ES CADA UNO
// ═══════════════════════════════════════════════════════════
/** Contexto de la persona: permisos del módulo y alcance de marcas. */
function ss_ctx(PDO $db, int $uid): array {
    $q = $db->prepare('SELECT id, username, display_name, role FROM g7_users WHERE id = ?'); $q->execute([$uid]);
    $u = $q->fetch(PDO::FETCH_ASSOC) ?: ['username' => '', 'display_name' => '', 'role' => 'user'];
    $can = [];
    foreach (['edit', 'approve', 'schedule', 'publish', 'tickets', 'assign', 'inbox', 'reply', 'analytics', 'reports', 'accounts', 'config'] as $a) $can[$a] = g7_can($uid, 'social', $a);
    // The agency's plan (license from the base) can leave whole sections out: they disappear like a missing permission
    if (!ss_plan_allows('social_inbox')) { $can['inbox'] = false; $can['reply'] = false; }
    if (!ss_plan_allows('social_analytics')) $can['analytics'] = false;
    if (!ss_plan_allows('social_reports')) $can['reports'] = false;
    return ['id' => $uid, 'name' => (string)($u['display_name'] ?: $u['username']), 'admin' => $u['role'] === 'admin', 'can' => $can,
            'brands' => g7_user_brand_ids($uid),   // marcas que puede usar (null = todas)
            'scope' => g7_brand_scope($uid)];      // marcas que ve ahora (permitidas ∩ selector de la barra)
}
// ── Plan limits (license_lib.php: LIC_LIMITS; edited in the base, Red Cronos › Planes) ──
function ss_plan_allows(string $key): bool { return !function_exists('lic_allows') || lic_allows($key); }
function ss_plan_net_ok(string $net): bool { return !function_exists('lic_allows_item') || lic_allows_item('social_networks', $net); }
function ss_plan_fail(string $what): void { ss_fail(function_exists('lic_plan_msg') ? lic_plan_msg($what) : 'Tu plan no incluye ' . $what . '.', 403); }
/** Connected accounts that count for the plan's maximum. */
function ss_plan_accounts_used(PDO $db): int { return (int)$db->query("SELECT COUNT(*) FROM g7_ss_accounts WHERE token_enc IS NOT NULL AND token_enc <> ''")->fetchColumn(); }
/** What the plan allows in Social Studio (for the screen). null = no limit. */
function ss_plan(PDO $db): array {
    $cap = fn($k) => function_exists('lic_cap') ? lic_cap($k) : null;
    $nets = $cap('social_networks');
    return ['accounts' => $cap('social_accounts'), 'used' => ss_plan_accounts_used($db), 'networks' => is_array($nets) ? $nets : null,
            'publish' => ss_plan_allows('social_publish'), 'inbox' => ss_plan_allows('social_inbox'), 'analytics' => ss_plan_allows('social_analytics'),
            'reports' => ss_plan_allows('social_reports'), 'reports_auto' => ss_plan_allows('social_reports_auto'), 'ai' => ss_plan_allows('social_ai'), 'central' => ss_plan_allows('social_central'),
            'profiles' => ss_plan_allows('social_profiles'), 'profiles_ai' => ss_plan_allows('social_profiles_ai'),
            'limited' => function_exists('lic_get') && lic_get() !== null && !empty(lic_get()['limits'])];
}
function ss_need(array $ctx, string $action): void {
    static $msg = ['edit' => 'crear o editar publicaciones', 'approve' => 'aprobar publicaciones', 'schedule' => 'programar publicaciones',
                   'publish' => 'marcar publicaciones como publicadas', 'tickets' => 'convertir publicaciones en tickets',
                   'assign' => 'asignar publicaciones a otras personas', 'accounts' => 'conectar o desconectar cuentas de redes', 'inbox' => 'ver las conversaciones del Inbox', 'reply' => 'responder mensajes y comentarios', 'analytics' => 'ver las métricas', 'reports' => 'armar y enviar informes', 'config' => 'configurar Social Studio'];
    if (empty($ctx['can'][$action])) ss_fail('No tienes permiso para ' . ($msg[$action] ?? $action) . '. Lo da un admin en Admin › Módulos y permisos.', 403);
}

/** Agrupación de la agencia: 'brand' (marcas de Cronos) o 'client' (fichas de Clientes). La elige quien configura. */
function ss_group(): string {
    $g = (string)g7_cfg('ss_group', '');
    $opts = ss_group_options();
    if (isset($opts[$g])) return $g;
    return isset($opts['brand']) ? 'brand' : (isset($opts['client']) ? 'client' : 'brand');
}
function ss_group_options(): array {
    $o = [];
    if (g7_brands(true)) $o['brand'] = 'Por marca';
    if (g7_site_has('clientes')) $o['client'] = 'Por cliente';
    if (!$o) $o['brand'] = 'Por marca';
    return $o;
}
function ss_clients_on(PDO $db): bool {
    static $on = null;
    if ($on === null) $on = g7_site_has('clientes') && (bool)$db->query("SHOW TABLES LIKE 'g7_clients'")->fetchColumn();
    return $on;
}

/** Condición SQL de las publicaciones que ve esta persona (alias p). Sin marca: las ve todo el que entra al módulo. */
function ss_scope_sql(array $ctx): string {
    if (ss_group() !== 'brand' || $ctx['scope'] === null) return '1=1';
    $ids = array_map('intval', $ctx['scope']);
    return $ids ? '(p.brand_id IS NULL OR p.brand_id IN (' . implode(',', $ids) . '))' : 'p.brand_id IS NULL';
}

// ═══════════════════════════════════════════════════════════
// CATÁLOGOS (estados, marcas, clientes, personas)
// ═══════════════════════════════════════════════════════════
function ss_statuses(PDO $db, bool $all = false): array {
    $out = [];
    foreach ($db->query('SELECT * FROM g7_ss_status' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY sort, id') as $r)
        $out[(int)$r['id']] = ['id' => (int)$r['id'], 'kind' => $r['kind'], 'name' => $r['name'], 'color' => $r['color'], 'sort' => (int)$r['sort'], 'active' => (int)$r['active']];
    return $out;
}
function ss_status_of_kind(PDO $db, string $kind): ?int {
    foreach (ss_statuses($db) as $s) if ($s['kind'] === $kind) return $s['id'];
    return null;
}
function ss_brands_for(array $ctx): array {
    $out = [];
    foreach (g7_brands() as $b) if ($ctx['brands'] === null || in_array($b['id'], $ctx['brands'], true))
        $out[] = ['id' => $b['id'], 'name' => $b['name'], 'color' => $b['color'], 'logo' => (string)$b['logo_url']];
    return $out;
}
function ss_clients(PDO $db): array {
    if (!ss_clients_on($db)) return [];
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'status' => $r['status']],
        $db->query("SELECT id, name, status FROM g7_clients WHERE status <> 'terminado' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC));
}
function ss_people(PDO $db): array {
    $teams = [];
    foreach ($db->query('SELECT m.user_id, m.team_id FROM g7_team_members m JOIN g7_teams t ON t.id = m.team_id AND t.active = 1') as $r) $teams[(int)$r['user_id']][] = (int)$r['team_id'];
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'avatar' => (string)$r['avatar'], 'teams' => $teams[(int)$r['id']] ?? []],
        $db->query("SELECT id, COALESCE(NULLIF(display_name,''), username) AS name, avatar FROM g7_users WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC));
}
function ss_user_active(PDO $db, int $id): bool {
    $q = $db->prepare('SELECT 1 FROM g7_users WHERE id = ? AND active = 1'); $q->execute([$id]); return (bool)$q->fetchColumn();
}
function ss_user_name(PDO $db, ?int $id): string {
    if (!$id) return '';
    $q = $db->prepare("SELECT COALESCE(NULLIF(display_name,''), username) FROM g7_users WHERE id = ?"); $q->execute([$id]); return (string)$q->fetchColumn();
}

/** Todo lo que la pantalla necesita para arrancar. */
function ss_meta(PDO $db, array $ctx): array {
    $g = ss_group();
    return ['group' => $g, 'group_options' => ss_group_options(), 'statuses' => array_values(ss_statuses($db, true)), 'kinds' => array_map(fn($k) => $k[0], SS_KINDS),
            'networks' => array_map(fn($n) => ['name' => $n[0], 'icon' => $n[1], 'color' => $n[2]], SS_NETWORKS), 'formats' => SS_FORMATS,
            'connectable' => function_exists('ss_connectable_networks') ? ss_connectable_networks() : ['instagram', 'facebook'],
            'brands' => ss_brands_for($ctx), 'clients' => ss_clients($db), 'people' => ss_people($db), 'can' => $ctx['can'], 'me' => $ctx['id'],
            'ticket_kinds' => SS_TICKET_KINDS, 'rules' => ss_rules($db), 'plan' => ss_plan($db), 'planify' => ss_planify_available($db) && $ctx['can']['config']];
}

// ═══════════════════════════════════════════════════════════
// REGISTRO E HISTORIAL
// ═══════════════════════════════════════════════════════════
function ss_log(PDO $db, int $postId, ?int $uid, string $kind, string $body): void {
    $db->prepare('INSERT INTO g7_ss_log (post_id, user_id, kind, body, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$postId, $uid, $kind, mb_substr($body, 0, 600), ss_now()]);
}
function ss_history(PDO $db, int $postId): array {
    $q = $db->prepare("SELECT l.id, l.kind, l.body, l.created_at, l.user_id, COALESCE(NULLIF(u.display_name,''), u.username) AS who FROM g7_ss_log l LEFT JOIN g7_users u ON u.id = l.user_id WHERE l.post_id = ? ORDER BY l.id DESC LIMIT 200");
    $q->execute([$postId]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'kind' => $r['kind'], 'body' => $r['body'], 'at' => $r['created_at'], 'who' => (string)($r['who'] ?? '')], $q->fetchAll(PDO::FETCH_ASSOC));
}

/** Aviso en la campana (tipo social, target_id = publicación). Nunca a quien hizo la acción. */
function ss_notify(PDO $db, array $users, int $postId, ?int $from, string $body): void {
    $users = array_values(array_unique(array_filter(array_map('intval', $users), fn($u) => $u > 0 && $u !== (int)$from)));
    if (!$users) return;
    $ins = $db->prepare("INSERT INTO g7_notifications (user_id, from_id, type, target_id, profile_id, body) VALUES (?, ?, 'social', ?, NULL, ?)");
    foreach ($users as $u) try { $ins->execute([$u, $from ?: null, $postId, mb_substr($body, 0, 280)]); } catch (Throwable $e) {}
}
/** Quién puede aprobar esta publicación (permiso approve y ve su marca). */
function ss_approvers(PDO $db, array $post): array {
    $out = [];
    foreach ($db->query('SELECT id FROM g7_users WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        $uid = (int)$uid;
        if (!g7_can($uid, 'social', 'approve')) continue;
        if (ss_group() === 'brand' && !empty($post['brand_id'])) { $b = g7_user_brand_ids($uid); if ($b !== null && !in_array((int)$post['brand_id'], $b, true)) continue; }
        $out[] = $uid;
    }
    return $out;
}

/** Límite de pedidos por persona y acción (ventana deslizante). */
function ss_rate_ok(PDO $db, string $key, int $max, int $win): bool {
    $now = time();
    if (random_int(1, 50) === 1) $db->prepare('DELETE FROM g7_ss_rate WHERE at < ?')->execute([$now - 86400]);
    $q = $db->prepare('SELECT COUNT(*) FROM g7_ss_rate WHERE k = ? AND at > ?'); $q->execute([$key, $now - $win]);
    if ((int)$q->fetchColumn() >= $max) return false;
    $db->prepare('INSERT INTO g7_ss_rate (k, at) VALUES (?, ?)')->execute([$key, $now]);
    return true;
}

// ═══════════════════════════════════════════════════════════
// PUBLICACIONES
// ═══════════════════════════════════════════════════════════
function ss_post_row(PDO $db, int $id): ?array {
    $q = $db->prepare('SELECT * FROM g7_ss_posts WHERE id = ? AND deleted_at IS NULL'); $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
/** La publicación si esta persona la puede ver; si no, 404 (no se distingue de "no existe"). */
function ss_post_visible(PDO $db, array $ctx, int $id): array {
    $q = $db->prepare('SELECT p.* FROM g7_ss_posts p WHERE p.id = ? AND p.deleted_at IS NULL AND ' . ss_scope_sql($ctx)); $q->execute([$id]);
    $p = $q->fetch(PDO::FETCH_ASSOC);
    if (!$p) ss_fail('Esa publicación no existe o no está en tus marcas.', 404);
    return $p;
}
function ss_split(string $s): array { return array_values(array_filter(array_map('trim', explode(',', $s)), 'strlen')); }
function ss_json_list(?string $s): array { $a = json_decode((string)$s, true); return is_array($a) ? $a : []; }

/** Forma pública de una publicación (lista y calendario: $full = false). */
function ss_post_out(PDO $db, array $p, bool $full = false, ?array $tk = null): array {
    $o = ['id' => (int)$p['id'], 'title' => $p['title'], 'date' => $p['pub_date'], 'time' => $p['pub_time'] ? substr($p['pub_time'], 0, 5) : null,
          'brand_id' => $p['brand_id'] ? (int)$p['brand_id'] : null, 'client_id' => $p['client_id'] ? (int)$p['client_id'] : null,
          'networks' => ss_split($p['networks']), 'format' => $p['format'], 'format_custom' => $p['format_custom'],
          'owner_id' => $p['owner_id'] ? (int)$p['owner_id'] : null, 'status_id' => (int)$p['status_id'], 'tags' => ss_split($p['tags']),
          'tickets' => $tk ?? ['total' => 0, 'done' => 0], 'updated_at' => $p['updated_at']];
    if ($full) $o += ['description' => (string)$p['description'], 'idea' => (string)$p['idea'], 'copy' => (string)$p['copy'], 'hashtags' => (string)$p['hashtags'],
                      'links' => ss_json_list($p['links']), 'refs' => ss_json_list($p['refs']), 'notes' => (string)$p['notes'],
                      'created_by' => ss_user_name($db, $p['created_by'] ? (int)$p['created_by'] : null), 'created_at' => $p['created_at']];
    return $o;
}

/**
 * Lista con filtros. $f: from, to (Y-m-d; si faltan, todas), undated (bool: solo sin fecha), brand, client, network, owner, status, q.
 */
function ss_posts_list(PDO $db, array $ctx, array $f): array {
    $w = ['p.deleted_at IS NULL', ss_scope_sql($ctx)]; $a = [];
    $d = fn($x) => is_string($x) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $x) ? $x : null;
    if (!empty($f['undated'])) $w[] = 'p.pub_date IS NULL';
    else {
        if ($from = $d($f['from'] ?? null)) { $w[] = 'p.pub_date >= ?'; $a[] = $from; }
        if ($to = $d($f['to'] ?? null)) { $w[] = 'p.pub_date <= ?'; $a[] = $to; }
    }
    foreach (['brand' => 'brand_id', 'client' => 'client_id', 'owner' => 'owner_id', 'status' => 'status_id'] as $k => $col) {
        if (!isset($f[$k]) || $f[$k] === '' || $f[$k] === null) continue;
        if ($f[$k] === 'none') { $w[] = "p.$col IS NULL"; continue; }
        $ids = array_values(array_filter(array_map('intval', (array)$f[$k])));
        if ($ids) $w[] = "p.$col IN (" . implode(',', $ids) . ')';
    }
    if (!empty($f['network']) && isset(SS_NETWORKS[$f['network']])) { $w[] = 'FIND_IN_SET(?, p.networks)'; $a[] = $f['network']; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
        $w[] = '(p.title LIKE ? OR p.copy LIKE ? OR p.description LIKE ? OR p.idea LIKE ? OR p.tags LIKE ? OR p.hashtags LIKE ?)';
        array_push($a, $like, $like, $like, $like, $like, $like);
    }
    $sql = 'SELECT p.* FROM g7_ss_posts p WHERE ' . implode(' AND ', $w) . ' ORDER BY p.pub_date IS NULL, p.pub_date, p.pub_time IS NULL, p.pub_time, p.id LIMIT 1500';
    $q = $db->prepare($sql); $q->execute($a);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    if (ss_sync_tickets($db, $ids)) { $q->execute($a); $rows = $q->fetchAll(PDO::FETCH_ASSOC); }   // cambió algún ticket: se relee por si una regla movió algo
    $tk = ss_ticket_counts($db, $ids);
    return array_map(fn($r) => ss_post_out($db, $r, false, $tk[(int)$r['id']] ?? null), $rows);
}

/** Valida y normaliza lo que llega del formulario. Devuelve las columnas a guardar. */
function ss_post_clean(PDO $db, array $ctx, array $in, ?array $old): array {
    $s = fn($k, $max) => mb_substr(trim((string)($in[$k] ?? '')), 0, $max);
    $c = [];
    $c['title'] = $s('title', 200);
    if ($c['title'] === '') ss_fail('Ponle un título a la publicación.');
    foreach (['description' => 5000, 'idea' => 5000, 'copy' => 10000, 'hashtags' => 2000, 'notes' => 10000] as $k => $max) $c[$k] = $s($k, $max);
    $date = trim((string)($in['date'] ?? ''));
    if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4)))) ss_fail('La fecha no es válida.');
    $time = trim((string)($in['time'] ?? ''));
    if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) ss_fail('La hora no es válida (HH:MM).');
    $c['pub_date'] = $date ?: null; $c['pub_time'] = $date && $time ? $time . ':00' : null;
    // Marca / cliente
    $bid = (int)($in['brand_id'] ?? 0); $cid = (int)($in['client_id'] ?? 0);
    if ($bid) {
        if (!isset(g7_brands()[$bid])) ss_fail('Esa marca no existe o está inactiva.');
        $keep = $old && (int)$old['brand_id'] === $bid;
        if (!$keep && $ctx['brands'] !== null && !in_array($bid, $ctx['brands'], true)) ss_fail('No tienes acceso a esa marca.', 403);
    }
    if ($cid) {
        if (!ss_clients_on($db)) ss_fail('Este sitio no usa Clientes.');
        $q = $db->prepare('SELECT 1 FROM g7_clients WHERE id = ?'); $q->execute([$cid]);
        if (!$q->fetchColumn()) ss_fail('Ese cliente no existe.');
    }
    if (ss_group() === 'brand' && !$bid && !empty($ctx['brands'])) ss_fail('Elige una de tus marcas.');   // quien tiene marcas asignadas trabaja dentro de ellas
    $c['brand_id'] = $bid ?: null; $c['client_id'] = $cid ?: null;
    // Redes, formato
    $nets = array_values(array_unique(array_filter((array)($in['networks'] ?? []), fn($n) => is_string($n) && isset(SS_NETWORKS[$n]))));
    $c['networks'] = implode(',', $nets);
    $fmt = (string)($in['format'] ?? 'imagen');
    if (!isset(SS_FORMATS[$fmt])) ss_fail('Elige un formato de la lista.');
    $c['format'] = $fmt; $c['format_custom'] = $fmt === 'personalizado' ? $s('format_custom', 60) : '';
    if ($fmt === 'personalizado' && $c['format_custom'] === '') ss_fail('Escribe cuál es el formato personalizado.');
    // Responsable
    $owner = (int)($in['owner_id'] ?? 0);
    if ($owner && !ss_user_active($db, $owner)) ss_fail('Esa persona no existe o está inactiva.');
    $c['owner_id'] = $owner ?: null;
    // Enlaces, referencias, etiquetas
    $links = [];
    foreach (array_slice((array)($in['links'] ?? []), 0, 20) as $l) {
        $u = trim((string)(is_array($l) ? ($l['url'] ?? '') : $l));
        if ($u === '') continue;
        if (!preg_match('#^https?://#i', $u) || !filter_var($u, FILTER_VALIDATE_URL)) ss_fail('Un enlace no es válido: ' . mb_substr($u, 0, 80));
        $links[] = ['url' => mb_substr($u, 0, 1000), 'label' => mb_substr(trim((string)(is_array($l) ? ($l['label'] ?? '') : '')), 0, 120)];
    }
    $c['links'] = json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $refs = [];
    foreach (array_slice((array)($in['refs'] ?? []), 0, 30) as $r) {
        if (!is_array($r)) continue;
        $type = (string)($r['type'] ?? 'url');
        if ($type === 'promo') {
            $q = $db->prepare('SELECT id, name, img FROM g7_content WHERE id = ?'); $q->execute([(int)($r['id'] ?? 0)]);
            if (!($pr = $q->fetch(PDO::FETCH_ASSOC))) ss_fail('Ese contenido de Promos ya no existe.');
            $refs[] = ['type' => 'promo', 'id' => (int)$pr['id'], 'label' => mb_substr((string)$pr['name'], 0, 160), 'url' => '/toolz/promos/', 'img' => (string)$pr['img']];
        } else {
            $u = trim((string)($r['url'] ?? ''));
            if ($u === '') continue;
            if (!preg_match('#^https?://#i', $u) || !filter_var($u, FILTER_VALIDATE_URL)) ss_fail('Una referencia no es un enlace válido.');
            $refs[] = ['type' => 'url', 'url' => mb_substr($u, 0, 1000), 'label' => mb_substr(trim((string)($r['label'] ?? '')), 0, 160)];
        }
    }
    $c['refs'] = json_encode($refs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tags = [];
    foreach (array_slice((array)($in['tags'] ?? []), 0, 15) as $t) { $t = mb_substr(trim(str_replace(',', ' ', (string)$t)), 0, 30); if ($t !== '' && !isset($tags[mb_strtolower($t)])) $tags[mb_strtolower($t)] = $t; }
    $c['tags'] = implode(',', array_values($tags));
    return $c;
}

/** ¿Puede llevar la publicación del estado $from (null = nueva) al estado $to? Corta con 403 si no. */
function ss_check_transition(PDO $db, array $ctx, ?int $from, int $to): void {
    $st = ss_statuses($db, true);
    if (!isset($st[$to]) || !$st[$to]['active']) ss_fail('Ese estado no existe o está desactivado.');
    if ($from === $to) return;
    foreach ([$from !== null ? ($st[$from]['kind'] ?? '') : '', $st[$to]['kind']] as $k) if (isset(SS_KIND_PERM[$k])) ss_need($ctx, SS_KIND_PERM[$k]);
}

/** Crea ($id = 0) o actualiza una publicación. Devuelve el id. */
function ss_post_save(PDO $db, array $ctx, array $in, int $id = 0): int {
    ss_need($ctx, 'edit');
    $old = $id ? ss_post_visible($db, $ctx, $id) : null;
    $c = ss_post_clean($db, $ctx, $in, $old);
    $status = (int)($in['status_id'] ?? 0) ?: ($old ? (int)$old['status_id'] : (ss_status_of_kind($db, 'idea') ?? (int)array_key_first(ss_statuses($db))));
    ss_check_transition($db, $ctx, $old ? (int)$old['status_id'] : null, $status);
    $c['status_id'] = $status;
    // Asignar a otra persona necesita "assign" (asignarse a uno mismo, o dejarlo como estaba, no)
    $prevOwner = $old && $old['owner_id'] ? (int)$old['owner_id'] : null;
    if ($c['owner_id'] && $c['owner_id'] !== $prevOwner && $c['owner_id'] !== $ctx['id']) ss_need($ctx, 'assign');
    // Mover la fecha de algo programado o publicado pide el mismo permiso que ese estado
    if ($old && ($old['pub_date'] !== $c['pub_date'] || (string)$old['pub_time'] !== (string)$c['pub_time'])) ss_check_date_move($db, $ctx, $old);
    $now = ss_now();
    if ($old) {
        $c['updated_at'] = $now;
        $db->prepare('UPDATE g7_ss_posts SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($c))) . ' WHERE id = ?')->execute([...array_values($c), $id]);
        $changes = ss_diff_text($db, $old, $c);
        if ($changes) ss_log($db, $id, $ctx['id'], 'edit', $changes);
    } else {
        $c += ['created_by' => $ctx['id'], 'created_at' => $now, 'updated_at' => $now];
        $db->prepare('INSERT INTO g7_ss_posts (' . implode(', ', array_keys($c)) . ') VALUES (' . implode(', ', array_fill(0, count($c), '?')) . ')')->execute(array_values($c));
        $id = (int)$db->lastInsertId();
        ss_log($db, $id, $ctx['id'], 'created', 'creó la publicación');
        g7_audit('social.post_create', '#' . $id, ['title' => $c['title']]);
    }
    if ($c['owner_id'] && $c['owner_id'] !== $prevOwner) ss_notify($db, [$c['owner_id']], $id, $ctx['id'], 'Te asignó la publicación "' . $c['title'] . '"');
    if (!$old || (int)$old['status_id'] !== $status) ss_after_status($db, $ctx, ss_post_row($db, $id), $old ? (int)$old['status_id'] : null, false);
    return $id;
}

function ss_check_date_move(PDO $db, array $ctx, array $post): void {
    $k = ss_statuses($db, true)[(int)$post['status_id']]['kind'] ?? '';
    if (in_array($k, ['scheduled', 'published'], true)) ss_need($ctx, SS_KIND_PERM[$k]);
}

/** Texto corto de lo que cambió (para el historial). */
function ss_diff_text(PDO $db, array $old, array $c): string {
    $labels = ['title' => 'título', 'description' => 'descripción', 'idea' => 'idea', 'copy' => 'copy', 'hashtags' => 'hashtags', 'notes' => 'notas internas',
               'brand_id' => 'marca', 'client_id' => 'cliente', 'networks' => 'redes', 'format' => 'formato', 'format_custom' => 'formato', 'owner_id' => 'responsable',
               'links' => 'enlaces', 'refs' => 'referencias', 'tags' => 'etiquetas'];
    $ch = [];
    foreach ($labels as $k => $l) if (array_key_exists($k, $c) && (string)$old[$k] !== (string)$c[$k]) $ch[$l] = true;
    $out = $ch ? 'cambió ' . implode(', ', array_keys($ch)) : '';
    if ($old['pub_date'] !== $c['pub_date'] || (string)$old['pub_time'] !== (string)$c['pub_time'])
        $out .= ($out ? '; ' : '') . 'movió la fecha a ' . ($c['pub_date'] ? ss_fmt_date($c['pub_date'], $c['pub_time']) : 'sin fecha');
    return $out;
}
function ss_fmt_date(?string $d, ?string $t = null): string {
    if (!$d) return 'sin fecha';
    return date('d/m/Y', strtotime($d)) . ($t ? ' ' . substr($t, 0, 5) : '');
}

/** Lo que pasa después de un cambio de estado: historial y avisos. $byRule = lo movió una regla de tickets. */
function ss_after_status(PDO $db, array $ctx, array $post, ?int $from, bool $byRule): void {
    $st = ss_statuses($db, true);
    $to = $st[(int)$post['status_id']] ?? null;
    if (!$to) return;
    if ($from !== null) ss_log($db, (int)$post['id'], $byRule ? null : $ctx['id'], $byRule ? 'rule' : 'status',
        ($byRule ? 'Regla de tickets: ' : 'cambió el estado ') . 'de "' . ($st[$from]['name'] ?? '?') . '" a "' . $to['name'] . '"');
    $title = '"' . $post['title'] . '"';
    $owner = $post['owner_id'] ? [(int)$post['owner_id']] : [];
    if ($to['kind'] === 'review') ss_notify($db, ss_approvers($db, $post), (int)$post['id'], $byRule ? null : $ctx['id'], 'Para revisar: ' . $title);
    elseif ($to['kind'] === 'approved') ss_notify($db, $owner, (int)$post['id'], $byRule ? null : $ctx['id'], 'Aprobada: ' . $title);
    elseif ($to['kind'] === 'changes') ss_notify($db, $owner, (int)$post['id'], $byRule ? null : $ctx['id'], 'Pidieron cambios en ' . $title);
    elseif ($byRule) ss_notify($db, $owner, (int)$post['id'], null, $title . ' pasó a "' . $to['name'] . '" (tickets)');
}

/** Cambia solo el estado (menú rápido o tablero). */
function ss_post_status(PDO $db, array $ctx, int $id, int $statusId): void {
    ss_need($ctx, 'edit');
    $p = ss_post_visible($db, $ctx, $id);
    if ((int)$p['status_id'] === $statusId) return;
    ss_check_transition($db, $ctx, (int)$p['status_id'], $statusId);
    $db->prepare('UPDATE g7_ss_posts SET status_id = ?, updated_at = ? WHERE id = ?')->execute([$statusId, ss_now(), $id]);
    ss_after_status($db, $ctx, ss_post_row($db, $id), (int)$p['status_id'], false);
}

/** Arrastrar en el calendario: nueva fecha y, si se suelta en una franja horaria, nueva hora ($time = '' = sin hora; null = la deja). */
function ss_post_move(PDO $db, array $ctx, int $id, ?string $date, ?string $time = null): void {
    ss_need($ctx, 'edit');
    $p = ss_post_visible($db, $ctx, $id);
    if ($date !== null && $date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) ss_fail('La fecha no es válida.');
    if ($time !== null && $time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) ss_fail('La hora no es válida.');
    $d = $date ?: null;
    $t = $d === null ? null : ($time === null ? $p['pub_time'] : ($time === '' ? null : $time . ':00'));
    if ($p['pub_date'] === $d && (string)$p['pub_time'] === (string)$t) return;
    ss_check_date_move($db, $ctx, $p);
    $db->prepare('UPDATE g7_ss_posts SET pub_date = ?, pub_time = ?, updated_at = ? WHERE id = ?')->execute([$d, $t, ss_now(), $id]);
    ss_log($db, $id, $ctx['id'], 'move', 'movió la fecha a ' . ss_fmt_date($d, $t));
}

function ss_post_delete(PDO $db, array $ctx, int $id): void {
    ss_need($ctx, 'edit');
    $p = ss_post_visible($db, $ctx, $id);
    $k = ss_statuses($db, true)[(int)$p['status_id']]['kind'] ?? '';
    if (isset(SS_KIND_PERM[$k])) ss_need($ctx, SS_KIND_PERM[$k]);
    $db->prepare('UPDATE g7_ss_posts SET deleted_at = ?, updated_at = ? WHERE id = ?')->execute([ss_now(), ss_now(), $id]);
    ss_log($db, $id, $ctx['id'], 'delete', 'borró la publicación');
    g7_audit('social.post_delete', '#' . $id, ['title' => $p['title']]);
}

/** Copia una publicación (sin fecha, en el primer estado, sin tickets ni archivos). Devuelve el id nuevo. */
function ss_post_duplicate(PDO $db, array $ctx, int $id): int {
    ss_need($ctx, 'edit');
    $p = ss_post_visible($db, $ctx, $id);
    $in = ss_post_out($db, $p, true);
    $in['title'] = mb_substr('Copia de ' . $p['title'], 0, 200);
    $in['date'] = ''; $in['time'] = '';
    $in['status_id'] = ss_status_of_kind($db, 'idea') ?? (int)array_key_first(ss_statuses($db));
    $in['owner_id'] = $ctx['id'];
    return ss_post_save($db, $ctx, $in);
}

/** Detalle completo: publicación, archivos, tickets e historial. */
function ss_post_detail(PDO $db, array $ctx, int $id): array {
    ss_post_visible($db, $ctx, $id);
    ss_sync_tickets($db, [$id]);
    $p = ss_post_row($db, $id);
    return ['post' => ss_post_out($db, $p, true, ss_ticket_counts($db, [$id])[$id] ?? null), 'files' => ss_files($db, $id),
            'tickets' => ss_post_tickets($db, $ctx, $id), 'history' => ss_history($db, $id)];
}

// ═══════════════════════════════════════════════════════════
// ARCHIVOS (se guardan una vez; los tickets los enlazan, no los copian)
// ═══════════════════════════════════════════════════════════
function ss_files_dir(): string { return dirname(__DIR__) . '/_private/social/'; }
/** Path on disk of a file (the ones from posts go in their folder; the ones from the Inbox, in inbox/). */
function ss_file_path(array $f): string { return ss_files_dir() . (!empty($f['pf_user_id']) ? 'pf/' . (int)$f['pf_user_id'] : (!empty($f['conv_id']) ? 'inbox' : (int)$f['post_id'])) . '/' . $f['token'] . '.' . $f['ext']; }
function ss_files(PDO $db, int $postId): array {
    $q = $db->prepare('SELECT f.*, COALESCE(NULLIF(u.display_name,\'\'), u.username) AS who FROM g7_ss_files f LEFT JOIN g7_users u ON u.id = f.user_id WHERE f.post_id = ? ORDER BY f.id');
    $q->execute([$postId]);
    return array_map(fn($f) => ['id' => (int)$f['id'], 'name' => $f['name'], 'ext' => $f['ext'], 'size' => (int)$f['size'], 'who' => (string)$f['who'], 'at' => $f['created_at'],
        'url' => '/toolz/social_file.php?t=' . $f['token'], 'image' => in_array($f['ext'], G7_EXT_IMG, true), 'video' => in_array($f['ext'], ['mp4', 'webm', 'mov', 'm4v'], true)], $q->fetchAll(PDO::FETCH_ASSOC));
}
function ss_file_upload(PDO $db, array $ctx, int $postId, $f): array {
    ss_need($ctx, 'edit');
    ss_post_visible($db, $ctx, $postId);
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) ss_fail('Elige un archivo.');
    if ($pr = g7_upload_problem($f)) ss_fail($pr);
    $ext = g7_upload_ext((string)$f['name'], SS_FILE_EXT);
    if ($ext === null) ss_fail('Ese tipo de archivo no se acepta (.' . (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) ?: '?') . ').');
    if ((int)$f['size'] > SS_FILE_MAX) ss_fail('El archivo pesa más de 200 MB.');
    if (in_array($ext, G7_EXT_IMG, true) && $ext !== 'avif' && @getimagesize($f['tmp_name']) === false) ss_fail('Esa imagen está dañada o no es una imagen.');
    if ((int)$db->query('SELECT COUNT(*) FROM g7_ss_files WHERE post_id = ' . $postId)->fetchColumn() >= 60) ss_fail('Una publicación admite hasta 60 archivos.');
    $dir = ss_files_dir() . $postId . '/';
    if (!is_dir(ss_files_dir())) { @mkdir(ss_files_dir(), 0755, true); }
    if (!is_file(ss_files_dir() . '.htaccess')) @file_put_contents(ss_files_dir() . '.htaccess', "Require all denied\nphp_flag engine off\n");
    $token = bin2hex(random_bytes(16));
    if ($err = g7_upload_store($f, $dir, $token . '.' . $ext)) ss_fail($err, 500);
    $name = mb_substr(preg_replace('/[\x00-\x1f\/\\\\]+/u', '', (string)$f['name']) ?: ('archivo.' . $ext), 0, 200);
    $db->prepare('INSERT INTO g7_ss_files (post_id, token, name, ext, size, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$postId, $token, $name, $ext, (int)$f['size'], $ctx['id'], ss_now()]);
    ss_log($db, $postId, $ctx['id'], 'file', 'subió ' . $name);
    $db->prepare('UPDATE g7_ss_posts SET updated_at = ? WHERE id = ?')->execute([ss_now(), $postId]);
    return ss_files($db, $postId);
}
function ss_file_delete(PDO $db, array $ctx, int $fileId): int {
    ss_need($ctx, 'edit');
    $q = $db->prepare('SELECT * FROM g7_ss_files WHERE id = ?'); $q->execute([$fileId]);
    if (!($f = $q->fetch(PDO::FETCH_ASSOC))) ss_fail('Ese archivo ya no existe.', 404);
    ss_post_visible($db, $ctx, (int)$f['post_id']);
    @unlink(ss_files_dir() . (int)$f['post_id'] . '/' . $f['token'] . '.' . $f['ext']);
    $db->prepare('DELETE FROM g7_ss_files WHERE id = ?')->execute([$fileId]);
    ss_log($db, (int)$f['post_id'], $ctx['id'], 'file', 'borró ' . $f['name']);
    return (int)$f['post_id'];
}
/**
 * ¿Puede esta persona bajar el archivo? Quien ve la publicación en Social, o quien está en alguno de sus tickets
 * (lo pidió, lo atiende o colabora): así el ticket enlaza el archivo sin copiarlo y sin dar acceso a Social.
 */
function ss_file_can(PDO $db, int $uid, array $file): bool {
    if (!empty($file['pf_user_id'])) {   // Profile Studio: private, except the thumbnail of a project shared in the agency gallery
        if (!g7_can($uid, 'social')) return false;
        if ((int)$file['pf_user_id'] === $uid) return true;
        if (g7_cfg('ss_pf_gallery', '') !== '1') return false;
        $q = $db->prepare('SELECT 1 FROM g7_ss_pf_projects WHERE thumb_token = ? AND shared = 1 AND deleted_at IS NULL'); $q->execute([$file['token']]);
        return (bool)$q->fetchColumn();
    }
    if (!empty($file['conv_id'])) {   // image attached in the Inbox: whoever sees that conversation
        if (!g7_can($uid, 'social', 'inbox')) return false;
        require_once __DIR__ . '/inbox_lib.php';
        try { ss_conv_row($db, ss_ctx($db, $uid), (int)$file['conv_id']); return true; } catch (SsError $e) { return false; }
    }
    if (g7_can($uid, 'social')) {
        try { ss_post_visible($db, ss_ctx($db, $uid), (int)$file['post_id']); return true; } catch (SsError $e) {}
    }
    if (!g7_can($uid, 'tareas')) return false;
    $q = $db->prepare('SELECT t.* FROM g7_ss_tickets l JOIN g7_wk_tickets t ON t.id = l.ticket_id WHERE l.post_id = ?'); $q->execute([(int)$file['post_id']]);
    $tks = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$tks) return false;
    require_once dirname(__DIR__) . '/trabajo/lib.php';
    $wctx = wk_ctx($db, $uid);
    foreach ($tks as $t) {
        if ((int)$t['requester_id'] === $uid || wk_tk_is_agent($db, $wctx, $t)) return true;
        if (function_exists('wk_tkc_is') && wk_tkc_is($db, (int)$t['id'], $uid)) return true;
    }
    return false;
}

// ═══════════════════════════════════════════════════════════
// TICKETS (Trabajo): convertir en ticket, sincronizar estado y reglas
// ═══════════════════════════════════════════════════════════
function ss_trabajo(PDO $db): void {
    require_once dirname(__DIR__) . '/trabajo/lib.php';
    wk_install($db);
}

/** Reglas de estado (configurables). Los destinos son ids de estado; si faltan, el primero del tipo. */
function ss_rules(PDO $db): array {
    $saved = g7_cfg_json('ss_rules', []);
    $id = fn($k) => ss_status_of_kind($db, $k);
    $st = ss_statuses($db);
    $pick = fn($v, $kind) => isset($st[(int)$v]) ? (int)$v : $id($kind);
    return [
        'on_create' => ['on' => (bool)($saved['on_create']['on'] ?? true), 'design' => $pick($saved['on_create']['design'] ?? 0, 'design'), 'video' => $pick($saved['on_create']['video'] ?? 0, 'video')],
        'on_done'   => ['on' => (bool)($saved['on_done']['on'] ?? true), 'to' => $pick($saved['on_done']['to'] ?? 0, 'review')],
        'on_reopen' => ['on' => (bool)($saved['on_reopen']['on'] ?? false), 'to' => $pick($saved['on_reopen']['to'] ?? 0, 'changes')],
    ];
}

/**
 * Aplica una regla: mueve la publicación a $to solo si avanza (rank mayor) y si su estado actual no está protegido
 * ($allowBack = la regla "ticket reabierto", que por definición vuelve de En revisión). Lo que no se aplica queda en el historial.
 */
function ss_rule_move(PDO $db, int $postId, ?int $to, string $why, bool $allowBack = false): bool {
    if (!$to) return false;
    $p = ss_post_row($db, $postId);
    if (!$p) return false;
    $st = ss_statuses($db, true);
    $cur = $st[(int)$p['status_id']] ?? null; $dst = $st[$to] ?? null;
    if (!$cur || !$dst || $cur['id'] === $dst['id']) return false;
    if (in_array($cur['kind'], SS_PROTECTED, true) || $cur['kind'] === 'custom') {
        ss_log($db, $postId, null, 'rule_skip', $why . ': no se movió a "' . $dst['name'] . '" porque está en "' . $cur['name'] . '" (manda el estado editorial)');
        return false;
    }
    $rc = SS_KINDS[$cur['kind']][1]; $rd = SS_KINDS[$dst['kind']][1] ?? null;
    if ($rd === null) return false;
    if ($allowBack ? $cur['kind'] !== 'review' : $rd <= $rc) return false;
    $db->prepare('UPDATE g7_ss_posts SET status_id = ?, updated_at = ? WHERE id = ?')->execute([$to, ss_now(), $postId]);
    ss_log($db, $postId, null, 'rule', $why . ': pasó de "' . $cur['name'] . '" a "' . $dst['name'] . '"');
    ss_after_status($db, ['id' => 0], ss_post_row($db, $postId), null, true);
    return true;
}

/** Opciones para el diálogo "Convertir en ticket": departamentos, categorías, prioridades y personas. */
function ss_ticket_options(PDO $db, array $ctx): array {
    ss_need($ctx, 'tickets');
    ss_trabajo($db);
    $cats = array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'team_id' => $c['team_id'] ? (int)$c['team_id'] : null],
        $db->query('SELECT id, name, team_id FROM g7_wk_ticket_cats WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC));
    $teams = array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name'], 'color' => $t['color']], $db->query('SELECT id, name, color FROM g7_teams WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC));
    $cfg = tk_config($db);
    return ['cats' => $cats, 'teams' => $teams, 'priorities' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name'] ?? $p['label'] ?? $p['id']], $cfg['priorities']),
            'default_priority' => $cfg['default_priority'], 'people' => ss_people($db), 'kinds' => SS_TICKET_KINDS];
}

/** Texto que lleva el ticket: lo pedido + los datos de la publicación + enlaces (a ella y a sus archivos, sin copiarlos). */
function ss_ticket_body(PDO $db, array $p, string $extra, array $files): string {
    $nets = array_map(fn($n) => SS_NETWORKS[$n][0] ?? $n, ss_split($p['networks']));
    $who = '';
    if ($p['brand_id'] && ($b = g7_brands(true)[(int)$p['brand_id']] ?? null)) $who = 'Marca: ' . $b['name'];
    if ($p['client_id'] && ss_clients_on($db)) { $q = $db->prepare('SELECT name FROM g7_clients WHERE id = ?'); $q->execute([(int)$p['client_id']]); $who .= ($who ? ' · ' : '') . 'Cliente: ' . $q->fetchColumn(); }
    $L = [];
    if (trim($extra) !== '') $L[] = trim($extra) . "\n";
    $L[] = '— Publicación de Social Studio —';
    $L[] = 'Título: ' . $p['title'];
    if ($who) $L[] = $who;
    $L[] = 'Redes: ' . ($nets ? implode(', ', $nets) : 'sin definir') . ' · Formato: ' . ($p['format'] === 'personalizado' ? $p['format_custom'] : (SS_FORMATS[$p['format']] ?? $p['format']));
    $L[] = 'Fecha de publicación: ' . ss_fmt_date($p['pub_date'], $p['pub_time']);
    if ($p['owner_id']) $L[] = 'Responsable de la publicación: ' . ss_user_name($db, (int)$p['owner_id']);
    foreach (['idea' => 'Idea', 'description' => 'Descripción', 'copy' => 'Copy', 'hashtags' => 'Hashtags'] as $k => $l) if (trim((string)$p[$k]) !== '') $L[] = "\n$l:\n" . trim((string)$p[$k]);
    $links = ss_json_list($p['links']); $refs = ss_json_list($p['refs']);
    if ($links) { $L[] = "\nEnlaces:"; foreach ($links as $l) $L[] = '- ' . ($l['label'] ? $l['label'] . ': ' : '') . $l['url']; }
    if ($refs) { $L[] = "\nReferencias:"; foreach ($refs as $r) $L[] = '- ' . ($r['label'] ? $r['label'] . ': ' : '') . (str_starts_with($r['url'], '/') ? g7_site_origin() . $r['url'] : $r['url']); }
    if ($files) { $L[] = "\nArchivos (se abren desde la publicación, no se copiaron):"; foreach ($files as $f) $L[] = '- ' . $f['name'] . ': ' . g7_site_origin() . $f['url']; }
    $L[] = "\nVer la publicación: " . ss_url('p/' . (int)$p['id']);
    return implode("\n", $L);
}

/**
 * Crea uno o varios tickets reales de Trabajo desde una publicación. $items: [{kind, label?, cat_id, team_id?, assignee_id?, priority?, due?, description?, files?: [ids]}].
 * Valida todo antes de crear el primero. Devuelve los ids de los tickets.
 */
function ss_make_tickets(PDO $db, array $ctx, int $postId, array $items): array {
    ss_need($ctx, 'tickets');
    $p = ss_post_visible($db, $ctx, $postId);
    ss_trabajo($db);
    if (!isset(WK_TK_ORIGINS['social'])) ss_fail('Falta actualizar Trabajo para recibir tickets de Social Studio.', 500);
    $items = array_values(array_filter($items, 'is_array'));
    if (!$items) ss_fail('Agrega al menos un ticket.');
    if (count($items) > 10) ss_fail('Hasta 10 tickets por vez.');
    if (!ss_rate_ok($db, 'tk:' . $ctx['id'], 30, 600)) ss_fail('Creaste muchos tickets seguidos: espera unos minutos.', 429);
    $files = []; foreach (ss_files($db, $postId) as $f) $files[$f['id']] = $f;
    $cfg = tk_config($db); $prios = array_column($cfg['priorities'], 'id');
    $plan = [];
    foreach ($items as $i => $it) {
        $n = $i + 1;
        $kind = isset(SS_TICKET_KINDS[$it['kind'] ?? '']) ? $it['kind'] : 'otro';
        $q = $db->prepare('SELECT * FROM g7_wk_ticket_cats WHERE id = ? AND active = 1'); $q->execute([(int)($it['cat_id'] ?? 0)]);
        if (!($cat = $q->fetch(PDO::FETCH_ASSOC))) ss_fail("Ticket $n: elige una categoría.");
        $team = (int)($it['team_id'] ?? 0);
        if ($team) { $q = $db->prepare('SELECT 1 FROM g7_teams WHERE id = ? AND active = 1'); $q->execute([$team]); if (!$q->fetchColumn()) ss_fail("Ticket $n: ese departamento no existe."); }
        $as = (int)($it['assignee_id'] ?? 0);
        if ($as && !ss_user_active($db, $as)) ss_fail("Ticket $n: esa persona no existe o está inactiva.");
        $prio = in_array($it['priority'] ?? '', $prios, true) ? $it['priority'] : $cfg['default_priority'];
        $due = trim((string)($it['due'] ?? ''));
        if ($due !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || $due < date('Y-m-d'))) ss_fail("Ticket $n: la entrega tiene que ser hoy o más adelante.");
        $fsel = [];
        foreach ((array)($it['files'] ?? []) as $fid) { if (!isset($files[(int)$fid])) ss_fail("Ticket $n: un archivo elegido ya no está en la publicación."); $fsel[] = $files[(int)$fid]; }
        $label = mb_substr(trim((string)($it['label'] ?? '')), 0, 40) ?: SS_TICKET_KINDS[$kind];
        $plan[] = compact('kind', 'cat', 'team', 'as', 'prio', 'due', 'fsel', 'label') + ['desc' => mb_substr((string)($it['description'] ?? ''), 0, 5000)];
    }
    $ids = []; $now = ss_now();
    foreach ($plan as $x) {
        $subject = mb_substr($x['label'] . ': ' . $p['title'], 0, 200);
        $tid = wk_create_ticket($db, $ctx['id'], (int)$x['cat']['id'], $x['prio'], $subject, ss_ticket_body($db, $p, $x['desc'], $x['fsel']), ['origin' => 'social']);
        $f = [];
        if ($x['as']) $f['assignee_id'] = $x['as'];
        if ($x['team'] && $x['team'] !== (int)$x['cat']['team_id']) $f['team_id'] = $x['team'];
        if ($x['due']) $f['resolution_due'] = $x['due'] . ' 23:59:00';
        if ($f) {
            wk_tk_set($db, $tid, $f);
            wk_task_from_tk($db, $tid, $ctx['id']);
            if ($x['as'] && $x['as'] !== $ctx['id']) wk_tk_notify($db, 'assigned', [$x['as']], wk_tk_row($db, $tid), $ctx['id'], 'Pedido desde Social Studio');
        }
        // La tarea del ticket queda con el cliente o la marca de la publicación (si el tablero agrupa por lo mismo)
        $t = wk_tk_row($db, $tid); $tbl = wk_cli_table($db);
        $cid = $tbl === 'g7_clients' ? (int)$p['client_id'] : ($tbl === 'g7_brands' ? (int)$p['brand_id'] : 0);
        if ($cid && !empty($t['task_id'])) try { $db->prepare('UPDATE g7_tasks SET client_id = ? WHERE id = ? AND client_id IS NULL')->execute([$cid, (int)$t['task_id']]); } catch (Throwable $e) {}
        $db->prepare('INSERT INTO g7_ss_tickets (post_id, ticket_id, kind, last_status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$postId, $tid, $x['kind'], (string)$t['status'], $ctx['id'], $now]);
        ss_log($db, $postId, $ctx['id'], 'ticket', 'creó el ticket #' . $tid . ' (' . $x['label'] . ')');
        $ids[] = $tid;
    }
    g7_audit('social.tickets', '#' . $postId, ['tickets' => $ids]);
    $r = ss_rules($db);
    if ($r['on_create']['on']) {
        $video = (bool)array_filter($plan, fn($x) => $x['kind'] === 'video');
        ss_rule_move($db, $postId, $video ? $r['on_create']['video'] : $r['on_create']['design'], 'Se crearon tickets');
    }
    return $ids;
}

/** Vincula un ticket que ya existe (lo tiene que poder ver quien lo vincula). */
function ss_link_ticket(PDO $db, array $ctx, int $postId, int $ticketId, string $kind): void {
    ss_need($ctx, 'tickets');
    ss_post_visible($db, $ctx, $postId);
    ss_trabajo($db);
    $t = wk_tk_row($db, $ticketId);
    if (!$t) ss_fail('Ese ticket no existe.', 404);
    if ((int)$t['requester_id'] !== $ctx['id'] && !wk_tk_is_agent($db, wk_ctx($db, $ctx['id']), $t)) ss_fail('No ves ese ticket en Trabajo.', 403);
    $kind = isset(SS_TICKET_KINDS[$kind]) ? $kind : 'otro';
    $db->prepare('INSERT IGNORE INTO g7_ss_tickets (post_id, ticket_id, kind, last_status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$postId, $ticketId, $kind, (string)$t['status'], $ctx['id'], ss_now()]);
    ss_log($db, $postId, $ctx['id'], 'ticket', 'vinculó el ticket #' . $ticketId);
}
function ss_unlink_ticket(PDO $db, array $ctx, int $postId, int $ticketId): void {
    ss_need($ctx, 'tickets');
    ss_post_visible($db, $ctx, $postId);
    $db->prepare('DELETE FROM g7_ss_tickets WHERE post_id = ? AND ticket_id = ?')->execute([$postId, $ticketId]);
    ss_log($db, $postId, $ctx['id'], 'ticket', 'desvinculó el ticket #' . $ticketId . ' (el ticket sigue en Trabajo)');
}

/** Totales de tickets por publicación: [post_id => [total, done]]. */
function ss_ticket_counts(PDO $db, array $postIds): array {
    $postIds = array_values(array_filter(array_map('intval', $postIds)));
    if (!$postIds) return [];
    $out = [];
    $done = implode(',', array_map(fn($s) => $db->quote($s), SS_TK_DONE));
    foreach ($db->query('SELECT post_id, COUNT(*) n, SUM(last_status IN (' . $done . ')) d FROM g7_ss_tickets WHERE post_id IN (' . implode(',', $postIds) . ') GROUP BY post_id') as $r)
        $out[(int)$r['post_id']] = ['total' => (int)$r['n'], 'done' => (int)$r['d']];
    return $out;
}

/** Tickets de una publicación, con lo que se ve de ellos (estado actual, responsable, plazo). */
function ss_post_tickets(PDO $db, array $ctx, int $postId): array {
    $q = $db->prepare('SELECT l.ticket_id, l.kind, l.created_at, t.subject, t.status, t.priority, t.assignee_id, t.team_id, t.resolution_due, t.requester_id, t.task_id, t.approver_id, tm.name AS team
                       FROM g7_ss_tickets l LEFT JOIN g7_wk_tickets t ON t.id = l.ticket_id LEFT JOIN g7_teams tm ON tm.id = t.team_id WHERE l.post_id = ? ORDER BY l.created_at, l.ticket_id');
    $q->execute([$postId]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    ss_trabajo($db);
    $names = wk_st('tickets'); $wctx = wk_ctx($db, $ctx['id']);
    return array_map(function ($r) use ($db, $names, $wctx, $ctx) {
        $gone = $r['subject'] === null;
        $st = (string)$r['status'];
        return ['id' => (int)$r['ticket_id'], 'kind' => $r['kind'], 'kind_name' => SS_TICKET_KINDS[$r['kind']] ?? $r['kind'], 'gone' => $gone,
                'subject' => (string)$r['subject'], 'status' => $st, 'status_name' => $names[$st][0] ?? $st, 'status_color' => $names[$st][1] ?? '#94a3b8',
                'done' => in_array($st, SS_TK_DONE, true), 'priority' => (string)$r['priority'], 'team' => (string)$r['team'],
                'assignee' => ss_user_name($db, $r['assignee_id'] ? (int)$r['assignee_id'] : null), 'due' => $r['resolution_due'],
                'can_open' => !$gone && g7_can($ctx['id'], 'tareas') && ((int)$r['requester_id'] === $ctx['id'] || wk_tk_is_agent($db, $wctx, $r + ['id' => (int)$r['ticket_id']])),
                'url' => '/toolz/trabajo/#tickets/' . (int)$r['ticket_id']];
    }, $rows);
}

/**
 * Trae el estado actual de los tickets vinculados y aplica las reglas. Corre al abrir el calendario o una publicación,
 * y en social/cron.php. $postIds null = todas. No toca Trabajo: solo lee g7_wk_tickets.
 */
function ss_sync_tickets(PDO $db, ?array $postIds = null): int {
    $where = '';
    if ($postIds !== null) {
        $postIds = array_values(array_filter(array_map('intval', $postIds)));
        if (!$postIds) return 0;
        $where = ' AND l.post_id IN (' . implode(',', $postIds) . ')';
    }
    try {
        $rows = $db->query("SELECT l.post_id, l.ticket_id, l.kind, l.last_status, t.status FROM g7_ss_tickets l LEFT JOIN g7_wk_tickets t ON t.id = l.ticket_id
                            WHERE (t.status IS NULL AND l.last_status <> 'borrado') OR CAST(t.status AS BINARY) <> CAST(l.last_status AS BINARY)" . $where)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return 0; }   // Trabajo todavía sin instalar
    if (!$rows) return 0;
    $names = function_exists('wk_st') ? wk_st('tickets') : [];
    $byPost = [];
    $up = $db->prepare('UPDATE g7_ss_tickets SET last_status = ? WHERE post_id = ? AND ticket_id = ?');
    foreach ($rows as $r) {
        $new = $r['status'] === null ? 'borrado' : (string)$r['status'];
        $up->execute([$new, (int)$r['post_id'], (int)$r['ticket_id']]);
        ss_log($db, (int)$r['post_id'], null, 'ticket_status', 'Ticket #' . $r['ticket_id'] . ' (' . (SS_TICKET_KINDS[$r['kind']] ?? $r['kind']) . '): ' . ($new === 'borrado' ? 'ya no existe en Trabajo' : 'pasó a "' . ($names[$new][0] ?? $new) . '"'));
        $byPost[(int)$r['post_id']][] = ['from' => $r['last_status'], 'to' => $new];
    }
    $rules = ss_rules($db);
    foreach ($byPost as $pid => $changes) {
        $reopened = (bool)array_filter($changes, fn($c) => in_array($c['from'], SS_TK_DONE, true) && !in_array($c['to'], SS_TK_DONE, true) && $c['to'] !== 'borrado');
        $closedNow = (bool)array_filter($changes, fn($c) => in_array($c['to'], SS_TK_DONE, true));
        $q = $db->prepare("SELECT COUNT(*) FROM g7_ss_tickets WHERE post_id = ? AND last_status <> 'borrado'"); $q->execute([$pid]); $total = (int)$q->fetchColumn();
        $done = ss_ticket_counts($db, [$pid])[$pid]['done'] ?? 0;
        if ($reopened && $rules['on_reopen']['on']) ss_rule_move($db, $pid, $rules['on_reopen']['to'], 'Se reabrió un ticket', true);
        elseif ($closedNow && $total > 0 && $done >= $total && $rules['on_done']['on']) ss_rule_move($db, $pid, $rules['on_done']['to'], 'Todos los tickets quedaron resueltos');
    }
    return count($rows);
}

// ═══════════════════════════════════════════════════════════
// CONFIGURACIÓN: agrupación, estados y reglas
// ═══════════════════════════════════════════════════════════
function ss_config_save(PDO $db, array $ctx, array $in): void {
    ss_need($ctx, 'config');
    if (isset($in['group'])) {
        if (!isset(ss_group_options()[$in['group']])) ss_fail('Esa forma de agrupar no está disponible en este sitio.');
        if ($in['group'] !== ss_group()) { g7_cfg_set('ss_group', (string)$in['group']); g7_audit('social.config', 'group', ['group' => $in['group']]); }
    }
    if (isset($in['statuses']) && is_array($in['statuses'])) ss_statuses_save($db, $in['statuses']);
    if (isset($in['rules']) && is_array($in['rules'])) {
        $st = ss_statuses($db); $r = $in['rules'];
        $v = fn($x) => isset($st[(int)$x]) ? (int)$x : 0;
        $save = ['on_create' => ['on' => !empty($r['on_create']['on']), 'design' => $v($r['on_create']['design'] ?? 0), 'video' => $v($r['on_create']['video'] ?? 0)],
                 'on_done' => ['on' => !empty($r['on_done']['on']), 'to' => $v($r['on_done']['to'] ?? 0)],
                 'on_reopen' => ['on' => !empty($r['on_reopen']['on']), 'to' => $v($r['on_reopen']['to'] ?? 0)]];
        foreach ([$save['on_create']['design'], $save['on_create']['video'], $save['on_done']['to'], $save['on_reopen']['to']] as $sid)
            if ($sid && in_array($st[$sid]['kind'], SS_PROTECTED, true)) ss_fail('Una regla no puede llevar a "' . $st[$sid]['name'] . '": aprobar, programar y publicar los decide una persona.');
        g7_cfg_set('ss_rules', json_encode($save));
        g7_audit('social.config', 'rules', $save);
    }
}

/** Guarda la lista de estados: [{id?, kind, name, color, active}] en el orden dado. No se borran: se desactivan. */
function ss_statuses_save(PDO $db, array $list): void {
    $cur = ss_statuses($db, true);
    $seen = []; $sort = 0;
    $upd = $db->prepare('UPDATE g7_ss_status SET name = ?, color = ?, sort = ?, active = ? WHERE id = ?');
    $ins = $db->prepare('INSERT INTO g7_ss_status (kind, name, color, sort, active) VALUES (?, ?, ?, ?, ?)');
    foreach ($list as $s) {
        if (!is_array($s)) continue;
        $name = mb_substr(trim((string)($s['name'] ?? '')), 0, 40);
        if ($name === '') ss_fail('Todos los estados necesitan un nombre.');
        $color = preg_match('/^#[0-9a-f]{6}$/i', (string)($s['color'] ?? '')) ? strtolower($s['color']) : '#94a3b8';
        $active = !empty($s['active']) ? 1 : 0;
        $id = (int)($s['id'] ?? 0);
        if ($id && isset($cur[$id])) { $upd->execute([$name, $color, $sort++, $active, $id]); $seen[$id] = $active; }
        elseif (!$id) { $ins->execute(['custom', $name, $color, $sort++, $active]); $seen[(int)$db->lastInsertId()] = $active; }
    }
    // Siempre tiene que quedar al menos un estado activo de cada tipo base
    $after = ss_statuses($db, true);
    foreach (array_keys(SS_KINDS) as $k) {
        if ($k === 'custom') continue;
        if (!array_filter($after, fn($x) => $x['kind'] === $k && $x['active'])) ss_fail('Tiene que quedar activo al menos un estado "' . SS_KINDS[$k][0] . '".');
    }
    g7_audit('social.config', 'statuses', ['n' => count($after)]);
}

// ═══════════════════════════════════════════════════════════
// IMPORTAR DE PLANIFY (copia; Planify queda intacto)
// ═══════════════════════════════════════════════════════════
function ss_planify_available(PDO $db): bool {
    try { return (bool)$db->query("SHOW TABLES LIKE 'g7_planify_posts'")->fetchColumn() && (int)$db->query('SELECT COUNT(*) FROM g7_planify_posts')->fetchColumn() > 0; }
    catch (Throwable $e) { return false; }
}
function ss_planify_projects(PDO $db, array $ctx): array {
    ss_need($ctx, 'config');
    if (!ss_planify_available($db)) return [];
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'posts' => (int)$r['n'], 'imported' => (int)$r['imp']],
        $db->query('SELECT pr.id, pr.name, COUNT(p.id) n, SUM(s.id IS NOT NULL) imp FROM g7_planify_projects pr LEFT JOIN g7_planify_posts p ON p.project_id = pr.id
                    LEFT JOIN g7_ss_posts s ON s.planify_id = p.id AND s.deleted_at IS NULL GROUP BY pr.id ORDER BY pr.name')->fetchAll(PDO::FETCH_ASSOC));
}
/** $map: [project_id => ['brand_id' => x, 'client_id' => y]]. Solo copia lo que no se copió antes. Devuelve cuántas. */
function ss_planify_import(PDO $db, array $ctx, array $map): int {
    ss_need($ctx, 'config');
    if (!ss_planify_available($db)) ss_fail('No hay publicaciones de Planify para importar.');
    $net = ['facebook' => ['facebook', 'imagen'], 'ig_post' => ['instagram', 'imagen'], 'ig_historia' => ['instagram', 'historia'], 'whatsapp' => ['', 'imagen'], 'x' => ['x', 'texto']];
    $kind = ['urgente' => 'pending', 'pendiente_assets' => 'pending', 'en_diseno' => 'design', 'publicado' => 'published'];
    $n = 0; $now = ss_now();
    $ins = $db->prepare('INSERT IGNORE INTO g7_ss_posts (title, description, pub_date, brand_id, client_id, networks, format, status_id, copy, links, refs, tags, planify_id, created_by, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($map as $pid => $m) {
        $pid = (int)$pid;
        $bid = (int)($m['brand_id'] ?? 0) ?: null; $cid = (int)($m['client_id'] ?? 0) ?: null;
        if ($bid && !isset(g7_brands(true)[$bid])) ss_fail('Una de las marcas elegidas no existe.');
        if ($cid) { $q = $db->prepare('SELECT 1 FROM g7_clients WHERE id = ?'); $q->execute([$cid]); if (!$q->fetchColumn()) ss_fail('Uno de los clientes elegidos no existe.'); }
        $q = $db->prepare('SELECT p.* FROM g7_planify_posts p LEFT JOIN g7_ss_posts s ON s.planify_id = p.id WHERE p.project_id = ? AND s.id IS NULL'); $q->execute([$pid]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
            [$nw, $fmt] = $net[$p['red_social']] ?? ['', 'imagen'];
            $refs = [];
            $rq = $db->prepare('SELECT * FROM g7_planify_refs WHERE post_id = ? ORDER BY sort_order'); $rq->execute([(int)$p['id']]);
            foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $r) $refs[] = ['type' => 'url', 'url' => str_starts_with($r['url'], '/') ? g7_site_origin() . $r['url'] : $r['url'], 'label' => (string)$r['name']];
            $links = $p['asset_final'] ? [['url' => str_starts_with($p['asset_final'], '/') ? g7_site_origin() . $p['asset_final'] : $p['asset_final'], 'label' => 'Asset final (Planify)']] : [];
            $tags = array_filter(['planify', $p['red_social'] === 'whatsapp' ? 'whatsapp' : '', $p['estado'] === 'urgente' ? 'urgente' : '']);
            $ins->execute([mb_substr($p['title'] ?: 'Sin título', 0, 200), (string)$p['descripcion'], $p['fecha_publicacion'], $bid, $cid, $nw, $fmt,
                           ss_status_of_kind($db, $kind[$p['estado']] ?? 'pending'), (string)$p['copy_placa'], json_encode($links, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                           json_encode($refs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), implode(',', $tags), (int)$p['id'], $ctx['id'], $now, $now]);
            if ($db->lastInsertId() && $ins->rowCount()) { $n++; ss_log($db, (int)$db->lastInsertId(), $ctx['id'], 'created', 'importó la publicación desde Planify'); }
        }
    }
    g7_audit('social.planify_import', '', ['n' => $n]);
    return $n;
}

/** Contenidos de Promos para elegir como referencia (buscador). Solo para quien entra a Promos, y de sus marcas. */
function ss_promo_search(PDO $db, array $ctx, string $q): array {
    if (!g7_can($ctx['id'], 'promos')) ss_fail('Para elegir contenidos necesitas acceso a Promos.', 403);
    try {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim($q), 0, 60)) . '%';
        $w = g7_brand_sql("CONCAT_WS(' ', project, projects)", g7_brand_scope($ctx['id']));
        $s = $db->prepare("SELECT id, name, img, project, start_date FROM g7_content WHERE name LIKE ? AND $w ORDER BY id DESC LIMIT 20"); $s->execute([$like]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'img' => (string)$r['img'], 'project' => (string)$r['project'], 'date' => $r['start_date']], $s->fetchAll(PDO::FETCH_ASSOC));
    } catch (SsError $e) { throw $e; } catch (Throwable $e) { return []; }
}
