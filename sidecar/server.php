<?php
/**
 * sidecar — 容器管理 API
 * php -S 0.0.0.0:8080 server.php（PHP 内置服务器）
 *
 * GET  /health                        → {"status":"ok"}
 * GET  /containers                    → 列出运行中的容器
 * POST /containers/{name}/restart     → 重启容器（白名单限制）
 *
 * 认证：除 /health 外，所有请求需要 X-Auth-Token 头匹配 SIDECAR_TOKEN
 */

// ---- 白名单 ----
// ALLOWED_CONTAINERS   — 允许在面板中查看的容器
// RESTARTABLE_CONTAINERS — 允许重启的容器（应为 ALLOWED 的子集）
$allowed = array_filter(array_map('trim', explode(',', getenv('ALLOWED_CONTAINERS') ?: '')));
if (!$allowed) {
    $allowed = ['l4d2-downloader', 'l4d2-coop', 'l4d2-versus', 'l4d2-php', 'l4d2-mysql', 'l4d2-glances', 'l4d2-nginx'];
}
$restartable = array_filter(array_map('trim', explode(',', getenv('RESTARTABLE_CONTAINERS') ?: '')));
if (!$restartable) {
    $restartable = ['l4d2-downloader', 'l4d2-coop', 'l4d2-versus'];
}

// ---- 认证 ----
$sidecarToken = getenv('SIDECAR_TOKEN') ?: '';
function requireAuth(): void
{
    global $sidecarToken;
    if ($sidecarToken === '') return; // 未配置 token 则跳过认证
    $provided = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if (!hash_equals($sidecarToken, $provided)) {
        json_out(['error' => 'unauthorized'], 401);
        exit;
    }
}

// ---- 工具函数 ----
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function docker(string ...$args): array
{
    $cmd = 'docker ' . implode(' ', array_map('escapeshellarg', $args));
    exec($cmd . ' 2>&1', $output, $ret);
    return [$ret, implode("\n", $output)];
}

// ---- 路由 ----
$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// CORS 预检
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

// GET /health
if ($method === 'GET' && $path === '/health') {
    json_out(['status' => 'ok']);
    exit;
}

// GET /containers
if ($method === 'GET' && $path === '/containers') {
    requireAuth();
    [$ret, $out] = docker('ps', '--format', '{{.Names}}\t{{.Status}}\t{{.Image}}');
    $containers = [];
    foreach (array_filter(explode("\n", $out)) as $line) {
        $parts = explode("\t", $line);
        $containers[] = [
            'name'   => $parts[0] ?? '?',
            'status' => $parts[1] ?? '?',
            'image'  => $parts[2] ?? '?',
        ];
    }
    json_out([
        'containers' => $containers,
        'viewable'   => array_values($allowed),
        'restartable' => array_values($restartable),
    ]);
    exit;
}

// GET /containers/{name}/logs?tail=50
if ($method === 'GET' && preg_match('#^/containers/([^/]+)/logs$#', $path, $m)) {
    requireAuth();
    $name = $m[1];
    if (!in_array($name, $allowed, true)) {
        json_out(['error' => "'{$name}' not in allowed list"], 403);
        exit;
    }
    $tail = max(1, min(200, (int)($_GET['tail'] ?? 50)));
    [$ret, $out] = docker('logs', '--tail', (string)$tail, $name);
    json_out(['logs' => $out, 'name' => $name, 'exit_code' => $ret]);
    exit;
}

// POST /containers/{name}/restart
if ($method === 'POST' && preg_match('#^/containers/([^/]+)/restart$#', $path, $m)) {
    requireAuth();
    $name = $m[1];

    if (!in_array($name, $restartable, true)) {
        json_out([
            'error'   => "'{$name}' 不允许重启",
            'restartable' => array_values($restartable),
        ], 403);
        exit;
    }

    [$ret, $out] = docker('restart', $name);
    if ($ret === 0) {
        json_out(['restarted' => $name, 'message' => $out ?: 'ok']);
    } else {
        json_out(['restarted' => $name, 'error' => $out], 500);
    }
    exit;
}

// 404
json_out(['error' => 'not found'], 404);

// 告诉 PHP 内置服务器：已处理，不要返回 404
return true;
