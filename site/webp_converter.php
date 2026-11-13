<?php
/**
 * webp_converter.php — Convertidor WebP.
 * Antes convertía en el servidor: podía perder la transparencia de los PNG, bajaba la calidad hasta 10%,
 * no achicaba medidas y dependía de los límites de subida. Ahora muestra el mismo convertidor que
 * webpmaker.php (convierte en el navegador), que además suma el renombrado por prefijo/sufijo de esta versión.
 * La versión anterior quedó en backups/.
 */
require_once __DIR__ . '/tool_wrapper_helper.php';
g7_wrap_tool(__DIR__ . '/webpmaker2.html', '/toolz/webp_converter.php', 'WebP Converter', true);
