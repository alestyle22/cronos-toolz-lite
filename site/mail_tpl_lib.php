<?php
/**
 * mail_tpl_lib.php — Plantillas de los emails que Cronos manda a las personas del equipo (Admin › Mails).
 *
 * Cada email tiene una clave (sec.device, task.assigned, tk.new, pt.done…) con textos por defecto que el admin puede cambiar:
 * asunto, título, texto y botón. Los textos admiten variables entre llaves ({nombre}, {de}, {titulo}, {lugar}…): se completan
 * al mandar. El diseño común (color, logo, pie, nombre del remitente) se ajusta una vez para todos.
 * Lo guardado vive en g7_config: mail_tpl (solo lo que cambió) y mail_layout. Además, tk_mail_send anota cada envío en
 * g7_mail_log (30 días) para la pestaña Enviados.
 * Lo usan login_alert_lib.php (seguridad) y tareas_lib.php (tareas, tickets y Portal de Solicitudes). Doc: toolz/MAILS.md
 */

/** Variables que puede usar cada grupo, con su explicación (las muestra el editor). */
const G7_MAIL_VARS = [
    'nombre' => 'Nombre de quien recibe el email', 'sitio' => 'Nombre de la plataforma', 'de' => 'Quién hizo la acción',
    'titulo' => 'Título de la tarea, el ticket o la solicitud', 'dispositivo' => 'Navegador y sistema (por ejemplo, Chrome en Windows)',
    'lugar' => 'Ubicación aproximada', 'ip' => 'Dirección IP', 'fecha' => 'Fecha y hora', 'cantidad' => 'Cantidad de intentos',
];

/**
 * Catálogo: clave => [grupo, nombre, a quién le llega, variables, asunto, título, texto, botón].
 * El botón vacío significa que lo pone el sistema (los de seguridad llevan enlaces firmados).
 */
function g7_mail_catalog(): array {
    static $c = null; if ($c !== null) return $c;
    $sec = ['nombre', 'sitio', 'dispositivo', 'lugar', 'ip', 'fecha'];
    $c = [
        'sec.device'    => ['Seguridad', 'Ingreso desde un dispositivo nuevo', 'La persona dueña de la cuenta', $sec,
            'Nuevo ingreso a tu cuenta de {sitio}', 'Nuevo ingreso a tu cuenta', 'Hola {nombre}, alguien entró a tu cuenta desde un dispositivo que no habías usado. Si fuiste vos, toca "Fui yo". Si no, toca "No fui yo": cerramos esa sesión al momento.', ''],
        'sec.country'   => ['Seguridad', 'Ingreso desde un país nuevo', 'La persona dueña de la cuenta', $sec,
            'Ingreso desde otro país · {sitio}', 'Ingreso desde otro país', 'Hola {nombre}, entraron a tu cuenta desde un país donde nunca habías entrado. Si fuiste vos (de viaje o con VPN), toca "Fui yo". Si no, toca "No fui yo": cerramos esa sesión al momento.', ''],
        'sec.fail_pass' => ['Seguridad', 'Muchas contraseñas equivocadas', 'La persona dueña de la cuenta', array_merge($sec, ['cantidad']),
            'Intentos de entrar a tu cuenta de {sitio}', 'Intentos de entrar a tu cuenta', 'Hola {nombre}, hubo {cantidad} intentos fallidos de entrar a tu cuenta. Frenamos los intentos por 15 minutos. Si no fuiste vos, activa la verificación en dos pasos; si te preocupa, bloquea tu cuenta.', ''],
        'sec.fail_2fa'  => ['Seguridad', 'Contraseña bien y código mal', 'La persona dueña de la cuenta', array_merge($sec, ['cantidad']),
            'Alguien sabe tu contraseña · {sitio}', 'Alguien sabe tu contraseña', 'Hola {nombre}, alguien escribió bien tu contraseña pero falló {cantidad} veces el código de verificación. Si no fuiste vos, cambia tu contraseña ahora o bloquea tu cuenta.', ''],
        'sec.locked'    => ['Seguridad', 'Cuenta bloqueada', 'La persona dueña de la cuenta', ['nombre', 'sitio', 'fecha'],
            'Tu cuenta de {sitio} está bloqueada', 'Tu cuenta está bloqueada', 'Hola {nombre}, bloqueamos tu cuenta el {fecha}: cerramos todas las sesiones y nadie puede entrar. Para volver a usarla, elige una contraseña nueva con este botón (vale 24 horas). Si vence, pídele a un administrador que la libere.', ''],
    ];
    // Afiliados de Cronos (afiliados/lib.php aff_mail): los reciben los particulares del programa (las agencias lo ven en su Toolz)
    $c += [
        'aff.invite'     => ['Afiliados', 'Invitación al portal', 'El afiliado particular', ['nombre', 'sitio'],
            'Tu acceso al programa de afiliados de {sitio}', 'Ya eres parte del programa', 'Hola {nombre}, te sumamos al programa de afiliados de {sitio}. Elige tu contraseña con este botón (vale 72 horas) y vas a ver tu link, lo que ganas y tus pagos.', 'Activar mi acceso'],
        'aff.reset'      => ['Afiliados', 'Recuperar la contraseña', 'El afiliado particular', ['nombre', 'sitio'],
            'Elige otra contraseña · afiliados de {sitio}', 'Elige otra contraseña', 'Hola {nombre}, pediste entrar de nuevo a tu panel de afiliado. Usa este botón en las próximas 2 horas. Si no fuiste tú, ignora este correo.', 'Elegir contraseña'],
        'aff.approved'   => ['Afiliados', 'Solicitud aprobada', 'Quien pidió sumarse', ['nombre', 'sitio'],
            '¡Bienvenido al programa de afiliados de {sitio}!', 'Aprobamos tu solicitud', 'Hola {nombre}, ya eres afiliado de {sitio}. En otro correo te llega el link para activar tu acceso.', ''],
        'aff.commission' => ['Afiliados', 'Comisión nueva', 'El afiliado particular', ['nombre', 'sitio', 'agencia', 'monto'],
            'Ganaste una comisión con {agencia}', 'Ganaste una comisión', 'Hola {nombre}, {agencia} pagó su plan y ganaste {monto}. Queda en espera unos días y después se suma a tu próximo pago.', 'Ver lo que gané'],
        'aff.payout'     => ['Afiliados', 'Pago realizado', 'El afiliado particular', ['nombre', 'sitio', 'monto', 'referencia'],
            'Te pagamos {monto} · afiliados de {sitio}', 'Te pagamos {monto}', 'Hola {nombre}, ya te transferimos {monto} (referencia {referencia}). El comprobante está en tu panel.', 'Ver el pago'],
    ];
    // Social Studio: informe de redes para el cliente (social/report_lib.php ss_rep_send). El PDF va adjunto y el botón lleva al informe en línea.
    $c += [
        'social.report' => ['Social Studio', 'Informe de redes para el cliente', 'El cliente o quien lo reciba', ['cliente', 'sitio', 'periodo', 'informe'],
            '{informe} · {periodo}', 'Informe de redes de {cliente}', 'Hola, te compartimos el informe de {periodo}. Lo podés ver en línea con el botón o en el PDF adjunto.', 'Ver el informe'],
    ];
    // Tareas, tickets y Portal de Solicitudes: los mismos eventos que tk_notify (tareas_lib.php)
    $tk = [
        'task.assigned' => ['Tareas', 'Te asignaron una tarea', '{de} te asignó una tarea', 'Te sumaron como responsable.'],
        'task.mention'  => ['Tareas', 'Te mencionaron', '{de} te mencionó', 'Te nombraron en una tarea.'],
        'task.comment'  => ['Tareas', 'Comentario nuevo', '{de} comentó', 'Hay un comentario nuevo en una tarea en la que participas.'],
        'task.status'   => ['Tareas', 'Cambió el estado', 'Cambió el estado de una tarea', '{de} la movió.'],
        'task.due_soon' => ['Tareas', 'Tarea por vencer', 'Una tarea está por vencer', 'Revisa la fecha límite.'],
        'task.overdue'  => ['Tareas', 'Tarea vencida', 'Una tarea se venció', 'La fecha límite ya pasó.'],
        'task.created'  => ['Tareas', 'Tarea nueva', '{de} creó una tarea', 'Hay una tarea nueva en el tablero.'],
        'task.reply'    => ['Tareas', 'Te respondieron', '{de} te respondió', 'Respondieron un comentario tuyo.'],
        'task.reaction' => ['Tareas', 'Reaccionaron a tu comentario', '{de} reaccionó a tu comentario', 'Reaccionaron a un comentario tuyo.'],
        'tk.new'        => ['Tickets', 'Ticket nuevo', 'Ticket nuevo', 'Entró un ticket a tu departamento.'],
        'tk.reply'      => ['Tickets', 'Respuesta en un ticket', '{de} respondió un ticket', 'Hay una respuesta nueva en un ticket en el que participas.'],
        'tk.status'     => ['Tickets', 'Cambió un ticket', 'Cambió un ticket', '{de} lo actualizó.'],
        'tk.due_soon'   => ['Tickets', 'Ticket por vencer', 'Un ticket está por vencer', 'Falta poco para el plazo del SLA.'],
        'tk.breach'     => ['Tickets', 'Ticket vencido', 'Se venció el plazo de un ticket', 'El SLA ya pasó.'],
        'tk.digest'     => ['Tickets', 'Resumen de tickets', 'Resumen de tickets', 'Lo abierto, lo vencido y lo que vence hoy.'],
        'pt.received'   => ['Portal de Solicitudes', 'Solicitud recibida', 'Recibimos tu solicitud', 'Ya está en la cola del departamento.'],
        'pt.approval'   => ['Portal de Solicitudes', 'Espera tu aprobación', 'Una solicitud espera tu aprobación', 'Revisala y aprobala o rechazala.'],
        'pt.approved'   => ['Portal de Solicitudes', 'Solicitud aprobada', 'Aprobaron tu solicitud', 'El departamento la aceptó.'],
        'pt.rejected'   => ['Portal de Solicitudes', 'Solicitud rechazada', 'Rechazaron tu solicitud', 'Mirá el motivo en la solicitud.'],
        'pt.assignee'   => ['Portal de Solicitudes', 'Solicitud con responsable', 'Tu solicitud tiene responsable', '{de} la asignó.'],
        'pt.date'       => ['Portal de Solicitudes', 'Cambió la fecha de entrega', 'Cambió la fecha de entrega', 'La solicitud tiene una fecha nueva.'],
        'pt.info'       => ['Portal de Solicitudes', 'Te piden información', 'Te piden información', 'Para seguir con tu solicitud hace falta un dato tuyo.'],
        'pt.started'    => ['Portal de Solicitudes', 'Empezaron tu solicitud', 'Empezaron tu solicitud', 'Ya la están trabajando.'],
        'pt.done'       => ['Portal de Solicitudes', 'Solicitud lista', 'Tu solicitud está lista', 'La marcaron como completada.'],
        'pt.due_soon'   => ['Portal de Solicitudes', 'Se entrega mañana', 'Una solicitud se entrega mañana', 'Revisá cómo viene.'],
        'pt.late'       => ['Portal de Solicitudes', 'Solicitud atrasada', 'Una solicitud está atrasada', 'La fecha comprometida ya pasó.'],
        'pt.overload'   => ['Portal de Solicitudes', 'Solicitud con sobrecarga', 'Solicitud con sobrecarga', 'Entró para un día que ya estaba lleno.'],
    ];
    foreach ($tk as $k => [$g, $name, $title, $line]) {
        $btn = $g === 'Tareas' ? 'Abrir la tarea' : ($g === 'Tickets' ? 'Abrir el ticket' : 'Abrir la solicitud');
        $c[$k] = [$g, $name, 'Las personas que participan', ['nombre', 'sitio', 'de', 'titulo'], $title . ': {titulo}', $title, $line, $btn];
    }
    return $c;
}

/** Clave de plantilla para un evento de tk_notify ('assigned' → task.assigned, 'tk_new' → tk.new, 'pt_done' → pt.done). */
function g7_mail_tk_key(string $event): string {
    if (strncmp($event, 'tk_', 3) === 0) return 'tk.' . substr($event, 3);
    if (strncmp($event, 'pt_', 3) === 0) return 'pt.' . substr($event, 3);
    return 'task.' . $event;
}

/** Textos de una plantilla: los por defecto con lo que haya cambiado el admin encima. ['subject','title','text','button','custom'] */
function g7_mail_tpl(string $key): array {
    $cat = g7_mail_catalog()[$key] ?? null;
    $def = $cat ? ['subject' => $cat[4], 'title' => $cat[5], 'text' => $cat[6], 'button' => $cat[7]] : ['subject' => '', 'title' => '', 'text' => '', 'button' => ''];
    $saved = function_exists('g7_cfg') ? ((json_decode((string)g7_cfg('mail_tpl', '{}'), true) ?: [])[$key] ?? []) : [];
    $out = $def; $custom = false;
    foreach ($def as $f => $v) if (isset($saved[$f]) && is_string($saved[$f]) && trim($saved[$f]) !== '') { $out[$f] = $saved[$f]; $custom = $custom || $saved[$f] !== $v; }
    return $out + ['custom' => $custom, 'default' => $def];
}

/** Diseño común de todos los emails. */
function g7_mail_layout(): array {
    $l = function_exists('g7_cfg') ? (json_decode((string)g7_cfg('mail_layout', '{}'), true) ?: []) : [];
    $acc = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($l['accent'] ?? '')) ? $l['accent'] : '#ff6600';
    return ['accent' => $acc, 'logo' => (string)($l['logo'] ?? ''), 'footer' => (string)($l['footer'] ?? ''),
        'from_name' => (string)($l['from_name'] ?? ''), 'theme' => ($l['theme'] ?? 'dark') === 'light' ? 'light' : 'dark'];
}

/** Completa {variables} en un texto ya escapado para HTML (las variables también se escapan). */
function g7_mail_fill(string $s, array $vars, bool $html = true): string {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $base = $html ? $h($s) : $s;
    return preg_replace_callback('/\{([a-z_]+)\}/', fn($m) => array_key_exists($m[1], $vars) ? ($html ? $h($vars[$m[1]]) : (string)$vars[$m[1]]) : $m[0], $base);
}

/**
 * Arma un email: devuelve [asunto, html].
 * $extra: tpl / layout (textos y diseño sin guardar, para la vista previa), kicker (texto chico arriba), info_html (recuadro con datos, HTML ya armado), highlight (título de la tarea),
 *         quote (comentario citado), buttons ([[label, url, style: primary|ghost|danger]…]), url (botón principal con el texto
 *         de la plantilla), foot (nota al pie propia del grupo).
 */
function g7_mail_build(string $key, array $vars, array $extra = []): array {
    $t = g7_mail_tpl($key); $L = g7_mail_layout();
    // Vista previa del editor: textos y diseño sin guardar
    foreach ((array)($extra['tpl'] ?? []) as $f => $v) if (isset($t[$f]) && is_string($v) && trim($v) !== '') $t[$f] = $v;
    foreach ((array)($extra['layout'] ?? []) as $f => $v) if (isset($L[$f]) && is_string($v)) $L[$f] = $v;
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $L['accent'])) $L['accent'] = '#ff6600';
    $cat = g7_mail_catalog()[$key] ?? ['Toolz'];
    $vars += ['sitio' => function_exists('g7_site') ? (string)((function_exists('g7_setting') ? g7_setting('platform_name') : '') ?: g7_site('name')) : 'Toolz'];
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $dark = $L['theme'] === 'dark';
    $C = $dark ? ['bg' => '#000000', 'card' => '#0d0d0d', 'line' => '#262626', 'txt' => '#ffffff', 'sub' => '#a0a0a0', 'box' => '#1a1a1a', 'muted' => '#5a5a5a']
               : ['bg' => '#f3f4f6', 'card' => '#ffffff', 'line' => '#e5e7eb', 'txt' => '#111111', 'sub' => '#4b5563', 'box' => '#f3f4f6', 'muted' => '#9ca3af'];
    $acc = $L['accent'];
    $btn = function (array $b) use ($h, $acc, $C) {
        $st = $b['style'] ?? 'primary';
        $css = $st === 'danger' ? 'background:#dc2626;color:#ffffff' : ($st === 'ghost' ? "background:transparent;color:{$C['txt']};border:1px solid {$C['line']}" : "background:$acc;color:#ffffff");
        return '<a href="' . $h($b['url']) . '" style="display:inline-block;margin:6px 8px 0 0;' . $css . ';text-decoration:none;font-weight:700;padding:11px 18px;border-radius:9px;font-size:14px">' . $h($b['label']) . '</a>';
    };
    $buttons = (array)($extra['buttons'] ?? []);
    if (!empty($extra['url']) && $t['button'] !== '') array_unshift($buttons, ['label' => g7_mail_fill($t['button'], $vars, false), 'url' => $extra['url']]);
    $logo = $L['logo'] !== '' && preg_match('#^https://#', $L['logo']) ? '<img src="' . $h($L['logo']) . '" alt="" style="max-height:34px;max-width:180px;display:block;margin-bottom:14px">' : '';
    $html = '<!doctype html><html><body style="margin:0;background:' . $C['bg'] . ';padding:24px;font-family:Montserrat,Arial,sans-serif">'
        . '<div style="max-width:560px;margin:0 auto;background:' . $C['card'] . ';border:1px solid ' . $C['line'] . ';border-radius:14px;padding:28px">' . $logo
        . '<div style="font-size:12px;letter-spacing:2px;text-transform:uppercase;color:' . $acc . ';font-weight:700;margin-bottom:12px">' . $h($vars['sitio']) . ' · ' . $h($extra['kicker'] ?? $cat[0]) . '</div>'
        . '<div style="font-size:18px;font-weight:700;color:' . $C['txt'] . ';margin-bottom:6px">' . g7_mail_fill($t['title'], $vars) . '</div>'
        . '<div style="font-size:14px;color:' . $C['sub'] . ';line-height:1.6">' . nl2br(g7_mail_fill($t['text'], $vars)) . '</div>'
        . (!empty($extra['info_html']) ? '<div style="margin:16px 0 0;font-size:14px;line-height:1.7;color:' . $C['txt'] . ';background:' . $C['box'] . ';border-radius:10px;padding:12px 14px">' . $extra['info_html'] . '</div>' : '')
        . (isset($extra['highlight']) && $extra['highlight'] !== '' ? '<div style="margin-top:14px;font-size:15px;color:' . $C['txt'] . ';font-weight:600">' . $h($extra['highlight']) . '</div>' : '')
        . (isset($extra['quote']) && $extra['quote'] !== '' ? '<div style="margin:16px 0;padding:12px 16px;background:' . $C['box'] . ';border-left:3px solid ' . $acc . ';border-radius:6px;color:' . $C['sub'] . ';font-size:14px;line-height:1.6">' . nl2br($h($extra['quote'])) . '</div>' : '')
        . ($buttons ? '<div style="margin-top:12px">' . implode('', array_map($btn, $buttons)) . '</div>' : '')
        . (!empty($extra['foot']) ? '<div style="margin-top:22px;font-size:11px;color:' . $C['muted'] . '">' . $h($extra['foot']) . '</div>' : '')
        . ($L['footer'] !== '' ? '<div style="margin-top:' . (!empty($extra['foot']) ? '6' : '22') . 'px;font-size:11px;color:' . $C['muted'] . ';line-height:1.5">' . nl2br($h($L['footer'])) . '</div>' : '')
        . '</div></body></html>';
    return [mb_substr(g7_mail_fill($t['subject'], $vars, false), 0, 200), $html];
}

/** Valores de ejemplo para la vista previa del editor. */
function g7_mail_sample(string $key): array {
    return ['nombre' => 'Ana', 'de' => 'Beto', 'titulo' => 'Rediseño de la web', 'dispositivo' => 'Chrome en Windows', 'lugar' => 'Córdoba, Argentina',
        'ip' => '200.45.10.1', 'fecha' => date('d/m/Y H:i'), 'cantidad' => '8', 'agencia' => 'Agencia Norte', 'monto' => 'US$ 25,00', 'referencia' => 'TR-0001'];
}

// ── Registro de envíos (pestaña Enviados) ──────────────────────────────────
function g7_mail_log_install(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS g7_mail_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sent_at DATETIME NOT NULL,
        to_addr VARCHAR(190) NOT NULL DEFAULT '',
        subject VARCHAR(200) NOT NULL DEFAULT '',
        mkey VARCHAR(40) NOT NULL DEFAULT '',
        ok TINYINT(1) NOT NULL DEFAULT 1,
        INDEX idx_at (sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
/** Anota un envío. Nunca falla: si la base no está, no se anota. */
function g7_mail_log_add(string $to, string $subject, string $key, bool $ok): void {
    try {
        if (!function_exists('getDB')) return;
        $db = getDB(); g7_mail_log_install($db);
        $db->prepare('INSERT INTO g7_mail_log (sent_at, to_addr, subject, mkey, ok) VALUES (NOW(), ?, ?, ?, ?)')->execute([mb_substr($to, 0, 190), mb_substr($subject, 0, 200), mb_substr($key, 0, 40), $ok ? 1 : 0]);
        if (random_int(1, 100) === 1) $db->exec('DELETE FROM g7_mail_log WHERE sent_at < NOW() - INTERVAL 30 DAY');
    } catch (Throwable $e) {}
}
