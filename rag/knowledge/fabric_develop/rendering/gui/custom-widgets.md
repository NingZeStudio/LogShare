# 自定义组件

组件本质上是容器化的界面元素，可以被添加到屏幕中供玩家交互，交互方式包括鼠标点击、键盘输入等。

## 创建组件

有很多种创建组件的方式，例如继承 `AbstractWidget`。 这个类提供了许多实用功能，比如控制组件的尺寸和位置，以及接收用户输入事件。事实上这些功能由 `Renderable`、`GuiEventListener`、`NarrationSupplier` 和 `NarratableEntry` 接口规定：

- `Renderable` - 用于渲染，需要通过 `addRenderableWidget` 方法将组件注册到屏幕上。
- `GuiEventListener` - 用于事件，比如处理鼠标点击、按下按键等事件。
- `NarrationSupplier` - 用于辅助功能，让组件能够通过屏幕阅读器或其他辅助工具访问。
- `NarratableEntry` - 用于选择，实现此接口后组件可以由 Tab 键选中，这也有助于提高可访问性。
