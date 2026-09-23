# FCL / Android 专项：渲染器与显示

基于 FoldCraftLauncher 源码（DriverPlugin 架构）与《手机启动器的渲染器》（2026-08-22）的移动端诊断卡。渲染器详细兼容性数据见 `mobile_launcher/手机启动器的渲染器.txt`，退出码决策树见 `日志分析/日志报错-退出码-KB-EXIT6-001`。

## 签名 (Signature)

```
hs_err: Problematic frame: libgl4es.so / libEGL.so / libOSMesa(Zink) ...
GLFW error ... EGL
GLFW error: 65542 / WGL: Failed to create OpenGL context（驱动不支持当前 OpenGL 或 Intel 核显驱动过旧 → 移动端换 MG 渲染器）
java.lang.UnsatisfiedLinkError: liblwjglxx.so / libopenal.so 等 LWJGL 原生库加载异常（移动端 → 首先升级 FCL 到最新版，多数原生库问题随升级解决）
游戏黑屏但无 crash-report（启动器日志里 GL 初始化失败）
渲染错乱：贴图全紫/模型闪烁（驱动兼容性问题）
```

## 渲染器体系（2026 现状，长按"启动"键选择）

所有渲染器本质都是图形 API 翻译层：Android GPU 原生只有 OpenGL ES，MC Java 需要 Desktop OpenGL。

| 渲染器 | 原理 | 定位 |
|---|---|---|
| KW（Krypton Wrapper）| GL4ES 变体，OpenGL 2.1+扩展增强 | 启动器默认，开箱即用；原版/常规模组首选（90% 用户）|
| MG（MobileGlues）| OpenGL ES 3.x 原生实现（非 GL4ES）| 模组/光影最推荐；Sodium/Iris/Create 支持优秀；需 ES 3.0+，未内置需插件安装 |
| Zink | Mesa 的 GL-on-Vulkan | 高阶兜底：KW/MG 跑不了的模组与光影；Adreno 表现好、Mali 问题多；骁龙设备仅支持旧 Mesa 版本（新 Mesa 闪退）|
| LTW（Large Thin Wrapper）| OpenGL 3.0 | 低端设备帧率稳定性优于 MG；仅 MC 1.17+；1.21.11+ 在 Mali 上有已知问题 |
| Holy GL4ES | 旧版 GL4ES，OpenGL 2.1 | 仅建议 MC ≤1.12.x 老设备；1.17+ 表现差，快照已确认崩溃，事实废弃 |
| Freedreno | Mesa 的 Adreno 开源驱动 | 新兴方案（Adreno 5xx+），暂未列入主推荐梯队 |
| VirGL / ANGLE / VGPU | 虚拟 GPU / 转换层 | 已边缘化或淘汰：仅调试与 iOS 场景，**不得作为切换建议** |

推荐优先级（2026-08）：MG（模组/光影）→ KW（默认稳定）→ Zink（极端场景）→ LTW（低端补充）→ Holy（仅 ≤1.12.x 兜底）。

## 排查顺序

1. 原生库加载失败（UnsatisfiedLinkError）→ 先升级 FCL 到最新版，再谈渲染器。
2. 非光影场景崩溃：当前 KW → 关全部 MG 扩展、按 SoC 调 ANGLE 后端（Exynos/麒麟/展锐必须启用，天玑推荐启用，Adreno 保持禁用）；仍崩 → 切 MG。当前 Zink/LTW → 切 KW 或 MG。
3. 光影场景崩溃：优先 MG（光影兼容优于 Zink 且开销低）；KW 仅支持基础光影，高负载光影必崩 → 切 MG 或 Zink。
4. 黑屏但能听到声音 → 几乎必是渲染层：先换渲染器再动别的。
5. 贴图紫块/闪烁：关光影、降渲染距离排除资源因素后仍复现 → 换渲染器/驱动插件版本。
6. `Problematic frame` 指向启动器自带库而非 Mod 库 → 不要删 Mod。

## 置信度线索

- **确定**：Android + hs_err 帧 = 启动器 GL 库。
- **坑**：FCL 的"启动"按钮长按才出渲染器菜单，很多玩家不知道这个入口——优先教操作。
- **坑**：MG 未内置在启动器中，需要插件安装；日志里找不到 MG 时先确认是否装了插件，而不是断定用户配置错误。
