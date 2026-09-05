<?php
// config / core / auth 已由 bootstrap.php 自动加载
// Content-Type: application/json 已由 json_error/json_success 自动设置

// 单次返回 download/upload 两种类型、各状态分组，供 dashboard 合并并发请求
$json  = file_get_contents('php://input');
$data  = json_decode($json, true);
$count = max(1, min((int)($data['count'] ?? 100), 100));

$result = query_tasks_grouped($count);
json_from($result);
