<?php
/**
 * mailingmaker/brands_inc.php — Versión sin código en línea de g7_brand_js() (access.php), para la CSP estricta (SEC-24 fase 2).
 * Devuelve los datos de marcas en un bloque JSON (no se ejecuta) más brands.js, que hace lo mismo que el script en línea.
 * Vacío si las marcas están apagadas o el usuario ve todas.
 */
function mm_brand_tags(int $uid): string {
    if (!g7_brands_enabled()) return '';
    $scope = g7_brand_scope($uid);
    if ($scope === null) return '';
    $data = [
        'scope' => array_values($scope),
        'unassigned' => g7_setting_on('brand_unassigned_visible'),
        'brands' => array_values(array_map(fn($b) => ['id' => $b['id'], 'name' => $b['name'], 'k' => $b['keywords']], g7_brands())),
    ];
    return '<script type="application/json" id="g7-brands-data">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>'
        . '<script src="/toolz/mailingmaker/brands.js?v=' . (int)@filemtime(__DIR__ . '/brands.js') . '"></script>';
}
