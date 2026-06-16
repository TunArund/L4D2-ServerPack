export function json_success(data) {
    return {
        success: true,
        data: data
    }
}
export function json_error(msg) {
    return {
        success: false,
        message: msg
    }
}
export function formatFileSize(bytes) {
    if (!bytes || bytes < 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.max(0, Math.floor(Math.log(bytes) / Math.log(k)));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// 别名（兼容旧调用）
export const formatBytes = formatFileSize;

export function formatBits(bps) {
    if (!bps || bps < 0) return '0 bps';
    const units = ['bps', 'Kbps', 'Mbps', 'Gbps'];
    const i = Math.max(0, Math.min(Math.floor(Math.log(bps) / Math.log(1000)), units.length - 1));
    return (bps / Math.pow(1000, i)).toFixed(1) + ' ' + units[i];
}

export function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

export function formatTimestamp(timestampInSeconds) {
    const date = new Date(timestampInSeconds * 1000); // 转为毫秒
    const Y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0'); // 月份从0开始
    const d = String(date.getDate()).padStart(2, '0');
    const H = String(date.getHours()).padStart(2, '0');
    const i = String(date.getMinutes()).padStart(2, '0');
    const s = String(date.getSeconds()).padStart(2, '0');
    return `${Y}-${m}-${d} ${H}:${i}:${s}`;
}
/**
 * 全部勾选/取消勾选
 * 选中input[data-action="toggle"]
 * @type {boolean} checked
 */
export function toggleAll(checked) {
    document.querySelectorAll('input[data-action="toggle"]').forEach(cb => {
        cb.checked = checked;
    });
}
/**
 * 获取所有data-toggle=self的input标签的data-id
 * @param {number} id
 * @return {Array} ids [1,2,3,...]
 */
export function getSelectedIds() {
    //获取元素
    const elems = [...document.querySelectorAll('input[data-action="toggle"]:checked')];
    //获取id
    const ids = elems.map(elem => {
        // 将data-id转换为整数
        const id = parseInt(elem.dataset.id, 10);
        // 检查是否为有效数字
        return isNaN(id) ? null : id;
    });
    //过滤id
    return ids.filter(id => id !== null && id >= 0);
}
/**
 * 渲染分页器
 * @param {string} page_func 跳转js函数引用 loadMapList(total,page)
 * @param {HTMLElement} nav_elem <nav>元素
 * @param {number} page 当前页码 
 * @param {number} page_size 每页显示数量
 * @param {number} total 总条目数量
 * @param {number} forsee 当前页码前后各显示多少页
 * @returns 
 */
export function renderPagination(page_func, nav_elem, total, page = 1, page_size = 20, forsee = 2) {
    if (!nav_elem || !page || !page_size || !total) return false;
    // 检查值是否为正整数
    function check_uint(value) {
        //检查value类型是undefined\null还是number
        if (typeof value !== 'number') return false;
        // 检查值是否为整数
        if (!Number.isInteger(value)) return false;
        // 检查值是否大于0
        if (value <= 0) return false;
        return true;
    }
    if (!check_uint(page) || !check_uint(page_size) || !check_uint(total)) return false;
    //计算总页数
    const totalPages = Math.ceil(total / page_size); // 计算总页数
    if (page < 1 || page > totalPages) page = 1;//检查page是否在合理范围内
    //内部工具函数
    function getPageItem(inner_elem, status = 'default') {
        const page_item = document.createElement("li");
        switch (status) {
            case 'active':
                page_item.className = "page-item active";
                page_item.setAttribute("aria-current", "page");
                break;
            case 'disabled':
                page_item.className = "page-item disabled";
                break;
            default:
                page_item.className = "page-item";
        }
        page_item.appendChild(inner_elem);
        return page_item;
    }
    function getPageLink(inner_elem, page_num) {
        let page_link = document.createElement("button");
        page_link.className = "page-link";
        page_link.appendChild(inner_elem);
        page_link.addEventListener('click', function (e) {
            page_func(total, page_num);
        });
        return page_link;
    }
    function getEllipsis() {  
        function getPageJump(){
            const page_jump = document.createElement("input");
            page_jump.className = "page-link";
            page_jump.type = "number";
            page_jump.min = 1;
            page_jump.max = totalPages;
            page_jump.addEventListener('keypress', function (event) {
                if (event.key !== 'Enter') return;
                const page_num = parseInt(page_jump.value);
                if (page_num < 1 || page_num > totalPages)
                    return;
                page_func(total, page_num);
            });
            return page_jump;
        }
        const ellipsis = document.createElement('button');
        ellipsis.className = "page-link";
        ellipsis.innerText = "...";
        ellipsis.addEventListener('click', function () {
            const page_jump = getPageJump();
            this.replaceWith(page_jump);
            page_jump.focus();
            page_jump.select();
        });
        return ellipsis;
    }

    // 上一页item «
    const prev_icon = document.createElement("span");
    prev_icon.ariaHidden = "true";
    prev_icon.innerText = "«";
    const prev_link = getPageLink(prev_icon, page - 1);
    let status = 'disabled';
    if (page > 1) status = 'default';
    let prev_item = getPageItem(prev_link, status);

    // 第一页item 1
    let first_item = document.createTextNode('');
    if (page != 1) {
        const first_item_text = document.createTextNode('1');
        const first_item_link = getPageLink(first_item_text, 1);
        first_item = getPageItem(first_item_link, 'default');
    }

    // 左省略item ...，后续添加输入页码跳转
    const ellipsis_link = getEllipsis();
    let left_ellipsis_item = document.createTextNode('');
    if (page - forsee - 1 > 1) {
        left_ellipsis_item = getPageItem(ellipsis_link, 'default');
    }

    // 左for_see个item ${page-forsee}~${page-1}
    let left_forsee_items = [];//从右向左循环前插
    for (let i = page - 1; i >= page - forsee && i > 1; i--) {
        const page_text = document.createTextNode(i);
        const page_link = getPageLink(page_text,i);
        left_forsee_items.unshift(getPageItem(page_link, 'default'));
    }

    //当前页item ${page}
    const current_page_text = document.createTextNode(page);
    const current_page_link = getPageLink(current_page_text,page);
    const current_page_item = getPageItem(current_page_link, 'active');

    // 右for_see个item ${page+1}~${page+forsee}
    let right_forsee_items = [];
    for (let i = page + 1; i < totalPages && i <= page + forsee; i++) {
        const page_text = document.createTextNode(i);
        const page_link = getPageLink(page_text,i);
        right_forsee_items.push(getPageItem(page_link, 'default'));
    }

    // 右省略item ...，点击后可输入页码，回车跳转
    let right_ellipsis_item = document.createTextNode('');
    if (page + forsee + 1 < totalPages) {
        right_ellipsis_item = getPageItem(ellipsis_link, 'default');
    }

    // 最后一页item ${totalPages}
    const last_item_text = document.createTextNode(totalPages);
    const last_item_link = getPageLink(last_item_text,totalPages)
    let last_item = getPageItem(last_item_link, 'default');
    //如果当前页码就是最后一页
    if (page == totalPages) {
        last_item = document.createTextNode('');
    }
    // 下一页item »
    const next_icon = document.createElement("span");
    next_icon.ariaHidden = "true";
    next_icon.innerText = "»";
    const next_link = getPageLink(next_icon,page + 1);
    status = 'disabled';
    if (page < totalPages) status = 'default';
    const next_item = getPageItem(next_link, status);

    //先将item添加到ul_elem
    const ul_elem = document.createElement("ul");
    ul_elem.className = "pagination justify-content-center";
    ul_elem.appendChild(prev_item);
    ul_elem.appendChild(first_item);
    ul_elem.appendChild(left_ellipsis_item);
    left_forsee_items.forEach(item => ul_elem.appendChild(item));
    ul_elem.appendChild(current_page_item);
    right_forsee_items.forEach(item => ul_elem.appendChild(item));
    ul_elem.appendChild(right_ellipsis_item);
    ul_elem.appendChild(last_item);
    ul_elem.appendChild(next_item);
    //后再加入nav_elem
    nav_elem.innerHTML = '';
    nav_elem.appendChild(ul_elem);
    return true;
}

// ---- 同时挂载到 window，供非 module 脚本使用 ----
window.formatFileSize = formatFileSize;
window.formatBytes     = formatBytes;
window.formatBits      = formatBits;
window.formatTimestamp = formatTimestamp;
window.escHtml         = escHtml;
window.toggleAll       = toggleAll;
window.getSelectedIds  = getSelectedIds;
window.renderPagination = renderPagination;



