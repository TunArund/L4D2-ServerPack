<?php
// config / core / auth 已由 bootstrap.php 自动加载
include_once 'navbar.php';
# POST 处理评论（session 已在 navbar.php 中启动）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
  $comment = htmlspecialchars($_POST['comment']);
  $map_id = $_GET['id'];
  $user_id = $_SESSION['user_id'];

  try {
    $pdo = conn_db();
    $stmt = $pdo->prepare("INSERT INTO comments (map_id, user_id, comment) VALUES (:map_id, :user_id, :comment)");
    $stmt->bindParam(':map_id', $map_id);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':comment', $comment);
    $stmt->execute();
    header("Location: map_info.php?id=$map_id");
  } catch (PDOException $e) {
    echo "评论失败: " . $e->getMessage();
  }
  exit();
}
// get获取地图id
$id = get_GET('id',1,0);
$pdo = conn_db();
//准备查询语句
$stmt = $pdo->prepare("SELECT title,link,steam_id,downlink,description,records,subscriptions,size,img_urls,preview_url,cos_url FROM maps WHERE id=:id");
$stmt->bindValue(':id',  $id, PDO::PARAM_INT);
$stmt->execute();
$maps_info = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($maps_info) {
  $result = $maps_info[0];
  $title = $result['title'];
  $link = $result['link'];
  $steamlink = 'steam://url/CommunityFilePage/'.$result['steam_id'];
  $downlink = $result['downlink'];
  $cos_url = $result['cos_url'];
  $description = $result['description'];
  $records = json_decode($result['records'] ?? '[]');
  $subscriptions = $result['subscriptions'];
  $size = bytes_to_str($result['size']);
  $img_urls = json_decode($result['img_urls'] ?? '[]');
  if(empty($img_urls))$img_urls = array($result['preview_url']);
} else {
  $title = '无此地图';
  $link = '#';
  $downlink = '暂无';
  $cos_url = null;
  $description = '无此地图';
  $records = '';
  $subscriptions = 0;
  $size = 0;
  $img_urls = '';
}
class CarouselGenerator
{
  private $id;
  private $imgSrcList;

  // 构造函数：初始化轮播的ID和图片列表
  public function __construct($id, $imgSrcList)
  {
    $this->id = $id;
    $this->imgSrcList = $imgSrcList;
  }

  // 生成轮播的HTML
  public function render()
  {
    // 检查图片列表是否为空
    if (empty($this->imgSrcList) || !is_array($this->imgSrcList)) {
      return "<p>Error: No images provided for the carousel.</p>";
    }
    $carouselId = htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8');
    // 生成分页指示器
    $indicators = $this->generateIndicators();
    // 生成轮播内容
    $carouselItems = $this->generateCarouselItems();
    // 返回完整的轮播HTML
    return <<<HTML
        <div id="$carouselId" class="carousel slide" data-bs-ride="carousel">
            <div class="carousel-indicators">
                $indicators
            </div>
            <div class="carousel-inner">
                $carouselItems
            </div>
            <button class="carousel-control-prev" type="button" data-bs-target="#$carouselId" data-bs-slide="prev">
                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                <span class="visually-hidden">上一张</span>
            </button>
            <button class="carousel-control-next" type="button" data-bs-target="#$carouselId" data-bs-slide="next">
                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                <span class="visually-hidden">下一张</span>
            </button>
        </div>
        HTML;
  }

  // 生成分页指示器HTML
  private function generateIndicators()
  {
    $indicators = '';
    foreach ($this->imgSrcList as $index => $src) {
      $active = $index === 0 ? 'class="active" aria-current="true"' : '';
      $indicators .= <<<HTML
      <button type="button" data-bs-target="#{$this->id}" data-bs-slide-to="$index" $active aria-label="Slide {$index}"></button>
      HTML;
    }
    return $indicators;
  }

  // 生成轮播内容HTML
  private function generateCarouselItems()
  {
    $items = '';
    foreach ($this->imgSrcList as $index => $src) {
      $active = $index === 0 ? 'active' : '';
      $escapedSrc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
      $items .= <<<HTML
            <div class="carousel-item $active">
                <img src="$escapedSrc" class="d-block w-100" alt="Slide $index">
            </div>
            HTML;
    }
    return $items;
  }
}
function print_records($records)
{
  if ($records) {
    foreach ($records as $record) {
      echo $record;
    }
  } else {
    echo <<<HTML
    <p class="text-light">暂无录像</p>
    HTML;
  }
}
function print_comments($pdo, $map_id)
{
  $is_login = check_login();
  $is_admin = check_admin();
  if ($is_login) {// 已登录，可发表评论
    echo <<<HTML
      <div class="card text-bg-dark mt-3" style="max-width: 18rem;">
        <div class="card-header">发表评论</div>
        <div class="card-body">
          <form method="POST">
                    <?php include_once LIB_DIR . 'auth.php'; echo csrf_hidden_field(); ?>
            <textarea class="form-control" rows=3 name="comment" placeholder="发表评论" required></textarea>
            <button class="btn btn-primary" type="submit">提交评论</button>
          </form>
        </div>
      </div>
    HTML;
  } else {
    echo <<<HTML
      <p class="text-light"><a href="/api/login.php?return_url=/map_info.php?map_id={$map_id}">登录</a>后才能评论</p>
    HTML;
  }
  // 数据库查该地图map_id评论
  $stmt = $pdo->prepare("SELECT comments.comment, comments.created_at, users.username 
                         FROM comments 
                         JOIN users ON comments.user_id = users.id 
                         WHERE comments.map_id = :map_id 
                         ORDER BY comments.created_at DESC");
  $stmt->bindParam(':map_id', $map_id);
  try {
    $stmt->execute();
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (PDOException $e) {
    echo "Error Querying: " . $e->getMessage();
  }
  // 没有评论,退出
  if (empty($comments)) {
    echo <<<HTML
      <p class="text-light">暂无评论</p>
    HTML;
    return;
  }
  // 有评论,显示
  if (!$is_admin) { // 普通用户
    foreach ($comments as $comment) {
      echo <<<HTML
      <div class="card bg-color-darkblue mt-3">
        <div class="card-header">
          <p class="text-light">{$comment['username']}</p>
          <p class="text-light">{$comment['created_at']}</p>
        </div>
        <div class="card-body">
          <p class="card-text text-light">{$comment['comment']}</p>
        </div>
      </div>
      HTML;
    }
  } else {
    foreach ($comments as $comment) {
      $comment_id = $comment['id'];
      echo <<<HTML
      <div class="card bg-color-darkblue mt-3">
        <div class="card-header">
          <p class="text-light">{$comment['username']}</p>
          <p class="text-light">{$comment['created_at']}</p>
        </div>
        <div class="card-body">
          <p class="card-text text-light">{$comment['comment']}</p>
          <form method='POST' action='/api/delete_comment.php' style='display:inline;'>
            <input type='hidden' name='comment_id' value='{$comment_id}'>
            <button class="btn btn-danger" type='submit'>删除</button>
          </form>
        </div>
      </div>
      HTML;
    }
  }
}
?>

<!DOCTYPE HTML>
<html lang="zh-hans">
<?php 
    include_once 'navbar.php';
    $additions='<meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests" />';
    printHeader($title,$additions);
?>
<body class="bg-color-darkerblue">
  <?php
  printNavbar('billboard');
  $carousel = new CarouselGenerator('img_carousel', $img_urls);
  $renderedCarousel = $carousel->render();

  // 构建 CDN 下载按钮
  $cdn_buttons = '';
  if ($cos_url) {
      $safe_cos = htmlspecialchars($cos_url, ENT_QUOTES, 'UTF-8');
      $cdn_buttons .= "<p><a class=\"btn btn-primary\" href=\"{$safe_cos}\" target=\"_blank\">腾讯CDN（直链下载）</a></p>\n";
  }
  $safe_dl = htmlspecialchars($downlink, ENT_QUOTES, 'UTF-8');
  $cdn_buttons .= "<p><a class=\"btn btn-success\" href=\"{$safe_dl}\" target=\"_blank\">SteamCDN（直链下载）</a></p>";

  echo <<<HTML
    <div class="row">
        <div class="col text-light">
            <h1>$title</h1>   
            <p>订阅数: $subscriptions</p>
            <p>大小: $size</p>
            <p><a class="btn btn-success" href="$link" target="_blank">跳转到steam页面</a></p>
            <p><a class="btn btn-success" href="$steamlink">在steam客户端中查看</a></p>
            $cdn_buttons
        </div>
        <div class="col align-self-center">
            $renderedCarousel
        </div>
    </div>
  HTML;
  //读取数据库录像内嵌代码
  print_records($records);
  $desc_html = nl2br(htmlspecialchars($description));
  echo <<<HTML
            <div class="mt-3 mx-auto rounded border border-3 bg-color-darkerblue text-light" style="width: 80%;">
                {$desc_html}
            </div>
        HTML;
  print_comments($pdo, $id);
  ?>

</body>

</html>