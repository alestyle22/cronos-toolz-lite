<?php
/** Título y destino de los avisos de la campana (copian notifText y clickNotif de assets/topbar.js). Los usan app_api.php y mobile/pulse.php. */
/** Título del aviso en texto plano (topbar.js notifText). */
function g7_app_title(array $n): string {
    $t = (string)$n['type']; $from = trim((string)($n['display_name'] ?: $n['username'] ?: '')) ?: 'Alguien'; $hasFrom = !empty($n['from_id']);
    $fixed = ['login_new' => 'Nuevo ingreso a tu cuenta', 'login_fail' => 'Intentos de entrar a tu cuenta', 'task_auto' => 'Automatización de una tarea',
        'cal_remind' => 'Recordatorio de tu calendario', 'pay' => 'Finanzas y pagos', 'aff' => 'Afiliados', 'aff_apply' => 'Afiliados', 'resto' => 'Restaurant',
        'pass' => 'Seguridad de tu cuenta', 'onboarding' => 'Configuración de tu cuenta'];
    if (isset($fixed[$t])) return $fixed[$t];
    $withFrom = ['wk_req' => ['solicitud', 'Novedad en una solicitud'], 'wk_tk' => ['ticket', 'Aviso de un ticket'], 'pt_tk' => ['solicitud', 'Aviso de una solicitud'],
        'wk_run' => ['proceso', 'Novedad en un proceso'], 'social' => ['Social Studio', 'Social Studio'],'bn_kudos' => ['Bienestar', 'Bienestar'], 'bn_leave' => ['vacaciones y licencias', 'Vacaciones y licencias']];
    if (isset($withFrom[$t])) return $hasFrom ? $from . ' · ' . $withFrom[$t][0] : $withFrom[$t][1];
    if (str_starts_with($t, 'bn_')) return ['bn_challenge' => 'Bienestar · retos', 'bn_survey' => 'Bienestar · encuesta', 'bn_habit' => 'Bienestar · tus hábitos'][$t] ?? 'Bienestar';
    if (str_starts_with($t, 'task_')) return ['task_assigned' => "$from te asignó una tarea", 'task_mention' => "$from te mencionó en una tarea", 'task_comment' => "$from comentó en una tarea",
        'task_status' => "$from cambió el estado de una tarea", 'task_due_soon' => 'Una tarea tuya está por vencer', 'task_overdue' => 'Una tarea se venció',
        'task_created' => "$from creó una tarea", 'task_reply' => "$from respondió tu comentario", 'task_reaction' => "$from reaccionó a tu comentario"][$t] ?? 'Novedad en una tarea';
    return ['kudos' => "$from te dio un reconocimiento", 'mention_post' => "$from te mencionó en Comunidad", 'post_reply' => "$from respondió tu publicación",
        'comment' => "$from comentó en tu muro", 'reply' => "$from respondió tu comentario", 'mention' => "$from te mencionó en un comentario"][$t] ?? "$from te notificó";
}

/** Página del aviso (topbar.js clickNotif). Siempre una ruta de /toolz/. */
function g7_app_target(array $n): string {
    $t = (string)$n['type']; $id = (int)($n['target_id'] ?? 0); $pid = (int)($n['profile_id'] ?? 0);
    if ($t === 'login_fail') return '/toolz/user_config.php#sesiones';
    if ($t === 'login_new') return '/toolz/user_config.php' . ($id ? '?ingreso=' . $id : '') . '#sesiones';
    if ($t === 'pt_tk' && $id) return '/toolz/solicitudes/#solicitudes/t/' . $id;
    if (in_array($t, ['wk_req', 'wk_tk', 'wk_run'], true) && $id) return '/toolz/trabajo/#' . ['wk_req' => 'solicitudes/', 'wk_tk' => 'tickets/', 'wk_run' => 'procesos/run/'][$t] . $id;
    if ($t === 'pass') return '/toolz/password.php';
    if ($t === 'onboarding') return '/toolz/bienvenida.php';
    if ($t === 'pay' && $id) return '/toolz/pagos/admin/#op/' . $id;
    if ($t === 'aff' || $t === 'aff_apply') return '/toolz/afiliados/#' . ($t === 'aff_apply' && $id ? 'af/' . $id : 'referidos');
    if ($t === 'resto') return '/toolz/resto/admin/' . ($id ? '#sucursal/' . $id : '');
    if (str_starts_with($t, 'bn_')) return '/toolz/bienestar/#' . (['bn_challenge' => 'retos', 'bn_survey' => 'encuestas', 'bn_kudos' => 'reconocimientos', 'bn_habit' => 'habitos', 'bn_leave' => 'ausencias'][$t] ?? 'inicio') . ($id && $t !== 'bn_kudos' ? '/' . $id : '');
    if ($t === 'cal_remind' && $id) return '/toolz/calendario/#e/' . $id;
    if ($t === 'social_report' && $id) return '/toolz/social/#informes/' . $id;
    if ($t === 'social_inbox' && $id) return '/toolz/social/#inbox/c/' . $id;
    if ($t === 'social') return '/toolz/social/#' . ($id ? 'p/' . $id : 'cuentas');
    if (str_starts_with($t, 'task_') && $id) return '/toolz/tareas.php?t=' . $id;
    if (in_array($t, ['kudos', 'mention_post', 'post_reply'], true) && $id) return '/toolz/comunidad.php?post=' . $id;
    if ($pid) return '/toolz/perfil.php?u=' . $pid . (in_array($t, ['comment', 'reply', 'mention'], true) ? '#muro' : '');
    return '/toolz/';
}
