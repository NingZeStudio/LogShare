# Java 版本选错

## 签名 (Signature)

```
java.lang.UnsupportedClassVersionError: ... has been compiled by a more recent version of the Java Runtime (class file version 65.0), this version of the Java Runtime recognizes class file versions up to 61.0
```

关键词：`UnsupportedClassVersionError`、`class file version`。

## 含义

某个 class 的编译版本高于当前 JVM 支持版本。版本号换算：**52=Java8，61=Java17，65=Java21**。

## 常见触发（按频率）

1. MC 1.20.5+ / 1.21 用了 Java 17（需要 21）
2. MC 1.17–1.20.4 用了 Java 8（需要 17）
3. 某个 Mod 单独要求更高 Java（如新版 Mixin/Fabric API）

## 修复步骤

1. 按游戏版本选 JRE：≤1.16.5 → Java 8+；1.17–1.20.4 → Java 17；1.20.5+ → Java 21。
2. 启动器里改该版本的 Java 路径（FCL：管理版本页 → Java 管理）。
3. 若是单个 Mod 报错：升级/降级该 Mod 到匹配当前 Java 的构建。

## 置信度线索

- **确定**：签名完整出现，且 version 号 > 当前 JRE 上限。
- **排除**：报的是 `NoClassDefFoundError`（那是缺类，不是版本问题）。
