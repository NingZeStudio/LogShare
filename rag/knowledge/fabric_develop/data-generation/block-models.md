请确保你已经完成数据生成器设置章节。

## 设置

首先，我们需要创建 ModelProvider。 创建一个继承 `FabricModelProvider` 的类。 实现两个抽象方法：`generateBlockStateModels` 和 `generateItemModels`。
最后，创建一个与 super 匹配的构造函数。
