<?php
// config / core / auth 已由 bootstrap.php 自动加载
// Content-Type: application/json 已由 json_error/json_success 自动设置

$json   = file_get_contents('php://input');
$data   = json_decode($json, true);
$status = $data['status'] ?? 'waiting';
$count  = (int)($data['count'] ?? 10);
$type   = $data['type'] ?? 'download';

$result = query_tasks($type, $status, $count);
json_from($result);
