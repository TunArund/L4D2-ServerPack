<?php
// config 已由 bootstrap.php 自动加载
function register($pdo, $username, $email, $password, $role)
{
  try {
    $stmt = $pdo->prepare("INSERT INTO users (username, email, hashpass, role) VALUES (:username, :email, :hashpass, :role)");
    $stmt->bindParam(':username', $username);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':hashpass', $password);
    $stmt->bindParam(':role', $role);
    $stmt->execute();
    return true;
  } catch (PDOException $e) {
    return "注册失败: " . $e->getMessage();
  }
}
$username = '';
$email = '';
$password = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	include_once LIB_DIR . 'auth.php';
	if (!verify_csrf()) { exit("无效的请求，请刷新页面重试。"); }
  include_once LIB_DIR . 'core.php'; // 包含数据库连接代码
  $username = htmlspecialchars($_POST['username']);
  $email = htmlspecialchars($_POST['email']);
  $password = get_POST('password',0,false);
  if (empty($username) || empty($email) || empty($password)) {
    exit( "用户名、邮箱和密码不能为空");
  }
  if(strlen($password)<8) exit("密码不能小于8位");
  $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
  $role = 'guest'; // 默认角色为guest
  $pdo = conn_db(); // 内含die()函数，如果连接失败则终止脚本
  try {
    $stmt = $pdo->prepare("SELECT vericode,expire FROM emails WHERE email=:email");
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $expire = strtotime($row['expire']);
    if ($row['vericode'] === $_POST['vericode'] && time() < $expire) {
      $result = register($pdo, $username, $email, $password, $role);
      if (! $result) return $result;
      header("Location: /api/login.php");
    } else {
      exit("验证码错误或已过期");
    }
  } catch (PDOException $e) {
    exit( "查询失败: " . $e->getMessage());
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

</head>

<body>
  <div class="card m-3 shadow-sm">
    <div class="card-header h5 bg-primary text-white">注册</div>
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
            <button type="button" class="btn btn-success" onclick="sendVericode()">发送验证码</button>
          </div>
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
      // 邮箱格式验证
      const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailPattern.test(email)) {
        alert("请输入有效的邮箱地址！");
        return false;
      }
      return true;
    }

    function sendVericode() {
      var email = document.getElementById('email').value;
      if (!checkEmail(email)) return;
      fetch('/api/check_email.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            email: email
          })
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            alert('验证码已发送，请检查邮箱！');
          } else {
            alert('验证码发送失败：' + data.message);
          }
        })
        .catch(error => {
          alert('发送失败：' + error);
        });
    }
  </script>
</body>

</html>