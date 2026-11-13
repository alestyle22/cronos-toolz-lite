<?php
/** jpg_converter.php — WebP a JPG: copia del Convertidor WebP (convierte en el navegador) que saca JPG, con fondo para lo transparente. */
require_once __DIR__ . '/tool_wrapper_helper.php';
g7_wrap_tool(__DIR__ . '/jpgmaker.html', '/toolz/jpg_converter.php', 'WebP a JPG', true);
