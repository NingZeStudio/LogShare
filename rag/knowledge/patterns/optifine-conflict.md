# OptiFine 与 Mod 加载器冲突

## 签名 (Signature)

```
java.lang.NoSuchMethodError / NoClassDefFoundError（栈含 OptiFine 类，如 Config、CustomColorizer）
Mixin apply failed ... (目标为渲染相关类)
The game crashed whilst initializing game（无更具体线索 + 装了 OptiFine）
```

## 含义

OptiFine 是"本体补丁型"Mod：直接改写大量渲染/资源类。与 Fabric 优化系（Sodium/Iris）或部分 Forge Mod 的字节码假设冲突。

## 常见触发

1. Fabric + OptiFine（Sodium/Iris 与其互斥）
2. Forge 大版本刚更新，OptiFine 未跟进
3. OptiFine 放进 mods/ 但版本不支持当前 Forge

## 修复步骤

1. **Fabric**：卸载 OptiFine，改用 `Sodium + Iris + Lithium` 组合覆盖同等功能。
2. **Forge**：确认 OptiFine 版本号后缀支持当前 Forge 小版本；否则等待或改用 Embeddium/Oculus。
3. 报错栈里出现 OptiFine 类名即可直接移除验证。

## 置信度线索

- **确定**：Fabric 场景 + 栈含 OptiFine/Sodium 双方类 → 必冲突。
- **排除**：原版+纯 OptiFine 崩溃多为显卡/驱动问题 → 转 gpu-opengl 卡。
