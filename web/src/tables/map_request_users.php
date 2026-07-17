<?php
// ============================================================
// map_request_users 关联表操作函数
// ============================================================

function bind_request_user(int $request_id, int $user_id): array
{
    $result = db_fetch_one(
        'SELECT 1 FROM map_request_users WHERE request_id = ? AND user_id = ?',
        [$request_id, $user_id]
    );
    if ($result['success'] && $result['data']) return array_success(0);

    return db_insert(
        'INSERT INTO map_request_users (request_id, user_id) VALUES (?, ?)',
        [$request_id, $user_id]
    );
}

function get_user_ids_by_request(int $request_id): array
{
    $result = db_fetch_all(
        'SELECT user_id FROM map_request_users WHERE request_id = ?',
        [$request_id]
    );
    if (!$result['success']) return $result;
    return array_success(array_column($result['data'], 'user_id'));
}

function get_user_ids_by_steam_id(int $steam_id): array
{
    $result = db_fetch_all(
        'SELECT DISTINCT mru.user_id
         FROM map_request_users mru
         JOIN map_requests mr ON mr.id = mru.request_id
         WHERE mr.steam_id = ?',
        [$steam_id]
    );
    if (!$result['success']) return $result;
    return array_success(array_column($result['data'], 'user_id'));
}

function delete_requests_by_request_id(int $request_id): array
{
    return db_execute_write('DELETE FROM map_request_users WHERE request_id = ?', [$request_id]);
}

function delete_request_user(int $request_id, int $user_id): array
{
    return db_execute_write(
        'DELETE FROM map_request_users WHERE request_id = ? AND user_id = ?',
        [$request_id, $user_id]
    );
}

function count_users_by_request(int $request_id): array
{
    return db_fetch_column(
        'SELECT COUNT(*) FROM map_request_users WHERE request_id = ?',
        [$request_id]
    );
}
