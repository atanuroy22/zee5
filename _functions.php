<?php
//=============================================================================//
// FOR EDUCATION PURPOSE ONLY. Don't Sell this Script, This is 100% Free.
// Join Community https://t.me/ygxworld, https://t.me/ygx_chat
//=============================================================================//

// CDN host constants
define('CDN_AKAMAI', 'z5ak-cmaflive.zee5.com');
define('CDN_CLOUDFRONT', 'z5live-cf.zee5.com');
// Other hosts (e.g. aasthaott.akamaized.net) are open — no token needed.

// Cache TTLs (seconds)
define('TTL_PLATFORM_TOKEN', 1800); // 30 min
define('TTL_HDNTL', 43200); // 12 hour – Akamai token, shared across all Akamai channels
define('TTL_CF_URL', 7200); // 2 hour – CloudFront signed URL per channel

/* ─────────────────────────────────────────────
   Helpers
   ───────────────────────────────────────────── */

function ensureTmpDir() {
    if (!file_exists('tmp')) { mkdir('tmp', 0755, true); }
}

function cacheGet($file, $ttl) {
    ensureTmpDir();
    $path = "tmp/$file";
    if (file_exists($path) && (time() - filemtime($path) < $ttl)) {
        return file_get_contents($path);
    }
    return null;
}

function cachePut($file, $data) {
    ensureTmpDir();
    file_put_contents("tmp/$file", $data);
}

function generateDDToken() {
    return base64_encode(json_encode([
        'schema_version' => '1',
        'os_name' => 'N/A',
        'os_version' => 'N/A',
        'platform_name' => 'Chrome',
        'platform_version' => '122',
        'device_name' => '',
        'app_name' => 'Web',
        'app_version' => '2.52.31',
        'player_capabilities' => [
            'audio_channel' => ['STEREO'],
            'video_codec' => ['H264'],
            'container' => ['MP4', 'TS'],
            'package' => ['DASH', 'HLS'],
            'resolution' => ['240p', 'SD', 'HD', 'FHD'],
            'dynamic_range' => ['SDR']
        ],
        'security_capabilities' => [
            'encryption' => ['WIDEVINE_AES_CTR'],
            'widevine_security_level' => ['L3'],
            'hdcp_version' => ['HDCP_V1', 'HDCP_V2', 'HDCP_V2_1', 'HDCP_V2_2']
        ]
    ]));
}

function generateGuestToken() {
    $bin = bin2hex(random_bytes(16));
    return substr($bin, 0, 8) . '-' .
           substr($bin, 8, 4) . '-' .
           substr($bin, 12, 4) . '-' .
           substr($bin, 16, 4) . '-' .
           substr($bin, 20);
}

/* ─────────────────────────────────────────────
   ZEE5 Platform Token (shared, cached 30 min)
   ───────────────────────────────────────────── */

function fetchPlatformToken() {
    $cached = cacheGet('platform_token.tmp', TTL_PLATFORM_TOKEN);
    if ($cached) return $cached;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://www.zee5.com/live-tv/zee-tv/0-9-zeetv',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
        ]
    ]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode !== 200) {
        exit("Error: Could not fetch platform token. Your server IP may be blocked by ZEE5.");
    }

    preg_match('/"gwapiPlatformToken"\s*:\s*"([^"]+)"/', $response, $matches);
    $token = $matches[1] ?? '';
    if (!$token) {
        exit("Error: Platform token not found in ZEE5 page response.");
    }

    cachePut('platform_token.tmp', $token);
    return $token;
}

/* ─────────────────────────────────────────────
   ZEE5 Playback API — fetch signed stream URL
   for a specific channel ID
   ───────────────────────────────────────────── */

function fetchSignedStreamUrl($channelId) {
    $guestToken = generateGuestToken();
    $platformToken = fetchPlatformToken();

    $apiUrl = 'https://spapi.zee5.com/singlePlayback/getDetails/secure'
        . '?channel_id=' . urlencode($channelId)
        . '&device_id=' . urlencode($guestToken)
        . '&platform_name=desktop_web'
        . '&translation=en'
        . '&user_language=en,hi,te,ta,mr,bn,kn,ml'
        . '&country=IN'
        . '&state='
        . '&app_version=4.24.0'
        . '&user_type=guest'
        . '&check_parental_control=false';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'content-type: application/json',
            'origin: https://www.zee5.com',
            'referer: https://www.zee5.com/',
            'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'x-access-token' => $platformToken,
            'X-Z5-Guest-Token' => $guestToken,
            'x-dd-token' => generateDDToken(),
        ]),
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response || $httpcode !== 200) {
        return null; // API blocked or error — caller will fall back to stored URL
    }

    $data = json_decode($response, true);
    if (isset($data['keyOsDetails']['video_token'])) {
        $url = $data['keyOsDetails']['video_token'];
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }
    return null;
}

/* ─────────────────────────────────────────────
   Akamai hdntl token
   Shared across ALL z5ak-cmaflive.zee5.com
   channels. Cached 12 h per User-Agent.
   ───────────────────────────────────────────── */

function getAkamaiHdntlToken($userAgent) {
    $cacheKey = 'hdntl_' . md5($userAgent) . '.tmp';
    $cached = cacheGet($cacheKey, TTL_HDNTL);
    if ($cached) return $cached;

    // Use several reliable Akamai channels as bootstrap candidates
    $bootstrapChannels = ['0-9-zeetv', '0-9-zeenews', '0-9-aajtak', '0-9-wion'];
    $signedUrl = null;
    foreach ($bootstrapChannels as $cid) {
        $signedUrl = fetchSignedStreamUrl($cid);
        if ($signedUrl && parse_url($signedUrl, PHP_URL_HOST) === CDN_AKAMAI) {
            break;
        }
        $signedUrl = null;
    }

    if (!$signedUrl) {
        return null; // API blocked — caller must fall back
    }

    // Follow the signed URL to resolve the final CDN URL containing hdntl token
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $signedUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
    ]);
    $result = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode !== 200) {
        return null;
    }

    if (preg_match('/hdntl=([^\s"&\n]+)/', $result, $matches)) {
        $token = 'hdntl=' . $matches[1];
        cachePut($cacheKey, $token);
        return $token;
    }
    return null;
}

/* ─────────────────────────────────────────────
   CloudFront signed URL
   Fetched per channel from the ZEE5 API and
   cached for 2 h (CF signatures are time-limited)
   ───────────────────────────────────────────── */

function getCloudFrontSignedUrl($channelId) {
    $cacheKey = 'cf_' . md5($channelId) . '.tmp';
    $cached = cacheGet($cacheKey, TTL_CF_URL);
    if ($cached) return $cached;

    $signedUrl = fetchSignedStreamUrl($channelId);
    if ($signedUrl) {
        cachePut($cacheKey, $signedUrl);
        return $signedUrl;
    }
    return null;
}

/* ─────────────────────────────────────────────
   Main entry point
   Returns the final URL to redirect to for
   any channel, regardless of CDN.
   ───────────────────────────────────────────── */

function getStreamRedirectUrl($channelId, $fallbackUrl, $userAgent) {
    $host = parse_url($fallbackUrl, PHP_URL_HOST);

    if ($host === CDN_AKAMAI) {
        $token = getAkamaiHdntlToken($userAgent);
        if ($token) {
            return $fallbackUrl . '?' . $token;
        }
        // hdntl failed — try to get a fresh direct signed URL from the API
        $signedUrl = fetchSignedStreamUrl($channelId);
        return $signedUrl ?: $fallbackUrl;
    }

    if ($host === CDN_CLOUDFRONT) {
        // CloudFront channels require a pre-signed URL from the ZEE5 API
        $signedUrl = getCloudFrontSignedUrl($channelId);
        return $signedUrl ?: $fallbackUrl;
    }

    // Other CDNs (e.g. aasthaott.akamaized.net) — open streams, no auth needed
    return $fallbackUrl;
}

//@yuvraj824
