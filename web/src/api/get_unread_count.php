<?php
// config 已由 bootstrap.php 自动加载
header('Content-Type: application/json');

// 鉴权检查
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => '未登录']);
    exit;
}

require_once LIB_DIR . 'core.php';
$pdo = conn_db();
$uid = intval($_SESSION['user_id']);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE user_id = ? AND is_read = 0");
$stmt->execute([$uid]);
$count = $stmt->fetchColumn();

echo json_encode(['count' => (int)$count]);
