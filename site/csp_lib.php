<?php
/**
 * csp_lib.php — CSP de scripts, fase 1: "solo informar" (Content-Security-Policy-Report-Only). 2026-11-10, auditoría de seguridad.
 * El navegador no bloquea nada: avisa a csp_report.php lo que una política estricta bloquearía (scripts en línea, onclick,
 * orígenes no previstos). Se prende por 24 h desde Mi perfil › Seguridad (superadmin) y se apaga sola.
 * Inventario estático: php dev/csp_inventario.php. Doc: toolz/SEGURIDAD_CSP.md
 */
require_once __DIR__ . '/priv_lib.php';
const G7_CSP_STATE = __DIR__ . '/_private/csp_state.php';     // {"until": timestamp}
const G7_CSP_REPORTS = __DIR__ . '/_private/csp_reports.php'; // avisos agrupados
const G7_CSP_MAX = 2000;                                     // tope de grupos distintos guardados
/** La política que se mide (la que se aplicaría en la fase 2). */
const G7_CSP_POLICY = "default-src 'self'; "
    . "script-src 'self' https://cdnjs.cloudflare.com https://unpkg.com https://cdn.jsdelivr.net https://cdn.tailwindcss.com 'report-sample'; "
    . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://unpkg.com https://cdn.jsdelivr.net; "
    . "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com https://unpkg.com https://cdn.jsdelivr.net; "
    . "img-src 'self' data: blob: https:; media-src 'self' data: blob: https:; connect-src 'self' https:; frame-src 'self' https:; worker-src 'self' blob:; "
    . "object-src 'none'; base-uri 'self'; frame-ancestors 'self'; report-uri /toolz/csp_report.php";

function g7_csp_until(): int {
    $r = g7_priv_read(G7_CSP_STATE);
    return $r === null ? 0 : (int)(json_decode($r, true)['until'] ?? 0);
}
function g7_csp_on(): bool { return g7_csp_until() > time(); }
function g7_csp_set(bool $on, int $hours = 24): int {
    $until = $on ? time() + $hours * 3600 : 0;
    g7_priv_write(G7_CSP_STATE, json_encode(['until' => $until]));
    return $until;
}
/** Manda el encabezado de medición si está prendida (lo llama auth.php en cada página). */
function g7_csp_header(): void {
    if (PHP_SAPI === 'cli' || headers_sent() || !g7_csp_on()) return;
    header('Content-Security-Policy-Report-Only: ' . G7_CSP_POLICY);
}
/** Normaliza un aviso del navegador en una clave de grupo y sus datos. */
function g7_csp_key(array $r): ?array {
    $dir = (string)($r['effective-directive'] ?? $r['violated-directive'] ?? '');
    $dir = preg_replace('/\s.*$/', '', $dir);
    if ($dir === '' || strlen($dir) > 40) return null;
    $blocked = (string)($r['blocked-uri'] ?? '');
    if (preg_match('#^https?://#i', $blocked)) $blocked = (string)parse_url($blocked, PHP_URL_SCHEME) . '://' . (string)parse_url($blocked, PHP_URL_HOST);
    elseif ($blocked === '' || $blocked === 'self') $blocked = 'inline';
    $blocked = mb_substr($blocked, 0, 80);
    $page = (string)parse_url((string)($r['document-uri'] ?? ''), PHP_URL_PATH);
    $src = (string)parse_url((string)($r['source-file'] ?? ''), PHP_URL_PATH);
    $line = (int)($r['line-number'] ?? 0);
    $sample = mb_substr(preg_replace('/\s+/', ' ', (string)($r['script-sample'] ?? '')), 0, 60);
    $d = ['dir' => $dir, 'blocked' => $blocked, 'page' => mb_substr($page, 0, 120), 'src' => mb_substr($src, 0, 120), 'line' => $line, 'sample' => $sample];
    return [sha1(implode('|', [$dir, $blocked, $d['page'], $d['src'], $line, $sample])), $d];
}
/** Suma avisos al informe (con bloqueo y tope). */
function g7_csp_add(array $reports): int {
    $fh = @fopen(G7_CSP_REPORTS, 'c+'); if (!$fh) return 0;
    $n = 0;
    try {
        flock($fh, LOCK_EX);
        $all = json_decode(g7_priv_decode((string)stream_get_contents($fh)), true) ?: ['groups' => [], 'total' => 0, 'dropped' => 0];
        foreach ($reports as $r) {
            $k = is_array($r) ? g7_csp_key($r) : null; if (!$k) continue;
            [$key, $d] = $k; $all['total']++;
            if (isset($all['groups'][$key])) { $all['groups'][$key]['n']++; $all['groups'][$key]['last'] = time(); }
            elseif (count($all['groups']) < G7_CSP_MAX) { $all['groups'][$key] = $d + ['n' => 1, 'first' => time(), 'last' => time()]; }
            else $all['dropped']++;
            $n++;
        }
        ftruncate($fh, 0); rewind($fh); fwrite($fh, g7_priv_encode(json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))); fflush($fh);
        flock($fh, LOCK_UN);
    } finally { fclose($fh); }
    @chmod(G7_CSP_REPORTS, 0600);
    return $n;
}
/** Resumen para la pantalla: grupos ordenados por cantidad, y totales por causa. */
function g7_csp_summary(int $limit = 80): array {
    $r = g7_priv_read(G7_CSP_REPORTS); $all = $r ? (json_decode($r, true) ?: []) : [];
    $g = array_values((array)($all['groups'] ?? []));
    usort($g, fn($a, $b) => $b['n'] <=> $a['n']);
    $by = []; foreach ($g as $x) { $k = $x['dir'] . ' · ' . ($x['blocked'] === 'inline' ? 'en línea' : $x['blocked']); $by[$k] = ($by[$k] ?? 0) + $x['n']; }
    arsort($by);
    $pages = []; foreach ($g as $x) $pages[$x['page']] = ($pages[$x['page']] ?? 0) + $x['n']; arsort($pages);
    return ['total' => (int)($all['total'] ?? 0), 'groups' => count($g), 'dropped' => (int)($all['dropped'] ?? 0), 'by_cause' => $by, 'by_page' => array_slice($pages, 0, 30, true), 'top' => array_slice($g, 0, $limit)];
}
function g7_csp_clear(): void { @unlink(G7_CSP_REPORTS); }

/** Fase 2: aplica la política en modo BLOQUEO en una página que ya no tiene código en línea (se llama después de requireLogin()). */
function g7_csp_enforce(): void {
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    header('Content-Security-Policy: ' . G7_CSP_POLICY);
    if (!defined('G7_CSP_ENFORCED')) define('G7_CSP_ENFORCED', 1);
}
