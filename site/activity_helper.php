<?php
// /toolz/activity_helper.php

if (!function_exists('g7GetRecentActivity')):

function g7GetRecentActivity($db, $limit = 8) {
    $items = [];

    // g7_activity (may not exist — caught silently)
    try {
        $q = $db->prepare("
            SELECT a.id, a.type, a.target_name, a.meta, a.created_at,
                   u.id as uid, u.username, u.display_name, u.avatar,
                   '' as tool_name
            FROM g7_activity a
            JOIN g7_users u ON u.id = a.user_id
            ORDER BY a.created_at DESC
            LIMIT " . (int)$limit);
        $q->execute();
        $items = $q->fetchAll();
    } catch(Exception $e) {}

    // g7_accesos_rapidos (may not exist — caught silently)
    try {
        $q2 = $db->prepare("
            SELECT ar.id, 'acceso_rapido' as type, ar.tool as target_name,
                   '' as meta, ar.accessed_at as created_at,
                   u.id as uid, u.username, u.display_name, u.avatar,
                   ar.tool_name
            FROM g7_accesos_rapidos ar
            JOIN g7_users u ON u.id = ar.user_id
            ORDER BY ar.accessed_at DESC
            LIMIT " . (int)$limit);
        $q2->execute();
        $items = array_merge($items, $q2->fetchAll());
    } catch(Exception $e) {}

    if (empty($items)) return [];

    usort($items, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    return array_slice($items, 0, $limit);
}

function g7ActivityIcon($type) {
    $icons = [
        'acceso_rapido'  => '<i class="fa-solid fa-arrow-up-right-from-square" style="color:#a78bfa"></i>',
        'content_create' => '<i class="fa-solid fa-plus-circle" style="color:#4ade80"></i>',
        'post_create'    => '<i class="fa-solid fa-pen-nib" style="color:#4ade80"></i>',
        'link_open'      => '<i class="fa-solid fa-arrow-up-right-from-square" style="color:#60a5fa"></i>',
        'profile_update' => '<i class="fa-solid fa-user-pen" style="color:#a78bfa"></i>',
        'focus_update'   => '<i class="fa-solid fa-crosshairs" style="color:#60a5fa"></i>',
        'status_update'  => '<i class="fa-solid fa-circle-dot" style="color:#f59e0b"></i>',
        'login'          => '<i class="fa-solid fa-right-to-bracket" style="color:#ff6600"></i>',
    ];
    return $icons[$type ?? ''] ?? '<i class="fa-solid fa-circle" style="color:#52525b"></i>';
}

function g7ActivityText($a) {
    // Sin nombre a mostrar (vacío, no solo NULL): el nombre de usuario
    $dn = trim((string)($a['display_name'] ?? ''));
    $name = htmlspecialchars($dn !== '' ? $dn : (trim((string)($a['username'] ?? '')) ?: 'Alguien'));
    $tn   = htmlspecialchars($a['target_name'] ?? '');
    $tool = htmlspecialchars($a['tool_name'] ?? $a['target_name'] ?? '');
    switch ($a['type'] ?? '') {
        case 'acceso_rapido':
            $isLib = isset($a['meta']) && $a['meta'] === 'biblioteca';
            $verb = $isLib ? 'accedió a' : 'usó';
            return "$name <span style='color:#71717a'>$verb</span> <span style='color:#a78bfa;font-weight:700'>$tool</span>";
        case 'content_create': return "$name creó <b>$tn</b>";
        case 'post_create':    return "$name publicó: <i style='color:#a1a1aa'>\"" . htmlspecialchars(mb_substr($a['target_name'] ?? '', 0, 40)) . "\"</i>";
        case 'link_open':      return "$name abrió un recurso";
        case 'profile_update': return "$name actualizó su perfil";
        case 'focus_update':   return "$name 🎯 $tn";
        case 'status_update':  return "$name cambió su estado";
        case 'login':          return "$name inició sesión";
        default:               return "$name realizó una acción";
    }
}

function g7TimeAgo($d) {
    if (!$d) return '';
    $diff = time() - strtotime($d);
    if ($diff < 60)    return 'ahora';
    if ($diff < 3600)  return floor($diff / 60) . 'm';
    if ($diff < 86400) return floor($diff / 3600) . 'h';
    return floor($diff / 86400) . 'd';
}

endif;