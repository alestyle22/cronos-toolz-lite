<?php
/**
 * integraciones/lib.php — Base común de las integraciones por agencia (Etapa 10 de rubros). Cada sitio carga sus propias
 * cuentas y claves desde Admin › Integraciones. Todo vive en _private/integrations.json (fuera de la web y protegido de
 * las actualizaciones). Las claves nunca vuelven al navegador: la pantalla solo sabe si están guardadas.
 * Lo que se hace queda en _private/integrations.log (una línea JSON por acción, sin claves).
 * Integraciones: google_calendar (gcal.php), whatsapp (whatsapp.php), arca (arca.php), portales (portales.php), youtube (youtube.php), opencloud (opencloud.php).
 * Doc: toolz/INTEGRACIONES.md
 */
require_once __DIR__ . '/../auth.php';

const INT_LOG_MAX = 2 * 1024 * 1024;   // al pasar este tamaño, el log se rota (queda el .1)

/** Campos de cada integración. type: text, secret, pem (secreto de varias líneas), select, bool, number. */
function int_defs(): array {
    return [
        'google_calendar' => ['name' => 'Google Calendar', 'icon' => 'fa-brands fa-google', 'color' => '#4285f4',
            'desc' => 'Cada persona conecta su Google Calendar desde su perfil, y sus eventos de Toolz (visitas, turnos y los personales) se copian solos.',
            'fields' => [
                'client_id' => ['label' => 'ID de cliente OAuth', 'type' => 'text', 'required' => true, 'placeholder' => '1234-abc.apps.googleusercontent.com'],
                'client_secret' => ['label' => 'Secreto del cliente', 'type' => 'secret', 'required' => true],
            ]],
        'whatsapp' => ['name' => 'WhatsApp Business', 'icon' => 'fa-brands fa-whatsapp', 'color' => '#25d366',
            'desc' => 'Manda por WhatsApp el recordatorio de los turnos (además del email), con la API oficial de Meta (WhatsApp Cloud API).',
            'fields' => [
                'phone_number_id' => ['label' => 'Identificador del número (phone number id)', 'type' => 'text', 'required' => true, 'placeholder' => '1098765432101234'],
                'token' => ['label' => 'Token de acceso (usuario del sistema)', 'type' => 'secret', 'required' => true],
                'tpl_reminder' => ['label' => 'Plantilla del recordatorio', 'type' => 'text', 'default' => 'recordatorio_turno', 'help' => 'Nombre de la plantilla aprobada en Meta. Variables: {{1}} nombre, {{2}} profesional, {{3}} fecha y hora.'],
                'tpl_lang' => ['label' => 'Idioma de la plantilla', 'type' => 'text', 'default' => 'es_AR', 'placeholder' => 'es_AR'],
                'remind_on' => ['label' => 'Mandar el recordatorio de Turnos por WhatsApp', 'type' => 'bool', 'default' => true],
                'api_version' => ['label' => 'Versión de la API Graph', 'type' => 'text', 'default' => 'v24.0', 'advanced' => true],
            ]],
        'arca' => ['name' => 'ARCA · Factura electrónica', 'icon' => 'fa-solid fa-file-shield', 'color' => '#0ea5e9',
            'desc' => 'Pide el CAE de las facturas emitidas a ARCA (ex AFIP) con los web services WSAA y WSFEv1. Monotributo emite Factura C.',
            'fields' => [
                'cuit' => ['label' => 'CUIT del emisor', 'type' => 'text', 'required' => true, 'placeholder' => '20123456789'],
                'pto_vta' => ['label' => 'Punto de venta (web service)', 'type' => 'number', 'required' => true, 'placeholder' => '2'],
                'condicion' => ['label' => 'Condición frente al IVA', 'type' => 'select', 'default' => 'monotributo',
                    'options' => ['monotributo' => 'Monotributo (Factura C)', 'responsable_inscripto' => 'Responsable inscripto (Factura A o B)', 'exento' => 'Exento (Factura C)']],
                'concepto' => ['label' => 'Qué se factura', 'type' => 'select', 'default' => '2', 'options' => ['2' => 'Servicios', '1' => 'Productos', '3' => 'Productos y servicios']],
                'env' => ['label' => 'Ambiente', 'type' => 'select', 'default' => 'homologacion', 'options' => ['homologacion' => 'Homologación (pruebas)', 'produccion' => 'Producción']],
                'cert' => ['label' => 'Certificado (.crt)', 'type' => 'pem', 'required' => true, 'placeholder' => '-----BEGIN CERTIFICATE-----'],
                'key' => ['label' => 'Clave privada (.key)', 'type' => 'pem', 'required' => true, 'placeholder' => '-----BEGIN PRIVATE KEY-----'],
                'key_pass' => ['label' => 'Contraseña de la clave (si tiene)', 'type' => 'secret'],
            ]],
        'portales' => ['name' => 'Portales inmobiliarios', 'icon' => 'fa-solid fa-building-circle-arrow-right', 'color' => '#f59e0b',
            'desc' => 'Un feed (XML y JSON) con las propiedades disponibles para los portales y agregadores, y fichas públicas para compartir con un interesado.',
            'module' => 'propiedades',
            'fields' => [
                'show_price' => ['label' => 'Mostrar el precio', 'type' => 'bool', 'default' => true],
                'show_address' => ['label' => 'Mostrar la dirección exacta (si no, solo barrio y ciudad)', 'type' => 'bool', 'default' => false],
                'contact_phone' => ['label' => 'Teléfono de contacto para los avisos', 'type' => 'text', 'placeholder' => '+54 9 11 5555 0000'],
                'contact_email' => ['label' => 'Email de contacto para los avisos', 'type' => 'text'],
            ]],
        'youtube' => ['name' => 'YouTube · métricas', 'icon' => 'fa-brands fa-youtube', 'color' => '#ff0000',
            'desc' => 'Trae vistas, me gusta y comentarios de los entregables de Talentos que tienen un link de YouTube.',
            'module' => 'talentos',
            'fields' => [
                'api_key' => ['label' => 'Clave de la API (YouTube Data API v3)', 'type' => 'secret', 'required' => true],
                'auto' => ['label' => 'Actualizar las métricas solas una vez por día', 'type' => 'bool', 'default' => true],
            ]],
        'opencloud' => ['name' => 'OpenCloud', 'icon' => 'fa-solid fa-cloud', 'color' => '#20434f',
            'desc' => 'La nube de archivos propia de la agencia (OpenCloud autoalojado): adjuntar archivos de OpenCloud en tickets, solicitudes y el chat, y una carpeta por cliente en su ficha.',
            'fields' => [
                'url' => ['label' => 'Dirección del servidor', 'type' => 'text', 'required' => true, 'placeholder' => 'https://nube.tu-agencia.com'],
                'user' => ['label' => 'Usuario de OpenCloud', 'type' => 'text', 'required' => true, 'placeholder' => 'toolz', 'help' => 'Conviene una cuenta solo para Toolz: lo que se ve desde Toolz es lo de esta cuenta.'],
                'token' => ['label' => 'Token de aplicación', 'type' => 'secret', 'required' => true, 'help' => 'Se crea en OpenCloud (Mi cuenta › Tokens de aplicación, o con "opencloud auth-app create"). No uses la contraseña de la cuenta.'],
                'root' => ['label' => 'Carpeta de Toolz', 'type' => 'text', 'default' => '/Toolz', 'placeholder' => '/Toolz', 'help' => 'Desde Toolz solo se ve esta carpeta y lo que tiene adentro. Si no existe, se crea.'],
                'clients_dir' => ['label' => 'Carpeta de los clientes (dentro de la de Toolz)', 'type' => 'text', 'default' => 'Clientes', 'placeholder' => 'Clientes'],
                'link_mode' => ['label' => 'Link que se pega al adjuntar', 'type' => 'select', 'default' => 'private',
                    'options' => ['private' => 'Interno: lo abre quien tenga cuenta en OpenCloud', 'public' => 'Público de solo lectura: lo abre cualquiera con el link']],
                'who' => ['label' => 'Quién puede adjuntar desde OpenCloud', 'type' => 'select', 'default' => 'everyone', 'options' => ['everyone' => 'Todas las personas de Toolz', 'admins' => 'Solo administradores']],
                'dav_path' => ['label' => 'Ruta WebDAV', 'type' => 'text', 'default' => '/remote.php/webdav', 'advanced' => true, 'help' => 'La de la carpeta personal de la cuenta. Cambiala solo si tu servidor usa otra.'],
                'insecure' => ['label' => 'Aceptar un certificado propio (no recomendado)', 'type' => 'bool', 'default' => false, 'advanced' => true, 'help' => 'Solo si tu servidor usa un certificado autofirmado. Lo correcto es un certificado válido (por ejemplo, Let’s Encrypt).'],
            ]],
    ];
}
/** Las que todavía no se pueden conectar (quedan documentadas en INTEGRACIONES.md). */
const INT_PENDING = [
    'mercadolibre' => ['Mercado Libre Inmuebles', 'fa-solid fa-handshake', 'Publicar directo desde Propiedades. Requiere una app en Mercado Libre Developers y el permiso de publicación.'],
    'instagram' => ['Instagram', 'fa-brands fa-instagram', 'Métricas de publicaciones de los talentos. Meta exige revisar la app (permiso instagram_business_manage_insights).'],
    'tiktok' => ['TikTok', 'fa-brands fa-tiktok', 'Métricas de videos. TikTok exige revisar la app (Display API, video.list).'],
];

// ── Mercado de integraciones (Admin › Integraciones) ────────
/** Categorías del mercado, en orden. */
const INT_CATS = ['pagos' => ['Pagos', 'fa-solid fa-wallet'], 'productividad' => ['Productividad', 'fa-solid fa-list-check'], 'comunicacion' => ['Comunicación', 'fa-solid fa-comments'],
    'fiscal' => ['Facturación fiscal', 'fa-solid fa-file-shield'], 'ia' => ['IA y contenido', 'fa-solid fa-wand-magic-sparkles'], 'inmobiliaria' => ['Inmobiliarias', 'fa-solid fa-building'],
    'video' => ['Video', 'fa-brands fa-youtube'], 'pronto' => ['Próximamente', 'fa-solid fa-hourglass-half']];
/**
 * Catálogo con el estado de cada integración: ok (conectada), warn (prendida con datos faltantes o con error), off, soon.
 * go: dónde se configura — card (la tarjeta de abajo), section (otra sección del admin, sin reescribirla) o url.
 * Solo dice si hay claves guardadas: nunca las lee para mostrarlas.
 */
function int_market(): array {
    $out = []; $add = function (string $id, string $name, string $cat, string $icon, string $color, string $desc, string $status, array $go) use (&$out) {
        $out[] = compact('id', 'name', 'cat', 'icon', 'color', 'desc', 'status', 'go');
    };
    $cardStatus = function (string $id): string { $p = int_public($id); return !empty($p['enabled']) ? (int_ready($id) && empty($p['status']['last_error']) ? 'ok' : 'warn') : 'off'; };
    // Pagos: la pasarela central (Finanzas y pagos)
    if (function_exists('g7_site_has') && g7_site_has('clientes') && is_file(dirname(__DIR__) . '/pagos/lib.php')) {
        try {
            require_once dirname(__DIR__) . '/pagos/lib.php';
            $desc = ['mercadopago' => 'Checkout Pro con tarjetas, dinero en cuenta y medios locales de Argentina y Latinoamérica.', 'stripe' => 'Tarjetas internacionales, Apple Pay y Google Pay. Ideal para USD y EUR.',
                'paypal' => 'Pagos internacionales con cuenta PayPal o tarjeta, en USD y EUR. Reembolsos y avisos verificados.',
                'transfer' => 'Varias cuentas bancarias, cada una con su moneda. El comprador sube el comprobante y una persona lo aprueba.',
                // 2026-11-16: Venezuela, cripto, Uruguay y España
                'pagomovil' => 'Venezuela: pago al teléfono del comercio en bolívares, con la tasa del día para cobrar lo que está en dólares.',
                'zelle' => 'Dólares desde bancos de EE. UU., muy usado en Venezuela. El comprador carga el código y una persona lo aprueba.',
                'zinli' => 'Billetera en dólares de Venezuela y Panamá. El comprador carga la referencia y una persona lo aprueba.',
                'binancepers' => 'USDT a tu cuenta personal de Binance. El comprador sube la captura y una persona lo aprueba.',
                'binancepay' => 'USDT desde la cuenta de Binance del comprador, con la API de comercios. Se confirma consultando a Binance.',
                'usdt' => 'USDT por la red TRON a tu propia billetera, sin intermediarios. Se verifica solo en la cadena.',
                'dlocalgo' => 'Uruguay y la región: tarjetas locales, Abitab, RedPagos, Prex y más medios locales.',
                'redsys' => 'España: tarjeta con el TPV virtual de tu banco.',
                'bizum' => 'España: pago con el móvil desde el banco, por el mismo TPV de Redsys.'];
            foreach (['mercadopago', 'stripe', 'paypal', 'transfer', 'pagomovil', 'zelle', 'zinli', 'binancepers', 'binancepay', 'usdt', 'dlocalgo', 'redsys', 'bizum'] as $id) {
                if (!isset(pay_providers()[$id])) continue;
                $p = pay_provider_public($id); $err = $p['last_error'] && (!$p['last_ok'] || $p['last_error']['at'] > $p['last_ok']);
                $add('pay_' . $id, $p['label'], 'pagos', $p['icon'], $p['color'], $desc[$id], $p['ready'] ? ($err ? 'warn' : 'ok') : ($p['on'] ? 'warn' : 'off'), ['url' => '/toolz/pagos/admin/#medios']);
            }
        } catch (Throwable $e) {}
        $add('pay_modo', 'MODO', 'pronto', 'fa-solid fa-mobile-screen', '#00a19b', 'Pago con QR y apps de bancos argentinos. Lo habilita tu banco o procesador.', 'soon', []);
    }
    // Productividad
    $jira = is_file(dirname(__DIR__) . '/_private/jira.php') ? (include dirname(__DIR__) . '/_private/jira.php') : [];
    $add('jira', 'Jira', 'productividad', 'fa-brands fa-jira', '#2684ff', 'Vincula contenidos de Promos, pedidos del chat y tickets de Trabajo con issues de Jira.', !empty($jira['token']) && !empty($jira['email']) ? 'ok' : 'off', ['section' => 'jira']);
    $mon = is_file(dirname(__DIR__) . '/_private/monday.php') ? (string)file_get_contents(dirname(__DIR__) . '/_private/monday.php') : '';
    $add('monday', 'Monday.com', 'productividad', 'fa-solid fa-check-square', '#ff3d57', 'Trae las tareas de un tablero de Monday a Toolz.', preg_match("/define\('MONDAY_TOKEN',\s*'[^']+'\)/", $mon) ? 'ok' : 'off', ['section' => 'monday']);
    // Las de este registro (tarjetas de abajo)
    $cats = ['google_calendar' => 'productividad', 'whatsapp' => 'comunicacion', 'arca' => 'fiscal', 'portales' => 'inmobiliaria', 'youtube' => 'video', 'opencloud' => 'productividad'];
    foreach (int_defs() as $id => $d) {
        if (!empty($d['module']) && !int_module_on($d['module'])) continue;   // sin su módulo no se ofrece
        $add($id, $d['name'], $cats[$id] ?? 'productividad', $d['icon'], $d['color'], $d['desc'], $cardStatus($id), ['card' => $id]);
    }
    // Redes sociales: la app de Meta de Social Studio se configura en el módulo (social/, SOCIAL_STUDIO.md); acá solo se muestra su estado
    if (function_exists('g7_modules') && (g7_modules()['social']['mode'] ?? 'off') !== 'off' && is_file(dirname(__DIR__) . '/social/meta_lib.php')) {
        try { require_once dirname(__DIR__) . '/social/meta_lib.php';
              $add('meta_social', 'Meta · Social Studio', 'comunicacion', 'fa-brands fa-meta', '#0866ff', 'Conecta páginas de Facebook y cuentas profesionales de Instagram para publicar desde Social Studio (y, en las próximas etapas, el Inbox y las métricas).',
                   ss_meta_ready() ? 'ok' : 'off', ['url' => '/toolz/social/#cuentas']); } catch (Throwable $e) {}
    }
    // IA y contenido (Admin › Inteligencia artificial)
    $ia = is_file(dirname(__DIR__) . '/_private/ia.php') ? (include dirname(__DIR__) . '/_private/ia.php') : [];
    $ia = is_array($ia) ? $ia : [];
    $add('claude', 'Claude (Anthropic)', 'ia', 'fa-solid fa-comment-dots', '#d97757', 'Escribe landings y busca datos en la web para las placas.', !empty($ia['claude']['key']) ? 'ok' : 'off', ['section' => 'ia']);
    $add('leonardo', 'Leonardo.Ai', 'ia', 'fa-solid fa-image', '#7c3aed', 'Genera fondos e imágenes para Placas Deportivas.', !empty($ia['leonardo']['key']) ? 'ok' : 'off', ['section' => 'ia']);
    foreach (['pexels' => ['Pexels', '#05a081'], 'pixabay' => ['Pixabay', '#2ec66d'], 'unsplash' => ['Unsplash', '#111111']] as $k => [$n, $c])
        $add($k, $n, 'ia', 'fa-solid fa-images', $c, 'Banco de imágenes gratis para buscar fondos y recursos.', !empty($ia['stock'][$k]) ? 'ok' : 'off', ['section' => 'ia']);
    foreach (INT_PENDING as $id => [$n, $ic, $d]) $add($id, $n, 'pronto', $ic, '#64748b', $d, 'soon', []);
    return $out;
}

// ── Dónde se guarda ─────────────────────────────────────────
require_once dirname(__DIR__) . '/priv_lib.php';
function int_dir(): string { return defined('G7_INT_DIR') ? G7_INT_DIR : dirname(__DIR__) . '/_private'; }
/** Archivo .php con "<?php exit; ?>" adelante: no se puede leer por web aunque falle el .htaccess (SEC-22). */
function int_file(): string { return int_dir() . '/integrations.php'; }
function int_file_old(): string { return int_dir() . '/integrations.json'; }   // nombre de antes: se migra solo

function int_store(): array {
    $raw = g7_priv_read(int_file(), int_file_old()); if ($raw === null) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
/** Lee, cambia y guarda con bloqueo (dos pedidos a la vez no se pisan). Devuelve lo guardado. */
function int_store_update(callable $fn): array {
    $dir = int_dir(); if (!is_dir($dir)) @mkdir($dir, 0750, true);
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "# Nada de esta carpeta se sirve por web\nRequire all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    g7_priv_migrate(int_file(), int_file_old());
    $fh = fopen(int_file(), 'c+'); if (!$fh) throw new RuntimeException('No se pudo guardar la configuración de integraciones.');
    try {
        flock($fh, LOCK_EX);
        $raw = g7_priv_decode((string)stream_get_contents($fh)); $d = json_decode($raw, true); if (!is_array($d)) $d = [];
        if (empty($d['_key'])) $d['_key'] = bin2hex(random_bytes(32));   // firma de los links (OAuth, fichas, feed)
        $d = $fn($d);
        ftruncate($fh, 0); rewind($fh); fwrite($fh, g7_priv_encode(json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT))); fflush($fh);
        flock($fh, LOCK_UN);
    } finally { fclose($fh); }
    @chmod(int_file(), 0600);
    return $d;
}
function int_key(): string {
    $k = (string)(int_store()['_key'] ?? '');
    if ($k === '') $k = (string)int_store_update(fn($d) => $d)['_key'];
    return $k;
}
function int_sign(string $data): string { return rtrim(strtr(base64_encode(hash_hmac('sha256', $data, int_key(), true)), '+/', '-_'), '='); }

/** Configuración de una integración con los valores por defecto (incluye las claves: solo para el servidor). */
function int_cfg(string $id): array {
    $def = int_defs()[$id] ?? null; if (!$def) return [];
    $c = (array)(int_store()[$id] ?? []);
    $o = ['enabled' => !empty($c['enabled'])];
    foreach ($def['fields'] as $k => $f) $o[$k] = array_key_exists($k, $c) ? $c[$k] : ($f['default'] ?? ($f['type'] === 'bool' ? false : ''));
    foreach ($c as $k => $v) if (!isset($o[$k])) $o[$k] = $v;   // datos propios de cada integración (usuarios de Google, feed_token, TA de ARCA…)
    return $o;
}
/** ¿Prendida y con lo obligatorio cargado? */
function int_ready(string $id): bool {
    $def = int_defs()[$id] ?? null; if (!$def) return false;
    $c = int_cfg($id); if (!$c['enabled']) return false;
    foreach ($def['fields'] as $k => $f) if (!empty($f['required']) && trim((string)$c[$k]) === '') return false;
    if (!empty($def['module']) && !int_module_on($def['module'])) return false;
    return true;
}
function int_module_on(string $m): bool {
    if (!function_exists('g7_site_has') || !g7_site_has('clientes')) return false;
    if ($m === 'propiedades') return g7_prop_enabled();
    if ($m === 'talentos') return g7_tl_enabled();
    if ($m === 'turnos') return g7_ap_enabled();
    return true;
}

/** Valida y guarda lo que manda el Admin. Las claves vacías se conservan; $clear lista las que se borran. */
function int_save(string $id, array $in, array $clear = []): array {
    $def = int_defs()[$id] ?? null; if (!$def) throw new RuntimeException('Integración desconocida.');
    $vals = (array)($in['fields'] ?? []); $new = [];
    foreach ($def['fields'] as $k => $f) {
        if (!array_key_exists($k, $vals)) continue;
        $v = $vals[$k];
        if ($f['type'] === 'bool') { $new[$k] = (bool)$v; continue; }
        $v = $f['type'] === 'pem' ? trim(str_replace("\r", '', (string)$v)) : trim((string)$v);
        if (in_array($f['type'], ['secret', 'pem'], true) && $v === '') continue;   // vacío = no cambia
        if (strlen($v) > ($f['type'] === 'pem' ? 20000 : 600)) throw new RuntimeException('"' . $f['label'] . '" es demasiado largo.');
        if ($f['type'] === 'select' && !isset($f['options'][$v])) throw new RuntimeException('Elige una opción de "' . $f['label'] . '".');
        if ($f['type'] === 'number' && $v !== '' && !ctype_digit($v)) throw new RuntimeException('"' . $f['label'] . '" tiene que ser un número.');
        $new[$k] = $v;
    }
    int_validate($id, $new);
    $enabled = array_key_exists('enabled', $in) ? !empty($in['enabled']) : null;
    int_store_update(function ($d) use ($id, $new, $clear, $enabled, $def) {
        $c = (array)($d[$id] ?? []);
        foreach ($new as $k => $v) $c[$k] = $v;
        foreach ($clear as $k) if (isset($def['fields'][$k]) && in_array($def['fields'][$k]['type'], ['secret', 'pem'], true)) unset($c[$k]);
        if ($enabled !== null) $c['enabled'] = $enabled;
        if ($id === 'arca' && (isset($new['cert']) || isset($new['key']) || isset($new['env']) || isset($new['cuit']) || in_array('cert', $clear, true))) unset($c['ta']);   // otro certificado o ambiente: pide un ticket nuevo
        if ($id === 'portales' && empty($c['feed_token'])) $c['feed_token'] = bin2hex(random_bytes(16));
        $d[$id] = $c; return $d;
    });
    int_log($id, 'config', true, $enabled === null ? 'Configuración guardada' : ($enabled ? 'Activada' : 'Desactivada'));
    return int_cfg($id);
}
/** Reglas propias de cada una (formato de los datos). */
function int_validate(string $id, array $v): void {
    if ($id === 'google_calendar' && isset($v['client_id']) && $v['client_id'] !== '' && !preg_match('/^[\w.-]+\.apps\.googleusercontent\.com$/', $v['client_id']))
        throw new RuntimeException('El ID de cliente de Google termina en .apps.googleusercontent.com. Cópialo de Google Cloud › Credenciales.');
    if ($id === 'whatsapp') {
        if (isset($v['phone_number_id']) && $v['phone_number_id'] !== '' && !preg_match('/^\d{6,25}$/', $v['phone_number_id'])) throw new RuntimeException('El identificador del número son solo dígitos (no es el número de teléfono).');
        if (isset($v['tpl_reminder']) && $v['tpl_reminder'] !== '' && !preg_match('/^[a-z0-9_]{1,512}$/', $v['tpl_reminder'])) throw new RuntimeException('El nombre de la plantilla va en minúsculas, números y guion bajo, como en Meta.');
        if (isset($v['tpl_lang']) && $v['tpl_lang'] !== '' && !preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $v['tpl_lang'])) throw new RuntimeException('El idioma va como es, es_AR o en_US.');
        if (isset($v['api_version']) && $v['api_version'] !== '' && !preg_match('/^v\d{1,3}\.\d$/', $v['api_version'])) throw new RuntimeException('La versión de la API va como v24.0.');
    }
    if ($id === 'arca') {
        if (isset($v['cuit']) && $v['cuit'] !== '' && !int_cuit_ok($v['cuit'])) throw new RuntimeException('Revisa el CUIT: son 11 números y el último es el dígito verificador.');
        if (isset($v['pto_vta']) && $v['pto_vta'] !== '' && ((int)$v['pto_vta'] < 1 || (int)$v['pto_vta'] > 99998)) throw new RuntimeException('El punto de venta va de 1 a 99998.');
        if (isset($v['cert']) && !@openssl_x509_read($v['cert'])) throw new RuntimeException('El certificado no se puede leer. Pega el contenido completo del .crt (con BEGIN CERTIFICATE).');
        if (isset($v['key']) && !str_contains($v['key'], 'PRIVATE KEY-----')) throw new RuntimeException('La clave privada no se puede leer. Pega el contenido completo del .key (con BEGIN … PRIVATE KEY).');
    }
    if ($id === 'opencloud') {
        if (isset($v['url']) && $v['url'] !== '' && !oc_url_ok($v['url'])) throw new RuntimeException('La dirección empieza con https:// y es la de tu servidor de OpenCloud, por ejemplo https://nube.tu-agencia.com.');
        foreach (['root' => 'La carpeta de Toolz', 'clients_dir' => 'La carpeta de los clientes', 'dav_path' => 'La ruta WebDAV'] as $k => $l)
            if (isset($v[$k]) && $v[$k] !== '' && (str_contains($v[$k], '..') || preg_match('/[\x00-\x1f<>"|?*]/', $v[$k]))) throw new RuntimeException("$l no puede tener .. ni caracteres raros.");
        if (isset($v['user']) && $v['user'] !== '' && !preg_match('/^[\w.@+-]{1,120}$/u', $v['user'])) throw new RuntimeException('Revisá el usuario de OpenCloud.');
    }
    if ($id === 'portales' && isset($v['contact_email']) && $v['contact_email'] !== '' && !filter_var($v['contact_email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Revisa el email de contacto.');
}
function int_cuit_ok(string $c): bool {
    $c = preg_replace('/\D/', '', $c); if (strlen($c) !== 11) return false;
    $w = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2]; $s = 0; for ($i = 0; $i < 10; $i++) $s += (int)$c[$i] * $w[$i];
    $d = 11 - $s % 11; $d = $d === 11 ? 0 : ($d === 10 ? 9 : $d);
    return $d === (int)$c[10];
}

/** Lo que ve el Admin: las claves solo como "guardada" (nunca su valor). */
function int_public(string $id): array {
    $def = int_defs()[$id]; $c = int_cfg($id); $f = [];
    foreach ($def['fields'] as $k => $fd) $f[$k] = in_array($fd['type'], ['secret', 'pem'], true) ? ['saved' => trim((string)$c[$k]) !== ''] : $c[$k];
    $st = (array)(int_store()['_status'][$id] ?? []);
    return ['id' => $id, 'enabled' => $c['enabled'], 'ready' => int_ready($id), 'fields' => $f, 'status' => $st];
}

// ── Log y estado ────────────────────────────────────────────
/** Anota una acción: en el log y como última actividad (o último error) de la integración. */
function int_log(string $id, string $act, bool $ok, string $msg, array $extra = []): void {
    if (function_exists('g7_cfg_all')) g7_cfg_all();   // carga la zona horaria del sitio (en la consola todavía no está)
    $msg = int_scrub(mb_substr($msg, 0, 500));
    $uid = null; if (function_exists('getCurrentUser') && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['g7_user_id'])) $uid = (int)$_SESSION['g7_user_id'];
    $line = json_encode(['t' => date('Y-m-d H:i:s'), 'int' => $id, 'act' => $act, 'ok' => $ok, 'msg' => $msg, 'uid' => $uid] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $lf = int_dir() . '/integrations.log';
    if (is_file($lf) && filesize($lf) > INT_LOG_MAX) @rename($lf, $lf . '.1');
    @file_put_contents($lf, $line . "\n", FILE_APPEND | LOCK_EX);
    if ($act === 'config') return;
    try {
        int_store_update(function ($d) use ($id, $ok, $msg) {
            $s = (array)($d['_status'][$id] ?? []); $now = date('Y-m-d H:i:s');
            $s['last_activity'] = $now; $s['last_msg'] = $msg;
            if ($ok) $s['last_ok'] = $now; else { $s['last_error'] = $msg; $s['last_error_at'] = $now; }
            $d['_status'][$id] = $s; return $d;
        });
    } catch (Throwable $e) {}
}
function int_log_read(?string $id = null, int $n = 60): array {
    $lf = int_dir() . '/integrations.log'; if (!is_file($lf)) return [];
    $lines = array_slice(file($lf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -2000);
    $out = [];
    for ($i = count($lines) - 1; $i >= 0 && count($out) < $n; $i--) { $r = json_decode($lines[$i], true); if (is_array($r) && ($id === null || ($r['int'] ?? '') === $id)) $out[] = $r; }
    return $out;
}
/** Saca cualquier clave guardada de un texto (por si una API la repite en un error). */
function int_scrub(string $s): string {
    foreach (int_store() as $id => $c) {
        if (!is_array($c) || $id[0] === '_') continue;
        foreach (['client_secret', 'token', 'api_key', 'key_pass', 'feed_token'] as $k) if (!empty($c[$k]) && strlen((string)$c[$k]) >= 6) $s = str_replace((string)$c[$k], '***', $s);
    }
    return preg_replace('/(key|token|access_token|refresh_token|client_secret)=([^&\s"]+)/i', '$1=***', $s);
}

// ── HTTP ────────────────────────────────────────────────────
/**
 * Pedido HTTP. Devuelve ['status' => int, 'body' => string, 'json' => ?array, 'error' => string].
 * En las pruebas, $GLOBALS['INT_HTTP_MOCK'] (callable: método, url, cabeceras, cuerpo → ['status', 'body']) responde en lugar de la red.
 */
function int_http(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 25, bool $verify = true): array {
    if (isset($GLOBALS['INT_HTTP_MOCK']) && is_callable($GLOBALS['INT_HTTP_MOCK'])) {
        $r = ($GLOBALS['INT_HTTP_MOCK'])($method, $url, $headers, $body);
        $r += ['status' => 200, 'body' => '', 'error' => ''];
    } elseif (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0, CURLOPT_USERAGENT => 'CronosToolz/1.0']);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch); $err = $resp === false ? curl_error($ch) : ''; $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $r = ['status' => $code, 'body' => (string)$resp, 'error' => $err];
    } else {
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => (string)$body, 'timeout' => $timeout, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx); $code = 0;
        foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
        $r = ['status' => $code, 'body' => (string)$resp, 'error' => $resp === false ? 'Sin conexión' : ''];
    }
    $j = json_decode($r['body'], true); $r['json'] = is_array($j) ? $j : null;
    if ($r['error'] !== '') $r['error'] = 'No se pudo conectar: ' . int_scrub($r['error']);
    return $r;
}

// ── Utilidades ──────────────────────────────────────────────
/** Dirección del sitio (para los links de OAuth y del feed): la del pedido o la de _private/site.php. */
function int_site_url(): string {
    if (function_exists('g7_site_origin')) return g7_site_origin();   // dominio configurado (los links del feed y de las fichas se publican afuera)
    $u = (string)g7_site('url', ''); return $u !== '' ? rtrim(preg_replace('#/toolz/?$#', '', $u), '/') : 'http://localhost';
}
/** Ejecuta una tarea de fondo como mucho cada $every segundos (marca en g7_config). */
function int_every(string $key, int $every): bool {
    if (time() - (int)g7_cfg($key, 0) < $every) return false;
    g7_cfg_set($key, (string)time()); return true;
}
