# Zink 与 VirGL（Mesa 系）

## 是什么

两者都来自 **Mesa 3D** 图形栈，随 Mesa 版本打包成插件（如 FCLRendererPlugin 的 `Mesa.23.1.9.apk`、`Mesa.24.2.7.apk`）：

- **Zink**：Mesa 的 OpenGL-on-**Vulkan** 实现。只要设备 Vulkan 驱动过关，就能拿到接近原生的 GL 4.6 能力。
- **VirGL（virglrenderer）**：为虚拟机/代理场景设计的虚拟化 OpenGL。在手机上作为"兜底"渲染器。

FCL-Team 维护 mesa 只读镜像：<https://github.com/FCL-Team/mesa>（上游 gitlab.freedesktop.org/mesa/mesa）。

## Zink

| 维度 | 表现 |
|---|---|
| GL 上限 | 4.6（当前 Mesa） |
| Sodium | ✓ |
| 光影 | ✓ 强（GLSL 完整度高，多数 Iris 光影可用） |
| 性能 | **Adreno（骁龙）新机型最佳**；Mali/Xclipse 视驱动版本浮动 |
| 前提 | 设备 Vulkan 1.1+ 且厂商驱动质量过关 |

### 高频问题

| 症状 | 原因 | 处理 |
|---|---|---|
| 启动即崩，hs_err 指向 `libOSMesa`/`libgallium` | Vulkan 驱动不达标或 Mesa 版本与设备不合 | 换 Mesa 插件版本；Mali 老设备改用 ANGLE |
| 画面花屏 | 厂商 Vulkan 驱动 bug | 关闭"异步/管道"类优化选项；换 Mesa 版本 |
| 发热降频严重 | Vulkan 路径满血输出 | 属预期，限制帧率 |

## VirGL

| 维度 | 表现 |
|---|---|
| 定位 | 兼容兜底、极低配机 |
| 性能 | ★☆☆☆☆（虚拟化开销大） |
| 光影 | 部分 |

### 高频问题

- 帧率低是常态，别当性能方案。
- 与某些光影的 FBO 用法不兼容 → 换 Zink/MobileGlues。

## 选型建议

- 骁龙 8 系 / Adreno 7xx：**首选 Zink**（光影+Sodium 全都要）。
- Mali / Xclipse（Exynos、天玑部分）：先试 MobileGlues，再试 ANGLE。
- 都不行再回退 VirGL。
