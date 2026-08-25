# MobileGlues 实战案例（359 issues 蒸馏）

> 所有 `#N` 指 `https://github.com/MobileGL-Dev/MobileGlues-release/issues/N`。
> 359 个 issue 中仅 154 关闭——大量渲染异常属于"已知未修"，先查本页再报。

## 排障决策树

```
MG 下崩溃/花屏
├─ 开着 ANGLE？→ 关 ANGLE 试（Create 系相反：开 ANGLE）
├─ F3 崩溃？→ 关 timer_query（#427 #257）
├─ ModernUI 异常？→ 关"忽略报错"（#203）
├─ 手持物穿模？→ 开 ANGLE Depth fix；或关 ANGLE 换深度伪影（#389 #403）
├─ 26.3-snapshot+？→ 必须开"忽略 shader/program error"（V1.3.5+）
├─ libshaderconv.so.19 缺失？→ 升级 ≥V1.3.3（已彻底移除该库）
├─ 设备不支持 OpenGL 4.5？→ 用不了 MG，换 Holy/LTW/GL4ES（#347）
└─ 都不是 → 抓 hs_err_pidXXX.log + latest_game.log 再报（#439）
```

## 崩溃类

- **libshaderconv.so.19 缺失**（MTK 设备连带 `libGLESv2_mtk.so` 崩）：旧版打包缺陷，#264 #302 #329 #345 → **升级 V1.3.3+**
- **Create 系 fatal error 6**：hs_err 确认是 instances draw 问题；ANGLE 下多半是设备 Vulkan 驱动或 ANGLE 本身（#249）；JCraft 时停崩与 OpenGL43/ARB_compute_shader 无关，开扩展也没用（#327 #353）
- **Sodium 全屏后开设置崩（Android 16/OneUI 8）**：非立即崩，F11/全屏后才崩（#436 open）
- **Embeddium "Use Compact Vertex Format" 开启进世界即崩** → 关该选项（#334）
- **MIUI/HyperOS 破坏 SAF**：选 MG 文件夹闪退 → 用 [最新 CI 版插件](https://github.com/MobileGL-Dev/MobileGlues-plugin/actions/workflows/mg.yml)（#434）；HyperOS SAF 授权卡顿同源（#40）
- `MG_DIR_PATH` 报错 = 插件目录配置错误（#365）

## 渲染异常类

### Xaero 系（重灾区）
- 小地图/世界地图黑块、缩放崩溃、HUD 闪烁、雷达图标损坏贯穿 V1.0~V2.0（#1 #12 #41 #377 #421 #456）
- 不开 ANGLE 会闪烁（#421）；V2.0.0 修了 World Map 渲染
- 1.21.8-fabric + xaero_mini_map 25.2.16 HUD 异常闪烁在 ZL1/ZL2/FCL 通现（#377）

### 光影
- 天玑系芯片无法加载光影包（#448 open）；Mali-G710 上 Complementary/Photon 无效果（#340）
- Maleoon GPU 开光影白屏（#263）；Complementary Reimagined 黑色闪电状伪影（#396）
- DH 场景黑屏崩溃 → 换 Derivative 光影可绕（#414 #424）；DH 支持本身缺失（#417）

### Mod 特定
| Mod | 现象 | 解法 |
|---|---|---|
| Create / Sable | 渲染错乱、铜管花屏 | 开 ANGLE（#288 #299 #445）；GPU 顶点重负载属预期（#158）|
| Physics Mod | 崩溃、水面无敌 | 用 patched 版/换安卓 physx jni（#11 #290 #359）|
| ModernUI | 告示牌无文字、回落失效 | 别开忽略报错；升级 ≥V1.3.3（#203 + changelog）|
| MTR 铁路 | 列车轨道全黑 | 未修（#333 #385 #446）|
| BBS mod / Simple Clouds | 打不开/卡 | 未修；Simple Clouds 建议 Adreno 且避 Mali（#278 #381）|

## 性能类

- **1.21.6+ FPS 大跌**：Adreno GLES + Iris 的实体渲染 workaround 本身降性能（V1.2.7 公告）；性能回退主线在 V1.3.2 修复（#312 #388）
- **OpenGL43 移除争议**：三星 M12 上 ARB_compute_shader 替代后帧数腰斩（123fps→24fps 案例）（#409-#412 #415 #444）
- appleskin+REI 同装开物品栏掉帧（1.21.x fabric）（#254）
- MultiDraw 选型经验：Indirect 需扩展支持；Compute 对 Sodium 有问题（#330）；DrawElements 是兼容兜底最慢（#246）；部分 GPU/驱动 MultiDraw 无收益（#325 #272）
- V2.0.0 后有"越玩越卡"报告（#452 open），关注后续 hotfix

## 设备矩阵速记

- **Adreno**：生态最好但坑多 —— 730/740 禁 ANGLE 默认；830 曾被强制 ANGLE 已改；725 开 ANGLE 即崩（#379）；650 实体渲染错（#306）
- **Mali**：Xaero 进世界崩（V1.1.0.1 修）、G57 区块损坏建议 LTW、天玑加载不了光影包
- **Exynos**：e1080 F3 崩 = timer_query（#257 #258）
- **Maleoon**（麒麟 GPU）：光影白屏（#263）
- **PowerVR**：MultiDraw Indirect 曾经独占
