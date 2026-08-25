# 缺前置 / 依赖版本不符

## 签名 (Signature)

Fabric:
```
Missing or unsupported mandatory dependencies:
- Mod fabric-api requires cloth-config ... 
```

Forge/NeoForge:
```
Mod §eforge§r requires §6minecraft§r §o1.20.1§r ...
Missing Mods: ...
```
或启动界面直接弹"缺少模组/依赖不匹配"窗口（FML 加载失败页）。

关键词：`missing mandatory dependencies`、`requires`、`Missing Mods`。

## 含义

加载器在早期校验 Mod 元数据（fabric.mod.json / mods.toml）声明的依赖：缺失、版本区间不符、Minecraft 版本不符都算。

## 常见触发

1. 只装了主 Mod 没装前置（如 Cloth Config、Architectury、Fabric API）
2. 前置版本下载错分支（给 1.20.4 的 Fabric API 装到 1.20.1）
3. 整合包更新后旧前置残留

## 修复步骤

1. 读报错里 `X requires Y (version range)`，逐条补齐/替换。
2. 版本区间如 `[18.0,)` 表示 ≥18.0；`[19,20)` 表示 ≥19 且 <20。
3. 从同一发行页（Modrinth/CurseForge）选**与游戏版本+加载器完全一致**的构建重下。

## 置信度线索

- **确定**：报错明确列出依赖名与所需区间 → 照单补齐即可，无需看堆栈。
- **注意**：Forge 的依赖错误常在启动前 GUI 就拦下，不一定生成 crash-report。
