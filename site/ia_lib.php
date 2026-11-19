<?php
// ══════════════════════════════════════════════════════════════════
//  Inteligencia artificial de Toolz: Claude (Anthropic) y Leonardo.Ai
//  Las claves y modelos se cargan en Admin › Inteligencia artificial y viven en _private/ia.php
//  (carpeta bloqueada para la web). Nunca se mandan al navegador.
//  Claude usa el SDK oficial de PHP, empaquetado en _private/claude-sdk.phar (el hosting no tiene Composer).
//  Para rearmarlo: composer require anthropic-ai/sdk guzzlehttp/guzzle nyholm/psr7 (platform php 8.2) y
//  empaquetar vendor/ con Phar::buildFromDirectory (ver Docs en toolz/IA.md).
// ══════════════════════════════════════════════════════════════════

const G7_IA_CFG  = __DIR__ . '/_private/ia.php';
const G7_IA_PHAR = __DIR__ . '/_private/claude-sdk.phar';
const G7_CLAUDE_MODELS = [
    'claude-opus-5-5'   => 'Claude Opus 5.5 (el más capaz, recomendado)',
    'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (más rápido y barato)',
    'claude-haiku-5-5'  => 'Claude Haiku 5.5 (el más barato)',
];
// Modelos de imagen de Leonardo (API v2): límites de ancho/alto de cada uno
const G7_LEO_MODELS = [
    // v1 = id del modelo en la API clásica (se usa si la v2 rechaza el pedido)
    'lucid-origin' => ['n' => 'Lucid Origin (recomendado: nítido, buen texto)', 'min' => 16, 'max' => 3616, 'v1' => '7b592283-e8a7-4c5a-9ba6-d18c31f258b9'],
    'phoenix-v1.0' => ['n' => 'Phoenix 1.0', 'min' => 32, 'max' => 2048, 'v1' => 'de7d3faf-762f-48e0-b3b7-9d0ac3a3fcf3'],
    'flux-dev'     => ['n' => 'FLUX Dev', 'min' => 480, 'max' => 2048, 'v1' => 'b2614463-296c-462a-9586-aafdb8f00e36'],
];

function g7_ia_cfg(): array {
    $c = is_file(G7_IA_CFG) ? (include G7_IA_CFG) : [];
    $c = is_array($c) ? $c : [];
    // Solo en local (config.local.php): clave falsa para probar contra una API simulada. En el servidor no existe.
    if (empty($c['claude']['key']) && getenv('G7_IA_TEST_KEY')) $c['claude']['key'] = getenv('G7_IA_TEST_KEY');
    return [
        'claude'   => array_merge(['key' => '', 'model' => 'claude-opus-5-5'], (array)($c['claude'] ?? [])),
        'leonardo' => array_merge(['key' => '', 'model' => 'lucid-origin'], (array)($c['leonardo'] ?? [])),
        // Bancos de imágenes gratis (Placas › Recursos › Buscar gratis): claves opcionales; Openverse, Iconify y Wikimedia no necesitan
        'stock'    => array_merge(['pexels' => '', 'pixabay' => '', 'unsplash' => ''], (array)($c['stock'] ?? [])),
    ];
}
function g7_ia_save(array $cfg): bool {
    $php = "<?php\n// Claves de IA. Las escribe Admin › Inteligencia artificial; las lee ia_lib.php. No subir a ningún lado.\nreturn " . var_export($cfg, true) . ";\n";
    $ok = @file_put_contents(G7_IA_CFG, $php, LOCK_EX) !== false;
    if ($ok && function_exists('opcache_invalidate')) @opcache_invalidate(G7_IA_CFG, true);
    return $ok;
}
/** Por qué Claude no se puede usar (o '' si está listo). */
function g7_claude_problem(): string {
    if (g7_ia_cfg()['claude']['key'] === '') return 'Claude no está configurado: un administrador tiene que cargar la clave en Admin › Inteligencia artificial.';
    if (PHP_VERSION_ID < 80100) return 'Claude necesita PHP 8.1 o más nuevo en el servidor (hay ' . PHP_VERSION . ').';
    if (!is_file(G7_IA_PHAR)) return 'Falta el paquete de Claude en el servidor (_private/claude-sdk.phar).';
    return '';
}
function g7_claude_ready(): bool { return g7_claude_problem() === ''; }
function g7_leonardo_ready(): bool { return g7_ia_cfg()['leonardo']['key'] !== ''; }

// ── Claude ───────────────────────────────────────────────────────
function g7_claude_client(): \Anthropic\Client {
    if ($p = g7_claude_problem()) throw new RuntimeException($p);
    require_once 'phar://' . G7_IA_PHAR . '/autoload.php';
    return new \Anthropic\Client(apiKey: g7_ia_cfg()['claude']['key']);
}
function g7_claude_model(): string {
    $m = g7_ia_cfg()['claude']['model'];
    return isset(G7_CLAUDE_MODELS[$m]) ? $m : 'claude-opus-5-5';
}
/** Errores de la API en castellano, para mostrarle a quien usa la herramienta. */
function g7_claude_error(Throwable $e): RuntimeException {
    if ($e instanceof RuntimeException && !($e instanceof \Anthropic\Core\Exceptions\APIException)) return $e;
    if ($e instanceof \Anthropic\Core\Exceptions\APIConnectionException) return new RuntimeException('No se pudo conectar con Claude. Probá de nuevo en un rato.');
    if ($e instanceof \Anthropic\Core\Exceptions\APIStatusException) {
        $t = $e->type?->value ?? '';
        $msg = $e->getMessage();
        if ($t === 'authentication_error') return new RuntimeException('La clave de Claude no es válida. Revisala en Admin › Inteligencia artificial.');
        if ($t === 'permission_error') return new RuntimeException('La clave de Claude no tiene permiso para esto.');
        if ($t === 'rate_limit_error') return new RuntimeException('Claude recibió demasiados pedidos seguidos. Esperá un minuto y probá de nuevo.');
        if ($t === 'overloaded_error') return new RuntimeException('Claude está con mucha demanda en este momento. Probá de nuevo en un rato.');
        if (stripos($msg, 'credit balance') !== false) return new RuntimeException('La cuenta de Claude no tiene crédito. Hay que cargar saldo en console.anthropic.com › Billing.');
        return new RuntimeException('Claude respondió con un error: ' . mb_substr($msg, 0, 300));
    }
    return new RuntimeException('Error con Claude: ' . mb_substr($e->getMessage(), 0, 300));
}
/** Opciones comunes: el modelo elegido, con el respaldo del servidor si el modelo rechaza el pedido. */
function g7_claude_common(): array {
    return ['model' => g7_claude_model(), 'betas' => ['server-side-fallback-2026-07-01'], 'fallbacks' => 'default'];
}
function g7_claude_check_stop(?string $stop): void {
    if ($stop === 'refusal') throw new RuntimeException('Claude no quiso responder este pedido. Revisá el texto y probá de otra forma.');
    if ($stop === 'max_tokens') throw new RuntimeException('La respuesta de Claude quedó cortada (demasiado larga). Probá con un texto más corto.');
}
/**
 * Texto libre (respuestas largas, por ejemplo una landing entera): va por streaming para no cortar por tiempo.
 * $opts: maxTokens, effort ('low'|'medium'|'high'), tick (callable sin argumentos, en cada evento del streaming).
 */
function g7_claude_text(string $system, string $user, array $opts = []): string {
    try {
        $client = g7_claude_client();
        $stream = $client->beta->messages->createStream(...g7_claude_common() + [
            'maxTokens' => $opts['maxTokens'] ?? 64000,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
            'outputConfig' => ['effort' => $opts['effort'] ?? 'medium'],
        ]);
        $text = ''; $stop = null;
        $tick = $opts['tick'] ?? null;   // opcional: se llama con cada evento (por ejemplo, para mandar un latido al navegador)
        foreach ($stream as $ev) {
            if ($tick) $tick();
            if (($ev->type ?? '') === 'content_block_delta' && ($ev->delta->type ?? '') === 'text_delta') $text .= $ev->delta->text;
            if (($ev->type ?? '') === 'message_delta') $stop = $ev->delta->stopReason ?? $stop;
        }
    } catch (Throwable $e) { throw g7_claude_error($e); }
    g7_claude_check_stop($stop);
    return $text;
}
/** Respuesta en JSON con el esquema pedido (salida estructurada: siempre válido). */
/** $user: texto, o una lista de bloques (por ejemplo una imagen + texto) para que Claude también vea. */
function g7_claude_json(string $system, string|array $user, array $schema, array $opts = []): array {
    try {
        $m = g7_claude_client()->beta->messages->create(...g7_claude_common() + [
            'maxTokens' => $opts['maxTokens'] ?? 16000,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
            'outputConfig' => ['effort' => $opts['effort'] ?? 'medium', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
        ]);
    } catch (Throwable $e) { throw g7_claude_error($e); }
    g7_claude_check_stop($m->stopReason);
    foreach ($m->content as $b) if ($b->type === 'text') { $d = json_decode($b->text, true); if (is_array($d)) return $d; }
    throw new RuntimeException('Claude no devolvió los datos en el formato esperado. Probá de nuevo.');
}
/**
 * Investigación en la web (búsqueda del lado de Anthropic): devuelve el texto de Claude y las fuentes que usó.
 * Si la búsqueda se pausa (muchas búsquedas seguidas), se continúa una vez.
 */
function g7_claude_research(string $system, string $user, int $maxSearches = 5): array {
    try {
        $client = g7_claude_client();
        $messages = [['role' => 'user', 'content' => $user]];
        $text = ''; $sources = [];
        for ($round = 0; $round < 3; $round++) {
            $m = $client->beta->messages->create(...g7_claude_common() + [
                'maxTokens' => 16000,
                'system' => $system,
                'messages' => $messages,
                'tools' => [['type' => 'web_search_20260209', 'name' => 'web_search', 'maxUses' => $maxSearches]],
                'outputConfig' => ['effort' => 'medium'],
            ]);
            foreach ($m->content as $b) {
                if ($b->type === 'text') {
                    $text .= $b->text;
                    foreach ($b->citations ?? [] as $c) if (!empty($c->url)) $sources[$c->url] = $c->title ?? $c->url;
                } elseif ($b->type === 'web_search_tool_result' && is_array($b->content)) {
                    foreach ($b->content as $r) if (!empty($r->url) && count($sources) < 12) $sources[$r->url] ??= $r->title ?? $r->url;
                }
            }
            if ($m->stopReason !== 'pause_turn') break;
            // Se reenvía el turno pausado tal cual: la API sigue donde quedó
            $messages[] = ['role' => 'assistant', 'content' => json_decode(json_encode($m->content), true)];
        }
    } catch (Throwable $e) { throw g7_claude_error($e); }
    g7_claude_check_stop($m->stopReason === 'pause_turn' ? null : $m->stopReason);
    return ['text' => trim($text), 'sources' => array_map(fn($u, $t) => ['url' => $u, 'title' => $t], array_keys($sources), $sources)];
}
/** Prueba de conexión para el panel: un pedido mínimo. */
function g7_claude_test(): string {
    $t = g7_claude_text('Respondé en una sola palabra.', 'Decí "listo".', ['maxTokens' => 2000, 'effort' => 'low']);
    return trim($t) !== '' ? 'Conectado con ' . G7_CLAUDE_MODELS[g7_claude_model()] . '. Respuesta: ' . mb_substr(trim($t), 0, 40) : 'Conectado, pero la respuesta llegó vacía.';
}

// ── Leonardo.Ai ──────────────────────────────────────────────────
function g7_leo_http(string $method, string $url, ?array $body = null): array {
    $key = g7_ia_cfg()['leonardo']['key'];
    if ($key === '') throw new RuntimeException('Leonardo no está configurado: un administrador tiene que cargar la clave en Admin › Inteligencia artificial.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'authorization: Bearer ' . $key],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($raw === false) throw new RuntimeException('No se pudo conectar con Leonardo: ' . $err);
    $j = json_decode((string)$raw, true);
    if ($code === 401 || $code === 403) throw new RuntimeException('La clave de Leonardo no es válida o no tiene permiso. Revisala en Admin › Inteligencia artificial.');
    if ($code === 402) throw new RuntimeException('La cuenta de Leonardo no tiene créditos suficientes.');
    if ($code === 429) throw new RuntimeException('Leonardo recibió demasiados pedidos seguidos. Esperá un minuto.');
    if ($code >= 400 || !is_array($j)) {
        $msg = is_array($j) ? ($j['error'] ?? $j['message'] ?? json_encode($j)) : mb_substr((string)$raw, 0, 200);
        throw new RuntimeException('Leonardo respondió con un error (' . $code . '): ' . mb_substr(is_string($msg) ? $msg : json_encode($msg), 0, 300));
    }
    return $j;
}
/** Medidas válidas para un modelo: múltiplo de 8 y dentro de sus límites, manteniendo la proporción. */
function g7_leo_size(string $model, int $w, int $h): array {
    $lim = G7_LEO_MODELS[$model] ?? G7_LEO_MODELS['lucid-origin'];
    $k = min(1, $lim['max'] / max($w, $h));
    $w = (int)round($w * $k); $h = (int)round($h * $k);
    if (min($w, $h) < $lim['min']) { $k2 = $lim['min'] / min($w, $h); $w = (int)round($w * $k2); $h = (int)round($h * $k2); }
    $r8 = fn($v) => max($lim['min'], min($lim['max'], (int)(round($v / 8) * 8)));
    return [$r8($w), $r8($h)];
}
/** Pide imágenes (trabajo asíncrono): devuelve el id de la generación para ir consultando.
    Primero la API v2 (model + parameters); si Leonardo la rechaza, la v1 clásica (modelId). Las dos se consultan igual. */
function g7_leo_generate(string $prompt, int $w, int $h, int $qty = 2, string $model = '', string $negative = ''): array {
    $model = isset(G7_LEO_MODELS[$model]) ? $model : g7_ia_cfg()['leonardo']['model'];
    if (!isset(G7_LEO_MODELS[$model])) $model = 'lucid-origin';
    // Lado largo hasta 1536 px: más grande gasta muchos más créditos y Leonardo lo rechaza con más facilidad (la placa lo agranda igual)
    $k = min(1, 1536 / max($w, $h, 1)); [$w, $h] = g7_leo_size($model, (int)round($w * $k), (int)round($h * $k));
    $qty = max(1, min(4, $qty)); $prompt = mb_substr($prompt, 0, 1500);
    $errs = [];
    $p = ['prompt' => $prompt, 'width' => $w, 'height' => $h, 'quantity' => $qty];
    if ($negative !== '' && $model === 'phoenix-v1.0') $p['negative_prompt'] = mb_substr($negative, 0, 1000);
    try {
        $j = g7_leo_http('POST', 'https://cloud.leonardo.ai/api/rest/v2/generations', ['model' => $model, 'parameters' => $p, 'public' => false]);
        if ($id = g7_leo_find($j, 'generationId')) return ['id' => $id, 'width' => $w, 'height' => $h, 'model' => $model, 'cost' => $j['apiCreditCost'] ?? null];
        $errs[] = 'v2: ' . g7_leo_explain($j);
    } catch (Throwable $e) { $errs[] = 'v2: ' . $e->getMessage(); }
    if ($uuid = G7_LEO_MODELS[$model]['v1'] ?? '') {
        $b = ['modelId' => $uuid, 'prompt' => $prompt, 'width' => $w, 'height' => $h, 'num_images' => $qty, 'public' => false];
        if ($model !== 'flux-dev') $b['contrast'] = 3.5;
        if ($negative !== '' && $model === 'phoenix-v1.0') $b['negative_prompt'] = mb_substr($negative, 0, 1000);
        try {
            $j = g7_leo_http('POST', 'https://cloud.leonardo.ai/api/rest/v1/generations', $b);
            if ($id = g7_leo_find($j, 'generationId')) return ['id' => $id, 'width' => $w, 'height' => $h, 'model' => $model, 'cost' => g7_leo_find($j, 'apiCreditCost')];
            $errs[] = 'v1: ' . g7_leo_explain($j);
        } catch (Throwable $e) { $errs[] = 'v1: ' . $e->getMessage(); }
    }
    error_log('[leonardo] ' . implode(' | ', $errs));
    throw new RuntimeException('Leonardo no aceptó el pedido (' . G7_LEO_MODELS[$model]['n'] . ', ' . $w . '×' . $h . '). ' . mb_substr(implode(' · ', $errs), 0, 600)
        . ' — Revisá que la cuenta tenga créditos de API (app.leonardo.ai › API Access) o probá con otro modelo.');
}
/** Texto del error que mandó Leonardo, con los detalles si los hay. */
function g7_leo_explain($j): string {
    $msg = g7_leo_find($j, 'message') ?? g7_leo_find($j, 'error') ?? '';
    $code = g7_leo_find($j, 'code') ?? '';
    return trim(($msg ?: 'respuesta sin número de generación') . ($code ? " [$code]" : '') . ' ' . mb_substr(json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 220));
}
/** Primer valor de texto con esa clave en cualquier nivel de la respuesta. */
function g7_leo_find($j, string $k): ?string {
    if (!is_array($j)) return null;
    if (isset($j[$k]) && is_scalar($j[$k]) && (string)$j[$k] !== '') return (string)$j[$k];
    foreach ($j as $v) if (is_array($v) && ($r = g7_leo_find($v, $k)) !== null) return $r;
    return null;
}
/** Estado de una generación: PENDING / COMPLETE / FAILED y las direcciones de las imágenes. */
function g7_leo_status(string $id): array {
    if (!preg_match('/^[a-zA-Z0-9-]{8,80}$/', $id)) throw new RuntimeException('Generación inválida.');
    $j = g7_leo_http('GET', 'https://cloud.leonardo.ai/api/rest/v1/generations/' . $id);
    $g = $j['generations_by_pk'] ?? $j['generation'] ?? $j;
    $imgs = [];
    foreach ((array)($g['generated_images'] ?? $g['images'] ?? []) as $im) if (!empty($im['url'])) $imgs[] = (string)$im['url'];
    return ['status' => strtoupper((string)($g['status'] ?? 'PENDING')), 'images' => $imgs];
}
/** Prueba de conexión: datos de la cuenta (créditos) si la API los da. */
function g7_leo_test(): string {
    $j = g7_leo_http('GET', 'https://cloud.leonardo.ai/api/rest/v1/me');
    $u = $j['user_details'][0] ?? [];
    $name = $u['user']['username'] ?? '';
    $cred = $u['apiPaidTokens'] ?? $u['apiSubscriptionTokens'] ?? $u['subscriptionTokens'] ?? null;
    return 'Conectado con Leonardo' . ($name ? ' como ' . $name : '') . ($cred !== null ? ' · créditos de API: ' . $cred : '') . '.';
}
