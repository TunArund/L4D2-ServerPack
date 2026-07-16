<?php
include_once 'navbar.php';
?>
<!DOCTYPE html>
<html>
<?php
$style = <<<HTML
<style>
/* 每屏全高区域 */
.fullscreen-section {
  height: 100vh;
  min-height: 600px;
  position: relative;
  overflow: hidden;
}

/* 视频填满整个区域 */
.fullscreen-video {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

/* Slogan 容器 */
.slogan-carousel {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background: rgba(0, 0, 0, 0.4);
  backdrop-filter: blur(8px);
  border-radius: 1rem;
  padding: 1.5rem 2.5rem;
  color: white;
  font-size: 1.75rem;
  font-weight: bold;
  white-space: nowrap;
}

/* Bootstrap Carousel 自定义 */
.carousel-inner {
  transition: transform 1s ease-in-out;
}
.bilibili-link {
  position: absolute;
  top: 1.5%;
  left: 2%;
  font-size: 2rem;        /* fs-2 等效 */
  font-weight: bold;      /* fw-bold 等效 */
  color: white;           /* text-white 等效 */
  text-decoration: none;  /* 去掉下划线 */
  transition: color 0.3s ease, transform 0.3s ease;
}

.bilibili-link:hover {
  color: #00c8ff;          /* 悬浮高亮 */
  transform: scale(1.08);  /* 悬浮轻微放大 */
}

</style>
HTML;
printHeader('Game Life', $style);
?>

<body>
	<?php
	printNavbar('index');
	?>
	<!-- 全屏视频区 -->
	<section class="fullscreen-section">
		<video autoplay muted loop playsinline preload="metadata" class="fullscreen-video">
			<source src="/static/video/wild_ride_5.mp4" type="video/mp4">
			不支持视频播放。
		</video>
		<!-- Slogan 轮播 -->
		<!-- 轮播文字左对齐 + 放大 -->
		<div id="sloganCarousel" class="carousel slide slogan-carousel position-absolute top-50 start-0 translate-middle-y ps-4"
			data-bs-ride="carousel" data-bs-interval="3000" style="z-index: 10;">
			<div class="carousel-inner text-white">
				<div class="carousel-item active fs-2">✔ 100 Tick</div>
				<div class="carousel-item fs-2">✔ 动态特感</div>
				<div class="carousel-item fs-2">✔ 增量僵尸</div>
				<div class="carousel-item fs-2">✔ 双倍备弹</div>
				<div class="carousel-item fs-2">✔ 三方战役</div>
			</div>
		</div>
		<!-- B站链接 -->
		<a class="bilibili-link" target="_blank"
			href="https://space.bilibili.com/3546804528351812">
			TunArun
		</a>

	</section>


	<!-- Server Info + How to Join 合并 -->
	<div class="container my-4 pt-3 bg-light rounded">
		<h4 class="mb-3">Server Info</h4>
		<ul class="list-group mb-4">
			<li class="list-group-item"><strong>当前地图：</strong><span id="mapName">加载中...</span></li>
			<li class="list-group-item"><strong>在线人数：</strong><span id="playerCount">加载中...</span></li>
			<li class="list-group-item"><strong>服务器状态：</strong><span id="serverStatus">加载中...</span></li>
		</ul>

		<h4 class="mb-3">How to Join?</h4>
		<div class="row">
			<div class="col-md-3">
				<ul class="nav nav-pills flex-column" id="enterMethodTabs" role="tablist">
					<li class="nav-item">
						<a class="nav-link active" id="tab-oneclick" data-bs-toggle="pill" href="#pane-oneclick">一键进入</a>
					</li>
					<li class="nav-item">
						<a class="nav-link" id="tab-ip" data-bs-toggle="pill" href="#pane-ip">IP直连</a>
					</li>
					<li class="nav-item">
						<a class="nav-link" id="tab-list" data-bs-toggle="pill" href="#pane-list">服务器列表</a>
					</li>
					<li class="nav-item">
						<a class="nav-link" id="tab-group" data-bs-toggle="pill" href="#pane-group">Steam组加入</a>
					</li>
				</ul>
			</div>
			<div class="col-md-9">
				<div class="tab-content border p-3 bg-light rounded">
					<div class="tab-pane fade show active" id="pane-oneclick">
						<h5><a class="btn btn-outline-success" href="steam://connect/<?= SERVER_CONNECT ?>">一键连接</a></h5>
						<p>点击按钮自动打开steam-求生之路2并连接服务器：</p>
						<p>tips：记得先进游戏，等待模组完全加载(附加内容旁的红色圈圈消失)后再点哦！</p>
					</div>
					<div class="tab-pane fade" id="pane-ip">
						<h5>IP 直连</h5>
						<p>
							主界面-选项-键盘/鼠标中-控制台-已启用
							在游戏主界面按下 <kbd>~</kbd> 打开控制台，输入：
						</p>
						<div class="d-flex align-items-center">
							<kbd class="mb-0 me-2" id="ipText">connect l4d2.tunarund.top</kbd>
							<button class="btn btn-outline-success btn-sm" onclick="copy(this,'connect l4d2.tunarund.top')">
								复制
							</button>
						</div>
						<p>或复制后按<kbd>Ctrl</kbd>+<kbd>V</kbd>粘贴 然后按<kbd>Enter</kbd></p>
					</div>
					<div class="tab-pane fade" id="pane-list">
						<h5>通过服务器列表加入</h5>
						<p>
							进入游戏 → 按<kbd>~</kbd>打开控制台 → 输入<kbd>openserverbrowser</kbd> -> 按<kbd>Enter</kbd>
							点击<kbd>我喜爱的</kbd> -> 右键添加 -> 输入<kbd>l4d2.tunarund.top</kbd>后按回车<kbd>enter</kbd>
						</p>
					</div>
					<div class="tab-pane fade" id="pane-group">
						<h5>通过 Steam 群组服务器</h5>
						<p>启动游戏后可在右下角群组服务器中进入。</p>
						<a href="steam://openurl/https://steamcommunity.com/groups/tunarundgroup" class="btn btn-outline-success"> 点击加入 Steam 群组</a>
						<a href="https://steamcommunity.com/groups/tunarundgroup" class="btn btn-outline-success" target="_blank">打开 Steam 群组 页面</a>
					</div>
				</div>
			</div>
		</div>
	</div>


	<!--Bottom Audio Player -->
	<audio class=" fixed-bottom" id="bgAudio" autoplay preload="auto" controls loop>
		<source src="/static/audio/dream_world.mp3" type="audio/mpeg">
		您的浏览器不支持音频播放。
	</audio>

	<script src="/static/js/custom/index.js"></script>
	<?php
	printFooter('center');
	?>
</body>

</html>
