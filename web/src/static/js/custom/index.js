function copy(elem,text) {
    const originalText = elem.innerText;
    
    if (!text) {
        console.error("[copy()]text element not found");
        return;
    }
    
    navigator.clipboard.writeText(text)
        .then(() => {
            elem.innerText = "复制成功√";
            setTimeout(() => {
                elem.innerText = originalText;
            }, 2000);
        })
        .catch(err => {
            console.error("复制失败:", err);
            elem.innerText = "复制失败×";
            setTimeout(() => {
                elem.innerText = originalText;
            }, 2000);
        });
}


document.addEventListener('DOMContentLoaded', function () {
    
    fetch('https://api.gamemonitoring.net/servers/6816344')  // 或替换为真实 API 地址
    .then(res => res.json())
    .then(data => {
        const r = data.response;
        document.getElementById('mapName').innerText = r.map || "未知地图";
        document.getElementById('playerCount').innerText = `${r.numplayers}/${r.maxplayers}`;
        document.getElementById('serverStatus').innerText = r.status ? "在线 ✅" : "离线 ❌";
    })
    .catch(() => {
        document.getElementById('mapName').innerText = "加载失败";
        document.getElementById('playerCount').innerText = "加载失败";
        document.getElementById('serverStatus').innerText = "加载失败";
    });
});
