<?php
include_once __DIR__ . '/../config.php';
//生成验证码、发邮件、存数据库
//by:TunArund
//at:2025.2.1
include_once LIB_DIR . 'core.php';
include_once LIB_DIR . 'auth.php';
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
    $select = "SELECT * FROM emails WHERE email = '$email'";
    $update = "UPDATE emails SET vericode = '$vericode',expire='$expire' WHERE email = '$email'";
    $insert = "INSERT INTO emails (email, vericode, expire) VALUES ('$email', '$vericode', '$expire')";
    try{
        $result = $pdo->query($select);
        if($result->rowCount() > 0)$pdo->exec($update);
        else $pdo->exec($insert);
    }catch (PDOException $e){
        $error_message = $e->getMessage();
        return false;
    }
    return true;
}



$pdo = conn_db();
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
