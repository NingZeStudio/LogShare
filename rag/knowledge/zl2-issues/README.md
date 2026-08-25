# ZL2 Issue 实战案例库

> 来源：[ZalithLauncher/ZalithLauncher2](https://github.com/ZalithLauncher/ZalithLauncher2) 的 1219 个 issue（截至 2026-08，1112 已关闭）。
> 所有 `#N` 均可跳转 `https://github.com/ZalithLauncher/ZalithLauncher2/issues/N`。
> 主要维护者：MovTery、Lo-quee、aaaapai、Capslock800000、wangzheruoyun。

## 文档索引

| 文档 | 内容 |
|---|---|
| [renderer-issues.md](renderer-issues) | 渲染器实战（含 ZL2 特有的 Kopper Zink / SurfaceView / 插件新格式） |
| [mod-and-launcher.md](mod-and-launcher) | Mod 兼容性 + Java/JVM + 启动器本体 |
| [account-input-online.md](account-input-online) | 账户体系 / 输入与触控 / 联机 |

## 与 FCL 生态的关系（诊断前必读）

1. **共享底层**：ZL2 基于 PojavLauncher 核心，FCL 与 ZL2 共享大量组件——MobileGlues、Holy/LTW/Krypton 渲染器、NativeLibPlugin、Terracotta 联机。同一问题常两边同时出现，解法互通。
2. **新一代渲染器插件格式**：MovTery + MGDev + FCL-Team 正联合设计，启动器读取插件信息自动构建环境变量，无需插件自行读本地配置；ZL2 与 MG 已支持（#1569）。
3. **ZL2 特有概念**：
   - **SurfaceView 渲染开关**（旧称"备用表层渲染"）——Vulkan 渲染错误/画面旋转 90° 的第一解法（#1006 #1586 #1637）
   - **Kopper Zink 渲染器**——较新引入（#1518），有横竖屏旋转 bug（#1586 #1587）
   - **离线账户限制绕过**：2.4.0 起需微软登录，根目录建 `circumventLimit` 空文件可离线（#1124）

## ZL2 高频维护者结论速查

- **MC 26.1 需要 Java 25** + **LWJGL 3.4.1**（#473 #725 #1448）
- **安卓只能用 NDK 特制的 JRE**，Linux Java 直接导入不可行，来源 [FCL-Team/Android-OpenJDK-Build](https://github.com/FCL-Team/Android-OpenJDK-Build)（#804）
- **"清理"功能曾删 libraries 导致游戏全挂**，官方决定不再清理依赖库目录（#617）★
- **机械动力:航空学崩 = 插件只能补库不能保稳定**（#1094 #1182）
- **imgui 类 UI 放弃支持**：无鼠标控制方案 + 安卓兼容问题（#176 #24）
- **Terracotta 不对非中国地区提供保证**（#1553）
- **内存过高被杀回桌面 ≠ 崩溃**，同 FCL 结论（#1604）
