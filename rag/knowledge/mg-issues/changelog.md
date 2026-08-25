# MobileGlues 更新日志蒸馏（V1.0.0 → V2.0.0）

> 原文：[Releases](https://github.com/MobileGL-Dev/MobileGlues-release/releases)。按时间倒序整理，★ = 对排障有直接价值。

## V2.0.0（2026-08-09）
- 性能优化（部分场景显著）；**长时间运行内存占用优化**
- 修复 Xaero's World Map 渲染问题
- 插件：UI 全面重构（主页/设置/信息三页）、Material/Miuix 主题、横竖屏、SAF 访问 MG 目录
- MultiDraw 重构：按函数自定义 + **内置基准测试自动选最快模拟方式**
- ★支持从 FCL / ZL2 **借用 ANGLE 库**

## V1.3.5（2026-07-11）
- ★修复 Sodium 渲染错误（GLSL 中 `VULKAN` 宏定义不当）
- 纹理子系统行为修复
- ★**MC 26.3-snapshot-3 及以后必须开启"忽略 shader/program error"**
- 插件：SAF 换成 `MANAGE_EXTERNAL_STORAGE` 全文件访问

## V1.3.4（2026-02-11）
- 库体积显著减小；修多个崩溃与渲染异常
- Compute 模式转正；移除 `OpenGL43` 扩展选项（≡`ARB_compute_shader`）
- ⚠️ 移除后三星 M12 等设备 FPS 大幅回退（#409 #411 #412 #444）

## V1.3.3（2025-10-01）
- 新增三个自检扩展：`GL_MG_mobileglues`、`GL_MG_backend_string_getter_access`、`GL_MG_settings_string_dump`（前端探测用）
- ModernUI (3.12+) 及全版本渲染修复；同步函数失效修复
- ★**移除 libshaderconv**，着色器转换全部改用 glslang + SPIRV-Cross（终结 libshaderconv.so.19 缺失系列 issue）

## V1.3.2（2025-09-22）★
- ★**修复自 V1.1.0/V1.2.0/V1.3.0 以来的性能回退 + OptiFine 极低帧**
- GLES 3.1 有 `draw_elements_base_vertex` 却未使用导致 UI 消失 → 修复
- Framebuffer 子系统修复

## V1.3.2.RC1/RC2（2025-09）
- RC2 撤销 RC1 的 GL_ARB_buffer_storage 全设备支持与 Iris 修复中误伤的部分；大量"渲染内容缺失"修复

## V1.3.1（2025-08-22）
- ★**DSA 扩展默认禁用**（开 DSA 时 Create 等 Mod 渲染异常/崩溃）
- Complementary Reimagined / Unbound 光影无法启用修复

## V1.3.0（2025-08-09）
- 新扩展：DSA、Vertex Attribute Binding、MultiBind
- 内置 FSR 1 后处理
- AcceleratedRendering Mod 条件适配：仅特定 Adreno + 关 ANGLE + 需 ARB_compute_shader/OpenGL43
- 自定义 GL 版本功能（官方不推荐）
- ★GLES 3.0/3.1 上正常渲染 MC 1.21.6+
- NeoForge 加载屏文字消失修复；声明 GLSL 4.60

## V1.2.7 / hotfix1（2025-07-15）
- `timer_query` 开关（部分设备 F3 崩溃）；hotfix 修默认值错误
- Adreno 原生 GLES + Iris 1.21.6+ 实体渲染损坏 workaround（取自 OpenLTW，降性能）
- ANGLE 默认策略：Prefer Disabled=强制关；Prefer Enabled=Adreno730/740 或 Vulkan<1.2 时关

## V1.2.6（2025-07-13）
- ANGLE 下 1.21.5+ 手持物遮挡 workaround；ModernUI 告示牌文字修复
- iOS 适配起步；Compute 多重绘制改进
- ★FCL/ZL 更新 ANGLE 至新 commit，补齐 MG "MultiDraw Indirect" 所需扩展 → 性能大涨
- Amethyst（安卓/iOS）内置 MG
- 团队重心转向 MobileGL 项目

## V1.2.5（2025-04-24）
- 新实验性 MultiDraw 模拟模式：Compute

## V1.2.4（2025-04-13）
- DrawElements 多重绘制模式；Physics Mod + OptiFine Render Region 崩溃修复

## V1.2.0 ~ V1.2.3（2025-03）
- Adreno 高视距崩溃修复；多重绘制模式可切换；Adreno 830 不再强制 ANGLE
- 支持 PojavLauncher 等其他启动器；armeabi-v7a/x86/x86_64 架构支持
- 连续三个版本因改名不完全导致崩溃（1.2.1/1.2.2/1.2.3 互为热修）

## V1.1.x（2025-02~03）
- V1.1.1：Sodium 性能大幅提升；宿主要求降至 GLES 3.1
- V1.1.0：Iris/OptiFine 绝大多数光影阴影修复（Sundial、Derivative、Continuum 等）；Physics Mod 崩溃修复；GLSL 缓存优化
- V1.1.0.2：着色器冷启动时间大减
- V1.1.0.1：Mali 设备 Xaero 进世界即崩修复

## V1.0.x（2025-02）
- V1.0.0 首发：Sodium 兼容、Iris/OptiFine 光影、JourneyMap/Create 类自定义渲染 Mod
- V1.0.1 Xaero 崩溃修复 + glGetError 忽略选项；V1.0.2 GLSL 缓存；V1.0.3 Forge/NeoForge 崩溃修复；V1.0.4 Physics Mod 渲染错误修复；V1.0.5 Xaero 黑块修复
