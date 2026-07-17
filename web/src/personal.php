<?php
// config / core / auth 已由 bootstrap.php 自动加载
include_once 'navbar.php';
if (!check_login()) {
    header('Location: /api/login.php?return_url=/personal.php');
    exit();
}
$user_id = intval($_SESSION['user_id']);
$isAdmin = check_admin();
$tab = $_GET['tab'] ?? 'profile';
$message_id = isset($_GET['message_id']) ? intval($_GET['message_id']) : null;
$title = '个人中心';
$pdo = conn_db();

// ---- 副作用操作（标记已读、删除、批量操作） ----

if ($message_id && $tab === 'inbox') {
    mark_message_read($message_id, $user_id);
}

if ($tab === 'inbox' && isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    delete_message($delete_id, $user_id);
    header('Location: /personal.php?tab=inbox');
    exit;
}

if ($tab === 'inbox' && isset($_GET['mark_all_read'])) {
    mark_all_messages_read($user_id);
    header('Location: /personal.php?tab=inbox');
    exit;
}

if ($tab === 'inbox' && !empty($_GET['delete_ids'])) {
    $ids = array_map('intval', (array)$_GET['delete_ids']);
    delete_messages($ids, $user_id);
    header('Location: /personal.php?tab=inbox');
    exit;
}

// ---- 页面组件 ----

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
                <i class="bi bi-gear me-1"></i>地图管理
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

function printProfile($pdo, $user_id, $isAdmin)
{
    $result = find_user_by_id($user_id);
    $user = $result['success'] ? $result['data'] : null;
    $profile = <<<HTML
        <div class="alert alert-danger">未找到用户信息。</div>
    HTML;
    if ($user) {
        $username = htmlspecialchars($user['username']);
        $userrole = ($isAdmin) ? "管理员" : "普通用户";
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

function printInbox($pdo, $user_id)
{
    $result = list_messages_by_user($user_id);
    $msgs = $result['success'] ? $result['data'] : [];

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
    HTML;
}

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
        <div class="row" role="alert">
            <a class="btn btn-info" href="https://steamcommunity.com/app/550/workshop/" target="_blank" rel="noopener">🎮 打开创意工坊</a>
            <small class="text-light mb-0">⚠️ 仅支持<strong>地图</strong>类型，无需材质 (Texture) / 音频 (Audio) 资源</small>
        </div>
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
        <script type="module" src="/static/js/custom/map_request.js"></script>
    HTML;
    echo $requests_tag;
}

function printMapManage()
{
    $card_tag = <<<HTML
        <div class="mb-2 d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-primary btn-sm" data-action="triggerUpdateAll">
                <i class="bi bi-arrow-repeat me-1"></i>检查更新
            </button>
            <button type="button" class="btn btn-info btn-sm" data-action="triggerCosSync">
                <i class="bi bi-cloud-arrow-up me-1"></i>COS 同步
            </button>
            <span id="trigger-status" class="text-muted small align-self-center"></span>
        </div>
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
        <script type="module" src="/static/js/custom/map_manage.js"></script>
    HTML;
    echo $card_tag;
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<?php printHeader($title); ?>

<body>
    <?php printNavbar($title); ?>
    <div class="container-fluid">
        <div class="row flex-column flex-md-row">
            <div class="col-12 col-md-auto mb-2 mb-md-0" style="min-width: 180px;">
                <?php printTabs($tab, $isAdmin); ?>
            </div>
            <div class="col ps-md-3">
                <?php
                switch ($tab) {
                    case 'profile':     printProfile($pdo, $user_id, $isAdmin); break;
                    case 'inbox':       printInbox($pdo, $user_id);            break;
                    case 'map_request': printMapRequest($isAdmin);             break;
                    case 'map_manage':  printMapManage();                      break;
                    default:            printProfile($pdo, $user_id, $isAdmin); break;
                }
                ?>
            </div>
        </div>
    </div>
    <?php printFooter(); ?>
<script src="/static/js/custom/personal.js"></script>
</body>

</html>
