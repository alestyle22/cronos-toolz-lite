<?php
/**
 * rubro_api.php — Acciones de Admin › Rubro, Campos de la ficha y Estados (admin_rubro.js). Solo admins.
 * Cambiar de rubro nunca borra datos (rubro_lib.php). Doc: toolz/RUBROS.md
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rubro_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function (array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
$me = getCurrentUser();
if (!$me) $out(['error' => 'Tu sesión venció: vuelve a entrar.'], 401);
if (($me['role'] ?? '') !== 'admin') $out(['error' => 'Solo los administradores pueden cambiar esto.'], 403);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !preg_match('#^application/json\s*(;|$)#i', (string)($_SERVER['CONTENT_TYPE'] ?? ''))) $out(['error' => 'Pedido inválido.'], 400);
$db = getDB();
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$act = (string)($in['action'] ?? '');
$hasClients = g7_site_has('clientes');
if ($hasClients) { require_once __DIR__ . '/clientes_lib.php'; cli_install($db); }
cf_install($db);

function rb_state(PDO $db, bool $hasClients): array {
    $lic = function_exists('lic_get') ? lic_get() : [];
    $lim = cf_types_limit();
    return [
        'rubro' => rubro_get(), 'rubro_name' => rubro_name(), 'rubros' => rubro_list(), 'parts' => RUBRO_PARTS,
        'terms' => g7_terms(true), 'term_labels' => VOCAB_LABELS, 'vocab_saved' => g7_cfg_json('vocab', []), 'samples' => rubro_samples_has(),
        'clients' => $hasClients, 'types' => cf_client_types(), 'types_limit' => $lim, 'hidden' => cf_client_hidden(), 'builtin' => CF_CLIENT_BUILTIN,
        'fields' => cf_fields($db, 'client'), 'field_types' => CF_TYPES, 'statuses' => st_all(),
        'license_rubro' => (string)($lic['rubro'] ?? ''), 'license_extras' => array_values((array)($lic['extras'] ?? [])),
    ];
}

try {
    switch ($act) {
        case 'state': $out(rb_state($db, $hasClients));
        case 'diff':
            $out(['diff' => rubro_diff($db, (string)($in['id'] ?? ''))]);
        case 'apply':
            $id = (string)($in['id'] ?? '');
            $parts = rubro_apply($db, $id, array_map('strval', (array)($in['parts'] ?? [])));
            $n = !empty($in['samples']) && !rubro_samples_has() ? rubro_samples_seed($db, $id, (int)$me['id']) : null;
            $out(['ok' => true, 'parts' => $parts, 'seeded' => $n] + rb_state($db, $hasClients));
        case 'vocab':
            $v = vocab_clean((array)($in['vocab'] ?? []));
            // Lo que quedó igual al valor de siempre no se guarda: así un sitio sin cambios sigue viéndose como siempre
            $v = array_filter($v, fn($x, $k) => $x['s'] !== VOCAB_DEFAULT[$k]['s'] || $x['p'] !== VOCAB_DEFAULT[$k]['p'], ARRAY_FILTER_USE_BOTH);
            g7_cfg_set('vocab', $v); g7_audit('rubro.vocab', '', $v);
            $out(['ok' => true] + rb_state($db, $hasClients));
        case 'samples_seed':
            if (rubro_samples_has()) throw new RuntimeException('Ya hay datos de ejemplo cargados.');
            $out(['ok' => true, 'seeded' => rubro_samples_seed($db, rubro_get() ?: 'agencia', (int)$me['id'])] + rb_state($db, $hasClients));
        case 'samples_clear':
            $n = rubro_samples_clear($db); g7_audit('rubro.samples_clear', '', $n);
            $out(['ok' => true, 'cleared' => $n] + rb_state($db, $hasClients));
        // Campos de la ficha
        case 'field_save':
            cf_save($db, 'client', (array)($in['field'] ?? []), (int)($in['id'] ?? 0));
            $out(['ok' => true] + rb_state($db, $hasClients));
        case 'field_delete':
            cf_delete($db, 'client', (int)($in['id'] ?? 0)); g7_audit('fields.delete', (string)($in['id'] ?? ''));
            $out(['ok' => true] + rb_state($db, $hasClients));
        case 'field_order':
            cf_reorder($db, 'client', (array)($in['ids'] ?? []));
            $out(['ok' => true] + rb_state($db, $hasClients));
        case 'types':
            $t = cf_client_types_clean((array)($in['types'] ?? []));
            if (($lim = cf_types_limit()) !== null && count($t) > $lim) throw new RuntimeException("Tu plan permite hasta $lim " . ($lim === 1 ? 'tipo' : 'tipos') . ' de ' . g7_term('client', 's', true) . '.');
            g7_cfg_set('client_types', $t);
            $out(['ok' => true] + rb_state($db, $hasClients));
        case 'hidden':
            g7_cfg_set('client_hidden', array_values(array_intersect(array_map('strval', (array)($in['hidden'] ?? [])), array_keys(CF_CLIENT_BUILTIN))));
            $out(['ok' => true] + rb_state($db, $hasClients));
        // Estados
        case 'statuses':
            $list = (string)($in['list'] ?? '');
            if (!isset(ST_LISTS[$list])) throw new RuntimeException('Lista desconocida.');
            if ($err = st_save($list, !empty($in['reset']) ? null : (array)($in['items'] ?? []))) throw new RuntimeException($err);
            g7_audit('statuses.save', $list);
            $out(['ok' => true] + rb_state($db, $hasClients));
    }
    $out(['error' => 'Acción desconocida.'], 400);
} catch (Throwable $e) { $out(['error' => $e->getMessage()], 409); }
