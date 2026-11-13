<?php
/**
 * status_lib.php — Listas de estados editables (clientes, servicios, proyectos, pedidos, tickets y facturas).
 * Se guardan en g7_config.statuses: { lista: [ {key, label, color}, ... ] }. Sin nada guardado valen los de siempre.
 * Los estados "bloqueados" tienen lógica detrás (ej.: cliente terminado, factura pagada): se les cambia el nombre
 * y el color, pero no se pueden borrar. Los estados de tareas tienen su propio editor (Trabajo › Ajustes). Doc: toolz/RUBROS.md
 */
const ST_LISTS = [
    'clients'  => ['label' => 'Clientes', 'default' => 'potencial', 'locked' => ['potencial', 'activo', 'terminado'], 'items' => [
        ['potencial', 'Potencial', '#8b8b94'], ['activo', 'Activo', '#22c55e'], ['pausado', 'Pausado', '#f59e0b'], ['terminado', 'Terminado', '#ef4444']]],
    'services' => ['label' => 'Servicios', 'default' => 'pendiente', 'locked' => ['pendiente', 'en_curso', 'terminado'], 'items' => [
        ['pendiente', 'Pendiente', '#8b8b94'], ['en_curso', 'En curso', '#3b82f6'], ['pausado', 'Pausado', '#f59e0b'], ['terminado', 'Terminado', '#22c55e']]],
    'projects' => ['label' => 'Proyectos', 'default' => 'planificado', 'locked' => ['planificado', 'en_pausa', 'terminado', 'cancelado'], 'items' => [
        ['planificado', 'Planificado', '#8b8b94'], ['en_curso', 'En curso', '#3b82f6'], ['en_pausa', 'En pausa', '#f59e0b'], ['terminado', 'Terminado', '#22c55e'], ['cancelado', 'Cancelado', '#ef4444']]],
    'requests' => ['label' => 'Pedidos', 'default' => 'nueva', 'locked' => ['nueva', 'convertida', 'cerrada'], 'items' => [
        ['nueva', 'Nueva', '#3b82f6'], ['en_revision', 'En revisión', '#f59e0b'], ['aprobada', 'Aprobada', '#22c55e'], ['rechazada', 'Rechazada', '#ef4444'], ['convertida', 'En marcha', '#a855f7'], ['cerrada', 'Cerrada', '#8b8b94']]],
    'tickets'  => ['label' => 'Tickets', 'default' => 'abierto', 'locked' => ['abierto', 'resuelto', 'cerrado'], 'items' => [
        ['abierto', 'Abierto', '#3b82f6'], ['en_curso', 'En curso', '#f59e0b'], ['esperando', 'Esperando respuesta', '#a855f7'], ['resuelto', 'Resuelto', '#22c55e'], ['cerrado', 'Cerrado', '#8b8b94']]],
    // Propiedades (módulo propiedades/): vendida y alquilada cierran la operación
    'properties' => ['label' => 'Propiedades', 'default' => 'disponible', 'locked' => ['disponible', 'vendida', 'alquilada'], 'module' => 'propiedades', 'items' => [
        ['disponible', 'Disponible', '#22c55e'], ['reservada', 'Reservada', '#f59e0b'], ['vendida', 'Vendida', '#a855f7'], ['alquilada', 'Alquilada', '#3b82f6'], ['suspendida', 'Suspendida', '#6b7280']]],
    // Las facturas calculan su estado (pagos y fechas): solo se cambia cómo se llaman
    'invoices' => ['label' => 'Facturas', 'default' => 'pendiente', 'locked' => ['borrador', 'pendiente', 'parcial', 'pagada', 'vencida', 'cancelada'], 'fixed' => true, 'items' => [
        ['borrador', 'Borrador', '#8b8b94'], ['pendiente', 'Pendiente de pago', '#f59e0b'], ['parcial', 'Parcialmente pagada', '#3b82f6'], ['pagada', 'Pagada', '#22c55e'], ['vencida', 'Vencida', '#ef4444'], ['cancelada', 'Cancelada', '#6b7280']]],
];

function st_cfg(): array {
    if (function_exists('g7_cfg_json')) return g7_cfg_json('statuses', []);   // ya tiene caché y se entera de lo que se guarda
    return function_exists('vocab_cfg') ? (array)vocab_cfg('statuses', []) : [];
}

/** La lista efectiva: [ ['key','label','color','locked'], ... ] en orden. */
function st_list(string $list): array {
    $def = ST_LISTS[$list] ?? null;
    if (!$def) return [];
    $locked = array_flip($def['locked']);
    $saved = st_cfg()[$list] ?? null;
    $out = [];
    if (is_array($saved) && $saved) {
        foreach ($saved as $it) {
            $k = (string)($it['key'] ?? '');
            if ($k === '' || isset($out[$k])) continue;
            $out[$k] = ['key' => $k, 'label' => (string)($it['label'] ?? $k), 'color' => (string)($it['color'] ?? '#8b8b94'), 'locked' => isset($locked[$k])];
        }
    }
    foreach ($def['items'] as [$k, $l, $c]) if (!isset($out[$k]) && (isset($locked[$k]) || !$saved)) $out[$k] = ['key' => $k, 'label' => $l, 'color' => $c, 'locked' => isset($locked[$k])];
    return array_values($out);
}
function st_keys(string $list): array { return array_column(st_list($list), 'key'); }
function st_has(string $list, string $key): bool { return in_array($key, st_keys($list), true); }
function st_default(string $list): string { return ST_LISTS[$list]['default'] ?? ''; }
/** key => label */
function st_labels(string $list): array { $o = []; foreach (st_list($list) as $s) $o[$s['key']] = $s['label']; return $o; }
/** key => [label, color]: la forma de las constantes viejas (WK_PROJECT_STATUS, etc.). */
function st_pairs(string $list): array { $o = []; foreach (st_list($list) as $s) $o[$s['key']] = [$s['label'], $s['color']]; return $o; }
function st_label(string $list, string $key): string { return st_labels($list)[$key] ?? $key; }

/** Valida y deja lista una lista que manda el editor. Devuelve [lista limpia, error|null]. */
function st_clean(string $list, array $items): array {
    $def = ST_LISTS[$list] ?? null;
    if (!$def) return [[], 'Lista desconocida.'];
    $out = []; $seen = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $label = mb_substr(trim((string)($it['label'] ?? '')), 0, 40);
        if ($label === '') continue;
        $k = (string)($it['key'] ?? '');
        if ($k === '') {   // estado nuevo: la clave sale del nombre
            if (!empty($def['fixed'])) return [[], 'En ' . $def['label'] . ' solo se pueden cambiar los nombres y colores.'];
            $k = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $label) ?: $label)), '_') ?: 'estado';
            $b = $k; $n = 2; while (isset($seen[$k])) $k = $b . '_' . $n++;
        }
        if (!preg_match('/^[a-z0-9_]{1,30}$/', $k) || isset($seen[$k])) continue;
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($it['color'] ?? '')) ? strtolower($it['color']) : '#8b8b94';
        $out[] = ['key' => $k, 'label' => $label, 'color' => $color]; $seen[$k] = true;
    }
    foreach ($def['locked'] as $k) if (!isset($seen[$k])) return [[], 'El estado "' . st_label($list, $k) . '" no se puede borrar: se usa para calcular otras cosas.'];
    if (count($out) > 20) return [[], 'Como máximo 20 estados por lista.'];
    return [$out, null];
}
/** Guarda una lista (o null para volver a la de siempre). */
function st_save(string $list, ?array $items): ?string {
    $all = st_cfg();
    if ($items === null) unset($all[$list]);
    else { [$clean, $err] = st_clean($list, $items); if ($err) return $err; $all[$list] = $clean; }
    g7_cfg_set('statuses', $all);
    return null;
}
/** Todas las listas, para el editor y para el JS. */
function st_all(): array {
    $o = [];
    foreach (ST_LISTS as $k => $d) {
        if (!empty($d['module']) && function_exists('g7_modules') && !isset(g7_modules()[$d['module']])) continue;   // lista de un módulo que este sitio no tiene
        $o[$k] = ['label' => $d['label'], 'fixed' => !empty($d['fixed']), 'items' => st_list($k)];
    }
    return $o;
}
