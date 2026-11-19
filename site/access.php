<?php
/**
 * access.php — Núcleo de acceso de Toolz
 *
 * Concentra en un solo lugar:
 *   - Registro de módulos (qué páginas forman cada herramienta).
 *   - Política de acceso por módulo: todos / por equipos / solo admins / apagado.
 *   - Permisos por equipo (g7_team_permissions) y excepciones por usuario (g7_user_permissions).
 *   - Marcas (las del paquete marcas/ del sitio o las que carga el admin) y qué usuarios/equipos ven cada una.
 *   - Ajustes de la plataforma (g7_config) con su esquema, y auditoría de cambios.
 *
 * Lo incluye auth.php. No produce salida salvo las pantallas de bloqueo.
 */

if (!defined('G7_BASE')) define('G7_BASE', '/toolz/');
const G7_ACCESS_SCHEMA = 3;
const G7_MODES = ['everyone', 'teams', 'admins', 'superadmins', 'off'];

// ═══════════════════════════════════════════════════════════
// CONFIGURACIÓN (g7_config)
// ═══════════════════════════════════════════════════════════

function &g7__cfg_store(): array { static $s = null; if ($s === null) $s = ['loaded' => false, 'data' => []]; return $s; }

function g7_cfg_all(): array {
    $s = &g7__cfg_store();
    if (!$s['loaded']) {
        $s['loaded'] = true;
        try {
            foreach (getDB()->query("SELECT cfg_key, cfg_value FROM g7_config")->fetchAll() as $r) {
                $s['data'][$r['cfg_key']] = $r['cfg_value'];
            }
        } catch (Throwable $e) {}
        $tz = $s['data']['tz_storage'] ?? ($s['data']['timezone'] ?? '');   // PHP escribe en la zona de almacenamiento (tz_lib.php)
        if ($tz && in_array($tz, timezone_identifiers_list(), true)) date_default_timezone_set($tz);
        if ((int)($s['data']['access_schema_version'] ?? 0) < G7_ACCESS_SCHEMA) {
            try { g7_access_install(getDB()); } catch (Throwable $e) {}
        }
    }
    return $s['data'];
}

function g7_cfg(string $key, $default = null) {
    $all = g7_cfg_all();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function g7_cfg_set(string $key, $value): void {
    $value = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
    getDB()->prepare("INSERT INTO g7_config (cfg_key, cfg_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE cfg_value = VALUES(cfg_value)")
           ->execute([$key, $value]);
    $s = &g7__cfg_store();
    $s['data'][$key] = $value;
}

function g7_cfg_json(string $key, $default = []) {
    $v = g7_cfg($key);
    if ($v === null || $v === '') return $default;
    $d = json_decode($v, true);
    return is_array($d) ? $d : $default;
}

/** Valor de un ajuste con el default del esquema. */
function g7_setting(string $key) {
    static $defaults = null;
    if ($defaults === null) {
        $defaults = [];
        foreach (g7_settings_schema() as $g) foreach ($g['fields'] as $f) $defaults[$f['key']] = $f['default'] ?? '';
    }
    return g7_cfg($key, $defaults[$key] ?? '');
}

function g7_setting_on(string $key): bool { return (string)g7_setting($key) === '1'; }

// ═══════════════════════════════════════════════════════════
// ESQUEMA DE AJUSTES
// Agregar un ajuste nuevo = agregar una entrada acá. El admin lo dibuja solo.
// ═══════════════════════════════════════════════════════════

function g7_settings_schema(): array {
    $tz = [];
    foreach (['America/Argentina/Buenos_Aires','America/Asuncion','America/Guatemala','America/Costa_Rica','America/Tegucigalpa','America/Mexico_City','America/Bogota','America/Lima','America/Santiago','America/Montevideo','America/Sao_Paulo','America/New_York','Europe/Madrid','UTC'] as $z) $tz[$z] = str_replace('_', ' ', $z);
    require_once __DIR__ . '/theme_list.php';   // lista única de temas
    $themes = array_map(fn($t) => $t['label'], g7_themes());
    $teams = ['' => 'Ninguno'];
    try { foreach (getDB()->query("SELECT id, name FROM g7_teams WHERE active=1 ORDER BY name") as $t) $teams[(string)$t['id']] = $t['name']; } catch (Throwable $e) {}

    return [
        ['id' => 'general', 'title' => 'Identidad', 'icon' => 'fa-building', 'fields' => [
            ['key' => 'platform_name', 'label' => 'Nombre de la plataforma', 'type' => 'text', 'default' => g7_site('name'), 'help' => 'Se usa en el login, títulos y correos.'],
            ['key' => 'timezone', 'label' => 'Zona horaria', 'type' => 'select', 'options' => $tz, 'default' => 'America/Argentina/Buenos_Aires', 'help' => 'Afecta las fechas que muestra la plataforma.'],
            ['key' => 'accent_color', 'label' => 'Color de acento por defecto', 'type' => 'color', 'default' => '#ff6600', 'help' => 'Para quienes no eligieron uno propio en su perfil.'],
        ]],
        ['id' => 'appearance', 'title' => 'Apariencia por defecto', 'icon' => 'fa-palette', 'fields' => [
            ['key' => 'default_theme', 'label' => 'Tema por defecto', 'type' => 'select', 'options' => $themes, 'default' => 'dark', 'help' => 'Cada usuario puede cambiarlo en su perfil.'],
            ['key' => 'default_font_size', 'label' => 'Tamaño de letra por defecto', 'type' => 'select', 'options' => ['small' => 'Chico', 'medium' => 'Mediano', 'large' => 'Grande'], 'default' => 'medium'],
        ]],
        ['id' => 'login', 'title' => 'Pantalla de login', 'icon' => 'fa-right-to-bracket', 'fields' => [
            ['key' => 'login_title', 'label' => 'Título', 'type' => 'text', 'default' => '', 'help' => 'Vacío = nombre de la plataforma.'],
            ['key' => 'login_subtitle', 'label' => 'Subtítulo', 'type' => 'text', 'default' => 'Ingresa para continuar'],
            ['key' => 'login_logo_url', 'label' => 'Logo del login', 'type' => 'text', 'default' => '', 'help' => 'URL de imagen. Vacío = logo de la topbar.'],
            ['key' => 'login_message', 'label' => 'Mensaje bajo el formulario', 'type' => 'textarea', 'default' => '', 'help' => 'Ej: "¿Sin cuenta? Pídela a un admin".'],
        ]],
        ['id' => 'security', 'title' => 'Acceso y seguridad', 'icon' => 'fa-shield', 'fields' => [
            ['key' => 'maintenance', 'label' => 'Modo mantenimiento', 'type' => 'toggle', 'default' => '0', 'help' => 'Solo los admins pueden usar la plataforma. El resto ve el mensaje de abajo.'],
            ['key' => 'maintenance_message', 'label' => 'Mensaje de mantenimiento', 'type' => 'textarea', 'default' => 'Estamos haciendo mejoras. Vuelve en un rato.'],
            ['key' => 'session_hours', 'label' => 'Duración de la sesión (horas)', 'type' => 'number', 'min' => 1, 'max' => 720, 'default' => '8'],
            ['key' => 'password_min_length', 'label' => 'Largo mínimo de contraseña', 'type' => 'number', 'min' => 4, 'max' => 64, 'default' => '6'],
            ['key' => 'new_user_team', 'label' => 'Equipo para usuarios nuevos', 'type' => 'select', 'options' => $teams, 'default' => '', 'help' => 'Se asigna al crear un usuario desde el admin.'],
            ['key' => 'denied_message', 'label' => 'Mensaje de acceso denegado', 'type' => 'textarea', 'default' => 'No tienes acceso a esta sección. Si lo necesitas, pídeselo a un admin.'],
        ]],
        ['id' => 'brands', 'title' => 'Marcas', 'icon' => 'fa-tags', 'fields' => [
            ['key' => 'brands_enabled', 'label' => 'Separar contenido por marca', 'type' => 'toggle', 'default' => '0', 'help' => 'Apagado: todos ven todas las marcas. Encendido: cada usuario ve solo las marcas que tiene asignadas.'],
            ['key' => 'brand_no_assignment', 'label' => 'Usuarios sin marca asignada', 'type' => 'select', 'options' => ['all' => 'Ven todas las marcas', 'none' => 'No ven contenido de ninguna marca'], 'default' => 'all'],
            ['key' => 'brand_unassigned_visible', 'label' => 'Contenido sin marca visible para todos', 'type' => 'toggle', 'default' => '1', 'help' => 'Contenido cuyo proyecto no coincide con ninguna marca (ej: "Otros").'],
            ['key' => 'brand_switcher', 'label' => 'Selector de marca en la topbar', 'type' => 'toggle', 'default' => '1', 'help' => 'Quien tenga varias marcas puede elegir con cuál trabajar.'],
        ]],
        ['id' => 'announce', 'title' => 'Anuncio global', 'icon' => 'fa-bullhorn', 'fields' => [
            ['key' => 'announce_enabled', 'label' => 'Mostrar anuncio', 'type' => 'toggle', 'default' => '0', 'help' => 'Barra bajo la topbar en todas las páginas.'],
            ['key' => 'announce_text', 'label' => 'Texto', 'type' => 'textarea', 'default' => ''],
            ['key' => 'announce_level', 'label' => 'Estilo', 'type' => 'select', 'options' => ['info' => 'Información', 'success' => 'Éxito', 'warning' => 'Aviso', 'danger' => 'Urgente'], 'default' => 'info'],
            ['key' => 'announce_link', 'label' => 'Link (opcional)', 'type' => 'text', 'default' => ''],
            ['key' => 'announce_dismissible', 'label' => 'Se puede cerrar', 'type' => 'toggle', 'default' => '1', 'help' => 'Si lo editas, vuelve a aparecer para todos.'],
            ['key' => 'announce_audience', 'label' => 'Audiencia', 'type' => 'select', 'options' => ['all' => 'Todos'] + array_filter($teams, fn($k) => $k !== '', ARRAY_FILTER_USE_KEY), 'default' => 'all'],
        ]],
    ];
}

// ═══════════════════════════════════════════════════════════
// INSTALACIÓN / MIGRACIÓN (idempotente)
// ═══════════════════════════════════════════════════════════

function g7_access_install(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS g7_user_permissions (
        user_id INT NOT NULL,
        module VARCHAR(50) NOT NULL,
        action VARCHAR(50) NOT NULL,
        effect ENUM('allow','deny') NOT NULL,
        updated_by INT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, module, action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_brands (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(50) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,
        color VARCHAR(7) NOT NULL DEFAULT '#ff6600',
        logo_url VARCHAR(500) DEFAULT '',
        keywords TEXT,
        sort_order INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_brand_access (
        subject_type ENUM('team','user') NOT NULL,
        subject_id INT NOT NULL,
        brand_id INT NOT NULL,
        PRIMARY KEY (subject_type, subject_id, brand_id),
        KEY idx_brand (brand_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_admin_audit (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        action VARCHAR(60) NOT NULL,
        target VARCHAR(190) DEFAULT '',
        detail TEXT,
        ip VARCHAR(45) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_time (created_at),
        KEY idx_action (action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Marcas iniciales: las del paquete de marcas del sitio (marcas/marcas.php → 'brands_seed': [slug, nombre, color, [palabras clave]]).
    // Una instalación sin paquete arranca sin marcas. Solo se cargan con la tabla vacía.
    if (($seed = function_exists('g7_pack') ? (array)g7_pack('brands_seed', []) : []) && (int)$db->query("SELECT COUNT(*) FROM g7_brands")->fetchColumn() === 0) {
        $ins = $db->prepare("INSERT INTO g7_brands (slug, name, color, keywords, sort_order) VALUES (?,?,?,?,?)");
        foreach ($seed as $i => [$slug, $name, $color, $kw]) $ins->execute([$slug, $name, $color, json_encode($kw), $i]);
    }
    // La columna role solo admite admin/user; versiones viejas del admin guardaban 'member' y quedaba vacío.
    $db->exec("UPDATE g7_users SET role='user' WHERE role IS NULL OR role NOT IN ('admin','user')");

    // Superadmin: columna aparte; la primera vez queda marcada la cuenta admin principal
    try { $db->query("SELECT is_super FROM g7_users LIMIT 1"); }
    catch (Throwable $e) { $db->exec("ALTER TABLE g7_users ADD COLUMN is_super TINYINT(1) NOT NULL DEFAULT 0"); }
    if (!(int)$db->query("SELECT COUNT(*) FROM g7_users WHERE is_super = 1")->fetchColumn()) {
        $db->exec("UPDATE g7_users SET is_super = 1 WHERE username = 'admin' AND role = 'admin' AND active = 1");
    }

    $db->prepare("INSERT INTO g7_config (cfg_key, cfg_value) VALUES ('access_schema_version', ?) ON DUPLICATE KEY UPDATE cfg_value = VALUES(cfg_value)")
       ->execute([(string)G7_ACCESS_SCHEMA]);
    $s = &g7__cfg_store();
    $s['data']['access_schema_version'] = (string)G7_ACCESS_SCHEMA;
}

// ═══════════════════════════════════════════════════════════
// REGISTRO DE MÓDULOS
// paths: archivo exacto o carpeta (termina en /), relativos a /toolz/.
// actions: solo las que el código del módulo realmente controla.
// ═══════════════════════════════════════════════════════════

function g7_base_modules(): array {
    return [
        'dashboard'   => ['label' => 'Inicio',             'icon' => 'fa-house',            'group' => 'Plataforma',   'paths' => ['', 'index.php'], 'core' => true, 'brand_scoped' => true],
        'soporte'     => ['label' => 'Soporte',            'icon' => 'fa-life-ring',        'group' => 'Plataforma',   'paths' => ['soporte/'], 'core' => true],
        'perfil'      => ['label' => 'Mi perfil',          'icon' => 'fa-user-gear',        'group' => 'Plataforma',   'paths' => ['user_config.php'], 'core' => true],
        'promos'      => ['label' => 'Promos CMS',         'icon' => 'fa-layer-group',      'group' => 'Contenido',    'paths' => ['promos/'], 'actions' => ['view', 'create', 'edit', 'delete'], 'default_mode' => 'teams', 'brand_scoped' => true],
        'landings'    => ['label' => 'Landing Gen',        'icon' => 'fa-file-code',        'group' => 'Contenido',    'paths' => ['landing-gen/'], 'brand_scoped' => true],
        // Landing Gen 2.0: landings y sitios con bloques, publicados como estáticos (landing-gen-2/, doc LANDING_GEN_2.md). Solo Cronos completo
        'landings2'   => ['label' => 'Landing Gen 2.0',    'icon' => 'fa-wand-magic-sparkles', 'group' => 'Contenido', 'paths' => ['landing-gen-2/'], 'actions' => ['view', 'edit', 'publish', 'config'], 'default_mode' => 'admins'],
        'mailing'     => ['label' => 'Mailing Maker',      'icon' => 'fa-envelope',         'group' => 'Contenido',    'paths' => ['mailingmaker/'], 'brand_scoped' => true],
        'mailing2'    => ['label' => 'Mailing Maker 2.0',  'icon' => 'fa-envelope-open-text', 'group' => 'Contenido', 'paths' => ['mailing-2/'], 'actions' => ['view', 'edit', 'templates', 'config'], 'default_mode' => 'admins'],
        'popups'      => ['label' => 'Popup Gen',          'icon' => 'fa-window-restore',   'group' => 'Contenido',    'paths' => ['popup-gen/']],
        'pages'       => ['label' => 'Pages',              'icon' => 'fa-file-lines',       'group' => 'Contenido',    'paths' => ['pages.php']],
        'planify'     => ['label' => 'Planify',            'icon' => 'fa-calendar-days',    'group' => 'Contenido',    'paths' => ['planify.php']],
        'placas_diarias' => ['label' => 'Placas diarias',  'icon' => 'fa-calendar-day',     'group' => 'Diseño',       'paths' => ['placasdeportivas/diarias.php'], 'default_mode' => 'admins'],
        'placas_paid' => ['label' => 'Placas paid',        'icon' => 'fa-bullhorn',         'group' => 'Diseño',       'paths' => ['placasdeportivas/paid.php'], 'default_mode' => 'admins'],
        'promos_gen'  => ['label' => 'Generador de promos', 'icon' => 'fa-tags',            'group' => 'Diseño',       'paths' => ['placasdeportivas/promos.php', 'placasdeportivas/promos/'], 'default_mode' => 'admins'],
        'placas'      => ['label' => 'Placas deportivas',  'icon' => 'fa-futbol',           'group' => 'Diseño',       'paths' => ['placasdeportivas/', 'placaspaid/', 'sport_placas.php']],
        'stock'       => ['label' => 'Stock',              'icon' => 'fa-photo-film',       'group' => 'Diseño',       'paths' => ['stock.php']],
        'imghost'     => ['label' => 'Image Host',         'icon' => 'fa-cloud-arrow-up',   'group' => 'Diseño',       'paths' => ['imghost.php']],
        'globo'       => ['label' => 'Globo',              'icon' => 'fa-comment-dots',     'group' => 'Diseño',       'paths' => ['taller/globo.php', 'taller/globo/']],
        'etiqueta'    => ['label' => 'Etiqueta',           'icon' => 'fa-at',               'group' => 'Diseño',       'paths' => ['taller/etiqueta.php', 'taller/etiqueta/']],
        'calco'       => ['label' => 'Calco',              'icon' => 'fa-note-sticky',      'group' => 'Diseño',       'paths' => ['taller/calco.php', 'taller/calco/']],
        // Mockup Studio (mockups/, doc MOCKUP_STUDIO.md): diseños sobre productos con motor de composición. edit = proyectos; share = presentaciones con enlace; tickets = importar y adjuntar a tickets;
        // assets = importar de Materiales y guardar ahí; ai = fondos y escenas con IA; templates = crear, editar y apagar plantillas
        'mockups'     => ['label' => 'Mockup Studio',      'icon' => 'fa-layer-group',      'group' => 'Diseño',       'paths' => ['mockups/'], 'actions' => ['view', 'edit', 'share', 'tickets', 'assets', 'ai', 'templates'], 'grant_actions' => ['ai', 'templates']],
        'rotulo'      => ['label' => 'Rótulo',             'icon' => 'fa-heading',          'group' => 'Diseño',       'paths' => ['taller/rotulo.php', 'taller/rotulo/']],
        'resena'      => ['label' => 'Reseña',             'icon' => 'fa-star',             'group' => 'Diseño',       'paths' => ['taller/resena.php', 'taller/resena/']],
        'codigo'      => ['label' => 'Código QR',          'icon' => 'fa-qrcode',           'group' => 'Diseño',       'paths' => ['taller/codigo.php', 'taller/codigo/']],
        // Paletas: generador de paletas, paletas armadas y significado de cada color, con la interfaz de estudio (paletas/, doc PALETAS.md)
        'paletas'     => ['label' => 'Paletas',            'icon' => 'fa-palette',          'group' => 'Diseño',       'paths' => ['paletas/']],
        'webp'        => ['label' => 'WebP Converter',     'icon' => 'fa-image',            'group' => 'Herramientas', 'paths' => ['webpmaker.php', 'webp_converter.php']],
        'jpg'         => ['label' => 'WebP a JPG',         'icon' => 'fa-file-image',       'group' => 'Herramientas', 'paths' => ['jpg_converter.php']],
        'svg'         => ['label' => 'SVG → PNG',          'icon' => 'fa-vector-square',    'group' => 'Herramientas', 'paths' => ['svg_converter.php']],
        'recolorear'  => ['label' => 'Recolorear SVG',     'icon' => 'fa-fill-drip',        'group' => 'Herramientas', 'paths' => ['recolorearsvg.php']],
        'renombrador' => ['label' => 'Renombrador',        'icon' => 'fa-i-cursor',         'group' => 'Herramientas', 'paths' => ['renombrador.php']],
        'claude'      => ['label' => 'Claude',             'icon' => 'fa-robot',            'group' => 'Herramientas', 'paths' => ['claude/']],
        'linkgen'     => ['label' => 'Links Generator',    'icon' => 'fa-link',             'group' => 'Herramientas', 'paths' => ['linkgen/', 'linkgenerator.php']],
        'links'       => ['label' => 'Biblioteca',         'icon' => 'fa-book-bookmark',    'group' => 'Comunidad',    'paths' => ['links/'], 'actions' => ['view', 'notes', 'catalog'], 'grant_actions' => ['notes', 'catalog']],   // catalog: Catálogo Cronos (CATALOGO.md)
        'comunidad'   => ['label' => 'Comunidad',          'icon' => 'fa-people-group',     'group' => 'Comunidad',    'paths' => ['comunidad.php']],
        'chat'        => ['label' => 'Chat',               'icon' => 'fa-comment-dots',     'group' => 'Comunidad',    'paths' => ['chat/', 'chat_page.php', 'chat.php', 'chat_embed.php']],
        'encuesta'    => ['label' => 'Encuesta',           'icon' => 'fa-square-poll-vertical', 'group' => 'Comunidad', 'paths' => ['encuesta/']],
        // Bienestar: ánimo, hábitos, retos, clima, reconocimientos, carga y ausencias (bienestar/, doc BIENESTAR.md).
        // manage = gestionar retos, encuestas, programas y aprobar ausencias de los equipos de la persona (los admins de equipo ya lo tienen)
        'bienestar'   => ['label' => 'Bienestar',          'icon' => 'fa-spa',              'group' => 'Comunidad',    'paths' => ['bienestar/'], 'actions' => ['view', 'manage'], 'grant_actions' => ['manage']],
        'stats'       => ['label' => 'Stats',              'icon' => 'fa-chart-line',       'group' => 'Gestión',      'paths' => ['stats.php', 'stats_minimal.php', 'stats_reporte.php', 'stats_produccion.php'], 'brand_scoped' => true],
        'status'      => ['label' => 'Status',             'icon' => 'fa-triangle-exclamation', 'group' => 'Gestión',  'paths' => ['status.php']],
        'todos'       => ['label' => 'To-Do',              'icon' => 'fa-list-check',       'group' => 'Gestión',      'paths' => ['todos.php']],
        'calendario'  => ['label' => 'Mi calendario',      'icon' => 'fa-calendar-days',    'group' => 'Gestión',      'paths' => ['calendario/']],
        // Social Studio: calendario editorial por marca o cliente y "Convertir en ticket" (social/, doc SOCIAL_STUDIO.md). edit = crear y editar;
        // approve/schedule/publish = llevar a Aprobado/Programado/Publicado y publicar en las redes; tickets = convertir en ticket; assign = asignar a otros;
        // inbox = ver las conversaciones; reply = responder en la red; analytics = métricas; reports = informes para clientes; accounts = conectar, renovar y desconectar cuentas; config = estados, reglas y agrupación
        'social'      => ['label' => 'Social Studio',      'icon' => 'fa-share-nodes',      'group' => 'Gestión',      'paths' => ['social/'], 'actions' => ['view', 'edit', 'approve', 'schedule', 'publish', 'tickets', 'assign', 'inbox', 'reply', 'analytics', 'reports', 'accounts', 'config'], 'grant_actions' => ['approve', 'publish', 'accounts', 'config'], 'default_mode' => 'admins'],
        // Blog Studio: CMS editorial multiblog (blog/, doc BLOG_STUDIO.md). view = entrar y trabajar en los blogs donde la persona tiene rol;
        // create = crear blogs nuevos. Lo de adentro (redactar, revisar, publicar, SEO, diseño…) lo decide el rol de cada blog. blog/ver.php y blog/t.php son públicos.
        'blog'        => ['label' => 'Blog Studio',        'icon' => 'fa-feather-pointed',  'group' => 'Gestión',      'paths' => ['blog/'], 'actions' => ['view', 'create'], 'grant_actions' => ['create'], 'default_mode' => 'admins'],
        // Your Web: portal que junta Landing Gen 2.0, Mailing Maker 2.0, Blog Studio y la Tienda, con dominios propios y visitas sumadas (yourweb/, doc YOUR_WEB.md).
        // view = entrar y ver las webs y estadísticas; manage = crear webs, cambiar dominios y verificarlos. yourweb/sitio.php es público (lo usan los dominios).
        'yourweb'     => ['label' => 'Your Web',           'icon' => 'fa-globe',            'group' => 'Gestión',      'paths' => ['yourweb/'], 'actions' => ['view', 'manage'], 'grant_actions' => ['manage'], 'default_mode' => 'admins'],
        'tareas'      => ['label' => 'Gestión de trabajo','icon' => 'fa-table-columns',    'group' => 'Gestión',      'paths' => ['trabajo/', 'tareas.php', 'tareas_api.php', 'tareas_files.php']],
        // Portal de Solicitudes aparte de Trabajo: quien no usa Gestión de trabajo igual pide y sigue sus pedidos (solicitudes/, SOLICITUDES.md)
        'solicitudes' => ['label' => 'Solicitudes',        'icon' => 'fa-inbox',            'group' => 'Gestión',      'paths' => ['solicitudes/']],
        'resto'       => ['label' => 'Restaurant',         'icon' => 'fa-utensils',         'group' => 'Gestión',      'paths' => ['resto/admin/'], 'actions' => ['view', 'manage', 'config'], 'default_mode' => 'admins'],   // Cronos Restaurant (toolz/RESTAURANT.md); permisos por sucursal dentro del módulo
        'eventos'     => ['label' => 'Eventos',            'icon' => 'fa-ticket',           'group' => 'Gestión',      'paths' => ['eventos/admin/', 'eventos/acceso/'], 'actions' => ['view', 'manage', 'config'], 'default_mode' => 'admins'],   // Cronos Tickets (toolz/EVENTOS.md); permisos por organizador dentro del módulo
        'tienda'      => ['label' => 'Tienda',             'icon' => 'fa-shop',             'group' => 'Gestión',      'paths' => ['tienda/admin/'], 'actions' => ['view', 'edit', 'config', 'design', 'publish'], 'default_mode' => 'admins'],
        'monday'      => ['label' => 'Monday',             'icon' => 'fa-check-square',     'group' => 'Gestión',      'paths' => ['monday.php']],
    ];
}

/** Módulos efectivos: base + personalizados, con nombre/ícono sobrescritos por el admin. */
function g7_modules(): array {
    static $mods = null;
    if ($mods !== null) return $mods;
    $mods = [];
    foreach (g7_site_modules(g7_base_modules()) as $id => $m) {
        $m['id'] = $id; $m['custom'] = false;
        $m['actions'] = $m['actions'] ?? ['view'];
        $mods[$id] = $m;
    }
    foreach (g7_cfg_json('modules_custom', []) as $c) {
        $id = preg_replace('/[^a-z0-9_]/', '', strtolower($c['id'] ?? ''));
        if (!$id || isset($mods[$id])) continue;
        $paths = array_values(array_filter(array_map(fn($p) => ltrim(trim($p), '/'), (array)($c['paths'] ?? [])), 'strlen'));
        if (!$paths) continue;
        $mods[$id] = ['id' => $id, 'label' => $c['label'] ?? $id, 'icon' => $c['icon'] ?? 'fa-puzzle-piece', 'group' => $c['group'] ?? 'Personalizados',
                      'paths' => $paths, 'actions' => ['view'], 'custom' => true, 'url' => $c['url'] ?? (G7_BASE . $paths[0])];
    }
    $pol = g7_cfg_json('module_policies', []);
    foreach ($mods as $id => &$m) {
        $p = $pol[$id] ?? [];
        if (!empty($p['label'])) $m['label'] = $p['label'];
        // Rubro: si la agencia o su rubro renombraron Clientes o Contenidos, el menú usa ese nombre (sin rubro, como siempre)
        elseif (isset(['clientes' => 1, 'promos' => 1][$id]) && function_exists('g7_term') && isset(g7_cfg_json('vocab', [])[$id === 'clientes' ? 'client' : 'content'])) $m['label'] = g7_term($id === 'clientes' ? 'client' : 'content', 'p');
        if (!empty($p['icon']))  $m['icon']  = $p['icon'];
        $m['description'] = $p['description'] ?? '';
        $mode = $p['mode'] ?? ($m['default_mode'] ?? 'everyone');
        $m['mode'] = !empty($m['core']) ? 'everyone' : (in_array($mode, G7_MODES, true) ? $mode : 'everyone');
        if (in_array($id, (array)g7_site('modulos_off'), true)) { $m['mode'] = 'off'; $m['site_off'] = true; }   // este sitio no lo usa: sigue registrado para que sus páginas queden bloqueadas, también para los admins
        $m['url'] = $m['url'] ?? (G7_BASE . $m['paths'][0]);
    }
    unset($m);
    return $mods;
}

function g7_modules_reset(): void { /* para tests: no hay caché persistente fuera del request */ }

/** Ruta relativa a /toolz/ del script actual. */
function g7_current_rel(): string {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    return strpos($script, G7_BASE) === 0 ? substr($script, strlen(G7_BASE)) : ltrim($script, '/');
}

/** Módulo al que pertenece una ruta relativa (match más largo gana). */
function g7_module_for_path(string $rel): ?string {
    $rel = ltrim(preg_replace('/[?#].*$/', '', $rel), '/');
    $best = null; $bestLen = -1;
    foreach (g7_modules() as $id => $m) {
        foreach ($m['paths'] as $p) {
            $hit = ($p === '') ? ($rel === '') : (substr($p, -1) === '/' ? (strpos($rel . '/', $p) === 0) : ($rel === $p));
            if ($hit && strlen($p) > $bestLen) { $best = $id; $bestLen = strlen($p); }
        }
    }
    return $best;
}

/** Módulo de un href del nav ("/toolz/promos/", "stock.php", "https://..."). */
function g7_module_for_href(string $href): ?string {
    if (preg_match('#^[a-z]+://#i', $href)) {
        $u = parse_url($href);
        if (($u['host'] ?? '') !== ($_SERVER['HTTP_HOST'] ?? '')) return null;
        $href = $u['path'] ?? '/';
    }
    $href = preg_replace('/[?#].*$/', '', $href);
    if (strpos($href, G7_BASE) === 0) $href = substr($href, strlen(G7_BASE));
    elseif (strpos($href, '/') === 0) return null;
    return g7_module_for_path($href);
}

// ═══════════════════════════════════════════════════════════
// CONTEXTO DE USUARIO (equipos, permisos, marcas) — con precarga masiva para la matriz
// ═══════════════════════════════════════════════════════════

function &g7__ctx_store(): array { static $s = []; return $s; }

function g7_ctx_preload_all(): void {
    $s = &g7__ctx_store();
    $db = getDB();
    $users = $db->query("SELECT id, role, active FROM g7_users")->fetchAll();
    $teamsOf = []; $grantsOfTeam = []; $ovr = []; $bUser = []; $bTeam = [];
    foreach ($db->query("SELECT tm.user_id, tm.team_id FROM g7_team_members tm JOIN g7_teams t ON t.id = tm.team_id AND t.active = 1") as $r) $teamsOf[$r['user_id']][] = (int)$r['team_id'];
    foreach ($db->query("SELECT team_id, module, action FROM g7_team_permissions") as $r) $grantsOfTeam[$r['team_id']][$r['module'] . '.' . $r['action']] = true;
    try { foreach ($db->query("SELECT user_id, module, action, effect FROM g7_user_permissions") as $r) $ovr[$r['user_id']][$r['module'] . '.' . $r['action']] = $r['effect']; } catch (Throwable $e) {}
    try {
        foreach ($db->query("SELECT subject_type, subject_id, brand_id FROM g7_brand_access") as $r) {
            if ($r['subject_type'] === 'user') $bUser[$r['subject_id']][] = (int)$r['brand_id'];
            else $bTeam[$r['subject_id']][] = (int)$r['brand_id'];
        }
    } catch (Throwable $e) {}
    foreach ($users as $u) {
        $uid = (int)$u['id']; $teams = $teamsOf[$uid] ?? [];
        $grants = []; $bt = [];
        foreach ($teams as $t) { $grants += $grantsOfTeam[$t] ?? []; foreach ($bTeam[$t] ?? [] as $b) $bt[$b] = $t; }
        $s[$uid] = ['id' => $uid, 'role' => $u['role'], 'active' => (int)$u['active'], 'teams' => $teams, 'grants' => $grants,
                    'overrides' => $ovr[$uid] ?? [], 'brands_direct' => array_values(array_unique($bUser[$uid] ?? [])), 'brands_team' => $bt];
    }
}

function g7_ctx(int $uid): array {
    $s = &g7__ctx_store();
    if (isset($s[$uid])) return $s[$uid];
    $db = getDB();
    $ctx = ['id' => $uid, 'role' => 'user', 'active' => 0, 'teams' => [], 'grants' => [], 'overrides' => [], 'brands_direct' => [], 'brands_team' => []];
    try {
        $q = $db->prepare("SELECT role, active FROM g7_users WHERE id=?"); $q->execute([$uid]);
        if ($u = $q->fetch()) { $ctx['role'] = $u['role']; $ctx['active'] = (int)$u['active']; }
        $q = $db->prepare("SELECT tm.team_id FROM g7_team_members tm JOIN g7_teams t ON t.id = tm.team_id AND t.active = 1 WHERE tm.user_id=?"); $q->execute([$uid]);
        $ctx['teams'] = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        if ($ctx['teams']) {
            $in = implode(',', $ctx['teams']);
            foreach ($db->query("SELECT module, action FROM g7_team_permissions WHERE team_id IN ($in)") as $r) $ctx['grants'][$r['module'] . '.' . $r['action']] = true;
            try { foreach ($db->query("SELECT subject_id, brand_id FROM g7_brand_access WHERE subject_type='team' AND subject_id IN ($in)") as $r) $ctx['brands_team'][(int)$r['brand_id']] = (int)$r['subject_id']; } catch (Throwable $e) {}
        }
        try {
            $q = $db->prepare("SELECT module, action, effect FROM g7_user_permissions WHERE user_id=?"); $q->execute([$uid]);
            foreach ($q as $r) $ctx['overrides'][$r['module'] . '.' . $r['action']] = $r['effect'];
            $q = $db->prepare("SELECT brand_id FROM g7_brand_access WHERE subject_type='user' AND subject_id=?"); $q->execute([$uid]);
            $ctx['brands_direct'] = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}
    return $s[$uid] = $ctx;
}

function g7_ctx_forget(?int $uid = null): void {
    $s = &g7__ctx_store();
    if ($uid === null) $s = []; else unset($s[$uid]);
}

// ═══════════════════════════════════════════════════════════
// RESOLUCIÓN DE PERMISOS
// Orden: admin global > módulo apagado/solo admins > denegación personal > permiso personal
//        > módulo abierto a todos > permiso de alguno de sus equipos > denegado.
// ═══════════════════════════════════════════════════════════

/** @return array{0:bool,1:string,2:string} [permitido, código, explicación] */
function g7_explain(int $uid, string $module, string $action = 'view'): array {
    $mods = g7_modules();
    if (!isset($mods[$module])) return [true, 'unknown', 'Página sin módulo asignado'];
    $m = $mods[$module];
    $ctx = g7_ctx($uid);
    if (!empty($m['site_off'])) return [false, 'off', 'Este sitio no usa este módulo'];
    // Apagado vale para todos, admins incluidos (como en los menús); se prende en Admin › Módulos y permisos
    if ($m['mode'] === 'off' && empty($m['core'])) return [false, 'off', 'Módulo apagado'];
    // Solo superadmin: va antes que nada, porque también deja afuera a los admins comunes
    if ($m['mode'] === 'superadmins') return $ctx['role'] === 'admin' && g7_super_ok($uid) ? [true, 'super', 'Superadmin'] : [false, 'superadmins', 'Solo superadmin'];
    if ($ctx['role'] === 'admin') return [true, 'admin', 'Admin global'];
    if (!empty($m['core'])) return [true, 'core', 'Sección básica, siempre disponible'];
    if ($m['mode'] === 'off') return [false, 'off', 'Módulo apagado'];
    if ($m['mode'] === 'admins') return [false, 'admins', 'Solo admins'];

    if ($action !== 'view') {
        [$v, $vc, $vr] = g7_explain($uid, $module, 'view');
        if (!$v) return [false, $vc, $vr];
    }
    $o = $ctx['overrides'][$module . '.' . $action] ?? null;
    if ($action !== 'view' && $o === null && ($ctx['overrides'][$module . '.view'] ?? null) === 'deny') $o = 'deny';
    if ($o === 'deny')  return [false, 'deny_user', 'Denegado para este usuario'];
    if ($o === 'allow') return [true, 'allow_user', 'Permitido para este usuario'];
    // Acciones sensibles (ej. ver las notas con claves de la Biblioteca): aunque el módulo esté abierto a todos,
    // solo las tienen los equipos o personas a quienes se les dio el permiso.
    if (in_array($action, $m['grant_actions'] ?? [], true)) {
        if (!empty($ctx['grants'][$module . '.' . $action])) return [true, 'team', 'Por su equipo'];
        return [false, 'none', 'Permiso aparte: se da por equipo o por persona'];
    }
    // "Abierto a todos" da el trabajo diario (ver y editar). Configurar el módulo o cualquier otra acción
    // se da aparte, por equipo o por persona (o la tiene el admin).
    if ($m['mode'] === 'everyone' && in_array($action, ['view', 'edit'], true)) return [true, 'everyone', 'Abierto a todos'];
    if (!empty($ctx['grants'][$module . '.' . $action])) return [true, 'team', 'Por su equipo'];
    return [false, 'none', $ctx['teams'] ? 'Su equipo no tiene este permiso' : 'No pertenece a ningún equipo con permiso'];
}

function g7_can(int $uid, string $module, string $action = 'view'): bool { return g7_explain($uid, $module, $action)[0]; }

function g7_can_href(int $uid, string $href): bool {
    $m = g7_module_for_href($href);
    return $m === null || g7_can($uid, $m);
}

// ═══════════════════════════════════════════════════════════
// BLOQUEOS: mantenimiento y acceso denegado
// ═══════════════════════════════════════════════════════════

function g7_wants_json(): bool {
    $a = $_SERVER['HTTP_ACCEPT'] ?? '';
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || stripos($a, 'application/json') !== false
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

function g7_block_page(int $code, string $icon, string $title, string $msg): void {
    http_response_code($code);
    if (g7_wants_json()) { header('Content-Type: application/json'); echo json_encode(['error' => $msg, 'code' => $code]); exit; }
    $name = htmlspecialchars(g7_setting('platform_name'));
    $accent = htmlspecialchars(g7_setting('accent_color') ?: '#ff6600');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . htmlspecialchars($title) . ' · ' . $name . '</title>'
       . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@400;600;700&display=swap">'
       . '<style>*{box-sizing:border-box;margin:0;padding:0}body{background:#0d0d0d;color:#e4e4e7;font-family:Geist,Inter,system-ui,sans-serif;min-height:100dvh;-webkit-font-smoothing:antialiased;display:flex;align-items:center;justify-content:center;padding:16px}'
       . '.c{background:#111;border:1px solid #27272a;border-radius:16px;padding:36px 32px;max-width:420px;width:100%;text-align:center}'
       . '.i{width:56px;height:56px;border-radius:14px;background:' . $accent . '1f;color:' . $accent . ';display:flex;align-items:center;justify-content:center;font-size:22px;margin:0 auto 16px}'
       . 'h1{font-size:18px;margin-bottom:8px}p{font-size:13px;color:#a1a1aa;line-height:1.6;white-space:pre-line}'
       . '.b{display:flex;gap:8px;justify-content:center;margin-top:22px}a{font-size:12px;font-weight:700;padding:9px 16px;border-radius:8px;text-decoration:none}'
       . '.p{background:' . $accent . ';color:#000}.g{background:#18181b;color:#a1a1aa;border:1px solid #27272a}</style></head><body>'
       . '<div class="c"><div class="i"><i class="fa-solid ' . $icon . '"></i></div><h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($msg) . '</p>'
       . '<div class="b"><a class="p" href="' . G7_BASE . '">Ir al inicio</a><a class="g" href="' . G7_BASE . 'logout.php">Cerrar sesión</a></div></div></body></html>';
    exit;
}

/** Se llama en cada página protegida (requireLogin y herramientas envueltas). */
function g7_enforce_access(array $user): void {
    static $checked = [];
    $uid = (int)$user['id'];
    if (isset($checked[$uid])) return;
    $checked[$uid] = true;
    $rel = g7_current_rel();
    if ($rel === 'logout.php') return;
    $ctx = g7_ctx($uid);
    if (!$ctx['active']) { logout(); }
    if ($ctx['role'] !== 'admin' && g7_setting_on('maintenance')) {
        g7_block_page(503, 'fa-screwdriver-wrench', 'En mantenimiento', g7_setting('maintenance_message'));
    }
    $mod = g7_module_for_path($rel);
    $shared = ['placasdeportivas/diarias/api.php' => ['placas_diarias', 'placas_paid', 'promos_gen'], 'placasdeportivas/sp/api.php' => ['placas', 'placas_diarias', 'placas_paid', 'promos_gen']][$rel] ?? null;
    if ($shared) {
        foreach ($shared as $sm) if (g7_can($uid, $sm, 'view')) return;
        $mod = $shared[0];   // nadie la puede usar: se bloquea aunque la ruta caiga dentro de otro módulo
    }
    if ($mod && !g7_can($uid, $mod, 'view')) {
        $m = g7_modules()[$mod];
        g7_block_page(403, 'fa-lock', 'Sin acceso a ' . $m['label'], g7_setting('denied_message'));
    }
}

// ═══════════════════════════════════════════════════════════
// MARCAS
// ═══════════════════════════════════════════════════════════

function g7_norm(string $s): string { return preg_replace('/[^a-z0-9]/', '', mb_strtolower($s, 'UTF-8')); }

function g7_brands(bool $includeInactive = false): array {
    static $all = null;
    if ($all === null) {
        $all = [];
        try {
            foreach (getDB()->query("SELECT * FROM g7_brands ORDER BY sort_order, name") as $b) {
                $b['id'] = (int)$b['id']; $b['active'] = (int)$b['active'];
                $kw = json_decode($b['keywords'] ?? '[]', true);
                $b['keywords'] = array_values(array_unique(array_filter(array_map('g7_norm', is_array($kw) ? $kw : []), 'strlen')));
                $all[$b['id']] = $b;
            }
        } catch (Throwable $e) {}
    }
    return $includeInactive ? $all : array_filter($all, fn($b) => $b['active']);
}

/** Marcas que detecta un texto (proyecto, plataforma...). */
function g7_brands_for_text(?string $text): array {
    $n = g7_norm((string)$text); $out = [];
    if ($n === '') return $out;
    foreach (g7_brands() as $b) foreach ($b['keywords'] as $k) if (strpos($n, $k) !== false) { $out[] = $b['id']; break; }
    return $out;
}

function g7_brands_enabled(): bool { return g7_setting_on('brands_enabled'); }

/** Marcas permitidas para el usuario. null = sin restricción (ve todo). */
function g7_user_brand_ids(int $uid): ?array {
    if (!g7_brands_enabled()) return null;
    $ctx = g7_ctx($uid);
    if ($ctx['role'] === 'admin') return null;
    $ids = array_values(array_intersect(array_unique(array_merge($ctx['brands_direct'], array_keys($ctx['brands_team']))), array_keys(g7_brands())));
    if (!$ids) return g7_setting('brand_no_assignment') === 'none' ? [] : null;
    return $ids;
}

/** Marcas que se filtran en esta petición: permitidas ∩ marca elegida en el selector. null = no filtrar. */
function g7_brand_scope(int $uid): ?array {
    $allowed = g7_user_brand_ids($uid);
    if (!g7_brands_enabled()) return null;
    if (session_status() === PHP_SESSION_NONE) startSecureSession();
    $sel = (int)($_SESSION['g7_brand_sel'] ?? 0);
    if ($sel && isset(g7_brands()[$sel]) && ($allowed === null || in_array($sel, $allowed, true))) return [$sel];
    return $allowed;
}

/** Condición SQL que deja pasar solo filas de las marcas indicadas. $expr = columna o expresión SQL con el texto de marca. */
function g7_brand_sql(string $expr, ?array $brandIds): string {
    if ($brandIds === null) return '1=1';
    $db = getDB();
    $norm = "LOWER(REGEXP_REPLACE(IFNULL($expr,''),'[^A-Za-z0-9]',''))";
    $match = function (array $kws) use ($norm, $db) {
        if (!$kws) return '0';
        return '(' . implode(' OR ', array_map(fn($k) => "$norm LIKE " . $db->quote('%' . $k . '%'), $kws)) . ')';
    };
    $parts = [];
    foreach (g7_brands() as $b) if (in_array($b['id'], $brandIds, true)) $parts[] = $match($b['keywords']);
    if (g7_setting_on('brand_unassigned_visible')) {
        $all = [];
        foreach (g7_brands() as $b) $all = array_merge($all, $b['keywords']);
        $parts[] = 'NOT ' . $match($all);
    }
    return $parts ? '(' . implode(' OR ', $parts) . ')' : '0';
}

/** Tablas con marca y la expresión que la contiene. */
function g7_brand_tables(): array {
    return [
        'g7_content'         => "CONCAT_WS(' ', project, projects)",
        'g7_landings'        => 'platform',
        'g7_mailing_exports' => 'platform',
        'g7_mailing_history' => 'platform',
    ];
}

/**
 * SOLO PÁGINAS DE LECTURA. Crea tablas temporales con el mismo nombre que las reales,
 * ya filtradas por marca, que las tapan durante esta conexión. Así todas las consultas
 * de la página quedan filtradas sin reescribirlas. Nunca usar en páginas que escriben
 * en estas tablas: lo escrito iría a la copia temporal y se perdería.
 */
function g7_brand_scope_readonly(int $uid): ?array {
    static $done = false;
    $scope = g7_brand_scope($uid);
    if ($scope === null || $done) return $scope;
    $done = true;
    $db = getDB();
    // En un solo paso: el SELECT se resuelve contra la tabla real antes de que exista la temporal.
    // (CREATE ... LIKE + INSERT ... SELECT leería la temporal vacía.)
    foreach (g7_brand_tables() as $t => $expr) {
        try {
            $db->exec("CREATE TEMPORARY TABLE `$t` (PRIMARY KEY (id)) AS SELECT * FROM `$t` WHERE " . g7_brand_sql($expr, $scope));
        } catch (Throwable $e) {}
    }
    try {
        $land = g7_brand_sql('platform', $scope);
        $db->exec("CREATE TEMPORARY TABLE g7_landing_views (PRIMARY KEY (id), KEY idx_landing (landing_id), KEY idx_created (created_at)) AS
                   SELECT v.* FROM g7_landing_views v WHERE v.landing_id IN (SELECT l.id FROM g7_landings l WHERE $land)");
    } catch (Throwable $e) {}
    return $scope;
}

/** ¿El usuario puede ver una fila con este texto de marca? */
function g7_brand_allows_text(int $uid, ?string $text): bool {
    $scope = g7_user_brand_ids($uid);
    if ($scope === null) return true;
    $hits = g7_brands_for_text($text);
    if (!$hits) return g7_setting_on('brand_unassigned_visible');
    return (bool)array_intersect($hits, $scope);
}

// ═══════════════════════════════════════════════════════════
// AUDITORÍA
// ═══════════════════════════════════════════════════════════

// ── Superadmin: un admin con la marca is_super. El rol sigue siendo 'admin', así no cambian los controles de rol del resto del sitio.
// Si no hay ninguno cargado (o falta la columna) todo funciona como antes: nadie queda afuera.
function g7_super_ids(): array {
    static $ids = null;
    if ($ids === null) {
        try { $ids = array_map('intval', getDB()->query("SELECT id FROM g7_users WHERE is_super = 1 AND role = 'admin' AND active = 1")->fetchAll(PDO::FETCH_COLUMN)); }
        catch (Throwable $e) { $ids = []; }
    }
    return $ids;
}
function g7_is_super(int $uid): bool { return in_array($uid, g7_super_ids(), true); }
/** Puede usar lo exclusivo del superadmin. */
function g7_super_ok(int $uid): bool { $ids = g7_super_ids(); return !$ids || in_array($uid, $ids, true); }
/** Motivo por el que $me no puede crear ($target = 0) o modificar a $target; vacío si puede. $newRole: el rol que quedaría, si cambia. */
function g7_super_denies(int $me, int $target, ?string $newRole = null): string {
    if (g7_super_ok($me)) return '';
    if (!$target) return $newRole === 'admin' ? 'Solo el superadmin puede crear admins.' : '';
    $q = getDB()->prepare("SELECT role FROM g7_users WHERE id = ?"); $q->execute([$target]);
    $role = (string)$q->fetchColumn();
    if ($role === 'admin' && $target !== $me) return 'Solo el superadmin puede modificar a otros admins.';
    if ($role !== 'admin' && $newRole === 'admin') return 'Solo el superadmin puede nombrar admins.';
    return '';
}

function g7_audit(string $action, string $target = '', $detail = null, ?int $userId = null): void {
    try {
        if ($userId === null) { $u = getCurrentUser(); $userId = $u ? (int)$u['id'] : null; }
        getDB()->prepare("INSERT INTO g7_admin_audit (user_id, action, target, detail, ip) VALUES (?,?,?,?,?)")
               ->execute([$userId, $action, mb_substr($target, 0, 190), $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Throwable $e) {}
}

/**
 * Script para el navegador que oculta opciones de proyecto/plataforma de marcas que el usuario no ve
 * (selects, checkboxes "projects[]", botones setProject(...)). Es comodidad visual: el servidor
 * igual rechaza lo que no corresponde. Devuelve '' si no hay nada que filtrar.
 */
function g7_brand_js(int $uid): string {
    if (!g7_brands_enabled()) return '';
    $scope = g7_brand_scope($uid);
    if ($scope === null) return '';
    $data = [
        'scope' => array_values($scope),
        'unassigned' => g7_setting_on('brand_unassigned_visible'),
        'brands' => array_values(array_map(fn($b) => ['id' => $b['id'], 'name' => $b['name'], 'k' => $b['keywords']], g7_brands())),
    ];
    return '<script>window.G7_BRANDS=' . json_encode($data, JSON_UNESCAPED_UNICODE) . ';(function(){'
        . 'var B=window.G7_BRANDS;function n(s){return String(s||"").toLowerCase().replace(/[^a-z0-9]/g,"")}'
        . 'window.g7BrandAllows=function(t){var x=n(t);if(!x)return true;var h=B.brands.filter(function(b){return b.k.some(function(k){return x.indexOf(k)>=0})}).map(function(b){return b.id});'
        . 'if(!h.length)return B.unassigned;return h.some(function(id){return B.scope.indexOf(id)>=0})};'
        . 'var RX=/project|proyecto|platform|plataforma|brand|marca/i;'
        . 'function prune(root){root=root||document;'
        . 'root.querySelectorAll("select").forEach(function(s){if(!RX.test((s.id||"")+" "+(s.name||"")+" "+(s.className||"")))return;'
        . 'Array.prototype.slice.call(s.options).forEach(function(o){var v=o.value||o.text;if(v&&!g7BrandAllows(v+" "+o.text)){if(o.selected){o.selected=false}o.remove()}})});'
        . 'root.querySelectorAll("input[type=checkbox],input[type=radio]").forEach(function(i){if(!RX.test(i.name||"")||!i.value)return;if(!g7BrandAllows(i.value)){i.checked=false;var l=i.closest("label")||i;l.style.display="none";i.disabled=true}});'
        . 'var FX=/(setProject|selectPlat|setPlat|selectPlatform|setPlatform)\(\s*(?:this\s*,\s*)?[\'"]([^\'"]+)/;'
        . 'root.querySelectorAll("[onclick]").forEach(function(b){var m=FX.exec(b.getAttribute("onclick")||"");if(!m||g7BrandAllows(m[2]))return;b.style.display="none";'
        . 'if(b.classList.contains("active")){b.classList.remove("active");var sib=Array.prototype.slice.call(b.parentNode.children).find(function(x){var mm=FX.exec(x.getAttribute("onclick")||"");return mm&&mm[1]===m[1]&&x.style.display!=="none"&&g7BrandAllows(mm[2])});if(sib)setTimeout(function(){sib.click()},0)}});'
        . 'root.querySelectorAll("[data-brand-text]").forEach(function(e){if(!g7BrandAllows(e.getAttribute("data-brand-text")))e.style.display="none"})}'
        . 'window.g7BrandPrune=prune;'
        . 'function go(){prune();var t;new MutationObserver(function(){clearTimeout(t);t=setTimeout(prune,60)}).observe(document.body,{childList:true,subtree:true})}'
        . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",go);else go();'
        . '})();</script>';
}

/** Como g7_brand_allows_text pero respetando la marca elegida en el selector (para listados y opciones). */
function g7_brand_in_scope(int $uid, ?string $text): bool {
    $scope = g7_brand_scope($uid);
    if ($scope === null) return true;
    $hits = g7_brands_for_text($text);
    if (!$hits) return g7_setting_on('brand_unassigned_visible');
    return (bool)array_intersect($hits, $scope);
}

// ═══════════════════════════════════════════════════════════
// CATÁLOGO DE HERRAMIENTAS (Inicio, buscador de la topbar)
// ═══════════════════════════════════════════════════════════

/**
 * Agrega un link al menú de arriba (guardado en la base) una sola vez: si después el admin lo saca, no vuelve.
 * $flag marca en g7_config que ya se agregó; va después de $afterHref, o al final si no está.
 */
function g7_nav_add_once(string $flag, array $item, string $afterHref = ''): void {
    if (g7_cfg($flag) === '1') return;
    $nav = g7_cfg_json('nav_links', null);
    if (is_array($nav) && !in_array($item['href'], array_column($nav, 'href'), true)) {
        $pos = $afterHref === '' ? false : array_search($afterHref, array_column($nav, 'href'), true);
        array_splice($nav, $pos === false ? count($nav) : $pos + 1, 0, [$item]);
        getDB()->prepare("UPDATE g7_config SET cfg_value = ? WHERE cfg_key = 'nav_links'")->execute([json_encode($nav, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $s = &g7__cfg_store(); $s['data']['nav_links'] = json_encode($nav, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    g7_cfg_set($flag, '1');
}

/** ¿Se muestra este link en menús? Oculta lo que el usuario no puede abrir y los módulos apagados (también a admins). */
function g7_href_visible(int $uid, string $href): bool {
    $m = g7_module_for_href($href);
    if ($m === null) return true;
    if ((g7_modules()[$m]['mode'] ?? '') === 'off') return false;
    return g7_can($uid, $m);
}

/** Herramientas que el usuario puede abrir, en el orden y con los textos del Inicio. */
function g7_tool_catalog(int $uid): array {
    $desc = [
        'promos' => 'Promos, lanzamientos y piezas', 'landings' => 'Landings de términos con IA', 'landings2' => 'Landings, promociones y sitios con bloques', 'mailing' => 'Mailings y comunicaciones', 'paletas' => 'Generador de paletas de color, paletas armadas y qué significa cada color', 'mailing2' => 'Editor visual de emails: componentes, responsive, plantillas y HTML para Outlook y Gmail',
        'popups' => 'Popups para los sitios', 'pages' => 'Páginas publicadas', 'planify' => 'Calendario de publicaciones',
        'placas' => 'Placas de partidos y resultados', 'placas_diarias' => 'Placas de redes para cada día', 'placas_paid' => 'Placas para pauta, por campaña', 'promos_gen' => 'Todas las piezas de una promo a partir de sus datos', 'stock' => 'Banco de imágenes y videos', 'imghost' => 'Sube una imagen y obtén el link',
        'webp' => 'Convierte imágenes a WebP', 'svg' => 'Exporta SVG a PNG', 'recolorear' => 'Cambia colores de un SVG',
        'renombrador' => 'Renombra imágenes en lote', 'globo' => 'Mockups de mensajes, chats y notificaciones', 'etiqueta' => 'Etiquetas de usuario para fotos e historias', 'calco' => 'Stickers troquelados desde una foto, texto o emoji', 'rotulo' => 'Títulos animados para videos', 'resena' => 'Insignias de puntaje y tarjetas de reseña', 'codigo' => 'Códigos QR con estilo que se pueden escanear', 'mockups' => 'Tus diseños sobre productos, pantallas, carteles y ropa', 'linkgen' => 'Enlaces para el CMS de cada sitio', 'claude' => 'Consultas y documentos con IA', 'links' => 'Recursos y enlaces del equipo',
        'afiliados' => 'Quién recomienda Cronos, comisiones y liquidaciones', 'recomendar' => 'Recomienda Cronos a otras agencias y gana con su primer pago',
        'comunidad' => 'Novedades del equipo', 'bienestar' => 'Tu ánimo, hábitos, retos y reconocimientos', 'chat' => 'Mensajes del equipo', 'encuesta' => 'Encuesta interna',
        'stats' => 'Producción y métricas', 'status' => 'Incidencias y su estado', 'todos' => 'Tus tareas pendientes', 'calendario' => 'Eventos personales y tus tareas con fecha', 'clientes' => (is_file(__DIR__ . '/facturacion/index.php') ? 'Fichas, servicios, archivos y facturas de cada cliente' : 'Fichas, servicios, notas y archivos de cada cliente'), 'facturacion' => 'Facturas, cobros y diseño de comprobantes', 'pagos' => 'Cobros en línea, transferencias por revisar y medios de pago', 'tareas' => 'Tablero de tareas por marca', 'monday' => 'Tareas de Monday', 'social' => 'Calendario editorial de redes y tickets para producir cada publicación',
    ];
    $out = [];
    foreach (g7_modules() as $id => $m) {
        if (!empty($m['core']) || $m['mode'] === 'off' || !g7_can($uid, $id)) continue;
        $out[$id] = ['id' => $id, 'label' => $m['label'], 'icon' => $m['icon'], 'group' => $m['group'], 'url' => $m['url'],
                     'desc' => $m['description'] ?: ($desc[$id] ?? '')];
    }
    return $out;
}

// ═══════════════════════════════════════════════════════════
// SUBIDAS: extensión permitida (2026-10-10)
// Antes cada página guardaba el archivo con la extensión que mandaba el usuario: un ".php" subido
// como imagen se podía ejecutar en el servidor. Ahora solo se aceptan extensiones de una lista, y
// las carpetas de subidas tienen además un .htaccess que no deja ejecutar código.
// ═══════════════════════════════════════════════════════════
const G7_EXT_IMG   = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
const G7_EXT_MEDIA = ['mp4', 'webm', 'mov', 'm4v', 'mp3', 'wav', 'ogg', 'm4a'];
const G7_EXT_DOCS  = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'zip', 'rar', '7z', 'psd', 'ai', 'fig', 'eps'];

/** Extensión en minúsculas si está en la lista permitida; null si no. */
function g7_upload_ext(string $name, array $allowed): ?string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return ($ext !== '' && in_array($ext, $allowed, true)) ? $ext : null;
}

/** Corta el pedido con un error JSON si la extensión no está permitida; si está, la devuelve. */
/** Por qué no llegó un archivo subido ($_FILES[x]); null si llegó bien o si no se mandó ninguno. */
function g7_upload_problem($f): ?string {
    if (!is_array($f) || !isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) return null;
    switch ($f['error']) {
        case UPLOAD_ERR_OK: return is_uploaded_file($f['tmp_name']) ? null : 'El archivo no llegó completo';
        case UPLOAD_ERR_INI_SIZE: case UPLOAD_ERR_FORM_SIZE: return 'La imagen pesa más de lo que acepta el servidor (' . ini_get('upload_max_filesize') . ')';
        case UPLOAD_ERR_PARTIAL: return 'La subida se cortó a la mitad: prueba de nuevo';
        default: return 'El servidor no pudo guardar el archivo (código ' . (int)$f['error'] . ')';
    }
}

/** Mueve un archivo subido a su carpeta (creándola si falta). Devuelve null si salió bien o el motivo del error. */
function g7_upload_store(array $f, string $dir, string $name): ?string {
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return 'No existe la carpeta de destino y no se pudo crear';
    if (!is_writable($dir)) return 'El servidor no tiene permiso para escribir en ' . basename($dir) . '/';
    return @move_uploaded_file($f['tmp_name'], rtrim($dir, '/') . '/' . $name) ? null : 'El servidor no pudo guardar el archivo';
}

function g7_upload_ext_or_die(string $name, array $allowed, string $errKey = 'error'): string {
    $ext = g7_upload_ext($name, $allowed);
    if ($ext === null) {
        header('Content-Type: application/json');
        echo json_encode([$errKey => 'Tipo de archivo no permitido (' . (strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'sin extensión') . ')', 'ok' => false, 'status' => 'error', 'message' => 'Tipo de archivo no permitido']);
        exit;
    }
    return $ext;
}

