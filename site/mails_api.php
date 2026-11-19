<?php
/**
 * mails_api.php — Admin › Mails (admin_mails.php). JSON por POST con cabecera X-Mails: 1. Solo administradores.
 *   state                         catálogo con los textos actuales, el diseño común y los emails que se ajustan en su módulo
 *   save {key, subject, title, text, button}   guarda los textos de un email (vacío = el texto por defecto)
 *   reset {key}                   vuelve a los textos por defecto
 *   layout_save {accent, logo, footer, from_name, theme}
 *   preview {key, tpl?, layout?}  asunto y HTML con datos de ejemplo (con lo que se está editando, sin guardar)
 *   test {key}                    lo manda a mi email con datos de ejemplo
 *   log {q?, key?, offset?}       emails enviados (30 días)
 * Doc: toolz/MAILS.md
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail_tpl_lib.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$out = function (array $d, int $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
$me = getCurrentUser();
if (!$me) $out(['error' => 'Tu sesión venció: vuelve a entrar.'], 401);
if (($me['role'] ?? '') !== 'admin') $out(['error' => 'Solo un administrador puede ajustar los emails.'], 403);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_MAILS'] ?? '') !== '1') $out(['error' => 'Pedido inválido.'], 400);
session_write_close();
$db = getDB(); $uid = (int)$me['id'];
$in = json_decode((string)file_get_contents('php://input'), true) ?: []; $act = (string)($in['action'] ?? '');
$cat = g7_mail_catalog();
$key = (string)($in['key'] ?? '');
$clean = fn($v, int $max) => mb_substr(str_replace("\r", '', trim(strip_tags((string)$v))), 0, $max);
$LIM = ['subject' => 200, 'title' => 160, 'text' => 1500, 'button' => 60];

// Emails que salen de otros módulos con sus propios textos: se listan para que se sepa que existen y dónde se ajustan
$others = array_values(array_filter([
    is_dir(__DIR__ . '/tienda') ? ['Tienda', 'Confirmación de pago, envío del pedido, membresías (cobro rechazado, renovación, vencimiento), cuenta y contraseña del cliente, suscripción a novedades', 'Tienda › Ajustes (nombre, logo y email de avisos)'] : null,
    is_dir(__DIR__ . '/pagos') ? ['Finanzas y pagos', 'Pago confirmado y transferencia revisada, al que paga', 'Finanzas y pagos'] : null,
    is_dir(__DIR__ . '/eventos') ? ['Cronos Tickets (eventos)', 'Entradas con el código QR, confirmaciones y avisos del evento', 'Cada evento, en su configuración'] : null,
    is_dir(__DIR__ . '/resto') ? ['Restaurant', 'Confirmación y recordatorio de reservas, campañas de fidelidad', 'Restaurant › Ajustes y Campañas'] : null,
    is_dir(__DIR__ . '/turnos') ? ['Turnos', 'Confirmación y recordatorio de turnos', 'Turnos › Ajustes'] : null,
    is_dir(__DIR__ . '/landing-gen-2') ? ['Landing Gen 2.0', 'Aviso de un contacto nuevo desde una landing', 'Cada landing, en Formularios'] : null,
    is_dir(__DIR__ . '/facturacion') ? ['Facturación', 'Enviar una factura por email', 'Al enviarla, desde la factura'] : null,
]));

$state = function () use ($cat) {
    $groups = [];
    foreach ($cat as $k => $c) {
        $t = g7_mail_tpl($k);
        $groups[$c[0]][] = ['key' => $k, 'name' => $c[1], 'to' => $c[2], 'vars' => array_map(fn($v) => ['v' => $v, 'd' => G7_MAIL_VARS[$v] ?? ''], $c[3]),
            'subject' => $t['subject'], 'title' => $t['title'], 'text' => $t['text'], 'button' => $t['button'], 'custom' => $t['custom'],
            'default' => $t['default'], 'system_buttons' => $c[7] === ''];
    }
    return ['groups' => array_map(fn($g, $items) => ['name' => $g, 'items' => $items], array_keys($groups), array_values($groups)), 'layout' => g7_mail_layout()];
};

try {
    if ($act === 'state') $out($state() + ['others' => array_map(fn($o) => ['name' => $o[0], 'what' => $o[1], 'where' => $o[2]], $others)]);

    if (in_array($act, ['save', 'reset', 'preview', 'test'], true) && !isset($cat[$key])) $out(['error' => 'Ese email no existe.'], 404);

    if ($act === 'save' || $act === 'reset') {
        $all = json_decode((string)g7_cfg('mail_tpl', '{}'), true) ?: [];
        if ($act === 'reset') unset($all[$key]);
        else {
            $def = g7_mail_tpl($key)['default']; $row = [];
            foreach ($LIM as $f => $max) {
                if ($f === 'button' && $cat[$key][7] === '') continue;   // los de seguridad llevan botones del sistema
                $v = $clean($in[$f] ?? '', $max);
                if ($v !== '' && $v !== $def[$f]) $row[$f] = $v;
            }
            if ($row) $all[$key] = $row; else unset($all[$key]);
        }
        g7_cfg_set('mail_tpl', $all ?: '{}');
        g7_audit('mails.' . $act, $key, null, $uid);
        $out(['ok' => true] + $state());
    }

    if ($act === 'layout_save') {
        $acc = (string)($in['accent'] ?? '');
        $logo = trim((string)($in['logo'] ?? ''));
        if ($logo !== '' && !preg_match('#^https://[^\s"<>]+$#', $logo)) $out(['error' => 'El logo tiene que ser una dirección que empiece con https://'], 422);
        g7_cfg_set('mail_layout', ['accent' => preg_match('/^#[0-9a-fA-F]{6}$/', $acc) ? $acc : '#ff6600', 'logo' => mb_substr($logo, 0, 500),
            'footer' => $clean($in['footer'] ?? '', 400), 'from_name' => $clean($in['from_name'] ?? '', 80), 'theme' => ($in['theme'] ?? '') === 'light' ? 'light' : 'dark']);
        g7_audit('mails.layout', 'diseño', null, $uid);
        $out(['ok' => true] + $state());
    }

    if ($act === 'preview' || $act === 'test') {
        $tpl = []; foreach ($LIM as $f => $max) if (isset($in['tpl'][$f])) $tpl[$f] = $clean($in['tpl'][$f], $max);
        $lay = (array)($in['layout'] ?? []);
        $g = $cat[$key][0];
        $vars = g7_mail_sample($key);
        $extra = ['tpl' => $act === 'preview' ? $tpl : [], 'layout' => $act === 'preview' ? array_map('strval', $lay) : [],
            'highlight' => $g === 'Seguridad' ? '' : $vars['titulo'], 'quote' => in_array($key, ['task.comment', 'task.reply', 'tk.reply'], true) ? 'Te dejo los cambios para revisar mañana.' : '',
            'url' => $g === 'Seguridad' ? '' : g7_site_origin() . '/toolz/'];
        if ($g === 'Seguridad') {
            $extra['info_html'] = '<b>Chrome en Windows</b><br>Córdoba, Argentina (aproximado)<br>IP 200.45.10.1<br>' . htmlspecialchars($vars['fecha']);
            $extra['buttons'] = $key === 'sec.locked' ? [['label' => 'Elegir contraseña nueva y desbloquear', 'url' => '#']]
                : (in_array($key, ['sec.device', 'sec.country'], true) ? [['label' => 'Fui yo', 'url' => '#', 'style' => 'ghost'], ['label' => 'No fui yo', 'url' => '#', 'style' => 'danger'], ['label' => 'Revisar en mi cuenta', 'url' => '#', 'style' => 'ghost']]
                : [['label' => 'Bloquear mi cuenta', 'url' => '#', 'style' => 'danger'], ['label' => 'Revisar en mi cuenta', 'url' => '#', 'style' => 'ghost']]);
        } else $extra['foot'] = 'Puedes elegir qué avisos te llegan desde Tareas › Mis avisos.';
        [$subject, $html] = g7_mail_build($key, $vars, $extra);
        if ($act === 'preview') $out(['subject' => $subject, 'html' => $html]);
        $q = $db->prepare('SELECT email FROM g7_users WHERE id = ?'); $q->execute([$uid]); $to = (string)$q->fetchColumn();
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) $out(['error' => 'Tu usuario no tiene un email válido.'], 422);
        require_once __DIR__ . '/tareas_lib.php';
        $GLOBALS['g7_mail_key'] = 'test:' . $key;
        $ok = tk_mail_send($to, '[Prueba] ' . $subject, $html);
        $out($ok ? ['ok' => true, 'to' => $to] : ['error' => 'El servidor no pudo mandar el email.'], $ok ? 200 : 502);
    }

    if ($act === 'log') {
        g7_mail_log_install($db);
        $w = ['sent_at > NOW() - INTERVAL 30 DAY']; $a = [];
        $q = trim((string)($in['q'] ?? ''));
        if ($q !== '') { $w[] = '(to_addr LIKE ? OR subject LIKE ?)'; $a[] = "%$q%"; $a[] = "%$q%"; }
        if (!empty($in['key'])) { $w[] = 'mkey LIKE ?'; $a[] = preg_replace('/[^a-z_.:]/', '', (string)$in['key']) . '%'; }
        $off = max(0, (int)($in['offset'] ?? 0));
        $st = $db->prepare('SELECT id, UNIX_TIMESTAMP(sent_at) at, to_addr, subject, mkey, ok FROM g7_mail_log WHERE ' . implode(' AND ', $w) . " ORDER BY id DESC LIMIT 101 OFFSET $off");
        $st->execute($a); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $names = array_map(fn($c) => $c[1], $cat);
        $out(['rows' => array_map(fn($r) => ['at' => (int)$r['at'], 'to' => (string)$r['to_addr'], 'subject' => (string)$r['subject'], 'key' => (string)$r['mkey'],
            'name' => $names[preg_replace('/^test:/', '', (string)$r['mkey'])] ?? '', 'test' => str_starts_with((string)$r['mkey'], 'test:'), 'ok' => (bool)$r['ok']], array_slice($rows, 0, 100)),
            'more' => count($rows) > 100, 'offset' => $off]);
    }

    $out(['error' => 'Acción desconocida.'], 400);
} catch (Throwable $e) {
    $out(['error' => 'No se pudo completar. Prueba de nuevo.'], 500);
}
