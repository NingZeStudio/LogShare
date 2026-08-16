# 食物物品

食物是生存 Minecraft 的核心方面，所以创建可食用的物品时，需要考虑食物的用途以及其他可食用物品。

除非是在制作有过于强的物品的模型，否则应该考虑：

- 你的可食用物品会添加或减少多少饥饿值。
- 会给予什么药水效果？
- 是在游戏早期还是末期可用的？

## 添加食物组件

要为物品添加食物组件，可以先传递到 `Item.Properties` 实例：

```java
new Item.Properties().food(new FoodProperties.Builder().build())
```

现在，只要让物品可食用，没有别的。

`FoodProperties.Builder` 类有某些方法，允许你修改玩家吃你的物品时发生的事情：

| 方法                   | 描述                |
| -------------------- | ----------------- |
| `nutrition`          | 设置你的物品会补充的饥饿值的数量。 |
| `saturationModifier` | 设置你的物品会增加的饱和度的数量。 |
| `alwaysEdible`       | 允许无论饥饿值均能吃你的物品。   |

按照你的喜好修改了 builder 后，可以调用 `build()` 方法以获取 `FoodProperties`。

如果你想在玩家食用食物时添加生物效果，则需要添加一个 `Consumable` 组件以及 `FoodProperties` 组件，如下例所示：
