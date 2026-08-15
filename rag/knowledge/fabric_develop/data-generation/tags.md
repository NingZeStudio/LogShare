请确保你已经完成数据生成器设置章节。

## 设置

在这里我们将展示如何创建 `Item` 标签，但同样的原则对其他场景也适用。

Fabric 提供了几个辅助标签提供程序，其中一个用于物品，`FabricTagsProvider.ItemTagsProvider`。 我们将使用这个辅助类作为这个例子。

你可以创建自己的类来继承 `FabricTagsProvider`，其中 `T` 是你想要为其提供标签的类型。 这是你的**提供程序**。

让你的 IDE 填充所需的代码，然后用你的类型的 `ResourceKey` 替换 `resourceKey` 构造函数参数：
