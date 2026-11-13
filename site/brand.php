<?php
/**
 * brand.php — Cambia la marca con la que trabaja el usuario (selector de la topbar).
 * POST brand_id (0 = todas sus marcas), back = página a la que volver.
 */
require_once __DIR__ . '/auth.php';
$user = requireLogin();

$id = (int)($_POST['brand_id'] ?? 0);
$allowed = g7_user_brand_ids((int)$user['id']);
if ($id && isset(g7_brands()[$id]) && ($allowed === null || in_array($id, $allowed, true))) {
    $_SESSION['g7_brand_sel'] = $id;
} else {
    unset($_SESSION['g7_brand_sel']);
}

$back = (string)($_POST['back'] ?? '');
if (!preg_match('#^' . preg_quote(G7_BASE, '#') . '[^\s]*$#', $back) || strpos($back, '//') !== false) $back = G7_BASE;
header('Location: ' . $back);
exit;
