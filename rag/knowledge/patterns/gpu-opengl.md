# OpenGL / 显卡驱动崩溃

## 签名 (Signature)

```
GLFW error 65543: GLX: Failed to create context / EGL is not or could not be initialized
org.lwjgl.opengl.OpenGLException: ...
glGetError ... returned GL_INVALID_ENUM / GL_OUT_OF_MEMORY
Failed to link program / shader compile error
```
hs_err 中 `Problematic frame` 指向 `libGL*`、`libEGL`、`nvd3dumx` 等驱动库。

## 含义

渲染上下文创建失败或 GPU 调用非法。**绝大多数不是 Mod 的锅**，是驱动/环境问题；少数是光影/材质触发驱动 bug。

## 常见触发（桌面）

1. 集显跑在核显驱动旧版上；双显卡选错了设备
2. 远程桌面/虚拟机里没有真 GPU → 需要 Mesa 软渲染（llvmpipe）
3. 光影包超出显卡能力（GL_OUT_OF_MEMORY）

## 常见触发（Android/FCL）

1. 渲染器插件与设备 GPU 不合：gl4es（兼容广）/ ANGLE(GLES 映射) / Zink(Mesa, Adreno 较好) / VirGL
2. 驱动插件版本与游戏版本不匹配

## 修复步骤

1. 桌面：更新/回退显卡驱动；虚拟机装 Mesa 并确认 `glxinfo | grep renderer`。
2. FCL：主界面长按启动键换渲染器，逐个试（顺序建议 Zink → gl4es → VirGL → ANGLE）。
3. 若特定光影/材质才崩 → 移除该资源验证。
4. 栈里同时出现某 Mod 类 + GL 调用 → 才考虑该 Mod 的渲染代码问题。

## 置信度线索

- **确定**：Problematic frame 是系统 GL 库且无 Mod 帧。
- **排除**：报错发生在资源加载前的窗口创建阶段时，Mod 因素几乎为 0。
