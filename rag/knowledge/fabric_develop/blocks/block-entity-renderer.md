# 方块实体渲染器

有的时候，用 Minecraft 自带的模型格式并不足够。 如果需要为你的方块的视觉效果添加动态渲染，则需要使用 `BlockEntityRenderer`。

举个例子，让我们来制作一个在 方块实体 文章中出现的 Counter Block，这个方块会在方块顶部显示点击次数。

## 创建一个 BlockEntityRenderer

方块实体渲染采用提交/渲染系统：首先提交将对象渲染至屏幕所需的数据，随后游戏基于该提交状态渲染对象。

为 `CounterBlockEntity` 创建 `BlockEntityRenderer` 时，若项目采用客户端与服务端分离的源集结构，务必将该类置于正确的源集中（例如 `src/client/`）。 直接访问 `src/main/` 源代码集中与渲染相关的类并不安全，因为这些类可能已在服务器上加载。

首先，我们需要为 `CounterBlockEntity` 创建一个 `BlockEntityRenderState` 来保存将用于渲染的数据。 在这种情况下，我们需要 `clicks` 在渲染期间可用。
