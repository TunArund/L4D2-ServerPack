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

// 全部标为已读
if ($tab === 'inbox' && isset($_GET['mark_all_read'])) {
    $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$user_id]);
    header('Location: /personal.php?tab=inbox');
    exit;
}

// 批量删除
if ($tab === 'inbox' && !empty($_GET['delete_ids'])) {
    $ids = array_map('intval', (array)$_GET['delete_ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("DELETE FROM messages WHERE id IN ($placeholders) AND user_id = ?");
    $stmt->execute([...$ids, $user_id]);
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
 * 打印收件箱tab — 紧凑排版、内联展开、批量操作
 */
function printInbox($pdo, $user_id)
{
    $stmt = $pdo->prepare("SELECT id, title, message, is_read, created_at FROM messages WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$msgs) {
        echo <<<HTML
        <div class="card">
            <div class="card-header">收件箱</div>
            <div class="card-body"><p class="text-muted mb-0">暂无消息。</p></div>
        </div>
        HTML;
        return;
    }

    $unread = count(array_filter($msgs, fn($m) => !$m['is_read']));

    $items = '';
    foreach ($msgs as $msg) {
        $id   = $msg['id'];
        $t    = htmlspecialchars($msg['title']);
        $time = htmlspecialchars($msg['created_at']);
        $body = nl2br(htmlspecialchars($msg['message']));
        $bold = $msg['is_read'] ? 'text-muted' : 'fw-bold';
        $badge = $msg['is_read'] ? '' : '<span class="badge bg-danger ms-1">未读</span>';

        $items .= <<<ITEM
        <div class="list-group-item py-2 px-3">
            <div class="d-flex align-items-center gap-2">
                <input type="checkbox" name="delete_ids[]" value="{$id}" class="form-check-input inbox-check flex-shrink-0">
                <button class="btn btn-link text-start text-decoration-none p-0 {$bold} flex-grow-1"
                        data-bs-toggle="collapse" data-bs-target="#msg-{$id}"
                        aria-expanded="false" onclick="markRead({$id}, this)">
                    {$t}{$badge}
                </button>
                <small class="text-muted text-end flex-shrink-0">{$time}</small>
            </div>
            <div class="collapse mt-2" id="msg-{$id}">
                <div class="bg-light rounded p-2 small border-start border-3 border-primary">{$body}</div>
            </div>
        </div>
        ITEM;
    }

    echo <<<HTML
    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>收件箱 <span class="text-muted small">({$unread} 未读)</span></span>
            <div class="d-flex gap-2">
                <a href="?tab=inbox&mark_all_read=1" class="btn btn-outline-secondary btn-sm">全部标为已读</a>
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="batchDelete()">删除选中</button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="list-group list-group-flush">{$items}</div>
        </div>
    </div>
    <script>
    function markRead(id, btn) {
        fetch('/personal.php?tab=inbox&message_id=' + id).then(() => {
            btn.classList.remove('fw-bold');
            btn.classList.add('text-muted');
            const b = btn.querySelector('.badge');
            if (b) b.remove();
        });
    }
    function batchDelete() {
        const cbs = document.querySelectorAll('.inbox-check:checked');
        if (!cbs.length) { alert('请先选择要删除的消息'); return; }
        if (!confirm('确定删除 ' + cbs.length + ' 条消息？')) return;
        const p = new URLSearchParams();
        p.set('tab', 'inbox');
        cbs.forEach(c => p.append('delete_ids[]', c.value));
        location.href = '/personal.php?' + p.toString();
    }
    </script>
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