# Mixin 注入失败

## 签名 (Signature)

```
org.spongepowered.asm.mixin.transformer.throwables.MixinTransformerError
Mixin apply failed: {mixin类} -> {目标类}
org.spongepowered.asm.mixin.injection.throwables.InjectionError
Critical injection failure
```

## 含义

Mixin 在字节码层往 MC 类里注入代码。目标类结构变了（版本错配）、或两个 Mixin 冲突、注入点不存在 → 变换失败。

## 常见触发

1. Mod 版本与游戏/加载器不匹配（最常见，本质同 api-mismatch）
2. 两个 Mod 对同一方法注入且兼容性差（如多个优化类 Mod）
3. 目标方法是 `final`/构造器等不可注入点，Mod 没做防御

## 修复步骤

1. 读 `Mixin apply failed` 行：`-> ` 后面的**目标类**属于哪个 Mod/本体。
2. 若目标类是某 Mod 的：移除或更新该 Mod。
3. 若是两个优化/增强 Mod 同场（如 Sodium 系 vs OptiFine 系）→ 二选一。
4. Fabric 下先确认 Fabric API 与 Loader 都是当前游戏版本的最新构建。

## 置信度线索

- **确定**：`MixinTransformerError` 直接点名 mixin → 目标类。
- **降级**：只有 `InjectionError ... expected N but found M` 可能是软冲突——部分 Mod 会容忍并跳过，看是否真崩。
