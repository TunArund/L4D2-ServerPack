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
// 可选：自定义域名/CDN 加速域名（如启用则直接返回该域名拼接的 URL）
define('COS_CUSTOM_DOMAIN', getenv('COS_CUSTOM_DOMAIN') ?: '');

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
    $stmt = $pdo->query(
        "SELECT id, disk_safe, version
         FROM maps
         WHERE status = 'active'
           AND (cos_version IS NULL OR cos_version != version)"
    );
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. 查询已同步的地图（完整性检查：COS 上文件可能被意外删除）
    $stmt2 = $pdo->query(
        "SELECT id, disk_safe, version
         FROM maps
         WHERE status = 'active'
           AND cos_version IS NOT NULL
           AND cos_version = version"
    );
    $synced = $stmt2->fetchAll(PDO::FETCH_ASSOC);

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
        $dup = $pdo->prepare(
            "SELECT 1 FROM tasks WHERE map_id = ? AND type = 'upload' AND status IN ('waiting', 'uploading')"
        );
        $dup->execute([$map['id']]);
        if ($dup->fetch()) continue;

        if (!file_exists($local_path)) {
            $ins = $pdo->prepare(
                "INSERT INTO tasks (type, map_id, src, dst, disk_safe, total_bytes, status)
                 VALUES ('upload', ?, ?, ?, ?, 0, 'fail')"
            );
            $ins->execute([$map['id'], $local_path, $cos_key, $map['disk_safe']]);
            $skipped++;
            continue;
        }

        $file_size = filesize($local_path) ?: 0;
        $ins = $pdo->prepare(
            "INSERT INTO tasks (type, map_id, src, dst, disk_safe, total_bytes, status)
             VALUES ('upload', ?, ?, ?, ?, ?, 'waiting')"
        );
        $ins->execute([$map['id'], $local_path, $cos_key, $map['disk_safe'], $file_size]);
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

    $pdo->prepare("UPDATE tasks SET status='uploading' WHERE id=?")->execute([$task_id]);

    if (!file_exists($local_path)) {
        $pdo->prepare("UPDATE tasks SET status='fail', total_bytes=0 WHERE id=?")->execute([$task_id]);
        return array_error("文件不存在: {$local_path}");
    }

    $res = cos_upload_file($local_path, $cos_key, 'application/octet-stream', 3,
        function($processed, $total) use ($pdo, $task_id) {
            $pdo->prepare("UPDATE tasks SET processed_bytes=?, total_bytes=?, updated_at=NOW() WHERE id=?")
                ->execute([$processed, $total, $task_id]);
        }
    );

    if ($res['success']) {
        $pdo->prepare("UPDATE tasks SET status='success', processed_bytes=total_bytes WHERE id=?")->execute([$task_id]);
        // 先查再写，避免 MySQL Error 1093（不能在 UPDATE 子查询中 SELECT 同一张表）
        $v = $pdo->prepare("SELECT version FROM maps WHERE id=?");
        $v->execute([$task['map_id']]);
        $version = $v->fetchColumn();
        $pdo->prepare("UPDATE maps SET cos_url=?, cos_version=? WHERE id=?")
            ->execute([$res['data']['url'], $version, $task['map_id']]);
        return array_success("上传成功");
    } else {
        $pdo->prepare("UPDATE tasks SET status='fail' WHERE id=?")->execute([$task_id]);
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
 * 从模板构建 index.html（替换 {{...}} 占位符）
 *
 * @param string $tpl_path 模板文件路径
 * @return string HTML 内容
 * @throws RuntimeException 模板文件不存在时
 */
function cos_build_index_html(string $tpl_path = '/var/www/html/static/html/cos_index.html'): string {
    if (!file_exists($tpl_path)) {
        throw new \RuntimeException("COS index template not found: {$tpl_path}");
    }

    $cdn_domain = COS_CUSTOM_DOMAIN !== '' ? rtrim(COS_CUSTOM_DOMAIN, '/') : '';
    $api_base   = COS_SCHEME . '://' . cos_host();
    $cdn_base   = $cdn_domain ?: $api_base;
    $page_title = $cdn_domain ? preg_replace('#^https?://#', '', $cdn_domain) : cos_host();

    $tpl = file_get_contents($tpl_path);
    return str_replace(
        ['{{COS_BUCKET}}', '{{COS_REGION}}', '{{COS_API_BASE}}', '{{COS_CDN_BASE}}', '{{COS_PAGE_TITLE}}'],
        [COS_BUCKET, COS_REGION, $api_base, $cdn_base, $page_title],
        $tpl
    );
}

/**
 * 构建并上传 index.html 到 COS 桶根
 *
 * @param string $tpl_path 模板文件路径
 * @return array ['success' => bool, 'message' => string]
 */
function cos_sync_index(string $tpl_path = '/var/www/html/static/html/cos_index.html'): array {
    if (!cos_configured()) {
        return array_error('COS 未配置');
    }

    try {
        $html = cos_build_index_html($tpl_path);
    } catch (\RuntimeException $e) {
        return array_error($e->getMessage());
    }

    $tmp = tempnam(sys_get_temp_dir(), 'cosidx');
    file_put_contents($tmp, $html);
    $res = cos_upload_file($tmp, '/index.html', 'text/html; charset=utf-8', 2);
    unlink($tmp);

    return $res;
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
    $stmt = $pdo->query("SELECT disk_safe FROM maps WHERE status = 'active'");
    $active = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $active[$row['disk_safe'] . '.vpk'] = true;
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
    if (COS_CUSTOM_DOMAIN !== '') {
        // 去除末尾斜杠后拼接
        $domain = rtrim(COS_CUSTOM_DOMAIN, '/');
        return $domain . '/' . ltrim($key, '/');
    }
    return COS_SCHEME . '://' . cos_host() . '/' . ltrim($key, '/');
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
 * @return array ['success' => bool, 'data' => ['files' => [...], 'dirs' => [...]]]
 */
function cos_list_objects(string $prefix = '', string $delimiter = '/', int $max_keys = 1000): array {
    if (!cos_configured()) {
        return array_error('COS 未配置');
    }

    $host  = cos_host();
    $date  = gmdate('D, d M Y H:i:s \G\M\T');

    // 构建查询参数（不纳入签名 — AWS V2 下 prefix/delimiter/max-keys 非子资源）
    $query = array_filter([
        'delimiter' => $delimiter ?: null,
        'max-keys'  => $max_keys,
        'prefix'    => $prefix ?: null,
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
