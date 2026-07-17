<?php
// config / core / auth 已由 bootstrap.php 自动加载
// Content-Type: application/json 已由 json_error/json_success 自动设置
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
function checkemail($email){
    if(!filter_var($email, FILTER_VALIDATE_EMAIL))return false;
    $result = user_email_exists($email);
    if (!$result['success']) return false;
    return !$result['data'];
}
function updatedb($email, $vericode, $expire){
    $result = upsert_email($email, $vericode, $expire);
    if (!$result['success']) {
        error_log("updatedb error: " . $result['message']);
        return false;
    }
    return true;
}



include_once LIB_DIR . 'auth.php';
if (!verify_csrf()) {
	json_error('CSRF 验证失败，请刷新页面重试。');
}
// 频率限制：每 60 秒最多 1 次（防验证码轰炸）
rate_limit(1, 60);
$data = json_decode(file_get_contents('php://input'),true);
$email = $data['email'] ?? null;
if(!checkemail($email)){
    json_error('邮箱格式不正确或已被注册');
}
$vericode = gencode();
$last_time = 10;//minutes
$expire = genexpire($last_time);
//存数据库
$result = updatedb($email, $vericode, $expire);
if($result!=true){
  json_error('数据库操作失败');
}
//发邮件
include_once LIB_DIR . 'ses.php';
$response = sendEmail($email,$vericode,$expire,$last_time);//有效时间默认10分钟
$result = json_decode($response,true);
$success = isset($result['Response']['MessageId']);
if($success){
    json_success(['message' => '验证码已发送，请检查邮箱！']);
}else{
    json_error('邮件发送失败：' . $result);
}
