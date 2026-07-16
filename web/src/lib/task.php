<?php
// ============================================================
// 任务查询（下载/上传队列）
// ============================================================
include_once __DIR__ . '/core.php';

function query_tasks(PDO $pdo, string $status = 'waiting', int $count = 10, string $type = 'download'): array {
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
        return array_success($query->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        return array_error($e->getMessage());
    }
}
