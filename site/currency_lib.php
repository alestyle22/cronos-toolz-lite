<?php
/**
 * currency_lib.php — Las monedas con las que se cobra en Toolz, en un solo lugar (2026-11-16).
 * Antes cada módulo (Finanzas y pagos, Tienda, Eventos) tenía su propia lista y sus símbolos: al sumar una moneda había que
 * acordarse de cuatro archivos. Ahora PAY_CURRENCIES, TS_CURRENCIES, pay_money, ts_money y ev_money leen de acá.
 * Son constantes (no funciones) porque PAY_CURRENCIES y TS_CURRENCIES se declaran con `const` y necesitan un valor fijo.
 * Doc: toolz/PAGOS.md › Monedas
 */

/** Código ISO 4217 => nombre para mostrar. El orden es el de las listas de las pantallas. */
const G7_CURRENCY_NAMES = [
    'ARS' => 'Peso argentino', 'USD' => 'Dólar', 'EUR' => 'Euro', 'BRL' => 'Real', 'MXN' => 'Peso mexicano', 'CLP' => 'Peso chileno',
    'COP' => 'Peso colombiano', 'PEN' => 'Sol', 'UYU' => 'Peso uruguayo',
    // 2026-11-16: Venezuela (bolívar, para Pago Móvil) y el resto de la región, más CAD y GBP
    'VES' => 'Bolívar', 'BOB' => 'Boliviano', 'PYG' => 'Guaraní', 'GTQ' => 'Quetzal', 'CRC' => 'Colón costarricense',
    'DOP' => 'Peso dominicano', 'HNL' => 'Lempira', 'NIO' => 'Córdoba', 'PAB' => 'Balboa', 'CAD' => 'Dólar canadiense', 'GBP' => 'Libra esterlina',
];
const G7_CURRENCY_CODES = ['ARS', 'USD', 'EUR', 'BRL', 'MXN', 'CLP', 'COP', 'PEN', 'UYU', 'VES', 'BOB', 'PYG', 'GTQ', 'CRC', 'DOP', 'HNL', 'NIO', 'PAB', 'CAD', 'GBP'];

/** Símbolo corto, distinto entre monedas que en su país usan "$" (así un listado con varias monedas no confunde). */
const G7_CURRENCY_SYMBOLS = [
    'ARS' => '$', 'USD' => 'US$', 'EUR' => '€', 'BRL' => 'R$', 'MXN' => 'MX$', 'CLP' => 'CLP$', 'COP' => 'COL$', 'PEN' => 'S/', 'UYU' => '$U',
    'VES' => 'Bs.', 'BOB' => 'Bs', 'PYG' => '₲', 'GTQ' => 'Q', 'CRC' => '₡', 'DOP' => 'RD$', 'HNL' => 'L', 'NIO' => 'C$', 'PAB' => 'B/.', 'CAD' => 'CA$', 'GBP' => '£',
];

/** Monedas que no usan centavos (ISO 4217: 0 decimales). */
const G7_CURRENCY_NO_CENTS = ['CLP', 'PYG'];

function g7_currency_symbol(string $cur): string { return G7_CURRENCY_SYMBOLS[$cur] ?? $cur; }
function g7_currency_decimals(string $cur): int { return in_array($cur, G7_CURRENCY_NO_CENTS, true) ? 0 : 2; }

/**
 * Importe con símbolo, en formato rioplatense (1.234,56).
 * $trimInt: sin decimales cuando el importe es entero (Tienda y Eventos lo muestran así; Finanzas y pagos siempre con centavos).
 */
function g7_money(float $n, string $cur, bool $trimInt = false): string {
    $dec = g7_currency_decimals($cur);
    if ($trimInt && abs($n - round($n)) < 0.005) $dec = 0;
    return g7_currency_symbol($cur) . ' ' . number_format($n, $dec, ',', '.');
}

/** Para las pantallas (JS): símbolo y decimales de cada moneda. */
function g7_currency_meta(): array {
    $o = [];
    foreach (G7_CURRENCY_NAMES as $c => $name) $o[$c] = ['name' => $name, 'sym' => g7_currency_symbol($c), 'dec' => g7_currency_decimals($c)];
    return $o;
}
