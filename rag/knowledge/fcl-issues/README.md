# FCL Issue 实战案例库

> 来源：[FCL-Team/FoldCraftLauncher](https://github.com/FCL-Team/FoldCraftLauncher) 的 1442 个 issue（截至 2026-08，其中 980 已关闭）。
> 所有 `#N` 均可直接跳转 `https://github.com/FCL-Team/FoldCraftLauncher/issues/N`。
> 提炼规则：只收录**有明确根因或解法**的案例；纯情绪帖、无日志帖已剔除。

## 文档索引

| 文档 | 内容 | 案例数 |
|---|---|---|
| [renderer-issues.md](renderer-issues) | 渲染器实战：Zink/ANGLE/VirGL/Holy/LTW/MobileGlues | ~50 |
| [mod-compat-issues.md](mod-compat-issues) | Mod 兼容性：Sodium/Create/DH/imgui/JNA/OptiFine… | ~25 |
| [launcher-issues.md](launcher-issues) | 启动器本体：Java/内存/ROM/模拟器/控制布局 | ~20 |
| [account-multiplayer-issues.md](account-multiplayer-issues) | 登录与联机 | ~15 |

## 高频维护者结论速查（一句话版）

- **Sodium cannot run on Holy-GL4ES** —— 换 LTW / MobileGlues / Zink（#951 等 5 次）
- **Zink 尽量配骁龙**，天玑适配差（#276）；Zink 要求 **Vulkan 1.3**（#297）
- **Turnip 驱动按设备逐个适配**，部分设备加载即崩（#436 #1590）
- **VirGL 不推荐骁龙**，文字只有 Mali 正常（#308）；官方现推荐 MG/LTW 替代（#1597）
- **Holy GL4ES 已闭源**，上游 bug FCL 无法修（#858）
- **imgui 类 Mod 没有 Android 构建**，无解（#1239）
- **"游戏无声无息消失 = 被系统强杀"**，不是崩溃（#1556）

## 数据画像（供检索参考）

- 渲染相关 issue 约 803 条提及（占 56%），其中 Zink 56、Holy/gl4es 124、ANGLE 21、VirGL 16
- 高峰期：2024 下半年（1.20.x 整合包潮）、2025 下半年（1.21.x 快照期）
- 主要维护者回复账号：ShirosakiMio、Tungstend、zkitefly、XiaoluoFoxington、aaaapai、ct-yx、root-S7
