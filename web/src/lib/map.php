<?php
// ============================================================
// 地图 / 申请 业务逻辑
// core.php 已由 bootstrap.php 自动加载
// ============================================================
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/steam.php';

//检查maps\map_requests中是否已有地图
function fetch_db_item($pdo, $steam_id)
{
    $stmt = $pdo->prepare("SELECT id,status,title,disk_safe,link,created_at,updated_at,size FROM map_requests WHERE steam_id = ? and status = 'pending'");
    $stmt->execute([$steam_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) return $result;
    $stmt = $pdo->prepare("SELECT id,version,status,title,disk_safe,downlink,size,link,created_at,updated_at FROM maps WHERE steam_id = ?");
    $stmt->execute([$steam_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) return $result;
    return false;
}

function fetch_map_request($pdo, $request_id=null, $steam_id = null, $status = null)
{
    if ($request_id != null) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM map_requests WHERE id = ?");
            $stmt->execute([$request_id]);
            return array_success($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (PDOException $e) { return array_error($e->getMessage()); }
    } elseif ($steam_id != null) {
        try {
            if($status!=null){
                $stmt = $pdo->prepare("SELECT * FROM map_requests WHERE steam_id = ? AND status = ?");
                $stmt->execute([$steam_id, $status]);
            } else{
                $stmt = $pdo->prepare("SELECT * FROM map_requests WHERE steam_id = ?");
                $stmt->execute([$steam_id]);
            }
            return array_success($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (PDOException $e) { return array_error($e->getMessage()); }
    } else {
        return array_error('fetch_map_request: steam_id和request_id不能同时为null');
    }
}

function build_map_request($steam_id){
    $map_request['in_maps'] = false;
    $map_request['steam_id'] = $steam_id;
    $map_request['link'] = "https://steamcommunity.com/sharedfiles/filedetails/?id={$steam_id}";
    $map_request['status'] = 'pending';
    $map_info = fetch_steam_item_by_api($steam_id);
    if (!$map_info) return array_error("无法从数据库或api获取对应的地图{$steam_id}信息");
    if($map_info['app_name']!='Left 4 Dead 2')$map_info['explaination'].='该物品对应的游戏'.$map_info['app_name'].'不是Left 4 Dead 2';
    $map_request['title'] = $map_info['title'];
    $map_request['version'] = $map_info['version'];
    $map_request['description'] = $map_info['description'];
    $map_request['disk_safe'] = $steam_id;
    $map_request['downlink'] = $map_info['downlink'];
    $map_request['size'] = $map_info['size'];
    $map_request['preview_url'] = $map_info['preview_url'];
    $map_request['is_map'] = $map_info['is_map'];
    $map_request['explaination'] = $map_info['explaination'] ?? '';
    $map_request['subscriptions'] = $map_info['subscriptions'] ?? '';
    if(!$map_request['is_map']){
        $map_request['explaination'] .= '这不是地图文件，请点击steam链接仔细鉴别';
    }
    return array_success($map_request);
}

function bind_user_to_request($pdo, $request_id, $user_id)
{
    $stmt = $pdo->prepare("INSERT INTO map_request_users (request_id, user_id) VALUES (?, ?)");
    try { $stmt->execute([$request_id, $user_id]); }
    catch (PDOException $e) { return array_error($e->getMessage()); }
    return array_success($pdo->lastInsertId());
}

function fetch_users_by_request($pdo, $request_id){
    $stmt = $pdo->prepare("SELECT user_id FROM map_request_users WHERE request_id = ?");
    $stmt->execute([$request_id]);
    return array_success($stmt->fetchAll(PDO::FETCH_COLUMN));
}

function insert_map_request($pdo, $request)
{
    $stmt = $pdo->prepare("insert into map_requests(in_maps,steam_id,link,status,title,version,description,disk_safe,downlink,size,preview_url,is_map,explaination,subscriptions) values (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    try{
        $stmt->execute([intval($request['in_maps']),$request['steam_id'],$request['link'],$request['status'],$request['title'],$request['version'],$request['description'],$request['disk_safe'],$request['downlink'],$request['size'],$request['preview_url'],intval($request['is_map']),$request['explaination'],$request['subscriptions']]);
    } catch (PDOException $e) { return array_error("插入请求失败".$e->getMessage()); }
    return array_success($pdo->lastInsertId());
}

function insert_map($pdo, $map)
{
    $stmt = $pdo->prepare("insert into maps(steam_id,link,status,title,version,description,disk_safe,downlink,size,preview_url,is_map,subscriptions) values (?,?,?,?,?,?,?,?,?,?,?,?)");
    try{
        $stmt->execute([$map['steam_id'],$map['link'],$map['status'],$map['title'],$map['version'],$map['description'],$map['disk_safe'],$map['downlink'],$map['size'],$map['preview_url'],(int)$map['is_map'],$map['subscriptions'] ?? 0]);
    } catch (PDOException $e) { return array_error($e->getMessage()); }
    return array_success($pdo->lastInsertId());
}

function update_map_info($pdo, $map)
{
    $stmt = $pdo->prepare("update maps set steam_id=?,link=?,title=?,version=?,description=?,disk_safe=?,downlink=?,size=?,preview_url=?,is_map=?,subscriptions=? where id=?");
    try{
        $stmt->execute([$map['steam_id'],$map['link'],$map['title'],$map['version'],$map['description'],$map['disk_safe'],$map['downlink'],$map['size'],$map['preview_url'],(int)$map['is_map'],$map['subscriptions'] ?? 0,$map['id']]);
    } catch (PDOException $e) { return array_error($e->getMessage()); }
    return array_success($pdo->lastInsertId());
}

function isDownloadLinkValid($url, $timeout = 5) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; LinkChecker/1.0)', CURLOPT_RETURNTRANSFER => true,
    ]);
    curl_exec($ch);
    if (curl_errno($ch)) return ['valid' => false, 'error' => curl_error($ch)];
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $isValid = ($httpCode >= 200 && $httpCode < 400);
    return ['valid' => $isValid, 'http_code' => $httpCode, 'error' => $isValid ? null : "HTTP {$httpCode}"];
}

// ============================================================
// 地图管理（原 map_manage.php / map_request.php 业务逻辑，已抽离到此）
// ============================================================

// --- maps 表 CRUD ---

function list_map($pdo, $limit = 10, $offset = 0, $order_by = 'id', $order = 'DESC') {
    $allowed_fields = ['id', 'title', 'size', 'steam_id', 'status'];
    if (!in_array($order_by, $allowed_fields)) {
        $order_by = 'id';
    }
    $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
    $sql = "SELECT id, title, size, steam_id, link, version, status
            FROM maps
            ORDER BY $order_by $order, title $order
            LIMIT $limit OFFSET $offset";
    try {
        $stmt = $pdo->query($sql);
    } catch (PDOException $e) {
        return array_error($e->getMessage());
    }
    return array_success($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function uninstall_map($pdo, $id) {
    $stmt = $pdo->prepare("SELECT disk_safe, status FROM maps WHERE id = ?");
    $result = exec_stmt($stmt, $id);
    if (!$result['success']) return array_error($result['message']);
    $row = $result['data']->fetch(PDO::FETCH_ASSOC);
    if ($row['status'] == 'updating') return array_error("地图正在更新，请稍后再试");
    $disk_safe = $row['disk_safe'];
    if (!$disk_safe) return array_error('记录中没有图名。');

    $stmt = $pdo->prepare("UPDATE maps SET status=? WHERE id = ?");
    $result = exec_stmt($stmt, 'abandon', $id);
    if (!$result['success']) return array_error($result['message']);

    $file_path = MAP_DIR . $disk_safe . '.vpk';
    if (!file_exists($file_path)) return array_success("$disk_safe 删除成功。");
    if (!is_writable($file_path) || !is_writable(MAP_DIR)) return array_error("没有删除权限，请检查uninstall_map");
    unlink($file_path);
    return array_success("$disk_safe 删除成功。");
}

function delete_map($pdo, $id) {
    $result = uninstall_map($pdo, $id);
    if (!$result['success']) return array_error($result['message']);
    $stmt = $pdo->prepare("DELETE FROM maps WHERE id = ?");
    $result = exec_stmt($stmt, $id);
    if (!$result['success']) return array_error($result['message']);
    return array_success("删除成功。");
}

function apply_map_update($pdo, array $row, array $steam_info, bool $install): array {
    $id        = $row['id'];
    $version   = $row['version'] ?? 0;
    $title     = $row['title'];
    $needs_file = $install && ($version < $steam_info['version']);

    $stmt = $pdo->prepare("UPDATE maps SET version=?,downlink=?,size=?,title=?,preview_url=?,description=?,subscriptions=? WHERE id = ?");
    $result = exec_stmt($stmt,
        $steam_info['version'],
        $steam_info['downlink'],
        $steam_info['size'],
        $steam_info['title'],
        $steam_info['preview_url'],
        $steam_info['description'],
        $steam_info['subscriptions'],
        $id
    );
    if (!$result['success']) return array_error('更新数据库失败' . $result['message']);

    if ($needs_file) {
        $result = uninstall_map($pdo, $id);
        if (!$result['success']) return array_error('更新前删除地图文件失败' . $result['message']);
        include_once __DIR__ . '/download.php';
        $disk_safe = $row['disk_safe'] ?: $row['steam_id'];
        $result = add_download_task($pdo, $steam_info['downlink'], $disk_safe, $id);
        if (!$result['success']) return array_error('添加下载任务失败' . $result['message']);
        $stmt = $pdo->prepare("UPDATE maps SET status='updating' WHERE id = ?");
        $result = exec_stmt($stmt, $id);
        if (!$result['success']) return array_error($result['message']);
        return array_success(['id' => $id, 'size' => $steam_info['size'], 'version' => $steam_info['version'], 'status' => 'updating']);
    }

    return array_success(['id' => $id, 'title' => $title, 'refreshed' => true]);
}

function update_maps($pdo, array $map_rows): array {
    if (empty($map_rows)) {
        return ['updated' => 0, 'failed' => 0, 'total' => 0, 'message' => '无待更新地图'];
    }
    include_once __DIR__ . '/steam.php';
    $steam_ids  = array_column($map_rows, 'steam_id');
    $steam_infos = fetch_steam_items_batch($steam_ids);

    $updated = $refreshed = $fail_count = 0;
    foreach ($map_rows as $row) {
        $steam_info = $steam_infos[(string)$row['steam_id']] ?? false;
        if (!$steam_info) { $fail_count++; continue; }
        $install = ($row['status'] === 'active');
        $result  = apply_map_update($pdo, $row, $steam_info, $install);
        if (!$result['success']) { $fail_count++; }
        elseif (!empty($result['data']['refreshed'])) { $refreshed++; }
        else { $updated++; }
    }
    $msg = "检查完成：{$updated} 个已更新" . ($updated > 0 ? "（含文件下载）" : "") . "，{$refreshed} 个已刷新（仅元数据）";
    if ($fail_count > 0) $msg .= "，{$fail_count} 个失败";
    $msg .= "，共 " . count($map_rows) . " 个地图";
    return ['message' => $msg, 'updated' => $updated, 'refreshed' => $refreshed, 'failed' => $fail_count, 'total' => count($map_rows)];
}

function count_map($pdo) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM maps");
    return $stmt->fetch(PDO::FETCH_NUM)[0];
}

// --- map_requests 表 CRUD ---

function delete_all_request($pdo, $steam_id) {
    try {
        $stmt = $pdo->prepare("DELETE FROM map_requests WHERE steam_id = ?");
        $stmt->execute([$steam_id]);
    } catch (PDOException $e) { return array_error($e->getMessage()); }
    return array_success();
}

function delete_request($pdo, $is_admin, $user_id, $request_id) {
    try {
        if ($is_admin) {
            $stmt = $pdo->prepare("DELETE FROM map_request_users WHERE request_id = ?");
            $stmt->execute([$request_id]);
            $stmt = $pdo->prepare("DELETE FROM map_requests WHERE id = ?");
            $stmt->execute([$request_id]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM map_request_users WHERE request_id = ? AND user_id = ?");
            $stmt->execute([$request_id, $user_id]);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM map_request_users WHERE request_id = ?");
            $stmt->execute([$request_id]);
            if ($stmt->fetchColumn() == 0) {
                $stmt = $pdo->prepare("DELETE FROM map_requests WHERE id = ?");
                $stmt->execute([$request_id]);
            }
        }
    } catch (PDOException $e) { return array_error($e->getMessage()); }
    return array_success();
}

function count_request($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM map_requests");
    $result = exec_stmt($stmt);
    if (!$result['success']) return array_error($result['message']);
    return array_success($result['data']->fetch(PDO::FETCH_NUM)[0]);
}

function list_request($pdo, $is_admin, $user_id, $limit, $offset, $order_by, $order) {
    $allowed_order = ['id', 'title', 'size', 'status'];
    if (!in_array($order_by, $allowed_order)) $order_by = 'id';
    if (!in_array($order, ['ASC', 'DESC'])) $order = 'DESC';
    try {
        if ($is_admin) {
            $stmt = $pdo->prepare("SELECT id,steam_id,title,status,created_at,updated_at,explaination,link,size
                                   FROM map_requests ORDER BY $order_by $order LIMIT $limit OFFSET $offset");
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("SELECT mr.id, mr.steam_id, mr.title, mr.status, mr.created_at, mr.updated_at, mr.explaination, mr.link, mr.size
                                   FROM map_requests mr
                                   JOIN map_request_users mru ON mr.id = mru.request_id
                                   WHERE mru.user_id = ?
                                   ORDER BY $order_by $order LIMIT $limit OFFSET $offset");
            $stmt->execute([$user_id]);
        }
    } catch (PDOException $e) { return array_error($e->getMessage()); }
    return array_success($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function add_request($pdo, $user_id, $steam_id) {
    if (!$steam_id) return array_error('非法steam_id');
    $db_item = fetch_db_item($pdo, $steam_id);
    if (!$db_item) {
        $result = build_map_request($steam_id);
        if (!$result['success']) return array_error('构造请求失败' . $result['message']);
        $map_request = $result['data'];
        delete_all_request($pdo, $steam_id);
        $result = insert_map_request($pdo, $map_request);
        if (!$result['success']) return array_error('插入数据库失败' . $result['message']);
        $map_request['id'] = $result['data'];
        $result = bind_user_to_request($pdo, $map_request['id'], $user_id);
        if (!$result['success']) return array_error('绑定用户失败' . $result['message']);
        unset($map_request['description']);
        return array_success($map_request);
    } else switch ($db_item['status']) {
        case 'pending':
            $result = bind_user_to_request($pdo, $db_item['id'], $user_id);
            if (!$result['success']) return array_error('绑定用户失败' . $result['message']);
            $result = fetch_map_request($pdo, $db_item['id']);
            if (!$result['success']) return array_error($result['message']);
            $map_request = $result['data'];
            unset($map_request['description']);
            return array_success($map_request);
        case 'updating':
            return array_error("{$db_item['title']}正在更新！");
        case 'active':
            return array_error("{$db_item['title']}已被收录!");
        case 'abandon':
            $map_request = [
                'steam_id' => $steam_id, 'status' => 'pending', 'in_maps' => true,
                'map_id' => $db_item['id'], 'title' => $db_item['title'],
                'size' => $db_item['size'], 'created_at' => $db_item['created_at'],
                'updated_at' => $db_item['updated_at'], 'explaination' => '数据库已收录地图，但地图文件不在服务器上'
            ];
            $result = insert_map_request($pdo, $map_request);
            if (!$result['success']) return array_error('插入数据库失败' . $result['message']);
            $map_request['id'] = $result['data'];
            $result = bind_user_to_request($pdo, $map_request['id'], $user_id);
            if (!$result['success']) return array_error('请求添加成功但绑定用户失败' . $result['message']);
            return array_success($map_request);
    }
}

function approve_request($pdo, $request_id) {
    $stmt = $pdo->prepare("SELECT * FROM map_requests WHERE id = ?");
    $result = exec_stmt($stmt, $request_id);
    if (!$result['success']) return array_error($result['message']);
    $request = $result['data']->fetch(PDO::FETCH_ASSOC);
    if (!$request) return array_error("未找到该地图申请。");
    if ($request['status'] !== 'pending') return array_error("当前地图申请非审核态");

    if (!$request['in_maps']) {
        $map = [
            'steam_id' => $request['steam_id'], 'link' => $request['link'], 'status' => 'abandon',
            'title' => $request['title'], 'version' => $request['version'], 'description' => $request['description'],
            'disk_safe' => $request['disk_safe'], 'downlink' => $request['downlink'],
            'size' => $request['size'], 'preview_url' => $request['preview_url'], 'is_map' => $request['is_map']
        ];
        $result = insert_map($pdo, $map);
        if (!$result['success']) return array_error('插入地图失败:' . $result['message']);
        $map_id = $result['data'];
    } else {
        $stmt = $pdo->prepare("SELECT id FROM maps WHERE steam_id = ?");
        $stmt->execute([$request['steam_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) return array_error("更新地图信息失败:地图库中没有{$request['steam_id']}");
        $map_id = $result['id'];
        $result = build_map_request($request['steam_id']);
        if (!$result['success']) return array_error('更新地图信息失败:无法通过api更新信息' . $result['message']);
        $map_info = $result['data'];
        $map_info['id'] = $map_id;
        $result = update_map_info($pdo, $map_info);
        if (!$result['success']) return array_error('插入地图失败:更新地图表时异常 ' . $result['message']);
    }

    $stmt = $pdo->prepare("SELECT status,disk_safe,downlink,size FROM maps WHERE id = ?");
    $result = exec_stmt($stmt, $map_id);
    if (!$result['success']) return array_error($result['message']);
    $down_info = $result['data']->fetch(PDO::FETCH_ASSOC);
    if ($down_info['status'] !== 'abandon') return array_error('该地图已在服务器，拒绝批准');
    $size_bytes = intval(preg_replace('/[^0-9.]/', '', $down_info['size']));
    if (!check_disk_capacity($size_bytes)) return array_error("服务器剩余空间不足");
    if ($down_info['disk_safe'] === '') {
        $stmt = $pdo->prepare("UPDATE maps SET disk_safe = ? WHERE id = ?");
        $result = exec_stmt($stmt, $down_info['steam_id'], $map_id);
        if (!$result['success']) return array_error($result['message']);
        $down_info['disk_safe'] = $down_info['steam_id'];
    }

    include_once __DIR__ . '/download.php';
    $result = add_download_task($pdo, $down_info['downlink'], $down_info['disk_safe'], $map_id);
    if (!$result['success']) return array_error($result['message']);

    $stmt = $pdo->prepare("UPDATE map_requests SET status = 'approved' WHERE id = ?");
    $result = exec_stmt($stmt, $request_id);
    if (!$result['success']) return array_error($result['message']);

    $result = fetch_users_by_request($pdo, $request_id);
    broadcast_message($result['data'], '地图已批准并进入下载队列', $request['title']);
    return array_success('地图已批准并进入下载队列');
}
