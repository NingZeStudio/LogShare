# Android 启动器生态（Pojav 系谱）

分析移动端渲染问题时，先分清玩家用的是哪个启动器——**同一渲染器在不同启动器里版本和集成方式不同**。

## 家族树

```
Boardwalk (2013)
└── PojavLauncher (PojavLauncherTeam)
    ├── 2025-09 官方归档，继任者：Amethyst-Android (AngelAuraMC)
    ├── Fold Craft Launcher (FCL-Team)         ★ 插件化最完善，见 plugin-system
    │   └── Zalith Launcher 1 → 2 (MovTery)    ★ ZL1 已归档，ZL2 活跃
    ├── Pojav Glow·Worm (Vera-Firefly)         ★ 本文主角 PGW
    │   └── Pojav Zenith Horizon (同作者)       ← PZH-X-PGW 仓库
    └── 其他社区分叉（Amethyst / OptiLauncher 等）
```

## Pojav Glow·Worm（PGW）

| 项 | 内容 |
|---|---|
| 仓库 | <https://github.com/Vera-Firefly/Pojav-Glow-Worm>（⭐349，活跃） |
| 定位 | PojavLauncher 的**功能增强魔改版**："adds more renderers and experimental settings" |
| 作者 | Vera-Firefly（BiliBili 发布视频，中文社区） |
| 平台 | 仅 Android |
| 版本支持 | rd-132211 ~ 1.21.x 快照；Forge/Fabric/OptiFine 均可 |

### 对诊断有意义的差异点

1. **内置更多渲染器**：比原版 Pojav 多出若干转译层选项——遇到"PGW 里能跑、Pojav 里不能跑"属正常。
2. **实验性设置**：部分崩溃排查要先确认用户是否开了实验开关。
3. 自带 JRE 构建链（android-openjdk-build），Java 版本问题排查同标准流程。

## 同作者的渲染基础设施（写文档时容易被忽略的宝藏）

| 仓库 | 用途 |
|---|---|
| [android-mesa-build](https://github.com/Vera-Firefly/android-mesa-build) | 给安卓设备构建 Mesa 渲染器（Zink/VirGL 的上游来源之一） |
| [mesa-25.2.0](https://github.com/Vera-Firefly/mesa-25.2.0) / mesa-build-fix | 新版 Mesa 构建与修复 |
| [TurnipDriver-CI](https://github.com/Vera-Firefly/TurnipDriver-CI) | **turnip 驱动 CI 构建**（Adreno 开源 Vulkan 驱动，配合驱动插件使用） |
| [FCL-Mesa-Plugin](https://github.com/Vera-Firefly/FCL-Mesa-Plugin) | FCL 的 Mesa 插件打包 |
| [MobileGlues](https://github.com/Vera-Firefly/MobileGlues)（fork） | MG 核心镜像 |

## Pojav Zenith Horizon（PZH）

- 仓库：<https://github.com/Vera-Firefly/PZH-X-PGW>（⭐7）
- 同为基于 PojavLauncher 的修改版；仓库名暗示与 PGW 有合并关系（X-PGW）
- 社区曝光度低于 PGW，文档以 PGW 为主

## 诊断时的提问模板

1. 你用的是哪个启动器？（PGW / FCL / ZL2 / Pojav / Amethyst…）+ 版本号
2. 选的渲染器是哪个？从哪装的这个渲染器（内置 / 插件 APK / 手动塞文件）？
3. 是否开过实验性设置？
4. Adreno 设备是否用过 turnip 驱动插件？

> 这四个答案能直接把渲染问题的搜索空间砍掉一半以上。
