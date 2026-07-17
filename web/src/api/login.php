<?php
// config 已由 bootstrap.php 自动加载
// core / auth 已由 bootstrap.php 自动加载

$error_msg = '';

// 处理登录请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
		$error_msg = "无效的请求，请刷新页面重试。";
	} else {
		$username = get_POST('username');
		$password = get_POST('password');
		// 验证用户输入
		if (empty($username) || empty($password)) {
			$error_msg = "用户名和密码不能为空。";
		} else {
			// 查询用户信息
			$result = find_user_by_username($username);
			if (!$result['success']) {
				$error_msg = "查询失败：" . $result['message'];
			} else {
				$user = $result['data'];
				if ($user && password_verify($password, $user['hashpass'])) {
					// 登录成功
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
					$error_msg = "用户名或密码错误。";
				}
			}
		}
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
	<link rel="stylesheet" href="/static/css/custom/global.css">
	<link rel="shortcut icon" href="/static/img/favicon.ico">
</head>

<body class="d-flex align-items-center" style="padding-top:0">
	<div class="container">
		<div class="row justify-content-center">
			<div class="col-12 col-md-8 col-lg-6">
				<div class="card shadow-sm border-0 overflow-hidden">
					<div class="card-header bg-color-darkblue text-light fw-bold">
						登录
					</div>
					<div class="card-body">
						<?php if ($error_msg): ?>
						<div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error_msg); ?></div>
						<?php endif; ?>
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
			</div>
		</div>
	</div>
</body>

</html>