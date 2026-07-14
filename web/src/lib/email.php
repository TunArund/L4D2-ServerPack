<?php
// ============================================================
// 邮箱验证码
// ============================================================

function sign($key, $msg)
{
    return hash_hmac("sha256", $msg, $key, true);
}
function sendEmail($destination, $veri_code, $expire_time_str, $valid_time_str = 10, $company_name = 'Tunarund GameLife')
{
    // 实例化一个认证对象，入参需要传入腾讯云账户 SecretId 和 SecretKey，此处还需注意密钥对的保密
    // 代码泄露可能会导致 SecretId 和 SecretKey 泄露，并威胁账号下所有资源的安全性
    // 以下代码示例仅供参考，建议采用更安全的方式来使用密钥
    // 请参见：https://cloud.tencent.com/document/product/1278/85305
    // 密钥可前往官网控制台 https://console.cloud.tencent.com/cam/capi 进行获取
    $secret_id = SES_SECRET_ID;
    $secret_key = SES_SECRET_KEY;
    $token = "";
    $service = "ses";
    $host = "ses.tencentcloudapi.com";
    $req_region = "ap-guangzhou";
    $version = "2020-10-02";
    $action = "SendEmail";
    $template_data = [
        'veri_code' => $veri_code,
        'valid_time_str' => "$valid_time_str",
        'expire_time_str' => $expire_time_str,
        'company_name' => $company_name
    ]; //修改了原payload格式，原来是json格式

    $params = json_encode([ //对应payload修改，原来是json_decode($payload),后续本来没用上params,现在需要（把下方的两个payload换成params了）
        "FromEmailAddress" => "TunArund <noreply@tunarund.top>",
        "Destination" => [$destination],
        "Subject" => "检查你的验证码",
        "Template" => [
            "TemplateID" => 33044,
            "TemplateData" => json_encode($template_data),
        ],
        "TriggerType" => 1
    ], JSON_UNESCAPED_UNICODE);

    $endpoint = "https://ses.tencentcloudapi.com";
    $algorithm = "TC3-HMAC-SHA256";
    $timestamp = time();
    $date = gmdate("Y-m-d", $timestamp);

    // ************* 步骤 1：拼接规范请求串 *************
    $http_request_method = "POST";
    $canonical_uri = "/";
    $canonical_querystring = "";
    $ct = "application/json; charset=utf-8";
    $canonical_headers = "content-type:" . $ct . "\nhost:" . $host . "\nx-tc-action:" . strtolower($action) . "\n";
    $signed_headers = "content-type;host;x-tc-action";
    $hashed_request_payload = hash("sha256", $params);
    $canonical_request = "$http_request_method\n$canonical_uri\n$canonical_querystring\n$canonical_headers\n$signed_headers\n$hashed_request_payload";

    // ************* 步骤 2：拼接待签名字符串 *************
    $credential_scope = "$date/$service/tc3_request";
    $hashed_canonical_request = hash("sha256", $canonical_request);
    $string_to_sign = "$algorithm\n$timestamp\n$credential_scope\n$hashed_canonical_request";

    // ************* 步骤 3：计算签名 *************
    $secret_date = sign("TC3" . $secret_key, $date);
    $secret_service = sign($secret_date, $service);
    $secret_signing = sign($secret_service, "tc3_request");
    $signature = hash_hmac("sha256", $string_to_sign, $secret_signing);

    // ************* 步骤 4：拼接 Authorization *************
    $authorization = "$algorithm Credential=$secret_id/$credential_scope, SignedHeaders=$signed_headers, Signature=$signature";

    // ************* 步骤 5：构造并发起请求 *************
    $headers = [
        "Authorization" => $authorization,
        "Content-Type" => "application/json; charset=utf-8",
        "Host" => $host,
        "X-TC-Action" => $action,
        "X-TC-Timestamp" => $timestamp,
        "X-TC-Version" => $version
    ];
    if ($req_region) {
        $headers["X-TC-Region"] = $req_region;
    }
    if ($token) {
        $headers["X-TC-Token"] = $token;
    }

    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_map(function ($k, $v) {
            return "$k: $v";
        }, array_keys($headers), $headers));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);

        return $response;
    } catch (Exception $err) {
        return $err->getMessage();
    }
}
function genCodeHtml($veri_code,$login_url, $site_name,$tips=''){
$mailBody = '
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <style>
    .card {
      max-width: 600px;
      margin: auto;
      border: 1px solid #ddd;
      border-radius: 8px;
      padding: 24px;
      background-color: #ffffff;
      font-family: Arial, sans-serif;
    }
    .code-box {
      font-size: 32px;
      font-weight: bold;
      letter-spacing: 6px;
      text-align: center;
      color: #0d6efd;
      margin: 20px 0;
    }
    .footer {
      font-size: 12px;
      color: #888;
      text-align: center;
      margin-top: 30px;
    }
  </style>
</head>
<body style="background-color:#f8f9fa; padding: 20px;">
  <div class="card">
    <h2 style="text-align:center; color:#0d6efd;">您的验证码</h2>
    <p>尊敬的用户，</p>
    <p>您正在尝试进行身份验证操作。以下是您的验证码，请在 <strong style="color:red;">10分钟</strong> 内使用：</p>
    <div class="code-box">' . $veri_code . '</div>
    <p>'. $tips.'</p>
    <p>如非本人操作，请忽略此邮件或及时修改密码以保障账号安全。</p>
    <div style="text-align:center; margin-top:20px;">
      <a href="' . $login_url . '" style="background-color:#0d6efd; color:white; padding:10px 20px; border-radius:5px; text-decoration:none;">立即登录</a>
    </div>
    <div class="footer">
      本邮件由系统自动发送，请勿直接回复。<br>
      &copy; 2025 ' . $site_name . '
    </div>
  </div>

</body>
</html>';
return $mailBody;
}
function sendmail($to,$subject= "检查你的验证码", $message){
    // 邮件头
    $headers = "From: tunarund@tunarund.top\r\n";
    $headers .= "Reply-To: yaokun-handsome@qq.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    //php email函数实际是通过apache调用sendemail（postfix），而apache默认用户为www-data，所以需要参数强制指定用户发送邮件
    $additional_parameters = "-fyaokun-handsome@qq.com";
    try{
        mail($to, $subject, $message, $headers, $additional_parameters);
    }catch (Exception $e){
        return $e->getMessage();
    }
    return true;
}

