<?php
// config / core / auth 已由 bootstrap.php 自动加载
include_once 'navbar.php';
$isAdmin = check_admin();
$sidecarToken = getenv('SIDECAR_TOKEN') ?: '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<?php
$additions = <<<HTML
<link rel="stylesheet" href="/static/css/custom/dashboard.css">
<script src="/static/js/chart.umd.min.js"></script>
HTML;
printHeader("下载任务监控", $additions);
?>

<body>
    <?php printNavbar('dashboard'); ?>

    <div class="container-fluid">
        <!-- 下载任务 -->
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold fs-5">下载任务</span>
                <small class="text-muted" id="download-update">最后更新: --</small>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-3 col-md-6">
                        <div class="card bg-light"><div class="card-header fw-bold bg-info bg-opacity-25">等待中</div>
                            <div class="card-body overflow-auto p-2" style="max-height:280px" id="download-waiting"></div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card bg-light"><div class="card-header fw-bold bg-warning bg-opacity-25">下载中</div>
                            <div class="card-body overflow-auto p-2" style="max-height:280px" id="download-downloading"></div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card bg-light"><div class="card-header fw-bold bg-success bg-opacity-25">成功</div>
                            <div class="card-body overflow-auto p-2" style="max-height:280px" id="download-success"></div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card bg-light"><div class="card-header fw-bold bg-danger bg-opacity-25">失败</div>
                            <div class="card-body overflow-auto p-2" style="max-height:280px" id="download-fail"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- COS 上传任务 -->
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold fs-5">☁️ COS 上传任务</span>
                <small class="text-muted" id="cos-upload-update">最后更新: --</small>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-3 col-md-6">
                        <div class="card bg-light"><div class="card-header fw-bold bg-info bg-opacity-25">等待中</div>
                            <div class="card-body overflow-auto p-2" style="max-height:280px" id="cos-upload-waiting"></div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card bg-light"><div class="card-header fw-bold bg-warning bg-opacity-25">上传中</div>
                            <div class="card-body overflow-auto p-2" style="max-height:280px" id="cos-upload-uploading"></div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card bg-light"><div class="card-header fw-bold bg-success bg-opacity-25">成功</div>
                            <div class="card-body overflow-auto p-2" style="max-height:280px" id="cos-upload-success"></div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card bg-light"><div class="card-header fw-bold bg-danger bg-opacity-25">失败</div>
                            <div class="card-body overflow-auto p-2" style="max-height:280px" id="cos-upload-fail"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 服务器资源 -->
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold fs-5">🖥️ 服务器资源</span>
                <small class="text-muted" id="metrics-update">最后更新: --</small>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <!-- CPU -->
                    <div class="col-lg-3 col-md-6">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span>⚡</span><span class="fw-bold small text-secondary">CPU</span>
                            <span class="ms-auto small text-muted" id="detail-cpu">--</span>
                        </div>
                        <div class="fs-3 fw-bold" id="val-cpu">--%</div>
                        <div class="chart-wrap"><canvas id="chart-cpu"></canvas></div>
                    </div>
                    <!-- 内存 -->
                    <div class="col-lg-3 col-md-6">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span>🧠</span><span class="fw-bold small text-secondary">内存</span>
                            <span class="ms-auto small text-muted" id="detail-ram">--</span>
                        </div>
                        <div class="fs-3 fw-bold" id="val-ram">--%</div>
                        <div class="chart-wrap"><canvas id="chart-ram"></canvas></div>
                    </div>
                    <!-- 磁盘 -->
                    <div class="col-lg-3 col-md-6">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span>💾</span><span class="fw-bold small text-secondary">磁盘</span>
                            <span class="ms-auto small text-muted" id="detail-disk">--</span>
                        </div>
                        <div class="fs-3 fw-bold" id="val-disk">--%</div>
                        <div class="chart-wrap"><canvas id="chart-disk"></canvas></div>
                    </div>
                    <!-- 网络 -->
                    <div class="col-lg-3 col-md-6">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span>🌐</span><span class="fw-bold small text-secondary">网络</span>
                            <span class="ms-auto small text-muted" id="detail-net">--</span>
                        </div>
                        <div class="fs-3 fw-bold">
                            <span class="text-primary">↓</span><span id="val-net-rx">--</span>
                            <span class="text-success">↑</span><span id="val-net-tx">--</span>
                        </div>
                        <div class="chart-wrap"><canvas id="chart-net"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

<?php if ($isAdmin): ?>
        <!-- 服务管理 -->
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold fs-5">🐳 服务管理</span>
                <small class="text-muted" id="container-update">最后更新: --</small>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush" id="container-list">
                    <div class="list-group-item text-muted">加载中...</div>
                </div>
            </div>
        </div>
        <script>
            // sidecar 认证令牌（仅管理员可见）
            window._SIDECAR_TOKEN = <?= json_encode($sidecarToken) ?>;
        </script>
<?php endif; ?>
    </div>

    <script type="module" src="/static/js/custom/dashboard.js"></script>
    <?php printFooter(); ?>
</body>
</html>
