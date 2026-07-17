<?php
// ============================================================
// tasks 表操作函数
// ============================================================

function query_tasks(string $type, string $status, int $limit = 10): array
{
    $limit = max(1, min($limit, 100));
    return db_fetch_all(
        'SELECT id, type, map_id, src, dst, disk_safe, status,
                processed_bytes, total_bytes, created_at, updated_at
         FROM tasks
         WHERE type = ? AND status = ?
         ORDER BY id DESC
         LIMIT ' . $limit,
        [$type, $status]
    );
}

function find_task_by_id(int $id): array
{
    return db_fetch_one(
        'SELECT id, type, map_id, src, dst, disk_safe, status,
                processed_bytes, total_bytes, created_at, updated_at
         FROM tasks WHERE id = ?',
        [$id]
    );
}

function task_exists_duplicate(int $map_id, string $type): array
{
    $result = db_fetch_column(
        "SELECT 1 FROM tasks WHERE map_id = ? AND type = ? AND status IN ('waiting', 'downloading', 'uploading')",
        [$map_id, $type]
    );
    if (!$result['success']) return $result;
    return array_success($result['data'] !== null);
}

function insert_task(array $data): array
{
    return db_insert(
        'INSERT INTO tasks (type, map_id, src, dst, disk_safe, total_bytes, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            $data['type'],
            $data['map_id'],
            $data['src'],
            $data['dst'],
            $data['disk_safe'],
            $data['total_bytes'] ?? 0,
            $data['status'] ?? 'waiting',
        ]
    );
}

function update_task_status(int $id, string $status): array
{
    return db_execute_write('UPDATE tasks SET status = ? WHERE id = ?', [$status, $id]);
}

function update_task_progress(int $id, int $processed_bytes, int $total_bytes): array
{
    return db_execute_write(
        'UPDATE tasks SET processed_bytes = ?, total_bytes = ?, updated_at = NOW() WHERE id = ?',
        [$processed_bytes, $total_bytes, $id]
    );
}

function mark_task_fail_zero_size(int $id): array
{
    return db_execute_write(
        'UPDATE tasks SET status = ?, total_bytes = 0 WHERE id = ?',
        ['fail', $id]
    );
}

function mark_task_upload_success(int $id): array
{
    return db_execute_write(
        'UPDATE tasks SET status = ?, processed_bytes = total_bytes WHERE id = ?',
        ['success', $id]
    );
}

// ============================================================
// Daemon 专用（使用 safe_execute 支持长连接重连）
// ============================================================

function fetch_next_download_task(): array|false
{
    global $pdo;
    $stmt = safe_execute($pdo,
        "SELECT id, type, map_id, src, dst, disk_safe, status,
                processed_bytes, total_bytes
         FROM tasks
         WHERE type = 'download' AND status = 'waiting'
         ORDER BY id ASC LIMIT 1"
    );
    if (!$stmt) return false;
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: false;
}

function fetch_next_download_resume_task(): array|false
{
    global $pdo;
    $stmt = safe_execute($pdo,
        "SELECT id, type, map_id, src, dst, disk_safe, status,
                processed_bytes, total_bytes
         FROM tasks
         WHERE type = 'download' AND status = 'downloading'
         ORDER BY id ASC LIMIT 1"
    );
    if (!$stmt) return false;
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: false;
}

function fetch_next_upload_task(): array|false
{
    global $pdo;
    $stmt = safe_execute($pdo,
        "SELECT id, type, map_id, src, dst, disk_safe, status,
                processed_bytes, total_bytes
         FROM tasks
         WHERE type = 'upload' AND status = 'waiting'
         ORDER BY id ASC LIMIT 1"
    );
    if (!$stmt) return false;
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: false;
}

function fetch_next_upload_resume_task(): array|false
{
    global $pdo;
    $stmt = safe_execute($pdo,
        "SELECT id, type, map_id, src, dst, disk_safe, status,
                processed_bytes, total_bytes
         FROM tasks
         WHERE type = 'upload' AND status = 'uploading'
         ORDER BY id ASC LIMIT 1"
    );
    if (!$stmt) return false;
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: false;
}

function safe_update_task_status(int $id, string $status): bool
{
    global $pdo;
    $stmt = safe_execute($pdo, 'UPDATE tasks SET status = ? WHERE id = ?', [$status, $id]);
    return $stmt !== false;
}

function safe_update_task_progress(int $id, int $processed_bytes, int $total_bytes): bool
{
    global $pdo;
    $stmt = safe_execute($pdo,
        'UPDATE tasks SET processed_bytes = ?, total_bytes = ?, updated_at = NOW() WHERE id = ?',
        [$processed_bytes, $total_bytes, $id]
    );
    return $stmt !== false;
}
