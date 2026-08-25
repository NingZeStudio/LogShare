# Amethyst 启动器专题（PojavLauncher 官方续作）

> 来源：[AngelAuraMC/Amethyst-Android](https://github.com/AngelAuraMC/Amethyst-Android) 的 175 个 issue + 168 个 PR（截至 2026-08，135 closed）。
> ⭐2062。主要维护者：**alexytomi**（回复极活跃）、hodayfa000-h、joaquimbc。
> [PojavLauncherTeam/PojavLauncher](https://github.com/PojavLauncherTeam/PojavLauncher) 已在 README 官宣停更，指向本仓库为唯一续作。

## 身份与生态位

- 基于 PojavLauncher 的安卓/iOS MC Java 启动器；iOS 版另有仓库。
- 内置 MobileGlues 渲染器（MG V1.2.6 公告确认）；也带 Krypton Wrapper、Holy GL4ES、Vulkan/Zink 系。
- **渲染器路线图**：Amethyst 维护者提过计划用 **MobileGL** 取代 MobileGlues 与 Krypton Wrapper（#330，feat/MobileGL 分支可试）——但 MobileGL（MGL）目前仍处于开发状态，尚未正式可用，"三大启动器合流/取代"的说法为时过早，勿当既定事实引用。
- 社区关系：Mod Browser 请求被指路 ZL2（#257）；与 FCL 共享 Turnip/Terracotta 等基建。

## 官方立场速查（报障前必读）

| 议题 | 立场 | 出处 |
|---|---|---|
| **离线/本地账户** | 永不加：需验证正版，反盗版 | #298 #120 #62 |
| **Sodium 安卓拦截码** | 不禁用（"不当舔狗"）：Sodium 报 "not supported on Pojav" 崩溃是**设计如此** | #118 #128 |
| **通用 JRE 启动器** | 拒绝：不是跑任意 jar 的工具，那是 Termux 的事 | #134 |
| **MCSR 兼容** | 已修但扣住不发，等 MCSR 作者表态 | #291 |
| **LTW 渲染器** | 有意不集成（同 Sodium 立场考量） | #118 |
| 快照版崩溃 | 属预期，先换最新 Actions 构建 | #233 #302 |

## 版本兼容时间线

| MC 版本 | Amethyst 状态 | 出处 |
|---|---|---|
| ≤1.16.5 | 需 Java 8（报 signal 6 先查 JVM 版本配对） | #188 |
| 1.17+ | Java 17 | #188 |
| **26.1+** | **需 Java 25**（#184 加入支持） | #183 #156 |
| **26.2 正式版** | LWJGL 注入不匹配：游戏要 3.4.1-snapshot、注入 3.3.3 → 用最新构建；Maleoon 910 NoSuchMethodError 由 PR #277 修 | #286 #303 #309 |
| **26.3 快照 6+** | 渲染损坏（open） | #341 |

## 高频维护者结论

- **"We aren't pojav."** —— 报错指向 Pojav 老版本/旧文档时直接打回（#297）
- 微软登录挂了先查 [isminecraftdown.com](https://isminecraftdown.com/)（#269）；登录页加载不出试 VPN（WARP/Proton）（#181 #159）
- Mod 加载失败且权限异常 = 用 ZArchiver/Shizuku 拷文件弄坏权限位 → 删掉用应用内目录按钮重放（#95）
- 重装/换最新 Actions 构建是万能第一招（#262 #302 #309）
- Forge 无 .jar 安装器的远古版本（≤1.4.7）不支持整合包自动安装，手动配（#290）；Forge 1.2.5 崩是 forgemodloader 自己的 bug（v3.0 才修）（#265）
