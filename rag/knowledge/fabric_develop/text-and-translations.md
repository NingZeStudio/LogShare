# 文本和翻译

Minecraft 不论何时在游戏内显示文本，都是使用 `Component` 对象定义的。
使用这种自定义的类型而非 `String`，是为了允许更多高级的格式化，包括颜色、加粗、混淆和点击事件。 这样还能够容易地访问翻译系统，使得将任何 UI 元素翻译成不同语言都变得容易。

如果以前有做过数据包和函数，应该看到用于displayName、书、告示牌等内容的就是用的 json 文本格式。 不难猜到，这就是 `Component` 对象的 json 呈现，可以使用 `Component.Serializer` 互相转换。

制作模组时，最好直接在代码中构造你的 `Component` 对象，并随时利用翻译。

## 字面文本组件

创建 `Component` 对象最简单的方法是创建一个字面值。 字面值就是一个普通的字符串，默认情况下会按原样显示，没有任何格式。

可以通过 `Component.nullToEmpty` 或 `Component.literal` 方法创建，这两个方法略有不同。 `Component.nullToEmpty` 接受空值作为输入，并返回一个 `Component` 实例。 而 `Component.literal` 则不能接受空值作为输入，它会返回一个 `MutableComponent` 对象，它是 `Component` 的子类，可以轻松地进行样式设置和字符串拼接。 稍后会详细介绍。

```java
Component literal = Component.nullToEmpty("Hello, world!");
MutableComponent mutable = Component.literal("Hello, world!");
// Keep in mind that a MutableComponent can be used as a Component, making this valid:
Component mutableAsText = mutable;
```

## 可翻译文本组件

给相同的文本字符串提供多个翻译时，可以使用 `Component.translatable` 方法，引用语言文件中的任意翻译键。 如果翻译键不存在，则字面转换翻译键。 如果翻译键不存在，则字面转换翻译键。

```java
Component translatable = Component.translatable("example-mod.text.hello");

// Similarly to literals, translatable text can be easily made mutable.
MutableComponent mutable = Component.translatable("example-mod.text.bye");
```

语言文件 `en_us.json`（简体中文为 `zh_cn.json`），看上去应该像这样：

```json
{
  "example-mod.text.hello": "Hello!",
  "example-mod.text.bye": "Goodbye :("
}
```

如果想在翻译中使用变量（类似于在死亡消息中可以在翻译中使用涉及的玩家和物品），可以将这些变量作为参数添加进去。 想添加多少参数都可以。

```java
Component translatable = Component.translatable("example-mod.text.hello", player.getDisplayName());
```

在翻译中，可以像这样引用这些变量：

```json
{
  "example-mod.text.hello": "%1$s said hello!"
}
```

在游戏中，`%1$s` 会被替换为你在代码中引用的玩家的名字。 使用 `player.getDisplayName()` 会使用鼠标悬停在聊天消息中的名字上时，以提示框形式出现实体的额外信息，相比之下使用 `player.getName()` 只会得到名字但不显示额外细节。 对物品堆叠也是类似，使用 `stack.getDisplayName()`。

至于 `%1$s` 到底是什么意思，你只需要知道那个数字对应的是你正准备调用的第几个变量就行了。 打个比方，假设你现在有三个正在使用的变量。

```java
Component translatable = Component.translatable("example-mod.text.whack.item", victim.getDisplayName(), attacker.getDisplayName(), itemStack.toHoverableText());
```

如果你想引用（在我们这个例子中）那个攻击者，应该使用 `%2$s`，因为这是我们传入的第二个变量。 类似地，`%3$s` 引用的是物品堆叠。 有这些额外参数的翻译可能会像这样：

```json
{
  "example-mod.text.whack.item": "%1$s was whacked by %2$s using %3$s"
}
```

## 序列化文本

前面提到过，可以使用 text codec 将文本序列化为 JSON。 更多关于 codec 的信息，请看 Codec 页面。
