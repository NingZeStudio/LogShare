# Mod 版本不匹配 / API 变动（NoSuchMethod / NoClassDefFound）

## 签名 (Signature)

```
java.lang.NoSuchMethodError: 'net.minecraft.class_1234 net.minecraft.client....'
java.lang.NoSuchFieldError: ...
java.lang.NoClassDefFoundError: com/example/some/Class
java.lang.AbstractMethodError
```
堆栈帧常落在 Mod 包名或 `...mixin...` 之后。

## 含义

Mod A 调用了 Mod B（或 MC 本体）的某个方法/类，但运行时该符号不存在——**编译期与运行期的二进制不一致**。MC 每个版本混淆映射都变（Fabric 里表现为 class_NNNN），跨版本 jar 必然炸。

## 常见触发

1. Mod 用错游戏版本（1.19.4 的 Mod 装进 1.20.1）
2. 两个 Mod 依赖同一库的不同大版本，装了旧的那个
3. 只更新了前置没更新依赖它的本体（或反之）
4. 整合包里混入了用户手动加的旧 Mod

## 修复步骤

1. 从堆栈第一个非 minecraft/JDK 包名帧确定肇事 Mod。
2. 确认它标注的支持版本与当前游戏+加载器一致，不一致 → 重下。
3. 版本一致仍炸 → 检查它依赖的库（Fabric API、Kotlin for Forge 等）是否同步更新。
4. 无法定位时二分法：移除一半 Mod 启动验证。

## 置信度线索

- **确定**：`NoSuchMethod/FieldError` + 帧含混淆名 `class_NNNN/method_NNNN` ≈ 99% 是版本错配。
- **排除**：`ClassNotFoundException` 且类名是玩家可见的 Mod 类 → 更可能是文件损坏/没装上，先查文件完整性。
