<?php
error_reporting(0);
if (ob_get_level()) ob_end_clean();
set_time_limit(0);

define("UA", "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:155.0) Gecko/20100101 Firefox/155.0");
define("WIDE", "https://wideiptv.top/");
define("ME", "https://download.ayatv.net/worker/yalla.php"); // <-- À adapter

// === Cache APCu + fichier ===
function cache_get($key) {
    return function_exists('apcu_fetch') ? apcu_fetch($key) : false;
}
function cache_set($key, $val, $ttl = 3600) {
    if (function_exists('apcu_store')) apcu_store($key, $val, $ttl);
}

function httpGet($url, $ref = WIDE, $maxRetries = 2) {
    for ($try = 0; $try < $maxRetries; $try++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 15,
            CURLOPT_TCP_KEEPINTVL => 5,
            CURLOPT_HTTPHEADER => ["User-Agent: ".UA, "Accept: */*", "Referer: ".$ref],
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res !== false && $res !== "") return $res;
        if ($try < $maxRetries - 1) usleep(200000); // 200ms
    }
    return "";
}

function httpPostJson($url, $arr, $maxRetries = 2) {
    for ($try = 0; $try < $maxRetries; $try++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($arr),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_HTTPHEADER => ["User-Agent: ".UA, "Content-Type: application/json", "Referer: ".WIDE],
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res !== false && $res !== "") {
            $dec = json_decode($res, true);
            if (is_array($dec)) return $dec;
        }
        if ($try < $maxRetries - 1) usleep(150000);
    }
    return [];
}

function freshStream($slug) {
    $slug = trim($slug);
    if ($slug === "") return "";
    $cache_key = "rb_" . $slug;

    // 1) APCu (instantané)
    $cached = cache_get($cache_key);
    if ($cached && !empty($cached["url"]) && $cached["exp"] > time() + 60) {
        return $cached["url"];
    }

    // 2) Fichier (fallback)
    $cf = sys_get_temp_dir() . "/rb_" . preg_replace("/[^A-Za-z0-9_\-]/", "", $slug) . ".json";
    if (is_file($cf)) {
        $c = json_decode(@file_get_contents($cf), true);
        if (!empty($c["url"]) && !empty($c["exp"]) && $c["exp"] > time() + 60) {
            cache_set($cache_key, $c, 3600);
            return $c["url"];
        }
        if (!empty($c["url"]) && !empty($c["token"])) {
            // Rafraîchir le token
            $j = httpPostJson("https://wideiptv.top/api/refresh_token.php", [
                "channel" => $slug, "current_token" => $c["token"],
            ]);
            if (!empty($j["success"]) && !empty($j["token"])) {
                $nu = preg_replace("/token=[^&]+/", "token=".$j["token"], $c["url"]);
                $exp = time() + ((int)($j["expires_in"] ?? 3600));
                $newData = ["url"=>$nu, "token"=>$j["token"], "exp"=>$exp];
                @file_put_contents($cf, json_encode($newData));
                cache_set($cache_key, $newData, 3600);
                return $nu;
            }
        }
    }

    // 3) Récupération initiale (lente, une seule fois)
    $html = httpGet("https://wideiptv.top/player/".$slug, "https://cdn.rawabit.site/");
    $url = ""; $tok = "";
    if (preg_match('#streamUrl:\s*"([^"]+)"#', $html, $m)) $url = str_replace("\\/", "/", $m[1]);
    if (preg_match('#currentToken:\s*"([^"]+)"#', $html, $m)) $tok = $m[1];
    if ($url !== "") {
        $exp = time() + 3600;
        $data = ["url"=>$url, "token"=>$tok, "exp"=>$exp];
        @file_put_contents($cf, json_encode($data));
        cache_set($cache_key, $data, 3600);
    }
    return $url;
}

function packU($s) { return rtrim(strtr(base64_encode((string)$s), "+/", "-_"), "="); }
function unpackU($s) {
    $s = str_replace(" ", "+", (string)$s);
    if (stripos($s, "http") === 0) return $s;
    $b = strtr($s, "-_", "+/");
    $pad = strlen($b) % 4;
    if ($pad) $b .= str_repeat("=", 4 - $pad);
    $d = base64_decode($b);
    return ($d !== false && stripos((string)$d, "http") === 0) ? $d : urldecode($s);
}

function absHls($base, $rel) {
    $rel = trim($rel);
    if ($rel === "" || preg_match("#^https?://#i", $rel)) return $rel ?: $base;
    $p = parse_url($base);
    $origin = ($p["scheme"] ?? "https") . "://" . ($p["host"] ?? "");
    $dir = preg_replace("#/[^/]*$#", "/", $p["path"] ?? "/");
    $path = (strpos($rel, "/") === 0) ? $rel : ($dir . $rel);
    $q = (strpos($rel, "?") === false && !empty($p["query"])) ? ("?" . $p["query"]) : "";
    return $origin . $path . $q;
}

// ===== TRAITEMENT =====
$slug = trim((string)($_GET["ch"] ?? ""));
$url = unpackU($_GET["u"] ?? $_GET["url"] ?? "");
if ($slug !== "") $url = freshStream($slug);
if ($url === "" || stripos($url, "http") !== 0) {
    http_response_code(400);
    echo "no url";
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 18,
    CURLOPT_TCP_KEEPALIVE => 1,
    CURLOPT_HTTPHEADER => ["User-Agent: ".UA, "Referer: ".WIDE, "Origin: https://wideiptv.top", "Accept: */*"],
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);
if ($code >= 400 || $body === false || $body === "") {
    http_response_code($code ?: 502);
    echo "cdn " . $code;
    exit;
}

header("Access-Control-Allow-Origin: *");
header("Cache-Control: no-store");

if (stripos($ctype, "mpegurl") !== false || strpos($body, "#EXTM3U") === 0) {
    header("Content-Type: application/vnd.apple.mpegurl");
    $baseUrl = $final ?: $url;
    $out = preg_replace_callback('/^(?!\s*#)(.+)$/m', function($m) use ($baseUrl) {
        $seg = absHls($baseUrl, trim($m[1]));
        return ME . "?u=" . packU($seg);
    }, $body);
    echo $out;
    exit;
}

header("Content-Type: video/MP2T");
echo $body;
