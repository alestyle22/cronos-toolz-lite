<?php
// Guía de Diseño: qué imágenes lleva cada pieza de Smartico. El HTML vive en _private para que no se vea sin login.
require_once __DIR__ . '/../tool_wrapper_helper.php';
g7_wrap_tool(__DIR__ . '/../_private/guias/smartico-imagenes.html', '/toolz/guias/smartico-imagenes.php', 'Mapa de imágenes Smartico');
