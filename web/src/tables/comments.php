<?php
// ============================================================
// comments 表操作函数
// ============================================================

function list_comments_by_map(int $map_id): array
{
    return db_fetch_all(
        'SELECT comments.id, comments.comment, comments.created_at, comments.map_id, comments.user_id, users.username
         FROM comments
         JOIN users ON comments.user_id = users.id
         WHERE comments.map_id = ?
         ORDER BY comments.created_at DESC',
        [$map_id]
    );
}

function insert_comment(int $map_id, int $user_id, string $comment): array
{
    return db_insert(
        'INSERT INTO comments (map_id, user_id, comment) VALUES (?, ?, ?)',
        [$map_id, $user_id, $comment]
    );
}

function delete_comment(int $id): array
{
    return db_execute_write('DELETE FROM comments WHERE id = ?', [$id]);
}
