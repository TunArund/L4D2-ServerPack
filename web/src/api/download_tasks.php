<?php
include_once 'tools.php';
header('Content-Type: application/json');

$json = file_get_contents('php://input');
$data = json_decode($json, true);
$status = $data['status'] ?? 'downloading';
$count = (int)$data['count'] ?? 1;
// 查数据库
$pdo = conn_db();
$query = $pdo->prepare("
    select id, status, downloaded_bytes, total_bytes, disk_safe, created_at, updated_at
    from download_tasks
    where status = :status
    order by id desc
    limit :count
");
$query->bindParam(':status', $status, PDO::PARAM_STR);
$query->bindParam(':count', $count, PDO::PARAM_INT);
try{
    $query->execute();
    $tasks = $query->fetchAll(PDO::FETCH_ASSOC);
    json_success($tasks);
} catch (PDOException $e) {
    json_error($e->getMessage());
}
// $stmt = $pdo->query("
//     SELECT id, status, downloaded_bytes, total_bytes, disk_safe, created_at, updated_at
//     FROM download_tasks
//     ORDER BY 
//         CASE status
//             WHEN 'waiting' THEN 1
//             WHEN 'downloading' THEN 2
//             WHEN 'success' THEN 3
//             WHEN 'fail' THEN 4
//         END,
//         created_at DESC
// ");
// $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// echo json_encode(['tasks' => $tasks]);
