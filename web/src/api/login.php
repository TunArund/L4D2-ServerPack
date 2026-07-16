<?php
include_once __DIR__ . '/../config.php';

// 处理登录请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	include_once LIB_DIR . 'core.php';
	include_once LIB_DIR . 'auth.php';
	if (!verify_csrf()) {
		echo "无效的请求，请刷新页面重试。";
		exit;
	}
	$username = get_POST('username');
	$password = get_POST('password');
	// 验证用户输入
	if (empty($username) || empty($password)) {
		echo "用户名和密码不能为空。";
		exit;
	}
	try {
		// 查询管理员信息
		$stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
		$stmt->bindParam(':username', $username, PDO::PARAM_STR);
		$stmt->execute();
		$user = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($user && password_verify($password, $user['hashpass'])) {
			// 登录成功
			session_start();
			session_unset();            // 清除所有 session 变量
			session_regenerate_id(true); // 防止会话固定攻击
			$_SESSION['user_id']   = $user['id']; //记录id
			$_SESSION['user_name'] = $user['username']; //记录名称
			$_SESSION['user_role'] = $user['role']; //记录角色
			//重定向
			$return_url = '/billboard.php';
			if (isset($_GET['return_url'])) {
				// 仅接受以 "/" 开头的相对路径，防止 Open Redirect 钓鱼
				$input = $_GET["return_url"];
				if (str_starts_with($input, "/") && !str_starts_with($input, "//")) {
					$return_url = $input;
				}
			}
			header("Location: $return_url");
			exit;
		} else {
			echo "用户名或密码错误。";
		}
	} catch (PDOException $e) {
		echo "查询失败：" . $e->getMessage();
	}
}
?>

<!DOCTYPE html>
<html lang="zh-CN">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>登录</title>
	<link href="/static/css/bootstrap.min.css" type="text/css" rel="stylesheet" />
	<link href="/static/font/bootstrap-icons.min.css" type="text/css" rel="stylesheet" />
</head>

<body>
	<div class="card m-3">
		<div class="card-header">
			登录
		</div>
		<div class="card-body">
			<form method="POST">
					<?php include_once LIB_DIR . 'auth.php'; echo csrf_hidden_field(); ?>
				<label for="username">用户名：</label>
				<input class="form-control" type="text" id="username" name="username" required><br><br>
				<label for="password">密码：</label>
				<input class="form-control" type="password" id="password" name="password" required><br><br>
				<div class="row justify-content-between">
					<div class="col-3">
						<button class="btn btn-outline-primary" type="submit">登录</button>
					</div>
					<div class="col-2">
						<a href="/api/register.php">注册</a>
					</div>
				</div>
			</form>
		</div>
	</div>
</body>

</html>