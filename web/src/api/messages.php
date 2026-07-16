<?php
// config 已由 bootstrap.php 自动加载
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => '未登录']);
    exit;
}

$type  = $_GET['type'] ?? 'count';
$pdo   = conn_db();
$uid   = (int) $_SESSION['user_id'];

switch ($type) {
    case 'count':
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$uid]);
        echo json_encode(['count' => (int) $stmt->fetchColumn()]);
        break;

    case 'list':
        $limit = min((int) ($_GET['limit'] ?? 5), 50);
        $stmt = $pdo->prepare("
            SELECT id, title, created_at
            FROM messages
            WHERE user_id = ? AND is_read = 0
            ORDER BY created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$uid]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $msg) {
            $result[] = [
                'id'    => $msg['id'],
                'title' => $msg['title'],
                'link'  => '/personal.php?tab=inbox&message_id=' . $msg['id'],
            ];
        }
        echo json_encode($result);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => '未知 type，支持 count | list']);
}
