<?php
// ============================================================
// maps 表操作函数
// ============================================================

include_once LIB_DIR . 'steam.php';

const MAPS_ALLOWED_ORDER_BY = ['id', 'title', 'size', 'steam_id', 'status', 'subscriptions', 'created_at', 'updated_at', 'version'];

function find_map_by_id(int $id): array
{
    return db_fetch_one(
        'SELECT id, title, link, description, img_urls, subscriptions, records,
                status, steam_id, created_at, updated_at, disk_safe, downlink,
                size, is_map, version, preview_url, cos_url, cos_version
         FROM maps WHERE id = ?',
        [$id]
    );
}

function find_map_by_steam_id(int $steam_id): array
{
    return db_fetch_one(
        'SELECT id, version, status, title, disk_safe, downlink, size, link, created_at, updated_at
         FROM maps WHERE steam_id = ?',
        [$steam_id]
    );
}

function find_map_detail_by_id(int $id): array
{
    return db_fetch_one(
        'SELECT title, link, steam_id, downlink, description, records,
                subscriptions, size, img_urls, preview_url, cos_url
         FROM maps WHERE id = ?',
        [$id]
    );
}

function find_map_with_disk_safe(int $id): array
{
    return db_fetch_one(
        'SELECT id, disk_safe, status, steam_id FROM maps WHERE id = ?',
        [$id]
    );
}

function list_maps(array $opts = []): array
{
    $limit    = max(1, (int)($opts['limit'] ?? 10));
    $offset   = max(0, (int)($opts['offset'] ?? 0));
    $order_by = db_validate_order_by($opts['order_by'] ?? 'id', MAPS_ALLOWED_ORDER_BY, 'id');
    $order    = db_validate_order($opts['order'] ?? 'DESC');
    $search   = $opts['search'] ?? null;

    if ($search) {
        return db_fetch_all(
            "SELECT id, title, size, steam_id, link, version, status
             FROM maps WHERE title LIKE ? ORDER BY {$order_by} {$order}, title {$order}
             LIMIT {$limit} OFFSET {$offset}",
            ["%{$search}%"]
        );
    }
    return db_fetch_all(
        "SELECT id, title, size, steam_id, link, version, status
         FROM maps ORDER BY {$order_by} {$order}, title {$order}
         LIMIT {$limit} OFFSET {$offset}"
    );
}

function list_maps_with_preview(array $opts = []): array
{
    $limit    = max(1, (int)($opts['limit'] ?? 24));
    $offset   = max(0, (int)($opts['offset'] ?? 0));
    $order_by = db_validate_order_by($opts['order_by'] ?? 'status', MAPS_ALLOWED_ORDER_BY, 'status');
    $order    = db_validate_order($opts['order'] ?? 'DESC');
    $search   = $opts['search'] ?? null;

    if ($search) {
        return db_fetch_all(
            "SELECT id, title, subscriptions, size, status, preview_url
             FROM maps WHERE title LIKE ?
             ORDER BY {$order_by} {$order}, title {$order}
             LIMIT {$limit} OFFSET {$offset}",
            ["%{$search}%"]
        );
    }
    return db_fetch_all(
        "SELECT id, title, subscriptions, size, status, preview_url
         FROM maps ORDER BY {$order_by} {$order}, title {$order}
         LIMIT {$limit} OFFSET {$offset}"
    );
}

function count_maps(?string $search = null): array
{
    if ($search) {
        return db_fetch_column('SELECT COUNT(*) FROM maps WHERE title LIKE ?', ["%{$search}%"]);
    }
    return db_fetch_column('SELECT COUNT(*) FROM maps');
}

function all_active_maps(): array
{
    return db_fetch_all(
        "SELECT id, disk_safe, version FROM maps WHERE status = 'active'"
    );
}

function all_maps_pending_cos_sync(): array
{
    return db_fetch_all(
        "SELECT id, disk_safe, version FROM maps
         WHERE status = 'active' AND (cos_version IS NULL OR cos_version != version)"
    );
}

function all_maps_cos_synced(): array
{
    return db_fetch_all(
        "SELECT id, disk_safe, version FROM maps
         WHERE status = 'active' AND cos_version IS NOT NULL AND cos_version = version"
    );
}

function all_maps_except_updating(): array
{
    return db_fetch_all(
        "SELECT id, steam_id, status, title, disk_safe, version FROM maps WHERE status != 'updating'"
    );
}

function find_maps_for_update_by_ids(array $ids): array
{
    if (empty($ids)) return array_success([]);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return db_fetch_all(
        "SELECT id, steam_id, status, title, disk_safe, version FROM maps WHERE id IN ({$placeholders}) AND status != 'updating'",
        $ids
    );
}

function all_active_map_disk_safes(): array
{
    return db_fetch_all(
        "SELECT disk_safe FROM maps WHERE status = 'active'"
    );
}

function insert_map(array $data): array
{
    return db_insert(
        'INSERT INTO maps (steam_id, link, status, title, version, description, disk_safe, downlink, size, preview_url, is_map, subscriptions)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $data['steam_id'],
            $data['link'],
            $data['status'] ?? 'abandon',
            $data['title'],
            $data['version'] ?? 0,
            $data['description'] ?? '',
            $data['disk_safe'] ?? '',
            $data['downlink'] ?? '',
            $data['size'] ?? 0,
            $data['preview_url'] ?? '',
            (int)($data['is_map'] ?? 1),
            $data['subscriptions'] ?? 0,
        ]
    );
}

function update_map(int $id, array $data): array
{
    return db_execute_write(
        'UPDATE maps SET steam_id=?, link=?, title=?, version=?, description=?, disk_safe=?, downlink=?, size=?, preview_url=?, is_map=?, subscriptions=? WHERE id=?',
        [
            $data['steam_id'],
            $data['link'],
            $data['title'],
            $data['version'],
            $data['description'] ?? '',
            $data['disk_safe'],
            $data['downlink'],
            $data['size'],
            $data['preview_url'] ?? '',
            (int)($data['is_map'] ?? 1),
            $data['subscriptions'] ?? 0,
            $id,
        ]
    );
}

function update_map_status(int $id, string $status): array
{
    return db_execute_write('UPDATE maps SET status = ? WHERE id = ?', [$status, $id]);
}

function update_map_version_meta(int $id, array $steam_info): array
{
    return db_execute_write(
        'UPDATE maps SET version=?, downlink=?, size=?, title=?, preview_url=?, description=?, subscriptions=? WHERE id = ?',
        [
            $steam_info['version'],
            $steam_info['downlink'],
            $steam_info['size'],
            $steam_info['title'],
            $steam_info['preview_url'],
            $steam_info['description'],
            $steam_info['subscriptions'],
            $id,
        ]
    );
}

function update_map_cos_info(int $id, string $cos_url, int $cos_version): array
{
    return db_execute_write(
        'UPDATE maps SET cos_url = ?, cos_version = ? WHERE id = ?',
        [$cos_url, $cos_version, $id]
    );
}

function update_map_disk_safe(int $id, string $disk_safe): array
{
    return db_execute_write('UPDATE maps SET disk_safe = ? WHERE id = ?', [$disk_safe, $id]);
}

function get_map_version(int $id): array
{
    return db_fetch_column('SELECT version FROM maps WHERE id = ?', [$id]);
}

function get_map_title(int $id): array
{
    return db_fetch_column('SELECT title FROM maps WHERE id = ?', [$id]);
}

function get_map_steam_id(int $id): array
{
    return db_fetch_column('SELECT steam_id FROM maps WHERE id = ?', [$id]);
}

function delete_map(int $id): array
{
    return db_execute_write('DELETE FROM maps WHERE id = ?', [$id]);
}

/**
 * 查找 maps 或 map_requests 中是否已有该 Steam ID
 * @return array|null 找到的行，或 null
 */
function find_in_maps_or_requests(int $steam_id): ?array
{
    $result = db_fetch_one(
        "SELECT id, status, title, disk_safe, link, created_at, updated_at, size
         FROM map_requests WHERE steam_id = ? AND status = 'pending'",
        [$steam_id]
    );
    if ($result['success'] && $result['data']) return $result['data'];

    $result = db_fetch_one(
        'SELECT id, version, status, title, disk_safe, downlink, size, link, created_at, updated_at
         FROM maps WHERE steam_id = ?',
        [$steam_id]
    );
    if ($result['success'] && $result['data']) return $result['data'];

    return null;
}

// ============================================================
// 地图业务操作（复用函数）
// ============================================================

/**
 * 通过 Steam API 构造地图申请数据
 */
function build_map_request(int $steam_id): array
{
    $map_request = [
        'in_maps' => false,
        'steam_id' => $steam_id,
        'link' => "https://steamcommunity.com/sharedfiles/filedetails/?id={$steam_id}",
        'status' => 'pending',
    ];
    $map_info = fetch_steam_item_by_api($steam_id);
    if (!$map_info) return array_error("无法从数据库或api获取对应的地图{$steam_id}信息");
    if ($map_info['app_name'] != 'Left 4 Dead 2') {
        $map_info['explaination'] = ($map_info['explaination'] ?? '') . '该物品对应的游戏' . $map_info['app_name'] . '不是Left 4 Dead 2';
    }
    $map_request['title'] = $map_info['title'];
    $map_request['version'] = $map_info['version'];
    $map_request['description'] = $map_info['description'];
    $map_request['disk_safe'] = (string)$steam_id;
    $map_request['downlink'] = $map_info['downlink'];
    $map_request['size'] = $map_info['size'];
    $map_request['preview_url'] = $map_info['preview_url'];
    $map_request['is_map'] = $map_info['is_map'];
    $map_request['explaination'] = $map_info['explaination'] ?? '';
    $map_request['subscriptions'] = $map_info['subscriptions'] ?? '';
    if (!$map_request['is_map']) {
        $map_request['explaination'] .= '这不是地图文件，请点击steam链接仔细鉴别';
    }
    return array_success($map_request);
}

/**
 * 卸载地图（改状态 + 删文件）
 */
function uninstall_map(int $id): array
{
    $result = find_map_with_disk_safe($id);
    if (!$result['success']) return array_error($result['message']);
    $row = $result['data'];
    if (!$row) return array_error('未找到地图记录。');
    if ($row['status'] == 'updating') return array_error("地图正在更新，请稍后再试");
    $disk_safe = $row['disk_safe'];
    if (!$disk_safe) return array_error('记录中没有图名。');

    $result = update_map_status($id, 'abandon');
    if (!$result['success']) return array_error($result['message']);

    $file_path = MAP_DIR . $disk_safe . '.vpk';
    if (!file_exists($file_path)) return array_success("$disk_safe 删除成功。");
    if (!is_writable($file_path) || !is_writable(MAP_DIR)) return array_error("没有删除权限，请检查uninstall_map");
    unlink($file_path);
    return array_success("$disk_safe 删除成功。");
}

/**
 * 单图版本比较 → 更新
 */
function apply_map_update(array $row, array $steam_info, bool $install): array
{
    $id      = $row['id'];
    $version = $row['version'] ?? 0;
    $title   = $row['title'];
    $needs_file = $install && ($version < $steam_info['version']);

    $result = update_map_version_meta($id, $steam_info);
    if (!$result['success']) return array_error('更新数据库失败' . $result['message']);

    if ($needs_file) {
        $result = uninstall_map($id);
        if (!$result['success']) return array_error('更新前删除地图文件失败' . $result['message']);
        $disk_safe = $row['disk_safe'] ?: $row['steam_id'];
        $result = task_exists_duplicate($id, 'download');
        if ($result['success'] && $result['data']) return array_error('已有相同任务');
        $result = insert_task([
            'type' => 'download', 'map_id' => $id,
            'src' => $steam_info['downlink'], 'dst' => MAP_DIR . $disk_safe . '.vpk', 'disk_safe' => $disk_safe,
        ]);
        if (!$result['success']) return array_error('添加下载任务失败' . $result['message']);
        $result = update_map_status($id, 'updating');
        if (!$result['success']) return array_error($result['message']);
        return array_success(['id' => $id, 'size' => $steam_info['size'], 'version' => $steam_info['version'], 'status' => 'updating']);
    }

    return array_success(['id' => $id, 'title' => $title, 'refreshed' => true]);
}

/**
 * 批量更新地图（内部并行 Steam API）
 */
function update_maps(array $map_rows): array
{
    if (empty($map_rows)) {
        return array_success(['updated' => 0, 'failed' => 0, 'total' => 0, 'message' => '无待更新地图']);
    }
    $steam_ids  = array_column($map_rows, 'steam_id');
    $steam_infos = fetch_steam_items_batch($steam_ids);

    $updated = $refreshed = $fail_count = 0;
    foreach ($map_rows as $row) {
        $steam_info = $steam_infos[(string)$row['steam_id']] ?? false;
        if (!$steam_info) { $fail_count++; continue; }
        $install = ($row['status'] === 'active');
        $result  = apply_map_update($row, $steam_info, $install);
        if (!$result['success']) { $fail_count++; }
        elseif (!empty($result['data']['refreshed'])) { $refreshed++; }
        else { $updated++; }
    }
    $msg = "检查完成：{$updated} 个已更新" . ($updated > 0 ? "（含文件下载）" : "") . "，{$refreshed} 个已刷新（仅元数据）";
    if ($fail_count > 0) $msg .= "，{$fail_count} 个失败";
    $msg .= "，共 " . count($map_rows) . " 个地图";
    return array_success(['message' => $msg, 'updated' => $updated, 'refreshed' => $refreshed, 'failed' => $fail_count, 'total' => count($map_rows)]);
}
