<?php
/**
 * recomendar/lib.php — "Recomendá Cronos" en cada instalación (2026-11-16). Doc: toolz/AFILIADOS.md
 *
 * La agencia es afiliada de Cronos: recomienda agencias nuevas y gana un porcentaje de su primer pago, que puede descontar de
 * su propio plan o cobrar. Los números viven en el Cronos principal (afiliados/lib.php); esta instalación no habla con él
 * directamente sino con el BASE, con la clave de este sitio (_private/site.php, igual que Soporte). El base guarda el último
 * resumen que le dejó el Cronos principal y encola lo que manda la agencia (pedidos y preferencias) hasta que el principal lo busca.
 * Los datos de cobro van en un SOBRE SELLADO con la clave pública del principal: el base los pasa sin poder leerlos.
 */
require_once dirname(__DIR__) . '/_update/lib.php';

/** Conexión con el base (_private/site.php). G7_RC_CFG solo la define el arranque de las pruebas automáticas, nunca un sitio. */
function rc_cfg(): array { return defined('G7_RC_CFG') ? array_merge(u_cfg(), G7_RC_CFG) : u_cfg(); }
/** ¿Esta instalación está conectada a un base? (los sitios propios sin base no tienen programa). */
function rc_connected(): bool { $c = rc_cfg(); return $c['central_url'] !== '' && $c['secret'] !== '' && $c['id'] !== ''; }

/** Pedido firmado al base (base/aff_api.php). Devuelve el JSON de respuesta o lanza RuntimeException con un texto claro. */
function rc_call(array $payload, int $timeout = 20): array {
    if (!rc_connected()) throw new RuntimeException('Este sitio todavía no está conectado al Cronos principal.');
    $c = rc_cfg();
    if (isset($GLOBALS['RC_CALL'])) [$code, $body] = ($GLOBALS['RC_CALL'])($payload);   // las pruebas lo reemplazan
    else {
        try { [$code, $body] = u_post(rtrim($c['central_url'], '/') . '/aff_api.php', $c['id'], $c['secret'], $payload, $timeout); }
        catch (Throwable $e) { throw new RuntimeException('No pudimos llegar al Cronos principal. Prueba de nuevo en un rato.'); }
    }
    $j = json_decode((string)$body, true);
    if ($code !== 200 || !is_array($j)) throw new RuntimeException(is_array($j) && !empty($j['error']) ? (string)$j['error'] : 'El Cronos principal no respondió bien (código ' . $code . ').');
    return $j;
}

/**
 * Sobre sellado: AES-256-GCM con una clave al azar, y esa clave envuelta con RSA-OAEP (la pública del Cronos principal).
 * Formato: "s1:" + base64( largo de la clave envuelta (2 bytes) · clave envuelta · iv (12) · tag (16) · datos ).
 */
function rc_seal(string $plain, string $pubPem): string {
    $pub = openssl_pkey_get_public($pubPem); if (!$pub) throw new RuntimeException('Falta la clave del Cronos principal: prueba de nuevo en unos minutos.');
    $key = random_bytes(32); $iv = random_bytes(12); $tag = '';
    $c = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($c === false || !openssl_public_encrypt($key, $wrapped, $pub, OPENSSL_PKCS1_OAEP_PADDING)) throw new RuntimeException('No se pudieron proteger los datos.');
    return 's1:' . base64_encode(pack('n', strlen($wrapped)) . $wrapped . $iv . $tag . $c);
}

/** Limpia un texto que escribió la persona. */
function rc_txt($v, int $n): string { return mb_substr(trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', is_scalar($v) ? (string)$v : '')), 0, $n); }

/** Pedido de una agencia recomendada: se valida acá también (el principal lo vuelve a validar). */
function rc_lead_payload(array $in, array $me): array {
    $d = ['agency' => rc_txt($in['agency'] ?? '', 160), 'contact' => rc_txt($in['contact'] ?? '', 160), 'email' => mb_strtolower(rc_txt($in['email'] ?? '', 190)),
        'phone' => rc_txt($in['phone'] ?? '', 40), 'note' => rc_txt($in['note'] ?? '', 1000)];
    if (mb_strlen($d['agency']) < 2) throw new RuntimeException('Escribe el nombre de la agencia o empresa.');
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo no es válido.');
    if ($d['email'] === '' && $d['phone'] === '') throw new RuntimeException('Deja un correo o un teléfono para contactarlos.');
    // Clave única: si el pedido se reintenta (red lenta, doble clic), el principal no lo duplica
    return ['action' => 'lead', 'key' => bin2hex(random_bytes(12)), 'lead' => $d, 'by' => rc_txt($me['name'] ?? '', 160)];
}
