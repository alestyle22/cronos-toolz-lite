<?php
/**
 * _update/remote.php — El sitio central le pide algo a este sitio (estado, instalar, volver atrás).
 * Pedidos firmados con la clave de _private/site.php. No carga auth.php: tiene que andar aunque una versión lo haya roto.
 */
require __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');
[$from, $in] = u_request(fn($s) => $s === 'central' && ($k = u_cfg()['secret']) ? $k : null);
// El base ya habló con este sitio (pedido firmado válido): desde ahora el código de conexión no se vuelve a mostrar en el Admin
if (!is_file(U_DIR . '/central_seen')) { if (!is_dir(U_DIR)) @mkdir(U_DIR, 0755, true); @file_put_contents(U_DIR . '/central_seen', date('c')); }
// Instalación nueva: con este primer pedido firmado del base queda activada (auth.php deja de bloquearla)
if (is_file(U_DIR . '/activation_pending')) @unlink(U_DIR . '/activation_pending');
try { echo json_encode(u_dispatch($in), JSON_UNESCAPED_UNICODE); }
catch (Throwable $e) { http_response_code(409); echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE); }
