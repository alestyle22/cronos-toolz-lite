<?php
/**
 * social/profile_ai_lib.php — Profile Studio, Stage 3: AI with Leonardo (the account loaded in Admin › Inteligencia artificial).
 *  - generate: avatar from a description (+ a style), square.
 *  - transform: the person's photo in another style KEEPING the face (Leonardo v2 'gemini-image-2' / 'nano-banana-2' with the photo as
 *    image reference, strength HIGH: the same models Placas Deportivas uses to edit).
 *  - edit: change clothes, expression, lighting or scene of the photo (same models, with an instruction).
 *  - upscale: better quality and resolution (Universal Upscaler). nobg: background removal with AI (better in hair than the browser one).
 *  - suggest: Claude writes 3 detailed descriptions from an idea (it only suggests: nothing is generated without the person's click).
 *  Jobs are asynchronous: the browser asks for the status; when it is ready the server downloads the images (only from Leonardo)
 *  and keeps them as the person's private images (g7_ss_files, kind 'ai'). Every request is logged in g7_ss_pf_ai (cost included).
 *  NOT included: regenerating only a part (inpainting): Leonardo has it in its own editor, but it was not confirmed in its current API.
 * Doc: toolz/SOCIAL_STUDIO.md (Profile Studio · IA)
 */
require_once __DIR__ . '/profile_lib.php';
require_once dirname(__DIR__) . '/ia_lib.php';

/** Styles: id => [name, category, description for the model]. Categories as the request: realistic, illustrated, gaming, professional. */
const SS_PF_STYLES = [
    'foto-pro'     => ['Fotografía profesional', 'real', 'professional portrait photography, soft studio light, sharp focus, natural skin, shallow depth of field'],
    'cine'         => ['Retrato cinematográfico', 'real', 'cinematic portrait, dramatic film lighting, anamorphic look, color graded, moody atmosphere'],
    'estudio'      => ['Fotografía de estudio', 'real', 'studio headshot, seamless backdrop, three-point lighting, high-end retouching'],
    'cyberpunk'    => ['Cyberpunk', 'real', 'cyberpunk portrait, neon lights, rainy night city, magenta and cyan glow, futuristic details'],
    'futurista'    => ['Futurista', 'real', 'futuristic sci-fi portrait, sleek high-tech outfit, clean holographic lights'],
    'dark'         => ['Dark aesthetic', 'real', 'dark aesthetic portrait, low key lighting, deep shadows, black outfit, moody'],
    'minimal'      => ['Minimalista', 'real', 'minimalist portrait, clean plain background, soft neutral tones, simple composition'],
    'anime'        => ['Anime', 'ilustrado', 'anime style portrait, clean line art, cel shading, vibrant colors, expressive eyes'],
    'manga'        => ['Manga', 'ilustrado', 'black and white manga style portrait, ink lines, screentone shading'],
    'cartoon'      => ['Cartoon', 'ilustrado', 'cartoon style portrait, bold outlines, flat colors, friendly and expressive'],
    '3d'           => ['3D de animación', 'ilustrado', 'stylized 3D animated movie character portrait, soft global illumination, big expressive eyes, smooth shading'],
    'comic'        => ['Cómic', 'ilustrado', 'comic book style portrait, halftone dots, bold ink, dynamic colors'],
    'digital'      => ['Ilustración digital', 'ilustrado', 'digital painting portrait, painterly brush strokes, rich colors, detailed'],
    'acuarela'     => ['Acuarela', 'ilustrado', 'watercolor portrait, soft washes, paper texture, delicate colors'],
    'pixel'        => ['Pixel art', 'ilustrado', 'pixel art portrait, 64x64 retro game style, limited palette, crisp pixels'],
    'gamer'        => ['Avatar gamer', 'gaming', 'gamer avatar, headset, RGB lights, energetic, esports vibe'],
    'esports'      => ['Esports', 'gaming', 'esports team player portrait, jersey, dramatic rim light, bold team colors'],
    'fantasia'     => ['Fantasía', 'gaming', 'epic fantasy hero portrait, magical light, detailed armor or robes, painterly'],
    'rpg'          => ['RPG', 'gaming', 'RPG character portrait, adventurer gear, fantasy game art, detailed'],
    'scifi'        => ['Sci-fi', 'gaming', 'sci-fi game character portrait, space armor, glowing visor details'],
    'neon-gaming'  => ['Neón gaming', 'gaming', 'neon gaming portrait, synthwave colors, glowing grid background'],
    'cyber-warrior' => ['Cyber warrior', 'gaming', 'cyber warrior portrait, futuristic armor, glowing circuits, intense look'],
    'linkedin'     => ['LinkedIn', 'pro', 'professional LinkedIn headshot, business casual, clean light background, friendly confident smile'],
    'ejecutivo'    => ['Ejecutivo', 'pro', 'executive corporate portrait, dark suit, office background softly blurred, confident'],
    'corporativo'  => ['Corporativo', 'pro', 'corporate team headshot, consistent soft light, neutral grey background'],
    'creativo'     => ['Creativo', 'pro', 'creative professional portrait, colorful modern background, relaxed confident pose'],
    'empresarial'  => ['Fotografía empresarial', 'pro', 'business photography portrait, natural window light, modern office'],
];
const SS_PF_STYLE_CATS = ['real' => 'Realistas', 'ilustrado' => 'Ilustrados', 'gaming' => 'Gaming', 'pro' => 'Profesionales'];
const SS_PF_EDIT_MODELS = ['gemini-image-2', 'nano-banana-2'];
const SS_PF_AI_HOUR = 30;   // AI requests per person per hour

// ── Leonardo access (own small wrapper: lets the tests point to a simulator; ia_lib stays as it is) ──
function ss_pfa_base(): string { return rtrim((string)g7_site('ia_leo_base', 'https://cloud.leonardo.ai'), '/'); }
function ss_pfa_test(): bool { return (bool)preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', ss_pfa_base()); }
function ss_pfa_key(): string { return ss_pfa_test() ? (string)g7_site('ia_leo_test_key', '') : (string)g7_ia_cfg()['leonardo']['key']; }
function ss_pfa_ready(): bool { return ss_pfa_key() !== ''; }
function ss_pfa_http(string $method, string $path, ?array $body = null): array {
    $key = ss_pfa_key();
    if ($key === '') ss_fail('Leonardo no está configurado: un administrador tiene que cargar la clave en Admin › Inteligencia artificial.');
    $ch = curl_init(ss_pfa_base() . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'authorization: Bearer ' . $key],
        CURLOPT_PROTOCOLS => ss_pfa_test() ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($raw === false) ss_fail('No se pudo conectar con Leonardo. Probá de nuevo en un rato.', 502);
    $j = json_decode((string)$raw, true);
    if ($code === 401 || $code === 403) ss_fail('La clave de Leonardo no es válida. Revisala en Admin › Inteligencia artificial.', 502);
    if ($code === 402) ss_fail('La cuenta de Leonardo no tiene créditos suficientes.', 402);
    if ($code === 429) ss_fail('Leonardo recibió demasiados pedidos seguidos. Esperá un minuto.', 429);
    if ($code >= 400 || !is_array($j)) ss_fail('Leonardo no aceptó el pedido: ' . mb_substr(is_array($j) ? (string)(g7_leo_find($j, 'message') ?? g7_leo_find($j, 'error') ?? $code) : (string)$code, 0, 240), 502);
    return $j;
}
/** Uploads the bytes of an image of the person to Leonardo as "init image" (presigned upload). Returns its id. */
function ss_pfa_upload_init(string $bin): string {
    $j = ss_pfa_http('POST', '/api/rest/v1/init-image', ['extension' => 'png']);
    $u = $j['uploadInitImage'] ?? [];
    $fields = is_string($u['fields'] ?? null) ? json_decode($u['fields'], true) : ($u['fields'] ?? null);
    if (empty($u['id']) || empty($u['url']) || !is_array($fields)) ss_fail('Leonardo no dio dónde subir la imagen.', 502);
    $tmp = tempnam(sys_get_temp_dir(), 'pfa'); file_put_contents($tmp, $bin);
    $post = $fields; $post['file'] = new CURLFile($tmp, 'image/png', 'imagen.png');
    $ch = curl_init((string)$u['url']);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_PROTOCOLS => ss_pfa_test() ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); @unlink($tmp);
    if ($code < 200 || $code >= 300) ss_fail('No se pudo subir la imagen a Leonardo (' . $code . ').', 502);
    return (string)$u['id'];
}
/** PNG bytes of an image of the person (max. 1536 px: enough for the models and lighter to upload). */
function ss_pfa_png(PDO $db, array $ctx, string $token): string {
    $q = $db->prepare('SELECT * FROM g7_ss_files WHERE token = ? AND pf_user_id = ?'); $q->execute([$token, $ctx['id']]);
    $f = $q->fetch(PDO::FETCH_ASSOC);
    if (!$f || !is_file($p = ss_file_path($f))) ss_fail('Esa imagen ya no está.', 404);
    $im = @imagecreatefromstring((string)file_get_contents($p));
    if (!$im) ss_fail('No se pudo leer la imagen.');
    $w = imagesx($im); $h = imagesy($im); $k = min(1, 1536 / max($w, $h));
    if ($k < 1) { $n = imagecreatetruecolor((int)($w * $k), (int)($h * $k)); imagealphablending($n, false); imagesavealpha($n, true); imagecopyresampled($n, $im, 0, 0, 0, 0, (int)($w * $k), (int)($h * $k), $w, $h); $im = $n; }
    imagesavealpha($im, true); ob_start(); imagepng($im); return (string)ob_get_clean();
}

// ── Requests ─────────────────────────────────────────────
function ss_pfa_style(string $id): ?array { return SS_PF_STYLES[$id] ?? null; }
function ss_pfa_guard(PDO $db, array $ctx): void {
    ss_pf_need($ctx);
    if (function_exists('ss_plan_allows') && !ss_plan_allows('social_profiles_ai')) ss_plan_fail('la IA de Profile Studio');
    $cap = function_exists('lic_cap') ? lic_cap('social_profiles_ai_month') : null;
    if ($cap !== null && ss_pfa_month_used($db) >= (int)$cap) ss_fail('Tu plan incluye ' . (int)$cap . ' pedidos de IA por mes en Profile Studio y ya se usaron todos. Se renuevan el 1.º del mes que viene, o mejorá el plan desde Admin › Licencia.', 403);
    if (!ss_pfa_ready()) ss_fail('Leonardo no está configurado: un administrador tiene que cargar la clave en Admin › Inteligencia artificial.');
    if (!ss_rate_ok($db, 'pfai:' . $ctx['id'], SS_PF_AI_HOUR, 3600)) ss_fail('Pediste muchas imágenes con IA en la última hora: esperá un rato.', 429);
}
/** AI requests of the whole agency this month (the style thumbnails of the admin do not count). */
function ss_pfa_month_used(PDO $db): int {
    return (int)$db->query("SELECT COUNT(*) FROM g7_ss_pf_ai WHERE created_at >= '" . date('Y-m-01') . " 00:00:00' AND op NOT LIKE 'thumb:%'")->fetchColumn();
}
function ss_pfa_log(PDO $db, array $ctx, int $projectId, string $op, string $model, string $job, string $kind, string $prompt, $cost): int {
    $db->prepare('INSERT INTO g7_ss_pf_ai (user_id, project_id, op, model, job, kind, prompt, cost, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$ctx['id'], $projectId ?: null, $op, $model, $job, $kind, mb_substr($prompt, 0, 2000), is_numeric($cost) ? (float)$cost : null, 'PENDING', ss_now()]);
    $id = (int)$db->lastInsertId();   // before the audit, which inserts its own row
    g7_audit('social.pf_ai', $op, ['model' => $model]);
    return $id;
}
/**
 * Starts an AI request. $in: op (generate|transform|edit|upscale|nobg), prompt, style, qty (1-4; 1-2 with a photo), photo_token, project_id.
 * Returns ['id' => request id, 'cost' => credits if Leonardo says them].
 */
function ss_pfa_start(PDO $db, array $ctx, array $in): array {
    ss_pfa_guard($db, $ctx);
    $op = (string)($in['op'] ?? ''); $pid = (int)($in['project_id'] ?? 0);
    if ($pid) ss_pf_row($db, $ctx, $pid);
    $prompt = trim(mb_substr((string)($in['prompt'] ?? ''), 0, 1200));
    $st = ss_pfa_style((string)($in['style'] ?? ''));
    switch ($op) {
        case 'generate': {
            if (mb_strlen($prompt) < 4 && !$st) ss_fail('Describí el avatar que querés (o elegí un estilo).');
            $qty = max(1, min(4, (int)($in['qty'] ?? 2)));
            $full = trim('Square profile picture avatar, centered head and shoulders, ' . ($st ? $st[2] . '. ' : '') . $prompt);
            $model = isset(G7_LEO_MODELS[(string)g7_ia_cfg()['leonardo']['model']]) ? (string)g7_ia_cfg()['leonardo']['model'] : 'lucid-origin';
            $j = ss_pfa_http('POST', '/api/rest/v2/generations', ['model' => $model, 'public' => false, 'parameters' => ['prompt' => $full, 'width' => 1024, 'height' => 1024, 'quantity' => $qty]]);
            $g = g7_leo_find($j, 'generationId'); if (!$g) ss_fail('Leonardo no aceptó el pedido.', 502);
            return ['id' => ss_pfa_log($db, $ctx, $pid, $op, $model, $g, 'gen', $full, g7_leo_find($j, 'apiCreditCost')), 'cost' => g7_leo_find($j, 'apiCreditCost')];
        }
        case 'transform': case 'edit': {
            $tok = (string)($in['photo_token'] ?? '');
            if (!preg_match('/^[a-f0-9]{32}$/', $tok)) ss_fail('Falta la foto.');
            if ($op === 'transform' && !$st) ss_fail('Elegí un estilo.');
            if ($op === 'edit' && mb_strlen($prompt) < 3) ss_fail('Escribí o elegí qué querés cambiar.');
            $qty = max(1, min(2, (int)($in['qty'] ?? 1)));
            $keep = 'Keep exactly the same person: same face, facial features, identity, skin tone and age. ';
            $full = $op === 'transform' ? $keep . 'Turn this photo into a square profile picture in this style: ' . $st[2] . '. ' . $prompt
                                        : $keep . 'Edit only this: ' . $prompt . '. Keep everything else as it is.';
            $model = in_array($in['model'] ?? '', SS_PF_EDIT_MODELS, true) ? (string)$in['model'] : 'gemini-image-2';
            $init = ss_pfa_upload_init(ss_pfa_png($db, $ctx, $tok));
            $j = ss_pfa_http('POST', '/api/rest/v2/generations', ['model' => $model, 'public' => false, 'parameters' => ['prompt' => $full, 'quantity' => $qty, 'width' => 0, 'height' => 0, 'prompt_enhance' => 'OFF',
                'guidances' => ['image_reference' => [['image' => ['id' => $init, 'type' => 'UPLOADED'], 'strength' => 'HIGH']]]]]);
            $g = g7_leo_find($j, 'generationId'); if (!$g) ss_fail('Leonardo no aceptó el pedido.', 502);
            return ['id' => ss_pfa_log($db, $ctx, $pid, $op, $model, $g, 'gen', $full, g7_leo_find($j, 'apiCreditCost')), 'cost' => g7_leo_find($j, 'apiCreditCost')];
        }
        case 'upscale': {
            $tok = (string)($in['photo_token'] ?? ''); if (!preg_match('/^[a-f0-9]{32}$/', $tok)) ss_fail('Falta la foto.');
            $init = ss_pfa_upload_init(ss_pfa_png($db, $ctx, $tok));
            $j = ss_pfa_http('POST', '/api/rest/v1/variations/universal-upscaler', ['initImageId' => $init, 'upscaleMultiplier' => 2, 'ultraUpscaleStyle' => 'REALISTIC', 'creativityStrength' => 2, 'detailContrast' => 5, 'similarity' => 9]);
            $v = $j['universalUpscaler']['id'] ?? g7_leo_find($j, 'id'); if (!$v) ss_fail('Leonardo no aceptó mejorar la imagen.', 502);
            return ['id' => ss_pfa_log($db, $ctx, $pid, $op, 'universal-upscaler', (string)$v, 'var', 'upscale x2', g7_leo_find($j, 'apiCreditCost')), 'cost' => g7_leo_find($j, 'apiCreditCost')];
        }
        case 'nobg': {
            $tok = (string)($in['photo_token'] ?? ''); if (!preg_match('/^[a-f0-9]{32}$/', $tok)) ss_fail('Falta la foto.');
            $init = ss_pfa_upload_init(ss_pfa_png($db, $ctx, $tok));
            $j = ss_pfa_http('POST', '/api/rest/v2/generations', ['model' => 'remove-bg', 'public' => false, 'parameters' => ['size' => 'auto', 'type' => 'auto', 'format' => 'png',
                'guidances' => ['image_reference' => [['image' => ['id' => $init, 'type' => 'UPLOADED']]]]]]);
            $g = g7_leo_find($j, 'generationId'); if (!$g) ss_fail('Leonardo no aceptó quitar el fondo.', 502);
            return ['id' => ss_pfa_log($db, $ctx, $pid, $op, 'remove-bg', $g, 'gen', 'remove background', g7_leo_find($j, 'apiCreditCost')), 'cost' => g7_leo_find($j, 'apiCreditCost')];
        }
    }
    ss_fail('Operación de IA desconocida.');
}
/** Only images from Leonardo (or the simulator in the tests) are downloaded. */
function ss_pfa_url_ok(string $u): bool {
    if (ss_pfa_test()) return str_starts_with($u, ss_pfa_base() . '/');
    $h = strtolower((string)parse_url($u, PHP_URL_HOST));
    return str_starts_with($u, 'https://') && ($h === 'leonardo.ai' || str_ends_with($h, '.leonardo.ai'));
}
function ss_pfa_download(PDO $db, array $ctx, string $url): array {
    if (!ss_pfa_url_ok($url)) ss_fail('Leonardo devolvió una dirección que no es suya: no se descargó.', 502);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => ss_pfa_test() ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS]);
    $bin = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($bin === false || $code !== 200 || strlen($bin) > 30 * 1048576) ss_fail('No se pudo bajar la imagen de Leonardo.', 502);
    $info = @getimagesizefromstring($bin);
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if (!$info || !$ext) ss_fail('Leonardo devolvió algo que no es una imagen.', 502);
    return ss_pf_store_bytes($db, $ctx, $bin, $ext, 'ai', (int)$info[0], (int)$info[1]);
}
/** Status of a request. When it finishes, its images are kept as the person's (once). Returns status + images [{token, url, w, h}]. */
function ss_pfa_status(PDO $db, array $ctx, int $id): array {
    ss_pf_need($ctx);
    $q = $db->prepare('SELECT * FROM g7_ss_pf_ai WHERE id = ? AND user_id = ?'); $q->execute([$id, $ctx['id']]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) ss_fail('Ese pedido no existe.', 404);
    if ($r['status'] === 'COMPLETE') return ['status' => 'COMPLETE', 'images' => json_decode((string)$r['result'], true) ?: [], 'op' => $r['op']];
    if ($r['status'] === 'FAILED') return ['status' => 'FAILED', 'error' => (string)$r['error'], 'op' => $r['op']];
    if (strtotime($r['created_at']) < time() - 900) {   // 15 minutes without an answer: given up
        $db->prepare("UPDATE g7_ss_pf_ai SET status = 'FAILED', error = 'Leonardo no terminó a tiempo.', done_at = ? WHERE id = ?")->execute([ss_now(), $id]);
        return ['status' => 'FAILED', 'error' => 'Leonardo no terminó a tiempo.', 'op' => $r['op']];
    }
    $urls = []; $st = 'PENDING';
    if ($r['kind'] === 'var') {
        $j = ss_pfa_http('GET', '/api/rest/v1/variations/' . rawurlencode($r['job']));
        $st = strtoupper((string)(g7_leo_find($j, 'status') ?? 'PENDING')); if ($u = g7_leo_find($j, 'url')) { $urls = [$u]; if ($st !== 'FAILED') $st = 'COMPLETE'; }
    } else {
        $j = ss_pfa_http('GET', '/api/rest/v1/generations/' . rawurlencode($r['job']));
        $g = $j['generations_by_pk'] ?? $j['generation'] ?? $j;
        $st = strtoupper((string)($g['status'] ?? 'PENDING'));
        foreach ((array)($g['generated_images'] ?? $g['images'] ?? []) as $im) if (!empty($im['url'])) $urls[] = (string)$im['url'];
    }
    if ($st === 'FAILED') {
        $db->prepare("UPDATE g7_ss_pf_ai SET status = 'FAILED', error = 'Leonardo no pudo generar la imagen (puede ser por su filtro de contenido).', done_at = ? WHERE id = ?")->execute([ss_now(), $id]);
        return ['status' => 'FAILED', 'error' => 'Leonardo no pudo generar la imagen (puede ser por su filtro de contenido).', 'op' => $r['op']];
    }
    if ($st !== 'COMPLETE' || !$urls) return ['status' => 'PENDING', 'op' => $r['op']];
    // Lock: only one process downloads the images
    $lk = $db->prepare("UPDATE g7_ss_pf_ai SET status = 'SAVING' WHERE id = ? AND status = 'PENDING'"); $lk->execute([$id]);
    if (!$lk->rowCount()) return ['status' => 'PENDING', 'op' => $r['op']];
    try { $imgs = array_map(fn($u) => ss_pfa_download($db, $ctx, $u), array_slice($urls, 0, 4)); }
    catch (SsError $e) { $db->prepare("UPDATE g7_ss_pf_ai SET status = 'PENDING' WHERE id = ?")->execute([$id]); throw $e; }
    $db->prepare("UPDATE g7_ss_pf_ai SET status = 'COMPLETE', result = ?, done_at = ? WHERE id = ?")->execute([json_encode($imgs), ss_now(), $id]);
    return ['status' => 'COMPLETE', 'images' => $imgs, 'op' => $r['op']];
}
/** Claude: 3 detailed descriptions from an idea (only suggestions). */
function ss_pfa_suggest(PDO $db, array $ctx, string $idea, string $style, string $mode): array {
    ss_pf_need($ctx);
    if (function_exists('ss_plan_allows') && !ss_plan_allows('social_profiles_ai')) ss_plan_fail('la IA de Profile Studio');
    if (!function_exists('g7_claude_ready') || !g7_claude_ready()) ss_fail('Las sugerencias usan Claude, que no está configurado en este sitio (Admin › Inteligencia artificial).');
    if (!ss_rate_ok($db, 'pfsug:' . $ctx['id'], 30, 3600)) ss_fail('Pediste muchas sugerencias seguidas: esperá un rato.', 429);
    $st = ss_pfa_style($style);
    $sys = 'Sos un director de arte que escribe descripciones para generar fotos de perfil con IA. Respondé en castellano rioplatense, claro y concreto. '
         . 'Cada sugerencia es UNA descripción lista para usar, de 1 a 3 oraciones, con encuadre de cabeza y hombros, luz, ropa, expresión y fondo. '
         . 'No inventes datos personales ni nombres reales; no describas a personas famosas; nada sexual ni violento.';
    $user = ($mode === 'edit' ? 'Quiero cambiar esto en mi foto de perfil: ' : 'Quiero una foto de perfil así: ') . mb_substr(trim($idea) ?: 'algo profesional y moderno', 0, 400)
          . ($st ? "\nEstilo elegido: {$st[0]}." : '') . "\nDame 3 opciones distintas.";
    $r = g7_claude_json($sys, $user, ['type' => 'object', 'properties' => ['ideas' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['ideas'], 'additionalProperties' => false], ['maxTokens' => 1500, 'effort' => 'low']);
    return array_values(array_slice(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 600), (array)($r['ideas'] ?? []))), 0, 3));
}
/** Recent AI requests of the person (history, with what each one cost). */
function ss_pfa_recent(PDO $db, array $ctx, int $limit = 30): array {
    ss_pf_need($ctx);
    $q = $db->prepare('SELECT id, op, status, cost, created_at, result FROM g7_ss_pf_ai WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit))); $q->execute([$ctx['id']]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'op' => $r['op'], 'status' => $r['status'], 'cost' => $r['cost'] !== null ? (float)$r['cost'] : null, 'at' => $r['created_at'],
        'images' => json_decode((string)$r['result'], true) ?: []], $q->fetchAll(PDO::FETCH_ASSOC));
}
function ss_pfa_meta(): array {
    $styles = []; foreach (SS_PF_STYLES as $id => [$n, $c]) $styles[] = ['id' => $id, 'name' => $n, 'cat' => $c, 'thumb' => is_file(ss_pfa_thumb_path($id)) ? '/toolz/social/perfiles/estilo.php?id=' . $id . '&v=' . filemtime(ss_pfa_thumb_path($id)) : null];
    return ['ready' => ss_pfa_ready(), 'suggest' => function_exists('g7_claude_ready') && g7_claude_ready(), 'styles' => $styles, 'cats' => SS_PF_STYLE_CATS,
            'plan' => !function_exists('ss_plan_allows') || ss_plan_allows('social_profiles_ai'),
            'month' => ['used' => ss_pfa_month_used(getDB()), 'cap' => function_exists('lic_cap') ? lic_cap('social_profiles_ai_month') : null]];
}

// ── Style thumbnails (an admin generates them once: they spend credits) ──
function ss_pfa_thumb_path(string $id): string { return ss_files_dir() . 'pfstyles/' . preg_replace('/[^a-z0-9-]/', '', $id) . '.jpg'; }
/** Starts the thumbnails that are missing (or all with $redo). Returns how many requests. */
function ss_pfa_thumbs_start(PDO $db, array $ctx, bool $redo = false): array {
    if (!$ctx['admin']) ss_fail('Las miniaturas de los estilos las genera un administrador del sitio.', 403);
    if (!ss_pfa_ready()) ss_fail('Leonardo no está configurado.');
    $n = 0; $cost = 0.0;
    foreach (SS_PF_STYLES as $id => [$name, , $desc]) {
        if (!$redo && is_file(ss_pfa_thumb_path($id))) continue;
        $j = ss_pfa_http('POST', '/api/rest/v2/generations', ['model' => 'lucid-origin', 'public' => false, 'parameters' => ['prompt' => 'Square profile picture, head and shoulders of a friendly young adult, ' . $desc, 'width' => 512, 'height' => 512, 'quantity' => 1]]);
        $g = g7_leo_find($j, 'generationId'); if (!$g) continue;
        $c = g7_leo_find($j, 'apiCreditCost'); if (is_numeric($c)) $cost += (float)$c;
        ss_pfa_log($db, $ctx, 0, 'thumb:' . $id, 'lucid-origin', $g, 'gen', $desc, $c); $n++;
    }
    g7_audit('social.pf_style_thumbs', (string)$n, ['cost' => $cost]);
    return ['started' => $n, 'cost' => $cost];
}
/** Picks up the finished thumbnails. Returns how many are still missing. */
function ss_pfa_thumbs_poll(PDO $db, array $ctx): array {
    if (!$ctx['admin']) ss_fail('Solo un administrador.', 403);
    $q = $db->prepare("SELECT * FROM g7_ss_pf_ai WHERE user_id = ? AND op LIKE 'thumb:%' AND status = 'PENDING'"); $q->execute([$ctx['id']]);
    $done = 0; $pending = 0;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $s = ss_pfa_status($db, $ctx, (int)$r['id']);
        if ($s['status'] === 'COMPLETE' && ($img = $s['images'][0] ?? null)) {
            $f = $db->prepare('SELECT * FROM g7_ss_files WHERE token = ?'); $f->execute([$img['token']]); $row = $f->fetch(PDO::FETCH_ASSOC);
            $im = $row ? @imagecreatefromstring((string)file_get_contents(ss_file_path($row))) : null;
            if ($im) { @mkdir(dirname(ss_pfa_thumb_path('x')), 0755, true); $t = imagecreatetruecolor(256, 256); imagecopyresampled($t, $im, 0, 0, 0, 0, 256, 256, imagesx($im), imagesy($im)); imagejpeg($t, ss_pfa_thumb_path(substr($r['op'], 6)), 86); $done++; }
        } elseif ($s['status'] === 'PENDING') $pending++;
    }
    return ['done' => $done, 'pending' => $pending];
}
