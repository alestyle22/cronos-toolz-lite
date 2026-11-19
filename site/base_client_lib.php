<?php
/**
 * base_client_lib.php — Cliente del sitio base para nuestro Toolz: firma cada pedido en el servidor y lo manda a base_url + ?api.
 * La clave ('base_sso_secret' de _private/site.php = 'toolz_sso_secret' del base) nunca llega al navegador.
 * La usan base_api.php (proxy del Admin › Red Cronos) y dev/test_base_api.php. Doc: toolz/BASE.md
 */

/** Acciones que se reenvían al base y cuánto se espera cada una (segundos). Las instalaciones de versiones van por 'op'. */
const BC_ACTS = [
    'state' => 300, 'add' => 60, 'remove' => 30, 'move' => 30,
    'plan_save' => 300, 'plan_delete' => 30, 'lic_save' => 90, 'lic_remove' => 90, 'lic_resign_all' => 300,
    'tickets' => 60, 'tk_save' => 30, 'tk_reply' => 30, 'tk_read' => 30, 'tk_delete' => 30, 'tk_file' => 60,
    'banner_save' => 300, 'banner_delete' => 300, 'banner_push' => 300, 'auto_save' => 30,
    'tk_sync' => 20, 'tk_sync_push' => 20, 'lic_list' => 30, 'lic_extend' => 90, 'aff_sync' => 30,
    'tool_list' => 30, 'tool_get' => 60, 'tool_cover' => 30, 'tool_save' => 120, 'tool_delete' => 30, 'tool_move' => 30,   // Catálogo Cronos (CATALOGO.md)
];
/** Las usa solo el servidor (Trabajo › Tickets, trabajo/base_sync.php): el proxy del navegador (base_api.php) no las acepta. */
const BC_SERVER_ONLY = ['tk_sync', 'tk_sync_push', 'lic_list', 'lic_extend', 'aff_sync'];   // lic_*: cobro de planes (pagos/plans.php); aff_sync: afiliados (afiliados/sync.php)
const BC_OPS = ['preview' => 180, 'apply' => 660, 'rollback' => 330];
/** Las que solo leen: no se anotan en la auditoría. */
const BC_READ = ['state', 'tickets', 'tk_read', 'tk_file', 'lic_list', 'tool_list', 'tool_get', 'tool_cover'];

function bc_url(): string { return (string)g7_site('base_url', ''); }
function bc_key(): string { return (string)g7_site('base_sso_secret', ''); }
function bc_configured(): bool { return bc_url() !== '' && strlen(bc_key()) >= 32; }

/** Misma fórmula que b_api_sig() del base (cronos-base/base/index.php). $user va codificado con rawurlencode. */
function bc_sign(string $key, string $method, string $ts, string $nonce, string $user, string $body): string {
    return hash_hmac('sha256', "toolz-base-api-v1\n$method\n$ts\n$nonce\n$user\n" . hash('sha256', $body), $key);
}
/** Encabezados firmados para un cuerpo. $ts y $nonce se pueden fijar (pruebas). */
function bc_headers(string $key, string $user, string $body, ?int $ts = null, ?string $nonce = null): array {
    $ts = (string)($ts ?? time()); $nonce = $nonce ?? bin2hex(random_bytes(16)); $u = rawurlencode(mb_substr($user, 0, 60));
    return ['Content-Type: application/json', "X-Toolz-Ts: $ts", "X-Toolz-Nonce: $nonce", "X-Toolz-User: $u", 'X-Toolz-Sig: ' . bc_sign($key, 'POST', $ts, $nonce, $u, $body)];
}
/** POST al base. Devuelve [código HTTP, cuerpo]. Si no responde, [502, {"error":...}]. */
function bc_post(string $url, array $headers, string $body, int $timeout): array {
    $c = curl_init(rtrim($url, '/') . '/?api=1');
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(15, $timeout), CURLOPT_HTTPHEADER => $headers]);
    $r = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); $err = curl_error($c); curl_close($c);
    if ($r === false) return [502, json_encode(['error' => 'El sitio base no respondió: ' . $err], JSON_UNESCAPED_UNICODE)];
    if (!is_array(json_decode((string)$r, true))) return [502, json_encode(['error' => "El sitio base respondió $code con algo que no es JSON. ¿Tiene la versión con API firmada?"], JSON_UNESCAPED_UNICODE)];
    return [$code ?: 502, (string)$r];
}
/** Un pedido al base en nombre de un usuario de Toolz. */
function bc_call(array $payload, string $user): array {
    $act = (string)($payload['act'] ?? ''); $op = (string)($payload['op'] ?? '');
    $timeout = $act !== '' ? (BC_ACTS[$act] ?? 0) : (BC_OPS[$op] ?? 0);
    if (!$timeout) return [400, json_encode(['error' => 'Pedido inválido.'], JSON_UNESCAPED_UNICODE)];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return bc_post(bc_url(), bc_headers(bc_key(), $user, $body), $body, $timeout);
}

/** CSRF propio de esta sección (la sesión de Toolz ya está abierta). */
function bc_csrf(): string {
    if (empty($_SESSION['g7_base_csrf'])) $_SESSION['g7_base_csrf'] = bin2hex(random_bytes(16));
    return (string)$_SESSION['g7_base_csrf'];
}
function bc_csrf_ok(string $t): bool { return $t !== '' && !empty($_SESSION['g7_base_csrf']) && hash_equals((string)$_SESSION['g7_base_csrf'], $t); }
