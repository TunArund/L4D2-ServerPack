import { formatBytes, formatBits, escHtml } from './tools.js';

// 下载任务面板配置
const downloadPanels = {
    downloading: {
        dom: document.querySelector('#download-downloading'),
        count: 10,
        step: 10,
        max: 100
    },
    waiting: {
        dom: document.querySelector('#download-waiting'),
        count: 10,
        step: 10,
        max: 100
    },
    success: {
        dom: document.querySelector('#download-success'),
        count: 10,
        step: 10,
        max: 100
    },
    fail: {
        dom: document.querySelector('#download-fail'),
        count: 10,
        step: 10,
        max: 100
    }
};

// 获取下载任务
async function getDownloadTasks(stat, cnt) {
    try {
        const response = await fetch('/api/download_tasks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: stat, count: cnt })
        });
        if (!response.ok) throw new Error(`获取下载任务失败,响应码: ${response.status}`);
        const result = await response.json();
        if (!result.success === true) throw new Error(`获取下载任务失败${result.message}`);
        return result.data;
    } catch (error) {
        console.error('获取下载任务失败:', error);
        throw error;
    }
}

async function updateDownloadPanel() {
    const update = document.querySelector('#download-update');
    update.textContent = `最后更新: ${new Date().toLocaleString()}`;
    for (const [status, panel] of Object.entries(downloadPanels)) {
        const tasks = await getDownloadTasks(status, panel.count);
        if (status === 'downloading') {
            refreshDownloadingPanel(tasks, panel.dom);
        } else {
            refreshNonDownloadingPanel(tasks, panel.dom, status);
        }
    }
}

// 记录上一次下载数据用于计算速度
let lastTaskStats = {};

function refreshDownloadingPanel(tasks, dom) {
    dom.innerHTML = '';
    tasks.forEach(task => {
        const progress = Math.floor((task.downloaded_bytes / task.total_bytes) * 100);
        const humanReadable = `${formatBytes(task.downloaded_bytes)} / ${formatBytes(task.total_bytes)}`;
        let speedText = '计算中...';
        const now = Date.now();
        if (lastTaskStats[task.id]) {
            const last = lastTaskStats[task.id];
            const timeDiff = (now - last.time) / 1000;
            if (timeDiff > 0) {
                const bytesDiff = task.downloaded_bytes - last.downloaded;
                speedText = formatBytes(bytesDiff / timeDiff) + '/s';
            }
        }
        lastTaskStats[task.id] = { downloaded: task.downloaded_bytes, time: now };
        const div = document.createElement('div');
        div.className = 'list-group-item';
        div.innerHTML = `
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="fw-bold text-break">${escHtml(task.disk_safe)}</span>
                <small class="text-muted ms-2">${progress}%</small>
            </div>
            <small class="text-secondary">${humanReadable} (${speedText})</small>
            <div class="progress mt-1" style="height:6px">
                <div class="progress-bar bg-success" style="width:${progress}%"></div>
            </div>
        `;
        dom.appendChild(div);
    });
    dom.appendChild(getViewMoreButton('downloading'));
}

function refreshNonDownloadingPanel(tasks, dom, status) {
    dom.innerHTML = '';
    tasks.forEach(task => {
        const div = document.createElement('div');
        div.className = 'list-group-item';
        div.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <span class="fw-bold text-break">${escHtml(task.disk_safe)}</span>
                <small class="text-muted ms-2">${escHtml(task.created_at)}</small>
            </div>
        `;
        dom.appendChild(div);
    });
    dom.appendChild(getViewMoreButton(status));
}

function getViewMoreButton(status) {
    const button = document.createElement('a');
    button.textContent = '查看更多';
    button.href = 'javascript:void(0)';
    button.className = 'btn btn-link btn-sm d-block mt-1';
    button.addEventListener('click', function() {
        if (downloadPanels[status].count >= downloadPanels[status].max) {
            alert(`已达到最大显示数量${downloadPanels[status].max}，无法显示更多任务。`);
        }
        downloadPanels[status].count += downloadPanels[status].step;
        updateDownloadPanel();
    });
    return button;
}

// 初始加载 + 定时刷新
updateDownloadPanel();
setInterval(updateDownloadPanel, 5000);

// ================================================================
// 系统资源监控（Glances API + Chart.js 折线图）
// ================================================================

const MAX_POINTS = 40;           // 折线图历史点数（40×5s ≈ 3.3 分钟）
const INTERVAL   = 5000;         // 刷新间隔 (ms)

// ---- 历史缓冲区 ----
const hist = {
    cpu:   Array(MAX_POINTS).fill(null),
    ram:   Array(MAX_POINTS).fill(null),
    disk:  Array(MAX_POINTS).fill(null),
    rx:    Array(MAX_POINTS).fill(null),
    tx:    Array(MAX_POINTS).fill(null),
};
const labels = Array(MAX_POINTS).fill('');

function push(arr, val) {
    arr.push(val);
    arr.shift();
}

function pushLabel() {
    const t = new Date();
    labels.push(t.getHours().toString().padStart(2,'0') + ':' +
                t.getMinutes().toString().padStart(2,'0') + ':' +
                t.getSeconds().toString().padStart(2,'0'));
    labels.shift();
}

// ---- 颜色工具 ----
function pctColor(val) {
    return val > 85 ? '#e53935' : val > 60 ? '#fb8c00' : '#43a047';
}
function pctFill(val) {
    return val > 85 ? 'rgba(229,57,53,0.12)' : val > 60 ? 'rgba(251,140,0,0.12)' : 'rgba(67,160,71,0.12)';
}

// ---- 通用折线图选项 ----
function lineChartOpts(yUnit) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 300 },
        interaction: { mode: 'index', intersect: false },
        scales: {
            x: {
                display: true,
                ticks: { maxTicksLimit: 5, font: { size: 10 }, autoSkip: true },
                grid: { display: false },
            },
            y: {
                display: true,
                min: 0,
                ticks: {
                    font: { size: 10 },
                    callback: v => v + (yUnit || '%'),
                },
            },
        },
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    // 值在上，时间在下（title=数组, label=单item）
                    title: items => (items[0].raw === null ? '--' : (+items[0].raw).toFixed(1) + (yUnit || '%')),
                    label: item => ' ' + item.label,
                },
            },
        },
    };
}

// CPU / 内存 / 磁盘 — 单线
function makeLineChart(canvasId, borderColor, fillColor, yUnit) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) {
        console.warn('Chart canvas #' + canvasId + ' not found, skipping');
        return null;
    }
    return new Chart(canvas, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                data: Array(MAX_POINTS).fill(null),
                borderColor: borderColor,
                backgroundColor: fillColor,
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 4,
                tension: 0.3,
                fill: true,
            }]
        },
        options: lineChartOpts(yUnit),
    });
}

const chartCPU  = makeLineChart('chart-cpu',  '#43a047', 'rgba(67,160,71,0.1)', '%');
const chartRAM  = makeLineChart('chart-ram',  '#43a047', 'rgba(67,160,71,0.1)', '%');
const chartDisk = makeLineChart('chart-disk', '#43a047', 'rgba(67,160,71,0.1)', '%');

// 网络 — 双线
const netCanvas = document.getElementById('chart-net');
const chartNet = netCanvas ? new Chart(netCanvas, {
    type: 'line',
    data: {
        labels: labels,
        datasets: [
            { data: Array(MAX_POINTS).fill(null), label: '↓ 下载', borderColor: '#2196F3', backgroundColor: 'rgba(33,150,243,0.08)', borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, tension: 0.3, fill: true },
            { data: Array(MAX_POINTS).fill(null), label: '↑ 上传', borderColor: '#4CAF50', backgroundColor: 'rgba(76,175,80,0.08)', borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, tension: 0.3, fill: true },
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 300 },
        interaction: { mode: 'index', intersect: false },
        scales: {
            x: { display: true, ticks: { maxTicksLimit: 5, font: { size: 10 }, autoSkip: true }, grid: { display: false } },
            y: {
                display: true,
                min: 0,
                ticks: { font: { size: 10 }, callback: v => v > 0 ? formatBits(v) + '/s' : '0' },
            },
        },
        plugins: {
            legend: { display: true, position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } },
            tooltip: {
                callbacks: {
                    title: items => formatBits(items[0].raw) + '/s',
                    label: item => ' ' + item.dataset.label + '  ' + item.label,
                },
            },
        },
    },
}) : null;

// ---- 更新折线图数据 ----
function updateLineChart(chart, val) {
    if (!chart) return;
    push(chart.data.datasets[0].data, val);
    chart.data.datasets[0].borderColor = pctColor(val);
    chart.data.datasets[0].backgroundColor = pctFill(val);
    chart.update('none');
}

function updateNetChart(rx, tx) {
    if (!chartNet) return;
    push(chartNet.data.datasets[0].data, rx);
    push(chartNet.data.datasets[1].data, tx);
    chartNet.update('none');
}

// ---- 主循环 ----
async function updateMetrics() {
    try {
        pushLabel();
        const [cpuRes, memRes, fsRes, netRes] = await Promise.all([
            fetch('/monitor-api/api/4/cpu'),
            fetch('/monitor-api/api/4/mem'),
            fetch('/monitor-api/api/4/fs'),
            fetch('/monitor-api/api/4/network'),
        ]);

        if (!cpuRes.ok || !memRes.ok) throw new Error('API error');

        // ---- CPU ----
        const cpuData = await cpuRes.json();
        const cpuPct = cpuData.total;

        updateLineChart(chartCPU, cpuPct);
        document.querySelector('#val-cpu').textContent = cpuPct.toFixed(1) + '%';
        document.querySelector('#detail-cpu').textContent =
            (cpuData.cpucore ? cpuData.cpucore + '核' : '');

        // ---- 内存 ----
        const memData = await memRes.json();
        const memPct = memData.percent;
        const memUsedGB  = ((memData.used  || 0) / 1024 / 1024 / 1024).toFixed(1);
        const memTotalGB = ((memData.total || 0) / 1024 / 1024 / 1024).toFixed(1);

        updateLineChart(chartRAM, memPct);
        document.querySelector('#val-ram').textContent = memPct.toFixed(1) + '%';
        document.querySelector('#detail-ram').textContent = memUsedGB + ' / ' + memTotalGB + ' GB';

        // ---- 磁盘 ----
        const fsList = await fsRes.json();
        let diskPct = 0, diskUsed = '--', diskTotal = '--';
        if (Array.isArray(fsList) && fsList.length) {
            const root = fsList.find(f => ['/', '/rootfs'].includes(f.mount_point))
                      || fsList.reduce((a, b) => (a.percent || 0) > (b.percent || 0) ? a : b);
            diskPct   = root.percent || 0;
            diskUsed  = formatBytes(root.used  || 0);
            diskTotal = formatBytes(root.size  || 0);
        }

        updateLineChart(chartDisk, diskPct);
        document.querySelector('#val-disk').textContent = diskPct.toFixed(1) + '%';
        document.querySelector('#detail-disk').textContent = diskUsed + ' / ' + diskTotal;

        // ---- 网络 ----
        const netList = await netRes.json();
        let rx = 0, tx = 0;
        if (Array.isArray(netList)) {
            // Glances v4 字段：bytes_recv_rate_per_sec / bytes_sent_rate_per_sec（bytes/s）
            const iface = netList.find(n => n.interface_name !== 'lo' && n.speed > 0)
                       || netList.find(n => n.interface_name !== 'lo')
                       || {};
            rx = (iface.bytes_recv_rate_per_sec || 0) * 8;  // bytes/s → bits/s
            tx = (iface.bytes_sent_rate_per_sec || 0) * 8;
        }

        updateNetChart(rx, tx);
        document.querySelector('#val-net-rx').textContent = formatBits(rx) + '/s';
        document.querySelector('#val-net-tx').textContent = formatBits(tx) + '/s';
        document.querySelector('#detail-net').textContent = '↓下载 ↑上传';
        document.querySelector('#metrics-update').textContent =
            '最后更新: ' + new Date().toLocaleTimeString();

    } catch (e) {
        console.error('获取监控指标失败:', e);
    }
}

// 启动 + 定时刷新
updateMetrics();
setInterval(updateMetrics, INTERVAL);

// ================================================================
// 服务管理（Sidecar API，仅管理员可见）
// ================================================================

(function initContainers() {
    const list = document.querySelector('#container-list');
    if (!list) return;  // 非管理员，面板未渲染

    let lastHash = '';  // 智能刷新：状态没变就跳过 DOM 更新

    // 记住哪些容器的日志是展开的（DOM 重建后恢复）
    function getExpandedLogs() {
        const expanded = [];
        list.querySelectorAll('.collapse.show').forEach(el => expanded.push(el.id));
        return expanded;
    }
    function restoreExpandedLogs(ids) {
        ids.forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            new bootstrap.Collapse(el, { toggle: false }).show();
            // 重新拉取日志内容（DOM 重建后为空）
            const name = id.replace('log-', '');
            window._toggleLogs(name);
        });
    }

    async function updateContainers() {
        try {
            const headers = {};
            if (window._SIDECAR_TOKEN) headers['X-Auth-Token'] = window._SIDECAR_TOKEN;
            const res = await fetch('/manage/containers', { headers });
            if (!res.ok) throw new Error('API error ' + res.status);
            const data = await res.json();
            const viewable    = new Set(data.viewable    || []);
            const restartable = new Set(data.restartable || []);
            const containers  = (data.containers || []).filter(c => viewable.has(c.name));

            // 智能刷新：对比状态哈希，没变化就跳过 DOM 重建
            const curHash = JSON.stringify(containers.map(c => c.name + '|' + c.status));
            if (curHash === lastHash) return;

            // DOM 重建前记住展开的日志
            const expanded = getExpandedLogs();
            lastHash = curHash;

            if (!containers.length) {
                list.innerHTML = '<div class="list-group-item text-muted">暂无服务</div>';
            } else {
                list.innerHTML = containers.map(c => {
                    const isUp = /^Up\b/i.test(c.status);
                    const badgeCls = isUp ? 'bg-success' : 'bg-danger';
                    const shortStatus = c.status.length > 30 ? c.status.slice(0, 30) + '…' : c.status;
                    const canRestart = restartable.has(c.name) && isUp;
                    const btnHtml = canRestart
                        ? `<button class="btn btn-outline-danger btn-sm" onclick="window._restartContainer('${escHtml(c.name)}', this)">重启</button>`
                        : '';
                    const logBtn = c.name.startsWith('l4d2-')
                        ? `<button class="btn btn-outline-secondary btn-sm log-toggle collapsed" data-bs-toggle="collapse" data-bs-target="#log-${escHtml(c.name)}" onclick="window._toggleLogs('${escHtml(c.name)}')"><span class="triangle"></span>查看日志</button>`
                        : '';
                    return `
                        <div class="list-group-item">
                            <div class="d-flex align-items-center gap-3 flex-wrap">
                                <strong style="min-width:130px">${escHtml(c.name)}</strong>
                                <span class="badge ${badgeCls}">${escHtml(shortStatus)}</span>
                                <small class="text-muted flex-fill">${escHtml(c.image)}</small>
                                ${btnHtml}${logBtn}
                            </div>
                            <div class="collapse mt-2" id="log-${escHtml(c.name)}">
                                <div class="bg-dark text-light font-monospace small p-3 rounded" style="max-height:300px;overflow:auto;white-space:pre-wrap;"></div>
                            </div>
                        </div>`;
                }).join('');
            }
            document.querySelector('#container-update').textContent =
                '最后更新: ' + new Date().toLocaleTimeString();

            // 恢复之前展开的日志
            restoreExpandedLogs(expanded);
        } catch (e) {
            console.error('获取服务列表失败:', e);
            list.innerHTML = '<div class="list-group-item text-danger">获取失败: ' + escHtml(e.message) + '</div>';
        }
    }

    window._toggleLogs = async function(name) {
        const collapse = document.getElementById('log-' + name);
        const body = collapse && collapse.querySelector('div');
        if (!body) return;
        // 已加载则跳过
        if (body.textContent && body.textContent !== '加载中...') return;
        body.textContent = '加载中...';
        try {
            const headers = {};
            if (window._SIDECAR_TOKEN) headers['X-Auth-Token'] = window._SIDECAR_TOKEN;
            const res = await fetch('/manage/containers/' + encodeURIComponent(name) + '/logs?tail=100', { headers });
            const data = await res.json();
            body.textContent = data.logs || '(空)';
        } catch (e) {
            body.textContent = '获取失败: ' + e.message;
        }
    };

    window._restartContainer = async function(name, btn) {
        if (!confirm('确定重启 ' + name + ' ?')) return;
        btn.disabled = true;
        btn.textContent = '…';
        try {
            const headers = {};
            if (window._SIDECAR_TOKEN) headers['X-Auth-Token'] = window._SIDECAR_TOKEN;
            const res = await fetch('/manage/containers/' + encodeURIComponent(name) + '/restart', { method: 'POST', headers });
            const data = await res.json();
            alert(res.ok ? ('✅ ' + name + ' 已重启') : ('❌ ' + (data.error || '未知错误')));
        } catch (e) {
            alert('请求失败: ' + e.message);
        }
        btn.disabled = false;
        btn.textContent = '重启';
        setTimeout(updateContainers, 2000);
    };

    updateContainers();
    setInterval(updateContainers, 10000);
})();
