<?php
// ============================================================
// messages 表操作函数
// ============================================================

function find_message_by_id(int $id): array
{
    return db_fetch_one(
        'SELECT id, user_id, title, message, is_read, created_at FROM messages WHERE id = ?',
        [$id]
    );
}

function list_messages_by_user(int $user_id): array
{
    return db_fetch_all(
        'SELECT id, title, message, is_read, created_at FROM messages WHERE user_id = ? ORDER BY created_at DESC',
        [$user_id]
    );
}

function count_unread_messages(int $user_id): array
{
    return db_fetch_column(
        'SELECT COUNT(*) FROM messages WHERE user_id = ? AND is_read = 0',
        [$user_id]
    );
}

function list_unread_messages(int $user_id, int $limit = 5): array
{
    $limit = min($limit, 50);
    return db_fetch_all(
        "SELECT id, title, created_at FROM messages WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT {$limit}",
        [$user_id]
    );
}

function insert_message(int $user_id, string $title, string $message): array
{
    return db_insert(
        'INSERT INTO messages (user_id, title, message) VALUES (?, ?, ?)',
        [$user_id, $title, $message]
    );
}

function broadcast_messages(array $user_ids, string $title, string $message): array
{
    if (empty($user_ids)) return array_success(0);
    $count = 0;
    foreach ($user_ids as $user_id) {
        $result = insert_message((int)$user_id, $title, $message);
        if ($result['success']) $count++;
    }
    return array_success($count);
}

function mark_message_read(int $id, int $user_id): array
{
    return db_execute_write(
        'UPDATE messages SET is_read = 1 WHERE id = ? AND user_id = ?',
        [$id, $user_id]
    );
}

function mark_all_messages_read(int $user_id): array
{
    return db_execute_write(
        'UPDATE messages SET is_read = 1 WHERE user_id = ?',
        [$user_id]
    );
}

function delete_message(int $id, int $user_id): array
{
    return db_execute_write(
        'DELETE FROM messages WHERE id = ? AND user_id = ?',
        [$id, $user_id]
    );
}

function delete_messages(array $ids, int $user_id): array
{
    if (empty($ids)) return array_success(0);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return db_execute_write(
        "DELETE FROM messages WHERE id IN ({$placeholders}) AND user_id = ?",
        [...$ids, $user_id]
    );
}
