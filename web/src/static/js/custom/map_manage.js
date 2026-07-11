
function getStatusBadge(status) {
	switch (status) {
		case 'updating':
			return '<span class="badge bg-info">updating</span>';
		case 'active':
			return '<span class="badge bg-success">active</span>';
		case 'abandon':
			return '<span class="badge bg-warning text-dark">abandon</span>';
		default:
			return '<span class="badge bg-secondary">未知</span>';
	}
}
async function getTotalMaps() {
	const res = await fetch('/api/map_manage.php?action=count');
	const data = await res.json();
	if (!data.success) {
		alert("获取地图总数失败：" + data.message);
		return false;
	}
	return data.data;
}

import {
	renderPagination,
	formatFileSize,
	formatTimestamp,
	json_success,
	json_error
} from "./tools.js";

async function loadMapList(total = 0, page = 1, page_size = 15, order_by = 'status', order = 'DESC') {
	const manageBody = document.getElementById("map-manage-body");
	if (!manageBody) return;
	const limit = page_size; // 每页显示的条数
	const offset = (page - 1) * page_size;//从第几条开始显示
	// 自动加载地图列表
	const res = await fetch(`/api/map_manage.php?action=list&limit=${limit}&offset=${offset}&order_by=${order_by}&order=${order}`)
	const data = await res.json();
	if (!data.success) {
		alert("加载地图列表失败：" + data.message);
		return;
	}
	manageBody.innerHTML = '';
	data.data.forEach(map => {
		const tr = document.createElement("tr");
		tr.id = `map-manage-${map.id}`;
		tr.innerHTML = `
	  <td name="checkbox"><input type="checkbox" class="form-check-input" data-action="toggle" data-id="${map.id}"></td>
	  <td name="title">${map.title || '未知地图'}</td>
	  <td name="size">${formatFileSize(map.size)}</td>
	  <td name="link"><a href="${map.link}" target="_blank">${map.steam_id}</a></td>
	  <td name="version">${formatTimestamp(map.version)}</td>
	  <td name="status">${getStatusBadge(map.status)}</td>
	  <td name="operation">
		  <button class="btn btn-sm btn-warning" data-action="uninstall" data-id=${map.id}><i class="bi bi-trash"></i></button>
		  <button class="btn btn-sm btn-success" data-action="update" data-id=${map.id}><i class="bi bi-download"></i></button>
		  <button class="btn btn-sm btn-danger" data-action="delete" data-id=${map.id}><i class="bi bi-x-circle"></i></button>
	  </td>
	`;
		manageBody.appendChild(tr);
	});
	// 更新分页
	const pagination_elem = document.getElementById("map-manage-pagination");
	if (total && pagination_elem) {
		renderPagination(loadMapList, pagination_elem, total, page, page_size);
	}
}
import {
	getSelectedIds,
} from "./tools.js";
// 批量卸载地图
function batchuninstallMaps() {
	const ids = getSelectedIds();
	if (ids.length === 0) {
		alert("请先选择要卸载的地图。");
		return;
	}
	if (!confirm(`确定要卸载 ${ids.length} 项？`)) return;

	fetch('/api/map_manage.php?action=uninstall', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({ ids })
	})
		.then(res => res.json())
		.then(data => {
			if (!data.success) {
				alert("卸载失败：" + data.message);
				return;
			}
			ids.forEach(id => {
				//卸载成功设置状态为abandon
				const row = document.getElementById(`map-manage-${id}`);
				if (!row) return;
				row.querySelector('td[name="status"]').innerHTML = getStatusBadge('abandon');
			});
		})
		.catch(err => alert("请求失败：" + err.message));
}


/**
 * AJAX请求更新
 * @param {Array} ids [1,2,3,...]
 * @return {Promise} maps [{id,size,version,status},...]
 */
async function fetchUpdate(ids) {
	let res = null;
	try {
		res = await fetch(`/api/map_manage.php?action=update`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ ids: ids })
		})
	} catch (err) {
		return json_error(`请求失败：${err.message}`);
	}
	const data = await res.json();
	if (!data.success) return json_error(data.message);
	//更新成功，依据data更新信息
	return json_success(data.data);
}
/**
 * 返回maps刷新状态等信息
 * @param {Array} maps [{id,size,version,status},...]
 */
function updateStatus(maps) {
	for (let i = 0; i < maps.length; i++) {
		const map = maps[i];
		const new_row = document.getElementById(`map-manage-${map.id}`);
		new_row.querySelector("td[name='size']").textContent = formatFileSize(map.size);
		new_row.querySelector("td[name='version']").textContent = map.version || '-';
		new_row.querySelector("td[name='status']").innerHTML = getStatusBadge(map.status);
		// 禁用所有可交互元素
		const operationTd = new_row.querySelector("td[name='operation']");
		operationTd.querySelectorAll("button, input, select, textarea, a").forEach(el => {
			if (el.tagName === "A") {
				el.classList.add("disabled");  // Bootstrap 的禁用样式
				el.href = "javascript:void(0)";  // 防止链接跳转
			} else {
				el.disabled = true;
			}
		});
	}
}
/**
 * 更新后，根据记录的rows回滚信息和操作按钮
 * @param {Array} rows [{row,status,operation},...]
 */
function rollBackStatus(rows) {
	for (let i = 0; i < rows.length; i++) {
		const row = rows[i].row;
		row.querySelector('td[name="status"]').innerHTML = rows[i].status;
		row.querySelector('td[name="operation"]').innerHTML = rows[i].operation;
	}
}
/**
 * 设置加载状态
 * @param {Element} row 
 * @returns {Object} {status,operation}
 */
function setLoadStatus(row){
	const statusCell = row.querySelector('td[name="status"]');
	const operationCell = row.querySelector('td[name="operation"]');
	const status = statusCell.innerHTML;
	const operation = operationCell.innerHTML;
	statusCell.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
	operationCell.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
	return {status,operation};
}
async function updateMaps(ids) {
	if (!Array.isArray(ids) || ids.length <= 0) {
		alert("请选择要更新的地图");
		return;
	}
	alert(`即将请求更新 ${ids.length} 个地图`);

	const rows = [];
	// 设置加载状态 & 记录旧状态
	ids.forEach(id => {
		const row = document.getElementById(`map-manage-${id}`);
		if (!row) return;
		const {status, operation} = setLoadStatus(row);
		rows.push({row,status,operation});
	});

	const res = await fetchUpdate(ids);

	if (!res.success || !res.data) {
		alert(`更新失败：${res.message}`);
		rollBackStatus(rows); // 还原按钮
		return;
	}
	alert(res.data.message || "地图更新中");
	rollBackStatus(rows);        // 还原按钮
	updateStatus(res.data.success_maps); // 更新成功状态
}
// 更新单个
async function updateMap(id) {
	if (!id) return;
	updateMaps([id]);
}
// 批量更新
async function batchUpdateMaps() {
	//获取所有选中的地图id
	const ids = getSelectedIds();
	updateMaps(ids);
}
// 卸载单个地图
function uninstallMap(id) {
	if (!confirm("确定卸载此地图？")) return;
	const row = document.getElementById(`map-manage-${id}`);
	const buttons = row.querySelectorAll('button');
	buttons.forEach(button => button.disabled = true);
	fetch(`/api/map_manage.php?action=uninstall`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({ ids: [id] })
	})
		.then(res => res.json())
		.then(data => {
			if (data.success) {
				//显示状态改为abandon
				if (!row) return;
				row.querySelector('td[name="status"]').innerHTML = getStatusBadge('abandon');
			} else {
				alert("卸载失败：" + data.message);
			}
		})
		.catch(err => alert("卸载失败：" + err.message));
	buttons.forEach(button => button.disabled = false);

}
// 移除单个地图
async function deleteMap(id) {
    const row = document.getElementById(`map-manage-${id}`);
    if (!row) return;
    if (!confirm("确定彻底移除此地图？包括记录？")) return;
    if (!confirm("注意！此操作不可逆！")) return;
	row.remove();
	try{
		const result = await fetch(`/api/map_manage.php?action=delete`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ ids: [id] })
		});
		if(!result.ok){
			alert(`删除失败: 网络错误${result.status}`);

			return;
		}
		const data = await result.json();
		if (!data.success) alert(`删除失败: ${data.message}`);


	} catch (err) {
		alert("删除失败：" + err.message);
	}
}
// 排序状态管理
let currentSort = {
	field: 'status',
	order: 'DESC'
};
// 更新排序图标显示
function updateSortIcons(field, order) {
	document.querySelectorAll('th[data-sort]').forEach(th => {
		const icon = th.querySelector('.sort-icon');
		if (th.dataset.sort === field) {
			icon.textContent = order === 'ASC' ? ' ▲' : ' ▼';
		} else {
			icon.textContent = '';
		}
	});
}

import {
	toggleAll
} from './tools.js';
document.addEventListener('DOMContentLoaded', async function () {
	// 加载地图列表
	const total = await getTotalMaps();
	loadMapList(total, 1, 15, currentSort.field, currentSort.order);
	updateSortIcons(currentSort.field, currentSort.order);
});
// 点击事件委托
document.addEventListener('click', function (event) {
	const actionEl = event.target.closest('[data-action]');
	const th = event.target.closest('th[data-sort]');
	// 处理表头排序
	if (th && th.dataset.sort) {
		const field = th.dataset.sort;
		// 点击同一字段则切换顺序，否则默认降序
		currentSort.order = (currentSort.field === field && currentSort.order === 'DESC') ? 'ASC' : 'DESC';
		currentSort.field = field;
		updateSortIcons(currentSort.field, currentSort.order);
		loadMapList(0, 1, 15, currentSort.field, currentSort.order);
		return;
	}
	
	// 处理操作按钮
	if (actionEl && actionEl.dataset.action) {
		switch (actionEl.dataset.action) {
			case 'toggleAll':
				toggleAll(actionEl.checked);
				break;
			case 'update':
				updateMap(actionEl.dataset.id);
				break;
			case 'uninstall':
				uninstallMap(actionEl.dataset.id);
				break;
			case 'delete':
				deleteMap(actionEl.dataset.id);
				break;
			case 'batchUpdate':
			case 'triggerUpdateAll':
				triggerUpdateAll();
				break;
			case 'triggerCosSync':
				triggerCosSync();
				break;
				batchUpdateMaps();
				break;
		}
	}
});
/**
 * 手动触发全量更新检查
 */
async function triggerUpdateAll() {
	const statusEl = document.getElementById('trigger-status');
	if (!statusEl) return;
	statusEl.textContent = '⏳ 正在检查所有地图更新…';
	try {
		const res = await fetch('/api/map_manage.php?action=trigger_update_all');
		const data = await res.json();
		if (data.success) {
			statusEl.textContent = '✅ ' + data.data.message;
			// 刷新列表
			const total = await getTotalMaps();
			loadMapList(total, 1, 15, currentSort.field, currentSort.order);
		} else {
			statusEl.textContent = '❌ ' + (data.message || '未知错误');
		}
	} catch (err) {
		statusEl.textContent = '❌ 请求失败: ' + err.message;
	}
}

/**
 * 手动触发 COS 同步（写入触发文件，由 task-daemon 在下次轮询时执行）
 */
async function triggerCosSync() {
	const statusEl = document.getElementById('trigger-status');
	if (!statusEl) return;
	statusEl.textContent = '⏳ 正在加入同步队列…';
	try {
		const res = await fetch('/api/map_manage.php?action=trigger_cos_sync');
		const data = await res.json();
		if (data.success) {
			statusEl.textContent = '📨 ' + data.data.message;
		} else {
			statusEl.textContent = '❌ ' + (data.message || '未知错误');
		}
	} catch (err) {
		statusEl.textContent = '❌ 请求失败: ' + err.message;
	}
}
