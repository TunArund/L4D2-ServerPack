<?php
// config 已由 bootstrap.php 自动加载
$username = '';
$email = '';
$password = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    include_once LIB_DIR . 'auth.php';
    if (!verify_csrf()) { exit("无效的请求，请刷新页面重试。"); }
  $username = htmlspecialchars($_POST['username']);
  $email = htmlspecialchars($_POST['email']);
  $password = get_POST('password',0,false);
  if (empty($username) || empty($email) || empty($password)) {
    exit( "用户名、邮箱和密码不能为空");
  }
  if(strlen($password)<8) exit("密码不能小于8位");
  $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
  $role = 'guest'; // 默认角色为guest
  $result = find_email($email);
  if (!$result['success']) exit("查询失败: " . $result['message']);
  $row = $result['data'];
  $expire = strtotime($row['expire']);
  if ($row && $row['vericode'] === $_POST['vericode'] && time() < $expire) {
      $result = insert_user($username, $email, $password, $role);
      if (!$result['success']) exit("注册失败: " . $result['message']);
      header("Location: /api/login.php");
  } else {
      exit("验证码错误或已过期");
  }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>注册</title>
  <link rel="shortcut icon" href="/static/img/favicon.ico">
  <link href="/static/css/bootstrap.min.css" type="text/css" rel="stylesheet" />
  <link href="/static/font/bootstrap-icons.min.css" type="text/css" rel="stylesheet" />
  <link rel="stylesheet" href="/static/css/custom/global.css">
</head>

<body class="d-flex align-items-center" style="padding-top:0">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-12 col-md-8 col-lg-6">

        <div class="card shadow-sm border-0 overflow-hidden">
          <div class="card-header bg-color-darkblue text-light fw-bold">注册</div>
          <div class="card-body p-4">

            <!-- 提示信息 -->
            <div class="alert alert-warning" role="alert">
              每个邮箱只能注册一次，且密码无法更改，请妥善保存密码。
            </div>

            <form method="POST">
              <?php include_once LIB_DIR . 'auth.php'; echo csrf_hidden_field(); ?>
              <div class="form-group mt-3">
                <label for="username">用户名</label>
                <input class="form-control" type="text" id="username" name="username" placeholder="用户名" value="<?php echo $username ?>" required>
              </div>

              <div class="form-group mt-3">
                <label for="email">邮箱</label>
                <input class="form-control" type="email" id="email" name="email" placeholder="邮箱" value="<?php echo $email ?>" required>
              </div>

              <div class="form-group mt-3">
                <label for="vericode">验证码</label>
                <div class="input-group">
                  <input class="form-control" type="text" id="vericode" name="vericode" placeholder="验证码" required>
                  <button type="button" id="send-code-btn" class="btn btn-success" onclick="sendVericode()">发送验证码</button>
                </div>
                <div id="verify-alert" class="alert d-none mt-2 mb-0 py-2" role="alert"></div>
              </div>

              <div class="form-group mt-3">
                <label for="password">密码</label>
                <input class="form-control" type="password" id="password" name="password" value="<?php echo $password ?>"
                  oninput="updatePasswordStrength()" placeholder="要求强度为中等及以上" minlength="8" required>
                <!-- 密码强度文本 -->
                <small id="passwordStrength" class="form-text mt-1">
                  密码强度：<span id="strengthLabel" class="fw-bold text-secondary">未输入</span>
                </small>
                <!-- 密码规则提示 -->
                <ul class="list-unstyled mt-2" id="passwordRules">
                  <li id="rule-length" class="text-muted">❌ 至少 8 个字符</li>
                  <li id="rule-upper" class="text-muted">❌ 包含大写字母</li>
                  <li id="rule-lower" class="text-muted">❌ 包含小写字母</li>
                  <li id="rule-digit" class="text-muted">❌ 包含数字</li>
                  <li id="rule-special" class="text-muted">❌ 包含特殊字符</li>
                </ul>
              </div>

              <div class="form-group mt-3">
                <label for="password2">确认密码</label>
                <input class="form-control" type="password" id="password2" name="password2" placeholder="再次输入密码" required>
              </div>

              <div class="form-group mt-4 d-flex justify-content-between align-items-center">
                <button class="btn btn-primary" type="submit">注册</button>
                <p class="mb-0">已有账号？<a href="/api/login.php">直接登录</a></p>
              </div>
            </form>
          </div>
        </div>

      </div>
    </div>
  </div>

  <script>
    function updatePasswordStrength() {
      const password = document.getElementById('password').value;
      const strengthLabel = document.getElementById('strengthLabel');
      const rules = [{
          id: 'rule-length',
          regex: /.{8,}/,
          message: '至少 8 个字符'
        },
        {
          id: 'rule-upper',
          regex: /[A-Z]/,
          message: '包含大写字母'
        },
        {
          id: 'rule-lower',
          regex: /[a-z]/,
          message: '包含小写字母'
        },
        {
          id: 'rule-digit',
          regex: /[0-9]/,
          message: '包含数字'
        },
        {
          id: 'rule-special',
          regex: /[^A-Za-z0-9]/,
          message: '包含特殊字符'
        }
      ];

      let passed = 0;
      let lengthValid = false;

      rules.forEach(rule => {
        const element = document.getElementById(rule.id);
        const passedThis = rule.regex.test(password);
        if (passedThis) {
          element.textContent = '✅ ' + rule.message;
          element.className = 'text-success';
          passed++;
          if (rule.id === 'rule-length') lengthValid = true;
        } else {
          element.textContent = '❌ ' + rule.message;
          element.className = 'text-muted';
        }
      });
      // 设置强度标签：只有长度合法时再考虑强度
      let text = '弱',
        color = 'danger';
      if (!password) {
        text = '未输入';
        color = 'secondary';
      } else if (!lengthValid) {
        text = '长度不足';
        color = 'danger';
      } else if (passed >= 4) {
        text = '强';
        color = 'success';
      } else if (passed >= 2) {
        text = '中';
        color = 'warning';
      }

      strengthLabel.textContent = text;
      strengthLabel.className = 'fw-bold text-' + color;
    }


    function checkEmail(email) {
      const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      return emailPattern.test(email);
    }

    let sendCodeTimer = null;

    function showVerifyAlert(msg, type) {
      const el = document.getElementById('verify-alert');
      el.textContent = msg;
      el.className = 'alert mt-2 mb-0 py-2 alert-' + type;
    }

    function sendVericode() {
      if (sendCodeTimer) return; // 倒计时中，忽略点击
      var email = document.getElementById('email').value;
      if (!checkEmail(email)) {
        showVerifyAlert('请输入有效的邮箱地址！', 'danger');
        return;
      }
      var btn = document.getElementById('send-code-btn');
      btn.disabled = true;
      showVerifyAlert('', ''); // 清空

      fetch('/api/check_email.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email: email })
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            showVerifyAlert('验证码已发送，请检查邮箱！', 'success');
            // 启动 60 秒倒计时
            var sec = 60;
            btn.textContent = sec + 's 后可重发';
            sendCodeTimer = setInterval(function() {
              sec--;
              if (sec <= 0) {
                clearInterval(sendCodeTimer);
                sendCodeTimer = null;
                btn.textContent = '发送验证码';
                btn.disabled = false;
              } else {
                btn.textContent = sec + 's 后可重发';
              }
            }, 1000);
          } else {
            showVerifyAlert(data.message || '发送失败', 'danger');
            btn.disabled = false;
          }
        })
        .catch(error => {
          showVerifyAlert('网络错误，请稍后再试', 'danger');
          btn.disabled = false;
        });
    }
  </script>
</body>

</html>