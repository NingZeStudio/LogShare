# crash-report-*.txt 结构解剖

游戏线程内崩溃时，Minecraft 在 `.minecraft/crash-reports/` 生成的文本报告。分析入口。

## 整体结构（自上而下）

```
---- Minecraft Crash Report ----        ← 文件头
// 嘲讽语录（无信息量）
Time: 2024-xx-xx ...                    ← 崩溃时间
Description: <一句话描述>                ★ 第一线索

<异常堆栈>                               ★ 第二线索（主线程栈）

-- System Details --                    ← 系统详情
   Minecraft Version: 1.20.1            ★ 版本 → 决定加载器生态
   Fabric Loader: 0.15.x / Forge 47.x   ★ 加载器与版本
   Mods:  ↑ 常在此列出 mod 列表           ★ 数量、名称、版本
```

## Description 高频值速查

| Description 原文 | 指向 |
|---|---|
| `Initializing game` | 早期初始化：Mod 加载、资源注册阶段 |
| `Preparing launch tick` / `Applying holder lookups` | 注册表阶段，常见 Mod/依赖问题 |
| `Loading dimension` / `Exception in server tick loop` | 世界/区块/数据包问题 |
| `Rendering overlay` | 渲染层：OptiFine/光影/材质 |
| `Ticking entity` / `Ticking block entity` | 实体或方块逻辑死循环/坏档 |
| `Manually triggered debug crash` | 玩家按了 F3+C，不是真崩溃 |

## 加载器判定

| 报告特征 | 加载器 |
|---|---|
| `FabricLoader` / `net.fabricmc.loader` | Fabric |
| `Forge Mod Loader` / `fml` / `net.minecraftforge` | Forge |
| `net.neoforged` | NeoForge |
| 无 Mod 段落、纯 vanilla 栈 | 原版 |

## 阅读堆栈的规则

1. **从上往下找第一个 `Caused by:`**——最底层的 Caused by 才是根因；上层多为包装。
2. 关注**首个非 `java.*`/`net.minecraft.*` 的包名帧**：它通常指出肇事 Mod（如 `com.example.coolmod.`）。
3. `Mixin apply failed` / `... mixin ...` 字样优先看 mixin 目标类属于哪个 Mod。
4. 堆栈里的 `(mod-id.jar)` 注释（部分加载器会标注来源 jar）是定位元凶的捷径。
