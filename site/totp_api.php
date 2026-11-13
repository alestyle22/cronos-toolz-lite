<?php
/**
 * totp_api.php — Verificación en dos pasos desde Mi perfil › Seguridad (perfil_seguridad.php). Doc: toolz/SEGURIDAD_2FA.md
 * Acciones (JSON por POST, cabecera X-2FA: 1):
 *   state · start (código nuevo para escanear) · confirm {code} (lo activa y devuelve los códigos de respaldo)
 *   recovery_new {code} · disable {password, code}
 *   Solo superadmin: require_set {on} (exigirlo a todos los superadmins) · reset_user {id} (quitárselo a otro que perdió el teléfono)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/totp_lib.php';
require_once __DIR__ . '/pass_lib.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$out = function (array $d, int $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
$me = getCurrentUser();
if (!$me) $out(['error' => 'Tu sesión venció: vuelve a entrar.'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_2FA'] ?? '') !== '1') $out(['error' => 'Pedido inválido.'], 400);
$db = getDB(); g7_totp_install($db);
$uid = (int)$me['id'];
$in = json_decode((string)file_get_contents('php://input'), true) ?: []; $act = (string)($in['action'] ?? '');
$isSuper = g7_super_ok($uid) && ($me['role'] ?? '') === 'admin';
$tries = function () use ($uid, $out) {   // freno a probar códigos desde una sesión abierta
    if (g7_login_locked('2fa-cfg:' . $uid) > 0) $out(['error' => 'Demasiados códigos incorrectos. Prueba de nuevo en 15 minutos.'], 429);
};

$state = function () use ($db, $uid, $isSuper) {
    $r = g7_totp_row($db, $uid);
    $s = ['on' => !empty($r['totp_on']), 'recovery_left' => count(json_decode((string)($r['totp_recovery'] ?? '[]'), true) ?: []),
        'required' => g7_totp_required_for($uid), 'is_super' => $isSuper, 'pass_left' => g7_pass_days_left($db, $uid), 'pass_days' => g7_pass_days()];
    if ($isSuper) {
        $s['scope'] = g7_totp_scope(); $s['scopes'] = G7_TOTP_SCOPES; $s['pass_options'] = G7_PASS_DAYS;
        require_once __DIR__ . '/csp_lib.php';
        $s['csp_until'] = g7_csp_until();
        $s['users'] = $db->query("SELECT id, COALESCE(NULLIF(display_name,''), username) AS name, role, is_super, totp_on FROM g7_users WHERE active = 1 ORDER BY role = 'admin' DESC, name")->fetchAll(PDO::FETCH_ASSOC);
    }
    return $s;
};

try {
    if ($act === 'state') $out($state());

    if ($act === 'start') {
        if (g7_totp_enabled($db, $uid)) $out(['error' => 'Ya tienes la verificación en dos pasos activa.'], 409);
        $secret = g7_totp_new_secret();
        $_SESSION['g7_totp_pending'] = ['secret' => $secret, 'at' => time()];   // no se guarda hasta que confirme con un código
        $acct = (string)($me['username'] ?? ('usuario' . $uid));
        $out(['secret' => $secret, 'uri' => g7_totp_uri($secret, $acct)]);
    }

    if ($act === 'confirm') {
        $tries();
        $p = $_SESSION['g7_totp_pending'] ?? null;
        if (!$p || time() - (int)$p['at'] > 900) $out(['error' => 'Pasó mucho tiempo: vuelve a empezar.'], 409);
        $step = g7_totp_match((string)$p['secret'], (string)($in['code'] ?? ''));
        if ($step === null) { g7_login_fail('2fa-cfg:' . $uid); $out(['error' => 'El código no coincide. Revisa que la hora del teléfono sea la correcta y escribe el código que se ve ahora.'], 400); }
        [$plain, $hashed] = g7_totp_recovery_new();
        $db->prepare('UPDATE g7_users SET totp_secret = ?, totp_on = 1, totp_last = ?, totp_recovery = ? WHERE id = ?')->execute([g7_totp_seal((string)$p['secret']), $step, json_encode($hashed), $uid]);   // cifrado (totp_lib.php)
        unset($_SESSION['g7_totp_pending']);
        g7_audit('auth.2fa_on', 'user:' . $uid, null, $uid);
        $out(['ok' => true, 'recovery' => $plain] + $state());
    }

    if ($act === 'recovery_new') {
        $tries();
        if (!g7_totp_check($db, $uid, (string)($in['code'] ?? ''))) { g7_login_fail('2fa-cfg:' . $uid); $out(['error' => 'El código no es correcto.'], 400); }
        [$plain, $hashed] = g7_totp_recovery_new();
        $db->prepare('UPDATE g7_users SET totp_recovery = ? WHERE id = ?')->execute([json_encode($hashed), $uid]);
        g7_audit('auth.2fa_recovery_new', 'user:' . $uid, null, $uid);
        $out(['ok' => true, 'recovery' => $plain] + $state());
    }

    if ($act === 'disable') {
        $tries();
        if (g7_totp_required_for($uid)) $out(['error' => 'Para los superadmins la verificación en dos pasos es obligatoria en este sitio.'], 403);
        $r = g7_totp_row($db, $uid);
        if (!password_verify((string)($in['password'] ?? ''), (string)($r['password'] ?? ''))) { g7_login_fail('2fa-cfg:' . $uid); $out(['error' => 'La contraseña no es correcta.'], 400); }
        if (!g7_totp_check($db, $uid, (string)($in['code'] ?? ''))) { g7_login_fail('2fa-cfg:' . $uid); $out(['error' => 'El código no es correcto.'], 400); }
        g7_totp_disable($db, $uid);
        g7_audit('auth.2fa_off', 'user:' . $uid, null, $uid);
        $out(['ok' => true] + $state());
    }

    // ── Solo superadmin ──
    if (!$isSuper) $out(['error' => 'Solo el superadmin puede hacer esto.'], 403);

    if ($act === 'require_set') {
        // A quién se le exige: all | admins | super | none (antes: on = solo superadmins)
        $scope = (string)($in['scope'] ?? (!empty($in['on']) ? 'super' : 'none')); if (!isset(G7_TOTP_SCOPES[$scope])) $out(['error' => 'Opción inválida.'], 400);
        $on = $scope !== 'none'; $lower = G7_TOTP_RANK[$scope] < G7_TOTP_RANK[g7_totp_scope()];
        // Solo quien ya la usa puede tocar este ajuste (si no, un superadmin sin el segundo paso la apagaría para saltearla)
        if (!g7_totp_enabled($db, $uid)) $out(['error' => 'Actívala primero en tu cuenta.'], 409);
        if ($lower) { $tries(); if (!g7_totp_check($db, $uid, (string)($in['code'] ?? ''))) { g7_login_fail('2fa-cfg:' . $uid); $out(['error' => 'Para exigirla a menos gente escribe un código de tu app.'], 400); } }
        g7_cfg_set('totp_required', $scope); g7_cfg_set('totp_required_super', in_array($scope, ['super', 'admins', 'all'], true) ? '1' : '0');
        g7_audit('auth.2fa_required', $scope, null, $uid);
        $out(['ok' => true] + $state());
    }
    if ($act === 'pass_policy_set') {   // cada cuánto se renuevan las contraseñas (0 = nunca)
        if (!g7_totp_enabled($db, $uid)) $out(['error' => 'Activa primero la verificación en dos pasos en tu cuenta.'], 409);
        g7_pass_days_set((int)($in['days'] ?? 0), $uid);
        $out(['ok' => true] + $state());
    }

    if ($act === 'reset_user') {
        $id = (int)($in['id'] ?? 0);
        if ($id === $uid) $out(['error' => 'Para tu cuenta usa "Desactivar".'], 400);
        $t = $db->prepare('SELECT id FROM g7_users WHERE id = ?'); $t->execute([$id]);
        if (!$t->fetchColumn()) $out(['error' => 'Esa persona no existe.'], 404);
        g7_totp_disable($db, $id);
        g7_audit('auth.2fa_reset', 'user:' . $id, null, $uid);
        $out(['ok' => true] + $state());
    }

    // CSP de scripts, fase 1: medir 24 h en modo "solo informar" y ver el informe
    if (in_array($act, ['csp_set', 'csp_report', 'csp_clear'], true)) {
        require_once __DIR__ . '/csp_lib.php';
        if ($act === 'csp_set') { $u = g7_csp_set(!empty($in['on'])); g7_audit('security.csp_measure', $u ? 'on' : 'off', null, $uid); $out(['ok' => true] + $state()); }
        if ($act === 'csp_report') $out(['ok' => true, 'report' => g7_csp_summary(80)]);
        g7_csp_clear(); $out(['ok' => true, 'report' => g7_csp_summary(80)]);
    }
    $out(['error' => 'Acción desconocida.'], 400);
} catch (Throwable $e) {
    error_log('[2fa] ' . $e->getMessage());
    $out(['error' => 'No se pudo completar. Prueba de nuevo.'], 500);
}
