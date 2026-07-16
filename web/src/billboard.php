<?php
include_once 'navbar.php';

function get_map_count($pdo, $map_name)
{
  $stmt = '';
  if ($map_name) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM maps WHERE title LIKE :map_name");
    $stmt->bindValue(':map_name', "%$map_name%", PDO::PARAM_STR);
  } else {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM maps");
  }
  $stmt->execute();
  return $stmt->fetchColumn();
}

function get_map_info($pdo, $page_num, $page_size, $map_name = '', $order_by = 'status', $order = 'DESC')
{
  // ORDER BY 白名单校验，防止动态拼接注入
  $allowed_cols = ['status', 'title', 'subscriptions', 'size', 'created_at', 'updated_at'];
  $allowed_dirs = ['ASC', 'DESC'];
  $order_by = in_array($order_by, $allowed_cols, true) ? $order_by : 'status';
  $order    = in_array(strtoupper($order), $allowed_dirs, true) ? strtoupper($order) : 'DESC';

  $stmt = '';
  if ($map_name == '') {
    $stmt = $pdo->prepare("
      SELECT id,title,subscriptions,size,status,preview_url
      FROM maps
      ORDER BY $order_by $order, title $order
      LIMIT :limit OFFSET :offset
    ");
  } else {
    $stmt = $pdo->prepare("
      SELECT id,title,subscriptions,size,status,preview_url
      FROM maps
      WHERE title LIKE :map_name
      LIMIT :offset , :limit
    ");
    $stmt->bindValue(':map_name', '%' . $map_name . '%', PDO::PARAM_STR);
  }
  $offset = ($page_num - 1) * $page_size;
  $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
  $stmt->bindValue(':limit', (int)$page_size, PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function print_search_bar($map_name, $page_num, $page_size)
{
  $src = "billboard.php?page_num=$page_num&page_size=%d&map_name=$map_name";
  $href = array(
    "12" => sprintf($src, 12),
    "24" => sprintf($src, 24),
    "48" => sprintf($src, 48)
  );
  if ($page_size != 12 && $page_size != 24 && $page_size != 48) $page_size = 24;
  $is_size = array(
    "12" => "btn-outline-success",
    "24" => "btn-outline-success",
    "48" => "btn-outline-success"
  );
  $is_size[$page_size] = "btn-success";
  $a_label =  array(
    "12" => sprintf('<a class="btn %s" href=%s>12</a>', $is_size["12"], $href["12"]),
    "24" => sprintf('<a class="btn %s" href=%s>24</a>', $is_size["24"], $href["24"]),
    "48" => sprintf('<a class="btn %s" href=%s>48</a>', $is_size["48"], $href["48"])
  );
  echo <<<HTML
      <form method="get">
        <input name="page_num" value=1 type="hidden">
        <div class="row g-3 align-items-center">
          <div class="col-md-9">
            <label for="map_name" class="form-label text-light">搜索地图</label>
            <input class="form-control" type="text" id="map_name" name="map_name" placeholder="为空显示全部地图" value="$map_name">
          </div>
          <div class="col-md-1">
            <label for="search" class="form-label d-none d-md-block">&nbsp;</label>
            <button title="search" type="submit" id="search" class="btn btn-success w-100">
                <i class="bi bi-search"></i>
            </button>
          </div>
          <div class="col-md-2">
            <label class="form-label text-light">每页显示数量</label>
            <div class="btn-group" role="group" aria-label="Page Size">
              $a_label[12]
              $a_label[24]
              $a_label[48]
            </div>
          </div>
        </div>
      </form>
  HTML;
}

function print_map_info($maps_info)
{
  $length = count($maps_info);
  echo '<div class="row mt-3">';
  for ($i = 0; $i < $length; $i++) {
    $info = $maps_info[$i];
    $id = $info['id'];
    $img_url = $maps_info[$i]['preview_url'];
    $title = $info['title'];
    $subscriptions = num_to_str($info['subscriptions']);
    $size = $info['size'];
    $size = bytes_to_str($size);
    $status = $info['status'];
    switch($status) {
      case 'active':
        $status = '<span class="badge rounded-pill text-bg-success">活跃</span>';
        break;
      case 'updating':
        $status = '<span class="badge rounded-pill text-bg-warning">更新中</span>';
        break;
      case 'abandon':
        $status = '<span class="badge rounded-pill text-bg-danger">已卸载</span>';
        break;
      default:
        $status = '<span class="badge rounded-pill text-bg-secondary">未知</span>';
        break;
    }
    echo <<<HTML
      <div class="col-12 col-md-4 col-lg-3 g-3">
          <a href="/map_info.php?id=$id">
              <div class="card">
                  <img src=$img_url class="card-img" loading="lazy">
                  <div class="card-img-overlay">
                      <div class="my-end">
                          <h5 class="card-title text-light">$title</h5>
                          <p class="card-text text-light"><small>$subscriptions 订阅 | $size | $status</small></p>
                      </div>
                  </div>
              </div>
          </a>
      </div>
    HTML;
  }
  echo "</div>";
}

function print_paginator($map_name, $map_count, $page_size, $page_num, $forsee = 2)
{
  $map_name_safe = htmlspecialchars($map_name);
  $page_count = ceil($map_count / $page_size);
  if ($page_count <= 1) return;
  if ($page_num < 1 || $page_num > $page_count) $page_num = 1;

  $href = "/billboard.php?page_size={$page_size}&map_name={$map_name_safe}&page_num=%d";

  echo <<<HTML
  <nav class="d-flex fixed-bottom justify-content-center" aria-label="Page navigation"
       data-page-count="$page_count" data-page-size="$page_size" data-map-name="$map_name_safe">
    <ul class="pagination justify-content-center">
  HTML;

  if ($page_num > 1) {
    $tmp = sprintf($href, $page_num - 1);
    echo <<<HTML
      <li class="page-item">
        <a class="page-link" href="$tmp" aria-label="Previous"><span aria-hidden="true">&laquo;</span></a>
      </li>
    HTML;
  } else {
    echo <<<HTML
      <li class="page-item disabled">
        <span class="page-link" aria-label="Previous"><span aria-hidden="true">&laquo;</span></span>
      </li>
    HTML;
  }

  if ($page_num > $forsee + 1) {
    $tmp = sprintf($href, 1);
    echo <<<HTML
      <li class="page-item"><a class="page-link" href="$tmp">1</a></li>
    HTML;

    if ($page_num > $forsee + 2) {
      echo <<<HTML
        <li class="page-item disabled"><a class="page-link" href="#" onclick="trans2form(this)">...</a></li>
      HTML;
    }
  }

  for ($i = $page_num - $forsee; $i < $page_num; $i++) {
    if ($i < 1) continue;
    $tmp = sprintf($href, $i);
    echo <<<HTML
      <li class="page-item"><a class="page-link" href="$tmp">$i</a></li>
    HTML;
  }

  $tmp = sprintf($href, $page_num);
  echo <<<HTML
    <li class="page-item active"><a class="page-link" href="$tmp">$page_num</a></li>
  HTML;

  for ($i = $page_num + 1; $i <= $page_num + $forsee; $i++) {
    if ($i > $page_count) break;
    $tmp = sprintf($href, $i);
    echo <<<HTML
      <li class="page-item"><a class="page-link" href="$tmp">$i</a></li>
    HTML;
  }

  if ($page_num + $forsee < $page_count) {
    if ($page_num + $forsee + 1 < $page_count) {
      echo <<<HTML
        <li class="page-item disabled"><a class="page-link" href="#" onclick="trans2form(this)">...</a></li>
      HTML;
    }

    $tmp = sprintf($href, $page_count);
    echo <<<HTML
      <li class="page-item"><a class="page-link" href="$tmp">$page_count</a></li>
    HTML;
  }

  if ($page_num < $page_count) {
    $tmp = sprintf($href, $page_num + 1);
    echo <<<HTML
      <li class="page-item">
        <a class="page-link" href="$tmp" aria-label="Next"><span aria-hidden="true">&raquo;</span></a>
      </li>
    HTML;
  } else {
    echo <<<HTML
      <li class="page-item disabled">
        <span class="page-link" aria-label="Next"><span aria-hidden="true">&raquo;</span></span>
      </li>
    HTML;
  }

  echo <<<HTML
    </ul>
  </nav>
  HTML;
}

?>
<!DOCTYPE HTML>
<html lang="zh-hans">
<?php
$additons = <<<HTML
    <link rel="stylesheet" href="/static/css/custom/billboard.css">
  HTML;
printHeader('评分板', $additons);
?>
<body>
  <?php
  printNavbar('billboard');
  // core.php 已由 bootstrap.php 自动加载
  $pdo = conn_db();
  $page_num  = get_GET('page_num', 1, 1);
  $page_size = get_GET('page_size', 1, 24);
  $map_name  = get_GET('map_name', 0, '');
  $map_count = get_map_count($pdo, $map_name);
  $maps_info = get_map_info($pdo, $page_num, $page_size, $map_name);

  print_search_bar($map_name, $page_num, $page_size);
  print_map_info($maps_info);
  print_paginator($map_name, $map_count, $page_size, $page_num);
  printFooter();
  ?>
<script src="/static/js/custom/billboard.js"></script>
</body>

</html>
