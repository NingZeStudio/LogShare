首先，请确保你已完成数据生成器设置并且创建了你的第一个物品。

对于每一个需要生成模型的物品，我们必须创建两个独立的 JSON 文件：

1. 一个**物品模型**，用于定义物品的纹理、旋转和整体外观。 将生成在 `generated/assets/example-mod/models/item` 目录下。
2. 一个**客户端物品**，根据组件、交互等不同标准来定义使用的模型。 将生成在 `generated/assets/example-mod/items` 目录下。

## 设置

首先，我们需要创建 ModelProvider。

可以重新使用在方块模型生成中创建的 `FabricModelProvider`。

创建一个继承 `FabricModelProvider` 的类，并且实现两个抽象方法：`generateBlockStateModels` 和 `generateItemModels`。
然后，创建一个匹配 `super` 的构造器。
