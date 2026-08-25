# Amethyst 实战案例（175 issues 蒸馏）

> 所有 `#N` 指 `https://github.com/AngelAuraMC/Amethyst-Android/issues/N`。

## 渲染器

### MobileGL 接班（重要动向）
- Mali-G610 (Android 15) EGL config matching 失败 → 已知问题；要玩快照用 feat/MobileGL 分支的 **MobileGL** 渲染器；MobileGlues 与 Krypton Wrapper 计划移除（#330）
- Maleoon 910 上 26.2 KW 渲染错误 + 26.3 启动失败：KW/MG 都坏，MG 只是靠"错误过滤"绕过着色器编译失败——是 hack 不是修复（#314）
- Kopper Zink 窗口缩放不跟随（#313 open）

### Vulkan
- Pixel 7 Pro：Vulkan 报告 `fillModeNonSolid`/`VK_EXT_vertex_attribute_divisor` 不实——即使 conformant 1.4 这俩也不是 core（#258）；Vulkan↔OpenGL 来回切后游戏全坏 → 清数据重来（#337）
- Adreno 8xx 无官方 Turnip：可手动替换 APK 内 .so 再重签（或先用 MobileGlues/Zink）（#180）
- 手机驱动不支持 Vulkan 1.2+ 所需扩展 → 只能继续 OpenGL 后端（#252）

### 其他
- 1.7.10 NEI-GTNH 方块渲染坏（GL4ES/KW 交互问题）→ 装 <2.8.5 的 NEI-GTNH 或 LWJGL3IFY instance（#331）
- ANGLE 更新后整体性能/兼容退化：同帧率下 GPU 占用翻倍（#38）
- Samsung 不按原生分辨率渲染 = 三星侧问题非启动器 bug（#202）
- 竖屏卡死 → 关"alternate surface mode"（Mojang 桌面端没旋转概念的老 bug）（#259）

## Mod / 加载器

| 场景 | 结论 | 出处 |
|---|---|---|
| Sodium 崩 "not supported on Pojav" | 设计如此（拦截码未禁） | #128 #118 |
| sable / Valkyrian Skies 2 | 要 glibc 不是 bionic，跑不了 | #245 |
| e4mc 联机崩 | e4mc 下发了 glibc natives，Quiche 本支持安卓，等上游修 | #60 |
| Forge modular LWJGL "Duplicate key lwjgl-3.3.3-merged-modules.jar" | 注入冲突，等构建更新 | #310 #295 |
| Cleanroom Loader 崩 | cleanroommc 更新引入，FCL 同样中招 | #296 |
| OptiFine 安装失败 | PR #238 已修 | #237 |
| CurseForge 可选 Mod | 未支持（#152 open）|
| jarmod | 手动放进 versions 目录；不支持 MMC patch 格式 | #338 |
| 整合包导入慢/卡 | 已修 | #319 |
| Babric StationAPI 崩 | [Reload-Screen-Destroyer](https://github.com/FarnGitHub/Reload-Screen-Destroyer) mod 解 | #136 |

## Java / JVM

- Java 25 支持由 #184 引入；26.1 snapshot 时代必须（#183 #156）
- HarmonyOS NEXT 上 Java17/21 SIGILL：JVM 自杀行为非 OS 崩溃（#157）
- Linux JRE 直接导入不支持：必须 Android toolchain 特制构建（#129，同 FCL/ZL2 共识）
- 重 NeoForge 包 SIGSEGV `libjvm.so+0xf1614c` → 服务端 `/flywheel backend flywheel:off`（#263）
- Applied Energistics 1.6.4 (ASM 4.1 自身 bug) 崩 = Mod 问题（#45）

## 账户

- 登录死循环（微软账号刚改过密/信息）：微软换了深色新版登录页导致（#280）
- 微软认证整体宕机：查 isminecraftdown.com，非启动器问题（#269）
- WebView 加载不出登录页：换 VPN（WARP/Proton）+清缓存（#181 #159）
- 无正版账号无法使用任何功能（连跑 jar 都不行）（#134）

## 输入 / 系统

- Elite Controller 2 映射失灵 = 1.1.7 的 SDL 检测回归 → 用最新 Actions 构建 + 重配（#346）
- DeX/触控板光标方向不对（Virtual Cursor 关闭时）（#335 open）
- Narrator 开启即崩（#321 open）
- OnePlus 15r 165Hz 被限 60（#272 open）
- Android 16 启动 Modrinth profile 报 ForegroundServiceStartNotAllowedException（#339）
- 旧版服务端资源包下载崩溃 = Mojang MCL-3732 回归，Prism 用特殊手段绕过（#83）

## 诊断提示（Amethyst 特有）

1. 版本对不上先问"用的正式版还是 Actions 构建"——大量 issue 靠换构建解决（#309 #302）
2. 日志里出现 `org.angelauramc.*` 类缺失 = methodsInjectorAgent 注入链断（#325）
3. 报障模板 `[BUG] <Short description>` 会被 alexytomi 直接打回，写清楚设备/版本/日志
