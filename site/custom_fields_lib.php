<?php
/**
 * custom_fields_lib.php — Campos propios (los crea la agencia o los trae el rubro), tipos de cliente y campos fijos ocultos de la ficha.
 *   g7_custom_fields: entity ('client'; después propiedades, productos...), fkey, label, type, options (JSON), grp, position, required, hidden
 *   g7_custom_values: entity, record_id, field_id, value
 *   g7_config.client_types:  [{key, label}]      (sin nada = sin tipos, como siempre)
 *   g7_config.client_hidden: ['cuit', ...]       campos fijos de la ficha que no se muestran
 * Lo usan clientes_api.php, admin_clientes.js (Campos en la ficha), admin_campos.php y rubro_lib.php. Doc: toolz/RUBROS.md
 */
const CF_TYPES = ['text' => 'Texto', 'number' => 'Número', 'date' => 'Fecha', 'select' => 'Lista de opciones', 'bool' => 'Sí / No', 'url' => 'Link', 'money' => 'Moneda'];
const CF_ENTITIES = ['client', 'property'];   // property: características propias de Propiedades
// Campos fijos de la ficha del cliente que se pueden ocultar (el nombre y el estado no)
const CF_CLIENT_BUILTIN = ['contact_name' => 'Persona de contacto', 'email' => 'Email', 'phone' => 'Teléfono', 'whatsapp' => 'WhatsApp',
    'legal_name' => 'Razón social', 'cuit' => 'CUIT', 'iva_cond' => 'Condición frente al IVA', 'address' => 'Domicilio', 'city' => 'Localidad', 'province' => 'Provincia',
    'website' => 'Sitio web', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube',
    'brand_colors' => 'Colores de marca', 'brand_fonts' => 'Tipografías'];

function cf_install(PDO $db): void {
    if ((int)(function_exists('g7_cfg') ? g7_cfg('cf_schema', 0) : 0) >= 1) return;
    $db->exec("CREATE TABLE IF NOT EXISTS g7_custom_fields (
        id INT AUTO_INCREMENT PRIMARY KEY, entity VARCHAR(20) NOT NULL, fkey VARCHAR(40) NOT NULL, label VARCHAR(80) NOT NULL, type VARCHAR(10) NOT NULL,
        options TEXT NULL, grp VARCHAR(40) NOT NULL DEFAULT '', position INT NOT NULL DEFAULT 0, required TINYINT(1) NOT NULL DEFAULT 0, hidden TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL, UNIQUE KEY ent_key (entity, fkey)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_custom_values (
        entity VARCHAR(20) NOT NULL, record_id INT NOT NULL, field_id INT NOT NULL, value VARCHAR(2000) NOT NULL,
        PRIMARY KEY (entity, record_id, field_id), KEY field_id (field_id)
    ) DEFAULT CHARSET=utf8mb4");
    try { $db->exec("ALTER TABLE g7_clients ADD COLUMN IF NOT EXISTS type VARCHAR(30) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}   // sin Clientes todavía: lo agrega cli_install
    if (function_exists('g7_cfg_set')) g7_cfg_set('cf_schema', '1');
}

function cf_slug(string $s): string {
    $s = strtolower(strtr(trim($s), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n', 'Ü' => 'u']));
    return substr(trim(preg_replace('/[^a-z0-9]+/', '_', $s), '_'), 0, 40) ?: 'campo';
}

/** Campos de una entidad, en orden. $all = también los ocultos. */
function cf_fields(PDO $db, string $entity = 'client', bool $all = true): array {
    try { $q = $db->prepare('SELECT * FROM g7_custom_fields WHERE entity = ?' . ($all ? '' : ' AND hidden = 0') . ' ORDER BY position, id'); $q->execute([$entity]); }
    catch (Throwable $e) { return []; }
    return array_map(fn($r) => ['id' => (int)$r['id'], 'key' => $r['fkey'], 'label' => $r['label'], 'type' => $r['type'], 'options' => json_decode((string)$r['options'], true) ?: [],
        'group' => $r['grp'], 'position' => (int)$r['position'], 'required' => (bool)$r['required'], 'hidden' => (bool)$r['hidden']], $q->fetchAll(PDO::FETCH_ASSOC));
}

/** Valor válido según el tipo ('' = vacío) o null si no es válido. */
function cf_value(array $f, $v): ?string {
    if (is_bool($v)) $v = $v ? '1' : '';
    $v = trim((string)$v);
    if ($v === '') return '';
    switch ($f['type']) {
        case 'number': $n = str_replace(',', '.', $v); return is_numeric($n) ? (string)(0 + $n) : null;
        case 'money':   // 1.500,50 / 1,500.50 / 1500.5 / $ 1.500
            $s = preg_replace('/[^\d,.\-]/', '', $v);
            if (strpos($s, ',') !== false && strpos($s, '.') !== false) $s = strrpos($s, ',') > strrpos($s, '.') ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
            elseif (strpos($s, ',') !== false) $s = str_replace(',', '.', $s);
            elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s);
            return is_numeric($s) ? (string)round((float)$s, 2) : null;
        case 'date':   return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
        case 'select': return in_array($v, (array)$f['options'], true) ? $v : null;
        case 'bool':   return in_array(strtolower($v), ['1', 'true', 'si', 'sí', 'yes'], true) ? '1' : (in_array(strtolower($v), ['0', 'false', 'no'], true) ? '' : null);
        case 'url':    return preg_match('#^https?://[^\s]+$#i', $v) && strlen($v) <= 1000 ? $v : null;
        default:       return mb_substr($v, 0, 2000);
    }
}

/** Limpia un campo que manda el editor. Devuelve [campo, error|null]. */
function cf_clean(array $in): array {
    $label = mb_substr(trim((string)($in['label'] ?? '')), 0, 80);
    if ($label === '') return [null, 'El campo necesita un nombre.'];
    $type = isset(CF_TYPES[$in['type'] ?? '']) ? $in['type'] : 'text';
    $opts = [];
    if ($type === 'select') {
        foreach ((array)($in['options'] ?? []) as $o) { $o = mb_substr(trim((string)$o), 0, 80); if ($o !== '' && !in_array($o, $opts, true)) $opts[] = $o; }
        if (!$opts) return [null, 'Una lista necesita al menos una opción.'];
        if (count($opts) > 60) return [null, 'Como máximo 60 opciones.'];
    }
    return [['label' => $label, 'type' => $type, 'options' => $opts, 'group' => mb_substr(trim((string)($in['group'] ?? '')), 0, 40),
        'required' => !empty($in['required']), 'hidden' => !empty($in['hidden'])], null];
}

/** Crea o cambia un campo. $id = 0 crea. La clave no cambia nunca (los valores quedan atados al id). Devuelve el id. */
function cf_save(PDO $db, string $entity, array $in, int $id = 0): int {
    if (!in_array($entity, CF_ENTITIES, true)) throw new RuntimeException('Entidad desconocida.');
    [$f, $err] = cf_clean($in); if ($err) throw new RuntimeException($err);
    if ($id) {
        $db->prepare('UPDATE g7_custom_fields SET label = ?, type = ?, options = ?, grp = ?, required = ?, hidden = ? WHERE id = ? AND entity = ?')
           ->execute([$f['label'], $f['type'], json_encode($f['options'], JSON_UNESCAPED_UNICODE), $f['group'], (int)$f['required'], (int)$f['hidden'], $id, $entity]);
        return $id;
    }
    $existing = array_column(cf_fields($db, $entity), 'key');
    if (count($existing) >= 60) throw new RuntimeException('Como máximo 60 campos propios.');
    $key = cf_slug((string)($in['key'] ?? '') ?: $f['label']); $b = $key; $n = 2;
    while (in_array($key, $existing, true)) $key = $b . '_' . $n++;
    $pos = (int)$db->query('SELECT COALESCE(MAX(position), 0) + 1 FROM g7_custom_fields WHERE entity = ' . $db->quote($entity))->fetchColumn();
    $db->prepare('INSERT INTO g7_custom_fields (entity, fkey, label, type, options, grp, position, required, hidden, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
       ->execute([$entity, $key, $f['label'], $f['type'], json_encode($f['options'], JSON_UNESCAPED_UNICODE), $f['group'], $pos, (int)$f['required'], (int)$f['hidden'], date('Y-m-d H:i:s')]);
    return (int)$db->lastInsertId();
}
/** Agrega el campo si no hay otro con esa clave (lo usa el rubro). Devuelve el id. */
function cf_ensure(PDO $db, string $entity, array $in): int {
    $key = cf_slug((string)($in['key'] ?? $in['label'] ?? ''));
    foreach (cf_fields($db, $entity) as $f) if ($f['key'] === $key) {
        if ($f['hidden']) $db->prepare('UPDATE g7_custom_fields SET hidden = 0 WHERE id = ?')->execute([$f['id']]);   // lo vuelve a mostrar
        return $f['id'];
    }
    return cf_save($db, $entity, ['key' => $key] + $in);
}
/** Borra un campo y sus valores (solo desde Admin › Campos, con confirmación). */
function cf_delete(PDO $db, string $entity, int $id): void {
    $db->prepare('DELETE FROM g7_custom_values WHERE entity = ? AND field_id = ?')->execute([$entity, $id]);
    $db->prepare('DELETE FROM g7_custom_fields WHERE entity = ? AND id = ?')->execute([$entity, $id]);
}
function cf_reorder(PDO $db, string $entity, array $ids): void {
    $u = $db->prepare('UPDATE g7_custom_fields SET position = ? WHERE id = ? AND entity = ?');
    foreach (array_values($ids) as $i => $id) $u->execute([$i + 1, (int)$id, $entity]);
}

/** Valores de un registro: clave => valor. */
function cf_values(PDO $db, string $entity, int $recordId): array {
    try {
        $q = $db->prepare('SELECT f.fkey, v.value FROM g7_custom_values v JOIN g7_custom_fields f ON f.id = v.field_id WHERE v.entity = ? AND v.record_id = ?');
        $q->execute([$entity, $recordId]);
        return $q->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) { return []; }
}
/** Guarda los valores que llegan (clave => valor). Los que no vienen no se tocan. Devuelve el error del primero que no vale, o null. */
function cf_set_values(PDO $db, string $entity, int $recordId, array $vals, bool $checkRequired = false, bool $dry = false): ?string {
    $fields = cf_fields($db, $entity); $clean = [];
    foreach ($fields as $f) {
        if (!array_key_exists($f['key'], $vals)) { if ($checkRequired && $f['required'] && !$f['hidden']) return 'Falta completar "' . $f['label'] . '".'; continue; }
        $v = cf_value($f, $vals[$f['key']]);
        if ($v === null) { $hint = ['number' => ' (tiene que ser un número)', 'money' => ' (tiene que ser un importe)', 'date' => ' (fecha)', 'url' => ' (tiene que empezar con https://)', 'select' => ' (elige una opción de la lista)'][$f['type']] ?? ''; return '"' . $f['label'] . '" no tiene un valor válido' . $hint . '.'; }
        if ($checkRequired && $f['required'] && !$f['hidden'] && $v === '') return 'Falta completar "' . $f['label'] . '".';
        $clean[$f['id']] = $v;
    }
    if ($dry) return null;   // solo validar (antes de crear el registro)
    $del = $db->prepare('DELETE FROM g7_custom_values WHERE entity = ? AND record_id = ? AND field_id = ?');
    $ins = $db->prepare('INSERT INTO g7_custom_values (entity, record_id, field_id, value) VALUES (?,?,?,?)');
    foreach ($clean as $fid => $v) { $del->execute([$entity, $recordId, $fid]); if ($v !== '') $ins->execute([$entity, $recordId, $fid, $v]); }
    return null;
}
function cf_delete_values(PDO $db, string $entity, int $recordId): void {
    try { $db->prepare('DELETE FROM g7_custom_values WHERE entity = ? AND record_id = ?')->execute([$entity, $recordId]); } catch (Throwable $e) {}
}

// ── Tipos de cliente y campos fijos ocultos ─────────────────
function cf_cfg(string $key, $default = []) { return function_exists('g7_cfg_json') ? g7_cfg_json($key, $default) : (function_exists('vocab_cfg') ? vocab_cfg($key, $default) : $default); }
/** [{key, label}] — vacío = este sitio no usa tipos (como siempre). */
function cf_client_types(): array {
    $o = [];
    foreach ((array)cf_cfg('client_types', []) as $t) if (is_array($t) && !empty($t['key'])) $o[] = ['key' => (string)$t['key'], 'label' => (string)($t['label'] ?? $t['key'])];
    return $o;
}
function cf_client_types_clean(array $in): array {
    $o = []; $seen = [];
    foreach ($in as $t) {
        if (!is_array($t)) continue;
        $label = mb_substr(trim((string)($t['label'] ?? '')), 0, 40); if ($label === '') continue;
        $key = cf_slug((string)($t['key'] ?? '') ?: $label); $b = $key; $n = 2;
        while (isset($seen[$key])) $key = $b . '_' . $n++;
        $o[] = ['key' => $key, 'label' => $label]; $seen[$key] = 1;
        if (count($o) >= 12) break;
    }
    return $o;
}
/** Cuántos tipos permite el plan (license_lib: client_types). null = sin tope. */
function cf_types_limit(): ?int {
    if (!function_exists('lic_limit')) return null;
    $v = lic_limit('client_types');
    return $v === null ? null : max(1, (int)$v);
}
function cf_client_hidden(): array { return array_values(array_intersect(array_map('strval', (array)cf_cfg('client_hidden', [])), array_keys(CF_CLIENT_BUILTIN))); }
