# l4d2 — 游戏服务器

Left 4 Dead 2 游戏服务器，基于 `ubuntu:22.04` + steamcmd。同一镜像可被战役服（coop）和对抗服（versus）两个实例复用。

## 镜像说明

镜像仅含 32 位运行库（~335MB），游戏文件（~9.3GB）通过 `./l4d2.sh install`（steamcmd 匿名下载）放到 `l4d2/src/`，运行时 **bind mount** 进容器，不进镜像。

```yaml
l4d2:             # 战役服               l4d2-versus:      # 对抗服
  image: l4d2-server-game                image: l4d2-server-game  ← 同一镜像
  volumes:                                volumes:
    - ...data/coop/addons                   - ...data/versus/addons
    - ...data/coop/cfg                      - ...data/versus/cfg
  ports:                                   ports:
    - 27015:27015                           - 27014:27015
```

## 挂载策略

两个容器共享同一份游戏本体 `${GAME_DIR}`（只读使用），但通过 **按路径覆盖挂载** 实现配置隔离：

```
${GAME_DIR:-./l4d2/src}          ← 游戏本体（共享，两个容器只读使用）
  ├── left4dead2/addons/          ← 被 coop 或 versus 的 addons 覆盖
  ├── left4dead2/cfg/             ← 部分子路径被覆盖，其余共享
  └── ...

l4d2/data/coop/                   ← 战役服专用数据（不进 Git）
  ├── addons/  → 覆盖 addons/
  ├── cfg/     → 覆盖 cfg/server.cfg, cfg/sourcemod/, cfg/cfgogl/ ...
  └── ...

l4d2/data/versus/                 ← 对抗服专用数据（不进 Git）
  ├── addons/  → 覆盖 addons/
  ├── cfg/     → 覆盖 cfg/server.cfg, cfg/stripper/, cfg/cvar_tracking.cfg ...
  └── ...
```

> **为什么不冲突？** 两个容器是独立实例，各自把不同宿主机路径挂载到各自容器内的相同位置，互不干扰。共享 `${GAME_DIR}` 中的默认文件（如 maps、materials）两个容器只读访问，写操作（日志、cvar_tracking）通过覆盖挂载隔离到各自目录。

## 数据目录与 Git 忽略策略

`l4d2/data/{coop,versus}/` 下的内容分为两类：

| 目录/文件 | Git | 原因 |
|-----------|-----|------|
| `addons/*` | 忽略（仅保留 `.gitkeep`） | 二进制插件/第三方 vpk，不进仓库，本地维护 |
| `ems/*` | 忽略（仅保留 `.gitkeep`） | 运行时数据 |
| `cfg/*` | 忽略（仅保留 `.gitkeep`） | 配置文件含服务器特定设置，通过 `.env` 管理差异 |
| `scripts/*` | 忽略（仅保留 `.gitkeep`） | vscripts 脚本，随 addons 分发 |
| `host.txt` / `motd.txt` | 忽略 | 服务器身份信息，环境相关 |

> `.gitkeep` 文件保留目录结构在 Git 中可见，实际内容通过 scp/rsync 同步到服务器。

> UID/GID 必须与 `l4d2/src/` owner 一致，否则 SourceMod 日志写入 Permission denied。

## 关键文件

| 文件 | 说明 |
|------|------|
| `l4d2/Dockerfile` | 镜像构建（32 位运行库 + steamcmd） |
| `l4d2/src/` | 游戏文件（bind mount，不进 Git） |
| `l4d2/data/coop/` | 战役服 addons + cfg |
| `l4d2/data/versus/` | 对抗服 addons + cfg |
| `l4d2.sh` | steamcmd 下载/更新游戏 |
