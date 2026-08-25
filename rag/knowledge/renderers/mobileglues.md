# MobileGlues

> **M**obile, **GL** **uses** **ES** —— 跑在宿主 OpenGL ES 3.x 上的 GL 实现，专为 Minecraft: Java Edition 移动端设计。

- 仓库：<https://github.com/MobileGL-Dev/MobileGlues-release>（最新 2.0.0，见 Releases）
- 语言：英文 / 简体中文 / 繁體中文 / 日文文档齐全
- 分发：独立 Release + FCL/ZL 渲染器插件包（`MobileGlues.apk`）

## 能力矩阵

| 特性 | 支持 |
|---|---|
| Sodium | ✓（官方特性列表第一条） |
| Iris 光影 | ✓ 大部分（维护 CompatibleShaders.md 兼容列表） |
| OptiFine 光影 | ✓ 大部分 |
| 自定义渲染 Mod | JourneyMap、Create 等可跑（CompatibleMods.md） |
| GPU 占用上报 | ✓ 对接 MC 的 GPU% 显示 |

## 特色机制

1. **可选 ANGLE 作为 ES 驱动**：系统 GLES 驱动烂的设备（某些 Mali），可在 MG 设置里改用 ANGLE 底层。
2. **借用启动器的 ANGLE 库**：可直接复用 FCL 或 ZL2 内置/插件的 ANGLE，不必重复安装。
3. **着色器缓存**：光影包重载更快。
4. **MultiDraw 模拟策略自适应**：内置基准测试自动选最快的 MultiDraw 模拟方式（不同驱动差异巨大）。

## 高频问题

| 症状 | 原因 | 处理 |
|---|---|---|
| GLES 3.1 设备 UI 消失/绘制缺失 | 老版 MG 未用 draw_elements_base_vertex 扩展 | 升级到新版 MG（已修复） |
| OptiFine 场景帧率骤降 | 历史版本性能回归 | 用新版（已修复并优化） |
| 开 ANGLE 底层后黑屏 | 组合兼容问题 | 切回系统 ES 驱动 |
| Xaero's World Map 渲染错误 | 已在新版修复 | 升级 MG |

## 选型建议

- **综合推荐位**：功能、稳定性、光影三者的当前最优平衡点。
- 设备适配差异大：查其 **Mod Support Matrix / Shader Support Matrix**（按设备 codename 查表），必要时提交自己的设备数据。

## 与其他渲染器的底层关系

```
Minecraft → MobileGlues (GL→GLES 转译)
                 └─ ES 后端二选一：
                     ├─ 系统 GLES 驱动（默认）
                     └─ ANGLE（可借用 FCL/ZL2 的库）
```
