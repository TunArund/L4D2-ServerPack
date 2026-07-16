// ============================================================
// 全局 CSRF 保护 — fetch 拦截器
// ============================================================
(function() {
    var _fetch = window.fetch;
    var CSRF_HEADER = 'X-CSRF-Token';
    var SAFE_METHODS = { GET: 1, HEAD: 1, OPTIONS: 1 };

    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function isSafeMethod(method) {
        return !!SAFE_METHODS[(method || 'GET').toUpperCase()];
    }

    function isSameOrigin(url) {
        try {
            var u = new URL(url, window.location.origin);
            return u.origin === window.location.origin;
        } catch (e) {
            return true;  // 相对路径视为同源
        }
    }

    window.fetch = function(input, init) {
        init = init || {};
        var method = (init.method || 'GET').toUpperCase();

        // 仅对同源的非只读请求注入 CSRF token
        if (!isSafeMethod(method) && isSameOrigin(input)) {
            var token = getCsrfToken();
            if (token) {
                init.headers = init.headers || {};
                // Headers 可能是普通对象或 Headers 实例
                if (init.headers instanceof Headers) {
                    if (!init.headers.has(CSRF_HEADER)) {
                        init.headers.append(CSRF_HEADER, token);
                    }
                } else {
                    var keys = Object.keys(init.headers);
                    var hasKey = false;
                    for (var i = 0; i < keys.length; i++) {
                        if (keys[i].toLowerCase() === CSRF_HEADER.toLowerCase()) {
                            hasKey = true;
                            break;
                        }
                    }
                    if (!hasKey) {
                        init.headers[CSRF_HEADER] = token;
                    }
                }
            }
        }
        return _fetch.call(window, input, init);
    };
})();

// ============================================================
// 导航栏功能
// ============================================================
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
