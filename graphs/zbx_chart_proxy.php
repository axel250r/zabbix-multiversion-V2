<?php
/**
 * zbx_chart_proxy.php
 * Proxy autenticado para obtener el gráfico PNG nativo de Zabbix.
 * Parámetros GET: itemid, from, to, width, height
 */
declare(strict_types=1);
session_start();

if (empty($_SESSION['zbx_auth_ok'])) {
    http_response_code(403);
    exit('Unauthorized');
}

require_once __DIR__ . '/../config.php';

$itemid = isset($_GET['itemid']) && ctype_digit($_GET['itemid']) ? $_GET['itemid'] : null;
// Acepta: "YYYY-MM-DD HH:mm:ss" o "now-Xs"
$from   = isset($_GET['from'])   ? preg_replace('/[^a-zA-Z0-9:.\-\/ ]/', '', rawurldecode($_GET['from'])) : 'now-86400';
$to     = isset($_GET['to'])     ? preg_replace('/[^a-zA-Z0-9:.\-\/ ]/', '', rawurldecode($_GET['to']))   : 'now';
$width  = isset($_GET['width'])  && ctype_digit($_GET['width'])  ? max(20, (int)$_GET['width'])  : 900;
$height = isset($_GET['height']) && ctype_digit($_GET['height']) ? max(20, (int)$_GET['height']) : 300;

if (!$itemid) { http_response_code(400); exit('Missing itemid'); }

$cookieJar = $_SESSION['zbx_cookiejar'] ?? null;
if (!$cookieJar || !file_exists($cookieJar)) {
    http_response_code(403);
    exit('No session cookie');
}

$base = rtrim(ZABBIX_URL, '/');
$q = [
    'from'        => $from,
    'to'          => $to,
    'itemids'     => [$itemid],
    'type'        => 0,
    'profileIdx'  => 'web.item.graph.filter',
    'profileIdx2' => $itemid,
    'width'       => $width,
    'height'      => $height,
];
$url = $base . '/chart.php?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE     => $cookieJar,
    CURLOPT_COOKIEJAR      => $cookieJar,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => 'Mozilla/5.0',
    CURLOPT_SSL_VERIFYPEER => defined('VERIFY_SSL') ? VERIFY_SSL : false,
    CURLOPT_SSL_VERIFYHOST => defined('VERIFY_SSL') && VERIFY_SSL ? 2 : 0,
]);
$png  = curl_exec($ch);
$ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200 || !$png || strpos($ct ?? '', 'image') === false) {
    http_response_code(502);
    exit('Chart unavailable');
}

header('Content-Type: image/png');
header('Cache-Control: no-store');
echo $png;
