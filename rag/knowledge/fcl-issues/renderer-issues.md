# 渲染器实战案例（FCL Issue 提炼）

按渲染器分组。格式：**症状 → 根因 → 解法**（#issue）。

## Vulkan Zink

### 设备适配
- **Zink 要求 Vulkan 1.3**：PowerVR GE8320 直接崩 → 无解，换 ANGLE/gl4es（#297）
- **天玑机型 Zink 支持普遍差**："能进一些版本都不错了"，尽量用骁龙（#276）
- **Turnip 驱动逐设备匹配**：A720 没有对应 Turnip 构建，只能等 Mesa 更新，且此类问题应报 Mesa 而非 FCL（#467）；部分设备加载 Turnip 就崩（OPPO K12/ColorOS 16 等），需提供 Vulkan 设备 ID 选驱动（#436 #1590）
- **骁龙 8 Gen4/至尊版等新卡**：新 GPU 初期无适配驱动，属正常空窗期（#996 #997）
- **Mali 老设备 Panfrost/Panvk**：兼容度仅 ~62%，实验性质（#1416 #370）

### 症状与解法
- **录屏/小窗时闪屏、卡住** → 录屏 overlay 与 Zink 冲突；关录屏或忽略（#439 #774）
- **OptiFine "快速渲染"引发加载阶段崩溃**（骁龙 7+G2 案例）→ 关闭 OptiFine 视频设置-性能里的快速渲染类选项（#151）
- **花屏** → 基本是厂商 Vulkan 驱动 bug，换 Mesa/Zink 版本或换渲染器（#467）
- **退出游戏崩溃**（26.2-snapshot-7 + Vulkan）→ 官方已让最新快照默认回 OpenGL（#1584 #1590）
- **VulkanMod 相关三连**：
  1. `NoSuchMethodError: VkApplicationInfo.wrap` → VulkanMod 用 LWJGL 3.3.2 而 FCL 内置 3.3.3，需 MC 1.21+ 的构建（#1161）
  2. FPS 显示失效 → VulkanMod 绕过启动器 SwapBuffers，无法统计（#1178 #1412）
  3. 官方立场：VulkanMod 不受支持（#292 #466 #483）
- **VEL 救不了新版本**：Vulkan Extension Layer 只提供 4 个扩展，无法让老设备跑 MC 26.2+（#1543）
- **可换自定义 Zink 驱动**：PGW 有 Mesa 切换功能；FCL 有 issue 请求同款能力（#382 #716）
- **加载 libvulkan.so 时被启动器替换为自定义驱动**：源码在 `FCLauncher/src/main/jni/lwjgl_dlopen_hook.c:23-49`（#1409）

## ANGLE
- **箱子 GUI 渲染错误** → ANGLE 自身缺陷，非 FCL 能修（#80 #81）
- **游戏内截图文件输出异常** → 同上，ANGLE 缺陷（#313）
- **Android 9 设备 gl4es+ 闪退** → 该渲染器以 ANGLE 为后端驱动，Android 9 不支持；后续可能移除该后端（#766）
- 定位：性能中等但一致性最好；也是 MobileGlues 可选的底层 ES 驱动

## VirGL
- **骁龙设备不推荐**：卡加载、性能差（#299）；**文字只有 Mali 能正常渲染**（#308）
- **维护者现役评价**：VirGL 在启动器里 bug 很多不建议用；推荐 MobileGlues/LTW 替代；MG 开 ANGLE 底层性能非常好但看设备支持（#1597）
- **Maleoon 910 闪烁**（帧交替错乱）：FPS>min(TPS,20) 时触发（#1597）
- 物品栏搜索后选择物品闪退（未修复，open）（#504）

## Holy GL4ES 家族
- **不能跑 Sodium** —— 最高频结论之一（出现 ≥5 次）→ 换 LTW / MobileGlues / Zink（#118 #269 #836 #951 #519）
- **跑不了 24w34a+ 快照**（黑屏/红屏/无画面）→ 等上游修或换 LTW（#532 #689）；选 Holy 启动 1.21.5 启动器会警告（#1249）
- **Holy 已闭源**：有 bug 上游无法处理（#858）；曾提议移除内置 Holy，因"部分 Mod 在 Krypton Wrapper 上有问题而 Holy 正常"被否（#1249）
- **gl_FragCoord 无法正常调用** → gl4es 层缺陷（#146）
- **open4es 光影开启即崩** → Holy 不支持（#141）
- **modular warfare 等重 GL Mod** → 只能用 zink 或 virgl，gl4es 不兼容（#546）
- **gl4es+ 在 vivo X21i (Android9) 闪退** → 见 ANGLE 条目 Android9（#766）
- **Embeddium 点开视频设置崩**（gl4es+）（未修，open）（#782）

## LTW
- **许可证风波**：LTW 是专有渲染器，2025 年因许可证争议从内置移除（#662 #789 #799），现经 [ShirosakiMio/FCLRendererPlugin](https://github.com/ShirosakiMio/FCLRendererPlugin) 以插件 APK 分发（#1023），注意该渠道构建可能过时
- **新插件版光影异常** → 已知问题，别混着报告（#798)
- **x86_64 模拟器崩溃**：`pojavSetInjectorCallback` 返回值与 invokePP 不匹配空指针（#1726）
- 1.21.3 Holy 黑屏时的社区替代方案就是它（#689）

## MobileGlues
- **无文字渲染 + 进世界崩** → MG 上游 bug，应到 MobileGL-Dev/MobileGlues-release 报（#933，对应上游 #189）
- **与 FCL 1.2.8.6 共存无法渲染** → 启动器回滚构建解决（#1399）
- **NeoForge 客户端参数意外重复** → 见 issue 详情（#840）
- 维护者口碑：优化非常好，光影党首选之一（#1597）

## 跨渲染器的通用教训

1. **先确认渲染器和来源**（内置 / 插件 APK / 手动塞文件），一半渲染 issue 是装错渠道的旧包。
2. **全局设置 vs 特定游戏设置**：渲染器改了没生效？检查该版本是否开了独立设置覆盖全局（#176）。
3. **一个渲染器不行就换**：FCL 社区的实际解法 90% 是"换渲染器"，而不是死磕配置。
4. **渲染层 bug 找上游**：ANGLE→google/angle，Zink/Turnip→mesa，MG→MobileGL-Dev，Holy→闭源没法找。
