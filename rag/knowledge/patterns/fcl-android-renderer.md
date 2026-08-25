# FCL / Android 专项：渲染器与显示

基于 FoldCraftLauncher 源码（DriverPlugin 架构）的移动端诊断卡。

## 签名 (Signature)

```
hs_err: Problematic frame: libgl4es.so / libEGL.so / libOSMesa(Zink) ...
GLFW error ... EGL
游戏黑屏但无 crash-report（启动器日志里 GL 初始化失败）
渲染错乱：贴图全紫/模型闪烁（驱动兼容性问题）
```

## FCL 渲染器插件（长按"启动"键选择）

| 插件 | 原理 | 适用 |
|---|---|---|
| gl4es | GLES→GL 转译层 | 兼容面最广，默认首选之一；性能中等 |
| ANGLE | GL→GLES 再映射 | 部分 Mali 设备更稳 |
| Zink | Mesa 的 GL-on-Vulkan | Adreno 新机型性能最好 |
| VirGL | 虚拟 OpenGL | 兼容兜底，性能最低 |

## 排查顺序

1. 换渲染器：Zink → gl4es → ANGLE → VirGL，逐个试。
2. 黑屏但能听到声音 → 几乎必是渲染层：先换渲染器再动别的。
3. 贴图紫块/闪烁：关光影、降渲染距离排除资源因素后仍复现 → 换驱动插件版本。
4. `Problematic frame` 指向启动器自带库而非 Mod 库 → 不要删 Mod。

## 置信度线索

- **确定**：Android + hs_err 帧 = 启动器 GL 库。
- **坑**：FCL 的"启动"按钮长按才出渲染器菜单，很多玩家不知道这个入口——优先教操作。
