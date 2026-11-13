<?php
/**
 * integraciones/whatsapp.php — WhatsApp Cloud API (Meta). Manda mensajes de plantilla aprobada (fuera de la ventana de
 * 24 h solo se puede con plantilla). Lo usa el recordatorio de Turnos (turnos/lib.php → ap_remind) y la prueba del Admin
 * con la plantilla hello_world. Doc: toolz/INTEGRACIONES.md
 */
require_once __DIR__ . '/lib.php';

/** Número en formato internacional sin "+" (lo que pide la API). Argentina: agrega 54 y el 9 de los celulares si faltan. */
function wa_number(string $raw, string $country = '54'): string {
    $n = preg_replace('/\D/', '', $raw); if ($n === '') return '';
    $intl = str_starts_with(trim($raw), '+'); if (!$intl && str_starts_with($n, '00')) { $n = substr($n, 2); $intl = true; }
    if (!$intl && !str_starts_with($n, $country)) {
        $n = ltrim($n, '0');
        if (strlen($n) === 12 && substr($n, 2, 2) === '15') $n = substr($n, 0, 2) . substr($n, 4);   // 11 15 5555 0000 → 11 5555 0000
        $n = $country . $n;
    }
    if ($country === '54' && str_starts_with($n, '54') && !str_starts_with($n, '549') && strlen($n) === 12) $n = '549' . substr($n, 2);   // celular: 54 9 área número
    return strlen($n) >= 10 && strlen($n) <= 15 ? $n : '';
}
/** Manda una plantilla. $params son las variables del cuerpo ({{1}}, {{2}}…). Devuelve [ok, id del mensaje o error]. */
function wa_send_template(string $to, string $template, string $lang, array $params = []): array {
    $c = int_cfg('whatsapp'); $num = wa_number($to);
    if ($num === '') return [false, 'El número no es válido.'];
    $tpl = ['name' => $template, 'language' => ['code' => $lang]];
    if ($params) $tpl['components'] = [['type' => 'body', 'parameters' => array_map(fn($p) => ['type' => 'text', 'text' => mb_substr((string)$p, 0, 1000)], array_values($params))]];
    $body = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $num, 'type' => 'template', 'template' => $tpl];
    $url = 'https://graph.facebook.com/' . ($c['api_version'] ?: 'v24.0') . '/' . rawurlencode((string)$c['phone_number_id']) . '/messages';
    $r = int_http('POST', $url, ['Authorization: Bearer ' . $c['token'], 'Content-Type: application/json'], json_encode($body, JSON_UNESCAPED_UNICODE));
    $id = (string)($r['json']['messages'][0]['id'] ?? '');
    if ($r['status'] === 200 && $id !== '') { int_log('whatsapp', 'send', true, "Plantilla $template a …" . substr($num, -4), ['msg_id' => $id]); return [true, $id]; }
    $err = wa_err($r);
    int_log('whatsapp', 'send', false, "Plantilla $template a …" . substr($num, -4) . ": $err");
    return [false, $err];
}
function wa_err(array $r): string {
    if ($r['error']) return $r['error'];
    $e = $r['json']['error'] ?? null;
    if (is_array($e)) {
        $code = (int)($e['code'] ?? 0);
        $hint = [190 => 'el token venció o no es válido', 131030 => 'el número no está en la lista de prueba de Meta', 132001 => 'la plantilla no existe o no está aprobada en ese idioma',
            131026 => 'el número no tiene WhatsApp', 100 => 'falta un dato o el identificador del número no es correcto', 131047 => 'pasaron más de 24 h: solo se puede con plantilla'][$code] ?? '';
        return trim(($e['message'] ?? 'error') . ($code ? " (código $code)" : '') . ($hint ? " · $hint" : ''));
    }
    return 'respuesta ' . $r['status'];
}
/** Probar la conexión: lee los datos del número. */
function wa_test(): array {
    $c = int_cfg('whatsapp');
    $r = int_http('GET', 'https://graph.facebook.com/' . ($c['api_version'] ?: 'v24.0') . '/' . rawurlencode((string)$c['phone_number_id']) . '?fields=display_phone_number,verified_name,quality_rating',
        ['Authorization: Bearer ' . $c['token']]);
    if ($r['status'] === 200 && isset($r['json']['display_phone_number'])) return [true, 'Conectado al número ' . $r['json']['display_phone_number'] . (isset($r['json']['verified_name']) ? ' (' . $r['json']['verified_name'] . ')' : '') . '.'];
    return [false, wa_err($r)];
}
/** ¿Se manda el recordatorio de Turnos por WhatsApp? */
function wa_reminders_on(): bool { return int_ready('whatsapp') && !empty(int_cfg('whatsapp')['remind_on']); }
