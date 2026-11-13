<?php if (!isset($currentUser)) { http_response_code(404); exit; } /* fragmento: solo se incluye desde su página */ ?>
<?php
/**
 * admin_updates_api.php — Acciones de Admin › Actualizaciones (solo superadmin; lo controla admin.php).
 * En el sitio central ve y maneja también los demás sitios ('sites' en _private/site.php). Lo incluye admin.php con $in y $act.
 */
require_once __DIR__ . '/_update/lib.php';

/** Lista de sitios en orden de instalación. El primero hace de prueba. */
function upd_sites(): array {
    $c = u_cfg(); $o = [];
    foreach ($c['sites'] as $id => $s) $o[$id] = ['id' => (string)$id, 'name' => (string)($s['name'] ?? $id), 'url' => (string)($s['url'] ?? ''), 'secret' => (string)($s['secret'] ?? ''), 'self' => (string)$id === (string)$c['id']];
    if (!$o) $o[$c['id'] ?: 'este'] = ['id' => $c['id'] ?: 'este', 'name' => $c['name'] ?: 'Este sitio', 'url' => $c['url'], 'secret' => '', 'self' => true];
    return $o;
}
function upd_call(array $site, array $payload, int $timeout = 60): array {
    if ($site['self']) return u_dispatch($payload);
    [$code, $body] = u_post(rtrim($site['url'], '/') . '/_update/remote.php', 'central', $site['secret'], $payload, $timeout);
    $d = json_decode($body, true);
    if (!is_array($d)) throw new UError($site['name'] . " respondió $code: ese sitio todavía no tiene el actualizador o está caído.");
    if (isset($d['error'])) throw new UError($d['error']);
    return $d;
}
function upd_out(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    if ($act === 'upd_state') {
        $sites = [];
        foreach (upd_sites() as $s) {
            $row = ['id' => $s['id'], 'name' => $s['name'], 'self' => $s['self']];
            try { $row += upd_call($s, ['action' => 'status'], 20); } catch (Throwable $e) { $row['unreachable'] = $e->getMessage(); }
            $sites[] = $row;
        }
        $rel = []; $catErr = '';
        try { $rel = u_catalog()['releases']; } catch (Throwable $e) { $catErr = $e->getMessage(); }
        // Código para dar de alta este sitio en el sitio base (lo pega un superadmin en su panel)
        // Una vez conectado (el base ya mandó un pedido firmado) el código no se muestra más: lleva la clave HMAC del sitio,
        // y con ella un superadmin podría firmarse a sí mismo una licencia sin límites.
        $linked = is_file(U_DIR . '/central_seen');
        $c = u_cfg(); $code = (!$linked && !u_is_central() && $c['central_url'] && $c['secret'] && $c['id']) ? base64_encode(json_encode(['id' => $c['id'], 'name' => $c['name'], 'url' => $c['url'], 'secret' => $c['secret']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) : '';
        upd_out(['sites' => $sites, 'releases' => $rel, 'catalog_error' => $catErr, 'central' => u_is_central(), 'configured' => is_file(U_ROOT . '/_private/site.php'),
            'central_url' => $c['central_url'], 'connect_code' => $code, 'linked' => $linked]);
    }
    if ($act === 'upd_run') {
        $op = (string)($in['op'] ?? ''); $sites = upd_sites(); $id = (string)($in['site'] ?? ''); $v = (string)($in['version'] ?? '');
        if (!in_array($op, ['preview', 'apply', 'rollback'], true) || !isset($sites[$id])) throw new UError('Pedido inválido.');
        // Nunca en todos a la vez: cada sitio espera a que el anterior tenga esta versión
        if ($op === 'apply') {
            foreach ($sites as $s) {
                if ($s['id'] === $id) break;
                $st = upd_call($s, ['action' => 'status'], 20);
                if (version_compare((string)$st['version'], $v, '<')) throw new UError('Primero hay que actualizar ' . $s['name'] . ' y comprobar que ande bien.');
            }
        }
        $r = upd_call($sites[$id], ['action' => $op, 'version' => $v], $op === 'preview' ? 120 : 600);
        if ($op !== 'preview') g7_audit('update.' . $op, $id, ['version' => $v, 'result' => $r['version'] ?? ''], (int)$currentUser['id']);
        upd_out(['ok' => true] + $r);
    }
} catch (Throwable $e) {
    if (($in['op'] ?? '') === 'apply' || ($in['op'] ?? '') === 'rollback') g7_audit('update.error', (string)($in['site'] ?? ''), ['error' => $e->getMessage()], (int)$currentUser['id']);
    upd_out(['error' => $e->getMessage()]);
}
upd_out(['error' => 'Acción desconocida.']);
