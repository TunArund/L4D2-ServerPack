<?php
include_once __DIR__ . '/../config.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => '未登录']);
    exit;
}

require_once LIB_DIR . 'core.php';
include_once LIB_DIR . 'auth.php';
$pdo = conn_db();
$uid = intval($_SESSION['user_id']);
$limit = (int) ($_GET['limit'] ?? 5);
// 用字符串拼接 LIMIT 子句，注意防止 SQL 注入
$query = "
    SELECT id, title, created_at
    FROM messages
    WHERE user_id = ?
    AND is_read = 0
    ORDER BY created_at DESC
    LIMIT {$limit}
";
$stmt = $pdo->prepare($query);
$stmt->execute([$uid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$result = [];
foreach ($rows as $msg) {
    $result[] = [
        'id' => $msg['id'],
        'title' => $msg['title'],
        'link' => "/personal.php?tab=inbox&message_id=" . $msg['id']
    ];
}

echo json_encode($result);
