<?php
/**
 * trabajo/form_lib.php — Formularios del Portal de Solicitudes (formato 2): esquema, condiciones, validación y conversión.
 * Todo lo que decide qué se guarda se calcula acá, en el servidor. El navegador (portal.js / builder.js) repite las mismas
 * reglas solo para mostrar errores al momento. Doc: toolz/SOLICITUDES.md
 *
 * Esquema: {v:2, mode:'full'|'steps', sections:[{id,title,desc,icon,items:[item…]}], rules:[regla de estimación…]}
 * Item: {id,type,label,desc,placeholder,required,default,options:[{v,l,icon,color,est}],valid:{…},cond:{op,rules},width,est,items(grupo)}
 */

/** Tipos de elemento: [nombre, ¿guarda un valor?]. */
const PT_TYPES = [
    'text' => ['Texto corto', true], 'textarea' => ['Texto largo', true], 'number' => ['Número', true], 'email' => ['Email', true],
    'phone' => ['Teléfono', true], 'select' => ['Desplegable', true], 'multiselect' => ['Selección múltiple', true], 'radio' => ['Opción única', true],
    'checkbox' => ['Casilla', true], 'date' => ['Fecha', true], 'time' => ['Hora', true], 'color' => ['Color', true], 'file' => ['Archivo', true],
    'image' => ['Imagen', true], 'url' => ['Link', true], 'priority' => ['Prioridad', true], 'catpick' => ['Categorías visuales', true],
    'separator' => ['Separador', false], 'heading' => ['Encabezado', false], 'paragraph' => ['Descripción', false], 'info' => ['Bloque informativo', false],
    'approval' => ['Aprobación', true], 'hidden' => ['Oculto interno', true], 'group' => ['Grupo', false], 'person' => ['Persona', true],
];
const PT_WIDTHS = ['1/1', '3/4', '2/3', '1/2', '1/3', '1/4'];
const PT_CMPS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'contains', 'ncontains', 'empty', 'filled', 'checked', 'unchecked', 'before', 'after', 'in'];
const PT_FILE_EXT = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'psd', 'ai', 'eps', 'zip', 'rar', '7z', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'mp4', 'mov', 'mp3', 'wav', 'ttf', 'otf', 'woff', 'woff2', 'fig', 'sketch', 'json'];
const PT_IMG_EXT = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'];

function pt_s($v, int $max): string { return mb_substr(trim((string)$v), 0, $max); }
function pt_id($v): string { return substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string)$v)), 0, 40); }

/** Limpia una condición (grupo Y/O anidado hasta 3 niveles). null = sin condición. */
function pt_clean_cond($c, int $depth = 0): ?array {
    if (!is_array($c) || $depth > 3) return null;
    $rules = [];
    foreach (array_slice((array)($c['rules'] ?? []), 0, 20) as $r) {
        if (!is_array($r)) continue;
        if (isset($r['rules'])) { if ($g = pt_clean_cond($r, $depth + 1)) $rules[] = $g; continue; }
        $f = pt_id($r['f'] ?? ''); $cmp = in_array($r['c'] ?? '', PT_CMPS, true) ? $r['c'] : 'eq';
        if ($f === '') continue;
        $v = $r['v'] ?? '';
        $v = is_array($v) ? array_values(array_map(fn($x) => pt_s($x, 200), array_slice($v, 0, 50))) : pt_s($v, 200);
        $rules[] = ['f' => $f, 'c' => $cmp, 'v' => $v];
    }
    return $rules ? ['op' => ($c['op'] ?? 'and') === 'or' ? 'or' : 'and', 'rules' => $rules] : null;
}

/** Evalúa una condición contra las respuestas (mismas reglas que ptCond() de portal.js). */
function pt_cond(?array $c, array $ans): bool {
    if (!$c || empty($c['rules'])) return true;
    $or = ($c['op'] ?? 'and') === 'or';
    foreach ($c['rules'] as $r) {
        $ok = isset($r['rules']) ? pt_cond($r, $ans) : pt_cmp($ans[$r['f']] ?? null, (string)$r['c'], $r['v'] ?? '');
        if ($or && $ok) return true;
        if (!$or && !$ok) return false;
    }
    return !$or;
}
function pt_cmp($a, string $c, $v): bool {
    $isArr = is_array($a);
    $empty = $a === null || $a === '' || ($isArr && !$a);
    $num = fn($x) => is_numeric(str_replace(',', '.', (string)$x)) ? (float)str_replace(',', '.', (string)$x) : null;
    switch ($c) {
        case 'empty': return $empty;
        case 'filled': return !$empty;
        case 'checked': return !$empty && $a !== '0' && $a !== false;
        case 'unchecked': return $empty || $a === '0' || $a === false;
        case 'eq': return $isArr ? in_array((string)$v, array_map('strval', $a), true) : (string)$a === (string)$v;
        case 'ne': return $isArr ? !in_array((string)$v, array_map('strval', $a), true) : (string)$a !== (string)$v;
        case 'in': return !$isArr && in_array((string)$a, array_map('strval', (array)$v), true);
        case 'contains': return $isArr ? in_array((string)$v, array_map('strval', $a), true) : ((string)$v !== '' && mb_stripos((string)$a, (string)$v) !== false);
        case 'ncontains': return !pt_cmp($a, 'contains', $v);
        case 'gt': case 'gte': case 'lt': case 'lte':
            $x = $num($a); $y = $num($v); if ($x === null || $y === null) return false;
            return $c === 'gt' ? $x > $y : ($c === 'gte' ? $x >= $y : ($c === 'lt' ? $x < $y : $x <= $y));
        case 'before': return !$empty && !$isArr && (string)$v !== '' && strcmp((string)$a, (string)$v) < 0;
        case 'after': return !$empty && !$isArr && (string)$v !== '' && strcmp((string)$a, (string)$v) > 0;
    }
    return false;
}

/** Regla de estimación limpia (ver estimate_lib.php). */
function pt_clean_rule($r): ?array {
    if (!is_array($r)) return null;
    $op = in_array($r['op'] ?? '', ['add', 'replace', 'mult', 'range', 'alt', 'per'], true) ? $r['op'] : null;
    if (!$op) return null;
    $out = ['name' => pt_s($r['name'] ?? '', 80), 'op' => $op, 'when' => pt_clean_cond($r['when'] ?? null)];
    if (in_array($op, ['add', 'replace', 'per'], true)) $out['v'] = max(0, min(100000, (int)round((float)($r['v'] ?? 0))));
    if ($op === 'mult') $out['v'] = max(0, min(100, round((float)($r['v'] ?? 1), 3)));
    if (in_array($op, ['range', 'per'], true)) $out['field'] = pt_id($r['field'] ?? '');
    if ($op === 'range') $out['ranges'] = array_values(array_filter(array_map(fn($x) => is_array($x) ? ['from' => (float)($x['from'] ?? 0), 'to' => ($x['to'] ?? '') === '' || $x['to'] === null ? null : (float)$x['to'], 'v' => max(0, min(100000, (int)round((float)($x['v'] ?? 0))))] : null, array_slice((array)($r['ranges'] ?? []), 0, 20))));
    if ($op === 'alt') $out['alts'] = array_values(array_filter(array_map(fn($x) => is_array($x) ? ['name' => pt_s($x['name'] ?? '', 80), 'when' => pt_clean_cond($x['when'] ?? null), 'v' => max(0, min(100000, (int)round((float)($x['v'] ?? 0))))] : null, array_slice((array)($r['alts'] ?? []), 0, 20))));
    return $out;
}

/** Ajuste de estimación de una opción o de un campo numérico: {op:add|replace|mult|per, v}. */
function pt_clean_est($e): ?array {
    if (!is_array($e) || !in_array($e['op'] ?? '', ['add', 'replace', 'mult', 'per'], true)) return null;
    $v = $e['op'] === 'mult' ? max(0, min(100, round((float)($e['v'] ?? 1), 3))) : max(0, min(100000, (int)round((float)($e['v'] ?? 0))));
    return ($e['op'] !== 'mult' && !$v) ? null : ['op' => $e['op'], 'v' => $v];
}

/** Un elemento limpio (o null si no sirve). $seen evita ids repetidos. */
function pt_clean_item($it, array &$seen, int $depth = 0): ?array {
    if (!is_array($it)) return null;
    $type = isset(PT_TYPES[$it['type'] ?? '']) ? $it['type'] : 'text';
    if ($type === 'group' && $depth > 0) $type = 'heading';   // grupos de un solo nivel
    $id = pt_id($it['id'] ?? '') ?: 'f_' . substr(md5(json_encode($it) . count($seen) . mt_rand()), 0, 6);
    if (preg_match('/^__/', $id)) $id = 'f' . $id;
    while (isset($seen[$id])) $id .= 'x';
    $seen[$id] = true;
    $o = ['id' => $id, 'type' => $type, 'label' => pt_s($it['label'] ?? '', 200), 'desc' => pt_s($it['desc'] ?? '', 1000),
          'width' => in_array($it['width'] ?? '', PT_WIDTHS, true) ? $it['width'] : '1/1'];
    if (!in_array($type, ['separator', 'heading', 'paragraph', 'info', 'group'], true)) {
        $o['required'] = !empty($it['required']) && $type !== 'hidden';
        $o['placeholder'] = pt_s($it['placeholder'] ?? '', 120);
        $d = $it['default'] ?? '';
        $o['default'] = is_array($d) ? array_values(array_map(fn($x) => pt_s($x, 200), array_slice($d, 0, 50))) : pt_s($d, 1000);
    }
    if ($type === 'info') $o['tone'] = in_array($it['tone'] ?? '', ['info', 'ok', 'warn', 'danger'], true) ? $it['tone'] : 'info';
    if (in_array($type, ['select', 'multiselect', 'radio'], true)) {
        $opts = []; $ov = [];
        foreach (array_slice((array)($it['options'] ?? []), 0, 100) as $op) {
            if (!is_array($op)) $op = ['l' => $op];
            $l = pt_s($op['l'] ?? ($op['v'] ?? ''), 120); if ($l === '') continue;
            $v = pt_s($op['v'] ?? '', 120); if ($v === '') $v = $l;
            if (isset($ov[$v])) continue; $ov[$v] = 1;
            $x = ['v' => $v, 'l' => $l];
            if (preg_match('/^fa-[a-z0-9-]{1,36}$/', (string)($op['icon'] ?? ''))) $x['icon'] = $op['icon'];
            if (preg_match('/^#[0-9a-f]{6}$/i', (string)($op['color'] ?? ''))) $x['color'] = strtolower($op['color']);
            if ($e = pt_clean_est($op['est'] ?? null)) $x['est'] = $e;
            $opts[] = $x;
        }
        if (!$opts) return null;
        $o['options'] = $opts;
    }
    if ($type === 'checkbox' && ($e = pt_clean_est($it['est'] ?? null))) $o['est'] = $e;
    if ($type === 'number' && ($e = pt_clean_est($it['est'] ?? null))) $o['est'] = $e;
    if ($type === 'catpick') $o['root'] = (int)($it['root'] ?? 0) ?: null;   // categoría padre (null = las de primer nivel del departamento)
    if ($type === 'approval') { $o['approver'] = (int)($it['approver'] ?? 0) ?: null; $o['pick'] = !empty($it['pick']); }
    $v = (array)($it['valid'] ?? []); $vc = [];
    foreach (['min', 'max', 'minlen', 'maxlen', 'mincount', 'maxcount', 'maxmb', 'maxfiles', 'step'] as $k)
        if (isset($v[$k]) && $v[$k] !== '' && is_numeric($v[$k])) $vc[$k] = $k === 'step' ? (float)$v[$k] : (float)$v[$k];
    foreach (['mindate', 'maxdate'] as $k) if (isset($v[$k]) && preg_match('/^(\d{4}-\d{2}-\d{2}|today|\+\d{1,3})$/', (string)$v[$k])) $vc[$k] = (string)$v[$k];
    if (!empty($v['pattern']) && @preg_match('~' . str_replace('~', '\~', (string)$v['pattern']) . '~u', '') !== false && mb_strlen((string)$v['pattern']) <= 200) {
        $vc['pattern'] = (string)$v['pattern']; $vc['pattern_msg'] = pt_s($v['pattern_msg'] ?? '', 120);
    }
    if (!empty($v['ext'])) $vc['ext'] = array_values(array_intersect(array_map('strtolower', (array)$v['ext']), $type === 'image' ? PT_IMG_EXT : PT_FILE_EXT));
    if ($vc) $o['valid'] = $vc;
    if ($c = pt_clean_cond($it['cond'] ?? null)) $o['cond'] = $c;
    if ($type === 'group') {
        $o['items'] = [];
        foreach (array_slice((array)($it['items'] ?? []), 0, 40) as $sub) if ($s = pt_clean_item($sub, $seen, $depth + 1)) $o['items'][] = $s;
    }
    if ($o['label'] === '' && !in_array($type, ['separator', 'paragraph', 'info', 'group', 'hidden'], true)) $o['label'] = PT_TYPES[$type][0];
    return $o;
}

/** Esquema completo limpio. */
function pt_clean_schema($s): array {
    $s = is_array($s) ? $s : [];
    $seen = []; $secs = []; $sid = [];
    foreach (array_slice((array)($s['sections'] ?? []), 0, 20) as $sec) {
        if (!is_array($sec)) continue;
        $id = pt_id($sec['id'] ?? '') ?: 's' . (count($secs) + 1);
        while (isset($sid[$id])) $id .= 'x'; $sid[$id] = 1;
        $items = [];
        foreach (array_slice((array)($sec['items'] ?? []), 0, 80) as $it) if ($c = pt_clean_item($it, $seen)) $items[] = $c;
        $x = ['id' => $id, 'title' => pt_s($sec['title'] ?? '', 120), 'desc' => pt_s($sec['desc'] ?? '', 500),
              'icon' => preg_match('/^fa-[a-z0-9-]{1,36}$/', (string)($sec['icon'] ?? '')) ? $sec['icon'] : 'fa-circle-dot', 'items' => $items];
        if ($c = pt_clean_cond($sec['cond'] ?? null)) $x['cond'] = $c;
        $secs[] = $x;
    }
    if (!$secs) $secs[] = ['id' => 's1', 'title' => 'Tu pedido', 'desc' => '', 'icon' => 'fa-circle-dot', 'items' => []];
    $rules = array_values(array_filter(array_map('pt_clean_rule', array_slice((array)($s['rules'] ?? []), 0, 40))));
    return ['v' => 2, 'mode' => ($s['mode'] ?? '') === 'steps' ? 'steps' : 'full', 'sections' => $secs, 'rules' => $rules,
            'title_label' => pt_s($s['title_label'] ?? '', 120) ?: 'Título del pedido', 'title_ph' => pt_s($s['title_ph'] ?? '', 120)];
}

/** Tema visual del formulario (colores, tipografía, bordes, espaciado, fondo, botones y portada). */
function pt_clean_theme($t): array {
    $t = is_array($t) ? $t : [];
    $hex = fn($v, $d) => preg_match('/^#[0-9a-f]{6}$/i', (string)$v) ? strtolower($v) : $d;
    return [
        'accent' => $hex($t['accent'] ?? '', ''), 'bg' => $hex($t['bg'] ?? '', ''), 'card' => $hex($t['card'] ?? '', ''), 'text' => $hex($t['text'] ?? '', ''),
        'font' => in_array($t['font'] ?? '', ['', 'Montserrat', 'Inter', 'Poppins', 'Roboto', 'Lora', 'system'], true) ? ($t['font'] ?? '') : '',
        'radius' => max(0, min(28, (int)($t['radius'] ?? 12))), 'gap' => max(8, min(32, (int)($t['gap'] ?? 16))),
        'btn' => in_array($t['btn'] ?? '', ['solid', 'soft', 'outline', 'pill'], true) ? $t['btn'] : 'solid',
        'cover' => pt_s($t['cover'] ?? '', 300), 'cover_h' => max(0, min(320, (int)($t['cover_h'] ?? 140))),
        'bg_style' => in_array($t['bg_style'] ?? '', ['plain', 'soft', 'grad', 'dots'], true) ? $t['bg_style'] : 'plain',
    ];
}

/** Todos los elementos en orden (los de los grupos, aplanados), con la sección y el grupo al que pertenecen. */
function pt_items(array $schema): array {
    $out = [];
    foreach ($schema['sections'] as $sec) foreach ($sec['items'] as $it) {
        $out[] = $it + ['_sec' => $sec['id'], '_secCond' => $sec['cond'] ?? null, '_grpCond' => null];
        if ($it['type'] === 'group') foreach ($it['items'] ?? [] as $sub) $out[] = $sub + ['_sec' => $sec['id'], '_secCond' => $sec['cond'] ?? null, '_grpCond' => $it['cond'] ?? null];
    }
    return $out;
}

/** Convierte un formulario viejo (lista de campos de Trabajo › Solicitudes) al formato 2. */
function pt_convert_v1(array $fields, string $name = ''): array {
    $map = ['text' => 'text', 'textarea' => 'textarea', 'number' => 'number', 'date' => 'date', 'select' => 'select', 'check' => 'checkbox', 'person' => 'person', 'url' => 'url'];
    $secs = [['id' => 's1', 'title' => $name ?: 'Tu pedido', 'desc' => '', 'icon' => 'fa-circle-dot', 'items' => []]];
    foreach ($fields as $f) {
        if (($f['type'] ?? '') === 'section') { $secs[] = ['id' => 's' . (count($secs) + 1), 'title' => (string)$f['label'], 'desc' => (string)($f['help'] ?? ''), 'icon' => 'fa-circle-dot', 'items' => []]; continue; }
        $it = ['id' => $f['id'] ?? '', 'type' => $map[$f['type'] ?? ''] ?? 'text', 'label' => $f['label'] ?? '', 'desc' => $f['help'] ?? '', 'required' => !empty($f['required'])];
        if (!empty($f['options'])) $it['options'] = array_map(fn($o) => ['v' => $o, 'l' => $o], (array)$f['options']);
        $secs[count($secs) - 1]['items'][] = $it;
    }
    if (count($secs) > 1 && !$secs[0]['items']) array_shift($secs);
    return pt_clean_schema(['mode' => 'full', 'sections' => $secs]);
}

/** Fecha de un límite de validación: 'today', '+N' (días) o una fecha fija. */
function pt_date_limit(string $v): string {
    if ($v === 'today') return date('Y-m-d');
    if ($v[0] === '+') return date('Y-m-d', strtotime('+' . (int)substr($v, 1) . ' days'));
    return $v;
}

/**
 * Valida respuestas contra un esquema. Devuelve [respuestas limpias, errores {id: texto}, ids visibles].
 * Lo oculto por condición no se valida ni se guarda. Los "oculto interno" toman siempre su valor por defecto.
 * $ctx: priorities (ids válidos), cats (ids de categorías válidas por raíz: [root|0 => [ids]]), files (callable que valida tokens), people (callable)
 */
function pt_validate(array $schema, array $given, array $ctx = []): array {
    $items = pt_items($schema);
    // Primer paso: valores crudos (sin validar) para evaluar las condiciones con lo que mandó la persona
    $raw = [];
    foreach ($items as $it) {
        if (!(PT_TYPES[$it['type']][1] ?? false)) continue;
        if ($it['type'] === 'hidden') { $raw[$it['id']] = $it['default'] ?? ''; continue; }
        $v = $given[$it['id']] ?? null;
        $raw[$it['id']] = is_array($v) ? array_values(array_map(fn($x) => is_array($x) ? $x : pt_s($x, 5000), array_slice($v, 0, 50))) : ($v === null ? '' : pt_s($v, 20000));
    }
    // Las condiciones pueden depender de campos que a su vez están ocultos: se itera hasta que no cambie nada
    $vis = [];
    for ($pass = 0; $pass < 5; $pass++) {
        $view = array_intersect_key($raw, array_flip(array_keys(array_filter($vis ?: array_fill_keys(array_column($items, 'id'), true)))));
        $nv = [];
        foreach ($items as $it) $nv[$it['id']] = pt_cond($it['_secCond'], $view) && pt_cond($it['_grpCond'], $view) && pt_cond($it['cond'] ?? null, $view);
        if ($nv === $vis) break;
        $vis = $nv;
    }
    $ans = []; $err = [];
    foreach ($items as $it) {
        $id = $it['id']; $t = $it['type'];
        if (!(PT_TYPES[$t][1] ?? false) || empty($vis[$id])) continue;
        $v = $raw[$id]; $va = (array)($it['valid'] ?? []); $lab = $it['label'] ?: PT_TYPES[$t][0];
        $isEmpty = $v === '' || $v === null || (is_array($v) && !$v);
        if ($t === 'hidden') { $ans[$id] = $v; continue; }
        if ($t === 'checkbox') { $v = ($v === '1' || $v === 'true' || $v === true || $v === 1) ? '1' : ''; $isEmpty = $v === ''; }
        if ($isEmpty) { if (!empty($it['required'])) $err[$id] = 'Completá "' . $lab . '"'; continue; }
        switch ($t) {
            case 'text': case 'textarea':
                if (is_array($v)) { $err[$id] = 'Valor no válido'; break; }
                $len = mb_strlen($v); $max = (int)($va['maxlen'] ?? ($t === 'text' ? 300 : 10000));
                if ($len > $max) $err[$id] = "Máximo $max caracteres";
                elseif (isset($va['minlen']) && $len < $va['minlen']) $err[$id] = 'Mínimo ' . (int)$va['minlen'] . ' caracteres';
                elseif (!empty($va['pattern']) && !preg_match('~' . str_replace('~', '\~', $va['pattern']) . '~u', $v)) $err[$id] = $va['pattern_msg'] ?: 'El formato no es válido';
                break;
            case 'number':
                $n = str_replace(',', '.', (string)$v);
                if (is_array($v) || !is_numeric($n)) { $err[$id] = 'Tiene que ser un número'; break; }
                $n = (float)$n; $v = (string)$n;
                if (isset($va['min']) && $n < $va['min']) $err[$id] = 'Mínimo ' . $va['min'];
                elseif (isset($va['max']) && $n > $va['max']) $err[$id] = 'Máximo ' . $va['max'];
                elseif (!empty($va['step']) && $va['step'] == 1 && floor($n) != $n) $err[$id] = 'Tiene que ser un número entero';
                break;
            case 'email': if (is_array($v) || !filter_var($v, FILTER_VALIDATE_EMAIL)) $err[$id] = 'El email no es válido'; break;
            case 'phone': if (is_array($v) || !preg_match('/^\+?[\d\s().-]{6,25}$/', $v)) $err[$id] = 'El teléfono no es válido'; break;
            case 'url': if (is_array($v) || !preg_match('#^https?://[^\s]{3,}$#i', $v) || mb_strlen($v) > 2000) $err[$id] = 'Tiene que ser un link (https://…)'; break;
            case 'color': if (is_array($v) || !preg_match('/^#[0-9a-f]{6}$/i', $v)) $err[$id] = 'Color no válido'; else $v = strtolower($v); break;
            case 'time': if (is_array($v) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) $err[$id] = 'Hora no válida'; break;
            case 'date':
                if (is_array($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v)) { $err[$id] = 'Fecha no válida'; break; }
                if (!empty($va['mindate']) && $v < pt_date_limit($va['mindate'])) $err[$id] = 'La fecha es anterior a la permitida';
                elseif (!empty($va['maxdate']) && $v > pt_date_limit($va['maxdate'])) $err[$id] = 'La fecha es posterior a la permitida';
                break;
            case 'select': case 'radio':
                if (is_array($v) || !in_array($v, array_column($it['options'], 'v'), true)) $err[$id] = 'Elegí una opción de la lista';
                break;
            case 'multiselect':
                $v = array_values(array_unique(array_map('strval', (array)$v)));
                if (array_diff($v, array_column($it['options'], 'v'))) { $err[$id] = 'Hay una opción que no está en la lista'; break; }
                if (isset($va['mincount']) && count($v) < $va['mincount']) $err[$id] = 'Elegí al menos ' . (int)$va['mincount'];
                elseif (isset($va['maxcount']) && count($v) > $va['maxcount']) $err[$id] = 'Elegí como mucho ' . (int)$va['maxcount'];
                break;
            case 'priority':
                if (is_array($v) || !in_array($v, (array)($ctx['priorities'] ?? []), true)) $err[$id] = 'Prioridad no válida';
                break;
            case 'catpick':
                $ok = (array)($ctx['cats'][(int)($it['root'] ?? 0)] ?? []);
                if (is_array($v) || !in_array((int)$v, $ok, true)) $err[$id] = 'Elegí una de las opciones'; else $v = (string)(int)$v;
                break;
            case 'person': case 'approval':
                if ($t === 'approval' && empty($it['pick'])) { $v = ''; break; }   // aprobador fijo: no lo elige quien pide
                if (is_array($v) || !(int)$v || (isset($ctx['people']) && !($ctx['people'])((int)$v, $it))) $err[$id] = 'Persona no válida'; else $v = (string)(int)$v;
                break;
            case 'file': case 'image':
                $list = array_values(array_filter((array)$v, 'is_array'));
                $max = (int)($va['maxfiles'] ?? 10);
                if (count($list) > $max) { $err[$id] = "Como mucho $max archivos"; break; }
                if (isset($ctx['files'])) { $r = ($ctx['files'])($list, $it); if (is_string($r)) { $err[$id] = $r; break; } $v = $r; }
                else $v = $list;
                break;
        }
        if (!isset($err[$id])) $ans[$id] = $v;
    }
    // Aprobación con aprobador fijo: queda registrada aunque no tenga valor
    foreach ($items as $it) if ($it['type'] === 'approval' && !empty($vis[$it['id']]) && empty($it['pick']) && !isset($ans[$it['id']])) $ans[$it['id']] = (string)($it['approver'] ?: '');
    return [$ans, $err, array_keys(array_filter($vis))];
}

/** Texto legible de una respuesta (para la descripción del ticket y los resúmenes). */
function pt_answer_text(array $it, $v, callable $name = null, callable $cat = null): string {
    if ($v === '' || $v === null || $v === []) return '';
    switch ($it['type']) {
        case 'checkbox': return 'Sí';
        case 'select': case 'radio': foreach ($it['options'] as $o) if ($o['v'] === $v) return $o['l']; return (string)$v;
        case 'multiselect': $m = array_column($it['options'], 'l', 'v'); return implode(', ', array_map(fn($x) => $m[$x] ?? $x, (array)$v));
        case 'person': case 'approval': return $v && $name ? $name((int)$v) : (string)$v;
        case 'catpick': return $cat ? $cat((int)$v) : (string)$v;
        case 'file': case 'image': return implode(', ', array_map(fn($f) => (string)($f['name'] ?? 'archivo'), (array)$v));
    }
    return is_array($v) ? implode(', ', $v) : (string)$v;
}
