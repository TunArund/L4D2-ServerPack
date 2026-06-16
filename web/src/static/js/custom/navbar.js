function relocation(location) {
  ret_url = window.location.href;
  window.location.href = location + "?return_url=" + ret_url;
}
function showPreview() {
  fetch('/api/get_unread_messages.php?limit=5')
    .then(res => res.json())
    .then(data => {
      inboxList.innerHTML = '';
      if (data.length === 0) {
        inboxList.innerHTML = '<li class="text-muted small">无未读消息</li>';
        inboxBadge.classList.add('d-none');
      } else {
        data.forEach(msg => {
          const li = document.createElement('li');
          li.innerHTML = `<a class="dropdown-item text-wrap small" href="${msg.link}">${msg.title}</a>`;
          inboxList.appendChild(li);
        });

        // 更新小红点（最大 99+）
        if (data.length >= 100) {
          inboxBadge.textContent = '99+';
        } else {
          inboxBadge.textContent = data.length;
        }
        inboxBadge.classList.remove('d-none');
      }

      inboxPreview.style.display = 'block';
    })
    .catch(err => {
      inboxList.innerHTML = '<li class="text-muted small">加载失败</li>';
      inboxBadge.classList.add('d-none');
    });
}
//获取未读消息
document.addEventListener('DOMContentLoaded', function () {
  const inboxIcon = document.getElementById('inbox-link');
  if (!inboxIcon) return; // 如果不存在，则直接返回
  const inboxPreview = document.getElementById('inbox-preview');
  const inboxList = document.getElementById('inbox-preview-list');
  const inboxBadge = document.getElementById('inbox-badge');

  let timer; // 用于存储定时器
  inboxIcon.addEventListener('mouseenter', () => {
    clearTimeout(timer);
    showPreview();
  });

  inboxIcon.addEventListener('mouseleave', () => {
    timer = setTimeout(() => inboxPreview.style.display = 'none', 300);
  });

  inboxPreview.addEventListener('mouseenter', () => clearTimeout(timer));
  inboxPreview.addEventListener('mouseleave', () => {
    timer = setTimeout(() => inboxPreview.style.display = 'none', 300);
  });

  fetch('/api/get_unread_count.php')
    .then(res => res.json())
    .then(data => {
      if (data.count > 0) {
        inboxBadge.textContent = data.count >= 100 ? '99+' : data.count;
        inboxBadge.classList.remove('d-none');
      }
    });
});
