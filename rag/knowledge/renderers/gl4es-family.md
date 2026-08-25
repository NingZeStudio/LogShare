# gl4es 家族：gl4es / Holy GL4ES / NG-GL4ES (Krypton)

## 家族关系

```
ptitSeb/gl4es（上游，MIT）
   ├─ Holy-GL4ES      ← FCL-Team/Holy-GL4ES："Holy"加强分叉
   └─ NG-GL4ES        ← FCL-Team/NG-GL4ES：新一代分叉，Krypton 包装
                          （NG = New Generation；Krypton 是 Sodium 在转译层上的适配方案名）
```

## 原理

把桌面 OpenGL 1.5~2.x 调用**翻译成 OpenGL ES 2.0/3.0**。纯 CPU 侧转译 + 批处理优化，无 Vulkan 参与。

- **优点**：极轻量、对老 GPU 兼容性最好、内存占用低。
- **天花板**：只覆盖到 GL 2.1 级特性 → MC 1.17+ 的部分渲染管线、Sodium、多数光影跑不了或表现差。

## 各分支定位

| 分支 | 定位 | 典型用途 |
|---|---|---|
| 原版 gl4es | 经典稳定 | ≤1.16.5 原版；注意此版本段配 Sodium 需 Krypton 包装 |
| Holy GL4ES | 社区魔改，修补+小优化 | 1.17+ 无 Sodium 需求的过渡 |
| NG-GL4ES (Krypton) | 为 Sodium/Krypton 适配的新一代包装 | 低配机硬要 Sodium |

## 高频问题

| 症状 | 原因 | 处理 |
|---|---|---|
| 1.17+/1.21 黑屏或崩溃 | GL 版本不够 | 换 Zink/MobileGlues/LTW |
| 光影全紫/不生效 | 无 GLSL 3.3 特性 | 属预期，光影党换 MobileGlues/Zink |
| `libGL.so not found` 类报错 | 渲染器插件没装好/没选 | 重装对应插件 APK 并在启动器里选中 |
| 贴图闪烁（老 Adreno） | GLES 驱动 bug 被 gl4es 触发 | 关 Mipmap/换 ANGLE 试 |

## 来源仓库

- 上游：<https://github.com/ptitSeb/gl4es>
- FCL 分叉：<https://github.com/FCL-Team/Holy-GL4ES> · <https://github.com/FCL-Team/NG-GL4ES>
- 插件包：FCLRendererPlugin Releases 中的 `GL4ES.Plus.apk`、`Legacy.Holy.GL4ES.apk`
