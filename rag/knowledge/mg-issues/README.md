# MobileGlues 渲染器专题

> 来源：[MobileGL-Dev/MobileGlues-release](https://github.com/MobileGL-Dev/MobileGlues-release) 的 30 个 release + 359 个 issue（截至 2026-08）。
> 核心仓库 [MobileGL-Dev/MobileGlues](https://github.com/MobileGL-Dev/MobileGlues)，插件 [MobileGlues-plugin](https://github.com/MobileGL-Dev/MobileGlues-plugin)。
> 主要维护者：Swung0x48、BZLZHH、ChengBing1。

## 定位与架构

- 名字含义：**(on) Mobile, GL uses ES** —— 跑在宿主 **OpenGL ES 3.2** 之上的桌面 GL 实现，专为手机跑 Minecraft Java Edition 设计。
- 声明 GLSL 4.60（V1.3.0 起）；宿主要求 GLES 3.2（Sodium 场景自 V1.1.1 降到 3.1）。
- 组成：核心库 `libmobileglues.so` + 配套插件 App（渲染器设置 UI）。FCL/ZL2/ZalithOne/Amethyst 均可加载；V1.2.2 起也支持 PojavLauncher。
- ⚠️ 团队重心已转向新项目 [MobileGL](https://github.com/MobileGL-Dev/MobileGL)（V1.2.6 公告），MG 进入稳定维护节奏。

## 版本速查（详见 changelog.md）

| 版本 | 日期 | 一句话 |
|---|---|---|
| V1.0.0 | 2025-02 | 首发：Sodium 兼容 + Iris/OptiFine 光影 |
| V1.1.1 | 2025-03 | Sodium 性能大幅提升，GLES 要求降至 3.1 |
| V1.2.6 | 2025-07 | ANGLE 默认策略改版；FCL/ZL 新 ANGLE 带 MultiDraw Indirect 提速 |
| V1.3.0 | 2025-08 | DSA/MultiBind 扩展、FSR1、AcceleratedRendering 条件支持 |
| **V1.3.2** | 2025-09 | ★修复 V1.1.0 以来的性能回退 + OptiFine 极低帧 |
| V1.3.4 | 2026-02 | 库体积大减；移除 OpenGL43 选项引发部分设备帧数回退争议 |
| **V1.3.5** | 2026-07 | ★修 Sodium 渲染错误；26.3-snapshot-3+ 需开"忽略着色器报错" |
| V2.0.0 | 2026-08 | 性能/内存优化；插件重构；MultiDraw 自动基准选最快 |

## 关键设置概念（诊断必读）

### MultiDraw 模拟模式
| 模式 | 说明 |
|---|---|
| Prefer Base Vertex | 默认推荐，合并顶点调用 |
| MultiDraw Indirect | 需 `GL_EXT_multi_draw_indirect`（新 ANGLE 后多数设备可用）；PowerVR 系独占过 |
| Compute | 曾长期实验性，与 Sodium 有问题（#330）；V1.3.4 转正并内置基准测试 |
| Force DrawElements | 兜底兼容模式，性能最差（#246）|

- V2.0.0 重构为按函数高度自定义 + **自动 benchmark 选最快的模拟方式**。

### ANGLE 开关权衡
- **ANGLE 开**：解决 Create 系渲染异常（#288 #299）、Xaero 闪烁（#421）、部分设备渲染错误（#370）；代价：手持物穿模（需开 `ANGLE Depth fix`，#389）、个别设备花屏（#390）、主界面掉帧（#337）
- **ANGLE 关（原生 GLES）**：深度线状伪影（#403）、Adreno 上 Iris 1.21.6+ 实体渲染损坏（官方 workaround 借自 OpenLTW，降性能）
- 默认策略演变：V1.2.6 起 Adreno（除 740）默认启用 ANGLE；Adreno 730/740 或无 Vulkan 1.2 时禁用（V1.2.7）

### 扩展选项
- `timer_query`：部分设备 F3 崩溃 → 关闭即解（V1.2.7 加开关；#257 #427）
- `OpenGL43` ≡ `ARB_compute_shader`（V1.3.4 移除前者）；但三星 Exynos M12 等设备 OpenGL43 帧数显著更高（#409-#412 #444）
- `忽略着色器/程序报错`：最后手段！ModernUI 靠检测报错做回落，开了反而错（#203）；但 **MC 26.3-snapshot-3+ 必须开**（V1.3.5 公告）

### 已知问题清单
官方汇总 issue：[#9](https://github.com/MobileGL-Dev/MobileGlues-release/issues/9)、[#52](https://github.com/MobileGL-Dev/MobileGlues-release/issues/52)。Sodium/Iris/OptiFine **不提供官方支持**，仅尽力兼容，勿向上游 Mod 作者报（[PSA #313](https://github.com/MobileGL-Dev/MobileGlues-release/issues/313)）。
