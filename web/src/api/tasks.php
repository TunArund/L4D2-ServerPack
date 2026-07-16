<?php
// config / core / auth 已由 bootstrap.php 自动加载
include_once LIB_DIR . 'task.php';
header('Content-Type: application/json');

$json   = file_get_contents('php://input');
$data   = json_decode($json, true);
$status = $data['status'] ?? 'waiting';
$count  = (int)($data['count'] ?? 10);
$type   = $data['type'] ?? 'download';

$pdo = conn_db();
$result = query_tasks($pdo, $status, $count, $type);
json_from($result);
