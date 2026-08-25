# Mod 兼容性实战案例（FCL Issue 提炼）

## 明确"不能跑"名单（别浪费时间排查）

| Mod/组件 | 原因 | 证据 |
|---|---|---|
| Sodium @ Holy-GL4ES | 渲染层不支持 | #118 #269 #836 #951 |
| VulkanMod（旧版） | LWJGL 版本不匹配；需 MC1.21+ 构建 | #1161 |
| LabyMod4 | 不支持 | #266 |
| Axiom 公理 | 用不了 | #1116 |
| Flashback / CorgiLib 等 imgui 系 | `imgui-java` 无 arm64-Android 构建 | #1239 #366 |
| Twitch 直播集成 | 无安卓 native 库 | #509 |
| Risugami's ModLoader (b1.7.3) | 需 Java7；可用 ModloaderFix 绕 | #296 |
| DistantHorizons 2.0.1 直接跑 | 不能跑在 OpenGL ES 上，需 zstd 插件方案 | #178 |

## 有明确解法的案例

### Distant Horizons（缺库问题的教科书）
- **症状**：`Unsupported OS/arch, cannot find /linux/aarch64/libzstd-jni_dh-1.5.7-6.so`
- **根因**：DH 自带的 zstd JNI 没有 Android aarch64 构建
- **解法**：安装原生库插件 [NativeLibPlugin zstd_jni_1.5.7-6_dhcompat](https://github.com/ZalithLauncher/NativeLibPlugin/releases/tag/zstd_jni_1.5.7-6_dhcompat)（#1316 → #1703）
- 历史：DH 的 SQLite 包名被改名为 `dh_sqlite` 也曾引发误判（#890）；FCL 还专门出过带 SQLite 的 Java21 构建（#413）

### JNA 类 Mod
- `Could not initialize class com.sun.jna.Native`：
  - 若启动早期崩：曾是 FCL 启动器 bug，1.1.3 修复；也有案例是**存档损坏**（#100 #191）
  - 若特定 Mod 崩：FCL 只内置 JNA **5.13.0** 的 `libjnidispatch.so`，其他版本让用户手动下载替换（#518 #420）

### Create 机械动力
- Fabric 1.20.1 Create 崩溃：**只有高通平台不用加修复 Mod，其他平台必须用修复 Mod**（#348）

### Forge + OptiFine
- 新版 Forge 删除了 OptiFine 依赖的方法 → 等双方更新；老版本用 OptiFine I6_pre6（#46 #137）
- OptiFabric 进世界崩 → Mali G57 设备用 VirGL 替代 Holy 可解（#310）

### 版本/依赖元数据类
- owo-lib 要求 fabric loader ≥0.76：升级 loader 或用 FCL Actions 构建里的补丁（#168 #207）
- NeoForge 改版本号规则导致自动安装识别失败 → 官方临时补丁（#184）
- Indium 报 Sodium 版本区间不符 → 按 `从 X（含）到 Y-（不含）` 区间装对应版本（#440）
- Rubidium/Embeddium 渲染伪影 → "rendering issue cannot be resolved"，换渲染器（#524）

### 行为怪异类
- **Fabric "Incompatible mods found!" 卡转圈** → 该弹窗只在桌面端显示，手机上 JVM 不退出就永远转圈。看 latest.log 找不兼容列表才是正道（#976）★ 高频误判点
- smoothfont 切字体失效 → 换内置 JRE8（#143）
- JEI 搜索无效、聊天卡顿等 → 多为游戏/网络问题，先 PC 复测（#174 #497）
- DawnCraft（约 500 Mods）→ "almost impossible to run on FCL"（#214）
- 万用汉化材质包崩溃 → 材质包本身不适配手机（#531）
- 正版皮肤服务器不显示 → 万用皮肤补丁类方案（#139）
- Watut 实时 GUI 不显示他人动作 → 模组新版特性不支持，回退旧版界面（#1268）

## 通用原则（维护者反复强调）

1. **不是所有 Mod 都能在手机上跑**——需要改渲染或带非 NDK 编译动态库的 Mod 基本无解（#366 root-S7 的经典回复）。
2. **报错先看是不是缺 native 库**：`libxxx.so not found` / `imgui-javaarm64` / `libzstd` 这类词一出现，走[缺库诊断](../android-native-lib/missing-library)。
3. **没有日志就没有帮助**："no log no help"（#186）。索要 `latest_game.log` 全量 + crash-report。
4. **上游问题去上游仓库**：Mod 的锅别找启动器。
