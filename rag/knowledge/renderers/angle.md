# ANGLE

## 是什么

Google 的 **A**lmost **N**ative **G**raphics **L**ayer **E**ngine：把 OpenGL ES 2/3 调用映射到宿主图形 API（Android 上通常仍是 GLES，也有 Vulkan 后端）。以"一致性"著称——Chrome 就用它。

- 仓库：上游 <https://github.com/google/angle>；FCL 使用分叉 <https://github.com/FCL-Team/angle-gles>
- 插件包：`ANGLE.Renderer.apk`（FCLRendererPlugin Releases）
- 许可：BSD 3-Clause

## 定位

| 维度 | 表现 |
|---|---|
| 兼容性 | ★★★★☆ 实现极规范，驱动差异被抹平 |
| 性能 | ★★★☆☆ 多一层映射，不如 Zink/MobileGlues 极限性能 |
| 光影 | 部分（取决于后端与版本） |
| 特色 | **Mali 设备上的稳定首选**；也被 MobileGlues 用作可选 ES 驱动 |

## 高频问题

| 症状 | 原因 | 处理 |
|---|---|---|
| 初始化失败 `EGL_NOT_INITIALIZED` | 与厂商 GLES 驱动冲突 | 换 ANGLE 版本或改用 Zink |
| 性能明显低于 Zink/MobileGlues | 双层转译开销 | 属预期，按需求取舍 |
| MobileGlues 里选了 ANGLE 后黑屏 | 组合兼容问题 | 在 MG 设置里切回系统 ES 驱动 |

## 与其他渲染器的关系

- MobileGlues 可**借用启动器的 ANGLE 库**作为底层 ES 驱动（其 Release Notes 明确支持从 FCL/ZL2 借用）。
- ANGLE ≠ 渲染器万能解：它解决"一致性"，不提供 GL 4.x 特性。
