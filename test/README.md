# test — 测试体系

统一入口 `./test.sh`，分三类测试。

## 运行

```bash
./test.sh healthcheck   # 快速探活（容器状态、PHP 扩展、DB 连接、COS 配置）
./test.sh auto           # 自动化测试（COS 签名、日志轮转）
./test.sh all            # 全部（自动化 + 人工引导）
```

## 脚本说明

| 脚本 | 类型 | 测试内容 |
|------|------|----------|
| `test/script/healthcheck.sh` | 探活 | 容器状态、PHP 扩展、MySQL 连接/表结构、daemon 语法、COS 配置、sidecar 端点、token 认证 |
| `test/script/auto_cos.sh` | 自动 | COS 函数可加载、签名格式验证 |
| `test/script/auto_logrotate.sh` | 自动 | 日志按日轮转目录结构、旧格式清理提示 |

## 测试日志

测试输出写入 `test/log/` 目录（Git 忽略），按日期命名。
