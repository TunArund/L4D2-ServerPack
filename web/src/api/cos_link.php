<?php
// config / core / auth / tables/* 已由 bootstrap.php 自动加载
// Content-Type: application/json 已由 json_error/json_success 自动设置
// 认证：仅登录用户可获取 COS 签名直链（私有桶公网直链返回 403）

include_once LIB_DIR . 'cos.php';

if (!check_login()) {
    json_error('请先登录后再下载。', 401);
}

$map_id = get_GET('map_id', 1, 0);
$result = find_map_by_id($map_id);
if (!$result['success'] || empty($result['data'])) {
    json_error('地图不存在。', 404);
}
$map = $result['data'];

if ($map['status'] !== 'active') {
    json_error('该地图尚未就绪，暂不可下载。', 409);
}
if (empty($map['disk_safe'])) {
    json_error('缺少文件标识，无法生成下载链接。');
}

// 短时效签名直链（默认 COS_PRESIGN_EXPIRE 秒），近似「单次有效」
$res = cos_presign_url($map['disk_safe'] . '.vpk');
if (!$res['success']) {
    json_error('生成下载链接失败：' . $res['message'], 502);
}

json_success(['url' => $res['data']['url'], 'expires_in' => $res['data']['expires_in']]);
