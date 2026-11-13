<?php
/**
 * tareas_lib.php — Tablero de tareas (sin Clientes, cada tarea es de una marca): tablas, configuración y avisos.
 * Lo usan tareas_api.php, tareas.php, notif.php (recordatorios de vencimiento) y topbar.php (contador).
 *
 * Configuración (g7_config "tasks_config", la edita un admin): estados, prioridades, etiquetas y avisos.
 * Avisos: campana (g7_notifications, tipos "task_*", target_id = tarea) y email con mail() del hosting.
 * Cada usuario elige los suyos (g7_user_prefs "tasks_notif") salvo que el admin lo bloquee.
 */

if (date_default_timezone_get() === 'UTC') date_default_timezone_set('America/Argentina/Buenos_Aires');

/** Eventos que avisan. app/email = valor por defecto; on = el admin lo deja activo. */
const TK_EVENTS = [
    'assigned' => ['label' => 'Me asignan una tarea',              'app' => true, 'email' => true],
    'mention'  => ['label' => 'Me mencionan (@usuario)',           'app' => true, 'email' => true],
    'comment'  => ['label' => 'Comentan en una tarea mía',         'app' => true, 'email' => false],
    'status'   => ['label' => 'Cambia el estado de una tarea mía', 'app' => true, 'email' => false],
    'due_soon' => ['label' => 'Una tarea mía está por vencer',     'app' => true, 'email' => true],
    'overdue'  => ['label' => 'Una tarea mía se venció',           'app' => true, 'email' => true],
    'created'  => ['label' => 'Se crea una tarea nueva',           'app' => true, 'email' => false],
    'reply'    => ['label' => 'Responden un comentario mío',       'app' => true, 'email' => true],
    'reaction' => ['label' => 'Reaccionan a un comentario mío',    'app' => true, 'email' => false],
    // Tickets (Trabajo › Tickets): cada ticket es también una tarea, estos avisos son los propios del ticket
    'tk_new'      => ['label' => 'Entra un ticket a mi departamento',       'app' => true,  'email' => true],
    'tk_reply'    => ['label' => 'Responden un ticket mío',                 'app' => true,  'email' => true],
    'tk_status'   => ['label' => 'Cambia un ticket mío (estado, responsable, escalado)', 'app' => true, 'email' => false],
    'tk_due_soon' => ['label' => 'Un ticket mío está por vencer su plazo',  'app' => true,  'email' => true],
    'tk_breach'   => ['label' => 'Se vence el plazo de un ticket mío',      'app' => true,  'email' => true],
    'tk_digest'   => ['label' => 'Resumen diario de tickets (jefes y admins)', 'app' => false, 'email' => true],
    // Portal de Solicitudes (trabajo/portal_lib.php, SOLICITUDES.md)
    'pt_received' => ['label' => 'Recibieron una solicitud mía',                 'app' => true, 'email' => true],
    'pt_approval' => ['label' => 'Una solicitud espera mi aprobación',           'app' => true, 'email' => true],
    'pt_approved' => ['label' => 'Aprobaron una solicitud mía',                  'app' => true, 'email' => true],
    'pt_rejected' => ['label' => 'Rechazaron una solicitud mía',                 'app' => true, 'email' => true],
    'pt_assignee' => ['label' => 'Cambió el responsable de una solicitud mía',   'app' => true, 'email' => false],
    'pt_date'     => ['label' => 'Cambió la fecha de entrega de una solicitud',  'app' => true, 'email' => true],
    'pt_info'     => ['label' => 'Me piden información sobre una solicitud mía', 'app' => true, 'email' => true],
    'pt_started'  => ['label' => 'Empezaron una solicitud mía',                  'app' => true, 'email' => false],
    'pt_done'     => ['label' => 'Completaron una solicitud mía',                'app' => true, 'email' => true],
    'pt_due_soon' => ['label' => 'Una solicitud se entrega mañana',              'app' => true, 'email' => false],
    'pt_late'     => ['label' => 'Una solicitud está atrasada',                  'app' => true, 'email' => true],
    'pt_overload' => ['label' => 'Entró una solicitud con sobrecarga',           'app' => true, 'email' => false],
];

/** Reacciones posibles en los comentarios (el servidor rechaza cualquier otra). */
const TK_REACTIONS = ['👍', '❤️', '😂', '🎉', '👀', '✅', '🙏', '🔥'];

const TK_DEFAULT_CONFIG = [
    'statuses' => [
        ['id' => 'pedidos',     'name' => 'Pedidos',     'color' => '#8b8b94', 'done' => false],
        ['id' => 'en_proceso',  'name' => 'En proceso',  'color' => '#3b82f6', 'done' => false],
        ['id' => 'en_revision', 'name' => 'En revisión', 'color' => '#f59e0b', 'done' => false],
        ['id' => 'finalizado',  'name' => 'Finalizado',  'color' => '#22c55e', 'done' => true],
    ],
    'priorities' => [
        ['id' => 'baja',    'name' => 'Baja',    'color' => '#60a5fa'],
        ['id' => 'media',   'name' => 'Normal',  'color' => '#22c55e'],
        ['id' => 'alta',    'name' => 'Alta',    'color' => '#f59e0b'],
        ['id' => 'urgente', 'name' => 'Urgente', 'color' => '#f97316'],
        ['id' => 'critica', 'name' => 'Crítica', 'color' => '#ef4444'],
    ],
    'default_priority' => 'media',
    'labels' => [],
    'notif' => [
        'events' => [],            // se completa con TK_EVENTS
        'user_override' => true,   // cada usuario puede cambiar sus avisos
        'email_enabled' => true,
        'from_name' => '',   // vacío = nombre del sitio
        'due_days' => 1,           // "por vencer" = vence dentro de estos días
        'created_notify' => [],    // usuarios a los que se avisa de cada tarea nueva (vacío = admins)
    ],
];

function tk_install(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    $v = null;
    try { $v = $db->query("SELECT cfg_value FROM g7_config WHERE cfg_key = 'tasks_schema'")->fetchColumn(); } catch (Throwable $e) {}
    if ($v === '3') return;
    if ($v === '2') { tk_install_v3($db); return; }
    if ($v === '1') { tk_install_v2($db); tk_install_v3($db); return; }
    $db->exec("CREATE TABLE IF NOT EXISTS g7_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(250) NOT NULL,
        description TEXT NULL,
        status VARCHAR(40) NOT NULL,
        priority VARCHAR(40) NOT NULL,
        due_date DATE NULL,
        client_id INT NULL,
        labels VARCHAR(500) NOT NULL DEFAULT '[]',
        position DOUBLE NOT NULL DEFAULT 0,
        archived TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT NULL,
        created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, completed_at DATETIME NULL,
        KEY status (status), KEY client_id (client_id), KEY due_date (due_date), KEY archived (archived)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_task_assignees (
        task_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (task_id, user_id), KEY user_id (user_id)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_task_content (
        task_id INT NOT NULL, content_id INT NOT NULL, PRIMARY KEY (task_id, content_id), KEY content_id (content_id)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_task_comments (
        id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, user_id INT NOT NULL, body TEXT NOT NULL,
        created_at DATETIME NOT NULL, edited_at DATETIME NULL, KEY task_id (task_id)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_task_activity (
        id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, user_id INT NULL, action VARCHAR(40) NOT NULL,
        detail VARCHAR(500) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, KEY task_id (task_id)
    ) DEFAULT CHARSET=utf8mb4");
    // Un recordatorio por tarea, usuario, tipo y fecha límite: si cambian la fecha, vuelve a avisar
    $db->exec("CREATE TABLE IF NOT EXISTS g7_task_reminders (
        task_id INT NOT NULL, user_id INT NOT NULL, kind VARCHAR(12) NOT NULL, due_date DATE NOT NULL,
        PRIMARY KEY (task_id, user_id, kind, due_date)
    ) DEFAULT CHARSET=utf8mb4");
    // La campana solo aceptaba comment/reply/mention: los avisos de tareas son "task_*"
    $col = $db->query("SHOW COLUMNS FROM g7_notifications LIKE 'type'")->fetch(PDO::FETCH_ASSOC);
    if ($col && stripos($col['Type'], 'enum') === 0) $db->exec("ALTER TABLE g7_notifications MODIFY type VARCHAR(30) NOT NULL");
    // El menú de arriba está guardado en la base: se agrega "Tareas" una sola vez, después de Promos
    $nav = json_decode((string)$db->query("SELECT cfg_value FROM g7_config WHERE cfg_key = 'nav_links'")->fetchColumn(), true);
    if (is_array($nav) && !in_array('/toolz/tareas.php', array_column($nav, 'href'), true)) {
        $pos = array_search('/toolz/promos/', array_column($nav, 'href'), true);
        array_splice($nav, $pos === false ? count($nav) : $pos + 1, 0, [['href' => '/toolz/tareas.php', 'icon' => 'fa-table-columns', 'label' => 'Tareas', 'enabled' => true, 'badge' => 'tasks']]);
        $db->prepare("UPDATE g7_config SET cfg_value = ? WHERE cfg_key = 'nav_links'")->execute([json_encode($nav, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
    $db->exec("INSERT INTO g7_config (cfg_key, cfg_value) VALUES ('tasks_schema', '1') ON DUPLICATE KEY UPDATE cfg_value = '1'");
    tk_install_v2($db);
    tk_install_v3($db);
}

/** v2 (2026-10-30): respuestas a comentarios (un nivel: parent_id apunta siempre al comentario principal) y reacciones.
 *  La columna emoji es binaria: con la intercalación normal de MariaDB todos los emoji "son iguales" y uno pisaba al otro. */
function tk_install_v2(PDO $db): void {
    $db->exec("ALTER TABLE g7_task_comments ADD COLUMN IF NOT EXISTS parent_id INT NULL AFTER user_id");
    $db->exec("ALTER TABLE g7_task_comments ADD INDEX IF NOT EXISTS parent_id (parent_id)");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_task_comment_reactions (
        comment_id INT NOT NULL, user_id INT NOT NULL, emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, created_at DATETIME NOT NULL,
        PRIMARY KEY (comment_id, user_id, emoji), KEY comment_id (comment_id)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("INSERT INTO g7_config (cfg_key, cfg_value) VALUES ('tasks_schema', '2') ON DUPLICATE KEY UPDATE cfg_value = '2'");
}

/** v3 (2026-10-30): lista de pasos, adjuntos (los de un cliente quedan en sus Materiales), horas, tareas que se repiten y plantillas. */
function tk_install_v3(PDO $db): void {
    $db->exec("ALTER TABLE g7_tasks ADD COLUMN IF NOT EXISTS repeat_rule VARCHAR(20) NOT NULL DEFAULT ''");
    $db->exec("ALTER TABLE g7_tasks ADD COLUMN IF NOT EXISTS repeat_done TINYINT(1) NOT NULL DEFAULT 0");   // ya se creó la siguiente
    $db->exec("ALTER TABLE g7_tasks ADD COLUMN IF NOT EXISTS repeat_from INT NULL");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_task_checks (
        id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, body VARCHAR(300) NOT NULL, done TINYINT(1) NOT NULL DEFAULT 0,
        position INT NOT NULL DEFAULT 0, done_by INT NULL, done_at DATETIME NULL, KEY task_id (task_id)
    ) DEFAULT CHARSET=utf8mb4");
    // Adjuntos: si la tarea tiene cliente, el archivo vive en sus Materiales (client_file_id); si no, en _private/tareas/<id>/
    $db->exec("CREATE TABLE IF NOT EXISTS g7_task_files (
        id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, client_file_id INT NULL,
        name VARCHAR(200) NOT NULL DEFAULT '', stored VARCHAR(80) NOT NULL DEFAULT '', mime VARCHAR(100) NOT NULL DEFAULT '', size BIGINT NOT NULL DEFAULT 0,
        uploaded_by INT NULL, created_at DATETIME NOT NULL, KEY task_id (task_id), KEY client_file_id (client_file_id)
    ) DEFAULT CHARSET=utf8mb4");
    // Horas: una fila por tramo; ended_at NULL = reloj andando (uno por persona)
    $db->exec("CREATE TABLE IF NOT EXISTS g7_task_time (
        id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, user_id INT NOT NULL, started_at DATETIME NOT NULL, ended_at DATETIME NULL,
        minutes INT NOT NULL DEFAULT 0, note VARCHAR(250) NOT NULL DEFAULT '', created_at DATETIME NOT NULL,
        KEY task_id (task_id), KEY user_id (user_id), KEY started_at (started_at)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_task_templates (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, data TEXT NOT NULL, created_by INT NULL, created_at DATETIME NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
    // Dos plantillas de ejemplo para arrancar (se pueden borrar o cambiar)
    if (!(int)$db->query('SELECT COUNT(*) FROM g7_task_templates')->fetchColumn()) {
        $ins = $db->prepare('INSERT INTO g7_task_templates (name, data, created_at) VALUES (?, ?, ?)');
        $ins->execute(['Branding', json_encode(['title' => 'Branding', 'description' => '', 'priority' => '', 'labels' => [], 'assignees' => [], 'repeat_rule' => '', 'due_in' => 21,
            'checks' => ['Brief y referencias', 'Bocetos de logo', 'Presentación de propuestas', 'Ajustes', 'Versiones finales y variantes', 'Manual de marca', 'Entrega de archivos']], JSON_UNESCAPED_UNICODE), tk_now()]);
        $ins->execute(['Redes sociales del mes', json_encode(['title' => 'Redes sociales del mes', 'description' => '', 'priority' => '', 'labels' => [], 'assignees' => [], 'repeat_rule' => 'monthly', 'due_in' => 7,
            'checks' => ['Calendario de publicaciones', 'Diseño de piezas', 'Textos (copys)', 'Aprobación del cliente', 'Programación', 'Reporte del mes']], JSON_UNESCAPED_UNICODE), tk_now()]);
    }
    $db->exec("INSERT INTO g7_config (cfg_key, cfg_value) VALUES ('tasks_schema', '3') ON DUPLICATE KEY UPDATE cfg_value = '3'");
}

/** Repeticiones posibles: id => [texto, cómo avanza la fecha]. */
const TK_REPEATS = ['daily' => ['Todos los días', '+1 day'], 'weekly' => ['Cada semana', '+1 week'], 'biweekly' => ['Cada 2 semanas', '+2 weeks'],
                    'monthly' => ['Cada mes', '+1 month'], 'yearly' => ['Cada año', '+1 year']];

function tk_now(): string { return date('Y-m-d H:i:s'); }
function tk_slug(string $s): string {
    $s = strtolower(strtr(trim($s), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ñ'=>'n']));
    return trim(preg_replace('/[^a-z0-9]+/', '_', $s), '_') ?: 'x';
}

/** Configuración efectiva: la guardada sobre los valores por defecto. */
function tk_config(PDO $db): array {
    $c = TK_DEFAULT_CONFIG;
    try {
        $q = $db->prepare("SELECT cfg_value FROM g7_config WHERE cfg_key = 'tasks_config'"); $q->execute();
        $saved = json_decode((string)$q->fetchColumn(), true);
    } catch (Throwable $e) { $saved = null; }
    if (is_array($saved)) {
        foreach (['statuses', 'priorities', 'labels'] as $k) if (isset($saved[$k]) && is_array($saved[$k])) $c[$k] = $saved[$k];
        if (!empty($saved['default_priority'])) $c['default_priority'] = $saved['default_priority'];
        if (isset($saved['notif']) && is_array($saved['notif'])) $c['notif'] = array_merge($c['notif'], $saved['notif']);
    }
    if (!$c['statuses']) $c['statuses'] = TK_DEFAULT_CONFIG['statuses'];
    if (!$c['priorities']) $c['priorities'] = TK_DEFAULT_CONFIG['priorities'];
    if (!in_array($c['default_priority'], array_column($c['priorities'], 'id'), true)) $c['default_priority'] = $c['priorities'][0]['id'];
    $ev = [];
    foreach (TK_EVENTS as $k => $d) {
        $s = (array)($c['notif']['events'][$k] ?? []);
        $ev[$k] = ['label' => $d['label'], 'on' => (bool)($s['on'] ?? true), 'app' => (bool)($s['app'] ?? $d['app']), 'email' => (bool)($s['email'] ?? $d['email'])];
    }
    $c['notif']['events'] = $ev;
    $c['notif']['due_days'] = max(0, min(14, (int)$c['notif']['due_days']));
    return $c;
}

function tk_status_ids(array $cfg, ?bool $done = null): array {
    $out = [];
    foreach ($cfg['statuses'] as $s) if ($done === null || (bool)($s['done'] ?? false) === $done) $out[] = $s['id'];
    return $out;
}
function tk_name_of(array $list, string $id): string {
    foreach ($list as $x) if ($x['id'] === $id) return $x['name'];
    return $id;
}

/** Avisos efectivos de un usuario: los del admin y, si se permite, los propios por encima. */
function tk_user_prefs(PDO $db, int $uid, ?array $cfg = null): array {
    $cfg = $cfg ?? tk_config($db);
    $own = [];
    try {
        $q = $db->prepare("SELECT pref_value FROM g7_user_prefs WHERE user_id = ? AND pref_key = 'tasks_notif'"); $q->execute([$uid]);
        $own = json_decode((string)$q->fetchColumn(), true) ?: [];
    } catch (Throwable $e) {}
    $out = ['events' => [], 'browser' => (bool)($own['browser'] ?? true)];
    foreach ($cfg['notif']['events'] as $k => $e) {
        $mine = $cfg['notif']['user_override'] ? (array)($own['events'][$k] ?? []) : [];
        $out['events'][$k] = [
            'app'   => $e['on'] && (bool)($mine['app'] ?? $e['app']),
            'email' => $e['on'] && $cfg['notif']['email_enabled'] && (bool)($mine['email'] ?? $e['email']),
        ];
    }
    return $out;
}

/** Emails pendientes: salen al final del pedido, después de responder, para no demorar la pantalla. */
function &tk__mail_queue(): array { static $q = []; return $q; }

function tk_mail_flush(): void {
    $q = &tk__mail_queue();
    if (!$q) return;
    if (function_exists('litespeed_finish_request')) litespeed_finish_request();
    elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    foreach ($q as $m) @tk_mail_send($m['to'], $m['subject'], $m['html'], $m['from_name']);
    $q = [];
}

function tk_mail_send(string $to, string $subject, string $html, string $fromName = ''): bool {
    if ($fromName === '') $fromName = (string)g7_site('name');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $host = g7_site_mail_host();
    $from = 'no-reply@' . $host;
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: =?UTF-8?B?' . base64_encode($fromName) . "?= <$from>",
        'X-Mailer: Toolz',
    ];
    return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, implode("\r\n", $headers), '-f' . $from);
}

function tk_task_url(int $id): string {
    return g7_site_origin() . '/toolz/tareas.php?t=' . $id;   // el enlace del correo sale del dominio configurado
}

function tk_mail_html(string $title, string $line, string $quote, string $url, string $taskTitle, bool $ticket = false): string {
    $h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $q = $quote !== '' ? '<div style="margin:16px 0;padding:12px 16px;background:#1c1c1c;border-left:3px solid #ff6600;border-radius:6px;color:#d0d0d0;font-size:14px;line-height:1.6">' . nl2br($h($quote)) . '</div>' : '';
    return '<!doctype html><html><body style="margin:0;background:#000;padding:24px;font-family:Montserrat,Arial,sans-serif">'
        . '<div style="max-width:560px;margin:0 auto;background:#0d0d0d;border:1px solid #262626;border-radius:14px;padding:28px">'
        . '<div style="font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#ff6600;font-weight:700;margin-bottom:12px">Toolz · ' . ($ticket ? 'Tickets' : 'Tareas') . '</div>'
        . '<div style="font-size:18px;font-weight:700;color:#fff;margin-bottom:6px">' . $h($title) . '</div>'
        . '<div style="font-size:14px;color:#a0a0a0;line-height:1.6">' . $h($line) . '</div>'
        . '<div style="margin-top:14px;font-size:15px;color:#fff;font-weight:600">' . $h($taskTitle) . '</div>' . $q
        . '<a href="' . $h($url) . '" style="display:inline-block;margin-top:18px;background:#ff6600;color:#fff;text-decoration:none;font-weight:700;padding:11px 20px;border-radius:8px;font-size:14px">' . ($ticket ? 'Abrir el ticket' : 'Abrir la tarea') . '</a>'
        . '<div style="margin-top:22px;font-size:11px;color:#5a5a5a">Puedes elegir qué avisos te llegan desde Tareas › Mis avisos.</div>'
        . '</div></body></html>';
}

/**
 * Avisa a varios usuarios de un evento de una tarea, según las preferencias de cada uno.
 * Nunca se avisa a quien hizo la acción ($fromId).
 */
function tk_notify(PDO $db, string $event, array $userIds, array $task, ?int $fromId, string $body = '', array $opt = []): void {
    // $opt['ticket'] = id del ticket: el aviso es de Trabajo › Tickets (campana wk_tk y link al ticket; 0 = la lista)
    $tkId = $opt['ticket'] ?? null;
    $cfg = tk_config($db);
    if (empty($cfg['notif']['events'][$event]['on'])) return;
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($u) => $u > 0 && $u !== (int)$fromId)));
    if (!$userIds) return;
    $from = '';
    if ($fromId) { $q = $db->prepare('SELECT COALESCE(NULLIF(display_name, \'\'), username) FROM g7_users WHERE id = ?'); $q->execute([$fromId]); $from = (string)$q->fetchColumn(); }
    $in = implode(',', $userIds);
    $users = $db->query("SELECT id, email FROM g7_users WHERE active = 1 AND id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
    $titles = [
        'assigned' => [$from . ' te asignó una tarea', 'Te sumaron como responsable.'],
        'mention'  => [$from . ' te mencionó', 'Te nombraron en una tarea.'],
        'comment'  => [$from . ' comentó', 'Hay un comentario nuevo en una tarea en la que participas.'],
        'status'   => ['Cambió el estado de una tarea', $from . ' la movió.'],
        'due_soon' => ['Una tarea está por vencer', 'Revisa la fecha límite.'],
        'overdue'  => ['Una tarea se venció', 'La fecha límite ya pasó.'],
        'created'  => [$from . ' creó una tarea', 'Hay una tarea nueva en el tablero.'],
        'reply'    => [$from . ' te respondió', 'Respondieron un comentario tuyo.'],
        'reaction' => [$from . ' reaccionó a tu comentario', 'Reaccionaron a un comentario tuyo.'],
        'tk_new'      => ['Ticket nuevo', 'Entró un ticket a tu departamento.'],
        'tk_reply'    => [($from ?: 'Alguien') . ' respondió un ticket', 'Hay una respuesta nueva en un ticket en el que participas.'],
        'tk_status'   => ['Cambió un ticket', ($from ?: 'Toolz') . ' lo actualizó.'],
        'tk_due_soon' => ['Un ticket está por vencer', 'Falta poco para el plazo del SLA.'],
        'tk_breach'   => ['Se venció el plazo de un ticket', 'El SLA ya pasó.'],
        'tk_digest'   => ['Resumen de tickets', 'Lo abierto, lo vencido y lo que vence hoy.'],
        'pt_received' => ['Recibimos tu solicitud', 'Ya está en la cola del departamento.'],
        'pt_approval' => ['Una solicitud espera tu aprobación', 'Revisala y aprobala o rechazala.'],
        'pt_approved' => ['Aprobaron tu solicitud', 'El departamento la aceptó.'],
        'pt_rejected' => ['Rechazaron tu solicitud', 'Mirá el motivo en la solicitud.'],
        'pt_assignee' => ['Tu solicitud tiene responsable', ($from ?: 'Toolz') . ' la asignó.'],
        'pt_date'     => ['Cambió la fecha de entrega', 'La solicitud tiene una fecha nueva.'],
        'pt_info'     => ['Te piden información', 'Para seguir con tu solicitud hace falta un dato tuyo.'],
        'pt_started'  => ['Empezaron tu solicitud', 'Ya la están trabajando.'],
        'pt_done'     => ['Tu solicitud está lista', 'La marcaron como completada.'],
        'pt_due_soon' => ['Una solicitud se entrega mañana', 'Revisá cómo viene.'],
        'pt_late'     => ['Una solicitud está atrasada', 'La fecha comprometida ya pasó.'],
        'pt_overload' => ['Solicitud con sobrecarga', 'Entró para un día que ya estaba lleno.'],
    ][$event] ?? [$from, ''];
    $ins = $db->prepare('INSERT INTO g7_notifications (user_id, from_id, type, target_id, profile_id, body) VALUES (?, ?, ?, ?, NULL, ?)');
    $snippet = mb_substr($body !== '' ? $task['title'] . ' — ' . $body : $task['title'], 0, 280);
    $isPt = strncmp($event, 'pt_', 3) === 0;   // avisos del Portal de Solicitudes: abren el portal aparte (solicitudes/), que también usa quien no tiene Trabajo
    foreach ($users as $u) {
        $p = tk_user_prefs($db, (int)$u['id'], $cfg);
        $ptMod = $isPt && $tkId && function_exists('g7_can') && g7_can((int)$u['id'], 'solicitudes');   // si no tiene el módulo Solicitudes, el aviso abre Trabajo como antes
        if (!empty($p['events'][$event]['app'])) {
            try { $ins->execute([(int)$u['id'], $fromId ?: null, $tkId !== null ? ($ptMod ? 'pt_tk' : 'wk_tk') : 'task_' . $event, $tkId ?? (int)$task['id'], $snippet]); } catch (Throwable $e) {}
        }
        if (!empty($p['events'][$event]['email']) && $u['email']) {
            $q = &tk__mail_queue();
            if (!$q) register_shutdown_function('tk_mail_flush');
            $q[] = ['to' => $u['email'], 'subject' => trim($titles[0]) . ': ' . $task['title'], 'from_name' => $cfg['notif']['from_name'] ?: (string)g7_site('name'),
                    'html' => tk_mail_html(trim($titles[0]), $titles[1], $body, $tkId !== null ? g7_site_origin() . ($ptMod ? '/toolz/solicitudes/#solicitudes/t/' . $tkId : '/toolz/trabajo/#tickets' . ($tkId ? '/' . $tkId : '')) : tk_task_url((int)$task['id']), $task['title'], $tkId !== null)];
        }
    }
}

/** Responsables + creador + quienes comentaron: la gente "de" la tarea. */
function tk_participants(PDO $db, int $taskId): array {
    $q = $db->prepare('SELECT user_id FROM g7_task_assignees WHERE task_id = ?
                       UNION SELECT created_by FROM g7_tasks WHERE id = ? AND created_by IS NOT NULL
                       UNION SELECT user_id FROM g7_task_comments WHERE task_id = ?');
    $q->execute([$taskId, $taskId, $taskId]);
    return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}

/** Usuarios nombrados con @usuario en un texto. */
function tk_mentions(PDO $db, string $text): array {
    if (!preg_match_all('/(?<![\w.])@([A-Za-z0-9_.\-]{2,40})/u', $text, $m)) return [];
    $names = array_values(array_unique(array_map('strtolower', $m[1])));
    $in = implode(',', array_fill(0, count($names), '?'));
    $q = $db->prepare("SELECT id FROM g7_users WHERE active = 1 AND LOWER(username) IN ($in)"); $q->execute($names);
    return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}

/** Tareas abiertas mías que vencen hoy o ya vencieron (para el contador del menú). */
function tk_my_alert_count(PDO $db, int $uid): int {
    try {
        // Abierta = sin completed_at: cada tablero puede tener sus propios estados
        $q = $db->prepare("SELECT COUNT(*) FROM g7_tasks t JOIN g7_task_assignees a ON a.task_id = t.id AND a.user_id = ?
                           WHERE t.archived = 0 AND t.due_date IS NOT NULL AND t.due_date <= ? AND t.completed_at IS NULL");
        $q->execute([$uid, date('Y-m-d')]);
        return (int)$q->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/**
 * Recordatorios de vencimiento. No hay cron: corre como mucho cada 10 minutos, cuando alguien
 * consulta la campana (notif.php) o abre el tablero.
 */
function tk_run_reminders(PDO $db, bool $force = false): void {
    try {
        $last = (int)$db->query("SELECT cfg_value FROM g7_config WHERE cfg_key = 'tasks_reminder_at'")->fetchColumn();
        if (!$force && time() - $last < 600) return;
        $db->prepare("INSERT INTO g7_config (cfg_key, cfg_value) VALUES ('tasks_reminder_at', ?) ON DUPLICATE KEY UPDATE cfg_value = VALUES(cfg_value)")->execute([(string)time()]);
        $cfg = tk_config($db);
        $today = date('Y-m-d');
        $limit = date('Y-m-d', strtotime('+' . $cfg['notif']['due_days'] . ' days'));
        $q = $db->prepare("SELECT id, title, due_date, created_by FROM g7_tasks WHERE archived = 0 AND due_date IS NOT NULL AND due_date <= ? AND completed_at IS NULL");
        $q->execute([$limit]);
        $mark = $db->prepare('INSERT IGNORE INTO g7_task_reminders (task_id, user_id, kind, due_date) VALUES (?, ?, ?, ?)');
        $asg = $db->prepare('SELECT user_id FROM g7_task_assignees WHERE task_id = ?');
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $kind = $t['due_date'] < $today ? 'overdue' : 'due_soon';
            $asg->execute([$t['id']]);
            $who = array_map('intval', $asg->fetchAll(PDO::FETCH_COLUMN));
            if ($kind === 'overdue' && $t['created_by']) $who[] = (int)$t['created_by'];
            $send = [];
            foreach (array_unique($who) as $u) { $mark->execute([$t['id'], $u, $kind, $t['due_date']]); if ($mark->rowCount()) $send[] = $u; }
            if ($send) {
                $when = $t['due_date'] === $today ? 'Vence hoy' : ($kind === 'overdue' ? 'Venció el ' . date('d/m', strtotime($t['due_date'])) : 'Vence el ' . date('d/m', strtotime($t['due_date'])));
                tk_notify($db, $kind, $send, $t, null, $when);
            }
        }
    } catch (Throwable $e) {}
}

function tk_log(PDO $db, int $taskId, ?int $uid, string $action, string $detail = ''): void {
    $db->prepare('INSERT INTO g7_task_activity (task_id, user_id, action, detail, created_at) VALUES (?, ?, ?, ?, ?)')
       ->execute([$taskId, $uid, $action, mb_substr($detail, 0, 500), tk_now()]);
}
