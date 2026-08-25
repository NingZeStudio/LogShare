# ZL2 Mod 兼容与启动器本体实战案例

## Mod 兼容性

### 明确不支持 / 有立场
| Mod | 结论 | 证据 |
|---|---|---|
| Key Binding Patch | **拒绝支持**：破坏 options.txt 格式，卸载后原版设置会被重置 | #1268 |
| Feather 客户端 | 不做集成：它是 Mod 不是加载器，无公开 API | #1309 |
| imgui 系（Axiom 编辑器等） | 放弃 imjava-gui：UI 无法用鼠标控制 + 安卓兼容未解 | #176 #24 |
| 机械动力:航空学 | 插件只能补全依赖库（sable 库），**无法保证物理 Mod 稳定**，崩=设备带不动 | #1094 #1182 |
| Angelica (GTNH) | ≥2.1.32 曾无法启动；GTNHLib 0.11.x 问题随 Angelica 更新自愈 | #1209 #1334 #1549 |

### 有解法
- **Distant Horizons 缺 zstd** → [NativeLibPlugin zstd_jni_1.5.7-6_dhcompat](https://github.com/ZalithLauncher/NativeLibPlugin/releases/tag/zstd_jni_1.5.7-6_dhcompat)，MovTery 亲自贴的链接（#1287）；voxy 运行库兼容另案（#1204）
- **Babric (b1.7.3) StationAPI 启动即崩** → 装 [StationAPI-no-startup-screen](https://modrinth.com/mod/stationapis-no-startup-screen)（#1441）
- **1.21.4 崩溃** → 装 fzzy_config ≥0.6.8+1.21.3、替换不兼容的 Stfu mod（#384 MovTery 给的标准配方）
- Enhanced Block Entities 崩 → 该 Mod 没有 26.1.2 版本，删（#1411）
- 整合包内含 404 失效链接的 Mod → 需要缓存/跳过机制（#1498）；同版本整合包重复下载游戏文件（#1400）；MCBBS 格式是唯一支持导入 JVM 参数的整合包格式，且仅导出不使用（#1248）

### libraries 去重问题 ★
同一 Maven 坐标多版本共存导致崩溃——启动器缺去重机制（#1620）。诊断时检查 `libraries/` 下同名不同版本的 jar。

## Java / JVM

- **版本矩阵**：≤1.20.4→Java17；1.20.5~1.21.x→Java21；**26.1+→Java25**（#473 #535 #720）；26.1+ 还需 **LWJGL 3.4.1**（#725）
- **安卓 JRE 来源**：必须 NDK 特制构建，[FCL-Team/Android-OpenJDK-Build](https://github.com/FCL-Team/Android-OpenJDK-Build)；Linux 的 tar.gz/xz 直接导入会 `arch is null`（#804 #454 #455 #827）
- **GC 建议**：ZGC 安卓可用但 32 位不行；加 `-XX:-UseNUMA`（安卓无 NUMA）；Java17 用 Shenandoah（#1031）
- **`--add-opens` 参数被无空格拼接** → commit 132b8d1 已修（#1461）；JVM 参数多行输入曾不过校验（#686）
- **JVM 错误码速记**：code 6 = fatal signal（SIGABRT 类，常见于 Create 航空学等重 Mod）；jvm-3 = 快照期低端机常见；Error 6 `ClassNotFoundException com.mio.libpatcher.MainAgent` = mio 补丁损坏/没装（#1182 #1693 #1365）
- 内存过高时启动器整个退回桌面 = 系统强杀（#1604）；自动分配内存功能已加（#981）；RAM 识别不准（#1205）

## 启动器本体

### 清理功能教训 ★
"清理"删了 `libraries/` 里依赖库导致所有游戏无法启动 → 官方决定**不再清理 libraries**（只清 assets 等）（#617）。用户侧自救：重装版本或手动补库。

### 网络
- 启动失败先关 WLAN 试（运营商网络劫持类）（#1175）
- NeoForge 安装超时 0 B/s：只有版本列表有镜像，依赖库只有官方源且国内不稳（#1417 #1233 #1300 #1338）
- littleskin 外置登录服务器进不去已修（#442 #447）

### 设备/ROM
- 荣耀 MagicOS：**游戏管家会干扰触控事件** → 把 ZL2 移出游戏管家重启（#1521）；同类触控 bug 重启后立即进启动器并锁后台可缓解（#750）
- Motorola One Action (Exynos, Android11) 崩 → 换 ROM 也救不了，wontfix（#661）
- Samsung Helio G99/iQOO 视口缩放 bug：切后台回来画面错位（#874）

### 日志位置与误区
- **上传错日志是最常见浪费**：把启动器应用级崩溃当游戏崩溃报（#1110）；正确做法同 FCL——要 `latest_game.log` 全量
- 修改过版本文件后启动卡"验证文件完整性" → 关闭完整性检查仍会验（#1622 open）
