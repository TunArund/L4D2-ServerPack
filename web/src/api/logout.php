<?php
include_once __DIR__ . '/../config.php';
session_start();
session_unset();
session_destroy();
// Redirect to the login page
$return_url = "/api/login.php";
if(isset($_GET['return_url'])){
    $return_url = $_GET['return_url'];
}
header("Location: $return_url");
?>