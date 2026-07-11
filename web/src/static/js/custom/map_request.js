//获取不同状态的badge
function getStatusBadge(status) {
	switch (status) {
		case 'pending':
			return '<span class="badge bg-warning text-dark">审核中</span>';
		case 'approved':
			return '<span class="badge bg-success">已批准</span>';
		case 'rejected':
			return '<span class="badge bg-danger">已拒绝</span>';
		default:
			return '<span class="badge bg-secondary">未知</span>';
	}
}
import {
	formatFileSize,
	renderPagination
} from './tools.js';

function save_button(urlInput) {
	const url = urlInput.value.trim();
	urlInput.value = '';
	const match = url.match(/\?id=(\d+)/);
	if (!match) {
		alert('链接格式非法，请确认输入 Steam Workshop 链接。');
		return;
	}
	const steam_id = match[1];

	// 创建临时行（ID 设置为临时）
	const tbody = document.getElementById('map-request-body');
	const tempRow = document.createElement("tr");
	tempRow.id = "map-request-temp";
	tempRow.innerHTML = `
		<td name="select"><input type="checkbox" class="form-check-input" disabled></td>
		<td name="title">加载中...</td>
		<td name="size">-</td>
		<td name="link"><a href="${url}" target="_blank">${steam_id}</a></td>
		<td name="updated_at">-</td>
		<td name="status"><span class="spinner-border spinner-border-sm" role="status"></span></td>
		<td name="actions"><em>添加中...</em></td>
		<td name="explaination">-</td>
	`;
	tbody.prepend(tempRow);

	// 发起添加请求
	fetch('/api/map_request.php?action=add&steam_id=' + steam_id)
		.then(res => res.json())
		.then(data => {
			if (!data.success) throw new Error(data.message || '添加失败');
			const row = data.data;
			// 替换临时行内容
			tempRow.id = `map-request-${row.id}`;
			tempRow.querySelector('[name="select"]').innerHTML =
				`<input type="checkbox" class="form-check-input" data-action="toggle" data-id="${row.id}">`;
			tempRow.querySelector('[name="title"]').textContent = row.title || '未知地图';
			tempRow.querySelector('[name="size"]').textContent = formatFileSize(row.size) || '-';
			//link在创建时已经添加
			tempRow.querySelector('[name="updated_at"]').textContent = row.updated_at || '-';
			tempRow.querySelector('[name="status"]').innerHTML = getStatusBadge(row.status);
			// 操作按钮
			const actions = tempRow.querySelector('[name="actions"]');
			actions.innerHTML = '';
			const deleteButton = document.createElement('button');
			deleteButton.type = 'button';
		deleteButton.classList.add('btn', 'btn-sm', 'btn-danger', 'mr-1');
			deleteButton.setAttribute('data-action', 'delete');
			deleteButton.textContent = '删除';
			actions.appendChild(deleteButton);
			if (row.status === 'pending') {
				const approveButton = document.createElement('button');
				approveButton.type = 'button';
			approveButton.classList.add('btn', 'btn-sm', 'btn-success');
				approveButton.setAttribute('data-action', 'approve');
				approveButton.textContent = '批准';
				actions.appendChild(approveButton);
			}
			tempRow.querySelector('[name="explaination"]').textContent = row.explaination || '-';
		})
		.catch(err => {
			tempRow.remove();
			alert('添加失败：' + err.message);
		});
}
async function getTotalRequests() {
	const res = await fetch('/api/map_request.php?action=count');
	const data = await res.json();
	if (!data.success) {
		alert("获取地图总数失败：" + data.message);
		return false;
	}
	return data.data;
}
//AJAX显示申请列表
async function loadMapRequests(total,page=1,page_size=16,order_by='id',order='DESC') {
	const manageBody = document.getElementById("map-request-body");
	if (!manageBody) return;
	fetch(`/api/map_request.php?action=list&limit=${page_size}&offset=${(page-1)*page_size}&order_by=${order_by}&order=${order}`)
		.then(response => response.json())
		.then(data => {
			if (!data.success) {
				alert("加载地图申请失败：" + data.message);
				return;
			}
			const tbody = document.getElementById("map-request-body");
			tbody.innerHTML = ""; // 清空原有内容

			data.data.forEach(row => {
				const tr = document.createElement("tr");
				tr.id = `map-request-${row.id}`; // 给每行添加唯一 ID

				// 选中 checkbox
				const tdSelect = document.createElement("td");
				tdSelect.setAttribute("name", "select");
				tdSelect.innerHTML = `<input type="checkbox" class="form-check-input" data-action="toggle" data-id="${row.id}">`;
				tr.appendChild(tdSelect);
				// 地图标题
				const tdTitle = document.createElement("td");
				tdTitle.setAttribute("name", "title");
				tdTitle.textContent = row.title || '加载中...';
				tr.appendChild(tdTitle);
				// 地图大小
				const tdSize = document.createElement("td");
				tdSize.setAttribute("name", "size");
				tdSize.textContent = formatFileSize(row.size) || '-';
				tr.appendChild(tdSize);
				// steam链接
				const tdLink = document.createElement("td");
				tdLink.setAttribute("name", "link");
				tdLink.innerHTML = `<a href="https://steamcommunity.com/sharedfiles/filedetails/?id=${row.steam_id}" target="_blank">${row.steam_id}</a>`;
				tr.appendChild(tdLink);
				const tdCreatedAt = document.createElement("td");
				tdCreatedAt.setAttribute("name", "created_at");
				tdCreatedAt.textContent = row.created_at || '-';
				tr.appendChild(tdCreatedAt);
				// 状态
				const tdStatus = document.createElement("td");
				tdStatus.setAttribute("name", "status");
				tdStatus.innerHTML = getStatusBadge(row.status);
				tr.appendChild(tdStatus);
				// 操作按钮
				const tdAction = document.createElement("td");
				tdAction.setAttribute("name", "actions");
				let ActionStr = `
				<button type="button" data-action="delete" class="btn btn-sm btn-danger me-1">删除</button>
				`;
				if(row.status == 'pending') ActionStr += `
				<button type="button" data-action="approve" class="btn btn-sm btn-success me-1">批准</button>
				`;
				tdAction.innerHTML = ActionStr;
				tr.appendChild(tdAction);
				// 说明字段
				const tdExplain = document.createElement("td");
				tdExplain.setAttribute("name", "explaination");
				tdExplain.textContent = row.explaination || '-';
				tr.appendChild(tdExplain);
				tbody.appendChild(tr);
			});
			// 更新分页
			const pagination_elem = document.getElementById("map-request-pagination");
			if(total&&pagination_elem){
			  renderPagination(loadMapRequests,pagination_elem,total,page,page_size);
			}
		})
		.catch(err => {
			console.error("AJAX错误:", err);
			alert("无法连接服务器。");
		});
		
}

function delete_request(el) {
	if (!confirm('确认删除？')) return;
	let trId = el.closest('tr').id;
	let request_id = trId.replace('map-request-', '');
	fetch(`/api/map_request.php?action=delete&request_id=${request_id}`)
		.then(resp => resp.json())
		.then(json => {
			if (!json.success) alert('删除失败: ' + json.message);
			location.reload();
		});
}
async function approve_request(el) {
	//按钮预处理
	el.disabled = true;
	el.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> 处理中...';
  //批准流程
	if (!confirm('确认批准？')) return;
  let trId = el.closest('tr').id.replace('map-request-', '');
	let ids = [trId];
	try{
		let result = await fetch(`/api/map_request.php?action=approve`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json'
			},
			body: JSON.stringify({ids})
		});
		result = await result.json();
		if(!result.success) alert('批准失败: ' + result.message);
		alert(result.data);
	} catch (error) {
		console.error('批准失败:', error);
		alert('批准失败: ' + error.message);
	}
	location.reload();
}
function batchDelete() {
	const ids = getSelectedIds();
	if (!ids.length) return alert('请先选择申请');
	if (!confirm('确认批量删除？操作不可逆！')) return;
	if (!confirm('真的确认批量删除？')) return;

	const promises = ids.map(async id => {
		const resp = await fetch(`/api/map_request.php?action=delete&request_id=${id}`);
		const json = await resp.json();
		return ({ id, success: json.success, message: json.message || '未知错误' });
	});

	Promise.all(promises).then(results => {
		const failed = results.filter(r => !r.success);
		if (failed.length) {
			let msg = '以下记录删除失败：\n';
			failed.forEach(f => msg += `ID ${f.id}: ${f.message}\n`);
			alert(msg);
		}
		location.reload();
	});
}
//批量批准
function batchApprove() {
	const ids = getSelectedIds();
	if (!ids.length) return alert('请先选择记录');
	if (!confirm('确认批量批准？')) return;
	if (!confirm('真的确认批量批准？')) return;
	fetch('/api/map_request.php?action=approve', {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json'
		},
		body: JSON.stringify({ ids })
	})
		.then(resp => resp.json())
		.then(json => {
			if (!json.success) {
				alert('批准失败: ' + (json.message || '未知错误'));
			} else {
				alert(json.data);
			}
			location.reload();
		})
		.catch(err => {
			alert('网络错误：' + err.message);
			location.reload();
		});
}
import {
	toggleAll,
	getSelectedIds,
} from './tools.js';
// 加载事件委托
document.addEventListener('DOMContentLoaded', async function () {
	// 异步api获取显示地图请求
	const total = await getTotalRequests();
	await loadMapRequests(total);
});
// 点击事件委托
document.addEventListener('click', function(event){
	// 提交申请按钮
	const saveButton = event.target.closest('button[data-action="save_button"]');
	if(saveButton){
		const urlInput = document.getElementById('map-link');
		save_button(urlInput);
		return;
	}
	// 批量勾选复选
	const toggleAllInput = event.target.closest('input[data-action="toggleAll"]');
	if(toggleAllInput){
		toggleAll(toggleAllInput.checked);
		return;
	}
	// 绑定批准按钮
	const approveButton = event.target.closest('button[data-action="approve"]');
	if(approveButton){
	  approve_request(approveButton);
	  return;
	}
	// 绑定批量批准按钮
	const batchApproveButton = event.target.closest('button[data-action="batchApprove"]');
	if(batchApproveButton){
		batchApprove();
		return;
	}
	// 绑定删除按钮
	const deleteButton = event.target.closest('button[data-action="delete"]');
	if(deleteButton){
		delete_request(deleteButton);
		return;
	}
	// 绑定批量删除按钮
	const batchDeleteButton = event.target.closest('button[data-action="batchDelete"]');
	if(batchDeleteButton){
		batchDelete();
		return;
	}
});



