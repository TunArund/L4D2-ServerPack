<?php
// ============================================================
// Steam Workshop API 封装
// ============================================================

/**
 * 格式化单个 steamworkshopdownloader.io API 响应 item
 */
function format_steam_item(array $item): array {
    $tags = [];
    $is_map = false;
    if (isset($item['tags']) && is_array($item['tags'])) {
        foreach ($item['tags'] as $tag) {
            if (strtolower($tag['tag']) === 'campaigns') $is_map = true;
            $tags[] = $tag['tag'];
        }
    }
    if (!isset($item['title_disk_safe']) || $item['title_disk_safe'] === '') {
        $item['title_disk_safe'] = str_replace('.vpk', '', $item['filename']);
    }
    return [
        'app_name'      => $item['app_name'],
        'is_map'        => $is_map,
        'version'       => $item['time_updated'],
        'tags'          => $tags,
        'title'         => $item['title'],
        'disk_safe'     => $item['title_disk_safe'],
        'downlink'      => $item['file_url'],
        'size'          => $item['file_size'],
        'preview_url'   => $item['preview_url'],
        'description'   => $item['file_description'],
        'subscriptions' => $item['subscriptions'],
    ];
}

function fetch_steam_item_by_api($steam_id) {
    $url = 'https://steamworkshopdownloader.io/api/details/file';
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
        'Origin: https://steamworkshopdownloader.io',
        'Referer: https://steamworkshopdownloader.io/',
        'Content-Type: application/x-www-form-urlencoded',
    ];
    $post_fields = '[' . $steam_id . ']';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $post_fields,
        CURLOPT_ENCODING => '',
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($http_code !== 200 || !$response) return false;
    $json = json_decode($response, true);
    if (!is_array($json) || !isset($json[0])) return false;
    return format_steam_item($json[0]);
}

function fetch_steam_items_batch(array $steam_ids): array {
    if (empty($steam_ids)) return [];
    $multi = curl_multi_init();
    $handles = [];
    $url     = 'https://steamworkshopdownloader.io/api/details/file';
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
        'Origin: https://steamworkshopdownloader.io',
        'Referer: https://steamworkshopdownloader.io/',
        'Content-Type: application/x-www-form-urlencoded',
    ];
    foreach ($steam_ids as $steam_id) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => '[' . $steam_id . ']',
            CURLOPT_ENCODING => '', CURLOPT_TIMEOUT => 10,
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[(string)$steam_id] = $ch;
    }
    do { curl_multi_exec($multi, $running); curl_multi_select($multi); } while ($running > 0);
    $results = [];
    foreach ($handles as $steam_id => $ch) {
        $response  = curl_multi_getcontent($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($http_code === 200 && $response) {
            $json = json_decode($response, true);
            $results[$steam_id] = (is_array($json) && isset($json[0])) ? format_steam_item($json[0]) : false;
        } else {
            $results[$steam_id] = false;
        }
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    return $results;
}
