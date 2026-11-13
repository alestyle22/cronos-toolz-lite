<?php
/**
 * index.php de la raíz del dominio (lo pone el paquete de instalación). La plataforma vive en /toolz/.
 * Sin instalar: lleva al asistente. Con Clientes: muestra el panel de clientes (toolz/portal/). Si no: va a la plataforma.
 */
$t = __DIR__ . '/toolz';
if (!is_file($t . '/_private/site.php')) { header('Location: /toolz/instalar.php', true, 302); exit; }
$s = include $t . '/_private/site.php';
if (is_array($s) && in_array('clientes', (array)($s['funciones'] ?? []), true) && is_file($t . '/portal/index.php')) { define('G7P_TOOLZ', $t); require $t . '/portal/index.php'; exit; }
header('Location: /toolz/', true, 302);
