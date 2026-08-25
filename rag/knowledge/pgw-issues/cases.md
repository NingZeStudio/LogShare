# PGW 实战案例（197 issues 蒸馏）

## 自定义 Mesa/Turnip 驱动（PGW 核心玩法）

### 配对铁律
| 情景 | 结论 | 出处 |
|---|---|---|
| Mesa24.3.x + 新版 Turnip | **必须 Turnip24.3.x 配对**，暂无解法 | #348 |
| Mesa24.0.x | 需要设备额外 VK 扩展，通常不支持 → 等 Mesa 侧改动 | #331 |
| Mali-G76 Zink 崩 | 试 Mesa23.0.4 也崩，**只有 22.0.5 能用** | #323 |
| A6XX 新版 Turnip + SEUS 系光影 | 老 Turnip 23.2 反而能跑 SEUS-v11/Bliss/Continuum；新驱动不兼容老着色器 | #339 #340 |
| Adreno(Freedreno) 730 | 全版本不工作，换构建 | #387 |

### 自定义驱动来源
- Turnip：[Vera-Firefly/freedreno-auto-builder](https://github.com/Vera-Firefly/freedreno-auto-builder/actions) Actions
- Mesa：[Vera-Firefly/android-mesa-build](https://github.com/Vera-Firefly/android-mesa-build/actions) Actions，解压导入（#336 #333）
- 导入后仍想用新 Mesa：等 Actions 新构建，别用插件侧 OSMesa 覆盖（#353）

## 渲染器遗迹经验（已 EOL 但可借鉴）

- **VGPU ≤1.16.5**：旧版常用，1.17+ 崩，别浪费时间（#300）
- VirGL 翻屏：重启游戏即好（#355）；LLVMpipe 只跑 x86/amd64（#156）
- GL4ES pitseb 3.3 不适用于 1.17+（#301）
- 光影黑屏/渲染错：先换渲染器再骂启动器——"这通常是渲染器问题不是启动器问题"（#383 #289）
- 自定义 GLES 版本：PGW 不支持插件用 ES 3.2，NG-GL4ES 需 Custom env（#352）

## Mod / 加载器经验

- **Sodium patcher 对应版本**：patch 必须和 Sodium 版本严格匹配（#232 #234）
- Sodium 崩查渲染器：GL4ES 不行，LTW 可（#375）
- Forge 安装：`.jar` 安装器要用 Java 8 运行（部分要 Java17）（#144）；装不上先查网络（#341）
- **`-XX:+UseVectorCmov` JVM 参数会导致 Forge/OptiFine 安装失败**（#290）★
- OptiFine + 沉浸工程渲染错误：开关区域渲染无效（#223）；下界闪烁多为 OptiFine/vtest（#289）
- Feather 客户端：需要自己下文件，Mod 形式仍崩，不支持（#361 #365）
- YSM 已修（#406）；Bigger Reactor / MrToad：要求日志后无果（#402 #379）
- 32 位设备 1.20.5+：不支持（Pojav 后来支持了）（#345）

## Java 教训

- **32 位 + Java21**：panorama 背景错 + 进不去世界/服务器（#330）；aaaapai 提供 [jre21-aarch32](https://github.com/aaaapai/android-openjdk-build/actions)（#281）
- JDK 25：最新版已内置，指路 FCL-Team [java releases](https://github.com/FCL-Team/FoldCraftLauncher/releases/tag/java)（#425 #428）
- Java 运行时缺失文件被"裁剪" → 兼容性问题（#228）

## 文件管理雷区

- **MT 管理器/文件管理器复制移动出错** → 无法读 Version JSON、启动失败；无解只能全删重下（#347）
- 从别的启动器移植版本目录到 PGW 易翻车：版本检测不到直接拒绝启动（#283）
- SAF 选 /data 目录无权限（#322）
- 升级慢/卡 = 缓存问题（#382）；语言选择不能识别标识符里的 "-"（"8-forge1" 报错）（#174）