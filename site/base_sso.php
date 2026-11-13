<?php
/**
 * base_sso.php — Entra al panel del sitio base sin otra contraseña. Solo superadmin y solo en el sitio que lo tiene configurado:
 * _private/site.php con 'base_url' (ej. https://cronostoolz.com/base/) y 'base_sso_secret' (la misma clave que 'toolz_sso_secret' del base).
 * Arma un token firmado que vale 2 minutos y una sola vez, y redirige. Lo usa la sección "Sitio base" del Admin (admin_base.php).
 */
require_once __DIR__ . '/auth.php';
$me = requireLogin();
$url = (string)g7_site('base_url', ''); $key = (string)g7_site('base_sso_secret', '');
if (!$url || strlen($key) < 32 || !g7_is_super((int)$me['id'])) { http_response_code(403); exit('No autorizado'); }
$b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$p = $b64(json_encode(['u' => (string)$me['username'], 'exp' => time() + 120, 'n' => bin2hex(random_bytes(12))]));
$tok = $p . '.' . $b64(hash_hmac('sha256', $p, $key, true));
g7_audit('base.sso', 'base', null, (int)$me['id']);
header('Cache-Control: no-store');
header('Location: ' . rtrim($url, '/') . '/?embed=1&sso=' . $tok);
