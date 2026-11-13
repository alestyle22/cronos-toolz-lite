<?php
// Plantillas y plataformas de Landing Gen (2026-10-31; plataformas en el paquete de marcas desde 2026-11-12).
// Las plataformas salen de g7_pack('landing_platforms'); un sitio sin paquete tiene dos genéricas (lg_platforms()).
// Plantilla "generica" (index.php, $LANDING_TEMPLATE): la IA devuelve el HTML entero.
// Plantilla "de textos": una plataforma del paquete puede traer 'plantilla' => 'nombre', que es
// marcas/landing-gen/templates/<nombre>.html (y sus recursos fijos en marcas/landing-gen/assets/<nombre>/).
// La IA devuelve solo los textos en JSON y acá se encajan en la plantilla, así el CSS y las animaciones no pasan por la IA.
// Si el archivo de la plantilla no está, la plataforma usa la genérica. Doc del paquete: toolz/MARCAS.md

// Efectos de la plantilla de textos que se prenden y apagan desde el editor (clases en <body>)
const LG_TX_EFECTOS = [
    'respirar'    => ['label' => 'Fondo que respira (zoom lento)',      'on' => true],
    'niebla'      => ['label' => 'Niebla de colores',                   'on' => true],
    'brasas'      => ['label' => 'Brasas que suben',                    'on' => true],
    'murcielagos' => ['label' => 'Murciélagos (Halloween)',             'on' => false],
    'entradas'    => ['label' => 'Entrada animada de textos y TyC',     'on' => true],
    'brillo'      => ['label' => 'Brillo dorado en título y monto',     'on' => true],
    'flotar'      => ['label' => 'Arte que flota y sigue al mouse',     'on' => true],
    'boton'       => ['label' => 'Botón que late con destello',         'on' => true],
];

/** Un ajuste de Landing Gen del paquete de marcas (g7_pack('landing_gen')), o el default si no está o está vacío. */
function lg_cfg(string $k, $default = null) {
    $c = g7_pack('landing_gen', []);
    return (is_array($c) && isset($c[$k]) && $c[$k] !== '') ? $c[$k] : $default;
}

/**
 * Plataformas de Landing Gen: [id, name, color, icon, url] y opcionales slug (prefijo del archivo publicado),
 * brand (nombre en títulos y prompts), cta (URL del botón por defecto), plantilla (de textos, ver arriba),
 * ejemplo (placeholder del texto de la promo) y editor (false = sus landings no se abren en el editor).
 */
function lg_platforms(): array {
    static $list = null;
    if ($list !== null) return $list;
    $host = (string)parse_url(g7_site_origin(), PHP_URL_HOST);
    $def = [
        ['id' => 'web',      'name' => 'Sitio web', 'color' => '#3b82f6', 'icon' => 'fa-globe',    'url' => $host !== '' ? $host : 'tu sitio'],
        ['id' => 'campanas', 'name' => 'Campañas',  'color' => '#a855f7', 'icon' => 'fa-bullhorn', 'url' => 'redes y anuncios'],
    ];
    $list = [];
    foreach ((array)g7_pack('landing_platforms', $def) as $p) {
        if (!is_array($p) || !preg_match('/^[a-z0-9_-]{1,60}$/', (string)($p['id'] ?? ''))) continue;
        $list[] = $p + ['name' => $p['id'], 'color' => '#71717a', 'icon' => 'fa-globe', 'url' => ''];
    }
    return $list;
}
function lg_platform(string $id): ?array {
    foreach (lg_platforms() as $p) if ($p['id'] === $id) return $p;
    return null;
}
/** ¿Las landings de esta plataforma se abren en el editor? */
function lg_platform_editable(string $id): bool {
    $p = lg_platform($id);
    return $p !== null && ($p['editor'] ?? true) !== false;
}

/** Archivo de la plantilla de textos de la plataforma, o '' si no tiene (o el sitio no tiene el paquete). */
function lg_tpl_file(string $platform): string {
    $n = (string)(lg_platform($platform)['plantilla'] ?? '');
    if (!preg_match('/^[a-z0-9_-]+$/', $n)) return '';
    $f = dirname(__DIR__) . '/marcas/landing-gen/templates/' . $n . '.html';
    return is_file($f) ? $f : '';
}
/** 'textos' (plantilla del paquete, la IA redacta solo los textos) o 'generica' (la IA arma el HTML entero). */
function lg_template_kind(string $platform): string {
    return lg_tpl_file($platform) !== '' ? 'textos' : 'generica';
}

function lg_platform_info(string $platform): array {
    $p = lg_platform($platform) ?? [];
    $slug = (string)($p['slug'] ?? '');
    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($platform)) ?: 'landing';
    return ['slug' => $slug, 'brand' => (string)($p['brand'] ?? ($p['name'] ?? $platform)), 'cta' => (string)($p['cta'] ?? '#')];
}

// Esquema y servidor del pedido (https salvo localhost), para armar URLs absolutas del mismo Toolz
function lg_req_origin(): string {
    $host  = preg_replace('/[^a-z0-9.:\-\[\]]/i', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?: 'localhost';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
          || !preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host);
    return ($https ? 'https' : 'http') . '://' . $host;
}
/** Ruta web de Toolz ('/toolz'), sacada del script de landing-gen/ que corre. */
function lg_toolz_path(): string {
    $d = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/toolz/landing-gen/index.php'), 2));
    return rtrim($d, '/.');
}

// URL absoluta de los recursos fijos de la plantilla de textos (fuente), en el paquete de marcas del mismo Toolz
function lg_assets_base(string $name): string {
    return lg_req_origin() . lg_toolz_path() . '/marcas/landing-gen/assets/' . $name . '/';
}

// Deja solo marcado simple: párrafos, listas, negritas, itálicas, saltos y links http(s)
function lg_clean_html(string $html, string $allowed = '<p><ul><ol><li><strong><b><em><br><a>'): string {
    $html = strip_tags($html, $allowed);
    $html = preg_replace('/<(p|ul|ol|li|strong|b|em|br|table|thead|tbody|tr|th|td)\b[^>]*>/i', '<$1>', $html);
    $html = preg_replace_callback('/<a\b[^>]*>/i', function ($m) {
        return preg_match('/href\s*=\s*["\'](https?:\/\/[^"\']+)["\']/i', $m[0], $h)
            ? '<a href="' . htmlspecialchars($h[1], ENT_QUOTES) . '" target="_blank" rel="noopener">'
            : '<a>';
    }, $html);
    return trim($html);
}

function lg_tx_build(array $d, string $platform): string {
    $f = lg_tpl_file($platform);
    $tpl = $f !== '' ? file_get_contents($f) : false;
    if ($tpl === false) throw new RuntimeException('No se encontró la plantilla de esta plataforma');
    $info = lg_platform_info($platform);
    $e = fn($s) => htmlspecialchars(trim((string)$s), ENT_QUOTES, 'UTF-8');

    $t1 = trim((string)($d['titulo_1'] ?? ''));
    $t2 = trim((string)($d['titulo_2'] ?? ''));
    $ctaUrl = trim((string)($d['cta_url'] ?? ''));
    if (!preg_match('#^https?://#i', $ctaUrl)) $ctaUrl = $info['cta'];

    $secs = '';
    foreach ((array)($d['secciones'] ?? []) as $s) {
        $titulo = trim((string)($s['titulo'] ?? ''));
        $html   = lg_clean_html((string)($s['html'] ?? ''), '<p><ul><ol><li><strong><b><em><br><a><table><thead><tbody><tr><th><td>');
        if ($titulo === '' || $html === '') continue;
        if (!preg_match('/^\s*<(p|ul|ol|table)\b/i', $html)) $html = '<p>' . $html . '</p>';
        $secs .= "    <details>\n      <summary><span class=\"acc-title\">" . $e($titulo) . "</span></summary>\n"
               . "      <div class=\"contenido\">\n        " . $html . "\n      </div>\n    </details>\n";
    }

    $arte  = trim((string)($d['arte'] ?? ''));
    $fondo = trim((string)($d['fondo'] ?? ''));
    $fondoStyle = preg_match('#^https?://#i', $fondo) ? ' style="background-image:url(' . $e(json_encode($fondo, JSON_UNESCAPED_SLASHES)) . ')"' : '';

    // Efectos: todos prendidos salvo los murciélagos (solo para Halloween). $d['efectos'] = ['brasas' => false, 'murcielagos' => true, ...]
    $fx = [];
    if (!empty($d['halloween']) && !isset($d['efectos']['murcielagos'])) $d['efectos']['murcielagos'] = true;
    foreach (LG_TX_EFECTOS as $k => $def) {
        $on = array_key_exists($k, (array)($d['efectos'] ?? [])) ? (bool)$d['efectos'][$k] : $def['on'];
        if ($k === 'murcielagos') { if ($on) $fx[] = 'fx-murcielagos'; }
        elseif (!$on) $fx[] = 'fx-no-' . $k;
    }

    return strtr($tpl, [
        '{{EFECTOS}}'       => implode(' ', $fx),
        '{{TITULO_PAGINA}}' => $e(trim($t1 . ' ' . $t2)) . ' — ' . $e($info['brand']),
        '{{DESCRIPCION}}'   => $e($d['descripcion'] ?? ''),
        '{{ASSETS}}'        => $e(lg_assets_base((string)(lg_platform($platform)['plantilla'] ?? ''))),
        '{{FONDO_STYLE}}'   => $fondoStyle,
        '{{TITULO_1}}'      => $e($t1),
        '{{TITULO_2}}'      => $e($t2),
        '{{SUBTITULO}}'     => lg_clean_html((string)($d['subtitulo'] ?? ''), '<strong><b><br>'),
        '{{CTA_URL}}'       => $e($ctaUrl),
        '{{CTA_TEXTO}}'     => $e(($d['cta_texto'] ?? '') ?: (string)lg_cfg('cta_texto', 'Ver más')),
        '{{ARTE}}'          => preg_match('#^https?://#i', $arte) ? $e($arte) : '',
        '{{ARTE_ALT}}'      => $e(trim($t1 . ' ' . $t2)),
        '{{SECCIONES}}'     => rtrim($secs, "\n"),
    ]);
}

require_once __DIR__ . '/../ia_lib.php';

/** Quién escribe las landings: Claude si está configurado en Admin › Inteligencia artificial; si no, Gemini. */
function lg_ai_name(): string { return g7_claude_ready() ? 'Claude' : 'Gemini'; }

// Llama a Gemini y devuelve el texto de la respuesta (o lanza RuntimeException con el error)
function lg_gemini(string $system, string $user, ?array $schema = null): string {
    $key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    if (!$key) throw new RuntimeException('API key no configurada');
    $gen = ['maxOutputTokens' => 8192, 'temperature' => 0.2];
    if ($schema) { $gen['responseMimeType'] = 'application/json'; $gen['responseSchema'] = $schema; }
    $payload = [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents'          => [['role' => 'user', 'parts' => [['text' => $user]]]],
        'generationConfig'  => $gen,
    ];
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $key);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch); $err = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$resp || $err) throw new RuntimeException('cURL: ' . ($err ?: 'sin respuesta'));
    $r = json_decode($resp, true);
    if (isset($r['error'])) throw new RuntimeException('[' . $code . '] ' . ($r['error']['message'] ?? 'Error Gemini'));
    return (string)($r['candidates'][0]['content']['parts'][0]['text'] ?? '');
}

// Prompt neutro de la plantilla de textos. Mismo JSON que pide lg_tx_generate().
const LG_TX_PROMPT =
    "Sos redactor de landings de promociones para {brand}.\n" .
    "Con el texto de una promo devolvés SOLO los textos de la landing, en el JSON pedido.\n\n" .
    "REGLAS:\n" .
    "1. Español rioplatense con voseo (\"sumá\", \"aprovechá\", \"participá\").\n" .
    "2. titulo_1 + titulo_2 forman el título del hero, corto (2 a 6 palabras en total). titulo_2 es la parte que se destaca\n" .
    "   (el producto, la marca o el premio). Ej: titulo_1 \"Semana de\", titulo_2 \"descuentos\".\n" .
    "3. subtitulo: una o dos frases persuasivas (máx. 30 palabras) que digan qué hay que hacer y qué se gana.\n" .
    "   Envolvé el valor clave (monto, porcentaje, cantidad) en <strong>. Sin otras etiquetas.\n" .
    "4. cta_texto: 1 a 3 palabras (ej: \"Quiero participar\", \"Ver más\", \"Sumate\"). cta_url: la URL del botón si el texto la trae; si no, vacío.\n" .
    "5. descripcion: una frase de hasta 160 caracteres para el meta description.\n" .
    "6. secciones: los términos y condiciones en desplegables, en este orden cuando la info exista:\n" .
    "   \"¿Cómo funciona esta promoción?\", \"Vigencia de la Promoción\", \"Productos participantes\", \"Beneficios y premios\",\n" .
    "   \"Condiciones de Participación\", \"Aspectos Legales y Finales\".\n" .
    "   Usá otros títulos si la promo lo pide; no inventes secciones sin información.\n" .
    "7. html de cada sección: solo <p>, <ul><li>, <strong>, <br> y <table>. Listas para enumeraciones (productos, premios, requisitos);\n" .
    "   <table> con <tr><th>/<td> solo para tablas de premios o tramos (2 o 3 columnas, primera fila con <th>);\n" .
    "   en ítems tipo \"Etiqueta: detalle\" poné la etiqueta en <strong>.\n" .
    "8. halloween: true solo si la promo es de Halloween (activa un efecto de murciélagos); si no, false.\n" .
    "9. No inventes datos: fechas, montos, productos y condiciones salen del texto. Conservá todo lo legal del original.";

function lg_tx_generate(string $raw, string $platform): string {
    $info = lg_platform_info($platform);
    $str = ['type' => 'STRING'];
    $schema = [
        'type' => 'OBJECT',
        'properties' => [
            'titulo_1'    => $str,
            'titulo_2'    => $str,
            'subtitulo'   => $str,
            'cta_texto'   => $str,
            'cta_url'     => $str,
            'descripcion' => $str,
            'halloween'   => ['type' => 'BOOLEAN'],
            'secciones'   => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT',
                'properties' => ['titulo' => $str, 'html' => $str], 'required' => ['titulo', 'html']]],
        ],
        'required' => ['titulo_1', 'titulo_2', 'subtitulo', 'cta_texto', 'secciones'],
    ];
    // El prompt puede venir del paquete de marcas ({brand} y {platform} se reemplazan); el de acá es neutro
    $system = strtr((string)lg_cfg('prompt_textos', LG_TX_PROMPT), ['{brand}' => $info['brand'], '{platform}' => $platform]);
    if (g7_claude_ready()) {
        // Mismo pedido con salida estructurada: Claude devuelve siempre JSON válido con este esquema
        $s = ['type' => 'string'];
        $d = g7_claude_json($system, "Texto de la promo:\n\n" . $raw, [
            'type' => 'object',
            'properties' => [
                'titulo_1' => $s, 'titulo_2' => $s, 'subtitulo' => $s, 'cta_texto' => $s, 'cta_url' => $s, 'descripcion' => $s,
                'halloween' => ['type' => 'boolean'],
                'secciones' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['titulo' => $s, 'html' => $s], 'required' => ['titulo', 'html'], 'additionalProperties' => false]],
            ],
            'required' => ['titulo_1', 'titulo_2', 'subtitulo', 'cta_texto', 'cta_url', 'descripcion', 'halloween', 'secciones'],
            'additionalProperties' => false,
        ]);
    } else {
        $out = lg_gemini($system, "Texto de la promo:\n\n" . $raw, $schema);
        $out = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($out));
        $d = json_decode($out, true);
    }
    if (!is_array($d) || empty($d['secciones'])) throw new RuntimeException('La IA no devolvió los textos en el formato esperado. Probá de nuevo.');
    return lg_tx_build($d, $platform);
}
