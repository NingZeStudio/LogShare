# 播放声音

Minecraft 有大量的声音供您选择。 查看 `SoundEvents` 类以查看 Mojang 提供的所有原版声音事件实例。

## 在您的模组中使用声音

使用声音时请确保在逻辑服务端执行 `playSound()` 方法。

在本例中，自定义交互式物品的 `interactLivingEntity()` 和 `useOn()` 方法用于播放“放置铜块”的声音和掠夺者的声音。
