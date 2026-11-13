<?php
/**
 * Cálculo de Producción de un mes. Lo usan stats.php (sección Producción) y stats_reporte.php,
 * así la pantalla y el reporte dan siempre los mismos números.
 *
 * - Proyectos unificados: variantes del mismo sitio se suman juntas (ver g7_prod_canon).
 * - Multiplataforma: un contenido para varias plataformas reparte sus cantidades en partes iguales.
 * - Revisiones y rechazos: cuentan en el mes en que se registraron (fila nueva con edit_type),
 *   no en el mes en que se creó la tarea original.
 */
require_once __DIR__ . '/promos/opciones.php';

/**
 * Nombre único de proyecto: variantes del mismo sitio ("Mi Marca", "mimarca.com", "Mi Marca Rebrand") se suman juntas.
 * Las equivalencias vienen del paquete de marcas del sitio (marcas/marcas.php → 'stats_canon'):
 *   'contains' => [fragmento => nombre]  (el nombre, sin espacios ni signos y en minúscula, contiene el fragmento)
 *   'map'      => [clave => nombre]      (el nombre, sin espacios ni signos y en minúscula, es exactamente la clave)
 */
function g7_prod_canon(string $p): string {
    $p = trim($p);
    $n = preg_replace('/[^a-z0-9]/', '', mb_strtolower($p, 'UTF-8'));
    if ($n === '') return '';
    static $c = null;
    if ($c === null) $c = function_exists('g7_pack') ? (array)g7_pack('stats_canon', []) : [];
    foreach ((array)($c['contains'] ?? []) as $frag => $name) if ($frag !== '' && str_contains($n, (string)$frag)) return (string)$name;
    return (string)(((array)($c['map'] ?? []))[$n] ?? $p);
}

/** Proyectos a los que suma un contenido (uno, o varios si es multiplataforma), ya unificados. */
function g7_prod_targets(array $r): array {
    $list = json_decode($r['projects'] ?? '[]', true);
    $list = array_values(array_filter(array_map('trim', is_array($list) ? $list : []), 'strlen'));
    if (count($list) >= 2 || (count($list) === 1 && trim((string)$r['project']) === '')) $out = $list;
    else $out = [(string)$r['project']];
    $out = array_values(array_unique(array_map('g7_prod_canon', $out)));
    return $out ?: [''];
}

function g7_prod_json_list($v): array {
    $a = json_decode((string)$v, true);
    return is_array($a) ? array_values(array_filter($a, 'is_string')) : [];
}

/**
 * @return array data (filas por proyecto), tot, proyectos, multiCount, ediciones, motivos, complicaciones, conComplicaciones, contenidos
 */
function g7_prod_mes(PDO $db, DateTime $mesDt, string $proyecto = ''): array {
    // Columnas nuevas de Promos: si todavía nadie abrió Promos desde que se agregaron, se crean acá
    foreach (['complications TEXT NULL DEFAULT NULL', 'complications_note VARCHAR(300) NULL DEFAULT NULL',
              'edit_reasons TEXT NULL DEFAULT NULL', 'edit_notes TEXT NULL DEFAULT NULL'] as $col) {
        try { $db->exec("ALTER TABLE g7_content ADD COLUMN IF NOT EXISTS $col"); } catch (Exception $e) {}
    }
    $st = $db->prepare("SELECT c.id, c.name, c.author, c.project, c.projects, c.estado, c.edit_type, c.edit_reasons, c.edit_notes,
                               c.complications, c.complications_note, c.type, c.qty_graficas, c.qty_videos, c.qty_tareas,
                               c.tiempo_entrega_dias, c.created_at, COALESCE(NULLIF(u.display_name,''), u.username) AS registro
                        FROM g7_content c LEFT JOIN g7_users u ON u.id = c.created_by
                        WHERE c.created_at >= ? AND c.created_at < ? ORDER BY c.created_at");
    $desde = $mesDt->format('Y-m-01');
    $hasta = (clone $mesDt)->modify('first day of next month')->format('Y-m-d');
    $st->execute([$desde, $hasta]);
    $rows = $st->fetchAll();

    $proySet = []; $agg = []; $totDias = []; $multiCount = 0;
    $tot = ['total_contenidos' => 0, 'graficas' => 0, 'videos' => 0, 'total_assets' => 0, 'tareas' => 0,
            'qty_mailings_total' => 0, 'revisiones' => 0, 'rechazos' => 0, 'avg_dias' => null];
    $motivos = ['revision' => [], 'rechazo' => []];
    $complicaciones = []; $conComplicaciones = 0; $ediciones = []; $contenidos = [];

    foreach ($rows as $r) {
        $targets = g7_prod_targets($r);
        foreach ($targets as $t) if ($t !== '') $proySet[$t] = true;
        if ($proyecto !== '' && !in_array($proyecto, $targets, true)) continue;
        $share = 1 / count($targets);
        if (count($targets) > 1) $multiCount++;
        $isRev = $r['edit_type'] === 'revision'; $isRech = $r['edit_type'] === 'rechazo';

        foreach ($targets as $t) {
            if ($proyecto !== '' && $t !== $proyecto) continue;
            if (!isset($agg[$t])) $agg[$t] = ['project' => $t, 'total_contenidos' => 0, 'activos' => 0, 'graficas' => 0, 'videos' => 0,
                'total_assets' => 0, 'tareas' => 0, 'qty_mailings_total' => 0, 'revisiones' => 0, 'rechazos' => 0,
                'total_ediciones' => 0, 'avg_dias' => null, 'sla_ok' => 0, 'sla_total' => 0, 'multi' => 0, '_dias' => []];
            $g = (int)$r['qty_graficas'] * $share; $v = (int)$r['qty_videos'] * $share; $tq = (int)$r['qty_tareas'] * $share;
            $x = &$agg[$t];
            $x['total_contenidos']++;
            $x['activos'] += ($r['estado'] ?? 'activo') === 'activo' ? 1 : 0;
            $x['graficas'] += $g; $x['videos'] += $v; $x['total_assets'] += $g + $v; $x['tareas'] += $tq;
            $x['qty_mailings_total'] += $r['type'] === 'mailings' ? $tq : 0;
            $x['revisiones'] += $isRev ? $share : 0; $x['rechazos'] += $isRech ? $share : 0;
            $x['total_ediciones'] += ($isRev || $isRech) ? 1 : 0;
            if (count($targets) > 1) $x['multi']++;
            if ($r['tiempo_entrega_dias'] !== null) { $x['_dias'][] = (float)$r['tiempo_entrega_dias']; $x['sla_total']++; if ((float)$r['tiempo_entrega_dias'] <= 3) $x['sla_ok']++; }
            unset($x);
            $tot['graficas'] += $g; $tot['videos'] += $v; $tot['total_assets'] += $g + $v; $tot['tareas'] += $tq;
            $tot['qty_mailings_total'] += $r['type'] === 'mailings' ? $tq : 0;
            $tot['revisiones'] += $isRev ? $share : 0; $tot['rechazos'] += $isRech ? $share : 0;
        }
        $tot['total_contenidos']++;
        if ($r['tiempo_entrega_dias'] !== null) $totDias[] = (float)$r['tiempo_entrega_dias'];

        $label = implode(' + ', array_filter($targets)) ?: 'Sin proyecto';
        if ($isRev || $isRech) {
            $keys = g7_prod_json_list($r['edit_reasons']);
            foreach ($keys as $k) $motivos[$r['edit_type']][$k] = ($motivos[$r['edit_type']][$k] ?? 0) + 1;
            $ediciones[] = ['fecha' => substr($r['created_at'], 0, 10), 'name' => $r['name'], 'project' => $label, 'tipo' => $r['edit_type'],
                            'motivos' => array_map(fn($k) => PR_EDIT_REASONS[$r['edit_type']][$k] ?? $k, $keys),
                            'notas' => (string)($r['edit_notes'] ?? ''), 'registro' => $r['registro'] ?: $r['author']];
        }
        $cx = g7_prod_json_list($r['complications']);
        if ($cx) {
            $conComplicaciones++;
            foreach ($cx as $k) $complicaciones[$k] = ($complicaciones[$k] ?? 0) + 1;
        }
        $contenidos[] = ['fecha' => substr($r['created_at'], 0, 10), 'name' => $r['name'], 'project' => $label, 'type' => $r['type'],
                         'author' => $r['author'], 'graficas' => (int)$r['qty_graficas'], 'videos' => (int)$r['qty_videos'],
                         'edicion' => $r['edit_type'] ?: '',
                         'complicaciones' => array_map(fn($k) => ($k === 'otro' && !empty($r['complications_note'])) ? 'Otro: ' . $r['complications_note'] : (PR_COMPLICATIONS[$k] ?? $k), $cx)];
    }
    foreach ($agg as &$x) { $x['avg_dias'] = $x['_dias'] ? array_sum($x['_dias']) / count($x['_dias']) : null; unset($x['_dias']); }
    unset($x);
    $tot['avg_dias'] = $totDias ? array_sum($totDias) / count($totDias) : null;
    $data = array_values($agg);
    usort($data, fn($a, $b) => [$b['total_assets'], $a['project']] <=> [$a['total_assets'], $b['project']]);
    foreach ($motivos as &$m) arsort($m); unset($m);
    arsort($complicaciones);

    // Proyectos para el filtro: los del mes y los de siempre, unificados
    foreach ($db->query("SELECT DISTINCT project FROM g7_content WHERE project != ''")->fetchAll(PDO::FETCH_COLUMN) as $pp) {
        $c = g7_prod_canon($pp); if ($c !== '') $proySet[$c] = true;
    }
    $proyectos = array_keys($proySet);
    sort($proyectos, SORT_NATURAL | SORT_FLAG_CASE);

    return compact('data', 'tot', 'proyectos', 'multiCount', 'ediciones', 'motivos', 'complicaciones', 'conComplicaciones', 'contenidos');
}
