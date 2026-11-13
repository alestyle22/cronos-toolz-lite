<?php
/**
 * forms_lib.php — Formularios de Promos armables por el admin.
 * Config en g7_config 'promos_forms':
 *   types:            [{key, label, icon, color, hidden, builtin, fields:[{key, label, hidden, required, custom, kind, options}]}]
 *   category_options: opciones del campo "Categoría"
 * Los campos del sistema son los que ya tenía el formulario (se pueden renombrar, ocultar, ordenar y hacer obligatorios).
 * Los campos nuevos guardan su valor en g7_content.extra (JSON {clave: valor}).
 * Los tipos nuevos se guardan en g7_content.type con su clave (VARCHAR).
 */

const PF_BUILTIN_TYPES = [
    'promo'    => ['Promo / Torneo', 'fa-tag',               '#ff6600'],
    'launch'   => ['Lanzamiento',    'fa-rocket',            '#a855f7'],
    'paid'     => ['Paid',           'fa-dollar-sign',       '#22c55e'],
    'social'   => ['Redes Sociales', 'fa-share-nodes',       '#3b82f6'],
    'otro'     => ['Otro',           'fa-ellipsis',          '#f59e0b'],
    'custom'   => ['Custom',         'fa-puzzle-piece',      '#06b6d4'],
    'tarea'    => ['Tarea rápida',   'fa-bolt',              '#ec4899'],
    'mailings' => ['Mailings',       'fa-envelope-open-text','#14b8a6'],
];
// Campos del sistema de cada tipo, en el orden en que aparecen hoy
const PF_TAIL = ['cover', 'graphics', 'author', 'prod', 'cx'];
const PF_BUILTIN_FIELDS = [
    'promo'    => ['name', 'project', 'category', 'multi', 'mailing', 'landing'],
    'launch'   => ['name', 'projects'],
    'paid'     => ['name', 'project', 'multi', 'media'],
    'social'   => ['name', 'project', 'multi', 'media'],
    'otro'     => ['name', 'project', 'multi', 'media', 'otro_field'],
    'custom'   => ['name', 'content_type', 'custom_date', 'resources'],
    'tarea'    => ['name', 'tarea_qty', 'tarea_desc'],
    'mailings' => ['name', 'mailings_qty', 'mailings_link'],
    // Tipos nuevos: pueden usar estos (los que no vienen prendidos arrancan ocultos)
    '_new'     => ['name', 'project', 'multi', 'media', 'category', 'landing', 'mailing'],
];
const PF_NEW_HIDDEN = ['multi', 'category', 'landing', 'mailing'];
// jira: vincular el contenido con tickets de Jira (solo si Admin › Jira API tiene Promos prendido; pf_jira_sync)
const PF_KINDS = ['text', 'textarea', 'number', 'date', 'url', 'select', 'checkbox', 'jira'];
const PF_ICONS = ['fa-tag','fa-rocket','fa-dollar-sign','fa-share-nodes','fa-ellipsis','fa-puzzle-piece','fa-bolt','fa-envelope-open-text',
    'fa-star','fa-trophy','fa-gift','fa-calendar-days','fa-bullhorn','fa-video','fa-image','fa-mobile','fa-gamepad','fa-futbol',
    'fa-dice','fa-crown','fa-fire','fa-heart','fa-newspaper','fa-pen-nib','fa-palette','fa-globe','fa-briefcase','fa-users'];

function pf_field_list(string $set, array $hidden = []): array {
    return array_map(fn($k) => ['key' => $k, 'hidden' => in_array($k, $hidden, true)], array_merge(PF_BUILTIN_FIELDS[$set], PF_TAIL));
}

function pf_default(): array {
    $types = [];
    foreach (PF_BUILTIN_TYPES as $k => [$label, $icon, $color]) {
        $types[] = ['key' => $k, 'label' => $label, 'icon' => $icon, 'color' => $color, 'hidden' => false, 'builtin' => true, 'fields' => pf_field_list($k)];
    }
    return ['types' => $types, 'category_options' => ['Casino', 'Deportes', 'General']];
}

function pf_str($v, int $max): string { return mb_substr(trim(strip_tags((string)$v)), 0, $max); }

/** Limpia una config que manda el navegador. Los tipos y campos del sistema no se pueden borrar: si faltan, se reponen. */
function pf_clean(array $in): array {
    $out = ['types' => [], 'category_options' => []];
    foreach ((array)($in['category_options'] ?? []) as $o) { $o = pf_str($o, 60); if ($o !== '' && !in_array($o, $out['category_options'], true)) $out['category_options'][] = $o; }
    $seen = [];
    foreach ((array)($in['types'] ?? []) as $t) {
        if (!is_array($t)) continue;
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($t['key'] ?? '')));
        if ($key === '' || strlen($key) > 40 || isset($seen[$key]) || $key[0] === '_') continue;
        $builtin = isset(PF_BUILTIN_TYPES[$key]);
        $allowed = array_merge(PF_BUILTIN_FIELDS[$builtin ? $key : '_new'], PF_TAIL);
        $fields = []; $fseen = [];
        foreach ((array)($t['fields'] ?? []) as $f) {
            if (!is_array($f)) continue;
            $fk = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($f['key'] ?? '')));
            if ($fk === '' || isset($fseen[$fk])) continue;
            $custom = !empty($f['custom']);
            if ($custom ? strpos($fk, 'x_') !== 0 : !in_array($fk, $allowed, true)) continue;
            $row = ['key' => $fk, 'label' => pf_str($f['label'] ?? '', 60), 'hidden' => !empty($f['hidden']) && $fk !== 'name', 'required' => !empty($f['required']) && !in_array($fk, ['prod', 'cx', 'multi', 'author'], true)];
            if (in_array($f['width'] ?? '', ['full', 'half'], true)) $row['width'] = $f['width'];
            if ($custom) {
                $row['custom'] = true;
                $row['kind'] = in_array($f['kind'] ?? '', PF_KINDS, true) ? $f['kind'] : 'text';
                if ($row['label'] === '') $row['label'] = 'Campo';
                if ($row['kind'] === 'select') $row['options'] = array_values(array_unique(array_filter(array_map(fn($o) => pf_str($o, 80), (array)($f['options'] ?? [])), 'strlen')));
            }
            $fields[] = $row; $fseen[$fk] = 1;
        }
        // Campos del sistema que no llegaron: al final y ocultos (salvo el nombre)
        foreach ($allowed as $fk) if (!isset($fseen[$fk])) $fields[] = ['key' => $fk, 'label' => '', 'hidden' => $fk !== 'name' && ($builtin || in_array($fk, PF_NEW_HIDDEN, true)), 'required' => false];
        $color = preg_match('/^#[0-9a-f]{6}$/i', (string)($t['color'] ?? '')) ? strtolower($t['color']) : '#71717a';
        $out['types'][] = [
            'key' => $key, 'label' => pf_str($t['label'] ?? '', 40) ?: ($builtin ? PF_BUILTIN_TYPES[$key][0] : $key),
            'icon' => in_array($t['icon'] ?? '', PF_ICONS, true) ? $t['icon'] : ($builtin ? PF_BUILTIN_TYPES[$key][1] : 'fa-star'),
            'color' => $color, 'hidden' => !empty($t['hidden']), 'builtin' => $builtin, 'fields' => $fields,
        ];
        $seen[$key] = 1;
    }
    foreach (pf_default()['types'] as $t) if (!isset($seen[$t['key']])) $out['types'][] = $t;
    return $out;
}

function pf_config(): array {
    $c = g7_cfg_json('promos_forms', []);
    if (!is_array($c) || empty($c['types'])) return pf_default();
    $c = pf_clean($c);
    if (!$c['category_options']) $c['category_options'] = pf_default()['category_options'];
    return $c;
}

function pf_type_ok(string $type): bool {
    foreach (pf_config()['types'] as $t) if ($t['key'] === $type) return true;
    return false;
}

/** Valores de los campos nuevos del tipo $type, limpios. $raw es JSON o array {clave: valor}. */
function pf_extra_clean($raw, string $type): ?string {
    $in = is_array($raw) ? $raw : json_decode((string)$raw, true);
    if (!is_array($in)) return null;
    $out = [];
    foreach (pf_config()['types'] as $t) {
        if ($t['key'] !== $type) continue;
        foreach ($t['fields'] as $f) {
            if (empty($f['custom']) || !array_key_exists($f['key'], $in)) continue;
            $v = $in[$f['key']];
            if ($f['kind'] === 'checkbox') { if ($v && $v !== '0') $out[$f['key']] = '1'; continue; }
            if ($f['kind'] === 'jira') {   // claves de tickets (DIS-12,CRM-3), sin repetir
                $keys = array_values(array_unique(array_filter(array_map(fn($k) => strtoupper(trim($k)), preg_split('/[\s,]+/', (string)$v)), fn($k) => preg_match('/^[A-Z][A-Z0-9_]{0,19}-\d{1,9}$/', $k))));
                if ($keys) $out[$f['key']] = implode(',', array_slice($keys, 0, 20));
                continue;
            }
            $v = mb_substr(trim((string)$v), 0, $f['kind'] === 'textarea' ? 4000 : 1000);
            if ($v === '') continue;
            if ($f['kind'] === 'number' && !is_numeric(str_replace(',', '.', $v))) continue;
            if ($f['kind'] === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) continue;
            if ($f['kind'] === 'url' && preg_match('/^\s*javascript:/i', $v)) continue;
            $out[$f['key']] = $v;
        }
    }
    return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : null;
}

function pf_install(PDO $db): void {
    try { $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS extra TEXT NULL DEFAULT NULL"); } catch (Throwable $e) {}
    // Los tipos nuevos no entran en el ENUM viejo
    try {
        $col = $db->query("SHOW COLUMNS FROM g7_content LIKE 'type'")->fetch(PDO::FETCH_ASSOC);
        if ($col && stripos($col['Type'], 'enum') === 0) $db->exec("ALTER TABLE g7_content MODIFY COLUMN type VARCHAR(40) NOT NULL DEFAULT 'promo'");
    } catch (Throwable $e) {}
}

/** POST JSON {action:'forms_save', config} — solo admin. Devuelve la config guardada. */
function pf_handle_save(PDO $db, bool $isAdmin, array $input): void {
    header('Content-Type: application/json');
    if (!$isAdmin) { echo json_encode(['ok' => false, 'error' => 'Solo el admin arma los formularios']); exit; }
    if (!empty($input['reset'])) { g7_cfg_set('promos_forms', ''); echo json_encode(['ok' => true, 'config' => pf_default()]); exit; }
    $new = pf_clean((array)($input['config'] ?? []));
    // No se borra un tipo que ya tiene contenidos: se puede ocultar
    $keep = array_column($new['types'], 'key');
    foreach (pf_config()['types'] as $t) {
        if (in_array($t['key'], $keep, true)) continue;
        $q = $db->prepare("SELECT COUNT(*) FROM g7_content WHERE type = ?"); $q->execute([$t['key']]);
        if ($n = (int)$q->fetchColumn()) { echo json_encode(['ok' => false, 'error' => "«{$t['label']}» tiene $n " . ($n === 1 ? 'contenido' : 'contenidos') . ": ocultala en vez de borrarla"]); exit; }
    }
    g7_cfg_set('promos_forms', $new);
    echo json_encode(['ok' => true, 'config' => $new], JSON_UNESCAPED_UNICODE); exit;
}

/**
 * Campos Jira del formulario → vínculos del contenido (g7_jira_links). Vincula los tickets nuevos del campo y quita los que
 * se sacaron del campo (los vinculados desde el detalle no se tocan). $oldExtra = extra antes de guardar (null al crear).
 * Si Jira no está prendido para Promos, no hace nada. Devuelve los avisos (ticket que no existe, Jira sin respuesta…).
 */
function pf_jira_sync(PDO $db, int $uid, int $contentId, ?string $newExtra, ?string $oldExtra, string $type): array {
    if (!is_file(dirname(__DIR__) . '/jira_use_lib.php')) return [];
    require_once dirname(__DIR__) . '/jira_use_lib.php';
    if (!g7_jira_on('promos')) return [];
    $keysOf = function (?string $extra) use ($type) {
        $ex = json_decode((string)$extra, true); $ex = is_array($ex) ? $ex : []; $k = [];
        foreach (pf_config()['types'] as $t) if ($t['key'] === $type) foreach ($t['fields'] as $f)
            if (!empty($f['custom']) && ($f['kind'] ?? '') === 'jira' && !empty($ex[$f['key']])) $k = array_merge($k, explode(',', $ex[$f['key']]));
        return array_values(array_unique($k));
    };
    $new = $keysOf($newExtra); $old = $keysOf($oldExtra); $target = 'content:' . $contentId; $warn = [];
    foreach (array_diff($new, $old) as $k) { try { g7_jira_link($db, $uid, $target, $k); } catch (Throwable $e) { $warn[] = $e->getMessage(); } }
    foreach (array_diff($old, $new) as $k) g7_jira_unlink($db, $target, $k);
    // Al editar, los del campo que se hayan quitado desde el detalle vuelven a quedar vinculados
    if ($oldExtra !== null) { $have = array_column(g7_jira_links_for($db, $target), 'key'); foreach (array_diff(array_intersect($new, $old), $have) as $k) { try { g7_jira_link($db, $uid, $target, $k); } catch (Throwable $e) { $warn[] = $e->getMessage(); } } }
    return $warn;
}