<?php
error_reporting(0);

define('API_KEY_FILE', 'getIP_ipInfo_apikey.php');
define('SERVER_LOCATION_CACHE_FILE', 'getIP_serverLocation.php');

require_once 'getIP_util.php';

/* ===================== IP CLASSIFICATION ===================== */

function getLocalOrPrivateIpInfo($ip){
    if ($ip === '::1') return 'localhost IPv6 access';
    if (stripos($ip, 'fe80:') === 0) return 'link-local IPv6 access';
    if (preg_match('/^(fc|fd)[0-9a-f:]+$/i', $ip)) return 'ULA IPv6 access';
    if (strpos($ip, '127.') === 0) return 'localhost IPv4 access';
    if (strpos($ip, '10.') === 0) return 'private IPv4 access';
    if (preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $ip)) return 'private IPv4 access';
    if (strpos($ip, '192.168.') === 0) return 'private IPv4 access';
    if (strpos($ip, '169.254.') === 0) return 'link-local IPv4 access';
    return null;
}

/* ===================== SERVER LOCATION ===================== */

function getServerLocation(){
    if (file_exists(SERVER_LOCATION_CACHE_FILE) && is_readable(SERVER_LOCATION_CACHE_FILE)) {
        require SERVER_LOCATION_CACHE_FILE;
        if (!empty($serverLoc)) return $serverLoc;
    }

    if (!file_exists(API_KEY_FILE)) return null;
    require API_KEY_FILE;
    if (empty($IPINFO_APIKEY)) return null;

    $json = @file_get_contents('https://ipinfo.io/json?token=' . $IPINFO_APIKEY);
    $data = json_decode($json, true);
    if (!isset($data['loc'])) return null;

    $serverLoc = $data['loc'];
    file_put_contents(
        SERVER_LOCATION_CACHE_FILE,
        "<?php\n\n\$serverLoc = '" . addslashes($serverLoc) . "';\n"
    );

    return $serverLoc;
}

/* ===================== DISTANCE ===================== */

function calculateDistance($clientLoc, $serverLoc, $unit){
    list($clat, $clon) = explode(',', $clientLoc);
    list($slat, $slon) = explode(',', $serverLoc);

    $rad = M_PI / 180;
    $dist = acos(
        sin($clat * $rad) * sin($slat * $rad) +
        cos($clat * $rad) * cos($slat * $rad) *
        cos(($clon - $slon) * $rad)
    ) / $rad * 60 * 1.853;

    if ($unit === 'mi') {
        $dist /= 1.609344;
        $dist = round($dist, -1);
        return ($dist < 15 ? '<15' : $dist) . ' mi';
    }

    $dist = round($dist, -1);
    return ($dist < 20 ? '<20' : $dist) . ' km';
}

/* ===================== ISP VIA IPINFO ===================== */

function getIspInfo_ipinfo($ip){
    if (!file_exists(API_KEY_FILE)) return null;
    require API_KEY_FILE;
    if (empty($IPINFO_APIKEY)) return null;

    $json = @file_get_contents("https://ipinfo.io/$ip/json?token=$IPINFO_APIKEY");
    $data = json_decode($json, true);
    if (!is_array($data)) return null;

    $isp = null;
    if (!empty($data['org'])) {
        $isp = preg_replace('/AS\\d+\\s/', '', $data['org']);
    } elseif (!empty($data['asn']['name'])) {
        $isp = $data['asn']['name'];
    }

    return [
        'isp' => $isp,
        'country' => $data['country'] ?? null,
        'loc' => $data['loc'] ?? null,
        'raw' => $data
    ];
}

/* ===================== RESPONSE ===================== */

function sendResponse($ip, $isp=null, $country=null, $distance=null, $raw=null){
    $out = $ip;
    if ($isp) $out .= ' - ' . $isp;
    if ($country) $out .= ', ' . $country;
    if ($distance) $out .= ' (' . $distance . ')';

    echo json_encode([
        'processedString' => $out,
        'rawIspInfo' => $raw ?: ''
    ]);
}

/* ===================== HEADERS ===================== */

header('Content-Type: application/json; charset=utf-8');
if (isset($_GET['cors'])) {
    header('Access-Control-Allow-Origin: *');
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/* ===================== MAIN ===================== */

$ip = getClientIp();

$local = getLocalOrPrivateIpInfo($ip);
if ($local) {
    sendResponse($ip, $local);
    exit;
}

if (!isset($_GET['isp'])) {
    sendResponse($ip);
    exit;
}

$info = getIspInfo_ipinfo($ip);

$distance = null;
if (
    $info &&
    isset($_GET['distance']) &&
    in_array($_GET['distance'], ['km','mi'], true) &&
    !empty($info['loc'])
) {
    $serverLoc = getServerLocation();
    if ($serverLoc) {
        $distance = calculateDistance($info['loc'], $serverLoc, $_GET['distance']);
    }
}

sendResponse(
    $ip,
    $info['isp'] ?? null,
    $info['country'] ?? null,
    $distance,
    $info['raw'] ?? null
);
