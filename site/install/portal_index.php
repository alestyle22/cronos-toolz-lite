<?php
/**
 * install/portal_index.php — Panel de clientes en una carpeta propia (dominio/clientes/) cuando la raíz del dominio la usa otra
 * página, como una landing. Normalmente no hace falta subirlo: Admin › Portal de clientes › Dirección crea la carpeta solo.
 * Si el hosting no deja crearla, subir este archivo como <carpeta>/index.php (junto a toolz/) y poner la misma carpeta en Dirección.
 */
// G7P_STUB
$t = dirname(__DIR__) . '/toolz';
if (!is_file($t . '/portal/index.php')) { header('Location: /'); exit; }
define('G7P_TOOLZ', $t);
require $t . '/portal/index.php';
