// billboard.php 分页器 — 省略号点击展开跳转输入框
function trans2form(element) {
    const nav = element.closest('nav');
    const pageCount = parseInt(nav.dataset.pageCount);
    const pageSize  = nav.dataset.pageSize;
    const mapName   = nav.dataset.mapName;

    const form = document.createElement('form');
    form.method = 'GET';
    form.action = '/billboard.php';

    const pageNum = document.createElement('input');
    pageNum.type = 'number';
    pageNum.name = 'page_num';
    pageNum.placeholder = '跳转页';
    pageNum.min = 1;
    pageNum.max = pageCount;
    form.appendChild(pageNum);

    const ps = document.createElement('input');
    ps.type = 'hidden';
    ps.name = 'page_size';
    ps.value = pageSize;
    form.appendChild(ps);

    const mn = document.createElement('input');
    mn.type = 'hidden';
    mn.name = 'map_name';
    mn.value = mapName;
    form.appendChild(mn);

    const submit = document.createElement('button');
    submit.type = 'submit';
    submit.className = 'btn btn-sm btn-primary ms-2';
    submit.textContent = '跳转';
    form.appendChild(submit);

    element.parentElement.replaceChild(form, element);
}
