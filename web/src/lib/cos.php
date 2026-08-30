<?php
/**
 * Tencent COS (Cloud Object Storage) 原生客户端
 *
 * 使用 HMAC-SHA1 签名算法直接调用 COS REST API，
 * 无需 Composer / SDK 依赖，适配原生 PHP 项目。
 *
 * 参考文档: https://cloud.tencent.com/document/product/436/7749
 */

define('COS_SECRET_ID',  getenv('COS_SECRET_ID')  ?: '');
define('COS_SECRET_KEY', getenv('COS_SECRET_KEY') ?: '');
define('COS_BUCKET',     getenv('COS_BUCKET')     ?: '');
define('COS_REGION',     getenv('COS_REGION')     ?: 'ap-guangzhou');
define('COS_SCHEME',     getenv('COS_SCHEME')     ?: 'https');
// 预签名下载链接有效期（秒），由 api/cos_link.php 生成下载直链时使用
define('COS_PRESIGN_EXPIRE', (int)(getenv('COS_PRESIGN_EXPIRE') ?: 60));

// 独立于 tools.php 的轻量辅助函数（不依赖外部 include）
if (!function_exists('array_error')) {
    function array_error(string $msg): array { return ['success' => false, 'message' => $msg]; }
}
if (!function_exists('array_success')) {
    function array_success($data = []): array { return ['success' => true, 'data' => $data]; }
}

/**
 * 判断 COS 是否已配置
 */
function cos_configured(): bool {
    return COS_SECRET_ID !== '' && COS_SECRET_KEY !== '' && COS_BUCKET !== '';
}

/**
 * 为待同步地图创建 COS 上传任务
 *
 * 查询 version 有变化的地图，为每个文件创建一条 type='upload' 的任务记录。
 * 实际上传由 task-daemon 的 process_next_task() 逐个执行。
 *
 * 同时检查已标记为"已同步"（cos_version = version）的地图：
 * 如果 COS 存储桶中对应的 .vpk 文件不存在（如被意外删除），
 * 也会为其创建重新上传任务，防止数据库与 COS 实际状态不一致。
 *
 * @return array ['created' => int, 'skipped' => int, 'recovered' => int]
 */
function cos_batch_create_tasks(PDO $pdo): array {
    // 1. 查询 version 有变化的地图（常规同步）
    $result = all_maps_pending_cos_sync();
    $pending = $result['success'] ? $result['data'] : [];

    // 2. 查询已同步的地图（完整性检查：COS 上文件可能被意外删除）
    $result = all_maps_cos_synced();
    $synced = $result['success'] ? $result['data'] : [];

    $created   = 0;
    $skipped   = 0;
    $recovered = 0;

    // 合并待上传列表：pending（版本变化）+ synced 中 COS 文件缺失的
    $to_upload = $pending;

    if (!empty($synced) && cos_configured()) {
        // 一次 COS List 请求获取所有已有 .vpk 文件，避免 N 次 HEAD 请求
        $list = cos_list_objects('', '', 1000);
        $cos_keys = [];
        if ($list['success']) {
            foreach ($list['data']['files'] as $file) {
                if (preg_match('/\.vpk$/i', $file['key'])) {
                    $cos_keys[basename($file['key'])] = true;
                }
            }
        }

        // 找出 COS 上不存在的"已同步"地图，加入上传队列
        foreach ($synced as $map) {
            $filename = $map['disk_safe'] . '.vpk';
            if (!isset($cos_keys[$filename])) {
                $to_upload[] = $map;
                $recovered++;
            }
        }
    }

    foreach ($to_upload as $map) {
        $local_path = MAP_DIR . $map['disk_safe'] . '.vpk';
        $cos_key    = $map['disk_safe'] . '.vpk';

        // 检查是否已有同 map 的 waiting/uploading 任务
        $result = task_exists_duplicate($map['id'], 'upload');
        if ($result['success'] && $result['data']) continue;

        if (!file_exists($local_path)) {
            insert_task([
                'type'       => 'upload',
                'map_id'     => $map['id'],
                'src'        => $local_path,
                'dst'        => $cos_key,
                'disk_safe'  => $map['disk_safe'],
                'total_bytes'=> 0,
                'status'     => 'fail',
            ]);
            $skipped++;
            continue;
        }

        $file_size = filesize($local_path) ?: 0;
        insert_task([
            'type'       => 'upload',
            'map_id'     => $map['id'],
            'src'        => $local_path,
            'dst'        => $cos_key,
            'disk_safe'  => $map['disk_safe'],
            'total_bytes'=> $file_size,
            'status'     => 'waiting',
        ]);
        $created++;
    }

    return ['created' => $created, 'skipped' => $skipped, 'recovered' => $recovered];
}

/**
 * 处理单个 COS 上传任务（由 task-daemon 调度，带进度回调）
 *
 * @return array ['success' => bool, 'message' => string]
 */
function process_upload_task(PDO $pdo, array $task): array {
    $task_id    = $task['id'];
    $local_path = $task['src'];
    $cos_key    = $task['dst'];

    update_task_status($task_id, 'uploading');

    if (!file_exists($local_path)) {
        mark_task_fail_zero_size($task_id);
        return array_error("文件不存在: {$local_path}");
    }

    $res = cos_upload_file($local_path, $cos_key, 'application/octet-stream', 3,
        function($processed, $total) use ($task_id) {
            update_task_progress($task_id, $processed, $total);
        }
    );

    if ($res['success']) {
        mark_task_upload_success($task_id);
        // 先查再写，避免 MySQL Error 1093（不能在 UPDATE 子查询中 SELECT 同一张表）
        $result = get_map_version($task['map_id']);
        $version = $result['success'] ? $result['data'] : 0;
        update_map_cos_info($task['map_id'], $res['data']['url'], (int)$version);
        return array_success("上传成功");
    } else {
        update_task_status($task_id, 'fail');
        return array_error($res['message']);
    }
}

// 兼容旧调用名
function cos_batch_upload(PDO $pdo): array {
    $result = cos_batch_create_tasks($pdo);
    return ['uploaded' => 0, 'skipped' => $result['skipped'], 'failed' => 0, 'recovered' => $result['recovered']];
}

/**
 * 删除单个 COS 对象（DELETE Object）
 *
 * @param string $cos_key 对象键，如 "foo.vpk"
 * @return array ['success' => bool, 'message' => string]
 */
function cos_delete_object(string $cos_key): array {
    if (!cos_configured()) {
        return array_error('COS 未配置');
    }

    if (strpos($cos_key, '/') !== 0) {
        $cos_key = '/' . $cos_key;
    }

    $host = cos_host();
    $url  = COS_SCHEME . '://' . $host . $cos_key;
    $date = gmdate('D, d M Y H:i:s \G\M\T');

    $auth = cos_generate_auth('DELETE', $cos_key, '', '', $date);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "Authorization: {$auth}",
            "Date: {$date}",
            "Host: {$host}",
        ],
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err_msg   = curl_error($ch);
    curl_close($ch);

    if ($http_code >= 200 && $http_code < 300) {
        return array_success(['key' => $cos_key, 'http' => $http_code]);
    }

    return array_error("DELETE {$cos_key}: HTTP {$http_code}" . ($err_msg ? " — {$err_msg}" : ''));
}

/**
 * 清理 COS 中无对应活跃地图的孤儿 .vpk 文件
 *
 * 获取所有 active 地图的文件名 → 列出 COS 中所有 .vpk → 删除不在 active 集合中的。
 * 跳过 index.html（目录浏览页本身）。
 *
 * @return array ['deleted' => int, 'failed' => int, 'keys' => string[]]
 */
function cos_cleanup_orphans(PDO $pdo): array {
    if (!cos_configured()) {
        return ['deleted' => 0, 'failed' => 0, 'keys' => []];
    }

    // 1. 获取所有 active 地图的 COS key
    $result = all_active_map_disk_safes();
    $active = [];
    if ($result['success']) {
        foreach ($result['data'] as $row) {
            $active[$row['disk_safe'] . '.vpk'] = true;
        }
    }

    // 2. 列出 COS 中所有 .vpk 文件
    $list = cos_list_objects('', '', 1000);
    if (!$list['success']) {
        return ['deleted' => 0, 'failed' => 0, 'keys' => []];
    }

    $deleted = 0;
    $failed  = 0;
    $keys    = [];

    foreach ($list['data']['files'] as $file) {
        $key = $file['key'];

        // 跳过 index.html 和非 .vpk 文件
        if ($key === 'index.html' || !preg_match('/\.vpk$/i', $key)) {
            continue;
        }

        // 跳过仍有活跃地图对应的文件
        $basename = basename($key);
        if (isset($active[$basename])) {
            continue;
        }

        $res = cos_delete_object($key);
        if ($res['success']) {
            $deleted++;
            $keys[] = $key;
        } else {
            $failed++;
        }
    }

    return ['deleted' => $deleted, 'failed' => $failed, 'keys' => $keys];
}

/**
 * 构建 COS Host
 */
function cos_host(): string {
    return COS_BUCKET . '.cos.' . COS_REGION . '.myqcloud.com';
}

/**
 * 构建对象的公网访问 URL
 *
 * @param string $key 对象键（路径），如 "l4d2-maps/foo.vpk"
 * @return string 完整访问 URL
 */
function cos_object_url(string $key): string {
    return COS_SCHEME . '://' . cos_host() . '/' . ltrim($key, '/');
}

/**
 * 生成 COS 对象的预签名下载 URL（腾讯云 V5 签名，q-sign-algorithm=sha1）
 *
 * 使用永久密钥 + 时效控制，URL 在 COS_PRESIGN_EXPIRE 秒内有效，过期后需重新生成。
 * 仅对 COS 源站域名生效（私有桶下公网直链返回 403，需走此签名直链）。
 *
 * 签名公式（参考 https://cloud.tencent.com/document/product/436/14690）：
 *   KeyTime      = (now-60) . ';' . (now+expires)      // 起点前移容忍时钟偏移
 *   SignKey      = HMAC-SHA1(SecretKey, KeyTime)        // hex 字符串，作下一轮密钥
 *   HttpString   = "get\n{uri}\n\n\n"                   // GET 无自定义头/参数
 *   StringToSign = "sha1\n" + KeyTime + "\n" + SHA1(HttpString) + "\n"
 *   Signature    = HMAC-SHA1(SignKey, StringToSign)     // hex 小写
 *
 * @param string $key     对象键，如 "foo.vpk"
 * @param int    $expires 有效期（秒），默认取 COS_PRESIGN_EXPIRE
 * @return array ['success' => bool, 'data' => ['url', 'expires_in'] | 'message' => string]
 */
function cos_presign_url(string $key, ?int $expires = null): array {
    if (!cos_configured()) {
        return array_error('COS 未配置');
    }
    $expires = $expires ?? COS_PRESIGN_EXPIRE;
    if ($expires <= 0) {
        $expires = 60;
    }

    // 规范化对象键并做 URL 编码（对象键为单段，如 {disk_safe}.vpk）
    $key = ltrim($key, '/');
    $uri = '/' . rawurlencode($key);

    $now      = time();
    $key_time = ($now - 60) . ';' . ($now + $expires);

    $sign_key       = hash_hmac('sha1', $key_time, COS_SECRET_KEY);
    $http_string    = "get\n{$uri}\n\n\n";
    $string_to_sign = "sha1\n{$key_time}\n" . sha1($http_string) . "\n";
    $signature      = hash_hmac('sha1', $string_to_sign, $sign_key);

    $query = 'q-sign-algorithm=sha1'
        . '&q-ak=' . rawurlencode(COS_SECRET_ID)
        . '&q-sign-time=' . rawurlencode($key_time)
        . '&q-key-time=' . rawurlencode($key_time)
        . '&q-header-list='
        . '&q-url-param-list='
        . '&q-signature=' . $signature;

    return array_success([
        'url'        => COS_SCHEME . '://' . cos_host() . $uri . '?' . $query,
        'expires_in' => $expires,
    ]);
}

/**
 * 检查 COS 对象是否存在（HEAD 请求）
 *
 * @param string $cos_key 对象键，如 "l4d2-maps/foo.vpk"
 * @return array ['exists' => bool, 'content_length' => int|null, 'etag' => string|null]
 *         不存在或请求失败时 exists=false
 */
function cos_head_object(string $cos_key): array {
    if (!cos_configured()) {
        return ['exists' => false, 'content_length' => null, 'etag' => null];
    }

    if (strpos($cos_key, '/') !== 0) {
        $cos_key = '/' . $cos_key;
    }

    $host   = cos_host();
    $url    = COS_SCHEME . '://' . $host . $cos_key;
    $date   = gmdate('D, d M Y H:i:s \G\M\T');

    $authorization = cos_generate_auth('HEAD', $cos_key, '', '', $date);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "Authorization: {$authorization}",
            "Date: {$date}",
            "Host: {$host}",
        ],
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        // 从响应头中提取 Content-Length 和 ETag
        $content_length = null;
        $etag = null;
        if (preg_match('/Content-Length:\s*(\d+)/i', $response, $m)) {
            $content_length = (int)$m[1];
        }
        if (preg_match('/ETag:\s*"?([^"\r\n]+)"?/i', $response, $m)) {
            $etag = trim($m[1], '"');
        }
        return ['exists' => true, 'content_length' => $content_length, 'etag' => $etag];
    }

    return ['exists' => false, 'content_length' => null, 'etag' => null];
}

/**
 * 列出 COS 指定前缀下的对象（GET Bucket）
 *
 * @param string $prefix    前缀，如 "l4d2-maps/"（空字符串列出所有）
 * @param string $delimiter 分隔符，"/" 按目录层级分组
 * @param int    $max_keys  单次最大返回数，默认 1000
 * @param string $marker    分页游标：传上一页返回的 next_marker 以获取后续对象
 * @return array ['success' => bool, 'data' => ['files' => [...], 'dirs' => [...],
 *                'is_truncated' => bool, 'next_marker' => string]]
 */
function cos_list_objects(string $prefix = '', string $delimiter = '/', int $max_keys = 1000, string $marker = ''): array {
    if (!cos_configured()) {
        return array_error('COS 未配置');
    }

    $host  = cos_host();
    $date  = gmdate('D, d M Y H:i:s \G\M\T');

    // 构建查询参数（不纳入签名 — AWS V2 下 prefix/delimiter/max-keys/marker 非子资源）
    $query = array_filter([
        'delimiter' => $delimiter ?: null,
        'max-keys'  => $max_keys,
        'prefix'    => $prefix ?: null,
        'marker'    => $marker ?: null,
    ]);
    ksort($query);
    $query_str = http_build_query($query);
    $req_path  = '/?' . $query_str;

    // 签名用桶根路径（GET Bucket 的子资源仅 acl/versioning/location 等）
    $auth = cos_generate_auth('GET', '/', '', '', $date);

    $ch = curl_init(COS_SCHEME . '://' . $host . $req_path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "Authorization: {$auth}",
            "Date: {$date}",
            "Host: {$host}",
        ],
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        return array_error("COS List 失败: HTTP {$http_code}");
    }

    $xml = simplexml_load_string($response);
    if (!$xml) {
        return array_error('XML 解析失败');
    }

    $files = [];
    $dirs  = [];

    // 文件（对象）
    if (isset($xml->Contents)) {
        foreach ($xml->Contents as $obj) {
            $files[] = [
                'key'          => (string)$obj->Key,
                'size'         => (int)$obj->Size,
                'last_modified'=> (string)$obj->LastModified,
                'etag'         => trim((string)$obj->ETag, '"'),
            ];
        }
    }

    // 子目录（Common Prefixes）
    if (isset($xml->CommonPrefixes)) {
        foreach ($xml->CommonPrefixes as $cp) {
            $dirs[] = (string)$cp->Prefix;
        }
    }

    return array_success([
        'files'       => $files,
        'dirs'        => $dirs,
        'is_truncated'=> ((string)($xml->IsTruncated ?? 'false')) === 'true',
        'next_marker' => (string)($xml->NextMarker ?? ''),
        'prefix'      => $prefix,
    ]);
}

/**
 * 生成 COS 签名（AWS S3 V2 兼容格式，HMAC-SHA1）
 *
 * StringToSign = VERB + "\n" + Content-MD5 + "\n" + Content-Type + "\n" + Date + "\n" + CanonicalizedResource
 * CanonicalizedResource = "/" + Bucket + ObjectPath
 *
 * @param string $method      HTTP 方法，如 PUT / GET / HEAD
 * @param string $path        对象路径，如 /l4d2-maps/foo.vpk
 * @param string $content_md5 Content-MD5 头值（可为空）
 * @param string $content_type Content-Type 头值（可为空）
 * @param string $date        Date 头值
 * @return string Authorization 头的值
 */
function cos_generate_auth(
    string $method,
    string $path,
    string $content_md5,
    string $content_type,
    string $date
): string {
    if (strpos($path, '/') !== 0) {
        $path = '/' . $path;
    }

    $resource = '/' . COS_BUCKET . $path;

    $string_to_sign = strtoupper($method) . "\n"
                    . $content_md5 . "\n"
                    . $content_type . "\n"
                    . $date . "\n"
                    . $resource;

    $signature = base64_encode(hash_hmac('sha1', $string_to_sign, COS_SECRET_KEY, true));

    return 'AWS ' . COS_SECRET_ID . ':' . $signature;
}

/**
 * 上传文件到 COS（PUT Object）
 *
 * 使用流式上传（CURLOPT_INFILE），适用于大文件，不会将整个文件加载到内存。
 *
 * @param string $local_path 本地文件路径
 * @param string $cos_key    COS 对象键
 * @param string $content_type MIME 类型
 * @param int    $max_retries 最大重试次数
 * @param callable|null $on_progress 进度回调 function(int $processed, int $total): void
 * @return array ['success' => bool, 'url' => string, 'message' => string]
 */
function cos_upload_file(
    string $local_path,
    string $cos_key,
    string $content_type = 'application/octet-stream',
    int $max_retries = 3,
    ?callable $on_progress = null
): array {
    if (!cos_configured()) {
        return array_error('COS 未配置（缺少 COS_SECRET_ID / COS_SECRET_KEY / COS_BUCKET 环境变量）');
    }

    if (!file_exists($local_path) || !is_readable($local_path)) {
        return array_error("文件不存在或不可读: {$local_path}");
    }

    $file_size = filesize($local_path);
    if ($file_size === false) {
        return array_error("无法获取文件大小: {$local_path}");
    }

    // 计算 Content-MD5 (base64 of binary md5)
    $md5_bin  = md5_file($local_path, true);
    $content_md5 = base64_encode($md5_bin);

    // 规范化路径
    if (strpos($cos_key, '/') !== 0) {
        $cos_key = '/' . $cos_key;
    }

    $host   = cos_host();
    $url    = COS_SCHEME . '://' . $host . $cos_key;
    $date   = gmdate('D, d M Y H:i:s \G\M\T');

    $authorization = cos_generate_auth('PUT', $cos_key, $content_md5, $content_type, $date);

    $attempt = 0;
    $success = false;
    $err_msg = '';
    $http_code = 0;

    while ($attempt < $max_retries && !$success) {
        $attempt++;

        $fp = fopen($local_path, 'rb');
        if (!$fp) {
            return array_error("无法打开文件: {$local_path}");
        }

        $ch = curl_init($url);
        if (!$ch) {
            fclose($fp);
            return array_error("无法初始化 cURL");
        }

        $opts = [
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => $fp,
            CURLOPT_INFILESIZE     => $file_size,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_HTTPHEADER     => [
                "Authorization: {$authorization}",
                "Content-MD5: {$content_md5}",
                "Content-Type: {$content_type}",
                "Date: {$date}",
                "Host: {$host}",
                "Expect:",
            ],
        ];

        // 进度回调（用于 dashboard 展示上传进度条）
        if ($on_progress !== null) {
            $opts[CURLOPT_NOPROGRESS] = false;
            $lastUpdate = 0;
            $opts[CURLOPT_PROGRESSFUNCTION] = function (
                $resource, float $dl_size, float $downloaded, float $ul_size, float $uploaded
            ) use ($on_progress, $file_size, &$lastUpdate) {
                $now = microtime(true);
                if (($now - $lastUpdate) <= 1) return;
                $lastUpdate = $now;
                ($on_progress)((int)$uploaded, $file_size);
            };
        }

        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err_msg   = curl_error($ch);
        $curl_errno = curl_errno($ch);

        curl_close($ch);
        fclose($fp);

        // 2xx 表示成功
        if ($http_code >= 200 && $http_code < 300) {
            $success = true;
            break;
        }

        // 4xx 客户端错误不重试
        if ($http_code >= 400 && $http_code < 500) {
            $err_msg = "COS 返回 HTTP {$http_code}: " . substr($response, 0, 512);
            break;
        }

        // 5xx / 网络错误 → 重试
        if ($attempt < $max_retries) {
            sleep(min($attempt * 2, 10));
        }
    }

    if (!$success) {
        $message = $err_msg ?: "HTTP {$http_code}";
        return array_error("COS 上传失败（第 {$attempt} 次尝试）: {$message}");
    }

    $object_url = cos_object_url($cos_key);
    return array_success([
        'url'       => $object_url,
        'cos_key'   => $cos_key,
        'file_size' => $file_size,
    ]);
}

/**
 * 从 COS 下载对象到本地（GET Object）
 *
 * 流式写入（CURLOPT_FILE），不会将整个文件加载到内存。
 * 支持 Range 断点续传：下载过程写入 {$local_path}.part，
 * 成功后 rename 为目标路径；中断/失败时保留 .part 供下次续传。
 *
 * 断点续传约定：
 *   - 每次尝试根据 .part 当前大小设置 CURLOPT_RESUME_FROM；
 *   - 服务器返回 416（Range 不满足）说明 .part 已完整，直接视为成功；
 *   - 服务器忽略 Range 返回 200 时，丢弃 .part 重新完整下载；
 *   - 4xx 客户端错误不重试并清除 .part，5xx/网络错误保留 .part 等待重试。
 *
 * @param string $cos_key    COS 对象键，如 "l4d2-maps/foo.vpk"
 * @param string $local_path 本地保存路径
 * @param int    $max_retries 最大重试次数
 * @param callable|null $on_progress 进度回调 function(int $processed, int $total): void
 * @return array ['success' => bool, 'data' => ['url', 'cos_key', 'file_size'], 'message' => string]
 */
function cos_download_file(
    string $cos_key,
    string $local_path,
    int $max_retries = 3,
    ?callable $on_progress = null
): array {
    if (!cos_configured()) {
        return array_error('COS 未配置（缺少 COS_SECRET_ID / COS_SECRET_KEY / COS_BUCKET 环境变量）');
    }

    $save_dir = dirname($local_path);
    if (!is_dir($save_dir) && !mkdir($save_dir, 0755, true) && !is_dir($save_dir)) {
        return array_error("无法创建目录: {$save_dir}");
    }

    // 规范化路径
    if (strpos($cos_key, '/') !== 0) {
        $cos_key = '/' . $cos_key;
    }

    $part_path = $local_path . '.part';
    $host   = cos_host();
    $url    = COS_SCHEME . '://' . $host . $cos_key;
    $date   = gmdate('D, d M Y H:i:s \G\M\T');

    $authorization = cos_generate_auth('GET', $cos_key, '', '', $date);

    $attempt   = 0;
    $success   = false;
    $err_msg   = '';
    $http_code = 0;

    while ($attempt < $max_retries && !$success) {
        $attempt++;

        // 每次尝试重新计算断点（.part 可能因上次尝试失败而增长）
        $resume_from = 0;
        if (file_exists($part_path)) {
            $sz = filesize($part_path);
            if ($sz !== false && $sz > 0) $resume_from = $sz;
        }

        $fp = ($resume_from > 0) ? fopen($part_path, 'ab') : fopen($part_path, 'wb');
        if (!$fp) {
            return array_error("无法打开文件: {$part_path}");
        }

        $ch = curl_init($url);
        if (!$ch) {
            fclose($fp);
            return array_error("无法初始化 cURL");
        }

        $opts = [
            CURLOPT_FILE            => $fp,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_CONNECTTIMEOUT  => 30,
            CURLOPT_TIMEOUT         => 0,
            CURLOPT_LOW_SPEED_LIMIT => 10240,
            CURLOPT_LOW_SPEED_TIME  => 15,
            CURLOPT_HTTPHEADER      => [
                "Authorization: {$authorization}",
                "Date: {$date}",
                "Host: {$host}",
            ],
        ];
        if ($resume_from > 0) {
            $opts[CURLOPT_RESUME_FROM] = $resume_from;
        }

        // 进度回调（processed/total 均为含断点偏移的累计值）
        if ($on_progress !== null) {
            $opts[CURLOPT_NOPROGRESS] = false;
            $lastUpdate = 0;
            $opts[CURLOPT_PROGRESSFUNCTION] = function (
                $resource, float $dl_size, float $downloaded, float $ul_size, float $uploaded
            ) use ($on_progress, $resume_from, &$lastUpdate) {
                $now = microtime(true);
                if (($now - $lastUpdate) <= 1) return;
                $lastUpdate = $now;
                ($on_progress)((int)($resume_from + $downloaded), (int)($resume_from + $dl_size));
            };
        }

        curl_setopt_array($ch, $opts);

        $ok        = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err_msg   = curl_error($ch);

        curl_close($ch);
        fclose($fp);

        // 2xx 表示成功（206=续传完成，200=完整下载）
        if ($ok && $http_code >= 200 && $http_code < 300) {
            if ($http_code == 200 && $resume_from > 0) {
                // 服务器忽略 Range 返回完整内容，却被续写进 .part → 丢弃，重新完整下载
                unlink($part_path);
                continue;
            }
            $success = true;
            break;
        }

        // 416: Range 不满足 → .part 大小已 ≥ 远端大小，视为已完整
        if ($http_code == 416 && $resume_from > 0) {
            $success = true;
            break;
        }

        // 4xx 客户端错误不重试；清除 .part 垃圾内容
        if ($http_code >= 400 && $http_code < 500) {
            if (file_exists($part_path)) unlink($part_path);
            break;
        }

        // 5xx / 网络错误 → 保留 .part 供续传，等待后重试
        if ($attempt < $max_retries) {
            sleep(min($attempt * 2, 10));
        }
    }

    if (!$success) {
        $message = $err_msg ?: "HTTP {$http_code}";
        return array_error("COS 下载失败（第 {$attempt} 次尝试）: {$message}");
    }

    // .part → 目标文件
    if (!rename($part_path, $local_path)) {
        return array_error("重命名失败: {$part_path} → {$local_path}");
    }

    $object_url = cos_object_url($cos_key);
    return array_success([
        'url'       => $object_url,
        'cos_key'   => $cos_key,
        'file_size' => filesize($local_path),
    ]);
}
