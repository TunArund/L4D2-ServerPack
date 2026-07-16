// personal.php 收件箱操作
function markRead(id, btn) {
    fetch('/personal.php?tab=inbox&message_id=' + id).then(() => {
        btn.classList.remove('fw-bold');
        btn.classList.add('text-muted');
        const b = btn.querySelector('.badge');
        if (b) b.remove();
    });
}

function batchDelete() {
    const cbs = document.querySelectorAll('.inbox-check:checked');
    if (!cbs.length) { alert('请先选择要删除的消息'); return; }
    if (!confirm('确定删除 ' + cbs.length + ' 条消息？')) return;
    const p = new URLSearchParams();
    p.set('tab', 'inbox');
    cbs.forEach(c => p.append('delete_ids[]', c.value));
    location.href = '/personal.php?' + p.toString();
}
