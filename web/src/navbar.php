<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
function printHeader($title = "TunArund's Server", $additons = '')
{
	echo <<<HTML
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title>TunArund {$title}</title>
			<link href="/static/css/bootstrap.min.css" rel="stylesheet">
			<script src="/static/js/bootstrap.bundle.min.js"></script>
			<link href="/static/font/bootstrap-icons.min.css" type="text/css" rel="stylesheet" />
			<link rel="shortcut icon" href="/static/img/favicon.ico">
			
			<style>
				body {
				margin: 0px;
				background-image: url("/static/img/blue-guy.jpg");
				background-size: cover;
				padding-top: 60px; /* Bootstrap 默认 navbar 高度为 56px，可视情况调高 */
			}
				.navbar {
					backdrop-filter: blur(12px); /* 毛玻璃模糊 */
					background-color: rgba(42, 71, 94, 0.75); /* 半透明深蓝 */
					-webkit-backdrop-filter: blur(12px);
					box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
					min-height: 60px;
				}
			.navbar .form-control,
			.navbar .btn {
				height: 38px;
				padding: 0.375rem 0.75rem;
				font-size: 0.9rem;
				white-space: nowrap;
			}
			.bg-color-darkblue {
				background-color: #2a475e;
			}
			.bg-color-darkerblue {
				background-color: #1d2838;
			}
			
			.link-hover-custom {
				--bs-icon-link-transform: translate3d(0, -0.2rem, 0);
				--bs-link-hover-color-rgb: 240, 192, 64;
			}
			</style>
			{$additons}
		</head>
		HTML;
}
function printNavbar($title)
{
	$pages = ['index', 'billboard', 'maps', 'dashboard'];
	$active = array_fill(0, count($pages), '');
	$index = array_search($title, $pages) ?: 0;
	$active[$index] = 'active';
	if (isset($_SESSION['user_name'])) { //已登录
		$username = $_SESSION['user_name'];
		$account = <<<HTML
		<div class="d-flex align-items-center ms-2">
			<!-- 通知图标组件 -->
			<div class="position-relative">
				<a href="/personal.php?tab=inbox" id="inbox-link"
				class="icon-link icon-link-hover link-hover-custom text-light position-relative">
					<i class="bi bi-envelope fs-5"></i>
					<span id="inbox-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" style="font-size: 0.7em;">0</span>
				</a>
				<div id="inbox-preview" class="dropdown-menu dropdown-menu-end p-2 shadow-sm" style="min-width: 280px; display: none;">
					<div class="text-muted small mb-2">最新未读消息</div>
					<ul class="list-unstyled mb-0" id="inbox-preview-list" style="max-height: 200px; overflow-y: auto;"></ul>
				</div>
			</div>
			<!-- 用户头像组件 -->
			<div class="dropdown ms-2">
				<a class="dropdown-toggle p-0 nav-link text-light" role="button" data-bs-toggle="dropdown" aria-expanded="false">
					欢迎,{$username}
				</a>
				<ul class="dropdown-menu">
					<li class="dropdown-item p-0"><a class="nav-link" href="/personal.php">个人空间</a></li>
					<li class="dropdown-item p-0"><a class="nav-link text-danger" href="#" onclick="relocation('/api/logout.php')">退出登录</a></li>
				</ul>
			</div>
		</div>
	HTML;
	} else { //未登录
		$account = <<<HTML
		<div class="d-flex align-items-center gap-2">
		<a class="btn btn-outline-light btn-sm px-3" href="#" onclick="relocation('/api/login.php')">登录</a>
		<a class="btn btn-light btn-sm px-3 text-dark" href="/api/register.php">注册</a>
		</div>
		HTML;
	}
	// 合并输出 左侧导航链接 搜索 右侧登录注册
	echo <<<HTML
		<nav class="navbar navbar-expand-lg bg-color-darkblue navbar-dark fixed-top">
		<div class="container-fluid">
				<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavAltMarkup" aria-controls="navbarNavAltMarkup" aria-expanded="false" aria-label="Toggle navigation">
					<span class="navbar-toggler-icon"></span>
				</button>
				<a class="navbar-brand" href="/">
						<img src="/static/img/favicon.ico" alt="Logo" width="32" height="32" class="d-inline-block align-text-top">
					TunArund	
				</a>
				<div class="collapse navbar-collapse" id="navbarNavAltMarkup">
					<ul class="navbar-nav">
						<li class="nav-item">
							<a class="nav-link {$active[0]}" href="/">主页</a>
						</li>
						<li class="nav-item">
							<a class="nav-link {$active[1]}" href="/billboard.php">地图列表</a>
						</li>
						<li class="nav-item">
							<a class="nav-link {$active[3]}" href="/dashboard.php">监控中心</a>
						</li>
						<!-- <li class="nav-item">
							<a class="nav-link {$active[2]}" href="/addons/workshop">地图文件</a>
						</li> -->
						<li class="nav-item">
							<a class="btn btn-outline-success" href="steam://connect/82.156.112.164:27015">
								<i class="bi bi-play-fill">一键进服</i>
							</a>
						</li>
					</ul>
					<form class="d-flex ms-auto mt-2 mt-lg-0" role="search" action="/billboard.php" method="GET">
							<input class="form-control me-2" type="text" name="map_name" placeholder="Search" aria-label="Search">
							<button class="btn btn-outline-success" type="submit">搜索</button>
					</form>
				</div>
				{$account}
		</div>
		</nav>
		HTML;
	//输出js
	echo <<<HTML
			<script src="/static/js/custom/navbar.js"></script>
		HTML;
}

function printFooter(string $loc='center')
{
	switch($loc){
		case 'center':
			$loc = "d-flex justify-content-$loc";break;
		case 'start':
			$loc = "d-flex justify-content-$loc";break;
		case 'end':
			$loc = "d-flex justify-content-$loc";break;
		default:
			$loc = "d-flex justify-content-center";
	}
	echo <<<HTML
	<footer class="footer $loc mt-auto bg-light text-muted small py-2">
	<a class="text-muted me-2" href="https://beian.miit.gov.cn/" target="_blank">豫ICP备2025106241号-1</a>
	<img src="/static/img/备案图标.png" alt="备案图标" style="height: 16px; vertical-align: text-bottom;">
	<a class="text-muted ms-2" href="https://beian.mps.gov.cn/#/query/webSearch?code=41010402003119" rel="noreferrer" target="_blank">豫公网安备41010402003119号</a>
	</footer>
	HTML;
}
