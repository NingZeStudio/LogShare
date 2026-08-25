# Pojav Glow·Worm (PGW) 专题

> 来源：[Vera-Firefly/Pojav-Glow-Worm](https://github.com/Vera-Firefly/Pojav-Glow-Worm) 的 197 个 issue + 229 个 PR（截至 2026-08，154 closed）。
> ⭐349，2022 年建仓。作者 Vera-Firefly（B 站活跃）。所有 `#N` 指 `https://github.com/Vera-Firefly/Pojav-Glow-Worm/issues/N`。

## 地位与现状

- Pojav 深度魔改版，最大卖点是**自定义 Mesa/Turnip 驱动管理**和实验性渲染器。
- **已基本停更（半 EOL）**：活跃期 2024~2025 初，2025 下半年后 issue 稀疏；社区常见答复 "Pojav is EOL"（#423）。延续其思路的 fork 有 ZalithOne、Zeryth-Launcher 等。
- 生态位：FCL 主打稳定开箱即用，PGW 主打"折腾驱动"。参考价值在于渲染器驱动兼容矩阵。

## 渲染器武器库（PGW 特有）

| 渲染器 | 状态/经验 |
|---|---|
| Zink (Mesa) | 主推；驱动可自定义导入 |
| Freedreno (Adreno GLES) | 兼容差：A730 不工作（#387）、部分设备 Vulkan ID 认了也崩（#405） |
| Turnip (Adreno Vulkan) | 需与 Mesa 严格配对（见 cases.md） |
| VirGL | 屏幕翻转 bug → 重启游戏（#355）；Mesa 更新排期（#197） |
| VGPU | **只支持到 1.16.5**，1.17+ 启动即崩（#300 #110） |
| VKGL | 实验品，默认不可用（#324） |
| OSMesa (CPU) | **内置下载导入**，无需渲染器插件；插件导入反而破坏平衡（#353） |
| LTW | 曾加入后移除（授权争议 #319 + 维护问题 #351）；官方建议想用 LTW 的去 [MobileGlues](https://github.com/Swung0x48/MobileGlues-release/releases)（#364） |
| Krypton Wrapper (NG-GL4ES) | 2025-12 加入（#403）|

## 官方结论速查

- **Mesa 与 Turnip 必须同版本配对**：Mesa24.3.x 要配 Turnip24.3.x，无解（#348）；Mesa24 需要设备支持更多 VK 扩展（#331）
- 新版 Turnip 反而兼容更差：老驱动（23.2）能跑 SEUS 系光影（SEUS-v11、Bliss_v2.0.4、Continuum），新驱动不行（#339 #340）
- Mali 设备别抱光影期望（"isn't friendly with shaders"）（#368）
- Samsung 设备上 Zink 用 CPU 渲染 → 把启动器加进 Samsung Game Launcher 游戏分类可解（#350）
- Sodium + GL4ES 崩 → 换 LTW 渲染器（#375）
- fatal signal 6 → 重新选择 Java 运行时（#390）；重装 + 默认设置仍崩 → 反馈时带日志（#415）
- 快照 25w43a 崩 = Mojang LWJGL 改动；**25w44a 恢复**因为官方回退（#396）——快照崩先看是不是 Mojang 自己反复横跳

## 报障习惯注意

Vera-Firefly/社区常要求：提供 latest.log；用启动器内置终端抓 logcat（#239）；确认 32 位/GPU/驱动版本。缺少信息直接打回。