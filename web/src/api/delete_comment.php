<?php
session_start();

// 检查用户是否登录且角色为管理员
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    die("无权执行此操作。");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $comment_id = $_POST['comment_id'];
    include_once 'tools.php';
    $pdo = conn_db();
    try {
        $stmt = $pdo->prepare("DELETE FROM comments WHERE id = :comment_id");
        $stmt->bindParam(':comment_id', $comment_id);
        $stmt->execute();
        echo "评论删除成功！";
    } catch (PDOException $e) {
        echo "删除评论失败: " . $e->getMessage();
    }
}
?>