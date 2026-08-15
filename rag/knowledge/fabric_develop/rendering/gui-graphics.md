本文假设您已经看过基本渲染概念。

`GuiGraphicsExtractor` 类是用于在游戏内渲染的主类， 用于渲染形状、文本和纹理，并且如前所述，用于操作 `PoseStack` 和使用 `BufferBuilder`。

## 绘制图形

使用 `GuiGraphicsExtractor` 绘制**基于矩形的**形状十分容易。 如果想绘制三角形或其他非矩形的图形，需要使用 `BufferBuilder`。

### 绘制矩形

可以使用 `GuiGraphicsExtractor.fill(...)` 方法绘制填充矩形。
