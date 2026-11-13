<?php
/**
 * net_lib.php — Pedidos salientes a direcciones que manda el usuario, sin SSRF (2026-11-10).
 * - Solo http y https, nunca file://, gopher://, php:// ni otros.
 * - El nombre se resuelve una vez, se rechaza cualquier IP privada, reservada o local, y curl se conecta
 *   a esa misma IP (CURLOPT_RESOLVE): un DNS que cambia entre la revisión y la conexión no sirve.
 * - Las redirecciones se siguen a mano y cada destino se vuelve a revisar.
 * - TLS verificado y tope de tamaño.
 */

/** IPv4 públicas del nombre, o [] si alguna es interna (una sola interna alcanza para rechazar). */
function g7_net_public_ips(string $host): array {
    $host = trim($host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) $ips = [$host];
    else $ips = @gethostbynamel($host) ?: [];
    if (!$ips) return [];
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return [];   // solo IPv4: las v6 internas tienen demasiadas formas
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return [];
        if (preg_match('/^(0\.|100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|169\.254\.|192\.0\.0\.|198\.1[89]\.)/', $ip)) return [];   // CGNAT, enlace local, pruebas
    }
    return $ips;
}

/** ¿La URL es http(s) hacia una dirección pública? Devuelve [esquema, host, puerto, ip] o null. */
function g7_net_check(string $url): ?array {
    $p = parse_url($url);
    if (!$p || empty($p['host'])) return null;
    $scheme = strtolower($p['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) return null;
    if (isset($p['user']) || isset($p['pass'])) return null;
    $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!in_array($port, [80, 443, 8080, 8443], true)) return null;    // nada de puertos de bases o servicios internos
    $ips = g7_net_public_ips($p['host']);
    if (!$ips) return null;
    return [$scheme, $p['host'], $port, $ips[0]];
}

/**
 * Baja una URL pública. $accept: prefijos de Content-Type permitidos (ej. ['text/html'] o ['image/']).
 * Devuelve ['body' => ..., 'type' => ..., 'url' => URL final] o null.
 */
function g7_net_fetch(string $url, array $accept = [], int $maxBytes = 600000, int $timeout = 6): ?array {
    for ($hop = 0; $hop < 4; $hop++) {
        $c = g7_net_check($url);
        if (!$c) return null;
        [$scheme, $host, $port, $ip] = $c;
        $buf = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE        => [trim($host, '[]') . ':' . $port . ':' . $ip],
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CronosToolz/1.0)',
            CURLOPT_WRITEFUNCTION  => function ($h, $d) use (&$buf, $maxBytes) { $buf .= $d; return strlen($buf) > $maxBytes ? 0 : strlen($d); },
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $loc  = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $type = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        curl_close($ch);
        if ($code >= 300 && $code < 400 && $loc !== '') { $url = $loc; continue; }
        if ($code !== 200 || $buf === '') return null;
        if ($accept) { $ok = false; foreach ($accept as $a) if (strpos($type, $a) === 0) { $ok = true; break; } if (!$ok) return null; }
        return ['body' => $buf, 'type' => $type, 'url' => $url];
    }
    return null;
}
