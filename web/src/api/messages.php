<?php
// config 已由 bootstrap.php 自动加载
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => '未登录']);
    exit;
}

$type  = $_GET['type'] ?? 'count';
$uid   = (int) $_SESSION['user_id'];

switch ($type) {
    case 'count':
        $result = count_unread_messages($uid);
        if (!$result['success']) {
            http_response_code(500);
            echo json_encode(['error' => $result['message']]);
            break;
        }
        echo json_encode(['count' => (int) $result['data']]);
        break;

    case 'list':
        $limit = min((int) ($_GET['limit'] ?? 5), 50);
        $result = list_unread_messages($uid, $limit);
        if (!$result['success']) {
            http_response_code(500);
            echo json_encode(['error' => $result['message']]);
            break;
        }
        $messages = [];
        foreach ($result['data'] as $msg) {
            $messages[] = [
                'id'    => $msg['id'],
                'title' => $msg['title'],
                'link'  => '/personal.php?tab=inbox&message_id=' . $msg['id'],
            ];
        }
        echo json_encode($messages);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => '未知 type，支持 count | list']);
}
