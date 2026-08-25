# FCL / ZL2 插件体系：渲染器插件、驱动插件、原生库插件

Pojav 系启动器把"非游戏本体的原生组件"全部做成了**可安装的 APK 插件**。三类插件的职责与来源：

## 总览

| 插件类型 | 职责 | 代表仓库 |
|---|---|---|
| 渲染器插件 | 提供 GL→GLES/Vulkan 转译层（见 [renderers/](../renderers/README)） | [ShirosakiMio/FCLRendererPlugin](https://github.com/ShirosakiMio/FCLRendererPlugin) · [FCL-Team/FCLRendererPlugin](https://github.com/FCL-Team/FCLRendererPlugin) · [ZalithLauncher/RendererPlugin](https://github.com/ZalithLauncher/RendererPlugin)（模板） |
| GPU 驱动插件 | 替换设备厂商的 Vulkan/GLES 驱动（如 turnip） | [FCL-Team/FCLDriverPlugin](https://github.com/FCL-Team/FCLDriverPlugin) |
| 原生库扩展插件 | 给 Mod 补缺失的原生依赖库 | [ZalithLauncher/NativeLibPlugin](https://github.com/ZalithLauncher/NativeLibPlugin)（模板） · [FCL-Team/zstd-jni-DH](https://github.com/FCL-Team/zstd-jni-DH) |

安全侧：Zalith 组织还有 [VerifiedPluginLoad](https://github.com/ZalithLauncher/VerifiedPluginLoad)——按 APK 签名信任列表校验插件。

## 渲染器插件包内容（FCLRendererPlugin Releases 实测清单）

`ANGLE.Renderer.apk`、`GL4ES.Plus.apk`、`Legacy.Holy.GL4ES.apk`、`LTW.apk`、`Mesa.23.1.9 / 24.2.7.apk`（含 Zink+VirGL）、`MobileGlues.apk`

> 安装后回到启动器的渲染器选择处刷新即可看到新条目；卸载插件 APK 即移除。

## 原生库插件工作原理（NativeLibPlugin 模板 README 要点）

插件本质是一个**带桌面图标、可被用户卸载的普通 APK**，内含 `jniLibs/<abi>/*.so`。启动器扫描已装插件后：

1. 在插件列表显示 `des` 配置的描述；
2. 按 `minMCVer` / `maxMCVer` 判断对当前版本是否生效（留空 = 不限）；
3. 启动游戏时把配置的 JVM 环境参数注入命令行，支持占位符：
   ```
   put("example.plugin.extra", "{nativeLibraryDir}libexample.so")
   → 实际注入 -Dexample.plugin.extra=<插件native库目录>/libexample.so
   ```
   `{nativeLibraryDir}` 会被替换为该插件的原生库绝对路径。

**这就是缺库问题的正解入口**：Mod 找不到 `.so` 时，先找有没有对应插件；没有则可用该模板自行打包。

### 已知实例

| 插件 | 提供的库 | 服务对象 |
|---|---|---|
| zstd-jni-DH (FCL-Team) | zstd JNI 绑定 | Distant Horizons Mod（需配合 MobileGlues 类现代渲染器） |

## 驱动插件（FCLDriverPlugin）

与渲染器正交：渲染器决定"GL 怎么翻译"，驱动插件决定"Vulkan/GLES 由谁执行"。Adreno 设备常用社区 turnip 驱动替换闭源出厂驱动以修复花屏/提帧。仓库无 README，用法同渲染器插件（安装 APK → 启动器内选择）。

## 排查顺序建议（渲染异常 + 缺库同时出现时）

1. 先解决缺库（有明确报错行，机械操作）。
2. 再换渲染器（黑屏/花屏/性能问题）。
3. 最后动驱动插件（前两者无效时的进阶手段）。
