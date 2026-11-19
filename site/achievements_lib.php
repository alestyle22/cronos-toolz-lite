<?php
/**
 * achievements_lib.php — Logros por niveles (Bronce, Plata, Oro, Diamante). 2026-11-14.
 * Cada logro mide algo que la persona ya hace en Cronos (tickets resueltos, contenidos, chat, herramientas, rachas…).
 * Los números salen de las tablas existentes; acá solo se guarda qué nivel se desbloqueó y cuándo (g7_achievements).
 * Cada nivel nuevo suma XP al sistema de niveles de gamification.php. La vitrina es logros.php; el aviso, assets/g7-ach.js.
 * Doc: toolz/LOGROS.md
 */

const G7_ACH_TIERS = [
    1 => ['name' => 'Bronce',   'color' => '#d08a4d', 'xp' => 20],
    2 => ['name' => 'Plata',    'color' => '#b8c4d4', 'xp' => 50],
    3 => ['name' => 'Oro',      'color' => '#f5c542', 'xp' => 120],
    4 => ['name' => 'Diamante', 'color' => '#7dd3fc', 'xp' => 300],
];
const G7_ACH_CATS = [
    'trabajo'      => ['name' => 'Trabajo',      'icon' => 'fa-briefcase',      'color' => '#60a5fa'],
    'contenido'    => ['name' => 'Contenido',    'icon' => 'fa-layer-group',    'color' => '#ff8a3d'],
    'equipo'       => ['name' => 'Equipo',       'icon' => 'fa-people-group',   'color' => '#34d399'],
    'marketing'    => ['name' => 'Marketing',    'icon' => 'fa-bullhorn',       'color' => '#f59e0b'],
    'diseno'       => ['name' => 'Diseño',       'icon' => 'fa-pen-ruler',      'color' => '#ec4899'],
    'herramientas' => ['name' => 'Herramientas', 'icon' => 'fa-wand-magic-sparkles', 'color' => '#a78bfa'],
    'constancia'   => ['name' => 'Constancia',   'icon' => 'fa-fire',           'color' => '#f472b6'],
    'cuenta'       => ['name' => 'Tu cuenta',    'icon' => 'fa-user-shield',    'color' => '#fbbf24'],
];
/** clave => [categoría, ícono, nombre, texto con {n}, umbrales por nivel, unidad] */
const G7_ACH = [
    'tickets'    => ['trabajo', 'fa-life-ring', 'Solucionador', 'Resolvé {n} tickets', [1, 10, 50, 200], 'tickets'],
    'tickets_ok' => ['trabajo', 'fa-bolt', 'Rayo', 'Resolvé {n} tickets antes del vencimiento', [1, 10, 40, 150], 'a tiempo'],
    'tasks'      => ['trabajo', 'fa-list-check', 'Hacedor', 'Terminá {n} tareas', [1, 10, 50, 200], 'tareas'],
    'stars'      => ['trabajo', 'fa-star', 'Cinco estrellas', 'Recibí {n} calificaciones de 5 estrellas', [1, 5, 20, 60], '5 estrellas'],
    'tk_msgs'    => ['trabajo', 'fa-reply', 'Buena onda', 'Respondé {n} mensajes en tickets', [5, 50, 250, 1000], 'respuestas'],
    'task_notes' => ['trabajo', 'fa-message', 'Comentarista', 'Comentá {n} veces en tareas', [1, 15, 75, 300], 'comentarios'],
    'bugs'       => ['trabajo', 'fa-bug', 'Cazador de errores', 'Reportá {n} incidencias', [1, 5, 20, 60], 'incidencias'],
    'agenda'     => ['trabajo', 'fa-calendar-plus', 'Organizado', 'Agendá {n} eventos en tu calendario', [1, 10, 50, 200], 'eventos'],
    'hours'      => ['trabajo', 'fa-stopwatch', 'Reloj en mano', 'Cargá {n} horas de trabajo', [1, 20, 100, 400], 'horas'],
    'content'    => ['contenido', 'fa-rocket', 'Creador', 'Subí {n} contenidos', [1, 25, 100, 500], 'contenidos'],
    'assets'     => ['contenido', 'fa-cubes', 'Fábrica de piezas', 'Entregá {n} piezas', [10, 100, 1000, 5000], 'piezas'],
    'comments'   => ['contenido', 'fa-comment-dots', 'Buen ojo', 'Dejá {n} comentarios en contenidos', [1, 20, 100, 300], 'comentarios'],
    'posts'      => ['equipo', 'fa-bullhorn', 'Voz del equipo', 'Publicá {n} novedades en Comunidad', [1, 5, 20, 60], 'novedades'],
    'reacts'     => ['equipo', 'fa-face-smile', 'Reaccionador', 'Reaccioná {n} veces', [5, 50, 200, 800], 'reacciones'],
    // Comunidad 3 (2026-11-14): interacción entre personas
    'kudos_given' => ['equipo', 'fa-hand-holding-heart', 'Generosidad', 'Dá {n} reconocimientos', [1, 10, 40, 120], 'reconocimientos'],
    'kudos_got'   => ['equipo', 'fa-award', 'Reconocido', 'Recibí {n} reconocimientos', [1, 5, 20, 60], 'reconocimientos'],
    'coffee'      => ['equipo', 'fa-mug-hot', 'Cafetero', 'Completá {n} cafés de la semana', [1, 5, 15, 40], 'cafés'],
    'qotd'        => ['equipo', 'fa-comment-dots', 'Opinólogo', 'Respondé {n} preguntas del día', [1, 10, 40, 120], 'respuestas'],
    'polls'       => ['equipo', 'fa-square-poll-vertical', 'Encuestador', 'Creá {n} encuestas', [1, 5, 20, 60], 'encuestas'],
    'votes'       => ['equipo', 'fa-check-to-slot', 'Votante', 'Votá en {n} encuestas', [5, 25, 100, 300], 'votos'],
    'greetings'   => ['equipo', 'fa-cake-candles', 'Siempre presente', 'Saludá en {n} cumpleaños o festejos', [1, 10, 40, 120], 'saludos'],
    'files'      => ['equipo', 'fa-paperclip', 'Compartidor', 'Compartí {n} archivos en el chat', [1, 20, 100, 400], 'archivos'],
    'convs'      => ['equipo', 'fa-hashtag', 'Sociable', 'Sumate a {n} conversaciones', [3, 10, 25, 50], 'conversaciones'],
    'chat'       => ['equipo', 'fa-comments', 'Conversador', 'Mandá {n} mensajes en el chat', [10, 200, 1000, 5000], 'mensajes'],
    'mood'       => ['equipo', 'fa-heart-pulse', 'Termómetro', 'Contá cómo estás {n} días', [1, 10, 50, 150], 'días'],
    'requests'   => ['equipo', 'fa-hand-holding-heart', 'Pedidor', 'Hacé {n} pedidos al equipo', [1, 10, 50, 150], 'pedidos'],
    'landings'   => ['marketing', 'fa-file-code', 'Landing master', 'Creá {n} landings', [1, 10, 40, 150], 'landings'],
    'land_views' => ['marketing', 'fa-eye', 'Imán de visitas', 'Sumá {n} visitas a tus landings', [100, 1000, 10000, 100000], 'visitas'],
    'mailings'   => ['marketing', 'fa-envelope-open-text', 'Cartero', 'Exportá {n} mailings', [1, 20, 100, 400], 'mailings'],
    'pages'      => ['marketing', 'fa-file-lines', 'Constructor', 'Creá {n} páginas en Pages', [1, 5, 20, 60], 'páginas'],
    'planify'    => ['marketing', 'fa-calendar-days', 'Estratega', 'Planificá {n} publicaciones', [1, 10, 50, 200], 'publicaciones'],
    'popups'     => ['marketing', 'fa-window-restore', 'Popupero', 'Guardá {n} popups', [1, 5, 20, 60], 'popups'],
    'calco'      => ['diseno', 'fa-note-sticky', 'Stickero', 'Abrí Calco {n} veces', [1, 10, 50, 200], 'veces'],
    'rotulo'     => ['diseno', 'fa-heading', 'Titulero', 'Abrí Rótulo {n} veces', [1, 10, 50, 200], 'veces'],
    'globo'      => ['diseno', 'fa-comment-dots', 'Charlatán', 'Abrí Globo {n} veces', [1, 10, 50, 200], 'veces'],
    'etiqueta'   => ['diseno', 'fa-at', 'Etiquetador', 'Abrí Etiqueta {n} veces', [1, 10, 50, 200], 'veces'],
    'convert'    => ['diseno', 'fa-feather', 'Peso pluma', 'Usá el convertidor de imágenes {n} veces', [1, 20, 100, 400], 'veces'],
    'imghost'    => ['diseno', 'fa-cloud-arrow-up', 'Subidor serial', 'Subí {n} imágenes al Image Host', [1, 50, 250, 1000], 'imágenes'],
    'stock'      => ['diseno', 'fa-photo-film', 'Archivista', 'Aportá {n} recursos al Stock', [1, 25, 100, 500], 'recursos'],
    'placas'     => ['diseno', 'fa-futbol', 'Placa a placa', 'Abrí los generadores de placas {n} veces', [1, 20, 100, 400], 'veces'],
    'favs'       => ['herramientas', 'fa-heart', 'Coleccionista', 'Guardá {n} favoritos', [1, 10, 30, 100], 'favoritos'],
    'explorer'   => ['herramientas', 'fa-compass', 'Explorador', 'Usá {n} herramientas distintas', [3, 8, 15, 25], 'herramientas'],
    'studio'     => ['herramientas', 'fa-palette', 'Artista', 'Abrí {n} veces las herramientas del estudio', [5, 50, 200, 800], 'veces'],
    'library'    => ['herramientas', 'fa-book-bookmark', 'Curador', 'Abrí {n} recursos de la biblioteca', [10, 100, 500, 2000], 'recursos'],
    'streak'     => ['constancia', 'fa-fire', 'En racha', 'Entrá {n} días seguidos', [3, 7, 30, 90], 'días seguidos'],
    'days'       => ['constancia', 'fa-calendar-check', 'Siempre presente', 'Usá Cronos {n} días', [5, 30, 120, 365], 'días'],
    'veteran'    => ['constancia', 'fa-medal', 'Veterano', 'Cumplí {n} días en el equipo', [30, 180, 365, 1095], 'días'],
    'owl'        => ['constancia', 'fa-moon', 'Búho', 'Trabajá {n} veces de madrugada', [1, 10, 40, 120], 'madrugadas'],
    'early'      => ['constancia', 'fa-sun', 'Madrugador', 'Arrancá {n} días antes de las 8', [1, 10, 40, 120], 'mañanas'],
    'onboarding' => ['cuenta', 'fa-flag-checkered', 'A bordo', 'Terminá la bienvenida', [1], ''],
    'secure'     => ['cuenta', 'fa-shield-halved', 'Cuenta blindada', 'Activá la verificación en dos pasos', [1], ''],
    'profile'    => ['cuenta', 'fa-id-badge', 'Perfil completo', 'Completá foto, puesto y biografía', [1], ''],
    'style'      => ['cuenta', 'fa-swatchbook', 'Con estilo', 'Elegí un tema propio en Mi configuración', [1], ''],
    'beats'      => ['cuenta', 'fa-volume-high', 'Con ritmo', 'Prendé los sonidos de la interfaz', [1], ''],
];


// ═════ Familias generadas: hacen que haya unas 500 medallas sin escribirlas una por una ═════
const G7_ACH_WEEKDAYS = [1 => ['Lunes de acero', 'fa-mug-hot'], 2 => ['Martes constante', 'fa-person-running'], 3 => ['Miércoles en marcha', 'fa-gears'], 4 => ['Jueves imparable', 'fa-bolt'], 5 => ['Viernes con todo', 'fa-champagne-glasses'], 6 => ['Sábado de guardia', 'fa-shield-heart'], 7 => ['Domingo heroico', 'fa-mask']];
const G7_ACH_MONTHS = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'];
const G7_ACH_EMOJIS = ['🔥' => ['Fuego', 'fa-fire-flame-curved'], '❤️' => ['Corazón grande', 'fa-heart'], '😂' => ['Risas', 'fa-face-laugh-squint'], '👍' => ['Pulgar arriba', 'fa-thumbs-up'], '🎉' => ['Fiestero', 'fa-champagne-glasses'], '👀' => ['Ojo de águila', 'fa-eye'], '🙌' => ['Aplausos', 'fa-hands-clapping'], '💯' => ['Cien por cien', 'fa-percent']];
const G7_ACH_GROUPCAT = ['Contenido' => 'marketing', 'Diseño' => 'diseno', 'Herramientas' => 'herramientas', 'Comunidad' => 'equipo', 'Gestión' => 'trabajo'];

/** Catálogo completo: los fijos (G7_ACH) + los generados con lo que tiene este sitio (módulos, tipos, plataformas, categorías). */
function g7_ach_catalog(?PDO $db = null): array {
    static $c = null; if ($c !== null) return $c;
    $c = G7_ACH;
    $T4 = [1, 10, 50, 200];
    // una por herramienta del sitio
    if (function_exists('g7_modules')) foreach (g7_modules() as $id => $m) {
        if (in_array($id, ['dashboard', 'perfil', 'soporte'], true) || ($m['mode'] ?? '') === 'off' || empty($m['url'])) continue;
        $cat = G7_ACH_GROUPCAT[$m['group'] ?? ''] ?? 'herramientas';
        $lb = $id === 'claude' ? 'el asistente IA' : $m['label']; // sin marcas de terceros
        $c['mod_' . $id] = [$cat, $m['icon'] ?: 'fa-cube', 'Fan de ' . $lb, 'Abrí ' . $lb . ' {n} veces', $T4, 'veces'];
    }
    foreach (G7_ACH_WEEKDAYS as $d => [$n, $i]) $c['wd_' . $d] = ['constancia', $i, $n, 'Trabajá {n} ' . strtolower(explode(' ', $n)[0]) . 's', [3, 15, 40, 100], 'días'];
    foreach (G7_ACH_MONTHS as $mm => $n) $c['mon_' . $mm] = ['constancia', 'fa-calendar', 'Presente en ' . $n, 'Usá Cronos en ' . $n, [1], ''];
    $k = 0; foreach (G7_ACH_EMOJIS as $e => [$n, $i]) $c['emo_' . (++$k)] = ['equipo', $i, $n, 'Reaccioná {n} veces con ' . $e, [1, 10, 50, 150], 'reacciones'];
    foreach (['urgente' => ['Bombero', 'fa-fire-extinguisher'], 'alta' => ['Prioridad uno', 'fa-arrow-up'], 'normal' => ['Día a día', 'fa-equals'], 'baja' => ['Sin apuro', 'fa-arrow-down']] as $pr => [$n, $i])
        $c['prio_' . $pr] = ['trabajo', $i, $n, 'Resolvé {n} tickets de prioridad ' . $pr, [1, 10, 50, 150], 'tickets'];
    if (defined('G7_LEVELS')) foreach (G7_LEVELS as $lv => $L) if ($L['min'] > 0) $c['rank_' . $lv] = ['cuenta', 'fa-ranking-star', 'Rango ' . $L['label'] . ' ' . $L['emoji'], 'Llegá a ' . number_format($L['min'], 0, ',', '.') . ' XP', [1], ''];
    // contenido (sin marcas): formatos, producción y me gusta
    $c['media_video'] = ['contenido', 'fa-film', 'Director', 'Subí {n} contenidos en video', [1, 10, 40, 120], 'videos'];
    $c['media_img'] = ['contenido', 'fa-camera-retro', 'Fotógrafo', 'Subí {n} contenidos con imagen', [1, 10, 50, 200], 'contenidos'];
    $c['qty_graf'] = ['contenido', 'fa-pen-ruler', 'Diseñador incansable', 'Sumá {n} gráficas producidas', [10, 100, 500, 1500], 'gráficas'];
    $c['qty_vid'] = ['contenido', 'fa-clapperboard', 'Editor de video', 'Sumá {n} videos producidos', [5, 50, 200, 600], 'videos'];
    $c['likes_got'] = ['contenido', 'fa-heart', 'Querido por todos', 'Recibí {n} me gusta en tus contenidos', [5, 25, 100, 300], 'me gusta'];
    $c['likes_given'] = ['equipo', 'fa-hand-holding-heart', 'Hinchada', 'Dá {n} me gusta a contenidos del equipo', [5, 25, 100, 300], 'me gusta'];
    $c['collector'] = ['cuenta', 'fa-trophy', 'Coleccionista de logros', 'Ganá {n} medallas', [10, 50, 150, 300], 'medallas'];
    if ($db) {
        $q = fn($sql) => (function () use ($db, $sql) { try { return $db->query($sql)->fetchAll(PDO::FETCH_NUM); } catch (Throwable $e) { return []; } })();
        $TYPE = ['promo' => 'promos', 'launch' => 'lanzamientos', 'paid' => 'piezas de pauta', 'social' => 'piezas de redes', 'mailing' => 'mailings', 'landing' => 'landings'];
        foreach ($q("SELECT DISTINCT type FROM g7_content WHERE type <> '' AND type <> 'tarea' LIMIT 12") as [$t]) $c['ctype_' . preg_replace('/[^a-z0-9]/i', '', $t)] = ['contenido', 'fa-shapes', 'Especialista en ' . ($TYPE[$t] ?? $t), 'Subí {n} ' . ($TYPE[$t] ?? $t), [1, 10, 50, 200], 'contenidos'];
        // Los logros no nombran marcas: nada de "Mailings para <plataforma>"; se cuenta en cuántas plataformas distintas trabajaste.
        $c['mail_plats'] = ['marketing', 'fa-envelopes-bulk', 'Multimarca', 'Exportá mailings para {n} plataformas distintas', [2, 4, 8, 12], 'plataformas'];
        $c['land_plats'] = ['marketing', 'fa-layer-group', 'Todoterreno', 'Creá landings para {n} plataformas distintas', [2, 3, 5, 8], 'plataformas'];
        // categorías de tickets: se saltean las que nombran una plataforma (marca)
        $brands = array_map(fn($r) => mb_strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$r[0])), array_merge($q("SELECT DISTINCT platform FROM g7_mailing_exports WHERE platform <> ''"), $q("SELECT DISTINCT platform FROM g7_landings WHERE platform <> ''")));
        $brands = array_filter(array_unique($brands), fn($b) => strlen($b) >= 4);
        foreach ($q("SELECT id, name FROM g7_wk_ticket_cats WHERE active = 1 LIMIT 20") as [$id, $nm]) {
            $flat = mb_strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$nm)); $isBrand = false;
            foreach ($brands as $b) if (strpos($flat, $b) !== false || strpos($b, $flat) !== false) { $isBrand = true; break; }
            if (!$isBrand) $c['tkcat_' . (int)$id] = ['trabajo', 'fa-tag', 'Experto en ' . $nm, 'Resolvé {n} tickets de ' . $nm, [1, 10, 40, 120], 'tickets'];
        }
    }
    return $c;
}

/** Números de las familias generadas (con pocas consultas agrupadas). */
function g7_ach_metrics_gen(PDO $db, int $uid): array {
    $m = [];
    $rows = function (string $sql, array $p) use ($db) { try { $q = $db->prepare($sql); $q->execute($p); return $q->fetchAll(PDO::FETCH_NUM); } catch (Throwable $e) { return []; } };
    // herramientas: cada acceso cuenta para el módulo cuya dirección empieza igual
    $byTool = $rows('SELECT tool, COUNT(*) FROM g7_accesos_rapidos WHERE user_id = ? GROUP BY tool', [$uid]);
    if (function_exists('g7_modules')) foreach (g7_modules() as $id => $mod) {
        if (empty($mod['url'])) continue; $u = rtrim(parse_url((string)$mod['url'], PHP_URL_PATH) ?: '', '/'); $n = 0;
        $alts = array_map(fn($p) => '/toolz/' . rtrim($p, '/'), (array)($mod['paths'] ?? []));
        foreach ($byTool as [$t, $c]) { $tp = rtrim(parse_url((string)$t, PHP_URL_PATH) ?: (string)$t, '/'); foreach (array_merge([$u], $alts) as $a) if ($a !== '' && $a !== '/toolz' && strpos($tp, $a) === 0) { $n += (int)$c; break; } }
        $m['mod_' . $id] = $n;
    }
    foreach ($rows('SELECT WEEKDAY(accessed_at) + 1, COUNT(DISTINCT DATE(accessed_at)) FROM g7_accesos_rapidos WHERE user_id = ? GROUP BY 1', [$uid]) as [$d, $c]) $m['wd_' . (int)$d] = (int)$c;
    foreach ($rows('SELECT MONTH(accessed_at), COUNT(*) FROM g7_accesos_rapidos WHERE user_id = ? GROUP BY 1', [$uid]) as [$mm, $c]) $m['mon_' . (int)$mm] = $c ? 1 : 0;
    $emo = []; foreach (['g7_ch_reactions', 'g7_post_reactions'] as $tb) foreach ($rows("SELECT emoji, COUNT(*) FROM $tb WHERE user_id = ? GROUP BY emoji", [$uid]) as [$e, $c]) $emo[$e] = ($emo[$e] ?? 0) + (int)$c;
    $k = 0; foreach (G7_ACH_EMOJIS as $e => $_) { $k++; $m['emo_' . $k] = ($emo[$e] ?? 0) + ($emo[str_replace("️", '', $e)] ?? 0); }
    foreach ($rows("SELECT priority, COUNT(*) FROM g7_wk_tickets WHERE assignee_id = ? AND status IN ('resuelto','cerrado') GROUP BY priority", [$uid]) as [$pr, $c]) $m['prio_' . $pr] = (int)$c;
    $xp = (int)g7_ach_q($db, 'SELECT xp_total FROM g7_user_xp WHERE user_id = ?', [$uid]);
    if (defined('G7_LEVELS')) foreach (G7_LEVELS as $lv => $L) $m['rank_' . $lv] = $xp >= $L['min'] ? 1 : 0;
    $cat = g7_ach_catalog($db); $m['collector'] = count(array_filter($rows("SELECT ach_key FROM g7_achievements WHERE user_id = ? AND ach_key <> 'collector'", [$uid]), fn($r) => isset($cat[$r[0]]))); // solo logros vigentes
    foreach ($rows("SELECT type, COUNT(*) FROM g7_content WHERE created_by = ? GROUP BY type", [$uid]) as [$t, $c]) $m['ctype_' . preg_replace('/[^a-z0-9]/i', '', (string)$t)] = (int)$c;
    $m['media_video'] = (int)g7_ach_q($db, "SELECT COUNT(*) FROM g7_content WHERE created_by = ? AND media_type = 'video'", [$uid]);
    $m['media_img'] = (int)g7_ach_q($db, "SELECT COUNT(*) FROM g7_content WHERE created_by = ? AND media_type = 'imagen'", [$uid]);
    $m['qty_graf'] = (int)g7_ach_q($db, 'SELECT COALESCE(SUM(qty_graficas), 0) FROM g7_content WHERE created_by = ?', [$uid]);
    $m['qty_vid'] = (int)g7_ach_q($db, 'SELECT COALESCE(SUM(qty_videos), 0) FROM g7_content WHERE created_by = ?', [$uid]);
    $m['likes_got'] = (int)g7_ach_q($db, 'SELECT COUNT(*) FROM g7_content_likes l JOIN g7_content c ON c.id = l.content_id WHERE c.created_by = ? AND l.user_id <> c.created_by', [$uid]);
    $m['likes_given'] = (int)g7_ach_q($db, 'SELECT COUNT(*) FROM g7_content_likes WHERE user_id = ?', [$uid]);
    $m['mail_plats'] =(int)g7_ach_q($db, "SELECT COUNT(DISTINCT platform) FROM g7_mailing_exports WHERE user_id = ? AND platform <> ''", [$uid]);
    $m['land_plats'] = (int)g7_ach_q($db, "SELECT COUNT(DISTINCT platform) FROM g7_landings WHERE created_by = ? AND platform <> ''", [$uid]);
    foreach ($rows("SELECT category_id, COUNT(*) FROM g7_wk_tickets WHERE assignee_id = ? AND status IN ('resuelto','cerrado') GROUP BY category_id", [$uid]) as [$cid, $c]) $m['tkcat_' . (int)$cid] = (int)$c;
    return $m;
}

function g7_ach_install(PDO $db): void {
    static $ok = false; if ($ok) return; $ok = true;
    $db->exec("CREATE TABLE IF NOT EXISTS g7_achievements (
        user_id INT NOT NULL, ach_key VARCHAR(40) NOT NULL, tier TINYINT NOT NULL, unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, seen TINYINT NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, ach_key, tier), INDEX idx_user_seen (user_id, seen)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Un número desde la base; si la tabla o la columna no existe en este sitio, 0. */
function g7_ach_q(PDO $db, string $sql, array $p): float {
    try { $q = $db->prepare($sql); $q->execute($p); return (float)($q->fetchColumn() ?: 0); } catch (Throwable $e) { return 0; }
}

/** Lo que mide cada logro, para una persona. */
function g7_ach_metrics(PDO $db, int $uid): array {
    $m = [];
    $m['tickets']    = g7_ach_q($db, "SELECT COUNT(*) FROM g7_wk_tickets WHERE assignee_id = ? AND status IN ('resuelto','cerrado')", [$uid]);
    $m['tickets_ok'] = g7_ach_q($db, "SELECT COUNT(*) FROM g7_wk_tickets WHERE assignee_id = ? AND status IN ('resuelto','cerrado') AND resolved_at IS NOT NULL AND resolution_due IS NOT NULL AND resolved_at <= resolution_due", [$uid]);
    $m['tasks']      = g7_ach_q($db, "SELECT COUNT(DISTINCT t.id) FROM g7_tasks t JOIN g7_task_assignees a ON a.task_id = t.id WHERE a.user_id = ? AND (t.completed_at IS NOT NULL OR t.status = 'finalizado')", [$uid]);
    $m['stars']      = g7_ach_q($db, "SELECT COUNT(*) FROM g7_wk_ratings WHERE assignee_id = ? AND stars >= 5", [$uid]);
    $m['hours']      = floor((g7_ach_q($db, "SELECT COALESCE(SUM(minutes),0) FROM g7_wk_tk_time WHERE user_id = ?", [$uid]) + g7_ach_q($db, "SELECT COALESCE(SUM(minutes),0) FROM g7_task_time WHERE user_id = ?", [$uid])) / 60);
    $m['content']    = g7_ach_q($db, "SELECT COUNT(*) FROM g7_content WHERE created_by = ?", [$uid]);
    $m['assets']     = g7_ach_q($db, "SELECT COALESCE(SUM(COALESCE(qty_graficas,0)+COALESCE(qty_videos,0)+COALESCE(qty_landings,0)+COALESCE(qty_mailings,0)+COALESCE(qty_creativos_paid,0)+COALESCE(qty_assets_rrss,0)+COALESCE(qty_assets_mobile,0)),0) FROM g7_content WHERE created_by = ?", [$uid]);
    $m['comments']   = g7_ach_q($db, "SELECT COUNT(*) FROM g7_content_comments WHERE author_id = ?", [$uid]);
    $m['chat']       = g7_ach_q($db, "SELECT COUNT(*) FROM g7_ch_messages WHERE user_id = ? AND deleted_at IS NULL", [$uid]);
    $m['mood']       = g7_ach_q($db, "SELECT COUNT(*) FROM g7_mood WHERE user_id = ?", [$uid]);
    $m['requests']   = g7_ach_q($db, "SELECT COUNT(*) FROM g7_wk_tickets WHERE requester_id = ?", [$uid]);
    $m['explorer']   = g7_ach_q($db, "SELECT COUNT(DISTINCT tool) FROM g7_accesos_rapidos WHERE user_id = ?", [$uid]);
    $m['studio']     = g7_ach_q($db, "SELECT COUNT(*) FROM g7_accesos_rapidos WHERE user_id = ? AND (tool LIKE '/toolz/taller/%' OR tool IN ('/toolz/webpmaker.php','/toolz/webp_converter.php','/toolz/jpg_converter.php','/toolz/svg_converter.php','/toolz/recolorearsvg.php','/toolz/renombrador.php'))", [$uid]);
    $m['library']    = g7_ach_q($db, "SELECT COUNT(*) FROM g7_link_clicks WHERE user_id = ?", [$uid]);
    $m['days']       = g7_ach_q($db, "SELECT COUNT(DISTINCT DATE(accessed_at)) FROM g7_accesos_rapidos WHERE user_id = ?", [$uid]);
    $m['veteran']    = g7_ach_q($db, "SELECT GREATEST(0, DATEDIFF(NOW(), created_at)) FROM g7_users WHERE id = ?", [$uid]);
    $m['owl']        = g7_ach_q($db, "SELECT COUNT(DISTINCT DATE(accessed_at)) FROM g7_accesos_rapidos WHERE user_id = ? AND HOUR(accessed_at) BETWEEN 0 AND 4", [$uid]);
    $m['early']      = g7_ach_q($db, "SELECT COUNT(DISTINCT DATE(accessed_at)) FROM g7_accesos_rapidos WHERE user_id = ? AND HOUR(accessed_at) BETWEEN 5 AND 7", [$uid]);
    $acc = fn(string $like) => g7_ach_q($db, "SELECT COUNT(*) FROM g7_accesos_rapidos WHERE user_id = ? AND tool LIKE ?", [$uid, $like]);
    $m['tk_msgs']    = g7_ach_q($db, "SELECT COUNT(*) FROM g7_wk_ticket_msgs WHERE user_id = ?", [$uid]);
    $m['task_notes'] = g7_ach_q($db, "SELECT COUNT(*) FROM g7_task_comments WHERE user_id = ?", [$uid]);
    $m['bugs']       = g7_ach_q($db, "SELECT COUNT(*) FROM g7_status_issues WHERE created_by = ?", [$uid]);
    $m['agenda']     = g7_ach_q($db, "SELECT COUNT(*) FROM g7_cal_events WHERE user_id = ?", [$uid]);
    // solo lo que la persona escribió (no las publicaciones automáticas ni la pregunta del día)
    $m['posts']      = g7_ach_q($db, "SELECT COUNT(*) FROM g7_posts WHERE user_id = ? AND kind IN ('post','poll')", [$uid]) ?: g7_ach_q($db, "SELECT COUNT(*) FROM g7_posts WHERE user_id = ?", [$uid]);
    $m['kudos_given'] = g7_ach_q($db, "SELECT COUNT(*) FROM g7_posts WHERE user_id = ? AND kind = 'kudos'", [$uid]);
    $m['kudos_got']   = g7_ach_q($db, "SELECT COUNT(*) FROM g7_posts WHERE target_user_id = ? AND kind = 'kudos'", [$uid]);
    $m['coffee']      = g7_ach_q($db, "SELECT COUNT(*) FROM g7_cm_coffee WHERE (a = ? AND done_a = 1) OR (b = ? AND done_b = 1)", [$uid, $uid]);
    $m['qotd']        = g7_ach_q($db, "SELECT COUNT(DISTINCT r.post_id) FROM g7_post_replies r JOIN g7_posts p ON p.id = r.post_id WHERE r.user_id = ? AND p.kind = 'qotd'", [$uid]);
    $m['polls']       = g7_ach_q($db, "SELECT COUNT(*) FROM g7_posts WHERE user_id = ? AND kind = 'poll'", [$uid]);
    $m['votes']       = g7_ach_q($db, "SELECT COUNT(DISTINCT post_id) FROM g7_cm_votes WHERE user_id = ?", [$uid]);
    $m['greetings']   = g7_ach_q($db, "SELECT COUNT(DISTINCT r.post_id) FROM g7_post_replies r JOIN g7_posts p ON p.id = r.post_id WHERE r.user_id = ? AND p.kind = 'auto' AND p.user_id <> r.user_id", [$uid]);
    $m['reacts']     = g7_ach_q($db, "SELECT COUNT(*) FROM g7_ch_reactions WHERE user_id = ?", [$uid]) + g7_ach_q($db, "SELECT COUNT(*) FROM g7_post_reactions WHERE user_id = ?", [$uid]) + g7_ach_q($db, "SELECT COUNT(*) FROM g7_task_comment_reactions WHERE user_id = ?", [$uid]);
    $m['files']      = g7_ach_q($db, "SELECT COUNT(*) FROM g7_ch_files WHERE user_id = ?", [$uid]);
    $m['convs']      = g7_ach_q($db, "SELECT COUNT(*) FROM g7_ch_members WHERE user_id = ?", [$uid]);
    $m['landings']   = g7_ach_q($db, "SELECT COUNT(*) FROM g7_landings WHERE created_by = ?", [$uid]);
    $m['land_views'] = g7_ach_q($db, "SELECT COUNT(*) FROM g7_landing_views v JOIN g7_landings l ON l.id = v.landing_id WHERE l.created_by = ?", [$uid]);
    $m['mailings']   = g7_ach_q($db, "SELECT COUNT(*) FROM g7_mailing_exports WHERE user_id = ?", [$uid]);
    $m['pages']      = g7_ach_q($db, "SELECT COUNT(*) FROM g7_pages WHERE owner_id = ?", [$uid]);
    $m['planify']    = g7_ach_q($db, "SELECT COUNT(*) FROM g7_planify_posts WHERE created_by = ?", [$uid]);
    $m['popups']     = g7_ach_q($db, "SELECT COUNT(*) FROM g7_popup_saves WHERE created_by = ? AND COALESCE(is_preset,0) = 0", [$uid]);
    $m['calco']      = $acc('/toolz/taller/calco%');
    $m['rotulo']     = $acc('/toolz/taller/rotulo%');
    $m['globo']      = $acc('/toolz/taller/globo%');
    $m['etiqueta']   = $acc('/toolz/taller/etiqueta%');
    $m['convert']    = $acc('/toolz/webp%') + $acc('/toolz/jpg_converter%');
    $m['imghost']    = g7_ach_q($db, "SELECT COUNT(*) FROM g7_imghost WHERE user_id = ?", [$uid]);
    $m['stock']      = g7_ach_q($db, "SELECT COUNT(*) FROM g7_stock_items WHERE owner_id = ?", [$uid]);
    $m['placas']     = $acc('/toolz/placasdeportivas%') + $acc('/toolz/placaspaid%');
    $m['favs']       = g7_ach_q($db, "SELECT COUNT(*) FROM g7_link_favorites WHERE user_id = ?", [$uid]) + g7_ach_q($db, "SELECT COUNT(*) FROM g7_stock_favorites WHERE user_id = ?", [$uid]);
    $m['style']      = g7_ach_q($db, "SELECT COUNT(*) FROM g7_user_prefs WHERE user_id = ? AND pref_key = 'theme' AND pref_value NOT IN ('\"dark\"', 'dark', '')", [$uid]);
    $m['beats']      = g7_ach_q($db, "SELECT COUNT(*) FROM g7_user_prefs WHERE user_id = ? AND pref_key = 'ui_sounds' AND pref_value IN ('true', '1', '\"1\"')", [$uid]);
    // racha: la más larga de días seguidos con actividad
    $best = 0;
    try {
        $q = $db->prepare("SELECT DISTINCT DATE(accessed_at) d FROM g7_accesos_rapidos WHERE user_id = ? ORDER BY d");
        $q->execute([$uid]); $prev = null; $run = 0;
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $t = strtotime($d); $run = ($prev !== null && $t - $prev === 86400) ? $run + 1 : 1; $prev = $t; if ($run > $best) $best = $run;
        }
    } catch (Throwable $e) {}
    $m['streak'] = $best;
    $m['onboarding'] = g7_ach_q($db, "SELECT COUNT(*) FROM g7_onboarding WHERE user_id = ? AND (status = 'done' OR completed_at IS NOT NULL)", [$uid]);
    $m['secure']     = g7_ach_q($db, "SELECT COUNT(*) FROM g7_onboarding WHERE user_id = ? AND totp_at IS NOT NULL", [$uid]);
    if (!$m['secure'] && function_exists('g7_totp_enabled')) { try { $m['secure'] = g7_totp_enabled($db, $uid) ? 1 : 0; } catch (Throwable $e) {} }
    $m['profile']    = g7_ach_q($db, "SELECT COUNT(*) FROM g7_users WHERE id = ? AND COALESCE(avatar,'') <> '' AND COALESCE(position,'') <> '' AND COALESCE(bio,'') <> ''", [$uid]);
    return $m + g7_ach_metrics_gen($db, $uid);
}

/** Nivel alcanzado (0 = ninguno) para un valor. */
function g7_ach_tier_for(array $th, float $v): int { $t = 0; foreach ($th as $i => $need) if ($v >= $need) $t = $i + 1; return $t; }

/** Calcula, guarda los niveles nuevos (y les da XP) y devuelve los recién desbloqueados. */
function g7_ach_evaluate(PDO $db, int $uid): array {
    g7_ach_install($db);
    $m = g7_ach_metrics($db, $uid);
    $have = [];
    $q = $db->prepare('SELECT ach_key, MAX(tier) FROM g7_achievements WHERE user_id = ? GROUP BY ach_key'); $q->execute([$uid]);
    foreach ($q->fetchAll(PDO::FETCH_NUM) as [$k, $t]) $have[$k] = (int)$t;
    $new = [];
    $ins = $db->prepare('INSERT IGNORE INTO g7_achievements (user_id, ach_key, tier) VALUES (?,?,?)');
    foreach (g7_ach_catalog($db) as $k => $a) {
        $reach = g7_ach_tier_for($a[4], $m[$k] ?? 0);
        for ($t = ($have[$k] ?? 0) + 1; $t <= $reach; $t++) {
            $ins->execute([$uid, $k, $t]);
            if ($ins->rowCount()) {
                $new[] = ['key' => $k, 'tier' => $t];
                if (function_exists('g7AwardXP')) { try { g7AwardXP($db, $uid, G7_ACH_TIERS[min(4, $t)]['xp'], 'logro:' . $k . ':' . $t); } catch (Throwable $e) {} }
            }
        }
    }
    // Coleccionista: medallas totales (incluye las recién ganadas)
    $tot = (int)g7_ach_q($db, 'SELECT COUNT(*) FROM g7_achievements WHERE user_id = ? AND ach_key <> ?', [$uid, 'collector']);
    $col = g7_ach_catalog($db)['collector'];
    for ($t = max($have['collector'] ?? 0, 0) + 1; $t <= g7_ach_tier_for($col[4], $tot); $t++) {
        $ins->execute([$uid, 'collector', $t]);
        if ($ins->rowCount()) { $new[] = ['key' => 'collector', 'tier' => $t]; if (function_exists('g7AwardXP')) { try { g7AwardXP($db, $uid, G7_ACH_TIERS[$t]['xp'], 'logro:collector:' . $t); } catch (Throwable $e) {} } }
    }
    return $new;
}

/** Los logros de una persona para mostrar: nivel, progreso al próximo, fechas. */
function g7_ach_state(PDO $db, int $uid): array {
    g7_ach_install($db);
    $m = g7_ach_metrics($db, $uid);
    $dates = [];
    $q = $db->prepare('SELECT ach_key, tier, unlocked_at FROM g7_achievements WHERE user_id = ?'); $q->execute([$uid]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $dates[$r['ach_key']][(int)$r['tier']] = $r['unlocked_at'];
    $out = [];
    foreach (g7_ach_catalog($db) as $k => [$cat, $icon, $name, $txt, $th, $unit]) {
        $v = (float)($m[$k] ?? 0); $tier = 0; foreach ($dates[$k] ?? [] as $t => $_) $tier = max($tier, $t);
        $tier = max($tier, g7_ach_tier_for($th, $v));
        $next = $th[$tier] ?? null; $prev = $tier ? $th[$tier - 1] : 0;
        $out[] = ['key' => $k, 'cat' => $cat, 'icon' => $icon, 'name' => $name, 'unit' => $unit, 'value' => $v, 'tier' => $tier, 'max' => count($th),
                  'goal' => $next, 'text' => str_replace('{n}', (string)($next ?? end($th)), $txt),
                  'pct' => $next === null ? 100 : (int)max(0, min(100, round(($v - $prev) * 100 / max(1, $next - $prev)))),
                  'dates' => $dates[$k] ?? []];
    }
    return $out;
}

/** Los desbloqueos que la persona todavía no vio (para el aviso), y se marcan como vistos. */
function g7_ach_unseen(PDO $db, int $uid): array {
    g7_ach_install($db);
    $q = $db->prepare('SELECT ach_key, tier FROM g7_achievements WHERE user_id = ? AND seen = 0 ORDER BY unlocked_at, tier'); $q->execute([$uid]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) $db->prepare('UPDATE g7_achievements SET seen = 1 WHERE user_id = ? AND seen = 0')->execute([$uid]);
    $cat = g7_ach_catalog($db);
    return array_values(array_filter(array_map(function ($r) use ($cat) {
        $a = $cat[$r['ach_key']] ?? null; if (!$a) return null; $t = (int)$r['tier'];
        return ['key' => $r['ach_key'], 'tier' => $t, 'tier_name' => count($a[4]) > 1 ? G7_ACH_TIERS[$t]['name'] : 'Logrado', 'color' => G7_ACH_TIERS[$t]['color'],
                'name' => $a[2], 'icon' => $a[1], 'text' => str_replace('{n}', (string)$a[4][$t - 1], $a[3]), 'xp' => G7_ACH_TIERS[$t]['xp']];
    }, $rows)));
}
