<?php
// 统一任务查询 API — tasks 表
// POST { status, count, type? }   type 默认 'download'，传 'upload' 查 COS 上传任务
include_once 'tools.php';
header('Content-Type: application/json');

$json = file_get_contents('php://input');
$data = json_decode($json, true);
$status = $data['status'] ?? 'waiting';
$count  = (int)($data['count'] ?? 10);
$type   = $data['type'] ?? 'download';

$pdo = conn_db();
$query = $pdo->prepare("
    SELECT id, type, map_id, src, dst, disk_safe, status,
           processed_bytes, total_bytes, created_at, updated_at
    FROM tasks
    WHERE type = :type AND status = :status
    ORDER BY id DESC
    LIMIT :count
");
$query->bindValue(':type', $type, PDO::PARAM_STR);
$query->bindValue(':status', $status, PDO::PARAM_STR);
$query->bindValue(':count', $count, PDO::PARAM_INT);

try {
    $query->execute();
    $tasks = $query->fetchAll(PDO::FETCH_ASSOC);
    json_success($tasks);
} catch (PDOException $e) {
    json_error($e->getMessage());
}
