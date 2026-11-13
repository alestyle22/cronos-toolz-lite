<?php
/**
 * integraciones/arca.php — Factura electrónica con ARCA (ex AFIP).
 *   WSAA: arma el TRA (loginTicketRequest), lo firma con el certificado y la clave de la agencia (openssl_pkcs7_sign, CMS)
 *         y pide el ticket de acceso (TA: token y sign). El TA dura 12 h y se guarda mientras sea válido.
 *   WSFEv1: FECompUltimoAutorizado (último número del punto de venta y tipo) y FECAESolicitar (pide el CAE).
 * Tipo de comprobante según la condición del emisor: Monotributo o Exento → Factura C (11); Responsable inscripto →
 * Factura A (1) a inscriptos y monotributistas, Factura B (6) al resto.
 * Guarda en g7_client_invoices: cae, cae_due, cbte_tipo, pto_vta, cbte_nro, doc_type y el detalle en fiscal_data.
 * Doc: toolz/INTEGRACIONES.md
 */
require_once __DIR__ . '/lib.php';

const ARCA_URLS = [
    'homologacion' => ['wsaa' => 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms', 'wsfe' => 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx'],
    'produccion'   => ['wsaa' => 'https://wsaa.afip.gov.ar/ws/services/LoginCms', 'wsfe' => 'https://servicios1.afip.gov.ar/wsfev1/service.asmx'],
];
const ARCA_NS = 'http://ar.gov.afip.dif.FEV1/';
const ARCA_TIPOS = [1 => ['A', 'factura_a', 'Factura A'], 6 => ['B', 'factura_b', 'Factura B'], 11 => ['C', 'factura_c', 'Factura C']];
// Condición frente al IVA del receptor (RG 5616): CondicionIVAReceptorId
const ARCA_COND_RECEPTOR = [1 => 'IVA Responsable Inscripto', 4 => 'IVA Sujeto Exento', 5 => 'Consumidor Final', 6 => 'Responsable Monotributo', 7 => 'Sujeto No Categorizado',
    8 => 'Proveedor del Exterior', 9 => 'Cliente del Exterior', 10 => 'IVA Liberado', 13 => 'Monotributista Social', 15 => 'IVA No Alcanzado', 16 => 'Monotributo Trabajador Independiente Promovido'];
const ARCA_ALIC = ['0' => 3, '2.5' => 9, '5' => 8, '10.5' => 4, '21' => 5, '27' => 6];   // alícuota % → Id de AlicIva

function arca_install(PDO $db): void {
    if (g7_cfg('arca_schema') === '1') return;
    foreach (['cbte_tipo SMALLINT NULL', 'pto_vta INT NULL', 'cbte_nro BIGINT NULL'] as $c) $db->exec('ALTER TABLE g7_client_invoices ADD COLUMN IF NOT EXISTS ' . $c);
    g7_cfg_set('arca_schema', '1');
}
function arca_env(): string { $e = (string)int_cfg('arca')['env']; return isset(ARCA_URLS[$e]) ? $e : 'homologacion'; }
function arca_cuit(): string { return preg_replace('/\D/', '', (string)int_cfg('arca')['cuit']); }

// ── WSAA ────────────────────────────────────────────────────
function arca_tra(string $service = 'wsfe', ?int $now = null): string {
    $now = $now ?? time();
    return '<?xml version="1.0" encoding="UTF-8"?><loginTicketRequest version="1.0"><header><uniqueId>' . $now . '</uniqueId>'
        . '<generationTime>' . date('c', $now - 120) . '</generationTime><expirationTime>' . date('c', $now + 600) . '</expirationTime></header>'
        . '<service>' . $service . '</service></loginTicketRequest>';
}
/** Firma el TRA (CMS con el contenido adentro) y devuelve el base64 que va en loginCms. */
function arca_sign_tra(string $tra, string $cert, string $key, string $pass = ''): string {
    $tmp = sys_get_temp_dir(); $in = tempnam($tmp, 'tra'); $out = tempnam($tmp, 'cms');
    try {
        file_put_contents($in, $tra);
        $pk = openssl_pkey_get_private($key, $pass);
        if (!$pk) throw new RuntimeException('No se pudo abrir la clave privada (¿la contraseña es correcta?).');
        if (!openssl_x509_check_private_key($cert, $pk)) throw new RuntimeException('El certificado no corresponde a esta clave privada.');
        if (!openssl_pkcs7_sign($in, $out, $cert, $pk, [], PKCS7_BINARY)) throw new RuntimeException('No se pudo firmar el pedido a ARCA: ' . openssl_error_string());
        $smime = str_replace("\r", '', (string)file_get_contents($out));
        $pos = strpos($smime, "\n\n"); if ($pos === false) throw new RuntimeException('La firma salió vacía.');
        return preg_replace('/\s+/', '', substr($smime, $pos + 2));   // sin las cabeceras MIME: solo el CMS en base64
    } finally { @unlink($in); @unlink($out); }
}
function arca_soap(string $url, string $action, string $body): array {
    $env = '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">' . '<soapenv:Header/><soapenv:Body>' . $body . '</soapenv:Body></soapenv:Envelope>';
    $r = int_http('POST', $url, ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: "' . $action . '"'], $env, 40);
    if ($r['error']) throw new RuntimeException($r['error']);
    $x = arca_xml($r['body']);
    if ($x === null) throw new RuntimeException('ARCA respondió algo que no se puede leer (HTTP ' . $r['status'] . ').');
    $fault = $x->xpath('//*[local-name()="Fault"]');
    if ($fault) { $fs = $fault[0]->xpath('.//*[local-name()="faultstring"]'); throw new RuntimeException('ARCA: ' . trim((string)($fs[0] ?? 'error'))); }
    return [$x, $r];
}
function arca_xml(string $s): ?SimpleXMLElement { $p = libxml_use_internal_errors(true); $x = simplexml_load_string($s, 'SimpleXMLElement', LIBXML_NONET); libxml_use_internal_errors($p); return $x === false ? null : $x; }   // ojo: un SimpleXMLElement sin hijos sin namespace da false con !
function arca_val(SimpleXMLElement $x, string $name): string { $n = $x->xpath('.//*[local-name()="' . $name . '"]'); return $n ? trim((string)$n[0]) : ''; }

/** Ticket de acceso vigente (lo pide de nuevo si venció). Devuelve ['token', 'sign', 'expires']. */
function arca_ta(bool $force = false): array {
    $c = int_cfg('arca'); $ta = (array)($c['ta'] ?? []);
    if (!$force && !empty($ta['token']) && ($ta['env'] ?? '') === arca_env() && strtotime((string)($ta['expires'] ?? '')) > time() + 300) return $ta;
    $cms = arca_sign_tra(arca_tra('wsfe'), (string)$c['cert'], (string)$c['key'], (string)$c['key_pass']);
    [$x] = arca_soap(ARCA_URLS[arca_env()]['wsaa'], '', '<wsaa:loginCms xmlns:wsaa="http://wsaa.view.sua.dvadac.desein.afip.gov"><wsaa:in0>' . $cms . '</wsaa:in0></wsaa:loginCms>');
    $ret = arca_val($x, 'loginCmsReturn');
    $t = $ret !== '' ? arca_xml($ret) : null;
    if ($t === null || arca_val($t, 'token') === '') throw new RuntimeException('ARCA no devolvió el ticket de acceso.');
    $ta = ['token' => arca_val($t, 'token'), 'sign' => arca_val($t, 'sign'), 'expires' => date('Y-m-d H:i:s', strtotime(arca_val($t, 'expirationTime'))), 'env' => arca_env()];
    int_store_update(function ($d) use ($ta) { $d['arca']['ta'] = $ta; return $d; });
    int_log('arca', 'wsaa', true, 'Ticket de acceso nuevo (' . arca_env() . '), vence ' . $ta['expires']);
    return $ta;
}
function arca_auth(): string { $ta = arca_ta(); return '<ar:Auth><ar:Token>' . htmlspecialchars($ta['token'], ENT_XML1) . '</ar:Token><ar:Sign>' . htmlspecialchars($ta['sign'], ENT_XML1) . '</ar:Sign><ar:Cuit>' . arca_cuit() . '</ar:Cuit></ar:Auth>'; }
function arca_wsfe(string $method, string $inner): SimpleXMLElement {
    [$x] = arca_soap(ARCA_URLS[arca_env()]['wsfe'], ARCA_NS . $method, '<ar:' . $method . ' xmlns:ar="' . ARCA_NS . '">' . $inner . '</ar:' . $method . '>');
    return $x;
}
/** Errores de WSFEv1 (Errors/Err) como texto, o ''. */
function arca_errors(SimpleXMLElement $x, string $tag = 'Err'): string {
    $o = []; foreach ($x->xpath('//*[local-name()="' . $tag . '"]') as $e) $o[] = arca_val($e, 'Code') . ' ' . arca_val($e, 'Msg');
    return implode(' · ', array_filter(array_map('trim', $o)));
}

// ── WSFEv1 ─────────────────────────────────────────────────
function arca_dummy(): array {
    $x = arca_wsfe('FEDummy', '');
    return ['app' => arca_val($x, 'AppServer'), 'db' => arca_val($x, 'DbServer'), 'auth' => arca_val($x, 'AuthServer')];
}
function arca_last(int $ptoVta, int $tipo): int {
    $x = arca_wsfe('FECompUltimoAutorizado', arca_auth() . '<ar:PtoVta>' . $ptoVta . '</ar:PtoVta><ar:CbteTipo>' . $tipo . '</ar:CbteTipo>');
    if (($e = arca_errors($x)) !== '') throw new RuntimeException('ARCA: ' . $e);
    return (int)arca_val($x, 'CbteNro');
}
/** Código de moneda de ARCA: PES (pesos), DOL (dólar) y 060 (euro). */
function arca_mon_id(string $cur): string { return ['USD' => 'DOL', 'EUR' => '060'][$cur] ?? 'PES'; }
function arca_cotizacion(string $mon): float {
    $x = arca_wsfe('FEParamGetCotizacion', arca_auth() . '<ar:MonId>' . $mon . '</ar:MonId>');
    $v = (float)arca_val($x, 'MonCotiz'); if ($v <= 0) throw new RuntimeException('ARCA no informó la cotización de ' . $mon . '. ' . arca_errors($x));
    return $v;
}
/** Condición del receptor a partir del texto de la ficha (iva_cond). */
function arca_cond_receptor(string $txt): int {
    $t = mb_strtolower($txt);
    if ($t === '') return 5;
    if (str_contains($t, 'inscript')) return 1;
    if (str_contains($t, 'social')) return 13;
    if (str_contains($t, 'monotrib')) return 6;
    if (str_contains($t, 'exent')) return 4;
    if (str_contains($t, 'no alcanz')) return 15;
    if (str_contains($t, 'exterior')) return 9;
    if (str_contains($t, 'no categ')) return 7;
    return 5;
}
/** Tipo de comprobante: Monotributo/Exento → C; Responsable inscripto → A (a inscriptos y monotributistas) o B. */
function arca_tipo(string $condEmisor, int $condReceptor): int {
    if ($condEmisor !== 'responsable_inscripto') return 11;
    return in_array($condReceptor, [1, 6, 13, 16], true) ? 1 : 6;
}
/** Documento del receptor: CUIT (80), DNI (96) o consumidor final (99, 0). */
function arca_doc(string $raw): array {
    $n = preg_replace('/\D/', '', $raw);
    if (strlen($n) === 11) return [80, $n];
    if (strlen($n) >= 7 && strlen($n) <= 8) return [96, $n];
    return [99, '0'];
}
/** Arma el pedido (FECAEDetRequest) de una factura. Separado para probarlo sin red. */
function arca_request(array $inv, array $client, int $tipo, int $ptoVta, int $nro, string $concepto, float $cotiz = 1.0): array {
    $total = round((float)$inv['amount'], 2);
    if ($total <= 0) throw new RuntimeException('La factura no tiene importe.');
    $cond = arca_cond_receptor((string)($client['iva_cond'] ?? ''));
    [$docTipo, $docNro] = arca_doc((string)($client['cuit'] ?? ''));
    if ($tipo === 1 && $docTipo !== 80) throw new RuntimeException('Para Factura A el cliente necesita CUIT en su ficha.');
    if ($docTipo === 99 && $total >= 10000000 && $tipo === 6) throw new RuntimeException('Por el importe, ARCA pide identificar al consumidor final: carga su CUIT o DNI en la ficha.');
    $fch = date('Ymd', strtotime((string)($inv['issue_date'] ?: 'today')));
    $r = ['Concepto' => (int)$concepto, 'DocTipo' => $docTipo, 'DocNro' => $docNro, 'CbteDesde' => $nro, 'CbteHasta' => $nro, 'CbteFch' => $fch,
        'ImpTotal' => $total, 'ImpTotConc' => 0, 'ImpNeto' => $total, 'ImpOpEx' => 0, 'ImpTrib' => 0, 'ImpIVA' => 0,
        'MonId' => arca_mon_id($inv['currency'] ?? 'ARS'), 'MonCotiz' => arca_mon_id($inv['currency'] ?? 'ARS') === 'PES' ? 1 : $cotiz, 'CondicionIVAReceptorId' => $cond];
    if ((int)$concepto !== 1) {   // servicios: período y vencimiento del pago
        $base = strtotime((string)($inv['issue_date'] ?: 'today'));
        $r['FchServDesde'] = date('Ym01', $base); $r['FchServHasta'] = date('Ymt', $base);
        $due = $inv['due_date'] ? date('Ymd', strtotime((string)$inv['due_date'])) : $fch; $r['FchVtoPago'] = max($due, $fch);
    }
    if ($tipo !== 11) {   // A y B discriminan IVA
        $tax = round((float)($inv['tax_total'] ?? 0), 2);
        if ($tax <= 0) throw new RuntimeException('Para Factura A o B las líneas tienen que tener IVA (por ejemplo 21 %).');
        $neto = round($total - $tax, 2);
        $groups = [];
        foreach ((array)($inv['items'] ?? []) as $it) { $p = (float)($it['tax_pct'] ?? 0); if ($p > 0) { $k = rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.'); $groups[$k] = ($groups[$k] ?? 0) + (float)$it['line_total']; } }
        if (!$groups) throw new RuntimeException('No se encontró la alícuota de IVA de las líneas.');
        foreach (array_keys($groups) as $k) if (!isset(ARCA_ALIC[$k])) throw new RuntimeException("ARCA no tiene la alícuota de IVA $k %.");
        $sum = array_sum($groups); $iva = []; $accB = 0; $accI = 0; $keys = array_keys($groups);
        foreach ($keys as $i => $k) {   // reparte neto e IVA en proporción, el último se lleva el redondeo
            $last = $i === count($keys) - 1;
            $b = $last ? round($neto - $accB, 2) : round($neto * $groups[$k] / $sum, 2); $im = $last ? round($tax - $accI, 2) : round($b * (float)$k / 100, 2);
            $accB += $b; $accI += $im; $iva[] = ['Id' => ARCA_ALIC[$k], 'BaseImp' => $b, 'Importe' => $im];
        }
        $r['ImpNeto'] = $neto; $r['ImpIVA'] = $tax; $r['Iva'] = $iva;
    }
    return $r;
}
function arca_num(float $v): string { return number_format($v, 2, '.', ''); }
function arca_request_xml(array $r, int $tipo, int $ptoVta): string {
    $d = ''; $order = ['Concepto', 'DocTipo', 'DocNro', 'CbteDesde', 'CbteHasta', 'CbteFch', 'ImpTotal', 'ImpTotConc', 'ImpNeto', 'ImpOpEx', 'ImpTrib', 'ImpIVA',
        'FchServDesde', 'FchServHasta', 'FchVtoPago', 'MonId', 'MonCotiz', 'CondicionIVAReceptorId', 'Iva'];   // el orden del WSDL importa
    foreach ($order as $k) {
        if (!array_key_exists($k, $r)) continue; $v = $r[$k];
        if ($k === 'MonCotiz') { $d .= '<ar:MonCotiz>' . rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.') . '</ar:MonCotiz>'; continue; }
        if ($k === 'Iva') { $d .= '<ar:Iva>' . implode('', array_map(fn($a) => '<ar:AlicIva><ar:Id>' . $a['Id'] . '</ar:Id><ar:BaseImp>' . arca_num($a['BaseImp']) . '</ar:BaseImp><ar:Importe>' . arca_num($a['Importe']) . '</ar:Importe></ar:AlicIva>', $v)) . '</ar:Iva>'; continue; }
        $d .= "<ar:$k>" . (is_float($v) || in_array($k, ['ImpTotal', 'ImpTotConc', 'ImpNeto', 'ImpOpEx', 'ImpTrib', 'ImpIVA'], true) ? arca_num((float)$v) : htmlspecialchars((string)$v, ENT_XML1)) . "</ar:$k>";
    }
    return '<ar:FeCAEReq><ar:FeCabReq><ar:CantReg>1</ar:CantReg><ar:PtoVta>' . $ptoVta . '</ar:PtoVta><ar:CbteTipo>' . $tipo . '</ar:CbteTipo></ar:FeCabReq>'
        . '<ar:FeDetReq><ar:FECAEDetRequest>' . $d . '</ar:FECAEDetRequest></ar:FeDetReq></ar:FeCAEReq>';
}

/** Pide el CAE de una factura emitida. Devuelve los datos fiscales guardados. */
function arca_request_cae(PDO $db, int $invoiceId, ?int $uid = null): array {
    if (!int_ready('arca')) throw new RuntimeException('ARCA no está configurado. Cárgalo en Admin › Integraciones.');
    if (!is_file(dirname(__DIR__) . '/facturacion/lib.php')) throw new RuntimeException('Esta instalación no tiene Facturación.');   // edición lite
    require_once dirname(__DIR__) . '/clientes_lib.php'; require_once dirname(__DIR__) . '/facturacion/lib.php';
    fact_install($db); arca_install($db);
    if (!$db->query("SELECT GET_LOCK('arca_cae', 60)")->fetchColumn()) throw new RuntimeException('Hay otro pedido de CAE en curso. Prueba en un minuto.');
    try {
        $inv = fact_get($db, $invoiceId, false);
        if (!$inv) throw new RuntimeException('La factura no existe.');
        if ($inv['state'] !== 'emitida') throw new RuntimeException('Solo se pide CAE de facturas emitidas (no de borradores ni canceladas).');
        if (($inv['cae'] ?? '') !== '') throw new RuntimeException('Esta factura ya tiene CAE.');
        $st = $db->prepare('SELECT * FROM g7_clients WHERE id = ?'); $st->execute([$inv['client_id']]);
        $client = array_merge((array)($st->fetch(PDO::FETCH_ASSOC) ?: []), array_filter((array)($inv['client_snapshot'] ?? []), fn($v) => $v !== ''));
        $c = int_cfg('arca'); $pv = (int)$c['pto_vta'];
        $tipo = arca_tipo((string)$c['condicion'], arca_cond_receptor((string)($client['iva_cond'] ?? '')));
        $mon = arca_mon_id($inv['currency'] ?? 'ARS'); $cot = $mon === 'PES' ? 1.0 : arca_cotizacion($mon);
        $nro = arca_last($pv, $tipo) + 1;
        $req = arca_request($inv, $client, $tipo, $pv, $nro, (string)$c['concepto'], $cot);
        $x = arca_wsfe('FECAESolicitar', arca_auth() . arca_request_xml($req, $tipo, $pv));
        $det = $x->xpath('//*[local-name()="FECAEDetResponse"]'); $det = $det[0] ?? null;
        $res = $det !== null ? arca_val($det, 'Resultado') : arca_val($x, 'Resultado');
        $obs = $det !== null ? arca_errors($det, 'Obs') : ''; $errs = arca_errors($x);
        $cae = $det !== null ? arca_val($det, 'CAE') : '';
        if ($res !== 'A' || $cae === '') {
            $m = trim($errs . ($obs ? ' · ' . $obs : '')) ?: 'rechazada sin detalle';
            int_log('arca', 'cae', false, "Factura $invoiceId rechazada: $m");
            fact_event($db, $invoiceId, $uid, 'arca', 'ARCA rechazó el pedido de CAE', ['error' => $m]);
            throw new RuntimeException('ARCA rechazó la factura: ' . $m);
        }
        $due = arca_val($det, 'CAEFchVto'); $dueSql = preg_match('/^\d{8}$/', $due) ? substr($due, 0, 4) . '-' . substr($due, 4, 2) . '-' . substr($due, 6, 2) : null;
        $fiscal = ['tipo' => ARCA_TIPOS[$tipo][2], 'cbte_tipo' => $tipo, 'pto_vta' => $pv, 'cbte_nro' => $nro, 'numero' => sprintf('%05d-%08d', $pv, $nro),
            'cae' => $cae, 'cae_vto' => $dueSql, 'env' => arca_env(), 'cuit_emisor' => arca_cuit(), 'doc_tipo' => $req['DocTipo'], 'doc_nro' => $req['DocNro'],
            'cond_receptor' => ARCA_COND_RECEPTOR[$req['CondicionIVAReceptorId']] ?? '', 'importe' => $req['ImpTotal'], 'moneda' => $req['MonId'], 'cotizacion' => $req['MonCotiz'],
            'fecha' => $req['CbteFch'], 'obs' => $obs, 'requested_at' => date('Y-m-d H:i:s')];
        $fiscal['qr'] = arca_qr_url($fiscal);
        $db->prepare('UPDATE g7_client_invoices SET cae = ?, cae_due = ?, cbte_tipo = ?, pto_vta = ?, cbte_nro = ?, doc_type = ?, fiscal_data = ? WHERE id = ? AND cae = \'\'')
           ->execute([$cae, $dueSql, $tipo, $pv, $nro, ARCA_TIPOS[$tipo][1], json_encode($fiscal, JSON_UNESCAPED_UNICODE), $invoiceId]);
        fact_event($db, $invoiceId, $uid, 'arca', ARCA_TIPOS[$tipo][2] . ' ' . $fiscal['numero'] . ' · CAE ' . $cae, $fiscal);
        int_log('arca', 'cae', true, "Factura $invoiceId → " . ARCA_TIPOS[$tipo][2] . ' ' . $fiscal['numero'] . " CAE $cae (" . arca_env() . ')');
        return $fiscal;
    } finally { $db->query("SELECT RELEASE_LOCK('arca_cae')"); }
}
/** Probar la conexión: estado de los servidores, ticket de acceso y último número del punto de venta. */
function arca_test(): array {
    $d = arca_dummy();
    if ($d['app'] !== 'OK') return [false, 'Los servidores de ARCA no responden bien (AppServer ' . ($d['app'] ?: '—') . ').'];
    $ta = arca_ta();
    $c = int_cfg('arca'); $tipo = arca_tipo((string)$c['condicion'], 5);
    $last = arca_last((int)$c['pto_vta'], $tipo);
    return [true, 'Conectado a ARCA (' . (arca_env() === 'produccion' ? 'producción' : 'homologación') . '). Ticket válido hasta ' . date('d/m H:i', strtotime($ta['expires'])) . '. Último ' . ARCA_TIPOS[$tipo][2] . ' del punto de venta ' . (int)$c['pto_vta'] . ': ' . $last . '.'];
}
/** Texto del QR de ARCA (RG 4892) para el PDF. */
function arca_qr_url(array $f): string {
    $data = ['ver' => 1, 'fecha' => substr($f['fecha'], 0, 4) . '-' . substr($f['fecha'], 4, 2) . '-' . substr($f['fecha'], 6, 2), 'cuit' => (int)$f['cuit_emisor'], 'ptoVta' => (int)$f['pto_vta'],
        'tipoCmp' => (int)$f['cbte_tipo'], 'nroCmp' => (int)$f['cbte_nro'], 'importe' => (float)$f['importe'], 'moneda' => $f['moneda'], 'ctz' => (float)$f['cotizacion'],
        'tipoDocRec' => (int)$f['doc_tipo'], 'nroDocRec' => (int)$f['doc_nro'], 'tipoCodAut' => 'E', 'codAut' => (int)$f['cae']];
    return 'https://www.afip.gob.ar/fe/qr/?p=' . base64_encode(json_encode($data));
}
