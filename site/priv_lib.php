<?php
/**
 * priv_lib.php — Archivos privados que no se pueden leer por web aunque falle el .htaccess (SEC-22, 2026-11-10).
 * Los secretos que antes eran .json o .key en _private/ (integraciones, pasarelas de la tienda, firma de la tienda) ahora son
 * .php que empiezan con "<?php exit; ?>": si alguien los pide por web (nginx, AllowOverride apagado), PHP los ejecuta y no muestra nada.
 * La primera vez que se leen, el archivo viejo se pasa al nuevo y se borra. Doc: toolz/SEGURIDAD_SECRETOS.md
 */
if (!defined('G7_PRIV_GUARD')) define('G7_PRIV_GUARD', "<?php exit; ?>\n");

function g7_priv_decode(string $raw): string {
    return strncmp($raw, G7_PRIV_GUARD, strlen(G7_PRIV_GUARD)) === 0 ? substr($raw, strlen(G7_PRIV_GUARD)) : $raw;
}
function g7_priv_encode(string $data): string { return G7_PRIV_GUARD . $data; }

/** Escribe con la protección, de una vez (temporal + renombrar) y con permisos 0600. */
function g7_priv_write(string $file, string $data): bool {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, g7_priv_encode($data), LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
    return true;
}

/** Si quedó el archivo viejo (legible por web) y todavía no está el nuevo, lo pasa y borra el viejo. Si no puede escribir, deja el viejo. */
function g7_priv_migrate(string $new, string $old): void {
    if (is_file($new) || !is_file($old)) return;
    $raw = @file_get_contents($old);
    if ($raw === false) return;
    if (g7_priv_write($new, $raw)) @unlink($old);
}

/** Contenido del archivo privado (sin la protección), o null si no existe. $old: nombre de antes, para migrarlo. */
function g7_priv_read(string $file, ?string $old = null): ?string {
    if ($old !== null) g7_priv_migrate($file, $old);
    if (!is_file($file)) return $old !== null && is_file($old) ? (string)@file_get_contents($old) : null;   // no se pudo migrar: se sigue usando el viejo
    $r = @file_get_contents($file);
    return $r === false ? null : g7_priv_decode($r);
}
