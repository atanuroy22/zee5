<?php
//=============================================================================//
// FOR EDUCATION PURPOSE ONLY. Don't Sell this Script, This is 100% Free.
// Join Community https://t.me/ygxworld, https://t.me/ygx_chat
//=============================================================================//
include_once '_functions.php';

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36";

$id = $_GET['id'] ?? null;
if (!$id) {
    http_response_code(400);
    die("Channel id not found in query parameter.");
}

$file      = 'data.json';
$json_data = file_get_contents($file);

if ($json_data === false) {
    http_response_code(500);
    die('data.json file not found.');
}

$data        = json_decode($json_data, true);
$channelData = null;
foreach ($data['data'] as $channel) {
    if ($channel['id'] == $id) {
        $channelData = $channel;
        break;
    }
}

if ($channelData === null) {
    http_response_code(404);
    die('Channel not found.');
}

$redirectUrl = getStreamRedirectUrl($id, $channelData['url'], $userAgent);
header("Location: $redirectUrl");
exit;

//@yuvraj824
