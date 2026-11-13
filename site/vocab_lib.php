<?php
/**
 * vocab_lib.php — Cómo se llaman las cosas en esta instalación (vocabulario por rubro).
 * Orden: lo que escribió la agencia (g7_config.vocab) > valores por defecto (los de siempre).
 * El rubro (rubro_lib.php) escribe g7_config.vocab al aplicarse; después la agencia lo cambia en Admin › Rubro.
 * En JS: window.G7_TERMS y g7Term(clave, 'p') (los carga topbar.php). Doc: toolz/RUBROS.md
 */
const VOCAB_DEFAULT = [
    'client'   => ['s' => 'Cliente',   'p' => 'Clientes',   'g' => 'm'],
    'content'  => ['s' => 'Contenido', 'p' => 'Contenidos', 'g' => 'm'],
    'project'  => ['s' => 'Proyecto',  'p' => 'Proyectos',  'g' => 'm'],
    'task'     => ['s' => 'Tarea',     'p' => 'Tareas',     'g' => 'f'],
    'service'  => ['s' => 'Servicio',  'p' => 'Servicios',  'g' => 'm'],
    'invoice'  => ['s' => 'Factura',   'p' => 'Facturas',   'g' => 'f'],
    'material' => ['s' => 'Material',  'p' => 'Materiales', 'g' => 'm'],
    'portal'   => ['s' => 'Panel del cliente', 'p' => 'Paneles de clientes', 'g' => 'm'],
    'client_name' => ['s' => 'Marca o empresa', 'p' => 'Marcas o empresas', 'g' => 'f'],   // rótulo del nombre en la ficha
];
const VOCAB_LABELS = [   // para el editor de Admin › Rubro
    'client' => 'A quién le trabajan', 'content' => 'Lo que producen', 'project' => 'Proyectos', 'task' => 'Tareas', 'service' => 'Lo que venden',
    'invoice' => 'Comprobantes', 'material' => 'Archivos de cada cliente', 'portal' => 'Panel que ve el cliente', 'client_name' => 'Nombre en la ficha',
];

/** Lee g7_config sin depender de access.php (el panel del cliente no lo carga). */
function vocab_cfg(string $key, $default = []) {
    if (function_exists('g7_cfg_json')) return g7_cfg_json($key, $default);
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $cache[$key] = $default;
        try {
            $st = getDB()->prepare('SELECT cfg_value FROM g7_config WHERE cfg_key = ?'); $st->execute([$key]);
            $v = $st->fetchColumn();
            if (is_string($v) && is_array($d = json_decode($v, true))) $cache[$key] = $d;
        } catch (Throwable $e) {}
    }
    return $cache[$key];
}

/** Todo el vocabulario: clave => ['s' => singular, 'p' => plural, 'g' => 'm'|'f']. */
function g7_terms(bool $fresh = false): array {
    static $t = null;
    if ($t !== null && !$fresh) return $t;
    $t = VOCAB_DEFAULT;
    foreach ((array)vocab_cfg('vocab', []) as $k => $v) {
        if (!isset($t[$k]) || !is_array($v)) continue;
        foreach (['s', 'p', 'g'] as $f) if (isset($v[$f]) && is_string($v[$f]) && trim($v[$f]) !== '') $t[$k][$f] = mb_substr(trim($v[$f]), 0, 40);
    }
    return $t;
}
/** g7_term('client') → "Cliente"; g7_term('client', 'p') → "Clientes"; $lower = true → "clientes". */
function g7_term(string $key, string $form = 's', bool $lower = false): string {
    $t = g7_terms()[$key][$form === 'p' ? 'p' : 's'] ?? $key;
    return $lower ? mb_strtolower($t) : $t;
}
/** "un cliente" / "una paciente" según el género del término. */
function g7_term_a(string $key, bool $lower = true): string {
    $g = g7_terms()[$key]['g'] ?? 'm';
    return ($g === 'f' ? 'una ' : 'un ') . g7_term($key, 's', $lower);
}
/** Limpia lo que manda el editor antes de guardarlo. */
function vocab_clean(array $in): array {
    $o = [];
    foreach (VOCAB_DEFAULT as $k => $def) {
        if (!isset($in[$k]) || !is_array($in[$k])) continue;
        $s = mb_substr(trim((string)($in[$k]['s'] ?? '')), 0, 40); $p = mb_substr(trim((string)($in[$k]['p'] ?? '')), 0, 40);
        if ($s === '' && $p === '') continue;
        $o[$k] = ['s' => $s ?: $def['s'], 'p' => $p ?: $def['p'], 'g' => ($in[$k]['g'] ?? $def['g']) === 'f' ? 'f' : 'm'];
    }
    return $o;
}
/** Vocabulario para el JS de la página: datos JSON más assets/vocab.js. */
function vocab_js(): string {
    // Datos en JSON (no se ejecuta) + script externo: sin código en línea, compatible con la CSP estricta
    return '<script type="application/json" id="g7-terms">' . json_encode(g7_terms(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>'
        . '<script src="/toolz/assets/vocab.js?v=1"></script>';
}
