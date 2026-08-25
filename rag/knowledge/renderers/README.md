# 渲染器知识总览（Android 端 Minecraft: Java Edition）

## 为什么手机需要"渲染器"

桌面 Minecraft 通过 LWJGL 调用 **OpenGL 2.0~4.6**。而 Android 只原生暴露 **OpenGL ES**（且各厂商驱动质量参差）。中间必须有一层"翻译"：

```
Minecraft (Java)
   ↓  LWJGL 调用 GL 函数
渲染器（转译层）★ 本文档主角
   ↓  转译成 ES / Vulkan 调用
设备 GPU 驱动 (Adreno / Mali / PowerVR / Xclipse...)
```

不同转译层的取舍 = **兼容性 ↔ 性能 ↔ 功能（光影/Sodium）三角**。

## 渲染器选型速查表

| 渲染器 | 底层技术 | GL 上限 | Sodium | 光影(Iris/OptiFine) | 适用场景 | 详细页 |
|---|---|---|---|---|---|---|
| gl4es | GL→GLES 2 转译 | ~2.1 | ✗ | ✗ | ≤1.16.5 老版本、低配机 | [gl4es-family](gl4es-family) |
| Holy GL4ES | gl4es 加强分叉 | ~2.1+ | 部分 | 部分(弱) | 1.17+ 过渡方案 | [gl4es-family](gl4es-family) |
| NG-GL4ES (Krypton 包装) | gl4es 新一代分叉 | ~2.1+ | ✓(Krypton) | 弱 | 低配机跑 Sodium | [gl4es-family](gl4es-family) |
| ANGLE | GL→GLES/Vulkan（Google 官方级实现） | 3.x+ | ✓ | 部分 | Mali 设备稳定性首选 | [angle](angle) |
| Zink | Mesa GL-on-Vulkan | 4.6 | ✓ | ✓(强) | Adreno 新机型性能王 | [zink-virgl](zink-virgl) |
| VirGL | Mesa 虚拟化 GL | 3.3~4.x | ✓ | 部分 | 兜底兼容 | [zink-virgl](zink-virgl) |
| LTW | 社区转译层 | ~3.0 | ✓ | 部分(阴影弱) | 追求帧率的整合包玩家 | [ltw](ltw) |
| MobileGlues | 自研 GL-on-GLES 3.2 | 3.2+ | ✓ | ✓(强，持续适配) | 综合推荐、光影党 | [mobileglues](mobileglues) |

> 表格是经验性总结，具体以各渲染器 Release 页与设备适配矩阵为准。
>
> 各启动器（PGW / FCL / ZL2 / Pojav）的渲染器集成差异见 [launchers/pgw](../launchers/pgw)。

## 排查渲染问题的通用流程

1. **确认当前渲染器**：FCL 主界面长按"启动"；ZL 在全局/版本设置的 Renderer 处查看。
2. **黑屏但有声音** → 渲染层没起来：先换渲染器再查别的。
3. **闪退且 hs_err 的 Problematic frame 指向 `libgl*`/`libEGL`/`libOSMesa`** → 渲染层崩溃：换渲染器或换该渲染器的版本。
4. **贴图紫块/闪烁/模型错乱** → 驱动 bug：降渲染距离排除资源包后仍复现 → 换渲染器。
5. **特定光影才炸** → 不是渲染器的错：看该光影的适配矩阵（MobileGlues 维护了 CompatibleShaders 列表）。

## 各启动器的插件分发渠道

| 启动器 | 渲染器插件 | GPU 驱动插件 | 原生库扩展 |
|---|---|---|---|
| Fold Craft Launcher (FCL) | [ShirosakiMio/FCLRendererPlugin](https://github.com/ShirosakiMio/FCLRendererPlugin)、[FCL-Team/FCLRendererPlugin](https://github.com/FCL-Team/FCLRendererPlugin) | [FCL-Team/FCLDriverPlugin](https://github.com/FCL-Team/FCLDriverPlugin) | [FCL-Team/zstd-jni-DH](https://github.com/FCL-Team/zstd-jni-DH) |
| Zalith Launcher 2 (ZL2) | [ZalithLauncher/RendererPlugin](https://github.com/ZalithLauncher/RendererPlugin)（构建模板） | 同上体系互通 | [ZalithLauncher/NativeLibPlugin](https://github.com/ZalithLauncher/NativeLibPlugin) |

详见 [plugin-system](../android-native-lib/plugin-system)。
