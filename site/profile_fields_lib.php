<?php
/**
 * profile_fields_lib.php — Datos del perfil que comparten Comunidad, Mi perfil (perfil.php) y el onboarding.
 * Herramientas (g7_users.skills_json, {"Figma":1}) y aptitudes (g7_users.aptitudes, ["Ventas","Liderazgo"]):
 * las herramientas son programas y tecnologías; las aptitudes, lo que alguien sabe hacer aunque no sea diseño.
 * El formulario lo dibuja assets/profile-edit.js (y el selector, assets/profile-picker.js). Doc: toolz/COMUNIDAD.md
 */

/** Herramientas: categoría › subcategoría › herramientas (el orden es el del selector). */
const PF_TOOLS = [
    'Diseño' => [
        'Gráfico' => ['Adobe Photoshop', 'Adobe Illustrator', 'Adobe InDesign', 'Affinity Designer', 'Affinity Photo', 'CorelDRAW', 'Photopea', 'Procreate'],
        'UI y web' => ['Figma', 'Adobe XD', 'Spline'],
        'Rápido y recursos' => ['Canva', 'Adobe Express', 'Adobe Firefly', 'Freepik', 'Envato'],
    ],
    'Video y foto' => [
        'Edición de video' => ['Adobe Premiere Pro', 'Adobe After Effects', 'Adobe Animate', 'DaVinci Resolve', 'Final Cut Pro', 'CapCut', 'Filmora', 'Avid Media Composer', 'Frame.io'],
        'Audio' => ['Adobe Audition', 'Audacity'],
        'Foto' => ['Adobe Lightroom', 'Capture One Pro', 'Photo Mechanic'],
        'Streaming' => ['OBS Studio', 'Streamlabs'],
    ],
    '3D y juegos' => [
        '3D' => ['Blender', 'Cinema 4D', 'Autodesk Maya', '3ds Max', 'ZBrush', 'Substance Painter', 'Houdini'],
        'Motores y shaders' => ['Unreal Engine', 'Unity', 'Godot', 'GDScript', 'HLSL', 'GLSL'],
        'Efectos (VFX)' => ['Nuke', 'Mocha Pro'],
    ],
    'Desarrollo' => [
        'Lenguajes' => ['HTML5', 'CSS3', 'JavaScript', 'TypeScript', 'PHP', 'Python', 'Java', 'Kotlin', 'Swift', 'Ruby', 'Go', 'C++', 'C#', 'SQL'],
        'Frameworks' => ['React', 'Next.js', 'Vue.js', 'Angular', 'Svelte', 'Node.js', 'Laravel', 'Flutter', 'Tailwind CSS', 'SASS', 'Webpack'],
        'Bases de datos' => ['PostgreSQL', 'MySQL', 'MongoDB', 'Redis', 'Firebase'],
        'Herramientas y nube' => ['Visual Studio Code', 'Visual Studio', 'Git', 'GitHub', 'Docker', 'Linux', 'AWS', 'Postman'],
        'Web y landings' => ['WordPress', 'Elementor', 'WooCommerce', 'Shopify', 'Webflow', 'Unbounce', 'Cloudflare', 'cPanel', 'hPanel', 'FileZilla'],
    ],
    'Marketing' => [
        'Publicidad' => ['Meta Ads', 'Google Ads', 'TikTok Ads'],
        'Analítica' => ['Google Analytics', 'Google Tag Manager', 'Looker Studio', 'Semrush', 'Hotjar'],
        'Redes' => ['Metricool', 'Hootsuite', 'ManyChat'],
        'Email y CRM' => ['Mailchimp', 'Brevo', 'Klaviyo', 'SendGrid', 'Customer.io', 'Xtremepush', 'HubSpot', 'Salesforce'],
    ],
    'Oficina y gestión' => [
        'Documentos' => ['Excel', 'Google Sheets', 'Google Docs', 'Google Drive', 'Word', 'PowerPoint', 'Outlook'],
        'Proyectos' => ['Notion', 'Trello', 'Jira', 'ClickUp', 'Asana', 'Monday', 'Miro'],
        'Comunicación' => ['Slack', 'Microsoft Teams', 'Zoom'],
        'Datos' => ['Power BI', 'Tableau', 'Metabase', 'BigQuery'],
    ],
    'Atención y soporte' => [
        'Atención y soporte' => ['Zendesk', 'Freshdesk', 'Intercom', 'LiveChat', 'WhatsApp Business', 'Telegram', 'Discord'],
    ],
    'IA' => [
        'Texto y asistentes' => ['ChatGPT', 'Claude', 'Gemini', 'Copilot', 'Perplexity'],
        'Imagen' => ['Midjourney', 'Leonardo', 'Higgsfield', 'Freepik AI'],
        'Video y audio' => ['Runway', 'Kling', 'ElevenLabs', 'Suno'],
    ],
    'iGaming' => [
        'Plataformas' => ['BetConstruct', 'SoftSwiss', 'EveryMatrix', 'Digitain', 'Altenar', 'Kambi', 'Playtech IMS', 'Pragmatic Play Back Office'],
        'Deportes y cuotas' => ['Sportradar', 'Genius Sports'],
        'CRM y retención' => ['Optimove', 'Fast Track CRM', 'Smartico'],
        'Afiliados' => ['Income Access', 'MyAffiliates', 'Cellxpert', 'NetRefer', 'Affilka'],
        'Pagos y verificación' => ['Sumsub', 'Jumio', 'Praxis Cashier', 'Paysafe', 'Skrill', 'Neteller'],
    ],
];

/** Aptitudes sugeridas: categoría › subcategoría › aptitudes (además se pueden escribir otras). */
const PF_APTITUDES = [
    'Comunicación' => [
        'Escribir y hablar' => ['Comunicación', 'Redacción', 'Copywriting', 'Corrección de textos', 'Guion', 'Oratoria', 'Presentaciones', 'Locución'],
        'Idiomas' => ['Traducción', 'Inglés', 'Portugués', 'Italiano', 'Francés', 'Alemán'],
    ],
    'Personas' => [
        'Equipo' => ['Trabajo en equipo', 'Recursos humanos', 'Selección de personal', 'Capacitación', 'Mentoría', 'Mediación', 'Empatía'],
        'Forma de trabajar' => ['Proactividad', 'Adaptabilidad', 'Trabajo bajo presión', 'Autonomía', 'Pensamiento crítico'],
    ],
    'Negocio' => [
        'Clientes y ventas' => ['Atención al cliente', 'Ventas', 'Negociación', 'Postventa', 'Soporte técnico', 'Moderación de comunidades', 'Gestión de reclamos'],
        'Organización' => ['Gestión de proyectos', 'Planificación', 'Organización', 'Coordinación de equipos', 'Liderazgo', 'Toma de decisiones', 'Resolución de problemas', 'Gestión del tiempo', 'Atención al detalle'],
        'Datos y números' => ['Análisis de datos', 'Reportes', 'Administración', 'Contabilidad', 'Facturación', 'Finanzas', 'Compras', 'Logística'],
    ],
    'Marketing' => [
        'Estrategia' => ['Marketing digital', 'Performance / growth', 'Estrategia de contenidos', 'Branding', 'Investigación de mercado'],
        'Canales' => ['Community management', 'SEO', 'Pauta publicitaria', 'Email marketing', 'Marketing de afiliados', 'Gestión de influencers y streamers'],
        'Medición' => ['Analítica web', 'Pruebas A/B de campañas'],
    ],
    'Creatividad' => [
        'Ideas y diseño' => ['Creatividad', 'Ideación', 'Dirección de arte', 'Ilustración', 'Diseño de banners y promos'],
        'Audiovisual' => ['Fotografía', 'Edición de video', 'Edición de reels', 'Motion graphics', 'Animación', 'Producción audiovisual', 'Streaming', 'Producción de eventos'],
    ],
    'Tecnología' => [
        'Tecnología' => ['Programación', 'Diseño UX/UI', 'Automatizaciones', 'Uso de IA', 'Testing / QA', 'Soporte de sistemas'],
    ],
    'iGaming' => [
        'Operación' => ['Gestión de casino online', 'Trading deportivo', 'Gestión de cuotas', 'Gestión de torneos', 'Host de casino en vivo', 'Afiliados'],
        'Jugadores' => ['Retención de jugadores', 'Gestión VIP', 'Bonos y promociones', 'CRM de jugadores', 'Análisis de jugadores', 'Atención a jugadores', 'Moderación de chat en vivo'],
        'Cumplimiento' => ['Juego responsable', 'KYC / AML', 'Prevención de fraude', 'Pagos y gestión de retiros', 'Cumplimiento normativo'],
    ],
];

/** Lista plana de herramientas (para validar y para la Comunidad). */
function pf_tools_flat(): array { return pf_flat(PF_TOOLS); }
/** Aplana una lista de 1 o 2 niveles (categoría › subcategoría › elementos). */
function pf_flat(array $tree): array {
    $out = [];
    array_walk_recursive($tree, function ($v) use (&$out) { $out[] = $v; });
    return array_values(array_unique($out));
}

/** Crea la columna de aptitudes si falta (una vez por pedido). */
function pf_install(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    try {
        if (!$db->query("SHOW COLUMNS FROM g7_users LIKE 'aptitudes'")->fetch()) $db->exec("ALTER TABLE g7_users ADD COLUMN aptitudes TEXT NULL");
    } catch (Throwable $e) {}
}

/** Aptitudes que vienen del formulario (JSON o lista): sin repetidas, sin < >, hasta 30 de 40 letras. */
function pf_clean_aptitudes($in): array {
    if (is_string($in)) $in = json_decode($in, true);
    $out = [];
    foreach ((array)$in as $a) {
        $a = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$a)));
        $a = str_replace(['<', '>', '"'], '', $a);
        if ($a === '' || mb_strlen($a) > 40) continue;
        $k = mb_strtolower($a);
        if (isset($out[$k])) continue;
        $out[$k] = $a;
        if (count($out) >= 30) break;
    }
    return array_values($out);
}

/** Herramientas del formulario: solo las de la lista, como {"Figma":1}. */
function pf_clean_tools($in): array {
    if (is_string($in)) $in = json_decode($in, true);
    $ok = array_flip(pf_tools_flat()); $out = [];
    foreach ((array)$in as $k => $v) if (isset($ok[$k]) && $v) $out[$k] = 1;
    return $out;
}

/** Aptitudes guardadas de una persona. */
function pf_aptitudes(?string $raw): array { $a = json_decode((string)$raw, true); return is_array($a) ? array_values(array_filter($a, 'is_string')) : []; }

/** Datos para el formulario de edición (assets/profile-edit.js), de la persona que mira. */
function pf_edit_data(PDO $db, int $uid): array {
    pf_install($db);
    $q = $db->prepare('SELECT display_name, username, position, email, phone, location, bio, avatar, cover, instagram, behance, linkedin, portfolio, skills_json, aptitudes, birthday, joined_at FROM g7_users WHERE id = ?');
    try { $q->execute([$uid]); $u = $q->fetch(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { $q = $db->prepare('SELECT display_name, username, position, email, phone, location, bio, avatar, cover, instagram, behance, linkedin, portfolio, skills_json FROM g7_users WHERE id = ?'); $q->execute([$uid]); $u = $q->fetch(PDO::FETCH_ASSOC) ?: []; }
    $sk = json_decode((string)($u['skills_json'] ?? '{}'), true) ?: [];
    return [
        'me' => [
            'display_name' => (string)($u['display_name'] ?? ''), 'username' => (string)($u['username'] ?? ''), 'position' => (string)($u['position'] ?? ''),
            'email' => (string)($u['email'] ?? ''), 'phone' => (string)($u['phone'] ?? ''), 'location' => (string)($u['location'] ?? ''), 'bio' => (string)($u['bio'] ?? ''),
            'avatar' => (string)($u['avatar'] ?? ''), 'cover' => (string)($u['cover'] ?? ''),
            'instagram' => (string)($u['instagram'] ?? ''), 'behance' => (string)($u['behance'] ?? ''), 'linkedin' => (string)($u['linkedin'] ?? ''), 'portfolio' => (string)($u['portfolio'] ?? ''),
            'birthday' => (string)($u['birthday'] ?? ''), 'joined' => (string)($u['joined_at'] ?? ''),
            'tools' => array_keys(array_filter($sk, fn($v) => $v > 0)), 'aptitudes' => pf_aptitudes($u['aptitudes'] ?? null),
        ],
        'tools' => PF_TOOLS, 'aptitudes' => PF_APTITUDES,
    ];
}

/**
 * Todas las personas activas con sus herramientas y aptitudes (para "Quién sabe qué" en Comunidad y las coincidencias de Mi perfil).
 * Las herramientas que ya no están en la lista no cuentan; las aptitudes escritas a mano sí.
 */
function pf_people(PDO $db): array {
    pf_install($db);
    static $cache = null; if ($cache !== null) return $cache;
    $ok = array_flip(pf_tools_flat()); $out = [];
    try { $rows = $db->query("SELECT id, username, display_name, avatar, position, skills_json, aptitudes, last_seen FROM g7_users WHERE active = 1 ORDER BY COALESCE(NULLIF(display_name,''), username)")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { return $cache = []; }
    foreach ($rows as $r) {
        $sk = json_decode((string)$r['skills_json'], true) ?: [];
        $out[] = ['id' => (int)$r['id'], 'name' => (string)($r['display_name'] ?: $r['username']), 'username' => (string)$r['username'], 'avatar' => (string)$r['avatar'], 'position' => (string)$r['position'],
            'online' => $r['last_seen'] && time() - strtotime((string)$r['last_seen']) < 300,
            'tools' => array_values(array_filter(array_keys(array_filter($sk, fn($v) => $v > 0)), fn($t) => isset($ok[$t]))), 'aptitudes' => pf_aptitudes($r['aptitudes'])];
    }
    return $cache = $out;
}

/**
 * Afinidad entre dos personas por herramientas y aptitudes: 2 × compartidas ÷ (las mías + las suyas), de 0 a 100.
 * Así no gana quien marca todo: hace falta tener en común una parte grande de lo de los dos.
 */
function pf_affinity(array $a, array $b): array {
    $k = fn($p) => array_merge(array_map(fn($t) => 't:' . mb_strtolower($t), $p['tools']), array_map(fn($t) => 'a:' . mb_strtolower($t), $p['aptitudes']));
    $ka = array_unique($k($a)); $kb = array_unique($k($b));
    $tot = count($ka) + count($kb);
    $lower = fn($l) => array_map('mb_strtolower', $l);
    $st = array_values(array_filter($b['tools'], fn($t) => in_array(mb_strtolower($t), $lower($a['tools']), true)));
    $sa = array_values(array_filter($b['aptitudes'], fn($t) => in_array(mb_strtolower($t), $lower($a['aptitudes']), true)));
    $sh = count($st) + count($sa);
    return ['pct' => $tot ? (int)round(200 * $sh / $tot) : 0, 'shared' => $sh, 'tools' => $st, 'aptitudes' => $sa];
}

/** Las personas que más coinciden con $uid (de mayor a menor), con lo que comparten. Vacío si $uid no cargó nada. */
function pf_matches(PDO $db, int $uid, int $limit = 8): array {
    $all = pf_people($db); $me = null;
    foreach ($all as $p) if ($p['id'] === $uid) $me = $p;
    if (!$me || (!$me['tools'] && !$me['aptitudes'])) return [];
    $out = [];
    foreach ($all as $p) {
        if ($p['id'] === $uid || (!$p['tools'] && !$p['aptitudes'])) continue;
        $af = pf_affinity($me, $p); if (!$af['shared']) continue;
        $out[] = ['id' => $p['id'], 'name' => $p['name'], 'avatar' => $p['avatar'], 'position' => $p['position'], 'online' => $p['online']] + $af;
    }
    usort($out, fn($x, $y) => $y['pct'] <=> $x['pct'] ?: $y['shared'] <=> $x['shared'] ?: strcmp($x['name'], $y['name']));
    return array_slice($out, 0, $limit);
}
