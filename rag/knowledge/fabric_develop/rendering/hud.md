# 在 HUD 中渲染

在基本渲染概念页面和绘制到 GUI 中，我们已经简要介绍了如何将内容渲染到 HUD，因此本页我们将重点介绍 Hud API 以及 `DeltaTracker` 参数。

## `HudElementRegistry`

Fabric 提供 Hud API 以在 HUD 上渲染和布局元素。

首先，我们需要向 `HudElementRegistry` 注册一个监听器，用于注册你的元素。 每个元素都是一个 `HudElement`。 `HudElement` 实例通常是一个 lambda 表达式，它接受一个 `GuiGraphicsExtractor` 和一个 `DeltaTracker` 实例作为参数。 有关如何使用该 API 的更多详细信息，请参阅 `HudElementRegistry` 及其相关的 Javadoc。

GUI 图形可用于访问游戏提供的各种渲染工具，以及原始矩阵堆栈。 你应该查看绘制到 GUI 页面以了解更多信息。

### Delta Tracker

`DeltaTracker` 类允许你获取当前的 `gameTimeDeltaPartialTick` 值。 `gameTimeDeltaPartialTick` 是上一个游戏刻和下一个游戏刻之间的“过程”。

例如，如果我们假设 200 FPS 场景，游戏大约每 10 帧运行一次新的刻。 每一帧，`gameTimeDeltaPartialTick` 代表上一刻与下一刻之间的距离。 超过 11 帧时，你可能会看到：

|   帧  | `gameTimeDeltaPartialTick` |
| :--: | -------------------------- |
|  `1` | `1`：新的刻                    |
|  `2` | `1/10 = 0.1`               |
|  `3` | `2/10 = 0.2`               |
|  `4` | `3/10 = 0.3`               |
|  `5` | `4/10 = 0.4`               |
|  `6` | `5/10 = 0.5`               |
|  `7` | `6/10 = 0.6`               |
|  `8` | `7/10 = 0.7`               |
|  `9` | `8/10 = 0.8`               |
| `10` | `9/10 = 0.9`               |
| `11` | `1`：新的刻                    |

可以调用 `deltaTracker.getGameTimeDeltaPartialTick(false)` 以检索 `gameTimeDeltaPartialTick`，其中布尔值参数是 `ignoreFreeze`，这实际上只是允许忽略玩家使用 `/tick freeze` 命令的情况。

实际上，只有当动画依赖于 Minecraft 刻时，才应该使用 `gameTimeDeltaPartialTick`。 对于基于时间的动画，请使用 `Util.getMillis()`，它可以测量现实世界的时间。

在本例中，我们将使用 `Util.getMillis()` 线性插入要渲染到 HUD 的正方形的颜色。
