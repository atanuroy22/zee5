<?php
// you can use query based filtering for country and language.
// Usage example for Indian channels with Hindi and English only: playlist.php?country=in&language=hi,en

$jsonFile = 'data.json';
$jsonData = file_get_contents($jsonFile);
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$requestUri = $_SERVER['REQUEST_URI'];
$baseUrl = $protocol . $host . parse_url(str_replace('playlist.php','index.php', $requestUri), PHP_URL_PATH);
$data = json_decode($jsonData, true);

function getUserAgent() {
    $uaFile = 'useragent';
    
    if (file_exists($uaFile)) {
        return trim(file_get_contents($uaFile));
    }
    
    $userAgents = [
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/131.0.0.0",
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15",
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0"
    ];
    
    $selectedUA = $userAgents[array_rand($userAgents)];
    file_put_contents($uaFile, $selectedUA);
    
    return $selectedUA;
}

$countries = !empty($_GET['country']) ? array_map('strtolower', explode(',', $_GET['country'])) : [];
$languages = !empty($_GET['language']) ? array_map('strtolower', explode(',', $_GET['language'])) : [];


header('Content-Type: audio/x-mpegurl');
echo "#EXTM3U\n";
echo "#https://github.com/yuvraj824/zee5\n\n";

$userAgent = getUserAgent();
$seenIds = [];

foreach ($data['data'] as $channel) {
    $id = $channel['id'] ?? '';
    if ($id === '' || isset($seenIds[$id])) continue;

    $country = strtolower($channel['country'] ?? '');
    $language = strtolower($channel['language'] ?? '');
    if (($countries && !in_array($country, $countries)) || ($languages && !in_array($language, $languages))) continue;

    $seenIds[$id] = true;

    $slug      = $channel['slug'] ?? '';
    $country   = $channel['country'] ?? '';
    $chno      = $channel['chno'] ?? '';
    $language  = $channel['language'] ?? '';
    $name      = $channel['name'] ?? '';
    $chanName  = $channel['channel_name'] ?? '';
    $logo      = $channel['logo'] ?? '';
    $genre     = $channel['genre'] ?? '';
    $streamUrl = $baseUrl . '?id=' . $id;
    
    
    echo "#EXTINF:-1 tvg-id=\"$id\" tvg-country=\"$country\" tvg-chno=\"$chno\" tvg-language=\"$language\" tvg-name=\"$name\" tvg-logo=\"$logo\" group-title=\"$genre\", $name\n";
    echo "#KODIPROP:inputstream=inputstream.adaptive\n";
    echo "#KODIPROP:inputstream.adaptive.manifest_type=HLS\n";
    echo "#KODIPROP:inputstream.adaptive.manifest_headers=User-Agent=".urlencode($userAgent)."\n";
    echo "#KODIPROP:inputstream.adaptive.stream_headers=User-Agent=".urlencode($userAgent)."\n";
    echo "#EXTVLCOPT:http-user-agent=$userAgent\n";
    echo "$streamUrl&%7CUser-Agent=$userAgent\n\n";
}
exit;
