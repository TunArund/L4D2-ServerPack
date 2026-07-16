<?php
// config 已由 bootstrap.php 自动加载
session_unset();
session_destroy();
// Redirect to the login page（仅允许相对路径，防止 Open Redirect 钓鱼）
$return_url = "/api/login.php";
if(isset($_GET['return_url'])){
    $input = $_GET['return_url'];
    if (str_starts_with($input, '/') && !str_starts_with($input, '//')) {
        $return_url = $input;
    }
}
header("Location: $return_url");
?>