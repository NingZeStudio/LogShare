# Android 原生库缺失问题（dlopen failed 全家桶）

## 背景：为什么 PC 上好好的 Mod 到手机上就"缺库"

Mod 自带的 `.so` 是按桌面平台编译的。Android 的动态链接器 (`/system/bin/linker64`) 规则完全不同：

1. **没有 libGL.so**——Android 只有 GLES；桌面 GL 由渲染器插件伪装提供。
2. **ABI 必须匹配**——`arm64-v8a` / `armeabi-v7a` / `x86_64` 各是独立目录，装错就找不到。
3. **Linker Namespace 限制**（Android 7.0+）——App 不能随意 dlopen 系统私有库，只能加载自己 ABI 目录与白名单路径下的库。
4. **依赖闭包**——`.so` 还依赖别的 `.so` 时，那些也必须在可搜索路径里。

## 错误签名速查

| 日志原文 | 含义 | 处理方向 |
|---|---|---|
| `dlopen failed: library "libxxx.so" not found` | 库不在任何搜索路径 | 见场景 A |
| `library "libGL.so" not found` | Mod 直接链接桌面 GL | 换带 GL 桩的启动器环境/找 Android 移植版 Mod |
| `... is not accessible for the namespace "classloader-namespace"` | 命中 Namespace 限制 | 库必须放进 App 可控目录，见场景 B |
| `wrong ELF class: ELFCLASS32` / `ELFCLASS64` | 32/64 位混装 | 补对应 ABI 的 .so 或统一启动位数 |
| `relocation ... cannot be used with symbol` / `undefined symbol: _ZN...` | so 编译工具链/NDK 版本太老或符号缺失 | 找重新编译的版本，无解则弃用该 Mod |
| `has invalid e_entry/e_type` | 文件损坏或根本不是 .so | 重下 |

## 场景 A：Mod 缺自带原生依赖

典型：需要压缩库、物理引擎、FFI 绑定的 Mod。

1. 确认 Mod 是否有 Android 构建（看 Mod 发布页是否提供 aar/apk 插件）。
2. 有 → 用启动器的**原生库插件**机制安装（见 [plugin-system](plugin-system)），例如 Distant Horizons 需要 zstd：FCL-Team 提供 [zstd-jni-DH](https://github.com/FCL-Team/zstd-jni-DH)。
3. 没有 → 该 Mod 在 Android 无解，只能找替代品。

## 场景 B：Namespace / 路径问题

- 库文件必须放在启动器能注入到 `LD_LIBRARY_PATH` / JVM `-D` 参数的目录下。
- 手动塞 `/data/local/tmp` 或 sdcard 根目录通常**无效**（namespace + noexec 挂载双重限制）。
- 正确姿势就是打包成插件 APK，让启动器把插件的 `nativeLibraryDir` 注入运行时。

## 场景 C：64 位设备跑 32 位游戏环境

- 部分老启动器/JRE 组合只有 armeabi-v7a；Mod 只发 arm64 → 冲突。
- 处理：优先全 64 位栈（现代 FCL/ZL2 默认）；确需 32 位时找 32 位版本的依赖库。

## 给诊断 Agent/文档使用者的提示

- 报错行在 **latest.log 末尾**或 logcat 里（hs_err 不一定生成）。
- 让用户提供：完整错误行、Mod 名、启动器+版本、设备 ABI（`adb shell getprop ro.product.cpu.abilist`）。
