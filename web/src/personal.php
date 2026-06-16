<?php
//登录验证
include_once 'api/tools.php';
include_once 'navbar.php';
if (!check_login()) {
	header('Location: /api/login.php?return_url=/personal.php');
	exit();
}
$user_id = intval($_SESSION['user_id']);
$isAdmin = check_admin();
// 获取 tab 和 message_id 参数
$tab = $_GET['tab'] ?? 'profile';
$message_id = isset($_GET['message_id']) ? intval($_GET['message_id']) : null;
$title = '个人中心';
$pdo = conn_db();

// 标记消息为已读
if ($message_id && $tab === 'inbox') {
    $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$message_id, $user_id]);
}

// 删除消息
if ($tab === 'inbox' && isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    $stmt = $pdo->prepare("DELETE FROM messages WHERE id = ? AND user_id = ?");
    $stmt->execute([$delete_id, $user_id]);
    header('Location: /personal.php?tab=inbox');
    exit;
}
/**
 * 打印左侧tab栏
 */
function printTabs($tab = 'profile', $isAdmin = false)
{
	$active_tab['profile'] = '';
	$active_tab['inbox'] = '';
	$active_tab['map_request'] = '';
	$active_tab['map_manage'] = '';
	$active_tab[$tab] = 'active';
	$manage_tag =  '';
	if ($isAdmin) {
		$manage_tag = <<<HTML
						<a href="?tab=map_manage" class="list-group-item list-group-item-action {$active_tab['map_manage']}">
							<i class="	bi bi-gear me-1"></i>地图管理
						</a>
		HTML;
	}
	echo <<<HTML
		<div class="d-sm-block overflow-auto">
			<div class="list-group flex-row flex-lg-column text-center text-lg-start">
				<a href="?tab=profile" class="list-group-item list-group-item-action {$active_tab['profile']}">
					<i class="bi bi-person-circle me-1"></i>账户
				</a>
				<a href="?tab=inbox" class="list-group-item list-group-item-action {$active_tab['inbox']}">
					<i class="bi bi-envelope me-1"></i>收件箱
				</a>
				<a href="?tab=map_request" class="list-group-item list-group-item-action {$active_tab['map_request']}">
					<i class="bi bi-file-earmark-plus me-1"></i>地图申请
				</a>
				{$manage_tag}
			</div>
		</div>

	HTML;
}
/**
 * 打印个人资料tab
 */
function printProfile($pdo, $user_id, $isAdmin)
{
	$stmt = $pdo->prepare("SELECT username, email, created_at FROM users WHERE id = ?");
	$stmt->execute([$user_id]);
	$user = $stmt->fetch(PDO::FETCH_ASSOC);
	$profile = <<<HTML
		<div class="alert alert-danger">未找到用户信息。</div>
	HTML;
	if ($user) {
		$username = htmlspecialchars($user['username']);
		$userrole = ($isAdmin)?"管理员":"普通用户";
		$email = htmlspecialchars($user['email']);
		$created_at = htmlspecialchars($user['created_at']);
		$profile = <<<HTML
		<div class="card">
			<div class="card-header">账户信息</div>
			<div class="card-body">
				<p><strong>用户名：</strong> {$username}</p>
				<p><strong>身份：</strong> {$userrole}</p>
				<p><strong>邮箱：</strong>  {$email}</p>
				<p><strong>入站时间：</strong> {$created_at}</p>
				<a href="/api/logout.php" class="btn btn-danger mt-3">退出登录</a>
			</div>
		</div>
		HTML;
	}
	echo $profile;
}
/**
 * 打印收件箱tab
 */
function printInbox($pdo, $user_id)
{
    $stmt = $pdo->prepare("SELECT id, title, message, is_read, created_at FROM messages WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $detail = '';
    $selected_id = isset($_GET['message_id']) ? intval($_GET['message_id']) : 0;

    if (!$msgs) {
        $list = '<p class="text-muted">暂无消息。</p>';
    } else {
        $items = '';
        foreach ($msgs as $msg) {
            $id = $msg['id'];
            $t = htmlspecialchars($msg['title']);
            $time = htmlspecialchars($msg['created_at']);
            $badge = $msg['is_read'] ? '' : ' <span class="badge bg-danger ms-1">未读</span>';
            $active = ($id === $selected_id) ? ' active' : '';

            $items .= <<<HTML
                <a href="?tab=inbox&message_id={$id}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center{$active}">
                    <span>{$t}{$badge}</span>
                    <small class="text-muted ms-2 text-end" style="min-width:140px">{$time}
                        <a href="?tab=inbox&delete_id={$id}" class="btn btn-outline-danger btn-sm ms-2" onclick="return confirm('确定删除？')" title="删除">✕</a>
                    </small>
                </a>
            HTML;

            // 查看具体消息
            if ($id === $selected_id) {
                $body = nl2br(htmlspecialchars($msg['message']));
                $detail = <<<HTML
                    <div class="card mt-3">
                        <div class="card-header d-flex justify-content-between">
                            <strong>{$t}</strong>
                            <small class="text-muted">{$time}</small>
                        </div>
                        <div class="card-body">{$body}</div>
                    </div>
                HTML;
            }
        }
        $list = '<div class="list-group">' . $items . '</div>';
    }

    echo <<<HTML
    <div class="card">
        <div class="card-header">收件箱</div>
        <div class="card-body">
            {$detail}
            {$list}
        </div>
    </div>
    HTML;
}
/**
 * 打印地图申请tab
 */
function printMapRequest($isAdmin)
{
	$approve_btn = '';
	if ($isAdmin) $approve_btn = <<<HTML
		<button type="button" class="btn btn-success btn-sm" data-action="batchApprove">批准选中项</button>
	HTML;
	$requests_tag = <<<HTML
			<div class="mb-3">
				<label for="map-link" class="form-label text-light">Steam地图链接</label>
				<input type="url" name="map-link" id="map-link" class="form-control" placeholder="https://steamcommunity.com/sharedfiles/filedetails/?id=1234567890" required>
				<button class="btn btn-primary mt-2" data-action="save_button">提交申请</button>
			</div>
			<!-- 显示申请列表表格 -->
			<form id="request-form">
				<div class="mb-2">
					<button type="button" class="btn btn-danger btn-sm" data-action="batchDelete">删除选中项</button>
					{$approve_btn}
				</div>
					<table class="table table-light table-hover text-nowrap">
						<thead class="table-light">
							<tr>
								<th scope="col"><input type="checkbox" data-action="toggleAll" class="form-check-input"></th>
								<th scope="col">地图名</th>
								<th scope="col">大小</th>
								<th scope="col">Steam链接</th>
								<th scope="col">添加时间</th>
								<th scope="col">状态</th>
								<th scope="col">操作</th>
								<th scope="col">说明</th>
							</tr>
						</thead>
						<tbody id="map-request-body"> </tbody>
					</table>
			</form>
			<nav id="map-request-pagination"></nav>
			<script type="module" src="/static/js/custom/map-request.js"></script>
	HTML;
	echo $requests_tag;
}
function printMapManage()
{
	$card_tag = <<<HTML
		<table class="table table-light table-hover text-nowrap">
			<thead class="table-light">
				<tr>
					<th scope="col"><input type="checkbox" data-action="toggleAll" class="form-check-input"></th>
					<th scope="col" data-sort="title">地图名 <span class="sort-icon"></span></th>
					<th scope="col" data-sort="size">大小 <span class="sort-icon"></span></th>
					<th scope="col">Steam 链接</th>
					<th scope="col" data-sort="version">更新时间 <span class="sort-icon"></span></th>
					<th scope="col" data-sort="status">状态 <span class="sort-icon"></span></th>
					<th scope="col">操作</th>
				</tr>
			</thead>
			<tbody id="map-manage-body"> </tbody>
		</table>
		<nav id="map-manage-pagination"></nav>
		<script type="module" src="/static/js/custom/map-manage.js"></script>
	HTML;
	echo $card_tag;
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<?php
include_once 'navbar.php';
printHeader($title);
?>

<body>
	<?php printNavbar($title); ?>
		<div class="row flex-column flex-md-row">
			<!-- 左侧 Tab 移动端变上方按钮组-->
			<div class="col-12 col-md-auto mb-2 mb-md-0" style="min-width: 180px;">
				<?php printTabs($tab, $isAdmin); ?>
			</div>
			<!-- 右侧内容区域 -->
			<div class="col ps-md-3">
				<?php
				switch ($tab) {
					case 'profile':
						printProfile($pdo, $user_id, $isAdmin);
						break;
					case 'inbox':
						printInbox($pdo, $user_id);
						break;
					case 'map_request':
						printMapRequest($pdo, $user_id, $isAdmin);
						break;
					case 'map_manage':
						printMapManage();
						break;
					default:
						printProfile($pdo, $user_id, $isAdmin);
						break;
				}
				?>
			</div>
		<?php printFooter(); ?>
</body>

</html>