<?php
// config / core / auth 已由 bootstrap.php 自动加载
header('Content-Type: application/json');
// 确保任何 PHP 错误都不会污染 JSON
ini_set('display_errors', 0);


function gencode(){
    $randomHex = dechex(rand(0,0xFFFFF));
    if(strlen($randomHex) < 5){
        $randomHex = str_pad($randomHex, 5, '0', STR_PAD_LEFT);
    }
    return $randomHex;
}
/**
 * @param $last_time 有效时间，单位：分钟
 */
function genexpire($last_time=10){//默认上海时区
    $now = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
    $now->modify("+$last_time minutes");
    return $now->format('Y-m-d H:i:s');
}
function checkemail($pdo,$email){
    if(!filter_var($email, FILTER_VALIDATE_EMAIL))return false;
    $stmt = $pdo->prepare("select count(*) from users where email=?");
    $stmt->execute([$email]);
    $count = $stmt->fetchColumn();
    if($count > 0)return false;
    return true;
}
function updatedb($pdo, $email, $vericode, $expire){
    try{
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM emails WHERE email = ?");
        $stmt->execute([$email]);
        if($stmt->fetchColumn() > 0){
            $stmt = $pdo->prepare("UPDATE emails SET vericode = ?, expire = ? WHERE email = ?");
            $stmt->execute([$vericode, $expire, $email]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO emails (email, vericode, expire) VALUES (?, ?, ?)");
            $stmt->execute([$email, $vericode, $expire]);
        }
    }catch (PDOException $e){
        error_log("updatedb error: " . $e->getMessage());
        return false;
    }
    return true;
}



$pdo = conn_db();
include_once LIB_DIR . 'auth.php';
if (!verify_csrf()) {
	echo json_encode(['success' => false, 'message' => 'CSRF 验证失败，请刷新页面重试。']);
	exit;
}
// 频率限制：每 60 秒最多 1 次（防验证码轰炸）
rate_limit(1, 60);
$data = json_decode(file_get_contents('php://input'),true);
$email = $data['email'] ?? null;
if(!checkemail($pdo,$email)){
    echo json_encode(['success' => false, 'message' => '邮箱格式不正确或已被注册']);
    exit;
}
$vericode = gencode();
$last_time = 10;//minutes
$expire = genexpire($last_time);
//存数据库
$result = updatedb($pdo, $email, $vericode, $expire);
if($result!=true){
  echo json_encode(['success' => false, 'message' => '数据库操作失败：']);
  exit;
}
//发邮件
include_once LIB_DIR . 'email.php';
$response = sendEmail($email,$vericode,$expire,$last_time);//有效时间默认10分钟
$result = json_decode($response,true);
$success = isset($result['Response']['MessageId']);
if($success){
    echo json_encode(['success' => true, 'message' => '验证码已发送，请检查邮箱！']);
}else{
    echo json_encode(['success' => false, 'message' => '邮件发送失败：' . $result]);
}
