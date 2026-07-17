<?php
// ============================================================
// map_requests 表操作函数
// ============================================================

const MAP_REQUESTS_ALLOWED_ORDER_BY = ['id', 'title', 'size', 'status', 'created_at', 'updated_at'];

function find_request_by_id(int $id): array
{
    return db_fetch_one('SELECT * FROM map_requests WHERE id = ?', [$id]);
}

function find_request_by_steam_id(int $steam_id, ?string $status = null): array
{
    if ($status !== null) {
        return db_fetch_one(
            'SELECT * FROM map_requests WHERE steam_id = ? AND status = ?',
            [$steam_id, $status]
        );
    }
    return db_fetch_one('SELECT * FROM map_requests WHERE steam_id = ?', [$steam_id]);
}

function list_requests(array $opts = []): array
{
    $limit    = max(1, (int)($opts['limit'] ?? 10));
    $offset   = max(0, (int)($opts['offset'] ?? 0));
    $order_by = db_validate_order_by($opts['order_by'] ?? 'id', MAP_REQUESTS_ALLOWED_ORDER_BY, 'id');
    $order    = db_validate_order($opts['order'] ?? 'DESC');

    return db_fetch_all(
        "SELECT id, steam_id, title, status, created_at, updated_at, explaination, link, size
         FROM map_requests ORDER BY {$order_by} {$order} LIMIT {$limit} OFFSET {$offset}"
    );
}

function list_requests_by_user(int $user_id, array $opts = []): array
{
    $limit    = max(1, (int)($opts['limit'] ?? 10));
    $offset   = max(0, (int)($opts['offset'] ?? 0));
    $order_by = db_validate_order_by($opts['order_by'] ?? 'id', MAP_REQUESTS_ALLOWED_ORDER_BY, 'id');
    $order    = db_validate_order($opts['order'] ?? 'DESC');

    return db_fetch_all(
        "SELECT mr.id, mr.steam_id, mr.title, mr.status, mr.created_at, mr.updated_at, mr.explaination, mr.link, mr.size
         FROM map_requests mr
         JOIN map_request_users mru ON mr.id = mru.request_id
         WHERE mru.user_id = ?
         ORDER BY {$order_by} {$order} LIMIT {$limit} OFFSET {$offset}",
        [$user_id]
    );
}

function count_requests(): array
{
    return db_fetch_column('SELECT COUNT(*) FROM map_requests');
}

function insert_request(array $data): array
{
    return db_insert(
        'INSERT INTO map_requests (in_maps, steam_id, link, status, title, version, description, disk_safe, downlink, size, preview_url, is_map, explaination, subscriptions)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            (int)($data['in_maps'] ?? 0),
            $data['steam_id'],
            $data['link'] ?? '',
            $data['status'] ?? 'pending',
            $data['title'] ?? '',
            $data['version'] ?? 0,
            $data['description'] ?? '',
            $data['disk_safe'] ?? '',
            $data['downlink'] ?? '',
            $data['size'] ?? 0,
            $data['preview_url'] ?? '',
            (int)($data['is_map'] ?? 1),
            $data['explaination'] ?? '',
            $data['subscriptions'] ?? 0,
        ]
    );
}

function update_request_status(int $id, string $status): array
{
    return db_execute_write('UPDATE map_requests SET status = ? WHERE id = ?', [$status, $id]);
}

function delete_request(int $id): array
{
    return db_execute_write('DELETE FROM map_requests WHERE id = ?', [$id]);
}

function delete_requests_by_steam_id(int $steam_id): array
{
    return db_execute_write('DELETE FROM map_requests WHERE steam_id = ?', [$steam_id]);
}

function find_map_id_by_steam_id(int $steam_id): array
{
    return db_fetch_column('SELECT id FROM maps WHERE steam_id = ?', [$steam_id]);
}

function find_request_ids_by_steam_id(int $steam_id): array
{
    $result = db_fetch_all(
        'SELECT id FROM map_requests WHERE steam_id = ?',
        [$steam_id]
    );
    if (!$result['success']) return $result;
    return array_success(array_column($result['data'], 'id'));
}
