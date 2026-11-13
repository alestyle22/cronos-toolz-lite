<?php
/**
 * rubro_lib.php — Rubros: paquetes (site/rubros/<id>.json) que acomodan la plataforma a un tipo de negocio.
 * g7_config.rubro = id aplicado. Sin rubro (los sitios de siempre) todo queda como hoy: "agencia" sin tocar nada.
 * rubro_apply() aplica las partes elegidas y NUNCA borra datos: los estados en uso se conservan, los campos propios quedan,
 * los tipos de contenido que sobran se ocultan y las herramientas que apagó un rubro se vuelven a prender al cambiar.
 * Lo usan instalar.php (paso Rubro), admin_rubro.php (Admin › Rubro) y dev/test_rubros.php. Doc: toolz/RUBROS.md
 */
require_once __DIR__ . '/vocab_lib.php';
require_once __DIR__ . '/status_lib.php';
require_once __DIR__ . '/custom_fields_lib.php';

const RUBRO_DIR = __DIR__ . '/rubros';
const RUBRO_PARTS = [
    'vocab' => 'Nombres (clientes, contenidos, proyectos…)', 'types' => 'Tipos de cliente', 'statuses' => 'Estados', 'fields' => 'Campos de la ficha',
    'contents' => 'Tipos de contenido', 'home' => 'Inicio', 'templates' => 'Plantillas de tareas y carpetas', 'modules' => 'Herramientas', 'look' => 'Color de acento', 'billing' => 'Conceptos de facturación',
];
const RUBRO_STATUS_LISTS = ['clients', 'services', 'projects'];   // las que trae un rubro (tickets, pedidos y facturas quedan como están)
// Herramientas que un rubro nunca apaga
// Módulos extra (los prende la licencia o el rubro con su propio interruptor): el plan de módulos del rubro no los apaga ni los prende
const RUBRO_EXTRA_MODULES = ['propiedades', 'vencimientos', 'turnos', 'talentos', 'builds', 'juegos'];
const RUBRO_KEEP_MODULES = ['dashboard', 'perfil', 'admin', 'tareas', 'solicitudes', 'chat', 'clientes', 'facturacion', 'calendario'];

/** Todos los rubros: id => preset, en el orden de 'order'. */
function rubro_all(): array {
    static $all = null;
    if ($all !== null) return $all;
    $all = [];
    foreach (glob(RUBRO_DIR . '/*.json') ?: [] as $f) {
        $p = json_decode((string)file_get_contents($f), true);
        if (is_array($p) && ($p['id'] ?? '') === basename($f, '.json')) $all[$p['id']] = $p;
    }
    uasort($all, fn($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));
    return $all;
}
function rubro_preset(string $id): ?array { return rubro_all()[$id] ?? null; }
/** Lo que se muestra en tarjetas (instalador, Admin, base). */
function rubro_list(): array {
    return array_values(array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'desc' => $p['desc'], 'icon' => $p['icon'], 'accent' => $p['accent'],
        'client' => $p['vocab']['client']['p'] ?? 'Clientes', 'types' => array_column($p['client_types'], 'label'), 'extras' => $p['extras']], rubro_all()));
}
/** El rubro aplicado: '' en los sitios que nunca eligieron uno (se comportan como agencia). */
function rubro_get(): string { $r = function_exists('g7_cfg') ? (string)g7_cfg('rubro', '') : (string)(vocab_cfg('rubro_id', '') ?: ''); return rubro_preset($r) ? $r : ''; }
function rubro_name(string $id = ''): string { $id = $id ?: rubro_get(); return $id ? (rubro_preset($id)['name'] ?? $id) : 'Agencia creativa (sin rubro elegido)'; }

// ── Qué cambiaría ─────────────────────────────────────────
/** parte => ['changes' => bool, 'lines' => [texto, ...]] para mostrar antes de aplicar. */
function rubro_diff(PDO $db, string $id): array {
    $p = rubro_preset($id); if (!$p) throw new RuntimeException('Ese rubro no existe.');
    $out = [];
    // Nombres
    $lines = []; $now = g7_terms(true); $new = VOCAB_DEFAULT;
    foreach ($p['vocab'] as $k => $v) if (isset($new[$k])) $new[$k] = $v + $new[$k];
    foreach ($new as $k => $v) if (($now[$k]['p'] ?? '') !== $v['p'] || ($now[$k]['s'] ?? '') !== $v['s']) $lines[] = ($now[$k]['p'] ?? $k) . ' → ' . $v['p'];
    $out['vocab'] = $lines;
    // Tipos de cliente
    $cur = array_column(cf_client_types(), 'label'); $nt = array_column($p['client_types'], 'label');
    $out['types'] = $cur === $nt ? [] : [$nt ? 'Tipos: ' . implode(', ', $nt) : 'Sin tipos de cliente'];
    // Estados
    $lines = [];
    foreach (RUBRO_STATUS_LISTS as $l) {
        $curL = array_column(st_list($l), 'label');
        $newL = isset($p['statuses'][$l]) ? array_column($p['statuses'][$l], 1) : array_column(ST_LISTS[$l]['items'], 1);
        if ($curL !== $newL) $lines[] = ST_LISTS[$l]['label'] . ': ' . implode(', ', $newL);
    }
    $out['statuses'] = $lines;
    // Campos
    $have = array_column(cf_fields($db, 'client'), 'key'); $lines = [];
    $add = array_values(array_filter($p['fields'], fn($f) => !in_array(cf_slug($f['key']), $have, true)));
    if ($add) $lines[] = 'Se agregan: ' . implode(', ', array_column($add, 'label'));
    $hide = array_values(array_diff($p['hide_fields'], cf_client_hidden())); $show = array_values(array_diff(cf_client_hidden(), $p['hide_fields']));
    if ($hide) $lines[] = 'Se ocultan: ' . implode(', ', array_map(fn($k) => CF_CLIENT_BUILTIN[$k] ?? $k, $hide));
    if ($show) $lines[] = 'Vuelven a verse: ' . implode(', ', array_map(fn($k) => CF_CLIENT_BUILTIN[$k] ?? $k, $show));
    $out['fields'] = $lines;
    // Contenidos, Inicio, plantillas, herramientas, color, facturación
    $out['contents'] = $p['content_types'] ? ['Tipos: ' . implode(', ', array_column($p['content_types'], 'label'))] : ($id === 'agencia' ? ['Vuelven los tipos de siempre'] : []);
    $out['home'] = $p['home']['tiles'] ? ['Accesos: ' . implode(', ', array_column($p['home']['tiles'], 'name'))] : ($id === 'agencia' ? ['Vuelven los accesos de siempre'] : []);
    $tn = []; try { $tn = $db->query('SELECT name FROM g7_task_templates')->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
    $nt = array_values(array_diff(array_column($p['task_templates'], 'name'), $tn));
    $out['templates'] = array_merge($nt ? ['Plantillas nuevas: ' . implode(', ', $nt)] : [], $id !== 'agencia' && $p['folders'] !== g7_cfg_json('client_folders', []) ? ['Carpetas de cada ficha: ' . implode(', ', $p['folders'])] : []);
    [$off, $on] = rubro_modules_plan($p);
    $lines = []; $mods = function_exists('g7_modules') ? g7_modules() : [];
    if ($off) $lines[] = 'Se apagan: ' . implode(', ', array_map(fn($m) => $mods[$m]['label'] ?? $m, $off));
    if ($on) $lines[] = 'Se vuelven a prender: ' . implode(', ', array_map(fn($m) => $mods[$m]['label'] ?? $m, $on));
    $out['modules'] = $lines;
    $acc = function_exists('g7_setting') ? (string)g7_setting('accent_color') : '';
    $out['look'] = strtolower($acc) !== strtolower($p['accent']) ? ['Color de acento ' . $p['accent']] : [];
    $out['billing'] = $p['billing']['concepts'] ? ['Conceptos: ' . implode(', ', $p['billing']['concepts'])] : [];
    return $out;
}

/** [apagar, volver a prender] según el rubro. Agencia no apaga nada: solo prende lo que apagó otro rubro. */
function rubro_modules_plan(array $p): array {
    if (!function_exists('g7_modules')) return [[], []];
    $mods = g7_modules(); $pol = g7_cfg_json('module_policies', []); $prev = (array)g7_cfg_json('rubro_modules_off', []);
    $want = $p['id'] === 'agencia' ? null : array_merge($p['modules_on'], RUBRO_KEEP_MODULES);
    $off = []; $on = [];
    foreach ($mods as $id => $m) {
        if (!empty($m['core']) || !empty($m['custom']) || !empty($m['site_off']) || in_array($id, RUBRO_EXTRA_MODULES, true)) continue;
        $isOff = ($pol[$id]['mode'] ?? '') === 'off';
        $shouldOff = $want !== null && !in_array($id, $want, true);
        if ($shouldOff && !$isOff) $off[] = $id;
        elseif (!$shouldOff && $isOff && in_array($id, $prev, true)) $on[] = $id;   // solo lo que apagó un rubro: lo que apagó la agencia queda apagado
    }
    return [$off, $on];
}

// ── Aplicar ───────────────────────────────────────────────
/** Aplica el rubro. $parts = claves de RUBRO_PARTS (null = todas). Devuelve las partes aplicadas. */
function rubro_apply(PDO $db, string $id, ?array $parts = null): array {
    $p = rubro_preset($id); if (!$p) throw new RuntimeException('Ese rubro no existe.');
    $parts = $parts === null ? array_keys(RUBRO_PARTS) : array_values(array_intersect($parts, array_keys(RUBRO_PARTS)));
    $has = fn($k) => in_array($k, $parts, true);
    $clients = is_file(__DIR__ . '/clientes_lib.php') && (!function_exists('g7_site_has') || g7_site_has('clientes'));
    if ($clients) { require_once __DIR__ . '/clientes_lib.php'; cli_install($db); }
    cf_install($db);

    if ($has('vocab')) { g7_cfg_set('vocab', $p['vocab']); g7_terms(true); }
    if ($has('types')) g7_cfg_set('client_types', cf_client_types_clean($p['client_types']));
    if ($has('statuses')) foreach (RUBRO_STATUS_LISTS as $l) {
        $items = isset($p['statuses'][$l]) ? array_map(fn($x) => ['key' => $x[0], 'label' => $x[1], 'color' => $x[2]], $p['statuses'][$l]) : null;
        // Los estados que hoy usa algún registro y el rubro nuevo no trae se conservan (al final), para no dejar datos colgados
        $inUse = rubro_status_in_use($db, $l);
        $cur = st_labels($l); $base = $items ?? array_map(fn($x) => ['key' => $x[0], 'label' => $x[1], 'color' => $x[2]], ST_LISTS[$l]['items']);
        $keys = array_column($base, 'key');
        foreach ($inUse as $k) if (!in_array($k, $keys, true)) { $base[] = ['key' => $k, 'label' => $cur[$k] ?? $k, 'color' => '#6b7280']; $items = $base; }
        if ($err = st_save($l, $items)) throw new RuntimeException($err);
    }
    if ($has('fields')) {
        foreach ($p['fields'] as $f) cf_ensure($db, 'client', $f);
        g7_cfg_set('client_hidden', array_values(array_intersect($p['hide_fields'], array_keys(CF_CLIENT_BUILTIN))));
    }
    if ($has('contents') && is_file(__DIR__ . '/promos/forms_lib.php')) rubro_apply_contents($p);
    if ($has('home') && is_file(__DIR__ . '/home_lib.php')) {
        require_once __DIR__ . '/home_lib.php';
        $c = home_config_all();
        if ($p['home']['tiles']) $c['tiles'] = array_map(fn($t) => $t + ['badge' => '', 'newtab' => false, 'on' => true, 'audience' => 'all', 'teams' => []], $p['home']['tiles']);
        elseif ($id === 'agencia') $c['tiles'] = home_default_tiles();
        $c['subtitle'] = (string)$p['home']['subtitle'];
        g7_cfg_set('home_config', home_sanitize($c));
    }
    if ($has('templates')) g7_cfg_set('client_folders', $id === 'agencia' ? [] : $p['folders']);   // carpetas de Materiales de cada ficha nueva
    if ($has('templates') && $p['task_templates'] && is_file(__DIR__ . '/tareas_lib.php')) {
        require_once __DIR__ . '/tareas_lib.php'; tk_install($db);
        $have = $db->query('SELECT name FROM g7_task_templates')->fetchAll(PDO::FETCH_COLUMN);
        $ins = $db->prepare('INSERT INTO g7_task_templates (name, data, created_at) VALUES (?, ?, ?)');
        foreach ($p['task_templates'] as $t) if (!in_array($t['name'], $have, true))
            $ins->execute([$t['name'], json_encode(['title' => $t['name'], 'description' => '', 'priority' => '', 'labels' => [], 'assignees' => [], 'repeat_rule' => $t['repeat_rule'] ?? '', 'due_in' => (int)($t['due_in'] ?? 7), 'checks' => $t['checks']], JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]);
    }
    if ($has('modules') && function_exists('g7_modules')) {
        [$off, $on] = rubro_modules_plan($p);
        $pol = g7_cfg_json('module_policies', []); $prev = (array)g7_cfg_json('rubro_modules_off', []);
        foreach ($off as $m) { $pol[$m]['mode'] = 'off'; $prev[] = $m; }
        foreach ($on as $m) { unset($pol[$m]['mode']); $prev = array_diff($prev, [$m]); }
        g7_cfg_set('module_policies', $pol); g7_cfg_set('rubro_modules_off', array_values(array_unique($prev)));
    }
    if ($has('look')) g7_cfg_set('accent_color', $p['accent']);
    if ($has('billing')) g7_cfg_set('billing_concepts', $p['billing']['concepts']);
    g7_cfg_set('rubro', $id);
    // Módulos extra del rubro que ya existen: su link en el menú (una sola vez; después la agencia lo mueve o lo saca)
    if (in_array('propiedades', $p['extras'], true) && is_file(__DIR__ . '/propiedades/index.php') && function_exists('g7_nav_add_once'))
        g7_nav_add_once('nav_propiedades_v1', ['href' => '/toolz/propiedades/', 'icon' => 'fa-building', 'label' => 'Propiedades', 'enabled' => true], '/toolz/clientes.php');
    foreach (['talentos' => ['talentos', 'fa-star', 'Talentos'], 'builds' => ['builds', 'fa-bug', 'Builds y bugs'], 'juegos' => ['juegos', 'fa-gamepad', 'Juegos']] as $ex => [$dir, $ic, $lb])
        if (in_array($ex, $p['extras'], true) && is_file(__DIR__ . "/$dir/index.php") && function_exists('g7_nav_add_once'))
            g7_nav_add_once("nav_{$dir}_v1", ['href' => "/toolz/$dir/", 'icon' => $ic, 'label' => $lb, 'enabled' => true], '/toolz/clientes.php');
    if (in_array('turnos', $p['extras'], true) && is_file(__DIR__ . '/turnos/index.php') && function_exists('g7_nav_add_once'))
        g7_nav_add_once('nav_turnos_v1', ['href' => '/toolz/turnos/', 'icon' => 'fa-calendar-check', 'label' => 'Turnos', 'enabled' => true], '/toolz/clientes.php');
    if (in_array('vencimientos', $p['extras'], true) && is_file(__DIR__ . '/vencimientos/index.php') && function_exists('g7_nav_add_once'))
        g7_nav_add_once('nav_vencimientos_v1', ['href' => '/toolz/vencimientos/', 'icon' => 'fa-calendar-xmark', 'label' => 'Vencimientos', 'enabled' => true], '/toolz/clientes.php');
    if (function_exists('g7_audit')) g7_audit('rubro.apply', $id, ['parts' => $parts]);
    return $parts;
}
/** Claves de estado que hoy usa algún registro de esa lista. */
function rubro_status_in_use(PDO $db, string $list): array {
    $sql = ['clients' => 'SELECT DISTINCT status FROM g7_clients', 'services' => 'SELECT DISTINCT status FROM g7_client_services', 'projects' => 'SELECT DISTINCT status FROM g7_wk_projects'][$list] ?? null;
    if (!$sql) return [];
    try { return array_values(array_filter(array_map('strval', $db->query($sql)->fetchAll(PDO::FETCH_COLUMN)))); } catch (Throwable $e) { return []; }
}
/** Tipos de contenido: muestra los del rubro (los crea si faltan) y oculta los demás. Ninguno se borra. */
function rubro_apply_contents(array $p): void {
    require_once __DIR__ . '/promos/forms_lib.php';
    $c = pf_config(); $want = []; foreach ($p['content_types'] as $t) $want[$t['key']] = $t;
    $rubroKeys = []; foreach (rubro_all() as $r) foreach ($r['content_types'] as $t) $rubroKeys[$t['key']] = 1;
    foreach ($c['types'] as &$t) {
        if (isset($want[$t['key']])) { $w = $want[$t['key']]; $t['hidden'] = false; $t['label'] = $w['label']; $t['icon'] = $w['icon']; $t['color'] = $w['color']; unset($want[$t['key']]); }
        elseif ($p['id'] === 'agencia') $t['hidden'] = !$t['builtin'] && isset($rubroKeys[$t['key']]) ? true : ($t['builtin'] ? false : $t['hidden']);
        else $t['hidden'] = $t['key'] !== 'tarea';
    }
    unset($t);
    foreach ($want as $w) $c['types'][] = ['key' => $w['key'], 'label' => $w['label'], 'icon' => $w['icon'], 'color' => $w['color'], 'hidden' => false, 'builtin' => false, 'fields' => pf_field_list('_new', PF_NEW_HIDDEN)];
    g7_cfg_set('promos_forms', pf_clean($c));
}

// ── Datos de ejemplo ──────────────────────────────────────
function rubro_samples(): array { $s = g7_cfg_json('rubro_samples', []); return ['clients' => array_map('intval', (array)($s['clients'] ?? [])), 'tasks' => array_map('intval', (array)($s['tasks'] ?? [])), 'props' => array_map('intval', (array)($s['props'] ?? [])), 'due_types' => array_map('intval', (array)($s['due_types'] ?? [])), 'ap_pros' => array_map('intval', (array)($s['ap_pros'] ?? []))]; }
function rubro_samples_has(): bool { $s = rubro_samples(); return (bool)($s['clients'] || $s['tasks'] || $s['props']); }
/** Carga los ejemplos del rubro (clientes con sus campos y tareas). Devuelve cuántos de cada uno. */
function rubro_samples_seed(PDO $db, string $id, ?int $userId = null): array {
    $p = rubro_preset($id); if (!$p) throw new RuntimeException('Ese rubro no existe.');
    $s = rubro_samples(); $nc = 0; $nt = 0; $first = null;
    if (is_file(__DIR__ . '/clientes_lib.php') && (!function_exists('g7_site_has') || g7_site_has('clientes'))) {
        require_once __DIR__ . '/clientes_lib.php'; cli_install($db); cf_install($db);
        $types = array_column(cf_client_types(), 'key');
        foreach ($p['samples']['clients'] as $c) {
            $st = st_has('clients', $c['status'] ?? '') ? $c['status'] : st_default('clients');
            $db->prepare('INSERT INTO g7_clients (name, status, type, contact_name, email, phone, notes, created_by) VALUES (?,?,?,?,?,?,?,?)')
               ->execute([$c['name'], $st, in_array($c['type'] ?? '', $types, true) ? $c['type'] : '', $c['contact_name'] ?? '', $c['email'] ?? '', $c['phone'] ?? '', 'Dato de ejemplo: se borra desde Admin › Rubro.', $userId]);
            $cid = (int)$db->lastInsertId(); $s['clients'][] = $cid; $first = $first ?? $cid; $nc++;
            if (!empty($c['fields'])) cf_set_values($db, 'client', $cid, $c['fields']);
        }
    }
    if (is_file(__DIR__ . '/tareas_lib.php')) {
        require_once __DIR__ . '/tareas_lib.php'; tk_install($db);
        $cfg = tk_config($db); $now = date('Y-m-d H:i:s');
        foreach ($p['samples']['tasks'] as $i => $t) {
            $db->prepare('INSERT INTO g7_tasks (title, description, status, priority, due_date, client_id, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
               ->execute([$t['title'], 'Tarea de ejemplo: se borra desde Admin › Rubro.', $cfg['statuses'][0]['id'], $cfg['default_priority'], date('Y-m-d', strtotime('+' . (3 + $i * 2) . ' days')), $first, $userId, $now, $now]);
            $s['tasks'][] = (int)$db->lastInsertId(); $nt++;
        }
    }
    // Propiedades de ejemplo (inmobiliaria): con propietario, interesados, una visita y avisos, atados a los clientes de ejemplo por nombre
    $np = 0;
    if (!empty($p['samples']['properties']) && is_file(__DIR__ . '/propiedades/lib.php') && (!function_exists('g7_site_has') || g7_site_has('clientes'))) {
        require_once __DIR__ . '/propiedades/lib.php'; pr_install($db);
        $byName = []; foreach ($s['clients'] as $cid) { $n = $db->query('SELECT name FROM g7_clients WHERE id = ' . (int)$cid)->fetchColumn(); if ($n !== false) $byName[$n] = (int)$cid; }
        $uid = (int)($userId ?: 0);
        foreach ($p['samples']['properties'] as $x) {
            $x['owner_id'] = $byName[$x['owner'] ?? ''] ?? 0; $x['agent_id'] = $uid; $x['portal_show'] = 1; $x['private_notes'] = 'Dato de ejemplo: se borra desde Admin › Rubro.';
            $pid = pr_save($db, $x, $uid); $s['props'][] = $pid; $np++;
            foreach ($x['leads'] ?? [] as $l) if (isset($byName[$l['name']])) pr_lead_save($db, $pid, $byName[$l['name']], $l, $uid);
            if (!empty($x['visit']) && $uid && isset($byName[$x['visit']['name']]))
                pr_visit_save($db, ['prop_id' => $pid, 'client_id' => $byName[$x['visit']['name']], 'agent_id' => $uid, 'date' => date('Y-m-d', strtotime('+' . (int)$x['visit']['in_days'] . ' days')), 'time' => $x['visit']['time']], $uid);
            foreach ($x['listings'] ?? [] as $l) pr_listing_save($db, $l + ['prop_id' => $pid, 'published_at' => date('Y-m-d', strtotime('-10 days'))]);
        }
    }
    // Vencimientos de ejemplo (estudio): las obligaciones de ejemplo, el CUIT de cada cliente y sus asignaciones; el barrido crea las tareas cercanas
    $nd = 0;
    if (!empty($p['samples']['due']) && is_file(__DIR__ . '/vencimientos/lib.php') && (!function_exists('g7_site_has') || g7_site_has('clientes'))) {
        require_once __DIR__ . '/vencimientos/lib.php'; ven_install($db);
        $before = array_column(ven_types($db), 'id'); ven_seed_types($db);
        $s['due_types'] = array_values(array_diff(array_column(ven_types($db), 'id'), $before));
        $tid = array_column(ven_types($db), 'id', 'name');
        $byName = []; foreach ($s['clients'] as $cid) { $n = $db->query('SELECT name FROM g7_clients WHERE id = ' . (int)$cid)->fetchColumn(); if ($n !== false) $byName[$n] = (int)$cid; }
        foreach ($p['samples']['due'] as $d) {
            $cid = $byName[$d['client']] ?? 0; if (!$cid) continue;
            if (!empty($d['cuit'])) $db->prepare('UPDATE g7_clients SET cuit = ? WHERE id = ?')->execute([$d['cuit'], $cid]);
            foreach ($d['types'] as $tn) if (isset($tid[$tn])) { ven_assign($db, $cid, (int)$tid[$tn], true, $userId ?: null); $nd++; }
        }
        if ($nd) ven_sweep($db);
    }
    // Turnos de ejemplo (salud): un profesional con horario y turnos en los próximos días hábiles para los pacientes de ejemplo
    $na = 0;
    if (!empty($p['samples']['appts']) && is_file(__DIR__ . '/turnos/lib.php') && (!function_exists('g7_site_has') || g7_site_has('clientes'))) {
        require_once __DIR__ . '/turnos/lib.php'; ap_install($db);
        $sp = $p['samples']['appts'];
        $pid = ap_pro_save($db, $sp['pro'] + ['user_id' => $userId ?: 0]); $s['ap_pros'][] = $pid; $pro = ap_pro($db, $pid);
        $byName = []; foreach ($s['clients'] as $cid) { $n = $db->query('SELECT name FROM g7_clients WHERE id = ' . (int)$cid)->fetchColumn(); if ($n !== false) $byName[$n] = (int)$cid; }
        foreach ($sp['items'] as $x) {
            if (!isset($byName[$x['client']])) continue;
            $d = date('Y-m-d', strtotime('+' . (int)$x['day'] . ' days')); for ($k = 0; $k < 7 && !ap_free_slots($db, $pro, $d); $k++) $d = date('Y-m-d', strtotime("$d +1 day"));
            $free = ap_free_slots($db, $pro, $d); if (!$free) continue;
            ap_save($db, ['pro_id' => $pid, 'client_id' => $byName[$x['client']], 'date' => $d, 'time' => $free[0], 'reason' => $x['reason'] ?? '', 'status' => $x['status'] ?? 'reservado', 'notes' => 'Turno de ejemplo: se borra desde Admin › Rubro.'], (int)$userId); $na++;
        }
    }
    // Talentos (influencers) y builds (videojuegos) de ejemplo, atados a los clientes de ejemplo por nombre
    $nx = 0;
    $byName = []; foreach ($s['clients'] as $cid) { $n = $db->query('SELECT name FROM g7_clients WHERE id = ' . (int)$cid)->fetchColumn(); if ($n !== false) $byName[$n] = (int)$cid; }
    if (!empty($p['samples']['talent']) && is_file(__DIR__ . '/talentos/lib.php') && (!function_exists('g7_site_has') || g7_site_has('clientes'))) {
        require_once __DIR__ . '/talentos/lib.php'; tl_install($db); $st = $p['samples']['talent'];
        foreach ($st['profiles'] as $pr) if (isset($byName[$pr['client']])) { tl_profile_save($db, $byName[$pr['client']], $pr); $nx++; }
        $c = $st['campaign'];
        $camp = tl_campaign_save($db, ['name' => $c['name'], 'brand_id' => $byName[$c['brand']] ?? 0, 'status' => $c['status'], 'budget' => $c['budget'], 'start_date' => date('Y-m-d', strtotime('-7 days')), 'end_date' => date('Y-m-d', strtotime('+21 days')), 'brief' => 'Campaña de ejemplo: se borra desde Admin › Rubro.'], (int)$userId);
        foreach ($c['deliverables'] as $d) if (isset($byName[$d['talent']])) tl_deliverable_save($db, $d + ['campaign_id' => $camp, 'talent_id' => $byName[$d['talent']], 'due_date' => date('Y-m-d', strtotime((int)$d['in_days'] . ' days')), 'published_at' => date('Y-m-d', strtotime((int)$d['in_days'] . ' days'))]);
    }
    if (!empty($p['samples']['builds']) && is_file(__DIR__ . '/builds/lib.php') && (!function_exists('g7_site_has') || g7_site_has('clientes'))) {
        require_once __DIR__ . '/builds/lib.php'; bd_install($db); $sb = $p['samples']['builds']; $ids = [];
        foreach ($sb['builds'] as $b) { $ids[$b['version']] = bd_build_save($db, $b + ['project' => $sb['project'], 'client_id' => $byName[$sb['client']] ?? 0, 'shared' => 1, 'build_date' => date('Y-m-d', strtotime('-' . (int)$b['days_ago'] . ' days'))], (int)$userId); $nx++; }
        foreach ($sb['bugs'] as $g) { bd_bug_save($db, $g + ['project' => $sb['project'], 'found_build_id' => $ids[$g['found'] ?? ''] ?? 0, 'fixed_build_id' => $ids[$g['fixed'] ?? ''] ?? 0, 'reporter' => 'QA (ejemplo)'], (int)$userId); $nx++; }
        $s['bd_projects'] = [$sb['project']];
    }
    g7_cfg_set('rubro_samples', $s);
    return ['clients' => $nc, 'tasks' => $nt, 'props' => $np, 'due' => $nd, 'appts' => $na, 'extra' => $nx];
}
/** Borra solo los registros que cargó rubro_samples_seed(). */
function rubro_samples_clear(PDO $db): array {
    $s = rubro_samples(); $n = ['clients' => 0, 'tasks' => 0, 'props' => 0, 'due' => 0, 'appts' => 0, 'extra' => 0];
    // Talentos: perfiles, campañas de las marcas de ejemplo y entregables de los talentos de ejemplo. Builds: el proyecto de ejemplo
    if ($s['clients']) { try { $in = implode(',', $s['clients']);
        if ((int)g7_cfg('tl_schema', 0)) { $n['extra'] += $db->exec("DELETE FROM g7_tal_deliverables WHERE talent_id IN ($in) OR campaign_id IN (SELECT id FROM g7_tal_campaigns WHERE brand_id IN ($in))"); $db->exec("DELETE FROM g7_tal_campaigns WHERE brand_id IN ($in)"); $db->exec("DELETE FROM g7_tal_profiles WHERE client_id IN ($in)"); }
    } catch (Throwable $e) {} }
    foreach ((array)(g7_cfg_json('rubro_samples', [])['bd_projects'] ?? []) as $pj) { try { $n['extra'] += $db->prepare('DELETE FROM g7_bd_bugs WHERE project = ?')->execute([$pj]) ? 1 : 0; $db->prepare('DELETE FROM g7_bd_builds WHERE project = ?')->execute([$pj]); } catch (Throwable $e) {} }
    // Turnos de los pacientes de ejemplo y el profesional de ejemplo (si no le quedan turnos de otros)
    if (($s['clients'] || $s['ap_pros']) && is_file(__DIR__ . '/turnos/lib.php')) { try {
        require_once __DIR__ . '/turnos/lib.php';
        if ($s['clients']) foreach ($db->query('SELECT id FROM g7_appts WHERE client_id IN (' . implode(',', $s['clients']) . ')')->fetchAll(PDO::FETCH_COLUMN) as $a) { ap_delete($db, (int)$a); $n['appts']++; }
        foreach ($s['ap_pros'] as $pid) if (!$db->query('SELECT 1 FROM g7_appts WHERE pro_id = ' . (int)$pid)->fetchColumn()) { $db->prepare('DELETE FROM g7_appt_blocks WHERE pro_id = ?')->execute([$pid]); $db->prepare('DELETE FROM g7_appt_pros WHERE id = ?')->execute([$pid]); }
    } catch (Throwable $e) {} }
    // Vencimientos de los clientes de ejemplo: sus tareas, vencimientos y asignaciones; y las obligaciones de ejemplo que nadie más usa
    if ($s['clients']) { try {
        $in = implode(',', $s['clients']);
        foreach ($db->query("SELECT task_id FROM g7_due_items WHERE client_id IN ($in) AND task_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $t) foreach (['g7_task_checks', 'g7_task_assignees', 'g7_task_comments', 'g7_tasks'] as $tb) try { $db->prepare("DELETE FROM $tb WHERE " . ($tb === 'g7_tasks' ? 'id' : 'task_id') . ' = ?')->execute([(int)$t]); } catch (Throwable $e) {}
        $db->exec("DELETE FROM g7_due_items WHERE client_id IN ($in)"); $n['due'] = $db->exec("DELETE FROM g7_due_assign WHERE client_id IN ($in)");
        foreach ($s['due_types'] as $t) if (!$db->query('SELECT 1 FROM g7_due_assign WHERE type_id = ' . (int)$t)->fetchColumn()) { $db->prepare('DELETE FROM g7_due_items WHERE type_id = ?')->execute([$t]); $db->prepare('DELETE FROM g7_due_types WHERE id = ?')->execute([$t]); }
    } catch (Throwable $e) {} }
    if ($s['props'] && is_file(__DIR__ . '/propiedades/lib.php')) { require_once __DIR__ . '/propiedades/lib.php'; foreach ($s['props'] as $id) { try { if (pr_get($db, $id)) { pr_delete($db, $id); $n['props']++; } } catch (Throwable $e) {} } }
    foreach ($s['clients'] as $id) { try { cf_delete_values($db, 'client', $id); $db->prepare('DELETE FROM g7_client_services WHERE client_id = ?')->execute([$id]); } catch (Throwable $e) {}
        $q = $db->prepare('DELETE FROM g7_clients WHERE id = ?'); $q->execute([$id]); $n['clients'] += $q->rowCount(); }
    foreach ($s['tasks'] as $id) { foreach (['g7_task_checks', 'g7_task_comments'] as $t) try { $db->prepare("DELETE FROM $t WHERE task_id = ?")->execute([$id]); } catch (Throwable $e) {}
        $q = $db->prepare('DELETE FROM g7_tasks WHERE id = ?'); $q->execute([$id]); $n['tasks'] += $q->rowCount(); }
    g7_cfg_set('rubro_samples', []);
    return $n;
}
