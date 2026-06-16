
**注意html和js中根路径为网页根目录，但php中根路径为Linux根目录**
安装xdebug，修改配置/etc/php/8.3/cli/conf.d/20-xdebug.ini
```ini
xdebug.mode=debug
xdebug.start_with_request=yes
xdebug.discover_client_host=true
xdebug.client_port=9003
xdebug.log=/tmp/xdebug.log
```
在打断点使用xdebug启动调试后，用终端php api/xxx.php就可命中断点
# 收件箱
/navbar.php 包含js展示信箱，悬浮刷新
但是不好看
/api/get_unread.php 获取新消息接口

/js/custom/navbar.js 包含刷新信箱的js
mysql-steam-messages

使用腾讯邮件推送，验证了发信域名、地址、模板,api请求，设置了envvars
veri_code:
valid_time_str
expire_time_str
company_name

# 个人空间与管理面板
/personal.php?tab=inbox
获取tab参数打印对应页面与js


# 地图申请
https://l4d2.tunarund.top/personal.php?tab=map_request

maps表img_urls\rating rating_num需要访问steam官网
删除上面两个条目并修改为api的subscriptions
billboard.php
map_info.php
map_request_tools.php
优先把正常流程实现，之后添加搜索、排序功能


# 资源监控
**内存显示有问题，不会定时更新**
/var/www/html/dashboard.html 前端展示
/var/www/html/monitor_daemon.php 周期循环，插入mysql-steam-server_info
| Field           | Type                                               | Null | Key | Default           | Extra             |
|-----------------|-----------------------------------------------------|------|-----|-------------------|-------------------|
| id              | int                                                 | NO   | PRI | NULL              | auto_increment    |
| metric_type     | enum('cpu','memory','disk','network','temperature') | NO   | MUL | NULL              |                   |
| metric_value    | decimal(10,2)                                       | NO   |     | NULL              |                   |
| additional_info | text                                                | YES  |     | NULL              |                   |
| server_node     | varchar(50)                                         | YES  |     | main              |                   |
| created_at      | timestamp                                           | NO   | MUL | CURRENT_TIMESTAMP | DEFAULT_GENERATED |

/var/www/html/api/get_metrics.php 前端api
/var/www/html/api/monitor.php 工具函数
scripts/monitor.sh start