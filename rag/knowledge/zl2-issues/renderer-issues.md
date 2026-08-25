# ZL2 渲染器实战案例

## SurfaceView 开关（ZL2 特有，先查这个）

- **Vulkan/Zink 画面渲染错误** → 关闭"使用 SurfaceView 渲染"（#1637）
- **Kopper Zink 竖屏/画面旋转 90°（Adreno 750）** → 已知 bug，关 SurfaceView 即解（#1586 #1587 #1613）
- **Samsung DeX 上游戏启动后悬浮层不消失** → SurfaceView 后端下启动器无法检测游戏画面状态，官方加了手动关闭按钮（#1492）
- 名称演变：旧称"启用备用表层渲染"，现名"使用 SurfaceView 渲染"——搜文档时注意（#1006）

## Kopper Zink（ZL2 新渲染器）

- 定位：较新引入的 Zink 方案；FCL 早已有类似能力，ZalithOne 第三方也加过（#1518）
- 旋转 bug 见上；其他问题先按通用 Zink 流程排查
- 社区请求"全设备支持 Vulkan Zink"被指方向错误：**GL4.6 跑老版本（1.12.2 等）反而出图形 bug**，老版本用 GL3.1 以下渲染器（#876）

## Mesa 版本策略

- **Mesa 23 全设备保底 + Mesa 25/26 可选**是社区共识；直接给 Mali 打 Mesa25 补丁会出新问题（#1565）
- 自定义驱动加载：`libvulkan.so` 会被启动器的 dlopen hook 替换为插件驱动（实现见 `lwjgl_dlopen_hook.c`，同 FCL #1409）
- Freedreno/Turnip 术语澄清：**freedreno = Adreno 的合规 GLES 驱动，Turnip = Vulkan 版**，社区常混用（#1339）；Adreno 8xx (a8xx) 支持长期缺（#1270 #1339）；Adreno 710 Zink 崩（#1306）；Adreno 610 `libopenal.so` SIGSEGV（#1361）

## MobileGlues 相关

- **Sodium 在 26.2 失效** → MG 的问题不是 ZL2 的，去 MobileGL-Dev 反馈（#1429）
- **Xclipse GPU 特殊配置疑问**：三星 A55 (Xclipse 530) 开光影掉到 5-7FPS，同机其他启动器快 10 倍 → 怀疑 ZL2 对 Xclipse 有特殊 MG 配置（#1626 #1632 open）
- **26.3-snapshot-3 无法启动** → 先看 [MG V1.3.5 更新公告](https://github.com/MobileGL-Dev/MobileGlues-release/releases/tag/V1.3.5)再报（#1548）
- **MG 里选"系统 Vulkan 驱动"在骁龙上会严重渲染错误甚至无法启动** → 用 Turnip 插件而非系统驱动（#1259）
- GTNH NEI 渲染异常（GL4ES/Krypton Wrapper）→ kw 渲染器随后加入（#520 #967）

## 快照兼容性时间线（重要背景）

| MC 版本 | 状态 | 出处 |
|---|---|---|
| ≤26.1 | 正常 | — |
| **26.1 需要 Java25 + LWJGL 3.4.1** | aaaapai 提供 Actions 构建 | #725 #1022 |
| **26.2**：Vulkan 退出崩溃、Sodium(MG) 失效、VEL 扩展不够 | 启动器已警告部分渲染器不支持 | #1429 #1180 #1543(FCL) |
| **26.3 快照**：SDL 加载崩，需 SDL3 支持；低端机 jvm-3 崩 | 待上游适配，启动器有警告 | #1655 #1668 #1618 #1693 |

## 其他

- 低版本(beta/alpha)画面过暗：游戏把 GL_QUAD 拆两个三角形的转换 bug → [GL4ES-Fix mod](https://github.com/ItsElix99/GL4ES-Fix)（#1310）
- Mali-G57 (Helio G100) OpenGL 区块渲染损坏/地形隐形 → 社区建议换 LTW（#1519）
- ZL2 随机冻结卡顿（对比其他启动器）→ 性能 issue 持续 open（#1362）
- 光影图标显示需求被拒后引社区不满（流程参考）（#1535）
