<?php
// config / core / auth 已由 bootstrap.php 自动加载

// 使用统一的 check_admin() 而非内联检查
if (!check_admin()) {
    die("无权执行此操作。");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        die("无效的请求，请刷新页面重试。");
    }
    $comment_id = $_POST['comment_id'];
    $result = delete_comment((int)$comment_id);
    if ($result['success']) {
        echo "评论删除成功！";
    } else {
        echo "删除评论失败: " . $result['message'];
    }
}
?>