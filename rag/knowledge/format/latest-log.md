# latest.log / debug.log 关键行

游戏"闪退但没生成 crash-report"、卡死、启动失败时，看 `.minecraft/logs/latest.log`。

## 启动失败的排查锚点（自上而下找第一个 ERROR/异常）

| 日志特征 | 指向 |
|---|---|
| `UnsupportedClassVersionError` | Java 版本不对（见 patterns/java-version-wrong） |
| `Failed to create mod instance` / `Constructing mod instance` | 具体 Mod 构造器崩溃，日志会带 Mod id |
| `Missing or unsupported mandatory dependencies` | Fabric：缺前置/前置版本不符 |
| `Mod file ... is missing mods.toml` / `not a valid mod file` | Forge：下载损坏或放错文件 |
| `Duplicate mods found` | 重复装了同一个 Mod |
| `Exception in thread "main"` | 主线程早期崩溃，往下读 Caused by |
| 最后一行停在 `Reloading ResourceManager` / `Loading resources` | 资源包阶段卡死/极慢，多为资源包过大 |

## 崩溃前最后 N 行的价值

- 玩家报"玩着玩着闪退且无 crash-report"→ 让他给 latest.log **最后 50 行**。
- 找 `FATAL`、`Exception`、`SIGSEGV`；若出现 `# A fatal error has been detected` 说明 JVM 层崩了 → 转 `hs-err.md`。
- `Stopping!` 之后还继续刷错误 ≠ 崩溃，是正常关闭中的噪音。

## debug.log

- 默认 latest.log 已含 INFO；debug.log 含 DEBUG。
- Mod 加载细节（Mixin 注入目标、事件订阅）只在 debug 里，用于确认"某个 Mod 是否真的加载了"。
