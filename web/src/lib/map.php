<?php
// ============================================================
// 地图 / 申请 业务逻辑
// ============================================================
include_once __DIR__ . '/core.php';
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
