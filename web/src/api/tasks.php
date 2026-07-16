<?php
include_once __DIR__ . '/../config.php';
include_once LIB_DIR . 'core.php';
include_once LIB_DIR . 'auth.php';
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
