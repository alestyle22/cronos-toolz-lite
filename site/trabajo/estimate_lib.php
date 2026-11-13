<?php
/**
 * trabajo/estimate_lib.php — Motor de estimación del Portal de Solicitudes. Solo corre en el servidor:
 * lo que mande el navegador como "tiempo" se ignora siempre; se recalcula con la versión del formulario y las respuestas guardadas.
 *
 * Fuentes, en este orden:
 *   1. Base: el tiempo de la categoría o variante elegida (o la del formulario).
 *   2. Reemplazos (op replace): la última que se cumpla pisa la base.
 *   3. Alternativas excluyentes (op alt): de cada grupo suma solo la primera que se cumple.
 *   4. Sumas (op add) y "por unidad" (op per: minutos × el número de un campo).
 *   5. Por rangos de cantidad (op range): suma lo del rango donde cae el número del campo.
 *   6. Multiplicadores (op mult).
 *   7. Tope entre el mínimo y el máximo de la categoría.
 * Las reglas salen de: las opciones de los campos (est), los campos numéricos (est per), las reglas del formulario y las de la categoría
 * (y de sus categorías padre). Cada regla puede llevar una condición (when) con el mismo lenguaje que la visibilidad.
 */
require_once __DIR__ . '/form_lib.php';

/** Categoría con sus padres (de la raíz a la hoja). */
function pt_cat_chain(PDO $db, ?int $catId): array {
    $out = []; $seen = [];
    while ($catId && !isset($seen[$catId]) && count($out) < 5) {
        $seen[$catId] = 1;
        $q = $db->prepare('SELECT * FROM g7_wk_ticket_cats WHERE id = ?'); $q->execute([$catId]);
        $c = $q->fetch(PDO::FETCH_ASSOC); if (!$c) break;
        array_unshift($out, $c);
        $catId = $c['parent_id'] ? (int)$c['parent_id'] : null;
    }
    return $out;
}

/**
 * Calcula el tiempo de trabajo (minutos). $catId = categoría elegida (o null).
 * Devuelve ['work_min', 'base', 'lines' => [[texto, op, valor]], 'min', 'max', 'clamped', 'cat_id'].
 */
function pt_estimate(PDO $db, array $schema, array $ans, ?int $catId): array {
    $chain = pt_cat_chain($db, $catId);
    $leaf = $chain ? end($chain) : null;
    $lines = []; $base = 0; $min = 0; $max = 0;
    // 1. Base: la de la hoja; si la hoja no tiene, la del padre más cercano que tenga
    foreach (array_reverse($chain) as $c) if ((int)$c['base_min'] > 0) { $base = (int)$c['base_min']; $lines[] = ['Base: ' . $c['name'], 'base', $base]; break; }
    foreach (array_reverse($chain) as $c) { if (!$min && (int)$c['min_min'] > 0) $min = (int)$c['min_min']; if (!$max && (int)$c['max_min'] > 0) $max = (int)$c['max_min']; }
    // Reglas: las de las categorías (raíz → hoja) y las del formulario, más las que vienen pegadas a los campos
    $rules = [];
    foreach ($chain as $c) foreach ((json_decode((string)($c['rules'] ?? ''), true) ?: []) as $r) if ($r = pt_clean_rule($r)) $rules[] = $r + ['src' => $c['name']];
    foreach ($schema['rules'] ?? [] as $r) $rules[] = $r + ['src' => 'Formulario'];
    foreach (pt_items($schema) as $it) {
        if (!array_key_exists($it['id'], $ans)) continue;   // oculto o vacío: no suma
        $v = $ans[$it['id']];
        if (!empty($it['options'])) foreach ($it['options'] as $o) {
            if (empty($o['est'])) continue;
            $chosen = is_array($v) ? in_array($o['v'], $v, true) : $v === $o['v'];
            if ($chosen) $rules[] = ['name' => ($it['label'] ?: 'Opción') . ': ' . $o['l'], 'op' => $o['est']['op'] === 'per' ? 'add' : $o['est']['op'], 'v' => $o['est']['v'], 'when' => null];
        }
        if ($it['type'] === 'checkbox' && !empty($it['est']) && $v === '1') $rules[] = ['name' => $it['label'], 'op' => $it['est']['op'] === 'per' ? 'add' : $it['est']['op'], 'v' => $it['est']['v'], 'when' => null];
        if ($it['type'] === 'number' && !empty($it['est'])) {
            $e = $it['est'];
            if ($e['op'] === 'per' || $e['op'] === 'add') $rules[] = ['name' => $it['label'] . ' × ' . $v, 'op' => 'per', 'v' => $e['v'], 'field' => $it['id'], 'when' => null];
            elseif ($e['op'] === 'mult') $rules[] = ['name' => $it['label'], 'op' => 'mult', 'v' => $e['v'], 'when' => null];
        }
    }
    $ok = fn($r) => pt_cond($r['when'] ?? null, $ans);
    $num = fn($f) => isset($ans[$f]) && !is_array($ans[$f]) && is_numeric(str_replace(',', '.', (string)$ans[$f])) ? (float)str_replace(',', '.', (string)$ans[$f]) : null;
    // 2. Reemplazos
    foreach ($rules as $r) if ($r['op'] === 'replace' && $ok($r)) { $base = (int)$r['v']; $lines[] = [($r['name'] ?: 'Reemplaza la base'), 'replace', $base]; }
    $total = $base;
    // 3. Alternativas excluyentes
    foreach ($rules as $r) if ($r['op'] === 'alt' && $ok($r)) foreach ($r['alts'] ?? [] as $a) if (pt_cond($a['when'] ?? null, $ans)) { $total += $a['v']; $lines[] = [($r['name'] ? $r['name'] . ': ' : '') . ($a['name'] ?: 'Alternativa'), 'alt', $a['v']]; break; }
    // 4. Sumas y por unidad
    foreach ($rules as $r) {
        if ($r['op'] === 'add' && $ok($r) && $r['v']) { $total += $r['v']; $lines[] = [$r['name'] ?: 'Suma', 'add', $r['v']]; }
        if ($r['op'] === 'per' && $ok($r) && ($n = $num($r['field'] ?? '')) !== null && $n > 0) { $m = (int)round($r['v'] * $n); $total += $m; $lines[] = [$r['name'] ?: ('Por unidad × ' . $n), 'per', $m]; }
    }
    // 5. Por rangos de cantidad
    foreach ($rules as $r) {
        if ($r['op'] !== 'range' || !$ok($r) || ($n = $num($r['field'] ?? '')) === null) continue;
        foreach ($r['ranges'] ?? [] as $g) if ($n >= $g['from'] && ($g['to'] === null || $n <= $g['to'])) { $total += $g['v']; $lines[] = [($r['name'] ?: 'Cantidad') . ' (' . $n . ')', 'range', $g['v']]; break; }
    }
    // 6. Multiplicadores
    foreach ($rules as $r) if ($r['op'] === 'mult' && $ok($r) && (float)$r['v'] !== 1.0) { $before = $total; $total = (int)round($total * (float)$r['v']); $lines[] = [($r['name'] ?: 'Multiplicador') . ' ×' . rtrim(rtrim(number_format((float)$r['v'], 2, '.', ''), '0'), '.'), 'mult', $total - $before]; }
    // 7. Topes
    $clamped = null;
    if ($min && $total < $min) { $clamped = 'min'; $total = $min; }
    if ($max && $total > $max) { $clamped = 'max'; $total = $max; }
    if ($clamped) $lines[] = [$clamped === 'min' ? 'Ajustado al mínimo' : 'Ajustado al máximo', 'clamp', $total];
    return ['work_min' => max(0, (int)$total), 'base' => $base, 'lines' => $lines, 'min' => $min, 'max' => $max, 'clamped' => $clamped, 'cat_id' => $leaf ? (int)$leaf['id'] : null];
}

/** "1 h 30 min" / "1,5 h" según la unidad del departamento. */
function pt_fmt_min(int $m, string $unit = 'h'): string {
    if ($unit === 'min') return $m . ' min';
    if ($m < 60) return $m . ' min';
    return intdiv($m, 60) . ' h' . ($m % 60 ? ' ' . ($m % 60) . ' min' : '');
}
